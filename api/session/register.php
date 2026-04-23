<?php
// ============================================================
// api/session/register.php — POST /api/session/register
// Register (or ignore duplicate of) a new study session.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();
require_method('POST');

$body = get_json_body();

// ── Validate ──────────────────────────────────────────────────
if (empty($body['sessionId'])) {
    json_response(['error' => 'sessionId is required'], 400);
}

$sessionId = (int) $body['sessionId'];
$startTime = $body['startTime'] ?? date('Y-m-d H:i:s');

// Normalise ISO-8601 strings to MySQL datetime
if (is_string($startTime) && str_contains($startTime, 'T')) {
    $parsed = strtotime($startTime);
    $startTime = $parsed !== false ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s');
}

try {
    $conn = get_db();

    $user = get_user();
    
    // INSERT … ON DUPLICATE KEY UPDATE preserves existing row
    $stmt = mysqli_prepare($conn, "
        INSERT INTO sessions (user_id, session_id, start_time, duration_min, focus_score,
            patience_index, distraction_pct, intervention_count, longest_streak_min)
        VALUES (?, ?, ?, 0, NULL, NULL, NULL, 0, 0)
        ON DUPLICATE KEY UPDATE start_time = start_time
    ");

    mysqli_stmt_bind_param($stmt, "iis", $user['user_id'], $sessionId, $startTime);
    mysqli_stmt_execute($stmt);

    json_response(['ok' => true, 'sessionId' => $sessionId]);
} catch (mysqli_sql_exception $e) {
    error_log("Register API Error: " . $e->getMessage());
    json_response(['error' => 'Failed to register session'], 500);
}
