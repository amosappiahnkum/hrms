<?php

namespace Database\Seeders;

use App\Models\SelfService\Rank;
use Illuminate\Database\Seeder;

class RankSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $ranks = [
            ['name' => 'Associate Professor'],
            ['name' => 'Senior Lecturer'],
            ['name' => 'Lecturer'],
            ['name' => 'Assistant Lecturer'],
            ['name' => 'Principal Instructor'],
            ['name' => 'Director of Works & Physical Dev\'t.'],
            ['name' => 'Director of Finance'],
            ['name' => 'Deputy Director of Finance'],
            ['name' => 'Procurement Officer'],
            ['name' => 'Internal Auditor'],
            ['name' => 'Senior Counsellor'],
            ['name' => 'Snr. Assistant Guid. & Counsellor Off.'],
            ['name' => 'Snr. Assistant Development Officer'],
            ['name' => 'Assistant Registrar (Legal)'],
            ['name' => 'Assistant Registrar (Safety Officer)'],
            ['name' => 'Senior Assistant Planning Officer'],
            ['name' => 'Deputy Registrar'],
            ['name' => 'Senior Assistant Registrar'],
            ['name' => 'Assistant Accountant'],
            ['name' => 'Accountant'],
            ['name' => 'Assistant Librarian'],
            ['name' => 'University Archivist (Analg. to Asst. Reg.)'],
            ['name' => 'Junior Asst. Development Officer'],
            ['name' => 'Assistant Internal Auditor'],
            ['name' => 'Assistant Procurement Officer'],
            ['name' => 'Asst. Development Officer'],
            ['name' => 'Jnr. Assistant Librarian'],
            ['name' => 'Jnr. Asst. Procurement Officer'],
            ['name' => 'Jnr. Asst. Registrar'],
            ['name' => 'Physician Assistant'],
            ['name' => 'RCHN'],
            ['name' => 'SCHN'],
            ['name' => 'Nursing Officer'],
            ['name' => 'Staff Midwife'],
            ['name' => 'Staff Nurse'],
            ['name' => 'Enrolled Nurse'],
            ['name' => 'Community Health Nurse'],
        ];

        foreach ($ranks as $rank) {
            Rank::firstOrCreate($rank);
        }
    }
}
