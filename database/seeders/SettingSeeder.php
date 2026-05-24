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
        $service->set('app.name', 'Kazi360', 'app', 'Application name', true);
        $service->set('app.logo', null, 'app', 'Logo URL', true);
        $service->set('app.website', null, 'app', 'Developer website', true);
        $service->set('app.address', 'Takoradi', 'app', 'Developer address', true);
        $service->set('app.phone', '+233544513074', 'app', 'Developer phone number', true);
        $service->set('app.email', 'amos.nkum@gmail.com', 'app', 'Developer contact email', true);
        $service->set('app.theme', 'midnight-navy', 'app', 'App Theme', true);
        $service->set('app.support_mail', 'amos.nkum@gmail.com', 'app', 'Support email', true);
        $service->set('app.timezone', 'Africa/Accra', 'app', 'Default timezone', true);
        $service->set('app.date_format', 'DD/MM/YYYY', 'app', 'Date display format', true);

        // === EMPLOYEES ===
        $service->set('features.employees.enabled', true, 'employees');
        $service->set('features.employees.termination', true, 'employees');
        $service->set('features.employees.biography', true, 'employees');
        $service->set('features.employees.specializations', true, 'employees');
        $service->set('features.employees.research_interests', true, 'employees');
        $service->set('features.employees.photo_upload', true, 'employees');

        // === LEAVE ===
        $service->set('features.leave.enabled', true, 'leave');
        $service->set('features.leave.self_service', true, 'leave');
        $service->set('features.leave.team_visibility', true, 'leave');
        $service->set('features.leave.hr_approval', true, 'leave');
        $service->set('features.leave.types_management', true, 'leave');

        // === APPRAISAL ===
        $service->set('features.appraisal.enabled', true, 'appraisal');
        $service->set('features.appraisal.self_review', true, 'appraisal');

        // === TRAINING ===
        $service->set('features.training.enabled', true, 'training');
        $service->set('features.training.quiz', true, 'training');
        $service->set('features.training.previous_ranks', true, 'training');
        $service->set('features.training.previous_positions', true, 'training');

        // === ANNOUNCEMENTS ===
        $service->set('features.announcements.enabled', true, 'announcements');
        $service->set('features.recruitment.enabled', true, 'recruitment');

        // === SELF-SERVICE ===
        $service->set('features.self_service.enabled', true, 'self_service');
        $service->set('features.self_service.qualifications', true, 'self_service');
        $service->set('features.self_service.experience', true, 'self_service');
        $service->set('features.self_service.emergency_contacts', true, 'self_service');
        $service->set('features.self_service.dependants', true, 'self_service');
        $service->set('features.self_service.awards', true, 'self_service');
        $service->set('features.self_service.achievements', true, 'self_service');
        $service->set('features.self_service.affiliations', true, 'self_service');
        $service->set('features.self_service.grants', true, 'self_service');
        $service->set('features.self_service.projects', true, 'self_service');
        $service->set('features.self_service.publications', true, 'self_service');
        $service->set('features.self_service.community_services', true, 'self_service');
        $service->set('features.self_service.next_of_kin', true, 'self_service');

        // === STAFF DIRECTORY ===
        $service->set('features.staff_directory.enabled', true, 'staff_directory');
        $service->set('features.staff_directory.publications', true, 'staff_directory');
        $service->set('features.staff_directory.qualifications', true, 'staff_directory');
        $service->set('features.staff_directory.experience', true, 'staff_directory');
        $service->set('features.staff_directory.awards', true, 'staff_directory');
        $service->set('features.staff_directory.achievements', true, 'staff_directory');
        $service->set('features.staff_directory.affiliations', true, 'staff_directory');
        $service->set('features.staff_directory.grants', true, 'staff_directory');
        $service->set('features.staff_directory.projects', true, 'staff_directory');

        // === SOCIAL AUTH ===
        $service->set('features.social_auth.google', true, 'social_auth', isPublic: true);
        $service->set('features.auth.password', true, 'auth', isPublic: true);
        $service->set('features.auth.password_change', false, 'auth');

        // === QUESTION BANK ===
        $service->set('features.question_bank.enabled', true, 'question_bank');

        // === DYNAMIC FORMS ===
        $service->set('features.dynamic_forms.enabled', true, 'dynamic_forms');

        // === INFORMATION UPDATES ===
        $service->set('features.information_updates.enabled', true, 'information_updates');

        // === DIRECT REPORTS ===
        $service->set('features.direct_reports.enabled', true, 'direct_reports');

        // === QUICK EMAIL ===
        $service->set('features.quick_email.enabled', true, 'quick_email');

        // === NOTIFICATIONS ===
        $service->set('notifications.channels', ['email'], 'notifications');

        // === FORMS ===
        $service->set('forms.employeeForm', [
            'rank' => [
                'required' => true,
                'visible'  => true,
            ],
        ], 'forms', 'Employee form field configuration');
    }
}
