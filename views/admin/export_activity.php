<?php
// admin/export_activity.php
// Export activity logs to CSV or Excel format

declare(strict_types=1);

// Enable error logging only
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Start output buffering to prevent any accidental output
ob_start();

require_once __DIR__ . '/../../config/bootstrap.php'; // Fixed: was '/../config' (one level too shallow)

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// CSRF token validation
function validateCsrfToken(): bool {
    $token = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Only admin should access this page
if (empty($_SESSION['is_admin'])) {
    ob_end_clean();
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// CSRF validation for export (prevents external triggering)
if (!validateCsrfToken()) {
    error_log("CSRF validation failed for export_activity.php");
    $_SESSION['flash_message'] = 'Security validation failed. Please try again.';
    $_SESSION['flash_type'] = 'error';
    ob_end_clean();
    header('Location: ' . BASE_URL . '/index.php?page=admin/activity_logs');
    exit;
}

require_once APP_ROOT . '/includes/ActivityLogger.php';
require_once APP_ROOT . '/helpers/functions.php';

try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    ob_end_clean();
    die("System temporarily unavailable. Please try again later.");
}

$logger = new ActivityLogger($db);

// Clear output buffer before sending file
ob_end_clean();

// Get export format (default to CSV)
$format = $_GET['format'] ?? 'csv';
if (!in_array($format, ['csv', 'excel'])) {
    $format = 'csv';
}

// Get filter parameters (same as activity logs page)
$filters = [
    'user_id' => !empty($_GET['user_id']) ? trim($_GET['user_id']) : null,
    'activity_type' => !empty($_GET['type']) ? trim($_GET['type']) : null,
    'search' => !empty($_GET['search']) ? trim($_GET['search']) : null,
    'date_from' => !empty($_GET['date_from']) ? $_GET['date_from'] : null,
    'date_to' => !empty($_GET['date_to']) ? $_GET['date_to'] : null
];

// Remove empty filters
$filters = array_filter($filters, function($value) {
    return $value !== null && $value !== '';
});

// Get sort parameters
$sortBy = $_GET['sort_by'] ?? 'created_at';
$sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';

// Validate sort column
$allowedSortColumns = ['id', 'user_id', 'user_name', 'activity_type', 'description', 'ip_address', 'created_at'];
$sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
$sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';

try {
    // Build WHERE clause
    $whereClauses = [];
    $params = [];
    
    if (!empty($filters['user_id'])) {
        $whereClauses[] = "user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if (!empty($filters['activity_type'])) {
        $whereClauses[] = "activity_type = ?";
        $params[] = $filters['activity_type'];
    }
    
    if (!empty($filters['search'])) {
        $whereClauses[] = "(user_name LIKE ? OR description LIKE ?)";
        $searchTerm = "%{$filters['search']}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($filters['date_from'])) {
        $whereClauses[] = "DATE(created_at) >= ?";
        $params[] = $filters['date_from'];
    }
    
    if (!empty($filters['date_to'])) {
        $whereClauses[] = "DATE(created_at) <= ?";
        $params[] = $filters['date_to'];
    }
    
    $whereSQL = '';
    if (!empty($whereClauses)) {
        $whereSQL = 'WHERE ' . implode(' AND ', $whereClauses);
    }
    
    // Get all activities matching filters (no pagination)
    $stmt = $db->prepare("
        SELECT 
            id,
            user_id,
            user_name,
            activity_type,
            description,
            ip_address,
            user_agent,
            details,
            created_at
        FROM activity_logs 
        $whereSQL 
        ORDER BY $sortBy $sortOrder
    ");
    
    $stmt->execute($params);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log the export activity
    $userId = $_SESSION['user_id'] ?? 'system';
    $userName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($userName)) {
        $userName = 'Admin User';
    }
    
    $logger->logActivity(
        $userId,
        $userName,
        'export_data',
        "Exported activity logs ({$format})",
        json_encode([
            'filters' => $filters,
            'record_count' => count($activities),
            'format' => strtoupper($format)
        ])
    );
    
    // Generate filename
    $filename = 'activity_logs_' . date('Y-m-d_His');
    
    if ($format === 'excel') {
        // Excel format (XLSX-like XML format)
        $filename .= '.xls';
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        
        // Create Excel XML format
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        echo ' xmlns:o="urn:schemas-microsoft-com:office:office"';
        echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
        echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        echo ' <Styles>';
        echo '  <Style ss:ID="1">';
        echo '   <Font ss:Bold="1"/>';
        echo '  </Style>';
        echo ' </Styles>';
        echo ' <Worksheet ss:Name="Activity Logs">' . "\n";
        echo '  <Table>' . "\n";
        
        // Headers
        echo '   <Row ss:StyleID="1">' . "\n";
        $headers = ['ID', 'User ID', 'User Name', 'Activity Type', 'Description', 'IP Address', 'User Agent', 'Date & Time', 'Details'];
        foreach ($headers as $header) {
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($header) . '</Data></Cell>' . "\n";
        }
        echo '   </Row>' . "\n";
        
        // Data rows
        foreach ($activities as $activity) {
            echo '   <Row>' . "\n";
            echo '    <Cell><Data ss:Type="Number">' . (int)$activity['id'] . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($activity['user_id'] ?? '') . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($activity['user_name'] ?? '') . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($activity['activity_type'] ?? '') . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($activity['description'] ?? '') . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($activity['ip_address'] ?? '') . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($activity['user_agent'] ?? '') . '</Data></Cell>' . "\n";
            echo '    <Cell><Data ss:Type="DateTime">' . date('Y-m-d\TH:i:s', strtotime($activity['created_at'])) . '</Data></Cell>' . "\n";
            
            // Details - parse JSON if present
            $details = '';
            if (!empty($activity['details'])) {
                $detailData = json_decode($activity['details'], true);
                if ($detailData) {
                    $details = json_encode($detailData, JSON_PRETTY_PRINT);
                } else {
                    $details = $activity['details'];
                }
            }
            echo '    <Cell><Data ss:Type="String">' . htmlspecialchars($details) . '</Data></Cell>' . "\n";
            echo '   </Row>' . "\n";
        }
        
        echo '  </Table>' . "\n";
        echo ' </Worksheet>' . "\n";
        echo '</Workbook>';
        
    } else {
        // CSV format
        $filename .= '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
        
        // Add headers
        fputcsv($output, [
            'ID',
            'User ID',
            'User Name',
            'Activity Type',
            'Description',
            'IP Address',
            'User Agent',
            'Date & Time',
            'Details'
        ]);
        
        // Add data rows
        foreach ($activities as $activity) {
            // Parse details
            $details = '';
            if (!empty($activity['details'])) {
                $detailData = json_decode($activity['details'], true);
                if ($detailData) {
                    $details = json_encode($detailData);
                } else {
                    $details = $activity['details'];
                }
            }
            
            fputcsv($output, [
                $activity['id'],
                $activity['user_id'],
                $activity['user_name'],
                $activity['activity_type'],
                $activity['description'],
                $activity['ip_address'] ?? '',
                $activity['user_agent'] ?? '',
                date('Y-m-d H:i:s', strtotime($activity['created_at'])),
                $details
            ]);
        }
        
        fclose($output);
    }
    
    exit;
    
} catch (Exception $e) {
    error_log("Export error: " . $e->getMessage());
    
    // Log the error
    try {
        if (isset($logger)) {
            $logger->logActivity(
                $_SESSION['user_id'] ?? 'system',
                $_SESSION['first_name'] ?? 'System' . ' ' . $_SESSION['last_name'] ?? '',
                'export_error',
                'Failed to export activity logs',
                json_encode(['error' => $e->getMessage()])
            );
        }
    } catch (Exception $logError) {
        error_log("Failed to log export error: " . $logError->getMessage());
    }
    
    // Set flash message and redirect
    $_SESSION['flash_message'] = 'Failed to export activity logs. Please try again.';
    $_SESSION['flash_type'] = 'error';
    
    // Ensure no output has been sent
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    header('Location: ' . BASE_URL . '/index.php?page=admin/activity_logs');
    exit;
}