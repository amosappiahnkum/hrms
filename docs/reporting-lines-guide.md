# Reporting Lines and Approvals

Who reports to whom, and how leave, appraisals and payroll requests follow it. Set up the structure once; every
approval then goes to the right people, with more steps for people lower down and fewer for people at the top.

## Contents

- **Setting up**
  1. [How it works](#1-how-it-works)
  2. [The structure: levels and sub-departments](#2-the-structure-levels-and-sub-departments)
  3. [Reporting lines: supervisors and Reports to](#3-reporting-lines-supervisors-and-reports-to)
- **Approvals**
  4. [Leave](#4-leave)
  5. [Appraisals](#5-appraisals)
  6. [Payroll requests](#6-payroll-requests)
- **Reference**
  7. [Questions](#7-questions)
  8. [Settings at a glance](#8-settings-at-a-glance)

---

## 1. How it works

*For: HR, and anyone who sets up departments or approvals.*

Everything follows from two things:

- **The department tree.** Departments can sit under other departments to any depth: a Directorate, its
  Departments, their Sections and Units. Each has a head.
- **Each employee's supervisor.** By default, the head of their unit: the nearest department above them that has a
  head other than themselves. Where someone reports to a different person, set **Reports to** on them.

From there:

| Who | Their supervisor | Heads above them |
|---|---|---|
| Officer in the Inspection unit | Head of Inspection | Head of Engineering, then the Technical Director |
| Officer in Stores (no head) | Head of Engineering | The Technical Director |
| Head of Inspection | Head of Engineering | The Technical Director |
| Technical Director | Nobody (top of the organization) | Nobody |

Leave, appraisals and payroll requests each choose how far up the line they go: only the supervisor, a number of heads,
up to a level (e.g. the Directorate), or every head. Then HR.

## 2. The structure: levels and sub-departments

*For: `edit-department`, `add-department`.*

### Levels

On **Employee management › Departments**, tap **Levels** and name the levels of your structure, top first, for
example *Directorate › Department › Section › Unit*. Then give each department its **Level** when you add or edit it.
The list shows each department's level.

Levels are optional; they're used to say where an approval chain stops ("up to the Directorate"). You can rename them
at any time. A level that a department is at can't be removed until that department's level is changed.

### Sections and units are sub-departments

Add a section or unit as a sub-department: open the department and tap **Add sub-department**. Give it a head.

**Sub-units** (the old list on Job details) became sub-departments when this was introduced: each sub-unit in use was
made a sub-department of the department its people were in, and they moved into it. Sub-unit is no longer shown on Job
details.

### "Department X" includes its sub-departments

Choosing a department in a filter or report (the employee list, leave requests and leave analytics, employee
analytics, the staff directory, payroll's employee list, training needs and plans) includes everyone in its sections and
units too. Views of "my own department's colleagues" (birthdays, upcoming leave) stay with the person's own unit.

Appraisal KPIs set for a department are used for its sections and units when they don't have their own.

## 3. Reporting lines: supervisors and Reports to

*For: `view-employee` to see, `edit-employee` to change.*

Open an employee (Employee management › Employees › the employee): open the **Reporting Line** tab (next to Emergency Contact). Employees see their own there, read-only, and on their dashboard under **Reporting line** (next to Colleagues, the rest of their department). It shows:

- **Reports to**: their supervisor, marked **Head of their unit** when it's the default.
- **Direct reports**: the people who report to them, marked **In their unit** (by default) or **Set**.

To have someone report to a different person, tap **Set someone else** and choose them. **Use the head of their unit**
goes back to the default. On the supervisor's own card, **Add** sets several people to report to them in turn.

A person can have only one supervisor. Someone can't report to themselves, or to someone who (through others) reports
to them. Changes are kept in the activity log.

The employee list's **Reports to** column shows everyone's supervisor (muted when it's the head of their unit).

The supervisor approves "supervisor" steps (leave, overtime, timesheets, loans), appraises them, and sees them in **My
team** on the dashboard.

## 4. Leave

*For: `edit-leave-types` to set up; heads to approve.*

### Who approves

On **Leave management › Leave types**, the **Who approves leave** card says who approves before HR:

- The employee's supervisor, then **nobody else** (the default: straight to HR, as before);
- **a number of heads above** (e.g. 1: the supervisor, then the head above them);
- **heads above, up to a level** (e.g. up to the Department);
- **every head above**.

It applies to leave requested from then on. Requests already made keep their approvers.

### Approving

Each head approves in turn. When a head approves and there are heads left, the request stays **Pending**, moves to the
next head (who gets the usual email), and the employee is told who has it now. The last head's approval sends it to HR.
A head can change the days or start date, as before; a rejection at any head ends it.

Only the head whose turn it is can approve or reject. A department head can still see the request in their team's list
and cancel it, as before.

The request shows each head's step (**Supervisor · name**, then each head), then HR, with who decided, when and their
comment.

A head of department with nobody above them (the top of the organization) has their leave approved automatically at
the head's step; HR then decides.

## 5. Appraisals

*For: `manage-appraisal-windows` to set up; supervisors to appraise; heads to endorse; `finalize-appraisals` for HR.*

### The appraiser

Each employee is appraised by their supervisor. **My team › Appraisals** lists exactly the people you appraise. Someone
at the top of the organization (nobody above them) goes straight to HR when they submit.

### Endorsement before HR

After the appraiser confirms and the employee acknowledges, the heads above the appraiser can endorse it before HR. On
**Assessment sessions**, choose how far up:

- **nobody** (the default: straight to HR, as before);
- **a number of heads above**;
- **heads above, up to a level** (e.g. up to the Directorate);
- **every head above**.

It applies to appraisals acknowledged from then on.

### Endorsing

Heads find what's waiting for them in **My team › Endorse Appraisals**, and get an email. Tap an appraisal to read it
(answers, KPIs, training, history) with the endorsement steps above it, then:

- **Endorse**, with an optional comment: it goes to the next head, or to HR after the last one.
- **Return to appraiser**, with the reason (required): it goes back to the appraiser, who is emailed. They revise it and
  confirm it again; once the employee acknowledges, endorsement starts over.

Endorsers can't change scores. While it's being endorsed the employee sees **Being endorsed**; HR sees it only after
the last endorsement. Each step appears in the appraisal's history.

Appraisals already past the employee's acknowledgment when this was introduced finish as before.

## 6. Payroll requests

*For: `configure-payroll`.* See the *Payroll manual* › Approval workflows for the rest.

- **The employee's supervisor** step goes to their supervisor (Reports to, else the head of their unit).
- **Heads up the chain** becomes one step per head above the employee, nearest first, skipping departments with no
  head. Choose where it stops: at the top, at a level, or after a number of heads; and whether to leave out the
  supervisor when an earlier step already asked them. A step whose approver would be the same person as the step before
  is folded into it. Use **Preview** with an employee to see their steps.

One workflow of *Supervisor → Heads up the chain → HR* then fits everyone: four steps for a unit officer, three for a
department officer, two for a department head.

## 7. Questions

**Someone changes department, or a head changes, while a request is waiting.** Requests keep the approvers they started
with. New requests follow the new structure.

**A department has no head.** It's skipped: its people's supervisor is the head of the department above.

**A head heads two departments, one under the other.** They're supervised by the head above the higher one, never by
themselves or someone they lead.

**Someone supervises people but isn't a head.** Set those people to report to them. They see them in My team on the
dashboard, approve their supervisor steps and appraise them. (The My Team menu itself shows for people with the head of
department role.)

**I want a step that always goes to a particular person**, e.g. the CEO for directors. Payroll workflows can name
specific people; leave and appraisal chains follow the structure only.

## 8. Settings at a glance

| Where | Setting | Default |
|---|---|---|
| Departments › Levels | The structure's levels, top first | Department |
| Employee profile › Reporting line | Reports to | The head of their unit |
| Leave types › Who approves leave | Heads above the supervisor before HR | Nobody (supervisor, then HR) |
| Assessment sessions | Heads above the appraiser who endorse before HR | Nobody (straight to HR) |
| Payroll › Approval workflows | Heads up the chain: stop at | The top |

---

*Supervisors and chains are worked out when a request is made or an appraisal is acknowledged; later changes to the
structure don't move requests already under way.*
