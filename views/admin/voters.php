<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once APP_ROOT . '/includes/ActivityLogger.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Only admin should view this page
if (empty($_SESSION['is_admin'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// Initialize Activity Logger
try {
    $db = Database::getInstance()->getConnection();
    $activityLogger = new ActivityLogger($db);
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $activityLogger = null;
}

// Handle password reset form submission
$resetSuccess = '';
$resetError = '';

// Flash variables for voter management actions (advance levels, mark graduated, etc.)
$resetVotingSuccess = '';
$resetVotingError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $resetError = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'];
        
        if ($action === 'reset_password') {
            // Handle password reset
            $userId = $_POST['user_id'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            if (empty($userId) || empty($newPassword) || empty($confirmPassword)) {
                $resetError = 'All password fields are required.';
            } elseif ($newPassword !== $confirmPassword) {
                $resetError = 'Passwords do not match.';
            } elseif (strlen($newPassword) < 8) {
                $resetError = 'Password must be at least 8 characters long.';
            } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                $resetError = 'Password must contain at least one uppercase letter.';
            } elseif (!preg_match('/[a-z]/', $newPassword)) {
                $resetError = 'Password must contain at least one lowercase letter.';
            } elseif (!preg_match('/[0-9]/', $newPassword)) {
                $resetError = 'Password must contain at least one number.';
            } elseif (!preg_match('/[\W_]/', $newPassword)) {
                $resetError = 'Password must contain at least one special character.';
            } else {
                try {
                    $stmt = $db->prepare("SELECT id, first_name, last_name, is_admin FROM users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$user) {
                        $resetError = 'User not found.';
                    } elseif ($user['is_admin'] == 1) {
                        $resetError = 'Cannot reset password for admin users through this form.';
                    } else {
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $result = $stmt->execute([$newHash, $userId]);
                        
                        if ($result && $stmt->rowCount() > 0) {
                            if ($activityLogger) {
                                $activityLogger->logActivity(
                                    $_SESSION['user_id'],
                                    ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                                    'admin_password_reset',
                                    "Reset password for voter: {$user['first_name']} {$user['last_name']} ({$userId})"
                                );
                            }
                            
                            $resetSuccess = "Password reset successfully for {$user['first_name']} {$user['last_name']}.";
                        } else {
                            $resetError = 'Failed to reset password. Please try again.';
                        }
                    }
                } catch (Exception $e) {
                    error_log("Password reset error: " . $e->getMessage());
                    $resetError = 'An error occurred. Please try again later.';
                }
            }
        } elseif ($action === 'advance_levels') {
            // Advance student levels by incrementing the first digit:
            // 1XXX -> 2XXX (Level 100 to Level 200)
            // 2XXX -> 3XXX (Level 200 to Level 300)
            // 3XXX -> Graduated (Level 300 to Graduated)
            $targetYear = $_POST['target_year'] ?? date('Y');
            $currentYear = date('Y');
            
            try {
                $db->beginTransaction();
                
                // Store the changes in a session variable for potential revert
                $_SESSION['last_level_advance'] = [
                    'timestamp' => time(),
                    'target_year' => $targetYear,
                    'counts' => []
                ];
                
                // Count students in each level before update
                $countStmt = $db->prepare("
                    SELECT 
                        SUM(CASE WHEN level LIKE '1%' THEN 1 ELSE 0 END) as level_100,
                        SUM(CASE WHEN level LIKE '2%' THEN 1 ELSE 0 END) as level_200,
                        SUM(CASE WHEN level LIKE '3%' THEN 1 ELSE 0 END) as level_300
                    FROM users 
                    WHERE is_admin = 0 
                    AND (graduation_year IS NULL OR graduation_year > ?)
                ");
                $countStmt->execute([$currentYear]);
                $counts = $countStmt->fetch(PDO::FETCH_ASSOC);
                
                $level100Count = $counts['level_100'] ?? 0;
                $level200Count = $counts['level_200'] ?? 0;
                $level300Count = $counts['level_300'] ?? 0;
                
                $_SESSION['last_level_advance']['counts']['before'] = [
                    'level_100' => $level100Count,
                    'level_200' => $level200Count,
                    'level_300' => $level300Count
                ];
                
                // Store the actual user data before changes for potential revert
                $backupStmt = $db->prepare("
                    SELECT id, level, graduation_year, has_voted, has_logged_in, voting_year
                    FROM users 
                    WHERE is_admin = 0 
                    AND (graduation_year IS NULL OR graduation_year > ?)
                    AND (level LIKE '1%' OR level LIKE '2%' OR level LIKE '3%')
                ");
                $backupStmt->execute([$currentYear]);
                $usersToUpdate = $backupStmt->fetchAll(PDO::FETCH_ASSOC);
                $_SESSION['last_level_advance']['backup_data'] = $usersToUpdate;
                
                // Advance Level 100 to Level 200 (1XXX -> 2XXX)
                if ($level100Count > 0) {
                    $stmt1 = $db->prepare("
                        UPDATE users 
                        SET level = CONCAT('2', SUBSTRING(level, 2))
                        WHERE is_admin = 0 
                        AND level LIKE '1%'
                        AND (graduation_year IS NULL OR graduation_year > ?)
                    ");
                    $stmt1->execute([$currentYear]);
                }
                
                // Advance Level 200 to Level 300 (2XXX -> 3XXX)
                if ($level200Count > 0) {
                    $stmt2 = $db->prepare("
                        UPDATE users 
                        SET level = CONCAT('3', SUBSTRING(level, 2))
                        WHERE is_admin = 0 
                        AND level LIKE '2%'
                        AND (graduation_year IS NULL OR graduation_year > ?)
                    ");
                    $stmt2->execute([$currentYear]);
                }
                
                // Mark Level 300 as Graduated (3XXX -> Graduated)
                $graduatedCount = 0;
                if ($level300Count > 0) {
                    $stmt3 = $db->prepare("
                        UPDATE users
                        SET graduation_year  = ?,
                            status           = 'graduated',
                            level            = 'Graduated',
                            has_voted        = 0,
                            has_logged_in    = 0,
                            voting_year      = NULL,
                            graduated_at     = NOW()
                        WHERE is_admin = 0
                        AND level LIKE '3%'
                        AND (graduation_year IS NULL OR graduation_year > ?)
                    ");
                    $stmt3->execute([$targetYear, $currentYear]);
                    $graduatedCount = $stmt3->rowCount();
                }
                
                $_SESSION['last_level_advance']['counts']['after'] = [
                    'level_100' => 0,
                    'level_200' => $level100Count,
                    'level_300' => $level200Count,
                    'graduated' => $graduatedCount
                ];
                
                // Log the action
                if ($activityLogger) {
                    $activityLogger->logActivity(
                        $_SESSION['user_id'],
                        ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                        'admin_advance_levels',
                        "Advanced student levels: {$level100Count} from Level 100→200, {$level200Count} from Level 200→300, {$graduatedCount} Level 300→Graduated (Class of {$targetYear})"
                    );
                }
                
                $db->commit();
                
                $resetVotingSuccess = "✅ Student levels advanced successfully:<br>" .
                                      "- <strong>{$level100Count}</strong> students promoted from Level 100 to 200 (e.g., 1A1 → 2A1)<br>" .
                                      "- <strong>{$level200Count}</strong> students promoted from Level 200 to 300 (e.g., 2A1 → 3A1)<br>" .
                                      "- <strong>{$graduatedCount}</strong> students marked as graduated (Class of {$targetYear})<br>" .
                                      "<button onclick='window.votersModule.showRevertModal()' class='mt-2 text-sm bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded inline-flex items-center'>" .
                                      "<i class='fas fa-undo mr-1'></i> Revert if this was a mistake</button>";
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Advance levels error: " . $e->getMessage());
                $resetVotingError = 'Failed to advance student levels. Please try again. Error: ' . $e->getMessage();
            }
        } elseif ($action === 'revert_level_advance') {
            // Revert the last level advance operation
            try {
                if (!isset($_SESSION['last_level_advance']['backup_data'])) {
                    throw new Exception('No level advance operation to revert');
                }
                
                $db->beginTransaction();
                
                $backupData = $_SESSION['last_level_advance']['backup_data'];
                $targetYear = $_SESSION['last_level_advance']['target_year'] ?? date('Y');
                
                // First, reset all affected users to their original state
                foreach ($backupData as $user) {
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET level = ?,
                            graduation_year = ?,
                            has_voted = ?,
                            has_logged_in = ?,
                            voting_year = ?
                        WHERE id = ? AND is_admin = 0
                    ");
                    $stmt->execute([
                        $user['level'],
                        $user['graduation_year'],
                        $user['has_voted'],
                        $user['has_logged_in'],
                        $user['voting_year'],
                        $user['id']
                    ]);
                }
                
                // Log the revert action
                if ($activityLogger) {
                    $activityLogger->logActivity(
                        $_SESSION['user_id'],
                        ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                        'admin_revert_level_advance',
                        "Reverted last level advance operation affecting " . count($backupData) . " students"
                    );
                }
                
                $db->commit();
                
                // Clear the backup data
                unset($_SESSION['last_level_advance']);
                
                $resetVotingSuccess = "✅ Level advance has been reverted successfully. All students have been restored to their previous levels.";
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Revert level advance error: " . $e->getMessage());
                $resetVotingError = 'Failed to revert level advance. Error: ' . $e->getMessage();
            }
        } elseif ($action === 'mark_graduated') {
            // Mark selected voters as graduated
            $voterIds = $_POST['voter_ids'] ?? [];
            $graduationYear = $_POST['graduation_year'] ?? date('Y');
            
            if (!empty($voterIds) && is_array($voterIds)) {
                try {
                    $placeholders = implode(',', array_fill(0, count($voterIds), '?'));
                    $params = array_merge($voterIds, [$graduationYear]);
                    
                    $stmt = $db->prepare("
                        UPDATE users 
                        SET graduation_year = ?,
                            level = 'Graduated',
                            has_voted = 0,
                            has_logged_in = 0,
                            voting_year = NULL
                        WHERE id IN ({$placeholders})
                    ");
                    $stmt->execute($params);
                    $updatedCount = $stmt->rowCount();
                    
                    if ($activityLogger) {
                        $activityLogger->logActivity(
                            $_SESSION['user_id'],
                            ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                            'admin_mark_graduated',
                            "Marked {$updatedCount} voters as graduated (Class of {$graduationYear})"
                        );
                    }
                    
                    $resetVotingSuccess = "Marked {$updatedCount} voters as graduated (Class of {$graduationYear}).";
                    
                } catch (Exception $e) {
                    error_log("Mark graduated error: " . $e->getMessage());
                    $resetVotingError = 'Failed to mark voters as graduated. Please try again.';
                }
            }
        }
    }
}

// Get filter parameters
$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : '';
$statusFilter = isset($_GET['status']) ? $_GET['status'] : 'all';
$graduatedFilter = isset($_GET['graduated']) ? $_GET['graduated'] : 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

try {
    $db = Database::getInstance()->getConnection();
    
    // Build WHERE clause for filtering
    $whereConditions = ["is_admin = 0"];
    $params = [];
    
    if (!empty($search)) {
        $whereConditions[] = "(id LIKE ? OR first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
        $searchTerm = "%{$search}%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    if (!empty($yearFilter)) {
        $whereConditions[] = "voting_year = ?";
        $params[] = $yearFilter;
    }
    
    if ($statusFilter === 'voted') {
        $whereConditions[] = "has_voted = 1";
    } elseif ($statusFilter === 'not_voted') {
        $whereConditions[] = "has_voted = 0";
    }
    
    if ($graduatedFilter === 'graduated') {
        $whereConditions[] = "graduation_year IS NOT NULL AND graduation_year <= ?";
        $params[] = date('Y');
    } elseif ($graduatedFilter === 'active') {
        $whereConditions[] = "(graduation_year IS NULL OR graduation_year > ?)";
        $params[] = date('Y');
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // Get total count for pagination
    $countStmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE {$whereClause}");
    $countStmt->execute($params);
    $totalVoters = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalVoters / $limit);
    
    // Get voters with pagination
    $sql = "SELECT id, first_name, last_name, department, level, email, 
                   has_voted, has_logged_in, voting_year, graduation_year
            FROM users 
            WHERE {$whereClause} 
            ORDER BY 
                CASE 
                    WHEN graduation_year IS NOT NULL AND graduation_year <= YEAR(CURDATE()) THEN 2
                    ELSE 1
                END,
                id ASC 
            LIMIT ? OFFSET ?";
    
    $stmt = $db->prepare($sql);
    
    $paramIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($paramIndex++, $param);
    }
    $stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $voters = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get distinct voting years for filter dropdown
    $yearsStmt = $db->query("SELECT DISTINCT voting_year FROM users WHERE voting_year IS NOT NULL ORDER BY voting_year DESC");
    $votingYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get stats with year breakdown
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(has_voted) as voted,
            COUNT(*) - SUM(has_voted) as not_voted,
            SUM(has_logged_in) as logged_in,
            COUNT(CASE WHEN graduation_year IS NOT NULL AND graduation_year <= YEAR(CURDATE()) THEN 1 END) as graduated
        FROM users
        WHERE is_admin = 0
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get year-wise voting stats
    $yearStatsStmt = $db->query("
        SELECT 
            voting_year,
            COUNT(*) as voter_count
        FROM users 
        WHERE voting_year IS NOT NULL 
        GROUP BY voting_year 
        ORDER BY voting_year DESC
    ");
    $yearStats = $yearStatsStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $voters = [];
    $totalVoters = 0;
    $totalPages = 1;
    $votingYears = [];
    $stats = ['total' => 0, 'voted' => 0, 'not_voted' => 0, 'logged_in' => 0, 'graduated' => 0];
    $yearStats = [];
}

require_once APP_ROOT . '/includes/header.php';

// Get current URL without page parameter to build pagination links
$currentUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$queryParams = $_GET;
unset($queryParams['p']);
$baseUrl = $currentUrl . (!empty($queryParams) ? '?' . http_build_query($queryParams) . '&' : '?');

// Check if there's a recent level advance that can be reverted
$canRevert = isset($_SESSION['last_level_advance']) && (time() - $_SESSION['last_level_advance']['timestamp'] < 3600); // 1 hour window
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
        <div>
            <h2 class="text-3xl font-bold text-gray-900">Voters Management</h2>
            <p class="text-gray-600">Manage voter registration, track voting years, and reset for new elections</p>
        </div>
        <div class="flex space-x-3">
            <?php if ($canRevert): ?>
            <button onclick="window.votersModule.showModal('revert-level-advance-modal')" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded text-sm transition-colors">
                <i class="fas fa-undo mr-1"></i> Revert Levels
            </button>
            <?php endif; ?>
            
            <!-- Advance Levels Button -->
            <button onclick="window.votersModule.showModal('advance-levels-modal')" class="bg-blue-700 hover:bg-blue-800 text-white px-4 py-2 rounded text-sm transition-colors">
                <i class="fas fa-arrow-up mr-1"></i> Advance Levels
            </button>
            
            <!-- Export PDF Button with Dropdown -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" 
                        @keydown.escape="open = false"
                        class="bg-purple-700 hover:bg-purple-800 text-white px-4 py-2 rounded text-sm transition-colors inline-flex items-center focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2">
                    <i class="fas fa-file-pdf mr-1"></i> Export
                    <i class="fas fa-chevron-down ml-1 text-xs" :class="{ 'rotate-180': open }"></i>
                </button>
                
                <div x-show="open" 
                     x-cloak
                     x-transition:enter="transition ease-out duration-200"
                     x-transition:enter-start="opacity-0 transform scale-95"
                     x-transition:enter-end="opacity-100 transform scale-100"
                     x-transition:leave="transition ease-in duration-150"
                     x-transition:leave-start="opacity-100 transform scale-100"
                     x-transition:leave-end="opacity-0 transform scale-95"
                     @click.away="open = false"
                     class="absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg z-50 border border-gray-200">
                    <div class="py-1">
                        <a href="<?= BASE_URL ?>/api/admin/export_voters_pdf.php" 
                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-download mr-2 text-gray-500"></i> All Voters
                        </a>
                        <a href="<?= BASE_URL ?>/api/admin/export_voters_pdf.php?status=active" 
                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-user-check mr-2 text-green-600"></i> Active Only
                        </a>
                        <a href="<?= BASE_URL ?>/api/admin/export_voters_pdf.php?status=graduated" 
                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-graduation-cap mr-2 text-purple-600"></i> Graduated Only
                        </a>
                        <div class="border-t border-gray-100 my-1"></div>
                        <a href="#" @click.prevent="open = false; window.votersModule?.showExportModal()" 
                           class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                            <i class="fas fa-sliders-h mr-2 text-blue-600"></i> Custom Export...
                        </a>
                    </div>
                </div>
            </div>
            
            <button onclick="window.votersModule.showModal('import-voters-modal')" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded text-sm transition-colors">
                <i class="fas fa-file-import mr-1"></i> Import
            </button>
            <button onclick="window.votersModule.openAddVoterModal()" class="bg-pink-700 hover:bg-pink-800 text-white px-4 py-2 rounded text-sm transition-colors">
                <i class="fas fa-plus mr-1"></i> Voter
            </button>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if ($resetSuccess): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-green-100 text-green-800 border-l-4 border-green-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($resetSuccess) ?></span>
                    <button type="button" class="close-flash" aria-label="Close">
                        <i class="fas fa-times text-green-600 hover:text-green-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($resetError): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-red-100 text-red-800 border-l-4 border-red-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($resetError) ?></span>
                    <button type="button" class="close-flash" aria-label="Close">
                        <i class="fas fa-times text-red-600 hover:text-red-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Stats Overview Cards -->
    <div class="grid grid-cols-1 md:grid-cols-5 gap-4 mb-6">
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="flex items-center">
                <div class="bg-blue-100 p-3 rounded-full mr-4">
                    <i class="fas fa-users text-blue-600"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Total Voters</p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($stats['total']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="flex items-center">
                <div class="bg-green-100 p-3 rounded-full mr-4">
                    <i class="fas fa-vote-yea text-green-600"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Voted</p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($stats['voted']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="flex items-center">
                <div class="bg-red-100 p-3 rounded-full mr-4">
                    <i class="fas fa-times-circle text-red-600"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Not Voted</p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($stats['not_voted']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="flex items-center">
                <div class="bg-purple-100 p-3 rounded-full mr-4">
                    <i class="fas fa-graduation-cap text-purple-600"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Graduated</p>
                    <p class="text-2xl font-bold text-gray-900"><?= htmlspecialchars($stats['graduated']) ?></p>
                </div>
            </div>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <div class="flex items-center">
                <div class="bg-yellow-100 p-3 rounded-full mr-4">
                    <i class="fas fa-percentage text-yellow-600"></i>
                </div>
                <div>
                    <p class="text-sm text-gray-600">Participation</p>
                    <p class="text-2xl font-bold text-gray-900">
                        <?= $stats['total'] > 0 ? round(($stats['voted'] / $stats['total']) * 100, 1) : 0 ?>%
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Year Stats -->
    <?php if (!empty($yearStats)): ?>
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-6">
        <h3 class="text-lg font-semibold text-gray-900 mb-3">Voting History by Year</h3>
        <div class="flex flex-wrap gap-3">
            <?php foreach ($yearStats as $yearStat): ?>
                <div class="bg-gray-50 px-4 py-2 rounded-lg border border-gray-200">
                    <span class="text-sm text-gray-600"><?= $yearStat['voting_year'] ?>:</span>
                    <span class="font-semibold text-gray-900 ml-1"><?= $yearStat['voter_count'] ?> voters</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <?php
    $hasActiveFilters = !empty($search) || !empty($yearFilter) || $statusFilter !== 'all' || $graduatedFilter !== 'all';
    ?>
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-6">
        <form method="GET" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php') ?>" class="grid grid-cols-1 md:grid-cols-5 gap-4">
            <!-- Router key -->
            <input type="hidden" name="page" value="admin/voters">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="ID, name, email..." 
                       class="w-full px-3 py-2 border border-gray-300 rounded-md">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Voting Year</label>
                <select name="year" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="">All Years</option>
                    <?php foreach ($votingYears as $year): ?>
                        <option value="<?= $year ?>" <?= $yearFilter == $year ? 'selected' : '' ?>>
                            <?= $year ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Voting Status</label>
                <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="all" <?= $statusFilter == 'all' ? 'selected' : '' ?>>All</option>
                    <option value="voted" <?= $statusFilter == 'voted' ? 'selected' : '' ?>>Voted</option>
                    <option value="not_voted" <?= $statusFilter == 'not_voted' ? 'selected' : '' ?>>Not Voted</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Student Status</label>
                <select name="graduated" class="w-full px-3 py-2 border border-gray-300 rounded-md">
                    <option value="all" <?= $graduatedFilter == 'all' ? 'selected' : '' ?>>All Students</option>
                    <option value="active" <?= $graduatedFilter == 'active' ? 'selected' : '' ?>>Active Students</option>
                    <option value="graduated" <?= $graduatedFilter == 'graduated' ? 'selected' : '' ?>>Graduated</option>
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="bg-pink-900 hover:bg-pink-800 text-white px-4 py-2 rounded-md flex-1">
                    Apply Filters
                </button>
                <?php if ($hasActiveFilters): ?>
                    <a href="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php?page=admin/voters') ?>"
                       class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-md whitespace-nowrap flex items-center gap-1"
                       title="Clear all filters">
                        <i class="fas fa-times text-xs"></i> Clear
                    </a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($hasActiveFilters): ?>
        <div class="flex flex-wrap gap-2 mt-3 pt-3 border-t border-gray-100">
            <span class="text-xs text-gray-500">Active filters:</span>
            <?php if (!empty($search)): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-pink-100 text-pink-800">
                    <i class="fas fa-search" style="font-size:10px"></i>
                    <?= htmlspecialchars($search) ?>
                </span>
            <?php endif; ?>
            <?php if (!empty($yearFilter)): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-blue-100 text-blue-800">
                    <i class="fas fa-calendar" style="font-size:10px"></i>
                    Year: <?= htmlspecialchars($yearFilter) ?>
                </span>
            <?php endif; ?>
            <?php if ($statusFilter !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">
                    <i class="fas fa-vote-yea" style="font-size:10px"></i>
                    <?= $statusFilter === 'voted' ? 'Voted' : 'Not voted' ?>
                </span>
            <?php endif; ?>
            <?php if ($graduatedFilter !== 'all'): ?>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-purple-100 text-purple-800">
                    <i class="fas fa-graduation-cap" style="font-size:10px"></i>
                    <?= $graduatedFilter === 'active' ? 'Active students' : 'Graduated' ?>
                </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($resetVotingSuccess): ?>
        <div class="mb-4 p-4 bg-green-50 rounded-lg border border-green-200" role="alert">
            <div class="flex items-start gap-3">
                <i class="fas fa-check-circle text-green-600 mt-0.5"></i>
                <div class="flex-1"><?= $resetVotingSuccess ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($resetVotingError): ?>
        <div class="mb-4 p-4 bg-red-50 rounded-lg border border-red-200" role="alert">
            <div class="flex items-start gap-3">
                <i class="fas fa-exclamation-circle text-red-600 mt-0.5"></i>
                <span class="flex-1"><?= htmlspecialchars($resetVotingError) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Voters Table -->
    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Full Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Level</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grad Year</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Voted</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Vote Year</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Logged in</th>
                        <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody id="voters-table-body" class="bg-white divide-y divide-gray-200">
                    <?php foreach ($voters as $voter): ?>
                    <tr class="hover:bg-gray-50 <?= (!empty($voter['graduation_year']) && $voter['graduation_year'] <= date('Y')) ? 'bg-gray-50 text-gray-500' : '' ?>">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                            <?= htmlspecialchars($voter['id']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?= htmlspecialchars($voter['first_name'] . ' ' . $voter['last_name']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?= htmlspecialchars($voter['department']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?= htmlspecialchars($voter['level']) ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?= htmlspecialchars($voter['graduation_year'] ?? '—') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?= $voter['has_voted'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' ?>">
                                <?= $voter['has_voted'] ? 'Yes' : 'No' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                            <?= htmlspecialchars($voter['voting_year'] ?? '—') ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?= $voter['has_logged_in'] ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800' ?>">
                                <?= $voter['has_logged_in'] ? 'Yes' : 'No' ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <div class="flex space-x-2 justify-center">
                                <button onclick='window.votersModule.editVoter(<?= json_encode($voter) ?>)' 
                                        class="text-blue-600 hover:text-blue-800 transition-colors"
                                        title="Edit Voter">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button onclick="window.votersModule.showResetPasswordModal('<?= htmlspecialchars($voter['id']) ?>', '<?= htmlspecialchars($voter['first_name'] . ' ' . $voter['last_name']) ?>')" 
                                        class="text-yellow-600 hover:text-yellow-800 transition-colors"
                                        title="Reset Password">
                                    <i class="fas fa-key"></i>
                                </button>
                                <button onclick="window.votersModule.confirmDeleteVoter('<?= htmlspecialchars($voter['id']) ?>')" 
                                        class="text-red-600 hover:text-red-800 transition-colors"
                                        title="Delete Voter">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if (empty($voters)): ?>
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-users text-4xl mb-3 text-gray-300"></i>
                <p>No voters found</p>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
            <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                <div class="text-sm text-gray-600">
                    Showing <span class="font-medium"><?= $offset + 1 ?></span> to 
                    <span class="font-medium"><?= min($offset + $limit, $totalVoters) ?></span> of 
                    <span class="font-medium"><?= $totalVoters ?></span> voters
                </div>
                <nav class="flex space-x-2">
                    <?php if ($page > 1): ?>
                        <a href="<?= $baseUrl ?>p=<?= $page - 1 ?>" 
                           class="px-3 py-1 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Previous
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1 border border-gray-300 rounded text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">
                            Previous
                        </span>
                    <?php endif; ?>
                    
                    <?php 
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $startPage + 4);
                    
                    if ($endPage - $startPage < 4) {
                        $startPage = max(1, $endPage - 4);
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                        <a href="<?= $baseUrl ?>p=<?= $i ?>" 
                           class="px-3 py-1 border rounded text-sm font-medium <?= $i == $page ? 'bg-pink-900 text-white border-pink-900' : 'text-gray-700 bg-white border-gray-300 hover:bg-gray-50' ?>">
                            <?= $i ?>
                        </a>
                    <?php endfor; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="<?= $baseUrl ?>p=<?= $page + 1 ?>" 
                           class="px-3 py-1 border border-gray-300 rounded text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Next
                        </a>
                    <?php else: ?>
                        <span class="px-3 py-1 border border-gray-300 rounded text-sm font-medium text-gray-400 bg-gray-100 cursor-not-allowed">
                            Next
                        </span>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="reset-password-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 modal-overlay">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-pink-900">Reset Voter Password</h3>
                <button type="button" onclick="window.votersModule.hideModal('reset-password-modal')" class="text-gray-500 hover:text-gray-700 modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form method="POST" id="reset-password-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset-user-id">
                
                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-2">Resetting password for:</p>
                    <p id="reset-user-name" class="font-semibold text-gray-900 bg-gray-50 p-2 rounded-lg"></p>
                </div>
                
                <div class="mb-4">
                    <label for="reset-new-password" class="block text-sm font-medium text-gray-700 mb-2">
                        New Password
                    </label>
                    <input type="password" 
                           id="reset-new-password"
                           name="new_password" 
                           required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                    <div class="text-xs text-gray-500 mt-1" id="reset-password-requirements">
                        <span class="req-length">❌ At least 8 characters</span><br>
                        <span class="req-upper">❌ At least one uppercase letter</span><br>
                        <span class="req-lower">❌ At least one lowercase letter</span><br>
                        <span class="req-number">❌ At least one number</span><br>
                        <span class="req-special">❌ At least one special character</span>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="reset-confirm-password" class="block text-sm font-medium text-gray-700 mb-2">
                        Confirm New Password
                    </label>
                    <input type="password" 
                           id="reset-confirm-password"
                           name="confirm_password" 
                           required
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                    <div class="text-xs text-gray-500 mt-1" id="reset-password-match"></div>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" 
                            onclick="window.votersModule.hideModal('reset-password-modal')"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-pink-900 hover:bg-pink-800 text-white rounded-lg transition-colors">
                        <i class="fas fa-key mr-2"></i>
                        Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Advance Levels Modal -->
<div id="advance-levels-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 modal-overlay">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-pink-900">Advance Student Levels</h3>
                <button type="button" onclick="window.votersModule.hideModal('advance-levels-modal')" class="text-gray-500 hover:text-gray-700 modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="mb-4 p-4 bg-yellow-50 rounded-lg border-l-4 border-yellow-400">
                <div class="flex">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mr-3"></i>
                    <div>
                        <p class="text-sm text-yellow-700 font-medium">This will advance all active students by changing the first digit of their level:</p>
                        <ul class="text-xs text-yellow-600 mt-2 list-disc list-inside">
                            <li><strong>1XXX → 2XXX</strong> (e.g., 1A1 becomes 2A1) - Level 100 to Level 200</li>
                            <li><strong>2XXX → 3XXX</strong> (e.g., 2A1 becomes 3A1) - Level 200 to Level 300</li>
                            <li><strong>3XXX → Graduated</strong> (Class of selected year) - Level 300 graduates</li>
                            <li>Graduated students will no longer be eligible to vote</li>
                            <li><strong class="text-red-600">A revert option will be available for 1 hour after this action</strong></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <div class="mb-4 p-3 bg-blue-50 rounded-lg">
                <div class="flex items-center">
                    <i class="fas fa-info-circle text-blue-600 mr-2"></i>
                    <span class="text-sm text-blue-700">
                        Preview of students to be affected will be shown below
                    </span>
                </div>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="advance_levels">
                
                <div class="mb-4">
                    <label for="advance-target-year" class="block text-sm font-medium text-gray-700 mb-2">
                        Graduation Year for Level 300 Students
                    </label>
                    <select id="advance-target-year" name="target_year" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="<?= date('Y') ?>"><?= date('Y') ?> (Current Year)</option>
                        <option value="<?= date('Y') + 1 ?>"><?= date('Y') + 1 ?> (Next Year)</option>
                    </select>
                </div>
                
                <!-- Level stats preview -->
                <div id="level-preview-stats" class="mb-4 p-3 bg-gray-50 rounded-lg text-sm">
                    <p class="font-medium mb-2">Current Active Students:</p>
                    <p><span class="text-blue-600 font-semibold" id="level-100-count">0</span> students in Level 100 (1XXX) → will become Level 200 (2XXX)</p>
                    <p><span class="text-indigo-600 font-semibold" id="level-200-count">0</span> students in Level 200 (2XXX) → will become Level 300 (3XXX)</p>
                    <p><span class="text-purple-600 font-semibold" id="level-300-count">0</span> students in Level 300 (3XXX) → will be marked as Graduated</p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" 
                            onclick="window.votersModule.hideModal('advance-levels-modal')"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white rounded-lg transition-colors"
                            onclick="return confirm('Are you sure you want to advance all student levels? This will automatically graduate Level 300 students. A revert option will be available for 1 hour if this was a mistake.')">
                        <i class="fas fa-arrow-up mr-2"></i>
                        Advance Levels
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Revert Level Advance Modal -->
<div id="revert-level-advance-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50 modal-overlay">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold text-pink-900">Revert Last Level Advance</h3>
                <button type="button" onclick="window.votersModule.hideModal('revert-level-advance-modal')" class="text-gray-500 hover:text-gray-700 modal-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="mb-4 p-4 bg-yellow-50 rounded-lg border-l-4 border-yellow-400">
                <div class="flex">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mr-3"></i>
                    <div>
                        <p class="text-sm text-yellow-700 font-medium">This will revert the last level advance operation:</p>
                        <ul class="text-xs text-yellow-600 mt-2 list-disc list-inside">
                            <li>Restore all students to their previous levels</li>
                            <li>Remove graduation status from any students who were graduated</li>
                            <li>Restore voting status to previous state</li>
                            <li>This action cannot be undone</li>
                            <li><strong>Time remaining: <span id="revert-time-remaining">60 minutes</span></strong></li>
                        </ul>
                    </div>
                </div>
            </div>
            
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="revert_level_advance">
                
                <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                    <p class="text-sm">
                        <strong>Operation details:</strong><br>
                        <?php if (isset($_SESSION['last_level_advance']['counts'])): ?>
                            Before: 
                            Level 100: <?= $_SESSION['last_level_advance']['counts']['before']['level_100'] ?? 0 ?>,
                            Level 200: <?= $_SESSION['last_level_advance']['counts']['before']['level_200'] ?? 0 ?>,
                            Level 300: <?= $_SESSION['last_level_advance']['counts']['before']['level_300'] ?? 0 ?><br>
                            After:
                            Level 200: <?= $_SESSION['last_level_advance']['counts']['after']['level_200'] ?? 0 ?>,
                            Level 300: <?= $_SESSION['last_level_advance']['counts']['after']['level_300'] ?? 0 ?>,
                            Graduated: <?= $_SESSION['last_level_advance']['counts']['after']['graduated'] ?? 0 ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <div class="flex justify-end space-x-3">
                    <button type="button" 
                            onclick="window.votersModule.hideModal('revert-level-advance-modal')"
                            class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100 transition-colors">
                        Cancel
                    </button>
                    <button type="submit" 
                            class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg transition-colors"
                            onclick="return confirm('Are you sure you want to revert the last level advance? This will restore all students to their previous levels. This cannot be undone.')">
                        <i class="fas fa-undo mr-2"></i>
                        Revert Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Loading Spinner -->
<div id="loading-spinner" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white p-6 rounded-lg shadow-lg flex items-center">
        <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-pink-900 mr-3"></div>
        <span>Processing...</span>
    </div>
</div>

<!-- Notification Toast -->
<div id="toast" class="fixed top-4 right-4 p-4 rounded-lg shadow-lg transition-all duration-300 opacity-0 hidden z-50" style="min-width: 300px;">
    <div class="flex items-center">
        <span id="toast-message" class="flex-1"></span>
        <button onclick="window.votersModule.hideToast()" class="ml-4 text-white hover:text-gray-200">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>

<!-- Include Modal Files -->
<?php require_once APP_ROOT . '/views/modals/add_voter.php'; ?>
<?php require_once APP_ROOT . '/views/modals/import_voters.php'; ?>
<?php require_once APP_ROOT . '/views/modals/export_voters.php'; ?>

<style>
.spinner {
    border: 2px solid #f3f3f3;
    border-top: 2px solid #831843;
    border-radius: 50%;
    width: 16px;
    height: 16px;
    animation: spin 1s linear infinite;
    display: inline-block;
    margin-right: 8px;
}
 [x-cloak] { 
    display: none !important; 
}
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
.drag-over {
    background-color: #f9fafb;
    border-color: #831843 !important;
}
/* Error state styling */
input.border-red-500 {
    border-color: #ef4444 !important;
    background-color: #fef2f2;
}

input.ring-red-500:focus {
    --tw-ring-color: #ef4444 !important;
    border-color: #ef4444 !important;
}

#email-error {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-5px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>

<script>
// Create a single global namespace to avoid conflicts
window.votersModule = (function() {
    // Private variables
    const API_BASE = '<?= BASE_URL ?>';
    const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
    let allVoters = <?= json_encode($voters) ?>;
    let revertTimeInterval = null;
    
    // Private utility functions
    function escapeHtml(unsafe) {
        if (unsafe === null || unsafe === undefined) return '';
        return String(unsafe)
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }
    
    function showLoading() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) spinner.classList.remove('hidden');
    }
    
    function hideLoading() {
        const spinner = document.getElementById('loading-spinner');
        if (spinner) spinner.classList.add('hidden');
    }
    
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        const toastMessage = document.getElementById('toast-message');
        
        if (!toast || !toastMessage) return;
        
        toastMessage.textContent = message;
        toast.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg transition-all duration-300 z-50 ${
            type === 'success' ? 'bg-green-600' : 'bg-red-600'
        } text-white opacity-0`;
        toast.classList.remove('hidden');
        
        setTimeout(() => {
            toast.classList.add('opacity-100');
        }, 10);
        
        setTimeout(hideToast, 5000);
    }
    
    function hideToast() {
        const toast = document.getElementById('toast');
        if (!toast) return;
        toast.classList.remove('opacity-100');
        setTimeout(() => {
            toast.classList.add('hidden');
        }, 300);
    }
    
    // Update revert countdown timer
    function updateRevertTimer() {
        const revertModal = document.getElementById('revert-level-advance-modal');
        if (!revertModal || revertModal.classList.contains('hidden')) return;
        
        const timeSpan = document.getElementById('revert-time-remaining');
        if (!timeSpan) return;
        
        // Get the timestamp from PHP (passed as data attribute)
        const timestamp = <?= isset($_SESSION['last_level_advance']['timestamp']) ? $_SESSION['last_level_advance']['timestamp'] : '0' ?>;
        if (timestamp === 0) return;
        
        const now = Math.floor(Date.now() / 1000);
        const elapsed = now - timestamp;
        const remaining = Math.max(0, 3600 - elapsed); // 1 hour = 3600 seconds
        
        if (remaining <= 0) {
            timeSpan.textContent = 'Expired';
            // Auto-hide revert button if timer expires
            const revertBtn = document.querySelector('[onclick="window.votersModule.showModal(\'revert-level-advance-modal\')"]');
            if (revertBtn) revertBtn.style.display = 'none';
            clearInterval(revertTimeInterval);
        } else {
            const minutes = Math.floor(remaining / 60);
            const seconds = remaining % 60;
            timeSpan.textContent = `${minutes}m ${seconds}s`;
        }
    }
    
    // Public methods
    return {
        // Modal functions
        showModal: function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                
                // If reset voting modal or advance levels modal, fetch stats
                if (modalId === 'advance-levels-modal') {
                    this.fetchVoterStats();
                }
                
                // If revert modal, start timer update
                if (modalId === 'revert-level-advance-modal') {
                    if (revertTimeInterval) clearInterval(revertTimeInterval);
                    revertTimeInterval = setInterval(updateRevertTimer, 1000);
                    updateRevertTimer();
                }
            }
        },
        
        hideModal: function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                
                // Clear timer when hiding revert modal
                if (modalId === 'revert-level-advance-modal' && revertTimeInterval) {
                    clearInterval(revertTimeInterval);
                    revertTimeInterval = null;
                }
            }
        },
        
        showToast: showToast,
        hideToast: hideToast,
        
        // Show revert modal (called from success message)
        showRevertModal: function() {
            this.showModal('revert-level-advance-modal');
        },
        
        // Fetch voter stats for preview
        fetchVoterStats: function() {
            fetch(`${API_BASE}/api/admin/voter-stats.php`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Update reset voting modal preview
                        const resetPreviewDiv = document.getElementById('reset-preview-stats');
                        const activeSpan = document.getElementById('active-count');
                        const graduatedSpan = document.getElementById('graduated-count');
                        
                        if (resetPreviewDiv && activeSpan && graduatedSpan) {
                            activeSpan.textContent = data.active_count || 0;
                            graduatedSpan.textContent = data.graduated_count || 0;
                            resetPreviewDiv.classList.remove('hidden');
                        }
                        
                        // Update advance levels modal preview
                        if (data.level_stats) {
                            document.getElementById('level-100-count').textContent = data.level_stats.level_100 || 0;
                            document.getElementById('level-200-count').textContent = data.level_stats.level_200 || 0;
                            document.getElementById('level-300-count').textContent = data.level_stats.level_300 || 0;
                        }
                    }
                })
                .catch(error => console.error('Failed to fetch voter stats:', error));
        },
        
        // Password reset modal
        showResetPasswordModal: function(userId, userName) {
            document.getElementById('reset-user-id').value = userId;
            document.getElementById('reset-user-name').textContent = userName;
            document.getElementById('reset-new-password').value = '';
            document.getElementById('reset-confirm-password').value = '';
            
            // Reset validation indicators
            document.querySelectorAll('#reset-password-requirements span').forEach(span => {
                span.innerHTML = span.innerHTML.replace('✅', '❌');
                span.style.color = '#ef4444';
            });
            document.getElementById('reset-password-match').innerHTML = '';
            
            this.showModal('reset-password-modal');
        },

        // Export modal
        showExportModal: function() {
            this.showModal('export-voters-modal');
        },
        
        // Voter CRUD
        openAddVoterModal: function() {
            if (typeof window.openAddVoterModalFromModule === 'function') {
                window.openAddVoterModalFromModule();
            } else {
                console.error('openAddVoterModalFromModule function not found');
                alert('Error: Could not open add voter modal');
            }
        },
        
        editVoter: function(voter) {
            if (typeof window.editVoterFromModule === 'function') {
                window.editVoterFromModule(voter);
            } else {
                console.error('editVoterFromModule function not found');
                alert('Error: Could not open edit voter modal');
            }
        },
        
        confirmDeleteVoter: function(voterId) {
            if (confirm(`Are you sure you want to delete voter ${voterId}? This action cannot be undone.`)) {
                this.deleteVoter(voterId);
            }
        },
        
        deleteVoter: async function(id) {
            showLoading();
            try {
                const response = await fetch(`${API_BASE}/api/admin/voters.php?id=${encodeURIComponent(id)}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN,
                        'Content-Type': 'application/json'
                    }
                });
                
                // Check if response is JSON
                const contentType = response.headers.get('content-type');
                let data;
                
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    const text = await response.text();
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned an invalid response. Please check server logs.');
                }
                
                if (data.success) {
                    showToast(data.message || 'Voter deleted successfully');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showToast(data.message || 'Failed to delete voter', 'error');
                }
            } catch (error) {
                console.error('Delete error:', error);
                showToast(error.message || 'Network error. Please try again.', 'error');
            } finally {
                hideLoading();
            }
        },
        
        // Preview auto-generated ID
        previewGeneratedId: async function() {
            const department = document.getElementById('voter-department')?.value;
            const entryYear = document.getElementById('voter-entry-year')?.value;
            const isEdit = document.getElementById('is-edit')?.value === 'true';
            
            // Only preview for new voters (not edit mode)
            if (isEdit || !department || !entryYear) {
                document.getElementById('id-preview-container')?.classList.add('hidden');
                return;
            }
            
            try {
                const response = await fetch(`${API_BASE}/api/admin/voters.php?preview_id=true&department=${encodeURIComponent(department)}&entry_year=${entryYear}`, {
                    method: 'GET',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN
                    }
                });
                
                const data = await response.json();
                
                if (data.success && data.preview_id) {
                    const previewEl = document.getElementById('preview-id');
                    const containerEl = document.getElementById('id-preview-container');
                    
                    if (previewEl && containerEl) {
                        previewEl.textContent = data.preview_id;
                        containerEl.classList.remove('hidden');
                    }
                } else {
                    document.getElementById('id-preview-container')?.classList.add('hidden');
                }
            } catch (error) {
                console.error('Failed to preview ID:', error);
                document.getElementById('id-preview-container')?.classList.add('hidden');
            }
        },
        
        // Search and filter functions
        searchVoters: function(query) {
            const searchTerm = query.toLowerCase().trim();
            const filterType = document.getElementById('filter-select').value;
            
            let filteredVoters = this.filterVotersData(filterType, allVoters);
            
            if (searchTerm) {
                filteredVoters = filteredVoters.filter(voter => {
                    const searchString = `${voter.id} ${voter.first_name} ${voter.last_name} ${voter.email} ${voter.department} ${voter.level}`.toLowerCase();
                    return searchString.includes(searchTerm);
                });
            }
            
            this.renderVotersTable(filteredVoters);
        },
        
        filterVoters: function(filterType) {
            const searchTerm = document.getElementById('voter-search').value.toLowerCase().trim();
            
            let filteredVoters = this.filterVotersData(filterType, allVoters);
            
            if (searchTerm) {
                filteredVoters = filteredVoters.filter(voter => {
                    const searchString = `${voter.id} ${voter.first_name} ${voter.last_name} ${voter.email} ${voter.department} ${voter.level}`.toLowerCase();
                    return searchString.includes(searchTerm);
                });
            }
            
            this.renderVotersTable(filteredVoters);
        },
        
        filterVotersData: function(filterType, voters) {
            const currentYear = new Date().getFullYear();
            
            switch (filterType) {
                case 'voted':
                    return voters.filter(voter => voter.has_voted);
                case 'not_voted':
                    return voters.filter(voter => !voter.has_voted);
                case 'logged_in':
                    return voters.filter(voter => voter.has_logged_in);
                case 'not_logged_in':
                    return voters.filter(voter => !voter.has_logged_in);
                case 'graduated':
                    return voters.filter(voter => voter.graduation_year && voter.graduation_year <= currentYear);
                case 'active':
                    return voters.filter(voter => !voter.graduation_year || voter.graduation_year > currentYear);
                default:
                    return voters;
            }
        },
        
        renderVotersTable: function(voters) {
            const tbody = document.getElementById('voters-table-body');
            if (!tbody) return;
            
            if (voters.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="10" class="text-center py-8 text-gray-500">
                            <i class="fas fa-users text-4xl mb-3 text-gray-300"></i>
                            <p>No voters found</p>
                        </td>
                    </tr>
                `;
                return;
            }
            
            tbody.innerHTML = voters.map(voter => `
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">${escapeHtml(voter.id)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(voter.first_name)} ${escapeHtml(voter.last_name)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(voter.email || '—')}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(voter.department)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(voter.level)}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">${escapeHtml(voter.graduation_year || '—')}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${voter.has_voted ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}">
                            ${voter.has_voted ? 'Yes' : 'No'}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">${escapeHtml(voter.voting_year || '—')}</td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${voter.has_logged_in ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'}">
                            ${voter.has_logged_in ? 'Yes' : 'No'}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-center">
                        <div class="flex space-x-2 justify-center">
                            <button onclick='window.votersModule.editVoter(${JSON.stringify(voter)})' 
                                    class="text-blue-600 hover:text-blue-800 transition-colors"
                                    title="Edit Voter">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="window.votersModule.showResetPasswordModal('${escapeHtml(voter.id)}', '${escapeHtml(voter.first_name)} ${escapeHtml(voter.last_name)}')" 
                                    class="text-yellow-600 hover:text-yellow-800 transition-colors"
                                    title="Reset Password">
                                <i class="fas fa-key"></i>
                            </button>
                            <button onclick="window.votersModule.confirmDeleteVoter('${escapeHtml(voter.id)}')" 
                                    class="text-red-600 hover:text-red-800 transition-colors"
                                    title="Delete Voter">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        },
        
        // Password validation
        setupPasswordValidation: function() {
            const newPassword = document.getElementById('reset-new-password');
            const confirmPassword = document.getElementById('reset-confirm-password');
            const matchIndicator = document.getElementById('reset-password-match');
            
            if (newPassword && confirmPassword) {
                const reqLength = document.querySelector('#reset-password-requirements .req-length');
                const reqUpper = document.querySelector('#reset-password-requirements .req-upper');
                const reqLower = document.querySelector('#reset-password-requirements .req-lower');
                const reqNumber = document.querySelector('#reset-password-requirements .req-number');
                const reqSpecial = document.querySelector('#reset-password-requirements .req-special');
                
                function checkPasswordStrength() {
                    const password = newPassword.value;
                    
                    reqLength.innerHTML = password.length >= 8 ? '✅ At least 8 characters' : '❌ At least 8 characters';
                    reqLength.style.color = password.length >= 8 ? '#10b981' : '#ef4444';
                    
                    reqUpper.innerHTML = /[A-Z]/.test(password) ? '✅ At least one uppercase letter' : '❌ At least one uppercase letter';
                    reqUpper.style.color = /[A-Z]/.test(password) ? '#10b981' : '#ef4444';
                    
                    reqLower.innerHTML = /[a-z]/.test(password) ? '✅ At least one lowercase letter' : '❌ At least one lowercase letter';
                    reqLower.style.color = /[a-z]/.test(password) ? '#10b981' : '#ef4444';
                    
                    reqNumber.innerHTML = /[0-9]/.test(password) ? '✅ At least one number' : '❌ At least one number';
                    reqNumber.style.color = /[0-9]/.test(password) ? '#10b981' : '#ef4444';
                    
                    reqSpecial.innerHTML = /[\W_]/.test(password) ? '✅ At least one special character' : '❌ At least one special character';
                    reqSpecial.style.color = /[\W_]/.test(password) ? '#10b981' : '#ef4444';
                }
                
                function validatePasswordMatch() {
                    if (confirmPassword.value) {
                        matchIndicator.innerHTML = newPassword.value === confirmPassword.value 
                            ? '✅ Passwords match' 
                            : '❌ Passwords do not match';
                        matchIndicator.style.color = newPassword.value === confirmPassword.value ? '#10b981' : '#ef4444';
                    } else {
                        matchIndicator.innerHTML = '';
                    }
                }
                
                newPassword.addEventListener('input', checkPasswordStrength);
                newPassword.addEventListener('input', validatePasswordMatch);
                confirmPassword.addEventListener('input', validatePasswordMatch);
                confirmPassword.addEventListener('keyup', validatePasswordMatch);
            }
        }
    };
})();

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.votersModule.setupPasswordValidation();
    
    // Flash message close functionality
    document.querySelectorAll('.close-flash').forEach(button => {
        button.addEventListener('click', function() {
            this.closest('[role="alert"]').remove();
        });
    });
    
    // Auto-hide flash messages after 5 seconds (except those with revert buttons)
    setTimeout(() => {
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            if (alert && !alert.querySelector('button[onclick*="showRevertModal"]')) {
                alert.style.transition = 'opacity 0.3s ease';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }
        });
    }, 5000);
    
    // Reset password form submission
    const resetForm = document.getElementById('reset-password-form');
    if (resetForm) {
        resetForm.addEventListener('submit', function(e) {
            e.preventDefault();
            document.getElementById('loading-spinner').classList.remove('hidden');
            this.submit();
        });
    }
    
    // Voter form submission
const voterForm = document.getElementById('voter-form');
if (voterForm) {
    // Remove any existing listeners
    const newForm = voterForm.cloneNode(true);
    voterForm.parentNode.replaceChild(newForm, voterForm);
    
    // Add enhanced handler
    newForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        formData.append('csrf_token', '<?= $_SESSION['csrf_token'] ?>');
        
        // Show loading spinner
        document.getElementById('loading-spinner').classList.remove('hidden');
        
        // Clear any previous email error styling
        const emailField = document.getElementById('voter-email');
        emailField.classList.remove('border-red-500', 'ring-red-500');
        const existingError = document.getElementById('email-error');
        if (existingError) existingError.remove();
        
        try {
            const response = await fetch('<?= BASE_URL ?>/api/admin/voters.php', {
                method: 'POST',
                body: formData
            });
            
            const responseText = await response.text();
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (e) {
                console.error('Failed to parse JSON:', responseText);
                throw new Error('Server returned invalid JSON');
            }
            
            if (data.success) {
                // Check if this was a new voter with auto-generated password
                if (data.is_new && data.generated_password) {
                    // Show the generated password
                    document.getElementById('generated-password').textContent = data.generated_password;
                    document.getElementById('password-display').classList.remove('hidden');
                    
                    // Show success message but don't close modal immediately
                    if (window.votersModule) {
                        window.votersModule.showToast('Voter added successfully! Password generated.');
                    }
                    
                    // Clear form for next entry but keep modal open
                    document.getElementById('voter-form').reset();
                    
                    // Reset ID preview
                    document.getElementById('id-preview-container').classList.add('hidden');
                    
                    // Reset entry year to default
                    const entryYearSelect = document.getElementById('voter-entry-year');
                    if (entryYearSelect) {
                        const currentYear = new Date().getFullYear().toString();
                        for (let option of entryYearSelect.options) {
                            if (option.value === currentYear) {
                                option.selected = true;
                                break;
                            }
                        }
                    }
                    
                    // Refresh the voters list in background
                    setTimeout(() => location.reload(), 10000); // Reload after 10 seconds
                } else {
                    // For edits or if no password generated, close modal and reload
                    window.votersModule.hideModal('add-voter-modal');
                    window.votersModule.showToast(data.message || 'Voter saved successfully');
                    setTimeout(() => location.reload(), 1500);
                }
            } else {
                // Handle specific error types
                if (data.message && data.message.toLowerCase().includes('email')) {
                    // Highlight email field for duplicate email
                    const emailField = document.getElementById('voter-email');
                    emailField.classList.add('border-red-500', 'ring-red-500');
                    emailField.focus();
                    
                    // Add error message below email field
                    let errorDiv = document.getElementById('email-error');
                    if (!errorDiv) {
                        errorDiv = document.createElement('p');
                        errorDiv.id = 'email-error';
                        errorDiv.className = 'text-xs text-red-600 mt-1';
                        emailField.parentNode.appendChild(errorDiv);
                    }
                    errorDiv.textContent = '❌ ' + data.message;
                    
                    // Show in toast as well
                    window.votersModule.showToast(data.message, 'error');
                } else {
                    window.votersModule.showToast(data.message || 'Failed to save voter', 'error');
                }
            }
        } catch (error) {
            console.error('Save error:', error);
            window.votersModule.showToast(error.message || 'Network error. Please try again.', 'error');
        } finally {
            document.getElementById('loading-spinner').classList.add('hidden');
        }
    });
}
    
    // Close modals with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(modal => {
                window.votersModule.hideModal(modal.id);
            });
        }
    });
    
    // Close modals when clicking outside
    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                window.votersModule.hideModal(modal.id);
            }
        });
    });
    
    // Initialize search and filter elements
    const searchInput = document.getElementById('voter-search');
    const filterSelect = document.getElementById('filter-select');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            window.votersModule.searchVoters(this.value);
        });
    }
    
    if (filterSelect) {
        filterSelect.addEventListener('change', function() {
            window.votersModule.filterVoters(this.value);
        });
    }
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>