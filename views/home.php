<?php
require_once APP_ROOT . '/config/db_connect.php';
require_once APP_ROOT . '/includes/header.php';

// Function to check if a page/file exists
function pageExists($pagePath) {
    $fullPath = APP_ROOT . '/' . $pagePath;
    return file_exists($fullPath);
}

try {
    $db = Database::getInstance()->getConnection();

    // Set timezone to match your application
    date_default_timezone_set('Africa/Accra');
    
    // Get elections with proper status calculation
    $elections = $db->query("
        SELECT *, 
               CASE 
                 WHEN start_date > NOW() THEN 'upcoming'
                 WHEN end_date < NOW() THEN 'ended' 
                 ELSE 'active'
               END as status
        FROM elections 
        ORDER BY start_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter upcoming elections
    $upcoming_elections = array_filter($elections, function($election) {
        return $election['status'] === 'upcoming';
    });
    
    // Filter active elections
    $active_elections = array_filter($elections, function($election) {
        return $election['status'] === 'active';
    });
    
} catch (Exception $e) {
    error_log("Database error in homepage: " . $e->getMessage());
    $upcoming_elections = [];
    $active_elections = [];
}

// Check if user is admin
$isAdmin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'];

// Determine the correct URLs based on what files exist
$elections_url = pageExists('elections.php') ? BASE_URL . '/elections.php' : BASE_URL . '/index.php?page=elections';
$vote_url = pageExists('vote.php') ? BASE_URL . '/vote.php' : BASE_URL . '/index.php?page=vote';
$login_url = pageExists('login.php') ? BASE_URL . '/login.php' : BASE_URL . '/index.php?page=login';
$results_url = pageExists('results.php') ? BASE_URL . '/results.php' : BASE_URL . '/index.php?page=results';
?>

<div class="max-w-6xl mx-auto px-4 py-8">
  <!-- Hero Section -->
  <div class="text-center mb-12">
    <h1 class="text-4xl font-bold text-gray-900 mb-4">Welcome to Nkoranza SHTs E-Voting System</h1>
    <p class="text-xl text-gray-600 mb-8">Cast your vote securely and conveniently for your school elections</p>
    
    <div class="flex flex-col sm:flex-row justify-center gap-4 mb-12">
      <?php if (isset($_SESSION['user_id'])): ?>
        <?php if (!empty($active_elections) && !$isAdmin): ?>
          <!-- Regular users can vote -->
          <a href="<?= $vote_url ?>" class="bg-pink-900 hover:bg-pink-800 text-white px-8 py-3 border-l-4 border-gray-900 rounded-full text-lg font-semibold transition-colors">
            <i class="fas fa-vote-yea mr-2"></i> Vote Now
          </a>
        <?php elseif ($isAdmin): ?>
          <!-- Admin users should go to elections management -->
          <a href="<?= BASE_URL ?>/index.php?page=admin/elections"  class="bg-pink-900 hover:bg-pink-800 text-white px-8 py-3 border-l-4 border-gray-900 rounded-full text-lg font-semibold transition-colors">
            <i class="fas fa-cog mr-2"></i> Manage Elections
          </a>
        <?php else: ?>
          <!-- No active elections -->
          <span class="bg-gray-400 text-white px-8 py-3 border-l-4 border-blue-900 rounded-full text-lg font-semibold cursor-not-allowed">
            <i class="fas fa-clock mr-2"></i> No Active Elections
          </span>
        <?php endif; ?>
      <?php else: ?>
        <a href="<?= $login_url ?>" class="bg-pink-900 hover:bg-pink-800 text-white px-8 py-3 border-l-4 border-gray-900 rounded-full text-lg font-semibold transition-colors">
          <i class="fas fa-sign-in-alt mr-2"></i> Login to Vote
        </a>
      <?php endif; ?>
      <a href="<?= $elections_url ?>" class="bg-gray-700 hover:bg-gray-800 text-white px-8 py-3 border-l-4 border-pink-900 rounded-full text-lg font-semibold transition-colors">
        <i class="fas fa-calendar-alt mr-2"></i> View Elections
      </a>
    </div>
  </div>

  <!-- User Status Indicator -->
<div class="mb-8 text-center">
  <?php if (isset($_SESSION['user_id'])): ?>
    <div class="p-4 inline-block">
      <p class="text-lg mb-2">
        Logged in as: <span class="font-semibold"><?= htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']) ?></span>
        <?php if ($isAdmin): ?>
          <span class="bg-red-100 text-red-800 px-3 py-1 rounded-full text-sm ml-2">Administrator</span>
        <?php else: ?>
          <span class="bg-blue-100 text-blue-800 px-3 py-1 rounded-full text-sm ml-2">Voter</span>
        <?php endif; ?>
      </p>
      <div class="flex flex-col sm:flex-row justify-center items-center gap-4 mt-2">
        <div class="bg-purple-100 text-purple-800 px-3 py-1 rounded-full text-sm font-medium">
          <i class="fas fa-id-card mr-1"></i> User ID: <?= htmlspecialchars($_SESSION['user_id']) ?>
        </div>
         <div class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
          <i class="fas fa-layer-group mr-1"></i> 
          <?php 
          if (isset($_SESSION['level']) && !empty($_SESSION['level'])) {
            echo 'Level: ' . htmlspecialchars($_SESSION['level']);
          } else {
            // Try to get user level from database if not in session
            try {
              $db = Database::getInstance()->getConnection();
              $stmt = $db->prepare('SELECT level FROM users WHERE id = ?');
              $stmt->execute([$_SESSION['user_id']]);
              $user = $stmt->fetch(PDO::FETCH_ASSOC);
              
              if ($user && !empty($user['level'])) {
                $_SESSION['level'] = $user['level'];
                echo 'Level: ' . htmlspecialchars($user['level']);
              } else {
                echo 'Level: Not specified';
              }
            } catch (Exception $e) {
              echo 'Level: Not available';
            }
          }
          ?>
        </div>
        <div class="bg-pink-100 text-pink-800 px-3 py-1 rounded-full text-sm font-medium">
          <i class="fas fa-building mr-1"></i> 
          <?php 
          if (isset($_SESSION['department']) && !empty($_SESSION['department'])) {
            echo 'Department: ' . htmlspecialchars($_SESSION['department']);
          } else {
            // Try to get department from database if not in session
            try {
              $db = Database::getInstance()->getConnection();
              $stmt = $db->prepare('SELECT department FROM users WHERE id = ?');
              $stmt->execute([$_SESSION['user_id']]);
              $user = $stmt->fetch(PDO::FETCH_ASSOC);
              
              if ($user && !empty($user['department'])) {
                $_SESSION['department'] = $user['department'];
                echo 'Department: ' . htmlspecialchars($user['department']);
              } else {
                echo 'Department: Not specified';
              }
            } catch (Exception $e) {
              echo 'Department: Not available';
            }
          }
          ?>
        </div>
      </div>
    </div>
  <?php else: ?>
    <p class="text-lg text-gray-600">Please login to participate in the ongoing elections.</p>
  <?php endif; ?>
</div>
  
  <!-- Features Grid -->
  <div class="grid md:grid-cols-3 gap-6 mb-12">
    <div class="bg-white p-6 rounded-lg shadow-md overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
      <div class="text-pink-900 mb-4 text-2xl">
        <i class="fas fa-user-shield"></i>
      </div>
      <h3 class="font-bold text-lg mb-2">Secure Voting</h3>
      <p class="text-gray-600">Our system uses advanced encryption to ensure your vote remains anonymous and secure.</p>
    </div>
    
    <div class="bg-white p-6 rounded-lg shadow-md overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
      <div class="text-pink-900 mb-4 text-2xl">
        <i class="fas fa-bolt"></i>
      </div>
      <h3 class="font-bold text-lg mb-2">Fast Results</h3>
      <p class="text-gray-600">Get real-time election results as soon as voting ends, eliminating long waits.</p>
    </div>
    
    <div class="bg-white p-6 rounded-lg shadow-md overflow-hidden transition-all duration-300 hover:shadow-xl hover:-translate-y-1">
      <div class="text-pink-900 mb-4 text-2xl">
        <i class="fas fa-mobile-alt"></i>
      </div>
      <h3 class="font-bold text-lg mb-2">Mobile Friendly</h3>
      <p class="text-gray-600">Vote from any device, whether you're using a computer, tablet, or smartphone.</p>
    </div>
  </div>

  <!-- Active Elections Section -->
  <?php if (!empty($active_elections)): ?>
    <div class="bg-white p-6 rounded-lg shadow-md mb-8">
      <h3 class="font-bold text-xl mb-4 text-center text-green-700">
        <i class="fas fa-vote-yea mr-2"></i>Active Elections - Vote Now!
      </h3>
      <div class="overflow-x-auto">
        <table class="min-w-full bg-white">
          <thead class="bg-green-50">
            <tr>
              <th class="py-3 px-4 border-b text-left">Election Title</th>
              <th class="py-3 px-4 border-b text-center">Ends In</th>
              <th class="py-3 px-4 border-b text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($active_elections as $election): 
              $end_date = new DateTime($election['end_date']);
              $now = new DateTime();
              $interval = $now->diff($end_date);
              $days_remaining = $interval->format('%a days, %h hours');
            ?>
              <tr class="hover:bg-green-50">
                <td class="py-3 px-4 border-b">
                  <div class="font-semibold"><?= htmlspecialchars($election['title']) ?></div>
                  <div class="text-sm text-gray-600"><?= htmlspecialchars($election['description']) ?></div>
                </td>
                <td class="py-3 px-4 border-b text-center">
                  <span class="bg-green-100 text-green-800 px-3 py-1 rounded-full text-sm font-medium">
                    <?= $days_remaining ?>
                  </span>
                </td>
                <td class="py-3 px-4 border-b text-center">
                  <?php if ($isAdmin): ?>
                    <span class="bg-gray-500 text-white px-4 py-2 rounded text-sm cursor-not-allowed" title="Administrators cannot vote">
                      <i class="fas fa-user-shield mr-1"></i> Admin View
                    </span>
                  <?php else: ?>
                     <a href="<?= $vote_url ?>" 
                       class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded text-sm transition-colors">
                      <i class="fas fa-vote-yea mr-1"></i> Vote Now
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- Upcoming Elections Section -->
  <div class="bg-white p-6 rounded-lg shadow-md">
    <h3 class="font-bold text-xl mb-4 text-center text-blue-700">
      <i class="fas fa-calendar-alt mr-2"></i>Upcoming Elections
    </h3>
    <div class="overflow-x-auto">
      <?php if (empty($upcoming_elections)): ?>
        <p class="text-center text-gray-500 py-4">No upcoming elections at the moment. Check back later!</p>
      <?php else: ?>
        <table class="min-w-full bg-white">
          <thead class="bg-blue-50">
            <tr>
              <th class="py-3 px-4 border-b text-left">Election Title</th>
              <th class="py-3 px-4 border-b text-center">Starts On</th>
              <th class="py-3 px-4 border-b text-center">Ends On</th>
              <th class="py-3 px-4 border-b text-center">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($upcoming_elections as $election): ?>
              <tr class="hover:bg-blue-50">
                <td class="py-3 px-4 border-b">
                  <div class="font-semibold"><?= htmlspecialchars($election['title']) ?></div>
                  <div class="text-sm text-gray-600"><?= htmlspecialchars($election['description']) ?></div>
                </td>
                <td class="py-3 px-4 border-b text-center"><?= date('M j, Y g:i A', strtotime($election['start_date'])) ?></td>
                <td class="py-3 px-4 border-b text-center"><?= date('M j, Y g:i A', strtotime($election['end_date'])) ?></td>
                <td class="py-3 px-4 border-b text-center">
                  <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                    Upcoming
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Quick Stats -->
  <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="bg-pink-50 p-6 rounded-lg border border-pink-200">
      <h4 class="font-semibold text-lg text-pink-900 mb-3">
        <i class="fas fa-info-circle mr-2"></i>Voting Instructions
      </h4>
      <ul class="list-disc list-inside text-gray-700 space-y-2">
        <li>Login with your student credentials.</li>
        <li>Select an active election to participate in.</li>
        <li>Cast and review candidates before submitting your vote.</li>
        <li>After successful submission, <b class="text-red-600">Return to Home and logout.</b></li>
        <li>You can only <b class="text-blue-600">vote once per election.</b></li>
      </ul>
    </div>
    
    <div class="bg-blue-50 p-6 rounded-lg border border-blue-200">
      <h4 class="font-semibold text-lg text-blue-900 mb-3">
        <i class="fas fa-question-circle mr-2"></i>Need Help?
      </h4>
      <p class="text-gray-700 mb-3">If you experience any issues with voting, please contact:</p>
      <div class="text-sm">
        <p class="font-medium">Election Committee</p>
        <p class="text-blue-600">Email: elections@nkoranzasht.edu.gh</p>
        <p class="text-blue-600">Phone: +233 54 963 2116 - Returning Officer</p>
        <p class="text-blue-600">Phone: +233 54 802 0174 - Presiding Officer</p>
        <p class="text-blue-600">Phone: +233 54 581 1179 - IT Personnel</p>
      </div>
    </div>
  </div>
  <div id="sync-notification" class="fixed bottom-4 right-4 bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-lg shadow-lg hidden z-50 max-w-sm">
    <div class="flex items-center">
        <i class="fas fa-sync-alt mr-3 animate-spin" aria-hidden="true"></i>
        <span id="sync-message">Syncing your votes...</span>
        <button class="ml-4 text-blue-700 hover:text-blue-900" onclick="hideSyncNotification()" aria-label="Dismiss sync notification">
            <i class="fas fa-times" aria-hidden="true"></i>
        </button>
    </div>
</div>
</div>

<script>
// Home page vote synchronization system
document.addEventListener('DOMContentLoaded', function() {
    console.log('Home page loaded - initializing vote sync system');
    
    // Initialize sync monitoring
    initializeVoteSync();
    
    // Debugging function to check if URLs are valid
    console.log('Base URL:', '<?= BASE_URL ?>');
    
    // Check if essential pages exist
    const essentialPages = [
        '<?= $elections_url ?>',
        '<?= $vote_url ?>', 
        '<?= $login_url ?>'
    ];
    
    essentialPages.forEach(url => {
        fetch(url, { method: 'HEAD' })
            .then(response => {
                console.log('URL check:', url, response.ok ? '✓ EXISTS' : '✗ MISSING');
                if (!response.ok) {
                    console.error('Page might not exist:', url);
                }
            })
            .catch(error => {
                console.error('Error checking URL:', url, error);
            });
    });
    
    // Add click handlers for debugging
    document.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', function(e) {
            console.log('Navigating to:', this.href);
        });
    });
});

// Vote synchronization system for home page
function initializeVoteSync() {
    console.log('Initializing vote synchronization on home page');
    
    // Check for pending votes immediately
    checkAndSyncPendingVotes();
    
    // Set up periodic sync checking (every 30 seconds)
    const syncInterval = setInterval(checkAndSyncPendingVotes, 30000);
    
    // Sync when coming online
    window.addEventListener('online', function() {
        console.log('Device came online - checking for pending votes');
        checkAndSyncPendingVotes();
    });
    
    // Listen for storage events (when votes are added from other tabs)
    window.addEventListener('storage', function(e) {
        if (e.key === 'pendingVotes') {
            console.log('Pending votes updated in storage - checking sync');
            setTimeout(checkAndSyncPendingVotes, 1000);
        }
    });
    
    // Listen for messages from service worker
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', (event) => {
            const { type, processed, error } = event.data;
            
            switch (type) {
                case 'SYNC_COMPLETED':
                    showHomePageNotification(`Background sync completed! ${processed} vote(s) submitted.`, 'success');
                    break;
                    
                case 'SYNC_FAILED':
                    console.error('Background sync failed:', error);
                    // Don't show notification for background failures
                    break;
            }
        });
    }
}

// Check and sync pending votes
async function checkAndSyncPendingVotes() {
    const pendingVotes = getPendingVotesForCurrentUser();
    
    if (pendingVotes.length === 0) {
        console.log('No pending votes found for current user');
        return;
    }
    
    if (!navigator.onLine) {
        console.log('Device is offline - cannot sync votes');
        showSyncNotification('Waiting for internet connection to sync votes...', 'warning');
        return;
    }
    
    console.log(`Found ${pendingVotes.length} pending votes, attempting sync...`);
    showSyncNotification('Syncing your votes with the server...', 'syncing');
    
    try {
        const result = await syncVotesWithServer(pendingVotes);
        
        if (result.success) {
            console.log('Sync successful:', result.processed, 'votes synced');
            
            // Remove successfully synced votes
            removeSyncedVotes(result.results.successful);
            
            // Show success notification
            showHomePageNotification(
                `Votes synchronized successfully! ${result.processed} vote(s) submitted.`,
                'success'
            );
            
            hideSyncNotification();
            
            // If all votes were synced, show a special message
            if (result.processed === pendingVotes.length) {
                showHomePageNotification('All your votes have been successfully submitted!', 'success');
            }
            
        } else {
            throw new Error(result.message || 'Sync failed');
        }
        
    } catch (error) {
        console.error('Sync error:', error);
        showSyncNotification('Failed to sync votes. Will retry automatically...', 'error');
        
        // Retry after a delay with exponential backoff
        setTimeout(checkAndSyncPendingVotes, 60000); // Retry after 1 minute
    }
}

// Get pending votes for current user only
function getPendingVotesForCurrentUser() {
    try {
        const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
        const currentUserId = '<?= $_SESSION['user_id'] ?? 'unknown' ?>';
        
        // Filter votes for current user only
        const userPendingVotes = pendingVotes.filter(vote => 
            vote.user_id === currentUserId
        );
        
        console.log(`Found ${userPendingVotes.length} pending votes for user ${currentUserId}`);
        return userPendingVotes;
        
    } catch (error) {
        console.error('Error reading pending votes:', error);
        return [];
    }
}

// Sync votes with server
async function syncVotesWithServer(pendingVotes) {
    const response = await fetch('<?= BASE_URL ?>/api/voting/sync-pending-votes.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ pending_votes: pendingVotes })
    });
    
    if (!response.ok) {
        throw new Error(`HTTP error! status: ${response.status}`);
    }
    
    return await response.json();
}

// Remove successfully synced votes from storage
function removeSyncedVotes(syncedVotes) {
    if (!syncedVotes || syncedVotes.length === 0) return;
    
    try {
        const pendingVotes = JSON.parse(localStorage.getItem('pendingVotes') || '[]');
        const currentUserId = '<?= $_SESSION['user_id'] ?? 'unknown' ?>';
        
        // Filter out synced votes
        const updatedPendingVotes = pendingVotes.filter(vote => 
            !syncedVotes.some(synced => 
                synced.election_id == vote.election_id &&
                synced.timestamp === vote.timestamp &&
                vote.user_id === currentUserId
            )
        );
        
        localStorage.setItem('pendingVotes', JSON.stringify(updatedPendingVotes));
        console.log(`Removed ${syncedVotes.length} synced votes from storage`);
        
    } catch (error) {
        console.error('Error removing synced votes:', error);
    }
}

// Show sync notification
function showSyncNotification(message, type = 'info') {
    const notification = document.getElementById('sync-notification');
    const messageElement = document.getElementById('sync-message');
    
    if (!notification || !messageElement) return;
    
    // Update styles based on type
    notification.className = 'fixed bottom-4 right-4 px-4 py-3 rounded-lg shadow-lg z-50 max-w-sm flex items-center';
    
    switch (type) {
        case 'syncing':
            notification.classList.add('bg-blue-100', 'border-blue-400', 'text-blue-700');
            break;
        case 'success':
            notification.classList.add('bg-green-100', 'border-green-400', 'text-green-700');
            break;
        case 'warning':
            notification.classList.add('bg-yellow-100', 'border-yellow-400', 'text-yellow-700');
            break;
        case 'error':
            notification.classList.add('bg-red-100', 'border-red-400', 'text-red-700');
            break;
        default:
            notification.classList.add('bg-blue-100', 'border-blue-400', 'text-blue-700');
    }
    
    messageElement.textContent = message;
    notification.classList.remove('hidden');
}

// Hide sync notification
function hideSyncNotification() {
    const notification = document.getElementById('sync-notification');
    if (notification) {
        notification.classList.add('hidden');
    }
}

// Show home page notification (temporary)
function showHomePageNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 px-4 py-3 rounded-lg shadow-lg z-50 max-w-sm transition-all duration-300 transform translate-x-0`;
    
    // Set styles based on type
    switch (type) {
        case 'success':
            notification.className += ' bg-green-100 border border-green-400 text-green-700';
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-check-circle mr-3" aria-hidden="true"></i>
                    <span>${message}</span>
                    <button class="ml-4 text-green-700 hover:text-green-900" onclick="this.parentElement.parentElement.remove()">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            `;
            break;
        case 'error':
            notification.className += ' bg-red-100 border border-red-400 text-red-700';
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle mr-3" aria-hidden="true"></i>
                    <span>${message}</span>
                    <button class="ml-4 text-red-700 hover:text-red-900" onclick="this.parentElement.parentElement.remove()">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            `;
            break;
        default:
            notification.className += ' bg-blue-100 border border-blue-400 text-blue-700';
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas fa-info-circle mr-3" aria-hidden="true"></i>
                    <span>${message}</span>
                    <button class="ml-4 text-blue-700 hover:text-blue-900" onclick="this.parentElement.parentElement.remove()">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            `;
    }
    
    document.body.appendChild(notification);
    
    // Auto-remove after 5 seconds for success messages
    if (type === 'success') {
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 5000);
    }
}

// Enhanced logout handling to preserve pending votes
function enhanceLogout() {
    const logoutLinks = document.querySelectorAll('a[href*="logout"]');
    
    logoutLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            const pendingVotes = getPendingVotesForCurrentUser();
            
            if (pendingVotes.length > 0) {
                e.preventDefault();
                
                if (confirm(`You have ${pendingVotes.length} pending vote(s) that need to be synced. Are you sure you want to logout? Your votes will continue syncing if you stay logged in.`)) {
                    // User confirmed, proceed with logout
                    window.location.href = this.href;
                }
            }
            // If no pending votes, allow normal logout
        });
    });
}

// Initialize enhanced logout when DOM is loaded
document.addEventListener('DOMContentLoaded', enhanceLogout);

// Register service worker for background sync (if not already registered)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw-sync.js')
        .then(function(registration) {
            console.log('Service Worker registered for background sync on home page');
        })
        .catch(function(registrationError) {
            console.log('SW registration failed:', registrationError);
        });
}

// Monitor page visibility for sync optimization
document.addEventListener('visibilitychange', function() {
    if (!document.hidden) {
        // Page became visible - check for pending votes
        console.log('Home page became visible - checking sync status');
        const pendingVotes = getPendingVotesForCurrentUser();
        
        if (pendingVotes.length > 0 && navigator.onLine) {
            // Small delay to ensure page is fully loaded
            setTimeout(checkAndSyncPendingVotes, 1000);
        }
    }
});

// Add pending votes badge to UI if there are pending votes
function addPendingVotesBadge() {
    const pendingVotes = getPendingVotesForCurrentUser();
    
    if (pendingVotes.length > 0) {
        // Add badge to user status area
        const userStatus = document.querySelector('.mb-8.text-center');
        if (userStatus) {
            // Check if badge already exists
            let badge = userStatus.querySelector('.pending-votes-badge');
            
            if (!badge) {
                badge = document.createElement('div');
                badge.className = 'pending-votes-badge bg-orange-500 text-white px-3 py-1 rounded-full text-sm font-medium mt-2 inline-block';
                badge.innerHTML = `<i class="fas fa-sync-alt mr-1"></i> ${pendingVotes.length} vote(s) pending sync`;
                userStatus.appendChild(badge);
            } else {
                badge.innerHTML = `<i class="fas fa-sync-alt mr-1"></i> ${pendingVotes.length} vote(s) pending sync`;
            }
        }
    }
}

// Initialize pending votes badge
document.addEventListener('DOMContentLoaded', addPendingVotesBadge);

// Update badge when votes are synced
const originalRemoveSyncedVotes = removeSyncedVotes;
removeSyncedVotes = function(syncedVotes) {
    originalRemoveSyncedVotes(syncedVotes);
    setTimeout(addPendingVotesBadge, 100); // Update badge after a short delay
};
</script>

<style>
.pending-votes-badge {
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.7; }a
    100% { opacity: 1; }
}

#sync-notification {
    transition: all 0.3s ease;
}

.sync-pulse {
    animation: syncPulse 1.5s ease-in-out infinite;
}

@keyframes syncPulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); }
}
</style>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>