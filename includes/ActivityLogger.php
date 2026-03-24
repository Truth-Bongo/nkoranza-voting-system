<?php
// includes/ActivityLogger.php
class ActivityLogger {
    private $db;
    private $table = 'activity_logs';
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Log user activity
     */
    public function logActivity($userId, $userName, $activityType, $description, $details = null) {
        try {
            $ipAddress = $this->getClientIp();
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
            
            // Convert details to JSON if it's an array
            if (is_array($details) || is_object($details)) {
                $details = json_encode($details);
            }
            
            // Ensure details is a string or null
            if ($details !== null && !is_string($details)) {
                $details = (string)$details;
            }
            
            $stmt = $this->db->prepare("
                INSERT INTO {$this->table} 
                (user_id, user_name, activity_type, description, ip_address, user_agent, details, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $userId, 
                $userName, 
                $activityType, 
                $description, 
                $ipAddress, 
                $userAgent,
                $details
            ]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Activity log error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get activities with filters, pagination, and sorting
     */
    public function getActivities($filters = [], $limit = 50, $offset = 0, $sortBy = 'created_at', $sortOrder = 'DESC') {
        try {
            $whereClauses = [];
            $params = [];
            
            // Build WHERE clause based on filters
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
            
            // Validate sort parameters
            $allowedSortColumns = ['id', 'user_id', 'user_name', 'activity_type', 'description', 'ip_address', 'created_at'];
            $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
            $sortOrder = strtoupper($sortOrder) === 'ASC' ? 'ASC' : 'DESC';
            
            // Get total count for pagination
            $countStmt = $this->db->prepare("SELECT COUNT(*) as total FROM {$this->table} $whereSQL");
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Get activities with pagination
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table} 
                $whereSQL 
                ORDER BY $sortBy $sortOrder 
                LIMIT ? OFFSET ?
            ");
            
            // Add limit and offset to params
            $allParams = array_merge($params, [(int)$limit, (int)$offset]);
            $stmt->execute($allParams);
            $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Parse JSON details
            foreach ($activities as &$activity) {
                if (!empty($activity['details'])) {
                    $details = json_decode($activity['details'], true);
                    $activity['details'] = $details !== null ? $details : $activity['details'];
                }
            }
            
            return [
                'activities' => $activities,
                'total' => (int)$total,
                'total_pages' => ceil($total / $limit)
            ];
        } catch (PDOException $e) {
            error_log("Get activities error: " . $e->getMessage());
            return [
                'activities' => [], 
                'total' => 0,
                'total_pages' => 1
            ];
        }
    }
    
    /**
     * Get client IP address
     */
    private function getClientIp() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        
        // Check for proxy headers
        $forwardedHeaders = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED'];
        
        foreach ($forwardedHeaders as $header) {
            if (!empty($_SERVER[$header])) {
                $ipList = explode(',', $_SERVER[$header]);
                $ip = trim($ipList[0]);
                break;
            }
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'Unknown';
    }
    
    /**
     * Clean up old logs
     */
    public function cleanupOldLogs($daysToKeep = 90) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM {$this->table} 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            
            $stmt->execute([$daysToKeep]);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Cleanup logs error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get activity types for filter dropdown
     */
    public function getActivityTypes() {
        try {
            $stmt = $this->db->query("
                SELECT DISTINCT activity_type 
                FROM {$this->table} 
                ORDER BY activity_type
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("Get activity types error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent activities for dashboard
     */
    public function getRecentActivities($limit = 5) {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM {$this->table} 
                ORDER BY created_at DESC 
                LIMIT ?
            ");
            
            $stmt->execute([$limit]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Get recent activities error: " . $e->getMessage());
            return [];
        }
    }
}
?>