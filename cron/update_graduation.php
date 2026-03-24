<?php
// cron/update_graduation.php
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../helpers/functions.php';

try {
    $db = Database::getInstance()->getConnection();
    $current_year = date('Y');
    $current_date = date('Y-m-d H:i:s');
    
    // Begin transaction
    $db->beginTransaction();
    
    // Find users who should be marked as graduated
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, graduation_year 
        FROM users 
        WHERE is_admin = 0 
        AND status = 'active'
        AND graduation_year IS NOT NULL 
        AND graduation_year <= ?
    ");
    $stmt->execute([$current_year]);
    $to_graduate = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($to_graduate)) {
        $db->commit();
        echo "[" . date('Y-m-d H:i:s') . "] No students to graduate.\n";
        exit(0);
    }
    
    // Update their status
    $update = $db->prepare("
        UPDATE users 
        SET status = 'graduated',
            graduated_at = ?
        WHERE is_admin = 0 
        AND status = 'active'
        AND graduation_year IS NOT NULL 
        AND graduation_year <= ?
    ");
    $update->execute([$current_date, $current_year]);
    $updated_count = $update->rowCount();
    
    // Log to activity_logs
    $log_stmt = $db->prepare("
        INSERT INTO activity_logs 
        (user_id, user_name, activity_type, description, details, created_at) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $log_stmt->execute([
        'SYSTEM',
        'System Auto-Update',
        'graduation_update',
        "Auto-graduated {$updated_count} students",
        json_encode([
            'year' => $current_year,
            'students' => array_column($to_graduate, 'id'),
            'count' => $updated_count
        ]),
        $current_date
    ]);
    
    $db->commit();
    
    // Send notification to admin (optional)
    if ($updated_count > 0) {
        $message = "Auto-graduated {$updated_count} students:\n";
        foreach ($to_graduate as $student) {
            $message .= "- {$student['first_name']} {$student['last_name']} ({$student['id']}) - Class of {$student['graduation_year']}\n";
        }
        
        // Log to file
        error_log("[GRADUATION] " . $message);
        
        // Optional: Send email to admin
        // mail('admin@school.edu', 'Graduation Update', $message);
    }
    
    echo "[" . date('Y-m-d H:i:s') . "] Successfully graduated {$updated_count} students.\n";
    
} catch (Exception $e) {
    if (isset($db)) {
        $db->rollBack();
    }
    error_log("Graduation cron error: " . $e->getMessage());
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    exit(1);
}