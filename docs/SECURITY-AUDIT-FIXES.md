# Security audit fixes — deployment & manual test guide

This package fixes the eight findings from the security audit (C-1, C-2, H-1 … H-6),
plus three defects found while testing them.

- **Package:** `hrms-security-fixes-<date>.zip` — a **delta package** for
  **Settings → System Update** (the deployment module).
- **Entries:** 37 files (34 updated, 3 added). No `config/`, no `uploads/`, no
  `storage/` — the deployer refuses those, by design.
- **Database:** nothing to run — **the package contains no `.sql` at all**, so the
  “Run packaged SQL” tick-box has nothing to act on either way. The three new columns
  install themselves on first use (see below).

---

## 1. Deploy

1. Log in to the target server as **Super Admin**.
2. Go to **Settings → System Update** (`/modules/deployment/index.php`).
3. **Upload package** → choose `hrms-security-fixes-<date>.zip` → **Analyze package**.
4. Check the preview. You should see:

   | Action | Count | Meaning |
   |---|---|---|
   | UPDATE | 34 | existing files replaced |
   | ADD | 3 | `file.php`, `change_password.php`, this guide |
   | SKIP | 0 | (any SKIP means that file already matches — harmless) |
   | PROTECTED | 0 | nothing in the package touches a protected path |

5. The package carries no `.sql`, so the **SQL summary shows 0** and the tick-box is
   inert. Nothing needs running against the database.
6. On a LIVE server you must also type `DEPLOY` to confirm. Click **Deploy**.

> **Why no `install/schema.sql`?** It is a FRESH-INSTALL script: its `CREATE`s are all
> `IF NOT EXISTS`, but its 14 seed `INSERT`s are not idempotent, so running it against a
> populated database stops at the first duplicate key. The target does not need it at
> runtime, so it is deliberately left out rather than shipped next to a tick-box that
> would break on it.

> **Deployment is one-way.** There is no rollback and no file backup. If you want a
> safety net, copy the app folder before deploying.

### After deploying — no manual steps required

Two things used to need hand-editing; they no longer do:

- **`uploads/.htaccess` and `storage/.htaccess`** are written automatically by
  `hrms_harden_data_dirs()` (in `includes/helpers.php`, called from `bootstrap.php`)
  on the first request after deployment. They are also *self-healing* — if either file
  is deleted or reverted, the next request rewrites it.
- **New database columns** (`users.must_change_password`, `payroll_runs.finalized_at`,
  `payroll_runs.finalized_by`) are added on demand by `db_ensure_column()` the first
  time the relevant page is opened.

> If a previous deployment of this package DID run `install/schema.sql` and reported
> *“Duplicate entry 'Super Admin' for key 'name' — 3 earlier statement(s) already
> applied”*: nothing was damaged. Those three are `CREATE DATABASE IF NOT EXISTS`,
> `USE`, and `CREATE TABLE IF NOT EXISTS roles` — all no-ops on an existing database.
> The failed statement is a single multi-row `INSERT`, which InnoDB rolls back whole,
> so no rows were written. No redeploy is needed.

### If an earlier fix package was already deployed to this server

Earlier packages (`hrms_security_fixes_2026-08-22.zip`, `..._DELTA2_...`,
`hrms_manual_htaccess_...`) installed an earlier version of the file gateway called
**`secure_file.php`**. This package replaces it with `file.php`, and rewrites every
page to link there — but a deployment never deletes files, so `secure_file.php` will
be left behind as a second, unreferenced gateway to the same employee PII.

**After deploying, delete it from the application root:**

```
<app-root>/secure_file.php
```

Nothing links to it any more (verified against the whole codebase), so removing it is
safe. Leaving two gateways to the same files is worse than one.

Also left behind, and harmless: `install/add_must_change_password.sql` and
`install/fix_must_change_password_scope.sql`. This package needs neither — the columns
install themselves.

### One thing worth checking first

Run this on the target database:

```sql
SELECT COUNT(*) FROM permissions;
```

If it returns fewer than ~89, the permission catalog was never seeded, and **every**
permission check on that server silently collapses to “Super Admin only”. That is a
pre-existing condition, not something this package causes, but tests 4, 5 and 7 below
will be meaningless until you fix it:

```bash
mysql -u root -p <dbname> < install/roles_permissions_seed.sql
```

---

## 2. Manual test checklist

Two logins are useful throughout:

- **A** — a Super Admin.
- **B** — a normal Employee account (self-scoped) whose role has *View Employees* and
  *View Documents*.

---

### Test 1 — H-1 · login cookie hardening

1. Open the login page in Chrome.
2. DevTools → **Application → Cookies** → your site.
3. Find `HRMS_SESSION`.

**Expect:** `HttpOnly` ✔ and `SameSite = Lax`. Over HTTPS, `Secure` ✔ as well.

*Before the fix, the cookie minted by the login page had none of these.*

---

### Test 2 — H-2 · employee files are no longer public

1. As **A**, open **Documents**, and copy the URL behind the eye icon.
   It must look like `…/file.php?p=uploads%2Femployee_docs%2F12%2F….pdf`,
   **not** `…/uploads/employee_docs/12/….pdf`.
2. Paste the *old-style* direct path (`/uploads/employee_docs/…`) into the address bar.
   **Expect: 403 Forbidden.**
3. Do the same for a photo (`/uploads/photos/…`) and a signature
   (`/storage/entities/sign_….jpeg`). **Expect: 403 each.**
4. Copy a working `file.php?p=…` document link, open a **private/incognito window**
   (no session), paste it. **Expect: bounced to the login page.**
5. Log in as **B**. Open your own profile → **Documents** tab → view a document.
   **Expect: it opens.**
6. Still as **B**, take a `file.php?p=uploads/employee_docs/<someone else's id>/…` link
   and open it. **Expect: 403 Forbidden.**
7. Try a traversal: `…/file.php?p=../config/database.php` and
   `…/file.php?p=config/database.php`. **Expect: 404 Not found** for both — never the
   file contents.
8. Confirm ordinary images still render: the sidebar logo, employee photos, and the
   signature on a letter (**Letters → view an issued letter**).

---

### Test 3 — H-3 · imported staff no longer get guessable passwords

1. As **A**, **Employees → Import**, upload a small CSV with a real email:

   ```csv
   name,email,phone,join_date
   Test Person,testperson@example.com,9000000001,01/01/2026
   ```

2. **Expect** the result banner to mention *“login account(s) created with a random
   password — each one must be given a password via Settings → Users”*.
3. Note the new employee code (e.g. `EMP0042`). Log out, and try to log in as
   `testperson@example.com` with `Hrms@0042` (the old scheme: `Hrms@` + last 4 of the
   code). **Expect: “Invalid email or password.”**
4. As **A**, **Settings → Users** → edit that user → set a password → save.
5. Log out, log in as that user with the password you just set.
   **Expect: you land on “Change Password”, and cannot navigate anywhere else** — try
   typing `/index.php` directly; you are sent straight back.
6. Enter the wrong current password → rejected. Enter a new password under 10
   characters → rejected. Mismatched confirmation → rejected. Reuse the same password
   → rejected.
7. Set a proper new password. **Expect: “Your password has been changed”**, and the
   whole app becomes reachable.

---

### Test 4 — H-4 · nobody but a Super Admin can mint an admin

1. As **A**, create a role `TempManager` with **Users → Create** and **Roles → Manage**
   permissions but *not* Super Admin. Create a user with that role.
2. Log in as that user.
3. **Settings → Users → New.** Open the Role dropdown.
   **Expect:** neither `Super Admin` nor any role named `Admin` can be assigned
   (attempting it gives *“You are not allowed to assign that role.”*).
4. **Settings → Roles → New Role**, name it `Admin`.
   **Expect:** *“You are not allowed to create an administrator role.”*
5. Edit an existing ordinary role and try to rename it to `Admin`.
   **Expect:** *“You are not allowed to give a role an administrator name.”*

---

### Test 5 — H-5 · permission checks that used to hide everything

As a user holding the permission named in each row (not a Super Admin):

| Give the user | Then open | Expect |
|---|---|---|
| `roles.manage` | Settings → Roles → edit a role | Heading says **Edit Role** (not *View Role*), fields editable, **Save** works |
| `training.manage` | Training → Create Course | page opens |
| `training.enroll` | Training → a course → Enroll | page opens |
| `letters.create` | Letters → open a Draft letter | **Issue Letter** button is visible |
| `payroll.process` | Payroll → Finalize (`finalize.php?run_id=<id>`) | page opens |

*Before the fix each of these checked a permission that does not exist, so the page or
button was invisible to everyone except a Super Admin.*

---

### Test 6 — H-6 · the payroll screens that showed ₹0.00

1. As **A**, **Payroll → Process Payroll** for a past month → **Save & Process**.
2. Open **Payroll → Finalize** for that run.
   **Expect:** Total Gross, Total Deductions, Net Payable and every table row show
   **real amounts**, and the subtitle shows the correct month (e.g. *July 2026*) —
   not ₹0.00 and not *January 1970*.
   *(₹0.00 in the PF/ESI columns is correct for an employee whose PF toggle is off.)*
3. Click **Finalize Payroll**. **Expect:** success, the run shows as Finalized, and the
   button is replaced by *“Payroll finalized on …”*.
4. Try **Process Payroll** for that same month again. **Expect:** blocked with
   *“…is finalized and cannot be reprocessed.”*
5. **Payroll → Export CSV.** Open it. **Expect:** the Gross, Net Pay, Special, Other
   Allowance and LOP Deduction columns are all populated.
6. **Payroll → History**, pick an employee. **Expect:** the month reads e.g. *Jul 2026*
   and the money columns are populated. (This page used to fail outright.)

---

### Test 7 — C-1 · one payroll engine, not two

This is the important one.

1. As **A**, pick a month and an employee. Note the employee’s figures on
   **Payroll → Salary Calculation** (the preview): *Gross*, *Total Deductions*, *Net Pay*.
2. **Payroll → Generate Salary Slip** for that same employee and month. Note the same
   three figures from the generated slip.
3. **Payroll → Process Payroll** for that month. Find the same employee in the preview
   table.

**Expect: all three screens show the same numbers.** Before the fix the batch run used
its own formula and disagreed on working days, half days, approved leave, loans,
benefits, bonuses, overtime, the late penalty and the ESI base.

4. Press **Save & Process**, then open that employee’s payslip (**Payroll → Slips →
   View**). **Expect:** the payslip renders fully — earnings lines, deduction lines and
   the attendance summary. Before the fix, batch slips stored `NULL` for all of those
   and the payslip came out blank.
5. If the employee has an active **loan**, check **Loans → repayment history**.
   **Expect:** the month’s EMI is recorded. Batch runs used to deduct nothing.

---

### Test 8 — C-2 · imported week-offs must not cancel absences

1. Pick an employee and a past month. In **Attendance**, mark them **Absent** on
   several working days.
2. Import (or mark) a biometric-style sheet where every Sunday and the 1st/3rd
   Saturday is `Holiday` / `Week Off`.
3. Open **Payroll → Salary Calculation** for that month.

**Expect:** *Paid Leave* does **not** include those week-off rows, the *LOP* column
equals the number of days you actually marked Absent, and the employee is **not** paid
a full month.

4. Now mark one genuine **working day** as `Holiday`. **Expect:** *Paid Leave* becomes 1
   and LOP drops by 1 — a real holiday still pays.

---

### Test 9 — regression sweep

Click through each module once as **A**, then once as **B**:

Dashboard · Employees (list, view, edit) · Attendance (list, leaves, **Export CSV**) ·
Payroll (all pages) · Letters · Assets · Training · Documents · Holidays · Roles ·
Settings (all tabs).

**Expect:** no PHP error, no *Unknown column*, no blank page. A 403 or a redirect for
**B** is correct behaviour, not a failure.

> `Attendance → Export CSV` is worth a specific look — it used to fail with
> *Unknown column 'a.date'* on every request and now produces a proper file.

---

## 3. What is in the package

| Area | Files |
|---|---|
| Payroll engine (C-1) | `modules/payroll/process.php`, `calculate.php` |
| Attendance (C-2) | `includes/PayrollCalculator.php` |
| Login/session (H-1) | `login.php` |
| File gateway (H-2) | **`file.php`** *(new)*, `includes/helpers.php`, `includes/bootstrap.php`, `includes/header.php`, `includes/sidebar.php`, `modules/documents/index.php`, `modules/employee/view.php`, `modules/employee/edit.php`, `modules/attendance/leaves.php`, `modules/letters/view.php`, `modules/payroll/slip.php`, `modules/settings/branding.php`, `modules/settings/tabs/entities.php`, `modules/holidays/circular.php` |
| Passwords (H-3) | **`change_password.php`** *(new)*, `includes/auth.php`, `modules/employee/import.php`, `modules/settings/users.php` |
| Role escalation (H-4) | `includes/permissions.php`, `modules/roles/create.php`, `edit.php`, `delete.php` |
| Permission names (H-5) | `modules/payroll/finalize.php`, `salary_structure.php`, `modules/roles/*`, `modules/training/*`, `modules/letters/issue.php`, `modules/letters/view.php`, `modules/assets/clearance.php` |
| Column names (H-6) | `modules/payroll/finalize.php`, `history.php`, `export.php` |
| Found while testing | `modules/attendance/export.php`, `modules/employee/view.php`, `modules/holidays/circular.php`, `modules/letters/view.php` |
| Reference schema | `install/schema.sql` *(file only — do not run)* |

**Not in the package, deliberately:** `config/app.php` and `config/database.php`
(environment-specific — the deployer refuses them), and `uploads/.htaccess` /
`storage/.htaccess` (protected paths — now written at runtime instead).
