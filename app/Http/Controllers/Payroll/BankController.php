<?php

namespace App\Http\Controllers\Payroll;

use App\Exceptions\UserFacingException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\Bank;
use App\Models\Payroll\EmployeePayProfile;
use App\Models\Payroll\PaymentFileLayout;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Banks employees are paid through. Picked on pay details (where a missing one can be added on the spot) and on
 * payment file layouts; duplicates are merged here.
 */
class BankController extends Controller
{
    public function index(): JsonResponse
    {
        $uses = $this->uses();

        return ApiResponse::success(Bank::orderByDesc('active')->orderBy('name')->get()
            ->map(fn (Bank $b) => self::row($b) + ['uses' => $uses[$b->id] ?? 0])->values());
    }

    /** Active banks matching what's typed (name or code), for the pickers. */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));

        return ApiResponse::success(Bank::where('active', true)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', "%{$q}%")->orWhere('code', 'like', "%{$q}%")))
            ->orderBy('name')->limit(30)->get()->map(fn (Bank $b) => self::row($b))->values());
    }

    /** Add a bank; one with the same name or code (case and spaces aside) is returned instead of a copy. */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        if ($existing = Bank::findByName($data['name']) ?? (filled($data['code'] ?? null) ? Bank::findByName($data['code']) : null)) {
            return ApiResponse::success(self::row($existing), 'That bank is already on the list.');
        }

        return ApiResponse::success(self::row(Bank::create($data + ['active' => true])), 'Bank added.', 201);
    }

    /** Rename it or change its code: pay details and layouts follow (their saved name is updated too). */
    public function update(Request $request, Bank $bank): JsonResponse
    {
        $data = $this->validated($request, $bank) + $request->validate(['active' => ['sometimes', 'boolean']]);
        $clash = Bank::whereKeyNot($bank->id)->get()->first(fn (Bank $b) => Bank::key($b->name) === Bank::key($data['name']));
        if ($clash) {
            throw new UserFacingException("There's already a bank called {$clash->name}. Merge into it instead.");
        }
        DB::transaction(function () use ($bank, $data) {
            $bank->update($data);
            EmployeePayProfile::where('bank_id', $bank->id)->update(['bank_name' => $bank->name]);
        });

        return ApiResponse::success(self::row($bank), 'Bank saved.');
    }

    /** Remove a bank nobody is paid through (otherwise merge it, or mark it inactive). */
    public function destroy(Bank $bank): JsonResponse
    {
        if ($uses = $this->uses()[$bank->id] ?? 0) {
            throw new UserFacingException("{$bank->name} is on {$uses} pay " . str('detail')->plural($uses) . ' or payment files. Merge it into the right bank, or mark it inactive.');
        }
        $bank->delete();

        return ApiResponse::success(null, 'Bank removed.');
    }

    /**
     * Merge a duplicate into the right bank: pay details and payment file layouts move across, and it's removed.
     * Payslips already made keep the name they were made with.
     */
    public function merge(Request $request, Bank $bank): JsonResponse
    {
        $into = Bank::where('uuid', $request->validate(['into_uuid' => ['required', 'uuid']])['into_uuid'])->firstOrFail();
        if ($into->is($bank)) {
            throw new UserFacingException('Choose a different bank to merge into.');
        }
        DB::transaction(function () use ($bank, $into) {
            EmployeePayProfile::where('bank_id', $bank->id)->update(['bank_id' => $into->id, 'bank_name' => $into->name]);
            PaymentFileLayout::where('bank_id', $bank->id)->update(['bank_id' => $into->id]);
            // Its branches go with it (merge any that duplicate the other bank's afterwards).
            \App\Models\Payroll\BankBranch::where('bank_id', $bank->id)->update(['bank_id' => $into->id]);
            $bank->update(['merged_into_id' => $into->id, 'active' => false]);
            $bank->delete();
        });
        activity('payroll')->causedBy($request->user())->performedOn($into)->event('bank_merged')
            ->withProperties(['merged' => $bank->name, 'into' => $into->name])->log("Bank {$bank->name} merged into {$into->name}");

        return ApiResponse::success(self::row($into), "{$bank->name} merged into {$into->name}.");
    }

    public static function row(Bank $b): array
    {
        return ['uuid' => $b->uuid, 'name' => $b->name, 'code' => $b->code, 'active' => $b->active];
    }

    /** Pay details and payment file layouts using each bank: id => count. */
    private function uses(): array
    {
        $out = [];
        foreach ([EmployeePayProfile::query(), PaymentFileLayout::query()] as $q) {
            foreach ($q->whereNotNull('bank_id')->selectRaw('bank_id, count(*) as n')->groupBy('bank_id')->pluck('n', 'bank_id') as $id => $n) {
                $out[$id] = ($out[$id] ?? 0) + (int) $n;
            }
        }

        return $out;
    }

    private function validated(Request $request, ?Bank $bank = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:30'],
        ]);

        return ['name' => trim(preg_replace('/\s+/', ' ', $data['name'])), 'code' => filled($data['code'] ?? null) ? strtoupper(trim($data['code'])) : null];
    }
}
