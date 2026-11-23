<?php

namespace App\Http\Controllers;

use App\Services\Leave\LeaveApprover;
use App\Services\Leave\LeaveBalance;
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
        return response()->json(['data' => $this->data()]);
    }

    private function data(): array
    {
        [$month, $day] = LeaveBalance::cycleStart();

        return [
            'approval_chain' => LeaveApprover::rule(),
            'department_levels' => \App\Models\Config\Department::levels(),
            'cycle_start' => sprintf('%02d-%02d', $month, $day),
        ];
    }

    public function update(Request $request): JsonResponse
    {
        // The leave year: saved on its own, from its own card.
        if ($request->has('cycle_start') && !$request->has('approval_chain')) {
            $value = $request->validate(['cycle_start' => ['required', 'date_format:m-d']])['cycle_start'];
            app(SettingService::class)->set('leave.cycle_start', $value);
            activity('leave-settings')->causedBy($request->user())->withProperties(['cycle_start' => $value])->log('Leave year start changed');

            return response()->json(['data' => $this->data(), 'message' => 'Saved.']);
        }

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

        return response()->json(['data' => $this->data(), 'message' => 'Saved.']);
    }
}
