<?php
/**
 * MagDyn HRMS — Admin Password Reset / First-Run Setup
 * =====================================================
 * Run this script ONCE after importing schema.sql to set
 * a valid bcrypt password hash for the admin account.
 *
 * Usage (CLI):   php install/reset_admin.php
 * Usage (Web):   https://yoursite.com/install/reset_admin.php
 *
 * DELETE this file after first login!
 */

define('HRMS_INSTALL', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

$defaultPassword = 'Admin@1234';
$hash = password_hash($defaultPassword, PASSWORD_BCRYPT, ['cost' => 12]);

$pdo = db();

// Upsert admin user
$existing = $pdo->prepare("SELECT id FROM users WHERE email = 'admin@hrms.local' LIMIT 1");
$existing->execute();
$adminId = $existing->fetchColumn();

if ($adminId) {
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, is_active = 1, role_id = 1 WHERE id = ?");
    $stmt->execute([$hash, $adminId]);
    $action = "Updated";
} else {
    $stmt = $pdo->prepare("INSERT INTO users (email, name, password_hash, role_id, is_active) VALUES ('admin@hrms.local', 'System Admin', ?, 1, 1)");
    $stmt->execute([$hash]);
    $action = "Created";
}

$isCLI = php_sapi_name() === 'cli';

if ($isCLI) {
    echo "✅ {$action} admin account.\n";
    echo "   Email:    admin@hrms.local\n";
    echo "   Password: {$defaultPassword}\n";
    echo "   Hash:     {$hash}\n\n";
    echo "⚠  Delete this file after first login:\n";
    echo "   rm " . __FILE__ . "\n";
} else {
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HRMS Admin Reset</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 36px 44px; max-width: 480px; width: 100%; }
        h1 { color: #38bdf8; margin-top: 0; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 13px; font-weight: 600; }
        .ok { background: #064e3b; color: #34d399; }
        .field { background: #0f172a; border-radius: 8px; padding: 12px 16px; margin: 10px 0; font-family: monospace; font-size: 14px; }
        .warn { background: #451a03; border: 1px solid #92400e; color: #fbbf24; border-radius: 8px; padding: 12px 16px; margin-top: 20px; font-size: 13px; }
        a.btn { display: inline-block; margin-top: 20px; background: #1e3a8a; color: white; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; }
    </style>
</head>
<body>
<div class="card">
    <h1>🔐 HRMS Setup</h1>
    <p><span class="badge ok">✓ <?= $action ?></span> Admin account is ready.</p>
    <div class="field">Email: <strong>admin@hrms.local</strong></div>
    <div class="field">Password: <strong><?= htmlspecialchars($defaultPassword) ?></strong></div>
    <div class="warn">
        ⚠️ <strong>Security:</strong> Delete <code>install/reset_admin.php</code> immediately after logging in.
        Change the admin password from Settings.
    </div>
    <a class="btn" href="../login.php">→ Go to Login</a>
</div>
</body>
</html>
<?php
}
