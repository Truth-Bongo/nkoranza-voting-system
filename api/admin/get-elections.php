<?php
// api/admin/get-elections.php
// Returns ALL elections with filtering support for the elections management page

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
    
    // First update statuses to ensure data is current
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
    
    // Get filter parameters
    $search = $_GET['search'] ?? '';
    $status_filter = $_GET['status'] ?? 'all';
    
    // Build query with candidate counts
    $query = "
        SELECT 
            e.id,
            e.title,
            e.description,
            e.start_date,
            e.end_date,
            e.status,
            e.created_at,
            (SELECT COUNT(*) FROM candidates WHERE election_id = e.id) as candidate_count
        FROM elections e
        WHERE 1=1
    ";
    $params = [];
    
    // Apply search filter
    if (!empty($search)) {
        $query .= " AND (e.title LIKE :search OR e.description LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    // Apply status filter
    if ($status_filter !== 'all') {
        $query .= " AND e.status = :status";
        $params[':status'] = $status_filter;
    }
    
    $query .= " ORDER BY 
        CASE e.status
            WHEN 'active' THEN 1
            WHEN 'upcoming' THEN 2
            WHEN 'ended' THEN 3
        END,
        e.start_date DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get overall statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN status = 'ended' THEN 1 ELSE 0 END) as ended
        FROM elections
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Format dates for JavaScript
    foreach ($elections as &$election) {
        $election['start_date'] = date('Y-m-d H:i:s', strtotime($election['start_date']));
        $election['end_date'] = date('Y-m-d H:i:s', strtotime($election['end_date']));
        $election['created_at'] = date('Y-m-d H:i:s', strtotime($election['created_at']));
    }
    
    echo json_encode([
        'success' => true,
        'elections' => $elections,
        'stats' => $stats,
        'count' => count($elections),
        'filters' => [
            'search' => $search,
            'status' => $status_filter
        ],
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    error_log("Error in get-elections.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to fetch elections: ' . $e->getMessage()
    ]);
}