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

        // === ANNOUNCEMENTS ===
        $service->setDefault('features.announcements.enabled', true, 'announcements');
        $service->setDefault('features.recruitment.enabled', true, 'recruitment');

        // === TALENT ACQUISITION ===
        $service->setDefault('features.talent_acquisition.enabled', true, 'talent_acquisition');
        $service->setDefault('features.talent_acquisition.job_postings', true, 'talent_acquisition');
        $service->setDefault('features.talent_acquisition.candidates', true, 'talent_acquisition');
        $service->setDefault('features.talent_acquisition.interviews', true, 'talent_acquisition');
        $service->setDefault('features.talent_acquisition.public_portal', true, 'talent_acquisition');
        $service->setDefault('features.talent_acquisition.feedback', true, 'talent_acquisition');
        $service->setDefault('features.talent_acquisition.evaluation', true, 'talent_acquisition');

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
