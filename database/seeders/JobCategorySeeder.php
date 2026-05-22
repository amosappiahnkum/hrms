<?php

namespace Database\Seeders;

use App\Models\JobCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class JobCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        /*$categories = [
            [
                'name' => 'Management/Key Officers',
                'leave_entitlement' => 45
            ],
            [
                'name' => 'Senior Members',
                'leave_entitlement' => 42
            ],
            [
                'name' => 'Senior Staff',
                'leave_entitlement' => 42
            ],
            [
                'name' => 'Junior Staff (lower certificate)',
                'leave_entitlement' => 35
            ],
            [
                'name' => 'Junior Staff (no certificate)',
                'leave_entitlement' => 32
            ],
        ];*/

        $categories = [
            [
                'name' => 'Senior Management',
                'leave_entitlement' => 0
            ],
            [
                'name' => 'Officers',
                'leave_entitlement' => 0
            ],
            [
                'name' => 'Junior Officers',
                'leave_entitlement' => 0
            ],
        ];

        foreach ($categories as $category) {
            JobCategory::updateOrCreate([
                'name' => $category['name']
            ], [
                'name' => $category['name'],
                'leave_entitlement' => $category['leave_entitlement']
            ]);
        }
    }
}
