<?php

namespace Database\Seeders;

use App\Models\JobCategory;
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

        $levels = JobCategory::query()->select('id', 'name')->get();

        foreach ($leaveTypes as $leaveType) {
            foreach ($levels as $level) {
                $leaveType->leaveTypeLevelConfigs()->updateOrCreate([
                    'job_category_id' => $level->id,
                ],[
                    'number_of_days' => 42,
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
