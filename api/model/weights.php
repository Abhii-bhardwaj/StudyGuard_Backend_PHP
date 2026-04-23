<?php
// ============================================================
// api/model/weights.php — GET + POST /api/model/weights
// GET:  Return latest model weights (extension polls this)
// POST: Insert new weight row after validation
// ============================================================

declare(strict_types=1);

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../config/db.php';

cors_headers();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $conn = get_db();

    // ────────────────────────────────────────────────────────────
    // GET — Return the most recent weights row
    // ────────────────────────────────────────────────────────────
    if ($method === 'GET') {

        $result = mysqli_query($conn, "
            SELECT * FROM model_weights ORDER BY id DESC LIMIT 1
        ");
        $row = mysqli_fetch_assoc($result);

        if (!$row) {
            json_response(['error' => 'No weights found'], 404);
        }

        json_response([
            'weights' => [
                'tabSwitchFreq'     => (float) $row['tab_switch_freq'],
                'idleDuration'      => (float) $row['idle_duration'],
                'scrollIrregularity'=> (float) $row['scroll_irregularity'],
                'keystrokeVariance' => (float) $row['keystroke_variance'],
                'domainRevisitFreq' => (float) $row['domain_revisit_freq'],
                'timeOfDayWeight'   => (float) $row['time_of_day_weight'],
            ],
            'updatedAt' => $row['updated_at'],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // POST — Insert new weights (called after ML retraining)
    // ────────────────────────────────────────────────────────────
    elseif ($method === 'POST') {
        require_role(['admin', 'researcher']);

        $body = get_json_body();

        // Accept both camelCase and snake_case
        $w = [
            'tab_switch_freq'     => safe_float($body['tabSwitchFreq']     ?? $body['tab_switch_freq']     ?? null),
            'idle_duration'       => safe_float($body['idleDuration']      ?? $body['idle_duration']       ?? null),
            'scroll_irregularity' => safe_float($body['scrollIrregularity']?? $body['scroll_irregularity'] ?? null),
            'keystroke_variance'  => safe_float($body['keystrokeVariance'] ?? $body['keystroke_variance']  ?? null),
            'domain_revisit_freq' => safe_float($body['domainRevisitFreq'] ?? $body['domain_revisit_freq'] ?? null),
            'time_of_day_weight'  => safe_float($body['timeOfDayWeight']   ?? $body['time_of_day_weight']  ?? null),
        ];

        // Validate: all fields must be present and between 0 and 1
        foreach ($w as $key => $val) {
            if ($val < 0 || $val > 1) {
                json_response([
                    'error' => "Weight '{$key}' must be between 0 and 1, got {$val}"
                ], 400);
            }
        }

        // Validate: sum should be approximately 1.0 (allow 0.8–1.2)
        $sum = array_sum($w);
        if ($sum < 0.8 || $sum > 1.2) {
            json_response([
                'error' => sprintf(
                    'Weights must sum to approximately 1.0 (got %.2f). Acceptable range: 0.80 – 1.20.',
                    $sum
                ),
            ], 400);
        }

        $stmt = mysqli_prepare($conn, "
            INSERT INTO model_weights
                (tab_switch_freq, idle_duration, scroll_irregularity,
                 keystroke_variance, domain_revisit_freq, time_of_day_weight)
            VALUES
                (?, ?, ?, ?, ?, ?)
        ");

        mysqli_stmt_bind_param($stmt, 
            "dddddd",
            $w['tab_switch_freq'],
            $w['idle_duration'],
            $w['scroll_irregularity'],
            $w['keystroke_variance'],
            $w['domain_revisit_freq'],
            $w['time_of_day_weight']
        );
        mysqli_stmt_execute($stmt);

        json_response([
            'ok'      => true,
            'weights' => [
                'tabSwitchFreq'     => $w['tab_switch_freq'],
                'idleDuration'      => $w['idle_duration'],
                'scrollIrregularity'=> $w['scroll_irregularity'],
                'keystrokeVariance' => $w['keystroke_variance'],
                'domainRevisitFreq' => $w['domain_revisit_freq'],
                'timeOfDayWeight'   => $w['time_of_day_weight'],
            ],
        ]);
    }

    // ────────────────────────────────────────────────────────────
    // Any other method → 405
    // ────────────────────────────────────────────────────────────
    else {
        json_response(['error' => 'Method not allowed. Use GET or POST.'], 405);
    }

} catch (mysqli_sql_exception $e) {
    error_log("Weights API Error: " . $e->getMessage());
    json_response(['error' => 'Weights operation failed'], 500);
}
