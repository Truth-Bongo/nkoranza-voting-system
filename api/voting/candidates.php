<?php
// api/voting/candidates.php
// SECURITY PATCHES:
//   [1] CORS wildcard replaced with allowlist
//   [2] Exception message, file path, and line number no longer returned to client

require_once __DIR__ . '/../../config/bootstrap.php';

// [FIX 1]
$allowed_origins = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [BASE_URL];
$request_origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
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

    $position_id = $_GET['position_id'] ?? null;

    if (!$position_id) {
        throw new Exception('Position ID is required');
    }

    $db = Database::getInstance()->getConnection();

    $checkStmt = $db->prepare('SELECT id, name FROM positions WHERE id = ?');
    $checkStmt->execute([$position_id]);
    $position = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$position) {
        ob_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Position not found']);
        exit;
    }

    $stmt = $db->prepare('
        SELECT
            c.id,
            c.user_id,
            c.manifesto,
            c.photo_path,
            u.first_name,
            u.last_name,
            u.department,
            u.level
        FROM candidates c
        INNER JOIN users u ON c.user_id = u.id
        WHERE c.position_id = ?
        ORDER BY u.first_name, u.last_name
    ');
    $stmt->execute([$position_id]);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($candidates as &$candidate) {
        if ($candidate['photo_path'] && !filter_var($candidate['photo_path'], FILTER_VALIDATE_URL)) {
            $cleanPath = ltrim(str_replace('../', '', $candidate['photo_path']), '/');
            $candidate['photo_path'] = BASE_URL . '/' . $cleanPath;
        }
    }
    unset($candidate);

    ob_clean();
    echo json_encode([
        'success'    => true,
        'position'   => $position,
        'candidates' => $candidates,
        'count'      => count($candidates)
    ]);

} catch (Exception $e) {
    ob_clean();
    // [FIX 2] Log internally; never expose file path, line number, or message
    error_log("Candidates (voting) API error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch candidates']);
} finally {
    ob_end_flush();
}
