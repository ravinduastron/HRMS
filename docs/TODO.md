Pending

1. Inputs to payroll (build first)

Pay components: a master list of earnings and deductions, with flags for EPF, ETF and tax, and how each is calculated (fixed, percentage or formula).
Salary structures and employee salaries: these must be effective-dated, with history and approval. The Salary tab on the employee page is waiting for this.
Pay calendar: pay periods, cut-off dates, pay dates, and locking a period once it is closed.
Attendance, overtime and leave: decide where this data comes from (see the questions below). No-pay leave and late deductions feed straight into salary.
Loans and advances: installment schedules and balances.
Adjustments: one-off and recurring bonuses, arrears and recoveries.
Company settings: legal entity, EPF/ETF registration details and the paying bank.

2. The payroll engine

Calculation: gross to net, including overtime, pro-rata for joiners and leavers, EPF, ETF, income tax (APIT) and loan deductions. It needs unit tests built from real payslips, because small errors here become real money errors. Please confirm current statutory rates and tax tables with the authorities when this is built.
Pay run lifecycle: draft, calculated, reviewed, approved, locked and paid, per scheme, with a variance check against last month. Employees excluded from payroll or with no active approved bank account should be flagged before approval.
Immutable results: a finished run keeps a snapshot of its inputs and outputs, so later changes never rewrite history.
Approval workflow: one reusable engine for pay runs, salary changes and bank accounts, which you already planned.

3. Outputs and compliance

Payslips: PDF generation, email delivery and history.
Bank transfer files: in each bank's required format, using only active approved accounts, with checks before the file is produced.
Statutory returns: EPF, ETF and income tax monthly returns, plus year-end certificates and returns.
Reports: payroll register, department cost, headcount and variance reports. Also a journal export to your ERP, if you want payroll posted to the ledger.
Final settlements: gratuity, leave encashment and pro-rata pay for leavers.

4. Before go-live

Opening balances: if you go live mid-year, you need year-to-date earnings, tax, EPF and loan balances loaded.
Parallel run: two or three cycles alongside your current process, comparing results line by line.
Hardening: two-factor sign-in for admins, backup and restore testing, queue monitoring, server setup and log retention.
UAT and training.
Deferred items: local employee and scheme creation, in case MiHCM is ever dropped, and employee self-service for payslips.