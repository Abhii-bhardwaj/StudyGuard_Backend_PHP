<?php
// ============================================================
// api/session/snapshot.php — POST /api/session/snapshot
// Receives live behavioral data from the Chrome Extension.
// Stores ALL 13 ONNX features + DLS + tier into snapshots,
// and upserts session aggregate fields.
//
// This is the MOST CRITICAL endpoint in the entire backend.
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();
require_method('POST');

$body = get_json_body();

// ── Debug Logging (Phase 2) ───────────────────────────────────
// Log every incoming snapshot payload for troubleshooting.
// Writes to php-backend/debug.log — tail this file during testing.
$logFile = __DIR__ . '/../../debug.log';
$logEntry = [
    'time'       => date('Y-m-d H:i:s'),
    'sessionId'  => $body['sessionId'] ?? 'MISSING',
    'dls'        => $body['currentDLS'] ?? $body['dls'] ?? 'MISSING',
    'tier'       => $body['currentTier'] ?? $body['tier'] ?? 'MISSING',
    'hasFeatures'=> isset($body['features']),
    'featureKeys'=> isset($body['features']) ? array_keys($body['features']) : [],
    'totalKeys'  => count($body),
];
// file_put_contents($logFile, json_encode($logEntry) . PHP_EOL, FILE_APPEND);

// ── Strict Validation ─────────────────────────────────────────
if (!isset($body['currentDLS']) && !isset($body['dls'])) {
    json_response(['error' => 'Distraction Level Score (dls) is required for ML integrity'], 400);
}
if (empty($body['sessionId']) && empty($body['timestamp'])) {
    json_response(['error' => 'Either sessionId or timestamp must be provided'], 400);
}

// ── Extract top-level fields ──────────────────────────────────
$currentDLS             = safe_float($body['currentDLS'] ?? $body['dls'] ?? 0);
$currentTier            = safe_int($body['currentTier'] ?? $body['tier'] ?? 0);
$focusScore             = isset($body['focusScore'])   ? safe_float($body['focusScore'])   : null;
$patienceIndex          = isset($body['patienceIndex']) ? safe_float($body['patienceIndex']) : null;
$distractionPercentage  = isset($body['distractionPercentage']) ? safe_float($body['distractionPercentage']) : null;
$longestStreak          = safe_float($body['longestStreak'] ?? 0);
$interventionCount      = safe_int($body['interventionCount'] ?? 0);
$sessionDuration        = safe_float($body['sessionDuration'] ?? 0);

// Handle 13-digit epoch timestamps without (int) cast to prevent 32-bit PHP overflows
$timestamp = isset($body['timestamp']) && is_numeric($body['timestamp']) 
    ? (string) $body['timestamp'] 
    : (string) (time() * 1000);

$sessionId = isset($body['sessionId']) && is_numeric($body['sessionId'])
    ? (string) $body['sessionId']
    : (string) ((float)$timestamp - ($sessionDuration * 60000));

if ((float)$sessionId <= 0) {
    json_response(['error' => 'Unable to determine sessionId'], 400);
}

// ── Extract features (nested or flat) ─────────────────────────
$features = $body['features'] ?? $body;

$tabSwitchFreq     = safe_float($features['tabSwitchFreq']     ?? $features['tab_switch_freq']     ?? 0);
$tabSwitchSpeed    = safe_float($features['tabSwitchSpeed']    ?? $features['tab_switch_speed']    ?? 0);
$burstSwitchCount  = safe_float($features['burstSwitchCount']  ?? $features['burst_switch_count']  ?? 0);
$idleDuration      = safe_float($features['idleDuration']      ?? $features['idle_duration']      ?? 0);
$scrollIrregularity= safe_float($features['scrollIrregularity']?? $features['scroll_irregularity']?? 0);
$scrollJerk        = safe_float($features['scrollJerk']        ?? $features['scroll_jerk']        ?? 0);
$keystrokeVariance = safe_float($features['keystrokeVariance'] ?? $features['keystroke_variance'] ?? 0);
$mouseVelocity     = safe_float($features['mouseVelocity']     ?? $features['mouse_velocity']     ?? 0);
$domainRevisitFreq = safe_float($features['domainRevisitFreq'] ?? $features['domain_revisit_freq'] ?? 0);
$notifOpenSpeed    = safe_float($features['notifOpenSpeed']    ?? $features['notif_open_speed']    ?? 0);
$timeOfDayWeight   = safe_float($features['timeOfDayWeight']   ?? $features['time_of_day_weight']  ?? 0);
$appSwitchFreq     = safe_float($features['appSwitchFreq']     ?? $features['app_switch_freq']     ?? 0);
$appSwitchDuration = safe_float($features['appSwitchDuration'] ?? $features['app_switch_duration'] ?? 0);

$user = get_user();
$userId = $user['user_id'];
$isDistracted = $currentDLS >= 0.50 ? 1 : 0;

try {
    $conn = get_db();
    mysqli_begin_transaction($conn);

    // ── 1. Upsert session aggregates ──────────────────────────
    $stmtSession = mysqli_prepare($conn, "
        INSERT INTO sessions
            (user_id, session_id, start_time, duration_min, focus_score, patience_index,
             distraction_pct, intervention_count, longest_streak_min)
        VALUES
            (?, ?, ?, ?, ?, ?,
             ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            duration_min       = VALUES(duration_min),
            focus_score        = VALUES(focus_score),
            patience_index     = VALUES(patience_index),
            distraction_pct    = VALUES(distraction_pct),
            intervention_count = VALUES(intervention_count),
            longest_streak_min = VALUES(longest_streak_min)
    ");

    $startTime = date('Y-m-d H:i:s', (int) ((float)$sessionId / 1000));
    mysqli_stmt_bind_param($stmtSession, 
        "issddddid",
        $userId,
        $sessionId,
        $startTime,
        $sessionDuration,
        $focusScore,
        $patienceIndex,
        $distractionPercentage,
        $interventionCount,
        $longestStreak
    );
    mysqli_stmt_execute($stmtSession);

    // ── 2. Insert snapshot (all 13 features) ──────────────────
    $stmtSnap = mysqli_prepare($conn, "
        INSERT INTO snapshots
            (user_id, session_id, timestamp, dls, tier, focus_score, is_distracted,
             tab_switch_freq, tab_switch_speed, burst_switch_count,
             idle_duration, scroll_irregularity, scroll_jerk,
             keystroke_variance, mouse_velocity, domain_revisit_freq,
             notif_open_speed, time_of_day_weight, app_switch_freq, app_switch_duration)
        VALUES
            (?, ?, ?, ?, ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?,
             ?, ?, ?, ?)
    ");

    $snapFocusScore = $focusScore ?? (100 - ($currentDLS * 100));
    
    mysqli_stmt_bind_param($stmtSnap, 
        "issdididdddddddddddd",
        $userId,                    // i
        $sessionId,                 // s
        $timestamp,                 // s
        $currentDLS,                // d
        $currentTier,               // i
        $snapFocusScore,            // d
        $isDistracted,              // i
        $tabSwitchFreq,             // d
        $tabSwitchSpeed,            // d
        $burstSwitchCount,          // d
        $idleDuration,              // d
        $scrollIrregularity,        // d
        $scrollJerk,                // d
        $keystrokeVariance,         // d
        $mouseVelocity,             // d
        $domainRevisitFreq,         // d
        $notifOpenSpeed,            // d
        $timeOfDayWeight,           // d
        $appSwitchFreq,             // d
        $appSwitchDuration          // d
    );
    mysqli_stmt_execute($stmtSnap);

    mysqli_commit($conn);

    json_response(['ok' => true]);
} catch (mysqli_sql_exception $e) {
    if (isset($conn)) {
        mysqli_rollback($conn);
    }
    error_log("Snapshot API Error: " . $e->getMessage());
    json_response(['error' => 'Snapshot insert failed'], 500);
}
