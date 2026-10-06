<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\PayRunInputsTemplate;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Payroll\PayRun;
use App\Models\Payroll\PayRunInput;
use App\Services\Payroll\PayRunInputs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A pay run's variable items: list, add, edit, remove, and import from Excel. */
class PayRunInputController extends Controller
{
    public function __construct(private readonly PayRunInputs $inputs)
    {
    }

    public function index(PayRun $payRun): JsonResponse
    {
        return ApiResponse::success(PayRunInput::where('pay_run_id', $payRun->id)->with(['employee', 'component'])->latest('id')->get()->map(fn ($i) => $this->row($i))->values());
    }

    public function store(Request $request, PayRun $payRun): JsonResponse
    {
        $input = $this->inputs->save($payRun, $this->validated($request), $request->user());

        return ApiResponse::success($this->row($input->load(['employee', 'component'])), 'Input added.', 201);
    }

    public function update(Request $request, PayRun $payRun, PayRunInput $payRunInput): JsonResponse
    {
        abort_unless($payRunInput->pay_run_id === $payRun->id, 404);
        $input = $this->inputs->save($payRun, $this->validated($request, true), $request->user(), $payRunInput);

        return ApiResponse::success($this->row($input->load(['employee', 'component'])), 'Input saved.');
    }

    public function destroy(PayRun $payRun, PayRunInput $payRunInput): JsonResponse
    {
        abort_unless($payRunInput->pay_run_id === $payRun->id, 404);
        $this->inputs->delete($payRun, $payRunInput);

        return ApiResponse::success(null, 'Input removed.');
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new PayRunInputsTemplate(), 'pay-run-inputs.xlsx');
    }

    public function import(Request $request, PayRun $payRun): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:5120']]);
        $rows = IOFactory::load($request->file('file')->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        array_shift($rows); // headings

        $result = $this->inputs->import($payRun, $rows, $request->user());

        return $result['errors']
            ? ApiResponse::error('Nothing was imported: fix these rows and try again.', ['rows' => $result['errors']], 422)
            : ApiResponse::success($result, "Imported {$result['created']} input(s).");
    }

    private function validated(Request $request, bool $editing = false): array
    {
        return $request->validate([
            'employee_uuid'      => [$editing ? 'prohibited' : 'required', 'string'],
            'pay_component_uuid' => [$editing ? 'prohibited' : 'required', 'string'],
            'quantity'           => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'amount'             => ['nullable', 'numeric', 'min:0'],
            'notes'              => ['nullable', 'string', 'max:255'],
        ]);
    }

    private function row(PayRunInput $i): array
    {
        return [
            'uuid'      => $i->uuid,
            'employee'  => ['uuid' => $i->employee?->uuid, 'name' => trim(preg_replace('/\s+/', ' ', (string) $i->employee?->name)), 'staff_id' => $i->employee?->staff_id],
            'component' => ['uuid' => $i->component?->uuid, 'code' => $i->component?->code, 'name' => $i->component?->name, 'calculation' => $i->component?->calculation->value, 'unit' => $i->component?->unit, 'kind' => $i->component?->kind->value],
            'quantity'  => $i->quantity !== null ? (float) $i->quantity : null,
            'amount'    => $i->amount !== null ? (float) $i->amount : null,
            'source'    => $i->source,
            'notes'     => $i->notes,
        ];
    }
}
