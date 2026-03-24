<?php
// admin/activity_logs.php
declare(strict_types=1);

// Enable error reporting for debugging (disable in production)
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0); // Disable display for production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

require_once __DIR__ . '/../../config/bootstrap.php';
require_once APP_ROOT . '/includes/ActivityLogger.php';
require_once APP_ROOT . '/includes/header.php';

// Start session securely
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Only admin should view this page
if (empty($_SESSION['is_admin'])) {
    $_SESSION['flash_message'] = 'Access denied. Admin privileges required.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL . '/login');
    exit;
}

// Initialize database connection with error handling
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed in activity logs: " . $e->getMessage());
    die('<div class="max-w-7xl mx-auto px-4 py-8"><div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg">System temporarily unavailable. Please try again later.</div></div>');
}

$logger = new ActivityLogger($db);

// ==================== PARAMETER HANDLING ====================
// Pagination parameters
// 'p' = page number; 'page' is consumed by the router for routing
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$limit = isset($_GET['limit']) ? min(100, max(10, (int)$_GET['limit'])) : 10;
$offset = ($page - 1) * $limit;

// Sort parameters with validation
$allowedSortColumns = ['user_name', 'activity_type', 'description', 'ip_address', 'created_at'];
$sortBy = isset($_GET['sort_by']) && in_array($_GET['sort_by'], $allowedSortColumns) 
    ? $_GET['sort_by'] 
    : 'created_at';
$sortOrder = isset($_GET['sort_order']) && strtoupper($_GET['sort_order']) === 'ASC' ? 'ASC' : 'DESC';

// Build filters with sanitization
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

// Validate date formats
if (isset($filters['date_from']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_from'])) {
    unset($filters['date_from']);
}
if (isset($filters['date_to']) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['date_to'])) {
    unset($filters['date_to']);
}

// ==================== FETCH DATA ====================
$activities = [];
$total = 0;
$totalPages = 1;
$error = null;

try {
    $result = $logger->getActivities($filters, $limit, $offset, $sortBy, $sortOrder);
    
    if (is_array($result)) {
        $activities = $result['activities'] ?? [];
        $total = (int)($result['total'] ?? 0);
        $totalPages = (int)($result['total_pages'] ?? ceil($total / $limit));
    } else {
        throw new Exception('Invalid response from ActivityLogger');
    }
} catch (Exception $e) {
    error_log("Error fetching activities: " . $e->getMessage());
    $error = 'Failed to load activity logs. Please try again.';
}

// Get activity types for filter dropdown
$activityTypes = [];
try {
    $activityTypes = $logger->getActivityTypes();
} catch (Exception $e) {
    error_log("Error getting activity types: " . $e->getMessage());
    // Fallback to common types
    $activityTypes = ['login', 'logout', 'vote_cast', 'admin_action'];
}

// ==================== URL BUILDING FUNCTIONS ====================
function buildQueryString(array $params = [], bool $includeCurrent = true): string {
    if (!$includeCurrent) {
        return http_build_query($params);
    }
    // Strip the pagination number param ('p') but preserve all filter params.
    // 'page' is the router key and must be preserved.
    $currentParams = $_GET;
    unset($currentParams['p']);
    $mergedParams = array_merge($currentParams, $params);
    return http_build_query($mergedParams);
}

function buildPaginationUrl(int $page, array $params = []): string {
    // Use the front-controller so Apache's QSA rewrite doesn't corrupt params.
    // 'page' = router key, 'p' = page number (avoids collision).
    $params['p'] = $page;
    return BASE_URL . '/index.php?page=admin/activity_logs&' . buildQueryString($params, false);
}

// ==================== LOG ACTIVITY ====================
try {
    $userId = $_SESSION['user_id'] ?? 'system';
    $userName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    if (empty($userName)) {
        $userName = 'Admin User';
    }
    
    $logger->logActivity(
        $userId, 
        $userName, 
        'admin_view', 
        'Viewed activity logs', 
        json_encode([
            'filters' => $filters,
            'sort' => "$sortBy $sortOrder",
            'page' => $page
        ])
    );
} catch (Exception $e) {
    error_log("Failed to log activity: " . $e->getMessage());
}

// Calculate showing range
$showingFrom = $total > 0 ? $offset + 1 : 0;
$showingTo = min($offset + $limit, $total);
// Use front-controller URL so 'Clear Filters' always works regardless of rewrite
$currentPage = rtrim(BASE_URL, '/') . '/index.php?page=admin/activity_logs';
?>

<!-- Meta tags and CSRF -->
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

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Status badges */
.badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.badge-primary {
    background-color: var(--primary-soft);
    color: var(--primary);
}

.badge-success {
    background-color: #d1fae5;
    color: #065f46;
}

.badge-warning {
    background-color: #fef3c7;
    color: #92400e;
}

.badge-danger {
    background-color: #fee2e2;
    color: #991b1b;
}

.badge-info {
    background-color: #dbeafe;
    color: #1e40af;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="modern-card p-6 mb-8 fade-in">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center space-x-4">
                <div class="gradient-bg rounded-2xl p-4 shadow-lg">
                    <i class="fas fa-history text-3xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Activity <span class="text-pink-900">Logs</span></h1>
                    <p class="text-gray-600 mt-1">Monitor all user activities and system events</p>
                </div>
            </div>
            
            <!-- Export Dropdown -->
            <div class="relative" x-data="{ open: false }">
                <button @click="open = !open" 
                        class="inline-flex items-center px-4 py-2 bg-pink-900 hover:bg-pink-800 text-white rounded-lg text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-pink-500 focus:ring-offset-2">
                    <i class="fas fa-download mr-2"></i>
                    Export
                    <i class="fas fa-chevron-down ml-2 text-xs" :class="{ 'transform rotate-180': open }"></i>
                </button>
                <div x-show="open" @click.away="open = false" 
                     class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 border border-gray-200"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="transform opacity-0 scale-95"
                     x-transition:enter-end="transform opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="transform opacity-100 scale-100"
                     x-transition:leave-end="transform opacity-0 scale-95">
                    <a href="#" onclick="exportLogs('csv')" 
                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        <i class="fas fa-file-csv mr-2 text-green-600"></i>
                        Export as CSV
                    </a>
                    <a href="#" onclick="exportLogs('excel')" 
                       class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                        <i class="fas fa-file-excel mr-2 text-green-600"></i>
                        Export as Excel
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Error Message -->
    <?php if ($error): ?>
        <div class="mb-6 bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg" role="alert">
            <div class="flex items-center">
                <i class="fas fa-exclamation-circle mr-3"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Flash Messages -->
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'bg-red-100 text-red-800 border-l-4 border-red-500' : 'bg-green-100 text-green-800 border-l-4 border-green-500' ?> rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($_SESSION['flash_message']) ?></span>
                    <button type="button" onclick="this.closest('[role=\"alert\"]').remove()" class="ml-4" aria-label="Close">
                        <i class="fas fa-times text-gray-400 hover:text-gray-600"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
    <?php endif; ?>

    <!-- Filters Card -->
    <div class="modern-card p-6 mb-8">
        <h2 class="text-lg font-semibold text-gray-900 flex items-center mb-4">
            <i class="fas fa-filter mr-2 text-pink-900"></i>
            Filter Activities
        </h2>
        
        <form method="GET" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php') ?>" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
            <!-- Hidden fields: 'page' routes to this view; others preserve state -->
            <input type="hidden" name="page" value="admin/activity_logs">
            <input type="hidden" name="limit" value="<?= htmlspecialchars((string)$limit) ?>">
            <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sortBy) ?>">
            <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sortOrder) ?>">
            
            <div>
                <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input type="text" 
                           id="search"
                           name="search" 
                           value="<?= htmlspecialchars($filters['search'] ?? '') ?>" 
                           placeholder="Search descriptions..." 
                           class="w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent">
                </div>
            </div>
            
            <div>
                <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">User ID</label>
                <input type="text" 
                       id="user_id"
                       name="user_id" 
                       value="<?= htmlspecialchars($filters['user_id'] ?? '') ?>" 
                       placeholder="e.g., STU001" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent">
            </div>
            
            <div>
                <label for="type" class="block text-sm font-medium text-gray-700 mb-1">Activity Type</label>
                <select id="type"
                        name="type" 
                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent">
                    <option value="">All Types</option>
                    <?php foreach ($activityTypes as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= ($filters['activity_type'] ?? '') === $type ? 'selected' : '' ?>>
                            <?= htmlspecialchars($type) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label for="date_from" class="block text-sm font-medium text-gray-700 mb-1">Date From</label>
                <input type="date" 
                       id="date_from"
                       name="date_from" 
                       value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent">
            </div>
            
            <div>
                <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Date To</label>
                <input type="date" 
                       id="date_to"
                       name="date_to" 
                       value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" 
                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pink-500 focus:border-transparent">
            </div>
            
            <div class="lg:col-span-5 flex justify-end space-x-3 mt-2">
                <button type="submit" 
                        class="px-6 py-2 bg-pink-900 hover:bg-pink-800 text-white rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-pink-500 focus:ring-offset-2">
                    <i class="fas fa-filter mr-2"></i>
                    Apply Filters
                </button>
                <a href="<?= $currentPage ?>" 
                   class="px-6 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg transition-colors focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    <i class="fas fa-times mr-2"></i>
                    Clear Filters
                </a>
            </div>
        </form>
        
        <!-- Active filters display -->
        <?php if (!empty($filters)): ?>
        <div class="flex flex-wrap gap-2 mt-4 pt-4 border-t border-gray-200">
            <span class="text-sm text-gray-500 mr-2">Active filters:</span>
            <?php foreach ($filters as $key => $value): ?>
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm bg-pink-100 text-pink-800">
                    <i class="fas fa-tag mr-1"></i>
                    <?= htmlspecialchars($key) ?>: <?= htmlspecialchars($value) ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Results Info and Controls -->
    <div class="flex flex-col sm:flex-row justify-between items-center mb-4 gap-4">
        <div class="text-sm text-gray-600">
            <?php if ($total > 0): ?>
                Showing <span class="font-medium"><?= number_format($showingFrom) ?></span> 
                to <span class="font-medium"><?= number_format($showingTo) ?></span> 
                of <span class="font-medium"><?= number_format($total) ?></span> activities
            <?php else: ?>
                <span class="font-medium">No activities found</span>
            <?php endif; ?>
        </div>
        
        <div class="flex items-center space-x-2">
            <span class="text-sm text-gray-600">Show:</span>
            <select id="limitSelector" 
                    class="px-3 py-1 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-transparent">
                <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
            </select>
            <span class="text-sm text-gray-600">per page</span>
        </div>
    </div>

    <!-- Activities Table Card -->
    <div class="modern-card overflow-hidden">
        <div class="overflow-x-auto custom-scroll">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th scope="col" 
                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-pink-900 transition-colors sort-header"
                            data-sort="user_name">
                            <div class="flex items-center space-x-1">
                                <span>User</span>
                                <?php if ($sortBy === 'user_name'): ?>
                                    <i class="fas fa-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </th>
                        <th scope="col" 
                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-pink-900 transition-colors sort-header"
                            data-sort="activity_type">
                            <div class="flex items-center space-x-1">
                                <span>Activity</span>
                                <?php if ($sortBy === 'activity_type'): ?>
                                    <i class="fas fa-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </th>
                        <th scope="col" 
                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-pink-900 transition-colors sort-header"
                            data-sort="description">
                            <div class="flex items-center space-x-1">
                                <span>Description</span>
                                <?php if ($sortBy === 'description'): ?>
                                    <i class="fas fa-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </th>
                        <th scope="col" 
                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-pink-900 transition-colors sort-header"
                            data-sort="ip_address">
                            <div class="flex items-center space-x-1">
                                <span>IP Address</span>
                                <?php if ($sortBy === 'ip_address'): ?>
                                    <i class="fas fa-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </th>
                        <th scope="col" 
                            class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:text-pink-900 transition-colors sort-header"
                            data-sort="created_at">
                            <div class="flex items-center space-x-1">
                                <span>Date & Time</span>
                                <?php if ($sortBy === 'created_at'): ?>
                                    <i class="fas fa-arrow-<?= $sortOrder === 'ASC' ? 'up' : 'down' ?> text-xs"></i>
                                <?php endif; ?>
                            </div>
                        </th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (empty($activities)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-history text-5xl mb-4 text-gray-300"></i>
                                <p class="text-lg">No activity logs found</p>
                                <p class="text-sm text-gray-400 mt-1">Try adjusting your filters or check back later</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activities as $activity): ?>
                            <tr class="table-row">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8 bg-pink-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-pink-600 text-sm"></i>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($activity['user_name'] ?? 'System') ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                ID: <?= htmlspecialchars($activity['user_id'] ?? 'N/A') ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="badge badge-<?= getActivityTypeClass($activity['activity_type'] ?? '') ?>">
                                        <?= htmlspecialchars($activity['activity_type'] ?? 'unknown') ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900 max-w-md">
                                        <?= htmlspecialchars($activity['description'] ?? '') ?>
                                    </div>
                                    <?php if (!empty($activity['details'])): ?>
                                        <?php if (is_array($activity['details']) || is_object($activity['details'])): ?>
                                            <button onclick='showDetails(<?= htmlspecialchars(json_encode($activity['details']), ENT_QUOTES, 'UTF-8') ?>)' 
                                                    class="text-xs text-pink-600 hover:text-pink-800 mt-1 focus:outline-none">
                                                <i class="fas fa-info-circle mr-1"></i> View Details
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 font-mono">
                                    <?= htmlspecialchars($activity['ip_address'] ?? '—') ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <div class="flex flex-col">
                                        <span><?= date('M j, Y', strtotime($activity['created_at'])) ?></span>
                                        <span class="text-xs text-gray-400"><?= date('g:i A', strtotime($activity['created_at'])) ?></span>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                <div class="flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div class="text-sm text-gray-600">
                        Page <span class="font-medium"><?= $page ?></span> of <span class="font-medium"><?= $totalPages ?></span>
                    </div>
                    
                    <nav class="flex items-center space-x-2" aria-label="Pagination">
                        <!-- First page -->
                        <a href="<?= buildPaginationUrl(1) ?>" 
                           class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>"
                           aria-label="First page">
                            <i class="fas fa-angle-double-left"></i>
                        </a>

                        <!-- Previous page -->
                        <a href="<?= buildPaginationUrl($page - 1) ?>" 
                           class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>"
                           aria-label="Previous page">
                            <i class="fas fa-chevron-left"></i>
                        </a>

                        <!-- Page numbers -->
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        if ($startPage > 1): ?>
                            <a href="<?= buildPaginationUrl(1) ?>" 
                               class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors">1</a>
                            <?php if ($startPage > 2): ?>
                                <span class="px-3 py-2 text-gray-500">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php if ($i == $page): ?>
                                <span class="px-3 py-2 rounded-lg bg-pink-900 text-white border border-pink-900 font-medium"><?= $i ?></span>
                            <?php else: ?>
                                <a href="<?= buildPaginationUrl($i) ?>" 
                                   class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors"><?= $i ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <span class="px-3 py-2 text-gray-500">...</span>
                            <?php endif; ?>
                            <a href="<?= buildPaginationUrl($totalPages) ?>" 
                               class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors"><?= $totalPages ?></a>
                        <?php endif; ?>

                        <!-- Next page -->
                        <a href="<?= buildPaginationUrl($page + 1) ?>" 
                           class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors <?= $page >= $totalPages ? 'opacity-50 pointer-events-none' : '' ?>"
                           aria-label="Next page">
                            <i class="fas fa-chevron-right"></i>
                        </a>

                        <!-- Last page -->
                        <a href="<?= buildPaginationUrl($totalPages) ?>" 
                           class="px-3 py-2 rounded-lg bg-white text-gray-700 hover:bg-gray-100 border border-gray-300 transition-colors <?= $page >= $totalPages ? 'opacity-50 pointer-events-none' : '' ?>"
                           aria-label="Last page">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </nav>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Details Modal -->
<div id="details-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-hidden">
        <div class="gradient-bg px-6 py-4 flex justify-between items-center">
            <h3 class="text-lg font-bold text-white flex items-center">
                <i class="fas fa-info-circle mr-2"></i>
                Activity Details
            </h3>
            <button type="button" onclick="hideDetailsModal()" class="text-white hover:text-pink-200 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto custom-scroll" id="details-content">
            <!-- Content will be inserted here -->
        </div>
        <div class="px-6 py-4 border-t border-gray-200 flex justify-end">
            <button type="button" onclick="hideDetailsModal()" 
                    class="px-4 py-2 bg-pink-900 hover:bg-pink-800 text-white rounded-lg transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="toast-container"></div>

<!-- Loading Spinner -->
<div id="loading-spinner" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white p-6 rounded-lg shadow-lg flex items-center">
        <div class="spinner mr-3"></div>
        <span>Loading...</span>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>

<script>
// Configuration
const CONFIG = {
    BASE_URL: '<?= BASE_URL ?>',
    CSRF_TOKEN: '<?= $_SESSION['csrf_token'] ?? '' ?>',
    CURRENT_PAGE: '<?= $currentPage ?>'
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

// Auto-hide flash messages
document.querySelectorAll('[role="alert"]').forEach(alert => {
    setTimeout(() => {
        alert.style.transition = 'opacity 0.3s ease';
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
});

// Limit selector
const limitSelector = document.getElementById('limitSelector');
if (limitSelector) {
    limitSelector.addEventListener('change', function() {
        const url = new URL(window.location.href);
        url.searchParams.set('limit', this.value);
        url.searchParams.delete('p'); // reset to first page; preserve 'page' (router key)
        window.location.href = url.toString();
    });
}

// Sort functionality
document.querySelectorAll('.sort-header').forEach(header => {
    header.addEventListener('click', function() {
        const column = this.dataset.sort;
        const url = new URL(window.location.href);
        const currentSort = url.searchParams.get('sort_by');
        const currentOrder = url.searchParams.get('sort_order');
        
        if (currentSort === column) {
            url.searchParams.set('sort_order', currentOrder === 'ASC' ? 'DESC' : 'ASC');
        } else {
            url.searchParams.set('sort_by', column);
            url.searchParams.set('sort_order', 'DESC');
        }
        
        url.searchParams.delete('p'); // reset to first page; preserve 'page' (router key)
        window.location.href = url.toString();
    });
});

// Export functionality
window.exportLogs = function(format) {
    // Build export URL preserving all active filters from the current page.
    // Points to views/admin/export_activity.php which is a real file (served
    // directly by Apache, not routed through index.php).
    const current = new URL(window.location.href);
    const params = new URLSearchParams();

    // Copy filter params from current URL
    for (const key of ['search', 'user_id', 'type', 'date_from', 'date_to', 'sort_by', 'sort_order', 'limit']) {
        if (current.searchParams.has(key)) {
            params.set(key, current.searchParams.get(key));
        }
    }
    params.set('format', format);
    params.set('csrf_token', CONFIG.CSRF_TOKEN);

    window.location.href = CONFIG.BASE_URL + '/views/admin/export_activity.php?' + params.toString();
    Toast.success(`Preparing ${format.toUpperCase()} export...`);
};

// Details modal
window.showDetails = function(details) {
    const modal = document.getElementById('details-modal');
    const content = document.getElementById('details-content');
    
    if (!modal || !content) return;
    
    let html = '<div class="space-y-3">';
    
    if (typeof details === 'object' && details !== null) {
        Object.entries(details).forEach(([key, value]) => {
            let displayValue = value;
            if (typeof value === 'object') {
                displayValue = JSON.stringify(value, null, 2);
            }
            html += `
                <div class="border-b border-gray-100 pb-2">
                    <span class="text-sm font-medium text-gray-600">${escapeHtml(key)}:</span>
                    <pre class="text-sm text-gray-900 mt-1 bg-gray-50 p-2 rounded overflow-x-auto">${escapeHtml(displayValue)}</pre>
                </div>
            `;
        });
    } else {
        html += `<pre class="text-sm text-gray-900 bg-gray-50 p-3 rounded">${escapeHtml(details)}</pre>`;
    }
    
    html += '</div>';
    content.innerHTML = html;
    
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    document.body.style.overflow = 'hidden';
};

window.hideDetailsModal = function() {
    const modal = document.getElementById('details-modal');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
    }
};

// Escape HTML helper
function escapeHtml(unsafe) {
    if (unsafe === null || unsafe === undefined) return '';
    return String(unsafe)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        hideDetailsModal();
    }
});

// Close modal when clicking outside
const detailsModal = document.getElementById('details-modal');
if (detailsModal) {
    detailsModal.addEventListener('click', function(e) {
        if (e.target === detailsModal) {
            hideDetailsModal();
        }
    });
}

// Loading spinner
window.showLoading = function(show) {
    const spinner = document.getElementById('loading-spinner');
    if (spinner) {
        if (show) {
            spinner.classList.remove('hidden');
        } else {
            spinner.classList.add('hidden');
        }
    }
};
</script>

<?php
// Helper function to get activity type class
function getActivityTypeClass($type) {
    $type = strtolower($type);
    if (strpos($type, 'login') !== false) return 'success';
    if (strpos($type, 'logout') !== false) return 'info';
    if (strpos($type, 'error') !== false) return 'danger';
    if (strpos($type, 'warning') !== false) return 'warning';
    if (strpos($type, 'create') !== false) return 'success';
    if (strpos($type, 'update') !== false) return 'info';
    if (strpos($type, 'delete') !== false) return 'danger';
    return 'primary';
}

require_once APP_ROOT . '/includes/footer.php';
?>