# HRMS Security Fixes — MERGED delta (C-1 … L-8)

This package is the security-audit remediation set **re-merged onto the current
codebase**. It replaces `HRMS-SECURITY-FIXES-FINAL-C1-to-L8.zip`, which must not
be deployed as-is.

## Why the original package was unsafe

It was built against commit `348702f` and therefore predates commit `7688513`
("Internship certificate, Experience certificate and Id-card functionalities are
added"). Deploying it would have overwritten 8 files with pre-feature versions,
silently reverting that commit — and one of those reversions was a hard crash.

The System Update deployer only ADDs and UPDATEs; it never deletes. So nothing
was going to be "removed" from disk — the features would have disappeared
because the files carrying them were rolled back.

## What was re-merged (3-way: base 348702f / live HEAD / security fix)

70 of the 75 files merged automatically with no conflict. These 8 needed the
feature work carried across:

| File | Feature restored on top of the security fix |
|---|---|
| `includes/helpers.php` | `designation_is_intern()`, `employee_is_intern()` |
| `includes/header.php` | "Experience Letters" + "Internship Certificates" nav items |
| `includes/permissions.php` | `letters.experience` / `letters.internship` labels |
| `modules/letters/create.php` | Experience + Internship templates, field sets, eligibility guard, `EMP_TYPE_SCOPE` |
| `modules/letters/view.php` | Experience + Internship render branches |
| `modules/employee/edit.php` | Intern CTC zeroing + `intern_ctc_guard.php` |
| `modules/employee/add_form.php` | `intern_ctc_guard.php` |
| `install/roles_permissions_seed.sql` | `letters.experience` / `letters.internship` permissions and role grants |

### The crash that was avoided

The original package deleted `designation_is_intern()` from `includes/helpers.php`,
but does **not** ship `modules/employee/create.php`, which calls it at line 143.
Adding an employee would have been a PHP fatal error. `includes/letter_types.php`
had the same problem with `employee_is_intern()`.

### One conflict, resolved by hand

`modules/letters/create.php` — reference-number generation. The security fix
(L-5) replaced the racy `COUNT(*)+1` with the atomic `next_letter_reference()`;
the feature work had added per-type reference codes (`EXP`, `INT`). Both are
wanted, so the resolution keeps both:

```php
$ref = next_letter_reference(LETTER_REF_CODES[$type] ?? $type[0]);
```

## One addition beyond the original package

`includes/bootstrap.php` now self-defaults `SESSION_NAME`:

```php
if (!defined('SESSION_NAME')) define('SESSION_NAME', 'HRMS_SESSION');
```

H-1 calls `session_name(SESSION_NAME)`, but the constant is defined in
`config/app.php` — a **protected path no package can write**. On this server it
exists, so behaviour is unchanged. On a server with an older config it would
fatal on the first request with no way to deploy a fix.

## Deliberate behaviour change to be aware of (M-4)

The ID-card portal password is no longer derived from name + DOB. It is now a
random secret, stored only as a hash and **revealed exactly once**. Cards still
holding a derived password are rotated on next use. The "First 4 letters of name
+ DDMM" hint and the example password no longer appear on the card page — anyone
who relied on that rule to tell an employee their password will need the
reveal-once flow instead.

## Verification performed on this package

- 3-way merge, base `348702f`; 70/75 automatic, 1 conflict resolved, 4 new files.
- `php -l` across the entire simulated post-deploy tree — 0 syntax errors.
- Static resolve scan of every function call in the post-deploy tree —
  **0 unresolved**. The same scan run against the original package reports
  `designation_is_intern` and `employee_is_intern` as unresolved, which is how
  the crash was found.
- Every audit fix re-checked as still present after merging (H-1…H-11, M-1…M-21,
  L-1…L-8), and every feature of commit `7688513` re-checked as still present.

## Deploying

The deployer takes **no backups**; an overwrite is irreversible and rollback only
removes newly-added files. Take a full copy of the app directory first.

Tick **Run SQL** so `install/roles_permissions_seed.sql` executes — it is
`INSERT IGNORE` and deletes nothing. It is what creates `payroll.override`
(needed by M-20) and, on a fresh install, the two letter permissions.
