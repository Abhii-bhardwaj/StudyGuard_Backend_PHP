<?php
// ============================================================
// Root Index File - StudyGuard Backend
// ============================================================

header('Content-Type: application/json; charset=utf-8');

// Optionally allow CORS for the root ping if needed
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// Return a simple JSON response indicating the API is running
echo json_encode([
    'status' => 'success',
    'message' => 'StudyGuard Backend API is running.',
    'docs' => 'API endpoints are located under /api/',
    'timestamp' => date('c')
]);
exit;
