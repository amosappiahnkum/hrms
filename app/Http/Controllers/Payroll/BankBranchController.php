<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Bank;
use App\Models\Payroll\BankBranch;
use App\Models\Payroll\EmployeePayProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A bank's branches, with their sort codes (for bank upload files). Picked on pay details after the bank, where a
 * missing one can be added on the spot; duplicates are merged in Settings › Banks.
 */
class BankBranchController extends Controller
{
    public function index(Bank $bank): JsonResponse
    {
        $uses = EmployeePayProfile::whereNotNull('bank_branch_id')->selectRaw('bank_branch_id, count(*) as n')->groupBy('bank_branch_id')->pluck('n', 'bank_branch_id');

        return ApiResponse::success($bank->branches()->orderByDesc('active')->get()
            ->map(fn (BankBranch $b) => self::row($b) + ['uses' => (int) ($uses[$b->id] ?? 0)])->values());
    }

    /** The bank's active branches matching what's typed (name or sort code), for the picker. */
    public function search(Request $request, Bank $bank): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        return ApiResponse::success($bank->branches()->where('active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('sort_code', 'like', "%{$q}%")))
            ->limit(30)->get()->map(fn (BankBranch $b) => self::row($b))->values());
    }

    /** Add a branch to the bank; one with the same name or sort code (case and spaces aside) is returned instead. */
    public function store(Request $request, Bank $bank): JsonResponse
    {
        $data = $this->validated($request);
        $existing = BankBranch::findFor($bank, $data['name']) ?? (filled($data['sort_code']) ? BankBranch::findFor($bank, $data['sort_code']) : null);
        if ($existing) {
            return ApiResponse::success(self::row($existing), 'That branch is already on the list.');
        }

        return ApiResponse::success(self::row($bank->branches()->create($data + ['active' => true])), 'Branch added.', 201);
    }

    /** Rename it or change its sort code: pay details on it show the new name. */
    public function update(Request $request, BankBranch $bankBranch): JsonResponse
    {
        $data = $this->validated($request) + $request->validate(['active' => ['sometimes', 'boolean']]);
        $clash = BankBranch::where('bank_id', $bankBranch->bank_id)->whereKeyNot($bankBranch->id)->get()
            ->first(fn (BankBranch $b) => Bank::key($b->name) === Bank::key($data['name']));
        if ($clash) {
            throw new UserFacingException("{$bankBranch->bank->name} already has a {$clash->name} branch. Merge into it instead.");
        }
        DB::transaction(function () use ($bankBranch, $data) {
            $bankBranch->update($data);
            EmployeePayProfile::where('bank_branch_id', $bankBranch->id)->update(['bank_branch' => $bankBranch->name]);
        });

        return ApiResponse::success(self::row($bankBranch), 'Branch saved.');
    }

    /** Remove a branch nobody is paid through (otherwise merge it, or mark it inactive). */
    public function destroy(BankBranch $bankBranch): JsonResponse
    {
        if ($uses = EmployeePayProfile::where('bank_branch_id', $bankBranch->id)->count()) {
            throw new UserFacingException("{$bankBranch->name} is on {$uses} pay " . str('detail')->plural($uses) . '. Merge it into the right branch, or mark it inactive.');
        }
        $bankBranch->delete();

        return ApiResponse::success(null, 'Branch removed.');
    }

    /** Merge a duplicate into another branch of the same bank: pay details move across, and it's removed. */
    public function merge(Request $request, BankBranch $bankBranch): JsonResponse
    {
        $into = BankBranch::where('uuid', $request->validate(['into_uuid' => ['required', 'uuid']])['into_uuid'])->firstOrFail();
        if ($into->is($bankBranch) || $into->bank_id !== $bankBranch->bank_id) {
            throw new UserFacingException('Choose another branch of the same bank.');
        }
        DB::transaction(function () use ($bankBranch, $into) {
            EmployeePayProfile::where('bank_branch_id', $bankBranch->id)->update(['bank_branch_id' => $into->id, 'bank_branch' => $into->name]);
            $bankBranch->update(['merged_into_id' => $into->id, 'active' => false]);
            $bankBranch->delete();
        });

        return ApiResponse::success(self::row($into), "{$bankBranch->name} merged into {$into->name}.");
    }

    public static function row(BankBranch $b): array
    {
        return ['uuid' => $b->uuid, 'name' => $b->name, 'sort_code' => $b->sort_code, 'active' => $b->active];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'sort_code' => ['nullable', 'string', 'max:20'],
        ]);

        return ['name' => trim(preg_replace('/\s+/', ' ', $data['name'])), 'sort_code' => filled($data['sort_code'] ?? null) ? strtoupper(trim($data['sort_code'])) : null];
    }
}
