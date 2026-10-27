<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveApprover;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Who approves leave before HR: the employee's supervisor, then optionally heads above them (none, a number, up to
 * a level, or to the top). Applies to leave requested from then on; requests already made keep their approvers.
 */
class LeaveSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => ['approval_chain' => LeaveApprover::rule(), 'department_levels' => \App\Models\Config\Department::levels()]]);
    }

    public function update(Request $request): JsonResponse
    {
        $levels = count(\App\Models\Config\Department::levels());
        $data = $request->validate([
            'approval_chain.stop'  => ['required', Rule::in(['count', 'level', 'top'])],
            'approval_chain.count' => ['nullable', 'required_if:approval_chain.stop,count', 'integer', 'between:0,10'],
            'approval_chain.level' => ['nullable', 'required_if:approval_chain.stop,level', 'integer', 'min:0', 'max:' . max(0, $levels - 1)],
        ])['approval_chain'];
        $rule = ['stop' => $data['stop']] + match ($data['stop']) {
            'count' => ['count' => (int) $data['count']],
            'level' => ['level' => (int) $data['level']],
            default => [],
        };
        app(SettingService::class)->set('leave.approval_chain', $rule);
        activity('leave-settings')->causedBy($request->user())->withProperties($rule)->log('Leave approvers changed');

        return response()->json(['data' => ['approval_chain' => LeaveApprover::rule(), 'department_levels' => \App\Models\Config\Department::levels()], 'message' => 'Saved.']);
    }
}
