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
    // Organisations with a database/seed-data/appraisal/{org}.json file are seeded
    // from that file (see seedFromJson). The PHP definitions below cover the rest.
    //
    // Each entry maps a template name → the job category names it applies to.
    // job_categories: [] means "applies to all" (no category restriction).
    // questions_key: key into the question sets built in run().
    //
    private array $orgTemplates = [
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

        $jsonPath = database_path("seed-data/appraisal/{$org}.json");
        if (file_exists($jsonPath)) {
            $this->seedFromJson($org, json_decode(file_get_contents($jsonPath), true, flags: JSON_THROW_ON_ERROR));
            return;
        }

        if (!isset($this->orgTemplates[$org])) {
            $this->command->warn("No appraisal template definitions found for organisation \"{$org}\". Skipping.");
            return;
        }

        $adminUser = User::first();
        $ratingScale = $this->orgRatingOptions[$org] ?? $this->orgRatingOptions['default'];

        // ── Build question sets ───────────────────────────────────────────────

        $questionSets = match ($org) {
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

    // ── JSON-driven seeding ───────────────────────────────────────────────────

    private function seedFromJson(string $org, array $data): void
    {
        $adminUser = User::first();
        $ratingScale = $data['rating_scale'] ?? $this->orgRatingOptions['default'];

        Assessment::where('type', 'appraisal')
            ->whereNotIn('title', array_column($data['templates'], 'name'))
            ->update(['is_active' => false]);

        foreach ($data['templates'] as $def) {
            $parent = QuestionCategory::updateOrCreate(
                ['name' => $def['category']['name'], 'parent_id' => null],
                [
                    'description' => $def['category']['description'] ?? null,
                    'is_active' => true,
                    'user_id' => $adminUser->id,
                ]
            );

            $categoryIds = empty($def['job_categories'])
                ? []
                : JobCategory::whereIn('name', $def['job_categories'])->pluck('id')->toArray();

            $this->createTemplate(
                $def['name'],
                $def['description'] ?? '',
                $categoryIds,
                $this->createSectionsAndQuestions($def['sections'], $parent, $adminUser, $ratingScale),
                $adminUser,
                (bool) ($def['include_training_section'] ?? false),
                (bool) ($def['include_next_period_targets'] ?? false),
            );
        }

        $this->command->info("Appraisal seeder [{$org}]: " . count($data['templates']) . ' template(s) seeded from JSON.');
    }

    // ── Template creation ─────────────────────────────────────────────────────

    private function createTemplate(string $name, string $description, array $jobCategoryIds, array $questions, User $user, bool $includeTrainingSection = false, bool $includeNextPeriodTargets = false): void
    {
        $template = Assessment::updateOrCreate(
            ['title' => $name, 'type' => 'appraisal'],
            [
                'description' => $description,
                'is_active' => true,
                'include_training_section' => $includeTrainingSection,
                'include_next_period_targets' => $includeNextPeriodTargets,
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

            foreach ($section['questions'] as $qOrder => $item) {
                // A question is either plain text (a rating question) or ['text', 'type', 'is_required']
                $item = is_string($item) ? ['text' => $item] : $item;
                $type = $item['type'] ?? 'rating';

                $question = Question::updateOrCreate(
                    ['text' => $item['text'], 'question_category_id' => $category->id],
                    [
                        'type' => $type,
                        'weight' => 1,
                        'is_active' => true,
                        'is_required' => $item['is_required'] ?? true,
                        'order' => $qOrder,
                        'user_id' => $user->id,
                    ]
                );

                if ($type === 'rating' && $question->options()->doesntExist()) {
                    foreach ($ratingOptions as $opt) {
                        $question->options()->create(array_merge($opt, ['user_id' => $user->id]));
                    }
                }

                $allQuestions[] = ['question' => $question, 'order' => ($sectionOrder * 100) + $qOrder];
            }
        }

        return $allQuestions;
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
}
