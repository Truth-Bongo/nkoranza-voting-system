<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

// Use one bootstrap only
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../helpers/functions.php'; // safe (has function_exists guards)

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'DB connection failed']); exit;
}

// GET only — do not block with CSRF for reads
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
}

try {
    $status = $_GET['status'] ?? '';
    $sql = "SELECT id, title, description, start_date, end_date, status FROM elections";
    $params = [];
    
    if ($status === 'active') {
        $sql .= " WHERE start_date <= NOW() AND end_date >= NOW() AND status = 'active'";
    } else if ($status === 'upcoming') {
        $sql .= " WHERE start_date > NOW() AND status = 'active'";
    } else if ($status === 'completed' || $status === 'ended') {
        // 'ended' is the actual DB value; 'completed' accepted as alias for compatibility
        $sql .= " WHERE status = 'ended'";
    }
    
    $sql .= " ORDER BY start_date DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true, 'elections'=>$rows]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log("Elections API Error: " . $e->getMessage());
    echo json_encode(['success'=>false,'message'=>'Database error: ' . $e->getMessage()]); 
}