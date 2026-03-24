<?php
// Start session with secure settings but ensure it works on all environments
if (session_status() === PHP_SESSION_NONE) {
    // Remove strict options that might cause issues on some hosts
    session_start();
}

// Generate CSRF token if not exists - use a simpler approach
if (empty($_SESSION['csrf_token'])) {
    try {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        // Fallback for systems without random_bytes
        $_SESSION['csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
    }
}

require_once __DIR__ . '/../config/constants.php';

// Helper function for safe session data access
function getSessionData($key, $default = '') {
    return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
}

// Get user display name safely
$displayName = '';
if (isset($_SESSION['full_name']) && !empty($_SESSION['full_name'])) {
    $displayName = $_SESSION['full_name'];
} elseif (isset($_SESSION['first_name']) && !empty($_SESSION['first_name'])) {
    $displayName = $_SESSION['first_name'];
} else {
    $displayName = 'User';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?= htmlspecialchars($pageTitle ?? 'Nkoranza SHTs - E-Voting System', ENT_QUOTES, 'UTF-8') ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/logo.png">
    
    <!-- Preconnect to external domains -->
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <!-- Scripts - Define BASE_URL and CSRF token FIRST before any other scripts -->
    <script>
        // Define global constants for JavaScript - make sure these are set correctly
        window.BASE_URL = "<?= rtrim(BASE_URL, '/') ?>";
        window.CSRF_TOKEN = "<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>";
        
        // For debugging - remove in production
        console.log('BASE_URL set to:', window.BASE_URL);
        console.log('CSRF_TOKEN set to:', window.CSRF_TOKEN ? 'Token exists' : 'No token');
    </script>
    
    <!-- Styles -->
     <!-- Add this in head section -->
    <script src="//unpkg.com/alpinejs" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="<?= BASE_URL ?>/assets/css/styles.css" rel="stylesheet">
    
    <style>
        /* Mobile menu styles */
        #mobile-menu {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        
        #mobile-menu.hidden {
            display: none !important;
        }
        
        @media (max-width: 768px) {
            nav ul {
                display: none;
            }
        }
        
        @media (min-width: 769px) {
            #mobile-menu-btn, #mobile-menu {
                display: none !important;
            }
        }
        
        /* Focus styles for accessibility */
        a:focus-visible, button:focus-visible {
            outline: 2px solid #fbbf24;
            outline-offset: 2px;
        }
        
        /* Loading spinner for AJAX requests */
        .loading-spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #fbbf24;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="font-sans antialiased bg-gray-100">
    <div id="app" class="min-h-screen flex flex-col">
        <!-- Header -->
        <header class="bg-pink-900 text-white shadow-md sticky top-0 z-40">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between items-center h-16">
                    <!-- Logo and brand -->
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-vote-yea text-2xl text-white-400" aria-hidden="true"></i>
                        <h1 class="text-lg sm:text-xl font-bold truncate max-w-[200px] sm:max-w-none">
                            Nkoranza SHTs E-Voting
                        </h1>
                    </div>
                    
                    <!-- Desktop navigation -->
                    <nav class="hidden md:block" aria-label="Main navigation">
                        <ul class="flex space-x-1 lg:space-x-4">
                            <?php 
                            $navItems = [
                                ['url' => BASE_URL, 'label' => 'Home', 'icon' => 'fa-home'],
                                ['url' => BASE_URL . '/elections', 'label' => 'Elections', 'icon' => 'fa-calendar-alt'],
                                ['url' => BASE_URL . '/results', 'label' => 'Results', 'icon' => 'fa-chart-bar'],
                            ];
                            
                            if (isset($_SESSION['user_id'])) {
                                $navItems[] = ['url' => BASE_URL . '/vote', 'label' => 'Vote', 'icon' => 'fa-check-circle'];
                            }
                            
                            if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == true) {
                                $navItems[] = ['url' => BASE_URL . '/admin/dashboard', 'label' => 'Admin', 'icon' => 'fa-cog'];
                            }
                            
                            foreach ($navItems as $item): 
                                $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
                                $isActive = strpos($currentUrl, $item['url']) === 0 && $item['url'] !== BASE_URL ? true : 
                                           ($item['url'] === BASE_URL && $currentUrl === BASE_URL . '/');
                            ?>
                                <li>
                                    <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>" 
                                       class="flex items-center space-x-1 px-3 py-2 rounded-md text-sm font-medium hover:bg-pink-800 hover:text-yellow-400 transition-colors duration-200 <?= $isActive ? 'text-yellow-400 bg-pink-800' : '' ?>">
                                        <i class="fas <?= $item['icon'] ?> text-sm" aria-hidden="true"></i>
                                        <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            
                            <!-- Auth links -->
                            <li class="border-l border-pink-700 pl-2 ml-2">
                                <?php if (!isset($_SESSION['user_id'])): ?>
                                    <a href="<?= BASE_URL ?>/login" 
                                       class="flex items-center space-x-1 px-3 py-2 rounded-md text-sm font-medium hover:bg-pink-800 hover:text-yellow-400 transition-colors duration-200">
                                        <i class="fas fa-sign-in-alt" aria-hidden="true"></i>
                                        <span>Login</span>
                                    </a>
                                <?php else: ?>
                                    <div class="relative">
                                        <button id="user-menu-button" class="flex items-center space-x-2 px-3 py-2 rounded-md text-sm font-medium hover:bg-pink-800 transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-yellow-400" aria-expanded="false">
                                            <i class="fas fa-user-circle text-lg" aria-hidden="true"></i>
                                            <span class="max-w-[100px] truncate hidden lg:inline"><?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?></span>
                                            <i class="fas fa-chevron-down text-xs" aria-hidden="true"></i>
                                        </button>
                                        
                                        <!-- Dropdown menu - hidden by default, shown on hover/focus -->
                                        <div id="user-dropdown" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 hidden z-50">
                                            <a href="<?= BASE_URL ?>/profile" 
                                               class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                <i class="fas fa-user mr-2"></i> Profile
                                            </a>
                                            <a href="<?= BASE_URL ?>/settings" 
                                               class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                                                <i class="fas fa-cog mr-2"></i> Settings
                                            </a>
                                            <hr class="my-1">
                                            <a href="<?= BASE_URL ?>/logout" 
                                               class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                                            </a>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </li>
                        </ul>
                    </nav>
                    
                    <!-- Mobile menu button -->
                    <button id="mobile-menu-btn" 
                            class="md:hidden p-2 rounded-md text-white hover:bg-pink-800 focus:outline-none focus:ring-2 focus:ring-yellow-400" 
                            aria-label="Toggle menu"
                            aria-expanded="false">
                        <i class="fas fa-bars text-xl" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            
            <!-- Mobile menu -->
            <div id="mobile-menu" class="hidden md:hidden bg-pink-800">
                <div class="container mx-auto px-4 py-3">
                    <ul class="space-y-2">
                        <?php foreach ($navItems as $item): ?>
                            <li>
                                <a href="<?= htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') ?>" 
                                   class="flex items-center space-x-3 px-4 py-3 rounded-md hover:bg-pink-700 transition-colors duration-200">
                                    <i class="fas <?= $item['icon'] ?> w-5 text-center" aria-hidden="true"></i>
                                    <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                        
                        <!-- Mobile auth links -->
                        <?php if (!isset($_SESSION['user_id'])): ?>
                            <li>
                                <a href="<?= BASE_URL ?>/login" 
                                   class="flex items-center space-x-3 px-4 py-3 rounded-md hover:bg-pink-700 transition-colors duration-200">
                                    <i class="fas fa-sign-in-alt w-5 text-center" aria-hidden="true"></i>
                                    <span>Login</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <li class="pt-2 border-t border-pink-700">
                                <div class="px-4 py-2 text-sm text-pink-300">
                                    <i class="fas fa-user-circle mr-2" aria-hidden="true"></i>
                                    <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/profile" 
                                   class="flex items-center space-x-3 px-4 py-3 rounded-md hover:bg-pink-700 transition-colors duration-200">
                                    <i class="fas fa-user w-5 text-center" aria-hidden="true"></i>
                                    <span>Profile</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/settings" 
                                   class="flex items-center space-x-3 px-4 py-3 rounded-md hover:bg-pink-700 transition-colors duration-200">
                                    <i class="fas fa-cog w-5 text-center" aria-hidden="true"></i>
                                    <span>Settings</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/logout" 
                                   class="flex items-center space-x-3 px-4 py-3 rounded-md text-red-300 hover:bg-pink-700 transition-colors duration-200">
                                    <i class="fas fa-sign-out-alt w-5 text-center" aria-hidden="true"></i>
                                    <span>Logout</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </header>
        
        <!-- Main content -->
        <main class="flex-1">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-6">
                <!-- Flash messages -->
                <?php if (isset($_SESSION['flash_message'])): ?>
                    <div class="mb-6" role="alert">
                        <div class="p-4 <?= ($_SESSION['flash_type'] ?? 'info') === 'error' ? 'bg-red-100 text-red-800 border-l-4 border-red-500' : 'bg-green-100 text-green-800 border-l-4 border-green-500' ?> rounded-r-lg shadow-md">
                            <div class="flex items-center">
                                <div class="flex-shrink-0">
                                    <?php if (($_SESSION['flash_type'] ?? 'info') === 'error'): ?>
                                        <i class="fas fa-exclamation-circle text-red-500" aria-hidden="true"></i>
                                    <?php else: ?>
                                        <i class="fas fa-check-circle text-green-500" aria-hidden="true"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="ml-3 flex-1">
                                    <p class="text-sm font-medium">
                                        <?= htmlspecialchars($_SESSION['flash_message'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                                <button type="button" class="ml-auto close-flash" aria-label="Close">
                                    <i class="fas fa-times text-gray-400 hover:text-gray-600"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
                <?php endif; ?>

<!-- Cookie Consent Banner -->
<?php include APP_ROOT . '/includes/cookie-consent.php'; ?>

<script>
// Modern JavaScript with improved error handling
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    
    // Mobile menu functionality
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');
    
    if (mobileMenuBtn && mobileMenu) {
        mobileMenuBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const isHidden = mobileMenu.classList.contains('hidden');
            mobileMenu.classList.toggle('hidden');
            mobileMenuBtn.setAttribute('aria-expanded', isHidden ? 'true' : 'false');
        });
        
        // Close when clicking outside
        document.addEventListener('click', function(e) {
            if (!mobileMenu.classList.contains('hidden') && 
                !mobileMenu.contains(e.target) && 
                e.target !== mobileMenuBtn && 
                !mobileMenuBtn.contains(e.target)) {
                mobileMenu.classList.add('hidden');
                mobileMenuBtn.setAttribute('aria-expanded', 'false');
            }
        });
        
        // Close on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
                mobileMenu.classList.add('hidden');
                mobileMenuBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }
    
    // User dropdown menu functionality
    const userMenuButton = document.getElementById('user-menu-button');
    const userDropdown = document.getElementById('user-dropdown');
    
    if (userMenuButton && userDropdown) {
        userMenuButton.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const isHidden = userDropdown.classList.contains('hidden');
            userDropdown.classList.toggle('hidden');
            userMenuButton.setAttribute('aria-expanded', !isHidden);
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!userDropdown.classList.contains('hidden') && 
                !userDropdown.contains(e.target) && 
                e.target !== userMenuButton && 
                !userMenuButton.contains(e.target)) {
                userDropdown.classList.add('hidden');
                userMenuButton.setAttribute('aria-expanded', 'false');
            }
        });
    }
    
    // Flash message close functionality
    document.querySelectorAll('.close-flash').forEach(function(button) {
        button.addEventListener('click', function(e) {
            const flashMessage = e.target.closest('[role="alert"]');
            if (flashMessage) {
                flashMessage.style.transition = 'opacity 0.3s ease';
                flashMessage.style.opacity = '0';
                setTimeout(function() {
                    if (flashMessage.parentNode) {
                        flashMessage.remove();
                    }
                }, 300);
            }
        });
    });
    
    // Auto-hide flash messages after 5 seconds
    setTimeout(function() {
        document.querySelectorAll('[role="alert"]').forEach(function(alert) {
            alert.style.transition = 'opacity 0.3s ease';
            alert.style.opacity = '0';
            setTimeout(function() {
                if (alert.parentNode) {
                    alert.remove();
                }
            }, 300);
        });
    }, 5000);
    
    // Helper function for AJAX requests (for login forms)
    window.makeRequest = function(url, method = 'GET', data = null) {
        return new Promise(function(resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open(method, url, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('X-CSRF-Token', window.CSRF_TOKEN);
            
            xhr.onload = function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    try {
                        const response = JSON.parse(xhr.responseText);
                        resolve(response);
                    } catch (e) {
                        resolve(xhr.responseText);
                    }
                } else {
                    reject({
                        status: xhr.status,
                        statusText: xhr.statusText,
                        response: xhr.responseText
                    });
                }
            };
            
            xhr.onerror = function() {
                reject({
                    status: 0,
                    statusText: 'Network Error',
                    response: 'Could not connect to the server. Please check your internet connection.'
                });
            };
            
            if (data) {
                xhr.send(JSON.stringify(data));
            } else {
                xhr.send();
            }
        });
    };
    
    console.log('Header initialized successfully');
});
</script>