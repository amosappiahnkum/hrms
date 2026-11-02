<?php

namespace Tests\Feature;

use App\Models\Competency\Competency;
use App\Models\Competency\CompetencyAssessment;
use App\Models\Competency\PositionCompetency;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class CompetencyMatrixImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;
    private Position $hrAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hrAdmin = Position::create(['name' => 'HR Administrator']);

        $book = new Spreadsheet();
        $this->hrSheet($book->getActiveSheet()->setTitle('HR Dpt'));
        $this->adminSheet($book->createSheet()->setTitle('Admin Dpt.'));
        $book->createSheet()->setTitle('Employee list')->fromArray([['Emp.No', 'Name'], ['AI1', 'Jane']]);

        $this->path = tempnam(sys_get_temp_dir(), 'cm') . '.xlsx';
        (new Xlsx($book))->save($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /** Headings on row 12, names on 13-14 (a category above two of them), legend, roles, then employees. */
    private function hrSheet(Worksheet $sheet): void
    {
        $sheet->fromArray(['Educational Competencies', null, 'Technical Competencies', null, 'IMS Competencies', 'Client Specific'], null, 'H12');
        $sheet->mergeCells('H12:I12')->mergeCells('J12:K12');
        $sheet->fromArray(['Minimum Education', 'Professional Certification', 'Lifting accessories (LAC)', null, 'Hazard identification and risk assessment', 'None applicable at the moment'], null, 'H13');
        $sheet->mergeCells('J13:K13')->mergeCells('H13:H20');
        $sheet->fromArray(['Wire rope slings', 'Chain slings'], null, 'J14');
        $sheet->fromArray([['Level', 'Rating'], [1, 'Awareness'], [4, 'Proficient'], ['N/A', 0]], null, 'A14');

        $sheet->fromArray(['Administrator (HR)', 'Minimum educational level', null, 'Education', null, null, 120, 3, 'n/a', 2, 0, 3, 3], null, 'A21');
        $sheet->fromArray(['Chief Tea Taster', null, null, null, null, null, 10, 2], null, 'A22');

        $sheet->fromArray(['Emp.No', 'Name of Employees', 'Educational level', 'Role Type', 'Job role'], null, 'A25');
        $sheet->fromArray(['AI24031', 'Jane Doe', 'Tertiary', 'Support role', 'HR Administrator', null, null, 1, 1, 1, 1, 1], null, 'A26');
    }

    /** Same competency spelt differently, and the same role again with a higher requirement. */
    private function adminSheet(Worksheet $sheet): void
    {
        $sheet->fromArray(['Education', 'IMS COMPTENCIES', 'Behavioural & Leadership Competencies'], null, 'H12');
        $sheet->fromArray(['Minimum Education', 'Hazard identification & risk-assessment', 'Payroll ProcessingPayroll Processing'], null, 'H13');
        $sheet->fromArray(['HR Administrator', null, null, null, null, null, 50, 3, 4, 2], null, 'A21');
        $sheet->fromArray(['Emp.No', 'Name of Employees'], null, 'A24');
    }

    private function import(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('competency:import-matrix', ['file' => $this->path] + $options);
    }

    private function competency(string $name): Competency
    {
        return Competency::where('name', $name)->firstOrFail();
    }

    public function test_it_imports_the_competency_library_from_every_sheet(): void
    {
        $this->import()->assertSuccessful();

        $this->assertEqualsCanonicalizing([
            'Minimum Education', 'Professional Certification', 'Wire rope slings', 'Chain slings',
            'Hazard identification and risk assessment', 'Payroll Processing',
        ], Competency::pluck('name')->all(), 'spelling variants merged, placeholder and doubled text cleaned');

        $this->assertSame('educational', $this->competency('Minimum Education')->group->value);
        $this->assertSame('ims', $this->competency('Hazard identification and risk assessment')->group->value);
        $this->assertSame('behavioural', $this->competency('Payroll Processing')->group->value);

        $slings = $this->competency('Wire rope slings');
        $this->assertSame('technical', $slings->group->value);
        $this->assertSame('Lifting accessories (LAC)', $slings->description);
    }

    public function test_role_requirements_go_to_matching_positions_and_employee_rows_are_ignored(): void
    {
        $this->import()->expectsOutputToContain('NO MATCH')->assertSuccessful();

        $levels = PositionCompetency::where('position_id', $this->hrAdmin->id)->with('competency')->get()
            ->mapWithKeys(fn ($r) => [$r->competency->name => $r->required_level])->all();

        $this->assertEquals([
            'Minimum Education'                         => 3,
            'Wire rope slings'                          => 2,
            'Hazard identification and risk assessment' => 4, // 3 on one sheet, 4 on the other
            'Payroll Processing'                        => 2,
        ], $levels, '"n/a" and 0 are not requirements');

        $this->assertNull(Position::where('name', 'Chief Tea Taster')->first());
        $this->assertSame(0, CompetencyAssessment::count());
    }

    public function test_unmatched_roles_can_be_created_as_positions(): void
    {
        $this->import(['--create-positions' => true])->assertSuccessful();

        $taster = Position::where('name', 'Chief Tea Taster')->firstOrFail();
        $this->assertSame(1, $taster->competencyRequirements()->count());
    }

    public function test_running_again_adds_nothing_and_keeps_edited_levels(): void
    {
        $this->import()->assertSuccessful();
        PositionCompetency::where('position_id', $this->hrAdmin->id)
            ->where('competency_id', $this->competency('Minimum Education')->id)->update(['required_level' => 1]);
        $counts = [Competency::count(), PositionCompetency::count()];

        $this->import()->expectsOutputToContain('Created 0 competencies, 0 role requirements')->assertSuccessful();

        $this->assertSame($counts, [Competency::count(), PositionCompetency::count()]);
        $this->assertSame(1, PositionCompetency::where('competency_id', $this->competency('Minimum Education')->id)->value('required_level'));
    }

    public function test_dry_run_saves_nothing(): void
    {
        $this->import(['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();

        $this->assertSame(0, Competency::count());
    }
}
