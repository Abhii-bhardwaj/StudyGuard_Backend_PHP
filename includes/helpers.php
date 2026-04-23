<?php
// ============================================================
// includes/helpers.php — Shared Utility Functions
// StudyGuard PHP Backend
// ============================================================

declare(strict_types=1);

/**
 * Send CORS headers for cross-origin requests from the Chrome Extension.
 * Also handles OPTIONS preflight automatically.
 */
function cors_headers(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-User-Id, X-User-Role');
    header('Access-Control-Max-Age: 86400');
    // Prevent MIME-sniffing XSS
    header('X-Content-Type-Options: nosniff');

    // Handle preflight OPTIONS request
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit(0);
    }
}

/**
 * Returns the current validated user context containing ID and role.
 * Supports explicit headers (used by the Chrome Extension) and PHP Sessions.
 *
 * @return array
 */
function get_user(): array
{
    // 0. Check for strict Authorization Bearer token (JWT)
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (strpos($authHeader, 'Bearer ') === 0) {
        $jwt = substr($authHeader, 7);
        $payload = verify_jwt($jwt, defined('JWT_SECRET') ? JWT_SECRET : 'fallback-secret');
        if ($payload && isset($payload['user_id'])) {
            return [
                'user_id' => (int) $payload['user_id'],
                'role'    => $payload['role'] ?? 'user'
            ];
        } else {
            json_response(['error' => 'Unauthorized: Invalid token'], 401);
        }
    }

    // 1. Check for explicit extension headers first (Legacy Fallback)
    if (isset($_SERVER['HTTP_X_USER_ID'])) {
        return [
            'user_id' => (int) $_SERVER['HTTP_X_USER_ID'],
            'role'    => $_SERVER['HTTP_X_USER_ROLE'] ?? 'user'
        ];
    }

    // 2. Fall back to standard PHP Sessions
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    return [
        'user_id' => isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1,
        'role'    => $_SESSION['role'] ?? 'user'
    ];
}

/**
 * Validates that the current user possesses an allowed role.
 * Halts execution and returns a JSON 403 error if authorization fails.
 *
 * @param array $allowed_roles
 */
function require_role(array $allowed_roles): void
{
    $user = get_user();
    if (!in_array($user['role'], $allowed_roles, true)) {
        json_response(['error' => 'Forbidden: insufficient privileges'], 403);
    }
}

/**
 * Send a JSON response with the given HTTP status code, then exit.
 *
 * @param array|object $data  Payload to encode as JSON
 * @param int          $code  HTTP status code (default 200)
 */
function json_response(array|object $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}

/**
 * Read and decode the raw JSON body from the request.
 *
 * @return array  Decoded associative array (empty array on failure)
 */
function get_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Assert that the request method matches the expected method.
 * Returns a 405 error if it does not.
 *
 * @param string $expected  e.g. 'POST', 'GET'
 */
function require_method(string $expected): void
{
    if ($_SERVER['REQUEST_METHOD'] !== strtoupper($expected)) {
        json_response([
            'error' => "Method not allowed. Expected {$expected}, got {$_SERVER['REQUEST_METHOD']}."
        ], 405);
    }
}

/**
 * Validate that all required keys exist in the given data array.
 * Returns a 400 error listing the missing fields if any are absent.
 *
 * @param array    $data     The input data to validate
 * @param string[] $required Array of required key names
 */
function require_fields(array $data, array $required): void
{
    $missing = [];
    foreach ($required as $field) {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            $missing[] = $field;
        }
    }
    if (!empty($missing)) {
        json_response([
            'error'   => 'Missing required fields',
            'missing' => $missing,
        ], 400);
    }
}

/**
 * Safely cast a value to float, defaulting to 0.0 if not numeric.
 *
 * @param  mixed $val
 * @return float
 */
function safe_float(mixed $val): float
{
    return is_numeric($val) ? (float) $val : 0.0;
}

/**
 * Safely cast a value to int, defaulting to 0 if not numeric.
 *
 * @param  mixed $val
 * @return int
 */
function safe_int(mixed $val): int
{
    return is_numeric($val) ? (int) $val : 0;
}

// ============================================================
// JWT Helpers (Zero Dependency Base64 URL logic)
// ============================================================

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
}

/**
 * Generates an HMAC-SHA256 JWT
 */
function generate_jwt(array $payload, string $secret): string {
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $base64UrlHeader = base64url_encode((string)$header);
    $base64UrlPayload = base64url_encode((string)json_encode($payload));
    
    $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
    $base64UrlSignature = base64url_encode($signature);
    
    return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
}

/**
 * Verifies an HMAC-SHA256 JWT string, handles expiration
 * Returns payload array on success, null on failure
 */
function verify_jwt(string $jwt, string $secret): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    
    list($headb64, $bodyb64, $cryptob64) = $parts;
    
    $signature = base64url_decode($cryptob64);
    $expected_signature = hash_hmac('sha256', $headb64 . "." . $bodyb64, $secret, true);
    
    if (!hash_equals($signature, $expected_signature)) {
        return null;
    }
    
    $payload = json_decode(base64url_decode($bodyb64), true);
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null; // Expired
    }
    
    return $payload;
}
