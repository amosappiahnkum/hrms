@php
    $money = fn ($n) => $n === null || (float) $n == 0 ? '–' : number_format((float) $n, 2);
    $date = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '—';
    $h = $header;
    $pay = $h['payment'];
    $rows = max(count($earnings), count($deductions));
    $rateLabel = fn ($r) => match ($r['applies_to']) {
        'basic'      => 'Exchange rate: basic salary',
        'allowances' => 'Exchange rate: allowances',
        default      => 'Exchange rate',
    };
    $rateDate = collect($h['exchange_rates'])->pluck('date')->filter()->max();
    // At least two decimals, more when the rate has them: 13.00, 11.3933.
    $rate = fn ($n) => preg_replace('/(\.\d\d\d*?)0+$/', '$1', number_format((float) $n, 6));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip – {{ $h['name'] }} – {{ $run->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9px; color: #1a1a1a; line-height: 1.4; }
        .page { padding: 26px 30px; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .letterhead { margin-bottom: 18px; }
        .letterhead .logo img { max-width: 90px; max-height: 60px; }
        .letterhead .org { text-align: right; vertical-align: top; }
        .org-name { font-size: 11px; font-weight: bold; }
        .meta td { padding: 2px 4px 2px 0; vertical-align: top; }
        .meta .k { font-weight: bold; white-space: nowrap; }
        .meta .v { text-align: right; padding-right: 14px; }
        .lines { margin-top: 12px; }
        .lines th { background: #7a9a3a; color: #fff; text-align: left; padding: 3px 5px; font-size: 9px; }
        .lines th.amt { text-align: right; }
        .lines td { padding: 3px 5px; }
        .lines .name { background: #eceff3; width: 25%; }
        .lines .amt { text-align: right; width: 25%; white-space: nowrap; }
        .lines .gap td { padding: 4px 0; }
        .sum td { font-weight: bold; padding: 5px; }
        .sum .name { background: #eceff3; }
        .sum .amt { background: #eceff3; text-align: right; }
        .payment { margin-top: 22px; border-top: 1px solid #1a1a1a; }
        .payment td { padding: 4px 4px 0 0; vertical-align: top; }
        .payment .lbl { color: #64748b; font-size: 8.5px; }
        h2 { font-size: 8.5px; text-transform: uppercase; color: #64748b; letter-spacing: .4px; margin: 16px 0 4px; }
        .records td { padding: 2px 4px; border-bottom: 1px solid #eef2f7; }
        .records .amt { text-align: right; }
    </style>
</head>
<body>
<div class="page">
    <table class="letterhead"><tr>
        <td class="logo">@if($logo)<img src="{{ $logo }}" alt="">@endif</td>
        <td class="org">
            <div class="org-name">{{ $company->get('name') ?? config('app.name') }}</div>
            @if($company->get('address'))<div class="muted">{!! nl2br(e($company->get('address'))) !!}</div>@endif
            <div class="muted" style="margin-top: 4px;">Payslip · {{ $h['month'] }}@if($h['off_cycle']) (off-cycle)@endif</div>
        </td>
    </tr></table>

    <table class="meta">
        <tr>
            <td class="k">Staff name</td><td class="v">{{ $h['name'] }}</td>
            <td class="k">Employee no.</td><td class="v">{{ $h['staff_id'] ?? '—' }}</td>
            <td class="k">Month</td><td class="v">{{ $h['month'] }}</td>
        </tr>
        <tr>
            <td class="k">Position</td><td class="v">{{ $h['position'] ?? '—' }}</td>
            <td class="k">Period start date</td><td class="v">{{ $date($h['period_start']) }}</td>
            <td class="k">Social security no.</td><td class="v">{{ $h['ssnit_number'] ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Engagement date</td><td class="v">{{ $date($h['engagement_date']) }}</td>
            <td class="k">Period end date</td><td class="v">{{ $date($h['period_end']) }}</td>
            <td class="k">Payment date</td><td class="v">{{ $date($h['pay_date']) }}</td>
        </tr>
        <tr>
            <td class="k">Cost centre</td><td class="v">{{ $h['cost_centre'] ?? '—' }}</td>
            <td class="k">{{ $h['days'] ? 'Days worked' : '' }}</td><td class="v">@if($h['days']){{ $h['days']['worked'] }} of {{ $h['days']['working'] }}@endif</td>
            <td colspan="2"></td>
        </tr>
        @foreach($h['exchange_rates'] as $r)
            <tr>
                <td colspan="4"></td>
                <td class="k">{{ $rateLabel($r) }}</td>
                <td class="v">{{ $r['currency'] }} 1 to {{ $h['currency'] }} {{ $rate($r['rate']) }}</td>
            </tr>
        @endforeach
        @if($rateDate)
            <tr><td colspan="4"></td><td class="k">Exchange rate date</td><td class="v">{{ $date($rateDate) }}</td></tr>
        @endif
    </table>

    <table class="lines">
        <tr><th>Earnings</th><th class="amt">Amount</th><th>Deductions</th><th class="amt">Amount</th></tr>
        @for($i = 0; $i < $rows; $i++)
            @php($e = $earnings[$i] ?? null)
            @php($d = $deductions[$i] ?? null)
            <tr>
                <td class="name">@if($e){{ $e['name'] }}@if($e['quantity'] !== null) <span class="muted">({{ $e['quantity'] }})</span>@endif @endif</td>
                <td class="amt">@if($e){{ $money($e['amount']) }}@endif</td>
                <td class="name">{{ $d['name'] ?? '' }}</td>
                <td class="amt">@if($d){{ $money($d['amount']) }}@endif</td>
            </tr>
        @endfor
        <tr class="gap"><td colspan="4"></td></tr>
        <tr class="sum">
            <td class="name">Gross pay</td><td class="amt">{{ number_format($totals['gross_pay'], 2) }}</td>
            <td class="name">Total deductions</td><td class="amt">{{ number_format($totals['total_deductions'], 2) }}</td>
        </tr>
        @if($showEmployer)
            <tr class="gap"><td colspan="4"></td></tr>
            @foreach($employer as $c)
                <tr class="sum"><td class="name">{{ $c['name'] }}</td><td class="amt">{{ $money($c['amount']) }}</td><td colspan="2"></td></tr>
            @endforeach
            <tr class="gap"><td colspan="4"></td></tr>
            <tr class="sum">
                <td class="name">Staff cost</td><td class="amt">{{ number_format($totals['staff_cost'], 2) }}</td>
                <td class="name">Net salary</td><td class="amt">{{ number_format($totals['net_pay'], 2) }}</td>
            </tr>
        @else
            <tr class="gap"><td colspan="4"></td></tr>
            <tr class="sum"><td colspan="2"></td><td class="name">Net salary</td><td class="amt">{{ number_format($totals['net_pay'], 2) }}</td></tr>
        @endif
    </table>

    <table class="payment">
        <tr><td colspan="4" style="font-weight: bold; padding-top: 6px;">Payment details</td></tr>
        <tr>
            <td>{{ $pay['account'] ?? ($pay['method_label'] ?? '—') }}<div class="lbl">{{ $pay['method'] === 'bank' ? 'Account number' : 'Paid by' }}</div></td>
            <td>{{ $pay['bank_name'] ?? '' }}@if($pay['bank_name'])<div class="lbl">Bank</div>@endif</td>
            <td>{{ $pay['bank_branch'] ?? '' }}@if($pay['bank_branch'])<div class="lbl">Branch</div>@endif</td>
            <td style="text-align: right;"><strong>{{ number_format($totals['net_pay'], 2) }}</strong><div class="lbl">Take home pay ({{ $h['currency'] }})</div></td>
        </tr>
    </table>

    <h2>For your records</h2>
    <table class="records">
        <tr><td>Taxable income</td><td class="amt">{{ number_format((float) $payslip->taxable_income, 2) }}</td></tr>
        @if($ytd)
            <tr><td>Year to date: gross pay</td><td class="amt">{{ number_format((float) $ytd->gross, 2) }}</td></tr>
            <tr><td>Year to date: income tax</td><td class="amt">{{ number_format((float) $ytd->paye, 2) }}</td></tr>
            <tr><td>Year to date: SSNIT</td><td class="amt">{{ number_format((float) $ytd->ssnit, 2) }}</td></tr>
            <tr><td>Year to date: net pay</td><td class="amt">{{ number_format((float) $ytd->net, 2) }}</td></tr>
        @endif
    </table>

    <p class="muted" style="margin-top: 16px; font-size: 8px;">Amounts in {{ $h['currency'] }}. Generated {{ now()->format('j M Y') }}. Questions about your pay: contact HR.</p>
</div>
</body>
</html>
