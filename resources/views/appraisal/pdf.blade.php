<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Appraisal Report – {{ $attempt->user->name }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            background: #fff;
            line-height: 1.5;
        }

        .page { padding: 28px 32px; }

        /* ── Header ─────────────────────────────────────────────── */
        .header {
            border-bottom: 2px solid #1a1a2e;
            padding-bottom: 14px;
            margin-bottom: 16px;
        }
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .header-left { vertical-align: middle; }
        .header-right { vertical-align: middle; text-align: right; width: 100px; }

        .org-name {
            font-size: 15px;
            font-weight: bold;
            color: #1a1a2e;
            letter-spacing: 0.4px;
        }
        .report-title {
            font-size: 10px;
            color: #64748b;
            margin-top: 2px;
        }
        .org-tagline {
            font-size: 8.5px;
            color: #94a3b8;
            margin-top: 3px;
            font-style: italic;
        }
        .header-right img {
            max-width: 90px;
            max-height: 60px;
        }
        .generated-date {
            font-size: 7.5px;
            color: #94a3b8;
            margin-top: 4px;
        }

        /* ── Status badge ────────────────────────────────────────── */
        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 8px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-completed { background: #d1fae5; color: #065f46; }
        .status-confirmed { background: #ede9fe; color: #5b21b6; }
        .status-pending   { background: #fef3c7; color: #92400e; }

        /* ── Info grid ───────────────────────────────────────────── */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            page-break-inside: avoid;
        }
        .info-cell {
            width: 50%;
            padding: 10px 12px;
            vertical-align: top;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        .info-cell + .info-cell { border-left: none; }
        .info-label {
            font-size: 7.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 3px;
        }
        .info-value {
            font-size: 10px;
            color: #111827;
            font-weight: bold;
        }
        .info-sub {
            font-size: 8.5px;
            color: #6b7280;
            margin-top: 1px;
        }

        /* ── Score grid (3 cols) ─────────────────────────────────── */
        .score-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            page-break-inside: avoid;
        }
        .score-cell {
            width: 33.33%;
            padding: 12px 10px;
            text-align: center;
            border-right: 1px solid #e2e8f0;
            vertical-align: middle;
        }
        .score-cell:last-child { border-right: none; }
        .score-num {
            font-size: 18px;
            font-weight: bold;
            color: #1a1a2e;
            display: block;
        }
        .score-lbl {
            font-size: 7.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #64748b;
            margin-top: 3px;
            display: block;
        }

        /* ── Section heading ─────────────────────────────────────── */
        .section-title {
            font-size: 8.5px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #1a1a2e;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 5px;
            margin-bottom: 10px;
            margin-top: 18px;
        }

        /* ── Category heading ────────────────────────────────────── */
        .category-heading {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #0369a1;
            background: #e0f2fe;
            padding: 5px 10px;
            margin-top: 10px;
            margin-bottom: 6px;
            border-left: 3px solid #0369a1;
            page-break-after: avoid;
        }

        /* ── Response item ───────────────────────────────────────── */
        .response-item {
            border: 1px solid #e5e7eb;
            margin-bottom: 7px;
            page-break-inside: avoid;
        }
        .response-header {
            background: #f3f4f6;
            padding: 6px 10px;
        }
        .response-header-table {
            width: 100%;
            border-collapse: collapse;
        }
        .question-text-cell {
            font-size: 9.5px;
            font-weight: bold;
            color: #111827;
            vertical-align: top;
        }
        .score-badge-cell {
            text-align: right;
            white-space: nowrap;
            vertical-align: top;
            width: 50px;
        }
        .score-badge {
            display: inline-block;
            background: #1a1a2e;
            color: #fff;
            font-size: 8px;
            font-weight: bold;
            padding: 1px 7px;
            border-radius: 10px;
        }
        .response-body {
            padding: 8px 10px;
        }
        .answer-text {
            font-size: 9.5px;
            color: #374151;
        }
        .no-answer {
            font-size: 9px;
            color: #d1d5db;
            font-style: italic;
        }

        /* ── KPI table ───────────────────────────────────────────── */
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9.5px;
            page-break-inside: avoid;
        }
        .kpi-table th {
            background: #1a1a2e;
            color: #e2e8f0;
            padding: 6px 10px;
            text-align: left;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .kpi-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        .kpi-table tr { page-break-inside: avoid; }
        .kpi-table tr:nth-child(even) td { background: #f9fafb; }
        .achieve-good { font-weight: bold; color: #059669; }
        .achieve-warn { font-weight: bold; color: #d97706; }
        .achieve-bad  { font-weight: bold; color: #dc2626; }

        /* ── Timeline ────────────────────────────────────────────── */
        .timeline-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        .timeline-row { page-break-inside: avoid; }
        .timeline-dot-cell {
            width: 14px;
            vertical-align: top;
            padding-top: 3px;
        }
        .timeline-dot {
            width: 8px;
            height: 8px;
            background: #1a1a2e;
            border-radius: 4px;
            display: inline-block;
        }
        .timeline-content-cell {
            padding-bottom: 10px;
            vertical-align: top;
        }
        .timeline-event {
            font-size: 9px;
            font-weight: bold;
            color: #111827;
            text-transform: capitalize;
        }
        .timeline-meta { font-size: 8px; color: #9ca3af; }
        .timeline-comment {
            font-size: 8.5px;
            color: #6b7280;
            margin-top: 2px;
            font-style: italic;
        }

        /* ── Signatures ──────────────────────────────────────────── */
        .sig-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 40px;
            page-break-inside: avoid;
        }
        .sig-cell {
            width: 33.33%;
            text-align: center;
            padding: 0 14px;
            vertical-align: bottom;
        }
        .sig-line {
            border-top: 1px solid #9ca3af;
            margin-bottom: 5px;
        }
        .sig-label { font-size: 8px; color: #6b7280; }
        .sig-signed-name {
            font-size: 11px;
            font-weight: bold;
            font-style: italic;
            color: #1a1a2e;
            padding-bottom: 3px;
        }
        .sig-signed-date { font-size: 7.5px; color: #6b7280; margin-bottom: 2px; }
        .sig-pending { font-size: 8px; color: #d97706; font-style: italic; padding-bottom: 6px; }

        .text-muted { color: #9ca3af; font-style: italic; font-size: 9px; }
    </style>
</head>
<body>
<div class="page">

    {{-- ── Header ──────────────────────────────────────────────────── --}}
    <div class="header">
        <table class="header-table">
            <tr>
                <td class="header-left">
                    <div class="org-name">{{ $company->get('name', config('app.name')) }}</div>
                    <div class="report-title">Staff Performance Appraisal Report</div>
                    @if($company->get('tagline'))
                        <div class="org-tagline">{{ $company->get('tagline') }}</div>
                    @endif
                    <div class="generated-date">
                        Generated: {{ now()->format('d M Y, H:i') }}
                        &nbsp;&nbsp;
                        @php
                            $statusMap = [
                                'supervisor_confirmed' => ['Pending HR Review',    'status-pending'],
                                'pending_signatures'   => ['Pending Signatures',   'status-pending'],
                                'completed'            => ['Completed',             'status-completed'],
                            ];
                            [$statusLabel, $statusClass] = $statusMap[$attempt->status] ?? [$attempt->status, ''];
                        @endphp
                        <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
                    </div>
                </td>
                <td class="header-right">
                    @if($logoBase64)
                        <img src="{{ $logoBase64 }}" alt="{{ $company->get('abbreviation', '') }}">
                    @else
                        <div style="font-size:11px;font-weight:bold;color:#1a1a2e;">
                            {{ $company->get('abbreviation', '') }}
                        </div>
                    @endif
                </td>
            </tr>
        </table>
    </div>

    {{-- ── Employee & Assessment Info ─────────────────────────────── --}}
    <table class="info-table">
        <tr>
            <td class="info-cell">
                <div class="info-label">Employee</div>
                <div class="info-value">{{ $attempt->user->name }}</div>
                <div class="info-sub">{{ $attempt->user->email }}</div>
                @if($attempt->user->employee?->staff_id)
                    <div class="info-sub">Staff ID: {{ $attempt->user->employee->staff_id }}</div>
                @endif
                @if($attempt->user->employee?->department?->name)
                    <div class="info-sub">{{ $attempt->user->employee->department->name }}</div>
                @endif
                @if($attempt->user->employee?->rank?->name)
                    <div class="info-sub">{{ $attempt->user->employee->rank->name }}</div>
                @endif
            </td>
            <td class="info-cell">
                <div class="info-label">Assessment Window</div>
                <div class="info-value">{{ $attempt->window->title }}</div>
                <div class="info-sub">{{ $attempt->window->assessment->name }}</div>
                @if($attempt->submitted_at)
                    <div class="info-sub" style="margin-top:5px;">Submitted: {{ $attempt->submitted_at->format('d M Y') }}</div>
                @endif
                @if($attempt->supervisor_confirmed_at)
                    <div class="info-sub">Confirmed: {{ $attempt->supervisor_confirmed_at->format('d M Y') }}</div>
                @endif
                @if($attempt->finalized_at)
                    <div class="info-sub">Finalized: {{ $attempt->finalized_at->format('d M Y') }}</div>
                @endif
            </td>
        </tr>
    </table>

    @if($attempt->supervisor)
    <table class="info-table">
        <tr>
            <td class="info-cell">
                <div class="info-label">Supervisor / Confirmed By</div>
                <div class="info-value">{{ $attempt->supervisor->name }}</div>
                <div class="info-sub">{{ $attempt->supervisor->email }}</div>
            </td>
            <td class="info-cell">
                @if($attempt->supervisor_comment)
                    <div class="info-label">Supervisor Comment</div>
                    <div class="answer-text">{{ $attempt->supervisor_comment }}</div>
                @elseif($attempt->employee_comment)
                    <div class="info-label">Employee Comment</div>
                    <div class="answer-text">{{ $attempt->employee_comment }}</div>
                @endif
            </td>
        </tr>
    </table>
    @endif

    {{-- ── Score Grid ────────────────────────────────────────────── --}}
    @if($attempt->score !== null || $attempt->self_score !== null || $attempt->kpi_score !== null)
    <table class="score-table">
        <tr>
            @if($attempt->score !== null)
            <td class="score-cell">
                <span class="score-num">{{ number_format((float)$attempt->score, 2) }}</span>
                <span class="score-lbl">Overall Score</span>
            </td>
            @endif
            @if($attempt->self_score !== null)
            <td class="score-cell">
                <span class="score-num">{{ number_format((float)$attempt->self_score, 2) }}</span>
                <span class="score-lbl">Self Score</span>
            </td>
            @endif
            @if($attempt->kpi_score !== null)
            <td class="score-cell">
                <span class="score-num">{{ number_format((float)$attempt->kpi_score, 2) }}%</span>
                <span class="score-lbl">KPI Achievement</span>
            </td>
            @endif
        </tr>
    </table>
    @endif

    {{-- ── Responses grouped by category ────────────────────────── --}}
    <div class="section-title">Appraisal Responses</div>

    @php
        $grouped    = collect($responses)->groupBy('category');
        $questionNo = 1;
    @endphp

    @forelse($grouped as $category => $items)
        <div class="category-heading">{{ $category }}</div>

        @foreach($items as $resp)
        @php
            $effectiveAnswer = $resp['supervisor_answer'] ?? $resp['answer'];
            $effectiveScore  = $resp['supervisor_score']  ?? $resp['self_score'];
        @endphp
        <div class="response-item">
            <div class="response-header">
                <table class="response-header-table">
                    <tr>
                        <td class="question-text-cell">{{ $questionNo++ }}. {{ $resp['question_text'] }}</td>
                        @if($effectiveScore !== null)
                        <td class="score-badge-cell">
                            <span class="score-badge">{{ $effectiveScore }}</span>
                        </td>
                        @endif
                    </tr>
                </table>
            </div>
            <div class="response-body">
                <div class="{{ $effectiveAnswer ? 'answer-text' : 'no-answer' }}">
                    {{ $effectiveAnswer ?? 'No answer provided' }}
                </div>
            </div>
        </div>
        @endforeach
    @empty
        <p class="text-muted">No responses recorded.</p>
    @endforelse

    {{-- ── KPIs ──────────────────────────────────────────────────── --}}
    @if($kpis->isNotEmpty())
    <div class="section-title">Job-Specific KPIs</div>
    <table class="kpi-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Description</th>
                <th style="text-align:right;">Target</th>
                <th style="text-align:right;">Actual</th>
                <th style="text-align:right;">Achievement</th>
            </tr>
        </thead>
        <tbody>
        @foreach($kpis as $i => $kpi)
            @php
                $achievement  = null;
                $achieveClass = '';
                if ($kpi->actual !== null && (float)$kpi->target > 0) {
                    $achievement  = round(((float)$kpi->actual / (float)$kpi->target) * 100, 1);
                    $achieveClass = $achievement >= 100 ? 'achieve-good' : ($achievement >= 70 ? 'achieve-warn' : 'achieve-bad');
                }
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $kpi->description }}</td>
                <td style="text-align:right;">{{ number_format((float)$kpi->target, 2) }}</td>
                <td style="text-align:right;">{{ $kpi->actual !== null ? number_format((float)$kpi->actual, 2) : '—' }}</td>
                <td style="text-align:right;" class="{{ $achieveClass }}">{{ $achievement !== null ? $achievement . '%' : '—' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    @endif

    {{-- ── Timeline ───────────────────────────────────────────────── --}}
    @if($events->isNotEmpty())
    <div class="section-title">Activity Timeline</div>
    <table class="timeline-table">
        @php
            $eventLabels = [
                'submitted'             => 'Submitted by employee',
                'supervisor_confirmed'  => 'Confirmed by supervisor',
                'returned'              => 'Returned for revision',
                'answers_edited'        => 'Responses edited by supervisor',
                'employee_acknowledged' => 'Changes accepted by employee',
                'employee_disagreed'    => 'Employee disagreed with changes',
                'hr_completed'          => 'Finalized by HR',
                'employee_signed'       => 'Signed by employee',
                'supervisor_signed'     => 'Signed by supervisor',
            ];
        @endphp
        @foreach($events as $event)
        <tr class="timeline-row">
            <td class="timeline-dot-cell"><div class="timeline-dot"></div></td>
            <td class="timeline-content-cell">
                <div class="timeline-event">{{ $eventLabels[$event->event_type] ?? $event->event_type }}</div>
                <div class="timeline-meta">
                    {{ $event->actor?->name ?? 'System' }} &middot; {{ $event->created_at->format('d M Y, H:i') }}
                </div>
                @if($event->comment)
                    <div class="timeline-comment">"{{ $event->comment }}"</div>
                @endif
            </td>
        </tr>
        @endforeach
    </table>
    @endif

    {{-- ── Signatures ─────────────────────────────────────────────── --}}
    <table class="sig-table">
        <tr>
            <td class="sig-cell">
                @if($attempt->employee_signed_at)
                    <div class="sig-signed-name">{{ $attempt->user->name }}</div>
                    <div class="sig-signed-date">Signed: {{ $attempt->employee_signed_at->format('d M Y, H:i') }}</div>
                @else
                    <div class="sig-pending">Pending signature</div>
                @endif
                <div class="sig-line"></div>
                <div class="sig-label">{{ $attempt->user->name }}<br>Employee</div>
            </td>
            <td class="sig-cell">
                @if($attempt->supervisor_signed_at)
                    <div class="sig-signed-name">{{ $attempt->supervisor?->name ?? 'Supervisor' }}</div>
                    <div class="sig-signed-date">Signed: {{ $attempt->supervisor_signed_at->format('d M Y, H:i') }}</div>
                @else
                    <div class="sig-pending">Pending signature</div>
                @endif
                <div class="sig-line"></div>
                <div class="sig-label">{{ $attempt->supervisor?->name ?? 'Supervisor' }}<br>Supervisor / HOD</div>
            </td>
            <td class="sig-cell">
                @if(isset($hrFinalizer))
                    <div class="sig-signed-name">{{ $hrFinalizer->name }}</div>
                    @if($attempt->finalized_at)
                        <div class="sig-signed-date">Finalized: {{ $attempt->finalized_at->format('d M Y, H:i') }}</div>
                    @endif
                @endif
                <div class="sig-line"></div>
                <div class="sig-label">{{ isset($hrFinalizer) ? $hrFinalizer->name : 'HR Representative' }}<br>Human Resources</div>
            </td>
        </tr>
    </table>

</div>
</body>
</html>
