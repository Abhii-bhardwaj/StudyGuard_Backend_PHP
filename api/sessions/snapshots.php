<?php
// ============================================================
// api/sessions/snapshots.php — GET /api/sessions/snapshots
// Return all snapshots for a given session.
// Usage: ?session_id=<epoch_ms>
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();
require_method('GET');

// ── Parse session_id from query string ────────────────────────
$sessionId = $_GET['session_id'] ?? null;

if (empty($sessionId) || !is_numeric($sessionId)) {
    json_response(['error' => 'session_id query parameter is required (numeric)'], 400);
}

$sessionId = (int) $sessionId;

try {
    $conn = get_db();

    $user = get_user();
    
    if ($user['role'] === 'user') {
        $stmt = mysqli_prepare($conn, "
            SELECT
                id, session_id, timestamp, dls, tier, focus_score, is_distracted,
                tab_switch_freq, tab_switch_speed, burst_switch_count,
                idle_duration, scroll_irregularity, scroll_jerk,
                keystroke_variance, mouse_velocity, domain_revisit_freq,
                notif_open_speed, time_of_day_weight, app_switch_freq, app_switch_duration,
                created_at
            FROM snapshots
            WHERE session_id = ? AND user_id = ?
            ORDER BY timestamp ASC
            LIMIT 5000
        ");

        mysqli_stmt_bind_param($stmt, "ii", $sessionId, $user['user_id']);
    } else {
        $stmt = mysqli_prepare($conn, "
            SELECT
                id, session_id, timestamp, dls, tier, focus_score, user_id, is_distracted,
                tab_switch_freq, tab_switch_speed, burst_switch_count,
                idle_duration, scroll_irregularity, scroll_jerk,
                keystroke_variance, mouse_velocity, domain_revisit_freq,
                notif_open_speed, time_of_day_weight, app_switch_freq, app_switch_duration,
                created_at
            FROM snapshots
            WHERE session_id = ?
            ORDER BY timestamp ASC
            LIMIT 5000
        ");

        mysqli_stmt_bind_param($stmt, "i", $sessionId);
    }
    
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $snapshots = mysqli_fetch_all($result, MYSQLI_ASSOC);

    json_response(['snapshots' => $snapshots]);
} catch (mysqli_sql_exception $e) {
    error_log("Snapshots Read API Error: " . $e->getMessage());
    json_response(['error' => 'Failed to fetch snapshots'], 500);
}
