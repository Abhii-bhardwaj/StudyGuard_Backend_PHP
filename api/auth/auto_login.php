<?php
// ==========================================
// ZERO-CLICK AUTO-LOGIN ENDPOINT
// Automatically registers/logs in the user
// based on Chrome Identity profile info.
// ==========================================

require_once '../../config/db.php';
require_once '../../includes/helpers.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$email = $input['email'] ?? '';
$google_id = $input['google_id'] ?? '';

// If we don't even have a Google ID, generate a completely random session ID
if (empty($google_id)) {
    $google_id = 'anon_' . bin2hex(random_bytes(8));
}

// Generate username
if (!empty($email)) {
    $parts = explode('@', $email);
    $username = $parts[0];
} else {
    $username = 'student_' . substr($google_id, 0, 8);
    $email = null;
}

try {
    $conn = get_db();

    // 1. Check if user already exists (by email OR username)
    // If email is null, check by username.
    if ($email) {
        $stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE email = ? OR username = ?");
        $stmt->bind_param("ss", $email, $username);
    } else {
        $stmt = $conn->prepare("SELECT id, username, email, role FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        // 2. User does not exist, auto-register them
        // Generate a random password hash since they won't use it to login
        $random_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        
        // Ensure username is unique by appending a suffix if needed
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $check_stmt->bind_param("s", $username);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $count = $check_result->fetch_row()[0];
        
        if ($count > 0) {
            $username = $username . '_' . rand(100, 999);
        }

        $insert_stmt = $conn->prepare("INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, 'user')");
        // $email could be null, it is legally accepted as a string param in bind_param if null or empty, 
        // but if strictly null, 's' will cast to empty string or null depending on PHP version.
        $safe_email = $email ?: null; 
        $insert_stmt->bind_param("sss", $username, $safe_email, $random_pass);
        $insert_stmt->execute();
        
        $user_id = $conn->insert_id;
        
        $user = [
            'id' => $user_id,
            'username' => $username,
            'email' => $email,
            'role' => 'user'
        ];
    } else {
        $user_id = $user['id'];
    }

    // 3. Issue JWT Access Token
    // Valid for 1 hour
    $access_token = generate_jwt([
        'user_id' => $user_id,
        'role' => $user['role'],
        'exp' => time() + 3600 
    ], JWT_SECRET);

    // 4. Issue Refresh Token
    // Valid for 30 days
    $refresh_token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', time() + (30 * 24 * 60 * 60));
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $token_hash = hash('sha256', $refresh_token);

    $token_stmt = $conn->prepare("
        INSERT INTO auth_tokens (user_id, token_hash, expires_at, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?)
    ");
    $token_stmt->bind_param("issss", $user_id, $token_hash, $expires_at, $ip_address, $user_agent);
    $token_stmt->execute();

    // Return the payload
    echo json_encode([
        'status' => 'success',
        'message' => 'Zero-click authentication successful',
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'user' => [
            'id' => $user_id,
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
