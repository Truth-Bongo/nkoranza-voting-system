<?php
// views/about.php
require_once __DIR__ . '/../bootstrap.php';

$pageTitle = "About Us - Nkoranza SHTs E-Voting System";

// Set timezone
date_default_timezone_set('Africa/Accra');

// Get current year for dynamic calculations
$currentYear = date('Y');
$establishedYear = 2025;
$yearsActive = $currentYear - $establishedYear;

// Team members data
$teamMembers = [
    [
        'name' => 'Dr. Kwame Asante',
        'position' => 'Election Committee Chair',
        'department' => 'ICT Department',
        'image' => 'default-avatar.jpg',
        'bio' => 'Leading the digital transformation of student elections with over 15 years of experience in educational technology.',
        'social' => ['twitter' => '#', 'linkedin' => '#', 'email' => 'k.asante@nkoranzashts.edu.gh']
    ],
    [
        'name' => 'Ms. Akua Mensah',
        'position' => 'Vice Chairperson',
        'department' => 'Administration',
        'image' => 'default-avatar.jpg',
        'bio' => 'Dedicated to ensuring fair and transparent electoral processes for all students.',
        'social' => ['twitter' => '#', 'linkedin' => '#', 'email' => 'a.mensah@nkoranzashts.edu.gh']
    ],
    [
        'name' => 'Mr. Yaw Adjei',
        'position' => 'Technical Lead',
        'department' => 'Software Development',
        'image' => 'default-avatar.jpg',
        'bio' => 'Architect of the e-voting platform, passionate about creating secure and accessible voting solutions.',
        'social' => ['twitter' => '#', 'linkedin' => '#', 'email' => 'y.adjei@nkoranzashts.edu.gh']
    ],
    [
        'name' => 'Ms. Esi Boateng',
        'position' => 'Student Representative',
        'department' => 'Student Council',
        'image' => 'default-avatar.jpg',
        'bio' => 'Voice of the student body, ensuring the platform meets the needs of all voters.',
        'social' => ['twitter' => '#', 'linkedin' => '#', 'email' => 'e.boateng@student.nkoranzashts.edu.gh']
    ]
];

// Milestones data
$milestones = [
    ['year' => 2025, 'title' => 'Platform Launch', 'description' => 'First digital election system launched at Nkoranza SHTs'],
    ['year' => 2025, 'title' => 'First Digital Election', 'description' => 'Successfully conducted maiden digital student council elections'],
    ['year' => 2025, 'title' => '500+ Voters', 'description' => 'Reached milestone of 500 registered voters on the platform'],
    ['year' => 2025, 'title' => 'Mobile App Launch', 'description' => 'Launched mobile-responsive voting interface']
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

    <div class="max-w-7xl mx-auto px-4 py-8">
        <!-- Hero Section -->
        <div class="text-center mb-16">
            <h1 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">About Our E-Voting System</h1>
            <p class="text-xl text-gray-600 max-w-3xl mx-auto">
                Empowering Nkoranza Senior High Technical School students with secure, 
                transparent, and accessible digital elections since <?= $establishedYear ?>.
            </p>
        </div>

        <!-- Mission & Vision -->
        <div class="grid md:grid-cols-2 gap-8 mb-16">
            <div class="bg-white rounded-xl shadow-lg p-8 transform hover:-translate-y-2 transition-all duration-300">
                <div class="w-16 h-16 bg-pink-100 rounded-full flex items-center justify-center mb-6">
                    <i class="fas fa-bullseye text-3xl text-pink-600"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Our Mission</h2>
                <p class="text-gray-600 leading-relaxed">
                    To provide a secure, transparent, and accessible digital voting platform that 
                    empowers every student to participate in the democratic process. We strive to 
                    eliminate electoral malpractices and ensure every vote counts through 
                    cutting-edge technology and rigorous security measures.
                </p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-8 transform hover:-translate-y-2 transition-all duration-300">
                <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mb-6">
                    <i class="fas fa-eye text-3xl text-blue-600"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900 mb-4">Our Vision</h2>
                <p class="text-gray-600 leading-relaxed">
                    To become Ghana's leading model for student digital democracy, inspiring 
                    other educational institutions to adopt transparent and technology-driven 
                    electoral processes. We envision a future where every student's voice is 
                    heard and valued through seamless digital participation.
                </p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-800 rounded-2xl shadow-xl p-8 mb-16">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6 text-white">
                <div class="text-center">
                    <div class="text-4xl font-bold mb-2"><?= $yearsActive ?></div>
                    <div class="text-pink-200">Years Active</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold mb-2">500+</div>
                    <div class="text-pink-200">Registered Voters</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold mb-2">5</div>
                    <div class="text-pink-200">Elections Held</div>
                </div>
                <div class="text-center">
                    <div class="text-4xl font-bold mb-2">100%</div>
                    <div class="text-pink-200">Secure Voting</div>
                </div>
            </div>
        </div>

        <!-- Core Values -->
        <div class="mb-16">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-12">Our Core Values</h2>
            <div class="grid md:grid-cols-3 gap-8">
                <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                    <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-shield-alt text-2xl text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Security</h3>
                    <p class="text-gray-600">Bank-level encryption and security protocols to protect every vote</p>
                </div>
                <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                    <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-balance-scale text-2xl text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Transparency</h3>
                    <p class="text-gray-600">Open and verifiable election results with real-time monitoring</p>
                </div>
                <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                    <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-universal-access text-2xl text-purple-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-2">Accessibility</h3>
                    <p class="text-gray-600">Mobile-responsive design accessible to all students anywhere</p>
                </div>
            </div>
        </div>

        <!-- Team Section -->
        <div class="mb-16">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-12">Meet Our Team</h2>
            <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($teamMembers as $member): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden group">
                    <div class="h-48 bg-gradient-to-br from-pink-900 to-pink-700 flex items-center justify-center">
                        <i class="fas fa-user-circle text-6xl text-white"></i>
                    </div>
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900"><?= htmlspecialchars($member['name']) ?></h3>
                        <p class="text-pink-600 font-medium text-sm mb-2"><?= htmlspecialchars($member['position']) ?></p>
                        <p class="text-gray-500 text-sm mb-3"><?= htmlspecialchars($member['department']) ?></p>
                        <p class="text-gray-600 text-sm mb-4"><?= htmlspecialchars($member['bio']) ?></p>
                        <div class="flex space-x-3">
                            <a href="<?= $member['social']['twitter'] ?>" class="text-gray-400 hover:text-blue-400"><i class="fab fa-twitter"></i></a>
                            <a href="<?= $member['social']['linkedin'] ?>" class="text-gray-400 hover:text-blue-600"><i class="fab fa-linkedin"></i></a>
                            <a href="mailto:<?= $member['social']['email'] ?>" class="text-gray-400 hover:text-pink-600"><i class="fas fa-envelope"></i></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Milestones Timeline -->
        <div class="mb-16">
            <h2 class="text-3xl font-bold text-center text-gray-900 mb-12">Our Journey</h2>
            <div class="relative">
                <div class="absolute left-1/2 transform -translate-x-1/2 h-full w-1 bg-pink-200"></div>
                <?php foreach ($milestones as $index => $milestone): ?>
                <div class="relative mb-8 <?= $index % 2 === 0 ? 'md:ml-auto md:pl-8 md:text-left' : 'md:mr-auto md:pr-8 md:text-right' ?>" style="width: 50%">
                    <div class="bg-white rounded-lg shadow-lg p-6 relative">
                        <div class="absolute top-1/2 transform -translate-y-1/2 w-4 h-4 bg-pink-600 rounded-full <?= $index % 2 === 0 ? '-left-2' : '-right-2' ?>"></div>
                        <span class="text-pink-600 font-bold"><?= $milestone['year'] ?></span>
                        <h3 class="text-lg font-bold text-gray-900 mt-1"><?= htmlspecialchars($milestone['title']) ?></h3>
                        <p class="text-gray-600 text-sm mt-2"><?= htmlspecialchars($milestone['description']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Call to Action -->
        <div class="bg-gradient-to-r from-pink-900 to-pink-800 rounded-2xl shadow-xl p-12 text-center">
            <h2 class="text-3xl font-bold text-white mb-4">Ready to Make Your Voice Heard?</h2>
            <p class="text-pink-200 mb-8 max-w-2xl mx-auto">
                Join thousands of students who have already participated in shaping 
                the future of Nkoranza SHTs through democratic elections.
            </p>
            <div class="flex flex-wrap justify-center gap-4">
                <a href="<?= BASE_URL ?>/elections" class="bg-white text-pink-900 px-8 py-3 rounded-lg font-bold hover:bg-gray-100 transition-colors">
                    <i class="fas fa-vote-yea mr-2"></i> View Active Elections
                </a>
                <a href="<?= BASE_URL ?>/contact" class="bg-transparent border-2 border-white text-white px-8 py-3 rounded-lg font-bold hover:bg-white hover:text-pink-900 transition-colors">
                    <i class="fas fa-envelope mr-2"></i> Contact Us
                </a>
            </div>
        </div>
    </div>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>