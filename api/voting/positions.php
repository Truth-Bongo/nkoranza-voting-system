<?php
// api/voting/positions.php
// SECURITY PATCH:
//   [1] CORS wildcard replaced with allowlist (BASE_URL only)
//   [2] Exception message no longer returned to client in error response

require_once __DIR__ . '/../../config/bootstrap.php';

// [FIX 1] Only the application's own origin may read this endpoint cross-origin.
// A wildcard is acceptable for truly public, anonymous, credential-free endpoints,
// but restricting to the known origin is still better practice.
$allowed_origins   = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [BASE_URL];
$request_origin    = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($request_origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $request_origin);
} else {
    header('Access-Control-Allow-Origin: ' . BASE_URL);
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

ob_start();

try {
    header('Content-Type: application/json');

    $electionId = isset($_GET['election_id']) ? (int)$_GET['election_id'] : 0;

    if ($electionId <= 0) {
        throw new Exception('Valid election ID is required');
    }

    $db = Database::getInstance()->getConnection();

    $checkStmt = $db->prepare("SELECT id, title FROM elections WHERE id = ? AND status = 'active'");
    $checkStmt->execute([$electionId]);
    $election = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$election) {
        ob_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Active election not found']);
        exit;
    }

    $stmt = $db->prepare(
        "SELECT id, name, description, category
         FROM positions
         WHERE election_id = ?
         ORDER BY category, name"
    );
    $stmt->execute([$electionId]);
    $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_clean();
    echo json_encode([
        'success'        => true,
        'positions'      => $positions,
        'count'          => count($positions),
        'election_id'    => $electionId,
        'election_title' => $election['title']
    ]);

} catch (Exception $e) {
    ob_clean();
    // [FIX 2] Log internally; return generic message
    error_log("Positions API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch positions']);
} finally {
    ob_end_flush();
    exit;
}
