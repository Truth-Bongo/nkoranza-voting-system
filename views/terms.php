<?php
// views/terms.php
require_once __DIR__ . '/../bootstrap.php';

$pageTitle = "Terms of Service - Nkoranza SHTs E-Voting System";
$effectiveDate = "January 1, 2025";
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
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Terms of Service</h1>
            <p class="text-gray-600">Effective Date: <?= $effectiveDate ?></p>
        </div>

        <!-- Introduction -->
        <div class="bg-white rounded-xl shadow-lg p-8 mb-8">
            <p class="text-gray-600 leading-relaxed">
                Welcome to the Nkoranza Senior High Technical School E-Voting System. By accessing or using our platform, 
                you agree to be bound by these Terms of Service. Please read them carefully. If you do not agree to these 
                terms, you may not use our services.
            </p>
        </div>

        <!-- Terms Sections -->
        <div class="space-y-6">
            <!-- Account Terms -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-user-circle text-blue-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">1. Account Terms</h3>
                </div>
                <div class="pl-13 space-y-4">
                    <p class="text-gray-600">You are responsible for maintaining the security of your account and password. The School cannot and will not be liable for any loss or damage from your failure to comply with this security obligation.</p>
                    <p class="text-gray-600">You are responsible for all content posted and activity that occurs under your account.</p>
                    <p class="text-gray-600">One person may not maintain more than one account.</p>
                    <p class="text-gray-600">You must be a registered student of Nkoranza Senior High Technical School to use this service.</p>
                </div>
            </div>

            <!-- Voting Terms -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-green-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-vote-yea text-green-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">2. Voting Terms</h3>
                </div>
                <div class="pl-13 space-y-4">
                    <p class="text-gray-600">Each eligible student may cast one vote per position in an election.</p>
                    <p class="text-gray-600">Votes are final and cannot be changed once submitted.</p>
                    <p class="text-gray-600">Any attempt to manipulate or interfere with the voting process may result in account suspension and disciplinary action.</p>
                    <p class="text-gray-600">Election results are determined by the system based on vote counts and are considered final once certified by the election committee.</p>
                </div>
            </div>

            <!-- Acceptable Use -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-check-circle text-yellow-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">3. Acceptable Use</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600 mb-4">You agree not to:</p>
                    <ul class="list-disc list-inside space-y-2 text-gray-600">
                        <li>Use the service for any illegal purpose</li>
                        <li>Attempt to gain unauthorized access to other accounts or system areas</li>
                        <li>Interfere with or disrupt the integrity or performance of the system</li>
                        <li>Create accounts for automated voting or ballot stuffing</li>
                        <li>Share your login credentials with others</li>
                        <li>Use the system to harass, intimidate, or coerce other voters</li>
                        <li>Attempt to decipher, decompile, or reverse engineer any software used in the system</li>
                    </ul>
                </div>
            </div>

            <!-- Intellectual Property -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-purple-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-copyright text-purple-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">4. Intellectual Property</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        The Nkoranza SHTs E-Voting System, including its software, design, text, graphics, and all intellectual property rights, 
                        are owned by Nkoranza Senior High Technical School. You may not copy, modify, distribute, sell, or lease any part of 
                        our service without explicit permission.
                    </p>
                </div>
            </div>

            <!-- Privacy and Data -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-shield-alt text-red-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">5. Privacy and Data Protection</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        Your use of the system is also governed by our <a href="<?= BASE_URL ?>/privacy" class="text-pink-600 hover:underline">Privacy Policy</a>. 
                        By using the system, you consent to the collection and use of your information as described in that policy.
                    </p>
                </div>
            </div>

            <!-- Termination -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-ban text-orange-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">6. Termination</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        The School reserves the right to suspend or terminate your access to the system at any time, without notice, 
                        for conduct that we believe violates these Terms or is harmful to other users or the integrity of the electoral process.
                    </p>
                </div>
            </div>

            <!-- Disclaimer of Warranties -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-gray-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-exclamation-triangle text-gray-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">7. Disclaimer of Warranties</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        The system is provided "as is" without any warranties, express or implied. The School does not warrant that 
                        the system will be uninterrupted, timely, secure, or error-free.
                    </p>
                </div>
            </div>

            <!-- Limitation of Liability -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-indigo-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-balance-scale text-indigo-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">8. Limitation of Liability</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        To the maximum extent permitted by law, the School shall not be liable for any indirect, incidental, 
                        special, consequential, or punitive damages arising out of or relating to your use of the system.
                    </p>
                </div>
            </div>

            <!-- Changes to Terms -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-pink-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-sync-alt text-pink-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">9. Changes to Terms</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        We reserve the right to modify these terms at any time. We will notify users of any material changes via 
                        email or system announcements. Continued use of the system after such changes constitutes acceptance of 
                        the new terms.
                    </p>
                </div>
            </div>

            <!-- Governing Law -->
            <div class="bg-white rounded-xl shadow-lg p-8">
                <div class="flex items-center mb-4">
                    <div class="w-10 h-10 bg-teal-100 rounded-full flex items-center justify-center mr-3">
                        <i class="fas fa-gavel text-teal-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900">10. Governing Law</h3>
                </div>
                <div class="pl-13">
                    <p class="text-gray-600">
                        These Terms shall be governed by and construed in accordance with the laws of the Republic of Ghana, 
                        without regard to its conflict of law provisions.
                    </p>
                </div>
            </div>
        </div>

        <!-- Contact Information -->
        <div class="mt-8 bg-gray-50 rounded-xl p-8">
            <h3 class="text-xl font-bold text-gray-900 mb-4 flex items-center">
                <i class="fas fa-envelope text-pink-600 mr-2"></i>
                Contact Information
            </h3>
            <p class="text-gray-600 mb-4">
                For questions about these Terms of Service, please contact:
            </p>
            <div class="space-y-2">
                <p><strong class="text-gray-900">Email:</strong> <a href="mailto:legal@nkoranzashts.edu.gh" class="text-pink-600 hover:underline">legal@nkoranzashts.edu.gh</a></p>
                <p><strong class="text-gray-900">Address:</strong> Nkoranza Senior High Technical School, P.O. Box 28, Bono East Region, Ghana</p>
                <p><strong class="text-gray-900">Phone:</strong> +233 54 581 1179</p>
            </div>
        </div>

        <!-- Acceptance -->
        <div class="mt-8 border-t pt-8 text-center">
            <p class="text-sm text-gray-500">
                By using the Nkoranza SHTs E-Voting System, you acknowledge that you have read, understood, 
                and agree to be bound by these Terms of Service.
            </p>
        </div>
    </div>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>