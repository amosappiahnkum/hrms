<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\OvertimeRequest;
use App\Models\Payroll\TimesheetDay;
use App\Models\Payroll\TimesheetEntry;
use App\Models\Payroll\WorkSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Where work is done: chosen on timesheets and overtime requests. Anyone filling those can type a new one in;
 * it waits for HR's review (keep it, merge it into another, or discard it).
 */
class WorkSiteController extends Controller
{
    public function index(): JsonResponse
    {
        $sites = WorkSite::with('creator:id,name')->orderByDesc('needs_review')->orderByDesc('active')->orderBy('code')->get();
        $uses = $this->uses($sites->pluck('id')->all());

        return ApiResponse::success($sites->map(fn ($s) => self::row($s) + [
            'created_by' => $s->needs_review ? $s->creator?->name : null,
            'created_at' => $s->created_at?->toIso8601String(),
            'uses'       => $uses[$s->id] ?? 0,
        ])->values());
    }

    /** Active sites matching what's typed (code or name), for the pickers. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? '');

        return ApiResponse::success(WorkSite::where('active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('name')->limit(30)->get()->map(fn ($s) => self::row($s))->values());
    }

    /**
     * A site typed in that isn't on the list: made (not offshore, to review) unless one with that name or code
     * exists (ignoring case and spaces), which is returned instead.
     */
    public function quick(Request $request): JsonResponse
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $request->validate(['name' => ['required', 'string', 'max:255']])['name']));
        $key = self::key($name);
        $existing = WorkSite::where('active', true)->get()->first(fn ($s) => self::key($s->name) === $key || self::key($s->code) === $key);
        if ($existing) {
            return ApiResponse::success(self::row($existing), 'That site is already on the list.');
        }

        $site = WorkSite::create([
            'code' => $this->newCode($name), 'name' => $name, 'is_offshore' => false, 'active' => true,
            'needs_review' => true, 'created_by' => $request->user()->id,
        ]);

        return ApiResponse::success(self::row($site), 'Site added. HR will check it.', 201);
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success(self::row(WorkSite::create($this->validated($request))), 'Site added.', 201);
    }

    /** Saving a site (by HR) also marks a typed-in one as reviewed. */
    public function update(Request $request, WorkSite $workSite): JsonResponse
    {
        $workSite->update($this->validated($request, $workSite) + ['needs_review' => false]);

        return ApiResponse::success(self::row($workSite), 'Site saved.');
    }

    /**
     * Remove a site. One typed in and still to review is discarded, which only works while nothing uses it
     * (otherwise merge it). Other sites are kept for the records that name them (soft deleted).
     */
    public function destroy(WorkSite $workSite): JsonResponse
    {
        if ($workSite->needs_review && ($uses = $this->uses([$workSite->id])[$workSite->id] ?? 0)) {
            throw new UserFacingException("{$workSite->name} is used on {$uses} " . str('timesheet day or request')->plural($uses) . '. Merge it into the right site instead.');
        }
        $workSite->delete();

        return ApiResponse::success(null, $workSite->needs_review ? 'Site discarded.' : 'Site removed.');
    }

    /**
     * Merge a site into another: its timesheet entries and days and overtime requests move to that site, and it is
     * removed. Refused while anything using it has been paid in a pay run sent for approval (payslips keep what
     * they were made with).
     */
    public function merge(Request $request, WorkSite $workSite): JsonResponse
    {
        $into = WorkSite::where('uuid', $request->validate(['into_uuid' => ['required', 'uuid']])['into_uuid'])->firstOrFail();
        if ($into->is($workSite)) {
            throw new UserFacingException('Choose a different site to merge into.');
        }
        $closed = fn ($r) => $r->whereNotIn('status', ['draft', 'calculated']);
        $sheets = TimesheetDay::where(fn ($d) => $d->where('work_site_id', $workSite->id)->orWhereHas('entries', fn ($e) => $e->where('work_site_id', $workSite->id)))
            ->distinct()->pluck('timesheet_id');
        $locked = OvertimeRequest::where('work_site_id', $workSite->id)->whereHas('payRun', $closed)->exists()
            || \App\Models\Payroll\TimeInput::whereIn('timesheet_id', $sheets)->whereHas('payRun', $closed)->exists();
        if ($locked) {
            throw new UserFacingException("{$workSite->name} is on pay already sent for approval, so it can't be merged. Rename it instead.");
        }

        DB::transaction(function () use ($workSite, $into) {
            TimesheetEntry::withTrashed()->where('work_site_id', $workSite->id)->update(['work_site_id' => $into->id]);
            TimesheetDay::where('work_site_id', $workSite->id)->update(['work_site_id' => $into->id]);
            OvertimeRequest::withTrashed()->where('work_site_id', $workSite->id)->update(['work_site_id' => $into->id, 'location' => $into->name]);
            $workSite->update(['merged_into_id' => $into->id, 'needs_review' => false, 'active' => false]);
            $workSite->delete();
        });
        activity('payroll')->causedBy(request()->user())->performedOn($into)->event('site_merged')
            ->withProperties(['merged' => $workSite->name, 'into' => $into->name])->log("Site {$workSite->name} merged into {$into->name}");

        return ApiResponse::success(self::row($into), "{$workSite->name} merged into {$into->name}.");
    }

    public static function row(WorkSite $s): array
    {
        return [
            'uuid' => $s->uuid, 'code' => $s->code, 'name' => $s->name, 'is_offshore' => $s->is_offshore, 'active' => $s->active,
            'needs_review' => (bool) $s->needs_review,
        ];
    }

    /** How many timesheet days and entries and overtime requests use each site: id => count. */
    private function uses(array $ids): array
    {
        $count = fn ($query) => $query->whereIn('work_site_id', $ids)->selectRaw('work_site_id, count(*) as n')->groupBy('work_site_id')->pluck('n', 'work_site_id');
        $out = [];
        foreach ([$count(TimesheetEntry::query()), $count(TimesheetDay::query()->whereDoesntHave('entries')), $count(OvertimeRequest::query())] as $counts) {
            foreach ($counts as $id => $n) {
                $out[$id] = ($out[$id] ?? 0) + (int) $n;
            }
        }

        return $out;
    }

    /** "Tema  Port" and "tema port" are the same site. */
    private static function key(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($text));
    }

    /** A code for a typed-in site: its initials or first letters, unique (e.g. "Tema Port" → TP, then TP2). */
    private function newCode(string $name): string
    {
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $name)));
        $base = count($words) > 1 ? implode('', array_map(fn ($w) => $w[0], $words)) : ($words[0] ?? 'SITE');
        $base = substr(strtoupper($base), 0, 8) ?: 'SITE';
        $code = $base;
        for ($n = 2; WorkSite::withTrashed()->where('code', $code)->exists(); $n++) {
            $code = $base . $n;
        }

        return $code;
    }

    private function validated(Request $request, ?WorkSite $site = null): array
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        return $request->validate([
            'code'        => ['required', 'string', 'max:20', Rule::unique('work_sites', 'code')->ignore($site?->id)->whereNull('deleted_at')],
            'name'        => ['required', 'string', 'max:255'],
            'is_offshore' => ['boolean'],
            'active'      => ['boolean'],
        ], ['code.unique' => 'Another site has this code.']);
    }
}
