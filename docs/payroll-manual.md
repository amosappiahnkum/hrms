# Payroll Manual

How to set up payroll, run it every month, and handle overtime, time inputs and staff loans. It is written for HR
and payroll officers, managers who approve requests, and employees using self-service. Amounts are in Ghana cedis
(GHS) unless your organization pays in another currency.

## Contents

- **Getting started**
  1. [What the module does](#1-what-the-module-does)
  2. [Who can do what](#2-who-can-do-what)
- **Setting up**
  3. [Setting up, step by step](#3-setting-up-step-by-step)
  4. [Pay components](#4-pay-components)
  5. [Employees' pay details](#5-employees-pay-details)
  6. [Approval workflows](#6-approval-workflows)
- **Every day**
  7. [For employees](#7-for-employees)
  8. [For approvers](#8-for-approvers)
  9. [Overtime](#9-overtime)
  10. [Time inputs and timesheets](#10-time-inputs-and-timesheets)
  11. [Staff loans](#11-staff-loans)
- **Every month**
  12. [Running payroll each month](#12-running-payroll-each-month)
  13. [Bonuses and extra payments](#13-bonuses-and-extra-payments)
  14. [Back pay](#14-back-pay)
  15. [How pay is worked out](#15-how-pay-is-worked-out)
- **Reports**
  16. [Reports and the dashboard](#16-reports-and-the-dashboard)
  17. [Audit trail](#17-audit-trail)
- **Help**
  18. [Common problems](#18-common-problems)
  19. [Settings at a glance](#19-settings-at-a-glance)

---

## 1. What the module does

The payroll module works out each employee's pay every month the Ghana way: basic salary and allowances, SSNIT,
income tax (PAYE) by the GRA bands, then deductions such as loan repayments. It produces payslips, the statutory
schedules for SSNIT and GRA, and bank or mobile money payment files.

It has four parts. Each can be switched on by itself, so an organization can use only what it needs.

| Part | What it covers |
|---|---|
| **Payroll** | Pay details, monthly pay runs, payslips, statutory reports, payment files. |
| **Overtime** | Employees request overtime; it goes for approval; approved hours are paid with salary. |
| **Time inputs** | Units per month, such as offshore days or shifts, entered or imported by HR. |
| **Staff loans** | Loan and advance requests, approval, paying out, and repayment from pay. |

A super-admin switches the parts on in **Employee management › System Features**. Overtime, time inputs and loans also
work with payroll switched off: you then export the approved figures and pay them in your usual system.

## 2. Who can do what

Access depends on permissions. HR has all of them to start with; give them to finance or management as needed under
user roles and permissions.

| Permission | Lets a person |
|---|---|
| `configure-payroll` | Change payroll settings, rates, components, workflows and loan types. See the audit trail. Decide requests nobody else can. |
| `prepare-payroll` | Set employees' pay details, create and calculate pay runs, enter inputs, mark runs paid. |
| `approve-payroll` | Approve pay runs (when the workflow asks for it). |
| `view-payroll` | Read pay details, runs, reports and the dashboard without changing anything. |
| `view-overtime` | See everyone's overtime requests and export them. |
| `enter-time-inputs` | Enter and import time inputs (for timekeepers and site supervisors). |
| `manage-loans` | Use the loans register: pay out, record repayments, settle, pause. |

Employees need no permission for their own payslips, pay details, overtime and loans. Approvers need none either: a
request reaches them because the workflow names them.

---

## 3. Setting up, step by step

*For: HR / payroll officer, administrator.*

Do these once, in this order. Everything is under **Payroll › Settings** unless stated.

1. **Switch on the parts you need** in **Employee management › System Features** (super-admin).
2. **General.** Set the **pay period** (the day it starts: 1 for the calendar month, or e.g. 21 for the 21st of the
   previous month to the 20th) and the **pay day**. Set the base currency (GHS), working days a month (usually 22) and
   the **working day** (usually 08:00–17:00 with a 60-minute break, which makes 8 hours a day), and what overtime's
   hourly rate is based on. Choose how overtime and bonuses are taxed, whether pay runs need a different
   approver from the preparer, and what payslips show.
3. **Statutory rates.** A Ghana starting set is loaded: SSNIT rates, PAYE bands, reliefs, Tier 3 limit, overtime and
   bonus tax. Check every figure against the current GRA and SSNIT publications, correct anything out of date, then
   press **Confirm**. Pay runs refuse to calculate until a set is confirmed. When rates change, add a new set from the
   date it applies; earlier runs keep the old one.
4. **Exchange rates.** Only if some employees are paid in another currency (for example USD). Each rate applies from
   a date until a later one, so add a new rate whenever it changes (e.g. every month). A pay run uses each currency's
   latest rate on or before its **rate date**: the last day of the pay period, or a day you choose ("Rates are taken
   on day"). Switch on **Basic salary has its own rate** to convert basic at a separate rate (add rates "for Basic
   salary"); everything else uses the standard rate. An employee with a rate fixed in their contract can have it on
   their pay details instead. Rates an approved pay run used can't be changed.
5. **Pay components.** Add your allowances, deductions and overtime rates. See [Pay components](#4-pay-components).
6. **Overtime** and **Loans** sections, if those parts are on. See [Overtime](#9-overtime) and
   [Staff loans](#11-staff-loans).
7. **Banks and payment files.** In **Settings › Banks**, list the banks employees are paid through (or add them as you
   set up pay details), and under each bank's **Branches** its branches with their sort codes. Merge any duplicates
   (e.g. "GCB" and "GCB Bank", or two spellings of a branch) with the merge button: their employees and payment files
   move across. Payment file layouts can include the **Bank code** and **Branch sort code** columns. Then add a layout for each bank upload or mobile money file: which employees it covers
   (everyone paid by bank, or one bank from the list), CSV or Excel,
   and the columns in order. Use **Preview** to check it.
8. **Approval workflows.** Decide who approves overtime, time inputs, loans and pay runs. See
   [Approval workflows](#6-approval-workflows).
9. **Heads of department, and exceptions to who reports to whom.** In Employee management, give every department
   (and section or unit) a head. Everyone's supervisor is then the head of their unit, and a head's is the head above
   them, with no further setup. Where someone reports to a different person, set it on their **Job details ›
   Reporting line**. See the *Reporting lines and approvals* guide.
10. **Employees' pay details.** Set each employee's basic salary and how they are paid. See
    [Employees' pay details](#5-employees-pay-details).

> **Apave:** Apave's components, overtime types and workflows can be loaded in one go by your technical team with
> `php artisan payroll:apply-preset abave`. It only runs once every rate in the preset has been confirmed.

> **Saving a setup as a preset (technical team).** Once an organization's payroll is set up on the screens,
> `php artisan payroll:export-preset <organization>` writes it to `database/seeders/organizations/<organization>.payroll.json`:
> the payroll features that are on, the payroll settings (pay period, overtime rules, timesheet columns, payslip lines…),
> the latest exchange rate of each currency, sites, cost centres, pay components (not the built-in ones), overtime types
> and the approval workflows. Commit the file; `payroll:apply-preset <organization>` rebuilds the same setup on another
> server or after a reinstall. Options: `--force` to replace an existing file, `--path=` to write elsewhere, `--stdout` to
> print it. Not included: statutory rates (confirm them on each server), banks and payment file layouts, and anything
> about employees. Roles named in workflows must exist on the server before applying.
>
> **Overtime and timesheets** are separate features (off unless turned on): an organization without them never sees
> them. What they pay and how is set up per organization (overtime types, timesheet columns, sites), so turning them on
> elsewhere starts from that organization's own setup, not Apave's.

## 4. Pay components

A pay component is any line that can appear on a payslip. Find them in **Payroll › Settings › Pay components**,
grouped as earnings, deductions and employer contributions.

### How the amount is worked out

| Choice | Means | Example |
|---|---|---|
| Fixed amount | The same amount each month (an employee can have their own amount). | Transport GHS 300 |
| Percentage of basic salary | A share of the month's basic. | Housing 10% |
| Multiple of the hourly rate | Hours × hourly rate × the multiple. For overtime. | Weekday overtime 1.5× |
| Rate per unit | Quantity × a fixed rate per day, hour, trip… The quantity is entered each month, or is the **days worked**. | Offshore day USD 3 per day · Lunch GHS 70 per day worked |
| Entered each time | You type the amount each time it's used. | Bonus, one-off deduction |

### Switches on each component

- **Taxable** (earnings): counts towards PAYE. For deductions this reads **Taken before tax**, for example an approved
  pension contribution.
- **A bonus**: taxed at GRA's bonus rate up to its share of annual basic (see
  [How pay is worked out](#15-how-pay-is-worked-out)).
- **Subject to SSNIT**: usually only basic salary.
- **Recurring**: a standing item employees are given every month. Off means it's entered per pay run.
- **Pro-rated**: reduced for part months. **Pro-rated by** chooses how (fixed, entered and percentage amounts):
  - *Days employed*: for joiners and leavers, by calendar days in the period.
  - *Days worked ÷ working days in the period*: e.g. transport GHS 1,500 with 18 of 21 days worked = GHS 1,285.71.
- A rate per unit paid **per day worked** is always recurring: give it to the employees who get it.
- **Shown on payslips**, **Active**, and an **account code** for the journal export.

Some components are built in and marked with a lock: **Basic salary**, **Back pay**, **Loan repayment**, **Loan
paid out** and **Days worked** (a count, never paid; see [Time inputs](#10-time-inputs-and-timesheets)). You can rename them and set their order, but not remove them; the system fills their amounts.

## 5. Employees' pay details

*For: `prepare-payroll`.*

Open **Payroll › Employees**. The list flags anyone missing what's needed to be paid (no pay details, no SSNIT
number, no TIN or Ghana Card, no bank account). Open an employee to see or change their pay.

### Pay details

Basic salary a month and its currency (in another currency, optionally a **fixed rate** agreed in their contract, used
instead of the pay run's rate for basic), how they're paid (bank with account details, mobile money, or cash), SSNIT
number, TIN, Tier 2 and Tier 3 schemes, reliefs claimed, whether they're tax resident in Ghana, and whether they can
claim overtime (leave empty to follow the overtime rules).

There are two ways to change details, and the difference matters:

| Button | Use it for | What happens |
|---|---|---|
| **Correct** | Mistakes, such as a wrong account number. | Fixes the details in force. Changing the salary this way also changes it for past months, which can create [back pay](#14-back-pay). |
| **Change from a date** | Raises and promotions, for example a raise from 1 July. | Adds new details from that date. Earlier months keep the old salary. |

Bank and mobile money numbers are stored encrypted and shown masked to the employee.

**Each employee needs their own account.** Saving details with an account number another employee is already paid
into (at the same bank), or their mobile money number, is refused and names who has it. The number is compared however
it was typed (spaces and dashes don't count; +233 24… and 024… are the same number). The same number at another bank is
a different account. The Excel import refuses such rows too, including two rows of the file with one account (two
employees swapping accounts in one file is fine). Details saved before this check are flagged on the payslips of the
pay run ("Paid into the same account as …"), and `php artisan payroll:account-fingerprints --check` lists them.

The **bank** is picked from the banks list: type part of its name or code. If it isn't listed, choose **Create "…"**
to add it; a bank with the same name or code (ignoring case and spaces) is picked instead of a copy. The **branch** is
then picked from that bank's branches the same way (by name or sort code). In the Excel import, the Bank column takes a
bank's name or code and the Branch column one of that bank's branches (name or sort code). A bank or branch that isn't
listed is **added when the import is saved**: once, however many rows name it (spelled differently, it takes the
properly capitalised spelling). The check before importing says which rows add one, and the result lists the banks
added. If an added bank turns out to be another name for one already listed, merge it in **Settings › Banks**.

### Many employees at once: Excel import

On **Payroll › Employees**, tap **Import from Excel**.

1. Download the template. It lists every employee with their current pay details. **Staff ID, Name and Department
   are locked** so you can see whose row it is; a hidden column is what actually identifies each row, so rows typed in
   or copied from another sheet are refused. You can still filter and sort the columns.
2. Change only what's different. An empty cell keeps what is saved now. Account and mobile money numbers are left out
   of the template on purpose; fill them in only to change them. **Paid by** and **Tax resident** have dropdowns.
3. **In force from** decides what a row does:
   - empty, or the current date: the current details are corrected;
   - a later date: new details from then (a raise), the old ones kept for earlier months;
   - for someone with no pay details yet: when they start (empty means the 1st of this month).
4. Choose the file. It's **checked first**: you see every row that would change, old → new, with anything that
   looks wrong highlighted (a pay cut, a big raise, a change of account or payment method, details that start after
   a pay run that's still open, corrections to months already paid). Nothing is saved until you tap **Import**. If
   any row has a problem, it's listed by row and nothing can be imported until it's fixed.

### Cost centre

At the top of an employee's pay page, choose the **cost centre** their pay is charged to. Empty means their department.
It's printed on their payslips. Cost centres are listed in **Settings › Cost centres**.

### Recurring components

Under the employee's pay, add the standing allowances and deductions they get each month, with their own amount if
different from the component's, and the dates they start and end.

### Sites, clients and work orders

In **Settings**: **Sites** (where work is done, with a code as used on timesheets, and whether it's offshore),
**Clients & work orders** (the clients you work for and the PO, work order or project numbers work is done under), and
**Cost centres**. Once there are active sites, overtime requests choose their location from them instead of the list
in Overtime settings. Removing an entry keeps it on records that already name it; a client can't be removed while it
has work orders (mark it inactive instead).

**Sites typed in by employees.** On a timesheet or overtime request, anyone can type a site that isn't listed and
add it. It's never offshore until you say so, so it can't unlock offshore allowances. Such sites are marked **To
review** at the top of **Settings › Sites**, with who added it and how much uses it:

- **Keep**: give it the right code and name, and say whether it's offshore.
- **Merge into…**: it duplicates another site (e.g. "Tema harbour" for TP). Its timesheet days and overtime requests
  move to that site and it's removed. Not possible once it's on pay already sent for approval.
- **Discard**: only while nothing uses it; otherwise merge it.

## 6. Approval workflows

*For: `configure-payroll`.*

A workflow is the list of people who approve a kind of request, in order. Set them in **Payroll › Settings ›
Approval workflows**, one or more per kind: overtime requests, time inputs, loan requests and pay runs. The one marked
**Default for this process** is used for new requests (a loan type can name its own).

### Who approves a step

- **The employee's supervisor**: who they report to (Employee management › Job details › Reporting line), or, when
  that isn't set, the head of their unit.
- **Head of their department** (for example the unit manager).
- **Head of the parent department.**
- **Heads up the chain.** One step per head above the employee, nearest first: their unit's head, then the
  department's, then the director's… Departments with no head are skipped. Choose where it stops (at the top, or after
  a number of heads) and whether to leave out the supervisor when an earlier step already asked them. One workflow
  then fits everyone: a unit officer might get supervisor → head of department → director → HR, a department officer
  supervisor → director → HR. Use **Preview** with an employee to see their steps.
- **Everyone with a role** (for example hr) or **everyone with a permission**: any one of them can decide.
- **Specific people**.

For each step, **May change** says what the approver can lower: approved hours for overtime, amount and repayment
period for loans, quantity for time inputs. Switch on **A different person at each step** so one person can't approve
twice. Use **Preview** to see who would approve for a chosen employee.

### When a step finds nobody

1. If a step finds nobody (someone at the top of the organization has no supervisor, for example), it goes to HR:
   everyone with `configure-payroll`. Pay runs go to everyone with `approve-payroll`. A **Heads up the chain** step
   that finds no heads is left out instead.
2. Nobody approves their own request. If that leaves no one, it goes to anyone else with `approve-payroll` or
   `configure-payroll`, or a super-admin.
3. If there is still no one, the request shows **No one can approve this step yet**. As soon as someone is given one of
   those permissions, it appears in their Approvals.

With no workflow set up at all, HR approves in a single step.

> **Heads of department:** a head's supervisor is the head of the department above them, so their requests go up the
> line on their own. At the top of the organization there's no one above, so HR approves.

Editing a workflow only affects new requests. Requests already under way keep the steps they started with.

---

## 7. For employees

*For: everyone.*

Everything is under **Self-service › My Workspace**. You see only the parts your organization uses.

### Payslips

Your payslip for each month appears once salaries have been paid. Tap **Download PDF** to keep a copy. Your
organization may also email you when it's ready.

The payslip shows your employee number, position, engagement date and cost centre; the pay period, payment date and
SSNIT number; for pay in another currency, the exchange rates it was converted at and their date; your earnings and
deductions side by side (some lines are always listed, with "–" when there's nothing); gross pay, total deductions,
what your employer paid and the staff cost (if your organization shows them), net salary; and the account it was paid
into.

### Pay details

Your salary, how you're paid and your statutory numbers, read-only. Account numbers are partly hidden. Tell HR if
anything is wrong.

### Overtime

1. Work the overtime first. Then open **Overtime** and tap **Request overtime**.
2. Choose the date worked and enter the hours. The type (weekday, weekend or public holiday) is chosen from the date
   and shown under it. If the work was offshore, tick **Offshore**: the hours are paid as the day's overtime *and* as
   offshore overtime.
3. Say what it was for, and where if asked. Tap **Send for approval**.
4. The request shows as Pending. Tap **Approvals** on it to see who has approved and who it's waiting for. You can
   **Withdraw** it while it's pending.
5. You get an email when it's approved or rejected. Approved hours are paid with your next salary; the request then
   shows which pay run paid it.

Filter your requests by dates worked (with quick choices such as This month and Last 3 months), status, type, and
whether they've been paid. The line above the list adds up the approved hours for what you've filtered.

A request is refused straight away if it breaks a rule, with the reason: for example a date in the future, more hours
in a day than allowed, or a date too far back.

**If your organization approves overtime in advance** (the overtime request form), request it *before* the work:
choose the date it's needed, the hours needed, the PO / work order / project (or describe the activities), where, and
the reason it's urgent. Your supervisor answers whether the work could wait for normal hours and whether it will be on
the client's timesheet. If your organization also confirms the hours worked, it isn't paid until they are: when your
organization uses timesheets, the approved overtime appears on that day of your timesheet to confirm (see below);
otherwise tap **Confirm hours worked** on the request once the work is done.

**Several days at once** (e.g. every Saturday this month on one PO): switch the form to **Several days**, choose the
dates from and to, which days of the week, and the hours each day. The dates are listed so you can change one day's
hours or remove it. They go as one request: your approvers decide them together (and may lower a day's hours or leave a
day out), and each approved day then counts on its own (its type, your timesheet, your pay). Withdrawing one withdraws
them all.

For **where**, type the site's code or name. If it isn't listed, choose **Create "…"** to add it; HR checks new
sites.

### Timesheet

If your organization uses timesheets, open **Timesheet**. It shows the current pay month as a calendar (a list of days
on a phone): each day's hours, overtime and sites, with weekends and public holidays shaded and approved leave marked.

1. Tap **Fill normal days** and choose your usual site. Every empty working day gets a normal day (e.g. 8 hours).
   Weekends, holidays, leave and days you've already filled stay as they are.
2. Tap any day that was different to change it. For each site you worked at, enter the **hours** (or switch to
   **Start and end** and enter the times and your break) and tick what applies, such as offshore (only at an offshore
   site). Worked at two sites? Tap **Add another site**. **Same as …** copies the day before. Each day saves on its
   own.
3. You don't work out overtime: the day shows it as you type. On a weekday, hours past the normal day (or, with times,
   outside it) are overtime; every hour on a weekend or public holiday is overtime. For example, 11 hours on a Monday
   is 8 normal and 3 overtime.
4. **Overtime approved in advance** appears on its day as **Planned** (the calendar says *Confirm OT*). Open the day and
   tap **Worked as approved**, change the hours to what you actually worked, or remove it if you didn't work it.
5. If your organization approves overtime in advance on some days (usually weekends and holidays) and you enter
   overtime there without an approved request, the day says *Needs request*. For today or a later date you can tap
   **Request overtime**, filled in from the day. The timesheet can't be sent until it's approved, or the hours removed.
6. Tap **Send for approval**. It goes to your supervisor, then the next approver. You can't change it meanwhile.
7. If it's sent back, the reason and the date are shown at the top: correct it and send it again.
8. **Table** shows the month in the paper timesheet's columns; **PDF** downloads it in that layout (every site worked
   on a day is listed), with who approved it and when.

### Loans

1. Open **Loans** and tap **Request a loan**.
2. Choose the type. You see its interest, its limits, and the most you can borrow.
3. Enter the amount and the number of months to repay. As you type, the form shows the monthly repayment, or why the
   amount isn't allowed (for example, repayments would take too much of your take-home pay).
4. Name a guarantor if the type needs one, add what it's for, and send it for approval.
5. Once approved and paid out, tap the loan to see the schedule, what you've repaid and what you still owe.
   Repayments come off your pay each month.

## 8. For approvers

*For: supervisors, heads of department, HR.*

When a request reaches you, you get an email. Open **Self-service › My Workspace › Approvals**.

1. **Waiting for me** lists what needs your decision, oldest first: who asked, what for, and the step it's at. Tap
   **Steps** to see earlier decisions.
2. Tap **Approve**. For overtime on several dates you see each date with its hours: lower a day's hours, or set it to
   0 to leave that day out (the rest are approved). If your step asks questions (for overtime: can the work be postponed to normal hours; will it be on
   the client's timesheet), answer each yes or no. If your step allows it, you can lower the hours, amount, months or
   quantity before approving. Add a comment if you like.
3. Or tap **Reject** and say why. The employee sees your reason and the request stops there.
4. After you approve, it moves to the next step. After the last step, the employee is told.

**Decided** lists what you've already approved or rejected. Pay runs appear here too, with **Open**: they're approved
on the pay run's own page.

## 9. Overtime

*For: `configure-payroll`, `view-overtime`.*

### Setting it up

In **Payroll › Settings › Overtime**:

- **Overtime types.** First add an hourly earning in Pay components (for example Weekday overtime, 1.5× the hourly
  rate). Then add a type for each kind of day: weekdays, weekends, public holidays. Public holidays come from the
  holiday calendar. A type for **any day** (e.g. Offshore) is paid **on top**: employees tick it on a request, and the
  same hours are paid at the day's type and at it (two payslip lines).
- **When overtime is requested.** *After it's worked* (claimed, the default); *before it's worked* (the overtime
  request form: the PO / work order or activities and the reason it's urgent are asked, up to a number of days ahead);
  or *before it's worked, then the hours worked confirmed* (paid on the approved hours, the hours worked, or whichever
  is lower). In the advance modes, **Requested in advance on** chooses the kinds of day that need a request first
  (e.g. weekends and public holidays); overtime on other days (e.g. a weekday that runs late) is claimed on the
  timesheet and approved with it.
- **Rules.** Who can claim (everyone, or chosen types of employment); the most hours a day and a month (0 for no
  monthly limit); how many days after the work it can be claimed; whether a reason and a location are needed; the list
  of work locations; and whether the type is chosen from the date.
- In **General**, choose whether overtime is taxed as ordinary income or at GRA's concessionary rates for junior staff.

### Following requests

**Payroll › Overtime** lists everyone's requests with search, status, dates and a **Not yet paid** filter, and totals
the approved hours. **Record for an employee** enters overtime on someone's behalf; it still goes through their
approval steps. **Export** downloads the list.

### Approval questions

In **Settings › Approval workflows**, an overtime step can **ask** the form's two yes/no questions (can the work be
postponed to normal hours; will it be on the client's timesheet). The approver answers them to approve; the answers
show on the request and its approval steps.

### How it gets paid

Approved hours are brought into the next regular pay run automatically when it's calculated, one line per employee and
type, and each request is paid once. When the hours worked are confirmed after the work, a request isn't paid until
they are: the payslip notes the overtime that's waiting. HR can confirm hours from **Payroll › Overtime**. With payroll switched off, filter Approved and Not yet paid and export the list
for your payroll provider.

## 10. Time inputs and timesheets

*For: `enter-time-inputs`, `prepare-payroll`.*

Time inputs are counts per employee per month that are paid at a rate per unit, such as offshore days or rope access
days. First add a **Rate per unit** component for each in Pay components.

1. Open **Payroll › Time inputs**. Choose the month and the component.
2. Type each employee's quantity. It saves when you leave the box or press Enter. Clear the box (or enter 0) to remove
   it.
3. For many people at once, use **Import**: download the month's sheet. It has one row per employee and one column per
   component, already filled with what's entered. Staff ID and name are locked, and grey cells are already in a pay run
   sent for approval and can't change. Type the quantities (empty a cell to remove it) and upload it. Only cells that
   changed are saved; if any has a problem, nothing is saved and the rows are listed. A sheet for another month is
   refused.

**Days worked.** When a component follows days worked (pro-rated by them, or paid per day worked), **Days worked**
appears first in the component list. Enter each employee's days worked in the period there, like any other time input.
Without it, those components are left out of the employee's payslip with a warning. The page shows the period's dates
and working days (weekdays that aren't public holidays). Time inputs must be switched on.

If approval is on (**Settings › General › Time inputs need approval**), each entry goes through the time inputs
workflow and shows Pending until approved. Changing an entry sends it for approval again. Once an entry is in a pay run
that has been sent for approval, it can't change.

### Timesheets

*For: `manage-timesheets` (HR to start; give it to timekeepers).* Switch on **Timesheets** in System Features.

- **Settings › Timesheets:** the columns (label, hours or a tick, what each feeds: overtime hours on weekdays,
  weekends or holidays; a per-unit component such as Offshore day; or nothing), which need an offshore site, and their
  FM-06 column letters for imports; which columns make a day count as **worked**; and whether **pay comes from approved
  timesheets**. Mark the column **normal hours go here** (e.g. Normal 8–5). Employees enter hours per site; the
  normal-hours column and the overtime columns are worked out from them, so only the ticks and other columns are
  filled in.
- **Settings › General › Working day:** start, end and break (e.g. 08:00–17:00, 60 minutes = 8 hours). On a weekday,
  the first 8 hours are normal and the rest overtime; with times entered, hours outside 08:00–17:00 are overtime. The
  hours a day used for overtime's hourly rate follow it.
- **Settings › Approval workflows:** a **Timesheets** workflow (e.g. supervisor, then Operations Manager / CEO).
- **Payroll › Timesheets:** everyone with pay details for a month and their sheet: not started, being filled in,
  waiting, sent back or approved. **Start** or **Open** one to fill it in for the employee, **Import FM-06** (a filled
  paper timesheet in Excel: rows are the period's days in order from row 10, columns by their letters, sites by code),
  send it, or **Reopen** a sent or approved one for corrections.

**When pay comes from timesheets**, approving a sheet sets, for its pay month: each per-unit column's total as an
approved time input, the days worked (for allowances paid by days worked), and the overtime hours: overtime approved
in advance gets the hours confirmed on its day (removed means none were worked), and overtime on days that don't need
a request first (or when overtime is claimed after the work) becomes approved overtime at the site where it was
worked. Overtime worked at an **offshore site** is claimed with the any-day type (e.g. Offshore) paid on top, as if
the employee had ticked it. The sheet also totals normal and overtime hours **per site**, for billing clients. Reopening an approved sheet withdraws all of that until it's approved again; once a pay run holding
it has been sent for approval, it can't be reopened. A pay run notes anyone without an approved timesheet.

### Freelancers

Freelancers are employees with the job type **Freelance**. Their pay details have a basic salary of 0 (it isn't
printed), and they're given the day-rate components (e.g. Working day, Standby day, Travel day) with **their own
amount**, which is their rate per day. A per-unit component given with an own amount is paid at that rate for the
month's quantity; nothing when there's none.

- **Timesheets:** a freelancer fills in one sheet per client or work order a month (**New sheet** on their Timesheet),
  ticking working, standby and travel days, with the task, who signed it for the client and when, and a scan of the
  signed sheet if there is one. Then it's sent for approval like any timesheet. Approved sheets add up for the month.
- **Tax** (**Settings › General**): as income (PAYE) like staff, or a flat **withholding** rate on what they're paid
  (set the rate on the statutory rates). Freelancers never have SSNIT. Withholding tax is kept apart from PAYE.

## 11. Staff loans

*For: `configure-payroll`, `manage-loans`.*

### Loan types

In **Payroll › Settings › Loans**, add each loan or advance you offer. Every limit is optional:

- **Interest**: none; flat (on the full amount for the whole period); or reducing balance (on what is still owed each
  month, with equal repayments).
- **Most it can be**, as an amount and/or as a multiple of monthly basic (2 means two months' basic). The lower of the
  two applies.
- **Longest repayment** in months, **service needed first**, and **loans of this type at a time**.
- **Repayments up to a % of take-home pay**, counting all the employee's loans together.
- **Needs a guarantor**, and which **approval** workflow to use.

Also here: **Take the balance from the final pay**. When on, a leaver's remaining instalments are deducted in their
last pay run.

### The loans register

**Payroll › Loans** shows how many loans are waiting to be paid out, how many are being repaid, and the total owed.
Filter by status or type, search, or export. Open a loan to act on it:

- **Pay out** an approved loan: the date, how (bank transfer, mobile money, cash, or on the payslip), a reference, and
  the month repayments start. This creates the repayment schedule.
- Repayments are deducted in each regular pay run, after tax, and recorded when the run is marked paid. The loan closes
  itself when the last instalment is paid.
- **Record repayment** for money paid back by hand. It pays off the last instalments first, so the loan ends sooner
  and the monthly deduction stays the same.
- **Settle in full** to close it now. The employee pays the principal still owed; interest not yet due is waived.
- **Pause** a month's instalment (next to it in the schedule). It moves to the end.
- **Call off** an approved loan that won't be paid out.

> **Good to know:** If you change a loan while one of its instalments is in a pay run still being prepared, the run
> goes back to draft and needs recalculating. If the run has been sent for approval, wait until it's paid.

---

## 12. Running payroll each month

*For: `prepare-payroll`, `approve-payroll`.*

A pay run moves through these statuses:

**Draft → Calculated → Sent for approval → Approved → Paid**

1. **Create the run.** In **Payroll › Pay runs**, tap **New pay run**, choose the month and Regular monthly payroll,
   and the pay date (empty means the last day of the month). There is one regular run per month.
2. **Add this month's inputs.** On the run's **Inputs** tab, tap **Add input** for one-off items (a bonus, a
   deduction, extra hours) or **Import from Excel** for many: the run's own sheet has one row per employee and one
   column per component, filled with what's entered (hours or units, or an amount); staff ID and name are locked, and
   emptying a cell removes that input. You don't enter approved overtime, time inputs, loan
   repayments or back pay: they're added automatically and tagged with where they came from.
3. **Calculate.** Tap **Calculate**. Everyone with pay details in force for the month is included, pro-rated if they
   joined or left mid-month. It stops if the statutory rates aren't confirmed or an exchange rate is missing, and says
   which.
4. **Check.** Look at the totals and the **Payslips** tab. Payslips with warnings (no SSNIT number, missing bank
   details, negative net pay) are flagged; filter to see only those. Download the payroll register to review in Excel.
   Fix anything and **Recalculate** as often as you need; it says who was added or dropped since the last time.
   **Not in this run** lists everyone the run doesn't pay and why (no pay details, details that start after the
   month, or joining after it): fix their pay details and recalculate.
5. **Send for approval.** Tap **Send for approval**. The run is locked while it's being approved. If the setting is
   on, the person who prepared it can't approve it.
6. **Approve.** The approver opens the run (from the email or Approvals) and taps **Approve** or **Reject** with a
   reason. A rejected run goes back to be corrected and recalculated. To correct a run that's waiting for approval or
   approved but not yet paid (for example, pay details were missing), tap **Reopen**: it goes back to draft, the
   approval is withdrawn, and you recalculate and send it again. A paid run can't be reopened.
7. **Pay.** Once approved, download the payment files and the statutory reports under **Reports and payment files**.
   Upload the payment files to the bank or mobile money provider.
8. **Mark as paid.** Once salaries have gone out, tap **Mark as paid**. Employees can now see their payslips (and are
   emailed if that's switched on), and loan instalments are recorded as repaid.

### Reports from a run

| Report | For | Available |
|---|---|---|
| Payroll register | Everyone's earnings, deductions and net pay | Once calculated |
| SSNIT contributions | SSNIT monthly schedule | Once approved |
| Tier 2 schedule | Each Tier 2 scheme | Once approved |
| GRA PAYE return | To GRA | Once approved |
| Journal summary | Accounts, by account code | Once approved |
| Payment files | Bank or mobile money upload, one per layout | Once approved |

Check the statutory schedules against the current official templates before submitting them. A run can be deleted
only while it's a draft or calculated; anything it brought in (overtime, loans, back pay) goes back for the next run.

## 13. Bonuses and extra payments

To pay something outside the monthly run, such as an annual bonus, create an **off-cycle** run for the month: **New
pay run**, then Off-cycle.

- It pays only what you enter in it, and only to those employees. Basic salary and recurring items are not paid again.
- Calculate the month's regular run first. The off-cycle run is taxed on top of it: the tax is the extra tax on the
  month's total, so the employee pays the right amount overall. SSNIT uses only what's left of the monthly cap.
- Mark bonus components as **A bonus** so GRA's bonus rule applies.

It then goes through approval, payment files and Mark as paid like a regular run.

## 14. Back pay

If a raise is entered late, with a date in a month already paid, the next regular run pays the difference
automatically as a **Back pay** line. Its note lists the months it covers, for example "Back pay for Apr 2026, May
2026".

- It covers basic salary only, and looks back as many months as set in **Settings › General › Back pay: look back
  (months)** (12 by default; 0 turns it off).
- Each month's shortfall is paid once, even if the run is recalculated.
- Pay cuts entered late are not taken back.
- Back pay is taxed and SSNIT is applied in the month it's paid.

> **Watch out:** Using **Correct** to change a salary changes it from the details' original start date, which can
> create back pay for many months. For a raise, use **Change from a date**.

## 15. How pay is worked out

For each employee, each month:

1. **Earnings**: basic salary, recurring allowances, and this month's inputs (overtime, units, one-offs, back pay),
   converted into cedis where needed and pro-rated for part months.
2. **SSNIT** on earnings subject to SSNIT, up to the maximum insurable earnings: the employee's share and the
   employer's share, split into Tier 1 and Tier 2.
3. **Taxable income**: taxable earnings, less employee SSNIT, Tier 3 (within its limit), deductions taken before tax,
   and reliefs.
4. **PAYE** by the monthly GRA bands. Non-residents pay a flat rate on their income (25%), and a flat rate on their
   overtime and bonuses (20%); the junior-staff and bonus concessions are for residents. Overtime and bonuses follow
   their own rules (below).
5. **Net pay**: gross pay less SSNIT, Tier 3, PAYE and other deductions, including loan repayments.

### Example: basic salary of GHS 5,000, nothing else

| | GHS |
|---|---:|
| Gross pay | 5,000.00 |
| Employee SSNIT (5.5%) | 275.00 |
| Taxable income (5,000 − 275) | 4,725.00 |
| PAYE (2024 bands) | 779.75 |
| **Net pay** | **3,945.25** |

The figures come from the 2024 bands in the starting set; your confirmed rates decide the real ones.

### Overtime

Hourly rate = basic salary ÷ the hours in a month. **Settings › General** chooses the hours: working days a month ×
hours a day (the default), the pay period's working days × hours a day, or a fixed number of hours (e.g. 173.33). With
GHS 4,400 basic, 22 days and 8 hours, that's GHS 25 an hour; 10 hours at 1.5× is GHS 375. By default overtime is taxed as income. If you choose GRA's junior staff rule,
overtime for residents whose taxable employment income for the year is up to the threshold (GRA: GH¢18,000) is taxed
apart at the lower rate (5%) up to a share of monthly basic (50%), and the higher rate (10%) above it. The year's income
is the month's taxable earnings without overtime and bonuses, times 12, so taxable allowances count, not just basic.

### Bonuses

Under GRA's rule (the default), bonuses up to 15% of annual basic in the year are taxed at 5%; anything above that is
taxed as income. With GHS 5,000 basic, the yearly allowance is 15% of 60,000 = 9,000. A GHS 10,000 bonus: 9,000 at 5%
is GHS 450, and the other 1,000 is taxed with the month's income. You can choose to tax all bonuses as income instead.

### Joiners and leavers

Pro-rated components are paid for the calendar days employed in the pay period. Components pro-rated by days worked
use the days worked instead (days worked ÷ working days, never more than the full amount).

### Pay periods

A run for a month covers its pay period: with the period starting on the 21st, September runs 21 August – 20 September
and is paid on the pay day (by default the last day of September). Overtime's monthly limit counts the same period.
Changing the start day affects new runs only: runs already created keep their dates. When you change it, check that the
first new period doesn't overlap the last run's.

---

## 16. Reports and the dashboard

*For: `prepare-payroll`, `approve-payroll`, `view-payroll`.*

### Dashboard

**Payroll › Dashboard** shows the latest approved month (choose another at the top):

- Employees paid, gross pay, net pay and cost to the employer, each with the change since the month before.
- Cost by department and by component.
- Who joined, who left, and the biggest changes in net pay: the first things to check.
- The last 12 months as a trend.

### Year reports

**Payroll › Year reports** shows each employee's year so far (gross, taxable, PAYE, SSNIT, net) and downloads three
spreadsheets for the year: year to date per employee, PAYE by month for the annual return, and SSNIT by month. Only
approved and paid runs count.

## 17. Audit trail

*For: `configure-payroll`.*

**Payroll › Audit** lists every change to payroll data, newest first: pay details, components, rates, workflows, pay
runs and inputs, overtime, time inputs, loans and repayments, approval decisions and payroll settings. Each entry shows
who made it, when, whose record it was, and each value before and after. Filter by kind of record and dates, or export
to Excel. Bank and mobile money numbers are never recorded.

---

## 18. Common problems

**The pay run won't calculate.**
The message says why. Usually: the statutory rates haven't been confirmed (**Settings › Statutory rates**), an
exchange rate is in force on the run's rate date (**Settings › Exchange rates**), or, for an off-cycle run, the month's regular
run hasn't been calculated yet.

**An employee is missing from the run.**
They have no pay details in force for that month, they joined after the month ended, or they left before it started.
For an off-cycle run, only employees with an input in it are included.

**I can't edit or remove an input.**
Inputs that came from approved overtime, time inputs, loans or back pay are changed where they came from, not in the
run. Recalculate to bring them in again. No inputs can change once the run is sent for approval.

**An overtime or loan request was refused when sent.**
It broke one of the rules and the message says which: too many hours in a day or month, a future date, a date too far
back, no reason, more than the loan limit, repayments too high for take-home pay, or not enough service.

**A request is waiting for the wrong person.**
Check the employee's supervisor and department head in Employee management, and the workflow in **Settings › Approval
workflows**. A step with nobody to ask goes to HR. Requests already under way keep their steps; later steps use the
people set when they're reached.

**It says "No one can approve this step yet".**
Nobody other than the person who asked can approve it. Give someone the `approve-payroll` or `configure-payroll`
permission; it then appears in their Approvals.

**An employee can't see their payslip.**
Payslips appear only after the run is marked paid. Check the run's status in **Payroll › Pay runs**.

**Unexpected back pay appeared.**
A salary was changed with a start date in a month already paid, often by using Correct instead of Change from a date.
Check the employee's pay details history.

## 19. Settings at a glance

| Where | Setting | Default |
|---|---|---|
| General | Pay period starts on day · pay day | 1 (calendar month) · 0 (last day) |
| General | Base currency | GHS |
| General | Working days a month · working day | 22 · 08:00–17:00, 60-minute break (8 hours) |
| General | Hourly pay is basic divided by | Working days a month × hours a day |
| Exchange rates | Rates are taken on day · basic salary has its own rate | 0 (end of period) · Off |
| Exchange rates | Overtime's hourly rate converts basic at (with a basic rate) | The basic salary rate |
| General | Back pay: look back (months) | 12 |
| General | How overtime is taxed | As income |
| General | How bonuses are taxed | GRA bonus rule |
| General | Pay runs need a different approver | On |
| General | Payslips: show employer contributions · year to date · email employees | On · On · Off |
| General | Payslips: lines always printed · account number | None · last 4 digits |
| General | Time inputs need approval | On |
| Overtime | Who can claim | Everyone |
| Overtime | Most hours a day · a month | 12 · no limit |
| Overtime | Claim within (days) | 31 |
| Overtime | Requested · days ahead · pay (when confirmed) | After it's worked · 30 · whichever is lower |
| Overtime | Requested in advance on | Weekdays, weekends and public holidays |
| Timesheets | Pay comes from · a day counts as worked when it has | What's entered · Normal hours |
| General | Freelancers are taxed | As income (PAYE) |
| Overtime | Type from the date · reason required · location required | On · On · Off |
| Loans | Take the balance from the final pay | On |

---

*Figures in examples use the starting statutory set (2024 GRA bands); always confirm the current rates before running
payroll.*
