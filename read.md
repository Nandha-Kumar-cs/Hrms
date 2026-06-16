# MagDyn HRMS

A PHP-based Human Resource Management System with PWA support, covering the full
employee lifecycle: attendance, payroll, letters, assets, training and more.

## Tech Stack

- **Backend:** PHP 8.2 (PDO / MySQL)
- **Database:** MySQL / MariaDB (`magdyn_hrms`)
- **Server:** Apache (XAMPP)
- **Frontend:** Vanilla JS + Bootstrap, custom CSS
- **PWA:** Installable app via `manifest.json` + service worker

## Requirements

- XAMPP (Apache + MySQL) with PHP 8.2+
- A MySQL database named `magdyn_hrms`

## Setup

1. Place the project in your web root (e.g. `C:\xampp8.2\htdocs\hrms`).
2. Create the database `magdyn_hrms` in MySQL/phpMyAdmin and import the schema.
3. Configure `config/database.php` with your DB credentials.
4. Configure `config/app.php` — set `BASE_URL`, company identity, and environment.
5. Visit the app in a browser (e.g. `http://localhost/hrms`).

## Configuration

| File | Purpose |
|------|---------|
| `config/database.php` | DB host, name, user, password (do not commit credentials) |
| `config/app.php` | Base URL, company info, sessions, SSO, payroll, attendance, PWA |

Switch `APP_ENV` to `production` in `config/app.php` to disable error display.

## Project Structure

```
config/        Database and application configuration
includes/      Bootstrap, auth, permissions, header/footer/sidebar
modules/       Feature modules (see below)
api/           Backend endpoints (e.g. notifications)
assets/        CSS, JS, icons, screenshots
install/       Setup / admin reset helpers
manifest.json  PWA manifest
index.php      Dashboard entry point
login.php      Authentication entry point
```

## Modules

- **employee** — directory, profiles, create/edit, family, import
- **attendance** — daily/monthly marking, calendar, OD, comp-off, OT, exports
- **payroll** — salary structure, components, slips, finalize, history, exports
- **letters** — create, issue, view, delete HR letters
- **assets** — asset register, assignment, clearance
- **training** — courses, enrollment
- **roles** — role-based access control
- **benefits / bonuses / increments / loans / promotions** — compensation events
- **documents** — employee document storage
- **settings** — OT, grace and general settings
- **sso** — Single Sign-On (OAuth2 / SAML / LDAP), disabled by default
- **pwa** — Progressive Web App support

## Key Defaults

- Currency: INR (₹), 26 working days/month
- PF: 12% employee / 12% employer; ESI applies below ₹21,000 gross
- Work hours: 09:00–18:00, 15 min daily grace, 90 min monthly grace
- Timezone: Asia/Kolkata
- Session timeout: 2 hours

## Notes

- Do not commit real database credentials or VAPID keys.
- Replace `config/*.php` when moving between environments.
