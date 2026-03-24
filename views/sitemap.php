<?php
// views/sitemap.php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$pageTitle = "Sitemap - Nkoranza SHTs E-Voting System";

// Define sitemap structure
$sitemap = [
    'public' => [
        'title' => 'Public Pages',
        'icon' => 'fa-globe',
        'links' => [
            ['url' => BASE_URL, 'label' => 'Home', 'description' => 'Welcome page and system overview'],
            ['url' => BASE_URL . '/elections', 'label' => 'Elections', 'description' => 'View all active, upcoming, and past elections'],
            ['url' => BASE_URL . '/results', 'label' => 'Election Results', 'description' => 'View results from completed elections'],
            ['url' => BASE_URL . '/candidates', 'label' => 'Candidates', 'description' => 'Browse all candidates and their manifestos'],
            ['url' => BASE_URL . '/about', 'label' => 'About Us', 'description' => 'Learn about our e-voting system and mission'],
            ['url' => BASE_URL . '/faq', 'label' => 'FAQ', 'description' => 'Frequently asked questions about voting'],
            ['url' => BASE_URL . '/guidelines', 'label' => 'Voting Guidelines', 'description' => 'Rules and guidelines for voting'],
            ['url' => BASE_URL . '/contact', 'label' => 'Contact Us', 'description' => 'Get in touch with the election committee']
        ]
    ],
    'voter' => [
        'title' => 'Voter Services',
        'icon' => 'fa-vote-yea',
        'links' => [
            ['url' => BASE_URL . '/vote', 'label' => 'Cast Your Vote', 'description' => 'Vote in active elections'],
            ['url' => BASE_URL . '/profile', 'label' => 'My Profile', 'description' => 'View and manage your account'],
            ['url' => BASE_URL . '/voting-history', 'label' => 'My Voting History', 'description' => 'View elections you\'ve participated in'],
            ['url' => BASE_URL . '/change-password', 'label' => 'Change Password', 'description' => 'Update your login credentials']
        ]
    ],
    'information' => [
        'title' => 'Information',
        'icon' => 'fa-info-circle',
        'links' => [
            ['url' => BASE_URL . '/privacy', 'label' => 'Privacy Policy', 'description' => 'How we protect your data'],
            ['url' => BASE_URL . '/terms', 'label' => 'Terms of Service', 'description' => 'Terms and conditions for using the system'],
            ['url' => BASE_URL . '/accessibility', 'label' => 'Accessibility', 'description' => 'Accessibility features and statements'],
            ['url' => BASE_URL . '/cookies', 'label' => 'Cookie Policy', 'description' => 'How we use cookies on our site'],
            ['url' => BASE_URL . '/sitemap', 'label' => 'Sitemap', 'description' => 'Complete site structure and navigation']
        ]
    ]
];

// Add admin section if user is admin
if (isset($_SESSION['is_admin']) && $_SESSION['is_admin']) {
    $sitemap['admin'] = [
        'title' => 'Administration',
        'icon' => 'fa-user-shield',
        'links' => [
            ['url' => BASE_URL . '/admin/dashboard', 'label' => 'Admin Dashboard', 'description' => 'Overview of system statistics'],
            ['url' => BASE_URL . '/admin/elections', 'label' => 'Manage Elections', 'description' => 'Create and manage elections'],
            ['url' => BASE_URL . '/admin/candidates', 'label' => 'Manage Candidates', 'description' => 'Add and manage candidates'],
            ['url' => BASE_URL . '/admin/voters', 'label' => 'Manage Voters', 'description' => 'Manage voter registrations'],
            ['url' => BASE_URL . '/admin/users', 'label' => 'Manage Users', 'description' => 'Manage system users and admins'],
            ['url' => BASE_URL . '/admin/activity_logs', 'label' => 'Activity Logs', 'description' => 'View system activity logs']
        ]
    ];
}
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

    <div class="max-w-7xl mx-auto px-4 py-12">
        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Sitemap</h1>
            <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                Navigate through all pages and features available on the Nkoranza SHTs E-Voting System
            </p>
        </div>

        <!-- Quick Stats -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-12">
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-3xl font-bold text-pink-600 mb-2"><?= array_sum(array_map('count', $sitemap)) ?></div>
                <div class="text-sm text-gray-600">Total Pages</div>
            </div>
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-3xl font-bold text-green-600 mb-2"><?= count($sitemap['public']['links'] ?? []) ?></div>
                <div class="text-sm text-gray-600">Public Pages</div>
            </div>
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-3xl font-bold text-blue-600 mb-2"><?= count($sitemap['voter']['links'] ?? []) ?></div>
                <div class="text-sm text-gray-600">Voter Services</div>
            </div>
            <?php if (isset($sitemap['admin'])): ?>
            <div class="bg-white rounded-lg shadow p-6 text-center">
                <div class="text-3xl font-bold text-purple-600 mb-2"><?= count($sitemap['admin']['links'] ?? []) ?></div>
                <div class="text-sm text-gray-600">Admin Pages</div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sitemap Grid -->
        <div class="grid md:grid-cols-2 gap-8">
            <?php foreach ($sitemap as $key => $section): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-pink-900 to-pink-800 px-6 py-4">
                        <div class="flex items-center space-x-3">
                            <i class="fas <?= $section['icon'] ?> text-white text-xl"></i>
                            <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($section['title']) ?></h2>
                            <span class="ml-auto bg-white bg-opacity-20 text-white px-3 py-1 rounded-full text-sm">
                                <?= count($section['links']) ?> pages
                            </span>
                        </div>
                    </div>
                    
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($section['links'] as $link): ?>
                            <a href="<?= htmlspecialchars($link['url']) ?>" 
                               class="block px-6 py-4 hover:bg-gray-50 transition-colors group">
                                <div class="flex items-start">
                                    <i class="fas fa-chevron-right text-gray-400 mt-1 mr-3 text-sm group-hover:text-pink-600 transition-colors"></i>
                                    <div class="flex-1">
                                        <h3 class="text-gray-900 font-medium group-hover:text-pink-600 transition-colors">
                                            <?= htmlspecialchars($link['label']) ?>
                                        </h3>
                                        <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($link['description']) ?></p>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- XML Sitemap Notice -->
        <div class="mt-12 bg-blue-50 rounded-xl p-6">
            <div class="flex items-start">
                <i class="fas fa-code text-blue-600 text-2xl mr-4 mt-1"></i>
                <div>
                    <h3 class="text-lg font-bold text-gray-900 mb-2">XML Sitemap for Search Engines</h3>
                    <p class="text-gray-600 mb-3">
                        An XML version of this sitemap is available for search engines to better index our site.
                    </p>
                    <a href="<?= BASE_URL ?>/sitemap.xml" class="inline-flex items-center text-blue-600 hover:text-blue-800 font-medium">
                        <i class="fas fa-file-code mr-2"></i>
                        View XML Sitemap
                        <i class="fas fa-external-link-alt ml-2 text-sm"></i>
                    </a>
                </div>
            </div>
        </div>

        <!-- Search Box -->
        <div class="mt-8">
            <div class="relative">
                <input type="text" id="sitemap-search" placeholder="Search pages..." 
                       class="w-full px-6 py-4 pl-14 text-lg border border-gray-300 rounded-xl focus:ring-2 focus:ring-pink-500 focus:border-transparent">
                <i class="fas fa-search absolute left-5 top-1/2 transform -translate-y-1/2 text-gray-400 text-xl"></i>
            </div>
            <div id="search-results" class="mt-4 hidden"></div>
        </div>
    </div>

    <script>
    // Sitemap search functionality
    const searchInput = document.getElementById('sitemap-search');
    const searchResults = document.getElementById('search-results');
    
    // Collect all links for searching
    const allLinks = [
        <?php foreach ($sitemap as $section): ?>
            <?php foreach ($section['links'] as $link): ?>
                { url: "<?= htmlspecialchars($link['url']) ?>", label: "<?= htmlspecialchars($link['label']) ?>", description: "<?= htmlspecialchars($link['description']) ?>" },
            <?php endforeach; ?>
        <?php endforeach; ?>
    ];
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            if (searchTerm.length < 2) {
                searchResults.classList.add('hidden');
                return;
            }
            
            const matches = allLinks.filter(link => 
                link.label.toLowerCase().includes(searchTerm) || 
                link.description.toLowerCase().includes(searchTerm)
            );
            
            if (matches.length > 0) {
                let html = '<div class="bg-white rounded-lg shadow-lg p-4">';
                html += '<h3 class="font-bold text-gray-900 mb-3">Search Results:</h3>';
                html += '<div class="space-y-2">';
                
                matches.slice(0, 10).forEach(link => {
                    html += `<a href="${link.url}" class="block p-3 hover:bg-gray-50 rounded-lg transition-colors">
                        <div class="font-medium text-pink-600">${link.label}</div>
                        <div class="text-sm text-gray-600">${link.description}</div>
                    </a>`;
                });
                
                if (matches.length > 10) {
                    html += `<p class="text-sm text-gray-500 mt-2">...and ${matches.length - 10} more results</p>`;
                }
                
                html += '</div></div>';
                searchResults.innerHTML = html;
                searchResults.classList.remove('hidden');
            } else {
                searchResults.innerHTML = '<div class="bg-white rounded-lg shadow-lg p-4 text-gray-600">No matching pages found.</div>';
                searchResults.classList.remove('hidden');
            }
        });
    }
    </script>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>