<?php

namespace Database\Seeders;

use App\Models\Recruitment\Candidate;
use Illuminate\Database\Seeder;

class CandidateSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Candidate::factory(50)->create();
    }
}
