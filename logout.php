<?php
require_once __DIR__ . '/includes/bootstrap.php';
if ($u = current_user()) activity_log('logout', 'Auth', 'Logged out: ' . ($u['name'] ?? ''));
logout_user();
redirect(BASE_URL . '/login.php');
