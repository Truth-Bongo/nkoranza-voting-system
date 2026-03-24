<?php
// Ensure session is started securely but simply
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Function to safely get CSRF token
function getCsrfToken() {
    return $_SESSION['csrf_token'] ?? '';
}
?>

<script>
    // Modern JavaScript configuration with proper encoding
    window.APP_CONFIG = {
        ...window.APP_CONFIG,
        BASE_URL: "<?= rtrim(htmlspecialchars(BASE_URL, ENT_QUOTES, 'UTF-8'), '/') ?>",
        CSRF_TOKEN: "<?= htmlspecialchars(getCsrfToken(), ENT_QUOTES, 'UTF-8') ?>",
        CURRENT_YEAR: <?= date('Y') ?>
    };
</script>

    </main> <!-- End of main content -->

    <!-- Footer -->
    <footer class="bg-gradient-to-b from-pink-900 to-pink-950 text-white mt-auto" role="contentinfo" aria-label="Site footer">
        <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 lg:gap-12">
                <!-- About Section -->
                <div class="space-y-4">
                    <div class="flex items-center space-x-3">
                        <i class="fas fa-vote-yea text-2xl text-yellow-400" aria-hidden="true"></i>
                        <h3 class="text-xl font-bold">Nkoranza SHTs</h3>
                    </div>
                    <p class="text-gray-300 text-sm leading-relaxed">
                        Empowering students through democratic processes and transparent elections since 2025.
                    </p>
                    <div class="flex space-x-4 pt-2">
                        <a href="https://www.facebook.com/share/1GFWqCqbek/?mibextid=wwXIfr" 
                                target="_blank" class="social-link facebook" title="Follow us on Facebook">
                                <i class="fab fa-facebook-f"></i>
                        </a>
                        <a href="https://www.tiktok.com/@sectechvibes?_r=1&_t=ZS-94Pi0F1VcY9" 
                                target="_blank" class="social-link tiktok" title="Follow us on TikTok">
                                <i class="fab fa-tiktok"></i>
                        </a>
                       <a href="https://www.instagram.com/sectechvibes?igsh=c3loeHpwYmV4NjE5" 
                                target="_blank" class="social-link instagram" title="Follow us on Instagram">
                                <i class="fab fa-instagram"></i>
                        </a>
                        <a href="#" class="social-link website" title="Visit our website" 
                                onclick="window.open('https://sectechvibes.com', '_blank');">
                                <i class="fas fa-globe"></i>
                        </a>
                    </div>
                </div>

                <!-- Quick Links -->
                <div>
                    <h4 class="text-lg font-semibold mb-4 text-yellow-400">Quick Links</h4>
                    <ul class="space-y-3">
                        <?php 
                        $quickLinks = [
                            ['url' => BASE_URL, 'label' => 'Home', 'icon' => 'fa-home'],
                            ['url' => BASE_URL . '/elections', 'label' => 'Elections', 'icon' => 'fa-calendar-alt'],
                            ['url' => BASE_URL . '/results', 'label' => 'Results', 'icon' => 'fa-chart-bar'],
                            ['url' => BASE_URL . '/candidates', 'label' => 'Candidates', 'icon' => 'fa-users'],
                            ['url' => BASE_URL . '/about', 'label' => 'About Us', 'icon' => 'fa-info-circle']
                        ];
                        
                        foreach ($quickLinks as $link): ?>
                            <li>
                                <a href="<?= htmlspecialchars($link['url'], ENT_QUOTES, 'UTF-8') ?>" 
                                   class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas <?= $link['icon'] ?> text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span><?= htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Admin Resources (only visible to admins) -->
                <div>
                    <h4 class="text-lg font-semibold mb-4 text-yellow-400"><?= isset($_SESSION['is_admin']) && $_SESSION['is_admin'] ? 'Admin' : 'Resources' ?></h4>
                    <ul class="space-y-3">
                        <?php if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']): ?>
                            <!-- Admin Links -->
                            <li>
                                <a href="<?= BASE_URL ?>/admin/dashboard" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-tachometer-alt text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Dashboard</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/admin/candidates" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-user-tie text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Manage Candidates</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/admin/elections" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-calendar-alt text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Manage Elections</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/admin/voters" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-users-cog text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Manage Users</span>
                                </a>
                            </li>
                        <?php else: ?>
                            <!-- Public Resources -->
                            <li>
                                <a href="<?= BASE_URL ?>/faq" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-question-circle text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>FAQ</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/guidelines" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-file-alt text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Voting Guidelines</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/privacy" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-shield-alt text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Privacy Policy</span>
                                </a>
                            </li>
                            <li>
                                <a href="<?= BASE_URL ?>/terms" class="flex items-center space-x-3 text-gray-400 hover:text-white transition-colors duration-200 group">
                                    <i class="fas fa-gavel text-sm w-5 text-center text-pink-500 group-hover:text-yellow-400 transition-colors duration-200" aria-hidden="true"></i>
                                    <span>Terms of Service</span>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Contact Information -->
                <div>
                    <h4 class="text-lg font-semibold mb-4 text-yellow-400">Contact Us</h4>
                    <address class="not-italic text-gray-300 space-y-4">
                        <div class="flex items-start space-x-3">
                            <i class="fas fa-map-marker-alt mt-1 text-pink-500" aria-hidden="true"></i>
                            <span class="text-sm">
                                Nkoranza Senior High Technical School<br>
                                Bono East Region, Ghana<br>
                                P.O. Box 28
                            </span>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-phone-alt text-pink-500" aria-hidden="true"></i>
                            <a href="tel:+233549632116" class="text-sm text-gray-400 hover:text-white transition-colors duration-200">
                                +233 54 581 1179
                            </a>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-envelope text-pink-500" aria-hidden="true"></i>
                            <a href="mailto:elections@nkoranzashts.edu.gh" class="text-sm text-gray-400 hover:text-white transition-colors duration-200 break-all">
                                elections@nkoranzashts.edu.gh
                            </a>
                        </div>
                        
                        <div class="flex items-center space-x-3">
                            <i class="fas fa-clock text-pink-500" aria-hidden="true"></i>
                            <span class="text-sm">
                                Mon - Fri: 8:00 AM - 4:00 PM<br>
                            </span>
                        </div>
                    </address>
                </div>
            </div>

            <!-- Footer Bottom -->
            <div class="border-t border-pink-800 mt-8 pt-6 flex flex-col md:flex-row justify-between items-center text-sm text-gray-400">
                <p class="text-center md:text-left">
                    &copy; <?= date('Y') ?> Nkoranza Senior High Technical School. 
                    <span class="block sm:inline">All rights reserved.</span>
                </p>
                
                <div class="flex space-x-6 mt-4 md:mt-0">
                    <a href="<?= BASE_URL ?>/sitemap" class="hover:text-white transition-colors duration-200">Sitemap</a>
                    <a href="<?= BASE_URL ?>/accessibility" class="hover:text-white transition-colors duration-200">Accessibility</a>
                    <a href="<?= BASE_URL ?>/cookies" class="hover:text-white transition-colors duration-200">Cookies</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- Back to Top Button -->
    <button id="back-to-top" 
            class="fixed bottom-4 right-4 bg-yellow-500 text-pink-900 p-3 rounded-full shadow-lg hover:bg-yellow-400 transition-all duration-300 opacity-0 invisible z-50 focus:outline-none focus:ring-2 focus:ring-yellow-400"
            aria-label="Back to top"
            onclick="window.scrollTo({top: 0, behavior: 'smooth'})">
        <i class="fas fa-arrow-up" aria-hidden="true"></i>
    </button>

</div> <!-- End of app wrapper -->

<script>
// Modern JavaScript with ES6+ features
(function() {
    'use strict';

    // Back to top button visibility
    const backToTop = document.getElementById('back-to-top');
    if (backToTop) {
        const toggleBackToTop = () => {
            if (window.scrollY > 300) {
                backToTop.classList.remove('opacity-0', 'invisible');
                backToTop.classList.add('opacity-100', 'visible');
            } else {
                backToTop.classList.add('opacity-0', 'invisible');
                backToTop.classList.remove('opacity-100', 'visible');
            }
        };
        
        window.addEventListener('scroll', toggleBackToTop);
        toggleBackToTop(); // Initial check
    }

    // Smooth scroll for anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#') {
                e.preventDefault();
                const target = document.querySelector(href);
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            }
        });
    });

    // Helper function for showing notifications
    window.showNotification = function(message, type = 'info') {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `fixed top-4 right-4 p-4 rounded-lg shadow-lg z-50 transform transition-all duration-300 translate-x-full ${
            type === 'success' ? 'bg-green-500' : 
            type === 'error' ? 'bg-red-500' : 'bg-blue-500'
        } text-white`;
        notification.innerHTML = `
            <div class="flex items-center space-x-3">
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                <span>${message}</span>
                <button onclick="this.parentElement.parentElement.remove()" class="ml-4 hover:text-gray-200">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Trigger animation
        setTimeout(() => {
            notification.classList.remove('translate-x-full');
        }, 10);
        
        // Auto remove after 5 seconds
        setTimeout(() => {
            notification.classList.add('translate-x-full');
            setTimeout(() => notification.remove(), 300);
        }, 5000);
    };

    console.log('Footer initialized successfully');
})();
</script>

<style>
/* Additional styles for footer animations */
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

.animate-slide-in {
    animation: slideIn 0.3s ease-out;
}

/* Smooth transition for back to top button */
#back-to-top {
    transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease;
}

#back-to-top:hover {
    transform: translateY(-4px);
}

/* Footer link hover effects */
footer a {
    position: relative;
}

footer a::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 0;
    height: 2px;
    background-color: #fbbf24;
    transition: width 0.3s ease;
}

footer a:hover::after {
    width: 100%;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    footer .grid {
        gap: 2rem;
    }
    
    #back-to-top {
        bottom: 1rem;
        right: 1rem;
    }
}

/* Print styles */
@media print {
    footer {
        display: none;
    }
}
</style>

</body>
</html>