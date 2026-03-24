<?php
if (empty($_SESSION['logged_in']) || !isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/index.php?page=login");
    exit;
}

// Check if user is admin
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

require_once __DIR__ . '/../config/constants.php';

// Include ActivityLogger class
require_once APP_ROOT . '/includes/ActivityLogger.php';

// Check if user has already voted in the ACTIVE election (not any historical election)
try {
    $db = Database::getInstance()->getConnection();
    $activityLogger = new ActivityLogger($db);

    // Find the currently active election
    $activeElStmt = $db->query(
        "SELECT id FROM elections WHERE status = 'active' AND NOW() BETWEEN start_date AND end_date LIMIT 1"
    );
    $activeElection = $activeElStmt->fetch(PDO::FETCH_ASSOC);

    $alreadyVoted = false;
    if ($activeElection) {
        $stmt = $db->prepare(
            'SELECT id FROM votes WHERE voter_id = ? AND election_id = ? LIMIT 1'
        );
        $stmt->execute([$_SESSION['user_id'], $activeElection['id']]);
        $alreadyVoted = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    if ($alreadyVoted && !$isAdmin) {
        // Log that user tried to access voting page after already voting
        $activityLogger->logActivity(
            $_SESSION['user_id'],
            $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
            'vote_page_view_blocked',
            'Attempted to access voting page after already voting',
            json_encode(['blocked_reason' => 'already_voted'])
        );
        
        // User has already voted, show message and exit
        require_once APP_ROOT . '/includes/header.php';
        echo '<div class="max-w-4xl mx-auto mt-8">';
        echo '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 border-l-4 border-blue-900 rounded-full relative">';
        echo '<strong class="font-bold">Thank you!</strong>';
        echo '<span class="block sm:inline"> You have already cast your vote. Results will be announced after the election period.</span>';
        echo '</div>';
        echo '<div class="text-center mt-6">';
        echo '<a href="' . BASE_URL . '" class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-6 rounded-lg inline-block">Return to Home</a>';
        echo '</div>';
        echo '</div>';
        require_once APP_ROOT . '/includes/footer.php';
        exit;
    }
    
    // Log page view
   $activityLogger->logActivity(
        $_SESSION['user_id'],
        $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
        'vote_page_view',
        'Accessed voting page',
        json_encode(['page' => 'vote.php', 'is_admin' => $isAdmin])
    );
    
} catch (Exception $e) {
    // Continue even if there's an error checking vote status
    error_log("Error checking vote status: " . $e->getMessage());
}

// Set timezone to match your application
date_default_timezone_set('Africa/Accra');

// Get active elections for server-side rendering
$activeElections = [];
$electionOptions = '<option value="">Select an election</option>';

try {
    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT * FROM elections WHERE start_date <= NOW() AND end_date >= NOW()');
    $stmt->execute();
    $activeElections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($activeElections)) {
        foreach ($activeElections as $election) {
            $electionOptions .= '<option value="' . $election['id'] . '" data-description="' . htmlspecialchars($election['description']) . '" data-start-date="' . $election['start_date'] . '" data-end-date="' . $election['end_date'] . '">' . htmlspecialchars($election['title']) . '</option>';
        }
    }
    
    // Log elections fetched
    if (!empty($activeElections)) {
        $activityLogger->logActivity(
            $_SESSION['user_id'],
            $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
            'elections_fetched',
            'Active elections loaded for voting',
            json_encode(['count' => count($activeElections)])
        );
    }
    
} catch (Exception $e) {
    error_log("Error checking active elections: " . $e->getMessage());
    // Try to log error if logger is available
    if (isset($activityLogger)) {
        $activityLogger->logActivity(
            $_SESSION['user_id'] ?? 'unknown',
            $_SESSION['first_name'] ?? 'Unknown' . ' ' . $_SESSION['last_name'] ?? 'User',
            'election_fetch_error',
            'Error loading active elections',
            json_encode(['error' => $e->getMessage()])
        );
    }
}

require_once APP_ROOT . '/includes/header.php'; 
?>

<script>
// Pass PHP variables to JavaScript
const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
const CURRENT_USER_ID = '<?= $_SESSION['user_id'] ?? 'unknown' ?>';
const BASE_URL = '<?= BASE_URL ?>';
</script>

<!-- Add this meta tag for CSRF token -->
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<script>
// Register service worker for background sync
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(BASE_URL + '/sw-sync.js')
        .then(function(registration) {
            console.log('SW registered for background sync: ', registration);
            
            // Request notification permission
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        })
        .catch(function(registrationError) {
            console.log('SW registration failed: ', registrationError);
        });
}

// Listen for messages from service worker
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('message', (event) => {
        const { type, processed, error } = event.data;
        
        switch (type) {
            case 'SYNC_COMPLETED':
                console.log(`Background sync completed: ${processed} votes synced`);
                showBackgroundNotification(`Votes synced successfully! ${processed} vote(s) submitted.`);
                // Log sync completion via AJAX
                logActivity('vote_sync_completed', `Background sync completed: ${processed} votes synced`);
                break;
                
            case 'SYNC_FAILED':
                console.error('Background sync failed:', error);
                logActivity('vote_sync_failed', `Background sync failed: ${error}`);
                break;
                
            case 'SYNC_STARTED':
                console.log('Background sync started by service worker');
                showBackgroundNotification('Syncing votes in background...');
                logActivity('vote_sync_started', 'Background sync started');
                break;
        }
    });
}

// Function to log activities via AJAX
function logActivity(activityType, description, details = {}) {
    fetch(BASE_URL + '/api/log-activity.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
        },
        body: JSON.stringify({
            activity_type: activityType,
            description: description,
            details: details
        })
    }).catch(error => console.error('Error logging activity:', error));
}

// Function to show background sync notifications
function showBackgroundNotification(message) {
    // Show a subtle notification that doesn't interrupt the user
    const notification = document.createElement('div');
    notification.className = 'fixed bottom-4 left-4 bg-blue-100 border border-blue-400 text-blue-700 px-4 py-2 rounded-lg shadow-lg z-40 max-w-sm';
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-sync-alt mr-2 animate-spin"></i>
            <span class="text-sm">${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}
</script>

<div class="max-w-7xl mx-auto px-4 py-8">
  <!-- Election Info Bar -->
  <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center mb-8 gap-4">
    <div>
      <h2 class="text-3xl font-bold text-gray-900 mb-2">Cast Your Vote</h2>
      <p class="text-gray-600">Select your preferred candidates for each position</p>
    </div>
    
    <!-- Quick Actions -->
    <div class="flex flex-wrap gap-2">
      <button id="toggle-help" class="bg-blue-100 hover:bg-blue-200 text-blue-800 px-3 py-2 rounded-lg text-sm font-medium" aria-label="Voting help information">
        <i class="fas fa-question-circle mr-1" aria-hidden="true"></i> Voting Help
      </button>
      <button id="toggle-contrast" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-3 py-2 rounded-lg text-sm font-medium" aria-label="Toggle high contrast mode">
        <i class="fas fa-adjust mr-1" aria-hidden="true"></i> High Contrast
      </button>
      <button id="share-vote" class="bg-green-100 hover:bg-green-200 text-green-800 px-3 py-2 rounded-lg text-sm font-medium" aria-label="Share that you voted">
        <i class="fas fa-share-alt mr-1" aria-hidden="true"></i> Share
      </button>
      <button id="offline-status" class="bg-yellow-100 text-yellow-800 px-3 py-2 rounded-lg text-sm font-medium hidden" aria-label="Offline mode">
        <i class="fas fa-wifi-slash mr-1" aria-hidden="true"></i> Offline
      </button>
      <button id="manual-sync" class="bg-blue-100 hover:bg-blue-200 text-blue-800 px-3 py-2 rounded-lg text-sm font-medium hidden" aria-label="Sync pending votes">
        <i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> Sync Votes
      </button>
    </div>
  </div>
  
  <!-- Progress Indicator -->
  <div class="mb-8 bg-white rounded-lg shadow-sm p-4 border border-gray-200">
    <div class="flex justify-between items-center mb-2">
      <span class="text-sm font-medium text-gray-700">Your Voting Progress</span>
      <span id="progress-percent" class="text-sm font-semibold text-pink-600">0%</span>
    </div>
    <div class="bg-gray-200 rounded-full h-2.5">
      <div id="vote-progress" class="bg-pink-600 h-2.5 rounded-full transition-all duration-300" style="width: 0%" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
    </div>
    <div class="flex justify-between mt-2">
      <span class="text-xs text-gray-500">0 positions completed</span>
      <span class="text-xs text-gray-500" id="total-positions">0 positions total</span>
    </div>
  </div>
  
  <div class="flex flex-col lg:flex-row gap-6">
    <!-- Left Column - Election Selection & Info -->
    <div class="lg:w-1/4">
      <div class="bg-white rounded-lg shadow-lg p-6 border border-gray-100 sticky top-6">
        <div class="mb-6">
          <label for="election-select" class="block text-sm font-medium text-gray-700 mb-2">Select Election</label>
          <select id="election-select" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-pink-500 focus:border-transparent transition-all duration-200" aria-describedby="election-description">
            <?= $electionOptions ?>
          </select>
          <div id="election-description" class="sr-only">Select an election to vote in</div>
        </div>
        
        <div id="election-info" class="hidden">
          <div class="p-4 bg-blue-50 rounded-lg border border-blue-100 mb-4">
            <h3 class="text-lg font-semibold text-blue-800" id="election-title"></h3>
            <p class="text-blue-600 text-sm mt-1" id="election-description"></p>
          </div>
          
          <div class="grid grid-cols-2 gap-4 mb-4">
            <div class="bg-gray-50 p-3 rounded-lg text-center">
              <p class="text-xs text-gray-500">Starts</p>
              <p class="text-sm font-medium text-gray-800" id="election-start"></p>
            </div>
            <div class="bg-gray-50 p-3 rounded-lg text-center">
              <p class="text-xs text-gray-500">Ends</p>
              <p class="text-sm font-medium text-gray-800" id="election-end"></p>
            </div>
          </div>
          
          <div class="bg-pink-50 p-3 rounded-lg border border-pink-100 mb-4">
            <div class="flex justify-between items-center mb-2">
              <p class="text-xs text-pink-700 font-medium">TIME REMAINING</p>
              <i class="fas fa-clock text-pink-600" aria-hidden="true"></i>
            </div>
            <p class="text-lg font-bold text-pink-700" id="time-left"></p>
          </div>
        </div>
        
        <!-- Category Navigation -->
        <div id="category-navigation" class="hidden">
          <div class="flex justify-between items-center mb-3">
            <h3 class="text-sm font-medium text-gray-700">Voting Categories</h3>
            <button id="search-toggle" class="text-gray-500 hover:text-gray-700" aria-label="Search candidates">
              <i class="fas fa-search" aria-hidden="true"></i>
            </button>
          </div>
          
          <!-- Search Box -->
          <div id="search-box" class="hidden mb-3">
            <input type="text" placeholder="Search candidates..." 
                   class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm"
                   id="candidate-search" aria-label="Search candidates">
          </div>
          
          <div id="category-tabs" class="space-y-2" role="tablist" aria-label="Voting categories">
            <!-- Category tabs will be generated here -->
          </div>
        </div>
      </div>
      
      <!-- Help Panel -->
      <div id="help-panel" class="hidden mt-6 bg-white rounded-lg shadow-lg border border-gray-100 overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-700 px-5 py-4">
          <h3 class="text-base font-semibold text-white flex items-center gap-2">
            <i class="fas fa-question-circle"></i> How to Vote — Nkoranza SHTs
          </h3>
          <p class="text-pink-200 text-xs mt-0.5">Follow these steps to cast your vote successfully</p>
        </div>

        <div class="p-5 space-y-3 text-sm text-gray-600">

          <!-- Step 1 -->
          <div class="flex items-start gap-3">
            <div class="w-7 h-7 bg-pink-100 text-pink-800 rounded-full flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5">1</div>
            <div>
              <p class="font-medium text-gray-800">Select the active election</p>
              <p class="text-xs text-gray-500 mt-0.5">Choose the current election from the dropdown at the top of the voting area.</p>
            </div>
          </div>

          <!-- Step 2 -->
          <div class="flex items-start gap-3">
            <div class="w-7 h-7 bg-pink-100 text-pink-800 rounded-full flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5">2</div>
            <div>
              <p class="font-medium text-gray-800">Vote for each position</p>
              <p class="text-xs text-gray-500 mt-0.5">Positions are grouped by category (e.g. Senior Prefects, Sports Prefects). Select one candidate per position. Use <strong>Next Category</strong> to move forward.</p>
            </div>
          </div>

          <!-- Step 3 -->
          <div class="flex items-start gap-3">
            <div class="w-7 h-7 bg-pink-100 text-pink-800 rounded-full flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5">3</div>
            <div>
              <p class="font-medium text-gray-800">Single-candidate positions</p>
              <p class="text-xs text-gray-500 mt-0.5">If only one candidate is running for a position, you will be asked to vote <span class="text-green-700 font-medium">Yes (approve)</span> or <span class="text-red-600 font-medium">No (reject)</span>.</p>
            </div>
          </div>

          <!-- Step 4 -->
          <div class="flex items-start gap-3">
            <div class="w-7 h-7 bg-pink-100 text-pink-800 rounded-full flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5">4</div>
            <div>
              <p class="font-medium text-gray-800">Review and submit</p>
              <p class="text-xs text-gray-500 mt-0.5">After completing all categories, a summary of your choices will appear. Review carefully — <span class="font-medium text-gray-700">you cannot change your vote after submission.</span></p>
            </div>
          </div>

          <!-- Step 5 -->
          <div class="flex items-start gap-3">
            <div class="w-7 h-7 bg-pink-100 text-pink-800 rounded-full flex items-center justify-center font-bold text-xs flex-shrink-0 mt-0.5">5</div>
            <div>
              <p class="font-medium text-gray-800">Logout after voting</p>
              <p class="text-xs text-gray-500 mt-0.5">Once your vote is confirmed, log out immediately. You are allowed to vote only once. Do not share your login credentials with anyone.</p>
            </div>
          </div>

          <!-- Rules box -->
          <div class="bg-amber-50 border-l-3 border-amber-400 rounded-r-lg p-3 mt-1" style="border-left: 3px solid #f59e0b">
            <p class="text-xs font-semibold text-amber-800 mb-1"><i class="fas fa-exclamation-triangle mr-1"></i> Important Rules</p>
            <ul class="text-xs text-amber-700 space-y-0.5 list-disc list-inside">
              <li>Your vote is secret — no one can see your choices</li>
              <li>Each student votes only once per election</li>
              <li>Attempting to vote multiple times is a violation</li>
              <li>Report any issues to the Electoral Commissioner</li>
            </ul>
          </div>

        </div>
      </div>
    </div>
    
    <!-- Right Column - Voting Area -->
    <div class="lg:w-3/4">
      <div id="vote-container" class="bg-white rounded-lg shadow-lg p-6 border border-gray-100">
        <!-- Current Category Display -->
        <div id="current-category" class="hidden mb-6 p-4 bg-gradient-to-r from-pink-50 to-purple-50 rounded-lg border border-pink-200">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="text-xl font-semibold text-pink-800 flex items-center">
                <i class="fas fa-tag mr-2" aria-hidden="true"></i>
                <span id="current-category-name">Loading...</span>
              </h3>
              <p class="text-sm text-pink-600 mt-1">Select your preferred candidate for each position</p>
            </div>
            <div class="bg-white px-3 py-1 rounded-full border border-pink-200">
              <span class="text-sm text-pink-700 font-medium" id="category-progress">0/0</span>
            </div>
          </div>
        </div>
        
        <!-- Positions Container - Side by Side Layout -->
        <div id="positions-container" class="mb-6">
          <div class="text-center py-12">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gray-100 rounded-full mb-4">
              <i class="fas fa-vote-yea text-3xl text-gray-400" aria-hidden="true"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-600">Select an election to view positions</h3>
            <p class="text-sm text-gray-500 mt-1">Choose from the dropdown to see available positions</p>
          </div>
        </div>
        
        <!-- Navigation Buttons -->
        <div id="category-navigation-buttons" class="hidden flex justify-between mt-8 pt-6 border-t border-gray-200">
          <button id="prev-category" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 px-5 rounded-lg transition-all duration-200" aria-label="Previous category">
            <i class="fas fa-arrow-left mr-2" aria-hidden="true"></i>Previous Category
          </button>
          <div class="flex gap-2">
            <button id="save-progress-btn" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 px-4 rounded-lg transition-all duration-200 text-sm" aria-label="Save progress">
              <i class="fas fa-save mr-1" aria-hidden="true"></i>Save
            </button>
            <button id="next-category" class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-5 rounded-lg transition-all duration-200" aria-label="Next category">
              Next Category<i class="fas fa-arrow-right ml-2" aria-hidden="true"></i>
            </button>
          </div>
        </div>
        
        <!-- Review Section -->
        <div id="review-section" class="hidden mt-8 p-6 bg-gray-50 rounded-lg border border-gray-200">
          <h3 class="text-xl font-semibold text-gray-800 mb-6 flex items-center">
            <i class="fas fa-eye mr-2" aria-hidden="true"></i>Review Your Votes
          </h3>
          <div id="review-content" class="space-y-6">
            <!-- Selected candidates will be listed here -->
          </div>
          <div class="mt-8 flex justify-between items-center">
            <button id="edit-votes" class="text-pink-700 hover:text-pink-900 font-medium py-2 px-4 rounded-lg border border-pink-200 hover:bg-pink-50" aria-label="Edit votes">
              <i class="fas fa-edit mr-2" aria-hidden="true"></i>Edit Votes
            </button>
            <div class="flex gap-3">
              <button id="save-progress" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-medium py-2 px-5 rounded-lg transition-all duration-200" aria-label="Save progress">
                <i class="fas fa-save mr-2" aria-hidden="true"></i>Save Progress
              </button>
              <button id="confirm-votes" class="bg-yellow-900 hover:bg-gray-900 text-white font-semibold py-2 px-6 rounded-lg transition-all duration-200" aria-label="Confirm and submit votes">
                <i class="fas fa-check-circle mr-2" aria-hidden="true"></i>Confirm & Submit
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Candidate Profile Modal -->
<div id="candidate-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" aria-hidden="true" role="dialog" aria-labelledby="candidate-modal-title">
  <div class="bg-white rounded-xl p-6 max-w-2xl mx-4 shadow-2xl max-h-[90vh] overflow-y-auto">
    <div class="flex justify-between items-start mb-4">
      <h3 class="text-xl font-semibold text-gray-900" id="candidate-modal-title">Candidate Profile</h3>
      <button id="close-candidate-modal" class="text-gray-500 hover:text-gray-700" aria-label="Close candidate profile">
        <i class="fas fa-times text-xl" aria-hidden="true"></i>
      </button>
    </div>
    <div id="candidate-modal-content" class="space-y-4">
      <!-- Candidate details will be loaded here -->
    </div>
  </div>
</div>

<!-- Compare Candidates Modal -->
<div id="compare-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" aria-hidden="true" role="dialog" aria-labelledby="compare-modal-title">
  <div class="bg-white rounded-xl p-6 max-w-4xl mx-4 shadow-2xl max-h-[90vh] overflow-y-auto w-full">
    <div class="flex justify-between items-start mb-4">
      <h3 class="text-xl font-semibold text-gray-900" id="compare-modal-title">Compare Candidates</h3>
      <button id="close-compare-modal" class="text-gray-500 hover:text-gray-700" aria-label="Close compare candidates">
        <i class="fas fa-times text-xl" aria-hidden="true"></i>
      </button>
    </div>
    <div id="compare-modal-content" class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <!-- Candidate comparison will be loaded here -->
    </div>
  </div>
</div>

<!-- Share Modal -->
<div id="share-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" aria-hidden="true" role="dialog" aria-labelledby="share-modal-title">
  <div class="bg-white rounded-xl p-6 max-w-md mx-4 shadow-2xl w-full">
    <div class="text-center">
      <div class="w-14 h-14 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
        <i class="fas fa-check-circle text-green-600 text-2xl"></i>
      </div>
      <h3 class="text-xl font-semibold text-gray-900 mb-1" id="share-modal-title">You Voted!</h3>
      <p class="text-gray-500 text-sm mb-5">Let others know you participated. Your choices remain private.</p>

      <!-- Native share (mobile) -->
      <button id="native-share-btn" class="hidden w-full mb-4 bg-pink-700 hover:bg-pink-800 text-white font-medium py-2.5 px-5 rounded-lg flex items-center justify-center gap-2">
        <i class="fas fa-share-alt"></i> Share via…
      </button>

      <!-- Social buttons -->
      <div class="flex justify-center gap-3 mb-5">
        <button id="share-whatsapp"
                class="bg-green-500 hover:bg-green-600 text-white p-3 rounded-full transition-transform hover:scale-110"
                title="Share on WhatsApp">
          <i class="fab fa-whatsapp text-lg"></i>
        </button>
        <button id="share-facebook"
                class="bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-full transition-transform hover:scale-110"
                title="Share on Facebook">
          <i class="fab fa-facebook-f text-lg"></i>
        </button>
        <button id="share-twitter"
                class="bg-sky-400 hover:bg-sky-500 text-white p-3 rounded-full transition-transform hover:scale-110"
                title="Share on X (Twitter)">
          <i class="fab fa-twitter text-lg"></i>
        </button>
        <button id="share-telegram"
                class="bg-blue-500 hover:bg-blue-600 text-white p-3 rounded-full transition-transform hover:scale-110"
                title="Share on Telegram">
          <i class="fab fa-telegram-plane text-lg"></i>
        </button>
      </div>

      <!-- Copy message -->
      <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 mb-4 text-left">
        <p class="text-xs text-gray-400 mb-1">Or copy this message</p>
        <div class="flex items-center gap-2">
          <span id="share-message" class="flex-1 text-sm text-gray-800">🗳️ I just voted in the Nkoranza SHTs school elections! Every vote counts. #NkoranzaSHTs #IVoted #SchoolElections</span>
          <button id="copy-share-message" class="flex-shrink-0 text-pink-600 hover:text-pink-800 p-1.5 rounded-lg hover:bg-pink-50 transition-colors" aria-label="Copy share message">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>

      <button id="close-share-modal" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-medium py-2 px-5 rounded-lg transition-colors">
        Close
      </button>
    </div>
  </div>
</div>

<!-- Confirmation Modal -->
<div id="confirmation-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" aria-hidden="true" role="dialog" aria-labelledby="confirmation-modal-title">
  <div class="bg-white rounded-xl p-6 max-w-md mx-4 shadow-2xl">
    <div class="text-center">
      <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <i class="fas fa-check-circle text-gray-900 text-2xl" aria-hidden="true"></i>
      </div>
      <h3 class="text-xl font-bold text-gray-900 mb-3" id="confirmation-modal-title">Confirm Your Vote</h3>
      <p class="text-gray-600 mb-6">Please review your selections one final time. This action cannot be undone.</p>
      
      <div id="confirmation-summary" class="bg-gray-50 p-4 rounded-lg mb-6 text-left max-h-60 overflow-y-auto">
        <!-- Selected candidates will be listed here -->
      </div>
      
      <div class="flex flex-col sm:flex-row gap-3">
        <button id="cancel-confirmation" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-5 rounded-lg flex-1">
          Go Back
        </button>
        <button id="final-confirm-vote" class="bg-gray-900 hover:bg-pink-900 text-white font-semibold py-2 px-6 rounded-lg flex-1">
          <i class="fas fa-check-circle mr-2" aria-hidden="true"></i>Confirm Vote
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Success Modal -->
<div id="success-modal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center hidden z-50" aria-hidden="true" role="dialog" aria-labelledby="success-modal-title">
  <div class="bg-white rounded-xl p-8 max-w-md mx-4 shadow-2xl">
    <div class="text-center">
      <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-check-circle text-green-600 text-4xl" aria-hidden="true"></i>
      </div>
      <h3 class="text-2xl font-bold text-gray-900 mb-3" id="success-modal-title">Vote Submitted Successfully!</h3>
      <p class="text-gray-600 mb-6">Thank you for participating in this year's election. Your vote has been recorded.</p>
      
      <!-- Vote Verification -->
      <div class="bg-gray-50 p-4 rounded-lg mb-6 text-left">
        <h4 class="font-semibold text-gray-800 mb-2 flex items-center">
          <i class="fas fa-shield-check text-green-500 mr-2" aria-hidden="true"></i>Vote Verification
        </h4>
        <p class="text-sm text-gray-600">Your vote has been securely recorded with reference ID: <span id="vote-reference" class="font-mono"><?= bin2hex(random_bytes(4)) ?></span></p>
        <p class="text-sm text-gray-600 mt-2">Verification code: <span id="vote-verification-code" class="font-mono"><?= substr(hash('sha256', uniqid() . microtime()), 0, 12) ?></span></p>
        <button id="copy-verification-code" class="text-xs text-pink-600 hover:text-pink-800 mt-1 flex items-center">
          <i class="fas fa-copy mr-1" aria-hidden="true"></i> Copy verification code
        </button>
      </div>
      
      <div class="flex flex-col gap-3">
        <button id="return-home-btn" class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-3 px-8 rounded-lg transition-colors">
          Return to Home
        </button>
        <button id="share-after-vote" class="bg-white border border-gray-300 hover:bg-gray-100 text-gray-800 font-medium py-2 px-5 rounded-lg">
          <i class="fas fa-share-alt mr-2" aria-hidden="true"></i>Share That I Voted
        </button>
        <button id="download-receipt" class="bg-yellow-900 hover:bg-blue-700 text-white font-medium py-2 px-5 rounded-lg">
          <i class="fas fa-download mr-2" aria-hidden="true"></i>Download Receipt
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Offline Voting Notice -->
<div id="offline-notice" class="fixed bottom-4 right-4 bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded-lg shadow-lg hidden z-50" role="alert">
  <div class="flex items-center">
    <i class="fas fa-wifi-slash mr-3" aria-hidden="true"></i>
    <span>You are currently offline. Votes will be stored locally and synced when you're back online.</span>
    <button class="ml-4 text-yellow-700 hover:text-yellow-900" onclick="document.getElementById('offline-notice').classList.add('hidden')" aria-label="Dismiss offline notice">
      <i class="fas fa-times" aria-hidden="true"></i>
    </button>
  </div>
</div>

<script>
// Global variables
let currentElectionId = null;
let selectedCandidates = {};
let timeLeftInterval = null;
let totalPositions = 0;
let completedPositions = 0;
let currentCategoryIndex = 0;
let categories = [];
let categoryPositions = {};
let candidateDetails = {}; // Store candidate details for modals
let searchTimeout = null;
let isOnline = true;
let pendingVotes = []; // Store votes when offline

// Function to clear voting storage
function clearVotingStorage() {
    console.log('Clearing voting storage for user:', CURRENT_USER_ID);
    
    const currentUserId = CURRENT_USER_ID;
    
    // Clear localStorage voting data but keep only current user's pending votes
    const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
    const otherUsersVotes = pendingVotes.filter(vote => vote.user_id !== currentUserId);
    
    localStorage.setItem('pendingVotes', JSON.stringify(otherUsersVotes));
    
    // Remove vote progress for current user
    localStorage.removeItem(`voteProgress_${currentElectionId}`);
    
    // Clear sessionStorage voting data for current user
    for (let i = 0; i < sessionStorage.length; i++) {
        const key = sessionStorage.key(i);
        if (key && (key.startsWith('hasVoted_') || key.startsWith('hasVotedLocally_'))) {
            sessionStorage.removeItem(key);
        }
    }
    
    // Log storage clearance
    logActivity('storage_cleared', 'Voting storage cleared for current user');
    console.log('Voting storage cleared successfully for current user');
}

// Function to validate storage ownership
function validateStorageOwnership() {
    console.log('Validating storage ownership for user:', CURRENT_USER_ID);
    
    // If we have an unknown user ID but find voting data, clear it
    if (CURRENT_USER_ID === 'unknown') {
        const hasVotingData = localStorage.getItem('pendingVotes') || 
                             sessionStorage.length > 0;
        
        if (hasVotingData) {
            console.warn('Unknown user with voting data - clearing storage');
            clearVotingStorage();
            return false;
        }
    }
    
    // Check if we have any voting data that might belong to previous user
    const hasPreviousVotes = localStorage.getItem('pendingVotes') || 
                            sessionStorage.length > 0;
    
    if (hasPreviousVotes) {
        console.log('Found previous voting data, ensuring clean slate for current user');
        
        // For safety, clear any existing voting data when new user accesses the page
        clearVotingStorage();
    }
    
    return true;
}

// Enhanced function to check if user has already voted
function hasUserVoted(electionId) {
    const currentUserId = CURRENT_USER_ID;
    
    // Safety check - clear storage if user ID is unknown but we have voting data
    if (CURRENT_USER_ID === 'unknown') {
        const hasVotingData = sessionStorage.getItem('hasVoted_' + electionId) || 
                             localStorage.getItem('pendingVotes');
        
        if (hasVotingData) {
            console.warn('Unknown user with voting data - clearing storage');
            clearVotingStorage();
            return false;
        }
    }
    
    // Check if user has voted online (session)
    if (sessionStorage.getItem(`hasVoted_${electionId}`) === 'true') {
        return true;
    }
    
    // Check if user has pending offline vote for this election
    try {
        const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
        const hasPendingVote = pendingVotes.some(vote => 
            vote.election_id == electionId && 
            vote.user_id === currentUserId
        );
        
        return hasPendingVote;
    } catch (e) {
        console.error('Error reading pending votes:', e);
        return false;
    }
}

// Function to get CSRF token from meta tag
function getCsrfToken() {
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    return metaTag ? metaTag.getAttribute('content') : '';
}

// Function to calculate time left
function calculateTimeLeft(endDate) {
    const now = new Date();
    const end = new Date(endDate);
    const difference = end - now;
    
    if (difference <= 0) {
        return { expired: true };
    }
    
    const days = Math.floor(difference / (1000 * 60 * 60 * 24));
    const hours = Math.floor((difference % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((difference % (1000 * 60 * 60)) / (1000 * 60));
    const seconds = Math.floor((difference % (1000 * 60)) / 1000);
    
    return {
        days,
        hours,
        minutes,
        seconds,
        expired: false
    };
}

// Function to format time left
function formatTimeLeft(timeLeft) {
    if (timeLeft.expired) {
        return 'Election ended';
    }
    
    if (timeLeft.days > 0) {
        return `${timeLeft.days}d ${timeLeft.hours}h ${timeLeft.minutes}m`;
    } else if (timeLeft.hours > 0) {
        return `${timeLeft.hours}h ${timeLeft.minutes}m ${timeLeft.seconds}s`;
    } else {
        return `${timeLeft.minutes}m ${timeLeft.seconds}s`;
    }
}

// Function to update time left display
function updateTimeLeft(endDate) {
    if (timeLeftInterval) {
        clearInterval(timeLeftInterval);
    }
    
    timeLeftInterval = setInterval(() => {
        const timeLeft = calculateTimeLeft(endDate);
        document.getElementById('time-left').textContent = formatTimeLeft(timeLeft);
        
        if (timeLeft.expired) {
            clearInterval(timeLeftInterval);
            const confirmButton = document.getElementById('confirm-votes');
            if (confirmButton) {
                confirmButton.disabled = true;
                confirmButton.textContent = 'Election Ended';
            }
        }
    }, 1000);
}

// Function to update progress indicator
function updateProgress() {
    completedPositions = Object.keys(selectedCandidates).length;
    const progress = totalPositions > 0 ? (completedPositions / totalPositions) * 100 : 0;
    
    document.getElementById('vote-progress').style.width = `${progress}%`;
    document.getElementById('vote-progress').setAttribute('aria-valuenow', Math.round(progress));
    document.getElementById('progress-percent').textContent = `${Math.round(progress)}%`;
    
    // Update the positions completed text
    const completedText = document.querySelector('.flex.justify-between.mt-2 span:first-child');
    if (completedText) {
        completedText.textContent = `${completedPositions} positions completed`;
    }
    
    // Update category tabs
    updateCategoryTabs();
    
    // Enable/disable navigation buttons
    updateNavigationButtons();
}

// Function to update category tabs
function updateCategoryTabs() {
    const tabsContainer = document.getElementById('category-tabs');
    if (!tabsContainer) return;
    
    tabsContainer.innerHTML = '';
    
    categories.forEach((category, index) => {
        const categoryPositionsCount = categoryPositions[category] ? categoryPositions[category].length : 0;
        const completedInCategory = categoryPositions[category] ? 
            categoryPositions[category].filter(pos => selectedCandidates[pos.id]).length : 0;
        
        const tab = document.createElement('button');
        tab.className = `px-3 py-1.5 rounded-full text-xs font-medium transition-all ${index === currentCategoryIndex ? 
            'bg-pink-900 text-white' : 
            completedInCategory === categoryPositionsCount ? 
                'bg-green-100 text-green-800' : 
                'bg-gray-200 text-gray-800'}`;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', index === currentCategoryIndex ? 'true' : 'false');
        tab.setAttribute('aria-controls', `category-${index}`);
        tab.setAttribute('id', `tab-${index}`);
        
        tab.innerHTML = `${category} <span class="ml-1">${completedInCategory}/${categoryPositionsCount}</span>`;
        tab.addEventListener('click', () => {
            if (index !== currentCategoryIndex) {
                showCategory(index);
            }
        });
        
        tabsContainer.appendChild(tab);
    });
}

// Function to update navigation buttons
function updateNavigationButtons() {
    const currentCategory = categories[currentCategoryIndex];
    const currentCategoryPositions = categoryPositions[currentCategory] || [];
    const completedInCategory = currentCategoryPositions.filter(pos => selectedCandidates[pos.id]).length;
    
    // Show/hide previous button
    const prevButton = document.getElementById('prev-category');
    if (prevButton) {
        prevButton.style.display = currentCategoryIndex > 0 ? 'block' : 'none';
    }
    
    // Update next button text
    const nextButton = document.getElementById('next-category');
    if (nextButton) {
        if (currentCategoryIndex < categories.length - 1) {
            nextButton.innerHTML = `Next Category <i class="fas fa-arrow-right ml-2" aria-hidden="true"></i>`;
        } else {
            nextButton.innerHTML = `Review Votes <i class="fas fa-eye ml-2" aria-hidden="true"></i>`;
        }
        
        // Enable/disable next button based on completion
        nextButton.disabled = completedInCategory < currentCategoryPositions.length;
    }
}

// Function to show a specific category
function showCategory(index) {
    currentCategoryIndex = index;
    const category = categories[index];
    
    // Log category view
    logActivity('category_view', `Viewed category: ${category}`);
    
    // Update current category display
    document.getElementById('current-category-name').textContent = category;
    document.getElementById('current-category').classList.remove('hidden');
    
    // Update category progress
    const categoryPositionsList = categoryPositions[category] || [];
    const completedInCategory = categoryPositionsList.filter(pos => selectedCandidates[pos.id]).length;
    document.getElementById('category-progress').textContent = `${completedInCategory}/${categoryPositionsList.length}`;
    
    // Clear positions container
    const container = document.getElementById('positions-container');
    container.innerHTML = '';
    
    // Add positions for this category in a grid layout
    const positions = categoryPositions[category] || [];
    
    if (positions.length === 0) {
        container.innerHTML = `
            <div class="text-center py-8 text-gray-500">
                <i class="fas fa-info-circle text-2xl mb-2" aria-hidden="true"></i>
                <p>No positions found in this category</p>
            </div>
        `;
        return;
    }
    
    // Create a grid container
    const gridContainer = document.createElement('div');
    gridContainer.className = 'grid grid-cols-1 md:grid-cols-2 gap-6';
    
    positions.forEach(position => {
        const positionCard = document.createElement('div');
        positionCard.className = 'position-card bg-white border border-gray-200 rounded-xl p-5 shadow-sm';
        positionCard.innerHTML = `
            <h4 class="text-lg font-semibold text-gray-800 mb-3 flex items-center">
                <i class="fas fa-user-circle mr-2 text-pink-600" aria-hidden="true"></i>${position.name}
            </h4>
            ${position.description ? `<p class="text-sm text-gray-600 mb-4">${position.description}</p>` : ''}
            <div class="candidates-container" id="candidates-${position.id}">
                <div class="text-center py-4">
                    <div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-pink-600"></div>
                    <p class="mt-2 text-sm text-gray-500">Loading candidates...</p>
                </div>
            </div>
        `;
        gridContainer.appendChild(positionCard);
    });
    
    container.appendChild(gridContainer);
    
    // Show navigation buttons
    document.getElementById('category-navigation-buttons').classList.remove('hidden');
    
    // Update navigation buttons
    updateNavigationButtons();
    
    // Load candidates for each position after DOM is updated
    setTimeout(() => {
        positions.forEach(position => {
            loadCandidates(position.id);
        });
    }, 100);
}

// Function to show the review section
function showReviewSection() {
    document.getElementById('positions-container').classList.add('hidden');
    document.getElementById('category-navigation-buttons').classList.add('hidden');
    document.getElementById('current-category').classList.add('hidden');
    document.getElementById('review-section').classList.remove('hidden');
    
    // Log review section view
    logActivity('review_section_view', 'Viewed vote review section');
    
    prepareReviewContent();
}

// Function to go back to category voting
function backToVoting() {
    document.getElementById('review-section').classList.add('hidden');
    document.getElementById('positions-container').classList.remove('hidden');
    document.getElementById('category-navigation-buttons').classList.remove('hidden');
    document.getElementById('current-category').classList.remove('hidden');
}

// Function to prepare review content
function prepareReviewContent() {
    const reviewContent = document.getElementById('review-content');
    reviewContent.innerHTML = '';
    
    if (Object.keys(selectedCandidates).length === 0) {
        reviewContent.innerHTML = '<p class="text-gray-500 text-center py-8">No votes selected yet.</p>';
        return;
    }
    
    // Group selected candidates by category for better organization
    categories.forEach(category => {
        const categoryPositionsList = categoryPositions[category] || [];
        const categoryVotes = categoryPositionsList.filter(pos => selectedCandidates[pos.id]);
        
        if (categoryVotes.length > 0) {
            const categoryDiv = document.createElement('div');
            categoryDiv.className = 'mb-6';
            categoryDiv.innerHTML = `<h4 class="font-semibold text-pink-700 mb-3 text-lg border-b border-gray-200 pb-2">${category}</h4>`;
            
            const list = document.createElement('div');
            list.className = 'space-y-3';
            
            categoryVotes.forEach(position => {
                const candidateId = selectedCandidates[position.id];
                
                // Get candidate name from stored candidate details instead of DOM
                let candidateName = "Unknown Candidate";
                
                if (candidateId === 'no') {
                    candidateName = "No Vote (Rejected candidate)";
                    
                    // Try to get the candidate name for context
                    const positionElement = document.querySelector(`#candidates-${position.id}`);
                    if (positionElement) {
                        const candidateElement = positionElement.querySelector('.candidate-card');
                        if (candidateElement) {
                            const nameElement = candidateElement.querySelector('p.text-md');
                            if (nameElement) {
                                candidateName = `No (Rejected ${nameElement.textContent})`;
                            }
                        }
                    }
                } else if (candidateDetails[candidateId]) {
                    candidateName = `${candidateDetails[candidateId].first_name} ${candidateDetails[candidateId].last_name}`;
                } else {
                    // Fallback: try to get from DOM if available
                    const candidateElement = document.querySelector(`#candidates-${position.id} .candidate-card input[value="${candidateId}"]`);
                    if (candidateElement) {
                        candidateName = candidateElement.closest('.candidate-card').querySelector('p.text-md').textContent;
                    }
                }
                
                const listItem = document.createElement('div');
                listItem.className = 'flex justify-between items-center bg-white p-3 rounded-lg border border-gray-200';
                listItem.innerHTML = `
                    <div>
                        <span class="font-medium">${position.name}:</span>
                        <span class="text-gray-700 ml-2 ${candidateId === 'no' ? 'text-red-600' : ''}">${candidateName}</span>
                    </div>
                    <button class="text-pink-600 hover:text-pink-800 edit-vote-btn" data-position-id="${position.id}" aria-label="Edit vote for ${position.name}">
                        <i class="fas fa-edit" aria-hidden="true"></i>
                    </button>
                `;
                
                // Add click event to edit button
                listItem.querySelector('.edit-vote-btn').addEventListener('click', () => {
                    // Find which category this position belongs to
                    let categoryIndex = 0;
                    for (let i = 0; i < categories.length; i++) {
                        if (categoryPositions[categories[i]].some(p => p.id === position.id)) {
                            categoryIndex = i;
                            break;
                        }
                    }
                    
                    // Log edit action
                    logActivity('vote_edit', `Editing vote for position: ${position.name}`);
                    
                    // Go back to that category
                    backToVoting();
                    showCategory(categoryIndex);
                    
                    // Scroll to the position
                    setTimeout(() => {
                        const positionElement = document.getElementById(`candidates-${position.id}`).closest('.position-card');
                        if (positionElement) {
                            positionElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            
                            // Highlight the position
                            positionElement.classList.add('ring-2', 'ring-pink-500');
                            setTimeout(() => {
                                positionElement.classList.remove('ring-2', 'ring-pink-500');
                            }, 2000);
                        }
                    }, 300);
                });
                
                list.appendChild(listItem);
            });
            
            categoryDiv.appendChild(list);
            reviewContent.appendChild(categoryDiv);
        }
    });
}

// Function to validate election status before submitting vote
async function validateElectionStatus(electionId) {
    try {
        const response = await fetch(`${BASE_URL}/api/voting/validate-election.php?election_id=${electionId}`);
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Election validation failed');
        }
        
        return data;
    } catch (error) {
        console.error('Error validating election:', error);
        throw error;
    }
}

// Function to show error message
function showError(message) {
    // Create or show error notification
    let errorDiv = document.getElementById('error-notification');
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.id = 'error-notification';
        errorDiv.className = 'fixed top-4 right-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg shadow-lg z-50';
        document.body.appendChild(errorDiv);
    }
    
    errorDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-exclamation-circle mr-3" aria-hidden="true"></i>
            <span>${message}</span>
            <button class="ml-4 text-red-700 hover:text-red-900" onclick="this.parentElement.parentElement.remove()" aria-label="Dismiss error">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
    `;
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (errorDiv.parentElement) {
            errorDiv.remove();
        }
    }, 5000);
    
    // Log error
    logActivity('error_displayed', message);
}

// Function to show confirmation modal
function showConfirmationModal() {
    const confirmationSummary = document.getElementById('confirmation-summary');
    confirmationSummary.innerHTML = '';
    
    if (Object.keys(selectedCandidates).length === 0) {
        confirmationSummary.innerHTML = '<p class="text-gray-500 text-center py-4">No votes selected.</p>';
        return;
    }
    
    // Group selected candidates by category
    categories.forEach(category => {
        const categoryPositionsList = categoryPositions[category] || [];
        const categoryVotes = categoryPositionsList.filter(pos => selectedCandidates[pos.id]);
        
        if (categoryVotes.length > 0) {
            const categoryDiv = document.createElement('div');
            categoryDiv.className = 'mb-4';
            categoryDiv.innerHTML = `<h4 class="font-semibold text-gray-800 mb-2 text-sm border-b border-gray-200 pb-1">${category}</h4>`;
            
            const list = document.createElement('div');
            list.className = 'space-y-2';
            
            categoryVotes.forEach(position => {
                const candidateId = selectedCandidates[position.id];
                
                const listItem = document.createElement('div');
                listItem.className = 'flex justify-between items-center text-sm';
                
                if (candidateId === 'no') {
                    // Handle "No" vote - try to get the candidate name for context
                    let candidateName = "the candidate";
                    const positionElement = document.querySelector(`#candidates-${position.id}`);
                    
                    if (positionElement) {
                        const candidateElement = positionElement.querySelector('.candidate-card');
                        if (candidateElement) {
                            const nameElement = candidateElement.querySelector('p.text-md');
                            if (nameElement) {
                                candidateName = nameElement.textContent;
                            }
                        }
                    }
                    
                    listItem.innerHTML = `
                        <span class="text-gray-600">${position.name}:</span>
                        <span class="font-medium text-red-600">No (Rejected ${candidateName})</span>
                    `;
                } else {
                    // Get candidate name from stored candidate details
                    const candidate = candidateDetails[candidateId];
                    let candidateName = "Unknown Candidate";
                    
                    if (candidate) {
                        candidateName = `${candidate.first_name} ${candidate.last_name}`;
                    } else {
                        // Fallback: try to get from DOM if available
                        const candidateElement = document.querySelector(`#candidates-${position.id} .candidate-card input[value="${candidateId}"]`);
                        if (candidateElement) {
                            candidateName = candidateElement.closest('.candidate-card').querySelector('p.text-md').textContent;
                        }
                    }
                    
                    listItem.innerHTML = `
                        <span class="text-gray-600">${position.name}:</span>
                        <span class="font-medium text-gray-800">${candidateName}</span>
                    `;
                }
                
                list.appendChild(listItem);
            });
            
            categoryDiv.appendChild(list);
            confirmationSummary.appendChild(categoryDiv);
        }
    });
    
    document.getElementById('confirmation-modal').classList.remove('hidden');
    document.getElementById('confirmation-modal').setAttribute('aria-hidden', 'false');
}

// Function to submit vote
function submitVote() {
    showConfirmationModal();
}

// Function to actually submit the vote after confirmation
async function finalizeVoteSubmission() {
    try {
        // Check if user is admin (prevent admins from voting)
        if (IS_ADMIN) {
            showError('Administrators cannot vote in elections');
            logActivity('admin_vote_attempt', 'Admin attempted to vote', { election_id: currentElectionId });
            return;
        }
        
        // Check if user has already voted for this election
        if (hasUserVoted(currentElectionId)) {
            showError('You have already cast a vote for this election. Cannot vote again.');
            logActivity('duplicate_vote_attempt', 'User attempted to vote again', { election_id: currentElectionId });
            document.getElementById('confirmation-modal').classList.add('hidden');
            return;
        }
        
        // Check if all positions have selections
        if (Object.keys(selectedCandidates).length < totalPositions) {
            showError('Please make a selection for all positions before submitting.');
            return;
        }
        
        // Get CSRF token
        const csrfToken = getCsrfToken();
        if (!csrfToken) {
            showError('Security token missing. Please refresh the page and try again.');
            return;
        }
        
        // Prepare vote data with CSRF token
        const voteData = {
            election_id: currentElectionId,
            votes: selectedCandidates,
            csrf_token: csrfToken
        };
        
        // Log vote submission attempt
        logActivity('vote_submission_attempt', 'Attempting to submit vote', { 
            election_id: currentElectionId, 
            vote_count: Object.keys(selectedCandidates).length 
        });
        
        // Check if we're online
        if (!isOnline) {
            // Store vote for later submission with validation
            const success = storeVoteForOffline(voteData);
            if (!success) {
                return;
            }
        } else {
            // Submit vote online
            const response = await fetch(`${BASE_URL}/api/voting/vote.php`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(voteData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                // Hide confirmation modal and show success modal
                document.getElementById('confirmation-modal').classList.add('hidden');
                document.getElementById('success-modal').classList.remove('hidden');
                
                // Log successful vote
                logActivity('vote_submitted', 'Vote submitted successfully', { 
                    election_id: currentElectionId,
                    vote_count: Object.keys(selectedCandidates).length
                });
                
                // Generate vote receipt
                generateVoteReceipt(result.vote_id || Date.now());
                
                // Clear saved progress
                localStorage.removeItem(`voteProgress_${currentElectionId}`);
                
                // Update session status to reflect voting
                updateSessionStatus(true);
                
                // Mark as voted in session storage
                sessionStorage.setItem(`hasVoted_${currentElectionId}`, 'true');
            } else {
                showError('Failed to submit vote: ' + result.message);
                
                // Log failed vote
                logActivity('vote_submission_failed', 'Vote submission failed', { 
                    election_id: currentElectionId, 
                    error: result.message 
                });
                
                // If it's a CSRF error, suggest refreshing the page
                if (result.message.includes('token') || result.message.includes('security')) {
                    showError('Security token invalid. Please refresh the page and try again.');
                }
            }
        }
        
    } catch (error) {
        console.error('Error submitting vote:', error);
        showError('Failed to submit vote. Please try again.');
        
        // Log error
        logActivity('vote_submission_error', 'Error submitting vote', { 
            election_id: currentElectionId, 
            error: error.message 
        });
    }
}

// Enhanced function to store vote for offline submission with background sync
function storeVoteForOffline(voteData) {
    const electionId = voteData.election_id;
    const currentUserId = CURRENT_USER_ID;
    
    console.log('Storing vote for offline submission with background sync:', { electionId, currentUserId });
    
    // Log offline vote storage
    logActivity('offline_vote_stored', 'Storing vote for offline submission', { 
        election_id: electionId 
    });
    
    // Enhanced validation - check if user has already voted for this election
    if (hasUserVoted(electionId)) {
        showError('You have already cast a vote for this election. Your vote is pending synchronization.');
        document.getElementById('confirmation-modal').classList.add('hidden');
        return false;
    }
    
    const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
    
    // Enhanced duplicate check - include user_id in comparison
    const existingVoteIndex = pendingVotes.findIndex(vote => 
        vote.election_id == electionId && 
        vote.user_id === currentUserId
    );
    
    // Add user_id to vote data for better tracking
    voteData.user_id = currentUserId;
    voteData.timestamp = new Date().toISOString();
    voteData.verification_code = generateVerificationCode();
    voteData.sync_attempts = 0;
    voteData.last_attempt = null;
    voteData.vote_id = 'offline-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    
    if (existingVoteIndex !== -1) {
        // Replace existing pending vote for this election
        pendingVotes[existingVoteIndex] = voteData;
        console.log('Replaced existing pending vote:', voteData);
    } else {
        // Add new pending vote
        pendingVotes.push(voteData);
        console.log('Added new pending vote:', voteData);
    }
    
    localStorage.setItem('pendingVotes', JSON.stringify(pendingVotes));
    
    // Also store in IndexedDB for service worker access
    storeVoteInIndexedDB(voteData);
    
    // Mark this election as voted in session storage
    sessionStorage.setItem(`hasVoted_${electionId}`, 'true');
    sessionStorage.setItem(`hasVotedLocally_${electionId}`, 'true');
    
    // Update UI for offline voting
    document.getElementById('confirmation-modal').classList.add('hidden');
    document.getElementById('success-modal').classList.remove('hidden');
    
    const successTitle = document.querySelector('#success-modal h3');
    const successMessage = document.querySelector('#success-modal p');
    
    if (successTitle && successMessage) {
        successTitle.textContent = 'Vote Stored for Offline Submission';
        successMessage.textContent = 'Your vote has been stored and will be submitted automatically when you are back online. You can safely close this page.';
    }
    
    // Generate offline receipt
    generateVoteReceipt(voteData.vote_id, voteData.verification_code);
    
    // Clear saved progress
    localStorage.removeItem(`voteProgress_${electionId}`);
    
    // Update sync button visibility
    updateSyncButtonVisibility();
    
    // Disable voting interface for this election
    disableVotingInterface();
    
    // Register for background sync
    registerBackgroundSync();
    
    // Immediately try to sync if we're actually online but detection failed
    setTimeout(() => {
        if (navigator.onLine) {
            console.log('Actually online, attempting immediate sync...');
            submitPendingVotesWithRetry();
        }
    }, 1000);
    
    return true;
}

// Store vote in IndexedDB for service worker access
function storeVoteInIndexedDB(voteData) {
    if (!('indexedDB' in window)) {
        console.log('IndexedDB not supported, skipping storage for service worker');
        return;
    }
    
    const request = indexedDB.open('VoteAppDB', 1);
    
    request.onupgradeneeded = (event) => {
        const db = event.target.result;
        if (!db.objectStoreNames.contains('pendingVotes')) {
            const store = db.createObjectStore('pendingVotes', { keyPath: 'id' });
            store.createIndex('timestamp', 'timestamp', { unique: false });
        }
    };
    
    request.onsuccess = (event) => {
        const db = event.target.result;
        const transaction = db.transaction(['pendingVotes'], 'readwrite');
        const store = transaction.objectStore('pendingVotes');
        
        // Create a unique ID for the vote
        voteData.id = `${voteData.election_id}_${voteData.timestamp}`;
        
        store.put(voteData);
        
        transaction.oncomplete = () => {
            console.log('Vote stored in IndexedDB for background sync');
        };
        
        transaction.onerror = () => {
            console.error('Error storing vote in IndexedDB');
        };
    };
    
    request.onerror = () => {
        console.error('Error opening IndexedDB');
    };
}

// Register for background sync
function registerBackgroundSync() {
    if ('serviceWorker' in navigator && 'SyncManager' in window) {
        navigator.serviceWorker.ready.then((registration) => {
            return registration.sync.register('vote-sync');
        }).then(() => {
            console.log('Background sync registered');
            showBackgroundNotification('Vote will be synced in background');
        }).catch((err) => {
            console.log('Background sync registration failed:', err);
        });
    } else {
        console.log('Background sync not supported');
        // Fallback: use periodic checking
        startPeriodicSyncCheck();
    }
}

// Enhanced function to handle page visibility changes
function handlePageVisibility() {
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && navigator.onLine) {
            // Page became visible and we're online - try to sync
            console.log('Page visible and online, checking for pending votes...');
            const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
            const currentUserId = CURRENT_USER_ID;
            const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
            
            if (userPendingVotes.length > 0) {
                logActivity('page_visible_sync', 'Page became visible, attempting sync', { 
                    pending_count: userPendingVotes.length 
                });
                submitPendingVotesWithRetry();
            }
        }
    });
}

// Function to disable voting interface when vote is cast offline
function disableVotingInterface() {
    const electionId = currentElectionId;
    
    if (!electionId) return;
    
    // Disable all voting controls
    document.querySelectorAll('.candidate-radio, .candidate-card, .position-card').forEach(element => {
        if (element.classList.contains('candidate-radio')) {
            element.disabled = true;
        }
        
        if (element.classList.contains('candidate-card') || element.classList.contains('position-card')) {
            element.style.opacity = '0.6';
            element.style.cursor = 'not-allowed';
            element.style.pointerEvents = 'none';
            
            // Remove existing event listeners and add blocking handler
            const newElement = element.cloneNode(true);
            element.parentNode.replaceChild(newElement, element);
            
            newElement.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                showError('You have already cast your vote for this election. It will be synced when online.');
            });
        }
    });
    
    // Disable navigation and submission buttons
    const disableButtons = [
        '#confirm-votes',
        '#final-confirm-vote',
        '#next-category',
        '#prev-category',
        '#save-progress',
        '#save-progress-btn'
    ];
    
    disableButtons.forEach(selector => {
        const button = document.querySelector(selector);
        if (button) {
            button.disabled = true;
            button.style.opacity = '0.5';
            button.style.cursor = 'not-allowed';
            button.title = 'Vote already cast - pending synchronization';
        }
    });
    
    // Add persistent offline vote notice
    const voteContainer = document.getElementById('vote-container');
    if (voteContainer && !voteContainer.querySelector('.offline-vote-notice')) {
        const offlineNotice = document.createElement('div');
        offlineNotice.className = 'offline-vote-notice bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-4';
        offlineNotice.innerHTML = `
            <div class="flex items-center">
                <i class="fas fa-wifi-slash text-yellow-600 mr-3 text-xl"></i>
                <div class="flex-1">
                    <p class="font-medium text-yellow-800">Vote Cast Offline</p>
                    <p class="text-sm text-yellow-700 mt-1">Your vote for this election has been recorded locally and will be synced when you're back online. You cannot vote again for this election.</p>
                    <div class="mt-2 flex items-center text-xs text-yellow-600">
                        <i class="fas fa-info-circle mr-1"></i>
                        <span>Pending synchronization</span>
                    </div>
                </div>
            </div>
        `;
        voteContainer.prepend(offlineNotice);
    }
    
    // Update election select to reflect voting status
    const electionSelect = document.getElementById('election-select');
    if (electionSelect) {
        const selectedOption = electionSelect.options[electionSelect.selectedIndex];
        if (selectedOption) {
            selectedOption.textContent += ' (Vote Cast - Pending Sync)';
            electionSelect.disabled = true;
        }
    }
}

// Enhanced function to check and restore voting state on page load
function restoreVotingState() {
    const electionSelect = document.getElementById('election-select');
    if (!electionSelect || !electionSelect.value) return;
    
    const electionId = electionSelect.value;
    
    // Check if user has already voted for this election
    if (hasUserVoted(electionId)) {
        console.log('User has already voted for election', electionId, '- disabling interface');
        disableVotingInterface();
        
        // Show appropriate message based on online/offline status
        if (sessionStorage.getItem(`hasVotedLocally_${electionId}`) === 'true') {
            showError('You have already cast your vote for this election. It is pending synchronization.');
        } else {
            showError('You have already voted in this election.');
        }
    }
}

// Function to generate verification code
function generateVerificationCode() {
    return Math.random().toString(36).substring(2, 10).toUpperCase() + 
           Math.random().toString(36).substring(2, 10).toUpperCase();
}

// Function to generate vote receipt
function generateVoteReceipt(voteId, verificationCode = null) {
    const receiptCode = verificationCode || generateVerificationCode();
    document.getElementById('vote-verification-code').textContent = receiptCode;
    
    // Store receipt in localStorage for later verification
    const receipts = JSON.parse(localStorage.getItem('voteReceipts') || '[]');
    receipts.push({
        vote_id: voteId,
        election_id: currentElectionId,
        verification_code: receiptCode,
        timestamp: new Date().toISOString()
    });
    localStorage.setItem('voteReceipts', JSON.stringify(receipts));
    
    // Log receipt generation
    logActivity('receipt_generated', 'Vote receipt generated', { 
        vote_id: voteId, 
        election_id: currentElectionId 
    });
}

// Function to update session status
function updateSessionStatus(hasVoted) {
    // Make an API call to update the user's voting status
    try {
        fetch(BASE_URL + '/api/user/updateVotingStatus.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ has_voted: hasVoted })
        }).then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Session status updated successfully');
            }
        }).catch(error => {
            console.error('Error updating session status:', error);
        });
    } catch (error) {
        console.error('Error updating session status:', error);
    }
}

// Enhanced fetch with timeout and better error handling
async function fetchWithTimeout(url, options = {}, timeout = 10000) {
    const controller = new AbortController();
    const id = setTimeout(() => controller.abort(), timeout);
    
    try {
        const response = await fetch(url, {
            ...options,
            signal: controller.signal
        });
        clearTimeout(id);
        return response;
    } catch (error) {
        clearTimeout(id);
        if (error.name === 'AbortError') {
            throw new Error('Request timeout - please check your connection');
        }
        throw error;
    }
}

// Function to load candidates for a position
async function loadCandidates(positionId) {
    // Wait a bit to ensure DOM is fully updated
    await new Promise(resolve => setTimeout(resolve, 50));
    
    const container = document.getElementById(`candidates-${positionId}`);
    if (!container) {
        console.warn(`Container not found for position ${positionId}, retrying...`);
        // Retry after a short delay
        setTimeout(() => loadCandidates(positionId), 100);
        return;
    }
    
    // Add loading overlay
    const loadingOverlay = document.createElement('div');
    loadingOverlay.className = 'loading-overlay';
    loadingOverlay.innerHTML = '<div class="loading-spinner"></div>';
    container.appendChild(loadingOverlay);
    
    try {
        console.log(`Loading candidates for position ${positionId}`);
        const response = await fetch(`${BASE_URL}/api/voting/candidates.php?position_id=${positionId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to fetch candidates');
        }
        
        const candidates = data.candidates || [];
        
        // Remove loading overlay
        if (loadingOverlay.parentNode) {
            loadingOverlay.remove();
        }
        
        // Store candidate details for modal views
        candidates.forEach(candidate => {
            candidateDetails[candidate.id] = candidate;
        });
        
        if (candidates.length === 0) {
            container.innerHTML = `
                <div class="text-center py-4 text-yellow-600 bg-yellow-50 rounded-lg">
                    <i class="fas fa-exclamation-circle text-xl mb-2" aria-hidden="true"></i>
                    <p class="text-sm">No candidates available for this position</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = '';
        
        // Handle single candidate scenario
        if (candidates.length === 1) {
            const candidate = candidates[0];
            const photoUrl = candidate.photo_path || `${BASE_URL}/assets/images/default-avatar.png`;
            
            const candidateElement = document.createElement('div');
            candidateElement.className = 'candidate-card bg-blue-50 border border-blue-300 rounded-lg p-4 mb-4';
            candidateElement.setAttribute('role', 'region');
            candidateElement.setAttribute('aria-label', `Single candidate: ${candidate.first_name} ${candidate.last_name}`);
            
            candidateElement.innerHTML = `
                <div class="flex items-center space-x-4 mb-4">
                    <div class="flex-shrink-0 relative">
                        <img class="h-14 w-14 rounded-full object-cover border-2 border-blue-500 lazy" 
                             data-src="${photoUrl}" 
                             src="${BASE_URL}/assets/images/placeholder-avatar.png"
                             alt="${candidate.first_name} ${candidate.last_name}"
                             onerror="this.onerror=null; this.src='${BASE_URL}/assets/images/default-avatar.png'">
                        <button class="absolute -bottom-1 -right-1 bg-blue-600 text-white p-1 rounded-full text-xs candidate-profile-btn" data-candidate-id="${candidate.id}" aria-label="View ${candidate.first_name} ${candidate.last_name}'s profile">
                            <i class="fas fa-user" aria-hidden="true"></i>
                        </button>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-md font-semibold text-blue-900">${candidate.first_name} ${candidate.last_name}</p>
                        <p class="text-xs text-blue-700 mt-1">${candidate.department || 'General Candidate'}</p>
                        ${candidate.level ? `<p class="text-xs text-blue-600 mt-1">Level ${candidate.level}</p>` : ''}
                    </div>
                </div>
                ${candidate.manifesto ? `<div class="mt-3 text-xs text-blue-800 bg-blue-100 p-3 rounded-lg"><strong class="text-blue-700">Manifesto:</strong> ${candidate.manifesto.substring(0, 120)}${candidate.manifesto.length > 120 ? '...' : ''}</div>` : ''}
                <div class="mt-4 p-3 bg-blue-100 border border-blue-300 rounded-lg text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    This is the only candidate for this position. Do you approve of this candidate?
                </div>
                <div class="mt-4 flex space-x-4">
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="radio" name="position-${positionId}" value="${candidate.id}" 
                               class="h-5 w-5 text-green-600 focus:ring-green-500 border-gray-300 candidate-radio">
                        <span class="text-sm font-medium text-green-800">Yes, I approve</span>
                    </label>
                    <label class="flex items-center space-x-2 cursor-pointer">
                        <input type="radio" name="position-${positionId}" value="no" 
                               class="h-5 w-5 text-red-600 focus:ring-red-500 border-gray-300 candidate-radio">
                        <span class="text-sm font-medium text-red-800">No, I don't approve</span>
                    </label>
                </div>
            `;
            
            // Add click event to profile button
            candidateElement.querySelector('.candidate-profile-btn').addEventListener('click', (e) => {
                e.stopPropagation();
                showCandidateProfile(candidate.id);
            });
            
            // Add change event to radio buttons
            const radioButtons = candidateElement.querySelectorAll('.candidate-radio');
            radioButtons.forEach(radio => {
                radio.addEventListener('change', (e) => {
                    if (e.target.checked) {
                        if (e.target.value === 'no') {
                            selectedCandidates[positionId] = 'no';
                            showSuccess(`Recorded "No" vote for ${candidate.first_name} ${candidate.last_name}`);
                            
                            // Log vote selection
                            logActivity('vote_selected', `Selected NO for position ${positionId}`, { 
                                position_id: positionId, 
                                candidate_id: 'no' 
                            });
                        } else {
                            selectedCandidates[positionId] = candidate.id;
                            showSuccess(`Selected ${candidate.first_name} ${candidate.last_name}`);
                            
                            // Log vote selection
                            logActivity('vote_selected', `Selected candidate for position ${positionId}`, { 
                                position_id: positionId, 
                                candidate_id: candidate.id,
                                candidate_name: `${candidate.first_name} ${candidate.last_name}`
                            });
                        }
                        
                        // Update progress
                        updateProgress();
                    }
                });
            });
            
            // Pre-select if this position was previously voted on
            if (selectedCandidates[positionId]) {
                if (selectedCandidates[positionId] === 'no') {
                    candidateElement.querySelector('input[value="no"]').checked = true;
                } else if (selectedCandidates[positionId] === candidate.id) {
                    candidateElement.querySelector(`input[value="${candidate.id}"]`).checked = true;
                }
            }
            
            container.appendChild(candidateElement);
            
        } else {
            // Multiple candidates - normal flow
            candidates.forEach(candidate => {
                const photoUrl = candidate.photo_path || `${BASE_URL}/assets/images/default-avatar.png`;
                
                const candidateElement = document.createElement('div');
                candidateElement.className = 'candidate-card bg-white border border-gray-200 rounded-lg p-4 mb-4 hover:border-pink-300 cursor-pointer transition-all duration-200';
                candidateElement.setAttribute('role', 'option');
                candidateElement.setAttribute('aria-selected', selectedCandidates[positionId] == candidate.id ? 'true' : 'false');
                candidateElement.tabIndex = 0;
                candidateElement.innerHTML = `
                    <div class="flex items-center space-x-4">
                        <div class="flex-shrink-0 relative">
                            <img class="h-14 w-14 rounded-full object-cover border-2 border-gray-200 lazy" 
                                 data-src="${photoUrl}" 
                                 src="${BASE_URL}/assets/images/placeholder-avatar.png"
                                 alt="${candidate.first_name} ${candidate.last_name}"
                                 onerror="this.onerror=null; this.src='${BASE_URL}/assets/images/default-avatar.png'">
                            <button class="absolute -bottom-1 -right-1 bg-pink-600 text-white p-1 rounded-full text-xs candidate-profile-btn" data-candidate-id="${candidate.id}" aria-label="View ${candidate.first_name} ${candidate.last_name}'s profile">
                                <i class="fas fa-user" aria-hidden="true"></i>
                            </button>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-md font-semibold text-gray-900">${candidate.first_name} ${candidate.last_name}</p>
                            <p class="text-xs text-gray-500 mt-1">${candidate.department || 'General Candidate'}</p>
                            ${candidate.level ? `<p class="text-xs text-gray-400 mt-1">Level ${candidate.level}</p>` : ''}
                        </div>
                        <div class="flex-shrink-0">
                            <input type="radio" name="position-${positionId}" value="${candidate.id}" 
                                   class="h-5 w-5 text-pink-600 focus:ring-pink-500 border-gray-300 candidate-radio sr-only">
                            <div class="h-5 w-5 rounded-full border-2 border-gray-300 flex items-center justify-center candidate-radio-visual">
                                ${selectedCandidates[positionId] == candidate.id ? '<div class="h-3 w-3 rounded-full bg-pink-600"></div>' : ''}
                            </div>
                        </div>
                    </div>
                    ${candidate.manifesto ? `<div class="mt-3 text-xs text-gray-600 bg-gray-50 p-3 rounded-lg"><strong class="text-pink-600">Manifesto:</strong> ${candidate.manifesto.substring(0, 120)}${candidate.manifesto.length > 120 ? '...' : ''}</div>` : ''}
                `;
                
                // Add click event to select candidate
                candidateElement.addEventListener('click', (e) => {
                    if (!e.target.matches('input[type="radio"]') && !e.target.closest('.candidate-profile-btn')) {
                        const radio = candidateElement.querySelector('.candidate-radio');
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change'));
                    }
                });
                
                // Add keyboard support
                candidateElement.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        const radio = candidateElement.querySelector('.candidate-radio');
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change'));
                    }
                });
                
                // Add click event to profile button
                candidateElement.querySelector('.candidate-profile-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    showCandidateProfile(candidate.id);
                });
                
                // Add change event to radio button
                const radio = candidateElement.querySelector('.candidate-radio');
                radio.addEventListener('change', (e) => {
                    if (e.target.checked) {
                        selectedCandidates[positionId] = candidate.id;
                        // Highlight selected candidate
                        document.querySelectorAll(`#candidates-${positionId} .candidate-card`).forEach(card => {
                            card.classList.remove('border-pink-500', 'bg-pink-50', 'ring-2', 'ring-pink-300');
                            card.setAttribute('aria-selected', 'false');
                            card.querySelector('.candidate-radio-visual').innerHTML = '';
                        });
                        candidateElement.classList.add('border-pink-500', 'bg-pink-50', 'ring-2', 'ring-pink-300');
                        candidateElement.setAttribute('aria-selected', 'true');
                        candidateElement.querySelector('.candidate-radio-visual').innerHTML = '<div class="h-3 w-3 rounded-full bg-pink-600"></div>';
                        
                        // Show selection confirmation
                        const candidateName = `${candidate.first_name} ${candidate.last_name}`;
                        showSuccess(`Selected ${candidateName} for this position`);
                        
                        // Log vote selection
                        logActivity('vote_selected', `Selected candidate for position ${positionId}`, { 
                            position_id: positionId, 
                            candidate_id: candidate.id,
                            candidate_name: candidateName
                        });
                        
                        // Update progress
                        updateProgress();
                    }
                });
                
                // Pre-select if this candidate was previously selected
                if (selectedCandidates[positionId] == candidate.id) {
                    radio.checked = true;
                    candidateElement.classList.add('border-pink-500', 'bg-pink-50', 'ring-2', 'ring-pink-300');
                    candidateElement.setAttribute('aria-selected', 'true');
                    candidateElement.querySelector('.candidate-radio-visual').innerHTML = '<div class="h-3 w-3 rounded-full bg-pink-600"></div>';
                }
                
                container.appendChild(candidateElement);
            });
        }

        // Lazy load images
        lazyLoadImages();
    } catch (error) {
        // Remove loading overlay on error
        if (loadingOverlay.parentNode) {
            loadingOverlay.remove();
        }
        
        console.error('Error loading candidates:', error);
        container.innerHTML = `
            <div class="text-center py-4 text-red-600 bg-red-50 rounded-lg">
                <i class="fas fa-exclamation-triangle text-xl mb-2" aria-hidden="true"></i>
                <p class="text-sm font-medium">Failed to load candidates</p>
                <p class="text-xs mt-1 text-red-500">${error.message}</p>
                <button onclick="loadCandidates(${positionId})" class="mt-2 bg-red-600 hover:bg-red-700 text-white px-3 py-1.5 rounded text-xs">
                    Try Again
                </button>
            </div>
        `;
        
        // Log error
        logActivity('candidate_load_error', 'Error loading candidates', { 
            position_id: positionId, 
            error: error.message 
        });
    }
}

// Lazy load images
function lazyLoadImages() {
    const lazyImages = document.querySelectorAll('img.lazy');
    
    if ('IntersectionObserver' in window) {
        const imageObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const img = entry.target;
                    img.src = img.dataset.src;
                    img.classList.remove('lazy');
                    imageObserver.unobserve(img);
                }
            });
        });

        lazyImages.forEach(img => {
            imageObserver.observe(img);
        });
    } else {
        // Fallback for browsers without IntersectionObserver
        lazyImages.forEach(img => {
            img.src = img.dataset.src;
            img.classList.remove('lazy');
        });
    }
}

// Function to filter candidates based on search query
function filterCandidates(query) {
    if (!query || query.length < 2) {
        // Show all candidates if query is too short or empty
        document.querySelectorAll('.candidate-card').forEach(card => {
            card.style.display = 'flex';
        });
        return;
    }
    
    const searchTerm = query.toLowerCase();
    let anyMatches = false;
    
    document.querySelectorAll('.candidate-card').forEach(card => {
        const candidateName = card.querySelector('p.text-md').textContent.toLowerCase();
        const candidateDepartment = card.querySelector('p.text-xs.text-gray-500')?.textContent.toLowerCase() || '';
        const candidateManifesto = card.querySelector('.text-gray-600')?.textContent.toLowerCase() || '';
        
        if (candidateName.includes(searchTerm) || 
            candidateDepartment.includes(searchTerm) || 
            candidateManifesto.includes(searchTerm)) {
            card.style.display = 'flex';
            anyMatches = true;
            
            // Highlight the matching text
            if (typeof Mark !== 'undefined') {
                const mark = new Mark(card);
                mark.unmark().mark(searchTerm);
            }
        } else {
            card.style.display = 'none';
        }
    });
    
    // Show message if no matches found
    const containers = document.querySelectorAll('.candidates-container');
    containers.forEach(container => {
        const visibleCandidates = container.querySelectorAll('.candidate-card[style="display: flex"]');
        if (visibleCandidates.length === 0 && container.querySelector('.candidate-card')) {
            const noResults = container.querySelector('.no-results') || document.createElement('div');
            noResults.className = 'no-results text-center py-4 text-gray-500';
            noResults.innerHTML = `<p>No candidates match "${query}"</p>`;
            
            if (!container.querySelector('.no-results')) {
                container.appendChild(noResults);
            }
        } else {
            const noResults = container.querySelector('.no-results');
            if (noResults) {
                noResults.remove();
            }
        }
    });
}

// Helper function to format date
function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
}

// Function to show candidate profile modal
function showCandidateProfile(candidateId) {
    const candidate = candidateDetails[candidateId];
    if (!candidate) return;
    
    document.getElementById('candidate-modal-title').textContent = `${candidate.first_name} ${candidate.last_name}'s Profile`;
    
    const modalContent = document.getElementById('candidate-modal-content');
    modalContent.innerHTML = `
        <div class="flex flex-col md:flex-row gap-6">
            <div class="md:w-1/3">
                <img src="${candidate.photo_path || `${BASE_URL}/assets/images/default-avatar.png`}" 
                     alt="${candidate.first_name} ${candidate.last_name}" 
                     class="w-full h-auto rounded-lg object-cover shadow-md">
            </div>
            <div class="md:w-2/3">
                <h4 class="text-lg font-semibold text-gray-800 mb-2">${candidate.first_name} ${candidate.last_name}</h4>
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <p class="text-xs text-gray-500">Department</p>
                        <p class="text-sm font-medium">${candidate.department || 'Not specified'}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Level</p>
                        <p class="text-sm font-medium">${candidate.level || 'Not specified'}</p>
                    </div>
                </div>
                <div class="mb-4">
                    <p class="text-xs text-gray-500">Manifesto</p>
                    <p class="text-sm text-gray-800 mt-1">${candidate.manifesto || 'No manifesto provided.'}</p>
                </div>
                <div class="mb-4">
                    <p class="text-xs text-gray-500">Qualifications</p>
                    <p class="text-sm text-gray-800 mt-1">${candidate.qualifications || 'No qualifications provided.'}</p>
                </div>
                <div class="flex gap-2">
                    <button class="bg-pink-100 hover:bg-pink-200 text-pink-800 text-sm px-3 py-1 rounded-full">
                        <i class="fas fa-user-circle mr-1" aria-hidden="true"></i> Candidate
                    </button>
                    <button class="bg-blue-100 hover:bg-blue-200 text-blue-800 text-sm px-3 py-1 rounded-full compare-candidate-btn" data-candidate-id="${candidateId}">
                        <i class="fas fa-balance-scale mr-1" aria-hidden="true"></i> Compare
                    </button>
                </div>
            </div>
        </div>
    `;
    
    // Add event listener to compare button
    modalContent.querySelector('.compare-candidate-btn').addEventListener('click', function() {
        const candidateId = this.getAttribute('data-candidate-id');
        showCompareModal(candidateId);
    });
    
    document.getElementById('candidate-modal').classList.remove('hidden');
    document.getElementById('candidate-modal').setAttribute('aria-hidden', 'false');
    
    // Log profile view
    logActivity('candidate_profile_view', `Viewed profile for candidate ID: ${candidateId}`, { 
        candidate_id: candidateId, 
        candidate_name: `${candidate.first_name} ${candidate.last_name}` 
    });
}

// Function to show compare modal
function showCompareModal(selectedCandidateId) {
    const positionId = Object.keys(selectedCandidates).find(posId => 
        document.querySelector(`#candidates-${posId} input[value="${selectedCandidateId}"]`)
    );
    
    if (!positionId) return;
    
    const candidates = Array.from(document.querySelectorAll(`#candidates-${positionId} .candidate-card`)).map(card => {
        const candidateId = card.querySelector('input').value;
        return candidateDetails[candidateId];
    }).filter(candidate => candidate);
    
    const modalContent = document.getElementById('compare-modal-content');
    modalContent.innerHTML = '';
    
    candidates.forEach(candidate => {
        const isSelected = selectedCandidateId == candidate.id;
        const candidateCard = document.createElement('div');
        candidateCard.className = `bg-white border rounded-lg p-4 ${isSelected ? 'border-pink-500 ring-2 ring-pink-200' : 'border-gray-200'}`;
        candidateCard.innerHTML = `
            <div class="text-center mb-4">
                <img src="${candidate.photo_path || `${BASE_URL}/assets/images/default-avatar.png`}" 
                     alt="${candidate.first_name} ${candidate.last_name}" 
                     class="w-20 h-20 rounded-full mx-auto object-cover border-2 ${isSelected ? 'border-pink-500' : 'border-gray-200'}">
                <h4 class="text-lg font-semibold mt-2">${candidate.first_name} ${candidate.last_name}</h4>
                <p class="text-sm text-gray-600">${candidate.department || ''}</p>
                ${isSelected ? '<span class="inline-block bg-pink-100 text-pink-800 text-xs px-2 py-1 rounded-full mt-1">Your Selection</span>' : ''}
            </div>
            <div class="space-y-3">
                <div>
                    <p class="text-xs text-gray-500">Level</p>
                    <p class="text-sm font-medium">${candidate.level || 'Not specified'}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Manifesto</p>
                    <p class="text-sm text-gray-800 line-clamp-3">${candidate.manifesto || 'No manifesto provided.'}</p>
                </div>
                <div>
                    <p class="text-xs text-gray-500">Qualifications</p>
                    <p class="text-sm text-gray-800 line-clamp-2">${candidate.qualifications || 'No qualifications provided.'}</p>
                </div>
            </div>
        `;
        modalContent.appendChild(candidateCard);
    });
    
    document.getElementById('candidate-modal').classList.add('hidden');
    document.getElementById('candidate-modal').setAttribute('aria-hidden', 'true');
    document.getElementById('compare-modal').classList.remove('hidden');
    document.getElementById('compare-modal').setAttribute('aria-hidden', 'false');
    
    // Log compare action
    logActivity('candidate_compare', 'Compared candidates', { 
        position_id: positionId, 
        selected_candidate: selectedCandidateId,
        total_candidates: candidates.length
    });
}

// Function to toggle high contrast mode
function toggleHighContrast() {
    document.body.classList.toggle('high-contrast');
    localStorage.setItem('highContrastMode', document.body.classList.contains('high-contrast'));
    
    if (document.body.classList.contains('high-contrast')) {
        showSuccess('High contrast mode enabled');
        logActivity('high_contrast_enabled', 'High contrast mode enabled');
    } else {
        showSuccess('High contrast mode disabled');
        logActivity('high_contrast_disabled', 'High contrast mode disabled');
    }
}

// Function to show success message
function showSuccess(message) {
    // Create or show success notification
    let successDiv = document.getElementById('success-notification');
    if (!successDiv) {
        successDiv = document.createElement('div');
        successDiv.id = 'success-notification';
        successDiv.className = 'fixed top-4 right-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg shadow-lg z-50';
        document.body.appendChild(successDiv);
    }
    
    successDiv.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-check-circle mr-3" aria-hidden="true"></i>
            <span>${message}</span>
            <button class="ml-4 text-green-700 hover:text-green-900" onclick="this.parentElement.parentElement.remove()" aria-label="Dismiss success message">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
    `;
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (successDiv.parentElement) {
            successDiv.remove();
        }
    }, 5000);
}

// Function to save progress to local storage
function saveProgress() {
    if (currentElectionId && Object.keys(selectedCandidates).length > 0) {
        const progressData = {
            electionId: currentElectionId,
            selections: selectedCandidates,
            timestamp: new Date().getTime()
        };
        
        localStorage.setItem(`voteProgress_${currentElectionId}`, JSON.stringify(progressData));
        showSuccess('Progress saved locally!');
        
        // Log progress save
        logActivity('progress_saved', 'Vote progress saved locally', { 
            election_id: currentElectionId,
            selections_count: Object.keys(selectedCandidates).length
        });
    }
}

// Function to load progress from local storage
function loadProgress(electionId) {
    const savedProgress = localStorage.getItem(`voteProgress_${electionId}`);
    if (savedProgress) {
        try {
            const progressData = JSON.parse(savedProgress);
            
            // Check if the saved progress is for the current election and not too old (e.g., 24 hours)
            const now = new Date().getTime();
            if (progressData.electionId === electionId && (now - progressData.timestamp) < 24 * 60 * 60 * 1000) {
                selectedCandidates = progressData.selections;
                
                // Update UI to reflect saved selections
                Object.keys(selectedCandidates).forEach(positionId => {
                    const radio = document.querySelector(`input[name="position-${positionId}"][value="${selectedCandidates[positionId]}"]`);
                    if (radio) {
                        radio.checked = true;
                        radio.dispatchEvent(new Event('change'));
                    }
                });
                
                showSuccess('Previous progress loaded!');
                
                // Log progress load
                logActivity('progress_loaded', 'Saved vote progress loaded', { 
                    election_id: electionId,
                    selections_count: Object.keys(selectedCandidates).length
                });
                
                return true;
            }
        } catch (e) {
            console.error('Error loading saved progress:', e);
        }
    }
    return false;
}

// Enhanced election change handler with better validation
function handleElectionChange(event) {
    const electionId = event.target.value;
    const selectedOption = event.target.options[event.target.selectedIndex];
    
    // Enhanced check if user has already voted for this election
    if (electionId && hasUserVoted(electionId)) {
        showError('You have already cast a vote for this election. Cannot vote again.');
        
        // Log blocked attempt
        logActivity('election_change_blocked', 'Attempted to change to already voted election', { 
            election_id: electionId 
        });
        
        // Reset selection to previous value or empty
        event.target.value = currentElectionId || '';
        
        // Show appropriate message based on vote status
        if (sessionStorage.getItem(`hasVotedLocally_${electionId}`) === 'true') {
            showError('Your vote for this election is pending synchronization.');
        } else {
            showError('You have already voted in this election.');
        }
        return;
    }
    
    if (!electionId) {
        document.getElementById('election-info').classList.add('hidden');
        document.getElementById('positions-container').innerHTML = '<p class="text-center text-gray-500">Select an election to view positions</p>';
        document.getElementById('category-navigation').classList.add('hidden');
        document.getElementById('category-navigation-buttons').classList.add('hidden');
        document.getElementById('current-category').classList.add('hidden');
        document.getElementById('review-section').classList.add('hidden');
        return;
    }
    
    // Log election selection
    logActivity('election_selected', `Selected election ID: ${electionId}`, { 
        election_id: electionId, 
        election_title: selectedOption.textContent 
    });
    
    currentElectionId = electionId;
    selectedCandidates = {};
    currentCategoryIndex = 0;
    candidateDetails = {};
    
    // Reset progress
    completedPositions = 0;
    document.getElementById('vote-progress').style.width = '0%';
    document.getElementById('vote-progress').setAttribute('aria-valuenow', '0');
    document.getElementById('progress-percent').textContent = '0%';
    const completedText = document.querySelector('.flex.justify-between.mt-2 span:first-child');
    if (completedText) {
        completedText.textContent = '0 positions completed';
    }
    
    // Show election info
    const electionInfo = document.getElementById('election-info');
    document.getElementById('election-title').textContent = selectedOption.textContent;
    document.getElementById('election-description').textContent = selectedOption.getAttribute('data-description');
    document.getElementById('election-start').textContent = formatDate(selectedOption.getAttribute('data-start-date'));
    document.getElementById('election-end').textContent = formatDate(selectedOption.getAttribute('data-end-date'));
    electionInfo.classList.remove('hidden');
    
    // Start time left counter
    updateTimeLeft(selectedOption.getAttribute('data-end-date'));
    
    // Hide review section and show positions container
    document.getElementById('review-section').classList.add('hidden');
    document.getElementById('positions-container').classList.remove('hidden');
    
    loadPositionsByCategory(electionId);
}

// Function to load positions by category
async function loadPositionsByCategory(electionId) {
    const container = document.getElementById('positions-container');
    container.innerHTML = `
        <div class="text-center py-12">
            <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-pink-600"></div>
            <p class="mt-4 text-gray-500">Loading positions...</p>
        </div>
    `;
    
    try {
        const response = await fetch(`${BASE_URL}/api/voting/positions.php?election_id=${electionId}`);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (!data.success) {
            throw new Error(data.message || 'Failed to fetch positions');
        }
        
        const positions = data.positions || [];
        totalPositions = positions.length;
        document.getElementById('total-positions').textContent = `${totalPositions} positions total`;
        
        // Log positions loaded
        logActivity('positions_loaded', `Loaded ${positions.length} positions for election ${electionId}`, { 
            election_id: electionId, 
            positions_count: positions.length 
        });
        
        if (positions.length === 0) {
            container.innerHTML = '<p class="text-center text-gray-500 py-12">No positions found for this election</p>';
            return;
        }
        
        // Group positions by category
        categoryPositions = {};
        positions.forEach(position => {
            const category = position.category || 'General';
            if (!categoryPositions[category]) {
                categoryPositions[category] = [];
            }
            categoryPositions[category].push(position);
        });
        
        // Get all unique categories from the database
        categories = Object.keys(categoryPositions);
        
        // Show category navigation
        document.getElementById('category-navigation').classList.remove('hidden');
        
        // Update category tabs
        updateCategoryTabs();
        
        // Try to load saved progress
        loadProgress(electionId);
        
        // Show the first category
        showCategory(0);
        
        // Update progress
        updateProgress();
        
    } catch (error) {
        console.error('Error loading positions:', error);
        container.innerHTML = `
            <div class="text-center py-12 text-red-600">
                <i class="fas fa-exclamation-triangle text-4xl mb-4" aria-hidden="true"></i>
                <p class="text-lg font-semibold">Failed to load positions</p>
                <p class="text-sm mt-2">${error.message}</p>
                <button onclick="loadPositionsByCategory(${electionId})" class="mt-4 bg-pink-900 hover:bg-pink-800 text-white px-5 py-2.5 rounded-lg">
                    Try Again
                </button>
            </div>
        `;
        
        // Log error
        logActivity('positions_load_error', 'Error loading positions', { 
            election_id: electionId, 
            error: error.message 
        });
    }
}

// Debounce function to prevent multiple rapid API calls
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

// Add keyboard navigation support
function addKeyboardNavigation() {
    document.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') {
            document.getElementById('next-category')?.click();
        } else if (e.key === 'ArrowLeft') {
            document.getElementById('prev-category')?.click();
        } else if (e.key === 'Enter') {
            const focusedElement = document.activeElement;
            if (focusedElement.classList.contains('candidate-card')) {
                focusedElement.querySelector('.candidate-radio')?.click();
            }
        } else if (e.key === 'Escape') {
            // Close any open modals
            if (!document.getElementById('candidate-modal').classList.contains('hidden')) {
                document.getElementById('close-candidate-modal').click();
            } else if (!document.getElementById('compare-modal').classList.contains('hidden')) {
                document.getElementById('close-compare-modal').click();
            } else if (!document.getElementById('confirmation-modal').classList.contains('hidden')) {
                document.getElementById('cancel-confirmation').click();
            }
        }
    });
}

// Initialize mobile features
function initMobileFeatures() {
    // Prevent zoom on input focus for iOS
    document.addEventListener('touchstart', function() {
        if (document.activeElement.tagName === 'INPUT' || 
            document.activeElement.tagName === 'SELECT' ||
            document.activeElement.tagName === 'TEXTAREA') {
            document.activeElement.style.fontSize = '16px';
        }
    });
    
    // Handle touch events for candidate selection
    document.addEventListener('touchstart', function(e) {
        const candidateCard = e.target.closest('.candidate-card');
        if (candidateCard && !e.target.matches('input[type="radio"]')) {
            // Add visual feedback for touch
            candidateCard.classList.add('bg-pink-100');
        }
    }, { passive: true });
    
    document.addEventListener('touchend', function(e) {
        const candidateCard = e.target.closest('.candidate-card');
        if (candidateCard) {
            candidateCard.classList.remove('bg-pink-100');
        }
    }, { passive: true });
    
    // Swipe detection for category navigation
    let touchStartX = 0;
    let touchEndX = 0;
    
    document.addEventListener('touchstart', e => {
        touchStartX = e.changedTouches[0].screenX;
    }, { passive: true });
    
    document.addEventListener('touchend', e => {
        touchEndX = e.changedTouches[0].screenX;
        handleSwipe();
    }, { passive: true });
    
    function handleSwipe() {
        const swipeThreshold = 50;
        const swipeDistance = touchEndX - touchStartX;
        
        if (Math.abs(swipeDistance) > swipeThreshold) {
            if (swipeDistance > 0) {
                // Swipe right - previous category
                document.getElementById('prev-category')?.click();
            } else {
                // Swipe left - next category
                document.getElementById('next-category')?.click();
            }
        }
    }
}

// Optimize images for mobile
function optimizeImagesForMobile() {
    if (window.innerWidth < 768) {
        document.querySelectorAll('img').forEach(img => {
            // Lazy load images that are not in viewport
            if (!img.hasAttribute('loading')) {
                img.setAttribute('loading', 'lazy');
            }
            
            // Add low-quality image placeholder for candidates
            if (img.src.includes('candidate') || img.closest('.candidate-card')) {
                img.addEventListener('load', function() {
                    this.classList.add('loaded');
                });
                
                if (!img.complete) {
                    img.classList.add('opacity-0');
                }
            }
        });
    }
}

// Enhanced online status check with better detection
function checkOnlineStatus() {
    const wasOnline = isOnline;
    isOnline = navigator.onLine;
    
    console.log('Online status changed:', { wasOnline, isOnline });
    
    const offlineStatus = document.getElementById('offline-status');
    const offlineNotice = document.getElementById('offline-notice');
    const manualSync = document.getElementById('manual-sync');
    
    if (isOnline) {
        offlineStatus.classList.add('hidden');
        offlineNotice.classList.add('hidden');
        
        // Show sync button when coming online with pending votes
        const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
        const currentUserId = CURRENT_USER_ID;
        const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
        
        if (userPendingVotes.length > 0) {
            manualSync.classList.remove('hidden');
            manualSync.innerHTML = `<i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> Sync Votes (${userPendingVotes.length})`;
            
            // Auto-sync after a short delay
            setTimeout(() => {
                console.log('Auto-syncing after coming online...');
                logActivity('auto_sync_triggered', 'Auto-sync triggered after coming online', { 
                    pending_count: userPendingVotes.length 
                });
                submitPendingVotesWithRetry();
            }, 2000);
        }
        
    } else {
        offlineStatus.classList.remove('hidden');
        offlineNotice.classList.remove('hidden');
        manualSync.classList.add('hidden');
        
        // Check if user has pending votes
        const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
        const currentUserId = CURRENT_USER_ID;
        const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
        
        if (userPendingVotes.length > 0) {
            showError('You have pending votes that will be synced when online');
        }
    }
    
    // Update sync button visibility
    updateSyncButtonVisibility();
}

// Enhanced error logging function
function logSyncError(error, context = '') {
    console.error('Sync Error:', {
        context,
        error: error.message,
        stack: error.stack,
        timestamp: new Date().toISOString(),
        online: navigator.onLine,
        pendingVotes: JSON.parse(localStorage.getItem('pendingVotes') || '[]').length
    });
    
    // Log to server
    logActivity('sync_error', `Sync error: ${context} - ${error.message}`, { 
        context, 
        error: error.message 
    });
}

// Enhanced function with retry logic for submitting pending votes
async function submitPendingVotesWithRetry(maxRetries = 3) {
    console.log('Starting sync with retry logic, max attempts:', maxRetries);
    
    for (let attempt = 1; attempt <= maxRetries; attempt++) {
        try {
            console.log(`Sync attempt ${attempt} of ${maxRetries}`);
            await submitPendingVotes();
            
            // Check if all votes were successful
            const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
            const currentUserId = CURRENT_USER_ID;
            const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
            
            if (userPendingVotes.length === 0) {
                console.log('All votes synced successfully, exiting retry loop');
                break; // Success, exit retry loop
            } else {
                console.log(`${userPendingVotes.length} votes still pending, will retry if attempts remain`);
            }
            
        } catch (error) {
            console.error(`Sync attempt ${attempt} failed:`, error);
            
            if (attempt === maxRetries) {
                console.error('All sync attempts failed');
                showError('Failed to sync votes after ' + maxRetries + ' attempts. Please try manual sync.');
                break;
            }
            
            // Wait before retrying (exponential backoff)
            const delay = Math.pow(2, attempt) * 1000;
            console.log(`Waiting ${delay}ms before next attempt...`);
            await new Promise(resolve => setTimeout(resolve, delay));
        }
    }
}

// Enhanced submitPendingVotes function with better error handling
async function submitPendingVotes() {
    const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
    const currentUserId = CURRENT_USER_ID;
    
    console.log('Attempting to sync pending votes:', { total: pendingVotes.length, currentUserId });
    
    if (pendingVotes.length === 0) {
        console.log('No pending votes to sync');
        return;
    }

    const successfulSubmissions = [];
    const failedSubmissions = [];

    // Filter votes for current user only
    const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
    
    console.log('User pending votes:', userPendingVotes.length);

    if (userPendingVotes.length === 0) {
        console.log('No pending votes for current user');
        return;
    }

    // Log sync start
    logActivity('sync_started', 'Starting sync of pending votes', { 
        pending_count: userPendingVotes.length 
    });

    for (const voteData of userPendingVotes) {
        try {
            console.log('Syncing vote for election:', voteData.election_id);
            
            const response = await fetchWithTimeout(
                BASE_URL + '/api/voting/sync-pending-votes.php', 
                {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        pending_votes: [voteData]
                    })
                },
                10000 // 10 second timeout
            );
            
            console.log('Sync response status:', response.status);
            
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            
            const result = await response.json();
            console.log('Sync API response:', result);
            
            if (result.success) {
                successfulSubmissions.push(voteData);
                console.log('Pending vote submitted successfully:', voteData);
                
                // Log successful sync
                logActivity('vote_synced', 'Pending vote synced successfully', { 
                    election_id: voteData.election_id 
                });
                
                // Update session status to reflect voting
                updateSessionStatus(true);
                
                // Remove local voting flags for this election
                sessionStorage.removeItem(`hasVotedLocally_${voteData.election_id}`);
            } else {
                failedSubmissions.push({
                    voteData,
                    reason: result.message || 'Sync failed'
                });
                console.warn('Sync API returned failure:', result.message);
            }
        } catch (error) {
            console.error('Error submitting pending vote:', error);
            failedSubmissions.push({
                voteData,
                reason: error.message
            });
        }
    }
    
    // Remove successfully submitted votes from pending
    if (successfulSubmissions.length > 0) {
        const updatedPendingVotes = pendingVotes.filter(vote => 
            !successfulSubmissions.some(success => 
                success.election_id == vote.election_id &&
                success.user_id === vote.user_id
            )
        );
        localStorage.setItem('pendingVotes', JSON.stringify(updatedPendingVotes));
        
        // Show success notification
        showSuccess(`${successfulSubmissions.length} pending vote(s) synchronized successfully!`);
        console.log('Successfully synced votes:', successfulSubmissions.length);
        
        // Log sync completion
        logActivity('sync_completed', 'Sync completed', { 
            successful: successfulSubmissions.length,
            failed: failedSubmissions.length
        });
    }
    
    // Handle failed submissions
    if (failedSubmissions.length > 0) {
        console.warn('Some votes failed to sync:', failedSubmissions);
        
        // Show appropriate error message
        if (failedSubmissions.length === userPendingVotes.length) {
            showError('Failed to sync votes. They will be retried automatically.');
        } else {
            showError(`${failedSubmissions.length} vote(s) failed to sync. They will be retried.`);
        }
        
        // Log failures
        logActivity('sync_partial_failure', 'Some votes failed to sync', { 
            failed_count: failedSubmissions.length 
        });
    }
    
    // Update sync button visibility
    updateSyncButtonVisibility();
}

// Update sync button visibility
function updateSyncButtonVisibility() {
    const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
    const manualSyncButton = document.getElementById('manual-sync');
    
    if (pendingVotes.length > 0 && !isOnline) {
        manualSyncButton.classList.remove('hidden');
        manualSyncButton.innerHTML = `<i class="fas fa-sync-alt mr-1" aria-hidden="true"></i> Sync (${pendingVotes.length})`;
    } else {
        manualSyncButton.classList.add('hidden');
    }
}

// Add Periodic Sync Check
function startPeriodicSyncCheck() {
    setInterval(() => {
        if (isOnline) {
            const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
            const currentUserId = CURRENT_USER_ID;
            const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
            
            if (userPendingVotes.length > 0) {
                console.log('Periodic sync check: Found pending votes, attempting sync...');
                logActivity('periodic_sync', 'Periodic sync check triggered', { 
                    pending_count: userPendingVotes.length 
                });
                submitPendingVotesWithRetry();
            }
        }
    }, 60000); // Check every minute
}

// Enhanced return to home functionality
function setupReturnToHomeButton() {
    const returnHomeButton = document.getElementById('return-home-btn');
    if (returnHomeButton) {
        returnHomeButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
            const currentUserId = CURRENT_USER_ID;
            const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
            
            if (userPendingVotes.length > 0) {
                // Show confirmation dialog with more information
                if (confirm(`You have ${userPendingVotes.length} vote(s) pending synchronization. These votes will continue syncing in the background. Return to home page?`)) {
                    // Log return to home with pending votes
                    logActivity('return_home_with_pending', 'Returned to home with pending votes', { 
                        pending_count: userPendingVotes.length 
                    });
                    
                    // Register for one more sync attempt before leaving
                    registerBackgroundSync();
                    
                    // Small delay to ensure sync registration
                    setTimeout(() => {
                        window.location.href = BASE_URL;
                    }, 500);
                }
            } else {
                // No pending votes, proceed immediately
                logActivity('return_home', 'Returned to home page');
                window.location.href = BASE_URL;
            }
        });
    }
}

// Initialize the page
document.addEventListener('DOMContentLoaded', async () => {
    console.log('Vote page loaded, BASE_URL:', BASE_URL);
    console.log('Current user ID:', CURRENT_USER_ID);
    
    // Validate storage ownership before doing anything else
    validateStorageOwnership();
    
    // Start periodic sync check
    startPeriodicSyncCheck();
    
    // Load high contrast mode preference
    if (localStorage.getItem('highContrastMode') === 'true') {
        document.body.classList.add('high-contrast');
    }
    
    // Check online status
    checkOnlineStatus();
    window.addEventListener('online', checkOnlineStatus);
    window.addEventListener('offline', checkOnlineStatus);
    
    // Add keyboard navigation
    addKeyboardNavigation();
    
    // Initialize mobile features
    initMobileFeatures();
    
    // Setup return to home button
    setupReturnToHomeButton();
    
    // Initialize page visibility handler
    handlePageVisibility();
    
    // Add event listeners
    document.getElementById('next-category').addEventListener('click', () => {
        if (currentCategoryIndex < categories.length - 1) {
            showCategory(currentCategoryIndex + 1);
        } else {
            showReviewSection();
        }
    });
    
    document.getElementById('prev-category').addEventListener('click', () => {
        if (currentCategoryIndex > 0) {
            showCategory(currentCategoryIndex - 1);
        }
    });
    
    document.getElementById('confirm-votes').addEventListener('click', submitVote);
    document.getElementById('edit-votes').addEventListener('click', backToVoting);
    document.getElementById('save-progress').addEventListener('click', saveProgress);
    document.getElementById('save-progress-btn').addEventListener('click', saveProgress);
    document.getElementById('toggle-contrast').addEventListener('click', toggleHighContrast);
    document.getElementById('toggle-help').addEventListener('click', () => {
        document.getElementById('help-panel').classList.toggle('hidden');
        logActivity('help_panel_toggled', 'Help panel ' + (document.getElementById('help-panel').classList.contains('hidden') ? 'closed' : 'opened'));
    });
    // ── Share modal helpers ────────────────────────────────────────────
    const SHARE_TEXT = document.getElementById('share-message').textContent.trim();
    const SHARE_URL  = window.location.origin;

    function openShareModal() {
        document.getElementById('share-modal').classList.remove('hidden');
        document.getElementById('share-modal').setAttribute('aria-hidden', 'false');
        logActivity('share_modal_opened', 'Share modal opened');
    }
    function closeShareModal() {
        document.getElementById('share-modal').classList.add('hidden');
        document.getElementById('share-modal').setAttribute('aria-hidden', 'true');
    }

    // Native share API (mobile devices)
    if (navigator.share) {
        document.getElementById('native-share-btn').classList.remove('hidden');
        document.getElementById('native-share-btn').addEventListener('click', async () => {
            try {
                await navigator.share({ title: 'I Voted — Nkoranza SHTs', text: SHARE_TEXT, url: SHARE_URL });
                logActivity('native_share_used', 'Native share API used');
            } catch(e) { /* user cancelled — no action needed */ }
        });
    }

    document.getElementById('share-vote').addEventListener('click', openShareModal);

    document.getElementById('close-share-modal').addEventListener('click', closeShareModal);

    document.getElementById('share-after-vote').addEventListener('click', () => {
        document.getElementById('success-modal').classList.add('hidden');
        document.getElementById('success-modal').setAttribute('aria-hidden', 'true');
        openShareModal();
    });

    // WhatsApp
    document.getElementById('share-whatsapp').addEventListener('click', () => {
        const url = 'https://wa.me/?text=' + encodeURIComponent(SHARE_TEXT + ' ' + SHARE_URL);
        window.open(url, '_blank', 'noopener');
        logActivity('share_whatsapp', 'Shared via WhatsApp');
    });

    // Facebook
    document.getElementById('share-facebook').addEventListener('click', () => {
        const url = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(SHARE_URL) + '&quote=' + encodeURIComponent(SHARE_TEXT);
        window.open(url, '_blank', 'noopener,width=600,height=400');
        logActivity('share_facebook', 'Shared via Facebook');
    });

    // Twitter / X
    document.getElementById('share-twitter').addEventListener('click', () => {
        const url = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(SHARE_TEXT) + '&url=' + encodeURIComponent(SHARE_URL);
        window.open(url, '_blank', 'noopener,width=600,height=400');
        logActivity('share_twitter', 'Shared via Twitter/X');
    });

    // Telegram
    document.getElementById('share-telegram').addEventListener('click', () => {
        const url = 'https://t.me/share/url?url=' + encodeURIComponent(SHARE_URL) + '&text=' + encodeURIComponent(SHARE_TEXT);
        window.open(url, '_blank', 'noopener');
        logActivity('share_telegram', 'Shared via Telegram');
    });

    // Copy message
    document.getElementById('copy-share-message').addEventListener('click', () => {
        const message = document.getElementById('share-message').textContent.trim();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(message).then(() => {
                showSuccess('Message copied to clipboard!');
                logActivity('share_message_copied', 'Share message copied to clipboard');
            });
        } else {
            // Fallback for older browsers
            const ta = document.createElement('textarea');
            ta.value = message;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select(); document.execCommand('copy');
            document.body.removeChild(ta);
            showSuccess('Message copied!');
        }
    });
    document.getElementById('close-candidate-modal').addEventListener('click', () => {
        document.getElementById('candidate-modal').classList.add('hidden');
        document.getElementById('candidate-modal').setAttribute('aria-hidden', 'true');
    });
    document.getElementById('close-compare-modal').addEventListener('click', () => {
        document.getElementById('compare-modal').classList.add('hidden');
        document.getElementById('compare-modal').setAttribute('aria-hidden', 'true');
    });
    
    // Add event listeners for confirmation modal
    document.getElementById('final-confirm-vote').addEventListener('click', finalizeVoteSubmission);
    document.getElementById('cancel-confirmation').addEventListener('click', () => {
        document.getElementById('confirmation-modal').classList.add('hidden');
        document.getElementById('confirmation-modal').setAttribute('aria-hidden', 'true');
        logActivity('confirmation_cancelled', 'Vote confirmation cancelled');
    });
    
    // Add event listener for vote receipt download
    document.getElementById('download-receipt').addEventListener('click', () => {
        const verificationCode = document.getElementById('vote-verification-code').textContent;
        const receiptData = {
            election: document.getElementById('election-title').textContent,
            verificationCode: verificationCode,
            timestamp: new Date().toLocaleString(),
            reference: document.getElementById('vote-reference').textContent
        };
        
        const blob = new Blob([JSON.stringify(receiptData, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `vote-receipt-${verificationCode}.json`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
        
        showSuccess('Vote receipt downloaded!');
        logActivity('receipt_downloaded', 'Vote receipt downloaded');
    });
    
    // Add event listener for copying verification code
    document.getElementById('copy-verification-code').addEventListener('click', () => {
        const verificationCode = document.getElementById('vote-verification-code').textContent;
        navigator.clipboard.writeText(verificationCode).then(() => {
            showSuccess('Verification code copied to clipboard!');
            logActivity('verification_code_copied', 'Verification code copied');
        });
    });
    
    // Add search functionality
    document.getElementById('search-toggle').addEventListener('click', () => {
        const searchBox = document.getElementById('search-box');
        searchBox.classList.toggle('hidden');
        
        if (!searchBox.classList.contains('hidden')) {
            document.getElementById('candidate-search').focus();
            logActivity('search_opened', 'Candidate search opened');
        } else {
            logActivity('search_closed', 'Candidate search closed');
        }
    });
    
    document.getElementById('candidate-search').addEventListener('input', (e) => {
        const query = e.target.value.trim();
        
        // Debounce the search to avoid performance issues
        if (searchTimeout) {
            clearTimeout(searchTimeout);
        }
        
        searchTimeout = setTimeout(() => {
            filterCandidates(query);
        }, 300);
    });
    
    // Add manual sync functionality with better feedback
    document.getElementById('manual-sync').addEventListener('click', async () => {
        const button = document.getElementById('manual-sync');
        const originalText = button.innerHTML;
        
        // Show loading state
        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-1" aria-hidden="true"></i> Syncing...';
        button.disabled = true;
        
        logActivity('manual_sync_started', 'Manual sync started');
        
        try {
            await submitPendingVotesWithRetry();
        } catch (error) {
            console.error('Manual sync failed:', error);
            showError('Manual sync failed: ' + error.message);
            logActivity('manual_sync_failed', 'Manual sync failed', { error: error.message });
        } finally {
            // Restore button state after a delay
            setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = false;
                updateSyncButtonVisibility();
            }, 2000);
        }
    });
    
    // Load elections if not already loaded server-side
    const electionSelect = document.getElementById('election-select');
    if (electionSelect.options.length <= 1) {
        try {
            const response = await fetch(`${BASE_URL}/api/voting/elections.php?status=active`);
            if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
            
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to fetch elections');
            }
            
            const elections = data.elections || [];
            
            electionSelect.innerHTML = '<option value="">Select an election</option>';
            
            if (elections.length === 0) {
                electionSelect.innerHTML = '<option value="">No active elections available</option>';
                return;
            }
            
            elections.forEach(election => {
                const option = document.createElement('option');
                option.value = election.id;
                option.textContent = election.title;
                option.setAttribute('data-description', election.description || '');
                option.setAttribute('data-start-date', election.start_date || '');
                option.setAttribute('data-end-date', election.end_date || '');
                electionSelect.appendChild(option);
            });
            
            // If there's only one election, select it automatically
            if (elections.length === 1) {
                electionSelect.value = elections[0].id;
                electionSelect.dispatchEvent(new Event('change'));
            }
            
            // Log elections loaded
            logActivity('elections_loaded', 'Active elections loaded', { count: elections.length });
            
        } catch (error) {
            console.error('Error loading elections:', error);
            showError('Failed to load elections. Please try again later.');
            
            // Fallback: try to load without the status parameter
            try {
                const response = await fetch(`${BASE_URL}/api/voting/elections.php`);
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                
                const data = await response.json();
                
                if (data.success) {
                    const elections = data.elections || [];
                    electionSelect.innerHTML = '<option value="">Select an election</option>';
                    
                    elections.forEach(election => {
                        const option = document.createElement('option');
                        option.value = election.id;
                        option.textContent = election.title;
                        option.setAttribute('data-description', election.description || '');
                        option.setAttribute('data-start-date', election.start_date || '');
                        option.setAttribute('data-end-date', election.end_date || '');
                        electionSelect.appendChild(option);
                    });
                }
            } catch (fallbackError) {
                console.error('Fallback error loading elections:', fallbackError);
                showError('Failed to load elections. Please contact support if this issue persists.');
            }
        }
    }
    
    electionSelect.addEventListener('change', handleElectionChange);
    
    // Check and restore voting state after elections are loaded
    setTimeout(restoreVotingState, 500);
    
    // Optimize images for mobile
    optimizeImagesForMobile();
    window.addEventListener('resize', debounce(optimizeImagesForMobile, 250));
    
    // Periodically check for pending votes (every 30 seconds)
    setInterval(checkOnlineStatus, 30000);
});

// Function to disable voting for admin users but allow viewing
function setupAdminViewMode() {
    console.log('Admin user detected - view mode enabled (voting disabled)');
    
    // Log admin view mode
    logActivity('admin_view_mode', 'Admin accessed voting page in view mode');
    
    // Add admin badge to the UI
    const quickActions = document.querySelector('.flex.flex-wrap.gap-2');
    if (quickActions) {
        const adminBadge = document.createElement('div');
        adminBadge.className = 'bg-blue-100 text-blue-800 px-3 py-2 rounded-lg text-sm font-medium';
        adminBadge.innerHTML = '<i class="fas fa-user-shield mr-1" aria-hidden="true"></i> Administrator (View Mode)';
        quickActions.prepend(adminBadge);
    }
    
    // Update the title and description
    const titleElement = document.querySelector('h2.text-3xl');
    if (titleElement) {
        titleElement.textContent = 'Election Viewer (Admin Mode)';
    }
    
    const descElement = document.querySelector('p.text-gray-600');
    if (descElement) {
        descElement.textContent = 'View elections, positions, and candidates as administrator';
    }
    
    // Disable all voting buttons and inputs
    document.addEventListener('click', function(e) {
        // Prevent voting-related actions
        if (e.target.closest('.candidate-radio') || 
            e.target.closest('#confirm-votes') ||
            e.target.closest('#final-confirm-vote') ||
            e.target.closest('#save-progress') ||
            e.target.closest('#save-progress-btn')) {
            e.preventDefault();
            e.stopPropagation();
            showError('Administrators cannot vote in elections');
            
            // Log admin vote attempt
            logActivity('admin_vote_attempt', 'Admin attempted to vote');
            
            return false;
        }
    }, true);
    
    // Make radio buttons visually disabled but still visible
    setTimeout(() => {
        document.querySelectorAll('.candidate-radio').forEach(radio => {
            radio.disabled = true;
            radio.style.cursor = 'not-allowed';
            
            // Style the parent candidate card
            const card = radio.closest('.candidate-card');
            if (card) {
                card.style.opacity = '0.8';
                card.style.cursor = 'default';
                card.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    showError('Administrators cannot vote in elections');
                });
            }
        });
    }, 1000);
    
    // Disable navigation buttons that lead to voting
    const disableButtons = [
        '#confirm-votes',
        '#final-confirm-vote',
        '#save-progress',
        '#save-progress-btn'
    ];
    
    disableButtons.forEach(selector => {
        const button = document.querySelector(selector);
        if (button) {
            button.disabled = true;
            button.style.opacity = '0.6';
            button.style.cursor = 'not-allowed';
            button.title = 'Admins cannot vote in elections';
        }
    });
    
    // Add admin notification to review section
    const originalPrepareReviewContent = window.prepareReviewContent;
    window.prepareReviewContent = function() {
        originalPrepareReviewContent.apply(this, arguments);
        
        const reviewContent = document.getElementById('review-content');
        if (reviewContent) {
            const adminNotice = document.createElement('div');
            adminNotice.className = 'bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4';
            adminNotice.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-info-circle text-blue-500 mr-2"></i>
                    <p class="text-blue-700 text-sm"><strong>Admin View Mode:</strong> You can review the interface but cannot submit votes.</p>
                </div>
            `;
            reviewContent.prepend(adminNotice);
        }
    };
}

// Check if user is admin and set up view mode
document.addEventListener('DOMContentLoaded', function() {
    if (IS_ADMIN) {
        // Delay slightly to ensure all elements are loaded
        setTimeout(setupAdminViewMode, 100);
    }
});

// Override the submitVote function for admins
const originalSubmitVote = window.submitVote;
window.submitVote = function() {
    if (IS_ADMIN) {
        showError('Administrators cannot vote in elections');
        logActivity('admin_vote_attempt', 'Admin attempted to submit vote');
        return false;
    }
    return originalSubmitVote.apply(this, arguments);
};

// Override the finalizeVoteSubmission function for admins
const originalFinalizeVoteSubmission = window.finalizeVoteSubmission;
window.finalizeVoteSubmission = function() {
    if (IS_ADMIN) {
        showError('Administrators cannot vote in elections');
        return false;
    }
    return originalFinalizeVoteSubmission.apply(this, arguments);
};

// Update the Return to Home button to not interrupt sync
document.addEventListener('DOMContentLoaded', function() {
    // Override the return to home functionality
    const returnHomeButton = document.querySelector('button[onclick*="window.location.href"]');
    if (returnHomeButton) {
        returnHomeButton.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Check if there are pending votes
            const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
            const currentUserId = CURRENT_USER_ID;
            const userPendingVotes = pendingVotes.filter(vote => vote.user_id === currentUserId);
            
            if (userPendingVotes.length > 0) {
                // Show confirmation dialog
                if (confirm('You have pending votes that will be synced in the background. Are you sure you want to leave this page?')) {
                    // Log leaving with pending votes
                    logActivity('leave_with_pending', 'Leaving page with pending votes', { 
                        pending_count: userPendingVotes.length 
                    });
                    
                    // Continue with navigation
                    window.location.href = BASE_URL;
                }
            } else {
                // No pending votes, proceed normally
                window.location.href = BASE_URL;
            }
        });
    }
    
    // Initialize page visibility handler
    handlePageVisibility();
});

// Function to show background sync notifications
function showBackgroundNotification(message) {
    // Show a subtle notification that doesn't interrupt the user
    const notification = document.createElement('div');
    notification.className = 'fixed bottom-4 left-4 bg-blue-100 border border-blue-400 text-blue-700 px-4 py-2 rounded-lg shadow-lg z-40 max-w-sm';
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-sync-alt mr-2 animate-spin"></i>
            <span class="text-sm">${message}</span>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}
</script>

<style>
.category-section {
    transition: all 0.3s ease;
}

.category-section:hover {
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
    transform: translateY(-2px);
}

.position-card {
    transition: all 0.3s ease;
    min-height: 280px;
}

.position-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px -5px rgba(0, 0, 0, 0.1);
}

.candidate-card {
    transition: all 0.2s ease;
}

.candidate-card:hover {
    transform: translateX(4px);
}

/* High contrast mode */
.high-contrast {
    --tw-bg-opacity: 1;
    background-color: rgba(0, 0, 0, var(--tw-bg-opacity)) !important;
    color: white !important;
}

.high-contrast .bg-white {
    background-color: black !important;
    color: white !important;
    border-color: yellow !important;
}

.high-contrast .text-gray-800 {
    color: white !important;
}

.high-contrast .text-gray-600 {
    color: #ccc !important;
}

/* Single candidate styling */
.bg-blue-50 {
    background-color: #eff6ff;
}

.border-blue-300 {
    border-color: #93c5fd;
}

.text-blue-900 {
    color: #1e3a8a;
}

.text-blue-700 {
    color: #1d4ed8;
}

.text-blue-600 {
    color: #2563eb;
}

.bg-blue-100 {
    background-color: #dbeafe;
}

.border-blue-500 {
    border-color: #3b82f6;
}

/* Line clamp utility */
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.line-clamp-3 {
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Focus styles for accessibility */
button:focus,
input:focus,
select:focus,
.candidate-card:focus {
    outline: 2px solid #ec4899;
    outline-offset: 2px;
}

/* Mobile optimizations */
@media (max-width: 768px) {
    /* Increase touch target sizes for mobile */
    .candidate-card {
        min-height: 60px;
        padding: 12px;
    }
    
    .candidate-radio {
        width: 24px;
        height: 24px;
        min-width: 24px;
    }
    
    /* Make buttons more touch-friendly */
    button, .btn {
        min-height: 44px;
        padding: 12px 16px;
    }
    
    /* Category tabs mobile scrolling */
    #category-tabs {
        display: flex;
        flex-wrap: nowrap;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
        padding-bottom: 8px;
    }
    
    #category-tabs::-webkit-scrollbar {
        display: none;
    }
    
    #category-tabs button {
        white-space: nowrap;
        flex-shrink: 0;
    }
    
    /* Stack layout elements vertically */
    .flex.flex-col.lg\:flex-row {
        flex-direction: column;
    }
    
    .lg\:w-1\/4, .lg\:w-3\/4 {
        width: 100%;
    }
    
    /* Adjust padding for mobile */
    .max-w-7xl {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .px-4 {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    /* Improve candidate card layout */
    .candidate-card .flex.space-x-4 {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .candidate-card .flex-shrink-0 {
        margin-bottom: 0.75rem;
    }
    
    /* Modal improvements */
    .fixed.inset-0 .mx-4 {
        margin-left: 0.5rem;
        margin-right: 0.5rem;
    }
    
    /* Bottom navigation bar for mobile */
    #category-navigation-buttons {
        position: fixed;
        bottom: 0;
        left: 0;
        right: 0;
        background: white;
        padding: 1rem;
        border-top: 1px solid #e5e7eb;
        box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
        z-index: 40;
    }
    
    /* Stack action buttons on mobile */
    .flex.flex-wrap.gap-2 {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    /* Improve candidate selection */
    .candidate-radio {
        width: 24px;
        height: 24px;
    }
    
    /* Modal content scrolling */
    .max-h-\[90vh\].overflow-y-auto {
        max-height: 85vh;
    }
    
    /* Improve text readability on mobile */
    body {
        -webkit-font-smoothing: antialiased;
        text-rendering: optimizeLegibility;
    }
    
    /* Adjust font sizes for mobile */
    .text-3xl {
        font-size: 1.75rem;
    }
    
    .text-xl {
        font-size: 1.25rem;
    }
    
    .text-lg {
        font-size: 1.125rem;
    }
    
    /* Improve progress bar visibility */
    .bg-gray-200.rounded-full.h-2\.5 {
        height: 1rem;
    }
    
    #vote-progress {
        height: 1rem;
    }
    
    /* Candidate image sizing for mobile */
    .candidate-card img {
        width: 50px;
        height: 50px;
    }
    
    /* Loading states for mobile */
    .loading-spinner {
        width: 30px;
        height: 30px;
    }
}

/* Additional mobile optimizations */
@media (max-width: 640px) {
    .max-w-7xl {
        max-width: 100%;
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    /* Navigation improvements */
    #category-navigation-buttons {
        flex-direction: column;
        gap: 1rem;
    }
    
    #category-navigation-buttons button {
        width: 100%;
    }
    
    #category-tabs {
        flex-direction: column;
        gap: 0.5rem;
    }
    
    #category-tabs button {
        width: 100%;
        text-align: left;
    }
    
    /* Position cards mobile optimization */
    .position-card {
        padding: 1rem;
        margin-bottom: 1rem;
    }
    
    /* Election info mobile optimization */
    .flex.flex-col.lg\:flex-row {
        flex-direction: column;
    }
    
    .lg\:w-1\/4, .lg\:w-3\/4 {
        width: 100%;
    }
}

/* Touch-friendly styles */
@media (hover: none) and (pointer: coarse) {
    .candidate-card:hover {
        transform: none; /* Remove hover effects on touch devices */
    }
    
    button:hover, .btn:hover {
        opacity: 1; /* Remove hover effects */
    }
    
    /* Increase tap target sizes */
    .candidate-card {
        min-height: 60px;
    }
    
    .candidate-profile-btn {
        width: 32px;
        height: 32px;
    }
}

/* Touch feedback styles */
@media (max-width: 768px) {
    .candidate-card:active {
        background-color: #fdf2f8;
        transform: scale(0.98);
    }
    
    button:active, .btn:active {
        transform: scale(0.97);
        opacity: 0.9;
    }
    
    /* Improve focus states for touch */
    button:focus-visible, 
    .candidate-card:focus-visible,
    input:focus-visible {
        outline: 2px solid #ec4899;
        outline-offset: 2px;
    }
}

/* Prevent zoom on input focus for iOS */
@media (max-width: 768px) {
    select, input, button {
        font-size: 16px; /* Prevents zoom on focus in iOS */
    }
}

.loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10;
    border-radius: 0.5rem;
}

.loading-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #ec4899;
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Search highlight */
mark {
    background-color: #fffd8e;
    padding: 0 2px;
    border-radius: 2px;
}

.high-contrast mark {
    background-color: yellow;
    color: black;
}

/* Print styles for vote receipts */
@media print {
    .no-print {
        display: none !important;
    }
    
    .print-receipt {
        display: block;
        padding: 20px;
        font-family: Arial, sans-serif;
    }
}

/* Reduced motion support */
@media (prefers-reduced-motion: reduce) {
    * {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
    }
    
    .loading-spinner {
        animation: none;
        border: 4px solid #f3f3f3;
        border-top: 4px solid #ec4899;
        border-radius: 50%;
    }
}

/* Screen reader only content */
.sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* Additional styles for admin view mode */
.admin-view-mode .candidate-card {
    opacity: 0.8;
}

.admin-view-mode .candidate-card:hover {
    transform: none;
    cursor: default;
}

.admin-view-mode .candidate-radio {
    cursor: not-allowed;
}

.admin-view-mode .vote-button {
    opacity: 0.6;
    cursor: not-allowed;
}
/* Offline voting styles */
.offline-vote-indicator {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: #f59e0b;
    color: white;
    padding: 10px 15px;
    border-radius: 8px;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    z-index: 1000;
    display: flex;
    align-items: center;
    gap: 8px;
}

.pending-votes-badge {
    background: #ef4444;
    color: white;
    border-radius: 50%;
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.vote-disabled {
    opacity: 0.6;
    pointer-events: none;
    cursor: not-allowed;
}
</style>
<?php require_once APP_ROOT . '/includes/footer.php'; ?>