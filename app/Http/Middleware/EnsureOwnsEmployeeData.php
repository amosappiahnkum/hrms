<?php

namespace App\Http\Middleware;

use App\Models\SelfService\Employee;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts employee-scoped endpoints to the owning employee. Users holding view-employee
 * (for reads) or edit-employee (for writes) may act on any employee's records.
 *
 * For everyone else it checks every bound route model (an Employee must be the user's
 * own record; any other model with an employee_id must belong to them) and every
 * employee reference in the request (employee_uuid, employee_id, employeeId).
 * Unfiltered GET listings are scoped to the user's own employee_uuid.
 */
class EnsureOwnsEmployeeData
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $bypass = $request->isMethodSafe() ? 'view-employee' : 'edit-employee';

        if ($user?->can($bypass)) {
            return $next($request);
        }

        $employee = $user?->employee;

        // User::employee() uses withDefault(), so check the record really exists.
        if (!$employee?->exists) {
            return $this->deny();
        }

        foreach ($request->route()?->parameters() ?? [] as $name => $parameter) {
            // Some actions take the employee uuid as a plain string instead of a bound model.
            if ($name === 'employee' && is_string($parameter)) {
                if ($parameter !== $employee->uuid) {
                    return $this->deny();
                }
                continue;
            }

            if (!$parameter instanceof Model) {
                continue;
            }

            if ($parameter instanceof Employee) {
                if ((int) $parameter->id !== (int) $employee->id) {
                    return $this->deny();
                }
                continue;
            }

            if (array_key_exists('employee_id', $parameter->getAttributes())
                && (int) $parameter->employee_id !== (int) $employee->id) {
                return $this->deny();
            }
        }

        foreach (['employee_uuid', 'employee_id', 'employeeId'] as $key) {
            if (!$request->filled($key)) {
                continue;
            }

            $value = (string) $request->input($key);

            if ($value !== $employee->uuid && $value !== (string) $employee->id) {
                return $this->deny();
            }
        }

        if ($request->isMethod('GET') && !$request->filled('employee_uuid')) {
            $request->merge(['employee_uuid' => $employee->uuid]);
        }

        return $next($request);
    }

    private function deny(): Response
    {
        return response()->json(['message' => 'You can only access your own records.'], 403);
    }
}
