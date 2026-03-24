<?php
// helpers/functions.php

// Output buffering control
if (!function_exists('start_output_buffering')) {
    function start_output_buffering() {
        if (ob_get_level() === 0) {
            ob_start();
        }
    }
}

if (!function_exists('clean_output_buffer')) {
    function clean_output_buffer() {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }
}

// Session management
if (!function_exists('ensure_session')) {
    function ensure_session() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}

// DEBUG_MODE definition
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// ---------------- CSRF Helpers ---------------- //
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        ensure_session();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('validate_csrf_token')) {
    function validate_csrf_token($token) {
        ensure_session();

        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        $is_valid = hash_equals($_SESSION['csrf_token'], $token);
        if ($is_valid) {
            unset($_SESSION['csrf_token']); // regenerate after use
        }
        return $is_valid;
    }
}

if (!function_exists('request_csrf_token')) {
    function request_csrf_token() {
        return $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    }
}

// ---------------- Input Sanitization ---------------- //
// NOTE: sanitize_input is for light cleanup. Always validate types separately.
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        if (is_array($data)) {
            return array_map('sanitize_input', $data);
        }
        return trim($data);
    }
}

// Escape output safely
if (!function_exists('escape_output')) {
    function escape_output($string) {
        return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ---------------- JSON response helper ---------------- //
if (!function_exists('json_response')) {
    function json_response($success, $message = '', $data = null) {
        clean_output_buffer();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'data'    => $data
        ]);
        exit;
    }
}

// ---------------- Request payload ---------------- //
if (!function_exists('request_payload')) {
    function request_payload() {
        return json_decode(file_get_contents('php://input'), true);
    }
}

// Set timezone to match your application
    date_default_timezone_set('Africa/Accra');

// ---------------- Date formatting ---------------- //
if (!function_exists('formatDate')) {
    function formatDate($date, $format = 'M d, Y H:i') {
        return date($format, strtotime($date));
    }
}

// ---------------- Election status refresher ---------------- //
if (!function_exists('refresh_election_statuses')) {
    function refresh_election_statuses($db) {
        try {
            $now = date('Y-m-d H:i:s');
            // [FIX] Unique param names for PDO strict mode
            $sql = "UPDATE elections SET status =
                CASE
                    WHEN :ct1 < start_date THEN 'upcoming'
                    WHEN :ct2 <= end_date THEN 'active'
                    ELSE 'ended'
                END";
            $stmt = $db->prepare($sql);
            $stmt->execute([':ct1' => $now, ':ct2' => $now]);
            
            $affected = $stmt->rowCount();
            
            // Log if any statuses changed (optional)
            if ($affected > 0 && defined('DEBUG_MODE') && DEBUG_MODE) {
                error_log("Election statuses refreshed: {$affected} elections updated");
            }
            
            return $affected;
        } catch (Exception $e) {
            error_log("Error refreshing election statuses: " . $e->getMessage());
            return 0;
        }
    }
}

// ---------------- Authentication helpers ---------------- //
function require_login() {
    ensure_session();
    if (empty($_SESSION['user_id'])) {
        $_SESSION['flash_message'] = 'Please login to access this page';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . BASE_URL . '/login');
        exit;
    }
}

function require_admin() {
    ensure_session();

    if (empty($_SESSION['user_id'])) {
        $_SESSION['flash_message'] = 'Please login to access this page';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . BASE_URL . '/login');
        exit;
    }

    if (empty($_SESSION['is_admin'])) {
        $_SESSION['flash_message'] = 'Access denied. Admin privileges required';
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . BASE_URL . '/home');
        exit;
    }
}

function is_logged_in() {
    ensure_session();
    return !empty($_SESSION['user_id']);
}

function is_admin() {
    ensure_session();
    return !empty($_SESSION['user_id']) && !empty($_SESSION['is_admin']);
}

// ---------------- Flash message helpers ---------------- //
function set_flash_message($message, $type = 'success') {
    ensure_session();
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
}

function get_flash_message() {
    ensure_session();
    $message = $_SESSION['flash_message'] ?? '';
    $type = $_SESSION['flash_type'] ?? '';
    unset($_SESSION['flash_message'], $_SESSION['flash_type']);
    return ['message' => escape_output($message), 'type' => $type];
}

// ---------------- Redirect helper ---------------- //
function redirect($url) {
    // Allowlist common routes to prevent open redirect
    $allowed_pages = ['home','login','vote','admin/dashboard'];
    $clean_url = ltrim(parse_url($url, PHP_URL_PATH), '/');
    if (!in_array($clean_url, $allowed_pages, true)) {
        $clean_url = 'home';
    }
    header('Location: ' . BASE_URL . '/' . $clean_url);
    exit;
}

// ---------------- Validation helpers ---------------- //
function validate_password($password) {
    // At least 8 chars, uppercase, lowercase, number, special char
    return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/', $password);
}

function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function generate_random_string($length = 10) {
    return bin2hex(random_bytes((int) ceil($length/2)));
}

// ---------------- File upload helper ---------------- //
function handle_file_upload($file, $allowed_types = ['jpg','jpeg','png','webp'], $max_size = 2097152) {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'File upload error'];
    }

    if ($file['size'] > $max_size) {
        return ['success' => false, 'message' => 'File too large'];
    }

    $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($file_ext, $allowed_types, true)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }

    // MIME type check
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $allowed_mimes = ['image/jpeg','image/png','image/webp'];
    if (!in_array($mime, $allowed_mimes, true)) {
        return ['success' => false, 'message' => 'Invalid file content'];
    }

    // Secure random filename
    $new_filename = bin2hex(random_bytes(16)) . '.' . $file_ext;
    $upload_dir = __DIR__ . '/../uploads/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $upload_path = $upload_dir . $new_filename;
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return ['success' => true, 'filename' => $new_filename, 'path' => '/uploads/' . $new_filename];
    }

    return ['success' => false, 'message' => 'Failed to move uploaded file'];
}

// ---------------- Database error handler ---------------- //
function handle_database_error($e) {
    error_log("Database Error: " . $e->getMessage());
    if (DEBUG_MODE) {
        return "Database error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    }
    return "A database error occurred. Please try again later.";
}

// ---------------- Election utilities ---------------- //
function is_election_active($election_id, $db) {
    $stmt = $db->prepare("SELECT status FROM elections WHERE id = :id");
    $stmt->execute([':id' => $election_id]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    return $election && $election['status'] === 'active';
}

function has_user_voted($user_id, $election_id, $db) {
    // [FIX] column is voter_id (not user_id) — matches the votes table schema
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM votes WHERE voter_id = :user_id AND election_id = :election_id");
    $stmt->execute([
        ':user_id' => $user_id,
        ':election_id' => $election_id
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'] > 0;
}

function get_user_votes_count($user_id, $db) {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM votes WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $user_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'];
}

function get_election_results($election_id, $db) {
    $stmt = $db->prepare("
        SELECT c.id, c.name, c.position, COUNT(v.id) as votes
        FROM candidates c
        LEFT JOIN votes v ON c.id = v.candidate_id
        WHERE c.election_id = :election_id
        GROUP BY c.id, c.name, c.position
        ORDER BY c.position, votes DESC
    ");
    $stmt->execute([':election_id' => $election_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_active_elections($db) {
    $stmt = $db->query("SELECT * FROM elections WHERE status = 'active' ORDER BY start_date ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_upcoming_elections($db) {
    $stmt = $db->query("SELECT * FROM elections WHERE status = 'upcoming' ORDER BY start_date ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_closed_elections($db) {
    $stmt = $db->query("SELECT * FROM elections WHERE status = 'ended' ORDER BY end_date DESC"); // [FIX] was 'closed' — system uses 'ended'
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Log user activity
 */
function logActivity($activityType, $description, $details = null) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id']) && isset($_SESSION['username'])) {
        global $db;
        
        // Initialize logger if not already done
        if (!isset($GLOBALS['activityLogger'])) {
            $GLOBALS['activityLogger'] = new ActivityLogger($db);
        }
        
        $GLOBALS['activityLogger']->logActivity(
            $_SESSION['user_id'],
            $_SESSION['username'],
            $activityType,
            $description,
            $details
        );
    }
}

/**
 * Get common activity types
 */
function getActivityTypes() {
    return [
        'login' => 'User Login',
        'logout' => 'User Logout',
        'vote_cast' => 'Vote Cast',
        'election_create' => 'Election Created',
        'election_edit' => 'Election Modified',
        'election_delete' => 'Election Deleted',
        'candidate_add' => 'Candidate Added',
        'candidate_edit' => 'Candidate Modified',
        'candidate_delete' => 'Candidate Deleted',
        'user_create' => 'User Created',
        'user_edit' => 'User Modified',
        'user_delete' => 'User Deleted',
        'results_view' => 'Results Viewed',
        'export' => 'Data Exported',
        'settings_change' => 'Settings Changed',
        'password_change' => 'Password Changed',
        'failed_login' => 'Failed Login Attempt',
        'security' => 'Security Event',
        'admin_action' => 'Admin Action'
    ];
}

/**
 * Generate a deterministic 8-character password based on user data
 * Same input will ALWAYS produce the same password
 * 
 * @param string $firstName User's first name
 * @param string $lastName User's last name
 * @param string $id User's ID
 * @return string 8-character password
 */
function generateSimplePassword($firstName = '', $lastName = '', $id = '') {
    // Character sets (no ambiguous characters)
    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ'; // No I, O
    $lowercase = 'abcdefghijkmnopqrstuvwxyz'; // No l
    $numbers = '23456789'; // No 0,1
    $special = '@#$%&*?';
    
    // Normalize inputs to ensure consistency
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $id = trim($id);
    
    // Create a seed from the user's data using a more robust method
    $seedString = strtolower($firstName . '|' . $lastName . '|' . $id);
    
    // Use crc32 for a consistent integer hash
    $seed = crc32($seedString);
    
    // Use the seed to initialize a deterministic random number generator
    // This ensures the same sequence every time
    srand($seed);
    
    $password = '';
    
    // Add 2 uppercase letters
    for ($i = 0; $i < 2; $i++) {
        $index = rand(0, strlen($uppercase) - 1);
        $password .= $uppercase[$index];
    }
    
    // Add 2 lowercase letters
    for ($i = 0; $i < 2; $i++) {
        $index = rand(0, strlen($lowercase) - 1);
        $password .= $lowercase[$index];
    }
    
    // Add 2 numbers
    for ($i = 0; $i < 2; $i++) {
        $index = rand(0, strlen($numbers) - 1);
        $password .= $numbers[$index];
    }
    
    // Add 2 special characters
    for ($i = 0; $i < 2; $i++) {
        $index = rand(0, strlen($special) - 1);
        $password .= $special[$index];
    }
    
    // Shuffle the password deterministically using the same seed
    $passwordArray = str_split($password);
    
    // Fisher-Yates shuffle with deterministic rand
    for ($i = count($passwordArray) - 1; $i > 0; $i--) {
        $j = rand(0, $i);
        // Swap
        $temp = $passwordArray[$i];
        $passwordArray[$i] = $passwordArray[$j];
        $passwordArray[$j] = $temp;
    }
    
    // Reset random generator to avoid affecting other parts of the script
    srand();
    
    return implode('', $passwordArray);
}

/**
 * Check if a user is eligible to vote
 */
function isEligibleVoter($user_id, $election_id, $db) {
    // Get election year for graduation check
    $elStmt = $db->prepare("SELECT YEAR(end_date) as year FROM elections WHERE id = ?");
    $elStmt->execute([$election_id]);
    $election_year = $elStmt->fetchColumn();

    // Check user status
    $stmt = $db->prepare("SELECT status, graduation_year, is_admin FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) return false;
    if ($user['is_admin']) return false;
    // Allow 'graduated' status users to be blocked; 'active' required
    if ($user['status'] !== 'active') return false;
    if ($user['graduation_year'] && $user['graduation_year'] <= $election_year) return false;

    // Check votes table for THIS specific election — not global has_voted flag
    // This ensures the eligibility check works correctly across multiple election years
    $vStmt = $db->prepare(
        "SELECT COUNT(*) FROM votes WHERE voter_id = ? AND election_id = ?"
    );
    $vStmt->execute([$user_id, $election_id]);
    if ((int)$vStmt->fetchColumn() > 0) return false;

    return true;
}
