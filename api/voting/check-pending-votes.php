<?php
require_once __DIR__ . '/../../config/bootstrap.php';

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Simple endpoint for service worker to check pending votes
try {
    // This would normally check the database for pending votes
    // For now, return a simple response
    echo json_encode([
        'success' => true,
        'pending_votes' => 0, // This would be calculated from your database
        'message' => 'Sync check completed'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error checking pending votes: ' . $e->getMessage()
    ]);
}
?>