<?php

namespace App\Imports;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Rank;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Concerns\Importable;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithProgressBar;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Spatie\Permission\Models\Role;

class EmployeeImport implements ToModel, WithHeadingRow, WithProgressBar
{
    use Importable;

    /**
     * @param array $row
     * @return mixed
     */
    public function model(array $row)
    {
        $department = Department::firstOrCreate([
            'name' => $row['department']
        ]);

        $position = Position::firstOrCreate([
            'name' => $row['position']
        ]);

        $email = $row['work_email'];
        $employee = Employee::updateOrCreate([
            'first_name' => $row['first_name'] ?: '',
            'middle_name' => $row['other_names'] ?: '',
            'last_name' => $row['last_name'] ?: '',
            'staff_id' => $row['staff_id'] ?: '',
        ], [
            'title' => $row['title'] ?: '',
            'first_name' => $row['first_name'] ?: '',
            'middle_name' => $row['other_names'] ?: '',
            'last_name' => $row['last_name'] ?: '',
            'staff_id' => $row['staff_id'] ?: '',
            'dob' => Carbon::parse(Date::excelToDateTimeObject($row['date_of_birth']))->format('Y-m-d'),
            'gender' => $row['gender'] ?: '',
            'job_type' => $row['type'] ?: '',
            'ssnit_number' => $row['ssnit_number'] ?: '',
            'gtec_placement' => null,
            'rank_id' => null,
            'department_id' => $department->id,
            'user_id' => 1
        ]);

        $employee->contactDetail()->create([
            'telephone' => $row['phone_number'] ?: '',
            'work_telephone' => $row['other_phone_number'] ?: '',
            'work_email' => $email ?: '',
            'other_email' => $row['personal_email'] ?: '',
            'user_id' => 1,
        ]);

        $employee->jobDetail()->create([
            'status' => null,
            'position_id' => $position->id,
        ]);

        if (!empty($email)) {
            $staffRole = Role::query()->where('name', 'staff')->first();

            $cleanEmail = Str::of($email)
                ->trim()
                ->replace(' ', '')
                ->lower();
            $username = Str::before($cleanEmail, '@');

            $user = User::updateOrcreate(['username' => $username], [
                'name' => $employee->name,
                'username' => $username,
                'email' => $cleanEmail,
                'password' => Hash::make('password'),
                'phone_number' => $row['phone_number'] ?: '',
                'employee_id' => $employee->id,
                'uuid' => Str::uuid(),
            ]);

            $employee->update(['user_id' => $user->id]);
            $user->assignRole($staffRole);
        }

        return $employee;
    }
}
