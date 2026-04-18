<?php

namespace App\Http\Controllers;

use App\Http\Resources\public\EmployeeResource;
use App\Models\Employee;
use Illuminate\Http\Request;

class PublicController extends Controller
{
    public function getEmployees(Request $request)
    {
        $employees = Employee::query()
            ->when($request->department, fn ($q, $v) => $q->where('department', $v))
            ->when($request->faculty,    fn ($q, $v) => $q->where('faculty', $v))
            ->when($request->search,     fn ($q, $v) => $q->where(function ($q) use ($v) {
                $q->where('first_name', 'like', "%{$v}%")
                    ->orWhere('last_name',  'like', "%{$v}%")
                    ->orWhere('email',      'like', "%{$v}%");
            }))
            ->orderBy($request->input('sort_by', 'last_name'), $request->input('sort_dir', 'asc'))
            ->paginate(15)
            ->withQueryString(); // preserves ?search=, ?department= etc. in pagination links


        return EmployeeResource::collection($employees);
    }
}
