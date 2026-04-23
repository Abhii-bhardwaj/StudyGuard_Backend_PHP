<?php
// ============================================================
// api/auth/register.php — POST /api/auth/register
// Register a new user account.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();
require_method('POST');

$body = get_json_body();

// ── Validate ──────────────────────────────────────────────────
require_fields($body, ['name', 'email', 'password']);

$name     = trim($body['name']);
$email    = trim(strtolower($body['email']));
$password = $body['password'];

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_response(['error' => 'Invalid email format'], 400);
}

// Validate name length
if (strlen($name) < 2 || strlen($name) > 50) {
    json_response(['error' => 'Name must be between 2 and 50 characters'], 400);
}

// Validate password strength
if (strlen($password) < 6) {
    json_response(['error' => 'Password must be at least 6 characters'], 400);
}

try {
    $conn = get_db();

    // Check if email already exists
    $checkStmt = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
    mysqli_stmt_bind_param($checkStmt, "s", $email);
    mysqli_stmt_execute($checkStmt);
    $checkResult = mysqli_stmt_get_result($checkStmt);

    if (mysqli_fetch_assoc($checkResult)) {
        json_response(['error' => 'An account with this email already exists'], 409);
    }

    // Check if username already exists
    $checkUser = mysqli_prepare($conn, "SELECT id FROM users WHERE username = ?");
    mysqli_stmt_bind_param($checkUser, "s", $name);
    mysqli_stmt_execute($checkUser);
    $checkUserResult = mysqli_stmt_get_result($checkUser);

    if (mysqli_fetch_assoc($checkUserResult)) {
        json_response(['error' => 'Username already taken'], 409);
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Insert new user
    $stmt = mysqli_prepare($conn, "
        INSERT INTO users (username, password_hash, email, role)
        VALUES (?, ?, ?, 'user')
    ");
    mysqli_stmt_bind_param($stmt, "sss", $name, $passwordHash, $email);
    mysqli_stmt_execute($stmt);

    $userId = mysqli_insert_id($conn);

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['user_id'] = $userId;
    $_SESSION['role']    = 'user';

    json_response([
        'ok'   => true,
        'user' => [
            'id'       => $userId,
            'username' => $name,
            'email'    => $email,
            'role'     => 'user',
        ],
    ]);

} catch (mysqli_sql_exception $e) {
    error_log("Register API Error: " . $e->getMessage());
    json_response(['error' => 'Registration failed'], 500);
}
