<?php

namespace Database\Seeders;

use App\Models\Config\EducationLevel;
use Illuminate\Database\Seeder;

class EducationLevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        $levels = [
            ['name' => 'Junior High/Secondary School'],
            ['name' => 'Senior Secondary School'],
            ['name' => 'Graduate'],
            ['name' => 'Post Graduate'],
        ];

        foreach ($levels as $level){
            EducationLevel::firstOrCreate($level);
        }
    }
}
