# Payroll, Overtime & Loans — Implementation Plan

Goal: a Ghana-standard payroll module (with overtime and staff loans) that any organization can switch on and set up
to fit its own rules, without code changes per organization.

- Backend: `hrms-be` (this repo) · Frontend: `/Users/israelnkum/WebstormProjects/ttu-hrms-frontend` (paths relative to `src/`)
- Status: **Phases 0 and 1 done.** Tick a step when it is merged.

---

## Principles

1. **One deployment per organization.** "Organization-specific" means settings, configuration tables and an optional
   seeded preset (`database/seeders/organizations/<org>.payroll.json`, next to `<org>.json`). No organization names in code.
2. **Everything that varies is configuration**, editable by HR in the app: pay components, statutory rates, overtime
   types, approval chains, loan rules, currencies and rates, bank file layouts. Seeders supply sensible defaults only.
3. **Toggleable.** Each part has a feature flag (SettingSeeder, `feature:` middleware, Feature toggles screen):

   | Flag | Default | Effect |
   |---|---|---|
   | `features.payroll.enabled` | off | The payroll module: configuration, pay runs, payslips, statutory reports. |
   | `features.payroll.overtime` | off | Overtime requests and approval. Works without payroll: approved overtime is reported/exported instead of paid. |
   | `features.payroll.loans` | off | Staff loans and advances (deducted through payroll when it is on; tracked manually otherwise). |
   | `features.payroll.time_inputs` | off | Per-period unit inputs (e.g. offshore days, rope-access days) that feed pay components. |

4. **Calculations happen on the server**, never in the browser. A pay run stores every line as calculated
   ("snapshot"): changing configuration never changes a locked payslip.
5. **Statutory rates are dated data**, maintained by HR (decision: HR updates them). Every rate table has an effective
   date; a run uses the rates in force for its period.
6. **Conventions** from the competency plan apply (integer id + uuid + soft deletes, settings via `SettingSeeder`,
   permissions via migrations, `UserFacingException`, audit trail, RTK Query services, a feature test per step).

## Decisions taken

| # | Question | Decision |
|---|---|---|
| 1 | Tenancy | One deployment per organization. |
| 2 | Currency | Pay may be defined in a foreign currency (e.g. USD); an **exchange rate is entered per year** and used by every run that year. |
| 3 | Overtime approval | Configurable chain, not code. Apave preset: supervisor → unit manager → HR. |
| 4 | Overtime tax | Organization setting: tax as ordinary income (default) or GRA junior-staff concessionary rates. |
| 5 | Loans | No interest by default; interest, limits and approval chain are configurable per loan type. |
| 6 | Pay frequency, bank formats, accounting export | Configurable (monthly to start; layouts defined in the app). |
| 7 | Statutory updates | Done by HR in the app. |

## What exists today (and what happens to it)

- **Backend:** no payroll, overtime, timesheet or loan code. Employee has `ssnit_number`, `job_type`, `level`, `rank_id`;
  the Ghana Card (also the individual TIN) is on contact details. No salary, bank or relief data anywhere.
- **Frontend:** Apave screens carried over from the first commit, calling endpoints that don't exist:
  `apps/payroll-management/*` (not mounted; broken import), `apps/leave-management/overtime-request/*` and the overtime
  and monthly-timesheet entries under Time & Attendance (mounted, broken), `utils/salary-util.ts` (pay maths in the
  browser, Apave constants), a payslip hard-coding Apave's name, logo and address.
- **Plan:** use them only as a reference for Apave's fields and flow; build the new screens in `apps/payroll/` (RTK Query);
  remove the old screens and menu entries in step 2.5.

---

## Phase 0 — Foundations

### 0.1 Flags, permissions, menus — [x]
- **Settings** (`SettingSeeder`): the four flags above (all off).
- **Permissions** (migration, granted to `hr` to start): `configure-payroll`, `prepare-payroll`, `approve-payroll`,
  `view-payroll`, `manage-loans`, `view-overtime`. Self-service needs none.
- **Frontend:** new `apps/payroll/` with routes and menu gated by flag + permission; self-service "Payslips",
  "Overtime" and "Loans" entries gated by their flags.
- **As built**: the `feature:` middleware now also accepts "any of" (`feature:a|b`). `routes/v1/payroll.php`;
  `PayrollSettingsController` (General settings). Frontend: new `apps/payroll/` (links, menus, settings layout with a
  section nav that scrolls on phones), registered as the "Payroll" app (`app-util.ts`, `protected-routes.tsx`,
  `common/routes/admin/payroll-routes.tsx`). The old `apps/payroll-management` is no longer referenced (removed in 2.5).

### 0.2 Configurable approval workflows — [x]
One engine for every approval in this module (overtime, loans, unit inputs, pay runs), generalising the training
plan's approval levels so HR defines chains instead of code.
- **Migrations:** `approval_workflows` (`process` e.g. `overtime` | `loan` | `time_input` | `pay_run`, `name`, active,
  optional condition: e.g. loan type or amount above X), `approval_workflow_steps` (`order`, `name`, `approver_type`:
  `supervisor` | `department_head` | `parent_department_head` | `role` | `permission` | `users`, `approver_value`,
  `rule`: `any` | `all`, `can_adjust` json: fields this step may change, e.g. `approved_hours`, `amount`),
  `approval_records` (polymorphic: subject, step, decision, actor, comment, adjusted values, decided_at).
- **Backend:** `ApprovalWorkflowService`: start(subject), current step, who may act (resolves approver types), decide
  (approve / reject / send back), adjustments within `can_adjust`, the different-approver rule (setting), notifications,
  and a fallback when a step resolves to nobody (configurable: skip or route to HR).
- **Frontend:** Payroll → Settings → Approval workflows: list per process, step editor (drag to order), preview of who
  would approve for a chosen employee.
- **Done when:** the Apave overtime chain (supervisor → unit manager → HR) and a different one are both set up in the
  UI with no code change, and approvals follow them.
- **As built**: `ApprovalWorkflowService` (start / decide / cancel / resolve), `ApprovalSubject` contract for things
  that are approved, `approval_workflows`, `approval_workflow_steps`, `approvals` (steps snapshot + resolved
  `current_approver_ids` for "waiting for me"), `approval_decisions` (with adjustments from→to). Simplifications: any one
  approver decides a step (no "all must approve"); no conditions on workflows (loan types will name a workflow instead).
  No workflow for a process → a single "HR approval" step (configure-payroll holders); a step resolving to nobody → HR;
  the requester never approves their own request. Notification type `payroll_approval`; its link
  (`/self-service/approvals`) gets its page with the first real subject (2.2). Frontend: Settings → Approval workflows
  (cards per process, drawer editor with ordered steps, "who approves" preview for a chosen employee).

### 0.3 Statutory tables (Ghana pack) — [x]
- **Migrations:** `statutory_rate_sets` (`name`, `effective_from`, notes) with child tables or a json schema for:
  SSNIT (employee %, employer %, Tier 1 / Tier 2 split, maximum insurable earnings), PAYE monthly bands (from, to,
  rate), reliefs (marriage, children, disability, old age… amounts and conditions), Tier 3 tax-relief limit, overtime tax
  method and its thresholds, bonus tax (rate, % of annual basic), loan-benefit rule.
- **Seeder:** a "Ghana" set with the rates in force at go-live, **checked against current GRA and SSNIT publications**
  (they change; do not trust remembered figures).
- **Backend:** `StatutoryRates::for(date)` returns the set in force; editing creates a new dated set (old runs keep theirs).
- **Settings:** `payroll.overtime_tax_method` (`income` default | `gra_junior`), `payroll.bonus_tax_method`.
- **Frontend:** Payroll → Settings → Statutory rates: current set, history, "new rates from <date>" form with validation
  (bands contiguous, percentages sane).
- **As built**: one `statutory_rate_sets` table (json columns per part) with `confirmed_at`/`confirmed_by`; editing clears
  the confirmation; confirmed sets can't be deleted. `StatutoryRatesSeeder` (called from `SettingSeeder`, only when no set
  exists) seeds "Ghana (starting set: verify before use)" from the 2024 GRA bands and SSNIT rates, **unconfirmed** and with
  no SSNIT cap: HR must check and confirm it. Validation: bands in order with only the last open-ended; Tier 1 + Tier 2 =
  employee + employer. Pay runs (1.1) must refuse unconfirmed sets and lock the sets they use.

### 0.4 Currencies and yearly exchange rates — [x]
- **Migrations:** `payroll_currencies` (code, name, symbol; base currency setting `payroll.base_currency` = GHS),
  `exchange_rates` (`currency`, `year`, `rate` to base, entered_by). Optional per-run override recorded on the run.
- **Rule:** a run converts foreign-currency amounts with the rate for its year; it refuses to calculate when the rate is missing.
- **Frontend:** Settings → Currencies & rates.
- **As built**: `exchange_rates` only (no currency table: the base currency plus those with rates);
  `ExchangeRate::for($currency, $year)` (1 for the base currency, null when missing).

### 0.5 Pay components — [x]
The building blocks of a payslip, defined by HR.
- **Migration:** `pay_components`: `code`, `name`, `kind` (`earning` | `deduction` | `employer_contribution`),
  `calculation` (`fixed` | `percent_of_basic` | `hourly_multiplier` (× basic ÷ working days ÷ hours per day) |
  `rate_per_unit` (amount per day/hour/unit) | `manual`), `rate`, `currency`, `unit`, flags: `taxable`,
  `ssnit_applicable`, `recurring`, `prorate`, `show_on_payslip`, `sort_order`, `active`.
- **Settings:** `payroll.working_days_per_month` (fixed number, e.g. 22, or `calendar`), `payroll.hours_per_day` (8).
- **Seeder:** basic salary and common allowances (transport, lunch…), all editable.
- **Frontend:** Settings → Pay components (list + form with a live example calculation).
- **As built**: `PayComponentsSeeder` (from `SettingSeeder`) adds only the built-in `BASIC` (protected: name and order
  editable). No other components are seeded; the Apave preset (2.6) brings theirs. Fields that don't apply to the
  calculation are cleared on save; deductions are never SSNIT-applicable; for deductions "taxable" means "before tax".

### 0.6 Employee pay profile — [x]
- **Migrations:** `employee_pay_profiles` (effective-dated history): basic salary + currency, pay group, payment method,
  bank (name, branch, account name/number) or mobile money, SSNIT number (moved/linked from `employees.ssnit_number`),
  TIN (default: Ghana Card PIN), Tier 2 / Tier 3 schemes and Tier 3 %, reliefs claimed, tax residency, overtime
  eligibility override; `employee_pay_components` (recurring components with amount/rate overrides, effective dates).
- **Optional:** `salary_scales` (grade/level → basic) to fill basic from `level`/`rank` (organizations that use scales).
- **Security:** bank details and salaries only for `view-payroll`; changes audited; self-service read-only with a
  "request a change" flow (reuse information updates).
- **Frontend:** Payroll → Employees (list with missing-data flags), employee pay profile page; self-service "My pay details".
- **As built**: `employee_pay_profiles` (account and mobile money numbers **encrypted**, kept out of the audit log) and
  `employee_pay_components`. Saving: `mode=correct` edits the profile in force (or creates the first), `mode=change` adds a
  profile from a later date copying the rest. SSNIT number stays on `employees`; TIN defaults to the Ghana Card PIN. Reliefs
  are checked against the statutory set in force. Available when payroll **or** loans is on. Frontend: Payroll → Employees
  (list with what's missing, employee page), self-service "Pay details" (read-only, numbers masked).
  **Not built**: salary scales, and a self-service "request a change" flow (employees ask HR for now).

---

## Phase 1 — Pay runs

### 1.1 Pay periods, runs and the calculation engine — [x]
- **Migrations:** `pay_periods` (year, month, cut-off), `pay_runs` (period, type `regular` | `off_cycle`, status `draft →
  calculated → reviewed → approved → paid → locked`, exchange rates used, statutory set used, workflow),
  `payslips` (run, employee, snapshot of profile), `payslip_lines` (component, kind, quantity, rate, amount, currency,
  base amount, taxable/ssnit flags, source: profile | overtime | time_input | loan | one_off).
- **Engine** (server, one class, unit-tested per rule): proration for joiners/leavers → earnings (recurring, inputs,
  overtime) → currency conversion → SSNIT (employee/employer, cap, Tier 1/2) → taxable income (minus employee SSNIT,
  Tier 3 within limit, reliefs) → PAYE (bands; overtime and bonus per the tax settings) → deductions (loans, one-offs,
  with a net-pay floor setting) → net pay. Recalculate any time before approval; locked runs never change; corrections go
  into the next run as arrears/adjustment lines.
- **Approval:** the `pay_run` workflow (0.2); preparer and approver must differ (setting).
- **Frontend:** Payroll → Pay runs: create, calculate, review (variance vs last month, warnings: missing bank details,
  missing rate, negative net), approve, mark paid, lock.
- **As built**: no separate `pay_periods` table (a run carries its period); statuses draft → calculated →
  pending_approval → approved → paid ("locked" = approved/paid). `PayrollCalculator` (pure, unit-tested against hand-worked
  figures) and `PayRunService`. Pro-rating by calendar days from `job_details.joined_date` / `employees.termination_date`.
  Tier 3 is a % of basic, relieved up to the limit % of gross. Non-residents: flat `non_resident_rate` (new column on rate
  sets, default 25%). Payslips keep an employee snapshot and an **encrypted** payment snapshot. Calculation refuses
  unconfirmed rates and missing exchange rates. Approval: the `pay_run` workflow; with none, `approve-payroll` holders;
  the preparer is excluded when `require_different_approvers` is on. Rates (statutory and exchange) used by approved runs
  are locked. Generic `POST /payroll/approvals/{approval}/decide`. Frontend: Payroll → Pay runs (list, run page with
  actions by status, totals, approval progress, payslip list + drawer). Corrections after approval (arrears lines in the
  next run) are not built yet.

### 1.2 One-off inputs and imports — [x]
- **Migration:** `pay_run_inputs` (employee, component, amount/quantity, note, source).
- **Backend/Frontend:** add per employee, or import from Excel with a template and row-level validation report.
- **As built**: `pay_run_inputs` (`source`: input / overtime / time_input / loan, so Phases 2–3 feed the same table).
  Checked by component (hourly/per-unit need a quantity; manual needs an amount); a change puts a calculated run back to
  draft. Excel import is all-or-nothing with problems listed by row; template lists the usable components. Frontend:
  "Inputs" tab on the run page (list by employee, add/edit, import).

### 1.3 Payslips — [x]
- **Backend:** PDF from a Blade view using `PdfLetterhead` (company name/logo from settings — no hard-coded organization);
  payslip settings: show employer contributions, YTD totals, leave balance. Email on publish (notification preference),
  self-service download, password option (setting).
- **Frontend:** self-service "My payslips"; HR preview inside the run.
- **As built**: PDF (`payroll/payslip.blade.php`, letterhead from `PdfLetterhead`, account number masked). Employees see
  payslips of **paid** runs (self-service "Payslips"); HR downloads any. Settings `payroll.payslip_show_employer`,
  `payroll.payslip_show_ytd`, `payroll.email_payslips` (a link by email on payment, no attachment). Password-protected PDFs
  were not built.

### 1.4 Payments and statutory outputs — [x]
- **Bank/payment files:** `payment_file_layouts` configured in the app (columns, order, format, delimiter, header/footer,
  grouping per bank); generate per run.
- **Statutory:** SSNIT monthly contribution report, Tier 2 schedule (per trustee), GRA PAYE monthly return, payroll
  register, cost/journal export (configurable account mapping per component). Excel + PDF where relevant.
- **Frontend:** "Outputs" tab on an approved run.
- **As built**: `payment_file_layouts` (columns from a fixed list of fields, CSV/Excel, separator, header, optional single
  bank) and Excel reports: register (columns per component), SSNIT, Tier 2 (by scheme), GRA PAYE, journal (by component
  with the new `pay_components.account_code`). Only the register is available before approval. **To check**: the SSNIT,
  Tier 2 and GRA schedules carry standard columns; compare them with the current official templates and adjust.

---

## Phase 2 — Overtime and time inputs

### 2.1 Overtime policy (configuration) — [x]
- **Migration:** `overtime_types`: `code`, `name`, `calculation` (`hourly_multiplier` with multiplier, or `rate_per_hour`
  with amount + currency), `applies_on` (weekday | weekend | public holiday | any), linked pay component, active.
- **Settings:** eligibility (job types / levels / everyone), max hours per day/month, how far back a request may be
  made, require reason, require location, auto-detect type from the date (public holidays from the existing calendar).
- **Frontend:** Settings → Overtime.
- **As built**: `overtime_types` (name, pay component, `applies_on`, active, order); the rate lives on the component
  (hourly multiplier, or per-hour rate with currency). Policy settings `payroll.overtime_*` (eligibility everyone or by
  job type, plus the per-employee `overtime_eligible` on the pay profile; max hours a day/month; days to claim within;
  reason/location required; locations list; type from the date). Holidays come from `holidays`. With types chosen by
  date, an any-day type (e.g. offshore) can still be chosen. Settings → Overtime shows only when overtime is on.

### 2.2 Overtime requests and approval — [x]
- **Migration:** `overtime_requests` (employee, date, hours requested, type, approved hours, location (optional,
  configurable list), reason, status, pay run line when paid).
- **Flow:** employee (or HR on behalf) requests → the `overtime` workflow (e.g. supervisor → unit manager → HR) with
  `approved_hours` adjustable at the steps allowed → approved overtime waits for the next run (or is exported when payroll is off).
- **Frontend:** self-service "My overtime", "Team overtime" for approvers, HR overview with filters and export.
- **As built**: `OvertimeService` (policy checks, request, cancel); `OvertimeRequest` is an `ApprovalSubject`
  (`approved_hours` adjustable, never above what was asked). Approvers use one self-service **Approvals** inbox
  (`GET payroll/approvals`, waiting/decided) for every process instead of a team-overtime page; pay runs link to their
  page. Requesters get `ApprovalDecidedNotification` (type `payroll_decided`). HR can record overtime for an employee
  (Payroll → Overtime); it still goes through the employee's workflow. Export: `GET payroll/overtime/export`.

### 2.3 Time inputs per period — [x]
- **Migration:** `time_inputs` (employee, period, component (`rate_per_unit`), quantity, note, status) with optional
  approval (`time_input` workflow).
- **Frontend:** monthly grid per employee/component; Excel import.
- **As built**: `time_inputs` (employee, per-unit component, year, month, quantity, notes, status, pay run). Permission
  `enter-time-inputs` (HR to start). Setting `payroll.time_inputs_require_approval` (on: the `time_input` workflow with
  `quantity` adjustable; off: approved as entered). A change replaces the entry and restarts its approval; entries in a
  run sent for approval can't change. Page: Payroll → Time inputs, one component and month at a time, saved as you
  leave each field (mobile friendly), plus all-or-nothing Excel import.

### 2.4 Feeding the pay run — [x]
- Approved overtime and time inputs become payslip lines in the run for their period (or the next open one); a request
  can be paid only once; the run shows their origin.
- **As built**: each calculation of a regular run releases and re-claims approved, unpaid overtime (worked up to the
  period end) and time inputs (up to its month) as `pay_run_inputs` with source `overtime` / `time_input`, one per
  employee and component, and sets their `pay_run_id`; removing the run hands them back. Those inputs can't be edited
  in the run (changed at source instead) and are tagged in the Inputs tab. With payroll off, approved overtime is
  exported from Payroll → Overtime.

### 2.5 Retire the legacy screens — [x]
- Remove `apps/payroll-management/*`, `apps/leave-management/overtime-request/*`, the monthly-timesheet and overtime menu
  entries, `utils/salary-util.ts`, and their Redux slices.
- **As built**: also removed the unmounted legacy salary screens that depended on them (employee salary tab and form,
  self-service my-salaries) and the unmounted overtime timesheet list; `ProfileItem` moved into `medical-item.tsx`.
  Daily timesheets and leave requests are untouched. The legacy backend salary endpoints are still there.

### 2.6 Apave preset — [x] (values to confirm)
- `database/seeders/organizations/abave.payroll.json`, applied by a `payroll:apply-preset` command (idempotent): USD
  currency and the year's rate, pay components (basic, lunch, transport, other, offshore day, offshore/onshore rope
  access), overtime types (weekday, weekend, holiday as multipliers; offshore overtime as an hourly rate), working days 22 ×
  8 h, and the overtime workflow supervisor → unit manager → HR. Values to be confirmed with Apave before loading.
- **As built**: `abave.payroll.json` plus `php artisan payroll:apply-preset [org] [--dry-run]`. It refuses until
  `"confirmed": true` and every rate is filled in. The old rates lived in a `/system/config` endpoint that no longer
  exists, so the USD rate, offshore day, offshore overtime and rope access rates are blank, and the 1.5 / 2 / 2
  multipliers are placeholders. Also sets a time-input workflow (unit manager → HR).

---

## Phase 3 — Staff loans and advances

### 3.1 Loan types (configuration) — [x]
- **Migration:** `loan_types`: name, interest method (`none` default | `flat` | `reducing_balance`) and rate, maximum
  amount (fixed and/or multiple of monthly basic), maximum tenor (months), minimum service (months), maximum active loans,
  maximum deduction as % of net pay, requires guarantor, linked `loan` workflow, loan-benefit tax treatment (setting).
- **Frontend:** Settings → Loan types.
- **As built**: `loan_types` with every limit optional (fixed maximum and/or a multiple of basic, converted at the
  year's rate; longest tenor; service needed; loans of the type at a time; repayments as % of take-home pay, all loans
  together, where take-home is the last paid payslip's net plus its loan deductions, or basic before any payslip),
  guarantor, and an optional workflow of its own (else the default loan workflow). Settings → Loans also holds the
  leaver setting `payroll.loans_deduct_from_final_pay`. **Not built:** taxing the benefit of a cheap or interest-free
  loan; it needs GRA's benchmark rate and is left for when an organization asks.

### 3.2 Requests and approval — [x]
- **Migration:** `loans` (employee, type, amount requested/approved, tenor, purpose, status, workflow).
- **Flow:** request with live eligibility check (limits, service, active loans, affordability) → configured workflow
  (amount/tenor adjustable where allowed) → disbursement (date, method; outside payroll or as a payslip line).
- **As built**: `loans` copies the type's interest when requested. The request form checks the limits and shows the
  monthly repayment as the employee types (`POST payroll/my-loans/check`). Approvers adjust amount (never above what
  was asked) and tenor in the Approvals inbox. Paying out sets the date, method (bank, mobile money, cash, or on the
  payslip as the built-in non-taxable `LOAN_PAYOUT` earning) and the first repayment month. A guarantor is named;
  there's no consent step for them.

### 3.3 Repayment — [x]
- **Migration:** `loan_schedules` (instalments), `loan_repayments` (from a payslip line or manual).
- **Rules:** instalments deducted automatically in runs; early settlement, top-up (re-schedule), pause (with approval);
  balance and statement for the employee and HR; leaver handling (deduct from final pay, setting).
- **Frontend:** self-service "My loans"; HR loans register and statements.
- **As built**: `loan_instalments` (schedules for no interest, flat, and reducing balance with equal payments) and
  `loan_repayments`. Each calculation of a regular run claims due instalments as one `LOAN_REPAY` deduction (after
  tax) per employee, source `loan`; marking the run paid records the repayments and settles finished loans. A part
  payment by hand clears the last instalments first (same monthly deduction, ends sooner); settling in full pays the
  principal still owed and waives interest not yet due; pausing moves a month's instalment to the end. Instalments in a
  run still being prepared are taken back (the run goes back to draft); a run sent for approval blocks changes. A
  leaver's remaining instalments come off their final pay when the setting is on. Register export:
  `GET payroll/loans/export`. **Not built:** top-up (reschedule into a bigger loan); settle and request a new one.

---

## Phase 4 — Reporting and year-end — [x]
- Payroll dashboard (cost by department/component, headcount, variance), YTD per employee, annual PAYE/SSNIT summaries,
  bonus runs (bonus tax rules), arrears/back-pay, payroll audit report (every change to pay data and runs).
- **As built**:
  - **Off-cycle runs** (fixed): they now pay only their inputs, to the employees given something, with no basic,
    recurring components, proration, Tier 3 or reliefs. PAYE is the tax on the month's total less the tax on what the
    month's earlier runs taxed; SSNIT uses what's left of the cap. Calculating one needs the month's regular run
    calculated first. Payslips keep `ssnit_base`, `bonus_concession` and `bonus_tax` for this.
  - **Bonus tax**: earnings can be marked as a bonus (`is_bonus`). With `payroll.bonus_tax_method` = `gra_bonus`
    (default), bonus up to the statutory share of annual basic, less what was already taxed that way in the year, is
    taxed at the bonus rate; the rest is income. `income` taxes it all as income. Non-residents: as income.
  - **Back pay**: a regular run compares the employees' paid regular payslips of the last `payroll.arrears_months`
    (default 12, 0 = off) with the basic salary now in force for those months, and pays any shortfall as the built-in
    `ARREARS` earning (taxable, SSNIT), recorded in `pay_arrears` so it's paid once. Basic salary only; pay cuts aren't
    recovered; taxed in the month paid.
  - **Dashboard** (Payroll → Dashboard): a regular run's headline figures and change since the previous run, cost by
    department and component, joiners, leavers and the biggest changes in net pay, and a 12-month trend.
  - **Year reports**: year to date per employee on screen; downloads of year to date, PAYE by month (annual return) and
    SSNIT by month. Approved and paid runs only.
  - **Audit** (Payroll → Audit, `configure-payroll`): every change to payroll records and payroll settings from the
    activity log, with who, when, whose record and old → new values, filterable and exportable. Bank and mobile money
    numbers are never logged; calculated payslips are left out (the run is in the trail).

---

## Still to settle (before the step that needs them)

1. **Go-live statutory rates** (0.3): HR to confirm the current GRA bands, reliefs and SSNIT cap from official sources.
2. **Apave preset values** (2.6): the rates, multipliers and component list currently in their spreadsheet/old app.
3. **Bank file layouts and accounting export** (1.4): which banks and which accounting system, per organization.
4. **Payslip delivery** (1.3): email with password-protected PDF, or self-service only.
