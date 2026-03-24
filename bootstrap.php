<?php
// bootstrap.php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/helpers/functions.php';

// ---- Security settings ---- //
// Disable error display in production
ini_set('display_errors', 0);
error_reporting(0);

// Secure session settings
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,             // session cookie only until browser close
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), // [FIX] isset() is true even when HTTPS=off
        'httponly' => true,          // not accessible via JS
        'samesite' => 'Lax',         // adjust to 'Strict' if no cross-site usage
    ]);
    session_start();
}

// ---- Auto-update election statuses on every page load ----
function updateElectionStatuses($db) {
    try {
        $now = date('Y-m-d H:i:s');
        // [FIX] Each named param must be unique — PDO with ATTR_EMULATE_PREPARES=false
        // throws HY093 if the same name appears more than once in one statement.
        $sql = "UPDATE elections SET status =
            CASE
                WHEN :ct1 < start_date THEN 'upcoming'
                WHEN :ct2 <= end_date THEN 'active'
                ELSE 'ended'
            END
            WHERE status !=
                CASE
                    WHEN :ct3 < start_date THEN 'upcoming'
                    WHEN :ct4 <= end_date THEN 'active'
                    ELSE 'ended'
                END";
        $stmt = $db->prepare($sql);
        $stmt->execute([':ct1' => $now, ':ct2' => $now, ':ct3' => $now, ':ct4' => $now]);
        
        $affected = $stmt->rowCount();
        
        // Log the update only if changes occurred (optional)
        if ($affected > 0) {
            error_log("Election statuses updated: {$affected} elections changed at " . $now);
        }
    } catch (Exception $e) {
        error_log("Election status update error: " . $e->getMessage());
    }
}

// Get database instance and update statuses
try {
    $db = Database::getInstance()->getConnection();
    updateElectionStatuses($db);
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $db = null; // Set to null to prevent errors in authentication functions
}

// Set timezone to match your application
    date_default_timezone_set('Africa/Accra');

// ---- Authentication functions ---- //
if (!function_exists('require_login')) {
    function require_login() {
        // Check for user_id instead of logged_in (more reliable)
        if (empty($_SESSION['user_id'])) {
            $_SESSION['flash_message'] = 'Please login to access this page';
            $_SESSION['flash_type'] = 'error';
            header("Location: " . BASE_URL . "/index.php?page=login");
            exit;
        }
    }
}

if (!function_exists('require_student_login')) {
    function require_student_login() {
        require_login();

        // Restrict admin access
        if (!empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
            header("Location: " . BASE_URL . "/index.php?page=admin/dashboard");
            exit;
        }
    }
}

if (!function_exists('require_admin_login')) {
    function require_admin_login() {
        require_login();

        // Restrict non-admin access
        if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
            $_SESSION['flash_message'] = 'Admin access required';
            $_SESSION['flash_type'] = 'error';
            header("Location: " . BASE_URL . "/index.php?page=vote");
            exit;
        }
    }
}

// ---- Helper function to check login status ----
if (!function_exists('is_logged_in')) {
    function is_logged_in() {
        return !empty($_SESSION['user_id']);
    }
}

if (!function_exists('is_admin')) {
    function is_admin() {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
    }
}

// ---- CSRF Token Management ----
if (!function_exists('ensure_csrf_token')) {
    function ensure_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

// Ensure CSRF token exists
ensure_csrf_token();

// ---- Current User Info ----
if (!function_exists('current_user')) {
    function current_user() {
        if (!is_logged_in()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'first_name' => $_SESSION['first_name'] ?? null,
            'last_name' => $_SESSION['last_name'] ?? null,
            'email' => $_SESSION['email'] ?? null,
            'is_admin' => is_admin()
        ];
    }
}

// ---- Get Session Manager instance ----
if (!function_exists('getSessionManager')) {
    function getSessionManager() {
        global $sessionManager;
        return $sessionManager;
    }
}

// ---- Get active sessions (admin only) ----
if (!function_exists('getActiveSessions')) {
    function getActiveSessions() {
        if (!is_admin()) {
            return [];
        }
        
        $sessionManager = getSessionManager();
        return $sessionManager ? $sessionManager->getActiveSessions() : [];
    }
}