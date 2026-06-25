<?php

namespace Database\Seeders;

use App\Models\Appraisal\Assessment;
use App\Models\JobCategory;
use App\Models\QuestionBank\Question;
use App\Models\QuestionBank\QuestionCategory;
use App\Models\QuestionBank\QuestionUsage;
use App\Models\User;
use Illuminate\Database\Seeder;

class AppraisalSeeder extends Seeder
{
    // ── Organisation-specific template definitions ────────────────────────────
    //
    // Each entry maps a template name → the job category names it applies to.
    // Add a new key here to support a new organisation (ORGANIZATION env value).
    //
    private array $orgTemplates = [
        'abave' => [
            [
                'name' => 'Managerial Performance Appraisal',
                'description' => 'Performance appraisal template for managerial staff.',
                'questions_key' => 'managerial',
                'job_categories' => ['Senior Management'],
            ],
            [
                'name' => 'Non-Managerial Performance Appraisal',
                'description' => 'Performance appraisal template for non-managerial staff.',
                'questions_key' => 'non_managerial',
                'job_categories' => ['Officers', 'Junior Officers'],
            ],
        ],
    ];

    public function run(): void
    {
        $org = strtolower(env('ORGANIZATION', 'ttu'));

        if (!isset($this->orgTemplates[$org])) {
            $this->command->warn("No appraisal template definitions found for organisation \"{$org}\". Skipping.");
            return;
        }

        $adminUser = User::first();

        // ── Question categories ───────────────────────────────────────────────

        $nonManagerialParent = QuestionCategory::updateOrCreate(
            ['name' => 'Non-Managerial Performance', 'parent_id' => null],
            ['description' => 'Appraisal criteria for non-managerial staff', 'is_active' => true, 'user_id' => $adminUser->id]
        );

        $managerialParent = QuestionCategory::updateOrCreate(
            ['name' => 'Managerial Performance', 'parent_id' => null],
            ['description' => 'Appraisal criteria for managerial staff', 'is_active' => true, 'user_id' => $adminUser->id]
        );

        // ── Build question sets ───────────────────────────────────────────────

        $questionSets = [
            'non_managerial' => $this->createSectionsAndQuestions($this->nonManagerialSections(), $nonManagerialParent, $adminUser),
            'managerial' => $this->createSectionsAndQuestions($this->managerialSections(), $managerialParent, $adminUser),
        ];

        // ── Seed templates for this organisation ──────────────────────────────

        $templateCount = 0;

        foreach ($this->orgTemplates[$org] as $def) {
            $categoryIds = JobCategory::whereIn('name', $def['job_categories'])->pluck('id')->toArray();

            $this->createTemplate(
                $def['name'],
                $def['description'],
                $categoryIds,
                $questionSets[$def['questions_key']],
                $adminUser
            );

            $templateCount++;
        }

        $this->command->info("Appraisal seeder [{$org}]: {$templateCount} templates seeded.");
    }

    // ── Template creation ─────────────────────────────────────────────────────

    private function createTemplate(string $name, string $description, array $jobCategoryIds, array $questions, User $user): void
    {
        $template = Assessment::updateOrCreate(
            ['title' => $name, 'type' => 'appraisal'],
            [
                'description' => $description,
                'is_active' => true,
                'user_id' => $user->id,
            ]
        );

        // Sync many-to-many job categories
        $template->jobCategories()->sync($jobCategoryIds);

        foreach ($questions as $item) {
            QuestionUsage::updateOrCreate(
                [
                    'question_id' => $item['question']->id,
                    'usable_type' => Assessment::class,
                    'usable_id' => $template->id,
                ],
                [
                    'order' => $item['order'],
                    'user_id' => $user->id,
                ]
            );
        }
    }

    // ── Question bank builders ────────────────────────────────────────────────

    private function createSectionsAndQuestions(array $sections, QuestionCategory $parent, User $user): array
    {
        $allQuestions = [];

        foreach ($sections as $sectionOrder => $section) {
            $category = QuestionCategory::updateOrCreate(
                ['name' => $section['name'], 'parent_id' => $parent->id],
                ['is_active' => true, 'user_id' => $user->id]
            );

            foreach ($section['questions'] as $qOrder => $text) {
                $question = Question::updateOrCreate(
                    ['text' => $text, 'question_category_id' => $category->id],
                    [
                        'type' => 'rating',
                        'weight' => 1,
                        'is_active' => true,
                        'is_required' => true,
                        'order' => $qOrder,
                        'user_id' => $user->id,
                    ]
                );

                if ($question->options()->doesntExist()) {
                    foreach ([
                                 ['option_text' => 'Poor', 'option_value' => '1', 'order' => 1],
                                 ['option_text' => 'Average', 'option_value' => '2', 'order' => 2],
                                 ['option_text' => 'Good', 'option_value' => '3', 'order' => 3],
                                 ['option_text' => 'Very Good', 'option_value' => '4', 'order' => 4],
                                 ['option_text' => 'Excellent', 'option_value' => '5', 'order' => 5],
                             ] as $opt) {
                        $question->options()->create(array_merge($opt, ['user_id' => $user->id]));
                    }
                }

                $allQuestions[] = ['question' => $question, 'order' => ($sectionOrder * 100) + $qOrder];
            }
        }

        return $allQuestions;
    }

    // ── Question data ─────────────────────────────────────────────────────────

    private function nonManagerialSections(): array
    {
        return [
            [
                'name' => 'Job Knowledge & Quality of Work',
                'questions' => [
                    'Demonstrates a thorough understanding of all aspects of the job and excellent technical competence.',
                    'Maintains a high work load, is able to keep pace with the work flow for the job and meet deadlines.',
                    'Follows appropriate procedure in executing work and executes work accurately, thoroughly and without errors.',
                    'Has met expected targets for the appraisal period.',
                ],
            ],
            [
                'name' => 'Work Ethics & Attitude',
                'questions' => [
                    'Attendance to work (Punctuality).',
                    'Is enthusiastic about work and consistently looks for new opportunities and responsibilities.',
                    'Generates ideas and uses available resources efficiently (time, company resources, information, people etc.).',
                    'Is able to complete tasks assigned with minimum supervision/direction.',
                    'Demonstrates willingness and ability to learn new things.',
                ],
            ],
            [
                'name' => 'Initiative & Commitment',
                'questions' => [
                    'Demonstrates thorough understanding of all aspects of the position and completes/finishes tasks, not giving up.',
                    'Maintains regular attendance at work and follows through on commitments.',
                    'Is conscientious and works to very high standards.',
                    'Demonstrates ability to adapt to changes in tasks and performance expectations.',
                ],
            ],
            [
                'name' => 'Interpersonal Relations',
                'questions' => [
                    'Builds excellent informal working relationships and cooperates with others – is a team player.',
                    'Readily accepts supervision and welcomes suggestions and constructive criticism.',
                    'Demonstrates courtesy and respect for clients, work colleagues and superiors.',
                    'Works well with others and demonstrates willingness to support and solicit the help of others to achieve company goals.',
                ],
            ],
            [
                'name' => 'Accountability',
                'questions' => [
                    'Provides colleagues with required information in a timely manner using the approved channels.',
                    'Is able to communicate effectively both orally and in writing.',
                    'Takes responsibility for all work activities and personal actions.',
                    'Maintains confidentiality with sensitive information.',
                ],
            ],
        ];
    }

    private function managerialSections(): array
    {
        return [
            [
                'name' => 'Leadership & Problem Solving',
                'questions' => [
                    'Is able to promptly make sound and effective decisions based on relevant information.',
                    'Possesses exceptional analytical skills, anticipates and plans for potential problems and is able to generate creative approaches to addressing them.',
                    'Takes the lead, through actions and words, and influences people to perform at their best.',
                    'Follows through on commitments and quickly adapts to changes in tasks and performance expectations.',
                ],
            ],
            [
                'name' => 'Job Knowledge & Quality of Work (Managerial)',
                'questions' => [
                    'Demonstrates a thorough understanding of all aspects of the job and excellent technical competence.',
                    'Performs to agreed-upon work standards and achieves expected results.',
                    'Maintains a high work load and demonstrates the ability to organize work efficiently, meet deadlines and complete tasks assigned.',
                    'Very meticulous, accurate and thorough in executing tasks.',
                ],
            ],
            [
                'name' => 'Managing Staff',
                'questions' => [
                    'Sets clear goals and expectations for staff and monitors progress against goals.',
                    'Gives regular performance feedback to staff and provides on-the-job training and development.',
                    'Manages staff effectively by facilitating the participation and contribution of others and promoting team work.',
                    'Ensures constructive conflict resolution and has excellent negotiation skills.',
                ],
            ],
            [
                'name' => 'Accountability & Resource Management',
                'questions' => [
                    'Demonstrates ability to prioritize and effectively manage time and available resources.',
                    'Provides relevant information in a timely manner and maintains a high level of confidentiality with sensitive information.',
                    "Demonstrates responsible stewardship of the company's resources and consistently works to minimize the company's exposure to risks.",
                    'Takes responsibility (where appropriate) for work related and personal actions which affect delivery of results.',
                ],
            ],
            [
                'name' => 'Interpersonal & Communication Skills',
                'questions' => [
                    'Respects diverse viewpoints and demonstrates tact and courtesy towards clients, colleagues and subordinates.',
                    'Demonstrates emotional maturity and makes the effort to maintain effective work relations with others.',
                    'Consistently demonstrates passion, enthusiasm and excitement about work and people.',
                    'Demonstrates willingness to support others in their work and provide them with the needed cooperation to achieve company goals.',
                ],
            ],
        ];
    }
}
