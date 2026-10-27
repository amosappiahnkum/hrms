# Walkthrough: the September 2026 sample payslip (Nana Yeboah Afari)

Step by step, from a freshly migrated and seeded database, to a payslip matching `refs/Nana Yeboah Afari.pdf`. Every
step was run end to end against the app (2026-10-18); the result is within a few pesewas of the sample (see
[What comes out](#what-comes-out)).

---

## What the sample is made of

Working back from the PDF:

| Line | Sample (GH¢) | How it's made |
|---|---|---|
| Basic Salary | 8,613.05 | Paid in USD, converted at the **basic rate** USD 1 = GHS 13.00 (USD 662.54) |
| Lunch Allowance | 1,260.00 | GHS 70 × **18 days worked** |
| Trans. Allowance | 1,285.71 | GHS 1,500 × 18 days worked ÷ **21 working days** in 21 Aug – 20 Sep |
| Weekday Overtime | 1,938.92 | Hours × 1.25 × hourly rate (basic ÷ 22 days ÷ 8 h) |
| Weekend Overtime | 2,659.09 | Hours × 1.5 × hourly rate |
| Offshore Transport Allowance | 300.00 | A one-off amount this month |
| Risk Allowance (NDT-RT) | 2,000.00 | A one-off amount this month |
| Offshore Allowance | 2,392.59 | **USD 210** (7 days × USD 30) at the allowances rate USD 1 = GHS 11.3933 |
| Off. Rope Access | 444.34 | **USD 39** (3 days × USD 13) at 11.3933 |
| SSF 5.5% | 473.72 | 5.5% of basic only |
| PAYE | 4,747.10 | 2026 GRA bands on gross pay less SSF (no reliefs) |
| Welfare Contribution | 100.00 | A fixed deduction after tax |
| Employer 13% SSF | 1,119.70 | 13% of basic |

The sample's overtime amounts don't come from whole hours at any hourly basis, so the hours below are the closest
(31.70 weekday, 36.22 weekend). Offshore and rope-access rates per day aren't on the payslip: USD 30 and USD 13 are
chosen because they give the exact amounts (any rate × days making USD 210 and USD 39 does).

---

## Before you start

- Payroll migrations run, then `php artisan db:seed --class=SettingSeeder` (settings, the system pay components and
  the starting statutory rates: the 2026 GRA bands from 1 Jan 2026).
- You're signed in as someone with `configure-payroll`, `prepare-payroll`, `view-payroll`, `enter-time-inputs` (a
  super-admin has them all).
- The steps use the screens; the API equivalent is given where it helps.

---

## 1. Apply the Apave preset

It sets up the components (lunch, transport, overtime, offshore, rope access), the overtime types, the exchange rates
(USD standard 11.3933 on 22 Sep 2026, USD basic 13.00 from 1 Jan 2026), the settings (pay period 21st → 20th, pay day
= month end, rates taken on the 22nd, a separate rate for basic, full account numbers on payslips, the fixed payslip
lines) and the cost centre **NDT**.

1. Its workflows name the role **operations-manager**, so create it first: **Settings › System & Access › Roles &
   permissions › Add role** → `operations-manager` (no permissions needed for this walkthrough).
2. Run:

   ```bash
   php artisan payroll:apply-preset abave --dry-run   # see what it will do
   php artisan payroll:apply-preset abave
   ```

## 2. Settings for this walkthrough

**Payroll › Settings:**

1. **Timesheets › Pay comes from: What's entered.** The preset takes pay from approved FM-06 timesheets; for a
   one-employee walkthrough it's quicker to enter days worked and overtime directly. Save.
2. **General › Time inputs need approval: off.** (Or leave it on and approve the days-worked entry in Approvals in
   step 6.) Save.
3. **Statutory rates:** open the starting set (2026 bands: 588 / 80 / 100 / 2,900 / 16,000 / 30,332, then 35%),
   check it, and tap **Confirm**. Pay runs refuse unconfirmed rates.
4. **Exchange rates:** check there are two USD rates: *basic* 13.00 from 1 Jan 2026 and *standard* 11.3933 on
   22 Sep 2026 (the preset adds them).

## 3. Components

**Payroll › Settings › Pay components:**

1. Edit **Offshore day**: name **Offshore Allowance**, rate **30** (USD, per day). Save.
2. Edit **Rope access (offshore)**: name **Off. Rope Access**, rate **13** (USD, per day). Save.
3. *(Optional, to match the sample's labels)* rename **Transport allowance** to **Trans. Allowance**, **Lunch
   allowance** to **Lunch Allowance**, **Weekday overtime** / **Weekend overtime** to **Weekday Overtime** / **Weekend
   Overtime**.
4. **Add** three components:

   | Code | Name | Kind | Calculation | Recurring | Taxable | SSNIT |
   |---|---|---|---|---|---|---|
   | OFFSHORE_TRANSPORT | Offshore Transport Allowance | Earning | Entered each run (manual) | No | Yes | No |
   | RISK_NDTRT | Risk Allowance (NDT-RT) | Earning | Entered each run (manual) | No | Yes | No |
   | WELFARE | Welfare Contribution | Deduction | Fixed, 100 | Yes | No (after tax) | – |

   *Taxable* on a deduction means "taken before tax"; the welfare contribution comes off after tax, so leave it off.

## 4. The bank

**Payroll › Settings › Banks › Add:** **Universal Merchant Bank**, then **Branches › Add a branch:** **Takoradi** (with
its sort code if you have it). (Or add both from the pay details form in step 5.)

## 5. The employee

1. **Employee management:** add (or open) **Nana Yeboah Afari**: staff ID **AI24016**, department **NDT**. On **Job
   details**, position **NDT Inspector**. (The sample's engagement date is blank: leave the joined date empty, or set it
   to print one.)
2. **Payroll › Employees › Nana Yeboah Afari:**
   - **Cost centre:** NDT.
   - **Pay details** (Correct), in force from **1 Jan 2026**:
     - Basic salary **662.54**, currency **USD** (converted at the basic rate, 13.00 → 8,613.02).
     - Paid by **Bank**: bank **Universal Merchant Bank**, branch **Takoradi**, account name **Nana Yeboah Afari**,
       account number **51300487972**.
     - SSNIT number **B128910090013**. Tax resident: yes. No reliefs, no Tier 3.
   - **Recurring components:** add **Lunch allowance**, **Transport allowance** and **Welfare Contribution**, from
     1 Jan 2026, at their own rates (leave the amount empty).

## 6. Days worked

**Payroll › Time inputs:** month **September 2026** (21 Aug – 20 Sep, 21 working days), component **Days worked**:
enter **18** for Nana. (With approval on, approve it in Approvals.)

Lunch is paid per day worked (70 × 18) and transport is shared by days worked (1,500 × 18 ÷ 21).

## 7. The pay run

1. **Payroll › Pay runs › New run:** **September 2026**, regular. It covers 21 Aug – 20 Sep 2026, paid 30 Sep 2026.
2. **Inputs** (on the run) → **Add**, for Nana:

   | Component | Quantity | Amount |
   |---|---|---|
   | Weekday overtime | 31.70 hours | |
   | Weekend overtime | 36.22 hours | |
   | Offshore Allowance | 7 days | |
   | Off. Rope Access | 3 days | |
   | Offshore Transport Allowance | | 300.00 |
   | Risk Allowance (NDT-RT) | | 2,000.00 |

3. **Calculate.** Open Nana's payslip; **PDF** downloads it.

(To pay it: send the run for approval, have someone else approve it, then mark it paid; Nana then sees it in
self-service.)

---

## What comes out

| | Sample | App | Difference |
|---|---|---|---|
| Basic Salary | 8,613.05 | 8,613.02 | −0.03 (USD 662.54 × 13) |
| Lunch Allowance | 1,260.00 | 1,260.00 | |
| Trans. Allowance | 1,285.71 | 1,285.71 | |
| Weekday Overtime | 1,938.92 | 1,939.15 | +0.23 (hours) |
| Weekend Overtime | 2,659.09 | 2,658.78 | −0.31 (hours) |
| Offshore Transport Allowance | 300.00 | 300.00 | |
| Risk Allowance (NDT-RT) | 2,000.00 | 2,000.00 | |
| Offshore Allowance | 2,392.59 | 2,392.59 | |
| Off. Rope Access | 444.34 | 444.34 | |
| **Gross Pay** | **20,893.70** | **20,893.59** | −0.11 |
| SSF 5.5% | 473.72 | 473.72 | |
| PAYE | 4,747.10 | 4,747.06 | −0.04 |
| Welfare Contribution | 100.00 | 100.00 | |
| **Total Deductions** | **5,320.81** | **5,320.78** | −0.03 |
| Employer 13% SSF | 1,119.70 | 1,119.69 | −0.01 |
| Staff Cost | 22,013.40 | 22,013.28 | −0.12 |
| **Net Salary** | **15,572.89** | **15,572.81** | −0.08 |

The header prints as the sample's: staff name, AI24016, NDT Inspector, cost centre NDT, period 21/08/2026 –
20/09/2026, payment date 30/09/2026, SSNIT B128910090013, **Exchange Rate: Basic Salary USD 1 to GHS 13.00**,
**Allowances USD 1 to GHS 11.3933** (22/09/2026), and Universal Merchant Bank, Takoradi, 51300487972 in full.

### Getting closer

- **Basic exactly 8,613.05:** enter the basic as **GHS 8,613.05** instead of USD. The basic line, SSF (473.72) and
  employer SSF (1,119.70) then match; but the payslip no longer prints the basic-salary exchange rate (only foreign
  currency pay shows a rate).
- **Overtime:** the sample's overtime can't be reproduced from whole hours (open question D10 in
  `docs/timesheet-payroll-completion-plan.md`: how Apave's hourly rate is worked out). With Apave's hours for that
  month and its hourly basis, set **Settings › General › Hourly pay is basic divided by** to match and enter the real
  hours.
- **PAYE:** follows from gross pay; with the gross exact, it's 4,747.09–4,747.10.

### Lines the sample shows as "–"

The sample also lists Bonus, Holiday Overtime, On. Rope Access, Offshore Overtime, Tier 3, Loan Deduction, Other
Deduction and Welfare Loan Repayment with "–". The preset already prints the overtime, rope access, Tier 3 and loan
lines at "–" (Settings › General › Payslips › Lines always printed). For **Bonus**, **Other Deduction** and **Welfare
Loan Repayment**, add those components (Bonus: an earning marked as a bonus; the others: deductions) and add their codes
to *Lines always printed*.

---

## In the real month

For Apave the month normally runs from timesheets, not hand entries: **Pay comes from: Approved timesheets** (the
preset), each employee's FM-06 timesheet (days with normal hours = days worked; overtime past 8 hours on weekdays;
weekend overtime from approved requests; offshore and rope-access ticks at offshore sites), approved by the supervisor
and Operations Manager. Approving a timesheet fills days worked, the offshore and rope-access days and the overtime
hours; the run then needs only the one-off items (offshore transport, risk allowance).
