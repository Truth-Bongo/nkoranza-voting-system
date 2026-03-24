<?php
// api/admin/voter-stats.php
// Returns statistics about active and graduated voters with level breakdown

// Enable error logging but don't display errors
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Start output buffering
ob_start();

// Set JSON header
header('Content-Type: application/json');

try {
    // Define constants if not already defined
    if (!defined('APP_ROOT')) {
        define('APP_ROOT', dirname(__DIR__, 2));
    }

    // Check if required files exist
    $requiredFiles = [
        '/../../config/constants.php',
        '/../../config/db_connect.php'
    ];

    foreach ($requiredFiles as $file) {
        $fullPath = __DIR__ . $file;
        if (!file_exists($fullPath)) {
            throw new Exception("Required file not found: " . $file);
        }
        require_once $fullPath;
    }

    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Admin check
    if (empty($_SESSION['is_admin'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }

    // Get database connection
    try {
        $db = Database::getInstance()->getConnection();
        $db->exec("USE nkoranza_voting");
    } catch (Exception $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }

    $currentYear = date('Y');
    
    // Count active voters (not graduated)
    $activeStmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE is_admin = 0 
        AND (graduation_year IS NULL OR graduation_year > ?)
    ");
    $activeStmt->execute([$currentYear]);
    $activeCount = $activeStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count graduated voters
    $gradStmt = $db->prepare("
        SELECT COUNT(*) as count 
        FROM users 
        WHERE is_admin = 0 
        AND graduation_year IS NOT NULL 
        AND graduation_year <= ?
    ");
    $gradStmt->execute([$currentYear]);
    $gradCount = $gradStmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get level statistics for active students (based on first digit)
    $levelStmt = $db->prepare("
        SELECT 
            SUM(CASE WHEN level LIKE '1%' THEN 1 ELSE 0 END) as level_100,
            SUM(CASE WHEN level LIKE '2%' THEN 1 ELSE 0 END) as level_200,
            SUM(CASE WHEN level LIKE '3%' THEN 1 ELSE 0 END) as level_300
        FROM users 
        WHERE is_admin = 0 
        AND (graduation_year IS NULL OR graduation_year > ?)
    ");
    $levelStmt->execute([$currentYear]);
    $levelStats = $levelStmt->fetch(PDO::FETCH_ASSOC);
    
    // Clear output buffer and send response
    ob_end_clean();
    
    echo json_encode([
        'success' => true,
        'active_count' => (int)$activeCount,
        'graduated_count' => (int)$gradCount,
        'total_voters' => (int)($activeCount + $gradCount),
        'current_year' => $currentYear,
        'level_stats' => [
            'level_100' => (int)($levelStats['level_100'] ?? 0),
            'level_200' => (int)($levelStats['level_200'] ?? 0),
            'level_300' => (int)($levelStats['level_300'] ?? 0)
        ]
    ]);
    
} catch (Exception $e) {
    // Clear output buffer
    ob_end_clean();
    
    error_log("Voter stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>