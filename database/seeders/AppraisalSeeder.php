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
    // job_categories: [] means "applies to all" (no category restriction).
    // questions_key: key into the question sets built in run().
    //
    private array $orgTemplates = [
        'cashpoint' => [
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

        'abave' => [
            [
                'name' => 'Employee Performance Review',
                'description' => 'Mid-year / annual performance review for all Abave staff.',
                'questions_key' => 'abave_general',
                'job_categories' => [], // applies to all categories
            ],
        ],
    ];

    // ── Rating scale per organisation ─────────────────────────────────────────

    private array $orgRatingOptions = [
        'default' => [
            ['option_text' => 'Poor', 'option_value' => '1', 'order' => 1],
            ['option_text' => 'Average', 'option_value' => '2', 'order' => 2],
            ['option_text' => 'Good', 'option_value' => '3', 'order' => 3],
            ['option_text' => 'Very Good', 'option_value' => '4', 'order' => 4],
            ['option_text' => 'Excellent', 'option_value' => '5', 'order' => 5],
        ],
        'abave' => [
            ['option_text' => 'Unsatisfactory', 'option_value' => '25', 'order' => 1],
            ['option_text' => 'Needs Improvement', 'option_value' => '35', 'order' => 2],
            ['option_text' => 'Meets Expectation', 'option_value' => '50', 'order' => 3],
            ['option_text' => 'Exceeds Expectations', 'option_value' => '75', 'order' => 4],
            ['option_text' => 'Outstanding', 'option_value' => '100', 'order' => 5],
        ],
    ];

    public function run(): void
    {
        $org = strtolower(config('kazi360.organization', 'ttu'));

        if (!isset($this->orgTemplates[$org])) {
            $this->command->warn("No appraisal template definitions found for organisation \"{$org}\". Skipping.");
            return;
        }

        $adminUser = User::first();
        $ratingScale = $this->orgRatingOptions[$org] ?? $this->orgRatingOptions['default'];

        // ── Build question sets ───────────────────────────────────────────────

        $questionSets = match ($org) {
            'cashpoint' => $this->buildCashpointSets($adminUser, $ratingScale),
            'abave' => $this->buildAbaveSets($adminUser, $ratingScale),
            default => [],
        };

        // ── Deactivate templates from any other org ───────────────────────────

        $currentOrgTitles = array_column($this->orgTemplates[$org], 'name');

        Assessment::where('type', 'appraisal')
            ->whereNotIn('title', $currentOrgTitles)
            ->update(['is_active' => false]);

        // ── Seed templates for the current org ───────────────────────────────

        $templateCount = 0;

        foreach ($this->orgTemplates[$org] as $def) {
            $categoryIds = empty($def['job_categories'])
                ? []  // empty = no restriction; window query handles this via whereDoesntHave('jobCategories')
                : JobCategory::whereIn('name', $def['job_categories'])->pluck('id')->toArray();

            $this->createTemplate(
                $def['name'],
                $def['description'],
                $categoryIds,
                $questionSets[$def['questions_key']],
                $adminUser
            );

            $templateCount++;
        }

        $this->command->info("Appraisal seeder [{$org}]: {$templateCount} template(s) seeded.");
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

    // ── Generic section/question builder ──────────────────────────────────────

    private function createSectionsAndQuestions(array $sections, QuestionCategory $parent, User $user, array $ratingOptions): array
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
                    foreach ($ratingOptions as $opt) {
                        $question->options()->create(array_merge($opt, ['user_id' => $user->id]));
                    }
                }

                $allQuestions[] = ['question' => $question, 'order' => ($sectionOrder * 100) + $qOrder];
            }
        }

        return $allQuestions;
    }

    // ── Cashpoint question-set builder ────────────────────────────────────────

    private function buildCashpointSets(User $user, array $ratingScale): array
    {
        $nonManagerialParent = QuestionCategory::updateOrCreate(
            ['name' => 'Non-Managerial Performance', 'parent_id' => null],
            ['description' => 'Appraisal criteria for non-managerial staff', 'is_active' => true, 'user_id' => $user->id]
        );

        $managerialParent = QuestionCategory::updateOrCreate(
            ['name' => 'Managerial Performance', 'parent_id' => null],
            ['description' => 'Appraisal criteria for managerial staff', 'is_active' => true, 'user_id' => $user->id]
        );

        return [
            'non_managerial' => $this->createSectionsAndQuestions($this->nonManagerialSections(), $nonManagerialParent, $user, $ratingScale),
            'managerial' => $this->createSectionsAndQuestions($this->managerialSections(), $managerialParent, $user, $ratingScale),
        ];
    }

    // ── Abave question-set builder ────────────────────────────────────────────

    private function buildAbaveSets(User $user, array $ratingScale): array
    {
        $parent = QuestionCategory::updateOrCreate(
            ['name' => 'Abave Performance Review', 'parent_id' => null],
            ['description' => 'General performance appraisal criteria for all Abave staff', 'is_active' => true, 'user_id' => $user->id]
        );

        return [
            'abave_general' => $this->createSectionsAndQuestions($this->abaveSections(), $parent, $user, $ratingScale),
        ];
    }

    // ── Abave question data ───────────────────────────────────────────────────

    private function abaveSections(): array
    {
        return [
            [
                'name' => 'Personal Characteristics',
                'questions' => [
                    'Positive attitude',
                    'Cooperative',
                    'Responsible',
                    'Dedicated',
                    'Verbal/Persuasive',
                    'Ability to learn',
                ],
            ],
            [
                'name' => 'Goals / Self Perception',
                'questions' => [
                    'Realistic appraisal of self',
                    'Reason for interest in field',
                    'Realistic career goals',
                    'Qualifications',
                ],
            ],
            [
                'name' => 'Education & Training',
                'questions' => [
                    'Accomplishments',
                    'Skills',
                    'Continual upgrade of experience/knowledge',
                    'Computer Skills',
                ],
            ],
            [
                'name' => 'Professionalism & Collegiality',
                'questions' => [
                    'Professional attitude with clients',
                    'Respect code of ethics',
                    'Collegiality and teamwork',
                    'Commitment to tasks',
                    'Punctuality in training and task completion',
                ],
            ],
            [
                'name' => 'Adaptability & Independence at Work',
                'questions' => [
                    'Adaptability to changes in the working environment',
                    'Self-initiative',
                    'Availability for engagement',
                    'Openness to new professional challenges',
                    'Follow instructions and work related procedures',
                    'Punctuality and timeliness in independent work',
                    'Capable of making decisions and solving problems',
                    'Specific Knowledge of the job and ability to apply it',
                    'Works under minimal supervision',
                    'Capacity and ambition for advancement',
                ],
            ],
            [
                'name' => 'Decision Making / Problem Solving',
                'questions' => [
                    'Creativity',
                    'Logic',
                ],
            ],
            [
                'name' => 'Quality & Safety',
                'questions' => [
                    'Comply with the quality procedures',
                ],
            ],
            [
                'name' => 'HSE (Health, Safety & Environment)',
                'questions' => [
                    'Compliance with HSE procedures and work instructions',
                    'Proper use of PPE and safety equipment',
                    'Participation in weekly safety meetings (TBT Attendance records)',
                    'Housekeeping and maintenance of safe work environment',
                    'Digital Reporting and the Use of Resources',
                    'Total Number of HSE Training Completed',
                ],
            ],
        ];
    }

    // ── Cashpoint question data ───────────────────────────────────────────────

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
