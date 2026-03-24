<?php
// Production settings — errors logged server-side only
ini_set('display_errors', 0);
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

// Start output buffering
ob_start();

require_once __DIR__ . '/../bootstrap.php';
require_once APP_ROOT . '/includes/header.php';
require_once APP_ROOT . '/includes/ActivityLogger.php';

// Initialize Activity Logger with database connection
$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);

require_login();

// Check if user is admin
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];

// Log page access
$activityLogger->logActivity(
    $_SESSION['user_id'],
    $_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? ''),
    'results_page_view',
    'Accessed results page',
    json_encode(['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'])
);

// Store user info in JavaScript accessible variables
echo '<script>
    const USER_IS_ADMIN = ' . ($isAdmin ? 'true' : 'false') . ';
    const BASE_URL = "' . BASE_URL . '";
    const USER_ID = "' . $_SESSION['user_id'] . '";
    const FIRST_NAME = "' . ($_SESSION['first_name'] ?? 'Unknown') . '";
    const LAST_NAME = "' . ($_SESSION['last_name'] ?? 'User') . '";
</script>';

// Set timezone to match your application
date_default_timezone_set('Africa/Accra');
?>

<div class="max-w-7xl mx-auto px-4 py-8">
    <!-- Header Section -->
    <div class="text-center mb-8">
        <h2 class="text-3xl font-bold text-gray-900 mb-2">Live Election Results</h2>
        <p class="text-gray-600">Real-time updates as votes are cast</p>
        <div class="mt-4 flex flex-wrap justify-center items-center gap-4">
            <span class="flex items-center bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm">
                <span class="w-2 h-2 bg-green-500 rounded-full mr-2 animate-pulse"></span>
                Live Updates Enabled
            </span>
            <span id="last-update" class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm">
                Last update: Just now
            </span>
            <span id="total-voters" class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full text-sm">
                Loading voter data...
            </span>
        </div>
        
        <!-- Overall Progress Bar -->
        <div id="overall-progress" class="hidden mt-6 max-w-2xl mx-auto">
            <div class="flex justify-between text-sm text-gray-600 mb-1">
                <span>Overall Turnout Progress</span>
                <span id="overall-turnout">0%</span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2.5">
                <div id="progress-bar" class="bg-pink-600 h-2.5 rounded-full transition-all duration-500" style="width: 0%"></div>
            </div>
        </div>
    </div>
    
    <!-- Access Message for Non-Admins Before Election Ends -->
    <div id="access-message" class="hidden bg-purple-100 border-l-4 border-pink-500 text-purple-700 rounded-full p-4 mb-6" role="alert">
        <p class="font-bold">Note</p>
        <p>Results will be available to all users 30 minutes after the election has ended. Only Administrators can view results now.</p>
    </div>
    
    <!-- Controls Section (Admin Only) -->
    <div id="admin-controls" class="mb-6 flex flex-wrap justify-between items-center gap-4">
        <div class="flex-1 min-w-64">
            <label for="results-election-select" class="block text-sm font-medium text-gray-700 mb-1">Select Election</label>
            <select id="results-election-select" class="block pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
                <option value="">Loading elections...</option>
            </select>
        </div>
        
        <div class="flex items-center gap-3 flex-wrap">
            <!-- Category Filter -->
            <div class="w-40">
                <label for="category-filter" class="block text-sm font-medium text-gray-700 mb-1">Filter by Category</label>
                <select id="category-filter" class="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
                    <option value="all">All Categories</option>
                    <!-- Categories will be populated dynamically -->
                </select>
            </div>
            
            <!-- Vote Threshold Filter -->
            <div class="w-32">
                <label for="vote-threshold" class="block text-sm font-medium text-gray-700 mb-1">Min Votes</label>
                <input type="number" id="vote-threshold" min="0" value="0" class="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
            </div>
            
            <!-- Winner Only Filter -->
            <div class="w-40 mt-6">
                <label for="winner-only" class="flex items-center">
                    <input type="checkbox" id="winner-only" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                    <span class="ml-2 text-sm text-gray-600">Show winners only</span>
                </label>
            </div>
            
            <!-- Chart Type Selector -->
            <div class="w-40">
                <label for="chart-type" class="block text-sm font-medium text-gray-700 mb-1">Chart Type</label>
                <select id="chart-type" class="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
                    <option value="bar">Bar Chart</option>
                    <option value="barHorizontal">Horizontal Bar</option>
                    <option value="pie">Pie Chart</option>
                    <option value="doughnut">Doughnut Chart</option>
                </select>
            </div>
            
            <!-- Export Format Selector -->
            <div class="w-32">
                <label for="export-format" class="block text-sm font-medium text-gray-700 mb-1">Export Format</label>
                <select id="export-format" class="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
                    <option value="csv">CSV</option>
                    <option value="excel">Excel</option>
                    <option value="pdf">PDF</option>
                </select>
            </div>
            
            <!-- Auto-refresh toggle -->
            <div class="flex items-center">
                <label class="switch mr-2">
                    <input type="checkbox" id="auto-refresh-toggle" checked>
                    <span class="slider"></span>
                </label>
                <span class="text-sm text-gray-600">Auto Refresh</span>
            </div>
            
            <!-- Refresh interval -->
            <div class="w-32">
                <label for="refresh-interval" class="block text-sm font-medium text-gray-700 mb-1">Refresh Every</label>
                <select id="refresh-interval" class="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
                    <option value="5000">5 seconds</option>
                    <option value="10000" selected>10 seconds</option>
                    <option value="30000">30 seconds</option>
                    <option value="60000">1 minute</option>
                </select>
            </div>
            
            <!-- View Toggle -->
            <div class="w-32">
                <label for="view-mode" class="block text-sm font-medium text-gray-700 mb-1">View Mode</label>
                <select id="view-mode" class="block w-full pl-3 pr-10 py-2 text-base border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-pink-500 sm:text-sm">
                    <option value="combined">Chart & Table</option>
                    <option value="chart-only">Chart Only</option>
                    <option value="table-only">Table Only</option>
                </select>
            </div>
            
            <!-- Export Button with Options -->
            <div class="relative">
                <button id="export-results-btn" class="bg-pink-900 hover:bg-pink-800 text-white px-4 py-2.5 rounded-lg text-sm flex items-center">
                    <i class="fas fa-file-export mr-2"></i> Export
                </button>
                <button id="export-options-btn" class="absolute -right-2 -top-2 bg-gray-300 hover:bg-gray-400 text-gray-700 rounded-full w-5 h-5 flex items-center justify-center text-xs" title="Export options">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
            
            <!-- Manual Refresh Button -->
            <button id="manual-refresh-btn" class="bg-blue-600 hover:bg-blue-700 text-white p-2.5 rounded-lg text-sm" title="Refresh Now">
                <i class="fas fa-sync-alt"></i>
            </button>
            
            <!-- Accessibility Toggle -->
            <button id="accessibility-toggle" class="bg-gray-200 hover:bg-gray-300 text-gray-700 p-2.5 rounded-lg text-sm" title="High Contrast Mode">
                <i class="fas fa-contrast"></i>
            </button>

            <!-- Tutorial Button -->
            <button id="tutorial-btn" class="bg-gray-200 hover:bg-gray-300 text-gray-700 p-2.5 rounded-lg text-sm" title="Show Tutorial">
                <i class="fas fa-question-circle"></i>
            </button>
        </div>
    </div>
    
    <!-- Election Summary Cards -->
    <div id="election-summary" class="hidden grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4 bg-gradient-to-r from-blue-50 to-blue-100">
            <div class="flex items-center">
                <div class="bg-blue-100 p-3 rounded-full">
                    <i class="fas fa-users text-blue-600"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-blue-600">Total Voters</p>
                    <p class="text-2xl font-bold text-blue-800" id="total-voters-count">0</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 bg-gradient-to-r from-green-50 to-green-100">
            <div class="flex items-center">
                <div class="bg-green-100 p-3 rounded-full">
                    <i class="fas fa-vote-yea text-green-600"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-green-600">Votes Cast</p>
                    <p class="text-2xl font-bold text-green-800" id="votes-cast-count">0</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 bg-gradient-to-r from-purple-50 to-purple-100">
            <div class="flex items-center">
                <div class="bg-purple-100 p-3 rounded-full">
                    <i class="fas fa-percentage text-purple-600"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-purple-600">Turnout Rate</p>
                    <p class="text-2xl font-bold text-purple-800" id="turnout-rate">0%</p>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-lg shadow p-4 bg-gradient-to-r from-orange-50 to-orange-100">
            <div class="flex items-center">
                <div class="bg-orange-100 p-3 rounded-full">
                    <i class="fas fa-clock text-orange-600"></i>
                </div>
                <div class="ml-4">
                    <p class="text-sm text-orange-600">Time Remaining</p>
                    <p class="text-2xl font-bold text-orange-800" id="time-remaining">--:--:--</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Analytics Dashboard Toggle -->
    <div id="analytics-toggle-container" class="mb-4 flex justify-center">
        <button id="analytics-toggle" class="bg-gray-100 hover:bg-gray-200 text-gray-700 px-4 py-2 rounded-lg text-sm flex items-center">
            <i class="fas fa-chart-line mr-2"></i> Show Analytics Dashboard
        </button>
    </div>
    
    <!-- Analytics Dashboard -->
    <div id="analytics-dashboard" class="hidden grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div class="bg-white p-4 rounded-lg shadow">
            <h4 class="font-medium text-gray-700 mb-4">Voting Trends</h4>
            <canvas id="trend-chart" height="200"></canvas>
        </div>
        
        <div class="bg-white p-4 rounded-lg shadow">
            <h4 class="font-medium text-gray-700 mb-4">Category Comparison</h4>
            <canvas id="category-chart" height="200"></canvas>
        </div>
        
        <div class="bg-white p-4 rounded-lg shadow">
            <h4 class="font-medium text-gray-700 mb-4">Time Distribution</h4>
            <canvas id="time-chart" height="200"></canvas>
        </div>
        
        <div class="bg-white p-4 rounded-lg shadow">
            <h4 class="font-medium text-gray-700 mb-4">Department Participation</h4>
            <canvas id="department-chart" height="200"></canvas>
        </div>
    </div>
    
    <!-- Results Container -->
    <div id="results-container" class="space-y-6">
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                <i class="fas fa-chart-bar text-3xl text-gray-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-600">Select an election to view results</h3>
            <p class="text-sm text-gray-500 mt-1">Choose from the dropdown above to see live election results</p>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
        <div class="bg-white rounded-lg p-6 flex items-center">
            <div class="animate-spin rounded-full h-6 w-6 border-b-2 border-pink-600 mr-3"></div>
            <span>Loading results...</span>
        </div>
    </div>
</div>

<!-- Tutorial Modal -->
<div id="tutorial-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-xl p-6 max-w-2xl mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-start mb-4">
            <h3 class="text-xl font-semibold text-gray-900">Election Results Tutorial</h3>
            <button id="close-tutorial-modal" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="space-y-4">
            <div>
                <h4 class="font-medium text-gray-800 mb-2">1. Selecting an Election</h4>
                <p class="text-sm text-gray-600">Use the dropdown menu at the top to select which election results you want to view. Only active or completed elections will appear in the list.</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800 mb-2">2. Understanding the Charts</h4>
                <p class="text-sm text-gray-600">Each position shows a visual chart of the results. You can switch between different chart types using the "Chart Type" dropdown.</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800 mb-2">3. Filtering Results</h4>
                <p class="text-sm text-gray-600">Use the category filter to view results for specific position categories. The "Min Votes" filter lets you hide candidates with fewer than a specified number of votes.</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800 mb-2">4. Auto-Refresh</h4>
                <p class="text-sm text-gray-600">Results automatically update at regular intervals. You can change the refresh frequency or disable auto-refresh entirely.</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800 mb-2">5. Exporting Data</h4>
                <p class="text-sm text-gray-600">Use the Export button to download results in CSV, Excel, or PDF format. Click the gear icon for advanced export options.</p>
            </div>
            <div>
                <h4 class="font-medium text-gray-800 mb-2">6. Accessibility</h4>
                <p class="text-sm text-gray-600">Toggle high contrast mode for better visibility. Keyboard shortcuts are available: Ctrl+E for export, Ctrl+R for refresh.</p>
            </div>
        </div>
        <div class="mt-6 flex justify-end">
            <button id="close-tutorial" class="bg-pink-900 hover:bg-pink-800 text-white px-4 py-2 rounded-lg">
                Got it!
            </button>
        </div>
    </div>
</div>

<!-- Export Options Modal -->
<div id="export-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg p-6 w-96">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Export Options</h3>
        
        <div class="space-y-4">
            <div>
                <label class="flex items-center">
                    <input type="checkbox" id="export-summary" checked class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                    <span class="ml-2 text-sm text-gray-600">Include election summary</span>
                </label>
            </div>
            
            <div>
                <label class="flex items-center">
                    <input type="checkbox" id="export-candidates" checked class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                    <span class="ml-2 text-sm text-gray-600">Include candidate details</span>
                </label>
            </div>
            
            <div>
                <label class="flex items-center">
                    <input type="checkbox" id="export-charts" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                    <span class="ml-2 text-sm text-gray-600">Include charts (PDF only)</span>
                </label>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                <div class="flex space-x-2">
                    <input type="date" id="export-start-date" class="flex-1 border border-gray-300 rounded-md px-3 py-2">
                    <input type="date" id="export-end-date" class="flex-1 border border-gray-300 rounded-md px-3 py-2">
                </div>
            </div>
        </div>
        
        <div class="mt-6 flex justify-end space-x-3">
            <button id="close-export-modal" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
            <button id="process-export" class="px-4 py-2 bg-pink-600 text-white rounded-md text-sm hover:bg-pink-700">Export</button>
        </div>
    </div>
</div>

<!-- Replace the Chart.js import with this -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2.0.1"></script>

<!-- Include external JavaScript files -->
<script src="<?php echo BASE_URL; ?>/assets/js/results.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/results-charts.js"></script>

<script>
// Function to log activities via AJAX
function logActivity(activityType, description, details = {}) {
    // Don't log if user is not logged in
    if (!USER_ID || USER_ID === 'unknown') {
        console.log('Skipping activity log - user not identified');
        return;
    }
    
    const logData = {
        activity_type: activityType,
        description: description,
        details: details,
        user_id: USER_ID,
        first_name: FIRST_NAME,
        last_name: LAST_NAME
    };
    
    // For debugging
    console.log('Logging activity:', activityType, description);
    
    // Use fetch with error handling
    fetch(BASE_URL + '/api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(logData),
        keepalive: true
    })
    .then(response => {
        if (!response.ok) {
            console.warn('Logging failed with status:', response.status);
        }
        return response.json();
    })
    .then(data => {
        if (data && !data.success) {
            console.warn('Logging failed:', data.message);
        }
    })
    .catch(error => {
        // Silent fail - don't show to user
        console.warn('Error logging activity (non-critical):', error);
    });
}

// Initialize the application
document.addEventListener('DOMContentLoaded', function() {
    // Log page load
    logActivity('results_page_load', 'Results page loaded', {
        url: window.location.href,
        user_agent: navigator.userAgent
    });
    
    // Initialize the results page
    if (typeof EnhancedResults !== 'undefined') {
        window.resultsSystem = new EnhancedResults();
        window.resultsSystem.init();
    } else {
        console.error('EnhancedResults class not found');
        // Fallback to original initialization if needed
        if (typeof ResultsPage !== 'undefined') {
            ResultsPage.init();
        }
    }
});
</script>

<script>
// Global variables
let realTimeResults = null;
let chartInstances = {};
let analyticsCharts = {};
let currentElection = null;
let allPositions = []; // Store all positions for filtering
let categories = []; // Store all categories
let highContrastMode = false;
let resultsCache = new Map();
let tutorialShown = localStorage.getItem('resultsTutorialShown');
let userIsAdmin = typeof USER_IS_ADMIN !== 'undefined' ? USER_IS_ADMIN : false;
let activityLoggerEnabled = true;

// Register Chart.js plugins
if (typeof Chart !== 'undefined' && typeof ChartDataLabels !== 'undefined') {
    Chart.register(ChartDataLabels);
}

// Security: XSS prevention function
function escapeHtml(unsafe) {
    if (typeof unsafe !== 'string') return unsafe;
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Security: Safe text content function
function setTextContent(element, text) {
    element.textContent = text;
}

// Security: Safe HTML function for trusted content
function setInnerHTML(element, html) {
    element.innerHTML = html;
}

// Fix the DOMContentLoaded event listener - make it async
document.addEventListener('DOMContentLoaded', async function() {
    initializeEventListeners();
    setupAccessibility();
    
    // Apply restrictions for non-admin users
    if (!userIsAdmin) {
        // Add event listeners for right-click and keyboard shortcuts
        document.addEventListener('contextmenu', disableRightClick);
        document.addEventListener('keydown', disableScreenshotShortcuts);
        
        // Disable selection, dragging, and printing
        disableSelectionAndDrag();
        disablePrinting();
        
        // Prevent developer tools
        preventDeveloperTools();
        
        // Add watermark
        addWatermark();
        
        // Hide export options
        hideExportOptions();
        
        // Log that non-admin is viewing results
        logActivity('non_admin_results_view', 'Non-admin user viewing results page');
    }
    
    // Show/hide admin controls based on user role
    if (userIsAdmin) {
        document.getElementById('admin-controls').classList.remove('hidden');
        await loadElections(); // This now works correctly
    } else {
        document.getElementById('admin-controls').classList.add('hidden');
        document.getElementById('access-message').classList.remove('hidden');
        // For non-admin users, we'll load elections but with limited access
        await loadElectionsForNonAdmins();
    }
    
    // Show tutorial if first time
    if (!tutorialShown) {
        setTimeout(() => {
            showTutorial();
            localStorage.setItem('resultsTutorialShown', 'true');
            logActivity('tutorial_viewed', 'User viewed tutorial for the first time');
        }, 2000);
    }
});

function initializeEventListeners() {
    // Election selection
    document.getElementById('results-election-select').addEventListener('change', handleElectionChange);
    
    // Category filter
    document.getElementById('category-filter').addEventListener('change', function() {
        logActivity('filter_category', 'Filtered by category: ' + this.value);
        filterResults();
    });
    
    // Vote threshold filter
    document.getElementById('vote-threshold').addEventListener('input', debounce(function() {
        logActivity('filter_threshold', 'Filtered by vote threshold: ' + this.value);
        filterResults();
    }, 300));
    
    // Winner only filter
    document.getElementById('winner-only').addEventListener('change', function() {
        logActivity('filter_winners', 'Filtered to show ' + (this.checked ? 'winners only' : 'all candidates'));
        filterResults();
    });
    
    // Chart type selector
    document.getElementById('chart-type').addEventListener('change', function() {
        logActivity('chart_type_change', 'Changed chart type to: ' + this.value);
        updateChartType();
    });
    
    // Auto-refresh toggle
    document.getElementById('auto-refresh-toggle').addEventListener('change', function() {
        logActivity('auto_refresh_toggle', 'Auto-refresh ' + (this.checked ? 'enabled' : 'disabled'));
        toggleAutoRefresh();
    });
    
    // Refresh interval change
    document.getElementById('refresh-interval').addEventListener('change', function() {
        logActivity('refresh_interval_change', 'Changed refresh interval to: ' + this.value + 'ms');
        updateRefreshInterval();
    });
    
    // View mode change
    document.getElementById('view-mode').addEventListener('change', function() {
        logActivity('view_mode_change', 'Changed view mode to: ' + this.value);
        updateViewMode();
    });
    
    // Disable "Include charts" checkbox for non-PDF formats
    function updateChartsCheckbox() {
        const fmt = document.getElementById('export-format').value;
        const cb  = document.getElementById('export-charts');
        const lbl = cb ? cb.closest('label') : null;
        if (!cb) return;
        if (fmt === 'pdf') {
            cb.disabled = false;
            if (lbl) lbl.style.opacity = '1';
        } else {
            cb.checked  = false;
            cb.disabled = true;
            if (lbl) lbl.style.opacity = '0.45';
        }
    }
    const fmtSel = document.getElementById('export-format');
    if (fmtSel) {
        fmtSel.addEventListener('change', updateChartsCheckbox);
        updateChartsCheckbox(); // run once on load
    }

    // Export button
    document.getElementById('export-results-btn').addEventListener('click', function() {
        logActivity('export_clicked', 'Export button clicked', {
            format: document.getElementById('export-format').value
        });
        handleExport();
    });
    
    // Export options button
    document.getElementById('export-options-btn').addEventListener('click', function() {
        logActivity('export_options_opened', 'Export options modal opened');
        showExportOptions();
    });
    
    // Manual refresh
    document.getElementById('manual-refresh-btn').addEventListener('click', function() {
        logActivity('manual_refresh', 'Manual refresh triggered');
        manualRefresh();
    });
    
    // Analytics toggle
    document.getElementById('analytics-toggle').addEventListener('click', function() {
        const isShowing = document.getElementById('analytics-dashboard').classList.contains('hidden');
        logActivity('analytics_toggle', 'Analytics dashboard ' + (isShowing ? 'opened' : 'closed'));
        toggleAnalytics();
    });
    
    // Accessibility toggle
    document.getElementById('accessibility-toggle').addEventListener('click', function() {
        logActivity('accessibility_toggle', 'High contrast mode ' + (highContrastMode ? 'disabled' : 'enabled'));
        toggleAccessibility();
    });
    
    // Tutorial button
    document.getElementById('tutorial-btn').addEventListener('click', function() {
        logActivity('tutorial_opened', 'Tutorial modal opened');
        showTutorial();
    });
    document.getElementById('close-tutorial-modal').addEventListener('click', hideTutorial);
    document.getElementById('close-tutorial').addEventListener('click', hideTutorial);
    
    // Keyboard shortcuts
    document.addEventListener('keydown', handleKeyboardShortcuts);
}

function setupAccessibility() {
    // Add ARIA labels for better screen reader support
    document.getElementById('export-results-btn').setAttribute('aria-label', 'Export election results');
    document.getElementById('manual-refresh-btn').setAttribute('aria-label', 'Refresh results manually');
    document.getElementById('accessibility-toggle').setAttribute('aria-label', 'Toggle high contrast mode');
    document.getElementById('tutorial-btn').setAttribute('aria-label', 'Show tutorial');
    
    // Set up reduced motion preference
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reducedMotion) {
        document.documentElement.classList.add('reduce-motion');
    }
}

function handleKeyboardShortcuts(e) {
    // Escape key closes modals
    if (e.key === 'Escape') {
        closeAllModals();
        logActivity('keyboard_escape', 'Closed modals with Escape key');
    }
    
    // Ctrl/Cmd + E for export
    if ((e.ctrlKey || e.metaKey) && e.key === 'e') {
        e.preventDefault();
        logActivity('keyboard_export', 'Export triggered via keyboard shortcut');
        handleExport();
    }
    
    // Ctrl/Cmd + R for refresh
    if ((e.ctrlKey || e.metaKey) && e.key === 'r') {
        e.preventDefault();
        logActivity('keyboard_refresh', 'Refresh triggered via keyboard shortcut');
        manualRefresh();
    }
    
    // Ctrl/Cmd + / for tutorial
    if ((e.ctrlKey || e.metaKey) && e.key === '/') {
        e.preventDefault();
        logActivity('keyboard_tutorial', 'Tutorial opened via keyboard shortcut');
        showTutorial();
    }
}

function showTutorial() {
    document.getElementById('tutorial-modal').classList.remove('hidden');
}

function hideTutorial() {
    document.getElementById('tutorial-modal').classList.add('hidden');
}

async function loadElections() {
    try {
        showLoading(true);
        // Log election loading attempt
        logActivity('elections_load_attempt', 'Attempting to load elections for admin');
        
        const response = await fetch(`${BASE_URL}/api/voting/elections.php`);
        const data = await response.json();
        
        if (!data.success) {
            // Log failure
            logActivity('elections_load_failed', 'Failed to load elections', {
                error: data.message || 'Unknown error'
            });
            throw new Error(data.message || 'Failed to load elections');
        }
        
        // Log success
        logActivity('elections_load_success', 'Elections loaded successfully', {
            count: data.elections.length
        });
        
        const electionSelect = document.getElementById('results-election-select');
        electionSelect.innerHTML = '<option value="">Select an election</option>';
        
        data.elections.forEach(election => {
            const option = document.createElement('option');
            option.value = election.id;
            const year = election.end_date ? election.end_date.substring(0, 4) : '';
            option.textContent = escapeHtml(election.title) + (year ? ' (' + year + ')' : '');
            option.dataset.endDate = election.end_date;
            option.dataset.status = election.status;
            electionSelect.appendChild(option);
        });
        
    } catch (error) {
        console.error('Error loading elections:', error);
        showError('Failed to load elections. Please try again later.');
    } finally {
        showLoading(false);
    }
}

async function loadElectionsForNonAdmins() {
    try {
        showLoading(true);
        logActivity('elections_load_attempt_non_admin', 'Non-admin attempting to load elections');
        
        const response = await fetch(`${BASE_URL}/api/voting/elections.php`);
        const data = await response.json();
        
        if (!data.success) throw new Error(data.message || 'Failed to load elections');
        
        // Filter to only show completed elections with 30-minute delay for non-admins
        const completedElections = data.elections.filter(election => {
            const endDate = new Date(election.end_date);
            const now = new Date();
            // Results available 30 minutes after election ends, or if status is 'ended'
            const resultsAvailableTime = new Date(endDate.getTime() + (30 * 60 * 1000));
            return resultsAvailableTime < now || election.status === 'ended';
        });
        
        if (completedElections.length === 0) {
            showNoElectionsMessage();
            logActivity('no_elections_available', 'No completed elections available for non-admin');
            return;
        }
        
        logActivity('elections_load_success_non_admin', 'Elections loaded for non-admin', {
            count: completedElections.length
        });
        
        const electionSelect = document.getElementById('results-election-select');
        electionSelect.innerHTML = '<option value="">Select an election</option>';
        
        completedElections.forEach(election => {
            const option = document.createElement('option');
            option.value = election.id;
            const year = election.end_date ? election.end_date.substring(0, 4) : '';
            option.textContent = escapeHtml(election.title) + (year ? ' (' + year + ')' : '');
            option.dataset.endDate = election.end_date;
            option.dataset.status = election.status;
            electionSelect.appendChild(option);
        });
        
        // Enable the dropdown for non-admins to view completed elections
        document.getElementById('admin-controls').classList.remove('hidden');
        document.getElementById('access-message').classList.add('hidden');
        
    } catch (error) {
        console.error('Error loading elections:', error);
        showError('Failed to load elections. Please try again later.');
    } finally {
        showLoading(false);
    }
}

// Add a function to calculate and display time until results are available
function calculateTimeUntilResults(endDate) {
    const resultsAvailableTime = new Date(endDate.getTime() + (30 * 60 * 1000));
    const now = new Date();
    const timeDiff = resultsAvailableTime - now;
    
    if (timeDiff <= 0) {
        return "Results available now";
    }
    
    const hours = Math.floor(timeDiff / (1000 * 60 * 60));
    const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
    
    return `Results available in ${hours}h ${minutes}m`;
}

// Update the UI to show when results will be available
function updateResultsAvailability(election) {
    if (!userIsAdmin && election.endDate) {
        const endDate = new Date(election.endDate);
        const now = new Date();
        const resultsAvailableTime = new Date(endDate.getTime() + (30 * 60 * 1000));
        
        if (resultsAvailableTime > now) {
            const timeUntilAvailable = calculateTimeUntilResults(endDate);
            // Show a message to students
            showNotification(`Results will be available in ${timeUntilAvailable}`, 'info');
            
            // Optionally disable the election selection or show a message
            document.getElementById('results-election-select').disabled = true;
        }
    }
}

function showNoElectionsMessage() {
    const resultsContainer = document.getElementById('results-container');
    resultsContainer.innerHTML = `
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
                <i class="fas fa-info-circle text-3xl text-blue-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-600">No completed elections available</h3>
            <p class="text-sm text-gray-500 mt-1">Results will be available here 30 minutes after election has ended.</p>
        </div>
    `;
}

function handleElectionChange() {
    const electionId = document.getElementById('results-election-select').value;
    const selectedOption = document.getElementById('results-election-select').selectedOptions[0];
    
    if (!electionId) {
        clearResults();
        return;
    }
    
    // Log election selection
    logActivity('election_selected', 'Selected election: ' + selectedOption.textContent, {
        election_id: electionId,
        election_title: selectedOption.textContent
    });
    
    currentElection = {
        id: electionId,
        endDate: selectedOption.dataset.endDate,
        status: selectedOption.dataset.status
    };
    
    // For non-admin users, check if they can view this election with 30-minute delay
    if (!userIsAdmin) {
        const endDate = new Date(currentElection.endDate);
        const now = new Date();
        const resultsAvailableTime = new Date(endDate.getTime() + (30 * 60 * 1000));
        
        if (resultsAvailableTime > now && currentElection.status !== 'ended') {
            const timeUntilAvailable = calculateTimeUntilResults(endDate);
            showError(`Results will be available ${timeUntilAvailable}`);
            clearResults();
            
            // Update the access message
            document.getElementById('access-message').innerHTML = `
                <p class="font-bold">Note</p>
                <p>Results will be available to all users 30 minutes after the election has ended.</p>
                <p>${timeUntilAvailable}</p>
            `;
            document.getElementById('access-message').classList.remove('hidden');
            
            return;
        }
    }
    
    initializeRealTimeResults();
    startTimeRemainingCounter();
}

function initializeRealTimeResults() {
    clearExistingResults();
    
    const electionId = document.getElementById('results-election-select').value;
    if (!electionId) return;
    
    // Load initial results
    loadResults();
    
    // Set up polling if auto-refresh is enabled and user is admin
    if (document.getElementById('auto-refresh-toggle').checked && userIsAdmin) {
        const interval = parseInt(document.getElementById('refresh-interval').value);
        startAutoRefresh(interval);
    }
}

// Modify the loadResults function to show notifications and log
async function loadResults(isManualRefresh = false) {
    const electionId = document.getElementById('results-election-select').value;
    if (!electionId) return;
    
    try {
        showLoading(true);
        
        // Log results loading attempt
        logActivity('results_load_attempt', 'Attempting to load results', {
            election_id: electionId,
            is_manual_refresh: isManualRefresh
        });
        
        const response = await fetch(`${BASE_URL}/api/voting/results.php?election_id=${encodeURIComponent(electionId)}`);
        
        // First check if the response is JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // If it's not JSON, get the text and see if it's an HTML error
            const text = await response.text();
            
            // Log error
            logActivity('results_load_failed', 'Non-JSON response received', {
                election_id: electionId,
                response_preview: text.substring(0, 100)
            });
            
            throw new Error(`Server returned non-JSON response: ${text.substring(0, 100)}...`);
        }
        
        // If it is JSON, parse it
        const data = await response.json();
        
        if (!data.success) {
            // Log failure
            logActivity('results_load_failed', 'API returned error', {
                election_id: electionId,
                error: data.message || 'Unknown error'
            });
            
            throw new Error(data.message || 'Failed to load results');
        }
        
        // Cache the results
        resultsCache.set(electionId, {
            data: data,
            timestamp: Date.now()
        });
        
        // Log success
        logActivity('results_load_success', 'Results loaded successfully', {
            election_id: electionId,
            position_count: data.positions ? data.positions.length : 0,
            total_votes: data.total_votes_cast || 0
        });
        
        processResults(data);
        
        // Show success notification
        if (isManualRefresh) {
            showRefreshNotification('Results refreshed successfully!');
        } else {
            // For auto-refresh, show a more subtle notification
            showRefreshNotification('Results updated successfully!', 'info');
        }
        
    } catch (error) {
        console.error('Error loading results:', error);
        showError(`Failed to load results: ${error.message}`);
        
        // Log error
        logActivity('results_load_error', 'Error loading results', {
            election_id: electionId,
            error: error.message
        });
        
        // Show error notification for refresh
        if (isManualRefresh) {
            showRefreshNotification('Failed to refresh results', 'error');
        }
    } finally {
        showLoading(false);
        updateLastUpdatedTime();
    }
}

function processResults(data) {
    if (data.positions && data.positions.length === 0) {
        showNoResults();
        return;
    }
    
    // Store all positions for filtering
    allPositions = data.positions || [];
    
    // Extract and populate categories
    extractCategories(allPositions);
    
    // Update overall progress
    updateOverallProgress(data);
    
    // Display results
    displayResults(allPositions);
    updateElectionSummary(data);
    
    // Update analytics if visible
    if (document.getElementById('analytics-dashboard').classList.contains('hidden') === false) {
        updateAnalyticsDashboard(data);
    }
}

function updateOverallProgress(data) {
    const totalVotes = data.positions ? data.positions.reduce((sum, pos) => sum + (pos.total_votes || 0), 0) : 0;
    const totalVoters = data.total_voters || 1;
    const turnoutPercentage = Math.min((totalVotes / totalVoters) * 100, 100);
    
    document.getElementById('overall-progress').classList.remove('hidden');
    document.getElementById('overall-turnout').textContent = `${turnoutPercentage.toFixed(1)}%`;
    document.getElementById('progress-bar').style.width = `${turnoutPercentage}%`;
}

function extractCategories(positions) {
    categories = [];
    const categorySet = new Set();
    
    positions.forEach(position => {
        // Safely check if category exists and is not undefined
        if (position.category && typeof position.category === 'string' && !categorySet.has(position.category)) {
            categorySet.add(position.category);
            categories.push(position.category);
        }
    });
    
    // Sort categories alphabetically
    categories.sort();
    
    // Update category filter dropdown
    const categoryFilter = document.getElementById('category-filter');
    categoryFilter.innerHTML = '<option value="all">All Categories</option>';
    
    categories.forEach(category => {
        const option = document.createElement('option');
        option.value = escapeHtml(category);
        option.textContent = escapeHtml(category);
        categoryFilter.appendChild(option);
    });
}

function filterResults() {
    const selectedCategory = document.getElementById('category-filter').value;
    const voteThreshold = parseInt(document.getElementById('vote-threshold').value) || 0;
    const winnersOnly = document.getElementById('winner-only').checked;
    
    let filteredPositions = allPositions;
    
    // Filter by category
    if (selectedCategory !== 'all') {
        filteredPositions = filteredPositions.filter(position => 
            position.category && position.category === selectedCategory
        );
    }
    
    // Filter by vote threshold and winners only
    filteredPositions = filteredPositions.map(position => {
        const maxVotes = Math.max(...position.candidates.map(c => c.votes || 0));
        
        const filteredCandidates = position.candidates.filter(candidate => {
            // Apply vote threshold
            if ((candidate.votes || 0) < voteThreshold) return false;
            
            // Apply winners only filter
            if (winnersOnly && candidate.votes !== maxVotes) return false;
            
            return true;
        });
        
        return {
            ...position,
            candidates: filteredCandidates
        };
    }).filter(position => position.candidates.length > 0); // Remove positions with no candidates after filtering
    
    displayResults(filteredPositions);
}

function displayResults(positions) {
    const viewMode = document.getElementById('view-mode').value;
    const chartType = document.getElementById('chart-type').value;
    
    // Check if we have positions to display
    if (!positions || positions.length === 0) {
        const noResultsHtml = `
            <div class="bg-white rounded-lg shadow p-8 text-center">
                <div class="inline-flex items-center justify-center w-16 h-16 bg-yellow-100 rounded-full mb-4">
                    <i class="fas fa-exclamation-triangle text-3xl text-yellow-400"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-600">No positions found</h3>
                <p class="text-sm text-gray-500 mt-1">No positions match the selected criteria.</p>
            </div>
        `;
        setInnerHTML(document.getElementById('results-container'), noResultsHtml);
        return;
    }
    
    // Check if election has ended
    const electionEnded = currentElection ? new Date() > new Date(currentElection.endDate) : false;
    
    // Limit to first 20 positions for performance, with pagination option
    const displayPositions = positions.slice(0, 20);
    
    // Create DOM elements safely instead of using innerHTML
    const resultsContainer = document.getElementById('results-container');
    resultsContainer.innerHTML = ''; // Clear previous results
    
    displayPositions.forEach(position => {
        const positionElement = createPositionElement(position, viewMode, chartType, electionEnded);
        resultsContainer.appendChild(positionElement);
    });
    
    // Add pagination if there are more than 20 positions
    if (positions.length > 20) {
        const paginationElement = createPaginationElement(positions.length);
        resultsContainer.appendChild(paginationElement);
        
        // Add event listener for load more button
        document.getElementById('load-more-positions').addEventListener('click', () => {
            logActivity('load_more_positions', 'User clicked load more positions');
            showNotification('Loading more positions...');
        });
    }
    
    // Create charts
    displayPositions.forEach(position => {
        createChart(position, chartType);
    });
}

function createPositionElement(position, viewMode, chartType, electionEnded) {
    const canvasId = `chart-${position.id}`;
    const showChart = viewMode !== 'table-only';
    const showTable = viewMode !== 'chart-only';
    
    // Check if this is a Yes/No position
    const isYesNoPosition = position.candidates.length === 1 && 
                           position.candidates[0].is_yes_no_candidate;
    
    // Find the maximum votes to determine winners (handle ties)
    const maxVotes = Math.max(...position.candidates.map(c => c.votes || 0));
    const winners = position.candidates.filter(c => c.votes === maxVotes);
    const isTie = winners.length > 1;
    
    // Sort candidates by votes (descending)
    const sortedCandidates = [...position.candidates].sort((a, b) => (b.votes || 0) - (a.votes || 0));
    
    // Create the main position container
    const positionDiv = document.createElement('div');
    positionDiv.className = 'bg-white rounded-lg shadow p-6 mb-6';
    
    if (isYesNoPosition) {
        positionDiv.classList.add('yes-no-position');
    }
    
    // Create header section
    const headerDiv = document.createElement('div');
    headerDiv.className = 'flex justify-between items-start mb-4';
    
    const titleDiv = document.createElement('div');
    const title = document.createElement('h3');
    title.className = 'text-xl font-bold text-gray-900';
    title.textContent = escapeHtml(position.title || position.name || 'Untitled Position');
    titleDiv.appendChild(title);
    
    if (position.category) {
        const categorySpan = document.createElement('span');
        categorySpan.className = 'inline-block bg-gray-100 text-gray-800 text-xs px-2 py-1 rounded-full mt-1';
        categorySpan.textContent = escapeHtml(position.category);
        titleDiv.appendChild(categorySpan);
    }
    
    // Add Yes/No badge if this is a Yes/No position
    if (isYesNoPosition) {
        const yesNoBadge = document.createElement('span');
        yesNoBadge.className = 'inline-block bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full ml-2';
        yesNoBadge.textContent = 'Yes/No Question';
        title.appendChild(yesNoBadge);
    }
    
    const votesDiv = document.createElement('div');
    votesDiv.className = 'text-sm text-gray-500';
    const votesSpan = document.createElement('span');
    votesSpan.className = 'flex items-center';
    votesSpan.innerHTML = '<i class="fas fa-vote-yea mr-1"></i> Total Votes: ' + (position.total_votes || 0);
    votesDiv.appendChild(votesSpan);
    
    headerDiv.appendChild(titleDiv);
    headerDiv.appendChild(votesDiv);
    positionDiv.appendChild(headerDiv);
    
    // Create content section
    const contentDiv = document.createElement('div');
    contentDiv.className = `grid grid-cols-1 ${showChart && showTable ? 'lg:grid-cols-2' : ''} gap-6`;
    
    if (showChart) {
        const chartContainer = document.createElement('div');
        chartContainer.className = isYesNoPosition ? 'yes-no-chart-container' : 'chart-container';
        chartContainer.style.position = 'relative';
        chartContainer.style.height = '350px';
                
        const canvas = document.createElement('canvas');
        canvas.id = canvasId;
        chartContainer.appendChild(canvas);
        
        contentDiv.appendChild(chartContainer);
    }
    
    if (showTable) {
        const tableContainer = document.createElement('div');
        tableContainer.className = 'overflow-x-auto';
        
        const table = document.createElement('table');
        table.className = 'min-w-full divide-y divide-gray-200';
        
        // Create table header
        const thead = document.createElement('thead');
        thead.className = 'bg-gray-50';
        const headerRow = document.createElement('tr');
        
        ['Rank', 'Candidate', 'Votes', 'Percentage', 'Status'].forEach(headerText => {
            const th = document.createElement('th');
            th.className = 'px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider';
            th.textContent = headerText;
            headerRow.appendChild(th);
        });
        
        thead.appendChild(headerRow);
        table.appendChild(thead);
        
        // Create table body
        const tbody = document.createElement('tbody');
        tbody.className = 'bg-white divide-y divide-gray-200';
        
        sortedCandidates.forEach((candidate, index) => {
            const row = createCandidateRow(candidate, index, maxVotes, winners.length, electionEnded);
            tbody.appendChild(row);
        });
        
        table.appendChild(tbody);
        tableContainer.appendChild(table);
        contentDiv.appendChild(tableContainer);
    }
    
    positionDiv.appendChild(contentDiv);
    return positionDiv;
}

function createCandidateRow(candidate, index, maxVotes, winnerCount, electionEnded) {
    const isYesNoCandidate = candidate.is_yes_no_candidate;
    const isWinner = candidate.votes === maxVotes;
    const votePercentage = candidate.percentage || 0;
    
    // Determine status based on election state and candidate position
    let statusClass = '';
    let statusText = '';
    let rowClass = '';
    
    // Function to convert number to ordinal (1st, 2nd, 3rd, etc.)
    function getOrdinalNumber(n) {
        const s = ["th", "st", "nd", "rd"];
        const v = n % 100;
        return n + (s[(v - 20) % 10] || s[v] || s[0]);
    }
    
    if (electionEnded) {
        // Election has ended - show final results
        if (isYesNoCandidate) {
            // Special handling for Yes/No candidates
            const yesVotes = candidate.yes_votes || 0;
            const noVotes = candidate.no_votes || 0;
            const totalYesNoVotes = yesVotes + noVotes;
            
            if (totalYesNoVotes > 0) {
                if (yesVotes > noVotes) {
                    statusClass = 'bg-green-100 text-green-800';
                    statusText = `Approved (${Math.round((yesVotes/totalYesNoVotes)*100)}% Yes)`;
                    rowClass = 'bg-green-50';
                } else if (noVotes > yesVotes) {
                    statusClass = 'bg-red-100 text-red-800';
                    statusText = `Rejected (${Math.round((noVotes/totalYesNoVotes)*100)}% No)`;
                    rowClass = 'bg-red-50';
                } else {
                    statusClass = 'bg-yellow-100 text-yellow-800';
                    statusText = 'Tied (50% Yes, 50% No)';
                    rowClass = 'bg-yellow-50';
                }
            } else {
                statusClass = 'bg-gray-100 text-gray-800';
                statusText = 'No votes cast';
            }
        } else if (isWinner) {
            statusClass = winnerCount > 1 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800';
            statusText = winnerCount > 1 ? 'Tied' : 'Winner';
            rowClass = 'bg-green-50';
        } else if (votePercentage > 0) {
            // Determine runner-up position
            const runnerUpPosition = index + 1 - winnerCount;
            let runnerUpText = '';
            
            if (runnerUpPosition === 1) {
                runnerUpText = 'Runner-up';
                statusClass = 'bg-blue-100 text-blue-800';
            } else if (runnerUpPosition === 2) {
                runnerUpText = '1st Runner-up';
                statusClass = 'bg-purple-100 text-purple-800';
            } else if (runnerUpPosition === 3) {
                runnerUpText = '2nd Runner-up';
                statusClass = 'bg-indigo-100 text-indigo-800';
            } else {
                runnerUpText = `${getOrdinalNumber(runnerUpPosition)} Runner-up`;
                statusClass = 'bg-gray-100 text-gray-800';
            }
            
            statusText = runnerUpText;
            rowClass = index < (3 + winnerCount) ? 'bg-blue-50' : '';
        } else {
            statusClass = 'bg-gray-100 text-gray-800';
            statusText = 'No votes';
        }
    } else {
        // Election is ongoing - show current standing
        if (isYesNoCandidate) {
            const yesVotes = candidate.yes_votes || 0;
            const noVotes = candidate.no_votes || 0;
            const totalYesNoVotes = yesVotes + noVotes;
            
            if (totalYesNoVotes > 0) {
                if (yesVotes > noVotes) {
                    statusClass = 'bg-green-100 text-green-800';
                    statusText = `Leading (${Math.round((yesVotes/totalYesNoVotes)*100)}% Yes)`;
                    rowClass = 'bg-green-50';
                } else if (noVotes > yesVotes) {
                    statusClass = 'bg-red-100 text-red-800';
                    statusText = `Leading (${Math.round((noVotes/totalYesNoVotes)*100)}% No)`;
                    rowClass = 'bg-red-50';
                } else {
                    statusClass = 'bg-yellow-100 text-yellow-800';
                    statusText = 'Tied (50% Yes, 50% No)';
                    rowClass = 'bg-yellow-50';
                }
            } else {
                statusClass = 'bg-gray-100 text-gray-800';
                statusText = 'No votes yet';
            }
        } else if (isWinner) {
            statusClass = winnerCount > 1 ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800';
            statusText = winnerCount > 1 ? 'Tied' : 'Winning';
            rowClass = 'bg-green-50';
        } else if (votePercentage > 0) {
            statusClass = 'bg-blue-100 text-blue-800';
            statusText = getOrdinalNumber(index + 1);
            rowClass = index < 3 ? 'bg-blue-50' : '';
        } else {
            statusClass = 'bg-gray-100 text-gray-800';
            statusText = 'No votes yet';
        }
    }
    
    const row = document.createElement('tr');
    row.className = `${rowClass} hover:bg-gray-50`;
    
    // Rank column
    const rankCell = document.createElement('td');
    rankCell.className = 'px-4 py-3 whitespace-nowrap text-sm font-bold text-center text-gray-900';
    rankCell.textContent = isYesNoCandidate ? '-' : getOrdinalNumber(index + 1);
    row.appendChild(rankCell);
    
    // Candidate column
    const candidateCell = document.createElement('td');
    candidateCell.className = 'px-4 py-3 whitespace-nowrap';
    
    const candidateDiv = document.createElement('div');
    candidateDiv.className = 'flex items-center';
    
    const imgDiv = document.createElement('div');
    imgDiv.className = 'flex-shrink-0 h-10 w-10';
    
    const img = document.createElement('img');
    img.className = 'h-10 w-10 rounded-full object-cover';
    img.src = candidate.photo_path || `${BASE_URL}/assets/images/default-user.jpg`;
    img.alt = escapeHtml(candidate.name || 'Candidate');
    img.onerror = function() {
        this.src = `${BASE_URL}/assets/images/default-user.jpg`;
    };
    
    imgDiv.appendChild(img);
    candidateDiv.appendChild(imgDiv);
    
    const infoDiv = document.createElement('div');
    infoDiv.className = 'ml-3';
    
    const nameDiv = document.createElement('div');
    nameDiv.className = 'text-sm font-medium text-gray-900';
    nameDiv.textContent = escapeHtml(candidate.name || 'Unknown Candidate');
    infoDiv.appendChild(nameDiv);
    
    if (candidate.department) {
        const deptDiv = document.createElement('div');
        deptDiv.className = 'text-xs text-gray-500';
        deptDiv.textContent = escapeHtml(candidate.department);
        infoDiv.appendChild(deptDiv);
    }
    
    // Add Yes/No indicator for Yes/No candidates
    if (isYesNoCandidate) {
        const yesNoBadge = document.createElement('span');
        yesNoBadge.className = 'ml-2 bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded-full';
        yesNoBadge.textContent = 'Yes/No Question';
        infoDiv.appendChild(yesNoBadge);
    }
    
    candidateDiv.appendChild(infoDiv);
    candidateCell.appendChild(candidateDiv);
    row.appendChild(candidateCell);
    
    // Votes column - show breakdown for Yes/No candidates
    const votesCell = document.createElement('td');
    votesCell.className = 'px-4 py-3 whitespace-nowrap text-sm font-bold text-gray-900';
    
    if (isYesNoCandidate) {
        const votesContainer = document.createElement('div');
        votesContainer.className = 'flex flex-col';
        
        const totalVotesDiv = document.createElement('div');
        totalVotesDiv.className = 'font-bold';
        totalVotesDiv.textContent = `Total: ${candidate.votes}`;
        votesContainer.appendChild(totalVotesDiv);
        
        const yesVotesDiv = document.createElement('div');
        yesVotesDiv.className = 'text-green-600 text-xs';
        yesVotesDiv.textContent = `Yes: ${candidate.yes_votes || 0}`;
        votesContainer.appendChild(yesVotesDiv);
        
        const noVotesDiv = document.createElement('div');
        noVotesDiv.className = 'text-red-600 text-xs';
        noVotesDiv.textContent = `No: ${candidate.no_votes || 0}`;
        votesContainer.appendChild(noVotesDiv);
        
        votesCell.appendChild(votesContainer);
    } else {
        votesCell.textContent = candidate.votes || 0;
    }
    row.appendChild(votesCell);
    
    // Percentage column - show breakdown for Yes/No candidates
    const percentageCell = document.createElement('td');
    percentageCell.className = 'px-4 py-3 whitespace-nowrap text-sm text-gray-900';
    
    const percentageDiv = document.createElement('div');
    percentageDiv.className = 'flex items-center';
    
    if (isYesNoCandidate) {
        const percentageContainer = document.createElement('div');
        percentageContainer.className = 'flex flex-col';
        
        // Show total percentage
        const totalPercentageDiv = document.createElement('div');
        totalPercentageDiv.className = 'font-bold text-sm';
        totalPercentageDiv.textContent = `Total: ${votePercentage}%`;
        percentageContainer.appendChild(totalPercentageDiv);
        
        // Show Yes percentage
        const yesPercentageDiv = document.createElement('div');
        yesPercentageDiv.className = 'text-green-600 text-xs';
        yesPercentageDiv.textContent = `Yes: ${candidate.yes_percentage || 0}%`;
        percentageContainer.appendChild(yesPercentageDiv);
        
        // Show No percentage
        const noPercentageDiv = document.createElement('div');
        noPercentageDiv.className = 'text-red-600 text-xs';
        noPercentageDiv.textContent = `No: ${candidate.no_percentage || 0}%`;
        percentageContainer.appendChild(noPercentageDiv);
        
        percentageDiv.appendChild(percentageContainer);
    } else {
        // Regular candidate display (unchanged)
        const percentageSpan = document.createElement('span');
        percentageSpan.className = 'font-bold';
        percentageSpan.textContent = `${votePercentage}%`;
        percentageDiv.appendChild(percentageSpan);
        
        const progressDiv = document.createElement('div');
        progressDiv.className = 'ml-2 w-16 bg-gray-200 rounded-full h-2';
        
        const progressBar = document.createElement('div');
        progressBar.className = 'bg-pink-600 h-2 rounded-full';
        progressBar.style.width = `${votePercentage}%`;
        
        progressDiv.appendChild(progressBar);
        percentageDiv.appendChild(progressDiv);
    }
    percentageCell.appendChild(percentageDiv);
    row.appendChild(percentageCell);
    
    // Status column
    const statusCell = document.createElement('td');
    statusCell.className = 'px-4 py-3 whitespace-nowrap';
    
    const statusSpan = document.createElement('span');
    statusSpan.className = `px-2 py-1 ${statusClass} text-xs rounded-full`;
    statusSpan.textContent = statusText;
    
    statusCell.appendChild(statusSpan);
    row.appendChild(statusCell);
    
    return row;
}

function createPaginationElement(totalPositions) {
    const paginationDiv = document.createElement('div');
    paginationDiv.className = 'bg-white rounded-lg shadow p-4 text-center';
    
    const text = document.createElement('p');
    text.className = 'text-sm text-gray-600';
    text.textContent = `Showing 20 of ${totalPositions} positions`;
    paginationDiv.appendChild(text);
    
    const button = document.createElement('button');
    button.id = 'load-more-positions';
    button.className = 'mt-2 bg-pink-900 hover:bg-pink-800 text-white px-4 py-2 rounded-lg text-sm';
    button.textContent = 'Load More Positions';
    
    paginationDiv.appendChild(button);
    return paginationDiv;
}

// Update the createChart function to handle horizontal bars
function createChart(position, chartType = 'bar') {
    const canvasId = `chart-${position.id}`;
    const canvasElement = document.getElementById(canvasId);
    if (!canvasElement) return;
    
    const ctx = canvasElement.getContext('2d');
    
    // Destroy previous chart instance if it exists
    if (chartInstances[canvasId]) {
        chartInstances[canvasId].destroy();
    }
    
    // Check if this is a Yes/No position (single candidate with is_yes_no_candidate flag)
    const isYesNoPosition = position.candidates.length === 1 && 
                           position.candidates[0].is_yes_no_candidate;
    
    if (isYesNoPosition) {
        // Special handling for Yes/No positions
        createYesNoChart(ctx, position, chartType);
        return;
    }
    
    // Regular multi-candidate position
    const candidateNames = position.candidates.map(c => escapeHtml(c.name || 'Unknown Candidate'));
    const votes = position.candidates.map(c => c.votes || 0);
    const percentages = position.candidates.map(c => c.percentage || 0);
    
    // Generate colors based on contrast mode
    const backgroundColors = highContrastMode ? 
        generateHighContrastColors(position.candidates.length) : 
        generateChartColors(position.candidates.length);
    
    // Determine the actual chart type and configuration
    let actualChartType = chartType;
    let indexAxis = 'x'; // Default for vertical bars
    
    if (chartType === 'barHorizontal') {
        actualChartType = 'bar';
        indexAxis = 'y';
    }
    
    // Common chart options
    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: actualChartType === 'pie' || actualChartType === 'doughnut',
                position: 'bottom'
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        const candidate = position.candidates[context.dataIndex];
                        return `${escapeHtml(candidate.name || 'Unknown Candidate')}: ${candidate.votes || 0} votes (${candidate.percentage || 0}%)`;
                    }
                }
            },
            datalabels: {
                color: highContrastMode ? '#000' : '#fff',
                font: {
                    weight: 'bold',
                    size: actualChartType === 'pie' || actualChartType === 'doughnut' ? 10 : 12
                },
                formatter: (value, context) => {
                    return actualChartType === 'pie' || actualChartType === 'doughnut' ? 
                        `${candidateNames[context.dataIndex]}: ${value}` : 
                        `${value} (${percentages[context.dataIndex]}%)`;
                },
                anchor: 'end',
                align: 'end'
            }
        }
    };
    
    // Chart-specific configurations
    let chartConfig = {
        type: actualChartType,
        data: {
            labels: candidateNames,
            datasets: [{
                label: 'Votes',
                data: votes,
                backgroundColor: backgroundColors,
                borderColor: backgroundColors.map(color => color.replace('0.8', '1')),
                borderWidth: 2,
                borderRadius: 6,
                barPercentage: 0.7,
            }]
        },
        options: commonOptions
    };
    
    // Adjust options based on chart type
    if (actualChartType === 'bar') {
        chartConfig.options.indexAxis = indexAxis;
        chartConfig.options.scales = {
            x: {
                beginAtZero: true,
                ticks: { precision: 0 },
                grid: { color: 'rgba(0, 0, 0, 0.1)' }
            },
            y: {
                grid: { color: 'rgba(0, 0, 0, 0.1)' }
            }
        };
    } else if (actualChartType === 'pie' || actualChartType === 'doughnut') {
        chartConfig.options.plugins.datalabels.anchor = 'center';
        chartConfig.options.plugins.datalabels.align = 'center';
    }
    
    chartInstances[canvasId] = new Chart(ctx, chartConfig);
}

function createYesNoChart(ctx, position, chartType) {
    const candidate = position.candidates[0];
    const yesVotes = candidate.yes_votes || 0;
    const noVotes = candidate.no_votes || 0;
    const totalYesNoVotes = yesVotes + noVotes;
    
    const forcedChartType = chartType === 'barHorizontal' ? 'doughnut' : 
                           (chartType === 'bar' ? 'pie' : chartType);
    
    // Use green for Yes, red for No
    const backgroundColors = highContrastMode ? 
        ['rgba(0, 128, 0, 0.8)', 'rgba(255, 0, 0, 0.8)'] : // High contrast colors
        ['rgba(75, 192, 192, 0.8)', 'rgba(255, 99, 132, 0.8)']; // Standard colors
    
    const chartConfig = {
        type: forcedChartType,
        data: {
            labels: ['Yes', 'No'],
            datasets: [{
                data: [yesVotes, noVotes],
                backgroundColor: backgroundColors,
                borderColor: backgroundColors.map(color => color.replace('0.8', '1')),
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const percentage = totalYesNoVotes > 0 ? 
                                Math.round((context.raw / totalYesNoVotes) * 100) : 0;
                            return `${context.label}: ${context.raw} votes (${percentage}%)`;
                        }
                    }
                },
                datalabels: {
                    color: highContrastMode ? '#000' : '#fff',
                    font: {
                        weight: 'bold',
                        size: 12
                    },
                    formatter: (value, context) => {
                        const percentage = totalYesNoVotes > 0 ? 
                            Math.round((value / totalYesNoVotes) * 100) : 0;
                        return `${context.chart.data.labels[context.dataIndex]}: ${percentage}%`;
                    },
                    anchor: 'center',
                    align: 'center'
                }
            }
        }
    };
    
    chartInstances[`chart-${position.id}`] = new Chart(ctx, chartConfig);
}

function generateChartColors(count) {
    const colors = [
        'rgba(255, 99, 132, 0.8)', 'rgba(54, 162, 235, 0.8)', 'rgba(255, 206, 86, 0.8)',
        'rgba(75, 192, 192, 0.8)', 'rgba(153, 102, 255, 0.8)', 'rgba(255, 159, 64, 0.8)',
        'rgba(199, 199, 199, 0.8)', 'rgba(83, 102, 255, 0.8)', 'rgba(40, 159, 64, 0.8)'
    ];
    
    // If we need more colors than available, generate random ones
    while (colors.length < count) {
        colors.push(`rgba(${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, 0.8)`);
    }
    
    return colors.slice(0, count);
}

function generateHighContrastColors(count) {
    const colors = [
        'rgba(255, 0, 0, 0.8)',      // Red
        'rgba(0, 0, 255, 0.8)',      // Blue
        'rgba(0, 128, 0, 0.8)',      // Green
        'rgba(255, 165, 0, 0.8)',    // Orange
        'rgba(128, 0, 128, 0.8)',    // Purple
        'rgba(255, 255, 0, 0.8)',    // Yellow
        'rgba(0, 255, 255, 0.8)',    // Cyan
        'rgba(255, 0, 255, 0.8)',    // Magenta
        'rgba(128, 128, 128, 0.8)'   // Gray
    ];
    
    while (colors.length < count) {
        colors.push(`rgba(${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, ${Math.floor(Math.random() * 255)}, 0.8)`);
    }
    
    return colors.slice(0, count);
}

function updateChartType() {
    const chartType = document.getElementById('chart-type').value;
    
    // Log chart type change
    logActivity('chart_type_change', 'Changed chart type to: ' + chartType);
    
    allPositions.slice(0, 20).forEach(position => {
        createChart(position, chartType);
    });
}

function updateElectionSummary(data) {
    const summaryElement = document.getElementById('election-summary');
    summaryElement.classList.remove('hidden');
    
    // Use the values calculated by the server instead of recalculating
    const totalVoters = data.total_voters || 0;
    const totalVotes = data.total_votes_cast || 0;
    const turnoutRate = data.turnout_rate || 0;
    
    // Update summary cards
    document.getElementById('total-voters-count').textContent = totalVoters;
    document.getElementById('votes-cast-count').textContent = totalVotes;
    document.getElementById('turnout-rate').textContent = `${turnoutRate.toFixed(1)}%`;
    
    // Update the main total voters display
    document.getElementById('total-voters').textContent = `${totalVoters} total voters`;
}

function updateAnalyticsDashboard(data) {
    // Destroy existing charts
    Object.values(analyticsCharts).forEach(chart => {
        if (chart && typeof chart.destroy === 'function') {
            chart.destroy();
        }
    });
    
    // Get canvas contexts
    const trendCtx = document.getElementById('trend-chart').getContext('2d');
    const categoryCtx = document.getElementById('category-chart').getContext('2d');
    const timeCtx = document.getElementById('time-chart').getContext('2d');
    const departmentCtx = document.getElementById('department-chart').getContext('2d');
    
    // Create charts with actual data
    if (data.analytics) {
        analyticsCharts.trend = createTrendChart(trendCtx, data.analytics.hourly_trend);
        analyticsCharts.category = createCategoryChart(categoryCtx, data.analytics.category_comparison);
        analyticsCharts.time = createTimeDistributionChart(timeCtx, data.analytics.time_distribution);
        analyticsCharts.department = createDepartmentChart(departmentCtx, data.analytics.department_participation);
    }
}

function createTrendChart(ctx, trendData) {
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: trendData.labels,
            datasets: [{
                label: 'Votes per hour',
                data: trendData.data,
                fill: true,
                backgroundColor: 'rgba(75, 192, 192, 0.2)',
                borderColor: 'rgb(75, 192, 192)',
                tension: 0.4,
                pointBackgroundColor: 'rgb(75, 192, 192)',
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Voting Activity Over Time'
                },
                legend: {
                    position: 'top',
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `Votes: ${context.raw}`;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Votes'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Time of Day'
                    }
                }
            }
        }
    });
}

function createCategoryChart(ctx, categoryData) {
    const backgroundColors = [
        'rgba(255, 99, 132, 0.8)',
        'rgba(54, 162, 235, 0.8)',
        'rgba(255, 206, 86, 0.8)',
        'rgba(75, 192, 192, 0.8)',
        'rgba(153, 102, 255, 0.8)',
        'rgba(255, 159, 64, 0.8)',
        'rgba(199, 199, 199, 0.8)',
        'rgba(83, 102, 255, 0.8)',
        'rgba(40, 159, 64, 0.8)'
    ];
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: categoryData.labels,
            datasets: [{
                label: 'Votes by Category',
                data: categoryData.data,
                backgroundColor: backgroundColors.slice(0, categoryData.labels.length),
                borderColor: backgroundColors.map(color => color.replace('0.8', '1')).slice(0, categoryData.labels.length),
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Votes by Position Category'
                },
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Votes'
                    }
                }
            }
        }
    });
}

function createTimeDistributionChart(ctx, timeData) {
    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: timeData.labels,
            datasets: [{
                data: timeData.data,
                backgroundColor: [
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 206, 86, 0.8)'
                ],
                borderColor: [
                    'rgb(255, 99, 132)',
                    'rgb(54, 162, 235)',
                    'rgb(255, 206, 86)'
                ],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Voting Time Distribution'
                },
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((context.raw / total) * 100);
                            return `${context.label}: ${context.raw} votes (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

function createDepartmentChart(ctx, departmentData) {
    return new Chart(ctx, {
        type: 'pie',
        data: {
            labels: departmentData.labels,
            datasets: [{
                data: departmentData.data,
                backgroundColor: [
                    'rgba(255, 99, 132, 0.8)',
                    'rgba(54, 162, 235, 0.8)',
                    'rgba(255, 206, 86, 0.8)',
                    'rgba(75, 192, 192, 0.8)',
                    'rgba(153, 102, 255, 0.8)',
                    'rgba(255, 159, 64, 0.8)'
                ],
                borderColor: [
                    'rgb(255, 99, 132)',
                    'rgb(54, 162, 235)',
                    'rgb(255, 206, 86)',
                    'rgb(75, 192, 192)',
                    'rgb(153, 102, 255)',
                    'rgb(255, 159, 64)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                title: {
                    display: true,
                    text: 'Voter Participation by Department'
                },
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((context.raw / total) * 100);
                            return `${context.label}: ${context.raw} voters (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

function toggleAnalytics() {
    const analyticsDashboard = document.getElementById('analytics-dashboard');
    const analyticsToggle = document.getElementById('analytics-toggle');
    
    const isShowing = analyticsDashboard.classList.contains('hidden');
    
    if (isShowing) {
        analyticsDashboard.classList.remove('hidden');
        analyticsToggle.innerHTML = '<i class="fas fa-chart-line mr-2"></i> Hide Analytics Dashboard';
        
        // Load analytics data if we have election data
        if (document.getElementById('results-election-select').value) {
            // This would load real analytics data
            // For now, we'll just show placeholder charts
            updateAnalyticsDashboard();
        }
    } else {
        analyticsDashboard.classList.add('hidden');
        analyticsToggle.innerHTML = '<i class="fas fa-chart-line mr-2"></i> Show Analytics Dashboard';
    }
}

function toggleAccessibility() {
    highContrastMode = !highContrastMode;
    
    const accessibilityToggle = document.getElementById('accessibility-toggle');
    
    if (highContrastMode) {
        document.documentElement.classList.add('high-contrast');
        accessibilityToggle.classList.add('bg-yellow-400');
        accessibilityToggle.title = 'Standard Contrast Mode';
        showNotification('High contrast mode enabled');
    } else {
        document.documentElement.classList.remove('high-contrast');
        accessibilityToggle.classList.remove('bg-yellow-400');
        accessibilityToggle.title = 'High Contrast Mode';
        showNotification('High contrast mode disabled');
    }
    
    // Recreate charts with appropriate colors
    if (document.getElementById('results-election-select').value) {
        const chartType = document.getElementById('chart-type').value;
        allPositions.slice(0, 20).forEach(position => {
            createChart(position, chartType);
        });
    }
}

// Add this function to create notification system
function createNotificationSystem() {
    // Create notification container if it doesn't exist
    if (!document.getElementById('refresh-notifications')) {
        const notificationContainer = document.createElement('div');
        notificationContainer.id = 'refresh-notifications';
        notificationContainer.className = 'fixed top-4 right-4 z-50 space-y-2';
        document.body.appendChild(notificationContainer);
    }
}

// Add this function to show refresh notifications
function showRefreshNotification(message, type = 'success') {
    createNotificationSystem();
    
    const notification = document.createElement('div');
    notification.className = `px-4 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full opacity-0 ${
        type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 
        type === 'error' ? 'bg-red-100 border border-red-400 text-red-700' :
        'bg-blue-100 border border-blue-400 text-blue-700'
    }`;
    
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'} mr-2"></i>
            <span>${escapeHtml(message)}</span>
            <button class="ml-4 hover:opacity-70" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.getElementById('refresh-notifications').appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full', 'opacity-0');
        notification.classList.add('translate-x-0', 'opacity-100');
    }, 10);
    
    // Remove after delay
    setTimeout(() => {
        notification.classList.add('translate-x-full', 'opacity-0');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, 3000);
}

function startTimeRemainingCounter() {
    if (!currentElection?.endDate) return;
    
    const updateTime = () => {
        const now = new Date();
        const endDate = new Date(currentElection.endDate);
        const diff = endDate - now;
        
        if (diff <= 0) {
            document.getElementById('time-remaining').textContent = 'Election Ended';
            return;
        }
        
        const hours = Math.floor(diff / (1000 * 60 * 60));
        const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((diff % (1000 * 60)) / 1000);
        
        document.getElementById('time-remaining').textContent = 
            `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    };
    
    updateTime();
    setInterval(updateTime, 1000);
}

function startAutoRefresh(interval) {
    clearExistingInterval();
    realTimeResults = setInterval(loadResults, interval);
}

function clearExistingInterval() {
    if (realTimeResults) {
        clearInterval(realTimeResults);
        realTimeResults = null;
    }
}

function toggleAutoRefresh() {
    const isEnabled = document.getElementById('auto-refresh-toggle').checked;
    
    const interval = parseInt(document.getElementById('refresh-interval').value);
    
    if (isEnabled && document.getElementById('results-election-select').value) {
        startAutoRefresh(interval);
    } else {
        clearExistingInterval();
    }
}

function updateRefreshInterval() {
    if (document.getElementById('auto-refresh-toggle').checked) {
        const interval = parseInt(document.getElementById('refresh-interval').value);
        startAutoRefresh(interval);
    }
}

function updateViewMode() {
    const viewMode = document.getElementById('view-mode').value;
    
    if (document.getElementById('results-election-select').value) {
        loadResults();
    }
}

function manualRefresh() {
    if (document.getElementById('results-election-select').value) {
        // Clear cache to force fresh data
        resultsCache.delete(document.getElementById('results-election-select').value);
        
        // Set a flag to indicate this is a manual refresh
        const isManualRefresh = true;
        loadResults(isManualRefresh);
    }
}

function handleExport() {
    if (!userIsAdmin) {
        // Log unauthorized export attempt
        logActivity('export_unauthorized_attempt', 'Unauthorized export attempt by non-admin');
        showError('Exporting results is only available to administrators');
        return;
    }
    
    const electionId = document.getElementById('results-election-select').value;
    if (!electionId) {
        showError('Please select an election first');
        return;
    }
    
    const format = document.getElementById('export-format').value;
    
    // Log export attempt
    logActivity('export_attempt', 'Export attempt', {
        election_id: electionId,
        format: format
    });
    
    exportResults(format);
}

function exportResults(format = 'csv') {
    const electionId = document.getElementById('results-election-select').value;
    if (!electionId) {
        showError('Please select an election first');
        return;
    }

    const electionSelect = document.getElementById('results-election-select');
    const selectedOption = electionSelect.options[electionSelect.selectedIndex];
    const electionName = selectedOption.textContent.replace(/[^a-z0-9]/gi, '_').toLowerCase();
    
    // Show loading state
    const exportBtn = document.getElementById('export-results-btn');
    const originalText = exportBtn.innerHTML;
    exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Exporting...';
    exportBtn.disabled = true;

    // For PDF, use form submission to avoid CORS issues with blob handling
    if (format === 'pdf') {        
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = `${BASE_URL}/api/voting/results.php`;
        form.target = '_blank';
        
        const electionIdInput = document.createElement('input');
        electionIdInput.type = 'hidden';
        electionIdInput.name = 'election_id';
        electionIdInput.value = electionId;
        form.appendChild(electionIdInput);
        
        const exportInput = document.createElement('input');
        exportInput.type = 'hidden';
        exportInput.name = 'export';
        exportInput.value = '1';
        form.appendChild(exportInput);
        
        const formatInput = document.createElement('input');
        formatInput.type = 'hidden';
        formatInput.name = 'format';
        formatInput.value = format;
        form.appendChild(formatInput);
        
        const downloadInput = document.createElement('input');
        downloadInput.type = 'hidden';
        downloadInput.name = 'download';
        downloadInput.value = '1';
        form.appendChild(downloadInput);
        
        // Add additional options if they exist in the modal
        const exportSummary = document.getElementById('export-summary');
        const exportCandidates = document.getElementById('export-candidates');
        const exportCharts = document.getElementById('export-charts');
        const exportStartDate = document.getElementById('export-start-date');
        const exportEndDate = document.getElementById('export-end-date');
        
        if (exportSummary) {
            const summaryInput = document.createElement('input');
            summaryInput.type = 'hidden';
            summaryInput.name = 'summary';
            summaryInput.value = exportSummary.checked ? '1' : '0';
            form.appendChild(summaryInput);
        }
        
        if (exportCandidates) {
            const candidatesInput = document.createElement('input');
            candidatesInput.type = 'hidden';
            candidatesInput.name = 'candidates';
            candidatesInput.value = exportCandidates.checked ? '1' : '0';
            form.appendChild(candidatesInput);
        }
        
        if (exportCharts) {
            const chartsInput = document.createElement('input');
            chartsInput.type = 'hidden';
            chartsInput.name = 'charts';
            chartsInput.value = exportCharts.checked ? '1' : '0';
            form.appendChild(chartsInput);
        }
        
        if (exportStartDate && exportStartDate.value) {
            const startDateInput = document.createElement('input');
            startDateInput.type = 'hidden';
            startDateInput.name = 'start_date';
            startDateInput.value = exportStartDate.value;
            form.appendChild(startDateInput);
        }
        
        if (exportEndDate && exportEndDate.value) {
            const endDateInput = document.createElement('input');
            endDateInput.type = 'hidden';
            endDateInput.name = 'end_date';
            endDateInput.value = exportEndDate.value;
            form.appendChild(endDateInput);
        }
        
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
        
        // Restore button state after a short delay
        setTimeout(() => {
            exportBtn.innerHTML = originalText;
            exportBtn.disabled = false;
            showExportSuccess();
        }, 3000);

        // Log export completion after successful export
        setTimeout(() => {
            logActivity('export_success', 'PDF export successful', {
                election_id: electionId,
                format: format,
                file_name: `election_${electionName}_results.pdf`
            });
        }, 3000);
        
    } else {
        // For CSV and Excel, use the fetch approach with GET parameters
        let exportUrl = `${BASE_URL}/api/voting/results.php?election_id=${encodeURIComponent(electionId)}&export=1&format=${format}&download=1`;
        
        // Add additional options if they exist
        const exportSummary = document.getElementById('export-summary');
        const exportCandidates = document.getElementById('export-candidates');
        const exportCharts = document.getElementById('export-charts');
        const exportStartDate = document.getElementById('export-start-date');
        const exportEndDate = document.getElementById('export-end-date');
        
        if (exportSummary) {
            exportUrl += `&summary=${exportSummary.checked ? '1' : '0'}`;
        }
        
        if (exportCandidates) {
            exportUrl += `&candidates=${exportCandidates.checked ? '1' : '0'}`;
        }
        
        if (exportCharts) {
            exportUrl += `&charts=${exportCharts.checked ? '1' : '0'}`;
        }
        
        if (exportStartDate && exportStartDate.value) {
            exportUrl += `&start_date=${encodeURIComponent(exportStartDate.value)}`;
        }
        
        if (exportEndDate && exportEndDate.value) {
            exportUrl += `&end_date=${encodeURIComponent(exportEndDate.value)}`;
        }
        
        fetch(exportUrl)
            .then(response => {
                if (!response.ok) {
                    // Log export failure
                    logActivity('export_failed', 'Export failed', {
                        election_id: electionId,
                        format: format,
                        error: 'Server error'
                    });
                    throw new Error('Export failed');
                }
                return response.blob();
            })
            .then(blob => {
                // Create download link
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.style.display = 'none';
                a.href = url;
                a.download = `election_${electionName}_results.${format}`;
                document.body.appendChild(a);
                a.click();
                
                // Clean up
                window.URL.revokeObjectURL(url);
                document.body.removeChild(a);
                
                // Log export success
                logActivity('export_success', 'Export successful', {
                    election_id: electionId,
                    format: format,
                    file_name: `election_${electionName}_results.${format}`
                });
                
                // Show success message
                showExportSuccess();
            })
            .catch(error => {
                console.error('Export error:', error);
                
                // Log export failure
                logActivity('export_failed', 'Export failed', {
                    election_id: electionId,
                    format: format,
                    error: error.message
                });
                
                showError('Failed to export results. Please try again.');
            })
            .finally(() => {
                // Restore button state
                exportBtn.innerHTML = originalText;
                exportBtn.disabled = false;
            });
    }
}

function showExportOptions() {
    const modalHtml = `
        <div class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-lg p-6 w-96">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Export Options</h3>
                
                <div class="space-y-4">
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" id="export-summary" checked class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                            <span class="ml-2 text-sm text-gray-600">Include election summary</span>
                        </label>
                    </div>
                    
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" id="export-candidates" checked class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                            <span class="ml-2 text-sm text-gray-600">Include candidate details</span>
                        </label>
                    </div>
                    
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" id="export-charts" class="rounded border-gray-300 text-pink-600 focus:ring-pink-500">
                            <span class="ml-2 text-sm text-gray-600">Include charts (PDF only)</span>
                        </label>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Date Range</label>
                        <div class="flex space-x-2">
                            <input type="date" id="export-start-date" class="flex-1 border border-gray-300 rounded-md px-3 py-2">
                            <input type="date" id="export-end-date" class="flex-1 border border-gray-300 rounded-md px-3 py-2">
                        </div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button onclick="closeExportModal()" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
                    <button onclick="processExport()" class="px-4 py-2 bg-pink-600 text-white rounded-md text-sm hover:bg-pink-700">Export</button>
                </div>
            </div>
        </div>
    `;
    
    const modal = document.createElement('div');
    modal.innerHTML = modalHtml;
    modal.id = 'export-modal';
    document.body.appendChild(modal);
}

function closeExportModal() {
    const modal = document.getElementById('export-modal');
    if (modal) {
        modal.remove();
    }
}

function closeAllModals() {
    const modals = document.querySelectorAll('[id$="-modal"]');
    modals.forEach(modal => modal.remove());
}

function processExport() {
    // Get options from modal
    const includeSummary = document.getElementById('export-summary').checked;
    const includeCandidates = document.getElementById('export-candidates').checked;
    const includeCharts = document.getElementById('export-charts').checked;
    const startDate = document.getElementById('export-start-date').value;
    const endDate = document.getElementById('export-end-date').value;
    
    // Close modal
    closeExportModal();
    
    // Build export URL with options
    const electionId = document.getElementById('results-election-select').value;
    const format = document.getElementById('export-format').value;
    
    let exportUrl = `${BASE_URL}/api/voting/results.php?election_id=${encodeURIComponent(electionId)}&export=1&format=${format}`;
    exportUrl += `&summary=${includeSummary ? 1 : 0}`;
    exportUrl += `&candidates=${includeCandidates ? 1 : 0}`;
    exportUrl += `&charts=${includeCharts ? 1 : 0}`;
    
    if (startDate) exportUrl += `&start_date=${encodeURIComponent(startDate)}`;
    if (endDate) exportUrl += `&end_date=${encodeURIComponent(endDate)}`;
    
    // Trigger download
    window.open(exportUrl, '_blank');
}

function updateLastUpdatedTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString();
    document.getElementById('last-update').textContent = `Last update: ${timeString}`;
}

function showLoading(show) {
    document.getElementById('loading-overlay').classList.toggle('hidden', !show);
}

function showError(message) {
    const errorDiv = document.createElement('div');
    errorDiv.className = 'fixed top-4 right-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg shadow-lg z-50';
    errorDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle mr-3"></i>
            <span>${escapeHtml(message)}</span>
            <button class="ml-4 text-red-700 hover:text-red-900" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(errorDiv);
    setTimeout(() => {
        if (errorDiv.parentElement) {
            errorDiv.remove();
        }
    }, 5000);
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    
    // Set different styles based on notification type
    let bgColor, borderColor, textColor, icon;
    
    switch(type) {
        case 'success':
            bgColor = 'bg-green-100';
            borderColor = 'border-green-400';
            textColor = 'text-green-700';
            icon = 'fa-check-circle';
            break;
        case 'warning':
            bgColor = 'bg-yellow-100';
            borderColor = 'border-yellow-400';
            textColor = 'text-yellow-700';
            icon = 'fa-exclamation-triangle';
            break;
        case 'error':
            bgColor = 'bg-red-100';
            borderColor = 'border-red-400';
            textColor = 'text-red-700';
            icon = 'fa-exclamation-circle';
            break;
        default: // info
            bgColor = 'bg-blue-100';
            borderColor = 'border-blue-400';
            textColor = 'text-blue-700';
            icon = 'fa-info-circle';
    }
    
    notification.className = `fixed bottom-4 right-4 ${bgColor} border ${borderColor} ${textColor} px-4 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-y-8 opacity-0`;
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${icon} mr-2"></i>
            <span>${escapeHtml(message)}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-y-8', 'opacity-0');
        notification.classList.add('translate-y-0', 'opacity-100');
    }, 10);
    
    // Remove after delay
    setTimeout(() => {
        notification.classList.add('translate-y-8', 'opacity-0');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, 3000);
}

function showExportSuccess() {
    const successDiv = document.createElement('div');
    successDiv.className = 'fixed top-4 right-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg shadow-lg z-50';
    successDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-3"></i>
            <span>Results exported successfully!</span>
            <button class="ml-4 text-green-700 hover:text-green-900" onclick="this.parentElement.parentElement.remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(successDiv);
    setTimeout(() => {
        if (successDiv.parentElement) {
            successDiv.remove();
        }
    }, 5000);
}

function clearResults() {
    const defaultHtml = `
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
                <i class="fas fa-chart-bar text-3xl text-gray-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-600">Select an election to view results</h3>
        </div>
    `;
    setInnerHTML(document.getElementById('results-container'), defaultHtml);
    document.getElementById('election-summary').classList.add('hidden');
    document.getElementById('overall-progress').classList.add('hidden');
    document.getElementById('analytics-dashboard').classList.add('hidden');
    document.getElementById('analytics-toggle').innerHTML = '<i class="fas fa-chart-line mr-2"></i> Show Analytics Dashboard';
    document.getElementById('category-filter').innerHTML = '<option value="all">All Categories</option>';
    clearExistingInterval();
}

function clearExistingResults() {
    // Destroy all chart instances
    Object.values(chartInstances).forEach(chart => {
        if (chart && typeof chart.destroy === 'function') {
            chart.destroy();
        }
    });
    chartInstances = {};
    
    // Destroy analytics charts
    Object.values(analyticsCharts).forEach(chart => {
        if (chart && typeof chart.destroy === 'function') {
            chart.destroy();
        }
    });
    analyticsCharts = {};
}

function showNoResults() {
    const noResultsHtml = `
        <div class="bg-white rounded-lg shadow p-8 text-center">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-yellow-100 rounded-full mb-4">
                <i class="fas fa-exclamation-triangle text-3xl text-yellow-400"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-600">No results available</h3>
            <p class="text-sm text-gray-500 mt-1">There are no results to display for this election yet.</p>
        </div>
    `;
    setInnerHTML(document.getElementById('results-container'), noResultsHtml);
}

// Function to disable right-click context menu
function disableRightClick(event) {
    if (!userIsAdmin) {
        event.preventDefault();
        return false;
    }
}

// Function to disable keyboard shortcuts for screenshots
function disableScreenshotShortcuts(event) {
    if (!userIsAdmin) {
        // Disable Print Screen key
        if (event.keyCode === 44) { // Print Screen key
            event.preventDefault();
            showNotification('Screenshots are disabled for results viewing', 'warning');
            return false;
        }
        
        // Disable Alt+Print Screen (Windows)
        if (event.altKey && event.keyCode === 44) {
            event.preventDefault();
            showNotification('Screenshots are disabled for results viewing', 'warning');
            return false;
        }
        
        // Disable Cmd+Shift+3/4 (Mac)
        if (event.metaKey && event.shiftKey && (event.keyCode === 51 || event.keyCode === 52)) {
            event.preventDefault();
            showNotification('Screenshots are disabled for results viewing', 'warning');
            return false;
        }
        
        // Disable Windows+Shift+S (Windows 10+ snipping tool)
        if (event.key === 's' && event.shiftKey && event.getModifierState('Win')) {
            event.preventDefault();
            showNotification('Screenshots are disabled for results viewing', 'warning');
            return false;
        }
    }
}

// Function to disable text selection and dragging
function disableSelectionAndDrag() {
    if (!userIsAdmin) {
        document.addEventListener('selectstart', function(e) {
            e.preventDefault();
            return false;
        });
        
        document.addEventListener('dragstart', function(e) {
            e.preventDefault();
            return false;
        });
        
        // Add CSS to disable text selection
        const style = document.createElement('style');
        style.innerHTML = `
            .no-select {
                -webkit-user-select: none;
                -moz-user-select: none;
                -ms-user-select: none;
                user-select: none;
            }
            .no-drag {
                -webkit-user-drag: none;
                -moz-user-drag: none;
                -ms-user-drag: none;
                user-drag: none;
            }
        `;
        document.head.appendChild(style);
        
        // Apply the classes to the body and relevant elements
        document.body.classList.add('no-select', 'no-drag');
    }
}

// Function to hide export options for non-admins
function hideExportOptions() {
    if (!userIsAdmin) {
        // Hide export button and options
        const exportBtn = document.getElementById('export-results-btn');
        const exportOptionsBtn = document.getElementById('export-options-btn');
        const exportFormat = document.getElementById('export-format');
        
        if (exportBtn) exportBtn.style.display = 'none';
        if (exportOptionsBtn) exportOptionsBtn.style.display = 'none';
        if (exportFormat) exportFormat.style.display = 'none';
        
        // Also hide any other export-related elements
        const exportRelated = document.querySelectorAll('[id*="export"], [class*="export"]');
        exportRelated.forEach(el => {
            if (el.id !== 'export-results-btn' && el.id !== 'export-options-btn' && el.id !== 'export-format') {
                el.style.display = 'none';
            }
        });
    }
}

// Function to override print functionality
function disablePrinting() {
    if (!userIsAdmin) {
        // Override the print function
        window.print = function() {
            showNotification('Printing results is not allowed', 'warning');
            return false;
        };
        
        // Disable Ctrl/Cmd+P
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.keyCode === 80) {
                e.preventDefault();
                showNotification('Printing results is not allowed', 'warning');
                return false;
            }
        });
    }
}

// Function to prevent developer tools (F12, Ctrl+Shift+I, etc.)
function preventDeveloperTools() {
    if (!userIsAdmin) {
        document.addEventListener('keydown', function(e) {
            // Disable F12
            if (e.keyCode === 123) { // F12 key
                e.preventDefault();
                return false;
            }
            
            // Disable Ctrl+Shift+I
            if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
                e.preventDefault();
                return false;
            }
            
            // Disable Ctrl+Shift+J
            if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
                e.preventDefault();
                return false;
            }
            
            // Disable Ctrl+U (view source)
            if (e.ctrlKey && e.keyCode === 85) {
                e.preventDefault();
                return false;
            }
        });
    }
}

// Function to add watermark to the page for non-admins
function addWatermark() {
    if (!userIsAdmin) {
        const watermark = document.createElement('div');
        watermark.innerHTML = 'Election Results - Confidential - ' + new Date().toLocaleDateString();
        watermark.style.position = 'fixed';
        watermark.style.bottom = '10px';
        watermark.style.right = '10px';
        watermark.style.backgroundColor = 'rgba(255, 255, 255, 0.7)';
        watermark.style.padding = '5px';
        watermark.style.borderRadius = '3px';
        watermark.style.fontSize = '12px';
        watermark.style.zIndex = '9999';
        watermark.style.pointerEvents = 'none';
        document.body.appendChild(watermark);
    }
}

// Utility functions
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

function getContrastColor(hexColor) {
    // Convert hex to RGB
    const r = parseInt(hexColor.substr(1, 2), 16);
    const g = parseInt(hexColor.substr(3, 2), 16);
    const b = parseInt(hexColor.substr(5, 2), 16);
    
    // Calculate luminance
    const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    
    // Return black or white depending on luminance
    return luminance > 0.5 ? '#000000' : '#FFFFFF';
}
</script>

<style>
.switch {
    position: relative;
    display: inline-block;
    width: 50px;
    height: 24px;
}

.switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 24px;
}

.slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #ec4899;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.chart-container {
    transition: all 0.3s ease;
}

.bg-white.rounded-lg.shadow {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.bg-white.rounded-lg.shadow:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
}

/* Animation for live updates */
@keyframes highlightUpdate {
    0% { background-color: transparent; }
    50% { background-color: rgba(34, 197, 94, 0.1); }
    100% { background-color: transparent; }
}

.updated-row {
    animation: highlightUpdate 2s ease;
}

/* Custom styles for form elements */
.input-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    margin-bottom: 0.25rem;
}

.input-field {
    display: block;
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #D1D5DB;
    border-radius: 0.375rem;
    font-size: 0.875rem;
}

.card {
    background: white;
    border-radius: 0.5rem;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
}

/* High contrast mode */
.high-contrast {
    --bg-color: #000;
    --text-color: #fff;
    --border-color: #fff;
    --primary-color: #ffff00;
}

.high-contrast body {
    background-color: var(--bg-color);
    color: var(--text-color);
}

.high-contrast .bg-white {
    background-color: var(--bg-color) !important;
    color: var(--text-color) !important;
    border: 1px solid var(--border-color);
}

.high-contrast .text-gray-900,
.high-contrast .text-gray-700,
.high-contrast .text-gray-600,
.high-contrast .text-gray-500 {
    color: var(--text-color) !important;
}

.high-contrast .border-gray-300 {
    border-color: var(--border-color) !important;
}

.high-contrast .bg-pink-100 {
    background-color: var(--primary-color) !important;
    color: #000 !important;
}

.high-contrast .bg-pink-600 {
    background-color: var(--primary-color) !important;
}

.high-contrast .bg-green-100 {
    background-color: #00ff00 !important;
    color: #000 !important;
}

.high-contrast .bg-blue-100 {
    background-color: #0000ff !important;
    color: #fff !important;
}

.high-contrast .bg-purple-100 {
    background-color: #ff00ff !important;
    color: #000 !important;
}

.high-contrast .bg-orange-100 {
    background-color: #ffa500 !important;
    color: #000 !important;
}

.high-contrast .bg-yellow-100 {
    background-color: #ffff00 !important;
    color: #000 !important;
}

.high-contrast .bg-gray-100 {
    background-color: #333 !important;
    color: #fff !important;
}

.high-contrast .shadow {
    box-shadow: 0 4px 6px -1px rgba(255, 255, 255, 0.1), 0 2px 4px -1px rgba(255, 255, 255, 0.06) !important;
}

/* Reduced motion support */
.reduce-motion *,
.reduce-motion *::before,
.reduce-motion *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
}

/* Loading animation for export button */
#export-results-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.fa-spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .max-w-7xl {
        padding: 1rem;
    }
    
    .election-controls {
        flex-direction: column;
        gap: 1rem;
    }
    
    .control-group {
        width: 100% !important;
    }
    
    .chart-container {
        height: 250px !important;
    }
    
    /* Touch-friendly elements */
    button, select, input {
        min-height: 44px;
        min-width: 44px;
    }
}

/* Notification animations */
.notification-enter {
    transform: translateY(2rem);
    opacity: 0;
}

.notification-enter-active {
    transform: translateY(0);
    opacity: 1;
    transition: transform 0.3s ease-out, opacity 0.3s ease-out;
}

.notification-exit {
    transform: translateY(0);
    opacity: 1;
}

.notification-exit-active {
    transform: translateY(2rem);
    opacity: 0;
    transition: transform 0.3s ease-in, opacity 0.3s ease-in;
}

/* Success notification specific styles */
.success-notification {
    background-color: #d1fae5;
    border-color: #34d399;
    color: #065f46;
}

.info-notification {
    background-color: #dbeafe;
    border-color: #60a5fa;
    color: #1e40af;
}

.warning-notification {
    background-color: #fef3c7;
    border-color: #f59e0b;
    color: #92400e;
}

.error-notification {
    background-color: #fee2e2;
    border-color: #f87171;
    color: #b91c1c;
}
.yes-no-badge {
    background: linear-gradient(90deg, #10b981 50%, #ef4444 50%);
    color: white;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
}

.yes-vote {
    background-color: #dcfce7;
    color: #166534;
}

.no-vote {
    background-color: #fee2e2;
    color: #991b1b;
}

.approved-status {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.rejected-status {
    background-color: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
.yes-no-badge {
    background: linear-gradient(90deg, #10b981 50%, #ef4444 50%);
    color: white;
    font-weight: bold;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.75rem;
}

.yes-vote {
    background-color: #dcfce7;
    color: #166534;
}

.no-vote {
    background-color: #fee2e2;
    color: #991b1b;
}

.approved-status {
    background-color: #dcfce7;
    color: #166534;
    border: 1px solid #bbf7d0;
}

.rejected-status {
    background-color: #fee2e2;
    color: #991b1b;
    border: 1px solid #fecaca;
}
/* Yes/No Chart Styles */
.yes-no-chart-container {
    position: relative;
    height: 350px;
    margin-bottom: 20px;
}

/* High contrast mode for Yes/No charts */
.high-contrast .yes-legend {
    background-color: rgba(0, 128, 0, 0.8);
}

.high-contrast .no-legend {
    background-color: rgba(255, 0, 0, 0.8);
}
/* Enhanced styles for results page */
.error-notification {
    z-index: 10000;
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
.reduce-motion *,
.reduce-motion *::before,
.reduce-motion *::after {
    animation-duration: 0.01ms !important;
    animation-iteration-count: 1 !important;
    transition-duration: 0.01ms !important;
    scroll-behavior: auto !important;
}

/* Loading animation for export button */
#export-results-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.fa-spinner {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* High contrast mode improvements */
.high-contrast .chart-container {
    border: 2px solid #fff;
}

.high-contrast .table-cell {
    border: 1px solid #fff;
}

/* Mobile responsiveness improvements */
@media (max-width: 768px) {
    .election-controls {
        flex-direction: column;
        gap: 1rem;
    }
    
    .control-group {
        width: 100% !important;
    }
    
    .chart-container {
        height: 250px !important;
    }
    
    /* Touch-friendly elements */
    button, select, input {
        min-height: 44px;
        min-width: 44px;
    }
}

/* Add these styles to the existing CSS */
.no-select {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}

.no-drag {
    -webkit-user-drag: none;
    -moz-user-drag: none;
    -ms-user-drag: none;
    user-drag: none;
}

/* Add a semi-transparent overlay to discourage screenshots */
@media screen {
    body:not(.admin-user)::after {
        content: '';
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, rgba(255, 255, 255, 0.03) 25%, transparent 25%, 
                                    transparent 50%, rgba(255, 255, 255, 0.03) 50%, 
                                    rgba(255, 255, 255, 0.03) 75%, transparent 75%);
        background-size: 10px 10px;
        pointer-events: none;
        z-index: 9998;
        opacity: 0.5;
    }
}

/* Make images non-draggable for non-admins */
body:not(.admin-user) img {
    -webkit-user-drag: none;
    -moz-user-drag: none;
    -ms-user-drag: none;
    user-drag: none;
}
</style>

<?php 
// Clean output buffer and include footer
ob_end_flush();
require_once APP_ROOT . '/includes/footer.php'; 
?>