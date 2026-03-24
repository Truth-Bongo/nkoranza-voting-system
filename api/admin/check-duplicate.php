<?php
// api/admin/check-duplicate.php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Check admin access
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = Database::getInstance()->getConnection();
$userId = $_GET['user_id'] ?? '';
$electionId = $_GET['election_id'] ?? '';
$positionId = $_GET['position_id'] ?? '';
$excludeId = $_GET['exclude_id'] ?? '';

if (!$userId || !$electionId || !$positionId) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $sql = "
        SELECT c.*, u.first_name, u.last_name, e.title as election_title, p.name as position_name 
        FROM candidates c
        LEFT JOIN users u ON c.user_id = u.id
        LEFT JOIN elections e ON c.election_id = e.id
        LEFT JOIN positions p ON c.position_id = p.id
        WHERE c.user_id = ? AND c.election_id = ? AND c.position_id = ?
    ";

    $params = [$userId, $electionId, $positionId];

    if ($excludeId) {
        $sql .= " AND c.id != ?";
        $params[] = $excludeId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($candidate) {
        echo json_encode([
            'exists' => true,
            'candidate' => $candidate,
            'message' => "{$candidate['first_name']} {$candidate['last_name']} is already a candidate for {$candidate['position_name']} in {$candidate['election_title']}"
        ]);
    } else {
        echo json_encode(['exists' => false]);
    }
} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}
?>