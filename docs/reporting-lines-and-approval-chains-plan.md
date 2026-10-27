# Reporting Lines & Approval Chains — Plan

Goal: one answer to "who is this employee's supervisor, and who is above them", used by every approval: leave,
appraisals and the payroll workflows (overtime, loans, time inputs, timesheets). Organizations with sections and units
under departments get approval chains that follow their structure, with a different number of steps for different
people from the same workflow:

```
Unit officer:        unit head ─► department head ─► director ─► HR
Department officer:  department head ─► director ─► HR
Director:            HR
```

- Backend: `hrms-be` (this repo) · Frontend: `/Users/israelnkum/WebstormProjects/ttu-hrms-frontend` (paths relative to `src/`)
- Status: **R1–R6 done** (2026-10-16, not committed). Each step can ship on its own; tick it when merged.

---

## Conventions

Repo conventions apply: integer `id` + `uuid` + soft deletes, settings and flags in `SettingSeeder` (deploy runs it),
permissions in migrations, `UserFacingException` / `ApiResponse`, a feature test per step. No organization names in
code: level names and chain rules are configuration.

---

## Where things stand (2026-10-16)

| Area | Today |
|---|---|
| **Supervisor** | `employee_supervisors` (supervisor, employee, Direct / In-Direct) and `DirectReportController` (`/direct-reports`) exist, but **no screen sets it** and the employee import has no column. The employee list's Supervisor column is always empty. |
| **Who reads it** | Payroll workflows' "The employee's supervisor" step (resolves to nobody, so it falls to the backup approvers: payroll admins, then super-admins); the dashboard's "my team"; competency evaluation (with department heads). |
| **Structure** | Departments nest to any depth (`parent_department_id`, a head `hod`), so sections and units can be sub-departments already. Separately, **Sub-unit** is a flat list of names on job details (`job_details.sub_unit_id`), linked to no department and used by no approval. |
| **Leave** | `App\Services\Leave\LeaveApprover`: the head of the nearest department above the employee (walking up, skipping departments with no head and ones the employee heads). One head, then HR. Ignores the supervisor link. |
| **Appraisals** | Hard-coded in `AssessmentAttemptController::findSupervisorUser()`: the head of the employee's own department (or of its parent if the employee is the head). Department with no head: nobody but HR (`finalize-appraisals`). Top-level head: auto-confirmed. Then HR finalizes (or the appraisal officer for HR staff), then signatures. Always one reviewer. Ignores the supervisor link. |
| **Payroll workflows** | `ApprovalWorkflowService::resolve()`: supervisor, own department head (`hodUser()`, the parent's head when the employee is the head), parent department head (exactly one level up, no skipping), role, permission, named people. A fixed list of steps per workflow. |

So: three different rules for "my head", none of them uses the supervisor link except the payroll workflows (where it's
never set), and no flow can follow a chain of heads of varying length.

---

## Decisions taken (2026-10-16)

| # | Question | Decision |
|---|---|---|
| D1 | Who is an employee's supervisor? | **The head of their unit by default** (the nearest head above them, walking up as leave does), **overridden by "Reports to"** when it's set on the employee. |
| D2 | What can heads above the appraiser do on an appraisal? | **Endorse or comment and pass it on, or return it to the appraiser.** Only the appraiser (the first step) changes scores. |
| D3 | Where does a chain stop? | **Per process**: leave and appraisals can differ. Set on the workflow step (payroll workflows, appraisals) or in leave settings. Stop at a level (e.g. Directorate), at the top, or after a number of heads. |
| D4 | Is Sub-unit different from a section? | **No: the same as a sub-department.** Sub-units become sub-departments; the Sub-unit list is retired. |

---

## Phase R1 — Structure: levels, and sub-units as sub-departments

### R1.1 Department levels — [x]
- **Setting** `org.department_levels`: the names of the levels, top first, e.g. `["Directorate", "Department",
  "Section", "Unit"]` (default `["Department"]`: one level, as today).
- **Migration**: `departments.level` (nullable small int, an index into the list). Not enforced against the tree (a
  unit can sit directly under a directorate); used to say where chains stop and to label the tree.
- **Frontend**: department form gains Level (from the setting); the departments list shows the tree with level tags.
  Settings page for the level names (with the other org settings).
- **Tests**: level saved and listed; unknown level refused.

### R1.2 Sub-units become sub-departments — [x]
- **Migration (data)**: for every (department, sub-unit) pair in use on `job_details`, create a child department named
  after the sub-unit under that department (level: the next one down, if levels are set), and move those employees
  into it (`employees.department_id`). Record the mapping in a log table so it can be checked. Then drop
  `job_details.sub_unit_id` use from forms, filters, exports and the diff (`JobDetail` `show_in_diff`), and retire
  `SubUnit` (`CommonController::getSubUnits`, `Controller` list) after a release.
- **Includes sub-departments**: anything that means "people in department X" must include its sub-departments once
  people move down the tree. Audit and fix: `EmployeeScope` department filter, reports and exports filtered by
  department, leave and appraisal HR lists filtered by department, the department head's "my department" views,
  department KPIs (`departmentKpis`), payroll cost centre default ("empty means their department": walk up to the
  nearest department with a cost centre), payroll reports by department (group by the chosen level).
- **Tests**: migration on fixture data (pairs → children, employees moved, mapping logged); department filters include
  descendants; cost centre falls back up the tree.

**As built (R1)**: setting `org.department_levels` (default `["Department"]`); `departments.level` and the move log
`sub_unit_moves` in migration `2026_10_16_130000_add_department_levels_and_move_sub_units` (a child named after the
sub-unit, or "ICT Unit (Finance)" when the name is taken; an existing child of that name is reused; rollback moves
people back and removes the children it made). `Department::levels()`, `levelName()`, `subtreeIds()`,
`subtreeIdsByUuid()`; `GET/PUT departments/levels` (`edit-department`; removing a level a department is at is refused);
departments take a `level` (resource gives `level` and `level_name`). Chains stop at a level: `{stop: "level", level}`
in `ReportingLine::chainAbove()` (the first department at that level or above, its head included), the workflow rule,
and the step editor. "Includes sub-departments" now in: the employee scope and filter, the employee list's department
filter, leave requests (`forDepartment`) and HR's leave list, leave and employee analytics, the public staff directory,
payroll employees, training needs and plan items, competency (already). Department KPIs on appraisals fall back to the
nearest department above with KPIs. Sub-unit is no longer shown on job details (nor in the change diff); the
`SubUnit` model, its list and `job_details.sub_unit_id` stay one release. **Not changed**: views of "my own
department's colleagues" (celebrations, upcoming leave) stay the same unit; payroll's cost centre default is the
department's name (departments have no cost centres), so payslips show the sub-department; payroll reports group by
the payslip's department as before; the appraisal reviewer list waits for R5. Frontend: department form Level, the
list's level tag, a **Levels** editor on Departments, "at a level" in the chain step. Tests: `DepartmentStructureTest`.

---

## Phase R2 — Reporting lines

### R2.1 One rule for "supervisor" and "heads above" — [x]
- **Service** `App\Services\Org\ReportingLine`:
  - `supervisorOf(Employee)`: their "Reports to" when set (and active), else the head of the nearest department above
    them that has a head other than themselves (today's `LeaveApprover::for()` rule, moved here).
  - `headsAbove(Employee, ChainRule)`: the heads of the departments above the employee's, nearest first, skipping
    departments with no head, the employee, and anyone already listed; stopping per the rule (at a level, at the top,
    or after N). The chain follows the **employee's** department even when "Reports to" points elsewhere.
  - `reportsOf(Employee)`: direct reports (people whose supervisor resolves to them), for "my team".
- `LeaveApprover`, `Employee::hodUser()`, `ApprovalWorkflowService::parentHead()` and
  `AssessmentAttemptController::findSupervisorUser()` call it instead of their own walks.
- **Tests**: supervisor by default and by override; heads skip headless departments and the employee; stop rules;
  loops in bad data stop.

### R2.2 Setting "Reports to" — [x]
- **Data**: `employees.reports_to_id` (see the change note below); empty means the head of their unit.
- **Backend**: `PUT employees/{e}/reports-to` (`edit employee`), clearing it returns to the default; the employee
  resource gives `reports_to` (set) and `supervisor` (resolved, with "by default" vs "set").
- **Frontend**: Job details: **Reports to** (employee picker, "Default: head of {unit}" placeholder); the employee list's
  Supervisor column shows the resolved supervisor (muted when by default); the profile's Direct reports tab lists
  `reportsOf`.
- **Import**: employee import gains a **Reports to (staff ID)** column; empty keeps it, `-` clears it.
- **Tests**: set, change (old row kept as history), clear; import column; list column resolved.

**As built (R2, R3.1)**: `App\Services\Org\ReportingLine` (`supervisorOf`, `reportsTo`, `unitHeadOf`, `headsAbove` with
`stop: top | count` (level comes with R1.1), `headsOwnChain`, `reportsOf`, `setReportsTo` refusing self and loops).
`LeaveApprover` now delegates to it (same rule as before). `ApproverType::SUPERVISOR` resolves through it.
`Employee::employeeSupervisor` is the latest Direct line. (The unused `GET /my-team` endpoint, which returned
`reportsOf`, was later removed; the dashboard shows **Colleagues** (the rest of the department, `GET /my-colleagues`)
and the **Reporting line** in tabs.) `EmployeeResource` gives the resolved `supervisor` and `supervisor_by_default`. Endpoints
`GET employees/{e}/reporting-line` (`view-employee`, or the employee their own), `PUT employees/{e}/reports-to` (`edit-employee`, activity logged).
Frontend: **Reporting line** tab on the employee's profile, loaded when opened (supervisor with "Head of their unit" or the person set,
change / go back, direct reports with Add and Remove); the list's "Reports to" column shows the resolved supervisor,
muted when it's the unit head. **Deviations**: there's no employee import screen (only a disabled one-off console
import), so instead of an import column HR adds several people from the supervisor's card (**Add** direct reports);
`hodUser()`, `parentHead()` and appraisals' `findSupervisorUser()` are unchanged until R3.2 / R5 (appraisals' review
list is built on department heads, so changing who reviews belongs with R5). Tests: `ReportingLineTest`.

**Changed (2026-10-16): one column instead of a table.** With one supervisor per employee, "Reports to" is
`employees.reports_to_id` (`Employee::reportsTo()`); migration `2026_10_16_120000_replace_employee_supervisors_with_reports_to`
copies each employee's current Direct line and drops `employee_supervisors` (In-Direct lines, which nothing used, go
with it; down() rebuilds the table from the column). Changes are kept in the activity log. Removed:
`EmployeeSupervisor`, `DirectReportController` and `/direct-reports`, `DirectReportResource`, `MyTeamResource`, the
direct-report form requests. The competency service, training evaluation and effectiveness checks read the column (or
`ReportingLine::supervisorOf`).

---

## Phase R3 — Approval chains in the workflow engine

### R3.1 Supervisor steps use the reporting line — [x]
- `ApproverType::SUPERVISOR` resolves through `ReportingLine::supervisorOf()`. Supervisor steps (overtime FM-07,
  timesheets FM-06) reach a real person instead of the backup approvers.
- **Tests**: a supervisor step goes to the unit head; to the Reports-to person when set.

### R3.2 "Heads up the chain" step — [x]
- **New approver type** `heads_chain` ("Heads up the chain"), with `approver_value` = the chain rule:
  `{stop: "top" | "level" | "count", level?: int, count?: int, skip_supervisor?: bool}`.
- **At start** (`ApprovalWorkflowService::start()`), the step is expanded into one step per head from
  `headsAbove()`, named after each head's department ("Head of Finance"), in the snapshot the approval already keeps.
  None found: the step is dropped (not escalated). `skip_supervisor` (default on) leaves out the head who already
  approved as supervisor.
- **Duplicates**: a step whose only approver is the same person as the step before is merged into it (one decision);
  the requester never approves their own (as today).
- **Preview** (`approval-workflows/{w}/preview`): pick an employee, see their expanded steps.
- **Frontend**: step editor offers the new type with "Stop at: the top / level [Directorate] / after [N] heads";
  approval progress shows the expanded steps.
- **Tests**: expansion for a unit officer, a department officer and a director from one workflow; stop rules; merged
  duplicates; dropped when nobody; a request started before a structure change keeps its snapshot.
- **As built**: `ApproverType::HEADS_CHAIN` (`heads_chain`), rule `{stop: top | count, count: 1–10, skip_supervisor}`
  (level waits for R1.1), checked in `ApprovalWorkflowController::chainRule()`. `ApprovalWorkflowService::stepsFor()`
  builds a request's steps (used by `start()` and the preview): chain steps become "Head of {department}" steps of type
  `users`; "after N heads" counts the heads asked (after leaving out the supervisor); a step whose only approver is the
  same as the step before is folded into it; positions run 1…n. `ReportingLine::chainAbove()` gives each head with the
  department they head. Frontend: the step editor's "Heads up the chain" with Stop (at the top / after N heads) and
  "Leave out the supervisor"; the workflow card describes it. Tests: `ApprovalChainTest`.

---

## Phase R4 — Leave follows the reporting line

### R4.1 Leave approvers — [x]
- The leave HOD step becomes **the supervisor** (`supervisorOf`), then optionally heads above, then HR.
- **Setting** `leave.approval_chain` (D3): the same chain rule (R3.2); default `{stop: "count", count: 0}` = supervisor only,
  then HR, which is today's behaviour where Reports-to isn't set.
- With more than one head: each approves in turn (statuses stay `pending` → `hod_approved` → HR's; the request records
  the heads who approved and whose turn it is: new `leave_request_approvals` rows, shown on the request).
- **Frontend**: leave settings for the chain; the request shows each head's decision.
- **Tests**: default unchanged; Reports-to approves; two heads then HR; a head returning or rejecting.
- **As built**: setting `leave.approval_chain` (`{stop: count|level|top, count?, level?}`, default count 0);
  `LeaveApprover::for()` is the supervisor (Reports-to aware), `chainFor()` the supervisor then heads above per the rule,
  `rule()` the checked setting. Migration `2026_10_16_140000_add_approval_chain_to_leave_requests`: `leave_requests.approval_chain`
  (employee ids, fixed when requested) and `approval_step`; `leave_approvals.step` with the unique index now
  (request, role, step) (the role column only allows hod/hr). `supervisor_id` stays the head whose turn it is, so the
  team list, emails and checks work as before; approving with heads left moves it on (status stays pending, the next
  head is emailed, the employee told), the last head's approval goes to HR; a rejection ends it. On a request with a
  chain only the current head may decide (department heads can still see and cancel as before). Requests made before
  this have no chain and behave as before. `GET/PUT leave-settings` (`edit-leave-types`); the request resource gives
  `approval_chain` (name and state per head) and each decision's `step`. Frontend: **Who approves leave** card on Leave
  types; the request's progress shows one step per head. Tests: `LeaveChainTest` (+ `LeaveApprovalChainTest` unchanged).

---

## Phase R5 — Appraisals on approval chains

### R5.1 Appraiser and endorsers — [x]
- **The appraiser** is `supervisorOf(employee)` (replacing `findSupervisorUser`), so Reports-to and unit heads work;
  a department with no head walks up instead of reaching nobody. Top of the tree: auto-confirmed, as today.
- **Process** `ApprovalProcess::APPRAISAL` on the workflow engine, started when the appraiser confirms
  (`supervisorConfirm`). Its default workflow: **Heads up the chain** (stop per D3, e.g. at Directorate), then the
  finalizing step stays HR's / the appraisal officer's own screen as today.
- **New status** `pending_endorsement` between `supervisor_confirmed` and HR. Each endorser (D2):
  - **Endorse** (with an optional comment): next head, or HR when the chain ends;
  - **Return to the appraiser** (comment required): back to `pending_supervisor`; the appraiser revises (and can
    return it to the employee as today) and confirms again, which restarts the chain.
  - Endorsers see scores, comments and KPIs read-only; they can't change scores.
- HR's and the appraisal officer's lists show `supervisor_confirmed` **after** the chain is endorsed (or when the
  workflow has no chain), so nothing reaches HR early. Comments go on the appraisal's events and print on the PDF.
- Attempts already past `pending_supervisor` when this ships finish the old way.
- **Frontend**: an "Endorse appraisals" list (from the approvals inbox, linking to the appraisal review page in a
  read-only endorser mode with Endorse / Return); the employee's and HR's views show the endorsement trail.
- **Tests**: appraiser by default and by Reports-to; headless department walks up; a unit officer's appraisal goes unit
  head (appraiser) → department head → director → HR; a department officer's skips straight to the director;
  return restarts the chain; endorsers can't change scores; HR sees it only after the last endorsement; in-flight
  attempts unchanged.
- **As built**: the appraiser is `ReportingLine::supervisorOf()` (`findSupervisorUser`); nobody above them →
  auto-confirmed (was "top-level HOD"). The supervisor's list (`supervisor/pending`) is the people they appraise
  (`reportsOf`). **Deviation**: instead of a workflow in Payroll › Approval workflows (behind the payroll flags), how far
  endorsement goes is a setting, `appraisal.endorsement_chain` = `{stop: none | count | level | top}` (default none:
  straight to HR, as before), edited on Assessment sessions (`GET/PUT appraisal/settings`, `manage-appraisal-windows`).
  The steps still run on the approval engine: `ApprovalProcess::APPRAISAL` (not configurable as a workflow, kept out of
  the payroll inbox and its decide endpoint; fallback approvers `finalize-appraisals`), started with
  `ApprovalWorkflowService::startWith()` from `App\Services\Appraisal\AppraisalEndorsement` (heads above, leaving out
  the employee and the appraiser). It starts when the employee **acknowledges** (that's when an appraisal reached HR
  before). Status `pending_endorsement` and events `sent_for_endorsement`, `endorsed`, `endorsement_returned` (migration
  `2026_10_16_150000_add_endorsement_to_appraisals`, enums extended). Endorse (comment optional) / return (comment
  required, back to `pending_supervisor`, the appraiser emailed): `appraisal/endorsements` (list, `/{attempt}/endorse`,
  `/{attempt}/return`). The attempt resource gives `endorsement` (steps, who, when, comment). Frontend: **Endorse
  Appraisals** under My Team (read-only review panel with Endorse / Return, the steps above it), the setting on
  Assessment sessions, history labels and the "Being endorsed" status. Tests: `AppraisalEndorsementTest`.

---

## Phase R6 — Docs — [x]

- `docs/payroll-manual.md` (supervisor and chain steps in Approval workflows) and the appraisal and leave guides: how
  supervisors are worked out, Reports to, levels, and what each approver does.
- AGENTS.md: point "who approves" to `ReportingLine`.
- **As built**: new user guide `docs/reporting-lines-guide.md` (structure and levels, Reporting line and Reports to,
  leave approvers, appraisal endorsement, payroll chain steps, questions, settings) in the payroll manual's format (it
  builds with `storage/app/manual/build.py`); there were no separate leave or appraisal guides to update. The payroll
  manual's setup step 9, "When a step finds nobody" and the heads-of-department note now describe the default
  supervisor. AGENTS.md has a "Reporting Lines & Approvals" section.

---

## Suggested order and size

| Phase | Depends on | Rough size |
|---|---|---|
| R1 Structure (levels, sub-units → sub-departments) | D4 | M (the "includes sub-departments" audit is most of it) |
| R2 Reporting lines (service, Reports to) | R1.1 | M |
| R3 Workflow chains | R2 | M |
| R4 Leave | R2, R3.2's chain rule | M |
| R5 Appraisals | R2, R3 | L |
| R6 Docs | all | S |

R2 + R3.1 alone fix today's problem of supervisor steps reaching the backup approvers.

## Open points to confirm before building
- The level names each organization uses (for the `org.department_levels` setting and its default).
- R1.2 moves employees into new sub-departments: confirm with HR before running it on live data, since department-based
  reports will then show the new units (rolled up by the filters above).
- Whether a director's own appraisal should go to a named person (e.g. the CEO) before HR instead of being
  auto-confirmed at the top of the tree: possible as an extra workflow step for directors only, if needed.
