-- migration: add_login_attempts_table.sql
-- Run this against the nkoranza_voting database to enable brute-force protection.
--
-- After running this migration, apply the brute-force check in api/auth/login.php
-- (see the patch notes — the snippet to add is included as a comment below).

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(100) NOT NULL COMMENT 'Student ID that was tried',
    ip_address VARCHAR(45)  NOT NULL COMMENT 'IPv4 or IPv6 address of the requester',
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_attempts_identifier (identifier),
    INDEX idx_attempts_ip (ip_address),
    INDEX idx_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Tracks failed login attempts for rate limiting';

-- ============================================================================
-- PHP SNIPPET — add this to api/auth/login.php BEFORE the credential check,
-- right after the "Missing credentials" early-exit block.
-- ============================================================================
--
-- define('MAX_ATTEMPTS',     5);   // max failures before lockout
-- define('LOCKOUT_SECONDS', 900);  // 15-minute window
--
-- $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
-- $windowStart = date('Y-m-d H:i:s', time() - LOCKOUT_SECONDS);
--
-- // Count recent failures for this IP or username
-- $attemptStmt = $db->prepare(
--     'SELECT COUNT(*) FROM login_attempts
--      WHERE (identifier = ? OR ip_address = ?)
--        AND attempted_at > ?'
-- );
-- $attemptStmt->execute([$username, $ip, $windowStart]);
-- $recentFailures = (int)$attemptStmt->fetchColumn();
--
-- if ($recentFailures >= MAX_ATTEMPTS) {
--     http_response_code(429);
--     echo json_encode([
--         'success' => false,
--         'message' => 'Too many failed attempts. Please wait 15 minutes before trying again.'
--     ]);
--     exit;
-- }
--
-- Then, on every failed credential check (the !$user || !password_verify block),
-- INSERT a row:
--
-- $db->prepare(
--     'INSERT INTO login_attempts (identifier, ip_address) VALUES (?, ?)'
-- )->execute([$username, $ip]);
--
-- And on successful login, clean up old rows for that user/IP (optional):
--
-- $db->prepare(
--     'DELETE FROM login_attempts WHERE identifier = ? OR ip_address = ?'
-- )->execute([$username, $ip]);
-- ============================================================================
