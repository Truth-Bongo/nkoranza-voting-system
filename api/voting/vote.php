<?php
// api/voting/vote.php
// SECURITY PATCHES:
//   [1] Election status verified server-side before any vote is accepted
//   [2] Each candidate_id validated against the claimed position AND election
//   [3] Each position_id validated as belonging to the claimed election
//   [4] CSRF token now uses the shared function from functions.php (rotates after use)
//   [5] Exception message no longer returned to client

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Administrators cannot vote in elections']);
    exit;
}

if (empty($_SESSION['user_id']) || empty($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please login to vote']);
    exit;
}

$input       = json_decode(file_get_contents('php://input'), true);
$election_id = isset($input['election_id']) ? (int)$input['election_id'] : null;
$votes       = $input['votes'] ?? [];
$csrf_token  = $input['csrf_token'] ?? '';

if (!$election_id || empty($votes)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid vote data']);
    exit;
}

// [FIX 4] Use the shared validate_csrf_token() from functions.php which rotates the
// token after a single successful use, preventing replay attacks within the same session.
// The local duplicate definition that did NOT rotate has been removed.
if (!validate_csrf_token($csrf_token)) {
    http_response_code(403);

    try {
        $db = Database::getInstance()->getConnection();
        $activityLogger = new ActivityLogger($db);
        $activityLogger->logActivity(
            $_SESSION['user_id'],
            $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
            'vote_csrf_failure',
            'CSRF token validation failed during vote submission',
            json_encode(['election_id' => $election_id, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'])
        );
    } catch (Exception $e) {
        error_log("Error logging CSRF failure: " . $e->getMessage());
    }

    echo json_encode(['success' => false, 'message' => 'Invalid security token']);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $activityLogger = new ActivityLogger($db);

    // [FIX 1] Verify the election exists and is currently active using server time.
    // Without this check a voter with a valid session after election close could
    // still submit votes.
    $electionStmt = $db->prepare(
        "SELECT id FROM elections
         WHERE id = ?
           AND status = 'active'
           AND NOW() BETWEEN start_date AND end_date
         LIMIT 1"
    );
    $electionStmt->execute([$election_id]);
    if (!$electionStmt->fetch()) {
        $activityLogger->logActivity(
            $_SESSION['user_id'],
            $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
            'vote_invalid_election',
            'Attempted to vote in a non-active or non-existent election',
            json_encode(['election_id' => $election_id])
        );
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This election is not currently active']);
        exit;
    }

    // Check duplicate vote
    $stmt = $db->prepare('SELECT id FROM votes WHERE voter_id = ? AND election_id = ? LIMIT 1');
    $stmt->execute([$_SESSION['user_id'], $election_id]);
    if ($stmt->fetch()) {
        $activityLogger->logActivity(
            $_SESSION['user_id'],
            $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
            'vote_duplicate_attempt',
            'Attempted to vote again in election',
            json_encode(['election_id' => $election_id])
        );
        echo json_encode(['success' => false, 'message' => 'You have already voted in this election']);
        exit;
    }

    // [FIX 2 & 3] Validate every submitted position_id and candidate_id against the
    // database before inserting anything. This prevents a voter from crafting a request
    // that references candidates or positions from a different election, or that assigns
    // a candidate to a position they are not actually running for.
    $position_ids  = array_map('intval', array_keys($votes));
    $candidate_ids = [];
    foreach ($votes as $val) {
        if (is_numeric($val)) {
            $candidate_ids[] = (int)$val;
        }
    }

    // Verify all positions belong to this election
    if (!empty($position_ids)) {
        $placeholders = implode(',', array_fill(0, count($position_ids), '?'));
        $posCheck = $db->prepare(
            "SELECT COUNT(*) FROM positions
             WHERE election_id = ? AND id IN ($placeholders)"
        );
        $posCheck->execute(array_merge([$election_id], $position_ids));
        $validCount = (int)$posCheck->fetchColumn();
        if ($validCount !== count($position_ids)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid vote data']);
            exit;
        }
    }

    // Verify all candidates belong to the claimed position AND election
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

        foreach ($votes as $position_id => $val) {
            if (!is_numeric($val)) {
                continue; // 'no' votes — handled below, no candidate to validate
            }
            $cid = (int)$val;
            $pid = (int)$position_id;
            if (!isset($validCandidates[$cid]) || (int)$validCandidates[$cid] !== $pid) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid vote data']);
                exit;
            }
        }
    }

    $db->beginTransaction();

    $vote_ids = [];

    foreach ($votes as $position_id => $candidate_id_or_choice) {
        $position_id = (int)$position_id;
        $val         = $candidate_id_or_choice;

        if ($val === 'no') {
            $stmt = $db->prepare(
                'INSERT INTO votes (voter_id, election_id, position_id, candidate_id, rejected, timestamp)
                 VALUES (?, ?, ?, NULL, 1, NOW())'
            );
            $stmt->execute([$_SESSION['user_id'], $election_id, $position_id]);
            $vote_ids[] = $db->lastInsertId();
        } else {
            $candidate_id = is_numeric($val) ? (int)$val : null;
            $stmt = $db->prepare(
                'INSERT INTO votes (voter_id, election_id, position_id, candidate_id, rejected, timestamp)
                 VALUES (?, ?, ?, ?, 0, NOW())'
            );
            $stmt->execute([$_SESSION['user_id'], $election_id, $position_id, $candidate_id]);
            $vote_ids[] = $db->lastInsertId();
        }
    }

    // Record has_voted=1 and the election year so year-scoped eligibility checks work
    $elYearStmt = $db->prepare("SELECT YEAR(end_date) as yr FROM elections WHERE id = ?");
    $elYearStmt->execute([$election_id]);
    $elYear = (int)($elYearStmt->fetchColumn() ?: date('Y'));

    $updateUserStmt = $db->prepare(
        'UPDATE users SET has_voted = 1, voting_year = ? WHERE id = ?'
    );
    $updateUserStmt->execute([$elYear, $_SESSION['user_id']]);

    $db->commit();

    $_SESSION['has_voted'] = true;

    $activityLogger->logActivity(
        $_SESSION['user_id'],
        $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
        'vote_submitted',
        'Successfully submitted vote in election',
        json_encode([
            'election_id' => $election_id,
            'vote_count'  => count($votes),
            'vote_ids'    => $vote_ids,
            'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ])
    );

    echo json_encode([
        'success' => true,
        'message' => 'Vote submitted successfully',
        'vote_id' => end($vote_ids)
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    // [FIX 5] Log the real error; return only a generic message to the client.
    error_log("Vote submission error for user {$_SESSION['user_id']}: " . $e->getMessage());

    try {
        if (isset($activityLogger)) {
            $activityLogger->logActivity(
                $_SESSION['user_id'],
                $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
                'vote_submission_error',
                'Error submitting vote',
                json_encode(['election_id' => $election_id ?? null, 'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'])
            );
        }
    } catch (Exception $logError) {
        error_log("Error logging vote failure: " . $logError->getMessage());
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to submit vote. Please try again.']);
}
