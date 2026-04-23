<?php
// ============================================================
// api/auth/google.php — Google OAuth2 Login / Register
// StudyGuard PHP Backend
// ============================================================

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/helpers.php';

cors_headers();
require_method('POST');

$conn = get_db();
$data = get_json_body();

require_fields($data, ['google_token']);

// 1. Verify the Google Access Token
$google_token = $data['google_token'];
$url = "https://www.googleapis.com/oauth2/v3/userinfo";
$options = [
    'http' => [
        'header'  => "Authorization: Bearer " . $google_token,
        'method'  => 'GET',
        'ignore_errors' => true // Don't crash on 401
    ]
];
$context = stream_context_create($options);
$response = file_get_contents($url, false, $context);

if ($response === false) {
    json_response(['error' => 'Failed to connect to Google API'], 502);
}

$user_info = json_decode($response, true);

if (isset($user_info['error']) || !isset($user_info['email'])) {
    json_response(['error' => 'Invalid Google token'], 401);
}

$email = $user_info['email'];
$name = $user_info['name'] ?? explode('@', $email)[0]; // Fallback if no name

// 2. See if user exists in DB
$stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE email = ?");
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();

$user = $result->fetch_assoc();

if (!$user) {
    // 3. User doesn't exist, auto-register them
    // Generate a secure random password since they login via Google
    $random_password = bin2hex(random_bytes(16));
    $hashed_password = password_hash($random_password, PASSWORD_BCRYPT);
    
    $insert_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
    $insert_stmt->bind_param('sss', $name, $email, $hashed_password);
    
    if (!$insert_stmt->execute()) {
         json_response(['error' => 'Failed to create user account'], 500);
    }
    
    $user_id = $insert_stmt->insert_id;
    $user = [
        'id' => $user_id,
        'username' => $name,
        'email' => $email,
        'role' => 'user'
    ];
}

// 4. Generate internal JWT tokens
$access_payload = [
    'sub' => (string) $user['id'], // Standard subject claim
    'user_id' => $user['id'],
    'role' => $user['role'],
    'exp' => time() + (60 * 60) // 1 Hour Access Token
];

$access_token = generate_jwt($access_payload, JWT_SECRET);

// 5. Generate Refresh Token
$refresh_token_plain = bin2hex(random_bytes(32));
$refresh_token_hash = hash('sha256', $refresh_token_plain);
$expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60)); // 30 Days

$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

$token_stmt = $conn->prepare("INSERT INTO auth_tokens (user_id, token_hash, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
$token_stmt->bind_param('issss', $user['id'], $refresh_token_hash, $expires_at, $ip_address, $user_agent);
$token_stmt->execute();

// 6. Return response
json_response([
    'ok' => true,
    'message' => 'Signed in successfully via Google',
    'access_token' => $access_token,
    'refresh_token' => $refresh_token_plain,
    'user' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role']
    ]
]);
