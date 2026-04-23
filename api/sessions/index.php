<?php
// ============================================================
// api/sessions/index.php — GET /api/sessions
// Return last 50 study sessions, newest first.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();
require_method('GET');

try {
    $conn = get_db();

    $user = get_user();

    if ($user['role'] === 'user') {
        $stmt = mysqli_prepare($conn, "
            SELECT
                id, session_id, start_time, end_time,
                duration_min, focus_score, patience_index,
                distraction_pct, intervention_count, longest_streak_min,
                created_at
            FROM sessions
            WHERE user_id = ?
            ORDER BY session_id DESC
            LIMIT 50
        ");
        mysqli_stmt_bind_param($stmt, "i", $user['user_id']);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
    } else {
        $result = mysqli_query($conn, "
            SELECT
                id, session_id, start_time, end_time, user_id,
                duration_min, focus_score, patience_index,
                distraction_pct, intervention_count, longest_streak_min,
                created_at
            FROM sessions
            ORDER BY session_id DESC
            LIMIT 50
        ");
    }

    $sessions = mysqli_fetch_all($result, MYSQLI_ASSOC);

    json_response(['sessions' => $sessions]);
} catch (mysqli_sql_exception $e) {
    error_log("Sessions Index API Error: " . $e->getMessage());
    json_response(['error' => 'Failed to fetch sessions'], 500);
}
