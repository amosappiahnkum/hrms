<?php

namespace App\Console\Commands;

use App\Enums\Competency\CompetencyGroup;
use App\Models\Competency\Competency;
use App\Models\Competency\PositionCompetency;
use App\Models\Position;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Imports the competency library and the levels each role requires from the department sheets of
 * the competency matrix workbook (AI-HR-CM-FM-01). Employee ratings are not imported.
 *
 * Each sheet holds one or more blocks: a row of group headings ("Educational Competencies", ...),
 * the competency names below them (some sheets put a category such as "Lifting accessories (LAC)"
 * above the names; it becomes the description), then one row per role with its required levels,
 * ending at the "Emp.No" heading of the employee list. Existing competencies and requirements are
 * left untouched, so it is safe to run again.
 */
class ImportCompetencyMatrix extends Command
{
    protected $signature = 'competency:import-matrix
        {file : Path to the .xlsx workbook}
        {--create-positions : Create positions for roles that match none}
        {--dry-run : Show what would be imported without saving}';

    protected $description = 'Import competencies and role requirements from the competency matrix workbook';

    /** Matched in order, so "Behavioural & Leadership" lands in behavioural. */
    private const GROUP_KEYWORDS = [
        'educat'      => CompetencyGroup::EDUCATIONAL,
        'technical'   => CompetencyGroup::TECHNICAL,
        'ims'         => CompetencyGroup::IMS,
        'hse'         => CompetencyGroup::IMS,
        'process'     => CompetencyGroup::PROCESS,
        'behaviou'    => CompetencyGroup::BEHAVIOURAL,
        'leadership'  => CompetencyGroup::LEADERSHIP,
        'client'      => CompetencyGroup::CLIENT,
        'legal'       => CompetencyGroup::LEGAL,
        'regulat'     => CompetencyGroup::LEGAL,
    ];

    /** Headings and levels start in column H; A-G hold the role, its education and the total. */
    private const FIRST_LEVEL_COLUMN = 7;

    /** Matrix role => existing position, where the two are named differently. */
    private const POSITION_ALIASES = [
        'Administrator (HR)'                            => 'HR Administrator',
        'Project Suuport Engineer'                      => 'Project Support Engineer',
        'Procurement & Logistics Officer'               => 'Logistics & Procurement Officer',
        'Assistant Finance Administrator'               => 'Finance Assistant Administrator',
        'Stores and Equipment Officer/Internal Auditor' => 'Equipment and Stores Officer',
    ];

    /** Columns kept in the form but not used yet, e.g. "None applicable at the moment". */
    private const PLACEHOLDER = '/none applicable/i';

    /** Column A labels that are part of the form, not roles. */
    private const NOT_ROLES = '/^(n\/?a|level|rating|total|per role|key|emp\.?\s*no)/i';

    /** @var array<string, array{name: string, group: CompetencyGroup, description: ?string}> keyed by name|group */
    private array $competencies = [];

    /** @var array<string, array<string, int>> competency key => spelling => times seen */
    private array $spellings = [];

    /** @var array<string, array{name: string, sheets: array, levels: array<string, int>}> keyed by normalised role */
    private array $roles = [];

    private array $warnings = [];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        foreach (IOFactory::load($file)->getAllSheets() as $sheet) {
            $this->readSheet(trim($sheet->getTitle()), $this->cells($sheet));
        }

        if (!$this->competencies) {
            $this->error('No competency blocks found in the workbook.');
            return self::FAILURE;
        }

        // Spellings that differ only in spacing or punctuation are one competency, named as most sheets spell it.
        foreach ($this->spellings as $key => $spellings) {
            arsort($spellings);
            $this->competencies[$key]['name'] = array_key_first($spellings);
        }

        $positions = $this->positions();

        $this->table(['Role', 'Sheet(s)', 'Requirements', 'Position'], collect($this->roles)->map(fn ($role, $key) => [
            $role['name'],
            implode(', ', array_unique($role['sheets'])),
            count($role['levels']),
            $positions[$key]->name ?? ($this->option('create-positions') ? '(new)' : 'NO MATCH, skipped'),
        ])->values());

        if ($this->option('dry-run')) {
            $this->info(count($this->competencies) . ' competencies found. Dry run: nothing saved.');
            $this->printWarnings();
            return self::SUCCESS;
        }

        [$newCompetencies, $newRequirements, $newPositions] = DB::transaction(fn () => $this->save($positions));

        $this->info("Created {$newCompetencies} competencies, {$newRequirements} role requirements and {$newPositions} positions.");
        $this->printWarnings();

        return self::SUCCESS;
    }

    /** The sheet's values, with every cell of a merged range holding the range's value. */
    private function cells(Worksheet $sheet): array
    {
        $rows = $sheet->toArray(null, true, true, false);

        foreach ($sheet->getMergeCells() as $range) {
            [[$fromCol, $fromRow], [$toCol, $toRow]] = Coordinate::rangeBoundaries($range);
            $value = $rows[$fromRow - 1][$fromCol - 1] ?? null;

            for ($r = $fromRow; $r <= $toRow; $r++) {
                for ($c = $fromCol; $c <= $toCol; $c++) {
                    $rows[$r - 1][$c - 1] = $value;
                }
            }
        }

        return $rows;
    }

    /** @return array<string, Position> keyed by normalised name, with the aliases added */
    private function positions(): array
    {
        $positions = Position::get(['id', 'name'])->keyBy(fn (Position $p) => self::key($p->name))->all();

        foreach (self::POSITION_ALIASES as $role => $position) {
            if (isset($positions[self::key($position)])) {
                $positions[self::key($role)] ??= $positions[self::key($position)];
            }
        }

        return $positions;
    }

    private function readSheet(string $sheet, array $rows): void
    {
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            if (!$this->isGroupRow($rows[$i])) {
                continue;
            }

            // Role rows run until the employee list or the next block; the names sit above them.
            $roleRows = [];
            for ($j = $i + 1; $j < $count && !$this->isGroupRow($rows[$j]); $j++) {
                $label = self::clean($rows[$j][0] ?? '');

                if (preg_match('/^emp/i', $label)) {
                    break;
                }

                if ($label !== '' && !is_numeric($label) && !preg_match(self::NOT_ROLES, $label) && $this->hasLevels($rows[$j])) {
                    $roleRows[$j] = $label;
                }
            }

            if (!$roleRows) {
                $this->warnings[] = "{$sheet}: a competency block on row " . ($i + 1) . ' has no role levels, skipped.';
                $i = $j - 1;
                continue;
            }

            $columns = $this->competencyColumns(array_slice($rows, $i, array_key_first($roleRows) - $i));

            foreach ($roleRows as $index => $label) {
                $this->readRole($sheet, $index + 1, $label, $rows[$index], $columns);
            }

            $i = $j - 1;
        }
    }

    /** The row of group headings, e.g. "Educational Competencies | Technical Competencies | ...". */
    private function isGroupRow(array $row): bool
    {
        $groups = array_filter(array_slice($row, self::FIRST_LEVEL_COLUMN), fn ($cell) => mb_strlen(self::clean($cell)) < 60 && $this->groupFor(self::clean($cell)));

        return count($groups) >= 2 && collect($groups)->contains(fn ($cell) => preg_match('/^\s*educat/i', (string) $cell));
    }

    private function hasLevels(array $row): bool
    {
        foreach (array_slice($row, self::FIRST_LEVEL_COLUMN) as $value) {
            if (is_numeric($value) && (int) $value >= 1 && (int) $value <= 4) {
                return true;
            }
        }

        return false;
    }

    /**
     * The competency in each column: its group from the heading row, its name from the lowest
     * cell under the heading, and its category (if any) from a different cell between the two.
     *
     * @param array $header the heading row and the rows under it, down to the first role
     * @return array<int, string> column index => competency key
     */
    private function competencyColumns(array $header): array
    {
        $columns = [];

        foreach (array_slice($header[0], self::FIRST_LEVEL_COLUMN, null, true) as $index => $heading) {
            $group = $this->groupFor(self::clean($heading));
            // Some cells were pasted twice: "Payroll ProcessingPayroll Processing".
            $cells = array_values(array_filter(
                array_map(fn ($row) => preg_replace('/^(.+)\1$/u', '$1', self::clean($row[$index] ?? '')), array_slice($header, 1)),
                fn ($cell) => $cell !== ''
            ));

            if (!$group || !$cells || preg_match(self::PLACEHOLDER, end($cells))) {
                continue;
            }

            $name = end($cells);
            $category = collect($cells)->first(fn ($cell) => $cell !== $name);

            $key = self::key($name) . '|' . $group->value;
            $this->competencies[$key] ??= ['name' => $name, 'group' => $group, 'description' => $category];
            $this->spellings[$key][$name] = ($this->spellings[$key][$name] ?? 0) + 1;
            $columns[$index] = $key;
        }

        return $columns;
    }

    private function readRole(string $sheet, int $rowNumber, string $label, array $row, array $columns): void
    {
        $key = self::key($label);
        $role = &$this->roles[$key];
        $role ??= ['name' => $label, 'sheets' => [], 'levels' => []];
        $role['sheets'][] = $sheet;

        foreach ($columns as $index => $competency) {
            $value = trim((string) ($row[$index] ?? ''));

            // Blank, "n/a" and 0 mean the role doesn't require it.
            if ($value === '' || !is_numeric($value) || (int) $value === 0) {
                continue;
            }

            $level = (int) $value;
            if ($level < 1 || $level > 4) {
                $this->warnings[] = "{$sheet} row {$rowNumber} ({$label}): level {$value} for \"{$this->competencies[$competency]['name']}\" is outside 1-4, skipped.";
                continue;
            }

            // A role listed twice keeps the higher requirement.
            $role['levels'][$competency] = max($role['levels'][$competency] ?? 0, $level);
        }
    }

    /** @return array{int, int, int} */
    private function save(array $positions): array
    {
        $existing = Competency::all()->keyBy(fn (Competency $c) => self::key($c->name) . '|' . $c->group->value);
        $newCompetencies = $newRequirements = $newPositions = 0;

        foreach ($this->competencies as $key => $competency) {
            if (!$existing->has($key)) {
                $existing->put($key, Competency::create($competency));
                $newCompetencies++;
            }
        }

        // Rows naming the same position (directly or by alias) are combined, keeping the higher level.
        $levels = [];
        foreach ($this->roles as $roleKey => $role) {
            if (!isset($positions[$roleKey])) {
                if (!$this->option('create-positions')) {
                    continue;
                }

                $positions[$roleKey] = Position::create(['name' => $role['name']]);
                $newPositions++;
            }

            foreach ($role['levels'] as $competencyKey => $level) {
                $current = &$levels[$positions[$roleKey]->id][$existing[$competencyKey]->id];
                $current = max($current ?? 0, $level);
                unset($current);
            }
        }

        foreach ($levels as $positionId => $required) {
            $present = PositionCompetency::where('position_id', $positionId)->pluck('competency_id')->flip();

            foreach (array_diff_key($required, $present->all()) as $competencyId => $level) {
                PositionCompetency::create([
                    'position_id'    => $positionId,
                    'competency_id'  => $competencyId,
                    'required_level' => $level,
                ]);
                $newRequirements++;
            }
        }

        return [$newCompetencies, $newRequirements, $newPositions];
    }

    private function groupFor(string $heading): ?CompetencyGroup
    {
        $heading = strtolower($heading);

        foreach (self::GROUP_KEYWORDS as $keyword => $group) {
            if (str_contains($heading, $keyword)) {
                return $group;
            }
        }

        return null;
    }

    private function printWarnings(): void
    {
        foreach ($this->warnings as $warning) {
            $this->warn($warning);
        }
    }

    private static function clean(mixed $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    /** Spelling-insensitive key: "Problem-Solving", "Problem solving" and "problem  solving" match. */
    private static function key(string $value): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', str_replace('&', 'and', mb_strtolower($value)));
    }
}
