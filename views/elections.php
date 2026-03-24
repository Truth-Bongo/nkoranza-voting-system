<?php
// views/elections.php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Add Activity Logger
require_once APP_ROOT . '/includes/ActivityLogger.php';
$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);

require_login();

// Get user information
$user = [
    'id' => $_SESSION['user_id'] ?? 'unknown',
    'first_name' => $_SESSION['first_name'] ?? 'Unknown',
    'last_name' => $_SESSION['last_name'] ?? 'User',
    'is_admin' => isset($_SESSION['is_admin']) && $_SESSION['is_admin'],
    'full_name' => trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Unknown User'
];

// Log page access
$activityLogger->logActivity(
    $user['id'],
    $user['full_name'],
    'elections_page_view',
    'Accessed elections page',
    json_encode([
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ])
);

// Set timezone
date_default_timezone_set('Africa/Accra');

// Auto-update election statuses — use prepared statement, not interpolation
try {
    $now = date('Y-m-d H:i:s');
    // [FIX] Prepared statement with unique param names (not string interpolation)
    $updateStmt = $db->prepare("
        UPDATE elections SET status = CASE
            WHEN :ct1 < start_date THEN 'upcoming'
            WHEN :ct2 <= end_date  THEN 'active'
            ELSE 'ended'
        END
    ");
    $updateStmt->execute([':ct1' => $now, ':ct2' => $now]);
} catch (Exception $e) {
    error_log("Status update error: " . $e->getMessage());
}

// Handle search and filter parameters
$search = isset($_GET['search']) ? htmlspecialchars(trim($_GET['search']), ENT_QUOTES, 'UTF-8') : '';
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['active', 'upcoming', 'ended']) 
    ? $_GET['status'] 
    : 'all';
// [FIX] 'page' is the router key ('elections'); 'p' is the pagination number
$page = isset($_GET['p']) ? max(1, (int)$_GET['p']) : 1;
$limit = 12;

// Log search/filter activities
if (!empty($search)) {
    $activityLogger->logActivity(
        $user['id'],
        $user['full_name'],
        'elections_search',
        'Searched elections',
        json_encode(['search_term' => $search])
    );
}

if ($status_filter !== 'all') {
    $activityLogger->logActivity(
        $user['id'],
        $user['full_name'],
        'elections_filter',
        'Filtered elections by status',
        json_encode(['status' => $status_filter])
    );
}

try {
    // Build query
    $query = "SELECT * FROM elections WHERE 1=1";
    $countQuery = "SELECT COUNT(*) as total FROM elections WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $searchTerm = "%$search%";
        $query .= " AND (title LIKE :search OR description LIKE :search)";
        $countQuery .= " AND (title LIKE :search OR description LIKE :search)";
        $params[':search'] = $searchTerm;
    }

    if ($status_filter !== 'all') {
        $query .= " AND status = :status";
        $countQuery .= " AND status = :status";
        $params[':status'] = $status_filter;
    }

    // Get total count for pagination
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalElections = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = max(1, ceil($totalElections / $limit));
    $offset = ($page - 1) * $limit;

    // Get paginated results
    $query .= " ORDER BY 
        CASE status
            WHEN 'active' THEN 1
            WHEN 'upcoming' THEN 2
            WHEN 'ended' THEN 3
        END,
        start_date DESC
        LIMIT :offset, :limit";
    
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $elections = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'upcoming' THEN 1 ELSE 0 END) as upcoming,
            SUM(CASE WHEN status = 'ended' THEN 1 ELSE 0 END) as ended
        FROM elections
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Log successful retrieval
    $activityLogger->logActivity(
        $user['id'],
        $user['full_name'],
        'elections_retrieved',
        'Retrieved elections list',
        json_encode([
            'count' => count($elections),
            'total' => $totalElections,
            'page' => $page,
            'filters' => [
                'search' => $search ?: null,
                'status' => $status_filter !== 'all' ? $status_filter : null
            ]
        ])
    );

} catch (Exception $e) {
    error_log("Elections error: " . $e->getMessage());
    $elections = [];
    $totalElections = 0;
    $totalPages = 1;
    $stats = ['total' => 0, 'active' => 0, 'upcoming' => 0, 'ended' => 0];
    
    $activityLogger->logActivity(
        $user['id'],
        $user['full_name'],
        'elections_error',
        'Error fetching elections',
        json_encode(['error' => $e->getMessage()])
    );
}

// Format elections data
$formattedElections = array_map(function($election) use ($db, $user) {
    // Calculate days remaining for active elections
    $days_remaining = '';
    if ($election['status'] === 'active') {
        $end_date = new DateTime($election['end_date']);
        $now = new DateTime();
        $interval = $now->diff($end_date);
        if ($interval->days > 0) {
            $days_remaining = $interval->format('%a days, %h hours remaining');
        } else {
            $days_remaining = $interval->format('%h hours, %i minutes remaining');
        }
    }
    
    // Check if user has voted (for non-admin users)
    $has_voted = false;
    if (!$user['is_admin'] && isset($_SESSION['user_id'])) {
        try {
            $voteCheck = $db->prepare("SELECT id FROM votes WHERE election_id = ? AND voter_id = ? LIMIT 1");
            $voteCheck->execute([$election['id'], $_SESSION['user_id']]);
            $has_voted = (bool)$voteCheck->fetch();
        } catch (Exception $e) {
            error_log("Vote check error: " . $e->getMessage());
        }
    }
    
    return [
        'id' => $election['id'],
        'title' => htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'),
        'description' => htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8'),
        'start_date' => $election['start_date'],
        'end_date' => $election['end_date'],
        'status' => $election['status'],
        'days_remaining' => $days_remaining,
        'has_voted' => $has_voted
    ];
}, $elections);

// Format stats for display
$statCards = [
    ['label' => 'Total Elections', 'count' => $stats['total'] ?? 0, 'icon' => 'fa-calendar-alt', 'color' => 'blue'],
    ['label' => 'Active', 'count' => $stats['active'] ?? 0, 'icon' => 'fa-vote-yea', 'color' => 'green'],
    ['label' => 'Upcoming', 'count' => $stats['upcoming'] ?? 0, 'icon' => 'fa-clock', 'color' => 'yellow'],
    ['label' => 'Ended', 'count' => $stats['ended'] ?? 0, 'icon' => 'fa-times-circle', 'color' => 'red']
];

// Fetch distinct election years for the year filter dropdown
$electionYears = [];
try {
    $yearsStmt = $db->query("
        SELECT DISTINCT YEAR(end_date) as yr
        FROM elections
        ORDER BY yr DESC
    ");
    $electionYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Election years fetch error: " . $e->getMessage());
}

// Handle year filter param
$year_filter = isset($_GET['year']) && in_array($_GET['year'], $electionYears)
    ? (int)$_GET['year']
    : 0;

// Re-run query with year filter if set
if ($year_filter) {
    try {
        $yQuery = "SELECT * FROM elections WHERE YEAR(end_date) = :yr";
        $ycQuery = "SELECT COUNT(*) as total FROM elections WHERE YEAR(end_date) = :yr";
        $yParams = [':yr' => $year_filter];
        if (!empty($search)) {
            $yQuery  .= " AND (title LIKE :search OR description LIKE :search)";
            $ycQuery .= " AND (title LIKE :search OR description LIKE :search)";
            $yParams[':search'] = "%$search%";
        }
        if ($status_filter !== 'all') {
            $yQuery  .= " AND status = :status";
            $ycQuery .= " AND status = :status";
            $yParams[':status'] = $status_filter;
        }
        $ycStmt = $db->prepare($ycQuery);
        $ycStmt->execute($yParams);
        $totalElections = (int)$ycStmt->fetch(PDO::FETCH_ASSOC)['total'];
        $totalPages = max(1, ceil($totalElections / $limit));
        $offset = ($page - 1) * $limit;
        $yQuery .= " ORDER BY CASE status WHEN 'active' THEN 1 WHEN 'upcoming' THEN 2 WHEN 'ended' THEN 3 END, start_date DESC LIMIT :offset, :limit";
        $yStmt = $db->prepare($yQuery);
        foreach ($yParams as $k => $v) { $yStmt->bindValue($k, $v); }
        $yStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $yStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $yStmt->execute();
        $elections = $yStmt->fetchAll(PDO::FETCH_ASSOC);
        // Reformat
        $formattedElections = array_map(function($election) use ($db, $user) {
            $days_remaining = '';
            if ($election['status'] === 'active') {
                $end_date = new DateTime($election['end_date']);
                $now = new DateTime();
                $interval = $now->diff($end_date);
                $days_remaining = $interval->days > 0
                    ? $interval->format('%a days, %h hours remaining')
                    : $interval->format('%h hours, %i minutes remaining');
            }
            $has_voted = false;
            if (!$user['is_admin'] && isset($_SESSION['user_id'])) {
                try {
                    $vc = $db->prepare("SELECT id FROM votes WHERE election_id = ? AND voter_id = ? LIMIT 1");
                    $vc->execute([$election['id'], $_SESSION['user_id']]);
                    $has_voted = (bool)$vc->fetch();
                } catch (Exception $e) { error_log("Vote check error: " . $e->getMessage()); }
            }
            return [
                'id'            => $election['id'],
                'title'         => htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8'),
                'description'   => htmlspecialchars($election['description'] ?? '', ENT_QUOTES, 'UTF-8'),
                'start_date'    => $election['start_date'],
                'end_date'      => $election['end_date'],
                'status'        => $election['status'],
                'days_remaining'=> $days_remaining,
                'has_voted'     => $has_voted
            ];
        }, $elections);
    } catch (Exception $e) {
        error_log("Year filter error: " . $e->getMessage());
    }
}

// Pass data to JavaScript
$initialState = json_encode([
    'user' => [
        'id'      => $user['id'],
        'name'    => $user['full_name'],
        'isAdmin' => $user['is_admin']
    ],
    'filters' => [
        'search' => $search,
        'status' => $status_filter,
        'year'   => $year_filter ?: '',
        'page'   => $page
    ],
    'stats'          => $statCards,
    'totalPages'     => $totalPages,
    'totalElections' => $totalElections,
    'currentPage'    => $page,
    'electionYears'  => $electionYears
]);
?>
<?php include APP_ROOT . '/includes/header.php'; ?>

<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<script>
    window.__INITIAL_STATE__ = <?= $initialState ?>;
    window.BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';
</script>

<style>
[x-cloak]{display:none!important}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.el-card{animation:fadeUp .35s ease both;transition:box-shadow .2s,transform .2s}
.el-card:hover{box-shadow:0 12px 28px -5px rgba(131,24,67,.14);transform:translateY(-2px)}
.stat-card{transition:transform .2s,box-shadow .2s}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 20px -4px rgba(0,0,0,.1)}
.line-clamp-2{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8"
     x-data="electionsApp()"
     x-init="init()">

    <!-- Page heading -->
    <div class="text-center mb-10">
        <h2 class="text-3xl font-bold text-gray-900 mb-2">Elections</h2>
        <p class="text-gray-500 max-w-xl mx-auto">Participate in democratic decision-making or review past election results.</p>
        <div class="mt-3 inline-flex items-center gap-2 px-4 py-1.5 bg-green-50 rounded-full">
            <span class="relative flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
            </span>
            <span class="text-sm font-medium text-green-700">Live updates</span>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <template x-for="(stat, i) in stats" :key="i">
            <div class="stat-card bg-white rounded-xl shadow-sm p-5 border border-gray-100"
                 :style="`animation-delay:${i*80}ms`">
                <div class="flex items-center gap-3">
                    <div :class="`bg-${stat.color}-100 p-2.5 rounded-xl`">
                        <i :class="`fas ${stat.icon} text-${stat.color}-600 text-lg`"></i>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500" x-text="stat.label"></p>
                        <p class="text-2xl font-bold text-gray-900 leading-tight" x-text="stat.count"></p>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Search + Filters -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-5 mb-8">
        <div class="flex flex-col md:flex-row gap-3">

            <!-- Search -->
            <div class="flex-1 relative">
                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                    <i class="fas fa-search text-gray-400 text-sm"></i>
                </div>
                <input type="text"
                       x-model="filters.search"
                       @input.debounce.500ms="applyFilters()"
                       placeholder="Search by title or description…"
                       class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all">
            </div>

            <!-- Status filter -->
            <select x-model="filters.status"
                    @change="applyFilters()"
                    class="w-full md:w-44 px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-pink-500 focus:border-transparent bg-white">
                <option value="all">All statuses</option>
                <option value="active">🟢 Active</option>
                <option value="upcoming">🟡 Upcoming</option>
                <option value="ended">🔴 Ended</option>
            </select>

            <!-- Year filter -->
            <select x-model="filters.year"
                    @change="applyFilters()"
                    class="w-full md:w-40 px-3 py-2.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-pink-500 focus:border-transparent bg-white">
                <option value="">All years</option>
                <template x-for="yr in electionYears" :key="yr">
                    <option :value="yr" x-text="yr"></option>
                </template>
            </select>

            <!-- Admin button -->
            <template x-if="user.isAdmin">
                <a :href="`${BASE_URL}/index.php?page=admin/elections`"
                   class="inline-flex items-center gap-2 px-5 py-2.5 bg-gray-800 hover:bg-gray-900 text-white text-sm font-medium rounded-lg transition-all whitespace-nowrap">
                    <i class="fas fa-cog"></i> Manage
                </a>
            </template>
        </div>

        <!-- Active filter pills -->
        <div class="flex flex-wrap gap-2 mt-3" x-show="hasActiveFilters" x-cloak>
            <span class="text-xs text-gray-400 self-center">Filters:</span>
            <template x-if="filters.search">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-pink-100 text-pink-800">
                    <i class="fas fa-search" style="font-size:10px"></i>
                    <span x-text="filters.search"></span>
                    <button @click="filters.search='';applyFilters()" class="ml-1 hover:text-pink-600"><i class="fas fa-times" style="font-size:10px"></i></button>
                </span>
            </template>
            <template x-if="filters.status !== 'all'">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs"
                      :class="{'bg-green-100 text-green-800':filters.status==='active','bg-yellow-100 text-yellow-800':filters.status==='upcoming','bg-red-100 text-red-800':filters.status==='ended'}">
                    <span x-text="filters.status.charAt(0).toUpperCase()+filters.status.slice(1)"></span>
                    <button @click="filters.status='all';applyFilters()" class="ml-1 hover:opacity-70"><i class="fas fa-times" style="font-size:10px"></i></button>
                </span>
            </template>
            <template x-if="filters.year">
                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                    <i class="fas fa-calendar" style="font-size:10px"></i>
                    <span x-text="filters.year"></span>
                    <button @click="filters.year='';applyFilters()" class="ml-1 hover:text-blue-600"><i class="fas fa-times" style="font-size:10px"></i></button>
                </span>
            </template>
            <button @click="clearFilters()" class="text-xs text-pink-600 hover:text-pink-800 underline">Clear all</button>
        </div>
    </div>

    <!-- Election grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

        <!-- Loading -->
        <template x-if="loading">
            <div class="col-span-full py-20 flex flex-col items-center">
                <div class="w-12 h-12 border-4 border-pink-200 border-t-pink-600 rounded-full animate-spin mb-4"></div>
                <p class="text-gray-400 text-sm">Loading elections…</p>
            </div>
        </template>

        <!-- Empty -->
        <template x-if="!loading && elections.length === 0">
            <div class="col-span-full py-20 text-center">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-gray-100 rounded-full mb-4">
                    <i class="fas fa-calendar-times text-3xl text-gray-400"></i>
                </div>
                <h3 class="text-lg font-semibold text-gray-700 mb-1">No elections found</h3>
                <p class="text-sm text-gray-400 mb-5" x-text="emptyStateMessage"></p>
                <button @click="clearFilters()"
                        class="px-5 py-2 bg-pink-600 hover:bg-pink-700 text-white text-sm rounded-lg transition-all">
                    Clear Filters
                </button>
            </div>
        </template>

        <!-- Cards -->
        <template x-for="(election, idx) in elections" :key="election.id">
            <div class="el-card bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden"
                 :style="`animation-delay:${idx*50}ms`">

                <!-- Status stripe -->
                <div class="h-1.5" :class="{
                    'bg-green-500': election.status === 'active',
                    'bg-yellow-400': election.status === 'upcoming',
                    'bg-gray-400': election.status === 'ended'
                }"></div>

                <div class="p-5">
                    <!-- Header row -->
                    <div class="flex justify-between items-start gap-3 mb-3">
                        <div class="flex-1 min-w-0">
                            <h3 class="font-bold text-gray-900 text-base leading-snug" x-text="election.title"></h3>
                            <!-- Year badge -->
                            <span class="inline-block mt-1 text-xs font-medium px-2 py-0.5 rounded-full bg-blue-50 text-blue-700"
                                  x-text="'Class of ' + election.end_date.substring(0,4)"></span>
                        </div>
                        <!-- Status badge -->
                        <span class="flex-shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium"
                              :class="{
                                  'bg-green-100 text-green-800': election.status === 'active',
                                  'bg-yellow-100 text-yellow-800': election.status === 'upcoming',
                                  'bg-gray-100 text-gray-700': election.status === 'ended'
                              }">
                            <span class="w-1.5 h-1.5 rounded-full" :class="{
                                'bg-green-500':election.status==='active',
                                'bg-yellow-400':election.status==='upcoming',
                                'bg-gray-400':election.status==='ended'
                            }"></span>
                            <span x-text="election.status.charAt(0).toUpperCase()+election.status.slice(1)"></span>
                        </span>
                    </div>

                    <!-- Description -->
                    <p class="text-sm text-gray-500 mb-4 line-clamp-2"
                       x-text="election.description || 'No description provided'"></p>

                    <!-- Dates + time info -->
                    <div class="space-y-1.5 mb-4 text-xs text-gray-400">
                        <div class="flex items-center gap-1.5">
                            <i class="fas fa-calendar-alt w-4 text-center"></i>
                            <span x-text="formatDate(election.start_date)"></span>
                            <span class="mx-1">→</span>
                            <span x-text="formatDate(election.end_date)"></span>
                        </div>
                        <!-- Active: time remaining -->
                        <template x-if="election.status === 'active' && election.days_remaining">
                            <div class="flex items-center gap-1.5 text-green-600 font-medium">
                                <i class="fas fa-hourglass-half w-4 text-center"></i>
                                <span x-text="election.days_remaining"></span>
                            </div>
                        </template>
                        <!-- Upcoming: starts in -->
                        <template x-if="election.status === 'upcoming'">
                            <div class="flex items-center gap-1.5 text-yellow-600 font-medium">
                                <i class="fas fa-clock w-4 text-center"></i>
                                <span x-text="startsIn(election.start_date)"></span>
                            </div>
                        </template>
                    </div>

                    <!-- Action row -->
                    <div class="flex justify-between items-center pt-3 border-t border-gray-100 gap-2">

                        <!-- Student vote actions -->
                        <template x-if="!user.isAdmin">
                            <div>
                                <template x-if="election.status === 'active' && !election.has_voted">
                                    <a :href="`${BASE_URL}/index.php?page=vote&election_id=${election.id}`"
                                       @click="logActivity('vote_click',{election_id:election.id})"
                                       class="inline-flex items-center gap-1.5 px-4 py-2 bg-pink-700 hover:bg-pink-800 text-white text-xs font-semibold rounded-lg transition-all">
                                        <i class="fas fa-vote-yea"></i> Vote Now
                                    </a>
                                </template>
                                <template x-if="election.status === 'active' && election.has_voted">
                                    <span class="inline-flex items-center gap-1.5 px-4 py-2 bg-green-100 text-green-700 text-xs font-medium rounded-lg">
                                        <i class="fas fa-check-circle"></i> Voted
                                    </span>
                                </template>
                                <template x-if="election.status === 'upcoming'">
                                    <span class="inline-flex items-center gap-1.5 px-4 py-2 bg-yellow-100 text-yellow-700 text-xs font-medium rounded-lg">
                                        <i class="fas fa-clock"></i> Coming Soon
                                    </span>
                                </template>
                                <template x-if="election.status === 'ended'">
                                    <a :href="`${BASE_URL}/index.php?page=results&election_id=${election.id}`"
                                       @click="logActivity('results_click',{election_id:election.id})"
                                       class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white text-xs font-semibold rounded-lg transition-all">
                                        <i class="fas fa-chart-bar"></i> View Results
                                    </a>
                                </template>
                            </div>
                        </template>

                        <!-- Admin left side -->
                        <template x-if="user.isAdmin">
                            <div>
                                <template x-if="election.status === 'ended'">
                                    <a :href="`${BASE_URL}/index.php?page=results&election_id=${election.id}`"
                                       @click="logActivity('results_click',{election_id:election.id})"
                                       class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-700 hover:bg-gray-800 text-white text-xs font-semibold rounded-lg transition-all">
                                        <i class="fas fa-chart-bar"></i> Results
                                    </a>
                                </template>
                                <template x-if="election.status !== 'ended'">
                                    <span class="inline-flex items-center gap-1.5 px-4 py-2 bg-gray-100 text-gray-500 text-xs rounded-lg">
                                        <i class="fas fa-user-shield"></i> Admin
                                    </span>
                                </template>
                            </div>
                        </template>

                        <!-- Admin edit/delete -->
                        <template x-if="user.isAdmin">
                            <div class="flex gap-1">
                                <a :href="`${BASE_URL}/index.php?page=admin/elections&action=edit&id=${election.id}`"
                                   @click="logActivity('edit_click',{election_id:election.id})"
                                   class="p-2 text-blue-600 hover:bg-blue-50 rounded-lg transition-colors" title="Edit">
                                    <i class="fas fa-edit text-sm"></i>
                                </a>
                                <button @click="confirmDelete(election)"
                                        class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
                                    <i class="fas fa-trash text-sm"></i>
                                </button>
                            </div>
                        </template>

                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- Pagination -->
    <div class="mt-10 flex flex-col sm:flex-row justify-between items-center gap-4" x-show="totalPages > 1">
        <p class="text-sm text-gray-500">
            Showing <span class="font-semibold" x-text="((filters.page-1)*12)+1"></span>–<span class="font-semibold" x-text="Math.min(filters.page*12, totalElections)"></span>
            of <span class="font-semibold" x-text="totalElections"></span> elections
        </p>
        <nav class="flex items-center gap-1.5">
            <button @click="goToPage(1)" :disabled="filters.page===1"
                    class="p-2 rounded-lg border border-gray-200 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed text-sm">
                <i class="fas fa-angle-double-left"></i>
            </button>
            <button @click="goToPage(filters.page-1)" :disabled="filters.page===1"
                    class="p-2 rounded-lg border border-gray-200 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed text-sm">
                <i class="fas fa-angle-left"></i>
            </button>
            <template x-for="pn in visiblePages" :key="pn">
                <button @click="goToPage(pn)"
                        class="px-3.5 py-2 rounded-lg text-sm font-medium transition-all"
                        :class="pn===filters.page ? 'bg-pink-700 text-white shadow' : 'border border-gray-200 hover:bg-gray-100'">
                    <span x-text="pn"></span>
                </button>
            </template>
            <button @click="goToPage(filters.page+1)" :disabled="filters.page===totalPages"
                    class="p-2 rounded-lg border border-gray-200 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed text-sm">
                <i class="fas fa-angle-right"></i>
            </button>
            <button @click="goToPage(totalPages)" :disabled="filters.page===totalPages"
                    class="p-2 rounded-lg border border-gray-200 hover:bg-gray-100 disabled:opacity-40 disabled:cursor-not-allowed text-sm">
                <i class="fas fa-angle-double-right"></i>
            </button>
        </nav>
    </div>
</div>

<!-- Delete modal -->
<div x-show="showDeleteModal" x-cloak
     class="fixed inset-0 z-50 overflow-y-auto"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     style="min-height:500px">
    <div style="min-height:500px;background:rgba(0,0,0,0.45);display:flex;align-items:center;justify-content:center;padding:1rem">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md"
             x-show="showDeleteModal"
             x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">
            <div class="p-6">
                <div class="flex items-start gap-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-exclamation-triangle text-red-600"></i>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-900">Delete Election</h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Are you sure you want to delete
                            <strong x-text="deleteCandidate?.title"></strong>?
                            This removes all associated candidates and votes and cannot be undone.
                        </p>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-4 rounded-b-xl flex justify-end gap-3">
                <button @click="showDeleteModal=false"
                        class="px-4 py-2 border border-gray-200 text-gray-700 text-sm rounded-lg hover:bg-gray-100 transition-all">
                    Cancel
                </button>
                <button @click="executeDelete()"
                        class="px-4 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-all">
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Toast stack -->
<div class="fixed bottom-4 right-4 z-50 space-y-2">
    <template x-for="t in toasts" :key="t.id">
        <div x-show="t.show"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-x-full"
             x-transition:enter-end="opacity-100 translate-x-0"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100 translate-x-0"
             x-transition:leave-end="opacity-0 translate-x-full"
             class="flex items-center gap-3 p-4 rounded-lg shadow-lg max-w-sm text-sm"
             :class="{
                 'bg-green-50 border-l-4 border-green-500 text-green-800': t.type==='success',
                 'bg-red-50 border-l-4 border-red-500 text-red-800': t.type==='error',
                 'bg-blue-50 border-l-4 border-blue-500 text-blue-800': t.type==='info'
             }">
            <i class="fas flex-shrink-0"
               :class="{'fa-check-circle text-green-600':t.type==='success','fa-exclamation-circle text-red-600':t.type==='error','fa-info-circle text-blue-600':t.type==='info'}"></i>
            <span class="flex-1" x-text="t.message"></span>
            <button @click="removeToast(t.id)" class="opacity-50 hover:opacity-80"><i class="fas fa-times"></i></button>
        </div>
    </template>
</div>

<script>
function electionsApp() {
    return {
        user:           window.__INITIAL_STATE__.user    || {},
        filters:        window.__INITIAL_STATE__.filters || {search:'',status:'all',year:'',page:1},
        stats:          window.__INITIAL_STATE__.stats   || [],
        elections:      <?= json_encode($formattedElections) ?>,
        electionYears:  window.__INITIAL_STATE__.electionYears || [],
        totalPages:     window.__INITIAL_STATE__.totalPages    || 1,
        totalElections: window.__INITIAL_STATE__.totalElections || 0,
        loading: false,
        showDeleteModal: false,
        deleteCandidate: null,
        toasts: [],

        get hasActiveFilters() {
            return this.filters.search || this.filters.status !== 'all' || this.filters.year;
        },
        get emptyStateMessage() {
            const parts = [];
            if (this.filters.status !== 'all') parts.push(this.filters.status);
            if (this.filters.year) parts.push(this.filters.year);
            const qualifier = parts.length ? parts.join(', ') + ' ' : '';
            return this.filters.search
                ? `No ${qualifier}elections match "${this.filters.search}"`
                : `No ${qualifier}elections found`;
        },
        get visiblePages() {
            const total = this.totalPages, cur = this.filters.page, d = 2;
            const pages = [];
            for (let i = 1; i <= total; i++) {
                if (i === 1 || i === total || (i >= cur - d && i <= cur + d)) pages.push(i);
            }
            return pages;
        },

        init() {
            let ready = false;
            this.$nextTick(() => { ready = true; });
            this.$watch('filters.search',  () => { if (ready) this.applyFilters(); });
            this.$watch('filters.status',  () => { if (ready) this.applyFilters(); });
            this.$watch('filters.year',    () => { if (ready) this.applyFilters(); });
        },

        applyFilters() {
            this.filters.page = 1;
            const p = new URLSearchParams();
            p.set('page', 'elections');
            if (this.filters.search)         p.set('search', this.filters.search);
            if (this.filters.status !== 'all') p.set('status', this.filters.status);
            if (this.filters.year)           p.set('year',   this.filters.year);
            p.set('p', '1');
            window.location.href = 'index.php?' + p.toString();
        },

        clearFilters() {
            window.location.href = 'index.php?page=elections';
        },

        goToPage(pg) {
            if (pg < 1 || pg > this.totalPages || pg === this.filters.page) return;
            const p = new URLSearchParams(window.location.search);
            p.set('page', 'elections');
            p.set('p', pg.toString());
            window.location.href = 'index.php?' + p.toString();
        },

        formatDate(ds) {
            return new Date(ds).toLocaleDateString('en-GB', {
                day:'numeric', month:'short', year:'numeric'
            });
        },

        startsIn(ds) {
            const diff = new Date(ds) - new Date();
            if (diff <= 0) return 'Starting soon';
            const days = Math.floor(diff / 86400000);
            const hrs  = Math.floor((diff % 86400000) / 3600000);
            if (days > 0) return `Starts in ${days} day${days > 1 ? 's' : ''}`;
            if (hrs  > 0) return `Starts in ${hrs} hour${hrs  > 1 ? 's' : ''}`;
            return 'Starting very soon';
        },

        confirmDelete(election) {
            this.deleteCandidate = election;
            this.showDeleteModal = true;
        },

        async executeDelete() {
            if (!this.deleteCandidate) return;
            try {
                const res  = await fetch(`${BASE_URL}/api/admin/elections.php?id=${this.deleteCandidate.id}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content }
                });
                const data = await res.json();
                if (data.success) {
                    this.showDeleteModal = false;
                    this.showToast('Election deleted successfully', 'success');
                    setTimeout(() => window.location.reload(), 900);
                } else {
                    this.showToast(data.message || 'Failed to delete', 'error');
                }
            } catch (e) {
                this.showToast('Network error', 'error');
            } finally {
                this.deleteCandidate = null;
            }
        },

        logActivity(type, data) {
            fetch(`${BASE_URL}/api/log-activity.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ activity_type: type, description: `${type} on elections page`, details: data }),
                keepalive: true
            }).catch(() => {});
        },

        showToast(message, type = 'info', duration = 5000) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, type, show: true });
            setTimeout(() => {
                const t = this.toasts.find(x => x.id === id);
                if (t) t.show = false;
                setTimeout(() => { this.toasts = this.toasts.filter(x => x.id !== id); }, 300);
            }, duration);
        },

        removeToast(id) {
            const t = this.toasts.find(x => x.id === id);
            if (t) t.show = false;
            setTimeout(() => { this.toasts = this.toasts.filter(x => x.id !== id); }, 300);
        }
    };
}
</script>

<?php include APP_ROOT . '/includes/footer.php'; ?>
