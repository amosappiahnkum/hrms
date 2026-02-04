<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeLevelConfigSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $leaveTypes = LeaveType::all();

        $levels = ['management', 'senior_member', 'senior_staff', 'junior_staff'];

        foreach ($leaveTypes as $leaveType) {
            foreach ($levels as $type) {
                $leaveType->leaveTypeLevelConfigs()->updateOrCreate([
                    'employee_level' => $type,
                ],[
//                    'entitlement_type' => 'custom',
                    'number_of_days' => 45,
                    'allow_half_day' => true,
                    'allow_carry_forward' => 0,
                    'maximum_allotment' => 45,
                    'maximum_consecutive_days' => 30,
                    'should_request_before' => 1
                ]);
            }
        }
    }
}
