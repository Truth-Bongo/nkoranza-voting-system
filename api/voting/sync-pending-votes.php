<?php
// api/voting/sync-pending-votes.php
// SECURITY PATCHES:
//   [1] CORS: reflected origin replaced with explicit allowlist + credentials only for
//       same-origin requests. Wildcard + credentials together bypass CSRF protection.
//   [2] CSRF token validation added (was entirely missing)
//   [3] Exception messages no longer returned to client

require_once __DIR__ . '/../../config/bootstrap.php';

session_start();
header('Content-Type: application/json');

// [FIX 1] Allowlist-based CORS.
// Only the application's own origin may send credentialed cross-origin requests.
// Never combine Access-Control-Allow-Credentials: true with a wildcard or reflected
// origin — doing so lets any website make authenticated requests using the visitor's
// session cookie, which defeats CSRF protection entirely.
$allowed_origins = [BASE_URL]; // defined in config/constants.php
$request_origin  = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($request_origin, $allowed_origins, true)) {
    header('Access-Control-Allow-Origin: ' . $request_origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    // For same-origin requests (no Origin header) or unlisted origins, do not emit
    // ACAO so the browser blocks cross-origin reads.
}

header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login to sync votes']);
    exit;
}

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrators cannot vote in elections']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit;
}

// [FIX 2] CSRF validation — this endpoint was previously unprotected.
// The service worker should include the CSRF token (stored in a non-HttpOnly cookie
// or returned from the login response) in the X-CSRF-Token request header or body.
$csrf_token = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!validate_csrf_token($csrf_token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

$pending_votes = $input['pending_votes'] ?? [];

if (empty($pending_votes)) {
    echo json_encode(['success' => false, 'message' => 'No pending votes to sync']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $results = ['successful' => [], 'failed' => []];

    foreach ($pending_votes as $vote_data) {
        $election_id       = isset($vote_data['election_id']) ? (int)$vote_data['election_id'] : null;
        $votes             = $vote_data['votes'] ?? [];
        $timestamp         = $vote_data['timestamp'] ?? null;
        $verification_code = $vote_data['verification_code'] ?? null;
        $user_id           = $vote_data['user_id'] ?? null;

        if (!$election_id || empty($votes) || !$timestamp || !$user_id) {
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'Missing required fields'
            ];
            continue;
        }

        // Verify user_id matches authenticated session
        if ($user_id != $_SESSION['user_id']) {
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'User mismatch'
            ];
            continue;
        }

        // Check duplicate vote
        $check_stmt = $db->prepare('SELECT id FROM votes WHERE voter_id = ? AND election_id = ? LIMIT 1');
        $check_stmt->execute([$_SESSION['user_id'], $election_id]);
        if ($check_stmt->fetch()) {
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'Already voted in this election'
            ];
            continue;
        }

        // Verify election exists (offline syncs allow a grace window after end_date,
        // but the election must have been real)
        $election_stmt = $db->prepare(
            'SELECT id, title, start_date, end_date, status FROM elections WHERE id = ?'
        );
        $election_stmt->execute([$election_id]);
        $election = $election_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$election) {
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'Election not found'
            ];
            continue;
        }

        // Validate position and candidate IDs before inserting
        $position_ids  = array_map('intval', array_keys($votes));
        $candidate_ids = [];
        foreach ($votes as $val) {
            if (is_numeric($val)) {
                $candidate_ids[] = (int)$val;
            }
        }

        // Positions must belong to this election
        $placeholders = implode(',', array_fill(0, count($position_ids), '?'));
        $posCheck = $db->prepare(
            "SELECT COUNT(*) FROM positions WHERE election_id = ? AND id IN ($placeholders)"
        );
        $posCheck->execute(array_merge([$election_id], $position_ids));
        if ((int)$posCheck->fetchColumn() !== count($position_ids)) {
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'Invalid position data'
            ];
            continue;
        }

        // Candidates must belong to the claimed position and election
        $valid = true;
        if (!empty($candidate_ids)) {
            $placeholders = implode(',', array_fill(0, count($candidate_ids), '?'));
            $candCheck = $db->prepare(
                "SELECT id, position_id FROM candidates
                 WHERE election_id = ? AND status = 'active' AND id IN ($placeholders)"
            );
            $candCheck->execute(array_merge([$election_id], $candidate_ids));
            $validCandidates = [];
            while ($row = $candCheck->fetch()) {
                $validCandidates[$row['id']] = $row['position_id'];
            }

            foreach ($votes as $pid => $val) {
                if (!is_numeric($val)) {
                    continue;
                }
                $cid = (int)$val;
                if (!isset($validCandidates[$cid]) || (int)$validCandidates[$cid] !== (int)$pid) {
                    $valid = false;
                    break;
                }
            }
        }

        if (!$valid) {
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'Invalid candidate data'
            ];
            continue;
        }

        // Insert votes inside a transaction
        try {
            $db->beginTransaction();

            foreach ($votes as $position_id => $candidate_id_or_choice) {
                $position_id = (int)$position_id;
                $val         = $candidate_id_or_choice;

                if ($val === 'no') {
                    $stmt = $db->prepare(
                        'INSERT INTO votes (voter_id, election_id, position_id, candidate_id, rejected,
                                           offline_synced, verification_code, timestamp)
                         VALUES (?, ?, ?, NULL, 1, 1, ?, NOW())'
                    );
                    $stmt->execute([$_SESSION['user_id'], $election_id, $position_id, $verification_code]);
                } else {
                    $candidate_id = is_numeric($val) ? (int)$val : null;
                    $stmt = $db->prepare(
                        'INSERT INTO votes (voter_id, election_id, position_id, candidate_id, rejected,
                                           offline_synced, verification_code, timestamp)
                         VALUES (?, ?, ?, ?, 0, 1, ?, NOW())'
                    );
                    $stmt->execute([$_SESSION['user_id'], $election_id, $position_id, $candidate_id, $verification_code]);
                }
            }

            $elYrStmt = $db->prepare("SELECT YEAR(end_date) as yr FROM elections WHERE id = ?");
            $elYrStmt->execute([$election_id]);
            $elYr = (int)($elYrStmt->fetchColumn() ?: date('Y'));

            $updateUserStmt = $db->prepare(
                'UPDATE users SET has_voted = 1, voting_year = ? WHERE id = ?'
            );
            $updateUserStmt->execute([$elYr, $_SESSION['user_id']]);

            $db->commit();

            $results['successful'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp
            ];

        } catch (Exception $innerEx) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            // [FIX 3] Log internally; generic reason to client
            error_log("Sync vote error for user {$_SESSION['user_id']}, election $election_id: " . $innerEx->getMessage());
            $results['failed'][] = [
                'election_id' => $election_id,
                'timestamp'   => $timestamp,
                'reason'      => 'Server error processing vote'
            ];
        }
    }

    echo json_encode([
        'success' => true,
        'results' => $results
    ]);

} catch (Exception $e) {
    // [FIX 3] Do not expose internal error details
    error_log("Sync pending votes error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again.']);
}
