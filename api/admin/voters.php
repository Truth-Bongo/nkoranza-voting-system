<?php
// api/admin/voters.php
// API endpoint for managing voters (CRUD operations) with auto-generated IDs

// Enable error logging but don't display errors
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Start output buffering to catch any unexpected output
ob_start();

// Set JSON header first
header('Content-Type: application/json');

try {
    // Define constants if not already defined
    if (!defined('APP_ROOT')) {
        define('APP_ROOT', dirname(__DIR__, 2));
    }

    // Check if required files exist
    $requiredFiles = [
        '/../../config/constants.php',
        '/../../config/db_connect.php',
        '/../../helpers/functions.php',
        '/../../includes/ActivityLogger.php'
    ];

    foreach ($requiredFiles as $file) {
        $fullPath = __DIR__ . $file;
        if (!file_exists($fullPath)) {
            throw new Exception("Required file not found: " . $file);
        }
        require_once $fullPath;
    }

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Initialize response array
    $response = ['success' => false, 'message' => ''];

    // Validate admin authentication
    if (empty($_SESSION['is_admin'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized access. Admin privileges required.']);
        exit;
    }

    // [FIX] Use shared validate_csrf_token() which rotates the token after use,
    // preventing replay attacks. Manual hash_equals without rotation left the same
    // token valid for unlimited requests in a session.
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $headers = function_exists('getallheaders') ? array_change_key_case(getallheaders(), CASE_LOWER) : [];
        $token = $headers['x-csrf-token'] ?? $_POST['csrf_token'] ?? '';
        if (empty($token)) {
            $rawBody = json_decode(file_get_contents('php://input'), true) ?? [];
            $token = $rawBody['csrf_token'] ?? '';
        }
        if (!validate_csrf_token($token)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }
    }

    // Get database connection
    try {
        $db = Database::getInstance()->getConnection();
        
        // Ensure we're using the correct database
        $db->exec("USE nkoranza_voting");
        
    } catch (Exception $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }

    // Initialize logger
    try {
        $logger = new ActivityLogger($db);
    } catch (Exception $e) {
        error_log("Logger initialization failed: " . $e->getMessage());
        $logger = null;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'POST':
            // Handle both form data and JSON input
            $input = $_POST;
            if (empty($input)) {
                $input = json_decode(file_get_contents('php://input'), true) ?? [];
            }
            
            // [FIX] Do NOT htmlspecialchars on input — that double-encodes data stored in DB.
            // HTML escaping belongs at output time. Trim strings only.
            $input = array_map(function($value) {
                return is_string($value) ? trim($value) : $value;
            }, $input);
            
            $id = $input['id'] ?? '';
            $firstName = $input['first_name'] ?? '';
            $lastName = $input['last_name'] ?? '';
            $department = $input['department'] ?? '';
            $level = $input['level'] ?? '';
            $email = $input['email'] ?? '';
            $password = $input['password'] ?? ''; // May be empty for auto-generation
            $entryYear = !empty($input['entry_year']) ? (int)$input['entry_year'] : (int)date('Y');
            $graduationYear = !empty($input['graduation_year']) ? (int)$input['graduation_year'] : null;
            $isEdit = ($input['is_edit'] ?? 'false') === 'true';

            // Validate required fields
            if (!$firstName || !$lastName || !$email) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing required fields: first name, last name, and email are required']);
                exit;
            }

            // Validate email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid email format']);
                exit;
            }

            $db->beginTransaction();

            try {
                if ($isEdit) {
                    // Check if voter exists and is not admin
                    if (empty($id)) {
                        throw new Exception('Voter ID is required for editing');
                    }
                    
                    $checkStmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND is_admin = 0");
                    $checkStmt->execute([$id]);
                    $existingUser = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$existingUser) {
                        throw new Exception('Voter not found or cannot be edited.');
                    }
                    
                    // Check for email duplicate (excluding current user)
                    $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                    $emailCheck->execute([$email, $id]);
                    if ($emailCheck->fetch()) {
                        throw new Exception('Email address already exists. Please use a different email.');
                    }
                    
                    // Update voter
                    $sql = "UPDATE users SET 
                            first_name = :first_name, 
                            last_name = :last_name, 
                            department = :department, 
                            level = :level, 
                            email = :email,
                            entry_year = :entry_year,
                            graduation_year = :graduation_year";
                    
                    $params = [
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':department' => $department,
                        ':level' => $level,
                        ':email' => $email,
                        ':entry_year' => $entryYear,
                        ':graduation_year' => $graduationYear,
                        ':id' => $id
                    ];

                    // Only update password if a new one is provided
                    if (!empty($password)) {
                        $sql .= ", password = :password";
                        $params[':password'] = password_hash($password, PASSWORD_BCRYPT);
                    }

                    $sql .= " WHERE id = :id AND is_admin = 0";

                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);

                    $message = 'Voter updated successfully';
                    
                } else {
                    // AUTO-GENERATE ID for new voters using PHP function (more reliable)
                    $newId = generateStudentIdPHP($db, $department, $entryYear);
                    
                    if (empty($newId)) {
                        throw new Exception('Failed to generate student ID');
                    }
                    
                    // Check for duplicate email
                    $emailCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
                    $emailCheck->execute([$email]);
                    if ($emailCheck->fetch()) {
                        throw new Exception('Email address already exists. Please use a different email.');
                    }
                    
                    // AUTO-GENERATE PASSWORD if not provided
                    if (empty($password)) {
                        // Generate simple 8-character password
                        $password = generateSimplePassword($firstName, $lastName, $newId);
                        $passwordGenerated = true;
                    } else {
                        $passwordGenerated = false;
                    }
                    
                    // Insert new voter with auto-generated ID and password
                    $stmt = $db->prepare("INSERT INTO users 
                        (id, password, first_name, last_name, department, level, email, entry_year, graduation_year, has_voted, has_logged_in, is_admin) 
                        VALUES (:id, :password, :first_name, :last_name, :department, :level, :email, :entry_year, :graduation_year, 0, 0, 0)");
                    
                    $stmt->execute([
                        ':id' => $newId,
                        ':password' => password_hash($password, PASSWORD_BCRYPT),
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':department' => $department,
                        ':level' => $level,
                        ':email' => $email,
                        ':entry_year' => $entryYear,
                        ':graduation_year' => $graduationYear
                    ]);

                    $id = $newId;
                    $message = 'Voter added successfully with ID: ' . $newId;
                    
                    // Include password in response for first-time display
                    if ($passwordGenerated) {
                        $message .= ' | Auto-generated password: ' . $password;
                    }
                }

                $db->commit();
                
                // Log activity if logger is available
                if ($logger) {
                    try {
                        $logger->logActivity(
                            $_SESSION['user_id'] ?? 'ADM0001',
                            ($_SESSION['first_name'] ?? 'System') . ' ' . ($_SESSION['last_name'] ?? 'Administrator'),
                            $isEdit ? 'voter_edit' : 'voter_add',
                            $message . ': ' . $firstName . ' ' . $lastName . ' (' . $id . ')'
                        );
                    } catch (Exception $e) {
                        error_log("Failed to log activity: " . $e->getMessage());
                    }
                }
                
                // Prepare response
                $response = [
                    'success' => true, 
                    'message' => $message,
                    'id' => $id,
                    'is_new' => !$isEdit
                ];
                
                // Include password in response if it was auto-generated
                if (!$isEdit && empty($input['password']) && isset($password)) {
                    $response['generated_password'] = $password;
                    $response['warning'] = 'Please save this password - it will not be shown again!';
                }
                
                echo json_encode($response);

            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(400);
                error_log("Voter POST error detail: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
            }
            break;

        case 'DELETE':
            $id = $_GET['id'] ?? '';
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Missing voter ID']);
                exit;
            }

            try {
                $db->beginTransaction();
                
                // Check if voter exists
                $checkStmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id = ? AND is_admin = 0");
                $checkStmt->execute([$id]);
                $voter = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$voter) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Voter not found']);
                    exit;
                }

                // Delete related records
                $tables = ['votes', 'candidates', 'activity_logs'];
                foreach ($tables as $table) {
                    try {
                        $stmt = $db->prepare("DELETE FROM {$table} WHERE user_id = ?");
                        $stmt->execute([$id]);
                    } catch (Exception $e) {
                        // Table might not exist, continue
                        error_log("Error deleting from {$table}: " . $e->getMessage());
                    }
                }
                
                // Delete the user
                $stmt = $db->prepare("DELETE FROM users WHERE id = ? AND is_admin = 0");
                $stmt->execute([$id]);
                
                $db->commit();
                
                // Log activity
                if ($logger) {
                    try {
                        $logger->logActivity(
                            $_SESSION['user_id'] ?? 'ADM0001',
                            ($_SESSION['first_name'] ?? 'System') . ' ' . ($_SESSION['last_name'] ?? 'Administrator'),
                            'voter_delete',
                            "Deleted voter: {$voter['first_name']} {$voter['last_name']} ({$id})"
                        );
                    } catch (Exception $e) {
                        error_log("Failed to log activity: " . $e->getMessage());
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Voter deleted successfully']);

            } catch (Exception $e) {
                $db->rollBack();
                http_response_code(500);
                error_log("Delete voter error: " . $e->getMessage());
                error_log("Delete voter error detail: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to delete voter. Please try again.']);
            }
            break;
            
        case 'GET':
            // Endpoint to get next auto-generated ID preview
            if (isset($_GET['preview_id']) && $_GET['preview_id'] === 'true') {
                $department = $_GET['department'] ?? '';
                $entryYear = isset($_GET['entry_year']) ? (int)$_GET['entry_year'] : (int)date('Y');
                
                if (empty($department)) {
                    echo json_encode(['success' => false, 'message' => 'Department required']);
                    exit;
                }
                
                try {
                    // Use PHP function for preview as well
                    $previewId = generateStudentIdPHP($db, $department, $entryYear, true);
                    
                    echo json_encode([
                        'success' => true, 
                        'preview_id' => $previewId,
                        'department' => $department,
                        'entry_year' => $entryYear
                    ]);
                } catch (Exception $e) {
                    error_log("Voter POST error detail: " . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Operation failed: ' . $e->getMessage()]);
                }
                exit;
            }
            
            // If not preview, return 405
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed for this endpoint']);
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    // Clear any output that might have been generated
    ob_clean();
    
    http_response_code(500);
    error_log("Voters API Error: " . $e->getMessage());
    error_log("Voters API Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An internal error occurred. Please try again.']);
}

/**
 * Generate a student ID using PHP (more reliable than MySQL function)
 * Format: [DEPT-CODE][YEAR-LAST-2][4-DIGIT-SEQUENCE]
 * Example: GSC230001
 */
function generateStudentIdPHP($db, $department, $entryYear, $isPreview = false) {
    // Department to code mapping (from your department_codes table)
    $deptCodes = [
        'General Science' => 'GSC',
        'General Arts' => 'GAR',
        'Business' => 'BUS',
        'Home Economics' => 'HEC',
        'Visual Arts' => 'VAR',
        'Technical' => 'TEC',
        'Agriculture' => 'AGR',
        'Vocational' => 'VOC'
    ];
    
    // Get department code (default to first 3 letters uppercase if not found)
    $deptCode = $deptCodes[$department] ?? strtoupper(substr(trim($department), 0, 3));
    
    // Get last 2 digits of entry year
    $yearCode = substr($entryYear, -2);
    
    // For preview mode, just generate a sample ID without checking database
    if ($isPreview) {
        // Get the next available number without inserting
        $pattern = $deptCode . $yearCode . '%';
        
        $stmt = $db->prepare("
            SELECT MAX(CAST(RIGHT(id, 4) AS UNSIGNED)) as max_sequence
            FROM users 
            WHERE id LIKE ? AND LENGTH(id) = 9
        ");
        $stmt->execute([$pattern]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nextSequence = ($result['max_sequence'] ?? 0) + 1;
        $sequencePadded = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        
        return $deptCode . $yearCode . $sequencePadded;
    }
    
    // For actual insertion, we need to ensure uniqueness
    $maxAttempts = 10;
    $attempt = 0;
    
    while ($attempt < $maxAttempts) {
        // Find the next available sequence number
        $pattern = $deptCode . $yearCode . '%';
        
        $stmt = $db->prepare("
            SELECT MAX(CAST(RIGHT(id, 4) AS UNSIGNED)) as max_sequence
            FROM users 
            WHERE id LIKE ? AND LENGTH(id) = 9
        ");
        $stmt->execute([$pattern]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $nextSequence = ($result['max_sequence'] ?? 0) + 1;
        $sequencePadded = str_pad($nextSequence, 4, '0', STR_PAD_LEFT);
        
        $newId = $deptCode . $yearCode . $sequencePadded;
        
        // Check if this ID is already taken (race condition protection)
        $checkStmt = $db->prepare("SELECT id FROM users WHERE id = ?");
        $checkStmt->execute([$newId]);
        
        if (!$checkStmt->fetch()) {
            return $newId;
        }
        
        $attempt++;
    }
    
    throw new Exception("Could not generate unique ID after $maxAttempts attempts");
}

// End output buffering and send response
ob_end_flush();
exit;
?>