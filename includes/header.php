<?php
/**
 * MagDyn HRMS — Layout Header Partial
 * Usage: include with $page_title and $page_subtitle defined.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$flash = render_flash();
$user  = current_user();
$notif_count = count(get_notifications($user['id'], true));

// Build nav with permission check
$nav = [
    ['icon' => '⊞', 'label' => 'Dashboard',   'href' => BASE_URL . '/index.php',                        'mod' => 'dashboard'],
    ['icon' => '👤', 'label' => 'Employees',   'href' => BASE_URL . '/modules/employee/index.php',       'mod' => 'employee'],
    ['icon' => '📋', 'label' => 'Attendance',  'href' => BASE_URL . '/modules/attendance/index.php',     'mod' => 'attendance'],
    ['icon' => '💰', 'label' => 'Payroll',     'href' => BASE_URL . '/modules/payroll/index.php',        'mod' => 'payroll'],
    ['icon' => '📄', 'label' => 'Letters',     'href' => BASE_URL . '/modules/letters/index.php',        'mod' => 'letters'],
    ['icon' => '🖥', 'label' => 'Assets',      'href' => BASE_URL . '/modules/assets/index.php',         'mod' => 'assets'],
    ['icon' => '🎓', 'label' => 'Training',    'href' => BASE_URL . '/modules/training/index.php',       'mod' => 'training'],
    ['icon' => '🔐', 'label' => 'Roles',       'href' => BASE_URL . '/modules/roles/index.php',          'mod' => 'roles'],
    ['icon' => '📱', 'label' => 'PWA Access',  'href' => BASE_URL . '/modules/pwa/index.php',            'mod' => 'pwa'],
    ['icon' => '⚙',  'label' => 'Settings',   'href' => BASE_URL . '/modules/settings/index.php',       'mod' => 'settings'],
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= PWA_THEME_COLOR ?>">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <title><?= h($page_title ?? 'Dashboard') ?> — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magdyn-base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hrms.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
</head>
<body>
<div class="layout">
<!-- ═══════════════ SIDEBAR ═══════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-mark">
            <svg width="38" height="38" viewBox="0 0 38 38" fill="none" xmlns="http://www.w3.org/2000/svg">
                <rect width="38" height="38" rx="8" fill="#1e3a8a"/>
                <text x="50%" y="55%" dominant-baseline="middle" text-anchor="middle" fill="white" font-size="18" font-weight="bold" font-family="system-ui">H</text>
            </svg>
        </div>
        <div class="brand-text">
            <div class="brand-title"><?= h(APP_NAME) ?></div>
            <div class="brand-sub"><?= h(COMPANY_NAME) ?></div>
        </div>
        <button class="sidebar-toggle" id="sidebarToggle" title="Toggle sidebar (Alt+S)">⇔</button>
    </div>

    <nav class="nav" id="mainNav">
        <div class="nav-section-label">MAIN</div>
        <?php foreach ($nav as $item): ?>
            <?php if (can($item['mod'])): ?>
            <a class="nav-item <?= active_if($item['mod']) ?>"
               href="<?= $item['href'] ?>"
               title="<?= h($item['label']) ?>">
                <span class="nav-icon"><?= $item['icon'] ?></span>
                <span class="nav-label"><?= h($item['label']) ?></span>
            </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sidebar-footer">
        <div class="nav-item user-menu" id="userMenuTrigger" style="cursor:pointer">
            <span class="nav-icon">👤</span>
            <span class="nav-label">
                <span style="display:block;font-size:12px;color:white;"><?= h($user['name']) ?></span>
                <span style="font-size:11px;color:#64748b;"><?= h($user['role_name'] ?? '') ?></span>
            </span>
            <?php if ($notif_count > 0): ?>
            <span class="notif-badge"><?= $notif_count ?></span>
            <?php endif; ?>
        </div>
        <a class="nav-item" href="<?= BASE_URL ?>/logout.php" title="Logout (Alt+Q)">
            <span class="nav-icon">⊗</span>
            <span class="nav-label">Logout</span>
        </a>
    </div>
</aside>

<!-- ═══════════════ MAIN ═══════════════ -->
<main class="main" id="mainContent">
    <?php if ($flash): ?>
    <div class="alert-container"><?= $flash ?></div>
    <?php endif; ?>

    <!-- Notification Bell (top-right) -->
    <div id="notifPanel" class="notif-panel" style="display:none"></div>

    <!-- Keyboard shortcuts hint bar -->
    <div class="shortcut-bar" id="shortcutBar" style="display:none">
        <strong>Keyboard Shortcuts Active</strong> — Hold <kbd>Alt</kbd> to highlight
    </div>
