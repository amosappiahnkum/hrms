<?php

namespace Database\Seeders;

use App\Models\LeaveType;
use Illuminate\Database\Seeder;

class LeaveTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Annual Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Casual Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Maternity Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Study Leave with Pay',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'years',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Study leave without Pay',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'years',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Study Leave with Pay and Sponsorship',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'years',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Sick Leave/Excuse Duty Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Compassionate Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Sabbatical Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'years',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Examination Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Leave of Absence',
                'description' => null,
                'entitlement_type' => 'custom',
                'request_type' => 'days',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
        ];
        /*$types = [
            [
                'name' => 'Annual Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Maternity Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Sick Leave/Excuse Duty Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ],
            [
                'name' => 'Bereaved Leave',
                'description' => null,
                'entitlement_type' => 'custom',
                'number_of_days' => 0,
                'start_of_annual_cycle' => '2023-01-01',
                'allow_half_day' => 1,
                'allow_carry_forward' => 0,
                'maximum_allotment' => 0,
                'maximum_consecutive_days' => 0,
                'should_request_before' => 1,
                'user_id' => 1
            ]
        ];*/

        foreach ($types as $type) {
            LeaveType::updateOrCreate(['name' => $type['name']], $type);
        }
    }
}
