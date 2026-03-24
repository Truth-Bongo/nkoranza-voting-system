<?php
// views/accessibility.php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$pageTitle = "Accessibility - Nkoranza SHTs E-Voting System";
$lastUpdated = "March 15, 2025";
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
    <style>
        /* High contrast mode */
        .high-contrast {
            filter: contrast(150%);
        }
        
        /* Reduced motion */
        .reduced-motion * {
            animation-duration: 0.01ms !important;
            animation-iteration-count: 1 !important;
            transition-duration: 0.01ms !important;
        }
        
        /* Focus indicators */
        *:focus-visible {
            outline: 3px solid #ec4899 !important;
            outline-offset: 2px !important;
        }
        
        /* Skip link */
        .skip-link {
            position: absolute;
            top: -40px;
            left: 0;
            background: #831843;
            color: white;
            padding: 8px;
            z-index: 100;
            text-decoration: none;
        }
        
        .skip-link:focus {
            top: 0;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Skip to main content link -->
    <a href="#main-content" class="skip-link">Skip to main content</a>
    
    <?php include APP_ROOT . '/includes/header.php'; ?>

    <main id="main-content" class="max-w-4xl mx-auto px-4 py-12">
        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Accessibility Statement</h1>
            <p class="text-xl text-gray-600">Ensuring equal access for all voters</p>
            <p class="text-sm text-gray-500 mt-2">Last Updated: <?= $lastUpdated ?></p>
        </div>

        <!-- Accessibility Tools -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Accessibility Tools</h2>
            
            <div class="grid md:grid-cols-2 gap-6">
                <!-- High Contrast Toggle -->
                <div class="border rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-adjust text-2xl text-purple-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">High Contrast Mode</h3>
                    </div>
                    <p class="text-gray-600 mb-4">Increase contrast for better readability</p>
                    <button id="high-contrast-toggle" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-toggle-off mr-2" id="contrast-icon"></i>
                        <span id="contrast-text">Enable High Contrast</span>
                    </button>
                </div>

                <!-- Reduced Motion Toggle -->
                <div class="border rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-running text-2xl text-blue-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">Reduced Motion</h3>
                    </div>
                    <p class="text-gray-600 mb-4">Minimize animations and transitions</p>
                    <button id="reduced-motion-toggle" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-toggle-off mr-2" id="motion-icon"></i>
                        <span id="motion-text">Enable Reduced Motion</span>
                    </button>
                </div>

                <!-- Text Size Controls -->
                <div class="border rounded-lg p-6 md:col-span-2">
                    <div class="flex items-center mb-4">
                        <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-text-height text-2xl text-green-600"></i>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900">Text Size</h3>
                    </div>
                    <p class="text-gray-600 mb-4">Adjust the text size for better readability</p>
                    <div class="flex items-center space-x-4">
                        <button id="decrease-text" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-minus"></i> Decrease
                        </button>
                        <span id="text-size-indicator" class="text-lg font-medium">100%</span>
                        <button id="increase-text" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus"></i> Increase
                        </button>
                        <button id="reset-text" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- WCAG Compliance -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Accessibility Features</h2>
            
            <div class="grid md:grid-cols-2 gap-8">
                <div>
                    <h3 class="text-lg font-bold text-pink-900 mb-4 flex items-center">
                        <i class="fas fa-check-circle text-green-500 mr-2"></i>
                        What We've Implemented
                    </h3>
                    <ul class="space-y-3">
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Screen Reader Compatible:</strong> All content is properly structured with semantic HTML and ARIA labels</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Keyboard Navigation:</strong> Full functionality available using only keyboard (Tab, Enter, Space)</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Color Contrast:</strong> Text meets WCAG 2.1 AA standards (4.5:1 minimum)</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Resizable Text:</strong> Text can be enlarged up to 200% without loss of functionality</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Focus Indicators:</strong> Clear visual focus indicators for keyboard navigation</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Alt Text:</strong> All images have descriptive alternative text</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-green-500 mt-1 mr-3"></i>
                            <span><strong>Form Labels:</strong> All form fields have proper labels and instructions</span>
                        </li>
                    </ul>
                </div>
                
                <div>
                    <h3 class="text-lg font-bold text-pink-900 mb-4 flex items-center">
                        <i class="fas fa-star text-yellow-500 mr-2"></i>
                        Keyboard Shortcuts
                    </h3>
                    <div class="space-y-3">
                        <div class="flex justify-between items-center border-b pb-2">
                            <span class="font-medium">Skip to content</span>
                            <kbd class="bg-gray-100 px-2 py-1 rounded">Alt + S</kbd>
                        </div>
                        <div class="flex justify-between items-center border-b pb-2">
                            <span class="font-medium">Go to search</span>
                            <kbd class="bg-gray-100 px-2 py-1 rounded">/</kbd>
                        </div>
                        <div class="flex justify-between items-center border-b pb-2">
                            <span class="font-medium">Navigate to elections</span>
                            <kbd class="bg-gray-100 px-2 py-1 rounded">Alt + E</kbd>
                        </div>
                        <div class="flex justify-between items-center border-b pb-2">
                            <span class="font-medium">Navigate to results</span>
                            <kbd class="bg-gray-100 px-2 py-1 rounded">Alt + R</kbd>
                        </div>
                        <div class="flex justify-between items-center border-b pb-2">
                            <span class="font-medium">Open main menu</span>
                            <kbd class="bg-gray-100 px-2 py-1 rounded">Alt + M</kbd>
                        </div>
                        <div class="flex justify-between items-center border-b pb-2">
                            <span class="font-medium">Toggle high contrast</span>
                            <kbd class="bg-gray-100 px-2 py-1 rounded">Alt + H</kbd>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Known Limitations -->
        <div class="bg-yellow-50 rounded-xl p-8 mb-8">
            <h2 class="text-xl font-bold text-yellow-800 mb-4 flex items-center">
                <i class="fas fa-exclamation-triangle text-yellow-600 mr-2"></i>
                Known Limitations & Workarounds
            </h2>
            <div class="space-y-4">
                <div class="flex items-start">
                    <span class="font-bold text-yellow-800 mr-3">•</span>
                    <div>
                        <strong class="text-yellow-900">PDF Documents:</strong>
                        <span class="text-yellow-700"> Some older PDF files may not be fully accessible. Contact us for alternative formats.</span>
                    </div>
                </div>
                <div class="flex items-start">
                    <span class="font-bold text-yellow-800 mr-3">•</span>
                    <div>
                        <strong class="text-yellow-900">Charts and Graphs:</strong>
                        <span class="text-yellow-700"> Visual data representations include text summaries and data tables for screen readers.</span>
                    </div>
                </div>
                <div class="flex items-start">
                    <span class="font-bold text-yellow-800 mr-3">•</span>
                    <div>
                        <strong class="text-yellow-900">Third-party Content:</strong>
                        <span class="text-yellow-700"> Some embedded content may have limited accessibility. We're working with providers to improve this.</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Accessibility Statement -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Our Commitment</h2>
            <p class="text-gray-600 leading-relaxed mb-4">
                Nkoranza Senior High Technical School is committed to ensuring digital accessibility for all students, 
                including those with disabilities. We are continually improving the user experience for everyone and 
                applying the relevant accessibility standards.
            </p>
            <p class="text-gray-600 leading-relaxed mb-4">
                We strive to conform to the Web Content Accessibility Guidelines (WCAG) 2.1 Level AA standards. 
                These guidelines explain how to make web content more accessible for people with disabilities, and 
                user-friendly for everyone.
            </p>
            <p class="text-gray-600 leading-relaxed">
                If you encounter any accessibility barriers or have suggestions for improvement, please contact our 
                accessibility coordinator. We welcome your feedback and are committed to providing an accessible 
                experience for all students.
            </p>
        </div>

        <!-- Compatibility -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-bold text-gray-900 mb-6">Browser & Device Compatibility</h2>
            <div class="grid md:grid-cols-3 gap-4">
                <div class="text-center p-4">
                    <i class="fab fa-chrome text-4xl text-blue-600 mb-2"></i>
                    <p class="font-medium">Chrome (latest)</p>
                    <p class="text-sm text-gray-500">Fully supported</p>
                </div>
                <div class="text-center p-4">
                    <i class="fab fa-firefox text-4xl text-orange-600 mb-2"></i>
                    <p class="font-medium">Firefox (latest)</p>
                    <p class="text-sm text-gray-500">Fully supported</p>
                </div>
                <div class="text-center p-4">
                    <i class="fab fa-safari text-4xl text-blue-800 mb-2"></i>
                    <p class="font-medium">Safari (latest)</p>
                    <p class="text-sm text-gray-500">Fully supported</p>
                </div>
                <div class="text-center p-4">
                    <i class="fab fa-edge text-4xl text-blue-600 mb-2"></i>
                    <p class="font-medium">Edge (latest)</p>
                    <p class="text-sm text-gray-500">Fully supported</p>
                </div>
                <div class="text-center p-4">
                    <i class="fas fa-mobile-alt text-4xl text-gray-600 mb-2"></i>
                    <p class="font-medium">Mobile Devices</p>
                    <p class="text-sm text-gray-500">Responsive design</p>
                </div>
                <div class="text-center p-4">
                    <i class="fas fa-universal-access text-4xl text-purple-600 mb-2"></i>
                    <p class="font-medium">Screen Readers</p>
                    <p class="text-sm text-gray-500">JAWS, NVDA, VoiceOver</p>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-800 rounded-xl p-8 text-white">
            <h2 class="text-2xl font-bold mb-4">Accessibility Assistance</h2>
            <p class="mb-6 opacity-90">
                Need help or have feedback about accessibility? Contact our Accessibility Coordinator:
            </p>
            <div class="grid md:grid-cols-3 gap-6">
                <div class="flex items-center">
                    <i class="fas fa-envelope text-2xl mr-3"></i>
                    <div>
                        <div class="text-sm opacity-75">Email</div>
                        <a href="mailto:accessibility@nkoranzashts.edu.gh" class="hover:underline">
                            accessibility@nkoranzashts.edu.gh
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
                <div class="flex items-center">
                    <i class="fas fa-clock text-2xl mr-3"></i>
                    <div>
                        <div class="text-sm opacity-75">Response Time</div>
                        <span>Within 48 hours</span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
    // Accessibility features JavaScript
    document.addEventListener('DOMContentLoaded', function() {
        // High contrast toggle
        const contrastToggle = document.getElementById('high-contrast-toggle');
        const contrastIcon = document.getElementById('contrast-icon');
        const contrastText = document.getElementById('contrast-text');
        
        if (contrastToggle) {
            // Check localStorage for saved preference
            if (localStorage.getItem('highContrast') === 'enabled') {
                document.body.classList.add('high-contrast');
                contrastIcon.className = 'fas fa-toggle-on mr-2';
                contrastText.textContent = 'Disable High Contrast';
            }
            
            contrastToggle.addEventListener('click', function() {
                document.body.classList.toggle('high-contrast');
                const isEnabled = document.body.classList.contains('high-contrast');
                
                if (isEnabled) {
                    contrastIcon.className = 'fas fa-toggle-on mr-2';
                    contrastText.textContent = 'Disable High Contrast';
                    localStorage.setItem('highContrast', 'enabled');
                } else {
                    contrastIcon.className = 'fas fa-toggle-off mr-2';
                    contrastText.textContent = 'Enable High Contrast';
                    localStorage.setItem('highContrast', 'disabled');
                }
            });
        }
        
        // Reduced motion toggle
        const motionToggle = document.getElementById('reduced-motion-toggle');
        const motionIcon = document.getElementById('motion-icon');
        const motionText = document.getElementById('motion-text');
        
        if (motionToggle) {
            if (localStorage.getItem('reducedMotion') === 'enabled') {
                document.body.classList.add('reduced-motion');
                motionIcon.className = 'fas fa-toggle-on mr-2';
                motionText.textContent = 'Disable Reduced Motion';
            }
            
            motionToggle.addEventListener('click', function() {
                document.body.classList.toggle('reduced-motion');
                const isEnabled = document.body.classList.contains('reduced-motion');
                
                if (isEnabled) {
                    motionIcon.className = 'fas fa-toggle-on mr-2';
                    motionText.textContent = 'Disable Reduced Motion';
                    localStorage.setItem('reducedMotion', 'enabled');
                } else {
                    motionIcon.className = 'fas fa-toggle-off mr-2';
                    motionText.textContent = 'Enable Reduced Motion';
                    localStorage.setItem('reducedMotion', 'disabled');
                }
            });
        }
        
        // Text size controls
        const textSizeIndicator = document.getElementById('text-size-indicator');
        const decreaseBtn = document.getElementById('decrease-text');
        const increaseBtn = document.getElementById('increase-text');
        const resetBtn = document.getElementById('reset-text');
        
        let currentTextSize = parseInt(localStorage.getItem('textSize')) || 100;
        
        function applyTextSize(size) {
            document.documentElement.style.fontSize = size + '%';
            textSizeIndicator.textContent = size + '%';
            localStorage.setItem('textSize', size);
        }
        
        if (decreaseBtn && increaseBtn && resetBtn) {
            applyTextSize(currentTextSize);
            
            decreaseBtn.addEventListener('click', function() {
                if (currentTextSize > 70) {
                    currentTextSize -= 10;
                    applyTextSize(currentTextSize);
                }
            });
            
            increaseBtn.addEventListener('click', function() {
                if (currentTextSize < 200) {
                    currentTextSize += 10;
                    applyTextSize(currentTextSize);
                }
            });
            
            resetBtn.addEventListener('click', function() {
                currentTextSize = 100;
                applyTextSize(currentTextSize);
            });
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Alt + S - Skip to content
            if (e.altKey && e.key === 's') {
                e.preventDefault();
                document.getElementById('main-content').focus();
            }
            
            // Alt + H - Toggle high contrast
            if (e.altKey && e.key === 'h') {
                e.preventDefault();
                contrastToggle.click();
            }
            
            // Alt + E - Go to elections
            if (e.altKey && e.key === 'e') {
                e.preventDefault();
                window.location.href = '<?= BASE_URL ?>/elections';
            }
            
            // Alt + R - Go to results
            if (e.altKey && e.key === 'r') {
                e.preventDefault();
                window.location.href = '<?= BASE_URL ?>/results';
            }
            
            // Alt + M - Open main menu (focus header navigation)
            if (e.altKey && e.key === 'm') {
                e.preventDefault();
                const firstNavLink = document.querySelector('nav a');
                if (firstNavLink) firstNavLink.focus();
            }
        });
    });
    </script>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>