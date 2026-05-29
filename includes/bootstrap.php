<?php
/**
 * MagDyn HRMS — Bootstrap
 * Include this at the top of every PHP page.
 */

// Load configs
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// Session init
session_name(SESSION_NAME);
session_start();

// Helpers
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
