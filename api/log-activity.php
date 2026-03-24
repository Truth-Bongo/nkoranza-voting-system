<?php
// api/log-activity.php
// SECURITY PATCH:
//   [1] CORS wildcard replaced with allowlist. This endpoint checks session auth
//       and CSRF tokens, so cross-origin access should only be permitted from the
//       application's own origin.

declare(strict_types=1);

// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

ob_start();

header("Content-Type: application/json");

// [FIX 1] Replace wildcard with allowlist.
// This endpoint requires a valid session and CSRF token, so it must never
// be open to arbitrary cross-origin callers.
require_once __DIR__ . '/../config/constants.php';
$_log_allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [BASE_URL];
$_log_origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($_log_origin, $_log_allowed, true)) {
    header("Access-Control-Allow-Origin: " . $_log_origin);
    header("Access-Control-Allow-Credentials: true");
} else {
    header("Access-Control-Allow-Origin: " . BASE_URL);
}
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function handleError($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error in log-activity: [$errno] $errstr in $errfile on line $errline");
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit;
}
set_error_handler('handleError');

function handleException($e) {
    error_log("Uncaught exception in log-activity: " . $e->getMessage());
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
    exit;
}
set_exception_handler('handleException');

try {
    require_once __DIR__ . '/../config/bootstrap.php';
    require_once APP_ROOT . '/includes/ActivityLogger.php';

    // [FIX] bootstrap.php already calls session_start(); don't call again
    if (session_status() === PHP_SESSION_NONE) { session_start(); }

    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
        exit();
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || !isset($input['activity_type'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit();
    }

    $db     = Database::getInstance()->getConnection();
    $logger = new ActivityLogger($db);

    $first_name = $input['first_name'] ?? ($_SESSION['first_name'] ?? '');
    $last_name  = $input['last_name']  ?? ($_SESSION['last_name']  ?? '');
    $user_name  = trim($first_name . ' ' . $last_name);
    if (empty($user_name)) {
        $user_name = $_SESSION['user_id'] ?? 'Unknown User';
    }

    $details = $input['details'] ?? null;
    if (is_array($details)) {
        $details = json_encode($details);
    }

    $success = $logger->logActivity(
        $input['user_id'] ?? $_SESSION['user_id'],
        $user_name,
        $input['activity_type'],
        $input['description'] ?? $input['activity_type'],
        $details
    );

    ob_end_clean();

    if ($success) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to log activity']);
    }

} catch (Exception $e) {
    error_log("Error in log-activity.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to log activity']);
}
