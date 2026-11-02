<?php

namespace Tests\Feature\Payroll;

use App\Models\Config\Setting;
use App\Models\Payroll\EmployeePayProfile;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Protection;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\SetsUpAccess;
use Tests\TestCase;

class EmployeePayImportTest extends TestCase
{
    use RefreshDatabase, SetsUpAccess;

    private $newcomer;
    private $current;
    private $raised;
    private bool $checking = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAccess([]);
        Setting::where('key', 'features.payroll.enabled')->update(['value' => true]);
        app(SettingService::class)->refreshCache();
        $hr = $this->userWithRole('hr');
        $hr->givePermissionTo('prepare-payroll');
        Sanctum::actingAs($hr);

        foreach (['newcomer' => 'P001', 'current' => 'P002', 'raised' => 'P003'] as $who => $staffId) {
            $this->{$who} = $this->userWithRole('staff')->employee;
            $this->{$who}->update(['staff_id' => $staffId]);
        }
        // Each with their own account (two employees can't share one).
        foreach (['1111222233' => $this->current, '1111222244' => $this->raised] as $account => $e) {
            EmployeePayProfile::create(['employee_id' => $e->id, 'effective_from' => '2026-01-01', 'basic_salary' => 5000, 'payment_method' => 'bank', 'bank_id' => $this->bank('GCB')->id, 'bank_name' => 'GCB', 'account_number' => (string) $account]);
            $this->bank('Ecobank');
        }
    }

    /** Download the template, let `$fill` change rows (keyed by staff ID), and upload it. */
    private function roundTrip(callable $fill)
    {
        $path = $this->get('/api/v1/payroll/employees-template')->assertOk()->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        $sheet = $book->getSheet(0);
        $headings = $sheet->rangeToArray('A1:R1')[0];
        for ($r = 2; $r <= $sheet->getHighestRow(); $r++) {
            $row = array_combine($headings, $sheet->rangeToArray("A{$r}:R{$r}")[0]);
            foreach ($fill($row) ?? [] as $heading => $value) {
                $sheet->setCellValue([array_search($heading, $headings, true) + 1, $r], $value);
            }
        }
        $out = tempnam(sys_get_temp_dir(), 'pi') . '.xlsx';
        (new Xlsx($book))->save($out);

        return $this->post('/api/v1/payroll/employees-import', ['file' => new UploadedFile($out, 'pay.xlsx', null, null, true)] + ($this->checking ? ['check' => 1] : []), ['Accept' => 'application/json']);
    }

    public function test_the_template_locks_whose_row_it_is_and_leaves_payment_numbers_out(): void
    {
        $path = $this->get('/api/v1/payroll/employees-template')->assertOk()->baseResponse->getFile()->getPathname();
        $sheet = IOFactory::load($path)->getSheet(0);

        $this->assertTrue($sheet->getProtection()->getSheet());
        // Filtering and sorting stay allowed on the protected sheet.
        $this->assertFalse($sheet->getProtection()->getAutoFilter());
        $this->assertFalse($sheet->getProtection()->getSort());
        // The hidden reference and Staff ID are locked; the details aren't.
        $this->assertSame($this->newcomer->uuid, collect($sheet->rangeToArray('A2:A10'))->flatten()->first(fn ($v) => $v === $this->newcomer->uuid));
        $this->assertFalse($sheet->getColumnDimension('A')->getVisible());
        $this->assertSame(Protection::PROTECTION_PROTECTED, $sheet->getStyle('A2')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_PROTECTED, $sheet->getStyle('B2')->getProtection()->getLocked());
        $this->assertSame(Protection::PROTECTION_UNPROTECTED, $sheet->getStyle('F2')->getProtection()->getLocked());
        $this->assertStringNotContainsString('1111222233', json_encode($sheet->toArray()));

        // Currency, Paid by and Tax resident are dropdowns; currency offers only accepted codes.
        $currency = $sheet->getCell('G3')->getDataValidation();
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST, $currency->getType());
        $this->assertSame('"' . implode(',', \App\Support\Currencies::CODES) . '"', $currency->getFormula1());
        $this->assertStringNotContainsString('GHC', $currency->getFormula1());
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST, $sheet->getCell('H3')->getDataValidation()->getType());
    }

    public function test_new_corrected_and_changed_details_are_saved_and_unchanged_rows_skipped(): void
    {
        $this->roundTrip(fn ($row) => match ($row['Staff ID']) {
            'P001'  => ['Basic salary a month' => 3000, 'Paid by' => 'Mobile money', 'Mobile money provider' => 'MTN', 'Mobile money number' => '0240000000'],
            'P002'  => ['Bank' => 'Ecobank', 'SSNIT number' => 'C99'],
            'P003'  => ['In force from (YYYY-MM-DD)' => '2026-07-01', 'Basic salary a month' => 5500],
            default => null,
        })->assertOk()->assertJsonPath('data.created', 1)->assertJsonPath('data.corrected', 1)->assertJsonPath('data.changed', 1);

        $this->assertSame('mobile_money', $this->newcomer->payProfiles()->sole()->payment_method);
        $current = $this->current->payProfiles()->sole();
        $this->assertSame(['Ecobank', '1111222233'], [$current->bank_name, $current->account_number]); // empty account number kept
        $this->assertSame('C99', $this->current->fresh()->ssnit_number);
        $this->assertSame(['5000.00', '5500.00'], $this->raised->payProfiles()->orderBy('effective_from')->pluck('basic_salary')->all());

        // The same sheet again changes nothing.
        $this->roundTrip(fn () => null)->assertOk()->assertJsonPath('data.unchanged', \App\Models\SelfService\Employee::count());
    }

    public function test_any_bad_row_stops_the_import_and_every_problem_is_listed(): void
    {
        $this->roundTrip(fn ($row) => match ($row['Staff ID']) {
            'P001'  => ['Basic salary a month' => 3000, 'Paid by' => 'Bank'], // bank without bank details
            'P002'  => ['Basic salary a month' => 6000],
            'P003'  => ['In force from (YYYY-MM-DD)' => '2025-01-01'],
            default => null,
        })->assertStatus(422)
            ->assertJsonPath('errors.rows', fn ($rows) => count($rows) === 2
                && collect($rows)->contains(fn ($m) => str_starts_with($m, 'P001') && str_contains($m, 'Choose the bank'))
                && collect($rows)->contains(fn ($m) => str_starts_with($m, 'P003') && str_contains($m, 'or later')));

        $this->assertSame('5000.00', $this->current->payProfiles()->sole()->basic_salary);
        $this->assertSame(0, $this->newcomer->payProfiles()->count());
    }

    public function test_a_bank_or_branch_not_on_the_list_is_added_once(): void
    {
        $fill = fn ($row) => match ($row['Staff ID']) {
            'P001'  => ['Basic salary a month' => 3000, 'Paid by' => 'Bank', 'Bank' => 'Fidelity  Bank', 'Branch' => 'Takoradi', 'Account number' => '5550001'],
            'P002'  => ['Bank' => 'fidelity bank', 'Branch' => 'Harbour Road'],
            'P003'  => ['Branch' => 'Kumasi'], // a new branch of GCB, which is listed
            default => null,
        };

        // The check says what it will add, adding nothing.
        $this->checking = true;
        $changes = collect($this->roundTrip($fill)->assertOk()->assertJsonPath('data.new_banks', ['Fidelity Bank'])->json('data.changes'))->keyBy(fn ($c) => substr($c['who'], 0, 4));
        $this->assertContains('Adds the bank "Fidelity Bank" to the banks list (if it\'s another name for a listed bank, merge it in Payroll → Settings → Banks).', $changes['P001']['warnings']);
        $this->assertContains('Adds the branch "Kumasi" to GCB\'s branches.', $changes['P003']['warnings']);
        $this->assertNull(\App\Models\Payroll\Bank::findByName('Fidelity Bank'));

        // Saved: one Fidelity Bank for both rows, with each row's branch.
        $this->checking = false;
        $this->roundTrip($fill)->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'Added to the banks list: Fidelity Bank.'));
        $fidelity = \App\Models\Payroll\Bank::where('name', 'Fidelity Bank')->sole();
        $this->assertSame(['Harbour Road', 'Takoradi'], $fidelity->branches()->pluck('name')->all());
        $newcomer = $this->newcomer->payProfiles()->sole();
        $this->assertSame([$fidelity->id, 'Fidelity Bank', 'Takoradi'], [$newcomer->bank_id, $newcomer->bank_name, $newcomer->branch->name]);
        $this->assertSame([$fidelity->id, 'Harbour Road'], [$this->current->payProfiles()->sole()->bank_id, $this->current->payProfiles()->sole()->bank_branch]);
        $this->assertSame('Kumasi', $this->raised->payProfiles()->sole()->branch->name);
        $this->assertSame($this->bank('GCB')->id, $this->raised->payProfiles()->sole()->branch->bank_id);
    }

    public function test_two_employees_cant_be_paid_into_the_same_account(): void
    {
        // Two rows with one account, and a row with someone else's (not in the file): both refused, nothing saved.
        $outsider = $this->userWithRole('staff')->employee;
        EmployeePayProfile::create(['employee_id' => $outsider->id, 'effective_from' => '2026-01-01', 'basic_salary' => 4000, 'payment_method' => 'mobile_money', 'mobile_money_provider' => 'MTN', 'mobile_money_number' => '0244000111']);
        $this->roundTrip(fn ($row) => match ($row['Staff ID']) {
            'P001'  => ['Basic salary a month' => 3000, 'Paid by' => 'Mobile money', 'Mobile money provider' => 'MTN', 'Mobile money number' => '+233 24 400 0111'],
            'P003'  => ['Account number' => '1111-2222-33'], // P002's, typed differently
            default => null,
        })->assertStatus(422)
            ->assertJsonPath('errors.rows', fn ($rows) => count($rows) === 2
                && collect($rows)->contains(fn ($m) => str_starts_with($m, 'P001') && str_contains($m, 'mobile money number is already used for'))
                && collect($rows)->contains(fn ($m) => str_starts_with($m, 'P003') && str_contains($m, 'P002') && str_contains($m, '(row')));
        $this->assertSame(0, $this->newcomer->payProfiles()->count());

        // Two employees swapping accounts in one file is fine: it's the result that counts.
        $this->roundTrip(fn ($row) => match ($row['Staff ID']) {
            'P002'  => ['Account number' => '1111222244'],
            'P003'  => ['Account number' => '1111222233'],
            default => null,
        })->assertOk()->assertJsonPath('data.corrected', 2);

        // Two already sharing an account (saved before the check) don't stop an import of something else.
        EmployeePayProfile::where('employee_id', $this->raised->id)->update(['account_number' => encrypt('1111222244', false), 'account_fingerprint' => EmployeePayProfile::fingerprint('1111222244', 'bank')]);
        $this->assertSame('1111222244', $this->raised->payProfiles()->sole()->account_number);
        $this->roundTrip(fn ($row) => $row['Staff ID'] === 'P001' ? ['Basic salary a month' => 3000, 'Paid by' => 'Cash'] : null)->assertOk()->assertJsonPath('data.created', 1);
    }

    public function test_rows_are_matched_by_the_hidden_reference_not_the_staff_id(): void
    {
        // An edited staff ID changes nothing about whose row it is.
        $this->roundTrip(fn ($row) => $row['Staff ID'] === 'P002' ? ['Staff ID' => 'P003', 'Basic salary a month' => 6000] : null)
            ->assertOk()->assertJsonPath('data.corrected', 1);
        $this->assertSame('6000.00', $this->current->payProfiles()->sole()->basic_salary);
        $this->assertSame('5000.00', $this->raised->payProfiles()->sole()->basic_salary);

        // A row without the reference (typed in, or from another sheet) is refused.
        $this->roundTrip(fn ($row) => $row['Staff ID'] === 'P002' ? ['Ref' => null, 'Basic salary a month' => 7000] : null)
            ->assertStatus(422)->assertJsonPath('errors.rows', fn ($rows) => str_contains(collect($rows)->first(), "isn't from the template"));
    }

    public function test_a_check_shows_what_would_change_and_warns_saving_nothing(): void
    {
        // A June run still open: details starting in July (the newcomer, by default this month) miss it.
        \Illuminate\Support\Carbon::setTestNow('2026-07-10');
        $this->postJson('/api/v1/payroll/runs', ['year' => 2026, 'month' => 6])->assertCreated();
        $this->checking = true;

        $res = $this->roundTrip(fn ($row) => match ($row['Staff ID']) {
            'P001'  => ['Basic salary a month' => 3000, 'Paid by' => 'Cash'],
            'P002'  => ['Basic salary a month' => 4000],
            'P003'  => ['Basic salary a month' => 8000],
            default => null,
        })->assertOk()->assertJsonPath('data.errors', []);
        $changes = collect($res->json('data.changes'))->keyBy(fn ($c) => substr($c['who'], 0, 4));

        $this->assertStringContainsString('still open', implode(' ', $changes['P001']['warnings']));
        $this->assertStringContainsString('goes down', implode(' ', $changes['P002']['warnings']));
        $this->assertContains(['label' => 'Basic salary a month', 'from' => '5,000.00', 'to' => '4,000.00'], $changes['P002']['fields']);
        $this->assertStringContainsString('goes up 60%', implode(' ', $changes['P003']['warnings']));

        // Nothing was saved.
        $this->assertSame(0, $this->newcomer->payProfiles()->count());
        $this->assertSame('5000.00', $this->current->payProfiles()->sole()->basic_salary);
        \Illuminate\Support\Carbon::setTestNow();
    }

    /** A bank on the banks list (made the first time). */
    private function bank(string $name): \App\Models\Payroll\Bank
    {
        return \App\Models\Payroll\Bank::firstOrCreate(['name' => $name], ['active' => true]);
    }
}
