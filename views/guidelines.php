<?php
// views/guidelines.php
require_once __DIR__ . '/../bootstrap.php';

$pageTitle = "Voting Guidelines - Nkoranza SHTs E-Voting System";
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
            <h1 class="text-4xl font-bold text-gray-900 mb-4">Voting Guidelines</h1>
            <p class="text-xl text-gray-600">Please read these guidelines carefully before participating in any election</p>
        </div>

        <!-- Important Notice -->
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-6 mb-8 rounded-r-lg">
            <div class="flex items-start">
                <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl mr-4 mt-1"></i>
                <div>
                    <h3 class="text-lg font-bold text-yellow-800 mb-2">Important Notice</h3>
                    <p class="text-yellow-700">These guidelines are designed to ensure fair and transparent elections. All students are expected to adhere to these rules. Violations may result in disqualification or disciplinary action.</p>
                </div>
            </div>
        </div>

        <!-- Guidelines Grid -->
        <div class="grid gap-8">
            <!-- Before Voting -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-blue-900 to-blue-800 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-clock mr-3"></i> Before Voting
                    </h2>
                </div>
                <div class="p-6">
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Verify Your Credentials:</strong> Ensure you have your student ID and password ready before the election begins.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Check Election Schedule:</strong> Note the start and end times of the election. Voting is only possible during the specified period.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Review Candidate Information:</strong> Take time to read candidate manifestos and make informed decisions.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Ensure Stable Internet:</strong> Connect to a reliable internet connection to prevent interruptions during voting.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- During Voting -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-green-900 to-green-800 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-vote-yea mr-3"></i> During Voting
                    </h2>
                </div>
                <div class="p-6">
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">One Vote Per Position:</strong> You can only vote once for each position. Choose your preferred candidate carefully.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Review Before Submitting:</strong> Double-check your selections before clicking the submit button.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Confirmation Message:</strong> After voting, you'll receive a confirmation message. Keep this as proof of voting.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-times-circle text-red-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">No Duplicate Votes:</strong> The system prevents multiple votes from the same account.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- After Voting -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-purple-900 to-purple-800 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-check-double mr-3"></i> After Voting
                    </h2>
                </div>
                <div class="p-6">
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Logout Securely:</strong> Always log out after voting, especially on shared devices.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Wait for Results:</strong> Results are typically available 30 minutes after the election ends.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check-circle text-green-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Report Issues:</strong> If you experience any problems, contact the ICT department immediately.</span>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Prohibited Actions -->
            <div class="bg-white rounded-xl shadow-lg overflow-hidden">
                <div class="bg-gradient-to-r from-red-900 to-red-800 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fas fa-ban mr-3"></i> Prohibited Actions
                    </h2>
                </div>
                <div class="p-6">
                    <ul class="space-y-4">
                        <li class="flex items-start">
                            <i class="fas fa-times-circle text-red-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Vote Buying/Selling:</strong> Offering or accepting money/gifts in exchange for votes is strictly prohibited.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-times-circle text-red-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Impersonation:</strong> Attempting to vote using another student's credentials is a serious offense.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-times-circle text-red-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Multiple Votes:</strong> Any attempt to vote multiple times using different accounts is prohibited.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-times-circle text-red-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Intimidation:</strong> Coercing or intimidating other voters is strictly forbidden.</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-times-circle text-red-500 mt-1 mr-3"></i>
                            <span><strong class="text-gray-900">Sharing Credentials:</strong> Do not share your login credentials with anyone.</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Consequences -->
        <div class="mt-8 bg-red-50 rounded-xl p-6">
            <h3 class="text-lg font-bold text-red-800 mb-3 flex items-center">
                <i class="fas fa-gavel mr-2"></i> Consequences of Violations
            </h3>
            <p class="text-red-700 mb-3">Violation of these guidelines may result in:</p>
            <ul class="list-disc list-inside text-red-700 space-y-1">
                <li>Immediate disqualification from the election</li>
                <li>Referral to the school's disciplinary committee</li>
                <li>Suspension of voting privileges</li>
                <li>Academic penalties as determined by school administration</li>
            </ul>
        </div>

        <!-- Contact Information -->
        <div class="mt-8 text-center">
            <p class="text-gray-600 mb-4">If you have any questions about these guidelines, please contact:</p>
            <div class="flex flex-wrap justify-center gap-6">
                <div class="text-center">
                    <i class="fas fa-envelope text-pink-600 text-xl mb-2"></i>
                    <p class="text-sm text-gray-600">elections@nkoranzashts.edu.gh</p>
                </div>
                <div class="text-center">
                    <i class="fas fa-phone text-pink-600 text-xl mb-2"></i>
                    <p class="text-sm text-gray-600">+233 54 581 1179</p>
                </div>
                <div class="text-center">
                    <i class="fas fa-map-marker-alt text-pink-600 text-xl mb-2"></i>
                    <p class="text-sm text-gray-600">ICT Department, Main Administration Block</p>
                </div>
            </div>
        </div>

        <!-- Agreement -->
        <div class="mt-8 border-t pt-8 text-center">
            <p class="text-sm text-gray-500">
                By participating in any election on this platform, you acknowledge that you have read, 
                understood, and agree to abide by these guidelines.
            </p>
        </div>
    </div>

    <?php include APP_ROOT . '/includes/footer.php'; ?>
</body>
</html>