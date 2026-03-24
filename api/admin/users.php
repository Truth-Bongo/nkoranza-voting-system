<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/bootstrap.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Throwable $e) {
    echo json_encode(['success'=>false,'message'=>'DB connection failed']); 
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success'=>false,'message'=>'Method not allowed']); 
    exit;
}

// ADMIN guard
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden: admin only']); 
    exit;
}

try {
    $stmt = $db->query("SELECT id, first_name, last_name, department FROM users ORDER BY first_name, last_name");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true, 'users'=>$users]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Database error']); 
}