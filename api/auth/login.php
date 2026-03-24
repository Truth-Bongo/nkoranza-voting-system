<?php
// api/auth/login.php
// SECURITY PATCHES:
//   [1] session_regenerate_id(true) called after successful auth — fixes session fixation
//   [2] client_time removed from all security decisions — server time only
//   [3] Exception message no longer returned to client in 500 response

session_start();
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$username        = isset($input['username']) ? trim($input['username']) : (isset($input['id']) ? trim($input['id']) : '');
$password        = $input['password'] ?? '';
// [FIX 2] client_timezone kept for display only — client_time removed entirely.
$client_timezone = $input['client_timezone'] ?? null;

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing credentials']);
    exit;
}

try {
    $stmt = $db->prepare(
        'SELECT id, password, first_name, last_name, is_admin, has_logged_in, has_voted, voting_year, status, graduation_year
         FROM users WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($password, $user['password'])) {
        $activityLogger->logActivity(
            0, 'Unknown User', 'login_failed',
            "Failed login attempt for username: $username - Invalid credentials"
        );
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid username or password. Please check your credentials and try again.']);
        exit;
    }

    if (isset($user['status']) && $user['status'] === 'graduated') {
        $activityLogger->logActivity(
            $user['id'], $user['first_name'] . ' ' . $user['last_name'],
            'login_blocked', 'Graduated user attempted to login - Access denied'
        );
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Your account has been graduated. You can no longer access the voting system. If you believe this is an error, please contact the administration.'
        ]);
        exit;
    }

    date_default_timezone_set('Africa/Accra');

    // [FIX 2] Always use server time — client_time intentionally removed.
    $electionStmt = $db->query(
        "SELECT end_date FROM elections WHERE status = 'active' OR status = 'ended' ORDER BY id DESC LIMIT 1"
    );
    $election = $electionStmt->fetch(PDO::FETCH_ASSOC);

    $election_ended = false;
    if ($election) {
        $election_ended = time() > strtotime($election['end_date']);
    }

    // Scope the one-login-per-election rule to the CURRENT election year only.
    // If the user's voting_year does not match the active election's year, their
    // flags are stale from a previous year — allow them to log in.
    $activeElectionYear = null;
    if ($election) {
        $activeElectionYear = (int)date('Y', strtotime($election['end_date']));
    }
    $userVotingYear = isset($user['voting_year']) ? (int)$user['voting_year'] : null;
    $lockedForThisYear = $user['has_logged_in']
        && $userVotingYear !== null
        && $userVotingYear === $activeElectionYear;

    if ($lockedForThisYear && !($user['is_admin'] == 1 || $user['is_admin'] === true) && !$election_ended) {
        $activityLogger->logActivity(
            $user['id'], $user['first_name'] . ' ' . $user['last_name'],
            'login_attempt', 'Attempted to login again during the same election year'
        );
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You have already logged in and cannot login again during this election period.']);
        exit;
    }

    // [FIX 1] Regenerate session ID immediately after credential verification.
    // This invalidates any session ID an attacker may have planted before login
    // (session fixation attack). The true parameter deletes the old session file.
    session_regenerate_id(true);

    $_SESSION['user_id']        = $user['id'];
    $_SESSION['is_admin']       = ($user['is_admin'] == 1 || $user['is_admin'] === true);
    $_SESSION['first_name']     = $user['first_name'];
    $_SESSION['last_name']      = $user['last_name'];
    $_SESSION['full_name']      = trim($user['first_name'] . ' ' . $user['last_name']);
    $_SESSION['logged_in']      = true;
    $_SESSION['election_ended'] = $election_ended;

    if ($client_timezone) {
        $_SESSION['client_timezone'] = $client_timezone;
    }

    generate_csrf_token();

    if (!$_SESSION['is_admin'] && !$election_ended && !$lockedForThisYear) {
        // Record login for this election year so the year-scoped block works next time
        $loginYear = $activeElectionYear ?? (int)date('Y');
        $updateStmt = $db->prepare('UPDATE users SET has_logged_in = TRUE, voting_year = ? WHERE id = ?');
        $updateStmt->execute([$loginYear, $user['id']]);
        $activityLogger->logActivity($user['id'], $_SESSION['full_name'], 'login_success', 'Login successful - marked as logged in for election year ' . $loginYear);
    } else {
        $loginType = $election_ended ? 'results_view' : 'login_success';
        $message   = $election_ended
            ? 'Logged in to view election results'
            : 'Logged in successfully' . ($_SESSION['is_admin'] ? ' (Admin)' : '');
        $activityLogger->logActivity($user['id'], $_SESSION['full_name'], $loginType, $message);
    }

    echo json_encode([
        'success'       => true,
        'message'       => 'Login successful',
        'isAdmin'       => $_SESSION['is_admin'],
        'electionEnded' => $election_ended,
        'user_id'       => $user['id'],
        'username'      => $user['first_name'] . ' ' . $user['last_name']
    ]);
    exit;

} catch (Exception $e) {
    // [FIX 3] Log the real error server-side; never return internal details to the client.
    error_log("Login server error for '$username': " . $e->getMessage());
    $activityLogger->logActivity(0, 'System', 'login_error', "Server error during login for username: $username");

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'A server error occurred. Please try again later.']);
    exit;
}
