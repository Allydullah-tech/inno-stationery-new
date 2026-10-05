<?php
/**
 * INNO's STATIONERY - Database connection
 *
 * Fill in your real database credentials below (or set them as
 * environment variables on your server).
 */

// ---------------------------------------------------------------
// EDIT THESE VALUES to match your setup
// ---------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3307');   // <-- your MySQL is on 3307, not the default 3306
define('DB_NAME', getenv('DB_NAME') ?: 'stationery_new_inno');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
// ---------------------------------------------------------------

function get_db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed: ' . $e->getMessage(),
            ]);
            exit;
        }
    }
    return $pdo;
}
