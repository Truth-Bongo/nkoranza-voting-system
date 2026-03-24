<?php
// views/privacy.php
require_once __DIR__ . '/../bootstrap.php';

$pageTitle = "Privacy Policy - Nkoranza SHTs E-Voting System";
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
</head>
<body class="bg-gray-100">
    <?php include APP_ROOT . '/includes/header.php'; ?>

    <div class="max-w-4xl mx-auto px-4 py-12">
        <!-- Header -->
        <div class="text-center mb-12">
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Privacy Policy</h1>
            <p class="text-gray-600">Last Updated: <?= $lastUpdated ?></p>
        </div>

        <!-- Introduction -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <div class="flex items-center mb-6">
                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                    <i class="fas fa-shield-alt text-2xl text-blue-600"></i>
                </div>
                <h2 class="text-2xl font-bold text-gray-900">Our Commitment to Privacy</h2>
            </div>
            <p class="text-gray-600 leading-relaxed">
                At Nkoranza Senior High Technical School, we are committed to protecting your privacy and ensuring the security of your personal information. This Privacy Policy explains how we collect, use, and safeguard your data when you use our e-voting system.
            </p>
        </div>

        <!-- Policy Sections -->
        <div class="space-y-6">
            <!-- Information We Collect -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-database text-purple-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">1. Information We Collect</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600 mb-4">We collect the following types of information:</p>
                    <ul class="list-disc list-inside space-y-2 text-gray-600">
                        <li><strong class="text-gray-900">Personal Information:</strong> Student ID, name, email address, department, and level/class</li>
                        <li><strong class="text-gray-900">Authentication Data:</strong> Securely hashed passwords and login activity</li>
                        <li><strong class="text-gray-900">Voting Records:</strong> Cast votes (anonymized and aggregated for results)</li>
                        <li><strong class="text-gray-900">Usage Data:</strong> Login timestamps, IP addresses, and browser information</li>
                    </ul>
                </div>
            </div>

            <!-- How We Use Your Information -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-cog text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">2. How We Use Your Information</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600 mb-4">Your information is used for:</p>
                    <ul class="list-disc list-inside space-y-2 text-gray-600">
                        <li>Authenticating your identity during login and voting</li>
                        <li>Ensuring one person, one vote (preventing duplicate voting)</li>
                        <li>Generating anonymous election results and statistics</li>
                        <li>Auditing and maintaining the integrity of the electoral process</li>
                        <li>Communicating important election-related information</li>
                    </ul>
                </div>
            </div>

            <!-- Data Security -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-lock text-red-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">3. Data Security Measures</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600 mb-4">We implement robust security measures including:</p>
                    <ul class="list-disc list-inside space-y-2 text-gray-600">
                        <li>End-to-end encryption of all sensitive data</li>
                        <li>Secure password hashing using industry-standard algorithms</li>
                        <li>Regular security audits and vulnerability assessments</li>
                        <li>Strict access controls for administrative functions</li>
                        <li>Secure, encrypted database storage</li>
                    </ul>
                </div>
            </div>

            <!-- Data Retention -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-clock text-yellow-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">4. Data Retention</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        We retain your personal information for as long as you are an active student at Nkoranza SHTs. 
                        Voting records are maintained for audit purposes and historical reference. After graduation, 
                        accounts are archived but voting records remain anonymized in election results.
                    </p>
                </div>
            </div>

            <!-- Your Rights -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-gavel text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">5. Your Rights</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600 mb-4">You have the right to:</p>
                    <ul class="list-disc list-inside space-y-2 text-gray-600">
                        <li>Access your personal information held by the system</li>
                        <li>Request corrections to inaccurate data</li>
                        <li>Request account deletion after graduation</li>
                        <li>Be informed about how your data is used</li>
                        <li>Report any privacy concerns or violations</li>
                    </ul>
                </div>
            </div>

            <!-- Third-Party Disclosure -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-share-alt text-orange-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">6. Third-Party Disclosure</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        We do not sell, trade, or transfer your personal information to external parties. 
                        Aggregated, anonymized election results may be shared with school administration 
                        and displayed publicly on the results page.
                    </p>
                </div>
            </div>

            <!-- Children's Privacy -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-pink-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-child text-pink-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">7. Children's Privacy</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        Our system is designed for students of Nkoranza SHTs. We do not knowingly collect 
                        information from anyone under the age of 13 without parental consent. If you believe 
                        we have inadvertently collected such information, please contact us immediately.
                    </p>
                </div>
            </div>

            <!-- Changes to Policy -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-sync-alt text-indigo-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">8. Changes to This Policy</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        We may update this privacy policy from time to time. Any changes will be posted on this page, 
                        and where appropriate, notified to users via email or system announcements.
                    </p>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="mt-8 bg-gray-50 rounded-xl p-8">
            <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                <i class="fas fa-envelope text-pink-600 mr-2"></i>
                Contact Us
            </h3>
            <p class="text-gray-600 mb-4">
                If you have questions or concerns about this privacy policy or your data, please contact:
            </p>
            <div class="space-y-2">
                <p><strong class="text-gray-900">Email:</strong> <a href="mailto:privacy@nkoranzashts.edu.gh" class="text-pink-600 hover:underline">privacy@nkoranzashts.edu.gh</a></p>
                <p><strong class="text-gray-900">Address:</strong> Nkoranza Senior High Technical School, P.O. Box 28, Bono East Region, Ghana</p>
                <p><strong class="text-gray-900">Phone:</strong> +233 54 581 1179</p>
            </div>
        </div>
    </div>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>