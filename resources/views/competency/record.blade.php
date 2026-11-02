@php
    $levels = ['Novice', 'Awareness', 'Developing', 'Competent', 'Proficient'];
    $level = fn ($l) => $l === null ? 'N/A' : $l . ' · ' . ($levels[$l] ?? '');
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('j M Y') : '—';
    $r = $record['readiness'];
    $s = $record['summary'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Development record – {{ $record['employee']['name'] }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #1a1a1a; line-height: 1.45; }
        .page { padding: 26px 30px; }
        .header { border-bottom: 2px solid #1a1a2e; padding-bottom: 10px; margin-bottom: 14px; }
        .header table { width: 100%; border-collapse: collapse; }
        .org { font-size: 14px; font-weight: bold; color: #1a1a2e; }
        .muted { color: #64748b; }
        .small { font-size: 8px; }
        .logo { text-align: right; width: 100px; }
        .logo img { max-width: 90px; max-height: 56px; }
        h1 { font-size: 13px; margin-bottom: 2px; }
        h2 { font-size: 10.5px; color: #1a1a2e; border-bottom: 1px solid #cbd5e1; padding-bottom: 3px; margin: 14px 0 6px; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { text-align: left; font-size: 8px; text-transform: uppercase; color: #64748b; border-bottom: 1px solid #cbd5e1; padding: 3px 4px; }
        table.grid td { border-bottom: 1px solid #eef2f7; padding: 4px; vertical-align: top; }
        .box { border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px 10px; margin-top: 8px; }
        .ok { color: #15803d; font-weight: bold; }
        .warn { color: #b45309; font-weight: bold; }
        .bad { color: #b91c1c; font-weight: bold; }
        .stats td { padding: 2px 14px 2px 0; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <table><tr>
            <td>
                <div class="org">{{ $company->get('name') ?? config('app.name') }}</div>
                <div class="muted">Employee development record · Competency matrix (AI-HR-CM-FM-01)</div>
            </td>
            <td class="logo">@if($logo)<img src="{{ $logo }}" alt="">@endif</td>
        </tr></table>
    </div>

    <h1>{{ $record['employee']['name'] }}</h1>
    <div class="muted">
        {{ implode(' · ', array_filter([$record['employee']['staff_id'], $record['position']['name'] ?? 'No position', $record['employee']['department']])) }}
    </div>

    <div class="box">
        @if($r['meets_requirements'])
            <span class="ok">Meets every requirement of the role.</span>
        @else
            <span class="{{ $s['gaps'] || $s['certification_gaps'] ? 'bad' : 'warn' }}">Does not yet meet every requirement of the role.</span>
        @endif
        <table class="stats" style="margin-top: 4px;"><tr>
            <td>Required: <b>{{ $s['required'] }}</b></td>
            <td>Meets: <b>{{ $s['met'] }}</b></td>
            <td>Gaps: <b>{{ $s['gaps'] }}</b></td>
            <td>Not rated: <b>{{ $s['not_rated'] }}</b></td>
            <td>Certificates missing/expired: <b>{{ $s['certification_gaps'] }}</b></td>
            <td>Open actions: <b>{{ $r['open_actions'] }}</b></td>
            <td>Authorized for: <b>{{ $r['authorized'] }}</b></td>
        </tr></table>
        <div class="muted small" style="margin-top: 3px;">
            @if($record['latest_assessment'])
                Last assessed {{ $date($record['latest_assessment']['assessed_on']) }}@if($record['latest_assessment']['assessor']) by {{ $record['latest_assessment']['assessor'] }}@endif · next review {{ $date($record['latest_assessment']['next_review_on']) }}
            @else
                Not assessed yet
            @endif
        </div>
    </div>

    <h2>Competencies</h2>
    @if(count($record['competencies']))
        <table class="grid">
            <tr><th>Competency</th><th>Type</th><th>Required</th><th>Current</th><th>Gap</th><th>Evidence</th></tr>
            @foreach($record['competencies'] as $c)
                <tr>
                    <td>{{ $c['competency']['name'] }}</td>
                    <td>{{ $c['competency']['group']['label'] ?? '' }}</td>
                    <td>{{ $level($c['required_level']) }}</td>
                    <td>{{ $c['assessed'] ? $level($c['level']) : 'Not rated' }}</td>
                    <td class="{{ $c['gap'] ? 'bad' : '' }}">{{ $c['gap'] ?: '—' }}</td>
                    <td>{{ $c['evidence'] }}@if(count($c['files'])) <span class="muted">({{ count($c['files']) }} file{{ count($c['files']) > 1 ? 's' : '' }})</span>@endif</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">No competencies required.</p>
    @endif

    @if(count($record['certifications']))
        <h2>Certificates</h2>
        <table class="grid">
            <tr><th>Certificate</th><th></th><th>Status</th><th>Expires</th></tr>
            @foreach($record['certifications'] as $c)
                <tr>
                    <td>{{ $c['type']['name'] }}</td>
                    <td>{{ $c['mandatory'] ? 'Mandatory' : 'Recommended' }}</td>
                    <td class="{{ in_array($c['status'], ['missing', 'expired']) && $c['mandatory'] ? 'bad' : ($c['status'] === 'expiring' ? 'warn' : '') }}">{{ ucfirst($c['status']) }}</td>
                    <td>{{ $c['certificate'] ? ($c['certificate']['expiry_date'] ? $date($c['certificate']['expiry_date']) : 'Does not expire') : '—' }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Authorizations</h2>
    @if(count($record['authorizations']))
        <table class="grid">
            <tr><th>Activity</th><th>Status</th><th>Valid until</th><th>Note</th></tr>
            @foreach($record['authorizations'] as $a)
                <tr>
                    <td>{{ $a['activity']['name'] }}</td>
                    <td>{{ $a['status']['label'] }}</td>
                    <td>{{ $a['valid_until'] ? $date($a['valid_until']) : ($a['status']['value'] === 'authorized' ? 'Until revoked' : '—') }}</td>
                    <td>{{ $a['reason'] }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">Not authorized for any activity.</p>
    @endif

    <h2>Development actions</h2>
    @if(count($record['actions']))
        <table class="grid">
            <tr><th>Competency</th><th>How</th><th>Status</th><th>Due / done</th><th>Effectiveness</th></tr>
            @foreach($record['actions'] as $a)
                <tr>
                    <td>{{ $a['competency']['name'] ?? '' }}@if($a['description'])<br><span class="muted">{{ $a['description'] }}</span>@endif</td>
                    <td>{{ $a['method']['label'] }}@if($a['training'])<br><span class="muted">{{ $a['training']['title'] }}</span>@endif</td>
                    <td>{{ $a['status']['label'] }}</td>
                    <td>{{ $a['completed_on'] ? 'Done ' . $date($a['completed_on']) : ($a['due_on'] ? 'Due ' . $date($a['due_on']) : '—') }}</td>
                    <td>
                        @if($a['effectiveness'])
                            {{ $a['effectiveness']['result']['label'] }} · level {{ $a['effectiveness']['verified_level'] }}
                            <br><span class="muted">{{ $date($a['effectiveness']['evaluated_on']) }}@if($a['effectiveness']['evaluated_by']) by {{ $a['effectiveness']['evaluated_by'] }}@endif</span>
                        @else — @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="muted">None.</p>
    @endif

    @if(count($record['trainings']))
        <h2>Training</h2>
        <table class="grid">
            <tr><th>Training</th><th>When</th><th>Status</th><th>Hours / result</th><th>Evaluation</th></tr>
            @foreach($record['trainings'] as $t)
                <tr>
                    <td>{{ $t['title'] }}@if(count($t['competencies']))<br><span class="muted">For: {{ implode(', ', $t['competencies']) }}</span>@endif</td>
                    <td>{{ $t['year'] }} {{ $t['quarter'] }}@if($t['completed_at'])<br><span class="muted">done {{ $date($t['completed_at']) }}</span>@endif</td>
                    <td>{{ $t['status']['label'] ?? '' }}</td>
                    <td>
                        {{ $t['hours'] !== null ? (float) $t['hours'] . ' h' : '' }}
                        @if($t['score'] !== null) · {{ (float) $t['score'] }}% @endif
                        @if($t['passed'] !== null) · {{ $t['passed'] ? 'passed' : 'not passed' }} @endif
                    </td>
                    <td>
                        @if($t['feedback'] && $t['feedback']['status'] === 'submitted') Feedback {{ $t['feedback']['rating'] }}/5 @endif
                        @if($t['review'] && $t['review']['status'] === 'submitted')
                            <br>{{ $t['review']['applied_on_job'] ? 'Applied on the job' : 'Not applied on the job' }} ({{ $t['review']['rating'] }}/5)
                        @elseif($t['review'])
                            <br><span class="muted">Review from {{ $date($t['review']['due_on']) }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if(count($record['history']))
        <h2>Assessment history</h2>
        <table class="grid">
            <tr><th>Assessed</th><th>By</th><th>Position</th><th>Comment</th></tr>
            @foreach($record['history'] as $h)
                <tr>
                    <td>{{ $date($h['assessed_on']) }}</td>
                    <td>{{ $h['assessor'] }}</td>
                    <td>{{ $h['position'] }}</td>
                    <td>{{ $h['comment'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p class="muted small" style="margin-top: 16px;">Generated {{ now()->format('j M Y, H:i') }}. A controlled record: the system is the source of truth.</p>
</div>
</body>
</html>
