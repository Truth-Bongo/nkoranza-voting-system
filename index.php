<?php
// index.php - Main router
require_once __DIR__ . '/bootstrap.php';

// Routing - get the page from URL or default to home
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

// Remove any trailing slashes and ensure proper formatting
$page = rtrim($page, '/');

// Security: prevent directory traversal
$page = str_replace(['..', './', '.php', '\\'], '', $page);

// Define public pages that don't require login
$publicPages = [
    'home', 'login', 'about', 'faq', 'guidelines', 
    'privacy', 'terms', 'sitemap', 'accessibility', 'cookies',
    'results', 'elections'
];

// Define pages that require login
$protectedPages = [
    'vote', 'profile', 'settings', 'logout'
];

// Define admin-only pages
$adminPages = [
    'admin', 'admin/dashboard', 'admin/voters', 'admin/elections',
    'admin/candidates', 'admin/graduation', 'admin/activity_logs',
    'admin/settings'
];

// Check access permissions
if (in_array($page, $protectedPages)) {
    require_login();
} elseif (in_array($page, $adminPages) || strpos($page, 'admin/') === 0) {
    require_admin();
} elseif (!in_array($page, $publicPages) && $page !== '404') {
    // Page not found - 404
    $page = '404';
}

// Route to the appropriate page
switch ($page) {
    // Admin routes
    case 'admin':
    case 'admin/dashboard':
        require_once APP_ROOT . '/views/admin/dashboard.php';
        break;
    case 'admin/voters':
        require_once APP_ROOT . '/views/admin/voters.php';
        break;
    case 'admin/elections':
        require_once APP_ROOT . '/views/admin/elections.php';
        break;
    case 'admin/candidates':
        require_once APP_ROOT . '/views/admin/candidates.php';
        break;
    case 'admin/graduation':
        require_once APP_ROOT . '/views/admin/graduation.php';
        break;
    case 'admin/activity_logs':
        require_once APP_ROOT . '/views/admin/activity_logs.php';
        break;
    case 'admin/settings':
        // This should point to the actual admin settings file
        if (file_exists(APP_ROOT . '/admin/settings.php')) {
            require_once APP_ROOT . '/admin/settings.php';
        } else {
            require_once APP_ROOT . '/views/admin/settings.php';
        }
        break;
    
    // Auth routes
    case 'login':
        // If already logged in, redirect to home
        if (isset($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/index.php?page=home');
            exit;
        }
        require_once APP_ROOT . '/views/auth/login.php';
        break;
    case 'logout':
        // Handle logout directly
        session_destroy();
        $_SESSION = []; // Clear all session data
        header('Location: ' . BASE_URL . '/index.php?page=login');
        exit;
        break;
    
    // User routes
    case 'vote':
        require_login();
        require_once APP_ROOT . '/views/vote.php';
        break;
    case 'results':
        require_once APP_ROOT . '/views/results.php';
        break;
    case 'elections':
        require_once APP_ROOT . '/views/elections.php';
        break;
    case 'profile':
        require_login();
        require_once APP_ROOT . '/views/profile.php';
        break;
    case 'settings':
        require_login();
        require_once APP_ROOT . '/views/settings.php';
        break;
    
    // Public info pages
    case 'about':
        require_once APP_ROOT . '/views/about.php';
        break;
    case 'faq':
        require_once APP_ROOT . '/views/faq.php';
        break;
    case 'guidelines':
        require_once APP_ROOT . '/views/guidelines.php';
        break;
    case 'privacy':
        require_once APP_ROOT . '/views/privacy.php';
        break;
    case 'terms':
        require_once APP_ROOT . '/views/terms.php';
        break;
    case 'sitemap':
        require_once APP_ROOT . '/views/sitemap.php';
        break;
    case 'accessibility':
        require_once APP_ROOT . '/views/accessibility.php';
        break;
    case 'cookies':
        require_once APP_ROOT . '/views/cookies.php';
        break;
    
    // Home page
    case 'home':
        require_once APP_ROOT . '/views/home.php';
        break;
    
    // 404 page
    case '404':
    default:
        header("HTTP/1.0 404 Not Found");
        require_once APP_ROOT . '/views/404.php';
        break;
}