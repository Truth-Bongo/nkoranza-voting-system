<?php
// api/admin/get-active-elections.php
// Returns ONLY active elections for the dashboard widget

require_once __DIR__ . '/../../config/bootstrap.php';
session_start();

header('Content-Type: application/json');

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Check admin authentication
if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    // First update statuses
    $current_time = date('Y-m-d H:i:s');
    $updateStmt = $db->prepare("
        UPDATE elections 
        SET status = CASE
            WHEN :ct1 < start_date THEN 'upcoming'
            WHEN :ct2 <= end_date THEN 'active'
            ELSE 'ended'
        END
    ");
    $updateStmt->execute([':ct1' => $current_time, ':ct2' => $current_time]);
    
    // Then fetch active elections
    $stmt = $db->prepare("
        SELECT 
            id, 
            title, 
            description, 
            start_date, 
            end_date, 
            status, 
            created_at
        FROM elections 
        WHERE status = 'active'
        ORDER BY start_date DESC
    ");
    $stmt->execute();
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates for JavaScript
    foreach ($elections as &$election) {
        $election['start_date'] = date('Y-m-d H:i:s', strtotime($election['start_date']));
        $election['end_date'] = date('Y-m-d H:i:s', strtotime($election['end_date']));
    }
    
    echo json_encode([
        'success' => true,
        'elections' => $elections,
        'count' => count($elections),
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-active-elections.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch active elections'
    ]);
}