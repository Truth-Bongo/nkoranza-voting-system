<?php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once APP_ROOT . '/includes/ActivityLogger.php';

// Initialize database and logger
$db = Database::getInstance()->getConnection();
$activityLogger = new ActivityLogger($db);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once APP_ROOT . '/includes/header.php';

$csrf_token = generate_csrf_token();

// Set timezone to match your application
date_default_timezone_set('Africa/Accra');

// Check if election has ended (using server time as reference)
$election_ended = false;
$election_end_timestamp = null;
try {
    $stmt = $db->query("SELECT end_date FROM elections WHERE status = 'active' OR status = 'ended' ORDER BY id DESC LIMIT 1");
    $election = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($election) {
        $election_end_time = strtotime($election['end_date']);
        $current_time = time();
        $election_ended = $current_time > $election_end_time;
        $election_end_timestamp = $election_end_time;
    }
} catch (Exception $e) {
    // If there's an error, we'll handle it gracefully
    $election_ended = false;
}

// Log page view
$activityLogger->logActivity(0, 'Guest User', 'page_view', 'Viewed login page');
?>

<script>
// Ensure BASE_URL is defined
if (typeof window.BASE_URL === 'undefined') {
    window.BASE_URL = '<?= rtrim(BASE_URL, '/') ?>';
}
</script>

<div class="min-h-screen flex items-center justify-center px-4 py-8 bg-gradient-to-br from-white-50 to-purple-50">
    <div class="bg-white rounded-3xl shadow-2xl border border-gray-200 overflow-hidden max-w-5xl w-full">
        <div class="flex flex-col md:flex-row">
            <!-- Left side with image -->
            <div class="md:w-1/2 bg-white p-8 flex flex-col items-center justify-center text-gray-800 border-r border-gray-100">
                <div class="max-w-md text-center md:text-left">
                    <!-- School Logo/Image -->
                    <div class="mb-6 flex justify-center md:justify-start">
                        <div class="w-24 h-24 bg-pink-50 rounded-2xl flex items-center justify-center">
                            <i class="fas fa-vote-yea text-5xl text-pink-900"></i>
                        </div>
                    </div>
                    
                    <h3 class="text-3xl font-bold mb-4 text-pink-900">Welcome Back!</h3>
                    <p class="text-pink-900 mb-6">
                        Cast your vote securely in the Nkoranza SHTs elections. Your voice matters in shaping the future of our school.
                    </p>
                    
                    <!-- Features list with pink accents -->
                    <div class="space-y-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-pink-50 rounded-full flex items-center justify-center">
                                <i class="fas fa-check text-sm text-pink-900"></i>
                            </div>
                            <span class="text-pink-600">Secure and anonymous voting</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-pink-50 rounded-full flex items-center justify-center">
                                <i class="fas fa-check text-sm text-pink-900"></i>
                            </div>
                            <span class="text-pink-600">One-time access per election</span>
                        </div>
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-pink-50 rounded-full flex items-center justify-center">
                                <i class="fas fa-check text-sm text-pink-900"></i>
                            </div>
                            <span class="text-pink-600">Real-time results after voting ends</span>
                        </div>
                    </div>
                    
                    <!-- Decorative elements with pink border -->
                    <div class="mt-8 pt-8 border-t border-pink-900 rounded-lg p-3 border-l-4">
                        <p class="text-xs text-gray-900">
                            <i class="fas fa-info-circle mr-2 text-pink-900"></i>
                            Empowering students through democratic processes since 2025
                        </p>
                    </div>
                </div>
            </div>
            
            <!-- Right side with login form -->
            <div class="md:w-1/2 p-8">
                <!-- Header with pink text only -->
                <div class="text-center mb-6">
                    <i class="fas fa-user-lock text-4xl text-pink-900 mb-2"></i>
                    <h2 class="text-3xl font-extrabold text-pink-900">Login Panel</h2>
                    <p class="text-pink-400 mt-1 text-sm">Enter your credentials to access the voting system</p>
                    <p class="text-pink-400 mt-1 text-xs font-semibold">
                        <?php if ($election_ended): ?>
                            View election results
                        <?php else: ?>
                            One-time access only for voting
                        <?php endif; ?>
                    </p>
                </div>

                <!-- Flash messages from PHP session -->
                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="mb-4 p-3 rounded-lg <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'bg-red-100 text-red-700 border-l-4 border-red-500' : 'bg-green-100 text-green-700 border-l-4 border-green-500' ?>" role="alert">
                        <div class="flex items-center">
                            <i class="fas <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-2"></i>
                            <span><?= htmlspecialchars($_SESSION['flash_message']) ?></span>
                        </div>
                    </div>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                <?php endif; ?>

                <!-- Login Form -->
                <form id="login-form" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" id="election-ended" value="<?= $election_ended ? 'true' : 'false' ?>">
                    <input type="hidden" id="election-end-timestamp" value="<?= $election_end_timestamp ?>">

                    <!-- User ID field -->
                    <div class="form-group">
                        <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-user text-pink-600 mr-2"></i>User ID
                        </label>
                        <div class="relative">
                            <input type="text" 
                                   id="username" 
                                   name="username" 
                                   placeholder="Enter your user ID" 
                                   required 
                                   autofocus
                                   class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-pink-600 focus:ring-2 focus:ring-pink-200 transition-all duration-200"
                                   pattern="[A-Za-z0-9_\-]+"
                                   title="Only letters, numbers, underscores and hyphens are allowed">
                            <div class="absolute right-3 top-2.5 text-gray-400">
                                <i class="fas fa-id-card"></i>
                            </div>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">Your student ID</div>
                    </div>

                    <!-- Password field -->
                    <div class="form-group">
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                            <i class="fas fa-lock text-pink-600 mr-2"></i>Password
                        </label>
                        <div class="relative">
                            <input type="password" 
                                   id="password" 
                                   name="password" 
                                   placeholder="Enter your password" 
                                   required
                                   class="w-full px-4 py-2.5 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-pink-600 focus:ring-2 focus:ring-pink-200 transition-all duration-200 pr-12">
                            <button type="button" 
                                    class="absolute right-3 top-2.5 text-gray-400 hover:text-pink-600 transition-colors"
                                    onclick="togglePasswordVisibility()"
                                    aria-label="Toggle password visibility">
                                <i class="fas fa-eye" id="toggle-password-icon"></i>
                            </button>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">Case sensitive</div>
                    </div>

                    <!-- Error message container (for AJAX errors) -->
                    <div id="login-error" class="p-3 bg-red-50 border-l-4 border-red-500 rounded-lg" role="alert">
                        <div class="flex items-center">
                            <i class="fas fa-exclamation-circle text-red-500 mr-2"></i>
                            <span id="error-text" class="text-sm text-red-700"></span>
                        </div>
                    </div>

                    <!-- Submit button -->
                    <div class="pt-2">
                        <button type="submit" class="btn w-full bg-pink-900 text-white py-2.5 px-4 rounded-lg font-semibold hover:bg-pink-800 focus:outline-none focus:ring-2 focus:ring-pink-500 focus:ring-offset-2 transition-all duration-200 shadow-lg hover:shadow-xl transform hover:-translate-y-0.5">
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            <?php if ($election_ended): ?>
                                View Results
                            <?php else: ?>
                                Login to Vote
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- Footer link -->
                    <div class="footer text-center text-sm text-gray-600 pt-2">
                        <p>Forgot your password? <a href="#" class="text-pink-900 hover:text-pink-700 font-medium transition-colors" onclick="showContactModal(event)">Please contact the election committee.</a></p>
                    </div>

                    <!-- System info -->
                    <div class="system-info bg-gray-50 rounded-lg p-3 border-l-4 border-pink-900 mt-4">
                        <p class="text-xs text-gray-600"><i class="fas fa-info-circle text-pink-900 mr-2"></i> This is a secure voting system. All activities are monitored.</p>
                        <?php if ($election_ended): ?>
                            <p class="mt-1 text-xs text-gray-600"><i class="fas fa-check-circle text-gray-900 mr-2"></i> Election has ended. You can now view results.</p>
                        <?php else: ?>
                            <p class="mt-1 text-xs text-gray-600"><i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i> Students can only login once for security reasons.</p>
                            <?php if ($election_end_timestamp): ?>
                                <p class="mt-1 text-xs text-gray-600"><i class="fas fa-clock text-pink-900 mr-2"></i> Election ends at: <span id="election-end-time" class="font-semibold text-pink-900"></span></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Contact Modal -->
<div id="contact-modal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50" onclick="closeModal(event)">
    <div class="bg-white rounded-lg p-6 max-w-md mx-4" onclick="event.stopPropagation()">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-900">Contact Election Committee</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="space-y-3">
            <p class="flex items-center text-gray-700">
                <i class="fas fa-envelope text-pink-900 w-6"></i>
                <a href="mailto:elections@nkoranzashts.edu.gh" class="text-pink-900 hover:text-pink-700">elections@nkoranzashts.edu.gh</a>
            </p>
            <p class="flex items-center text-gray-700">
                <i class="fas fa-phone text-pink-900 w-6"></i>
                <a href="tel:+233549632116" class="text-pink-900 hover:text-pink-700">+233 54 581 1179</a>
            </p>
            <hr class="my-2">
            <p class="text-sm text-gray-500">Office hours: Mon-Fri 8:00 AM - 4:00 PM</p>
        </div>
        <button onclick="closeModal()" class="mt-4 w-full bg-pink-900 text-white py-2 rounded-lg hover:bg-pink-800 transition-colors">Close</button>
    </div>
</div>

<script>
if (typeof window.BASE_URL === 'undefined') {
    window.BASE_URL = '<?= rtrim(BASE_URL, "/") ?>';
}

function togglePasswordVisibility() {
    const p = document.getElementById('password');
    const icon = document.getElementById('toggle-password-icon');
    if (p.type === 'password') {
        p.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        p.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

function showContactModal(e) {
    if (e) e.preventDefault();
    document.getElementById('contact-modal').style.display = 'flex';
}
function closeModal(e) {
    const m = document.getElementById('contact-modal');
    if (!e || e.target === m) m.style.display = 'none';
}

function showError(msg) {
    const box  = document.getElementById('login-error');
    const text = document.getElementById('error-text');
    if (!box || !text) { alert(msg); return; }
    text.textContent = msg;
    box.style.setProperty('display', 'flex', 'important');
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}
function hideError() {
    const box = document.getElementById('login-error');
    if (box) box.style.setProperty('display', 'none', 'important');
}

document.addEventListener('DOMContentLoaded', function () {

    // Format end time
    const ts = document.getElementById('election-end-timestamp')?.value;
    if (ts) {
        const el = document.getElementById('election-end-time');
        if (el) el.textContent = new Date(ts * 1000).toLocaleString();
    }

    // Clear storage if redirected from logout
    if (new URLSearchParams(location.search).has('clear_storage')) {
        ['pendingVotes','voteReceipts'].forEach(k => localStorage.removeItem(k));
        history.replaceState({}, '', location.pathname);
    }

    const form    = document.getElementById('login-form');
    const btnEl   = form ? form.querySelector('button[type="submit"]') : null;
    const elEnded = document.getElementById('election-ended')?.value === 'true';

    if (!form) return;

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideError();

        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;

        if (!username) { showError('Please enter your User ID.'); return; }
        if (!password) { showError('Please enter your password.'); return; }

        // Disable button while loading
        if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Checking...'; }

        try {
            const res = await fetch(window.BASE_URL + '/api/auth/login.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    username: username,
                    password: password,
                    csrf_token: document.querySelector('[name="csrf_token"]')?.value,
                    client_timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
                })
            });

            let data;
            const text = await res.text();
            try {
                data = JSON.parse(text);
            } catch (_) {
                showError('Unexpected server response. Please try again.');
                if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="fas fa-sign-in-alt mr-2"></i>' + (elEnded ? 'View Results' : 'Login to Vote'); }
                return;
            }

            if (!data.success) {
                showError(data.message || 'Invalid credentials. Please try again.');
                if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="fas fa-sign-in-alt mr-2"></i>' + (elEnded ? 'View Results' : 'Login to Vote'); }
                return;
            }

            // Success — show message then redirect
            const dest = data.isAdmin
                ? window.BASE_URL + '/index.php?page=admin/dashboard'
                : (elEnded || data.electionEnded)
                    ? window.BASE_URL + '/index.php?page=results'
                    : window.BASE_URL + '/index.php?page=vote';

            const banner = document.createElement('div');
            banner.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:12px 24px;border-radius:8px;font-weight:600;font-size:15px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.2);display:flex;align-items:center;gap:8px;';
            banner.innerHTML = '<i class="fas fa-check-circle"></i> Login successful! Redirecting...';
            document.body.appendChild(banner);

            setTimeout(() => { location.href = dest; }, 1200);

        } catch (err) {
            showError('Network error — please check your connection and try again.');
            if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="fas fa-sign-in-alt mr-2"></i>' + (elEnded ? 'View Results' : 'Login to Vote'); }
        }
    });
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeModal(); hideError(); }
});
</script>

<style>
#login-error {
    display: none;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: #fef2f2;
    border-left: 4px solid #dc2626;
    border-radius: 8px;
    margin-bottom: 16px;
}
#login-error i  { color: #dc2626; font-size: 1.1rem; flex-shrink: 0; }
#login-error span { color: #991b1b; font-size: 0.9rem; font-weight: 500; flex: 1; }
.fa-spinner { animation: spin 1s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.animate-slide-in { animation: slideIn 0.3s ease-out; }
#contact-modal { display: none; }
</style>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>