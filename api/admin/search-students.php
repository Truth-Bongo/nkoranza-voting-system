<?php
// api/admin/search-students.php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$query = $_GET['q'] ?? '';
if (strlen($query) < 2) {
    echo json_encode(['success' => true, 'students' => []]);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            id,
            first_name,
            last_name,
            CONCAT(first_name, ' ', last_name) as name,
            graduation_year,
            status
        FROM users 
        WHERE is_admin = 0 
        AND (id LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
        ORDER BY 
            CASE status
                WHEN 'active' THEN 1
                WHEN 'graduated' THEN 2
                ELSE 3
            END,
            last_name ASC
        LIMIT 10
    ");
    
    $searchTerm = "%$query%";
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'students' => $students]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}