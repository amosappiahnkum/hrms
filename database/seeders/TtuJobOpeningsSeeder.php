<?php

namespace Database\Seeders;

use App\Models\Recruitment\JobOpening;
use Illuminate\Database\Seeder;

class TtuJobOpeningsSeeder extends Seeder
{
    public function run(): void
    {
        $deadline = '2026-06-05';
        $location = 'Takoradi, Ghana';

        // ── Shared requirements text ──────────────────────────────────────────

        $lecturerRequirements = implode("\n", [
            "• A PhD in a relevant field",
            "• Relevant professional qualifications where applicable",
            "• Considerable teaching experience in a university or analogous institution",
            "• Industrial experience will be an added advantage",
            "",
            "APPLICATION DOCUMENTS",
            "Applicants must submit:",
            "- Application letter indicating the position applied for",
            "- Current Curriculum Vitae",
            "- Copies of Academic/Professional certificates with evaluation reports from the Ghana Tertiary Education Commission (GTEC)",
            "- Transcripts",
            "- Other relevant supporting documents",
            "- Contact details of three (3) referees",
            "",
            "Send to: vc@ttu.edu.gh or registrar@ttu.edu.gh",
            "Or deliver to: The Registrar, Takoradi Technical University, P.O. Box 256, Takoradi – Ghana",
        ]);

        $researchFellowRequirements = implode("\n", [
            "• A PhD in a relevant field",
            "• Considerable research experience in a university or comparable institution",
            "• A strong publication record",
            "• Grant writing experience and potential for attracting research funding",
            "• Competence in both quantitative and qualitative research",
            "",
            "APPLICATION DOCUMENTS",
            "Applicants must submit:",
            "- Application letter indicating the position applied for",
            "- Current Curriculum Vitae",
            "- Copies of Academic/Professional certificates with evaluation reports from the Ghana Tertiary Education Commission (GTEC)",
            "- Transcripts",
            "- Other relevant supporting documents",
            "- Contact details of three (3) referees",
            "",
            "Send to: vc@ttu.edu.gh or registrar@ttu.edu.gh",
            "Or deliver to: The Registrar, Takoradi Technical University, P.O. Box 256, Takoradi – Ghana",
        ]);

        // ── Teaching disciplines ──────────────────────────────────────────────

        $teachingDisciplines = [
            [
                'field'   => 'Chemical Pathology / Histopathology / Immunology',
                'faculty' => 'Faculty of Health and Allied Sciences',
            ],
            [
                'field'   => 'Maritime Engineering',
                'faculty' => 'Faculty of Maritime and Nautical Sciences',
            ],
            [
                'field'   => 'Nautical Science / Related field',
                'faculty' => 'Faculty of Maritime and Nautical Sciences',
            ],
            [
                'field'   => 'Renewable Energy Engineering (Electrical/Electronic background)',
                'faculty' => 'Faculty of Engineering',
            ],
            [
                'field'   => 'Welding and Fabrication',
                'faculty' => 'Faculty of Engineering',
            ],
            [
                'field'   => 'Refrigeration and Air Conditioning / Mechanical Engineering (Production Option)',
                'faculty' => 'Faculty of Engineering',
            ],
            [
                'field'   => 'Structural Engineering',
                'faculty' => 'Faculty of Engineering',
            ],
            [
                'field'   => 'Media and Communication Studies',
                'faculty' => 'Faculty of Media Technology and Liberal Studies',
            ],
            [
                'field'   => 'Information Systems Security',
                'faculty' => 'Faculty of Applied Sciences',
            ],
            [
                'field'   => 'Software Engineering / Related field',
                'faculty' => 'Faculty of Applied Sciences',
            ],
            [
                'field'   => 'Data Sciences / Related field',
                'faculty' => 'Faculty of Applied Sciences',
            ],
            [
                'field'   => 'Plumbing and Gas or Environmental Engineering (Plumbing and Gas background)',
                'faculty' => 'Faculty of Built and Natural Environment',
            ],
        ];

        // ── Seed Lecturer and Research Fellow openings ────────────────────────

        foreach ($teachingDisciplines as $discipline) {
            // Lecturer
            JobOpening::updateOrCreate(
                [
                    'title'    => "Lecturer – {$discipline['field']}",
                    'location' => $location,
                ],
                [
                    'description'  => implode("\n\n", [
                        "Takoradi Technical University invites applications from suitably qualified candidates for the position of Lecturer in {$discipline['field']}.",
                        "Faculty: {$discipline['faculty']}",
                        "The successful candidate will be responsible for teaching at undergraduate and postgraduate levels, developing course materials, supervising student projects and dissertations, and contributing to the academic and research activities of the department.",
                    ]),
                    'requirements' => $lecturerRequirements,
                    'status'       => 'open',
                    'deadline'     => $deadline,
                ]
            );

            // Research Fellow
            JobOpening::updateOrCreate(
                [
                    'title'    => "Research Fellow – {$discipline['field']}",
                    'location' => $location,
                ],
                [
                    'description'  => implode("\n\n", [
                        "Takoradi Technical University invites applications from suitably qualified candidates for the position of Research Fellow in {$discipline['field']}.",
                        "Faculty: {$discipline['faculty']}",
                        "The successful candidate will conduct high-quality research, publish in peer-reviewed journals, seek and manage research grants, mentor junior researchers, and contribute to the research profile of the university.",
                    ]),
                    'requirements' => $researchFellowRequirements,
                    'status'       => 'open',
                    'deadline'     => $deadline,
                ]
            );
        }

        // ── Non-teaching positions ────────────────────────────────────────────

        JobOpening::updateOrCreate(
            [
                'title'    => 'Nurse Manager',
                'location' => $location,
            ],
            [
                'description'  => implode("\n\n", [
                    "Takoradi Technical University invites applications from suitably qualified candidates for the position of Nurse Manager.",
                    "The Nurse Manager will oversee the day-to-day clinical and administrative operations of the University's Health Centre, ensure the delivery of high-quality patient care, manage nursing staff, and maintain compliance with health regulations and professional standards.",
                ]),
                'requirements' => implode("\n", [
                    "• A Master's degree in Nursing or Midwifery from a recognised and accredited institution",
                    "• Current registration as a State Registered Nurse (SRN) with the Nurses and Midwifery Council",
                    "• Considerable post-qualification working experience",
                    "• Ability to critically assess cases within the scope of approved professional regulations and make sound general decisions",
                    "",
                    "APPLICATION DOCUMENTS",
                    "Applicants must submit:",
                    "- Application letter indicating the position applied for",
                    "- Current Curriculum Vitae",
                    "- Copies of Academic/Professional certificates with evaluation reports from the Ghana Tertiary Education Commission (GTEC)",
                    "- Transcripts",
                    "- Other relevant supporting documents",
                    "- Contact details of three (3) referees",
                    "",
                    "Send to: vc@ttu.edu.gh or registrar@ttu.edu.gh",
                    "Or deliver to: The Registrar, Takoradi Technical University, P.O. Box 256, Takoradi – Ghana",
                ]),
                'status'   => 'open',
                'deadline' => $deadline,
            ]
        );

        JobOpening::updateOrCreate(
            [
                'title'    => 'Maintenance Engineer',
                'location' => $location,
            ],
            [
                'description'  => implode("\n\n", [
                    "Takoradi Technical University invites applications from suitably qualified candidates for the position of Maintenance Engineer.",
                    "The Maintenance Engineer will be responsible for the planning, coordination, and supervision of all maintenance activities across the University's facilities and infrastructure, ensuring that buildings, equipment, and utilities are maintained to the highest standard.",
                ]),
                'requirements' => implode("\n", [
                    "• A minimum of a Master's Degree in Built Environment, Estate Management, or a related field",
                    "• Membership of the Ghana Institute of Engineers or a relevant professional body",
                    "• Considerable post-qualification practical, hands-on experience in maintenance within a university or analogous institution",
                    "• Strong knowledge of facilities maintenance systems and infrastructure management in a university or analogous institution",
                    "",
                    "APPLICATION DOCUMENTS",
                    "Applicants must submit:",
                    "- Application letter indicating the position applied for",
                    "- Current Curriculum Vitae",
                    "- Copies of Academic/Professional certificates with evaluation reports from the Ghana Tertiary Education Commission (GTEC)",
                    "- Transcripts",
                    "- Other relevant supporting documents",
                    "- Contact details of three (3) referees",
                    "",
                    "Send to: vc@ttu.edu.gh or registrar@ttu.edu.gh",
                    "Or deliver to: The Registrar, Takoradi Technical University, P.O. Box 256, Takoradi – Ghana",
                ]),
                'status'   => 'open',
                'deadline' => $deadline,
            ]
        );
    }
}
