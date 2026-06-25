# MagDyn HRMS — Deployment / Configuration Guide

What to change when moving this app to another server (dev or production).
Everything environment-specific lives in **two files**: `config/database.php` and `config/app.php`.

---

## 1. Database connection — `config/database.php`

| Constant | Current | Change to |
|---|---|---|
| `DB_HOST` | `localhost` | DB server host (often `localhost` / `127.0.0.1`) |
| `DB_PORT` | `3306` | MySQL/MariaDB port |
| `DB_NAME` | `magdyn_hrms` | Target database name |
| `DB_USER` | `root` | A **dedicated** DB user (do NOT use `root` in production) |
| `DB_PASS` | *(empty)* | A **strong password** (never leave blank in production) |

---

## 2. Application config — `config/app.php`

**The single most important change:**

```php
define('BASE_URL', 'http://192.168.1.47/hrms');   // ← change to the new server URL
```
Set it to exactly how the app is reached, **no trailing slash**. Examples:
- In XAMPP subfolder: `http://<server-ip>/hrms`
- On a domain root: `https://hrms.yourcompany.com`
- The whole app uses absolute URLs built from `BASE_URL`, so getting this right is what makes links, forms, redirects, and assets work.

Other things to review in `config/app.php`:

| Setting | Note |
|---|---|
| `APP_ENV` | Set to **`'production'`** on the live server — this hides PHP errors. Keep `'development'` on the dev box. |
| `APP_DEBUG` | `false` for production. |
| `COMPANY_NAME / ADDRESS / EMAIL / PHONE / CIN / PAN` | Used on letterheads & payslips — set to the real company. (Note: most letters now pull company details from the **Entities** module too — set those in the app after login.) |
| `date_default_timezone_set('Asia/Kolkata')` | Change if the server serves a different timezone. |
| `SESSION_TIMEOUT` | Idle logout, seconds (default 7200 = 2h). |
| `UPLOAD_MAX_MB` | Max upload size (default 10). Must be ≤ PHP `upload_max_filesize` / `post_max_size`. |
| Payroll block (`PAYROLL_*`) | PF/ESI rates, working days, ESI wage limit — adjust to policy. |
| Attendance block (`WORK_START_TIME`, grace, OT) | Defaults; most are also editable in **Settings → Office** after login. |
| `SSO_*` / `GLOBAL_AUTH_*` | Only if using SSO — disabled by default (`SSO_ENABLED = false`). |
| `VAPID_PUBLIC_KEY / PRIVATE_KEY` | Only if using web-push notifications. |

> **Do not commit real credentials** to git. `config/database.php` and `config/app.php` should be edited per-environment.

---

## 3. Create & load the database

1. Create the database (name must match `DB_NAME`):
   ```sql
   CREATE DATABASE magdyn_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```
2. Import the base schema:
   ```bash
   mysql -u<user> -p magdyn_hrms < install/schema.sql
   ```
3. Apply **all** migration files in `install/` (they are idempotent — safe to re-run).
   Run each `install/add_*.sql` / `alter_*.sql` against the database, e.g.:
   ```bash
   for f in install/add_*.sql install/alter_*.sql; do mysql -u<user> -p magdyn_hrms < "$f"; done
   ```
   (On Windows use the MySQL client or phpMyAdmin → Import for each file.)
4. Set the admin password:
   ```bash
   mysql -u<user> -p magdyn_hrms < install/fix_admin_password.sql
   ```
   This sets the default login:
   - **Email:** `admin@hrms.local`
   - **Password:** `Admin@1234`
   - **→ Log in and change this password immediately.**

---

## 4. Writable directories

The app writes uploaded files here — they must exist and be **writable by the web server**:

- `storage/` (and `storage/entities/` — logos / signatures)
- `uploads/` (employee documents)

On Linux: `chown -R www-data:www-data storage uploads && chmod -R 775 storage uploads`.
On Windows/XAMPP they're usually writable already.

---

## 5. PHP requirements

- **PHP 8.2** (matches the dev environment).
- Required extensions: `pdo_mysql`, `mbstring`, `gd` (image/signature handling), `openssl`, `fileinfo`, `zip` (Excel import).
- `php.ini`: set `upload_max_filesize` and `post_max_size` ≥ `UPLOAD_MAX_MB` (10M), and `max_execution_time` high enough for bulk imports/payroll.

---

## 6. Web server

- **Apache (XAMPP):** drop the project in the document root so the URL matches `BASE_URL` (e.g. `htdocs/hrms` → `http://server/hrms`). No special rewrite rules required — the app does not use a front controller.
- **Production:** front it with HTTPS. If serving at a domain root, point the vhost `DocumentRoot` at the project folder and set `BASE_URL` to `https://yourdomain`.

---

## 7. ⚠️ Known machine-specific paths — PDF generation

The PDF exports (salary slip, letters, no-due clearance, loan history) currently look for a **TCPDF** library in hard-coded XAMPP paths from *this* machine, e.g.:

```
C:/xampp8.2/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php
C:/xampp8.2/htdocs/xibo/vendor/autoload.php
```
(in `modules/payroll/slip_pdf.php`, `modules/letters/download.php`,
`modules/assets/clearance_pdf.php`, `modules/loans/history_pdf.php`)

On a new server those paths won't exist, so PDF generation **falls back to a printable HTML page** (still works, just not a real PDF download). To get true PDFs on the new server you must either:
- install TCPDF/mPDF on that server and update those paths, or
- ask me to **bundle a PDF library inside the project** (recommended) so it's portable and no path-editing is needed.

---

## 8. Production hardening checklist

- [ ] `BASE_URL` set to the real address (with `https://`).
- [ ] `APP_ENV = 'production'`, `APP_DEBUG = false`.
- [ ] Dedicated DB user + strong `DB_PASS` (not `root` / blank).
- [ ] Default admin password changed after first login.
- [ ] `storage/` and `uploads/` writable; everything else read-only to the web user.
- [ ] Serve over **HTTPS**.
- [ ] Restrict or remove the `install/` folder after setup (contains schema & the password-reset SQL).
- [ ] Take a **database backup** before go-live and on a schedule.

---

## Quick "move it" summary

1. Copy the whole `hrms/` folder to the new server's web root.
2. Edit **`config/database.php`** (DB host/name/user/pass).
3. Edit **`config/app.php`** (`BASE_URL`, `APP_ENV`, company details, timezone).
4. Create the DB, import `install/schema.sql`, apply all `install/*.sql`, run `install/fix_admin_password.sql`.
5. Ensure `storage/` and `uploads/` are writable.
6. Open `BASE_URL` → log in as `admin@hrms.local` / `Admin@1234` → change password.
7. (Optional) Set up real PDF library — see §7.
