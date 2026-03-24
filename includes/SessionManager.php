<?php
// includes/SessionManager.php
// SECURITY PATCH:
//   [1] session.cookie_secure: isset($_SERVER['HTTPS']) replaced with a correct check.
//       isset() returns true even when $_SERVER['HTTPS'] === 'off' (Apache's way of
//       signalling a plain HTTP connection), meaning the Secure flag could be omitted
//       on HTTPS or incorrectly set on HTTP depending on the server config.

class SessionManager {
    private $db;
    private $sessionLifetime;
    private $sessionName;

    public function __construct($db) {
        $this->db              = $db;
        $this->sessionLifetime = $this->getSessionLifetime();
        $this->sessionName     = 'voting_session';

        session_set_save_handler(
            [$this, 'open'],
            [$this, 'close'],
            [$this, 'read'],
            [$this, 'write'],
            [$this, 'destroy'],
            [$this, 'gc']
        );

        ini_set('session.gc_maxlifetime',  $this->sessionLifetime);
        ini_set('session.cookie_lifetime', $this->sessionLifetime);
        ini_set('session.cookie_httponly', 1);
        // [FIX 1] isset() is true even when HTTPS === 'off' (Apache signals non-TLS
        // that way). The correct test checks both that the key exists AND that it is
        // not the literal string 'off'.
        ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 1 : 0);
        ini_set('session.cookie_samesite', 'Strict');
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);

        session_name($this->sessionName);
    }

    private function getSessionLifetime() {
        try {
            $stmt = $this->db->prepare(
                "SELECT setting_value FROM settings WHERE setting_key = 'session_timeout'"
            );
            $stmt->execute();
            $timeout = $stmt->fetchColumn();
            return $timeout ? (int)$timeout : 3600;
        } catch (Exception $e) {
            error_log("Error getting session timeout: " . $e->getMessage());
            return 3600;
        }
    }

    public function open($savePath, $sessionName) { return true; }
    public function close()                        { return true; }

    public function read($sessionId) {
        try {
            $stmt = $this->db->prepare(
                "SELECT payload FROM sessions WHERE session_id = ? AND last_activity > ?"
            );
            $stmt->execute([$sessionId, time() - $this->sessionLifetime]);
            $result = $stmt->fetchColumn();
            return $result ?: '';
        } catch (Exception $e) {
            error_log("Session read error: " . $e->getMessage());
            return '';
        }
    }

    public function write($sessionId, $data) {
        try {
            $userId    = $_SESSION['user_id'] ?? null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            $stmt = $this->db->prepare(
                "INSERT INTO sessions (session_id, user_id, ip_address, user_agent, payload, last_activity, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                     user_id = VALUES(user_id),
                     ip_address = VALUES(ip_address),
                     user_agent = VALUES(user_agent),
                     payload = VALUES(payload),
                     last_activity = VALUES(last_activity)"
            );
            return $stmt->execute([$sessionId, $userId, $ipAddress, $userAgent, $data, time()]);
        } catch (Exception $e) {
            error_log("Session write error: " . $e->getMessage());
            return false;
        }
    }

    public function destroy($sessionId) {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE session_id = ?");
            return $stmt->execute([$sessionId]);
        } catch (Exception $e) {
            error_log("Session destroy error: " . $e->getMessage());
            return false;
        }
    }

    public function gc($maxLifetime) {
        try {
            $stmt = $this->db->prepare("DELETE FROM sessions WHERE last_activity < ?");
            return $stmt->execute([time() - $maxLifetime]);
        } catch (Exception $e) {
            error_log("Session GC error: " . $e->getMessage());
            return false;
        }
    }

    public function getActiveSessions() {
        try {
            $stmt = $this->db->prepare(
                "SELECT s.*, u.first_name, u.last_name, u.email, u.is_admin
                 FROM sessions s
                 LEFT JOIN users u ON s.user_id = u.id
                 WHERE s.last_activity > ?
                 ORDER BY s.last_activity DESC"
            );
            $stmt->execute([time() - $this->sessionLifetime]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting active sessions: " . $e->getMessage());
            return [];
        }
    }

    public function terminateSession($sessionId) {
        return $this->destroy($sessionId);
    }

    public function terminateAllUserSessions($userId, $currentSessionId = null) {
        try {
            $sql    = "DELETE FROM sessions WHERE user_id = ?";
            $params = [$userId];
            if ($currentSessionId) {
                $sql    .= " AND session_id != ?";
                $params[] = $currentSessionId;
            }
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (Exception $e) {
            error_log("Error terminating user sessions: " . $e->getMessage());
            return false;
        }
    }

    public function getSessionStats() {
        try {
            $stmt = $this->db->query(
                "SELECT
                     COUNT(*) as total_sessions,
                     COUNT(CASE WHEN user_id IS NOT NULL THEN 1 END) as authenticated_sessions,
                     COUNT(CASE WHEN last_activity > UNIX_TIMESTAMP(NOW() - INTERVAL 1 HOUR) THEN 1 END) as active_last_hour,
                     MAX(last_activity) as last_session_time,
                     MIN(created_at) as oldest_session
                 FROM sessions
                 WHERE last_activity > ?"
            );
            $stmt->execute([time() - $this->sessionLifetime]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting session stats: " . $e->getMessage());
            return [];
        }
    }
}
