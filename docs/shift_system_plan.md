# Shift System — Complete Phase-Wise Implementation Plan

> **PROGRESS (30-Jul-2026):**
> - ✅ **Phase 0** done — `shifts` + `shift_breaks` + `shift_id` columns (incl. `attendance.shift_id`), 3 shifts seeded, all employees on General, helpers in place. Migration: `install/add_shift_system.sql` (applied to live DB).
> - ✅ **Phase 1** done — Settings → Shifts screen, employee Shift dropdown with shift-scoped lunch batches, nav entry.
> - ✅ **Phase 2** done — mark.php (server + JS) classifies per shift, stamps `attendance.shift_id`, Shift column + per-shift OT gating on the sheet; daily/monthly importers classify per shift + stamp + zero OT on straight shifts; grace.php bulk recalc is per-shift; report/index late & grace math per employee's shift; policy banners reworded.
> - ✅ **Phase 3** done — per-row shift resolution (stamped shift → employee shift → legacy globals) for late/OT/half/short/breaks/lunch + per-shift monthly grace, via ONE shared resolver `attendance_row_timing()` used by both payroll and the attendance reports (so a past month can never be judged differently in the two places). Manual OT override cannot re-enable OT on an OT-off shift.
> - ✅ **Legacy settings pages kept live** — Office / OT / Grace / Break Settings now mirror their values onto the **General** shift on save (otherwise they would silently have no effect). Other shifts are edited in Settings → Shifts.
> - **Verified:** exact regression match on a stored General slip, plus **28/28** assertions covering Evening/Morning/General classification, OT gating, half-day cutoffs, late pools, point-in-time stamping and the manual-OT gate. All touched pages render clean; a live mark.php POST for a Morning employee stamped the shift and correctly granted **no** OT for a 21:00 checkout.
> - ✅ **Phase 4** done — **Shift on the payslip** (screen + PDF), frozen into the slip's `attendance_summary` at generation time from the *stamped* rows, so it names the shift actually worked. **Shift column + Shift filter** on the attendance Records tab and both matrix reports (the matrix shows each employee's shift under their name, since that row's thresholds come from it). **Lunch-batch CRUD gained a Shift column** on both Office and Break Settings, and re-scoping a batch clears it from employees who are no longer on that shift. **Deactivation guard**: a shift with employees still assigned (or General) can no longer be set inactive — and the shift form now has the Status field it was missing, which previously meant any edit silently reactivated a shift.
>
> **ALL PHASES COMPLETE.** Verified end to end: 28/28 assertions, exact regression match on a stored slip, live mark POST, shift filters, and the deactivation guard.
>
> **Known gaps (deliberate, documented):**
> - A lunch batch with `shift_id = NULL` is treated as available to every shift (the backwards-compatible default).
> - `grace.php`'s bulk recalc rules 3/4 only touch `On Time`/`Late` rows (pre-existing behaviour), so a row already stored as `Half Day` won't revert if a shift's cutoff is later widened. Left as-is deliberately: changing it would also overwrite `Half Day` statuses set explicitly by an import.
> - Slips generated **before** this work have no `shift_name` in their stored JSON and show `—`; regenerating the slip fills it in.

**Goal:** Support multiple work shifts (General 09:00–18:00, Morning 06:00–14:00,
Evening 14:00–22:00, and future shifts) with **per-shift** configuration of
office hours, grace, OT, breaks and lunch — flowing correctly into **attendance**
and **payslips**.

**Locked decisions**
- Every employee defaults to the **General** shift; admins can **add shifts** and
  **assign a shift on the employee edit page**.
- **OT is per shift, and can be switched off per shift.**
- **All** timing (office hours, grace, OT, breaks, lunch) is per-shift.
- **Lunch stays as staggered batches, scoped to each shift.**
- **Morning (06:00–14:00) and Evening (14:00–22:00) are "straight" 8-hour shifts:
  no lunch, no tea breaks, and no OT.** Only the **General** shift has breaks, lunch
  and OT.
- General shift is seeded from today's global settings → **zero behaviour change
  on day one.**

---

## Current state (what we are replacing)

All timing is **global**, read through `includes/helpers.php`:

| Concept | Today | Source |
|---|---|---|
| Office start / end | 09:00 / 18:00 | `setting_office_start/end()` |
| Daily / monthly grace | 15 / 90 min | `setting_daily_grace_mins()`, `setting_monthly_grace_mins()` |
| OT trigger / baseline | 20:30 / 18:15 | `setting_ot_trigger()`, `setting_ot_baseline()` |
| Half-day cut-off | global | `modules/attendance/mark.php` |
| 2 tea breaks | 11:00–11:15, 16:00–16:15 | `tea_break_windows()` |
| Lunch | per-employee **lunch batches** (staggered), default 13:00–13:30 | `employee_lunch_window()`, `lunch_batches` table |

Two engines consume these:
1. **Attendance marking** — `modules/attendance/mark.php` (On Time / Late / Half-day).
2. **Payroll** — `includes/PayrollCalculator.php::getAttendance()` (late, OT,
   half/short/full via `break_minutes_within()` + `attendance_classify()`).

---

## Target data model

```
shifts                     -- parent: one row per shift
  id, name, status,
  start_time, end_time,
  daily_grace, monthly_grace,
  ot_enabled,                          -- 0 = this shift earns NO overtime
  ot_trigger_time, ot_baseline_time,   -- used only when ot_enabled = 1
  half_day_cutoff,
  created_at, updated_at

shift_breaks               -- tea / general breaks per shift (NOT lunch)
  id, shift_id -> shifts.id,
  kind ('tea' | 'break'),
  name, start_time, end_time

lunch_batches  (existing)  -- staggered lunch, now scoped to a shift
  + shift_id -> shifts.id

employees      (existing)  -- gains a shift assignment
  + shift_id -> shifts.id  (default = General)
  (already has lunch_batch_id; the batch must belong to the employee's shift)

attendance     (existing)  -- OPTIONAL, Phase 3: freeze shift used that day
  + shift_id -> shifts.id  (nullable)
```

New helper functions (mirroring the existing global helpers):
- `get_shift(int $shiftId): array`
- `employee_shift(int $empId): array` — the employee's shift row (falls back to General)
- `shift_break_windows(int $shiftId): array` — replaces `tea_break_windows()`
- `shift_lunch_window(int $empId): array` — replaces `employee_lunch_window()`, resolved via the shift-scoped batch

**Straight shifts (Morning / Evening).** These have `ot_enabled = 0`, **no
`shift_breaks` rows, and no lunch batches**. The universal "full day = 8 net hours"
rule still holds without any per-shift "expected hours" field:
- General reaches 8 net hours as **9h presence − 1h breaks** (30 lunch + 30 tea).
- A straight shift reaches 8 net hours as **8h presence − 0 breaks**.

So break subtraction naturally returns 0 for straight shifts, and a 06:00–14:00
employee working their full window is a full day.

---

## PHASE 0 — Foundation: schema + seed (NO behaviour change)

**Objective:** create the tables and data so General mirrors today exactly.
Nothing reads the new tables yet.

**Migration** `install/add_shift_system.sql` (idempotent, house style):
- `CREATE TABLE IF NOT EXISTS shifts (...)`
- `CREATE TABLE IF NOT EXISTS shift_breaks (...)`
- `ALTER TABLE lunch_batches ADD COLUMN IF NOT EXISTS shift_id ...`
- `ALTER TABLE employees ADD COLUMN IF NOT EXISTS shift_id ... DEFAULT (General)`
- Seed shifts:
  - **General** — 09:00–18:00, grace 15/90, **ot_enabled=1** (OT 20:30/18:15),
    half-day cutoff = current, breaks 11:00–11:15 & 16:00–16:15, plus lunch batches
    (all from current globals).
  - **Morning** — 06:00–14:00, grace as needed, **ot_enabled=0**, **no break rows,
    no lunch batches** (straight 8-hour shift).
  - **Evening** — 14:00–22:00, grace as needed, **ot_enabled=0**, **no break rows,
    no lunch batches** (straight 8-hour shift).
- Backfill: `UPDATE employees SET shift_id = <General>`; `UPDATE lunch_batches SET shift_id = <General>`.

**Also add** the helper functions in `includes/helpers.php` (`get_shift`,
`employee_shift`, `shift_break_windows`, `shift_lunch_window`) — defined but not
yet wired into the engines.

**Verification**
- Schema present; General shift values byte-match the current `app_settings`.
- All employees + all lunch batches point at General.
- App still runs; payroll output identical (nothing reads shifts yet).

**Rollback:** drop the two new tables + two columns. No engine touched.

---

## PHASE 1 — Shift management UI + employee assignment (NO calc change)

**Objective:** admins can create/edit shifts and put employees on them. General
still equals today's behaviour, so payslips/attendance are unchanged.

**New screen** `modules/settings/shifts.php` (built like `payroll/salary_components.php`):
- List shifts; add/edit/delete.
- Editing a shift sets: name, start/end, daily/monthly grace, OT trigger/baseline,
  half-day cutoff, and its **break rows** (add/remove tea/general breaks).
- Guard with a `settings` permission (add a `shifts` permission row if you want
  finer control).

**Lunch batches** (`modules/settings/.../lunch batches` screen + `add_lunch_batches.sql`):
- Show a **Shift** column; creating a batch picks its shift.

**Employee Add/Edit** (`modules/employee/add_form.php`, `create.php`, `edit.php`):
- Add a **Shift** dropdown (next to the Lunch Batch dropdown — same markup pattern).
- Make the **Lunch Batch dropdown depend on the chosen shift** (JS filters batches
  to the selected shift, like a country→state cascade). For a **straight shift**
  (no batches — e.g. Morning/Evening) the lunch dropdown is disabled with a
  "Straight 8-hour shift — no lunch/breaks" note.
- `create.php` / `edit.php`: save `shift_id`; validate the chosen lunch batch
  belongs to the chosen shift (else clear it).
- Edit page: add `shift` (and lunch batch) to the activity-log diff.

**Navigation:** add "Shifts" under the Settings section in `includes/header.php`.

**Verification**
- Create "Evening" shift with its breaks; assign an employee to it and save.
- Confirm the lunch dropdown only shows that shift's batches.
- Payroll/attendance output still identical (engines still use globals — Phase 2/3
  switch them over).

**Rollback:** remove the screen + dropdowns; data columns stay harmlessly.

---

## PHASE 2 — Attendance marking reads the shift

**Objective:** On Time / Late / Half-day judged against the employee's **shift**.

**Changes** in `modules/attendance/mark.php`:
- Replace the global `$lateThreshMins` / `$halfDayCutoff` with per-employee values
  derived from `employee_shift($empId)` (shift start + shift grace; shift half-day
  cutoff).
- Where the page batch-marks many employees, resolve each employee's shift once
  (cache) to avoid N queries.
- Attendance list/daily views: show a **Shift** column so it's clear which rule applied.

**Verification**
- Evening-shift employee checking in 14:05 → **On Time** (not Late).
- General employee behaviour unchanged.
- Half-day cutoff respects each shift.

**Rollback:** revert `mark.php` to the global helpers.

---

## PHASE 3 — Payroll reads the shift (the money step)

**Objective:** late, OT and half/short-day math use the employee's shift.

**Refactor** the timing helpers to be shift-aware (keep old signatures working via
a General fallback):
- `tea_break_windows()` → `shift_break_windows($shiftId)`.
- `employee_lunch_window($empId)` → resolve the shift-scoped batch.
- `break_minutes_within()` → receive the shift's full break+lunch set.

**Changes** in `includes/PayrollCalculator.php::getAttendance()`:
- Load the employee's shift once; use its `start_time` (late), `daily_grace`,
  `half_day_cutoff`, and its break/lunch windows.
- **OT only when `shift.ot_enabled = 1`** (and then the existing `employees.ot_enabled`
  flag + shift `ot_trigger`/`ot_baseline` apply). For **straight shifts**
  (Morning/Evening) OT hours are forced to **0** no matter how late the checkout is.
- Shifts with no breaks/lunch → break minutes = **0**, so net worked = full presence
  (a 06:00–14:00 shift worked fully = 8h net = full day).
- Everything downstream (per-day rate, LOP, PF/ESI) is shift-independent — **unchanged**.

**Point-in-time option (recommended):** stamp `attendance.shift_id` when a day is
marked (Phase 2), and have payroll read the shift **from the attendance row**, not
the employee's current shift. This freezes historical payslips even if the employee
is later moved to another shift. Falls back to the employee's shift when null.

**Verification (harness, like the PF/ESI checks):**
- For each shift, feed known check-in/out and assert late, OT hours, half/short
  classification match hand-calculated expectations.
- Regenerate a General employee's existing month → identical to before.
- Regenerate an Evening employee → OT triggers after 22:00, late measured from 14:00.

**Rollback:** revert `getAttendance()` + the helper refactor to globals.

---

## PHASE 4 — Polish, reporting, cleanup

- **Payslip** (`slip.php`, `slip_pdf.php`): show the shift name/timing.
- **Reports** (attendance, payroll register): filter / group by shift.
- **Retire globals:** the old Office / OT / Grace / Breaks settings pages either
  (a) become a shortcut that edits the **General** shift, or (b) are removed once
  every read goes through shifts. Keep them until Phase 3 is verified.
- **Docs/help text** updated to explain shifts.

---

## Cross-cutting concerns

1. **Shift change resets lunch batch.** Moving an employee to a new shift can orphan
   their lunch batch (belongs to the old shift). On save, if the batch's shift ≠ the
   new shift, clear it (or force re-pick). Enforce in `create.php` / `edit.php`.
2. **Point-in-time payslips.** Without `attendance.shift_id`, re-running an old month
   uses the employee's *current* shift. Add the stamp in Phase 3 to freeze history.
3. **Night shifts (future).** All three shifts end same-day (≤22:00) so no midnight
   crossing today. Design the table to allow `end_time < start_time` later, but the
   engines' cross-midnight date handling is out of scope until a night shift exists.
4. **Weekly-off / Saturday rules** stay global (not per-shift) unless you say otherwise.
5. **Permissions.** Reuse the `settings` permission, or add a dedicated `shifts`
   permission + role grants (see `install/roles_permissions_seed.sql`).
6. **Performance.** Cache shift + break lookups per request (static cache, like
   `employee_lunch_window()` already does).
7. **Two OT gates.** OT accrues only when **both** the shift (`shift.ot_enabled`)
   and the employee (`employees.ot_enabled`) allow it. Straight shifts set
   `ot_enabled = 0`, so their employees never earn OT even if individually flagged.

---

## Sequencing & safety summary

| Phase | Behaviour change | Risk | Depends on |
|---|---|---|---|
| 0 Foundation (schema + seed) | none | very low | — |
| 1 Shift UI + assignment | none | low | 0 |
| 2 Attendance reads shift | marking correctness | medium | 0,1 |
| 3 Payroll reads shift | payslip correctness | **high** (test hard) | 0,1,2 |
| 4 Polish & reporting | cosmetic | low | 3 |

Each phase is shippable on its own and reversible. General shift mirrors today's
settings throughout, so existing employees are unaffected until they're explicitly
moved to a new shift.

---

## Deliverables per phase (files)

- **Phase 0:** `install/add_shift_system.sql`; helpers in `includes/helpers.php`.
- **Phase 1:** `modules/settings/shifts.php`; lunch-batch screen + `shift_id`;
  `modules/employee/{add_form,create,edit}.php`; nav in `includes/header.php`.
- **Phase 2:** `modules/attendance/mark.php` + attendance views.
- **Phase 3:** `includes/PayrollCalculator.php`, timing helpers; optional
  `attendance.shift_id`.
- **Phase 4:** `modules/payroll/{slip,slip_pdf}.php`, reports; retire global
  settings pages.
