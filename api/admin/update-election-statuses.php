<?php
// api/admin/update-election-statuses.php
// Updates all election statuses based on current time

require_once __DIR__ . '/../../config/bootstrap.php';
session_start();

header('Content-Type: application/json');

// Enable error logging
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
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
    $current_time = date('Y-m-d H:i:s');
    
    $stmt = $db->prepare("
        UPDATE elections 
        SET status = CASE
            WHEN :ct1 < start_date THEN 'upcoming'
            WHEN :ct2 <= end_date THEN 'active'
            ELSE 'ended'
        END
    ");
    $stmt->execute([':ct1' => $current_time, ':ct2' => $current_time]);
    
    // Get updated counts
    $countStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN status = 'ended' THEN 1 ELSE 0 END) as ended
        FROM elections
    ");
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Election statuses updated',
        'counts' => $counts,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log("Error in update-election-statuses.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to update election statuses'
    ]);
}