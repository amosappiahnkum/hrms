<?php

namespace App\Console\Commands;

use App\Enums\TrainingPlan\TrainingNature;
use App\Models\TrainingPlan\TrainingCatalogueItem;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Imports the training catalogue from the "List of Training" sheet of the training plan workbook
 * (AI-HR-TP-01). Existing titles are skipped, so it is safe to run again. Employees and plan lines
 * are not imported.
 */
class ImportTrainingCatalogue extends Command
{
    protected $signature = 'training-plan:import-catalogue {file : Path to the .xlsx workbook} {--sheet=List of Training : Sheet name}';

    protected $description = 'Import the training catalogue from the training plan workbook';

    /** header (normalised) => attribute */
    private const COLUMNS = [
        'training title'    => 'title',
        'nature'            => 'nature',
        'duration(days)'    => 'default_days',
        'domain'            => 'domain',
        'estimated cost'    => 'estimated_cost',
        'trainer'           => 'trainer',
        'training location' => 'location',
    ];

    public function handle(): int
    {
        $file = $this->argument('file');

        if (!is_file($file)) {
            $this->error("File not found: {$file}");
            return self::FAILURE;
        }

        $workbook = IOFactory::load($file);
        $sheet = collect($workbook->getAllSheets())
            ->first(fn ($s) => strcasecmp(trim($s->getTitle()), trim($this->option('sheet'))) === 0);

        if (!$sheet) {
            $this->error("Sheet \"{$this->option('sheet')}\" not found.");
            return self::FAILURE;
        }

        $rows = $sheet->toArray(null, true, true, false);
        $header = array_map(fn ($h) => strtolower(preg_replace('/\s+/', ' ', trim((string) $h))), array_shift($rows) ?? []);
        $map = [];
        foreach ($header as $index => $name) {
            if (isset(self::COLUMNS[$name])) {
                $map[self::COLUMNS[$name]] = $index;
            }
        }

        if (!isset($map['title'], $map['nature'])) {
            $this->error('The sheet needs at least "Training Title" and "Nature" columns.');
            return self::FAILURE;
        }

        $existing = TrainingCatalogueItem::pluck('title')->map(fn ($t) => mb_strtolower(trim($t)))->flip();
        $created = $skipped = 0;
        $errors = [];

        foreach ($rows as $i => $row) {
            $rowNumber = $i + 2;
            $value = fn (string $attr) => isset($map[$attr]) ? trim((string) ($row[$map[$attr]] ?? '')) : '';
            $title = preg_replace('/\s+/', ' ', $value('title'));

            if ($title === '') {
                continue;
            }

            if ($existing->has(mb_strtolower($title))) {
                $skipped++;
                continue;
            }

            $nature = TrainingNature::fromLabel($value('nature'));
            if (!$nature) {
                $errors[] = "Row {$rowNumber} ({$title}): unknown nature \"{$value('nature')}\".";
                continue;
            }

            TrainingCatalogueItem::create([
                'title'          => $title,
                'nature'         => $nature,
                'domain'         => $value('domain') ?: null,
                'default_days'   => is_numeric($value('default_days')) ? $value('default_days') : null,
                'estimated_cost' => is_numeric($value('estimated_cost')) ? $value('estimated_cost') : null,
                'trainer'        => $value('trainer') ?: null,
                'location'       => $this->normaliseLocation($value('location')),
            ]);

            $existing->put(mb_strtolower($title), true);
            $created++;
        }

        $this->info("Created {$created} catalogue item(s); skipped {$skipped} already present.");
        foreach ($errors as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }

    private function normaliseLocation(string $location): ?string
    {
        if ($location === '') {
            return null;
        }

        return in_array(strtolower($location), ['online', 'onsite'], true) ? ucfirst(strtolower($location)) : $location;
    }
}
