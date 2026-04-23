<?php
// ============================================================
// config/db.php — PDO Database Connection (Singleton)
// StudyGuard PHP Backend
// ============================================================

declare(strict_types=1);

// A static secret key for JWT generation
define('JWT_SECRET', 'studyguard-super-secret-key-3b4c5d6e7f8g9h0i');

/**
 * Returns a singleton PDO connection to the studyguard MySQL database.
 * Uses XAMPP defaults: host=localhost, user=root, password='' (empty).
 *
 * @return mysqli
 */
function get_db(): mysqli
{
    static $conn = null;

    if ($conn !== null) {
        return $conn;
    }

    $host    = getenv('DB_HOST') ?: 'localhost';
    $dbname  = getenv('DB_NAME') ?: 'studyguard';
    $user    = getenv('DB_USER') ?: 'root';
    $pass    = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $port    = getenv('DB_PORT') ?: 3307;

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = mysqli_connect($host, $user, $pass, $dbname, $port);
        mysqli_set_charset($conn, 'utf8mb4');
    } catch (mysqli_sql_exception $e) {
        // Return a 500 JSON error — never leak credentials
        error_log("Database Connection Error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => 'Database connection failed'
        ]);
        exit(1);
    }

    return $conn;
}
