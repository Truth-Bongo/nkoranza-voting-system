<?php
// includes/cookie-consent.php
// Check if user has already set preferences
$showCookieBanner = !isset($_COOKIE['cookie_preferences']) && !isset($_SESSION['cookie_preferences']);
?>

<?php if ($showCookieBanner): ?>
<div id="cookie-consent" class="fixed bottom-0 left-0 right-0 bg-gray-900 text-white p-4 z-50 transform transition-transform duration-300 translate-y-0">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row items-center justify-between gap-4">
        <div class="flex items-center">
            <i class="fas fa-cookie-bite text-2xl text-yellow-400 mr-3"></i>
            <p class="text-sm">
                We use cookies to enhance your experience. By continuing to visit this site, you agree to our use of cookies.
                <a href="<?= BASE_URL ?>/cookies" class="text-pink-300 hover:text-pink-200 underline ml-1">Learn more</a>
            </p>
        </div>
        <div class="flex space-x-3">
            <button onclick="acceptAllCookies()" class="bg-pink-600 hover:bg-pink-700 text-white px-6 py-2 rounded-lg text-sm font-medium whitespace-nowrap">
                Accept All
            </button>
            <button onclick="openCookiePreferences()" class="bg-gray-700 hover:bg-gray-600 text-white px-6 py-2 rounded-lg text-sm font-medium whitespace-nowrap">
                Preferences
            </button>
        </div>
    </div>
</div>

<script>
function acceptAllCookies() {
    // Set all cookie preferences to true
    const preferences = {
        essential: true,
        functional: true,
        analytics: true,
        preferences: true
    };
    
    // Save to cookie (expires in 6 months)
    document.cookie = 'cookie_preferences=' + JSON.stringify(preferences) + '; path=/; max-age=' + (60 * 60 * 24 * 180);
    
    // Hide banner
    document.getElementById('cookie-consent').style.transform = 'translateY(100%)';
    
    // Optional: Reload page to apply analytics if enabled
    setTimeout(() => {
        location.reload();
    }, 500);
}

function openCookiePreferences() {
    window.location.href = '<?= BASE_URL ?>/cookies';
}
</script>
<?php endif; ?>