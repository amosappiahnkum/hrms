<?php

namespace App\Http\Controllers;

use App\Models\Config\Department;
use App\Models\Config\LeaveType;
use App\Models\LeaveRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeaveAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $year = $request->integer('year', now()->year);

        $base = LeaveRequest::query()->whereYear('leave_requests.created_at', $year);

        if ($request->filled('department')) {
            $dept = Department::where('uuid', $request->department)->first();
            if ($dept) {
                $base->where('department_id', $dept->id);
            }
        }

        return response()->json([
            'data' => [
                'summary'       => $this->summary(clone $base),
                'by_status'     => $this->byStatus(clone $base),
                'by_type'       => $this->byType(clone $base, $year),
                'by_department' => $this->byDepartment(clone $base),
                'by_month'      => $this->byMonth(clone $base, $year),
            ],
        ]);
    }

    private function summary($query): array
    {
        $row = (clone $query)->selectRaw(
            'COUNT(*) as total,
             ROUND(AVG(days_requested), 1) as avg_days_requested,
             ROUND(AVG(NULLIF(days_approved, 0)), 1) as avg_days_approved,
             SUM(days_requested) as total_days_requested,
             SUM(days_approved) as total_days_approved'
        )->first();

        $approved = (clone $query)->where('status', 'hr_approved')->count();
        $rejected = (clone $query)
            ->whereIn('status', ['hr_rejected', 'hod_rejected'])
            ->count();

        $approvalRate = ($approved + $rejected) > 0
            ? round(($approved / ($approved + $rejected)) * 100, 1)
            : 0;

        return [
            'total'               => (int) $row->total,
            'total_days_requested' => (int) ($row->total_days_requested ?? 0),
            'total_days_approved'  => (int) ($row->total_days_approved ?? 0),
            'avg_days_requested'  => (float) ($row->avg_days_requested ?? 0),
            'avg_days_approved'   => (float) ($row->avg_days_approved ?? 0),
            'approval_rate'       => $approvalRate,
        ];
    }

    private function byStatus($query): array
    {
        $labels = [
            'pending'      => 'Pending',
            'hod_approved' => 'HOD Approved',
            'hod_rejected' => 'HOD Rejected',
            'hr_approved'  => 'HR Approved',
            'hr_rejected'  => 'HR Rejected',
            'canceled'     => 'Cancelled',
        ];

        $counts = (clone $query)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return collect($labels)
            ->map(fn($label, $status) => [
                'status' => $status,
                'label'  => $label,
                'count'  => (int) ($counts[$status] ?? 0),
            ])
            ->values()
            ->all();
    }

    private function byType($query, int $year): array
    {
        $rows = (clone $query)
            ->join('leave_types', 'leave_requests.leave_type_id', '=', 'leave_types.id')
            ->selectRaw(
                'app_leave_types.uuid,
                 app_leave_types.name,
                 COUNT(app_leave_requests.id) as count,
                 SUM(app_leave_requests.days_requested) as days_requested,
                 SUM(app_leave_requests.days_approved) as days_approved'
            )
            ->groupBy('leave_types.id', 'leave_types.uuid', 'leave_types.name')
            ->orderByDesc('count')
            ->get();

        // Include types with zero requests for this year
        $activeIds = $rows->pluck('uuid')->all();
        $zeroTypes = LeaveType::whereNotIn('uuid', $activeIds)
            ->get(['uuid', 'name'])
            ->map(fn($t) => [
                'id'             => $t->uuid,
                'name'           => $t->name,
                'count'          => 0,
                'days_requested' => 0,
                'days_approved'  => 0,
            ]);

        return $rows->map(fn($r) => [
            'id'             => $r->uuid,
            'name'           => $r->name,
            'count'          => (int) $r->count,
            'days_requested' => (int) ($r->days_requested ?? 0),
            'days_approved'  => (int) ($r->days_approved ?? 0),
        ])->concat($zeroTypes)->values()->all();
    }

    private function byDepartment($query): array
    {
        $rows = (clone $query)
            ->join('departments', 'leave_requests.department_id', '=', 'departments.id')
            ->selectRaw(
                'app_departments.uuid,
                 app_departments.name,
                 COUNT(app_leave_requests.id) as count,
                 SUM(app_leave_requests.days_requested) as days_requested'
            )
            ->groupBy('departments.id', 'departments.uuid', 'departments.name')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn($r) => [
            'id'             => $r->uuid,
            'name'           => $r->name,
            'count'          => (int) $r->count,
            'days_requested' => (int) ($r->days_requested ?? 0),
        ])->all();
    }

    private function byMonth($query, int $year): array
    {
        $rows = (clone $query)
            ->selectRaw(
                'MONTH(created_at) as month_num,
                 COUNT(*) as count,
                 SUM(days_requested) as days_requested,
                 SUM(days_approved) as days_approved'
            )
            ->groupBy('month_num')
            ->orderBy('month_num')
            ->get()
            ->keyBy('month_num');

        $monthNames = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        return collect(range(1, 12))->map(function ($m) use ($rows, $monthNames) {
            $row = $rows->get($m);
            return [
                'month'          => $monthNames[$m - 1],
                'count'          => (int) ($row?->count ?? 0),
                'days_requested' => (int) ($row?->days_requested ?? 0),
                'days_approved'  => (int) ($row?->days_approved ?? 0),
            ];
        })->all();
    }
}
