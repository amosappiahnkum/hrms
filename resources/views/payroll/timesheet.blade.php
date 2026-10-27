@php
    $columns = $sheet['columns'];
    $value = function (array $c, $v) {
        if ($v === null || $v === false || $v === '') return '–';
        return $c['type'] === 'check' ? '✓' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    };
    $date = fn (?string $d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : '';
    $freelance = $sheet['template'] === 'freelance';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Timesheet – {{ $sheet['employee']['name'] }} – {{ $sheet['label'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 8px; color: #1a1a1a; line-height: 1.35; }
        .page { padding: 22px 26px; }
        table { width: 100%; border-collapse: collapse; }
        .muted { color: #64748b; }
        .head td { vertical-align: top; }
        .head .logo img { max-width: 70px; max-height: 44px; }
        .title { text-align: right; font-size: 11px; font-weight: bold; }
        .meta { margin: 10px 0 8px; }
        .meta td { padding: 2px 6px 2px 0; }
        .meta .k { color: #64748b; white-space: nowrap; }
        .meta .v { border-bottom: 1px solid #94a3b8; font-weight: bold; }
        .grid th, .grid td { border: 1px solid #cbd5e1; padding: 2px 3px; text-align: center; }
        .grid th { background: #f1f5f9; font-weight: bold; font-size: 7.5px; }
        .grid td.day { text-align: left; white-space: nowrap; }
        .grid tr.off td { background: #f8fafc; color: #64748b; }
        .grid tr.total td { font-weight: bold; background: #eef2f7; }
        .note { margin-top: 6px; font-size: 7px; color: #475569; }
        .sign { margin-top: 14px; }
        .sign td { width: 33%; vertical-align: top; padding-right: 10px; }
        .sign .line { border-bottom: 1px solid #1a1a1a; height: 14px; }
        .sign .lbl { color: #64748b; font-size: 7px; }
        .reject { margin-top: 10px; border: 1px solid #fca5a5; padding: 5px; }
    </style>
</head>
<body>
<div class="page">
    <table class="head"><tr>
        <td class="logo">@if($logo)<img src="{{ $logo }}" alt="">@endif</td>
        <td class="title">{{ $freelance ? 'FREELANCE TIMESHEET' : 'EMPLOYEE MONTHLY TIMESHEET' }}<div class="muted" style="font-size: 8px; font-weight: normal;">{{ $company->get('name') ?? config('app.name') }}</div></td>
    </tr></table>

    <table class="meta"><tr>
        <td class="k">Employee name</td><td class="v">{{ $sheet['employee']['name'] }}</td>
        <td class="k">Employee ID</td><td class="v">{{ $sheet['employee']['staff_id'] ?? '—' }}</td>
        <td class="k">Month</td><td class="v">{{ $sheet['label'] }}</td>
        <td class="k">Period</td><td class="v">{{ $date($sheet['period_start']) }} to {{ $date($sheet['period_end']) }}</td>
    </tr>
    @if($freelance)
        <tr>
            <td class="k">Client</td><td class="v">{{ $sheet['client']['name'] ?? '—' }}</td>
            <td class="k">Contract ref / PO</td><td class="v">{{ $sheet['work_order']['number'] ?? '—' }}</td>
            <td class="k">Department</td><td class="v">{{ $sheet['employee']['department'] ?? '—' }}</td>
            <td colspan="2"></td>
        </tr>
        @if($sheet['task_description'])<tr><td class="k">Task description</td><td class="v" colspan="7">{{ $sheet['task_description'] }}</td></tr>@endif
    @endif
    </table>

    <table class="grid">
        <tr>
            <th style="text-align: left;">Days / dates</th>
            @foreach($columns as $c)<th>{{ $c['label'] }}</th>@endforeach
            <th>Site worked</th>
            <th>Leave</th>
        </tr>
        @foreach($sheet['days'] as $d)
            <tr class="{{ $d['kind'] !== 'weekday' ? 'off' : '' }}">
                <td class="day">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('l, j F Y') }}@if($d['holiday']) <span class="muted">({{ $d['holiday'] }})</span>@endif</td>
                @foreach($columns as $c)<td>{{ $value($c, ((array) $d['values'])[$c['key']] ?? null) }}</td>@endforeach
                {{-- Staff sheets: every site worked that day (planned overtime aside). --}}
                <td>{{ isset($d['entries']) ? (collect($d['entries'])->reject(fn ($e) => $e['planned'])->pluck('work_site.code')->filter()->unique()->implode(' / ') ?: '–') : ($d['work_site']['code'] ?? '–') }}</td>
                <td>{{ $d['is_leave'] ? '✓' : '–' }}</td>
            </tr>
        @endforeach
        <tr class="total">
            <td class="day">Total</td>
            @foreach($columns as $c)<td>{{ $sheet['totals']['columns'][$c['key']] ?? 0 }}</td>@endforeach
            <td>{{ $sheet['totals']['days_worked'] ?? 0 }} days worked</td>
            <td>{{ $freelance ? '' : ($sheet['totals']['leave_days'] ?? 0) }}</td>
        </tr>
    </table>
    <p class="note">Ticked columns show ✓ for the days they apply; hour columns show the hours. Overtime hours are entered only where overtime was requested by the supervisor.</p>

    @if($sheet['status'] === 'rejected')
        <div class="reject">
            <strong>Rejected</strong> {{ $sheet['rejected_at'] ? \Illuminate\Support\Carbon::parse($sheet['rejected_at'])->format('j M Y, H:i') : '' }}
            @if($sheet['rejection_reason'])<div>Reason for rejection: {{ $sheet['rejection_reason'] }}</div>@endif
        </div>
    @endif

    <table class="sign"><tr>
        <td>
            <div class="line">{{ $sheet['employee']['name'] }}</div>
            <div class="lbl">Employee{{ $sheet['submitted_at'] ? ' · submitted ' . \Illuminate\Support\Carbon::parse($sheet['submitted_at'])->format('j M Y') : '' }}</div>
        </td>
        @if($freelance)
            <td>
                <div class="line">{{ $sheet['client_rep_name'] }}</div>
                <div class="lbl">Client representative or supervisor{{ $sheet['client_signed_on'] ? ' · ' . $date($sheet['client_signed_on']) : '' }}</div>
            </td>
        @endif
        @foreach($decisions as $d)
            <td>
                <div class="line">{{ $d->decider?->name }}</div>
                <div class="lbl">{{ $d->step_name }} · {{ $d->decision === 'approved' ? 'approved' : 'rejected' }} {{ $d->created_at->format('j M Y') }}</div>
            </td>
        @endforeach
    </tr></table>
</div>
</body>
</html>
