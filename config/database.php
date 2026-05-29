<?php
/**
 * MagDyn HRMS — Database Configuration
 * Replace this file when moving between environments.
 * DO NOT commit credentials to version control.
 */

define('DB_HOST',     'localhost');
define('DB_PORT',     '3306');
define('DB_NAME',     'magdyn_hrms');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_CHARSET',  'utf8mb4');

/**
 * Returns a PDO connection (singleton per request).
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('HRMS DB connection failed: ' . $e->getMessage());
            die(json_encode(['error' => 'Database connection failed. Check config/database.php']));
        }
    }
    return $pdo;
}
