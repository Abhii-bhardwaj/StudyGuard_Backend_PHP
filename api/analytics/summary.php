<?php
// ============================================================
// api/analytics/summary.php — GET /api/analytics/summary
// Return last 30 sessions with computed average focus score.
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
        $result = mysqli_query($conn, "
            SELECT
                id, session_id, start_time, end_time,
                duration_min, focus_score, patience_index,
                distraction_pct, intervention_count, longest_streak_min,
                created_at
            FROM sessions
            WHERE user_id = {$user['user_id']}
            ORDER BY id DESC
            LIMIT 30
        ");
        $sessions = mysqli_fetch_all($result, MYSQLI_ASSOC);

        // Calculate User Analytics
        $snapQuery = mysqli_query($conn, "
            SELECT COUNT(*) as total_snaps, SUM(is_distracted) as total_distracted 
            FROM snapshots 
            WHERE user_id = {$user['user_id']}
        ");
        $snapStats = mysqli_fetch_assoc($snapQuery);
        $totalSnaps = (int) ($snapStats['total_snaps'] ?? 0);
        $totalDistracted = (int) ($snapStats['total_distracted'] ?? 0);
        $distractionPercentage = $totalSnaps > 0 ? ($totalDistracted / $totalSnaps) * 100 : 0;

        $analytics = [
            'totalSnapshots' => $totalSnaps,
            'totalDistractedEvents' => $totalDistracted,
            'distractionPercentage' => round($distractionPercentage, 2)
        ];

    } else {
        $result = mysqli_query($conn, "
            SELECT
                id, session_id, start_time, end_time, user_id,
                duration_min, focus_score, patience_index,
                distraction_pct, intervention_count, longest_streak_min,
                created_at
            FROM sessions
            ORDER BY id DESC
            LIMIT 30
        ");
        $sessions = mysqli_fetch_all($result, MYSQLI_ASSOC);

        // Calculate Global Analytics for researchers and admins
        $snapQuery = mysqli_query($conn, "
            SELECT COUNT(*) as total_snaps, SUM(is_distracted) as total_distracted 
            FROM snapshots
        ");
        $snapStats = mysqli_fetch_assoc($snapQuery);
        $totalSnaps = (int) ($snapStats['total_snaps'] ?? 0);
        $totalDistracted = (int) ($snapStats['total_distracted'] ?? 0);
        $avgDistractionAcrossUsers = $totalSnaps > 0 ? ($totalDistracted / $totalSnaps) * 100 : 0;

        $analytics = [
            'totalSnapshotsGlobal' => $totalSnaps,
            'totalDistractedEventsGlobal' => $totalDistracted,
            'averageDistractionPercentage' => round($avgDistractionAcrossUsers, 2)
        ];
    }

    // Compute average focus score in PHP (matching Node.js behavior)
    $validScores = array_filter(
        array_column($sessions, 'focus_score'),
        fn($s) => $s !== null
    );
    $avgFocusScore = count($validScores) > 0
        ? round(array_sum($validScores) / count($validScores), 1)
        : 0;

    json_response([
        'sessions'       => $sessions,
        'avgFocusScore'  => $avgFocusScore,
        'totalSessions'  => count($sessions),
        'analytics'      => $analytics
    ]);
} catch (mysqli_sql_exception $e) {
    error_log("Analytics Summary API Error: " . $e->getMessage());
    json_response(['error' => 'Failed to fetch analytics'], 500);
}
