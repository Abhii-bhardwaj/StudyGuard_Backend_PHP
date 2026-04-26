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

    // --- Simple .env parser for local dev without Composer ---
    $env_file = __DIR__ . '/../.env';
    if (file_exists($env_file)) {
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) continue;
            if (strpos($line, '=') !== false) {
                list($name, $value) = explode('=', $line, 2);
                $name = trim($name);
                $value = trim($value);
                // Only set if not already set by system (like Render)
                if (getenv($name) === false) {
                    putenv(sprintf('%s=%s', $name, $value));
                    $_ENV[$name] = $value;
                }
            }
        }
    }

    // Retrieve variables (either from Render system ENVs or local .env)
    $host    = getenv('DB_HOST') ?: 'localhost';
    $dbname  = getenv('DB_NAME') ?: 'studyguard';
    $user    = getenv('DB_USER') ?: 'root';
    $pass    = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
    $port    = (int)(getenv('DB_PORT') ?: 3307);

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    try {
        $conn = mysqli_init();
        
        // If the host is not localhost, it's a cloud DB (like Aiven) which requires SSL
        $is_localhost = ($host === 'localhost' || $host === '127.0.0.1');
        $flags = $is_localhost ? 0 : MYSQLI_CLIENT_SSL | MYSQLI_CLIENT_SSL_DONT_VERIFY_SERVER_CERT;

        // Establish connection
        mysqli_real_connect($conn, $host, $user, $pass, $dbname, $port, null, $flags);
        mysqli_set_charset($conn, 'utf8mb4');
        
    } catch (mysqli_sql_exception $e) {
        // Return a 500 JSON error — never leak credentials
        error_log("Database Connection Error: " . $e->getMessage());
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'   => 'Database connection failed',
            'message' => 'Could not connect to the database securely.'
        ]);
        exit(1);
    }

    return $conn;
}
