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
        // The levels of the organization's structure, top first (e.g. Directorate, Department, Section, Unit). A
        // department's level labels the tree and can stop approval chains ("up to the Directorate").
        $service->setDefault('org.department_levels', ['Department'], 'org', 'Department levels');
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
        // Who approves leave before HR: the employee's supervisor, then (optionally) heads above them. Default: the
        // supervisor only. Rule as for approval workflows: {stop: count | level | top, count?, level?}.
        $service->setDefault('leave.approval_chain', ['stop' => 'count', 'count' => 0], 'leave', 'Leave approvers before HR');

        // === APPRAISAL ===
        $service->setDefault('features.appraisal.enabled', true, 'appraisal');
        $service->setDefault('features.appraisal.self_review', true, 'appraisal');
        // After the appraiser and the employee: the heads above the appraiser who endorse an appraisal before HR.
        // {stop: none} (default: straight to HR) | {stop: count, count} | {stop: level, level} | {stop: top}.
        $service->setDefault('appraisal.endorsement_chain', ['stop' => 'none'], 'appraisal', 'Appraisal endorsement before HR');

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

        // === PAYROLL ===
        // Opt-in, each part on its own: overtime works without payroll (approved hours are exported instead).
        $service->setDefault('features.payroll.enabled', false, 'payroll', 'Pay runs, payslips and statutory reports');
        $service->setDefault('features.payroll.overtime', false, 'payroll', 'Overtime requests and approval');
        $service->setDefault('features.payroll.loans', false, 'payroll', 'Staff loans and advances');
        $service->setDefault('features.payroll.time_inputs', false, 'payroll', 'Monthly unit inputs such as offshore days');
        $service->setDefault('features.payroll.timesheets', false, 'payroll', 'Daily timesheets, approved and fed into pay');
        // How pay is worked out; HR changes these under Payroll → Settings.
        $service->setDefault('payroll.base_currency', 'GHS', 'payroll');
        $service->setDefault('payroll.working_days_per_month', 22, 'payroll');
        // Pay period: 1 = the calendar month; e.g. 21 = 21st of the previous month to the 20th.
        $service->setDefault('payroll.period_start_day', 1, 'payroll');
        // Default pay date in the run's month: 0 = the last day.
        $service->setDefault('payroll.pay_day', 0, 'payroll');
        $service->setDefault('payroll.hours_per_day', 8, 'payroll');
        // The normal working day: on a weekday, hours past it (or outside it, with times) are overtime. Saving it
        // sets hours_per_day.
        $service->setDefault('payroll.work_day_start', '08:00', 'payroll');
        $service->setDefault('payroll.work_day_end', '17:00', 'payroll');
        $service->setDefault('payroll.work_day_break_minutes', 60, 'payroll');
        // Overtime's hourly rate = basic ÷ hours in a month: fixed_days (working days a month × hours a day),
        // period_working_days (the period's working days × hours a day) or fixed_hours (overtime_monthly_hours).
        $service->setDefault('payroll.overtime_hourly_basis', 'fixed_days', 'payroll');
        $service->setDefault('payroll.overtime_monthly_hours', 173.33, 'payroll');
        // Exchange rates: the day of the run's month whose rates are used (0 = the period's last day); a separate
        // rate for basic salary; and which rate converts a foreign-currency basic for overtime's hourly rate.
        $service->setDefault('payroll.exchange_rate_day', 0, 'payroll');
        $service->setDefault('payroll.separate_basic_rate', false, 'payroll');
        $service->setDefault('payroll.overtime_rate_type', 'basic', 'payroll');
        // income: overtime is taxed with the rest of pay; gra_junior: GRA's concessionary rates for junior staff.
        $service->setDefault('payroll.overtime_tax_method', 'income', 'payroll');
        // Bonus: at GRA's bonus rate up to a share of annual basic (the rest as income), or all as income.
        $service->setDefault('payroll.bonus_tax_method', 'gra_bonus', 'payroll');
        $service->setDefault('payroll.require_different_approvers', true, 'payroll');
        // Overtime policy (Payroll → Settings → Overtime).
        $service->setDefault('payroll.overtime_auto_type', true, 'payroll');          // type from the date (weekday/weekend/holiday)
        $service->setDefault('payroll.overtime_eligibility', 'everyone', 'payroll'); // everyone | job_types
        $service->setDefault('payroll.overtime_job_types', [], 'payroll');
        $service->setDefault('payroll.overtime_max_hours_per_day', 12, 'payroll');
        $service->setDefault('payroll.overtime_max_hours_per_month', 0, 'payroll'); // 0: no monthly limit
        $service->setDefault('payroll.overtime_backdate_days', 31, 'payroll');
        // When overtime is requested: after it's worked; before (FM-07, paid as approved); or both (approved before,
        // paid on the hours confirmed as worked, by the pay basis: approved | actual | lower).
        $service->setDefault('payroll.overtime_mode', 'after', 'payroll');
        $service->setDefault('payroll.overtime_advance_days', 30, 'payroll');
        // In advance modes, the kinds of day whose overtime needs a request approved before the work; overtime on
        // other days is claimed on the timesheet and approved with it.
        $service->setDefault('payroll.overtime_advance_for', ['weekday', 'weekend', 'holiday'], 'payroll');
        $service->setDefault('payroll.overtime_pay_basis', 'lower', 'payroll');
        $service->setDefault('payroll.overtime_require_reason', true, 'payroll');
        $service->setDefault('payroll.overtime_require_location', false, 'payroll');
        $service->setDefault('payroll.overtime_locations', [], 'payroll');
        // Time inputs: approved through the time_input workflow, or counted as entered.
        $service->setDefault('payroll.time_inputs_require_approval', true, 'payroll');
        // Timesheets: where pay's units, days worked and overtime hours come from (manual | timesheet), the columns
        // (each: key, label, type hours|check, feeds overtime (day_kind) | time_input (component) | nothing,
        // requires_offshore, sheet_column for FM-06 imports), and the columns that make a day count as worked.
        $service->setDefault('payroll.time_source', 'manual', 'payroll');
        $service->setDefault('payroll.timesheet_columns', [
            ['key' => 'normal_hours', 'label' => 'Normal hours', 'type' => 'hours', 'normal' => true],
            ['key' => 'ot_weekday', 'label' => 'Overtime (Mon–Fri)', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekday'],
            ['key' => 'ot_weekend', 'label' => 'Weekend overtime', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'weekend'],
            ['key' => 'ot_holiday', 'label' => 'Holiday overtime', 'type' => 'hours', 'feeds' => 'overtime', 'day_kind' => 'holiday'],
        ], 'payroll');
        $service->setDefault('payroll.timesheet_worked_columns', ['normal_hours'], 'payroll');
        // Freelancers (job type "freelance"): taxed as income (paye) or by the flat withholding rate on the statutory
        // rates; their timesheets' columns and the columns that count as a day worked.
        $service->setDefault('payroll.freelance_tax', 'paye', 'payroll');
        $service->setDefault('payroll.freelance_timesheet_columns', [
            ['key' => 'working_day', 'label' => 'Working day', 'type' => 'check'],
            ['key' => 'standby_day', 'label' => 'Standby day', 'type' => 'check'],
            ['key' => 'travel_day', 'label' => 'Travel day', 'type' => 'check'],
        ], 'payroll');
        $service->setDefault('payroll.freelance_worked_columns', ['working_day'], 'payroll');
        // Loans: a leaver's balance comes off their final pay.
        $service->setDefault('payroll.loans_deduct_from_final_pay', true, 'payroll');
        // Back pay: how many months back a regular run looks for raises entered late (0: off).
        $service->setDefault('payroll.arrears_months', 12, 'payroll');
        // Payslips: shown to employees once a run is marked paid.
        $service->setDefault('payroll.payslip_show_employer', true, 'payroll');
        $service->setDefault('payroll.payslip_show_ytd', true, 'payroll');
        $service->setDefault('payroll.email_payslips', false, 'payroll');
        // Component codes always printed on payslips, with "–" when there's nothing (e.g. BONUS, TIER3, LOAN_REPAY).
        $service->setDefault('payroll.payslip_fixed_lines', [], 'payroll');
        // The account number on payslips: masked (last 4 digits) or full.
        $service->setDefault('payroll.payslip_account_number', 'masked', 'payroll');
        // A starting set of Ghana statutory rates, unconfirmed until HR checks it (only when none exists).
        $this->call(StatutoryRatesSeeder::class);
        // The built-in pay components (basic salary).
        $this->call(PayComponentsSeeder::class);

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
