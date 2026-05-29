<?php
/**
 * MagDyn HRMS — Application Configuration
 * Replace this file when moving between environments.
 */

// ─── Base URL (no trailing slash) ────────────────────────────────────────────
define('BASE_URL',  'http://192.168.1.33/hrms');
define('APP_URL',   BASE_URL);           // alias used in PWA / Settings views
define('BASE_PATH', dirname(__DIR__));   // absolute path to project root

// ─── Application Identity ────────────────────────────────────────────────────
define('APP_NAME',    'MagDyn HRMS');
define('APP_VERSION', '1.0.0');
define('COMPANY_NAME', 'Your Company Name');
define('COMPANY_ADDRESS', '123 Business Park, Chennai, Tamil Nadu 600001');
define('COMPANY_EMAIL',   'hr@yourcompany.com');
define('COMPANY_PHONE',   '+91 44 0000 0000');
define('COMPANY_CIN',     'U00000TN0000PTC000000');  // for letterheads
define('COMPANY_PAN',     'AAAAA0000A');

// ─── Session ──────────────────────────────────────────────────────────────────
define('SESSION_NAME',    'HRMS_SESSION');
define('SESSION_TIMEOUT', 7200);   // seconds (2 hours)

// ─── Single Sign-On (SSO) ────────────────────────────────────────────────────
define('SSO_ENABLED',      false);
define('SSO_PROVIDER',     'oauth2');          // 'oauth2' | 'saml' | 'ldap'
define('SSO_CLIENT_ID',    'your_client_id');
define('SSO_CLIENT_SECRET','your_client_secret');
define('SSO_REDIRECT_URI',  BASE_URL . '/sso/callback.php');
define('SSO_AUTH_URL',      'https://login.microsoftonline.com/your-tenant/oauth2/v2.0/authorize');
define('SSO_TOKEN_URL',     'https://login.microsoftonline.com/your-tenant/oauth2/v2.0/token');
define('SSO_USERINFO_URL',  'https://graph.microsoft.com/oidc/userinfo');
define('SSO_SCOPE',         'openid profile email');

// ─── Global Login Schema (shared SSO database) ───────────────────────────────
define('GLOBAL_AUTH_ENABLED', false);
define('GLOBAL_AUTH_DB_HOST', 'localhost');
define('GLOBAL_AUTH_DB_NAME', 'magdyn_hrms');
define('GLOBAL_AUTH_DB_USER', 'root');
define('GLOBAL_AUTH_DB_PASS', '');

// ─── File Uploads ─────────────────────────────────────────────────────────────
define('UPLOAD_MAX_MB',    10);
define('UPLOAD_PATH',      BASE_PATH . '/uploads');
define('ALLOWED_DOC_TYPES',['application/pdf', 'image/jpeg', 'image/png']);

// ─── Payroll ─────────────────────────────────────────────────────────────────
define('PAYROLL_CURRENCY',      'INR');
define('PAYROLL_CURRENCY_SYMBOL','₹');
define('PAYROLL_WORKING_DAYS',  26);   // default working days per month
define('PAYROLL_PF_EMPLOYEE',   0.12); // 12%
define('PAYROLL_PF_EMPLOYER',   0.12);
define('PAYROLL_ESI_EMPLOYEE',  0.0075); // 0.75%
define('PAYROLL_ESI_EMPLOYER',  0.0325); // 3.25%
define('PAYROLL_ESI_WAGE_LIMIT',21000);  // ESI only applies below this gross

// ─── Attendance ───────────────────────────────────────────────────────────────
define('ATTENDANCE_GRACE_MINUTES', 15);  // late threshold
define('WORK_START_TIME',          '09:00');
define('WORK_END_TIME',            '18:00');

// ─── PWA ─────────────────────────────────────────────────────────────────────
define('PWA_APP_NAME',       APP_NAME);
define('PWA_SHORT_NAME',     'HRMS');
define('PWA_THEME_COLOR',    '#1e3a8a');
define('PWA_BG_COLOR',       '#f4f5f7');

// ─── Notifications ───────────────────────────────────────────────────────────
define('VAPID_PUBLIC_KEY',  'YOUR_VAPID_PUBLIC_KEY');   // generate with web-push library
define('VAPID_PRIVATE_KEY', 'YOUR_VAPID_PRIVATE_KEY');
define('VAPID_SUBJECT',     'mailto:' . COMPANY_EMAIL);

// ─── Environment ─────────────────────────────────────────────────────────────
define('APP_ENV',   'development');    // 'development' | 'production'
define('APP_DEBUG', true);

if (APP_ENV === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// ─── Timezone ─────────────────────────────────────────────────────────────────
date_default_timezone_set('Asia/Kolkata');
