<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run(): void
    {
        $this->call([
            TtuJobOpeningsSeeder::class,
//            UserSeeder::class,
//            JobCategorySeeder::class,
//            SubUnitSeeder::class,
//            RolesAndPermissionsSeeder::class,
//            TerminationReasonSeeder::class,
//            EducationLevelSeeder::class,
//            LeaveTypeSeeder::class,
//            PositionSeeder::class,
//            LeaveTypeLevelConfigSeeder::class,
//            LeaveRoleSeeder::class,
//            FacultySeeder::class,
//            SettingSeeder::class,
//            OrganizationSeeder::class,
        ]);
    }
}
