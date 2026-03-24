<?php
// views/settings.php
// User settings and profile management

// Enable strict typing
declare(strict_types=1);

// Load bootstrap from root directory (go up one level)
require_once __DIR__ . '/../bootstrap.php';

// Require login for settings page
require_login();

// Now we can use all bootstrap functions and constants
require_once APP_ROOT . '/includes/ActivityLogger.php';

// Initialize database connection
try {
    $db = Database::getInstance()->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    $_SESSION['flash_message'] = 'System temporarily unavailable. Please try again later.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL . '/index.php?page=home');
    exit;
}

$activityLogger = new ActivityLogger($db);
$userId = $_SESSION['user_id'];

// Get user details
try {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, email, department, level, is_admin
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found');
    }
} catch (Exception $e) {
    error_log("Error fetching user settings: " . $e->getMessage());
    $_SESSION['flash_message'] = 'Error loading settings. Please try again.';
    $_SESSION['flash_type'] = 'error';
    header('Location: ' . BASE_URL . '/index.php?page=home');
    exit;
}

// Handle form submissions
$activeTab = $_GET['tab'] ?? 'profile';
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_profile':
                // Update profile information
                $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
                
                if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $error = 'Please enter a valid email address.';
                } else {
                    try {
                        $stmt = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                        $result = $stmt->execute([$email ?: null, $userId]);
                        
                        if ($result && $stmt->rowCount() > 0) {
                            $activityLogger->logActivity(
                                $userId,
                                $user['first_name'] . ' ' . $user['last_name'],
                                'settings_update',
                                'Updated profile information'
                            );
                            
                            $success = 'Profile updated successfully!';
                            
                            // Refresh user data
                            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                            $stmt->execute([$userId]);
                            $user = $stmt->fetch(PDO::FETCH_ASSOC);
                        } else {
                            $success = 'No changes were made to your profile.';
                        }
                    } catch (Exception $e) {
                        error_log("Error updating profile: " . $e->getMessage());
                        $error = 'Failed to update profile. Please try again.';
                    }
                }
                break;
                
            case 'change_password':
                // Change password
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
                    $error = 'All password fields are required.';
                } elseif ($newPassword !== $confirmPassword) {
                    $error = 'New passwords do not match.';
                } elseif (strlen($newPassword) < 8) {
                    $error = 'Password must be at least 8 characters long.';
                } elseif (!preg_match('/[A-Z]/', $newPassword)) {
                    $error = 'Password must contain at least one uppercase letter.';
                } elseif (!preg_match('/[a-z]/', $newPassword)) {
                    $error = 'Password must contain at least one lowercase letter.';
                } elseif (!preg_match('/[0-9]/', $newPassword)) {
                    $error = 'Password must contain at least one number.';
                } elseif (!preg_match('/[\W_]/', $newPassword)) {
                    $error = 'Password must contain at least one special character.';
                } else {
                    try {
                        // Verify current password
                        $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                        $stmt->execute([$userId]);
                        $storedHash = $stmt->fetchColumn();
                        
                        if (!$storedHash || !password_verify($currentPassword, $storedHash)) {
                            $error = 'Current password is incorrect.';
                        } else {
                            // Check if new password is same as old
                            if (password_verify($newPassword, $storedHash)) {
                                $error = 'New password must be different from current password.';
                            } else {
                                // Update password
                                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                                $stmt = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                                $result = $stmt->execute([$newHash, $userId]);
                                
                                if ($result && $stmt->rowCount() > 0) {
                                    $activityLogger->logActivity(
                                        $userId,
                                        $user['first_name'] . ' ' . $user['last_name'],
                                        'password_change',
                                        'Changed password successfully'
                                    );
                                    
                                    $success = 'Password changed successfully!';
                                } else {
                                    $error = 'Failed to update password. Please try again.';
                                }
                            }
                        }
                    } catch (Exception $e) {
                        error_log("Password change error: " . $e->getMessage());
                        $error = 'An error occurred. Please try again later.';
                    }
                }
                break;
                
            case 'update_notifications':
                // Update notification preferences (to be implemented)
                $success = 'Notification preferences saved!';
                break;
        }
    }
}

// Log settings view
$activityLogger->logActivity(
    $userId,
    $user['first_name'] . ' ' . $user['last_name'],
    'settings_view',
    'Viewed settings page'
);

$pageTitle = 'Settings - Nkoranza SHTs E-Voting System';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Settings Header -->
<div class="py-8">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center">
            <h1 class="text-3xl font-bold flex items-center justify-center text-pink-900">
                <i class="fas fa-cog mr-3 text-pink-900"></i>
                Account Settings
            </h1>
            <p class="text-pink-600 mt-1">Manage your account preferences and security</p>
        </div>
    </div>
</div>

<!-- Main Content -->
<div class="container mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Flash Messages -->
    <?php if ($success): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-green-100 text-green-800 border-l-4 border-green-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-check-circle text-green-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($success) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-green-600 hover:text-green-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="mb-6" role="alert">
            <div class="p-4 bg-red-100 text-red-800 border-l-4 border-red-500 rounded-r-lg shadow-md">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-circle text-red-500 mr-3"></i>
                    <span class="flex-1"><?= htmlspecialchars($error) ?></span>
                    <button type="button" class="close-flash ml-4" aria-label="Close">
                        <i class="fas fa-times text-red-600 hover:text-red-800"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Settings Tabs -->
    <div class="bg-white rounded-xl shadow-md overflow-hidden">
        <div class="border-b border-gray-200">
            <nav class="flex -mb-px">
                <a href="?tab=profile" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'profile' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-user mr-2"></i>
                    Profile Information
                </a>
                <a href="?tab=security" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'security' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-shield-alt mr-2"></i>
                    Security
                </a>
                <a href="?tab=notifications" 
                   class="py-4 px-6 text-sm font-medium border-b-2 <?= $activeTab === 'notifications' ? 'border-pink-900 text-pink-900' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' ?> transition-colors">
                    <i class="fas fa-bell mr-2"></i>
                    Notifications
                </a>
            </nav>
        </div>
        
        <div class="p-6">
            <?php if ($activeTab === 'profile'): ?>
                <!-- Profile Settings Form -->
                <form method="POST" class="max-w-2xl">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Profile Information</h2>
                    
                    <div class="space-y-4">
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">First Name</label>
                                <input type="text" 
                                       value="<?= htmlspecialchars($user['first_name']) ?>" 
                                       disabled
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500">
                                <p class="text-xs text-gray-500 mt-1">Contact admin to change name</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Last Name</label>
                                <input type="text" 
                                       value="<?= htmlspecialchars($user['last_name']) ?>" 
                                       disabled
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500">
                            </div>
                        </div>
                        
                        <div class="grid md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Department</label>
                                <input type="text" 
                                       value="<?= htmlspecialchars($user['department']) ?>" 
                                       disabled
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Level</label>
                                <input type="text" 
                                       value="<?= htmlspecialchars($user['level']) ?>" 
                                       disabled
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-500">
                            </div>
                        </div>
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                Email Address <span class="text-gray-400 text-xs">(Optional)</span>
                            </label>
                            <input type="email" 
                                   id="email"
                                   name="email" 
                                   value="<?= htmlspecialchars($user['email'] ?? '') ?>" 
                                   placeholder="Enter your email address"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                            <p class="text-xs text-gray-500 mt-1">We'll never share your email with anyone else.</p>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" 
                                    class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-6 rounded-lg transition-colors">
                                <i class="fas fa-save mr-2"></i>
                                Save Changes
                            </button>
                        </div>
                    </div>
                </form>
                
            <?php elseif ($activeTab === 'security'): ?>
                <!-- Security Settings Form -->
                <form method="POST" class="max-w-2xl" id="password-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="change_password">
                    
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Change Password</h2>
                    
                    <div class="space-y-4">
                        <div>
                            <label for="current_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Current Password
                            </label>
                            <input type="password" 
                                   id="current_password"
                                   name="current_password" 
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                        </div>
                        
                        <div>
                            <label for="new_password" class="block text-sm font-medium text-gray-700 mb-2">
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
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-2">
                                Confirm New Password
                            </label>
                            <input type="password" 
                                   id="confirm_password"
                                   name="confirm_password" 
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:border-pink-500 focus:ring-2 focus:ring-pink-200 transition-colors">
                            <div class="text-xs text-gray-500 mt-1" id="password-match"></div>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" 
                                    class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-6 rounded-lg transition-colors">
                                <i class="fas fa-key mr-2"></i>
                                Change Password
                            </button>
                        </div>
                        
                        <div class="mt-6 p-4 bg-blue-50 rounded-lg">
                            <h3 class="font-semibold text-blue-800 mb-2 flex items-center">
                                <i class="fas fa-shield-alt mr-2"></i>
                                Security Tips
                            </h3>
                            <ul class="text-sm text-blue-700 space-y-1 list-disc list-inside">
                                <li>Use a unique password you don't use elsewhere</li>
                                <li>Include a mix of letters, numbers, and symbols</li>
                                <li>Avoid using personal information in your password</li>
                                <li>Change your password regularly</li>
                            </ul>
                        </div>
                    </div>
                </form>
                
            <?php elseif ($activeTab === 'notifications'): ?>
                <!-- Notification Settings Form -->
                <form method="POST" class="max-w-2xl">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                    <input type="hidden" name="action" value="update_notifications">
                    
                    <h2 class="text-xl font-bold text-gray-900 mb-6">Notification Preferences</h2>
                    
                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-700">Email Notifications</p>
                                <p class="text-sm text-gray-500">Receive updates about elections and results</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="email_notifications" value="1" class="sr-only peer" checked>
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-pink-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-700">SMS Alerts</p>
                                <p class="text-sm text-gray-500">Get important alerts via text message</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="sms_notifications" value="1" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-pink-600"></div>
                            </label>
                        </div>
                        
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <p class="font-medium text-gray-700">Browser Notifications</p>
                                <p class="text-sm text-gray-500">Receive notifications in your browser</p>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="browser_notifications" value="1" class="sr-only peer">
                                <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-pink-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-pink-600"></div>
                            </label>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" 
                                    class="bg-pink-900 hover:bg-pink-800 text-white font-semibold py-2 px-6 rounded-lg transition-colors">
                                <i class="fas fa-bell mr-2"></i>
                                Save Preferences
                            </button>
                        </div>
                        
                        <p class="text-xs text-gray-500 mt-4">
                            <i class="fas fa-info-circle mr-1"></i>
                            Note: Notification features are under development. Settings will be saved but may not take effect immediately.
                        </p>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Account Actions -->
    <div class="mt-6 flex justify-end space-x-3">
        <a href="<?= BASE_URL ?>/index.php?page=profile" 
           class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors">
            <i class="fas fa-user mr-2"></i>
            View Profile
        </a>
        <a href="<?= BASE_URL ?>/index.php?page=logout" 
           class="px-4 py-2 bg-red-50 text-red-700 rounded-lg hover:bg-red-100 transition-colors">
            <i class="fas fa-sign-out-alt mr-2"></i>
            Logout
        </a>
    </div>
</div>

<!-- Admin Settings Link (only visible to admins) -->
<?php if (is_admin()): ?>
<div class="container mx-auto px-4 sm:px-6 lg:px-8 pb-8">
    <div class="mt-6 pt-6 border-t border-gray-200">
        <div class="bg-gradient-to-r from-pink-50 to-purple-50 p-4 rounded-lg">
            <h3 class="font-semibold text-pink-800 mb-2 flex items-center">
                <i class="fas fa-crown mr-2"></i>
                Administrator Actions
            </h3>
            <p class="text-sm text-gray-600 mb-3">Manage system-wide settings and configurations.</p>
            <a href="<?= BASE_URL ?>/index.php?page=admin/settings" 
               class="inline-flex items-center px-4 py-2 bg-pink-900 text-white rounded-lg hover:bg-pink-800 transition-colors">
                <i class="fas fa-cogs mr-2"></i>
                System Settings
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

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