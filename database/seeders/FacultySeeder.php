<?php

namespace Database\Seeders;

use App\Models\Faculty;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FacultySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            'FACULTY OF ENGINEERING',
            'FACULTY OF APPLIED SCIENCE',
            'FACULTY OF BUSINESS STUDIES',
            'FACULTY OF APPLIED ARTS AND TECHNOLOGY',
            'FACULTY OF BUILT AND NATURAL ENVIRONMENT',
            'SCHOOL OF GRADUATE STUDIES',
            'FACULTY OF MEDIA TECHNOLOGY AND LIBERAL STUDIES',
            'FACULTY OF MARITIME AND NAUTICAL STUDIES',
            'FACULTY OF HEALTH AND ALLIED SCIENCES',
        ];

        foreach ($data as $d) {
            Faculty::updateOrCreate(['name' => $d], ['uuid' => Str::uuid()]);
        }
    }
}
