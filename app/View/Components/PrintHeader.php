<?php

namespace App\View\Components;

use App\Http\Resources\EmployeeResource;
use App\Models\SelfService\Employee;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PrintHeader extends Component
{
    protected $employeeId;
    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct($employeeId)
    {
        $this->employeeId = $employeeId;
    }
    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $employee = Employee::find($this->employeeId);

        $employeeResource = new EmployeeResource($employee);
        return view('components.print-header', compact('employeeResource'));
    }
}
