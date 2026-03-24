<?php
// admin/settings.php
// System settings management interface

require_once __DIR__ . '/../bootstrap.php';
require_once APP_ROOT . '/includes/ActivityLogger.php';

// Check admin authentication
if (!is_admin()) {
    $_SESSION['flash_message'] = 'Access denied. Admin privileges required.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL . '/index.php?page=login');
    exit;
}

// Initialize database connection
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("System temporarily unavailable. Please try again later.");
}

$activityLogger = new ActivityLogger($db);
$userId = $_SESSION['user_id'];
$userName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');

// Handle form submissions
$activeTab = $_GET['tab'] ?? 'general';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_settings':
                // Update multiple settings
                $settings = $_POST['settings'] ?? [];
                $updated = 0;
                
                try {
                    $db->beginTransaction();
                    
                    foreach ($settings as $key => $value) {
                        $type = $_POST['setting_types'][$key] ?? 'text';
                        
                        // Validate based on type
                        if ($type === 'number') {
                            $value = is_numeric($value) ? (float)$value : 0;
                        } elseif ($type === 'boolean') {
                            $value = $value === '1' || $value === 'true' ? 'true' : 'false';
                        } elseif ($type === 'json') {
                            // Validate JSON
                            json_decode($value);
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                throw new Exception("Invalid JSON for setting: $key");
                            }
                        }
                        
                        $stmt = $db->prepare("
                            UPDATE settings 
                            SET setting_value = ?, updated_at = NOW() 
                            WHERE setting_key = ?
                        ");
                        
                        if ($stmt->execute([$value, $key]) && $stmt->rowCount() > 0) {
                            $updated++;
                        }
                    }
                    
                    $db->commit();
                    
                    $activityLogger->logActivity(
                        $userId,
                        $userName,
                        'settings_update',
                        "Updated $updated system settings",
                        json_encode(['updated_settings' => array_keys($settings)])
                    );
                    
                    $success = "$updated settings updated successfully!";
                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("Settings update error: " . $e->getMessage());
                    $error = 'Failed to update settings. Please try again.';
                    error_log("Settings update error: " . $e->getMessage());
                }
                break;
                
            case 'add_setting':
                // Add new custom setting
                $key = preg_replace('/[^a-z0-9_]/i', '', $_POST['new_key'] ?? '');
                $value = $_POST['new_value'] ?? '';
                $type = $_POST['new_type'] ?? 'text';
                $description = $_POST['new_description'] ?? '';
                
                if (empty($key)) {
                    $error = 'Setting key is required and can only contain letters, numbers, and underscores.';
                } else {
                    try {
                        // Check if key already exists
                        $stmt = $db->prepare("SELECT id FROM settings WHERE setting_key = ?");
                        $stmt->execute([$key]);
                        
                        if ($stmt->fetch()) {
                            $error = "Setting key '$key' already exists.";
                        } else {
                            $stmt = $db->prepare("
                                INSERT INTO settings (setting_key, setting_value, setting_type, description, created_at)
                                VALUES (?, ?, ?, ?, NOW())
                            ");
                            $stmt->execute([$key, $value, $type, $description]);
                            
                            $activityLogger->logActivity(
                                $userId,
                                $userName,
                                'setting_added',
                                "Added new setting: $key",
                                json_encode(['key' => $key, 'type' => $type])
                            );
                            
                            $success = "New setting '$key' added successfully!";
                        }
                    } catch (Exception $e) {
                        error_log("Add setting error: " . $e->getMessage());
                    $error = 'Failed to add setting. Please try again.';
                    }
                }
                break;
                
            case 'delete_setting':
                // Delete custom setting (non-default only)
                $settingId = (int)($_POST['setting_id'] ?? 0);
                
                try {
                    // Check if it's a default setting
                    $stmt = $db->prepare("
                        SELECT setting_key FROM settings 
                        WHERE id = ? AND setting_key NOT IN (
                            'site_name', 'site_description', 'contact_email', 
                            'voting_start_time', 'voting_end_time', 'allow_offline_voting',
                            'require_verification', 'max_login_attempts', 'session_timeout',
                            'maintenance_mode', 'current_academic_year', 'id_format'
                        )
                    ");
                    $stmt->execute([$settingId]);
                    $setting = $stmt->fetch();
                    
                    if (!$setting) {
                        $error = "Cannot delete default settings.";
                    } else {
                        $stmt = $db->prepare("DELETE FROM settings WHERE id = ?");
                        $stmt->execute([$settingId]);
                        
                        $activityLogger->logActivity(
                            $userId,
                            $userName,
                            'setting_deleted',
                            "Deleted setting: {$setting['setting_key']}"
                        );
                        
                        $success = "Setting deleted successfully!";
                    }
                } catch (Exception $e) {
                    error_log("Delete setting error: " . $e->getMessage());
                    $error = 'Failed to delete setting. Please try again.';
                }
                break;
                
            case 'clear_sessions':
                // Clear expired sessions
                try {
                    $sessionManager = getSessionManager();
                    $cleared = $sessionManager ? $sessionManager->gc(3600) : 0;
                    
                    // Also clear old sessions manually
                    $stmt = $db->prepare("
                        DELETE FROM sessions 
                        WHERE last_activity < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 1 DAY))
                    ");
                    $stmt->execute();
                    $clearedCount = $stmt->rowCount();
                    
                    $activityLogger->logActivity(
                        $userId,
                        $userName,
                        'sessions_cleared',
                        "Cleared $clearedCount expired sessions"
                    );
                    
                    $success = "$clearedCount expired sessions cleared successfully!";
                } catch (Exception $e) {
                    error_log("Clear sessions error: " . $e->getMessage());
                    $error = 'Failed to clear sessions. Please try again.';
                }
                break;
                
            case 'terminate_session':
                // Terminate a specific session
                $sessionId = $_POST['session_id'] ?? '';
                
                try {
                    $sessionManager = getSessionManager();
                    if ($sessionManager && $sessionManager->terminateSession($sessionId)) {
                        $activityLogger->logActivity(
                            $userId,
                            $userName,
                            'session_terminated',
                            "Terminated session: $sessionId"
                        );
                        
                        $success = "Session terminated successfully!";
                    } else {
                        $error = "Failed to terminate session.";
                    }
                } catch (Exception $e) {
                    error_log("Terminate session error: " . $e->getMessage());
                    $error = 'Failed to terminate session. Please try again.';
                }
                break;

            case 'new_election_year':
                // Reset voting flags for all active voters so they can participate
                // in a new election year. Historical vote data in the votes table
                // is never deleted — only the per-user flags are cleared.
                $newYear = (int)($_POST['new_year'] ?? date('Y'));
                if ($newYear < 2020 || $newYear > 2100) {
                    $error = 'Invalid year specified.';
                    break;
                }

                try {
                    $db->beginTransaction();

                    // Count active voters who will be reset
                    $countStmt = $db->query(
                        "SELECT COUNT(*) FROM users
                         WHERE is_admin = 0
                           AND status = 'active'"
                    );
                    $voterCount = (int)$countStmt->fetchColumn();

                    // Reset flags for active voters only — graduated/archived are untouched
                    $resetStmt = $db->prepare(
                        "UPDATE users
                         SET has_voted      = 0,
                             has_logged_in  = 0,
                             voting_year    = NULL
                         WHERE is_admin = 0
                           AND status   = 'active'"
                    );
                    $resetStmt->execute();
                    $resetCount = $resetStmt->rowCount();

                    // Update the current_academic_year setting
                    $db->prepare(
                        "UPDATE settings SET setting_value = ?, updated_at = NOW()
                         WHERE setting_key = 'current_academic_year'"
                    )->execute([$newYear]);

                    $activityLogger->logActivity(
                        $userId, $userName,
                        'new_election_year',
                        "Started new election year $newYear — reset $resetCount active voters",
                        json_encode([
                            'new_year'       => $newYear,
                            'voters_reset'   => $resetCount,
                            'total_active'   => $voterCount,
                        ])
                    );

                    $db->commit();

                    $success = "New election year <strong>$newYear</strong> started. "
                             . "<strong>$resetCount</strong> active voters have been reset and can now log in and vote. "
                             . "All historical vote data has been preserved.";

                } catch (Exception $e) {
                    $db->rollBack();
                    error_log("New election year error: " . $e->getMessage());
                    $error = 'Failed to start new election year. Please try again.';
                }
                break;
        }
    }
}

// Fetch all settings
try {
    $stmt = $db->query("
        SELECT * FROM settings 
        ORDER BY 
            CASE 
                WHEN setting_key IN ('site_name', 'site_description', 'contact_email') THEN 1
                WHEN setting_key LIKE 'voting_%' THEN 2
                WHEN setting_key LIKE 'session_%' THEN 3
                WHEN setting_key LIKE 'security_%' THEN 4
                ELSE 5
            END,
            setting_key ASC
    ");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group settings by category
    $groupedSettings = [];
    foreach ($settings as $setting) {
        $key = $setting['setting_key'];
        
        if (strpos($key, 'site_') === 0) {
            $groupedSettings['Site Information'][] = $setting;
        } elseif (strpos($key, 'voting_') === 0) {
            $groupedSettings['Voting Configuration'][] = $setting;
        } elseif (strpos($key, 'session_') === 0 || $key === 'max_login_attempts') {
            $groupedSettings['Security & Sessions'][] = $setting;
        } elseif (in_array($key, ['maintenance_mode', 'current_academic_year', 'id_format'])) {
            $groupedSettings['System Configuration'][] = $setting;
        } else {
            $groupedSettings['Other Settings'][] = $setting;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching settings: " . $e->getMessage());
    $error = "Error loading settings.";
}

// Get active sessions
$activeSessions = getActiveSessions();

// Get session statistics
$sessionManager = getSessionManager();
$sessionStats = $sessionManager ? $sessionManager->getSessionStats() : [];

$pageTitle = 'System Settings - Nkoranza SHTs E-Voting System';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Settings Header -->
<div class="py-8">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h1 class="text-3xl font-bold flex items-center justify-center text-pink-900">
                <i class="fas fa-cogs mr-3 text-pink-900"></i>
                System Settings
            </h1>
            <p class="text-pink-600 mt-1">Configure system parameters and manage sessions</p>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Flash Messages -->
    <?php if ($success): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-green-100 text-green-800 border-l-4 border-green-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($success) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-green-600 hover:text-green-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-red-100 text-red-800 border-l-4 border-red-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($error) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-red-600 hover:text-red-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Settings Tabs -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden mb-8">
        <div class="border-b border-gray-200">
            <nav class="flex flex-wrap -mb-px">
                <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings&tab=general') ?>" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'general' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-sliders-h mr-2"></i>
                    General Settings
                </a>
                <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings&tab=sessions') ?>" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'sessions' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-users mr-2"></i>
                    Active Sessions
                    <?php if (!empty($activeSessions)): ?>
                        <span class="ml-2 bg-pink-100 text-pink-800 py-0.5 px-2 rounded-full text-xs">
                            <?= count($activeSessions) ?>
                        </span>
                    <?php endif; ?>
                </a>
                <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings&tab=add') ?>" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'add' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-plus-circle mr-2"></i>
                    Add Setting
                </a>
                <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings&tab=system') ?>" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'system' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-database mr-2"></i>
                    System Info
                </a>
                <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings&tab=election_year') ?>" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'election_year' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-calendar-plus mr-2"></i>
                    New Election Year
                </a>
            </nav>
        </div>
        
        <div class="p-6">
            <?php if ($activeTab === 'general'): ?>
                <!-- General Settings Form -->
                <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" class="max-w-4xl">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="update_settings">
                    
                    <?php foreach ($groupedSettings as $category => $categorySettings): ?>
                        <div class="mb-8">
                            <h3 class="text-lg font-semibold text-gray-900 mb-4 pb-2 border-b border-gray-200">
                                <?= htmlspecialchars($category) ?>
                            </h3>
                            
                            <div class="grid md:grid-cols-2 gap-6">
                                <?php foreach ($categorySettings as $setting): ?>
                                    <div class="bg-gray-50 p-4 rounded-lg">
                                        <label for="setting_<?= htmlspecialchars($setting['setting_key']) ?>" 
                                               class="block text-sm font-medium text-gray-700 mb-2">
                                            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $setting['setting_key']))) ?>
                                            <?php if (in_array($setting['setting_key'], ['site_name', 'contact_email', 'voting_start_time', 'voting_end_time'])): ?>
                                                <span class="text-red-500">*</span>
                                            <?php endif; ?>
                                        </label>
                                        
                                        <input type="hidden" name="setting_types[<?= htmlspecialchars($setting['setting_key']) ?>]" 
                                               value="<?= htmlspecialchars($setting['setting_type']) ?>">
                                        
                                        <?php if ($setting['setting_type'] === 'boolean'): ?>
                                            <div class="flex items-center space-x-4">
                                                <label class="inline-flex items-center">
                                                    <input type="radio" 
                                                           name="settings[<?= htmlspecialchars($setting['setting_key']) ?>]" 
                                                           value="true"
                                                           class="form-radio text-pink-600"
                                                           <?= $setting['setting_value'] === 'true' ? 'checked' : '' ?>>
                                                    <span class="ml-2">Enabled</span>
                                                </label>
                                                <label class="inline-flex items-center">
                                                    <input type="radio" 
                                                           name="settings[<?= htmlspecialchars($setting['setting_key']) ?>]" 
                                                           value="false"
                                                           class="form-radio text-pink-600"
                                                           <?= $setting['setting_value'] === 'false' ? 'checked' : '' ?>>
                                                    <span class="ml-2">Disabled</span>
                                                </label>
                                            </div>
                                        <?php elseif ($setting['setting_type'] === 'number'): ?>
                                            <input type="number" 
                                                   id="setting_<?= htmlspecialchars($setting['setting_key']) ?>"
                                                   name="settings[<?= htmlspecialchars($setting['setting_key']) ?>]" 
                                                   value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors"
                                                   step="any">
                                        <?php elseif ($setting['setting_type'] === 'json'): ?>
                                            <textarea 
                                                id="setting_<?= htmlspecialchars($setting['setting_key']) ?>"
                                                name="settings[<?= htmlspecialchars($setting['setting_key']) ?>]" 
                                                rows="4"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors font-mono text-sm"
                                                placeholder="Enter JSON data..."><?= htmlspecialchars($setting['setting_value']) ?></textarea>
                                        <?php else: ?>
                                            <input type="text" 
                                                   id="setting_<?= htmlspecialchars($setting['setting_key']) ?>"
                                                   name="settings[<?= htmlspecialchars($setting['setting_key']) ?>]" 
                                                   value="<?= htmlspecialchars($setting['setting_value']) ?>"
                                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                                        <?php endif; ?>
                                        
                                        <?php if (!empty($setting['description'])): ?>
                                            <p class="text-xs text-gray-500 mt-2"><?= htmlspecialchars($setting['description']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="pt-4 flex justify-end">
                        <button type="submit" 
                                class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-6 rounded-lg transition-colors">
                            <i class="fas fa-save mr-2"></i>
                            Save All Settings
                        </button>
                    </div>
                </form>
                
            <?php elseif ($activeTab === 'sessions'): ?>
                <!-- Active Sessions Management -->
                <div class="space-y-6">
                    <!-- Session Stats -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="bg-gradient-to-br from-pink-50 to-pink-100 p-4 rounded-lg">
                            <div class="text-pink-600 text-sm font-medium mb-1">Total Sessions</div>
                            <div class="text-2xl font-bold text-pink-900"><?= number_format($sessionStats['total_sessions'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gradient-to-br from-blue-50 to-blue-100 p-4 rounded-lg">
                            <div class="text-blue-600 text-sm font-medium mb-1">Authenticated</div>
                            <div class="text-2xl font-bold text-blue-900"><?= number_format($sessionStats['authenticated_sessions'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gradient-to-br from-green-50 to-green-100 p-4 rounded-lg">
                            <div class="text-green-600 text-sm font-medium mb-1">Active Last Hour</div>
                            <div class="text-2xl font-bold text-green-900"><?= number_format($sessionStats['active_last_hour'] ?? 0) ?></div>
                        </div>
                        <div class="bg-gradient-to-br from-purple-50 to-purple-100 p-4 rounded-lg">
                            <div class="text-purple-600 text-sm font-medium mb-1">Oldest Session</div>
                            <div class="text-sm font-bold text-purple-900">
                                <?= isset($sessionStats['oldest_session']) ? date('M j, H:i', strtotime($sessionStats['oldest_session'])) : 'N/A' ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Session Actions -->
                    <div class="flex justify-end space-x-3">
                        <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" class="inline" onsubmit="return confirm('Clear all expired sessions?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                            <input type="hidden" name="action" value="clear_sessions">
                            <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                                <i class="fas fa-broom mr-2"></i>
                                Clear Expired Sessions
                            </button>
                        </form>
                    </div>
                    
                    <!-- Sessions Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">IP Address</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Activity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($activeSessions)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                            <i class="fas fa-info-circle mr-2"></i>
                                            No active sessions found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($activeSessions as $session): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($session['user_id']): ?>
                                                    <div class="font-medium text-gray-900">
                                                        <?= htmlspecialchars(($session['first_name'] ?? '') . ' ' . ($session['last_name'] ?? '')) ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?= htmlspecialchars($session['user_id']) ?>
                                                        <?php if ($session['is_admin'] ?? false): ?>
                                                            <span class="ml-2 px-2 py-0.5 bg-purple-100 text-purple-800 rounded-full text-xs">Admin</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-gray-500 italic">Guest</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= htmlspecialchars($session['ip_address'] ?? 'Unknown') ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M j, Y H:i:s', $session['last_activity']) ?>
                                                <br>
                                                <span class="text-xs">
                                                    <?php 
                                                    $minutesAgo = round((time() - $session['last_activity']) / 60);
                                                    echo $minutesAgo < 1 ? 'Just now' : "$minutesAgo minutes ago";
                                                    ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?= date('M j, Y H:i:s', strtotime($session['created_at'] ?? 'now')) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <?php if ($session['session_id'] !== session_id()): ?>
                                                    <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" class="inline" onsubmit="return confirm('Terminate this session?')">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                        <input type="hidden" name="action" value="terminate_session">
                                                        <input type="hidden" name="session_id" value="<?= htmlspecialchars($session['session_id']) ?>">
                                                        <button type="submit" class="text-red-600 hover:text-red-900" title="Terminate Session">
                                                            <i class="fas fa-times-circle"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-green-600" title="Current Session">
                                                        <i class="fas fa-check-circle"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <?php elseif ($activeTab === 'add'): ?>
                <!-- Add New Setting Form -->
                <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" class="max-w-2xl mx-auto">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="add_setting">
                    
                    <h3 class="text-lg font-semibold text-gray-900 mb-6">Add Custom Setting</h3>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Setting Key <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   name="new_key" 
                                   required
                                   pattern="[a-z0-9_]+"
                                   title="Only lowercase letters, numbers, and underscores"
                                   placeholder="e.g., custom_setting_name"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                            <p class="text-xs text-gray-500 mt-1">Only lowercase letters, numbers, and underscores. No spaces.</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Setting Type <span class="text-red-500">*</span>
                            </label>
                            <select name="new_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="boolean">Boolean (true/false)</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Default Value
                            </label>
                            <input type="text" 
                                   name="new_value" 
                                   placeholder="Default value"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                Description
                            </label>
                            <textarea 
                                name="new_description" 
                                rows="3"
                                placeholder="What is this setting for?"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors"></textarea>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" 
                                    class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-6 rounded-lg transition-colors">
                                <i class="fas fa-plus-circle mr-2"></i>
                                Add Setting
                            </button>
                        </div>
                    </div>
                </form>
                
                <!-- Existing Custom Settings -->
                <div class="mt-8">
                    <h4 class="text-md font-semibold text-gray-900 mb-4">Custom Settings</h4>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Key</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                $hasCustom = false;
                                foreach ($settings as $setting): 
                                    $isDefault = in_array($setting['setting_key'], [
                                        'site_name', 'site_description', 'contact_email', 
                                        'voting_start_time', 'voting_end_time', 'allow_offline_voting',
                                        'require_verification', 'max_login_attempts', 'session_timeout',
                                        'maintenance_mode', 'current_academic_year', 'id_format'
                                    ]);
                                    if ($isDefault) continue;
                                    $hasCustom = true;
                                ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap font-medium"><?= htmlspecialchars($setting['setting_key']) ?></td>
                                        <td class="px-6 py-4 max-w-xs truncate"><?= htmlspecialchars($setting['setting_value']) ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-xs">
                                                <?= $setting['setting_type'] ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4"><?= htmlspecialchars($setting['description'] ?? '') ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" class="inline" onsubmit="return confirm('Delete this custom setting?')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                                <input type="hidden" name="action" value="delete_setting">
                                                <input type="hidden" name="setting_id" value="<?= $setting['id'] ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$hasCustom): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500">
                                            <i class="fas fa-info-circle mr-2"></i>
                                            No custom settings added yet.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <?php elseif ($activeTab === 'system'): ?>
                <!-- System Information -->
                <div class="space-y-6">
                    <h3 class="text-lg font-semibold text-gray-900">System Information</h3>
                    
                    <div class="grid md:grid-cols-2 gap-6">
                        <div class="bg-gray-50 p-6 rounded-lg">
                            <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                                <i class="fas fa-server mr-2 text-pink-600"></i>
                                Server Information
                            </h4>
                            <dl class="space-y-2">
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">PHP Version:</dt>
                                    <dd class="font-mono"><?= phpversion() ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Server Software:</dt>
                                    <dd><?= $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown' ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Database:</dt>
                                    <dd>MySQL <?= $db->getAttribute(PDO::ATTR_SERVER_VERSION) ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Server Time:</dt>
                                    <dd><?= date('Y-m-d H:i:s') ?></dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Timezone:</dt>
                                    <dd><?= date_default_timezone_get() ?></dd>
                                </div>
                            </dl>
                        </div>
                        
                        <div class="bg-gray-50 p-6 rounded-lg">
                            <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                                <i class="fas fa-database mr-2 text-pink-600"></i>
                                Database Statistics
                            </h4>
                            <dl class="space-y-2">
                                <?php
                                $stats = [];
                                $tables = ['users', 'elections', 'positions', 'candidates', 'votes', 'activity_logs', 'sessions'];
                                foreach ($tables as $table) {
                                    $stmt = $db->query("SELECT COUNT(*) FROM $table");
                                    $stats[$table] = $stmt->fetchColumn();
                                }
                                ?>
                                <?php foreach ($stats as $table => $count): ?>
                                    <div class="flex justify-between">
                                        <dt class="text-gray-600 capitalize"><?= str_replace('_', ' ', $table) ?>:</dt>
                                        <dd class="font-mono"><?= number_format($count) ?></dd>
                                    </div>
                                <?php endforeach; ?>
                            </dl>
                        </div>
                        
                        <div class="bg-gray-50 p-6 rounded-lg">
                            <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                                <i class="fas fa-id-card mr-2 text-pink-600"></i>
                                ID Format Configuration
                            </h4>
                            <p class="text-sm text-gray-600 mb-4">
                                Current ID format: <strong>DEPT+YY+NNNN</strong> (9 characters total)
                            </p>
                            <div class="bg-white p-3 rounded border border-gray-200">
                                <code class="text-sm">GSC240001</code> - General Science, 2024, Student #0001
                            </div>
                            <div class="mt-2 text-xs text-gray-500">
                                Format: 3 letters (department) + 2 digits (year) + 4 digits (sequence)
                            </div>
                        </div>
                        
                        <div class="bg-gray-50 p-6 rounded-lg">
                            <h4 class="font-medium text-gray-700 mb-4 flex items-center">
                                <i class="fas fa-shield-alt mr-2 text-pink-600"></i>
                                Security Status
                            </h4>
                            <dl class="space-y-2">
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">HTTPS:</dt>
                                    <dd>
                                        <?php if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'): // [FIX] isset() is true even for HTTPS=off ?>
                                            <span class="text-green-600">Enabled ✓</span>
                                        <?php else: ?>
                                            <span class="text-yellow-600">Disabled ⚠</span>
                                        <?php endif; ?>
                                    </dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Session Timeout:</dt>
                                    <dd><?= $sessionStats['session_timeout'] ?? 3600 ?> seconds</dd>
                                </div>
                                <div class="flex justify-between">
                                    <dt class="text-gray-600">Max Login Attempts:</dt>
                                    <dd><?= $settings['max_login_attempts'] ?? 5 ?></dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                    
                    <!-- Maintenance Actions -->
                    <div class="mt-8 p-6 bg-yellow-50 rounded-lg">
                        <h4 class="font-medium text-yellow-800 mb-4 flex items-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Maintenance Actions
                        </h4>
                        <div class="space-y-3">
                            <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" class="inline-block mr-3" onsubmit="return confirm('Run garbage collection to clean up old sessions?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                                <input type="hidden" name="action" value="clear_sessions">
                                <button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors">
                                    <i class="fas fa-broom mr-2"></i>
                                    Run Garbage Collection
                                </button>
                            </form>
                            
                            <p class="text-sm text-yellow-700 mt-4">
                                <i class="fas fa-info-circle mr-1"></i>
                                These actions may affect system performance. Use with caution.
                            </p>
                        </div>
                    </div>
                </div>
            <?php elseif ($activeTab === 'election_year'): ?>
                <!-- New Election Year Panel -->
                <?php
                // Fetch current stats for the panel
                try {
                    $activeVoterCount = (int)$db->query(
                        "SELECT COUNT(*) FROM users WHERE is_admin = 0 AND status = 'active'"
                    )->fetchColumn();
                    $gradVoterCount = (int)$db->query(
                        "SELECT COUNT(*) FROM users WHERE is_admin = 0 AND status != 'active'"
                    )->fetchColumn();
                    $alreadyVotedCount = (int)$db->query(
                        "SELECT COUNT(*) FROM users WHERE is_admin = 0 AND has_voted = 1"
                    )->fetchColumn();
                    $currentAcademicYear = $db->query(
                        "SELECT setting_value FROM settings WHERE setting_key = 'current_academic_year'"
                    )->fetchColumn() ?: date('Y');
                } catch (Exception $e) {
                    $activeVoterCount = $gradVoterCount = $alreadyVotedCount = 0;
                    $currentAcademicYear = date('Y');
                }
                ?>
                <div class="max-w-2xl">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">Start a New Election Year</h3>
                    <p class="text-sm text-gray-600 mb-6">
                        Use this when a new academic year begins and you need students to be able to log in
                        and vote again. This resets login and voting flags for all active students while
                        <strong>keeping all historical vote data completely intact</strong>.
                    </p>

                    <!-- Current state stats -->
                    <div class="grid grid-cols-3 gap-4 mb-6">
                        <div class="bg-blue-50 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-blue-800"><?= number_format($activeVoterCount) ?></div>
                            <div class="text-xs text-blue-600 mt-1">Active voters</div>
                        </div>
                        <div class="bg-green-50 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-green-800"><?= number_format($alreadyVotedCount) ?></div>
                            <div class="text-xs text-green-600 mt-1">Already voted this year</div>
                        </div>
                        <div class="bg-gray-100 rounded-lg p-4 text-center">
                            <div class="text-2xl font-bold text-gray-700"><?= number_format($gradVoterCount) ?></div>
                            <div class="text-xs text-gray-500 mt-1">Graduated / archived</div>
                        </div>
                    </div>

                    <!-- What this does / does not do -->
                    <div class="grid grid-cols-2 gap-4 mb-6 text-sm">
                        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                            <p class="font-semibold text-green-800 mb-2"><i class="fas fa-check-circle mr-1"></i> What this does</p>
                            <ul class="text-green-700 space-y-1 text-xs">
                                <li>• Resets <code>has_voted</code> to 0 for active students</li>
                                <li>• Resets <code>has_logged_in</code> to 0 for active students</li>
                                <li>• Clears <code>voting_year</code> so they can log in again</li>
                                <li>• Updates academic year setting to selected year</li>
                                <li>• Logs the action to activity trail</li>
                            </ul>
                        </div>
                        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
                            <p class="font-semibold text-red-800 mb-2"><i class="fas fa-times-circle mr-1"></i> What this does NOT do</p>
                            <ul class="text-red-700 space-y-1 text-xs">
                                <li>• Does NOT delete any votes</li>
                                <li>• Does NOT delete any elections</li>
                                <li>• Does NOT affect graduated/archived students</li>
                                <li>• Does NOT change passwords</li>
                                <li>• Cannot be undone automatically</li>
                            </ul>
                        </div>
                    </div>

                    <!-- Warning box -->
                    <div class="bg-yellow-50 border-l-4 border-yellow-400 rounded-r-lg p-4 mb-6">
                        <div class="flex items-start gap-3">
                            <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                            <div class="text-sm text-yellow-800">
                                <strong>Before proceeding:</strong> Make sure the previous election has fully ended
                                and all results have been exported. Currently active academic year:
                                <strong><?= htmlspecialchars($currentAcademicYear) ?></strong>.
                            </div>
                        </div>
                    </div>

                    <!-- Form -->
                    <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/settings') ?>" onsubmit="return confirm('Start new election year? This will allow all <?= $activeVoterCount ?> active students to log in and vote again. This cannot be undone automatically.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="new_election_year">

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">
                                New Academic / Election Year
                            </label>
                            <select name="new_year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200">
                                <?php for ($yr = (int)date('Y'); $yr <= (int)date('Y') + 3; $yr++): ?>
                                    <option value="<?= $yr ?>" <?= $yr == (int)$currentAcademicYear + 1 ? 'selected' : '' ?>>
                                        <?= $yr ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <button type="submit"
                                class="w-full bg-pink-900 hover:bg-pink-800 text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-calendar-plus"></i>
                            Start New Election Year
                        </button>
                    </form>
                </div>

            <?php endif; ?>
        </div>
    </div>
    
    <!-- Navigation Links -->
    <div class="mt-6 flex justify-end space-x-3">
        <a href="<?= BASE_URL ?>/index.php?page=admin/dashboard" 
           class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            <i class="fas fa-tachometer-alt mr-2"></i>
            Dashboard
        </a>
        <a href="<?= BASE_URL ?>/index.php?page=settings" 
           class="px-4 py-2 bg-pink-100 text-pink-700 rounded-lg hover:bg-pink-200 transition-colors">
            <i class="fas fa-user mr-2"></i>
            User Settings
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Flash message close functionality
    document.querySelectorAll('.close-flash').forEach(button => {
        button.addEventListener('click', function() {
            const flashMessage = this.closest('[role="alert"]');
            if (flashMessage) {
                flashMessage.remove();
            }
        });
    });
    
    // Auto-hide flash messages after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            alert.style.transition = 'opacity 0.3s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        });
    }, 5000);
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>