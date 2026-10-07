<?php

namespace App\Services\Payroll;

use App\Models\Payroll\EmployeePayProfile;
use App\Models\SelfService\Employee;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Protection;

/**
 * Employees' pay details in bulk, through a spreadsheet: a template listing every employee with their
 * current details (the reference columns locked), and the import of it back.
 *
 * On import, an empty cell keeps the current value. "In force from" decides what a row does: no
 * details yet → the first ones; empty or the current date → the current details are corrected; a later
 * date → new details from that date (a raise), the old ones kept for earlier months.
 */
class EmployeePayImport
{
    /**
     * field => heading. The first four are the reference columns, locked in the template: the employee's
     * uuid (hidden; it is what identifies the row on import), then staff ID, name and department for people.
     */
    public const COLUMNS = [
        'uuid'                  => 'Ref',
        'staff_id'              => 'Staff ID',
        'name'                  => 'Name',
        'department'            => 'Department',
        'effective_from'        => 'In force from (YYYY-MM-DD)',
        'basic_salary'          => 'Basic salary a month',
        'currency'              => 'Currency',
        'payment_method'        => 'Paid by',
        'bank_name'             => 'Bank',
        'bank_branch'           => 'Branch',
        'account_name'          => 'Account name',
        'account_number'        => 'Account number',
        'mobile_money_provider' => 'Mobile money provider',
        'mobile_money_number'   => 'Mobile money number',
        'ssnit_number'          => 'SSNIT number',
        'tin'                   => 'TIN / Ghana Card',
        'tier2_scheme'          => 'Tier 2 scheme',
        'tax_resident'          => 'Tax resident (Yes/No)',
    ];

    private const REFERENCE = ['uuid', 'staff_id', 'name', 'department'];

    /** Written out empty: payment numbers never leave the system. An empty cell keeps them. */
    private const SECRET = ['account_number', 'mobile_money_number'];

    /** The template as a file path: one row per current employee, with their details in force today. */
    public function template(): string
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet()->setTitle('Pay details');
        $fields = array_keys(self::COLUMNS);
        $col = fn (string $field) => \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(array_search($field, $fields, true) + 1);
        $last = $col(array_key_last(self::COLUMNS));

        $sheet->fromArray(array_values(self::COLUMNS));
        $employees = Employee::with(['department', 'payProfiles' => fn ($q) => $q->inForceOn(now())])->orderBy('first_name')->orderBy('last_name')->get();
        $base = strtoupper((string) setting('payroll.base_currency', 'GHS'));

        $rows = $employees->map(function (Employee $e) use ($base) {
            $p = $e->payProfiles->first();

            return [
                $e->uuid, $e->staff_id, $this->name($e), $e->department?->name,
                $p?->effective_from?->toDateString(), $p ? (float) $p->basic_salary : null, $p ? ($p->currency ?: $base) : null,
                $p ? (EmployeePayProfile::PAYMENT_METHODS[$p->payment_method] ?? $p->payment_method) : null,
                $p?->bank_name, $p?->bank_branch, $p?->account_name, null, $p?->mobile_money_provider, null,
                $e->ssnit_number, $p?->tin, $p?->tier2_scheme, $p ? ($p->tax_resident ? 'Yes' : 'No') : null,
            ];
        })->all();
        if ($rows) {
            $sheet->fromArray($rows, null, 'A2', true);
        }
        $end = max(2, count($rows) + 1);

        // The reference columns and headings are locked; the rest can be filled in. Filtering, sorting and
        // resizing columns stay allowed (a protection flag set to true would block them).
        Sheets\EmployeeGrid::protect($sheet, "{$col('effective_from')}2:{$last}{$end}");
        $sheet->getStyle("A1:{$last}1")->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
        $sheet->getStyle("A2:{$col('department')}{$end}")->getProtection()->setLocked(Protection::PROTECTION_PROTECTED);
        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A2:{$col('department')}{$end}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEF2F4');
        $sheet->setAutoFilter("A1:{$last}{$end}");
        $sheet->freezePane("{$col('effective_from')}2");
        foreach ($fields as $i => $field) {
            $sheet->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
        }
        $sheet->getColumnDimension($col('uuid'))->setVisible(false);
        // Text, so leading zeros and long numbers survive.
        foreach (['staff_id', 'account_number', 'mobile_money_number', 'ssnit_number', 'tin', 'effective_from'] as $field) {
            $sheet->getStyle("{$col($field)}2:{$col($field)}{$end}")->getNumberFormat()->setFormatCode('@');
        }

        // Choices as dropdowns.
        // (This library writes the flag inverted: true shows the in-cell arrow.)
        $list = function (string $field, array $choices, string $error = 'Pick one of the choices.') use ($sheet, $col, $end) {
            $v = new DataValidation();
            $v->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(true)->setShowErrorMessage(true)
                ->setErrorStyle(DataValidation::STYLE_STOP)->setErrorTitle('Choose from the list')->setError($error)
                ->setFormula1('"' . implode(',', $choices) . '"');
            $sheet->setDataValidation("{$col($field)}2:{$col($field)}{$end}", $v);
        };
        $list('payment_method', array_values(EmployeePayProfile::PAYMENT_METHODS));
        $list('tax_resident', ['Yes', 'No']);
        // Only the currencies payroll accepts, so a typo or an old code (GHC) can't be entered.
        $list('currency', \App\Support\Currencies::CODES, 'Choose a currency code from the list (e.g. GHS, USD).');

        Sheets\EmployeeGrid::guide($book, [
            'How to fill in this sheet',
            'Staff ID, Name and Department are locked: they say whose row it is. A hidden column identifies each row; rows not from this template are refused.',
            'An empty cell keeps what is there now. Account and mobile money numbers are left out on purpose: fill them only to change them.',
            'In force from: empty or the current date corrects the current details; a later date adds new details from then (e.g. a raise); for someone with no details yet it is when they start (empty: the 1st of this month).',
            "Currency: choose from the list. Empty: {$base}.",
            'Rows you leave unchanged are skipped. If any row has a problem, nothing is saved and the problems are listed by row.',
        ]);

        return Sheets\EmployeeGrid::save($book);
    }

    /**
     * Import rows (after the headings). All or nothing.
     *
     * @return array{created: int, corrected: int, changed: int, unchanged: int, errors: array<int, string>}
     */
    public function import(array $rows, User $user, bool $apply = true): array
    {
        $fields = array_keys(self::COLUMNS);
        $rows = collect($rows)->map(fn ($r) => array_combine($fields, array_pad(array_slice(array_values($r), 0, count($fields)), count($fields), null)))
            ->map(fn ($r) => array_map(fn ($v) => is_string($v) ? trim($v) : $v, $r));
        $employees = Employee::whereIn('uuid', $rows->pluck('uuid')->filter()->map(fn ($v) => (string) $v))
            ->with(['payProfiles' => fn ($q) => $q->orderBy('effective_from')])->get()->keyBy('uuid');

        $errors = [];
        $plans = [];
        $seen = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $editable = array_diff_key($row, array_flip(self::REFERENCE));
            if (!$row['uuid'] && !$row['staff_id'] && !array_filter($editable, fn ($v) => $v !== null && $v !== '')) {
                continue;
            }
            // Rows are identified by the hidden reference, never by the staff ID shown.
            $who = $row['staff_id'] ?: $row['name'] ?: "Row {$line}";
            $employee = $row['uuid'] ? $employees->get((string) $row['uuid']) : null;
            if (!$employee) {
                $errors[$line] = "{$who}: This row isn't from the template (or the employee no longer exists). Download a fresh template.";
                continue;
            }
            if (isset($seen[$employee->id])) {
                $errors[$line] = "{$who}: Also on row {$seen[$employee->id]}.";
                continue;
            }
            $seen[$employee->id] = $line;
            $who = $employee->staff_id ?: $this->name($employee);

            $result = $this->plan($employee, $editable);
            if (is_string($result)) {
                $errors[$line] = "{$who}: {$result}";
            } elseif ($result) {
                $plans[] = $result + ['line' => $line, 'who' => "{$who} · {$this->name($employee)}"];
            }
        }

        $counts = ['created' => 0, 'corrected' => 0, 'changed' => 0];
        foreach ($plans as $plan) {
            $counts[$plan['action']]++;
        }
        $preview = $errors ? [] : $this->preview($plans);
        if (!$apply || $errors) {
            return $counts + ['unchanged' => count($seen) - count($plans), 'errors' => $errors, 'changes' => $preview];
        }

        $counts = ['created' => 0, 'corrected' => 0, 'changed' => 0];
        if (!$errors) {
            DB::transaction(function () use ($plans, $user, &$counts) {
                foreach ($plans as $plan) {
                    $this->apply($plan, $user);
                    $counts[$plan['action']]++;
                }
            });
        }

        return $counts + ['unchanged' => count($seen) - count($plans), 'errors' => $errors, 'changes' => $preview];
    }

    /**
     * What each planned row changes, old → new, and what looks like a mistake: a pay cut or a big raise,
     * a salary of 0, corrections to months already paid, details that miss a pay run still open, or a
     * new account, payment method or currency. Warnings don't stop the import; they're for checking.
     */
    private function preview(array $plans): array
    {
        $ids = collect($plans)->pluck('employee.id');
        $lastPaid = \App\Models\Payroll\Payslip::whereIn('employee_id', $ids)
            ->whereHas('run', fn ($q) => $q->where('type', 'regular')->whereIn('status', [\App\Enums\Payroll\PayRunStatus::APPROVED, \App\Enums\Payroll\PayRunStatus::PAID]))
            ->with('run:id,period_end')->get()->groupBy('employee_id')->map(fn ($g) => $g->max(fn ($p) => $p->run->period_end));
        $openRuns = \App\Models\Payroll\PayRun::where('type', 'regular')
            ->whereIn('status', [\App\Enums\Payroll\PayRunStatus::DRAFT, \App\Enums\Payroll\PayRunStatus::CALCULATED, \App\Enums\Payroll\PayRunStatus::PENDING_APPROVAL])->get();
        $base = strtoupper((string) setting('payroll.base_currency', 'GHS'));
        $arrears = (int) setting('payroll.arrears_months', 12) > 0;
        $money = fn ($v) => number_format((float) $v, 2);
        $mask = fn ($v) => $v ? '…' . substr((string) $v, -4) : '—';

        return collect($plans)->map(function (array $plan) use ($lastPaid, $openRuns, $base, $arrears, $money, $mask) {
            ['employee' => $e, 'current' => $c, 'action' => $action, 'from' => $from, 'data' => $data, 'ssnit' => $ssnit] = $plan;
            $starts = $action === 'corrected' ? $c->effective_from : ($from ?? now()->startOfMonth());
            $paidUntil = $lastPaid->get($e->id);

            // Each field that changes (everything given, for new details).
            $fields = [];
            $show = fn ($field, $v) => match ($field) {
                'basic_salary'                          => $v === null ? '—' : $money($v),
                'currency'                              => $v ?: $base,
                'payment_method'                        => EmployeePayProfile::PAYMENT_METHODS[$v] ?? ($v ?: '—'),
                'account_number', 'mobile_money_number' => $mask($v),
                'tax_resident'                          => $v ? 'Yes' : 'No',
                default                                 => filled($v) ? (string) $v : '—',
            };
            foreach ($data as $field => $value) {
                $old = $c?->{$field};
                if ($action !== 'created' && $this->equal($old, $value)) {
                    continue;
                }
                if ($action === 'created' && blank($value) && $field !== 'tax_resident') {
                    continue;
                }
                $fields[] = ['label' => self::COLUMNS[$field], 'from' => $action === 'created' ? null : $show($field, $old), 'to' => $show($field, $value)];
            }
            if ($ssnit !== $e->ssnit_number) {
                $fields[] = ['label' => self::COLUMNS['ssnit_number'], 'from' => $e->ssnit_number ?: '—', 'to' => $ssnit ?: '—'];
            }

            $warnings = [];
            $old = $c ? (float) $c->basic_salary : null;
            $new = (float) $data['basic_salary'];
            if ($new <= 0) {
                $warnings[] = 'Basic salary is 0.';
            } elseif ($old && $new < $old && $action !== 'created') {
                $warnings[] = "Basic salary goes down, from {$money($old)} to {$money($new)}.";
            } elseif ($old && $new >= $old * 1.25) {
                $warnings[] = 'Basic salary goes up ' . round(($new / $old - 1) * 100) . "%, from {$money($old)} to {$money($new)}.";
            }
            $basicChanges = $old !== null && abs($new - $old) >= 0.005;
            if ($paidUntil && $starts->lte($paidUntil) && ($basicChanges || $action === 'created')) {
                $warnings[] = match (true) {
                    $action === 'created'           => 'Starts ' . $starts->format('j M Y') . ', in months already paid: those months aren\'t paid automatically (use an off-cycle run).',
                    $new < $old                     => 'Applies to months already paid: pay already given isn\'t taken back.',
                    $arrears                        => 'Applies to months already paid: the next regular run adds back pay.',
                    default                         => 'Applies to months already paid; back pay is switched off, so the difference won\'t be paid.',
                };
            }
            foreach ($openRuns as $run) {
                if ($starts->gt($run->period_end)) {
                    $warnings[] = "Starts {$starts->format('j M Y')}, so {$run->name} (still open) won't use " . ($action === 'created' ? 'them.' : 'this change.');
                }
            }
            if ($c && $data['payment_method'] !== $c->payment_method) {
                $warnings[] = 'Paid by changes to ' . (EmployeePayProfile::PAYMENT_METHODS[$data['payment_method']] ?? $data['payment_method']) . '.';
            } elseif ($c && (!$this->equal($c->account_number, $data['account_number']) || !$this->equal($c->mobile_money_number, $data['mobile_money_number']))) {
                $warnings[] = 'Paid into a different account (' . $mask($data['account_number'] ?? $data['mobile_money_number']) . ').';
            }
            if ($c && !$this->equal($c->currency ?: $base, $data['currency'] ?: $base)) {
                $warnings[] = 'Currency changes from ' . ($c->currency ?: $base) . ' to ' . ($data['currency'] ?: $base) . '.';
            }
            if ($data['tax_resident'] === false && ($c?->tax_resident ?? true)) {
                $warnings[] = 'Not tax resident: taxed at the non-resident flat rate.';
            }

            return [
                'line'     => $plan['line'],
                'who'      => $plan['who'],
                'action'   => match ($action) {
                    'created'   => 'New details from ' . $starts->format('j M Y'),
                    'corrected' => 'Corrected',
                    'changed'   => 'New details from ' . $starts->format('j M Y') . ' (earlier ones kept)',
                },
                'fields'   => $fields,
                'warnings' => $warnings,
            ];
        })->values()->all();
    }

    /** What a row would do, a problem in words, or null when it changes nothing. */
    private function plan(Employee $employee, array $cells): array|string|null
    {
        $profiles = $employee->payProfiles;
        $current = $profiles->filter(fn ($p) => $p->effective_from->lte(now()))->last() ?? $profiles->last();
        // Someone with no pay details whose row was left empty: nothing to do.
        if (!$current && !array_filter($cells, fn ($v) => $v !== null && $v !== '')) {
            return null;
        }

        $from = null;
        if (filled($cells['effective_from'])) {
            $from = $this->date($cells['effective_from']);
            if (!$from) {
                return 'In force from must be a date (YYYY-MM-DD).';
            }
        }
        $action = !$current ? 'created' : ($from && $from->gt($current->effective_from) ? 'changed' : 'corrected');
        if ($action === 'corrected' && $from && !$from->equalTo($current->effective_from)) {
            return 'In force from can only be the current date (' . $current->effective_from->toDateString() . ') or later.';
        }

        // Empty cells keep the current values.
        $method = filled($cells['payment_method']) ? $this->method((string) $cells['payment_method']) : $current?->payment_method;
        if (filled($cells['payment_method']) && !$method) {
            return 'Paid by must be one of: ' . implode(', ', EmployeePayProfile::PAYMENT_METHODS) . '.';
        }
        $resident = filled($cells['tax_resident']) ? $this->yesNo($cells['tax_resident']) : ($current?->tax_resident ?? true);
        if ($resident === null) {
            return 'Tax resident must be Yes or No.';
        }
        $pick = fn (string $f) => filled($cells[$f]) ? $cells[$f] : $current?->{$f};
        $data = [
            'basic_salary'          => $pick('basic_salary'),
            // Old codes are read as current ones (GHC is GHS).
            'currency'              => filled($cells['currency']) ? \App\Support\Currencies::normalize((string) $cells['currency']) : \App\Support\Currencies::normalize($current?->currency),
            'payment_method'        => $method,
            'bank_name'             => $pick('bank_name'),
            'bank_branch'           => $pick('bank_branch'),
            'account_name'          => $pick('account_name'),
            'account_number'        => filled($cells['account_number']) ? (string) $cells['account_number'] : $current?->account_number,
            'mobile_money_provider' => $pick('mobile_money_provider'),
            'mobile_money_number'   => filled($cells['mobile_money_number']) ? (string) $cells['mobile_money_number'] : $current?->mobile_money_number,
            'tin'                   => filled($cells['tin']) ? (string) $cells['tin'] : $current?->tin,
            'tier2_scheme'          => $pick('tier2_scheme'),
            'tax_resident'          => $resident,
        ];
        $ssnit = filled($cells['ssnit_number']) ? (string) $cells['ssnit_number'] : $employee->ssnit_number;

        $v = Validator::make($data + ['ssnit_number' => $ssnit], collect(EmployeePayProfile::rules())->only(array_keys($data + ['ssnit_number' => 1]))->all());
        if ($v->fails()) {
            return implode(' ', $v->errors()->all());
        }
        $base = strtoupper((string) setting('payroll.base_currency', 'GHS'));
        if ($data['currency'] === $base) {
            $data['currency'] = null;
        }
        $data = EmployeePayProfile::withoutUnusedPayment($data);

        $same = $current && $action === 'corrected' && $ssnit === $employee->ssnit_number
            && collect($data)->every(fn ($value, $field) => $this->equal($current->{$field}, $value));

        return $same ? null : compact('employee', 'current', 'action', 'from', 'data', 'ssnit');
    }

    private function apply(array $plan, User $user): void
    {
        ['employee' => $employee, 'current' => $current, 'action' => $action, 'from' => $from, 'data' => $data, 'ssnit' => $ssnit] = $plan;
        if ($ssnit !== $employee->ssnit_number) {
            $employee->update(['ssnit_number' => $ssnit]);
        }

        match ($action) {
            'corrected' => $current->update($data),
            'changed'   => $employee->payProfiles()->create($data + collect($current->only(['tier3_scheme', 'tier3_percent', 'reliefs', 'overtime_eligible', 'notes']))->all()
                + ['effective_from' => $from->toDateString(), 'created_by' => $user->id]),
            'created'   => $employee->payProfiles()->create($data + ['effective_from' => ($from ?? now()->startOfMonth())->toDateString(), 'created_by' => $user->id]),
        };
    }

    private function name(Employee $e): string
    {
        return trim(preg_replace('/\s+/', ' ', (string) $e->name));
    }

    private function date(mixed $value): ?Carbon
    {
        try {
            return is_numeric($value) ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->startOfDay() : Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function method(string $value): ?string
    {
        $value = strtolower(trim($value));
        foreach (EmployeePayProfile::PAYMENT_METHODS as $key => $label) {
            if ($value === $key || $value === strtolower($label) || $value === str_replace('_', ' ', $key)) {
                return $key;
            }
        }

        return null;
    }

    private function yesNo(mixed $value): ?bool
    {
        return match (strtolower(trim((string) $value))) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    private function equal(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.005;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }
}
