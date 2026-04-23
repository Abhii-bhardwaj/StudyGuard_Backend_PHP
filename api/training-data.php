<?php
// ============================================================
// api/training-data.php — GET /api/training-data
// Export feature vectors for ML retraining.
// Returns all 13 ONNX features + DLS + computed label.
// Label: 1 = distracted (DLS ≥ 0.5), 0 = focused (DLS < 0.5)
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../config/db.php';

cors_headers();
require_method('GET');
require_role(['admin', 'researcher']);

try {
    $conn = get_db();

    $stmt = mysqli_query($conn, "
        SELECT
            tab_switch_freq,
            tab_switch_speed,
            burst_switch_count,
            idle_duration,
            scroll_irregularity,
            scroll_jerk,
            keystroke_variance,
            mouse_velocity,
            domain_revisit_freq,
            notif_open_speed,
            time_of_day_weight,
            app_switch_freq,
            app_switch_duration,
            dls,
            CASE WHEN dls >= 0.50 THEN 1 ELSE 0 END AS label
        FROM snapshots
        ORDER BY id DESC
        LIMIT 5000
    ");

    $data = mysqli_fetch_all($stmt, MYSQLI_ASSOC);

    json_response([
        'count' => count($data),
        'data'  => $data,
    ]);
} catch (mysqli_sql_exception $e) {
    error_log("Training Data API Error: " . $e->getMessage());
    json_response(['error' => 'Failed to fetch training data'], 500);
}
