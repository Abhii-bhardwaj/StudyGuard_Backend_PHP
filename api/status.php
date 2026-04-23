<?php
// ============================================================
// api/status.php — GET /api/status
// Health check + aggregate database statistics
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/db.php';

cors_headers();
require_method('GET');

try {
    $conn = get_db();

    $user = get_user();

    if ($user['role'] === 'user') {
        $sessionStatsQuery = mysqli_prepare($conn, "
            SELECT
                COUNT(*)              AS total_sessions,
                COALESCE(AVG(focus_score), 0) AS avg_focus_score,
                COALESCE(SUM(duration_min), 0) AS total_study_minutes
            FROM sessions
            WHERE focus_score IS NOT NULL AND user_id = ?
        ");
        mysqli_stmt_bind_param($sessionStatsQuery, "i", $user['user_id']);
        mysqli_stmt_execute($sessionStatsQuery);
        $sessionStats = mysqli_fetch_assoc(mysqli_stmt_get_result($sessionStatsQuery));

        $snapCountQuery = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM snapshots WHERE user_id = ?");
        mysqli_stmt_bind_param($snapCountQuery, "i", $user['user_id']);
        mysqli_stmt_execute($snapCountQuery);
        $snapCount = mysqli_fetch_assoc(mysqli_stmt_get_result($snapCountQuery));
    } else {
        $sessionStatsQuery = mysqli_query($conn, "
            SELECT
                COUNT(*)              AS total_sessions,
                COALESCE(AVG(focus_score), 0) AS avg_focus_score,
                COALESCE(SUM(duration_min), 0) AS total_study_minutes
            FROM sessions
            WHERE focus_score IS NOT NULL
        ");
        $sessionStats = mysqli_fetch_assoc($sessionStatsQuery);

        $snapCountQuery = mysqli_query($conn, "SELECT COUNT(*) AS c FROM snapshots");
        $snapCount = mysqli_fetch_assoc($snapCountQuery);
    }

    json_response([
        'status'            => 'ok',
        'totalSessions'     => (int) ($sessionStats['total_sessions'] ?? 0),
        'totalSnapshots'    => (int) ($snapCount['c'] ?? 0),
        'avgFocusScore'     => round((float) ($sessionStats['avg_focus_score'] ?? 0), 1),
        'totalStudyMinutes' => round((float) ($sessionStats['total_study_minutes'] ?? 0), 1),
    ]);
} catch (mysqli_sql_exception $e) {
    error_log("Status API Error: " . $e->getMessage());
    json_response(['error' => 'Database query failed'], 500);
}
