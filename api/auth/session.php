<?php
// controllers/AuthController.php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/ActivityLogger.php';

class AuthController {
    private $db;
    private $activityLogger;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->activityLogger = new ActivityLogger($this->db);
    }
    
    /**
     * Check if user session is valid
     */
    public function checkSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Log session check activity
        if (isset($_SESSION['user_id']) && isset($_SESSION['full_name'])) {
            $this->activityLogger->logActivity(
                $_SESSION['user_id'],
                $_SESSION['full_name'],
                'session_check',
                'User session status checked'
            );
        } else {
            $this->activityLogger->logActivity(
                0,
                'Guest',
                'session_check',
                'Guest session status checked'
            );
        }
        
        if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
            return [
                'success' => true,
                'logged_in' => true,
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'first_name' => $_SESSION['first_name'],
                    'last_name' => $_SESSION['last_name'],
                    'full_name' => $_SESSION['full_name'],
                    'is_admin' => $_SESSION['is_admin']
                ],
                'election_ended' => $_SESSION['election_ended'] ?? false
            ];
        } else {
            return [
                'success' => true,
                'logged_in' => false,
                'message' => 'No active session'
            ];
        }
    }
    
    /**
     * Other authentication methods can be added here
     */
}
?>