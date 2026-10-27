# Timesheets, Overtime Pre-approval & Payslip — Completion Plan

Goal: close the gaps between the payroll module (built in [`payroll-plan.md`](payroll-plan.md), Phases 0–4) and Apave's
forms, so that a month's work flows from the timesheet into the payslip without re-keying:

```
Overtime request (FM-07, before the work) ─► approved ──┐
                                                        ▼
Daily timesheet (FM-06 / freelance) ─► submitted ─► supervisor ─► OM/CEO ─► approved
                                                        │
                       totals ─► time inputs (offshore, rope access, night duty)
                              └► overtime hours (matched to the approved requests)
                                                        ▼
                         Pay run (21st → 20th) ─► payslip (FM layout, dual exchange rates)
```

- Backend: `hrms-be` (this repo) · Frontend: `/Users/israelnkum/WebstormProjects/ttu-hrms-frontend` (paths relative to `src/`)
- Reference forms (in `refs/`): **AI-HR-FM-06** Employee Monthly Timesheet (xlsx, plus a filled-in sample),
  **AI-HR-FM-07** Overtime Request Form, the **freelance timesheet**, and a **sample payslip** (Sept 2026).
- Status: **Phases 5–11 done** (2026-10-15). **Phase 12** (timesheets and overtime as one flow) done (2026-10-15), not yet committed. Open decisions are settings, not code: see the table below. Phases continue the numbering of `payroll-plan.md`. Each step can ship on its own; tick it when merged.

---

## Conventions

Everything in `payroll-plan.md` → *Principles* applies: no organization names in code (Apave specifics go in
`abave.payroll.json`), everything that varies is configuration, each part behind a feature flag, calculations on the
server, payslips are snapshots. Plus the repo conventions: integer `id` + `uuid` + soft deletes, settings and flags in
`SettingSeeder`, permissions in migrations, `UserFacingException` / `ApiResponse::fromException`, RTK Query services in
`apps/payroll/services/`, a feature test per step under `tests/Feature/Payroll/`.

---

## Gap summary (from the analysis of `refs/`)

| # | Gap | Where it shows | Phase |
|---|---|---|---|
| 1 | Pay period is the calendar month; Apave's is the **21st → 20th** | FM-06 header, payslip "Period 21/08 – 20/09" | 5 |
| 2 | One exchange rate per currency **per year**; payslip uses a **dated monthly rate** and a **separate rate for basic** (USD 1 = 13.00) vs allowances (11.3933) | Payslip header | 6 |
| 3 | Payslip lacks period dates, engagement date, cost centre, exchange rates, staff cost, branch; doesn't list fixed lines at zero | Payslip | 7 |
| 4 | No sites, clients, POs/work orders or cost centres | FM-06 "Site worked", FM-07 "PO / Workorder / Project No.", freelance "Client", "Contract Ref / PO", payslip "Cost Centre" | 8 |
| 5 | Overtime can only be claimed **after** the work; FM-07 approves it **before**, with extra fields and supervisor questions | FM-07 | 9 |
| 6 | **No daily timesheet** (backend has none; old frontend Redux calls endpoints that don't exist) | FM-06, filled sample | 10 |
| 7 | No freelancer timesheet or day-rate pay | Freelance timesheet | 11 |
| 8 | Allowances can't follow **days worked**: transport (GHS 1,500/month) is prorated by days worked ÷ working days in the period; lunch is GHS 70 per day worked. Proration today is only for joiners/leavers (calendar days), and nothing counts days worked | Payslip: transport 1,500 × 18/21 = 1,285.71; lunch 70 × 18 = 1,260.00 | 5, 10 |
| 9 | Overtime hourly base is fixed (basic ÷ 22 days ÷ 8 h); Apave's base is unconfirmed — the sample's overtime lines can't be reproduced without the hours | Payslip: weekday OT 1,938.92, weekend OT 2,659.09 | 5 |

Overtime multipliers (Apave): weekday 1.25, weekend 1.5, public holiday 2 — supported as `hourly_multiplier` components;
set in the preset.

Already in place and reused: approval workflows (multi-step, adjustable fields, comments), overtime types incl. an
any-day offshore type, time inputs and their pay-run claim, holidays, leave requests, PAYE/SSNIT engine (the sample's SSF
5.5% = 473.72 and employer 13% = 1,119.70 match), loans incl. welfare loan, payslip PDF + self-service + notification.

---

## Decisions needed (before the phase that needs them)

| # | Question | Needed by | Suggested default |
|---|---|---|---|
| D1 | Is the 21st cut-off the same for everyone, and is pay date always month-end? | 5 | One org setting `payroll.period_start_day` (1 = calendar month); pay date setting stays. |
| D2 | Is the basic-salary rate (13.00) a **contract rate per employee** or a **company rate per month**? | 6 | Company rate type "basic" per month, with an optional fixed rate on the pay profile that overrides it. |
| D3 | Is the allowance rate the market rate on a given date (e.g. 22nd), entered monthly by HR? | 6 | Yes: dated rates; the run uses the latest rate on or before a configurable "rate date". |
| D4 | Overtime: approve **in advance only**, or in advance **and** confirm actual hours on the timesheet? | 9, 10 | Both: FM-07 approves hours in advance; the timesheet records actual hours; pay = the lower of the two (setting). |
| D5 | Do timesheets **replace** manual time inputs / after-the-fact overtime, or sit alongside? | 10 | Alongside, per setting: `payroll.time_source` = `timesheet` \| `manual`. Never both for the same month and employee. |
| D6 | Are freelancers paid through payroll (and taxed how), or invoiced with the timesheet used for client billing only? | 11 | Confirm with HR/finance before Phase 11; plan assumes payroll with day rates and a configurable withholding rate. |
| D7 | Full account number on the payslip (as the sample) or masked (today)? | 7 | Setting, default masked. |
| D8 | Does "Cost Centre" equal department, or a separate list? | 8 | Separate list, defaulting from the department. |
| D9 | What counts as a **day worked** for lunch and transport: are leave, offshore, travel, standby and public-holiday days included? (Sample: 18 of 21 working days counted) | 5.2 | Weekdays with normal hours at an onshore site; leave, offshore and holidays excluded. Configurable per component. |
| D10 | Overtime hourly rate: basic ÷ **22** fixed days ÷ 8 h (today), or ÷ the **period's working days**, or ÷ 173.33 h? And is basic taken at the basic exchange rate (13.00) or the allowance rate? | 5.3 | Ask for one employee's OT hours from the sample month and match the payslip figures. |

Record each answer in the "Decisions taken" table of `payroll-plan.md`. D2/D3 change decision #2 there.

---

## Phase 5 — Pay period cut-off

### 5.1 Configurable period start day — [x]
- **Setting**: `payroll.period_start_day` (1–28, default 1) in `SettingSeeder`; Settings → General → Pay runs.
- **Backend**:
  - `PayRunService::create()` derives `period_start`/`period_end` from the run's month and the start day: start day 21
    and month September → 21 Aug – 20 Sep. Add a `PayPeriod` value object (`forMonth(year, month)`,
    `containing(date)`) used everywhere instead of `startOfMonth`/`endOfMonth`.
  - Proration (joiners/leavers) works on the period's calendar days, not the month's.
  - `OvertimeService::problems()` monthly cap counts the pay period containing the date, not the calendar month.
  - `TimeInput` keeps `year`/`month` as the **pay month**; labels show the period dates.
  - Pay date default: setting `payroll.pay_day` (default: last day of the run's month).
- **Frontend**: new-run modal and run header show the period dates; time inputs page shows "Sep 2026 (21 Aug – 20 Sep)".
- **Tests**: period boundaries (21st start, December → January, February), proration across the boundary, overtime cap
  by period, existing runs with start day 1 unchanged.
- **Migration**: none (runs already store `period_start`/`period_end`).
- **As built**: `App\Services\Payroll\PayPeriod` (`forMonth`, `containing`, `ofRun`, `workingDays`, `label`);
  settings `payroll.period_start_day` (1–28) and `payroll.pay_day` (0 = last day) in General; `GET payroll/runs/period`
  previews a month's dates, working days and pay date (new-run modal). Overtime's monthly cap and time-input labels and
  the import's day check use the period. Changing the start day affects new runs only (manual says to check overlap).
  Tests: `tests/Feature/Payroll/PayPeriodTest.php`.

### 5.2 Working days and days worked — [x]
- **Backend**:
  - `PayPeriod::workingDays()`: weekdays in the period minus public holidays (`holidays` table). Sample: 21 Aug – 20 Sep
    2026 = 21.
  - **Days worked** per employee and run: a new pay run input source `days_worked`. Until timesheets exist (Phase 10) it
    is entered like a time input (a system "Days worked" unit, monthly, with the `time_input` approval); with timesheets
    it comes from the approved sheet (10.3) under D9's rule.
  - **Component options** (migration on `pay_components`):
    - `prorate_basis` = `employment` (today: joiners/leavers by calendar days) | `days_worked`
      (amount × days worked ÷ working days, capped at 1). Transport uses `days_worked`.
    - `quantity_source` = `input` (today) | `days_worked`: a per-day `rate_per_unit` component takes its quantity from
      days worked automatically. Lunch uses `days_worked`.
  - `PayrollCalculator` receives `working_days` and `days_worked` per employee; payslip lines show the quantity
    (e.g. "Lunch allowance (18 days)", "Transport allowance (18/21 days)").
  - A run warns about employees paid a days-worked component with no days worked recorded.
- **Frontend**: component drawer (`settings/components/*`) gets the two options; time inputs page shows "Days worked".
- **Tests**: the sample's figures — transport 1,500 × 18/21 = **1,285.71**, lunch 70 × 18 = **1,260.00**; working
  days with a holiday in the period; days worked above working days capped; joiner in the period (both bases
  combined: days worked already reflects the shorter employment, so `employment` proration is not applied on top).
- **As built**: days worked is a built-in component `DAYS_WORKED` (seeded by `PayComponentsSeeder`, never paid, not
  shown on payslips) entered as a **time input**, so it reuses the monthly grid, Excel import, approval and pay-run claim;
  it's offered only while a component follows days worked. Migration adds `pay_components.prorate_basis` /
  `quantity_source` and `payslips.working_days` / `days_worked`. A per-day-worked component is forced recurring (given
  to employees). Missing days worked: the component is left out with a payslip warning. Payslip detail returns `days`;
  the PDF shows the period and days worked. Preset: lunch `quantity_source: days_worked`, transport
  `prorate_basis: days_worked`, period start day 21. Tests: `tests/Feature/Payroll/DaysWorkedTest.php` (1,285.71 and
  1,260.00 reproduced).

### 5.3 Overtime hourly base — [x] (value pending D10)
- **Setting**: `payroll.overtime_hourly_basis` = `fixed_days` (today: `working_days_per_month` × `hours_per_day`) |
  `period_working_days` (5.2's working days × hours per day) | `fixed_hours` (`payroll.overtime_monthly_hours`, e.g.
  173.33); and, with Phase 6, which exchange rate converts a foreign-currency basic for the hourly rate.
- **Tests**: each basis; a test reproducing the sample's weekday/weekend overtime once Apave supplies the hours.
- **As built**: settings `payroll.overtime_hourly_basis` (default `fixed_days`, today's behaviour) and
  `payroll.overtime_monthly_hours` (173.33); `PayrollCalculator::overtimeHours()`. The exchange-rate part waits for
  Phase 6. Still to do: the sample reproduction test (needs the hours, D10).

---

## Phase 6 — Exchange rates by date and rate type

### 6.1 Dated rates with a rate type — [x]
- **Migration**: on `exchange_rates`, add `effective_date` (date) and `rate_type` (`standard` | `basic`, default
  `standard`); backfill `effective_date` = 1 Jan of `year`; keep `year` for reports. Unique on
  (`currency`, `rate_type`, `effective_date`).
- **Setting**: `payroll.exchange_rate_day` (day of the pay month whose rate is used, default = last day of the period);
  `payroll.separate_basic_rate` (bool, default off).
- **Backend**:
  - `ExchangeRate::forRun(PayRun $run)`: per currency and type, the latest rate on or before the rate date.
  - `PayRun.exchange_rates` becomes `{ "USD": { "standard": 11.3933, "basic": 13.00, "date": "2026-09-22" } }`
    (read the old flat shape too).
  - `PayrollCalculator::convert()` takes the rate type: basic salary uses `basic` when the setting is on (falls back to
    `standard`), everything else uses `standard`. Each payslip line keeps the rate it used (already stored).
  - Optional (D2): `employee_pay_profiles.fixed_exchange_rate` overrides the basic rate for that employee.
  - `ensureRates()` message names the rate type and date that is missing.
- **Frontend**: Settings → Currencies: rates by date and type; run page "Worked out with" shows both rates and the date.
- **Tests**: rate picked by date, basic vs standard, fallback, old runs still readable, approved runs lock the rates
  they used (existing rule, extended to dated rates).
- **As built** (payroll migrations are rerun, so the create migrations were edited and there's no backfill or reading
  of the old flat snapshot): `exchange_rates` has `rate_type` + `effective_date` (no `year`);
  `employee_pay_profiles.fixed_exchange_rate`. `ExchangeRate::inForceOn()` / `for($currency, $date, $type)` (basic falls
  back to standard); `EmployeePayProfile::basicRateOn()` (fixed → basic → standard) used by loans; `PayRun::rateFor()`
  used by back pay. `PayRunService::rateDate()` / `ratesOn()`; snapshot
  `{"USD": {"standard": {"rate", "date"}, "basic": {"rate", "date"} | null}}`. Settings `payroll.exchange_rate_day`,
  `payroll.separate_basic_rate`, `payroll.overtime_rate_type` (5.3's exchange-rate part) on Settings → Exchange rates.
  An approved/paid run locks the exact rate (currency, type, date) it used. Preset: USD standard 11.3933 from
  2026-09-22, basic 13.00, rate day 22, separate basic rate on (all CONFIRM). Tests: `ExchangeRatesTest` (sample's
  13.00 / 11.3933 split reproduced).

---

## Phase 7 — Payslip to the FM layout

### 7.1 Payslip header and totals — [x] (sample regression test pending)
- **Backend**:
  - Employee snapshot adds `engagement_date` (`job_details.joined_date`), `cost_centre` (Phase 8; department until then),
    `position`; payment snapshot already has bank, branch, account.
  - Payslip resource and PDF add: period start/end, pay date, exchange rates + date (from the run), **staff cost**
    (= gross + employer contributions; `employer_cost` exists, check it equals this), take-home pay.
  - Setting `payroll.payslip_fixed_lines`: component codes always printed, showing "–" when zero (the sample lists
    bonus, holiday overtime, loan deduction etc. at "–"). Default: none (today's behaviour).
  - Setting `payroll.payslip_show_account_number`: `masked` | `full` (D7).
- **Template** (`resources/views/payroll/payslip.blade.php`): two-column earnings/deductions table like the sample,
  header block (staff name, employee no., position, engagement date, cost centre, period start/end, social security
  no., payment date, exchange rates), gross / total deductions / employer SSF / staff cost / net, payment details row.
  Keep it configurable (no Apave text in the template).
- **Frontend**: payslip drawer (`apps/payroll/runs/payslip-*.tsx`) and self-service `my-payslip-card.tsx` show the
  same header fields.
- **Tests**: PDF renders the new fields; fixed lines at zero; masked vs full account; a regression test that rebuilds
  the sample payslip's figures from its inputs (basic, allowances incl. transport/lunch from 18 days worked, rates) and
  matches gross 20,893.70, deductions
  5,320.81, net 15,572.89 within rounding (PAYE depends on the confirmed rate set; mark as pending until D2/D3 and
  the go-live statutory rates are settled).
- **As built**: `App\Services\Payroll\PayslipPresenter` builds the header (staff no., position, engagement date, cost
  centre = department until Phase 8, SSNIT no., month, period, pay date, days worked, exchange rates by what they
  applied to with their date, payment with branch), the rows (shown lines plus `payroll.payslip_fixed_lines` codes —
  components or statutory SSNIT/TIER3/PAYE/SSNIT_ER — at null/"–", in payslip order) and totals (staff cost =
  `employer_cost`, checked equal to gross + employer contributions). Used by the PDF (`PayslipDocument::html()`,
  redesigned in the FM layout) and the payslip API (`header`, `totals`). `payroll.payslip_account_number` masked|full.
  Optional `company.address` setting on the PDF letterhead (Apave's added to `abave.json`). Frontend: drawer header,
  staff cost, settings for fixed lines and account number, period on self-service payslips. Preset: account number
  full, fixed lines for the preset's components + Tier 3 + loan repayment. Tests: `PayslipLayoutTest`.
  **Still to do**: the sample regression test (needs D2/D3 and confirmed statutory rates); the sample's bonus, offshore
  transport, risk (NDT-RT) and welfare contribution/loan lines aren't preset components yet (add with their rates).

---

## Phase 8 — Reference lists: sites, clients, work orders, cost centres

### 8.1 Master data — [x]
- **Migrations**: `work_sites` (code e.g. `TC`, `MV25`, name, `is_offshore`, active), `clients` (name, active),
  `work_orders` (client, number e.g. `PO# 4500028551`, description, start/end, active), `cost_centres` (code, name,
  active) and `job_details.cost_centre_id` (nullable; falls back to the department).
- **Backend**: CRUD controllers under `Payroll/` (permission `configure-payroll`); `payroll.overtime_locations`
  (today a plain list) migrates to `work_sites` (keep reading the setting until the data is moved).
- **Frontend**: Settings → Sites, Clients & work orders, Cost centres; selects reused by overtime, timesheets, payslips.
- **Preset**: `abave.payroll.json` gains sites and cost centres (values to confirm).
- **Tests**: CRUD, permissions, overtime location validated against active sites.
- **As built**: migration `2026_10_15_110000_create_payroll_reference_lists` (new tables, plus
  `job_details.cost_centre_id`); models `WorkSite`, `Client`, `WorkOrder`, `CostCentre` (soft deletes; codes and
  numbers unique among the not-deleted); `WorkSiteController`, `ClientController` (clients and work orders; a client
  with work orders can't be removed), `CostCentreController` (with employee counts); reads for configure|prepare|view,
  writes for `configure-payroll`. An employee's cost centre is set on Payroll › Employees
  (`PUT payroll/employees/{employee}/cost-centre`, `prepare-payroll`), not through self-service job details; the payslip
  snapshot uses it, else the department. `OvertimeService::locations()`: active site names, or the old setting while
  there are no sites (settings page says which). Added to the payroll audit trail. Preset: sites TC, ARD, MV25
  (offshore) and cost centre NDT (CONFIRM names). Tests: `ReferenceListsTest`, preset test.
  Not yet used: clients/work orders and `is_offshore` (Phases 9–11).

---

## Phase 9 — Overtime approved in advance (FM-07)

### 9.1 Request before the work — [x]
- **Setting**: `payroll.overtime_mode` = `after` (today) | `before` | `both` (D4). In `before`/`both`, future dates are
  allowed up to `payroll.overtime_advance_days` ahead; `after` keeps today's rule.
- **Migration** on `overtime_requests`: `work_order_id` (nullable), `activity_description` (required when no work
  order), `work_site_id` (replaces free-text `location`; keep the column for old rows), `urgency_reason`,
  `can_postpone` (nullable bool), `on_client_timesheet` (nullable bool), `actual_hours` (nullable, from the timesheet).
- **Backend**: `OvertimeService::problems()` validates the new fields by mode; the existing `reason` is shown as
  "Reason for urgency" in `before` mode.

### 9.2 Supervisor questions on the approval step — [x]
- **Approval workflow**: a step can ask questions. Add `questions` (JSON) to `approval_workflow_steps`
  (e.g. `[{key: "can_postpone", label: "Can the work be postponed…?", type: "yes_no", required: true}]`) and store
  answers in `approval_decisions.answers`; for overtime, answers with matching keys are copied onto the request.
- `ApprovalProcess::OVERTIME` exposes the two FM-07 questions as presets in the step editor.
- **Preset**: overtime workflow becomes supervisor (questions + approved hours) → Operations Manager/CEO
  (role), matching FM-07's two signature blocks.
- **Frontend**: request modal fields (`apps/payroll/overtime/*`, self-service `request-overtime-button.tsx`), the
  decide modal shows the step's questions, the request page shows the answers per step.
- **Tests**: advance requests by mode, required questions, answers saved per step and copied to the request,
  existing after-the-fact flow unchanged with mode `after`.

### 9.3 Paying pre-approved overtime — [x]
- In `before` mode, approved hours are paid as today. In `both` mode, a request is payable once its date has passed
  **and** `actual_hours` is set (by the timesheet, Phase 10, or by HR); pay = `min(approved_hours, actual_hours)`
  (setting `payroll.overtime_pay_basis` = `approved` | `actual` | `lower`).
- `OvertimeService::claimFor()` applies the basis and skips requests still waiting for actual hours (listed as a run
  warning).
- **Tests**: each pay basis; unconfirmed requests not paid and flagged.
- **As built (9.1–9.3)**: payroll migrations edited in place: `overtime_requests` gains `work_site_id`, `work_order_id`
  (FKs added in the reference-lists migration, which runs later), `activity_description`, `can_postpone`,
  `on_client_timesheet`, `actual_hours`, `requested_in_advance`; `approval_workflow_steps.questions`,
  `approval_decisions.answers`. Settings `payroll.overtime_mode` (after|before|both, default after),
  `overtime_advance_days` (30), `overtime_pay_basis` (lower). In advance: today up to the days ahead, a work order or
  activities required, `reason` is the reason for urgency. A site is chosen by uuid (or a location name matching a site).
  Questions are per process (`ApprovalProcess::questions()`: overtime's two FM-07 ones), chosen per step, required
  (yes/no) to approve, optional on rejection, stored on the decision and copied by the subject's `applyAnswers()`.
  `OvertimeRequest::payableHours()`; `OvertimeService::confirmActual()` (`POST payroll/my-overtime/{r}/actual-hours`,
  `POST payroll/overtime/{r}/actual-hours`); `claimFor()` returns the waiting notes, which go on the payslip warnings.
  Preset: mode `before`, FM-07 workflow supervisor (questions) → role `operations-manager` (the preset refuses until
  the role exists). Tests: `OvertimeInAdvanceTest`, preset test.

---

## Phase 10 — Daily timesheets (FM-06)

### 10.1 Timesheet model and entry — [x]
- **Flag**: `features.payroll.timesheets` (off). **Permission**: `manage-timesheets` (HR, timekeepers).
- **Settings**: `payroll.timesheet_columns`: which columns are shown and what each feeds, e.g.
  ```json
  [
    {"key": "normal_hours",   "label": "Normal 8–5 (8 hrs)",      "type": "hours"},
    {"key": "ot_weekday",     "label": "Overtime hrs (Mon–Fri)",  "type": "hours", "feeds": "overtime", "day_kind": "weekday"},
    {"key": "ot_weekend",     "label": "Weekend overtime",        "type": "hours", "feeds": "overtime", "day_kind": "weekend"},
    {"key": "ot_holiday",     "label": "Holiday overtime hrs",    "type": "hours", "feeds": "overtime", "day_kind": "holiday"},
    {"key": "offshore",       "label": "Offshore days",           "type": "check", "feeds": "time_input", "component": "OFFSHORE_DAY"},
    {"key": "rope_offshore",  "label": "Rope access offshore",    "type": "check", "feeds": "time_input", "component": "RA_OFFSHORE"},
    {"key": "rope_onshore",   "label": "Rope access onshore",     "type": "check", "feeds": "time_input", "component": "RA_ONSHORE"},
    {"key": "night_duty",     "label": "Night duty – RT",         "type": "check", "feeds": "time_input", "component": "NIGHT_RT"}
  ]
  ```
  Site and leave days are fixed columns. Columns live in the preset, not in code.
- **Migrations**: `timesheets` (employee, pay year/month, period start/end, template `staff` | `freelance`, status
  `draft → submitted → approved | rejected`, submitted_at, rejected_at, rejection_reason, totals JSON, pay_run_id);
  `timesheet_days` (timesheet, date, values JSON keyed by column, work_site_id, is_leave, leave_request_id, notes).
- **Backend**: `TimesheetService`:
  - `open(employee, period)` creates the sheet with one row per day of the **pay period** (Phase 5), weekends and
    holidays marked, and leave days filled from approved `leave_requests` (read-only on the sheet).
  - Validation: hours ≤ 24 a day; overtime hours only on days of the right kind; overtime hours on a day need an
    approved FM-07 request for that day when mode is `before`/`both` (FM-06 note: "only if requested by the supervisor");
    a "check" column only where the site allows it (e.g. offshore only at offshore sites).
  - Endpoints: `GET/PUT payroll/my-timesheets/{period}` (employee), `GET payroll/timesheets` (HR, filters),
    `POST …/submit`, Excel import of a filled FM-06 (one sheet per employee) for the transition.
- **Frontend**: self-service **My timesheet** (month grid like FM-06, mobile-friendly: a list of days on small
  screens), HR **Payroll → Timesheets** (status per employee, chase missing sheets). Delete the dead Redux timesheet
  code (`apps/leave-management/state/timesheets/*` and the unused screens under `apps/leave-management/timesheet/`
  that call `/timesheets`, `/group-timesheet`, `/my-timesheets`).
- **Tests**: period rows, leave prefill, holiday/weekend marking, validation rules, import.

### 10.2 Approval (supervisor → OM/CEO) — [x]
- `ApprovalProcess::TIMESHEET` (new case), `Timesheet` implements `ApprovalSubject`; default workflow supervisor →
  Operations Manager/CEO role. Rejection keeps the date/time and reason on the sheet (FM-06 "Rejected Date and Time",
  "Reason for Rejection") and reopens it for the employee.
- Approved sheets are locked; a correction after approval reopens it only while its pay run isn't approved.
- **Frontend**: approvals inbox shows timesheets with the grid read-only and totals.
- **Tests**: workflow, rejection reopens, lock after approval.

### 10.3 Feeding the pay run — [x]
- **Setting**: `payroll.time_source` = `manual` | `timesheet` (D5).
- With `timesheet`: approving a sheet writes its totals as approved `time_inputs` (source `timesheet`, linked back),
  records **days worked** for 5.2 (counted per D9), and sets `actual_hours` on the matching overtime requests (by date and day kind), creating "after" requests for
  unmatched overtime only when mode is `after`. With `manual`, nothing changes.
- A run warns about employees with no approved timesheet for its period.
- Printable FM-06 PDF (for clients) from an approved sheet, with the approvers' names and dates in the signature blocks.
- **Tests**: totals → time inputs, overtime matching, no double counting when re-approved, missing-sheet warning.
- **As built (10.1–10.3)**: migration `2026_10_15_120000_create_timesheet_tables` (`timesheets`, `timesheet_days`,
  `time_inputs.timesheet_id`, `overtime_requests.timesheet_id`, permission `manage-timesheets` for hr); flag
  `features.payroll.timesheets`; settings `payroll.time_source` (manual), `payroll.timesheet_columns` (generic: normal
  hours + three overtime columns), `payroll.timesheet_worked_columns` (normal_hours; D9). `TimesheetService` (open with
  leave from `hr_approved` leave, present, save/validate, submit, finished, reopen, apply/unapply, importFm06 by row
  position since FM-06's date cells are `TODAY()` formulas, missingFor); `PayPeriod::holidays()/dayKind()/ofSheet()`;
  `TimeInputService::fromTimesheet()`; `ApprovalProcess::TIMESHEET`; `TimesheetController` (my-timesheet, timesheets
  index/store/show/save/submit/reopen/import/pdf; approvers can view); inbox links to `/self-service/timesheets/{uuid}`;
  payslip warning "No approved timesheet for this month."; FM-06 PDF `payroll.timesheet`. Unmatched overtime on an
  approved sheet becomes approved overtime only in `after` mode (in advance, saving refuses overtime without a request).
  Frontend: self-service My timesheet (table on wide screens, list on phones), approver view, Payroll › Timesheets
  (+ sheet page with import/reopen), Settings › Timesheets (column editor). Dead Redux timesheet code removed
  (`apps/leave-management/state/timesheets`, `timesheet/timesheets`, `timesheet/my-timsheet`, `timesheet/index.tsx`,
  `menus/time-attendance-menus.ts`). Preset: FM-06 columns D–K, pay from timesheets, timesheet workflow supervisor →
  operations-manager. Tests: `TimesheetTest`, preset test.

---

## Phase 11 — Freelancers

### 11.1 Freelance timesheet — [x]
- Template `freelance` (Phase 10 model): per client and work order (one sheet per client/PO in a month, as the form's
  note says), columns Working day / Standby day / Travel day, task description, notes.
- Approval: client representative or supervisor → Operations Manager. The client representative is usually not a user:
  record their name and the date, with an optional signed-scan upload, as the supervisor step's answers.
- **Tests**: one sheet per client/PO/month, totals per day type.

### 11.2 Day-rate pay — [x]
- Employment type `freelance` (add to the job type options, front and back).
- Pay profile for freelancers: day rates for working / standby / travel days (components with `rate_per_unit`, unit
  `day`); no basic salary required (profile `basic_salary` nullable for this type).
- Tax: per D6, either the normal engine or a flat withholding rate set on the statutory rate set
  (`freelance_withholding_rate`), and SSNIT off for the type.
- **Tests**: day-rate payslip, tax treatment per setting.
- **As built (11.1–11.2)**: payroll migrations edited in place: `timesheets` gains `scope` (unique per employee/month:
  `staff`, `wo:{id}`, `client:{id}` or `freelance`), `client_id`, `work_order_id`, `task_description`,
  `client_rep_name`, `client_signed_on`, `client_signoff_path`; `statutory_rate_sets.freelance_withholding_rate`;
  `payslips.withholding_tax`. **Deviation**: the client's sign-off is recorded on the sheet (name, date, optional scan
  on the s3 disk) rather than as approval answers (those are yes/no), since the client rep usually isn't a user; the
  timesheet workflow then approves as usual. Freelancers are employees with job type `freelance` (added to the job
  type options). **Day rates**: a per-unit component given to an employee with their own amount now pays that amount
  *per unit* of the month's quantity (time inputs / timesheets), instead of as a flat standing amount; with no quantity,
  nothing. Freelancers' basic can be 0 (not printed), they have no SSNIT, and `payroll.freelance_tax` = `paye`
  (default) | `withholding` (flat `freelance_withholding_rate`; runs refuse without it; line `WHT`, kept out of PAYE).
  Settings `payroll.freelance_timesheet_columns` (working / standby / travel day) and `payroll.freelance_worked_columns`.
  A freelancer's sheets that month add up (each feeds its own time inputs). Endpoints: `GET/POST payroll/my-timesheets`,
  `POST …/{t}/details`, `GET payroll/timesheets/{t}/signoff`, HR `POST payroll/timesheets` with `template: freelance`.
  PDF titled "Freelance timesheet" with client, PO, task and the client's sign-off. Frontend: My timesheet lists a
  freelancer's sheets with New sheet; details card (task, signed by, date, scan); HR page lists each freelancer's
  sheets; settings for freelance columns and tax; statutory withholding rate. Preset: FL_* day components (rates per
  freelancer), freelance columns, tax `paye` (D6 to confirm). Tests: `FreelancePayTest`, `FreelanceTimesheetTest`.

---

## Phase 12 — Timesheets and overtime as one flow

Why: today a weekend shift is keyed twice: an FM-07 overtime request, then the same hours typed into the timesheet's
overtime column (which refuses them unless they match). The month grid also asks for every column of every day by
hand, including which hours are overtime, and the site picker only offers the active list. Phase 12 makes the
timesheet the record of hours worked, computes normal vs overtime hours from them, and makes an approved overtime
request show up on the timesheet ready to confirm. The FM-06 columns, PDF, approval and pay feed (Phase 10) stay as
they are: they are now *derived* from the entries instead of typed.

Decisions taken (2026-10-15):

| # | Question | Decision |
|---|---|---|
| D11 | Enter hours, or start/end times? | **Hours by default, times optional.** With times, time outside the working day is overtime. FM-06 import gives hours only. |
| D12 | Does weekday overtime need a request in advance? | **Setting per day kind** `payroll.overtime_advance_for` = `["weekend", "holiday"]` (preset). Weekday hours past the normal day are claimed on the timesheet and approved with it. |
| D13 | One entry per day, or several? | **Several** (an employee can work at more than one site in a day). See 12.2. |
| D14 | Who can create a site? | **Anyone filling a timesheet or overtime request**, by typing a name that doesn't exist. New sites are flagged for HR to review, merge or discard (12.5). |

### 12.1 The working day — [x]
- **Settings** (`SettingSeeder`): `payroll.work_day_start` (`08:00`), `payroll.work_day_end` (`17:00`),
  `payroll.work_day_break_minutes` (60). The normal day = end − start − break (8 h by default).
- `payroll.hours_per_day` (used for the overtime hourly rate) follows from them: Settings › General shows it as
  derived, and saving the working day updates it, so the rate and the timesheet split never disagree.
- `payroll.overtime_advance_for` (D12): the day kinds whose overtime needs an approved request before the work, in
  `before`/`both` mode. Replaces today's "every overtime column needs a request" check in `dayProblems()`.
- **Frontend**: Settings › Timesheets gains the working day (start, end, break) and the advance-request day kinds.
- **Tests**: normal day from the settings; hours_per_day kept in step.

### 12.2 Entries: several per day — [x]
- **Migration** (edit `2026_10_15_120000_create_timesheet_tables` in place): new `timesheet_entries` (id, uuid,
  timesheet_day_id, work_site_id nullable, hours, start_time / end_time nullable, break_minutes nullable,
  `checks` JSON (the tick columns, e.g. offshore, rope access), overtime_request_id nullable, notes, sort, soft
  deletes). `timesheet_days` keeps date, kind, leave and `values`, which become the **computed** column totals for the
  day; its `work_site_id` goes (the day's sites come from its entries).
- **Rules** (`TimesheetService::dayProblems()`):
  - The day's hours add up to ≤ 24. With times, entries don't overlap; an end before the start runs past midnight and
    counts on the start date.
  - A tick only where the entry's site allows it (offshore only at an offshore site, as today).
  - Leave days take no entries (as today); a part day of leave is out of scope.
- **The split** (`TimesheetService::split(day)`): on a weekday, the first *normal day* of hours are normal and the
  rest are weekday overtime; on a weekend or holiday every hour is overtime of that kind. With times, hours outside
  the working day are overtime and hours inside are normal (up to the normal day). Across entries, hours are taken in
  time order (or entry order without times), so the overtime lands on the later entry, i.e. on the site where it was
  worked. The day's `values` = normal hours + overtime by kind + each tick column (a tick counts once per day even if
  two entries have it). Totals, days worked, `apply()` and the FM-06 PDF keep reading `values`, so pay is unchanged.
- **Per-site totals**: `totals.sites` = hours (normal / overtime) per site for the month, for client billing and the
  approver's view.
- **FM-06 PDF / import**: the site column lists a day's sites ("TC / ARD"). Import makes one entry per day with the
  row's hours and site (no times), and reads the overtime columns as given.
- **Freelance sheets** keep one row per day with the day-type ticks (no hours, no split); they are per client/PO
  already.
- **Cost of several entries vs one**: the table and the "add another site" button are small; the real work is the
  split across entries and the PDF's site column, both above. Doing it now avoids migrating one-entry days later.
- **Tests**: split on weekday / weekend / holiday, hours vs times, overtime on the later entry, ticks once per day,
  24 h and overlap rules, midnight shift, import, PDF site column.

### 12.3 Overtime requests on the timesheet — [x]
- **Approving a request** (`OvertimeRequest::approvalFinished`) puts it on that day of the employee's timesheet for
  its pay month (opening the sheet if needed): an entry with the request's site, hours = approved hours, marked
  *planned* and linked (`overtime_request_id`). The employee confirms it ("Worked as approved") or changes the hours;
  confirming sets the request's `actual_hours` when the sheet is approved (`apply()` today matches by date instead,
  which goes). A cancelled or rejected request removes its planned entry if still unconfirmed.
- An entry with overtime on a day kind in `overtime_advance_for` and no approved request: the form says so and offers
  **Request overtime for this day** (prefilled), instead of refusing on save. Submitting the sheet is refused while
  such hours remain, as today.
- Weekday overtime not in `overtime_advance_for`: claimed by the sheet; on approval `apply()` creates the approved
  overtime request (today's `after` behaviour, now per day kind instead of per mode).
- The separate "confirm actual hours" action on a request (`POST …/actual-hours`) stays for HR (sheets not in use,
  corrections) but disappears from the employee's overtime page when `time_source` = `timesheet`.
- **Tests**: approval creates the planned entry; confirm and change; cancel removes it; request offered for an
  unapproved weekend; weekday overtime claimed by the sheet; no double counting with `overtime_pay_basis`.

### 12.4 Entering time: calendar and day form — [x]
- **My timesheet** becomes a **month calendar** (a list on phones): each day shows its state: empty working day,
  filled (hours, sites), leave, holiday, weekend, planned overtime to confirm, or a problem. Totals and days worked
  above it; Submit as today.
- **Day drawer** (tap a day): its entries, each with site (creatable, 12.5), hours or start/end + break, the ticks
  its site allows, notes; **Add another site**; the computed split shown live ("8 h normal · 3 h weekday overtime");
  planned overtime with Confirm / Change; Request overtime when needed (12.3).
- **Fill normal days**: fills every empty working day in the period (not leave, holiday or weekend) with one entry
  of a normal day at a chosen site. **Same as previous day** in the drawer. Most months are then a handful of edits.
- The FM-06 grid stays as the **read-only** view (approvers, HR sheet page, PDF), now with the computed columns.
- Backend: `PUT payroll/my-timesheets/{t}/days/{date}` saves one day's entries (draft), `POST …/fill` for Fill
  normal days; the whole-sheet save stays for HR and import. The sheet is still submitted once a month.
- **Tests**: fill skips leave/holidays/weekends and filled days; per-day save; submit rules unchanged.

### 12.5 Sites: type, pick or create; HR reviews — [x]
- **Migration** (edit `2026_10_15_110000_create_payroll_reference_lists` in place): `work_sites` gains `needs_review`
  (bool), `created_by`, `merged_into_id` (nullable).
- **Search and create** for anyone who can fill a timesheet or request overtime: `GET payroll/work-sites/search?q=`
  (active sites by code or name) and `POST payroll/work-sites/quick` (name only). An exact match (ignoring case and
  spaces) returns the existing site instead of a duplicate. A new site is active, **not offshore**, `needs_review` =
  true, so it can't unlock offshore or rope-access ticks until HR marks it.
- **Frontend**: the existing `components/creatable-dropdown-search.tsx` for the site in the day drawer, the overtime
  request (self-service and HR) and freelance sheets; new sites tagged "New" in the list.
- **HR review** (Settings › Sites): a "To review" filter and count. Per site:
  - **Keep**: set code, name, offshore; clears `needs_review`.
  - **Merge into…** another site: moves its timesheet entries and overtime requests to that site, then soft-deletes
    it with `merged_into_id`. Refused when a pay run already approved holds any of them (their records are snapshots).
  - **Discard**: only when nothing uses it; otherwise HR is told to merge.
- **Tests**: dedupe on create; new site can't carry offshore ticks; merge moves entries and requests; discard refused
  when in use; search permissions.

**As built (12.1–12.5)**:
- **Working day**: `App\Services\Payroll\WorkingDay` (start, end, break, hours, `advanceKinds()`); settings
  `payroll.work_day_start` / `work_day_end` / `work_day_break_minutes`, `payroll.overtime_advance_for`. Saving any
  working-day field sets `hours_per_day` (refused under an hour). `OvertimeService::needsAdvance(kind)`.
  **Deviation**: the seeder default for `overtime_advance_for` is all three kinds, so organizations already approving
  overtime in advance keep today's rule; the Apave preset sets weekend + holiday. A column can be flagged `normal`
  (else the first hours column that feeds nothing takes normal hours).
- **Entries**: `timesheet_entries` (in the timesheet migration) + `TimesheetEntry`; `TimesheetService::split()`,
  `save()` (per day: `entries`, or `values` made into one entry for imports and old clients; linked entries kept),
  `saveDay()`, `fill()`, `recompute()` (day `values` and site from the entries), `totals.sites`. Problems on save:
  24 h, overlaps, start without end, break longer than the time, offshore ticks, no overtime column for the kind,
  leave days. **Deviation**: `timesheet_days.work_site_id` stays (freelance sheets use it; on staff sheets it's the
  first entry's site). Import no longer keeps the given overtime split: the row's normal + overtime hours are one
  entry, split by the working day.
- **Overtime on the sheet**: `OvertimeRequest::approvalFinished` → `TimesheetService::planOvertime()` (a planned,
  linked entry; on a weekend/holiday an existing entry is linked instead; skipped once the sheet is sent, or on leave).
  Planned entries don't count until confirmed (`planned: false`, or changed hours). Unrequested overtime on a kind in
  `overtime_advance_for` is flagged per day (`needs_request`) and refused on submit, with planned entries left
  unconfirmed. `applyOvertime()`: linked entries set `actual_hours` (a removed planned entry = 0); a request with no
  entry takes the day's other overtime (sheets from before entries); the rest is claimed on kinds not needing a
  request. `POST my-overtime/{r}/actual-hours` is refused when pay comes from timesheets; options expose
  `advance_kinds` and `confirm_on_timesheet`.
- **Sites**: `work_sites.needs_review`, `created_by`, `merged_into_id`; `GET payroll/work-sites/search`,
  `POST payroll/work-sites/quick` (throttled; code from the initials, made unique: "Tema Port" → TP, TP2); saving a site
  marks it reviewed; `POST work-sites/{s}/merge` (refused when on pay sent for approval); discard refused for a
  to-review site in use (established sites still soft-delete as before).
- **Offshore on top** (2026-10-16): overtime requests gain `addon_type_id` (an any-day type paid on top for the same
  hours: two pay run lines); the request form's any-day choice is a tick on top of the date's type, and an any-day
  type sent as the type becomes the add-on. Overtime a sheet claims at an offshore site is its own claim with
  `OvertimeService::onTopForOffshore()` (the first active any-day type) on top.
- **Endpoints**: `PUT payroll/my-timesheets/{t}/days/{date}`, `POST …/fill`, and HR's `timesheets/{t}/…` equivalents.
- **Frontend**: `timesheets/timesheet-calendar.tsx` (month grid, list on phones, Fill normal days), `day-drawer.tsx`
  (entries, hours or times, ticks, live split from `split.ts`, planned overtime, Request overtime prefilled), panel
  Calendar | Table; `components/site-picker.tsx` (creatable, on `creatable-dropdown-search`) in the day drawer and the
  overtime form; Settings › General working day, Overtime "Requested in advance on", Timesheets "Normal hours go
  here"; Settings › Sites To review / Keep / Merge / Discard; self-service overtime hides Confirm hours when the
  timesheet confirms them.
- **Tests**: `TimesheetEntriesTest`, `WorkSiteReviewTest`; `TimesheetTest` updated (hours are split by the kind of day
  whatever column they came in; the advance-request check moved to submit).

---

## Suggested order and size

| Phase | Depends on | Rough size |
|---|---|---|
| 5 Period cut-off, days worked, OT base | D1, D9, D10 | M |
| 6 Exchange rates | D2, D3 | M |
| 7 Payslip layout | 5, 6, D7 | M |
| 8 Reference lists | D8 | S–M |
| 9 Overtime pre-approval | 8, D4 | M |
| 10 Timesheets | 5, 8, 9, D5 | L |
| 11 Freelancers | 10, D6 | M |
| 12 Timesheets + overtime as one flow | 10, 9, D11–D14 | L |

Phases 5–7 alone make the payslip match the sample; 8–10 remove the paper timesheet and overtime forms. 12 removes the double entry between them.

Phase 12 order: 12.1 → 12.2 → 12.3 → 12.5 → 12.4 (the screen last, once the backend computes everything).

## Also to update
- `payroll-plan.md`: decision #2 (yearly exchange rate) once D2/D3 are answered; link this plan from its header.
- `payroll-manual.md`: sections for period cut-off, dated rates, overtime in advance, timesheets.
- After Phase 12: `payroll-manual.md` timesheet and overtime sections (calendar, day form, planned overtime, site review);
  `abave.payroll.json`: working day 08:00–17:00 with a 60-minute break, `overtime_advance_for` weekend + holiday.
- `abave.payroll.json`: start day 21, rate types, fixed payslip lines, sites, cost centres, timesheet columns,
  overtime and timesheet workflows (values to confirm with Apave); transport `prorate_basis: days_worked` and lunch
  `quantity_source: days_worked` once 5.2 exists.
- **Preset already updated (2026-10-15)**: overtime multipliers 1.25 / 1.5 / 2; lunch = GHS 70 per day
  (`rate_per_unit`, days entered as a time input for now); transport = GHS 1,500 fixed (prorated for joiners/leavers
  only until 5.2); `confirmed` set back to **false** — it had been committed as `true` with placeholder rates (USD rate,
  offshore day, offshore overtime, rope access), so `payroll:apply-preset` would have loaded them. If it was applied
  anywhere, check those values in Payroll → Settings.
