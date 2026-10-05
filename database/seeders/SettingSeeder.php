<?php

namespace Database\Seeders;

use App\Services\SettingService;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $service = app(SettingService::class);

        // === APP CONFIGURATION (public — available before auth) ===
        $service->setDefault('app.name', 'Kazi360', 'app', 'Application name', true);
        $service->setDefault('app.logo', null, 'app', 'Logo URL', true);
        $service->setDefault('app.website', null, 'app', 'Developer website', true);
        $service->setDefault('app.address', 'Takoradi', 'app', 'Developer address', true);
        $service->setDefault('app.phone', '+233544513074', 'app', 'Developer phone number', true);
        $service->setDefault('app.email', 'amos.nkum@gmail.com', 'app', 'Developer contact email', true);
        $service->setDefault('app.theme', 'midnight-navy', 'app', 'App Theme', true);
        $service->setDefault('app.support_mail', 'amos.nkum@gmail.com', 'app', 'Support email', true);
        $service->setDefault('app.timezone', 'Africa/Accra', 'app', 'Default timezone', true);
        $service->setDefault('app.date_format', 'DD/MM/YYYY', 'app', 'Date display format', true);

        // === EMPLOYEES ===
        $service->setDefault('features.employees.enabled', true, 'employees');
        $service->setDefault('features.employees.termination', true, 'employees');
        $service->setDefault('features.employees.biography', true, 'employees');
        $service->setDefault('features.employees.specializations', true, 'employees');
        $service->setDefault('features.employees.research_interests', true, 'employees');
        $service->setDefault('features.employees.photo_upload', true, 'employees');

        // === LEAVE ===
        $service->setDefault('features.leave.enabled', true, 'leave');
        $service->setDefault('features.leave.self_service', true, 'leave');
        $service->setDefault('features.leave.team_visibility', true, 'leave');
        $service->setDefault('features.leave.hr_approval', true, 'leave');
        $service->setDefault('features.leave.types_management', true, 'leave');

        // === APPRAISAL ===
        $service->setDefault('features.appraisal.enabled', true, 'appraisal');
        $service->setDefault('features.appraisal.self_review', true, 'appraisal');

        // === TRAINING ===
        $service->setDefault('features.training.enabled', true, 'training');
        $service->setDefault('features.training.quiz', true, 'training');
        $service->setDefault('features.training.previous_ranks', true, 'training');
        $service->setDefault('features.training.previous_positions', true, 'training');
        // Opt-in: not every organisation plans training this way. Switch on under Feature toggles.
        $service->setDefault('features.training_plan.enabled', false, 'training');
        $service->setDefault('features.training_plan.require_different_approvers', true, 'training');
        // Evaluating completed trainings: the trainee's feedback, then their supervisor's review (SOP 5.3.6).
        $service->setDefault('features.training_plan.evaluations', true, 'training');
        $service->setDefault('training_plan.feedback_due_days', 7, 'training');
        $service->setDefault('training_plan.supervisor_review_after_days', 90, 'training');

        // === COMPETENCY MATRIX ===
        // Opt-in: not every organisation keeps a competency matrix. Switch on under Feature toggles.
        $service->setDefault('features.competency.enabled', false, 'competency');
        // Months until an employee is due for reassessment (procedure: periodic review).
        $service->setDefault('competency.review_interval_months', 12, 'competency');

        // === ANNOUNCEMENTS ===
        $service->setDefault('features.announcements.enabled', true, 'announcements');

        // === RECRUITMENT (shown as "Talent Acquisition" in the app) ===
        $service->setDefault('features.recruitment.enabled', true, 'recruitment');
        $service->setDefault('features.recruitment.public_portal', true, 'recruitment');
        $service->setDefault('features.recruitment.job_postings', true, 'recruitment');
        $service->setDefault('features.recruitment.candidates', true, 'recruitment');
        $service->setDefault('features.recruitment.interviews', true, 'recruitment');

        // === SELF-SERVICE ===
        $service->setDefault('features.self_service.enabled', true, 'self_service');
        $service->setDefault('features.self_service.qualifications', true, 'self_service');
        $service->setDefault('features.self_service.experience', true, 'self_service');
        $service->setDefault('features.self_service.emergency_contacts', true, 'self_service');
        $service->setDefault('features.self_service.dependants', true, 'self_service');
        $service->setDefault('features.self_service.awards', true, 'self_service');
        $service->setDefault('features.self_service.achievements', true, 'self_service');
        $service->setDefault('features.self_service.affiliations', true, 'self_service');
        $service->setDefault('features.self_service.grants', true, 'self_service');
        $service->setDefault('features.self_service.projects', true, 'self_service');
        $service->setDefault('features.self_service.publications', true, 'self_service');
        $service->setDefault('features.self_service.community_services', true, 'self_service');
        $service->setDefault('features.self_service.next_of_kin', true, 'self_service');

        // === STAFF DIRECTORY ===
        $service->setDefault('features.staff_directory.enabled', true, 'staff_directory');
        $service->setDefault('features.staff_directory.publications', true, 'staff_directory');
        $service->setDefault('features.staff_directory.qualifications', true, 'staff_directory');
        $service->setDefault('features.staff_directory.experience', true, 'staff_directory');
        $service->setDefault('features.staff_directory.awards', true, 'staff_directory');
        $service->setDefault('features.staff_directory.achievements', true, 'staff_directory');
        $service->setDefault('features.staff_directory.affiliations', true, 'staff_directory');
        $service->setDefault('features.staff_directory.grants', true, 'staff_directory');
        $service->setDefault('features.staff_directory.projects', true, 'staff_directory');

        // === SOCIAL AUTH ===
        $service->setDefault('features.social_auth.google', true, 'social_auth', isPublic: true);
        $service->setDefault('features.auth.password', true, 'auth', isPublic: true);
        $service->setDefault('features.auth.password_change', false, 'auth');

        // === QUESTION BANK ===
        $service->setDefault('features.question_bank.enabled', true, 'question_bank');

        // === DYNAMIC FORMS ===
        $service->setDefault('features.dynamic_forms.enabled', true, 'dynamic_forms');

        // === INFORMATION UPDATES ===
        $service->setDefault('features.information_updates.enabled', true, 'information_updates');

        // === DIRECT REPORTS ===
        $service->setDefault('features.direct_reports.enabled', true, 'direct_reports');

        // === CERTIFICATIONS ===
        $service->setDefault('features.certifications.enabled', true, 'certifications');

        // === QUICK EMAIL ===
        $service->setDefault('features.quick_email.enabled', true, 'quick_email');

        // === NOTIFICATIONS ===
        $service->setDefault('notifications.channels', ['email'], 'notifications');

        // === FORMS ===
        $service->setDefault('forms.employeeForm', [
            'rank' => [
                'required' => true,
                'visible'  => true,
            ],
        ], 'forms', 'Employee form field configuration');
    }
}
