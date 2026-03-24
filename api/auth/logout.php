<?php
// api/auth/logout.php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Initialize database and logger
$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);

// Log logout activity if user was logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $first_name = $_SESSION['first_name'] ?? 'Unknown';
    $last_name = $_SESSION['last_name'] ?? 'User';
    $is_admin = $_SESSION['is_admin'] ?? false;
    
    $activityLogger->logActivity(
        $user_id, 
        $first_name, 
        $last_name, 
        'LOGOUT', 
        "User logged out" . ($is_admin ? " (Admin)" : "")
    );
}

// Destroy all session data
$_SESSION = array();

// Delete the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to login page with clear_storage parameter
header('Location: ' . BASE_URL . '/index.php?page=login&clear_storage=1');
exit;
?>