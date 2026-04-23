<?php
// ============================================================
// api/auth/refresh.php — JWT Refresh Token Rotation
// StudyGuard PHP Backend
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

cors_headers();
require_method('POST');

$conn = get_db();
$data = get_json_body();

require_fields($data, ['refresh_token']);

$refresh_token_plain = $data['refresh_token'];
$refresh_token_hash = hash('sha256', $refresh_token_plain);

// 1. Find the refresh token in the database
$stmt = $conn->prepare("SELECT id, user_id, expires_at FROM auth_tokens WHERE token_hash = ?");
$stmt->bind_param('s', $refresh_token_hash);
$stmt->execute();
$result = $stmt->get_result();

$token_row = $result->fetch_assoc();

if (!$token_row) {
    json_response(['error' => 'Invalid refresh token'], 401);
}

// 2. Check if expired
if (strtotime($token_row['expires_at']) < time()) {
    // Delete expired token to clean up
    $del = $conn->prepare("DELETE FROM auth_tokens WHERE id = ?");
    $del->bind_param('i', $token_row['id']);
    $del->execute();
    
    json_response(['error' => 'Refresh token expired'], 401);
}

// 3. Delete the old refresh token (Rotation / One-time use)
$del = $conn->prepare("DELETE FROM auth_tokens WHERE id = ?");
$del->bind_param('i', $token_row['id']);
$del->execute();

// 4. Get User object to mint fresh JWT
$user_stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE id = ?");
$user_stmt->bind_param('i', $token_row['user_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user = $user_result->fetch_assoc();

if (!$user) {
    json_response(['error' => 'User no longer exists'], 401);
}

// 5. Generate NEW internal JWT tokens
$access_payload = [
    'sub' => (string) $user['id'],
    'user_id' => $user['id'],
    'role' => $user['role'],
    'exp' => time() + (60 * 60) // 1 Hour
];

$access_token = generate_jwt($access_payload, JWT_SECRET);

// 6. Generate NEW Refresh Token
$new_refresh_token_plain = bin2hex(random_bytes(32));
$new_refresh_token_hash = hash('sha256', $new_refresh_token_plain);
$expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // +30 Days

$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$new_token_stmt = $conn->prepare("INSERT INTO auth_tokens (user_id, token_hash, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
$new_token_stmt->bind_param('issss', $user['id'], $new_refresh_token_hash, $expires_at, $ip_address, $user_agent);
$new_token_stmt->execute();

// 7. Return payload
json_response([
    'ok' => true,
    'access_token' => $access_token,
    'refresh_token' => $new_refresh_token_plain
]);
