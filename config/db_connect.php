<?php
// config/db_connect.php
// SECURITY PATCH:
//   [1] die() with raw PDOException message replaced with a logged error + clean exit.
//       The original die() printed the DB host, credentials hint, and schema info
//       to the browser in plaintext, breaking JSON responses and leaking internals.

class Database {
    private static $instance = null;
    private $conn;

    private $host = DB_HOST;
    private $db   = DB_NAME;
    private $user = DB_USER;
    private $pass = DB_PASS;

    private function __construct() {
        try {
            $this->conn = new PDO(
                "mysql:host={$this->host};dbname={$this->db};charset=utf8mb4",
                $this->user,
                $this->pass,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            // [FIX 1] Log the real error server-side only. Never expose DB connection
            // details (host, credentials, driver version) to the HTTP response.
            error_log("Database connection failed: " . $e->getMessage());

            // Emit a clean JSON error if headers haven't been sent yet,
            // otherwise just stop execution silently.
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(503);
                echo json_encode([
                    'success' => false,
                    'message' => 'Service temporarily unavailable. Please try again later.'
                ]);
            }
            exit;
        }
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->conn;
    }
}
