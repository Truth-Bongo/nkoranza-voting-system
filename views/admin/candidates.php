<?php
// admin/candidates.php
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

// ----------------------
// Initialize Database and Logger
// ----------------------
$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);

// Get user information for logging
$userId = $_SESSION['user_id'] ?? 'unknown';
$userName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
if (empty(trim($userName))) $userName = $userId;

// Log page access
$activityLogger->logActivity(
    $userId,
    $userName,
    'candidates_page_view',
    'Accessed candidates management page',
    json_encode(['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'])
);

// ----------------------
// Handle search, filter and pagination
// ----------------------
$search = $_GET['search'] ?? '';
$election_filter = $_GET['election'] ?? '';
$position_filter = $_GET['position'] ?? '';

// Log search/filter activities
if (!empty($search)) {
    $activityLogger->logActivity(
        $userId,
        $userName,
        'candidates_search',
        'Searched candidates',
        json_encode(['search_term' => $search])
    );
}

if (!empty($election_filter)) {
    $activityLogger->logActivity(
        $userId,
        $userName,
        'candidates_filter_election',
        'Filtered candidates by election',
        json_encode(['election_id' => $election_filter])
    );
}

if (!empty($position_filter)) {
    $activityLogger->logActivity(
        $userId,
        $userName,
        'candidates_filter_position',
        'Filtered candidates by position',
        json_encode(['position_id' => $position_filter])
    );
}

// Pagination parameters
// 'p' holds the page number; 'page' is consumed by the router for routing
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$records_per_page = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$records_per_page = in_array($records_per_page, [10, 25, 50, 100]) ? $records_per_page : 10;
$offset = ($page - 1) * $records_per_page;

// Build the base query for counting total records
$count_query = "
    SELECT COUNT(*) as total
    FROM candidates c
    LEFT JOIN elections e ON c.election_id = e.id
    LEFT JOIN positions p ON c.position_id = p.id
    LEFT JOIN users u ON c.user_id = u.id
    WHERE 1=1
";

$count_params = [];

if (!empty($search)) {
    $count_query .= " AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR p.name LIKE :s4 OR e.title LIKE :s5)";
    $searchVal = "%$search%";
    $count_params[':s1'] = $searchVal;
    $count_params[':s2'] = $searchVal;
    $count_params[':s3'] = $searchVal;
    $count_params[':s4'] = $searchVal;
    $count_params[':s5'] = $searchVal;
}

if (!empty($election_filter)) {
    $count_query .= " AND c.election_id = :election_id";
    $count_params[':election_id'] = $election_filter;
}

if (!empty($position_filter)) {
    $count_query .= " AND c.position_id = :position_id";
    $count_params[':position_id'] = $position_filter;
}

// Get total records for pagination
$count_stmt = $db->prepare($count_query);
foreach ($count_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Build main query with pagination
$query = "
    SELECT 
        c.id,
        c.election_id,
        c.position_id,
        c.user_id,
        c.manifesto,
        c.photo_path,
        c.created_at,
        e.title AS election_title,
        e.status AS election_status,
        p.name AS position_name,
        p.category AS position_category,
        u.first_name,
        u.last_name,
        u.department,
        u.level,
        u.email
    FROM candidates c
    LEFT JOIN elections e ON c.election_id = e.id
    LEFT JOIN positions p ON c.position_id = p.id
    LEFT JOIN users u ON c.user_id = u.id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $query .= " AND (u.first_name LIKE :s1 OR u.last_name LIKE :s2 OR u.email LIKE :s3 OR p.name LIKE :s4 OR e.title LIKE :s5)";
    $searchVal = "%$search%";
    $params[':s1'] = $searchVal;
    $params[':s2'] = $searchVal;
    $params[':s3'] = $searchVal;
    $params[':s4'] = $searchVal;
    $params[':s5'] = $searchVal;
}

if (!empty($election_filter)) {
    $query .= " AND c.election_id = :election_id";
    $params[':election_id'] = $election_filter;
}

if (!empty($position_filter)) {
    $query .= " AND c.position_id = :position_id";
    $params[':position_id'] = $position_filter;
}

$query .= " ORDER BY e.start_date DESC, p.name ASC, u.first_name ASC";
$query .= " LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);

// Bind parameters
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Log the number of candidates retrieved
$activityLogger->logActivity(
    $userId,
    $userName,
    'candidates_retrieved',
    'Retrieved candidates list',
    json_encode([
        'count' => count($candidates),
        'total' => $total_records,
        'filters' => [
            'search' => $search ?: null,
            'election' => $election_filter ?: null,
            'position' => $position_filter ?: null
        ]
    ])
);

// Load elections for filter dropdown
$elections = $db->query("SELECT id, title, status FROM elections ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

// Load positions for filter dropdown
$positions = $db->query("SELECT id, name, category FROM positions ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_stmt = $db->query("
    SELECT 
        COUNT(*) as total_candidates,
        COUNT(DISTINCT election_id) as total_elections,
        COUNT(DISTINCT position_id) as total_positions
    FROM candidates
");
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Log statistics retrieval
$activityLogger->logActivity(
    $userId,
    $userName,
    'candidates_stats_viewed',
    'Viewed candidate statistics',
    json_encode($stats)
);

// Helper function to build pagination URL with existing filters
function buildPaginationUrl($page, $search, $election_filter, $position_filter, $limit = 10) {
    // Use the front-controller URL so that .htaccess rewriting never mangles
    // the query-string params (the old /admin/candidates.php URL caused Apache
    // to rewrite page=admin/candidates.php&page=2 — the second page param was ignored).
    $base_url = rtrim(BASE_URL, '/');
    $current_url = $base_url . '/index.php';

    $params = ['page' => 'admin/candidates'];
    if (!empty($search)) $params['search'] = trim($search);
    if (!empty($election_filter)) $params['election'] = $election_filter;
    if (!empty($position_filter)) $params['position'] = $position_filter;
    $params['p'] = $page;       // named 'p' to avoid collision with the route 'page' param
    $params['limit'] = $limit;

    return $current_url . '?' . http_build_query($params);
}

// Get current URL for clear filters
$base_url_clean = rtrim(BASE_URL, '/');
$base_url_clean = preg_replace('#([^:])//+#', '$1/', $base_url_clean);
// Use front-controller URL — see buildPaginationUrl() comment above
$current_page = rtrim(BASE_URL, '/') . '/index.php?page=admin/candidates';
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

.table-row {
    transition: all 0.2s ease;
}

.table-row:hover {
    background-color: var(--primary-bg);
}

.gradient-bg {
    background: linear-gradient(135deg, var(--primary) 0%, #b91c5c 100%);
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
.status-badge {
    display: inline-block;
    padding: 0.25rem 0.75rem;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-active {
    background-color: #d1fae5;
    color: #065f46;
}

.status-upcoming {
    background-color: #fef3c7;
    color: #92400e;
}

.status-ended {
    background-color: #fee2e2;
    color: #991b1b;
}

/* Filter chips */
.filter-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.75rem;
    background-color: #f3f4f6;
    border-radius: 9999px;
    font-size: 0.875rem;
    color: #374151;
    transition: all 0.2s ease;
}

.filter-chip:hover {
    background-color: #e5e7eb;
}

.filter-chip .remove-filter {
    margin-left: 0.5rem;
    color: #9ca3af;
    cursor: pointer;
}

.filter-chip .remove-filter:hover {
    color: #ef4444;
}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="modern-card p-6 mb-8 fade-in">
        <div class="flex flex-col md:flex-row justify-between items-center">
            <div class="flex items-center space-x-4">
                <div class="gradient-bg rounded-2xl p-4 shadow-lg">
                    <i class="fas fa-users text-3xl text-white"></i>
                </div>
                <div>
                    <h1 class="text-3xl font-bold text-gray-800">Candidates <span class="text-pink-900">Management</span></h1>
                    <p class="text-gray-600 mt-1">Add and manage all election candidates</p>
                </div>
            </div>
            <button onclick="showModal('add-candidate-modal')" class="mt-4 md:mt-0 action-btn flex items-center">
                <i class="fas fa-plus mr-2"></i> Add Candidate
            </button>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Total Candidates</p>
                    <p class="text-3xl font-bold text-gray-800"><?= number_format($stats['total_candidates']) ?></p>
                </div>
                <div class="bg-blue-100 p-3 rounded-lg">
                    <i class="fas fa-users text-blue-600 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Elections</p>
                    <p class="text-3xl font-bold text-gray-800"><?= number_format($stats['total_elections']) ?></p>
                </div>
                <div class="bg-green-100 p-3 rounded-lg">
                    <i class="fas fa-vote-yea text-green-600 text-xl"></i>
                </div>
            </div>
        </div>
        <div class="stat-card">
            <div class="flex justify-between items-start">
                <div>
                    <p class="text-gray-500 text-sm font-medium mb-1">Positions</p>
                    <p class="text-3xl font-bold text-gray-800"><?= number_format($stats['total_positions']) ?></p>
                </div>
                <div class="bg-purple-100 p-3 rounded-lg">
                    <i class="fas fa-briefcase text-purple-600 text-xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and Filter Section -->
    <div class="modern-card p-6 mb-8">
        <form id="filter-form" method="GET" action="<?= htmlspecialchars(rtrim(BASE_URL, '/') . '/index.php') ?>" class="space-y-4">
            <!-- [FIX] Router key must be a hidden field, not in the action URL.
                 Browsers discard the action's query string on GET form submit. -->
            <input type="hidden" name="page" value="admin/candidates">
            <div class="flex flex-col md:flex-row gap-4">
                <div class="flex-1">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" 
                               name="search" 
                               id="search-input"
                               value="<?= htmlspecialchars($search) ?>" 
                               placeholder="Search candidates by name, email, position, or election..." 
                               class="w-full pl-12 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all">
                    </div>
                </div>
                <div class="w-full md:w-48">
                    <select name="election" 
                            id="election-filter" 
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all bg-white">
                        <option value="">All Elections</option>
                        <?php foreach ($elections as $election): ?>
                            <option value="<?= $election['id'] ?>" <?= $election_filter == $election['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($election['title']) ?>
                                (<?= ucfirst($election['status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="w-full md:w-48">
                    <select name="position" 
                            id="position-filter" 
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all bg-white">
                        <option value="">All Positions</option>
                        <?php foreach ($positions as $position): ?>
                            <option value="<?= $position['id'] ?>" <?= $position_filter == $position['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($position['name']) ?>
                                <?= !empty($position['category']) ? '(' . htmlspecialchars($position['category']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex space-x-2">
                    <button type="submit" 
                            id="apply-filters" 
                            class="px-6 py-3 bg-pink-900 hover:bg-pink-800 text-white rounded-xl transition-all transform hover:scale-105 flex items-center">
                        <i class="fas fa-filter mr-2"></i> Apply
                    </button>
                    <?php if (!empty($search) || !empty($election_filter) || !empty($position_filter)): ?>
                        <a href="<?= htmlspecialchars($current_page) ?>" 
                           class="px-6 py-3 bg-gray-500 hover:bg-gray-600 text-white rounded-xl transition-all transform hover:scale-105 flex items-center">
                            <i class="fas fa-times mr-2"></i> Clear
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
        
        <!-- Active filters display -->
        <?php if (!empty($search) || !empty($election_filter) || !empty($position_filter)): ?>
            <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-gray-100">
                <span class="text-sm font-medium text-gray-700 mr-2">Active Filters:</span>
                
                <?php if (!empty($search)): ?>
                    <div class="filter-chip">
                        <i class="fas fa-search text-gray-500 mr-1"></i>
                        "<?= htmlspecialchars($search) ?>"
                        <a href="<?= buildPaginationUrl(1, '', $election_filter, $position_filter, $records_per_page) ?>" 
                           class="remove-filter" 
                           title="Remove search filter">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($election_filter)): 
                    $filtered_election = array_filter($elections, fn($e) => $e['id'] == $election_filter);
                    $election_title = !empty($filtered_election) ? reset($filtered_election)['title'] : 'Unknown Election';
                ?>
                    <div class="filter-chip">
                        <i class="fas fa-calendar-alt text-gray-500 mr-1"></i>
                        <?= htmlspecialchars($election_title) ?>
                        <a href="<?= buildPaginationUrl(1, $search, '', $position_filter, $records_per_page) ?>" 
                           class="remove-filter" 
                           title="Remove election filter">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($position_filter)): 
                    $filtered_position = array_filter($positions, fn($p) => $p['id'] == $position_filter);
                    $position_name = !empty($filtered_position) ? reset($filtered_position)['name'] : 'Unknown Position';
                ?>
                    <div class="filter-chip">
                        <i class="fas fa-briefcase text-gray-500 mr-1"></i>
                        <?= htmlspecialchars($position_name) ?>
                        <a href="<?= buildPaginationUrl(1, $search, $election_filter, '', $records_per_page) ?>" 
                           class="remove-filter" 
                           title="Remove position filter">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Results Summary -->
    <div class="flex flex-col sm:flex-row justify-between items-center mb-4 gap-2">
        <div class="text-sm text-gray-600">
            Showing <span class="font-medium"><?= $total_records > 0 ? $offset + 1 : 0 ?></span> 
            to <span class="font-medium"><?= min($offset + $records_per_page, $total_records) ?></span> 
            of <span class="font-medium"><?= number_format($total_records) ?></span> candidates
            <?php if (!empty($search) || !empty($election_filter) || !empty($position_filter)): ?>
                <span class="text-gray-500">(filtered)</span>
            <?php endif; ?>
        </div>
        
        <!-- Records per page selector -->
        <div class="flex items-center space-x-2">
            <label for="per-page" class="text-sm text-gray-600">Show:</label>
            <select id="per-page" 
                    onchange="changePerPage(this.value)" 
                    class="text-sm border border-gray-300 rounded-md px-2 py-1 focus:outline-none focus:ring-2 focus:ring-pink-500">
                <option value="10" <?= $records_per_page == 10 ? 'selected' : '' ?>>10</option>
                <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
            </select>
        </div>
    </div>

    <!-- Candidates Table -->
    <div class="modern-card overflow-hidden">
        <div class="overflow-x-auto custom-scroll">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-4 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Photo</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Candidate</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Position</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Election</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Department</th>
                        <th class="px-6 py-4 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Level</th>
                        <th class="px-6 py-4 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php if (!empty($candidates)): ?>
                        <?php foreach ($candidates as $candidate): ?>
                            <tr class="table-row" data-candidate-id="<?= $candidate['id'] ?>">
                                <!-- Candidate Photo -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <?php 
                                        $photo = !empty($candidate['photo_path']) 
                                                ? BASE_URL . "/" . htmlspecialchars($candidate['photo_path']) 
                                                : BASE_URL . "/assets/img/default-avatar.png";
                                    ?>
                                    <img src="<?= $photo ?>"
                                         alt="Candidate Photo"
                                         class="w-12 h-12 rounded-full object-cover border-2 border-gray-200 mx-auto hover:border-pink-500 transition-colors">
                                </td>

                                <!-- Candidate Name -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="font-medium text-gray-900">
                                        <?= htmlspecialchars(($candidate['first_name'] ?? '') . ' ' . ($candidate['last_name'] ?? '')) ?>
                                    </div>
                                    <?php if (!empty($candidate['email'])): ?>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($candidate['email']) ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($candidate['manifesto'])): ?>
                                        <div class="text-xs text-gray-400 mt-1 max-w-xs truncate" title="<?= htmlspecialchars($candidate['manifesto']) ?>">
                                            <i class="fas fa-quote-left mr-1"></i> <?= htmlspecialchars(substr($candidate['manifesto'], 0, 50)) ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <!-- Position -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($candidate['position_name'] ?? '—') ?>
                                    </div>
                                    <?php if (!empty($candidate['position_category'])): ?>
                                        <div class="text-xs text-gray-500"><?= htmlspecialchars($candidate['position_category']) ?></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Election -->
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?= htmlspecialchars($candidate['election_title'] ?? '—') ?>
                                    </div>
                                    <?php if (!empty($candidate['election_status'])): ?>
                                        <span class="status-badge status-<?= $candidate['election_status'] ?>">
                                            <?= ucfirst($candidate['election_status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <!-- Department -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= htmlspecialchars($candidate['department'] ?? '—') ?>
                                </td>

                                <!-- Level -->
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?= htmlspecialchars($candidate['level'] ?? '—') ?>
                                </td>

                                <!-- Actions -->
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex justify-center space-x-3">
                                        <button onclick='viewCandidate(<?= json_encode($candidate, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                class="text-green-600 hover:text-green-800 transition-colors" 
                                                title="View Details"
                                                data-candidate-id="<?= $candidate['id'] ?>"
                                                data-candidate-name="<?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>">
                                            <i class="fas fa-eye text-lg"></i>
                                        </button>
                                        <button onclick='editCandidate(<?= json_encode($candidate, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                                class="text-blue-600 hover:text-blue-800 transition-colors" 
                                                title="Edit Candidate"
                                                data-candidate-id="<?= $candidate['id'] ?>"
                                                data-candidate-name="<?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>">
                                            <i class="fas fa-edit text-lg"></i>
                                        </button>
                                        <button onclick="confirmDeleteCandidate(<?= (int)$candidate['id'] ?>, '<?= htmlspecialchars(addslashes($candidate['first_name'] . ' ' . $candidate['last_name'])) ?>')"
                                                class="text-red-600 hover:text-red-800 transition-colors" 
                                                title="Delete Candidate"
                                                data-candidate-id="<?= $candidate['id'] ?>"
                                                data-candidate-name="<?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name']) ?>">
                                            <i class="fas fa-trash text-lg"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-gray-500">
                                <i class="fas fa-users text-5xl mb-4 text-gray-300"></i>
                                <p class="text-lg">No candidates found</p>
                                <?php if (!empty($search) || !empty($election_filter) || !empty($position_filter)): ?>
                                    <p class="text-sm mt-2 text-gray-400">Try adjusting your search or filters</p>
                                    <a href="<?= htmlspecialchars($current_page) ?>" class="mt-4 inline-flex items-center text-pink-600 hover:text-pink-800">
                                        <i class="fas fa-times mr-2"></i> Clear all filters
                                    </a>
                                <?php else: ?>
                                    <p class="text-sm mt-2 text-gray-400">Get started by adding your first candidate</p>
                                    <button onclick="showModal('add-candidate-modal')" class="mt-4 action-btn inline-flex items-center">
                                        <i class="fas fa-plus mr-2"></i> Add Candidate
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200">
                <nav class="flex items-center justify-between" aria-label="Pagination">
                    <div class="hidden sm:block">
                        <p class="text-sm text-gray-700">
                            Page <span class="font-medium"><?= $page ?></span> of <span class="font-medium"><?= $total_pages ?></span>
                        </p>
                    </div>
                    <div class="flex-1 flex justify-between sm:justify-end space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="<?= htmlspecialchars(buildPaginationUrl($page - 1, $search, $election_filter, $position_filter, $records_per_page)) ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-chevron-left mr-2"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="<?= htmlspecialchars(buildPaginationUrl($page + 1, $search, $election_filter, $position_filter, $records_per_page)) ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Next <i class="fas fa-chevron-right ml-2"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="toast-container"></div>

<!-- Include the candidate modal -->
<?php require APP_ROOT . '/views/modals/add_candidate.php'; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>

<script>
// Global variables
const BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?>';
const USER_ID = '<?= $_SESSION['user_id'] ?? '' ?>';
const USER_NAME = '<?= ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '') ?>';

// Make BASE_URL available globally
window.BASE_URL = BASE_URL;

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

// Helper function to change records per page
function changePerPage(limit) {
    ActivityLogger.log('per_page_change', 'Changed records per page', { limit: limit });
    
    const urlParams = new URLSearchParams(window.location.search);
    urlParams.set('limit', limit);
    urlParams.set('page', 'admin/candidates'); // keep router key
    urlParams.set('p', '1');                   // reset to page 1
    // Always navigate through the front-controller
    window.location.href = BASE_URL + '/index.php?' + urlParams.toString();
}

// API helper
async function apiJSON(url, options = {}) {
    const res = await fetch(url, { credentials: 'same-origin', ...options });
    let payload = null;
    try {
        payload = await res.json();
    } catch (e) {
        console.error('Failed to parse JSON response:', e);
    }
    if (!res.ok) {
        const msg = (payload && (payload.message || payload.error)) || `HTTP ${res.status}`;
        throw new Error(msg);
    }
    return payload || {};
}

// Modal helpers
function showModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        ActivityLogger.log('modal_opened', 'Modal opened', { modal_id: id });
    }
}

function hideModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = 'auto';
        ActivityLogger.log('modal_closed', 'Modal closed', { modal_id: id });
    }
}

// View Candidate Details
window.viewCandidate = function(candidate) {
    ActivityLogger.log('view_candidate', 'Viewed candidate details', {
        candidate_id: candidate.id,
        candidate_name: candidate.first_name + ' ' + candidate.last_name
    });
    
    const modalContent = `
        <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white p-6 rounded-lg shadow-lg w-full max-w-md">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-pink-900">Candidate Details</h3>
                    <button type="button" onclick="this.closest('.fixed').remove()" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="text-center mb-4">
                    <img src="${candidate.photo_path ? BASE_URL + '/' + candidate.photo_path : BASE_URL + '/assets/img/default-avatar.png'}" 
                         alt="Candidate Photo" class="w-24 h-24 rounded-full object-cover border-2 border-gray-200 mx-auto">
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="font-semibold text-gray-600">Name:</label>
                        <p class="text-gray-900">${candidate.first_name} ${candidate.last_name}</p>
                    </div>
                    <div>
                        <label class="font-semibold text-gray-600">Position:</label>
                        <p class="text-gray-900">${candidate.position_name || '—'}</p>
                    </div>
                    <div>
                        <label class="font-semibold text-gray-600">Election:</label>
                        <p class="text-gray-900">${candidate.election_title || '—'}</p>
                        ${candidate.election_status ? `<span class="status-badge status-${candidate.election_status} mt-1">${candidate.election_status}</span>` : ''}
                    </div>
                    <div>
                        <label class="font-semibold text-gray-600">Department:</label>
                        <p class="text-gray-900">${candidate.department || '—'}</p>
                    </div>
                    <div>
                        <label class="font-semibold text-gray-600">Level:</label>
                        <p class="text-gray-900">${candidate.level || '—'}</p>
                    </div>
                    <div>
                        <label class="font-semibold text-gray-600">Email:</label>
                        <p class="text-gray-900">${candidate.email || '—'}</p>
                    </div>
                    ${candidate.manifesto ? `
                    <div>
                        <label class="font-semibold text-gray-600">Manifesto:</label>
                        <p class="mt-1 text-sm text-gray-700 bg-gray-50 p-3 rounded-lg">${candidate.manifesto}</p>
                    </div>
                    ` : ''}
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="button" onclick="this.closest('.fixed').remove()" class="bg-pink-900 text-white px-4 py-2 rounded-lg hover:bg-pink-800 transition-colors">
                        Close
                    </button>
                </div>
            </div>
        </div>
    `;
    
    const modal = document.createElement('div');
    modal.innerHTML = modalContent;
    document.body.appendChild(modal);
};

// Confirm Delete Candidate
window.confirmDeleteCandidate = function(id, name) {
    if (confirm(`Are you sure you want to delete ${name}? This action cannot be undone.`)) {
        ActivityLogger.log('delete_attempt', 'Attempting to delete candidate', {
            candidate_id: id,
            candidate_name: name
        });
        deleteCandidate(id, name);
    }
};

// Delete Candidate
async function deleteCandidate(id, name) {
    try {
        const data = await apiJSON(`${BASE_URL}/api/admin/candidates.php?id=${encodeURIComponent(id)}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': CSRF_TOKEN }
        });
        
        if (data.success) {
            ActivityLogger.log('delete_success', 'Candidate deleted successfully', {
                candidate_id: id,
                candidate_name: name
            });
            Toast.success('Candidate deleted successfully');
            setTimeout(() => location.reload(), 1000);
        } else {
            ActivityLogger.log('delete_failed', 'Failed to delete candidate', {
                candidate_id: id,
                error: data.message
            });
            Toast.error(data.message || 'Failed to delete candidate');
        }
    } catch (err) {
        console.error('Delete failed:', err);
        ActivityLogger.log('delete_error', 'Error deleting candidate', {
            candidate_id: id,
            error: err.message
        });
        Toast.error(err.message || 'Network error');
    }
}

// Global editCandidate function - Will be defined after modal loads
window.editCandidate = null;

// Initialize on document ready
document.addEventListener('DOMContentLoaded', () => {
    ActivityLogger.log('candidates_page_load', 'Candidates management page loaded', {
        url: window.location.href,
        filters: {
            search: '<?= $search ?>',
            election: '<?= $election_filter ?>',
            position: '<?= $position_filter ?>'
        }
    });

    const currentPath = window.location.pathname;
    if (currentPath.includes('/admin/admin/')) {
        console.warn('Detected duplicate /admin/ in path:', currentPath);
    }
});
</script>

<?php if (isset($_GET['debug'])): ?>
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 mb-4 bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700">
    <p class="font-bold">Debug Information:</p>
    <p>BASE_URL: <?= BASE_URL ?></p>
    <p>Current Page: <?= $current_page ?></p>
    <p>Sample Next Page URL: <?= buildPaginationUrl($page + 1, $search, $election_filter, $position_filter, $records_per_page) ?></p>
    <p>Request URI: <?= $_SERVER['REQUEST_URI'] ?></p>
    <p>Script Name: <?= $_SERVER['SCRIPT_NAME'] ?></p>
</div>
<?php endif; ?>