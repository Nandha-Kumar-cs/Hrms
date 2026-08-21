-- MagDyn HRMS — Roles & Permissions seed (COMPLETE, 89 permissions)
-- Idempotent: INSERT IGNORE + name/module/action resolution (portable across installs).
-- Safe to run on production. Does NOT touch users and deletes nothing.
-- Run:  mysql -u<user> -p <dbname> < roles_permissions_seed.sql
SET NAMES utf8mb4;

-- ── 1) Permissions catalog (full 89) ─────────────────────────────────
INSERT IGNORE INTO permissions (module, action, label) VALUES
('activity','view','View Activity Log'),
('assets','assign','Assign Assets'),
('assets','return','Return Assets'),
('assets','view','View Assets'),
('attendance','calendar','Monthly Attendance'),
('attendance','edit','Edit Attendance'),
('attendance','export','Export Attendance'),
('attendance','mark','Mark Attendance'),
('attendance','report','Attendance Report'),
('attendance','view','View Attendance'),
('benefits','create','Manage Benefits'),
('benefits','view','View Benefits'),
('bonuses','create','Manage Bonuses & Incentives'),
('bonuses','view','View Bonuses & Incentives'),
('compoff','edit','Manage Comp Offs'),
('compoff','view','View Comp Offs'),
('compoff_credits','edit','Manage Comp Off Credits'),
('compoff_credits','view','View Comp Off Credits'),
('dashboard','view','View Dashboard'),
('documents','create','Upload Documents'),
('documents','delete','Delete Documents'),
('documents','view','View Documents'),
('employee','create','Create Employee'),
('employee','delete','Delete Employee'),
('employee','edit','Edit Employee'),
('employee','view','View Employees'),
('holidays','create','Create Holidays'),
('holidays','delete','Delete Holidays'),
('holidays','edit','Edit Holidays'),
('holidays','view','View Holidays'),
('increments','create','Manage Increments'),
('increments','view','View Increments'),
('leave_history','view','View Leave History'),
('leaves','approve','Allow Admin to Approve Leave Request'),
('leaves','create','Create Leave Requests'),
('leaves','delete','Delete Leave Requests'),
('leaves','edit','Edit / Approve Leave Requests'),
('leaves','view','View Leave Requests'),
('letters','confirmation','Confirmation Letters'),
('letters','create','Create Letters'),
('letters','experience','Experience Letters'),
('letters','internship','Internship Certificates'),
('letters','delete','Delete Letters'),
('letters','increment','Increment Letters'),
('letters','offer','Offer Letters'),
('letters','promotion','Promotion Letters'),
('letters','view','View Letters'),
('loans','create','Manage Loans & Advances'),
('loans','view','View Loans & Advances'),
('od','create','Create OD Requests'),
('od','delete','Delete OD Requests'),
('od','edit','Approve / Reject OD Requests'),
('od','view','View OD Requests'),
('payroll','calculate','Salary Calculation'),
('payroll','export','Export Payroll'),
('payroll','process','Process Payroll'),
('payroll','view','View Payroll'),
('promotions','create','Manage Promotions'),
('promotions','view','View Promotions'),
('pwa','manage','Manage PWA Settings'),
('pwa','view','View PWA Settings'),
('report_benefits','export','Export Monthly Benefits'),
('report_benefits','view','View Monthly Benefits'),
('report_bonus','export','Export Bonus Report'),
('report_bonus','view','View Bonus Report'),
('report_history','export','Export Employee History'),
('report_history','view','View Employee History'),
('report_impact','export','Export Payroll Impact'),
('report_impact','view','View Payroll Impact'),
('roles','manage','Manage Roles & Permissions'),
('roles','view','View Roles & Permissions'),
-- Settings: overview flags + one granular permission per sub-section --------
('settings','manage','Manage Settings'),
('settings','view','View Settings'),
('settings','entities','Entities'),
('settings','departments','Departments'),
('settings','designations','Designations'),
('settings','leave_types','Leave Types'),
('settings','holiday_types','Holiday Types'),
('settings','benefit_fund_types','Benefit Fund Types'),
('settings','asset_categories','Asset Categories'),
('settings','office','Office Settings'),
('settings','branding','Branding'),
-- --------------------------------------------------------------------------
('sso','manage','Manage SSO Settings'),
('sso','view','View SSO Settings'),
('training','enroll','Enroll in Training'),
('training','manage','Manage Training'),
('training','view','View Training'),
('users','create','Create Users'),
('users','delete','Delete Users'),
('users','edit','Edit Users'),
('users','view','View Users');

-- ── 2) Roles ─────────────────────────────────────────────────────────
INSERT IGNORE INTO roles (name, description, self_scope, created_at) VALUES ('Super Admin','Full system access',0, NOW());
INSERT IGNORE INTO roles (name, description, self_scope, created_at) VALUES ('Employee','Self-service employee login',1, NOW());

-- ── 3) Grants ────────────────────────────────────────────────────────
-- Super Admin: no explicit grants needed (the role bypasses all permission checks).

-- Employee self-service baseline (25 permissions). Self-scope restricts to OWN data.
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
  WHERE r.name = 'Employee' AND (
    (p.module='dashboard' AND p.action='view')
    OR (p.module='employee' AND p.action='view')
    OR (p.module='attendance' AND p.action='view')
    OR (p.module='leaves' AND p.action='view')
    OR (p.module='leaves' AND p.action='create')
    OR (p.module='leave_history' AND p.action='view')
    OR (p.module='payroll' AND p.action='view')
    OR (p.module='letters' AND p.action='view')
    OR (p.module='letters' AND p.action='offer')
    OR (p.module='letters' AND p.action='increment')
    OR (p.module='letters' AND p.action='confirmation')
    OR (p.module='letters' AND p.action='promotion')
    OR (p.module='letters' AND p.action='experience')
    OR (p.module='letters' AND p.action='internship')
    OR (p.module='documents' AND p.action='view')
    OR (p.module='loans' AND p.action='view')
    OR (p.module='increments' AND p.action='view')
    OR (p.module='benefits' AND p.action='view')
    OR (p.module='bonuses' AND p.action='view')
    OR (p.module='promotions' AND p.action='view')
    OR (p.module='holidays' AND p.action='view')
    OR (p.module='od' AND p.action='view')
    OR (p.module='od' AND p.action='create')
    OR (p.module='compoff' AND p.action='view')
    OR (p.module='compoff_credits' AND p.action='view')
    OR (p.module='training' AND p.action='view')
    OR (p.module='training' AND p.action='enroll')
  );
