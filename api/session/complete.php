<?php
// ============================================================
// api/session/complete.php — POST /api/session/complete
// Mark a study session as finished by setting end_time.
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
$endTime   = $body['endTime'] ?? date('Y-m-d H:i:s');

// Normalise ISO-8601 strings to MySQL datetime
if (is_string($endTime) && str_contains($endTime, 'T')) {
    $parsed = strtotime($endTime);
    $endTime = $parsed !== false ? date('Y-m-d H:i:s', $parsed) : date('Y-m-d H:i:s');
}

try {
    $conn = get_db();

    $user = get_user();

    $stmt = mysqli_prepare($conn, "
        UPDATE sessions
        SET end_time = ?
        WHERE session_id = ? AND user_id = ?
    ");

    mysqli_stmt_bind_param($stmt, "sii", $endTime, $sessionId, $user['user_id']);
    mysqli_stmt_execute($stmt);

    if (mysqli_stmt_affected_rows($stmt) === 0) {
        json_response(['error' => 'Session not found', 'sessionId' => $sessionId], 404);
    }

    json_response(['ok' => true]);
} catch (mysqli_sql_exception $e) {
    error_log("Complete API Error: " . $e->getMessage());
    json_response(['error' => 'Failed to complete session'], 500);
}
