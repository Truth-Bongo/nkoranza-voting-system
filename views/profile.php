<?php
// Enable strict typing
declare(strict_types=1);

// No need to start session here - bootstrap.php already handles it
// No need to load constants - bootstrap.php already handles it

require_once APP_ROOT . '/config/db_connect.php';
require_once APP_ROOT . '/helpers/functions.php';
require_once APP_ROOT . '/includes/ActivityLogger.php';

// Initialize database connection
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $_SESSION['flash_message'] = 'System temporarily unavailable. Please try again later.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL);
    exit;
}

$activityLogger = new ActivityLogger($db);
$userId = $_SESSION['user_id'];

// Handle password change form submission
$passwordSuccess = '';
$passwordError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $passwordError = 'Invalid security token. Please try again.';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        // Validate inputs
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $passwordError = 'All password fields are required.';
        } elseif ($newPassword !== $confirmPassword) {
            $passwordError = 'New passwords do not match.';
        } elseif (strlen($newPassword) < 8) {
            $passwordError = 'Password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Z]/', $newPassword)) {
            $passwordError = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[a-z]/', $newPassword)) {
            $passwordError = 'Password must contain at least one lowercase letter.';
        } elseif (!preg_match('/[0-9]/', $newPassword)) {
            $passwordError = 'Password must contain at least one number.';
        } elseif (!preg_match('/[\W_]/', $newPassword)) {
            $passwordError = 'Password must contain at least one special character.';
        } else {
            try {
                // Verify current password
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $storedHash = $stmt->fetchColumn();
                
                if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
                    $passwordError = 'Current password is incorrect.';
                } else {
                    // Check if new password is same as old
                    if (password_verify($newPassword, $storedHash)) {
                        $passwordError = 'New password must be different from current password.';
                    } else {
                        // Update password
                        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $result = $stmt->execute([$newHash, $userId]);
                        
                        if ($result && $stmt->rowCount() > 0) {
                            // Log the password change
                            $activityLogger->logActivity(
                                $userId,
                                ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                                'password_change',
                                'Changed password successfully'
                            );
                            
                            $passwordSuccess = 'Password changed successfully!';
                            
                            // Clear password fields (handled by JavaScript)
                        } else {
                            $passwordError = 'Failed to update password. Please try again.';
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Password change error: " . $e->getMessage());
                $passwordError = 'An error occurred. Please try again later.';
            }
        }
    }
}

// Get current page for pagination
$currentPage = isset($_GET['history_page']) ? (int)$_GET['history_page'] : 1;
if ($currentPage < 1) $currentPage = 1;
$recordsPerPage = 2;
$offset = ($currentPage - 1) * $recordsPerPage;

// Get user details
try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, department, level, 
               has_voted, voting_year, created_at, is_admin
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found');
    }
} catch (Exception $e) {
    error_log("Error fetching user profile: " . $e->getMessage());
    $_SESSION['flash_message'] = 'Error loading profile. Please try again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL);
    exit;
}

// Get total count of voting history for pagination
try {
    $stmt = $db->prepare("
        SELECT COUNT(*) as total
        FROM votes v
        WHERE v.voter_id = ?
    ");
    $stmt->execute([$userId]);
    $totalRecords = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalRecords / $recordsPerPage);
} catch (Exception $e) {
    error_log("Error counting voting history: " . $e->getMessage());
    $totalRecords = 0;
    $totalPages = 1;
}

// Get voting history with pagination
try {
    $stmt = $db->prepare("
        SELECT v.timestamp, e.title as election_title, 
               p.name as position_name, 
               CASE 
                   WHEN v.rejected = 1 THEN 'Rejected Vote'
                   ELSE CONCAT('Voted for ', u.first_name, ' ', u.last_name)
               END as vote_details
        FROM votes v
        JOIN elections e ON v.election_id = e.id
        JOIN positions p ON v.position_id = p.id
        LEFT JOIN candidates c ON v.candidate_id = c.id
        LEFT JOIN users u ON c.user_id = u.id
        WHERE v.voter_id = ?
        ORDER BY v.timestamp DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$userId, $recordsPerPage, $offset]);
    $votingHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching voting history: " . $e->getMessage());
    $votingHistory = [];
}

// Get user statistics
try {
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT election_id) as elections_participated,
            COUNT(*) as total_votes_cast,
            MAX(timestamp) as last_vote_date
        FROM votes
        WHERE voter_id = ? AND rejected = 0
    ");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching user stats: " . $e->getMessage());
    $stats = ['elections_participated' => 0, 'total_votes_cast' => 0, 'last_vote_date' => null];
}

// Get active elections user hasn't voted in
try {
    $stmt = $db->prepare("
        SELECT e.id, e.title, e.end_date
        FROM elections e
        WHERE e.status = 'active'
        AND e.id NOT IN (
            SELECT DISTINCT election_id 
            FROM votes 
            WHERE voter_id = ?
        )
        ORDER BY e.end_date ASC
    ");
    $stmt->execute([$userId]);
    $pendingElections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching pending elections: " . $e->getMessage());
    $pendingElections = [];
}

// Log profile view
$activityLogger->logActivity(
    $userId, 
    $user['first_name'] . ' ' . $user['last_name'], 
    'profile_view', 
    'Viewed profile'
);

$pageTitle = 'My Profile - Nkoranza SHTs E-Voting System';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Profile Header -->
<div class="py-8">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <div class="w-24 h-24 bg-pink-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-user-circle text-5xl text-pink-900"></i>
            </div>
            <h1 class="text-3xl font-bold text-pink-900">
                <?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?>
            </h1>
            <p class="text-pink-600 mt-1">Member since <?= date('F Y', strtotime($user['created_at'])) ?></p>
            <?php if ($user['is_admin']): ?>
                <div class="mt-3">
                    <span class="inline-block bg-yellow-100 text-yellow-800 text-xs font-semibold px-3 py-1 rounded-full">
                        <i class="fas fa-shield-alt mr-1"></i> Administrator
                    </span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'bg-red-100 text-red-800 border-l-4 border-red-500' : 'bg-green-100 text-green-800 border-l-4 border-green-500' ?> rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($_SESSION['flash_message']) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-gray-400 hover:text-gray-600"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Password Change Messages -->
    <?php if ($passwordSuccess): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-green-100 text-green-800 border-l-4 border-green-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($passwordSuccess) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-green-600 hover:text-green-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($passwordError): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-red-100 text-red-800 border-l-4 border-red-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($passwordError) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-red-600 hover:text-red-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid lg:grid-cols-3 gap-8">
        <!-- Left Column - Profile Info -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-md overflow-hidden sticky top-24">
                <div class="p-6 bg-gradient-to-r from-pink-50 to-purple-50 border-b border-pink-100">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-id-card text-pink-900 mr-2"></i>
                        Profile Information
                    </h2>
                </div>
                
                <div class="p-6 space-y-4">
                    <div>
                        <label class="text-sm text-gray-500 block">User ID</label>
                        <p class="font-mono font-medium text-gray-900"><?= htmlspecialchars($user['id']) ?></p>
                    </div>
                    
                    <div>
                        <label class="text-sm text-gray-500 block">Full Name</label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($user['first_name'] . ' ' . $user['last_name']) ?></p>
                    </div>
                    
                    <div>
                        <label class="text-sm text-gray-500 block">Email</label>
                        <p class="font-medium text-gray-900"><?= htmlspecialchars($user['email'] ?? 'Not provided') ?></p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-sm text-gray-500 block">Department</label>
                            <p class="font-medium text-gray-900"><?= htmlspecialchars($user['department']) ?></p>
                        </div>
                        <div>
                            <label class="text-sm text-gray-500 block">Level</label>
                            <p class="font-medium text-gray-900"><?= htmlspecialchars($user['level']) ?></p>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t border-gray-100">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm text-gray-500">Voting Status</span>
                            <?php
                            // Scope to current election year so past-year votes don't falsely show "Voted"
                            $currentYear = (int)date('Y');
                            $votedThisYear = $user['has_voted'] && isset($user['voting_year']) && (int)$user['voting_year'] === $currentYear;
                            if ($votedThisYear): ?>
                                <span class="bg-green-100 text-green-800 text-xs font-semibold px-2 py-1 rounded-full">
                                    <i class="fas fa-check-circle mr-1"></i> Voted (<?= $currentYear ?>)
                                </span>
                            <?php else: ?>
                                <span class="bg-yellow-100 text-yellow-800 text-xs font-semibold px-2 py-1 rounded-full">
                                    <i class="fas fa-clock mr-1"></i> Not Voted Yet
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if (!empty($pendingElections)): ?>
                            <div class="mt-3 p-3 bg-blue-50 rounded-lg">
                                <p class="text-sm text-blue-800 font-medium mb-2">
                                    <i class="fas fa-info-circle mr-1"></i> Pending Elections:
                                </p>
                                <ul class="space-y-1">
                                    <?php foreach ($pendingElections as $election): ?>
                                        <li class="text-sm text-blue-700 flex items-center">
                                            <i class="fas fa-circle text-xs mr-2"></i>
                                            <?= htmlspecialchars($election['title']) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <a href="<?= BASE_URL ?>/vote" 
                                   class="mt-3 inline-block w-full text-center bg-blue-600 text-white text-sm py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                    <i class="fas fa-vote-yea mr-1"></i> Vote Now
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="p-6 bg-gray-50 border-t border-gray-100">
                    <a href="<?= BASE_URL ?>/settings" 
                       class="w-full flex items-center justify-center space-x-2 bg-gray-700 hover:bg-gray-800 text-white py-2 px-4 rounded-lg transition-colors">
                        <i class="fas fa-cog"></i>
                        <span>Edit Settings</span>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Right Column - Stats and History -->
        <div class="lg:col-span-2 space-y-8">
            <!-- Stats Cards -->
            <div class="grid sm:grid-cols-3 gap-4">
                <div class="bg-white p-6 rounded-xl shadow-md text-center">
                    <div class="text-pink-600 text-2xl mb-2">
                        <i class="fas fa-vote-yea"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900"><?= $stats['elections_participated'] ?></h3>
                    <p class="text-sm text-gray-500">Elections Participated</p>
                </div>
                
                <div class="bg-white p-6 rounded-xl shadow-md text-center">
                    <div class="text-pink-600 text-2xl mb-2">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <h3 class="text-3xl font-bold text-gray-900"><?= $stats['total_votes_cast'] ?></h3>
                    <p class="text-sm text-gray-500">Total Votes Cast</p>
                </div>
                
                <div class="bg-white p-6 rounded-xl shadow-md text-center">
                    <div class="text-pink-600 text-2xl mb-2">
                        <i class="fas fa-calendar-check"></i>
                    </div>
                    <h3 class="text-sm font-bold text-gray-900 mb-1">Last Vote</h3>
                    <p class="text-sm text-gray-600">
                        <?= $stats['last_vote_date'] ? date('M j, Y', strtotime($stats['last_vote_date'])) : 'Never' ?>
                    </p>
                </div>
            </div>
            
            <!-- Voting History with Pagination -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6 bg-gradient-to-r from-pink-50 to-purple-50 border-b border-pink-100">
                    <div class="flex justify-between items-center">
                        <h2 class="text-xl font-bold text-gray-900 flex items-center">
                            <i class="fas fa-history text-pink-900 mr-2"></i>
                            Voting History
                        </h2>
                        <?php if ($totalRecords > 0): ?>
                            <span class="text-sm text-gray-500">
                                Showing <?= $offset + 1 ?>-<?= min($offset + $recordsPerPage, $totalRecords) ?> of <?= $totalRecords ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="p-6">
                    <?php if (empty($votingHistory)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-vote-yea text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">No voting history yet</p>
                            <p class="text-sm text-gray-400 mt-1">Your votes will appear here once you participate in elections</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 mb-6">
                            <?php foreach ($votingHistory as $vote): ?>
                                <div class="border border-gray-100 rounded-lg p-4 hover:bg-gray-50 transition-colors">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <h3 class="font-semibold text-gray-900"><?= htmlspecialchars($vote['election_title']) ?></h3>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <i class="fas fa-tag text-pink-600 mr-1"></i>
                                                <?= htmlspecialchars($vote['position_name']) ?>
                                            </p>
                                            <p class="text-sm text-gray-700 mt-2">
                                                <?= htmlspecialchars($vote['vote_details']) ?>
                                            </p>
                                        </div>
                                        <span class="text-xs text-gray-500">
                                            <?= date('M j, Y g:i A', strtotime($vote['timestamp'])) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                            <div class="flex justify-center items-center space-x-2 mt-6">
                                <!-- Previous button -->
                                <?php if ($currentPage > 1): ?>
                                    <a href="?history_page=<?= $currentPage - 1 ?>" 
                                       class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg cursor-not-allowed">
                                        <i class="fas fa-chevron-left"></i>
                                    </span>
                                <?php endif; ?>
                                
                                <!-- Page numbers -->
                                <?php
                                $startPage = max(1, $currentPage - 2);
                                $endPage = min($totalPages, $currentPage + 2);
                                
                                if ($startPage > 1): ?>
                                    <a href="?history_page=1" class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">1</a>
                                    <?php if ($startPage > 2): ?>
                                        <span class="px-3 py-2 text-gray-500">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                                    <?php if ($i == $currentPage): ?>
                                        <span class="px-3 py-2 bg-pink-900 text-white rounded-lg"><?= $i ?></span>
                                    <?php else: ?>
                                        <a href="?history_page=<?= $i ?>" 
                                           class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors"><?= $i ?></a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <?php if ($endPage < $totalPages): ?>
                                    <?php if ($endPage < $totalPages - 1): ?>
                                        <span class="px-3 py-2 text-gray-500">...</span>
                                    <?php endif; ?>
                                    <a href="?history_page=<?= $totalPages ?>" 
                                       class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors"><?= $totalPages ?></a>
                                <?php endif; ?>
                                
                                <!-- Next button -->
                                <?php if ($currentPage < $totalPages): ?>
                                    <a href="?history_page=<?= $currentPage + 1 ?>" 
                                       class="px-3 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="px-3 py-2 bg-gray-50 text-gray-400 rounded-lg cursor-not-allowed">
                                        <i class="fas fa-chevron-right"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Page info for mobile -->
                            <div class="text-center text-sm text-gray-500 mt-4 md:hidden">
                                Page <?= $currentPage ?> of <?= $totalPages ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Account Security - Fixed Change Password Form -->
            <div class="bg-white rounded-xl shadow-md overflow-hidden">
                <div class="p-6 bg-gradient-to-r from-pink-50 to-purple-50 border-b border-pink-100">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center">
                        <i class="fas fa-shield-alt text-pink-900 mr-2"></i>
                        Account Security
                    </h2>
                </div>
                
                <div class="p-6">
                    <!-- Password Change Form -->
                    <form method="POST" class="mb-6">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                        <input type="hidden" name="action" value="change_password">
                        
                        <h3 class="font-semibold text-gray-800 mb-4">Change Password</h3>
                        
                        <div class="space-y-4">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">
                                    Current Password
                                </label>
                                <input type="password" 
                                       id="current_password"
                                       name="current_password" 
                                       required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                            </div>
                            
                            <div>
                                <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">
                                    New Password
                                </label>
                                <input type="password" 
                                       id="new_password"
                                       name="new_password" 
                                       required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                                <div class="text-xs text-gray-500 mt-1" id="password-requirements">
                                    <span class="req-length">❌ At least 8 characters</span><br>
                                    <span class="req-upper">❌ At least one uppercase letter</span><br>
                                    <span class="req-lower">❌ At least one lowercase letter</span><br>
                                    <span class="req-number">❌ At least one number</span><br>
                                    <span class="req-special">❌ At least one special character</span>
                                </div>
                            </div>
                            
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                                    Confirm New Password
                                </label>
                                <input type="password" 
                                       id="confirm_password"
                                       name="confirm_password" 
                                       required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                                <div class="text-xs text-gray-500 mt-1" id="password-match"></div>
                            </div>
                            
                            <div>
                                <button type="submit" 
                                        class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                                    <i class="fas fa-key mr-2"></i>
                                    Change Password
                                </button>
                            </div>
                        </div>
                    </form>
                    
                    <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                            <span class="text-sm text-gray-700">Two-factor authentication is enabled</span>
                        </div>
                        <span class="bg-green-100 text-green-800 text-xs font-semibold px-2 py-1 rounded-full">Active</span>
                    </div>
                    
                    <div class="mt-4 text-sm text-gray-500 flex items-center">
                        <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                        <span>Last login: <?= date('M j, Y g:i A', $_SESSION['login_time'] ?? time()) ?></span>
                    </div>
                    
                    <div class="mt-6 flex space-x-3">
                        <a href="<?= BASE_URL ?>/settings" 
                           class="flex-1 text-center bg-gray-100 text-gray-700 py-2 rounded-lg hover:bg-gray-200 transition-colors">
                            <i class="fas fa-cog mr-1"></i> Settings
                        </a>
                        <a href="<?= BASE_URL ?>/logout" 
                           class="flex-1 text-center bg-red-50 text-red-700 py-2 rounded-lg hover:bg-red-100 transition-colors">
                            <i class="fas fa-sign-out-alt mr-1"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
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
    
    // Password validation
    const newPassword = document.getElementById('new_password');
    const confirmPassword = document.getElementById('confirm_password');
    const matchIndicator = document.getElementById('password-match');
    
    if (newPassword && confirmPassword) {
        // Password requirements check
        const reqLength = document.querySelector('.req-length');
        const reqUpper = document.querySelector('.req-upper');
        const reqLower = document.querySelector('.req-lower');
        const reqNumber = document.querySelector('.req-number');
        const reqSpecial = document.querySelector('.req-special');
        
        function checkPasswordStrength() {
            const password = newPassword.value;
            
            // Length check
            if (password.length >= 8) {
                reqLength.innerHTML = '✅ At least 8 characters';
                reqLength.style.color = '#10b981';
            } else {
                reqLength.innerHTML = '❌ At least 8 characters';
                reqLength.style.color = '#ef4444';
            }
            
            // Uppercase check
            if (/[A-Z]/.test(password)) {
                reqUpper.innerHTML = '✅ At least one uppercase letter';
                reqUpper.style.color = '#10b981';
            } else {
                reqUpper.innerHTML = '❌ At least one uppercase letter';
                reqUpper.style.color = '#ef4444';
            }
            
            // Lowercase check
            if (/[a-z]/.test(password)) {
                reqLower.innerHTML = '✅ At least one lowercase letter';
                reqLower.style.color = '#10b981';
            } else {
                reqLower.innerHTML = '❌ At least one lowercase letter';
                reqLower.style.color = '#ef4444';
            }
            
            // Number check
            if (/[0-9]/.test(password)) {
                reqNumber.innerHTML = '✅ At least one number';
                reqNumber.style.color = '#10b981';
            } else {
                reqNumber.innerHTML = '❌ At least one number';
                reqNumber.style.color = '#ef4444';
            }
            
            // Special character check
            if (/[\W_]/.test(password)) {
                reqSpecial.innerHTML = '✅ At least one special character';
                reqSpecial.style.color = '#10b981';
            } else {
                reqSpecial.innerHTML = '❌ At least one special character';
                reqSpecial.style.color = '#ef4444';
            }
        }
        
        function validatePasswordMatch() {
            if (confirmPassword.value) {
                if (newPassword.value === confirmPassword.value) {
                    matchIndicator.innerHTML = '✅ Passwords match';
                    matchIndicator.style.color = '#10b981';
                    confirmPassword.setCustomValidity('');
                } else {
                    matchIndicator.innerHTML = '❌ Passwords do not match';
                    matchIndicator.style.color = '#ef4444';
                    confirmPassword.setCustomValidity('Passwords do not match');
                }
            } else {
                matchIndicator.innerHTML = '';
            }
        }
        
        newPassword.addEventListener('input', checkPasswordStrength);
        newPassword.addEventListener('input', validatePasswordMatch);
        confirmPassword.addEventListener('input', validatePasswordMatch);
        confirmPassword.addEventListener('keyup', validatePasswordMatch);
    }
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>