<?php
require_once __DIR__ . '/../../../config/bootstrap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

if (!isset($_GET['election_id'])) {
    echo json_encode(['success' => false, 'message' => 'Election ID required']);
    exit;
}

$electionId = intval($_GET['election_id']);
$userId = $_SESSION['user_id'];

try {
    $db = Database::getInstance()->getConnection();
    
    // Get election details
    $stmt = $db->prepare('SELECT * FROM elections WHERE id = ?');
    $stmt->execute([$electionId]);
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$election) {
        echo json_encode(['success' => false, 'message' => 'Election not found']);
        exit;
    }
    
    // Check if election is active
    $now = new DateTime();
    $start = new DateTime($election['start_date']);
    $end = new DateTime($election['end_date']);
    $isActive = ($now >= $start && $now <= $end);
    
    // Check if user has already voted
    $stmt = $db->prepare('SELECT id FROM votes WHERE user_id = ? AND election_id = ?');
    $stmt->execute([$userId, $electionId]);
    $hasVoted = (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'is_active' => $isActive,
        'has_voted' => $hasVoted,
        'message' => $isActive ? 
            ($hasVoted ? 'You have already voted' : 'Election is active') : 
            'Election is not active'
    ]);
    
} catch (Exception $e) {
    error_log("Validation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}