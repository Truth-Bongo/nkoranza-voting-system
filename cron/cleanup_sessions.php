<?php
// cron/cleanup_sessions.php
// Run this script periodically via cron job
// Example cron: 0 */6 * * * php /path/to/cron/cleanup_sessions.php

require_once __DIR__ . '/../bootstrap.php';

try {
    $db = Database::getInstance()->getConnection();
    $sessionManager = getSessionManager();
    
    if ($sessionManager) {
        // Run garbage collection
        $cleaned = $sessionManager->gc(86400); // 24 hours
        
        // Also clean up old sessions manually
        $stmt = $db->prepare("
            DELETE FROM sessions 
            WHERE last_activity < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))
        ");
        $stmt->execute();
        $oldCleaned = $stmt->rowCount();
        
        $totalCleaned = ($cleaned ? 1 : 0) + $oldCleaned;
        
        // Log cleanup
        error_log("Session cleanup: removed $totalCleaned expired sessions");
        
        echo "✅ " . date('Y-m-d H:i:s') . " - Cleaned up $totalCleaned expired sessions\n";
    } else {
        echo "❌ Session manager not available\n";
    }
    
} catch (Exception $e) {
    error_log("Session cleanup error: " . $e->getMessage());
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}