<?php
// views/cookies.php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$pageTitle = "Cookie Policy - Nkoranza SHTs E-Voting System";
$lastUpdated = "March 15, 2025";

// Handle cookie preferences
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cookies'])) {
    $preferences = [
        'essential' => true, // Always required
        'functional' => isset($_POST['functional']),
        'analytics' => isset($_POST['analytics']),
        'preferences' => isset($_POST['preferences'])
    ];
    
    // Store preferences in a cookie (expires in 6 months)
    setcookie('cookie_preferences', json_encode($preferences), time() + (86400 * 180), '/');
    
    // Also store in session for immediate use
    $_SESSION['cookie_preferences'] = $preferences;
    
    $preferences_updated = true;
}

// Get current preferences
$cookiePrefs = $_COOKIE['cookie_preferences'] ?? $_SESSION['cookie_preferences'] ?? null;
$preferences = $cookiePrefs ? json_decode($cookiePrefs, true) : [
    'essential' => true,
    'functional' => false,
    'analytics' => false,
    'preferences' => false
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/images/logo.png">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <?php include APP_ROOT . '/includes/header.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-12">
        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Cookie Policy</h1>
            <p class="text-xl text-gray-600">How we use cookies to improve your experience</p>
            <p class="text-sm text-gray-500 mt-2">Last Updated: <?= $lastUpdated ?></p>
        </div>

        <!-- Cookie Consent Status -->
        <?php if (isset($preferences_updated)): ?>
            <div class="mb-8 p-4 bg-green-100 border-l-4 border-green-500 text-green-700 rounded-r-lg">
                <i class="fas fa-check-circle mr-2"></i>
                Your cookie preferences have been saved successfully.
            </div>
        <?php endif; ?>

        <!-- Introduction -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-4">What Are Cookies?</h2>
            <p class="text-gray-600 leading-relaxed mb-4">
                Cookies are small text files that are placed on your computer or mobile device when you visit a website. 
                They are widely used to make websites work more efficiently and provide useful information to website owners.
            </p>
            <p class="text-gray-600 leading-relaxed">
                This page explains the types of cookies we use, why we use them, and how you can control them.
            </p>
        </div>

        <!-- Cookie Preferences Form -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Manage Your Cookie Preferences</h2>
            
            <form method="POST" class="space-y-6">
                <!-- Essential Cookies (Always enabled) -->
                <div class="bg-gray-50 p-6 rounded-lg">
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input type="checkbox" checked disabled 
                                   class="h-4 w-4 text-pink-600 border-gray-300 rounded bg-gray-100">
                        </div>
                        <div class="ml-3">
                            <label class="font-medium text-gray-900">Essential Cookies</label>
                            <p class="text-sm text-gray-500 mt-1">
                                Required for the website to function properly. These cannot be disabled.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Functional Cookies -->
                <div class="border rounded-lg p-6">
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input type="checkbox" name="functional" id="functional" value="1" 
                                   <?= $preferences['functional'] ? 'checked' : '' ?>
                                   class="h-4 w-4 text-pink-600 border-gray-300 rounded focus:ring-pink-500">
                        </div>
                        <div class="ml-3">
                            <label for="functional" class="font-medium text-gray-900">Functional Cookies</label>
                            <p class="text-sm text-gray-500 mt-1">
                                Enhance functionality and personalization, such as remembering your preferences.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Analytics Cookies -->
                <div class="border rounded-lg p-6">
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input type="checkbox" name="analytics" id="analytics" value="1" 
                                   <?= $preferences['analytics'] ? 'checked' : '' ?>
                                   class="h-4 w-4 text-pink-600 border-gray-300 rounded focus:ring-pink-500">
                        </div>
                        <div class="ml-3">
                            <label for="analytics" class="font-medium text-gray-900">Analytics Cookies</label>
                            <p class="text-sm text-gray-500 mt-1">
                                Help us understand how visitors interact with our website by collecting anonymous information.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Preference Cookies -->
                <div class="border rounded-lg p-6">
                    <div class="flex items-start">
                        <div class="flex items-center h-5">
                            <input type="checkbox" name="preferences" id="preferences" value="1" 
                                   <?= $preferences['preferences'] ? 'checked' : '' ?>
                                   class="h-4 w-4 text-pink-600 border-gray-300 rounded focus:ring-pink-500">
                        </div>
                        <div class="ml-3">
                            <label for="preferences" class="font-medium text-gray-900">Preference Cookies</label>
                            <p class="text-sm text-gray-500 mt-1">
                                Remember your settings and choices to provide a personalized experience.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" name="update_cookies" value="1" 
                            class="bg-pink-900 hover:bg-pink-800 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                        <i class="fas fa-save mr-2"></i>
                        Save Preferences
                    </button>
                </div>
            </form>
        </div>

        <!-- Cookie Details Table -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Cookies We Use</h2>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Cookie Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Purpose</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr>
                            <td class="px-6 py-4 font-mono text-sm">PHPSESSID</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">Essential</span></td>
                            <td class="px-6 py-4 text-sm">Maintains user session state</td>
                            <td class="px-6 py-4 text-sm">Session</td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 font-mono text-sm">csrf_token</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full">Essential</span></td>
                            <td class="px-6 py-4 text-sm">Security token to prevent CSRF attacks</td>
                            <td class="px-6 py-4 text-sm">Session</td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 font-mono text-sm">cookie_preferences</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full">Functional</span></td>
                            <td class="px-6 py-4 text-sm">Stores your cookie preferences</td>
                            <td class="px-6 py-4 text-sm">6 months</td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 font-mono text-sm">_ga</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Analytics</span></td>
                            <td class="px-6 py-4 text-sm">Google Analytics - distinguishes users</td>
                            <td class="px-6 py-4 text-sm">2 years</td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 font-mono text-sm">_gid</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 bg-yellow-100 text-yellow-800 text-xs rounded-full">Analytics</span></td>
                            <td class="px-6 py-4 text-sm">Google Analytics - distinguishes users</td>
                            <td class="px-6 py-4 text-sm">24 hours</td>
                        </tr>
                        <tr>
                            <td class="px-6 py-4 font-mono text-sm">user_preferences</td>
                            <td class="px-6 py-4"><span class="px-2 py-1 bg-purple-100 text-purple-800 text-xs rounded-full">Preferences</span></td>
                            <td class="px-6 py-4 text-sm">Stores user interface preferences</td>
                            <td class="px-6 py-4 text-sm">1 year</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Third-Party Cookies -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Third-Party Cookies</h2>
            <p class="text-gray-600 leading-relaxed mb-4">
                We use some third-party services that may set their own cookies. These include:
            </p>
            <ul class="list-disc list-inside space-y-2 text-gray-600">
                <li><strong>Google Analytics</strong> - Helps us understand how visitors use our site</li>
                <li><strong>Font Awesome</strong> - Provides icon fonts (may not set cookies)</li>
                <li><strong>Tailwind CSS</strong> - CSS framework (does not set cookies)</li>
            </ul>
            <p class="text-gray-600 mt-4">
                These third-party services are subject to their own privacy policies.
            </p>
        </div>

        <!-- How to Control Cookies -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-4">How to Control Cookies</h2>
            <p class="text-gray-600 leading-relaxed mb-4">
                You can control and/or delete cookies as you wish. You can delete all cookies that are already on 
                your computer and you can set most browsers to prevent them from being placed.
            </p>
            
            <h3 class="text-lg font-bold text-gray-900 mb-3">Browser Settings</h3>
            <div class="grid md:grid-cols-2 gap-4 mb-6">
                <a href="https://support.google.com/chrome/answer/95647" target="_blank" 
                   class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                    <i class="fab fa-chrome text-2xl text-blue-600 mr-3"></i>
                    <span>Chrome Instructions</span>
                    <i class="fas fa-external-link-alt ml-auto text-sm text-gray-400"></i>
                </a>
                <a href="https://support.mozilla.org/kb/delete-cookies-remove-info-websites-stored" target="_blank"
                   class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                    <i class="fab fa-firefox text-2xl text-orange-600 mr-3"></i>
                    <span>Firefox Instructions</span>
                    <i class="fas fa-external-link-alt ml-auto text-sm text-gray-400"></i>
                </a>
                <a href="https://support.apple.com/guide/safari/manage-cookies-and-website-data-sfri11471/mac" target="_blank"
                   class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                    <i class="fab fa-safari text-2xl text-blue-800 mr-3"></i>
                    <span>Safari Instructions</span>
                    <i class="fas fa-external-link-alt ml-auto text-sm text-gray-400"></i>
                </a>
                <a href="https://support.microsoft.com/microsoft-edge/delete-cookies-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank"
                   class="flex items-center p-3 border rounded-lg hover:bg-gray-50">
                    <i class="fab fa-edge text-2xl text-blue-600 mr-3"></i>
                    <span>Edge Instructions</span>
                    <i class="fas fa-external-link-alt ml-auto text-sm text-gray-400"></i>
                </a>
            </div>
        </div>

        <!-- Cookie Banner Preview -->
        <div class="bg-gray-900 text-white rounded-xl p-6 mb-8">
            <h3 class="text-lg font-bold mb-3 flex items-center">
                <i class="fas fa-cookie-bite text-yellow-400 mr-2"></i>
                Cookie Banner Preview
            </h3>
            <p class="text-gray-300 mb-4">
                This is how our cookie consent banner appears to first-time visitors:
            </p>
            <div class="bg-gray-800 rounded-lg p-4 flex flex-col sm:flex-row items-center justify-between gap-4">
                <div class="flex items-center">
                    <i class="fas fa-cookie-bite text-2xl text-yellow-400 mr-3"></i>
                    <span class="text-sm">We use cookies to enhance your experience. By continuing to visit this site, you agree to our use of cookies.</span>
                </div>
                <div class="flex space-x-2">
                    <button class="bg-pink-600 hover:bg-pink-700 text-white px-4 py-2 rounded-lg text-sm whitespace-nowrap">
                        Accept All
                    </button>
                    <button class="bg-gray-700 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm whitespace-nowrap">
                        Preferences
                    </button>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-800 rounded-xl p-8 text-white">
            <h2 class="text-2xl font-bold mb-4">Questions About Cookies?</h2>
            <p class="mb-6 opacity-90">
                If you have any questions about our use of cookies, please contact our Data Protection Officer:
            </p>
            <div class="grid md:grid-cols-2 gap-4">
                <div class="flex items-center">
                    <i class="fas fa-envelope text-2xl mr-3"></i>
                    <div>
                        <div class="text-sm opacity-75">Email</div>
                        <a href="mailto:dpo@nkoranzashts.edu.gh" class="hover:underline">
                            dpo@nkoranzashts.edu.gh
                        </a>
                    </div>
                </div>
                <div class="flex items-center">
                    <i class="fas fa-phone-alt text-2xl mr-3"></i>
                    <div>
                        <div class="text-sm opacity-75">Phone</div>
                        <a href="tel:+233545811179" class="hover:underline">
                            +233 54 581 1179
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>