@php
    $money = fn ($n) => number_format((float) $n, 2);
    $e = $payslip->employee_snapshot;
    $pay = $payslip->payment_snapshot ?? [];
    $lines = $payslip->lines->where('show_on_payslip', true);
    $earnings = $lines->where('kind', 'earning');
    $deductions = $lines->where('kind', 'deduction');
    $employer = $lines->where('kind', 'employer_contribution');
    $account = $pay['account_number'] ?? null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip – {{ $e['name'] ?? '' }} – {{ $run->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #1a1a1a; line-height: 1.45; }
        .page { padding: 26px 30px; }
        .header { border-bottom: 2px solid #1a1a2e; padding-bottom: 10px; margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        .org { font-size: 14px; font-weight: bold; color: #1a1a2e; }
        .muted { color: #64748b; }
        .logo { text-align: right; width: 100px; }
        .logo img { max-width: 90px; max-height: 56px; }
        .meta td { padding: 2px 0; vertical-align: top; }
        h2 { font-size: 9px; text-transform: uppercase; color: #64748b; letter-spacing: .4px; margin: 12px 0 4px; }
        .lines td { padding: 3px 4px; border-bottom: 1px solid #eef2f7; }
        .lines .amt { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; border-top: 1px solid #94a3b8; border-bottom: 0; }
        .net { margin-top: 14px; border: 1px solid #1a1a2e; padding: 8px 10px; }
        .net .label { font-size: 10px; }
        .net .value { font-size: 16px; font-weight: bold; text-align: right; }
        .col { width: 50%; vertical-align: top; }
        .col-l { padding-right: 10px; } .col-r { padding-left: 10px; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <table><tr>
            <td>
                <div class="org">{{ $company->get('name') ?? config('app.name') }}</div>
                <div class="muted">Payslip · {{ \Illuminate\Support\Carbon::create($run->year, $run->month)->format('F Y') }}@if($run->type === 'off_cycle') (off-cycle)@endif</div>
            </td>
            <td class="logo">@if($logo)<img src="{{ $logo }}" alt="">@endif</td>
        </tr></table>
    </div>

    <table class="meta"><tr>
        <td class="col col-l">
            <div style="font-size: 12px; font-weight: bold;">{{ $e['name'] ?? '' }}</div>
            <div class="muted">{{ implode(' · ', array_filter([$e['staff_id'] ?? null, $e['position'] ?? null, $e['department'] ?? null])) }}</div>
        </td>
        <td class="col col-r">
            <table>
                <tr><td class="muted">SSNIT number</td><td>{{ $e['ssnit_number'] ?? '—' }}</td></tr>
                <tr><td class="muted">Paid</td><td>
                    @if(($pay['payment_method'] ?? '') === 'bank'){{ $pay['bank_name'] ?? '' }}@if($account) · ••••{{ substr($account, -4) }}@endif
                    @elseif(($pay['payment_method'] ?? '') === 'mobile_money'){{ $pay['mobile_money_provider'] ?? '' }} mobile money
                    @else{{ ucfirst($pay['payment_method'] ?? '—') }}@endif
                </td></tr>
                @if($run->pay_date)<tr><td class="muted">Pay date</td><td>{{ $run->pay_date->format('j M Y') }}</td></tr>@endif
                @if((float) $payslip->proration < 1)<tr><td class="muted">Days paid</td><td>{{ round($payslip->proration * 100) }}% of the month</td></tr>@endif
            </table>
        </td>
    </tr></table>

    <table><tr>
        <td class="col col-l">
            <h2>Earnings</h2>
            <table class="lines">
                @foreach($earnings as $l)
                    <tr><td>{{ $l->name }}@if($l->quantity !== null) <span class="muted">({{ (float) $l->quantity }})</span>@endif</td><td class="amt">{{ $money($l->amount) }}</td></tr>
                @endforeach
                <tr class="total"><td>Gross pay</td><td class="amt">{{ $money($payslip->gross_pay) }}</td></tr>
            </table>
        </td>
        <td class="col col-r">
            <h2>Deductions</h2>
            <table class="lines">
                @foreach($deductions as $l)
                    <tr><td>{{ $l->name }}</td><td class="amt">{{ $money($l->amount) }}</td></tr>
                @endforeach
                <tr class="total"><td>Total deductions</td><td class="amt">{{ $money($payslip->total_deductions) }}</td></tr>
            </table>
        </td>
    </tr></table>

    <div class="net">
        <table><tr>
            <td class="label">Net pay ({{ $currency }})</td>
            <td class="value">{{ $money($payslip->net_pay) }}</td>
        </tr></table>
    </div>

    @if($showEmployer && $employer->count())
        <h2>Paid by your employer</h2>
        <table class="lines">
            @foreach($employer as $l)
                <tr><td>{{ $l->name }}</td><td class="amt">{{ $money($l->amount) }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>For your records</h2>
    <table class="lines">
        <tr><td>Taxable income</td><td class="amt">{{ $money($payslip->taxable_income) }}</td></tr>
        @if($ytd)
            <tr><td>Year to date: gross pay</td><td class="amt">{{ $money($ytd->gross) }}</td></tr>
            <tr><td>Year to date: income tax</td><td class="amt">{{ $money($ytd->paye) }}</td></tr>
            <tr><td>Year to date: SSNIT</td><td class="amt">{{ $money($ytd->ssnit) }}</td></tr>
            <tr><td>Year to date: net pay</td><td class="amt">{{ $money($ytd->net) }}</td></tr>
        @endif
    </table>

    <p class="muted" style="margin-top: 16px; font-size: 8px;">Amounts in {{ $currency }}. Generated {{ now()->format('j M Y') }}. Questions about your pay: contact HR.</p>
</div>
</body>
</html>
