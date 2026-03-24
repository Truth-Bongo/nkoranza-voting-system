<?php
// views/faq.php
require_once __DIR__ . '/../bootstrap.php';

$pageTitle = "FAQ - Nkoranza SHTs E-Voting System";

// FAQ Categories
$faqCategories = [
    'general' => [
        'title' => 'General Questions',
        'icon' => 'fa-question-circle',
        'questions' => [
            [
                'q' => 'What is the Nkoranza SHTs E-Voting System?',
                'a' => 'The Nkoranza SHTs E-Voting System is a secure digital platform designed specifically for conducting student elections at Nkoranza Senior High Technical School. It allows students to vote online for various positions using their unique credentials.'
            ],
            [
                'q' => 'Who can use this system?',
                'a' => 'The system is available to all registered students of Nkoranza SHTs. Each student receives unique login credentials to participate in elections. Administrative staff also have access for election management purposes.'
            ],
            [
                'q' => 'Is the voting system secure?',
                'a' => 'Yes, the system employs bank-level encryption, secure authentication, and multiple security layers to ensure that every vote is cast securely and counted accurately. All votes are encrypted and cannot be traced back to individual voters.'
            ]
        ]
    ],
    'voting' => [
        'title' => 'Voting Process',
        'icon' => 'fa-vote-yea',
        'questions' => [
            [
                'q' => 'How do I vote in an election?',
                'a' => 'To vote, log in with your student ID and password, navigate to the "Elections" page, select an active election, and choose your preferred candidates for each position. Confirm your choices and submit your vote.'
            ],
            [
                'q' => 'Can I change my vote after submitting?',
                'a' => 'No, once a vote is cast, it cannot be modified or retracted. This ensures the integrity of the election process. Please review your choices carefully before submitting.'
            ],
            [
                'q' => 'How do I know if I have already voted?',
                'a' => 'Your profile will indicate whether you have voted in active elections. Additionally, once you\'ve voted, the voting interface will show a confirmation message and prevent duplicate voting.'
            ]
        ]
    ],
    'technical' => [
        'title' => 'Technical Issues',
        'icon' => 'fa-laptop',
        'questions' => [
            [
                'q' => 'What devices can I use to vote?',
                'a' => 'The system is fully responsive and works on all devices including desktop computers, laptops, tablets, and smartphones. It is optimized for all major browsers (Chrome, Firefox, Safari, Edge).'
            ],
            [
                'q' => 'What should I do if I encounter technical issues?',
                'a' => 'If you experience technical difficulties, first try refreshing the page. If the problem persists, contact the ICT department or email elections@nkoranzashts.edu.gh for assistance.'
            ],
            [
                'q' => 'I forgot my password. What should I do?',
                'a' => 'Contact the school administration or ICT department to request a password reset. They will verify your identity and provide you with a new temporary password.'
            ]
        ]
    ],
    'results' => [
        'title' => 'Election Results',
        'icon' => 'fa-chart-bar',
        'questions' => [
            [
                'q' => 'When are election results available?',
                'a' => 'Results become available to all students 30 minutes after the election has ended. Administrators can view results immediately after the election concludes.'
            ],
            [
                'q' => 'How are winners determined?',
                'a' => 'For each position, the candidate with the highest number of votes wins. In case of a tie, the election committee will determine the winner based on established guidelines.'
            ],
            [
                'q' => 'Can I view results from past elections?',
                'a' => 'Yes, all past election results are archived and can be accessed through the "Results" page.'
            ]
        ]
    ]
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
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Frequently Asked Questions</h1>
            <p class="text-xl text-gray-600">Find answers to common questions about the e-voting system</p>
        </div>

        <!-- Search Bar -->
        <div class="mb-12">
            <div class="relative">
                <input type="text" id="faq-search" placeholder="Search questions..." 
                       class="w-full px-6 py-4 pl-14 text-lg border border-gray-300 rounded-xl focus:ring-2 focus:ring-pink-500 focus:border-transparent">
                <i class="fas fa-search absolute left-5 top-1/2 transform -translate-y-1/2 text-gray-400 text-xl"></i>
            </div>
        </div>

        <!-- FAQ Categories -->
        <div class="space-y-8">
            <?php foreach ($faqCategories as $categoryKey => $category): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                    <div class="bg-gradient-to-r from-pink-900 to-pink-800 px-6 py-4">
                        <div class="flex items-center space-x-3">
                            <i class="fas <?= $category['icon'] ?> text-white text-xl"></i>
                            <h2 class="text-xl font-bold text-white"><?= htmlspecialchars($category['title']) ?></h2>
                        </div>
                    </div>
                    
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($category['questions'] as $index => $faq): ?>
                            <div class="faq-item" data-question="<?= htmlspecialchars(strtolower($faq['q'])) ?>">
                                <button class="faq-question w-full px-6 py-4 text-left flex justify-between items-center hover:bg-gray-50 transition-colors">
                                    <span class="text-gray-900 font-medium pr-8"><?= htmlspecialchars($faq['q']) ?></span>
                                    <i class="fas fa-chevron-down text-gray-400 transition-transform duration-300"></i>
                                </button>
                                <div class="faq-answer hidden px-6 pb-4">
                                    <p class="text-gray-600"><?= htmlspecialchars($faq['a']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Still Have Questions -->
        <div class="mt-12 text-center">
            <div class="bg-blue-50 rounded-xl p-8">
                <i class="fas fa-question-circle text-5xl text-blue-500 mb-4"></i>
                <h3 class="text-2xl font-bold text-gray-900 mb-2">Still Have Questions?</h3>
                <p class="text-gray-600 mb-6">Can't find the answer you're looking for? Contact our support team.</p>
                <div class="flex flex-wrap justify-center gap-4">
                    <a href="mailto:elections@nkoranzashts.edu.gh" 
                       class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                        <i class="fas fa-envelope mr-2"></i> Email Support
                    </a>
                    <a href="<?= BASE_URL ?>/contact" 
                       class="bg-gray-600 hover:bg-gray-700 text-white px-6 py-3 rounded-lg font-medium transition-colors">
                        <i class="fas fa-phone mr-2"></i> Contact Us
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    // FAQ Accordion
    document.querySelectorAll('.faq-question').forEach(button => {
        button.addEventListener('click', () => {
            const answer = button.nextElementSibling;
            const icon = button.querySelector('.fa-chevron-down');
            
            // Toggle answer
            answer.classList.toggle('hidden');
            
            // Rotate icon
            icon.classList.toggle('rotate-180');
        });
    });

    // Search functionality
    const searchInput = document.getElementById('faq-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            document.querySelectorAll('.faq-item').forEach(item => {
                const question = item.dataset.question;
                const matches = question.includes(searchTerm);
                
                item.style.display = matches ? '' : 'none';
            });
        });
    }
    </script>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>