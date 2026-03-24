<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

function jsend($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $activityLogger = new ActivityLogger($db);
} catch (Exception $e) {
    jsend(['success'=>false,'message'=>'Database connection failed'], 500);
}

$method = $_SERVER['REQUEST_METHOD'];

// CSRF for non-GET
if ($method !== 'GET') {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token = $headers['X-CSRF-Token'] ?? $_REQUEST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsend(['success'=>false,'message'=>'Invalid CSRF token'], 403);
    }
}

// GET (students + admins)
$id = $_GET['id'] ?? null;
if ($method === 'GET') {
    try {
        if ($id) {
            $stmt = $db->prepare("
                SELECT p.*, e.title AS election_title, e.start_date, e.end_date
                FROM positions p
                JOIN elections e ON p.election_id = e.id
                WHERE p.id = ?
            ");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) jsend(['success'=>false,'message'=>'Not found'], 404);
            jsend(['success'=>true,'position'=>$row]);
        } else {
            $electionId = $_GET['election_id'] ?? null;
            $sql = "
                SELECT p.*, e.title AS election_title, e.start_date, e.end_date
                FROM positions p
                JOIN elections e ON p.election_id = e.id
                WHERE 1=1
            ";
            $params = [];
            if ($electionId) { $sql .= " AND p.election_id = ?"; $params[] = $electionId; }
            $sql .= " ORDER BY p.name ASC";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            jsend(['success'=>true,'positions'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }
    } catch (Exception $e) {
        error_log('positions.php GET error: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Database error'], 500);
    }
}

// Admin guard for POST/PUT/DELETE
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    jsend(['success'=>false,'message'=>'Forbidden: admin only'], 403);
}

$_adminId   = $_SESSION['user_id'] ?? 'system';
$_adminName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin';

function get_json_input() {
    $arr = json_decode(file_get_contents('php://input'), true);
    return is_array($arr) ? $arr : null;
}

// POST: create position
if ($method === 'POST') {
    $input       = get_json_input() ?? $_POST;
    $name        = trim($input['name'] ?? '');
    $election_id = isset($input['election_id']) ? (int)$input['election_id'] : null;
    $description = trim($input['description'] ?? '');
    $category    = trim($input['category'] ?? '');
    $max_votes   = isset($input['max_votes']) ? (int)$input['max_votes'] : 1;

    if (!$name || !$election_id) {
        jsend(['success'=>false,'message'=>'name and election_id are required'], 400);
    }

    try {
        $elStmt = $db->prepare("SELECT title FROM elections WHERE id = ?");
        $elStmt->execute([$election_id]);
        $election = $elStmt->fetch(PDO::FETCH_ASSOC);
        if (!$election) jsend(['success'=>false,'message'=>'Election not found'], 404);

        $stmt = $db->prepare(
            "INSERT INTO positions (name, election_id, description, category, max_votes)
             VALUES (:name, :election_id, :description, :category, :max_votes)"
        );
        $stmt->execute([
            ':name'        => $name,
            ':election_id' => $election_id,
            ':description' => $description ?: null,
            ':category'    => $category ?: null,
            ':max_votes'   => $max_votes,
        ]);
        $newId = $db->lastInsertId();

        $activityLogger->logActivity(
            $_adminId, $_adminName, 'position_created',
            'Created position: ' . $name,
            json_encode([
                'position_id'    => $newId,
                'position_name'  => $name,
                'election_id'    => $election_id,
                'election_title' => $election['title'],
                'category'       => $category ?: null,
            ])
        );

        jsend(['success'=>true,'message'=>'Position created','position_id'=>$newId]);
    } catch (Exception $e) {
        error_log('positions.php POST error: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Database error'], 500);
    }
}

// PUT/PATCH: update position
if ($method === 'PUT' || $method === 'PATCH') {
    if (!$id) jsend(['success'=>false,'message'=>'Missing id'], 400);

    $input = get_json_input();
    if (!is_array($input)) parse_str(file_get_contents('php://input'), $input);

    $fields = []; $params = [':id' => $id];
    if (isset($input['name']))        { $fields[] = "name = :name";               $params[':name']        = trim($input['name']); }
    if (isset($input['description'])) { $fields[] = "description = :description"; $params[':description'] = trim($input['description']); }
    if (isset($input['category']))    { $fields[] = "category = :category";       $params[':category']    = trim($input['category']); }
    if (isset($input['max_votes']))   { $fields[] = "max_votes = :mv";            $params[':mv']          = (int)$input['max_votes']; }
    if (isset($input['election_id'])) { $fields[] = "election_id = :eid";         $params[':eid']         = (int)$input['election_id']; }

    if (empty($fields)) jsend(['success'=>false,'message'=>'No fields to update'], 400);

    try {
        $nameStmt = $db->prepare("SELECT name, election_id FROM positions WHERE id = ?");
        $nameStmt->execute([$id]);
        $existing = $nameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) jsend(['success'=>false,'message'=>'Position not found'], 404);

        $stmt = $db->prepare("UPDATE positions SET " . implode(", ", $fields) . " WHERE id = :id");
        $ok   = $stmt->execute($params);

        if ($ok) {
            $activityLogger->logActivity(
                $_adminId, $_adminName, 'position_updated',
                'Updated position: ' . $existing['name'],
                json_encode([
                    'position_id'    => (int)$id,
                    'position_name'  => $existing['name'],
                    'election_id'    => $existing['election_id'],
                    'fields_changed' => array_keys($input),
                ])
            );
        }

        jsend(['success'=>$ok,'message'=>$ok ? 'Position updated' : 'Failed to update position']);
    } catch (Exception $e) {
        error_log('positions.php PUT error: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Database error'], 500);
    }
}

// DELETE: remove position
if ($method === 'DELETE') {
    if (!$id) jsend(['success'=>false,'message'=>'Missing id'], 400);

    try {
        $nameStmt = $db->prepare("SELECT name, election_id FROM positions WHERE id = ?");
        $nameStmt->execute([$id]);
        $existing = $nameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existing) jsend(['success'=>false,'message'=>'Not found'], 404);

        // Count cascade-deleted data so the log is informative
        $cStmt = $db->prepare("SELECT COUNT(*) FROM candidates WHERE position_id = ?");
        $cStmt->execute([$id]);
        $candCount = (int)$cStmt->fetchColumn();

        $vStmt = $db->prepare("SELECT COUNT(*) FROM votes WHERE position_id = ?");
        $vStmt->execute([$id]);
        $voteCount = (int)$vStmt->fetchColumn();

        $stmt = $db->prepare("DELETE FROM positions WHERE id = ?");
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) jsend(['success'=>false,'message'=>'Not found'], 404);

        $activityLogger->logActivity(
            $_adminId, $_adminName, 'position_deleted',
            'Deleted position: ' . $existing['name'],
            json_encode([
                'position_id'        => (int)$id,
                'position_name'      => $existing['name'],
                'election_id'        => $existing['election_id'],
                'candidates_removed' => $candCount,
                'votes_removed'      => $voteCount,
            ])
        );

        jsend(['success'=>true,'message'=>'Position deleted']);
    } catch (Exception $e) {
        error_log('positions.php DELETE error: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Database error'], 500);
    }
}

jsend(['success'=>false,'message'=>'Method not allowed'], 405);
