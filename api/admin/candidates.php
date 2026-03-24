<?php
// api/admin/candidates.php
// SECURITY PATCHES:
//   [1] display_errors = 0
//   [2] Verbose debug logging of $_SESSION, $_POST, $_FILES, $_GET removed
//   [3] CSRF token value no longer included in error responses
//   [4] Exception stack traces no longer returned to clients
//   [5] File upload extension derived from validated MIME type, not user filename

// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0); // [FIX 1]
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/candidates_debug.log');

ob_start();
header('Content-Type: application/json; charset=utf-8');

// [FIX 3 & 4] $data (which previously contained session tokens and stack traces)
// is now logged server-side only, never sent to the client.
function returnError($message, $data = null) {
    while (ob_get_level() > 0) ob_end_clean();
    if ($data !== null) {
        error_log("candidates.php error: " . print_r($data, true));
    }
    echo json_encode(['success' => false, 'message' => $message], JSON_PRETTY_PRINT);
    exit;
}

// [FIX 5] Derive safe extension from the already-validated MIME type — never from
// the user-supplied filename. pathinfo($_FILES['photo']['name']) could return 'php'
// for a file named 'shell.php.jpg', creating a potentially executable file.
function mimeToExtension($mimeType) {
    return [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
    ][$mimeType] ?? 'jpg';
}

try {
    // [FIX 2] Removed verbose per-request logging of $_POST, $_FILES, $_GET,
    // session_id(), and $_SESSION contents (which included CSRF tokens).

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $required_files = [
        '/../../config/constants.php',
        '/../../config/db_connect.php',
        '/../../includes/ActivityLogger.php',
        '/../../helpers/functions.php'
    ];

    foreach ($required_files as $file) {
        $path = __DIR__ . $file;
        if (!file_exists($path)) {
            error_log("Required file not found: $path");
            returnError("A configuration error occurred. Please contact the administrator.");
        }
    }

    require_once __DIR__ . '/../../config/constants.php';
    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../includes/ActivityLogger.php';
    require_once __DIR__ . '/../../helpers/functions.php';

    if (!class_exists('Database')) {
        returnError("A configuration error occurred. Please contact the administrator.");
    }

    $db = Database::getInstance()->getConnection();
    if (!$db) {
        returnError("A configuration error occurred. Please contact the administrator.");
    }

    // CSRF validation for state-changing requests
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $token = '';

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $token   = $headers['X-CSRF-Token'] ?? '';
        }
        if (empty($token)) {
            $token = $_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '';
        }

        if (empty($_SESSION['csrf_token'])) {
            returnError("Session expired. Please refresh and try again.");
        }
        if (empty($token)) {
            returnError("Security token missing. Please refresh and try again.");
        }
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            // [FIX 3] Log server-side only — never expose token values to client
            error_log("CSRF mismatch for user " . ($_SESSION['user_id'] ?? 'unknown'));
            returnError("Invalid security token. Please refresh and try again.");
        }
    }

    // Admin check
    if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        error_log("Non-admin access on candidates endpoint by: " . ($_SESSION['user_id'] ?? 'unknown'));
        returnError("Admin access required.");
    }

    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'POST' && isset($_POST['_method'])) {
        $method = strtoupper($_POST['_method']);
    }

    // =========================================================================
    // GET — retrieve candidates
    // =========================================================================
    if ($method === 'GET') {
        $id = $_GET['id'] ?? null;

        if ($id) {
            $stmt = $db->prepare("
                SELECT c.*, u.first_name, u.last_name, u.email, u.department, u.level,
                       e.title as election_title, e.status as election_status,
                       p.name as position_name, p.category as position_category
                FROM candidates c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN elections e ON c.election_id = e.id
                LEFT JOIN positions p ON c.position_id = p.id
                WHERE c.id = ?
            ");
            $stmt->execute([$id]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);

            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode($candidate
                ? ['success' => true, 'data' => $candidate]
                : ['success' => false, 'message' => 'Candidate not found']
            );
        } else {
            $search      = $_GET['search'] ?? '';
            $election_id = $_GET['election_id'] ?? '';
            $position_id = $_GET['position_id'] ?? '';

            $sql    = "
                SELECT c.*, u.first_name, u.last_name, u.email, u.department, u.level,
                       e.title as election_title, e.status as election_status,
                       p.name as position_name, p.category as position_category
                FROM candidates c
                LEFT JOIN users u ON c.user_id = u.id
                LEFT JOIN elections e ON c.election_id = e.id
                LEFT JOIN positions p ON c.position_id = p.id
                WHERE 1=1
            ";
            $params = [];

            if (!empty($search)) {
                $sql   .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR p.name LIKE ? OR e.title LIKE ?)";
                $term   = "%$search%";
                $params = array_merge($params, [$term, $term, $term, $term, $term]);
            }
            if (!empty($election_id)) { $sql .= " AND c.election_id = ?"; $params[] = $election_id; }
            if (!empty($position_id)) { $sql .= " AND c.position_id = ?"; $params[] = $position_id; }
            $sql .= " ORDER BY e.start_date DESC, p.name ASC, u.first_name ASC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true, 'data' => $candidates, 'count' => count($candidates)]);
        }
        exit;
    }

    // =========================================================================
    // POST — create candidate
    // =========================================================================
    if ($method === 'POST') {
        $inputData   = $_POST;
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'application/json') !== false) {
            $json = json_decode(file_get_contents('php://input'), true);
            if ($json) $inputData = array_merge($inputData, $json);
        }

        $required = ['user_id', 'election_id', 'position_id'];
        $missing  = array_filter($required, fn($f) => empty($inputData[$f]));
        if (!empty($missing)) {
            returnError("Missing required fields: " . implode(', ', $missing));
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT id, first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$inputData['user_id']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) throw new Exception("User not found");

            $stmt = $db->prepare("SELECT id, title FROM elections WHERE id = ?");
            $stmt->execute([$inputData['election_id']]);
            $election = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$election) throw new Exception("Election not found");

            $stmt = $db->prepare("SELECT id, name FROM positions WHERE id = ?");
            $stmt->execute([$inputData['position_id']]);
            $position = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$position) throw new Exception("Position not found");

            $stmt = $db->prepare("
                SELECT c.*, e.title as election_title, p.name as position_name
                FROM candidates c
                LEFT JOIN elections e ON c.election_id = e.id
                LEFT JOIN positions p ON c.position_id = p.id
                WHERE c.user_id = ? AND c.election_id = ? AND c.position_id = ?
            ");
            $stmt->execute([$inputData['user_id'], $inputData['election_id'], $inputData['position_id']]);
            $dup = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                throw new Exception("$userName is already registered as a candidate for {$dup['position_name']} in {$dup['election_title']}.");
            }

            // [FIX 5] Upload with MIME-derived extension
            $photoPath = null;
            if (!empty($_FILES['photo']['name'])) {
                $uploadDir = __DIR__ . '/../../uploads/candidates/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Failed to create upload directory");
                }

                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo        = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType     = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedTypes)) {
                    throw new Exception("Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.");
                }
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
                    throw new Exception("File size must be less than 2MB.");
                }

                $extension = mimeToExtension($mimeType); // [FIX 5]
                $filename  = uniqid('candidate_') . '_' . time() . '.' . $extension;
                $filepath  = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
                    throw new Exception("Failed to save uploaded photo");
                }
                $photoPath = 'uploads/candidates/' . $filename;
            }

            $stmt = $db->prepare(
                "INSERT INTO candidates (user_id, election_id, position_id, manifesto, photo_path, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([
                $inputData['user_id'], $inputData['election_id'], $inputData['position_id'],
                $inputData['manifesto'] ?? null, $photoPath
            ]);
            $candidateId = $db->lastInsertId();
            $db->commit();

            if (class_exists('ActivityLogger')) {
                (new ActivityLogger($db))->logActivity(
                    $_SESSION['user_id'] ?? 'system',
                    trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
                    'candidate_created', 'Candidate created successfully',
                    json_encode(['candidate_id' => $candidateId])
                );
            }

            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Candidate created successfully', 'candidate_id' => $candidateId]);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            // [FIX 4] Log trace server-side only
            error_log("Create candidate error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            returnError($e->getMessage());
        }
    }

    // =========================================================================
    // PUT — update candidate
    // =========================================================================
    if ($method === 'PUT') {
        $inputData = $_POST;
        if (empty($inputData)) {
            parse_str(file_get_contents('php://input'), $putData);
            $inputData = array_merge($inputData, $putData);
        }

        $id = $_GET['id'] ?? $inputData['id'] ?? $inputData['candidate_id'] ?? null;
        if (!$id) returnError("Candidate ID required for update");

        $required = ['user_id', 'election_id', 'position_id'];
        $missing  = array_filter($required, fn($f) => empty($inputData[$f]));
        if (!empty($missing)) {
            returnError("Missing required fields: " . implode(', ', $missing));
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT c.*, u.first_name, u.last_name FROM candidates c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
            $stmt->execute([$id]);
            $oldCandidate = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$oldCandidate) throw new Exception("Candidate not found");

            $stmt = $db->prepare("
                SELECT c.*, e.title as election_title, p.name as position_name
                FROM candidates c
                LEFT JOIN elections e ON c.election_id = e.id
                LEFT JOIN positions p ON c.position_id = p.id
                WHERE c.user_id = ? AND c.election_id = ? AND c.position_id = ? AND c.id != ?
            ");
            $stmt->execute([$inputData['user_id'], $inputData['election_id'], $inputData['position_id'], $id]);
            $dup = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($dup) {
                $uStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
                $uStmt->execute([$inputData['user_id']]);
                $u        = $uStmt->fetch(PDO::FETCH_ASSOC);
                $userName = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                throw new Exception("$userName is already registered as a candidate for {$dup['position_name']} in {$dup['election_title']}.");
            }

            // [FIX 5] MIME-derived extension
            $photoPath = $oldCandidate['photo_path'];
            if (!empty($_FILES['photo']['name'])) {
                $uploadDir = __DIR__ . '/../../uploads/candidates/';
                if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
                    throw new Exception("Failed to create upload directory");
                }

                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $finfo        = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType     = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedTypes)) {
                    throw new Exception("Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.");
                }
                if ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
                    throw new Exception("File size must be less than 2MB.");
                }

                $extension = mimeToExtension($mimeType); // [FIX 5]
                $filename  = uniqid('candidate_') . '_' . time() . '.' . $extension;
                $filepath  = $uploadDir . $filename;

                if (!move_uploaded_file($_FILES['photo']['tmp_name'], $filepath)) {
                    throw new Exception("Failed to save uploaded photo");
                }
                $photoPath = 'uploads/candidates/' . $filename;

                if ($oldCandidate['photo_path'] && file_exists(__DIR__ . '/../../' . $oldCandidate['photo_path'])) {
                    unlink(__DIR__ . '/../../' . $oldCandidate['photo_path']);
                }
            }

            $stmt = $db->prepare(
                "UPDATE candidates SET user_id = ?, election_id = ?, position_id = ?, manifesto = ?, photo_path = ? WHERE id = ?"
            );
            $stmt->execute([
                $inputData['user_id'], $inputData['election_id'], $inputData['position_id'],
                $inputData['manifesto'] ?? null, $photoPath, $id
            ]);
            $db->commit();

            if (class_exists('ActivityLogger')) {
                (new ActivityLogger($db))->logActivity(
                    $_SESSION['user_id'] ?? 'system',
                    trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
                    'candidate_updated', 'Candidate updated successfully',
                    json_encode(['candidate_id' => $id])
                );
            }

            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Candidate updated successfully', 'candidate_id' => $id]);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            error_log("Update candidate error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            returnError($e->getMessage());
        }
    }

    // =========================================================================
    // DELETE — remove candidate
    // =========================================================================
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) returnError("Candidate ID required");

        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT c.*, u.first_name, u.last_name FROM candidates c LEFT JOIN users u ON c.user_id = u.id WHERE c.id = ?");
            $stmt->execute([$id]);
            $candidate = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$candidate) throw new Exception("Candidate not found");

            if (!empty($candidate['photo_path'])) {
                $photoFile = __DIR__ . '/../../' . $candidate['photo_path'];
                if (file_exists($photoFile)) unlink($photoFile);
            }

            $stmt = $db->prepare("DELETE FROM candidates WHERE id = ?");
            if (!$stmt->execute([$id])) throw new Exception("Failed to delete candidate");

            $db->commit();

            if (class_exists('ActivityLogger')) {
                (new ActivityLogger($db))->logActivity(
                    $_SESSION['user_id'] ?? 'system',
                    trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')),
                    'candidate_deleted', 'Candidate deleted successfully',
                    json_encode([
                        'candidate_id'   => $id,
                        'candidate_name' => trim(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? ''))
                    ])
                );
            }

            while (ob_get_level() > 0) ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'Candidate deleted successfully', 'candidate_id' => $id]);
            exit;

        } catch (Exception $e) {
            $db->rollBack();
            error_log("Delete candidate error: " . $e->getMessage());
            returnError($e->getMessage());
        }
    }

    returnError("Method not allowed: " . $_SERVER['REQUEST_METHOD']);

} catch (Throwable $e) {
    // [FIX 4] Full details logged; generic message returned
    error_log("Unhandled exception in candidates.php: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    returnError("An unexpected error occurred. Please try again.");
}
