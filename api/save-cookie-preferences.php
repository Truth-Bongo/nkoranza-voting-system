<?php
// api/save-cookie-preferences.php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db_connect.php';

header('Content-Type: application/json');

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Validate CSRF token
$headers = function_exists('getallheaders') ? getallheaders() : [];
$token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? '';

if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

// Save preferences to session
$_SESSION['cookie_preferences'] = $input;

// Also set a cookie that lasts 6 months
setcookie('cookie_preferences', json_encode($input), time() + (86400 * 180), '/');

echo json_encode(['success' => true, 'message' => 'Cookie preferences saved']);