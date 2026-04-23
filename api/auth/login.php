<?php
// ============================================================
// api/auth/login.php — POST /api/auth/login
// Authenticate a user and return session + user metadata.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();
require_method('POST');

$body = get_json_body();

// ── Validate ──────────────────────────────────────────────────
require_fields($body, ['email', 'password']);

$email    = trim(strtolower($body['email']));
$password = $body['password'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Invalid email format'], 400);
}

try {
    $conn = get_db();

    $stmt = mysqli_prepare($conn, "
        SELECT id, username, email, password_hash, role
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);

    if (!$user) {
        json_response(['error' => 'Invalid email or password'], 401);
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        json_response(['error' => 'Invalid email or password'], 401);
    }

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role']    = $user['role'];

    json_response([
        'ok'   => true,
        'user' => [
            'id'       => (int) $user['id'],
            'username' => $user['username'],
            'email'    => $user['email'],
            'role'     => $user['role'],
        ],
    ]);

} catch (mysqli_sql_exception $e) {
    error_log("Login API Error: " . $e->getMessage());
    json_response(['error' => 'Login failed'], 500);
}
