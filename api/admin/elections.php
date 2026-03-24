<?php
// api/admin/elections.php - WITH SYSTEM TIME INTEGRATION

// Enable error reporting to file
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// [FIX] Debug logging gated behind DEBUG_MODE to prevent filling disk in production
$debugLog = __DIR__ . '/../../logs/api_debug.log';
if (!defined('DEBUG_MODE')) define('DEBUG_MODE', false);
if (DEBUG_MODE) {
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " =========== REQUEST STARTED ===========\n", FILE_APPEND);
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " Method: " . ($_SERVER['REQUEST_METHOD'] ?? 'unknown') . "\n", FILE_APPEND);
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " URI: " . ($_SERVER['REQUEST_URI'] ?? 'unknown') . "\n", FILE_APPEND);
}

// Start output buffering
ob_start();

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Set timezone
date_default_timezone_set('Africa/Accra');

// Response function with debug
function sendResponse($data, $code = 200) {
    global $debugLog;
    
    // Log the response
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " Response code: $code\n", FILE_APPEND);
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " Response data: " . json_encode($data) . "\n", FILE_APPEND);
    file_put_contents($debugLog, date('Y-m-d H:i:s') . " =========== REQUEST ENDED ===========\n\n", FILE_APPEND);
    
    ob_end_clean();
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// [FIX] debugLog is now a no-op unless DEBUG_MODE is true
function debugLog($message, $data = null) {
    if (!DEBUG_MODE) return;
    global $debugLog;
    $logEntry = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $logEntry .= ": " . (is_array($data) || is_object($data) ? json_encode($data) : $data);
    }
    file_put_contents($debugLog, $logEntry . "\n", FILE_APPEND);
}

try {
    debugLog("Loading required files");
    
    require_once __DIR__ . '/../../config/constants.php';
    debugLog("constants.php loaded");
    
    require_once __DIR__ . '/../../config/db_connect.php';
    debugLog("db_connect.php loaded");
    
    require_once __DIR__ . '/../../includes/ActivityLogger.php';
    debugLog("ActivityLogger.php loaded");
    
    require_once __DIR__ . '/../../helpers/functions.php';
    debugLog("functions.php loaded");
    
    debugLog("Getting database connection");
    $db = Database::getInstance()->getConnection();
    debugLog("Database connection successful");
    
    debugLog("Creating ActivityLogger");
    $activityLogger = new ActivityLogger($db);
    debugLog("ActivityLogger created");
    
    // Fetch system voting times
    debugLog("Fetching system voting times");
    $settingsStmt = $db->query("
        SELECT setting_key, setting_value 
        FROM settings 
        WHERE setting_key IN ('voting_start_time', 'voting_end_time', 'current_academic_year')
    ");
    $settings = [];
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $voting_start_time = $settings['voting_start_time'] ?? '08:00:00';
    $voting_end_time = $settings['voting_end_time'] ?? '17:00:00';
    $current_academic_year = $settings['current_academic_year'] ?? date('Y');
    
    debugLog("System voting times", [
        'start' => $voting_start_time,
        'end' => $voting_end_time,
        'academic_year' => $current_academic_year
    ]);
    
} catch (Exception $e) {
    debugLog("ERROR in initialization: " . $e->getMessage());
    debugLog("Stack trace: " . $e->getTraceAsString());
    
    ob_end_clean();
    http_response_code(500);
    // [FIX] Never expose exception message, file, or line to client
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
    exit;
}

// Log session info
debugLog("Session data", [
    'is_admin' => $_SESSION['is_admin'] ?? 'not set',
    'user_id' => $_SESSION['user_id'] ?? 'not set',
    'csrf_token_exists' => isset($_SESSION['csrf_token'])
]);

// Admin-only guard
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    debugLog("Admin check failed");
    sendResponse(['success' => false, 'message' => 'Forbidden: admin only'], 403);
}

debugLog("Admin check passed");

// Method normalization
$method = $_SERVER['REQUEST_METHOD'];
debugLog("Original method", $method);

// Check for method override
if ($method === 'POST' && isset($_POST['_method'])) {
    $override = strtoupper(trim($_POST['_method']));
    if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
        $method = $override;
        debugLog("Method overridden to", $method);
    }
}

debugLog("Final method", $method);

// CSRF validation
function getCsrfTokenFromRequest() {
    $token = '';
    
    // Check headers
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? '';
        debugLog("CSRF from headers", $token);
    }
    
    // Check JSON body
    if (!$token) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (is_array($input) && isset($input['csrf_token'])) {
            $token = $input['csrf_token'];
            debugLog("CSRF from JSON body", $token);
        }
    }
    
    // Check POST data
    if (!$token && isset($_POST['csrf_token'])) {
        $token = $_POST['csrf_token'];
        debugLog("CSRF from POST", $token);
    }
    
    return $token;
}

if ($method !== 'GET') {
    debugLog("Validating CSRF token");
    $token = getCsrfTokenFromRequest();
    
    debugLog("Session CSRF token", $_SESSION['csrf_token'] ?? 'not set');
    debugLog("Received CSRF token", $token);
    
    if (empty($_SESSION['csrf_token']) || empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        debugLog("CSRF validation failed");
        sendResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
    }
    
    debugLog("CSRF validation passed");
}

// Auto-update election statuses
try {
    debugLog("Updating election statuses");
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $stmt = $db->prepare("
        UPDATE elections SET status = CASE
            WHEN :ct1 < start_date THEN 'upcoming'
            WHEN :ct2 <= end_date THEN 'active'
            ELSE 'ended'
        END
    ");
    $stmt->execute([':ct1' => $now, ':ct2' => $now]);
    debugLog("Status update complete");
} catch (Exception $e) {
    debugLog("Status update error: " . $e->getMessage());
}

// Helper functions
function getJsonInput() {
    $raw = file_get_contents('php://input');
    debugLog("Raw input", $raw);
    
    if (empty($raw)) return null;
    
    $arr = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        debugLog("JSON parse error", json_last_error_msg());
        parse_str($raw, $parsed);
        return $parsed;
    }
    
    return is_array($arr) ? $arr : null;
}

function normalizeDatetime($v) {
    debugLog("Normalizing datetime", $v);
    if (!$v) return null;
    $v = str_replace('T', ' ', $v);
    try {
        $dt = new DateTime($v, new DateTimeZone('Africa/Accra'));
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        debugLog("Datetime normalization error", $e->getMessage());
        return null;
    }
}

// Routing
$id = $_GET['id'] ?? null;
debugLog("Election ID from request", $id);

// GET - Retrieve elections
if ($method === 'GET') {
    debugLog("Processing GET request");
    
    try {
        if ($id) {
            debugLog("Fetching single election", $id);
            $stmt = $db->prepare("SELECT * FROM elections WHERE id = ? LIMIT 1");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$row) {
                debugLog("Election not found", $id);
                sendResponse(['success' => false, 'message' => 'Not found'], 404);
            }
            
            debugLog("Election found", $row['title']);
            sendResponse(['success' => true, 'election' => $row]);
        } else {
            debugLog("Fetching all elections");
            $rows = $db->query("SELECT * FROM elections ORDER BY start_date DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
            debugLog("Elections count", count($rows));
            sendResponse(['success' => true, 'elections' => $rows]);
        }
    } catch (Exception $e) {
        debugLog("GET error: " . $e->getMessage());
        debugLog("Stack trace: " . $e->getTraceAsString());
        error_log('elections.php DB error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        sendResponse(['success' => false, 'message' => 'A database error occurred. Please try again.'], 500); // [FIX]
    }
}

// POST - Create new election
if ($method === 'POST') {
    debugLog("Processing POST request");
    
    $input = $_POST ?: getJsonInput() ?: [];
    debugLog("Input data", $input);
    
    $title = trim($input['title'] ?? '');
    $description = trim($input['description'] ?? '');
    $start_date_raw = $input['start_date'] ?? $input['start'] ?? '';
    $end_date_raw = $input['end_date'] ?? $input['end'] ?? '';

    debugLog("Title", $title);
    debugLog("Start date raw", $start_date_raw);
    debugLog("End date raw", $end_date_raw);

    // Validate required fields
    if (!$title || !$start_date_raw || !$end_date_raw) {
        debugLog("Missing required fields");
        sendResponse(['success' => false, 'message' => 'Missing title/start_date/end_date'], 400);
    }
    
    // IMPORTANT: Combine dates with system voting times
    // Remove any time component from the input dates (keep only YYYY-MM-DD)
    $start_date = substr($start_date_raw, 0, 10); // Get just the date part
    $end_date = substr($end_date_raw, 0, 10); // Get just the date part
    
    // Add system voting times
    $start_datetime = $start_date . ' ' . $voting_start_time;
    $end_datetime = $end_date . ' ' . $voting_end_time;
    
    debugLog("Combined with system times", [
        'start' => $start_datetime,
        'end' => $end_datetime
    ]);
    
    $start = normalizeDatetime($start_datetime);
    $end = normalizeDatetime($end_datetime);
    
    if (!$start || !$end) {
        debugLog("Invalid date format");
        sendResponse(['success' => false, 'message' => 'Invalid date format'], 400);
    }
    
    if (strtotime($end) <= strtotime($start)) {
        debugLog("End date not after start");
        sendResponse(['success' => false, 'message' => 'End date must be after start date'], 400);
    }
    
    // Check duplicate
    debugLog("Checking for duplicates");
    $dupStmt = $db->prepare("SELECT COUNT(*) as cnt FROM elections WHERE title = ? AND start_date = ? AND end_date = ?");
    $dupStmt->execute([$title, $start, $end]);
    $dupResult = $dupStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($dupResult['cnt'] > 0) {
        debugLog("Duplicate found");
        sendResponse(['success' => false, 'message' => 'An identical election already exists'], 409);
    }
    
    // Compute status
    $now = (new DateTime())->format('Y-m-d H:i:s');
    $status = ($now < $start) ? 'upcoming' : (($now <= $end) ? 'active' : 'ended');
    debugLog("Computed status", $status);

    try {
        $db->beginTransaction();
        debugLog("Transaction started");
        
        if ($status === 'active') {
            debugLog("Ending other active elections");
            $stmt = $db->prepare("UPDATE elections SET status = 'ended' WHERE status = 'active'");
            $stmt->execute();
        }
        
        debugLog("Inserting new election with system times");
        $stmt = $db->prepare("INSERT INTO elections (title, description, start_date, end_date, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$title, $description, $start, $end, $status]);
        
        $newId = $db->lastInsertId();
        debugLog("New election ID", $newId);
        
        // Log the creation with system times
        $activityLogger->logActivity(
            $_SESSION['user_id'] ?? 'system',
            $_SESSION['first_name'] ?? 'Admin',
            'election_created',
            "Created election: $title",
            json_encode([
                'election_id' => $newId,
                'start_datetime' => $start,
                'end_datetime' => $end,
                'system_start_time' => $voting_start_time,
                'system_end_time' => $voting_end_time
            ])
        );
        
        $db->commit();
        debugLog("Transaction committed");
        
        sendResponse([
            'success' => true, 
            'message' => 'Election created with system voting times',
            'election_id' => $newId,
            'start_datetime' => $start,
            'end_datetime' => $end
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        debugLog("Create error: " . $e->getMessage());
        debugLog("Stack trace: " . $e->getTraceAsString());
        error_log('elections.php DB error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        sendResponse(['success' => false, 'message' => 'A database error occurred. Please try again.'], 500); // [FIX]
    }
}

// PUT/PATCH - Update existing election
if ($method === 'PUT' || $method === 'PATCH') {
    debugLog("Processing PUT request");
    
    if (!$id) {
        debugLog("Missing election ID");
        sendResponse(['success' => false, 'message' => 'Missing election ID'], 400);
    }
    
    $input = getJsonInput() ?: [];
    debugLog("Input data", $input);
    
    $title = isset($input['title']) ? trim($input['title']) : null;
    $description = isset($input['description']) ? trim($input['description']) : null;
    $start_date_raw = $input['start_date'] ?? null;
    $end_date_raw = $input['end_date'] ?? null;
    
    debugLog("Title", $title);
    debugLog("Start date raw", $start_date_raw);
    debugLog("End date raw", $end_date_raw);
    
    $fields = [];
    $params = [':id' => $id];
    
    if ($title !== null) {
        $fields[] = "title = :title";
        $params[':title'] = $title;
    }
    if ($description !== null) {
        $fields[] = "description = :desc";
        $params[':desc'] = $description;
    }
    if ($start_date_raw !== null) {
        // IMPORTANT: Combine with system voting time
        $start_date = substr($start_date_raw, 0, 10);
        $start_datetime = $start_date . ' ' . $voting_start_time;
        $start = normalizeDatetime($start_datetime);
        if (!$start) {
            debugLog("Invalid start date");
            sendResponse(['success' => false, 'message' => 'Invalid start_date'], 400);
        }
        $fields[] = "start_date = :start";
        $params[':start'] = $start;
        debugLog("Normalized start with system time", $start);
    }
    if ($end_date_raw !== null) {
        // IMPORTANT: Combine with system voting time
        $end_date = substr($end_date_raw, 0, 10);
        $end_datetime = $end_date . ' ' . $voting_end_time;
        $end = normalizeDatetime($end_datetime);
        if (!$end) {
            debugLog("Invalid end date");
            sendResponse(['success' => false, 'message' => 'Invalid end_date'], 400);
        }
        $fields[] = "end_date = :end";
        $params[':end'] = $end;
        debugLog("Normalized end with system time", $end);
    }
    
    if (empty($fields)) {
        debugLog("No fields to update");
        sendResponse(['success' => false, 'message' => 'No fields to update'], 400);
    }

    try {
        $db->beginTransaction();
        debugLog("Transaction started");
        
        // Get current data
        debugLog("Fetching current election data", $id);
        $stmt = $db->prepare("SELECT * FROM elections WHERE id = ?");
        $stmt->execute([$id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current) {
            debugLog("Election not found", $id);
            sendResponse(['success' => false, 'message' => 'Election not found'], 404);
        }
        
        debugLog("Current data", $current);
        
        // Update
        $sql = "UPDATE elections SET " . implode(", ", $fields) . " WHERE id = :id";
        debugLog("Update SQL", $sql);
        debugLog("Update params", $params);
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        debugLog("Update executed");
        
        // Recompute status
        $stmt = $db->prepare("SELECT start_date, end_date FROM elections WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $now = (new DateTime())->format('Y-m-d H:i:s');
            $newStatus = ($now < $row['start_date']) ? 'upcoming' : (($now <= $row['end_date']) ? 'active' : 'ended');
            debugLog("New status", $newStatus);
            
            if ($newStatus === 'active') {
                $stmt2 = $db->prepare("UPDATE elections SET status = 'ended' WHERE status = 'active' AND id != ?");
                $stmt2->execute([$id]);
                debugLog("Other active elections ended");
            }
            
            $stmt2 = $db->prepare("UPDATE elections SET status = ? WHERE id = ?");
            $stmt2->execute([$newStatus, $id]);
            debugLog("Status updated");
        }
        
        // Log the update
        $activityLogger->logActivity(
            $_SESSION['user_id'] ?? 'system',
            $_SESSION['first_name'] ?? 'Admin',
            'election_updated',
            "Updated election: " . ($title ?? $current['title']),
            json_encode([
                'election_id' => $id,
                'changes' => $fields
            ])
        );
        
        $db->commit();
        debugLog("Transaction committed");
        
        sendResponse(['success' => true, 'message' => 'Election updated']);
        
    } catch (Exception $e) {
        $db->rollBack();
        debugLog("Update error: " . $e->getMessage());
        debugLog("Stack trace: " . $e->getTraceAsString());
        error_log('elections.php DB error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        sendResponse(['success' => false, 'message' => 'A database error occurred. Please try again.'], 500); // [FIX]
    }
}

// DELETE - Delete election
if ($method === 'DELETE') {
    debugLog("Processing DELETE request");
    
    if (!$id) {
        debugLog("Missing election ID");
        sendResponse(['success' => false, 'message' => 'Missing election ID'], 400);
    }
    
    try {
        // Get election details
        debugLog("Fetching election details", $id);
        $stmt = $db->prepare("SELECT * FROM elections WHERE id = ?");
        $stmt->execute([$id]);
        $election = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$election) {
            debugLog("Election not found for deletion", $id);
            sendResponse(['success' => false, 'message' => 'Not found'], 404);
        }
        
        debugLog("Election found", $election['title']);
        
        // Check for associated data
        $stmt = $db->prepare("SELECT COUNT(*) FROM candidates WHERE election_id = ?");
        $stmt->execute([$id]);
        $candidateCount = $stmt->fetchColumn();
        debugLog("Candidate count", $candidateCount);
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM votes WHERE election_id = ?");
        $stmt->execute([$id]);
        $voteCount = $stmt->fetchColumn();
        debugLog("Vote count", $voteCount);
        
        // Delete
        debugLog("Deleting election");
        $deleteStmt = $db->prepare("DELETE FROM elections WHERE id = ?");
        $deleteStmt->execute([$id]);
        
        if ($deleteStmt->rowCount() === 0) {
            debugLog("Delete failed - no rows affected");
            sendResponse(['success' => false, 'message' => 'Not found'], 404);
        }
        
        // Log the deletion
        $activityLogger->logActivity(
            $_SESSION['user_id'] ?? 'system',
            $_SESSION['first_name'] ?? 'Admin',
            'election_deleted',
            "Deleted election: {$election['title']}",
            json_encode([
                'election_id' => $id,
                'had_candidates' => $candidateCount > 0,
                'had_votes' => $voteCount > 0
            ])
        );
        
        debugLog("Delete successful");
        sendResponse(['success' => true, 'message' => 'Election deleted']);
        
    } catch (Exception $e) {
        debugLog("Delete error: " . $e->getMessage());
        debugLog("Stack trace: " . $e->getTraceAsString());
        error_log('elections.php DB error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        sendResponse(['success' => false, 'message' => 'A database error occurred. Please try again.'], 500); // [FIX]
    }
}

// If we get here, method not allowed
debugLog("Method not allowed", $method);
sendResponse(['success' => false, 'message' => 'Method not allowed'], 405);