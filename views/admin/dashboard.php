<?php
// admin/dashboard.php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Only admin should view this page
if (empty($_SESSION['is_admin'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

require_once APP_ROOT . '/includes/header.php';

// Use the Database class properly
$db = Database::getInstance()->getConnection();

// Include ActivityLogger class
$activityLogger = new ActivityLogger($db);

// Set timezone to match your application
date_default_timezone_set('Africa/Accra');

// Initialize all variables with default values
$activeElections = [];
$voterCount = $candidateCount = $electionCount = $votedCount = $recentVotes = $pendingElections = 0;
$completedElections = 0;
$upcomingElections = 0;
$activeElectionsCount = 0;
$recentActivities = [];

// First, update election statuses based on current time
try {
    $current_time = date('Y-m-d H:i:s');
    
    // Update election statuses
    $update_stmt = $db->prepare("
        UPDATE elections 
        SET status = CASE
            WHEN :ct1 < start_date THEN 'upcoming'
            WHEN :ct2 <= end_date THEN 'active'
            ELSE 'ended'
        END
    ");
    $update_stmt->execute([':ct1' => $current_time, ':ct2' => $current_time]);
    
} catch (PDOException $e) {
    error_log("Error updating election statuses: " . $e->getMessage());
}

try {
    // Get elections with status = 'active'
    $stmt = $db->prepare("
        SELECT * FROM elections 
        WHERE status = 'active'
        ORDER BY start_date DESC
    ");
    $stmt->execute();
    $activeElections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database error fetching active elections: " . $e->getMessage());
    $activeElections = [];
}

// Get additional stats for the dashboard
try {
    $voterCount = $db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = 0")->fetch(PDO::FETCH_ASSOC)['count'];
    $candidateCount = $db->query("SELECT COUNT(*) as count FROM candidates")->fetch(PDO::FETCH_ASSOC)['count'];
    $electionCount = $db->query("SELECT COUNT(*) as count FROM elections")->fetch(PDO::FETCH_ASSOC)['count'];
    // Count distinct voters in the current active election (election-scoped, multi-year safe).
    // Falls back to the most recently ended election so the stat remains meaningful
    // after an election closes and before a new one starts.
    $votedCount = 0;
    try {
        $activeElForCount = $db->query(
            "SELECT id FROM elections
             WHERE status = 'active' AND NOW() BETWEEN start_date AND end_date
             ORDER BY start_date DESC LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);

        if (!$activeElForCount) {
            // No active election — use the most recently ended one
            $activeElForCount = $db->query(
                "SELECT id FROM elections WHERE status = 'ended'
                 ORDER BY end_date DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
        }

        if ($activeElForCount) {
            $votedStmt = $db->prepare(
                "SELECT COUNT(DISTINCT voter_id) as count FROM votes WHERE election_id = ?"
            );
            $votedStmt->execute([$activeElForCount['id']]);
            $votedCount = (int)$votedStmt->fetch(PDO::FETCH_ASSOC)['count'];
        }
    } catch (PDOException $e) {
        error_log("Dashboard voted count error: " . $e->getMessage());
    }
    
    // Get recent activity data
    $recentVotes = $db->query("SELECT COUNT(*) as count FROM votes WHERE created_at >= NOW() - INTERVAL 1 DAY")->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Count elections by status
    $completedElections = $db->query("
        SELECT COUNT(*) as count FROM elections WHERE status = 'ended'
    ")->fetch(PDO::FETCH_ASSOC)['count'];
    
    $upcomingElections = $db->query("
        SELECT COUNT(*) as count FROM elections WHERE status = 'upcoming'
    ")->fetch(PDO::FETCH_ASSOC)['count'];
    
    $pendingElections = $db->query("
        SELECT COUNT(*) as count FROM elections WHERE status = 'upcoming'
    ")->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get active elections count
    $activeElectionsCount = $db->query("
        SELECT COUNT(*) as count FROM elections WHERE status = 'active'
    ")->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get recent activities
    $recentActivities = $activityLogger->getRecentActivities(2);
    
} catch (PDOException $e) {
    error_log("Database error fetching stats: " . $e->getMessage());
}

// Calculate participation rate safely
$participationRate = 0;
if ($voterCount > 0) {
    $participationRate = round(($votedCount / $voterCount) * 100, 1);
}

// Log that admin viewed the dashboard
$userId = $_SESSION['user_id'] ?? 0;
$userName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
if (empty($userName)) {
    $userName = 'Unknown User';
}
$activityLogger->logActivity($userId, $userName, 'admin_action', 'Viewed admin dashboard');

// Calculate initial time remaining for each active election
$electionTimeRemaining = [];
foreach ($activeElections as $election) {
    try {
        $end_date = new DateTime($election['end_date']);
        $now = new DateTime();
        if ($end_date > $now) {
            $interval = $now->diff($end_date);
            $hours_remaining = $interval->h + ($interval->days * 24);
            $minutes_remaining = $interval->i;
            
            if ($hours_remaining < 1) {
                $time_text = $minutes_remaining . ' minutes remaining';
                $time_class = 'text-red-600 font-bold';
            } elseif ($hours_remaining < 24) {
                $time_text = $hours_remaining . ' hours, ' . $minutes_remaining . ' minutes remaining';
                $time_class = 'text-orange-600';
            } else {
                $time_text = $interval->format('%a days, %h hours remaining');
                $time_class = 'text-green-600';
            }
        } else {
            $time_text = 'Ended';
            $time_class = 'text-gray-500';
        }
        $electionTimeRemaining[$election['id']] = [
            'text' => $time_text,
            'class' => $time_class,
            'end_timestamp' => $end_date->getTimestamp()
        ];
    } catch (Exception $e) {
        $electionTimeRemaining[$election['id']] = [
            'text' => 'Date error',
            'class' => 'text-red-600',
            'end_timestamp' => 0
        ];
    }
}

// Function to get status badge class
function getStatusBadgeClass($status) {
    switch ($status) {
        case 'active':
            return 'bg-green-100 text-green-800';
        case 'upcoming':
            return 'bg-yellow-100 text-yellow-800';
        case 'ended':
            return 'bg-gray-100 text-gray-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}
?>

<!-- Add this meta tag for CSRF token that will be used in AJAX calls -->
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-10">
        <h2 class="text-3xl font-bold text-gray-900 mb-2">Admin Dashboard</h2>
        <p class="text-gray-600">Manage elections, candidates, and voter information</p>
    </div>

    <!-- Auto-refresh Status Bar -->
    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 mb-6 rounded-lg flex justify-between items-center">
        <div class="flex items-center">
            <i class="fas fa-sync-alt text-blue-500 mr-3 animate-spin-slow" id="refresh-spinner"></i>
            <span class="text-sm text-blue-700" id="refresh-status">
                Auto-refreshing every 30 seconds
            </span>
        </div>
        <div class="flex items-center space-x-4">
            <span class="text-xs text-blue-600" id="last-updated">
                Last updated: just now
            </span>
            <button onclick="manualRefresh()" class="text-blue-600 hover:text-blue-800 text-sm flex items-center">
                <i class="fas fa-redo-alt mr-1"></i> Refresh Now
            </button>
        </div>
    </div>

    <!-- Enhanced Stats Section -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-10">
        <!-- Election Card -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-1">Total Elections</p>
                        <h3 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($electionCount) ?></h3>
                        <div class="flex flex-wrap gap-2 mt-2">
                            <?php if ($pendingElections > 0): ?>
                            <span class="bg-amber-100 text-amber-800 text-xs font-medium px-2 py-0.5 rounded-full">
                                <?= htmlspecialchars($pendingElections) ?> pending
                            </span>
                            <?php endif; ?>
                            <?php if ($upcomingElections > 0): ?>
                            <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2 py-0.5 rounded-full">
                                <?= htmlspecialchars($upcomingElections) ?> upcoming
                            </span>
                            <?php endif; ?>
                            <?php if ($activeElectionsCount > 0): ?>
                            <span class="bg-green-100 text-green-800 text-xs font-medium px-2 py-0.5 rounded-full">
                                <?= htmlspecialchars($activeElectionsCount) ?> active
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="bg-pink-100 p-4 rounded-full">
                        <i class="fas fa-calendar-alt text-pink-600 text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100 flex justify-between items-center">
                    <a href="<?= BASE_URL ?>/index.php?page=admin/elections" class="text-xs font-medium text-pink-700 hover:text-pink-900 flex items-center">
                        Manage elections <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                    <span class="text-xs font-medium text-gray-700 bg-gray-100 px-2 py-1 rounded-full">
                        <?= htmlspecialchars($completedElections) ?> completed
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Voter Card -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-1">Total Voters</p>
                        <h3 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($voterCount) ?></h3>
                        <?php if ($voterCount > 0): ?>
                        <p class="text-xs text-gray-500 mt-1">
                            <?= htmlspecialchars($voterCount - $votedCount) ?> not voted yet
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-blue-100 p-4 rounded-full">
                        <i class="fas fa-users text-blue-600 text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100">
                    <a href="<?= BASE_URL ?>/index.php?page=admin/voters" class="text-xs font-medium text-blue-700 hover:text-blue-900 flex items-center">
                        Manage voters <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Candidate Card -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-1">Candidates</p>
                        <h3 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($candidateCount) ?></h3>
                        <?php if ($candidateCount > 0 && $electionCount > 0): 
                            $avgCandidates = round($candidateCount / $electionCount, 1);
                        ?>
                        <p class="text-xs text-gray-500 mt-1">
                            ~<?= htmlspecialchars($avgCandidates) ?> per election
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-emerald-100 p-4 rounded-full">
                        <i class="fas fa-user-tie text-emerald-600 text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100">
                    <a href="<?= BASE_URL ?>/index.php?page=admin/candidates" class="text-xs font-medium text-emerald-700 hover:text-emerald-900 flex items-center">
                        Manage candidates <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Votes Card -->
        <div class="bg-white rounded-xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
            <div class="p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-500 mb-1">Votes Cast</p>
                        <h3 class="text-3xl font-bold text-gray-900"><?= htmlspecialchars($votedCount) ?></h3>
                        <?php if ($voterCount > 0): ?>
                        <div class="flex items-center mt-1">
                            <div class="w-full bg-gray-200 rounded-full h-1.5 mr-2">
                                <div class="bg-purple-600 h-1.5 rounded-full" style="width: <?= min($participationRate, 100) ?>%"></div>
                            </div>
                            <span class="text-xs font-medium text-purple-600"><?= htmlspecialchars($participationRate) ?>%</span>
                        </div>
                        <?php endif; ?>
                        <?php if ($recentVotes > 0): ?>
                        <p class="text-xs text-purple-600 mt-1 font-medium">
                            <span class="inline-flex items-center">
                                <i class="fas fa-bolt mr-1"></i> <?= htmlspecialchars($recentVotes) ?> today
                            </span>
                        </p>
                        <?php endif; ?>
                    </div>
                    <div class="bg-purple-100 p-4 rounded-full">
                        <i class="fas fa-vote-yea text-purple-600 text-2xl"></i>
                    </div>
                </div>
                <div class="mt-4 pt-3 border-t border-gray-100">
                    <a href="<?= BASE_URL ?>/index.php?page=results" class="text-xs font-medium text-purple-700 hover:text-purple-900 flex items-center">
                        View results <i class="fas fa-arrow-right ml-1 text-xs"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-8">
        <!-- Active Elections Section with Live Updates -->
        <div class="bg-white p-6 rounded-xl shadow-md">
            <div class="flex justify-between items-center mb-5">
                <div class="flex items-center">
                    <h3 class="text-xl font-bold text-gray-900">Active Elections</h3>
                    <span id="active-count-badge" class="ml-3 bg-green-100 text-green-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                        <?= count($activeElections) ?> Active
                    </span>
                </div>
                <div class="flex items-center space-x-2">
                    <button onclick="refreshActiveElections()" class="text-gray-500 hover:text-gray-700" title="Refresh active elections">
                        <i class="fas fa-sync-alt" id="active-refresh-icon"></i>
                    </button>
                    <span class="text-xs text-gray-400" id="active-election-countdown"></span>
                </div>
            </div>
            
            <div id="active-elections-container" class="space-y-4">
                <?php if (!empty($activeElections)): ?>
                    <?php foreach ($activeElections as $election): 
                    $timeData = $electionTimeRemaining[$election['id']] ?? [
                        'text' => 'Calculating...',
                        'class' => 'text-gray-500',
                        'end_timestamp' => 0
                    ];
                    $statusBadgeClass = getStatusBadgeClass($election['status']);
                    ?>
                        <div class="election-item border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors duration-200" 
                             data-election-id="<?= $election['id'] ?>" 
                             data-end-date="<?= $election['end_date'] ?>"
                             data-end-timestamp="<?= $timeData['end_timestamp'] ?>"
                             data-status="<?= $election['status'] ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-900"><?= htmlspecialchars($election['title']) ?></h4>
                                    <p class="text-sm text-gray-600 mt-1">
                                        <i class="far fa-calendar-alt mr-1"></i> 
                                        <?= date('M j, Y g:i A', strtotime($election['start_date'])) ?> - <?= date('M j, Y g:i A', strtotime($election['end_date'])) ?>
                                    </p>
                                    <?php if ($timeData['text']): ?>
                                        <p class="text-sm <?= $timeData['class'] ?> mt-1 time-remaining-container">
                                            <i class="fas fa-hourglass-half mr-1"></i> 
                                            <span class="time-remaining"><?= $timeData['text'] ?></span>
                                        </p>
                                    <?php endif; ?>
                                </div>
                                <span class="status-badge px-2.5 py-0.5 text-xs font-semibold rounded-full <?= $statusBadgeClass ?>" data-status="<?= $election['status'] ?>">
                                    <?= ucfirst($election['status']) ?>
                                </span>
                            </div>
                            <div class="mt-3 flex space-x-3">
                                <a href="<?= BASE_URL ?>/index.php?page=admin/elections&action=view&id=<?= $election['id'] ?>" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                    <i class="fas fa-eye mr-1"></i> View
                                </a>
                                <a href="<?= BASE_URL ?>/index.php?page=admin/elections&action=edit&id=<?= $election['id'] ?>" class="text-xs text-gray-600 hover:text-gray-800 font-medium">
                                    <i class="fas fa-edit mr-1"></i> Edit
                                </a>
                                <a href="<?= BASE_URL ?>/index.php?page=results&election_id=<?= $election['id'] ?>" class="text-xs text-purple-600 hover:text-purple-800 font-medium">
                                    <i class="fas fa-chart-bar mr-1"></i> Results
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div id="no-active-elections" class="text-center py-6 text-gray-500">
                        <i class="fas fa-calendar-times text-3xl mb-3 text-gray-300"></i>
                        <p>No active elections at the moment</p>
                        <a href="<?= BASE_URL ?>/index.php?page=admin/elections&action=create" class="inline-block mt-3 px-4 py-2 bg-pink-900 text-white rounded-lg text-sm hover:bg-pink-800 transition-colors">
                            Create New Election
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Actions Section -->
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h3 class="text-xl font-bold mb-4 text-gray-900">Quick Actions</h3>
            <div class="grid grid-cols-2 gap-3 mb-6">
                <a href="<?= BASE_URL ?>/index.php?page=admin/elections&action=create" 
                   class="bg-pink-900 hover:bg-pink-800 text-white px-4 py-3 rounded-lg text-center transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-vote-yea text-2xl mb-1"></i>
                    <span>New Election</span>
                </a>
                <a href="<?= BASE_URL ?>/index.php?page=admin/candidates&action=create" 
                   class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-3 rounded-lg text-center transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-user-plus text-2xl mb-1"></i>
                    <span>Add Candidate</span>
                </a>
                <a href="<?= BASE_URL ?>/index.php?page=admin/voters" 
                   class="bg-yellow-700 hover:bg-yellow-800 text-white px-4 py-3 rounded-lg text-center transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-users text-2xl mb-1"></i>
                    <span>Manage Voters</span>
                </a>
                <a href="<?= BASE_URL ?>/index.php?page=admin/activity_logs" 
                   class="bg-indigo-900 hover:bg-indigo-800 text-white px-4 py-3 rounded-lg text-center transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-history text-2xl mb-1"></i>
                    <span>Activity Logs</span>
                </a>
                <a href="<?= BASE_URL ?>/index.php?page=admin/graduation" 
                   class="bg-purple-700 hover:bg-purple-800 text-white px-4 py-3 rounded-lg text-center transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-graduation-cap text-2xl mb-1"></i>
                    <span>Graduation</span>
                </a>
                <a href="<?= BASE_URL ?>/index.php?page=admin/settings" 
                   class="bg-blue-900 hover:bg-blue-800 text-white px-4 py-3 rounded-lg text-center transition-colors flex flex-col items-center justify-center">
                    <i class="fas fa-cogs text-2xl mb-1"></i>
                    <span>System Settings</span>
                </a>
            </div>
            
            <!-- Recent Activity -->
            <div class="pt-6 border-t border-gray-200">
                <div class="flex justify-between items-center mb-3">
                    <h4 class="font-semibold text-gray-900">Recent Activity</h4>
                    <a href="<?= BASE_URL ?>/index.php?page=admin/activity_logs" class="text-sm text-blue-600 hover:text-blue-800">
                        View All
                    </a>
                </div>
                <div id="recent-activity-container" class="space-y-3">
                    <?php if (!empty($recentActivities)): ?>
                        <?php foreach ($recentActivities as $activity): ?>
                            <div class="flex items-center text-sm text-gray-600 p-2 bg-gray-50 rounded-lg hover:bg-blue-50 transition-colors">
                                <div class="flex-shrink-0 mr-3">
                                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-user text-blue-500 text-sm"></i>
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="font-medium text-gray-900 truncate"><?= htmlspecialchars($activity['user_name']) ?></p>
                                    <p class="truncate text-xs"><?= htmlspecialchars($activity['description']) ?></p>
                                    <p class="text-xs text-gray-500"><?= date('M j, g:i A', strtotime($activity['created_at'])) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-center py-4 text-gray-500">
                            <i class="fas fa-info-circle mr-2"></i> No recent activity
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
class DashboardManager {
    constructor() {
        this.refreshInterval = 30000;
        this.countdownInterval = null;
        this.autoRefreshEnabled = true;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.baseUrl = '<?= BASE_URL ?>';
        this.timeUpdateInterval = null;
        // Track which election IDs were active on the last server fetch.
        // When the client-side countdown detects a change (an election just
        // ended OR a new one became active), we trigger an immediate server
        // refresh instead of waiting up to 30 seconds.
        this._lastKnownActiveIds = new Set();
        this._immediateRefreshPending = false;

        if (this.csrfToken) {
            this.init();
        } else {
            console.warn('CSRF token not found');
        }
    }

    init() {
        // Seed _lastKnownActiveIds from the PHP-rendered election items
        document.querySelectorAll('.election-item[data-status="active"]').forEach(el => {
            this._lastKnownActiveIds.add(el.dataset.electionId);
        });

        this.startAutoRefresh();
        this.updateCountdown();
        this.attachEventListeners();
        this.startTimeUpdates();
        this.attachVisibilityHandler();

        // Initial fetch after 2 seconds to sync with server immediately
        setTimeout(() => this.refreshActiveElections(), 2000);
    }

    attachEventListeners() {
        document.querySelector('[onclick="manualRefresh()"]')
            ?.addEventListener('click', e => { e.preventDefault(); this.manualRefresh(); });
        document.querySelector('[onclick="refreshActiveElections()"]')
            ?.addEventListener('click', e => { e.preventDefault(); this.refreshActiveElections(); });
    }

    // Re-sync when the tab regains focus — handles the case where the admin
    // left the tab open and came back after an election ended or started.
    attachVisibilityHandler() {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.refreshActiveElections();
            }
        });
    }

    startAutoRefresh() {
        setInterval(() => {
            if (this.autoRefreshEnabled) this.refreshActiveElections();
        }, this.refreshInterval);
    }

    startTimeUpdates() {
        this.timeUpdateInterval = setInterval(() => this.updateAllTimeRemaining(), 1000);
    }

    updateAllTimeRemaining() {
        const electionItems = document.querySelectorAll('.election-item');
        const now = Math.floor(Date.now() / 1000);
        let statusChangedOnClient = false;

        electionItems.forEach(item => {
            const endTimestamp = parseInt(item.dataset.endTimestamp);
            if (!endTimestamp) return;

            const timeRemainingSpan = item.querySelector('.time-remaining');
            const timeContainer    = item.querySelector('.time-remaining-container');
            const statusBadge      = item.querySelector('.status-badge');
            if (!timeRemainingSpan || !timeContainer || !statusBadge) return;

            const diffSeconds  = endTimestamp - now;
            const wasActive    = item.dataset.status === 'active';

            if (diffSeconds <= 0) {
                // Election crossed the end boundary on the client
                timeRemainingSpan.textContent = 'Ended';
                timeContainer.className = 'text-sm text-gray-500 mt-1 time-remaining-container';
                statusBadge.className   = 'status-badge px-2.5 py-0.5 text-xs font-semibold rounded-full bg-gray-100 text-gray-800';
                statusBadge.textContent = 'Ended';
                item.dataset.status     = 'ended';

                // If this item was previously active, we need a server refresh
                // to remove it from the list and show "Create New Election"
                if (wasActive) statusChangedOnClient = true;
            } else {
                const hours   = Math.floor(diffSeconds / 3600);
                const minutes = Math.floor((diffSeconds % 3600) / 60);
                const seconds = diffSeconds % 60;

                let timeText, timeClass;
                if (hours < 1) {
                    timeText  = `${minutes}m ${seconds}s remaining`;
                    timeClass = 'text-red-600 font-bold';
                } else if (hours < 24) {
                    timeText  = `${hours}h ${minutes}m remaining`;
                    timeClass = 'text-orange-600';
                } else {
                    const days = Math.floor(hours / 24);
                    timeText   = `${days}d ${hours % 24}h remaining`;
                    timeClass  = 'text-green-600';
                }

                timeRemainingSpan.textContent = timeText;
                timeContainer.className = `text-sm ${timeClass} mt-1 time-remaining-container`;
                statusBadge.className   = 'status-badge px-2.5 py-0.5 text-xs font-semibold rounded-full bg-green-100 text-green-800';
                statusBadge.textContent = 'Active';
                item.dataset.status     = 'active';
            }
        });

        // If any election just ended on the client side, immediately fetch
        // fresh data from the server so the list is reconciled right away.
        if (statusChangedOnClient && !this._immediateRefreshPending) {
            this._immediateRefreshPending = true;
            // Short delay so the "Ended" label is visible for a moment
            setTimeout(() => {
                this._immediateRefreshPending = false;
                this.refreshActiveElections();
            }, 1500);
        }
    }

    updateCountdown() {
        const countdownEl = document.getElementById('active-election-countdown');
        if (!countdownEl) return;
        let seconds = this.refreshInterval / 1000;
        if (this.countdownInterval) clearInterval(this.countdownInterval);
        this.countdownInterval = setInterval(() => {
            seconds--;
            if (seconds <= 0) seconds = this.refreshInterval / 1000;
            countdownEl.textContent = `Refreshing in ${seconds}s`;
        }, 1000);
    }

    async manualRefresh() {
        this.showRefreshSpinner(true);
        await this.refreshActiveElections();
        this.showRefreshSpinner(false);
    }

    async refreshActiveElections() {
        const refreshIcon = document.getElementById('active-refresh-icon');
        const container   = document.getElementById('active-elections-container');

        refreshIcon?.classList.add('fa-spin');
        container?.classList.add('updating');

        try {
            // Single call: get-active-elections already updates statuses server-side
            const response = await fetch(`${this.baseUrl}/api/admin/get-active-elections.php`, {
                headers: { 'X-CSRF-Token': this.csrfToken }
            });

            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();

            if (data.success) {
                this.updateActiveElectionsList(data.elections || []);
                this.updateActiveCount(data.count || 0);

                // Detect newly-active elections (upcoming → active transition).
                // If the server returns an ID we hadn't seen before as active,
                // the list will already show it — but update our tracking set.
                const newIds = new Set((data.elections || []).map(e => String(e.id)));
                this._lastKnownActiveIds = newIds;
            } else {
                console.warn('get-active-elections:', data.message);
            }
        } catch (error) {
            console.error('Failed to refresh active elections:', error);
        } finally {
            refreshIcon?.classList.remove('fa-spin');
            container?.classList.remove('updating');
            this.updateLastUpdatedTime();
        }
    }

    updateActiveElectionsList(elections) {
        const container = document.getElementById('active-elections-container');
        if (!container) return;

        if (!elections || elections.length === 0) {
            container.innerHTML = `
                <div id="no-active-elections" class="text-center py-6 text-gray-500">
                    <i class="fas fa-calendar-times text-3xl mb-3 text-gray-300"></i>
                    <p>No active elections at the moment</p>
                    <a href="${this.baseUrl}/index.php?page=admin/elections" class="inline-block mt-3 px-4 py-2 bg-pink-900 text-white rounded-lg text-sm hover:bg-pink-800 transition-colors">
                        Create New Election
                    </a>
                </div>`;
            return;
        }

        container.innerHTML = elections.map(election => {
            const endTimestamp   = Math.floor(new Date(election.end_date).getTime() / 1000);
            const statusClass    = election.status === 'active'   ? 'bg-green-100 text-green-800'  :
                                   election.status === 'upcoming' ? 'bg-yellow-100 text-yellow-800' :
                                                                    'bg-gray-100 text-gray-800';
            return `
                <div class="election-item border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors duration-200"
                     data-election-id="${election.id}"
                     data-end-date="${election.end_date}"
                     data-end-timestamp="${endTimestamp}"
                     data-status="${election.status}">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <h4 class="font-medium text-gray-900">${this.escapeHtml(election.title)}</h4>
                            <p class="text-sm text-gray-600 mt-1">
                                <i class="far fa-calendar-alt mr-1"></i>
                                ${this.formatDate(election.start_date)} &ndash; ${this.formatDate(election.end_date)}
                            </p>
                            <p class="text-sm ${election.status === 'active' ? 'text-green-600' : 'text-gray-500'} mt-1 time-remaining-container">
                                <i class="fas fa-hourglass-half mr-1"></i>
                                <span class="time-remaining">${election.status === 'active' ? 'Calculating...' : 'Ended'}</span>
                            </p>
                        </div>
                        <span class="status-badge px-2.5 py-0.5 text-xs font-semibold rounded-full ${statusClass}" data-status="${election.status}">
                            ${election.status.charAt(0).toUpperCase() + election.status.slice(1)}
                        </span>
                    </div>
                    <div class="mt-3 flex space-x-3">
                        <a href="${this.baseUrl}/index.php?page=admin/elections&action=view&id=${election.id}" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                            <i class="fas fa-eye mr-1"></i> View
                        </a>
                        <a href="${this.baseUrl}/index.php?page=admin/elections&action=edit&id=${election.id}" class="text-xs text-gray-600 hover:text-gray-800 font-medium">
                            <i class="fas fa-edit mr-1"></i> Edit
                        </a>
                        <a href="${this.baseUrl}/index.php?page=results&election_id=${election.id}" class="text-xs text-purple-600 hover:text-purple-800 font-medium">
                            <i class="fas fa-chart-bar mr-1"></i> Results
                        </a>
                    </div>
                </div>`;
        }).join('');

        // Run client-side countdown immediately so items show real time
        setTimeout(() => this.updateAllTimeRemaining(), 50);
    }

    updateActiveCount(count) {
        const badge = document.getElementById('active-count-badge');
        if (badge) badge.textContent = `${count} Active`;
    }

    formatDate(dateString) {
        return new Date(dateString).toLocaleString('en-US', {
            month: 'short', day: 'numeric', year: 'numeric',
            hour: 'numeric', minute: '2-digit', hour12: true
        });
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    showRefreshSpinner(show) {
        const spinner = document.getElementById('refresh-spinner');
        if (!spinner) return;
        spinner.classList.toggle('animate-spin', show);
    }

    updateLastUpdatedTime() {
        const el = document.getElementById('last-updated');
        if (el) el.textContent = `Last updated: ${new Date().toLocaleTimeString()}`;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    try { window.dashboardManager = new DashboardManager(); }
    catch (e) { console.error('DashboardManager init failed:', e); }
});

function manualRefresh()         { window.dashboardManager?.manualRefresh(); }
function refreshActiveElections(){ window.dashboardManager?.refreshActiveElections(); }
</script>

<style>
/* Animation for refresh spinner */
@keyframes spin-slow {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.animate-spin-slow {
    animation: spin-slow 2s linear infinite;
}

/* Transition for election items */
.election-item {
    transition: all 0.3s ease;
}

.election-item:hover {
    transform: translateX(5px);
}

/* Status badge pulse animation for active elections */
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.7; }
}

.status-badge[data-status="active"] {
    animation: pulse 2s infinite;
}

/* Smooth fade for updates */
#active-elections-container {
    transition: opacity 0.3s ease;
}

#active-elections-container.updating {
    opacity: 0.6;
}

/* Time remaining countdown animation */
.time-remaining-container {
    transition: color 0.3s ease;
}
</style>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>