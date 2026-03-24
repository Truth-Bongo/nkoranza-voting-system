<?php
// admin/elections.php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

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

require_once APP_ROOT . '/includes/header.php';
$db = Database::getInstance()->getConnection();

// Initialize Activity Logger
$activityLogger = new ActivityLogger($db);

// Get user information for logging
$userId = $_SESSION['user_id'] ?? 'unknown';
$userName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
if (empty(trim($userName))) $userName = $userId;

// Fetch system settings for voting times and academic year
try {
    // Get voting time settings
    $settings_stmt = $db->query("
        SELECT setting_key, setting_value 
        FROM settings 
        WHERE setting_key IN ('voting_start_time', 'voting_end_time', 'current_academic_year')
    ");
    $settings = [];
    while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    
    $voting_start_time = $settings['voting_start_time'] ?? '08:00:00';
    $voting_end_time = $settings['voting_end_time'] ?? '17:00:00';
    $current_academic_year = $settings['current_academic_year'] ?? date('Y');
    
} catch (Exception $e) {
    error_log("Error fetching settings: " . $e->getMessage());
    $voting_start_time = '08:00:00';
    $voting_end_time = '17:00:00';
    $current_academic_year = date('Y');
}

// Log page access
$activityLogger->logActivity(
    $userId,
    $userName,
    'elections_page_view',
    'Accessed elections management page',
    json_encode(['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'])
);

// Set timezone
date_default_timezone_set('Africa/Accra');

// Auto-update election statuses before display
$now = date('Y-m-d H:i:s');
$db->exec("
    UPDATE elections 
    SET status = CASE
        WHEN '$now' < start_date THEN 'upcoming'
        WHEN '$now' <= end_date THEN 'active'
        ELSE 'ended'
    END
");

// Handle search and filter
$search = $_GET['search'] ?? '';
$status_filter = $_GET['status'] ?? 'all';

// Build query with filters
$query = "SELECT * FROM elections WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (title LIKE :search OR description LIKE :search)";
    $params[':search'] = "%$search%";
    
    // Log search
    $activityLogger->logActivity(
        $userId,
        $userName,
        'elections_search',
        'Searched elections',
        json_encode(['search_term' => $search])
    );
}

if ($status_filter !== 'all') {
    $query .= " AND status = :status";
    $params[':status'] = $status_filter;
    
    // Log filter
    $activityLogger->logActivity(
        $userId,
        $userName,
        'elections_filter',
        'Filtered elections by status',
        json_encode(['status' => $status_filter])
    );
}

$query .= " ORDER BY start_date DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics for dashboard
$stats_stmt = $db->query("
    SELECT 
        status,
        COUNT(*) as count
    FROM elections 
    GROUP BY status
");
$stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

$status_counts = [
    'active' => 0,
    'upcoming' => 0,
    'ended' => 0,
    'all' => count($elections)
];

foreach ($stats as $stat) {
    $status_counts[$stat['status']] = $stat['count'];
}

function js_json($data) {
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
?>

<!-- Add CSRF token meta tag for JavaScript -->
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<!-- Modern Styles -->
<style>
:root {
    --primary: #831843;
    --primary-light: #9d174d;
    --primary-soft: #fce7f3;
    --primary-bg: #fdf2f8;
}

.modern-card {
    background: white;
    border-radius: 1rem;
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
    transition: all 0.3s ease;
    border: 1px solid rgba(131, 24, 67, 0.1);
}

.modern-card:hover {
    box-shadow: 0 20px 30px -10px rgba(131, 24, 67, 0.15);
    transform: translateY(-2px);
}

.stat-card {
    background: linear-gradient(135deg, white 0%, #faf5ff 100%);
    border-left: 4px solid var(--primary);
    border-radius: 0.75rem;
    padding: 1.25rem;
    transition: all 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(131, 24, 67, 0.2);
}

.action-btn {
    background: var(--primary-soft);
    color: var(--primary);
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    transition: all 0.2s ease;
    border: 1px solid var(--primary);
    cursor: pointer;
}

.action-btn:hover {
    background: var(--primary);
    color: white;
}

.table-row {
    transition: all 0.2s ease;
}

.table-row:hover {
    background-color: var(--primary-bg);
}

.gradient-bg {
    background: linear-gradient(135deg, var(--primary) 0%, #b91c5c 100%);
}

/* Animations */
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-in {
    animation: fadeIn 0.3s ease-out;
}

/* Auto-refresh bar */
.auto-refresh-bar {
    background-color: #e0f2fe;
    border-left: 4px solid #0284c7;
    padding: 0.75rem 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1.5rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.refresh-spinner {
    animation: spin 2s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Toast notifications */
.toast-container {
    position: fixed;
    top: 20px;
    right: 20px;
    z-index: 9999;
}

.toast {
    animation: slideIn 0.3s ease-out;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

/* Custom scrollbar */
.custom-scroll::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}

.custom-scroll::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.custom-scroll::-webkit-scrollbar-thumb {
    background: var(--primary);
    border-radius: 10px;
}

/* Loading spinner */
.spinner {
    border: 3px solid var(--primary-soft);
    border-top: 3px solid var(--primary);
    border-radius: 50%;
    width: 40px;
    height: 40px;
    animation: spin 1s linear infinite;
}

/* Time display */
.time-display {
    font-family: 'Courier New', monospace;
    background: #f3f4f6;
    padding: 2px 6px;
    border-radius: 4px;
    font-size: 0.75rem;
}

.system-time-badge {
    background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
    color: #1e40af;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    border: 1px solid #3b82f6;
}

/* Settings link button */
.settings-link-btn {
    display: inline-flex;
    align-items: center;
    padding: 0.5rem 1rem;
    background: linear-gradient(135deg, #f3f4f6 0%, #e5e7eb 100%);
    color: #4b5563;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 1px solid #d1d5db;
}

.settings-link-btn:hover {
    background: linear-gradient(135deg, #e5e7eb 0%, #d1d5db 100%);
    color: #1f2937;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.settings-link-btn i {
    color: #6b7280;
    margin-right: 0.5rem;
}

.settings-link-btn:hover i {
    color: #374151;
}

/* System time indicator */
.system-time-indicator {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 1rem;
    background: #f3f4f6;
    border-radius: 9999px;
    font-size: 0.875rem;
    color: #4b5563;
    border: 1px solid #e5e7eb;
}

.system-time-indicator i {
    color: #3b82f6;
    margin-right: 0.5rem;
}

/* Delete animation */
.row-deleting {
    opacity: 0;
    transform: translateX(-10px);
    transition: opacity 0.3s ease, transform 0.3s ease;
}

/* Search and filter container */
.table-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.search-filter-container {
    display: flex;
    gap: 1rem;
    flex: 1;
    max-width: 600px;
}

.search-box {
    position: relative;
    flex: 1;
}

.search-box i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
    font-size: 0.875rem;
}

.search-box input {
    width: 100%;
    padding: 0.625rem 1rem 0.625rem 2.5rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    transition: all 0.2s;
}

.search-box input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(131, 24, 67, 0.1);
}

.filter-select {
    padding: 0.625rem 2rem 0.625rem 1rem;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    font-size: 0.875rem;
    background-color: white;
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20' stroke='%236b7280'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.5rem center;
    background-repeat: no-repeat;
    background-size: 1.5em 1.5em;
}

.filter-select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(131, 24, 67, 0.1);
}

.clear-filters-btn {
    padding: 0.625rem 1rem;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    color: #6b7280;
    font-size: 0.875rem;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
}

.clear-filters-btn:hover {
    background: #f3f4f6;
    border-color: #d1d5db;
    color: #374151;
}

.table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 1rem 1.5rem;
    background: #f9fafb;
    border-top: 1px solid #e5e7eb;
    font-size: 0.875rem;
    color: #6b7280;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="p-6 mb-8 fade-in">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center space-x-4">
                <div class="gradient-bg rounded-2xl p-4 shadow-lg">
                    <i class="fas fa-vote-yea text-3xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Elections <span class="text-pink-900">Management</span></h1>
                    <p class="text-gray-600 mt-1">Manage active, upcoming, and past elections</p>
                </div>
            </div>
            <div class="flex items-center space-x-3 mt-4 md:mt-0">
                <!-- Simple System Time Indicator -->
                <div class="system-time-indicator">
                    <i class="fas fa-clock"></i>
                    <span><?= date('g:i A', strtotime($voting_start_time)) ?> - <?= date('g:i A', strtotime($voting_end_time)) ?></span>
                </div>
                
                <!-- Simple Settings Link Button -->
                <a href="<?= BASE_URL ?>/index.php?page=admin/settings&tab=general" 
                   class="settings-link-btn">
                    <i class="fas fa-cog"></i>
                    Voting Times
                </a>
                
                <button id="position-templates-btn"
                        onclick="openPositionTemplatesModal()"
                        class="action-btn flex items-center"
                        style="background:#1d4ed8;border-color:#1d4ed8"
                        title="Define reusable positions before creating an election">
                    <i class="fas fa-layer-group mr-2"></i> Position Templates
                </button>

                <button id="add-election-btn" class="action-btn flex items-center">
                    <i class="fas fa-plus mr-2"></i> Add Election
                </button>
            </div>
        </div>
    </div>

    <!-- Auto-refresh Status Bar -->
    <div class="auto-refresh-bar">
        <div class="flex items-center">
            <i class="fas fa-sync-alt text-blue-600 mr-2 refresh-spinner" id="refresh-spinner"></i>
            <span class="text-sm text-blue-800" id="refresh-status">
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

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-8">
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Total Elections</p>
                    <p class="text-3xl font-bold text-gray-800" id="total-elections"><?= $status_counts['all'] ?></p>
                </div>
                <div class="bg-blue-100 p-3 rounded-lg">
                    <i class="fas fa-calendar-alt text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Active</p>
                    <p class="text-3xl font-bold text-gray-800" id="active-elections"><?= $status_counts['active'] ?></p>
                </div>
                <div class="bg-green-100 p-3 rounded-lg">
                    <i class="fas fa-vote-yea text-green-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Upcoming</p>
                    <p class="text-3xl font-bold text-gray-800" id="upcoming-elections"><?= $status_counts['upcoming'] ?></p>
                </div>
                <div class="bg-yellow-100 p-3 rounded-lg">
                    <i class="fas fa-clock text-yellow-600 text-xl"></i>
                </div>
            </div>
        </div>
        
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Ended</p>
                    <p class="text-3xl font-bold text-gray-800" id="ended-elections"><?= $status_counts['ended'] ?></p>
                </div>
                <div class="bg-red-100 p-3 rounded-lg">
                    <i class="fas fa-times-circle text-red-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Elections Table with Integrated Search and Filter -->
    <div class="modern-card overflow-hidden">
        <!-- Table Toolbar with Search and Filter -->
        <div class="table-toolbar">
            <div class="search-filter-container">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" 
                           id="search" 
                           value="<?= htmlspecialchars($search) ?>" 
                           placeholder="Search elections by title or description..."
                           onkeypress="if(event.key === 'Enter') applyFilters()">
                </div>
                <select id="status-filter" class="filter-select" onchange="applyFilters()">
                    <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>🟢 Active</option>
                    <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>🟡 Upcoming</option>
                    <option value="ended" <?= $status_filter === 'ended' ? 'selected' : '' ?>>🔴 Ended</option>
                </select>
                <?php if (!empty($search) || $status_filter !== 'all'): ?>
                <button class="clear-filters-btn" onclick="clearFilters()">
                    <i class="fas fa-times"></i> Clear Filters
                </button>
                <?php endif; ?>
            </div>
            <div class="text-sm text-gray-500">
                <span id="showing-count"><?= count($elections) ?></span> elections
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto custom-scroll">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Start Date</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">End Date</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Candidates</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody id="elections-table-body" class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($elections)): ?>
                        <?php foreach ($elections as $election): 
                            $candidate_stmt = $db->prepare("SELECT COUNT(*) as count FROM candidates WHERE election_id = ?");
                            $candidate_stmt->execute([$election['id']]);
                            $candidate_count = $candidate_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                            
                            // Format dates for display
                            $start_date = new DateTime($election['start_date']);
                            $end_date = new DateTime($election['end_date']);
                        ?>
                            <tr class="table-row" data-election-id="<?= $election['id'] ?>" data-status="<?= $election['status'] ?>">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-gray-900"><?= htmlspecialchars($election['title']) ?></div>
                                    <?php if (!empty($election['description'])): ?>
                                        <div class="text-xs text-gray-500 mt-1"><?= htmlspecialchars(substr($election['description'], 0, 50)) ?>...</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="font-medium text-gray-900"><?= $start_date->format('M j, Y') ?></div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1"></i>
                                        <span class="time-display"><?= $start_date->format('g:i A') ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <div class="font-medium text-gray-900"><?= $end_date->format('M j, Y') ?></div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <i class="far fa-clock mr-1"></i>
                                        <span class="time-display"><?= $end_date->format('g:i A') ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 status-cell">
                                    <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full status-badge
                                        <?= $election['status'] === 'active' ? 'bg-green-100 text-green-800' : ($election['status'] === 'upcoming' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800') ?>">
                                        <?= ucfirst($election['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <i class="fas fa-users mr-1"></i> <?= $candidate_count ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex space-x-3">
                                        <a href="<?= BASE_URL ?>/index.php?page=admin/candidates&election_id=<?= $election['id'] ?>" 
                                           class="text-indigo-600 hover:text-indigo-900 candidates-link"
                                           data-election-id="<?= $election['id'] ?>"
                                           data-election-title="<?= htmlspecialchars($election['title']) ?>"
                                           title="Manage Candidates">
                                            <i class="fas fa-users text-lg"></i>
                                        </a>
                                        <button class="edit-election-btn text-blue-600 hover:text-blue-900" 
                                                data-election='<?= js_json($election) ?>' 
                                                data-election-id="<?= $election['id'] ?>"
                                                data-election-title="<?= htmlspecialchars($election['title']) ?>"
                                                title="Edit Election">
                                            <i class="fas fa-edit text-lg"></i>
                                        </button>
                                        <button class="delete-election-btn text-red-600 hover:text-red-900" 
                                                data-id="<?= $election['id'] ?>" 
                                                data-title="<?= htmlspecialchars($election['title']) ?>"
                                                title="Delete Election">
                                            <i class="fas fa-trash text-lg"></i>
                                        </button>
                                        <?php if ($election['status'] === 'ended'): ?>
                                            <a href="<?= BASE_URL ?>/index.php?page=results&election_id=<?= $election['id'] ?>" 
                                               class="text-green-600 hover:text-green-900 results-link"
                                               data-election-id="<?= $election['id'] ?>"
                                               data-election-title="<?= htmlspecialchars($election['title']) ?>"
                                               title="View Results">
                                                <i class="fas fa-chart-bar text-lg"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr id="no-elections-row">
                            <td colspan="6" class="text-center py-12 text-gray-500">
                                <i class="fas fa-calendar-times text-5xl mb-4 text-gray-300"></i>
                                <p class="text-lg">No elections found</p>
                                <?php if (!empty($search) || $status_filter !== 'all'): ?>
                                    <p class="text-sm mt-2">Try adjusting your search or filters</p>
                                <?php else: ?>
                                    <p class="text-sm mt-2">Click "Add Election" to create your first election</p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Table Footer -->
        <div class="table-footer">
            <div class="flex items-center space-x-4">
                <span>
                    Showing <span class="font-semibold" id="footer-showing-count"><?= count($elections) ?></span> of <span class="font-semibold"><?= $status_counts['all'] ?></span> elections
                </span>
                <?php if (!empty($search) || $status_filter !== 'all'): ?>
                <span class="text-xs text-gray-400">
                    (filtered)
                </span>
                <?php endif; ?>
            </div>
            <div class="text-xs text-gray-500">
                <i class="far fa-clock mr-1"></i>
                System voting hours: <?= date('g:i A', strtotime($voting_start_time)) ?> - <?= date('g:i A', strtotime($voting_end_time)) ?>
            </div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="toast-container"></div>

<!-- Include the modernized election modal -->
<?php include_once APP_ROOT . '/views/modals/add_election.php'; ?>

<!-- ═══════════════════════════════════════ Position Templates Modal ═══ -->
<div id="position-templates-modal"
     class="fixed inset-0 z-50 overflow-y-auto hidden"
     role="dialog" aria-modal="true" aria-labelledby="pt-modal-title">
    <div class="flex items-start justify-center min-h-screen px-4 pt-10 pb-20">
        <div class="fixed inset-0 bg-gray-600 bg-opacity-60 transition-opacity" onclick="closePositionTemplatesModal()"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl z-10">

            <!-- Header -->
            <div class="flex justify-between items-center px-6 py-5 border-b border-gray-100"
                 style="background:linear-gradient(135deg,#1e3a8a,#1d4ed8);border-radius:1rem 1rem 0 0">
                <div class="flex items-center gap-3">
                    <div style="background:rgba(255,255,255,.15);border-radius:.75rem;padding:.6rem">
                        <i class="fas fa-layer-group text-white text-xl"></i>
                    </div>
                    <div>
                        <h3 id="pt-modal-title" class="text-lg font-bold text-white">Position Templates</h3>
                        <p class="text-blue-200 text-xs mt-0.5">Define positions before creating an election, then apply them in one click</p>
                    </div>
                </div>
                <button onclick="closePositionTemplatesModal()"
                        class="text-blue-200 hover:text-white p-1 rounded-lg transition-colors">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <div class="p-6">

                <!-- Add template form -->
                <div class="bg-blue-50 rounded-xl p-4 mb-5 border border-blue-100">
                    <h4 class="text-sm font-semibold text-blue-900 mb-3"><i class="fas fa-plus-circle mr-1"></i> Add New Template</h4>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <input id="pt-name"     type="text" placeholder="Position name *"
                               class="col-span-1 px-3 py-2 text-sm border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-400 focus:border-transparent">
                        <input id="pt-category" type="text" placeholder="Category (e.g. Prefectorial)"
                               class="col-span-1 px-3 py-2 text-sm border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-400 focus:border-transparent">
                        <input id="pt-desc"     type="text" placeholder="Description (optional)"
                               class="col-span-1 px-3 py-2 text-sm border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-400 focus:border-transparent">
                    </div>
                    <div class="flex justify-end mt-3">
                        <button onclick="addPositionTemplate()"
                                class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-sm font-medium rounded-lg transition-colors flex items-center gap-2">
                            <i class="fas fa-plus"></i> Add Template
                        </button>
                    </div>
                </div>

                <!-- Apply to election bar -->
                <div id="pt-apply-bar" class="hidden bg-green-50 border border-green-200 rounded-xl p-4 mb-4 flex items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-green-800 text-sm">
                        <i class="fas fa-check-circle text-green-600"></i>
                        <span id="pt-selected-count">0</span> position(s) selected
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="pt-election-target"
                                class="px-3 py-1.5 text-sm border border-green-300 rounded-lg focus:ring-2 focus:ring-green-400 bg-white">
                            <option value="">— select election —</option>
                        </select>
                        <button onclick="applyTemplatesToElection()"
                                class="px-4 py-2 bg-green-700 hover:bg-green-800 text-white text-sm font-semibold rounded-lg transition-colors flex items-center gap-2">
                            <i class="fas fa-arrow-right"></i> Apply to Election
                        </button>
                    </div>
                </div>

                <!-- Select all / deselect all -->
                <div class="flex items-center justify-between mb-2 px-1">
                    <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600 hover:text-gray-900">
                        <input type="checkbox" id="pt-select-all" onchange="toggleSelectAll(this.checked)"
                               class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                        <span class="font-medium">Select All</span>
                    </label>
                    <span id="pt-count-label" class="text-xs text-gray-400">0 of 0 selected</span>
                </div>

                <!-- Template list -->
                <div id="pt-list-container" class="space-y-1.5 max-h-72 overflow-y-auto pr-1">
                    <div class="text-center py-8 text-gray-400 text-sm">
                        <i class="fas fa-spinner fa-spin text-2xl mb-2"></i><br>Loading templates…
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// ── Position Templates module ─────────────────────────────────────────
let ptTemplates   = [];
let ptSelectedIds = new Set();
let ptElections   = [];

function openPositionTemplatesModal() {
    document.getElementById('position-templates-modal').classList.remove('hidden');
    loadPositionTemplates();
    loadElectionsForPT();
}

function closePositionTemplatesModal() {
    document.getElementById('position-templates-modal').classList.add('hidden');
    ptSelectedIds.clear();
    updatePTApplyBar();
    const allCb = document.getElementById('pt-select-all');
    if (allCb) { allCb.checked = false; allCb.indeterminate = false; }
    const label = document.getElementById('pt-count-label');
    if (label) label.textContent = '0 of 0 selected';
}

async function loadPositionTemplates() {
    try {
        const res  = await fetch(`${BASE_URL}/api/admin/position-templates.php`, {
            headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || CSRF_TOKEN || '' }
        });
        const data = await res.json();
        ptTemplates = data.templates || [];
        renderPTList();
    } catch (e) {
        document.getElementById('pt-list-container').innerHTML =
            '<p class="text-center text-red-500 text-sm py-4">Failed to load templates</p>';
    }
}

async function loadElectionsForPT() {
    try {
        const res  = await fetch(`${BASE_URL}/api/admin/get-elections.php`, {
            headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || CSRF_TOKEN || '' }
        });
        const data = await res.json();
        ptElections = data.elections || [];
        const sel  = document.getElementById('pt-election-target');
        sel.innerHTML = '<option value="">— select election —</option>';
        ptElections.forEach(e => {
            const opt = document.createElement('option');
            opt.value = e.id;
            opt.textContent = e.title + (e.end_date ? ' (' + e.end_date.substring(0,4) + ')' : '');
            sel.appendChild(opt);
        });
    } catch(e) { /* silent */ }
}

function renderPTList() {
    const container = document.getElementById('pt-list-container');
    if (!ptTemplates.length) {
        container.innerHTML = '<p class="text-center text-gray-400 text-sm py-6">No templates yet. Add one above.</p>';
        return;
    }

    // Group by category
    const groups = {};
    ptTemplates.forEach(t => {
        const cat = t.category || 'General';
        if (!groups[cat]) groups[cat] = [];
        groups[cat].push(t);
    });

    let html = '';
    Object.keys(groups).sort().forEach(cat => {
        html += `<p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mt-3 mb-1 px-1">${cat}</p>`;
        groups[cat].forEach(t => {
            const checked = ptSelectedIds.has(t.id) ? 'checked' : '';
            html += `
            <label class="flex items-center gap-3 px-3 py-2.5 rounded-lg hover:bg-blue-50 cursor-pointer transition-colors border border-transparent hover:border-blue-100">
                <input type="checkbox" ${checked} onchange="togglePTSelection(${t.id}, this.checked)"
                       class="h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-gray-900">${escapeHtml(t.name)}</p>
                    ${t.description ? `<p class="text-xs text-gray-500 truncate">${escapeHtml(t.description)}</p>` : ''}
                </div>
                <button onclick="event.preventDefault();deletePTTemplate(${t.id},'${escapeHtml(t.name).replace(/'/g,"\\'")}',this)"
                        class="text-red-400 hover:text-red-600 p-1 rounded transition-colors flex-shrink-0" title="Delete template">
                    <i class="fas fa-trash-alt text-xs"></i>
                </button>
            </label>`;
        });
    });
    container.innerHTML = html;
    updateSelectAllState();
}

function togglePTSelection(id, checked) {
    if (checked) { ptSelectedIds.add(id); } else { ptSelectedIds.delete(id); }
    updatePTApplyBar();
    updateSelectAllState();
}

function toggleSelectAll(checked) {
    ptTemplates.forEach(t => {
        if (checked) { ptSelectedIds.add(t.id); } else { ptSelectedIds.delete(t.id); }
    });
    updatePTApplyBar();
    renderPTList();          // re-render so checkboxes reflect new state
    updateSelectAllState();
}

function updateSelectAllState() {
    const total = ptTemplates.length;
    const sel   = ptSelectedIds.size;
    const allCb = document.getElementById('pt-select-all');
    const label = document.getElementById('pt-count-label');
    if (allCb) {
        allCb.checked       = total > 0 && sel === total;
        allCb.indeterminate = sel > 0 && sel < total;
    }
    if (label) label.textContent = `${sel} of ${total} selected`;
}

function updatePTApplyBar() {
    const bar = document.getElementById('pt-apply-bar');
    const cnt = document.getElementById('pt-selected-count');
    if (ptSelectedIds.size > 0) {
        bar.classList.remove('hidden');
        cnt.textContent = ptSelectedIds.size;
    } else {
        bar.classList.add('hidden');
    }
}

async function addPositionTemplate() {
    const name     = document.getElementById('pt-name').value.trim();
    const category = document.getElementById('pt-category').value.trim();
    const desc     = document.getElementById('pt-desc').value.trim();
    if (!name) { alert('Position name is required'); return; }

    try {
        const res  = await fetch(`${BASE_URL}/api/admin/position-templates.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || CSRF_TOKEN || ''
            },
            body: JSON.stringify({ action:'create', name, category, description:desc })
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('pt-name').value = '';
            document.getElementById('pt-category').value = '';
            document.getElementById('pt-desc').value = '';
            await loadPositionTemplates();
        } else {
            alert(data.message || 'Failed to add template');
        }
    } catch(e) { alert('Network error'); }
}

async function deletePTTemplate(id, name, btn) {
    if (!confirm(`Delete template "${name}"?\nThis does not affect existing election positions.`)) return;
    try {
        const res  = await fetch(`${BASE_URL}/api/admin/position-templates.php?id=${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || CSRF_TOKEN || '' }
        });
        const data = await res.json();
        if (data.success) {
            ptSelectedIds.delete(id);
            updatePTApplyBar();
            await loadPositionTemplates();
        } else {
            alert(data.message || 'Failed to delete');
        }
    } catch(e) { alert('Network error'); }
}

async function applyTemplatesToElection() {
    const electionId = document.getElementById('pt-election-target').value;
    if (!electionId)         { alert('Please select an election'); return; }
    if (!ptSelectedIds.size) { alert('Please select at least one position template'); return; }

    const election = ptElections.find(e => String(e.id) === String(electionId));
    if (!confirm(`Add ${ptSelectedIds.size} position(s) to "${election?.title || 'the election'}"?`)) return;

    try {
        const res  = await fetch(`${BASE_URL}/api/admin/position-templates.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || CSRF_TOKEN || ''
            },
            body: JSON.stringify({
                action:       'apply',
                election_id:  parseInt(electionId),
                template_ids: Array.from(ptSelectedIds)
            })
        });
        const data = await res.json();
        if (data.success) {
            alert(data.message);
            ptSelectedIds.clear();
            updatePTApplyBar();
            closePositionTemplatesModal();
            if (typeof loadElections === 'function') loadElections();
        } else {
            alert(data.message || 'Failed to apply templates');
        }
    } catch(e) { alert('Network error'); }
}

function escapeHtml(t) {
    const d = document.createElement('div');
    d.textContent = t;
    return d.innerHTML;
}

</script>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>

<script>
// Global variables
const BASE_URL = '<?= BASE_URL ?>';
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
const USER_ID = '<?= $_SESSION['user_id'] ?? '' ?>';
const USER_NAME = '<?= ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '') ?>';
const VOTING_START_TIME = '<?= $voting_start_time ?>';
const VOTING_END_TIME = '<?= $voting_end_time ?>';
const CURRENT_ACADEMIC_YEAR = '<?= $current_academic_year ?>';

// Initialize status counts for real-time updates
const statusCounts = {
    active: <?= $status_counts['active'] ?>,
    upcoming: <?= $status_counts['upcoming'] ?>,
    ended: <?= $status_counts['ended'] ?>,
    all: <?= $status_counts['all'] ?>
};

// Toast notification system
const Toast = {
    container: document.getElementById('toast-container'),
    
    show(message, type = 'success', duration = 5000) {
        const toast = document.createElement('div');
        const colors = {
            success: 'bg-green-100 border-green-500 text-green-700',
            error: 'bg-red-100 border-red-500 text-red-700',
            info: 'bg-blue-100 border-blue-500 text-blue-700',
            warning: 'bg-yellow-100 border-yellow-500 text-yellow-700'
        };
        
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            info: 'fa-info-circle',
            warning: 'fa-exclamation-triangle'
        };
        
        toast.className = `toast mb-2 p-4 rounded-lg border-l-4 shadow-lg ${colors[type]} flex items-center`;
        toast.innerHTML = `
            <i class="fas ${icons[type]} mr-3 text-xl"></i>
            <span class="flex-1">${message}</span>
            <button onclick="this.parentElement.remove()" class="ml-4 hover:opacity-70">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        this.container.appendChild(toast);
        
        setTimeout(() => {
            if (toast.parentElement) {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }
        }, duration);
    },
    
    success(message) { this.show(message, 'success'); },
    error(message) { this.show(message, 'error'); },
    info(message) { this.show(message, 'info'); },
    warning(message) { this.show(message, 'warning'); }
};

// Activity Logger
const ActivityLogger = {
    log(activityType, description, details = {}) {
        if (!USER_ID) {
            console.debug('Skipping activity log - user not identified');
            return;
        }
        
        fetch(BASE_URL + '/api/log-activity.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN
            },
            body: JSON.stringify({
                activity_type: activityType,
                description: description,
                details: details,
                user_id: USER_ID,
                first_name: '<?= $_SESSION['first_name'] ?? '' ?>',
                last_name: '<?= $_SESSION['last_name'] ?? '' ?>'
            }),
            keepalive: true
        }).catch(err => console.debug('Activity log error:', err));
    }
};

// Filter functions
function applyFilters() {
    const search = document.getElementById('search').value;
    const status = document.getElementById('status-filter').value;
    
    const params = new URLSearchParams();
    if (search) params.set('search', search);
    if (status !== 'all') params.set('status', status);
    
    ActivityLogger.log('filters_applied', 'Applied filters', { search, status });
    window.location.href = window.location.pathname + '?' + params.toString();
}

function clearFilters() {
    window.location.href = window.location.pathname;
}

// API Helper
const Api = {
    async request(url, options = {}) {
        try {
            const response = await fetch(url, {
                ...options,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_TOKEN,
                    ...options.headers
                }
            });
            
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return await response.json();
            }
            
            const text = await response.text();
            throw new Error(text.substring(0, 100) || 'Invalid server response');
        } catch (error) {
            console.error('API Error:', error);
            throw error;
        }
    },
    
    async delete(id) {
        return this.request(`${BASE_URL}/api/admin/elections.php?id=${encodeURIComponent(id)}`, {
            method: 'DELETE'
        });
    }
};

// Elections Manager Class for handling real-time updates
class ElectionsManager {
    constructor() {
        this.refreshInterval = 30000; // 30 seconds
        this.countdownInterval = null;
        this.autoRefreshEnabled = true;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        this.baseUrl = BASE_URL;
        
        // Initialize
        if (this.csrfToken) {
            this.init();
        } else {
            console.warn('CSRF token not found');
        }
    }
    
    init() {
        this.startAutoRefresh();
        this.updateCountdown();
        this.attachEventListeners();
        
        // Initial refresh after 2 seconds
        setTimeout(() => {
            this.refreshElections();
        }, 2000);
    }
    
    attachEventListeners() {
        // Manual refresh button
        const refreshBtn = document.querySelector('[onclick="manualRefresh()"]');
        if (refreshBtn) {
            refreshBtn.addEventListener('click', (e) => {
                e.preventDefault();
                this.manualRefresh();
            });
        }
        
        // Enter key in search
        const searchInput = document.getElementById('search');
        if (searchInput) {
            searchInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    applyFilters();
                }
            });
        }
    }
    
    startAutoRefresh() {
        setInterval(() => {
            if (this.autoRefreshEnabled) {
                this.refreshElections();
            }
        }, this.refreshInterval);
    }
    
    updateCountdown() {
        const countdownEl = document.getElementById('active-election-countdown');
        if (!countdownEl) return;
        
        let seconds = this.refreshInterval / 1000;
        
        if (this.countdownInterval) {
            clearInterval(this.countdownInterval);
        }
        
        this.countdownInterval = setInterval(() => {
            seconds--;
            if (seconds <= 0) {
                seconds = this.refreshInterval / 1000;
            }
            countdownEl.textContent = `Refreshing in ${seconds}s`;
        }, 1000);
    }
    
    async manualRefresh() {
        this.showRefreshSpinner(true);
        await this.refreshElections();
        this.updateLastUpdatedTime();
        this.showRefreshSpinner(false);
    }
    
    async refreshElections() {
        const refreshIcon = document.getElementById('refresh-spinner');
        const container = document.getElementById('elections-table-body');
        
        if (refreshIcon) {
            refreshIcon.classList.add('fa-spin');
        }
        
        if (container) {
            container.classList.add('updating');
        }
        
        try {
            // First update election statuses
            await fetch(`${this.baseUrl}/api/admin/update-election-statuses.php`, {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': this.csrfToken,
                    'Content-Type': 'application/json'
                }
            });
            
            // Then fetch updated elections with current filters
            const params = new URLSearchParams(window.location.search);
            const response = await fetch(`${this.baseUrl}/api/admin/get-elections.php?${params.toString()}`, {
                headers: {
                    'X-CSRF-Token': this.csrfToken
                }
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.updateElectionsList(data.elections || []);
                this.updateStatistics(data.stats || {});
            } else {
                console.error('Failed to fetch elections:', data.message);
            }
        } catch (error) {
            console.error('Failed to refresh elections:', error);
        } finally {
            if (refreshIcon) {
                refreshIcon.classList.remove('fa-spin');
            }
            if (container) {
                container.classList.remove('updating');
            }
            this.updateLastUpdatedTime();
        }
    }
    
    updateElectionsList(elections) {
        const tbody = document.getElementById('elections-table-body');
        if (!tbody) return;
        
        if (!elections || elections.length === 0) {
            tbody.innerHTML = `
                <tr id="no-elections-row">
                    <td colspan="6" class="text-center py-12 text-gray-500">
                        <i class="fas fa-calendar-times text-5xl mb-4 text-gray-300"></i>
                        <p class="text-lg">No elections found</p>
                        <p class="text-sm mt-2">Try adjusting your search or filters</p>
                    </td>
                </tr>
            `;
            document.getElementById('showing-count').textContent = '0';
            document.getElementById('footer-showing-count').textContent = '0';
            return;
        }
        
        let html = '';
        elections.forEach(election => {
            const statusClass = election.status === 'active' ? 'bg-green-100 text-green-800' : 
                               (election.status === 'upcoming' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800');
            
            const startDate = new Date(election.start_date);
            const endDate = new Date(election.end_date);
            
            html += `
                <tr class="table-row" data-election-id="${election.id}" data-status="${election.status}">
                    <td class="px-6 py-4">
                        <div class="font-semibold text-gray-900">${this.escapeHtml(election.title)}</div>
                        ${election.description ? `<div class="text-xs text-gray-500 mt-1">${this.escapeHtml(election.description.substring(0, 50))}...</div>` : ''}
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <div class="font-medium text-gray-900">${startDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            <i class="far fa-clock mr-1"></i>
                            <span class="time-display">${startDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm">
                        <div class="font-medium text-gray-900">${endDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</div>
                        <div class="text-xs text-gray-500 mt-1">
                            <i class="far fa-clock mr-1"></i>
                            <span class="time-display">${endDate.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 status-cell">
                        <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full status-badge ${statusClass}">
                            ${election.status.charAt(0).toUpperCase() + election.status.slice(1)}
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                            <i class="fas fa-users mr-1"></i> ${election.candidate_count || 0}
                        </span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex space-x-3">
                            <a href="${this.baseUrl}/index.php?page=admin/candidates&election_id=${election.id}" 
                               class="text-indigo-600 hover:text-indigo-900" title="Manage Candidates">
                                <i class="fas fa-users text-lg"></i>
                            </a>
                            <button onclick='editElectionFromTable(${JSON.stringify(election)})' 
                                    class="text-blue-600 hover:text-blue-900" title="Edit Election">
                                <i class="fas fa-edit text-lg"></i>
                            </button>
                            <button onclick="confirmDeleteFromTable('${election.id}', '${this.escapeHtml(election.title)}')" 
                                    class="text-red-600 hover:text-red-900" title="Delete Election">
                                <i class="fas fa-trash text-lg"></i>
                            </button>
                            ${election.status === 'ended' ? 
                                `<a href="${this.baseUrl}/index.php?page=results&election_id=${election.id}" 
                                    class="text-green-600 hover:text-green-900" title="View Results">
                                    <i class="fas fa-chart-bar text-lg"></i>
                                </a>` : ''}
                        </div>
                    </td>
                </tr>
            `;
        });
        
        tbody.innerHTML = html;
        document.getElementById('showing-count').textContent = elections.length;
        document.getElementById('footer-showing-count').textContent = elections.length;
        
        // Update statistics
        this.updateStatisticsFromTable();
    }
    
    updateStatistics(stats) {
        if (stats.total !== undefined) document.getElementById('total-elections').textContent = stats.total;
        if (stats.active !== undefined) document.getElementById('active-elections').textContent = stats.active;
        if (stats.upcoming !== undefined) document.getElementById('upcoming-elections').textContent = stats.upcoming;
        if (stats.ended !== undefined) document.getElementById('ended-elections').textContent = stats.ended;
    }
    
    updateStatisticsFromTable() {
        const rows = document.querySelectorAll('#elections-table-body tr[data-status]');
        let active = 0, upcoming = 0, ended = 0;
        
        rows.forEach(row => {
            const status = row.dataset.status;
            if (status === 'active') active++;
            else if (status === 'upcoming') upcoming++;
            else if (status === 'ended') ended++;
        });
        
        document.getElementById('active-elections').textContent = active;
        document.getElementById('upcoming-elections').textContent = upcoming;
        document.getElementById('ended-elections').textContent = ended;
        document.getElementById('total-elections').textContent = rows.length;
        
        // Update status counts
        statusCounts.active = active;
        statusCounts.upcoming = upcoming;
        statusCounts.ended = ended;
        statusCounts.all = rows.length;
    }
    
    formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    showRefreshSpinner(show) {
        const spinner = document.getElementById('refresh-spinner');
        if (spinner) {
            if (show) {
                spinner.classList.add('fa-spin');
            } else {
                spinner.classList.remove('fa-spin');
            }
        }
    }
    
    updateLastUpdatedTime() {
        const lastUpdated = document.getElementById('last-updated');
        if (lastUpdated) {
            const now = new Date();
            lastUpdated.textContent = `Last updated: ${now.toLocaleTimeString()}`;
        }
    }
}

// Initialize elections manager
document.addEventListener('DOMContentLoaded', function() {
    try {
        window.electionsManager = new ElectionsManager();
    } catch (error) {
        console.error('Failed to initialize elections manager:', error);
    }
    
    // Log page load
    ActivityLogger.log('elections_page_load', 'Elections management page loaded', {
        url: window.location.href,
        voting_start_time: VOTING_START_TIME,
        voting_end_time: VOTING_END_TIME,
        academic_year: CURRENT_ACADEMIC_YEAR
    });
});

// Global functions
function manualRefresh() {
    if (window.electionsManager) {
        window.electionsManager.manualRefresh();
    }
}

function editElectionFromTable(election) {
    if (typeof populateElectionForm === 'function') {
        populateElectionForm(election);
        showModal('add-election-modal');
    }
}

function confirmDeleteFromTable(id, title) {
    if (confirm(`Are you sure you want to delete "${title}"? This action cannot be undone.`)) {
        deleteElection(id, title);
    }
}

async function deleteElection(id, title) {
    ActivityLogger.log('delete_attempt', 'Attempting to delete election', {
        election_id: id,
        election_title: title
    });
    
    try {
        const data = await Api.delete(id);
        
        if (data.success) {
            ActivityLogger.log('delete_success', 'Election deleted successfully', {
                election_id: id,
                election_title: title
            });
            
            // Immediately remove the row from the table
            const rowToDelete = document.querySelector(`tr[data-election-id="${id}"]`);
            if (rowToDelete) {
                // Add fade-out animation
                rowToDelete.classList.add('row-deleting');
                
                // Remove after animation completes
                setTimeout(() => {
                    rowToDelete.remove();
                    
                    // Update statistics after removal
                    if (window.electionsManager) {
                        window.electionsManager.updateStatisticsFromTable();
                        
                        // Update the showing count
                        const remainingRows = document.querySelectorAll('#elections-table-body tr[data-election-id]').length;
                        document.getElementById('showing-count').textContent = remainingRows;
                        document.getElementById('footer-showing-count').textContent = remainingRows;
                        
                        // Check if no rows left
                        if (remainingRows === 0) {
                            const tbody = document.getElementById('elections-table-body');
                            tbody.innerHTML = `
                                <tr id="no-elections-row">
                                    <td colspan="6" class="text-center py-12 text-gray-500">
                                        <i class="fas fa-calendar-times text-5xl mb-4 text-gray-300"></i>
                                        <p class="text-lg">No elections found</p>
                                        <p class="text-sm mt-2">Click "Add Election" to create one</p>
                                    </td>
                                </tr>
                            `;
                        }
                    }
                    
                    Toast.success('Election deleted successfully');
                }, 300);
            } else {
                Toast.success('Election deleted successfully');
                setTimeout(() => window.electionsManager.refreshElections(), 1000);
            }
        } else {
            throw new Error(data.message || 'Delete failed');
        }
    } catch (error) {
        ActivityLogger.log('delete_error', 'Error deleting election', {
            election_id: id,
            error: error.message
        });
        Toast.error(error.message || 'Failed to delete election');
    }
}

// Override the original delete buttons to use our new function
document.querySelectorAll('.delete-election-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
        e.preventDefault();
        const id = btn.dataset.id;
        const title = btn.dataset.title;
        confirmDeleteFromTable(id, title);
    });
});

// Global functions for modal
window.hideModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
    }
};

window.showModal = function(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
};

// Open modal for adding
document.getElementById('add-election-btn').addEventListener('click', () => {
    if (typeof resetElectionForm === 'function') {
        resetElectionForm();
        showModal('add-election-modal');
    }
});

// Open modal for editing - keep original for compatibility
document.querySelectorAll('.edit-election-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const election = JSON.parse(btn.dataset.election);
        if (typeof populateElectionForm === 'function') {
            populateElectionForm(election);
            showModal('add-election-modal');
        }
    });
});

// Format date for datetime-local
window.formatDateTimeLocal = function(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}`;
};

</script>
