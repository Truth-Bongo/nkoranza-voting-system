<?php
// api/admin/export_voters_pdf.php
// Export voters data to PDF - regenerates plain passwords on the fly (no storage)

// Enable error logging but don't display errors
// error_reporting only in debug mode
if (defined('DEBUG_MODE') && DEBUG_MODE) { error_reporting(E_ALL); } else { error_reporting(0); }
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../../logs/php_errors.log');

// Start output buffering
ob_start();

// Set appropriate headers for PDF download
header('Content-Type: application/pdf');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php'; // Contains generateSimplePassword() and logActivity()

// Include TCPDF from lib folder
$tcpdfPath = APP_ROOT . '/lib/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    error_log("TCPDF library not found at: " . $tcpdfPath);
    die("PDF library not found. Please contact administrator.");
}
require_once $tcpdfPath;

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear output buffer before sending PDF
ob_end_clean();

// ============================================
// CUSTOM PDF CLASS
// ============================================
class VoterCredentialsPDF extends TCPDF {
    public $schoolName = "Nkoranza Senior High/Technical School";
    public $titleText = "Voter Login Credentials";
    public $exportedBy = "System";
    public $exportDate;
    public $electionInfo = null;
    public $filterInfo = [];

    public function Header() {
        $logoPath = __DIR__ . '/../../assets/images/logo.png';
        $pageWidth = $this->getPageWidth();

        $this->SetY(10);
        $logoBottomY = 0;

        if (file_exists($logoPath)) {
            // Insert logo (x=15mm, y=10mm, width=20mm)
            $this->Image($logoPath, 15, 10, 20);
            $logoBottomY = 10 + 20;
        }

        // Text beside logo
        $this->SetXY(40, 12);
        $this->SetFont('helvetica', 'B', 12);
        $this->Cell(0, 6, $this->schoolName, 0, 1, 'L');

        $this->SetX(40);
        $this->SetFont('helvetica', 'B', 10);
        $this->Cell(0, 6, $this->titleText, 0, 1, 'L');

        $this->SetX(40);
        $this->SetFont('helvetica', '', 9);
        
        $headerInfo = 'Generated on: ' . date('F j, Y g:i:s A');
        if (!empty($this->electionInfo)) {
            $headerInfo .= ' | Election: ' . $this->electionInfo;
        }
        $this->Cell(0, 5, $headerInfo, 0, 1, 'L');

        $this->SetX(40);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 4, 'Exported by: ' . $this->exportedBy, 0, 1, 'L');

        // Border line under header
        if ($logoBottomY > 0) {
            $this->SetDrawColor(100, 100, 100);
            $this->SetLineWidth(0.3);
            $this->Line($this->lMargin, $logoBottomY + 5, $pageWidth - $this->rMargin, $logoBottomY + 5);
            $this->SetY($logoBottomY + 8);
        } else {
            $this->Ln(8);
        }
    }

    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, 'Page ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, 0, 'C');
        
        // Add confidentiality footer
        $this->SetY(-25);
        $this->SetFont('helvetica', 'I', 6);
        $this->SetTextColor(150, 150, 150);
        $this->Cell(0, 10, 'CONFIDENTIAL - For authorized use only', 0, 0, 'C');
    }
}

// ============================================
// MAIN EXECUTION
// ============================================
try {
    // Include ActivityLogger if available
    $activityLogger = null;
    if (file_exists(APP_ROOT . '/includes/ActivityLogger.php')) {
        require_once APP_ROOT . '/includes/ActivityLogger.php';
        try {
            $db = Database::getInstance()->getConnection();
            $activityLogger = new ActivityLogger($db);
        } catch (Exception $e) {
            error_log("Failed to initialize ActivityLogger: " . $e->getMessage());
        }
    }

    // Check admin authentication
    if (empty($_SESSION['is_admin'])) {
        // Log unauthorized access attempt
        if (function_exists('logActivity')) {
            logActivity(
                $_SESSION['user_id'] ?? 'unknown',
                ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                'unauthorized_export_attempt',
                'Unauthorized attempt to export voters PDF',
                json_encode([
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ])
            );
        }
        
        header('HTTP/1.0 401 Unauthorized');
        echo 'Unauthorized access. Admin privileges required.';
        exit;
    }

    // CSRF validation (optional but recommended for exports)
    $csrfToken = $_GET['csrf_token'] ?? $_POST['csrf_token'] ?? '';
    if (!empty($_SESSION['csrf_token']) && !empty($csrfToken) && !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        error_log("CSRF token mismatch in export_voters_pdf.php");
        // Log but don't block - exports are GET requests and already admin-protected
    }

    // Get filter parameters
    $filters = [
        'department' => $_GET['department'] ?? '',
        'level' => $_GET['level'] ?? '',
        'entry_year' => isset($_GET['entry_year']) ? (int)$_GET['entry_year'] : 0,
        'status' => $_GET['status'] ?? 'all',
        'election_id' => isset($_GET['election_id']) ? (int)$_GET['election_id'] : 0
    ];

    // Log export start
    if (function_exists('logActivity')) {
        logActivity(
            $_SESSION['user_id'],
            ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
            'voters_export_started',
            'Started voters PDF export',
            json_encode(['filters' => $filters])
        );
    }

    // Get database connection
    try {
        $db = Database::getInstance()->getConnection();
        $db->exec("USE nkoranza_voting");
    } catch (Exception $e) {
        throw new Exception('Database connection failed: ' . $e->getMessage());
    }

    // Fetch election details if provided
    $election = null;
    if ($filters['election_id'] > 0) {
        $electionStmt = $db->prepare('SELECT id, title, description, start_date, end_date FROM elections WHERE id = ?');
        $electionStmt->execute([$filters['election_id']]);
        $election = $electionStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Build query with filters - FIXED for unique results
$sql = "SELECT DISTINCT id, first_name, last_name, department, level, email, 
               entry_year, graduation_year, has_logged_in
        FROM users 
        WHERE is_admin = 0";
    
$params = [];

if (!empty($filters['department'])) {
    $sql .= " AND department = ?";
    $params[] = $filters['department'];
}

if (!empty($filters['level'])) {
    $sql .= " AND level = ?";
    $params[] = $filters['level'];
}

if ($filters['entry_year'] > 0) {
    $sql .= " AND entry_year = ?";
    $params[] = $filters['entry_year'];
}

$currentYear = date('Y');
if ($filters['status'] === 'active') {
    $sql .= " AND (graduation_year IS NULL OR graduation_year > ?)";
    $params[] = $currentYear;
} elseif ($filters['status'] === 'graduated') {
    $sql .= " AND graduation_year IS NOT NULL AND graduation_year <= ?";
    $params[] = $currentYear;
}

// Order by level
$sql .= " ORDER BY 
    CASE 
        WHEN level LIKE '1%' THEN 1
        WHEN level LIKE '2%' THEN 2
        WHEN level LIKE '3%' THEN 3
        WHEN level LIKE '4%' THEN 4
        ELSE 5
    END,
    level ASC,
    last_name ASC,
    first_name ASC";

error_log("Export SQL: " . $sql);
error_log("Export Params: " . print_r($params, true));

$stmt = $db->prepare($sql);
$stmt->execute($params);
$voters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Debug: Log unique levels found
$uniqueLevels = array_unique(array_column($voters, 'level'));
error_log("Unique levels in export: " . print_r($uniqueLevels, true));
error_log("Total unique voters: " . count($voters));
    
    if (empty($voters)) {
        // Log no results
        if (function_exists('logActivity')) {
            logActivity(
                $_SESSION['user_id'],
                ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                'voters_export_no_results',
                'No voters found for export',
                json_encode(['filters' => $filters])
            );
        }
        
        header('HTTP/1.0 404 Not Found');
        echo 'No voters found matching the selected criteria.';
        exit;
    }

    // Log number of voters found
    if (function_exists('logActivity')) {
        logActivity(
            $_SESSION['user_id'],
            ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
            'voters_export_found',
            'Voters found for export',
            json_encode([
                'count' => count($voters),
                'filters' => $filters
            ])
        );
    }

    // Generate PDF
    generateVotersPDF($voters, $election, $filters);

} catch (Exception $e) {
    error_log("Export PDF error: " . $e->getMessage());
    
    // Log error
    if (function_exists('logActivity')) {
        logActivity(
            $_SESSION['user_id'] ?? 'unknown',
            ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
            'voters_export_error',
            'Error exporting voters PDF',
            json_encode([
                'error' => $e->getMessage(),
                'filters' => $filters ?? []
            ])
        );
    }
    
    header('HTTP/1.0 500 Internal Server Error');
    error_log('PDF generation error: ' . $e->getMessage());
    echo 'An error occurred while generating the PDF. Please try again or contact the administrator.';
    exit;
}

/**
 * Generate PDF with voter data
 */
function generateVotersPDF($voters, $election = null, $filters = []) {
    try {
        // Create new PDF document
        $pdf = new VoterCredentialsPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        
        // Set document properties
        $pdf->SetCreator('Nkoranza SHTs E-Voting System');
        $pdf->SetAuthor('Admin - ' . ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        $pdf->SetTitle('Voter Login Credentials - ' . date('Y-m-d'));
        $pdf->SetSubject('Voter Credentials Export');
        $pdf->SetKeywords('voters, credentials, passwords, election');
        
        // Set custom header properties
        $pdf->exportedBy = ($_SESSION['first_name'] ?? 'Admin') . ' ' . ($_SESSION['last_name'] ?? '');
        $pdf->exportDate = date('F j, Y H:i:s');
        
        if ($election && isset($election['title'])) {
            $pdf->titleText = "Voter Credentials - " . $election['title'];
            $pdf->electionInfo = $election['title'];
        } else {
            $pdf->titleText = "Voter Login Credentials - Prefectorial Election " . date('Y');
        }
        
        $pdf->filterInfo = $filters;
        
        // Set margins
        $pdf->SetMargins(15, 45, 15);
        $pdf->SetHeaderMargin(5);
        $pdf->SetFooterMargin(15);
        $pdf->SetAutoPageBreak(TRUE, 25);
        
        // Add first page
        $pdf->AddPage();
        
        // Filter summary
        $activeFilters = [];
        if (!empty($filters['department'])) {
            $activeFilters[] = "Department: " . $filters['department'];
        }
        if (!empty($filters['level'])) {
            $activeFilters[] = "Level: " . $filters['level'];
        }
        if ($filters['entry_year'] > 0) {
            $activeFilters[] = "Entry Year: " . $filters['entry_year'];
        }
        if ($filters['status'] !== 'all') {
            $activeFilters[] = "Status: " . ucfirst($filters['status']);
        }
        
        if (!empty($activeFilters)) {
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->SetFillColor(245, 245, 245);
            $pdf->Cell(0, 8, 'Filters Applied: ' . implode(' | ', $activeFilters), 0, 1, 'L', true);
            $pdf->Ln(2);
        }
        
        // Total count
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Total Voters: ' . count($voters), 0, 1, 'L');
        $pdf->Ln(4);
        
        // Group voters by level
        $groupedVoters = [];
        foreach ($voters as $voter) {
            $level = $voter['level'];
            if (!isset($groupedVoters[$level])) {
                $groupedVoters[$level] = [];
            }
            $groupedVoters[$level][] = $voter;
        }
        
        // Sort levels naturally
        ksort($groupedVoters, SORT_NATURAL);
        
        // Sort students within each level alphabetically
        foreach ($groupedVoters as $level => &$levelVoters) {
            usort($levelVoters, function($a, $b) {
                $lastNameCompare = strcasecmp($a['last_name'], $b['last_name']);
                if ($lastNameCompare !== 0) {
                    return $lastNameCompare;
                }
                return strcasecmp($a['first_name'], $b['first_name']);
            });
        }
        
        // Generate HTML table - FIXED VERSION for All Levels
$html = '<style>
    table { 
        border-collapse: collapse; 
        width: 100%; 
        font-family: helvetica, sans-serif;
        font-size: 9px;
    }
    th { 
        background-color: #831843; 
        color: white; 
        font-weight: bold; 
        padding: 8px 4px; 
        text-align: center;
        border: 1px solid #6b0f36;
    }
    td { 
        padding: 6px 4px; 
        border: 1px solid #dddddd; 
        vertical-align: middle;
    }
    .level-header { 
        background-color: #f3f4f6; 
        font-weight: bold; 
        font-size: 10px; 
        padding: 8px 4px;
        border: 1px solid #cccccc;
        text-align: left;
    }
    .plain-password { 
        font-family: "Courier New", monospace; 
        background-color: #d1fae5; 
        padding: 3px 6px; 
        border-radius: 4px; 
        color: #065f46; 
        font-weight: bold;
        letter-spacing: 0.5px;
        display: inline-block;
    }
    .status-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 12px;
        font-size: 8px;
        font-weight: bold;
    }
    .status-loggedin { 
        background-color: #dbeafe; 
        color: #1e40af;
    }
    .status-new { 
        background-color: #fef3c7; 
        color: #92400e;
    }
</style>';

$html .= '<table cellpadding="4">';
$html .= '<thead>
    <tr>
        <th width="5%">#</th>
        <th width="25%">Student Name</th>
        <th width="20%">Department</th>
        <th width="10%">Level</th>
        <th width="15%">User ID</th>
        <th width="15%">Password</th>
        <th width="10%">Status</th>
    </tr>
</thead>';
$html .= '<tbody>';

$counter = 1;
$passwordExamples = [];
$currentLevel = null;
$processedIds = []; // Track processed IDs to prevent duplicates

// First, group voters by level properly
$groupedVoters = [];
foreach ($voters as $voter) {
    $level = $voter['level'];
    if (!isset($groupedVoters[$level])) {
        $groupedVoters[$level] = [];
    }
    // Use ID as key to prevent duplicates within the same level
    $groupedVoters[$level][$voter['id']] = $voter;
}

// Sort levels naturally (1A1, 1A2, etc.)
ksort($groupedVoters, SORT_NATURAL);

// Process each level
foreach ($groupedVoters as $level => $levelVoters) {
    // Sort students within level alphabetically
    usort($levelVoters, function($a, $b) {
        $lastNameCompare = strcasecmp($a['last_name'], $b['last_name']);
        if ($lastNameCompare !== 0) {
            return $lastNameCompare;
        }
        return strcasecmp($a['first_name'], $b['first_name']);
    });
    
    // Add level header
    $levelCount = count($levelVoters);
    $html .= '<tr>';
    $html .= '<td colspan="7" class="level-header">📋 LEVEL ' . htmlspecialchars($level, ENT_QUOTES, 'UTF-8') . ' (' . $levelCount . ' students)</td>';
    $html .= '</tr>';
    
    // Add students for this level
    foreach ($levelVoters as $voter) {
        // Skip if we've already processed this ID (extra safety)
        if (in_array($voter['id'], $processedIds)) {
            continue;
        }
        $processedIds[] = $voter['id'];
        
        $fullName = $voter['first_name'] . ' ' . $voter['last_name'];
        
        // Generate password
        $plainPassword = generateSimplePassword(
            trim($voter['first_name']),
            trim($voter['last_name']),
            trim($voter['id'])
        );
        
        // Determine login status
        $statusClass = $voter['has_logged_in'] ? 'status-loggedin' : 'status-new';
        $statusText = $voter['has_logged_in'] ? 'Logged In' : 'Not Logged In';
        
        // Store examples for logging (first 3 unique users)
        if ($counter <= 3) {
            $passwordExamples[] = [
                'user_id' => $voter['id'],
                'name' => $fullName,
                'level' => $voter['level'],
                'password' => $plainPassword,
                'status' => $statusText
            ];
        }
        
        $html .= '<tr>';
        $html .= '<td align="center">' . $counter++ . '</td>';
        $html .= '<td>' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td>' . htmlspecialchars($voter['department'], ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td align="center">' . htmlspecialchars($voter['level'], ENT_QUOTES, 'UTF-8') . '</td>';
        $html .= '<td><code>' . htmlspecialchars($voter['id'], ENT_QUOTES, 'UTF-8') . '</code></td>';
        $html .= '<td align="center"><span class="plain-password">' . htmlspecialchars($plainPassword, ENT_QUOTES, 'UTF-8') . '</span></td>';
        $html .= '<td align="center"><span class="status-badge ' . $statusClass . '">' . $statusText . '</span></td>';
        $html .= '</tr>';
    }
}

$html .= '</tbody></table>';

// Add debug info if needed
error_log("Export: Processed " . count($processedIds) . " unique students across " . count($groupedVoters) . " levels");
        
        // Security notice
        $html .= '<br><br>';
        $html .= '<table width="100%" style="background-color: #fff3cd; border-left: 5px solid #ffc107; padding: 8px;">';
        $html .= '<tr><td style="font-size: 8px; line-height: 1.4;">';
        $html .= '<strong>🔐 IMPORTANT SECURITY NOTICE</strong><br>';
        $html .= '• These passwords are generated on-the-fly and are NOT stored in plain text.<br>';
        $html .= '• Each student gets a unique 8-character password (upper, lower, number, special).<br>';
        $html .= '• Students must change their password immediately after first login.<br>';
        $html .= '• This document contains sensitive information - handle with care.<br>';
        $html .= '• Generated: ' . date('Y-m-d H:i:s') . ' by ' . ($_SESSION['first_name'] ?? 'Admin') . ' ' . ($_SESSION['last_name'] ?? '') . '<br>';
        $html .= '• IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
        
        if ($election && isset($election['title'])) {
            $html .= '<br>• Election: ' . htmlspecialchars($election['title'], ENT_QUOTES, 'UTF-8');
            if (isset($election['start_date'])) {
                $html .= ' (' . date('M j, Y', strtotime($election['start_date'])) . ' - ' . date('M j, Y', strtotime($election['end_date'])) . ')';
            }
        }
        
        $html .= '</td></tr>';
        $html .= '</table>';
        
        // Write HTML to PDF
        $pdf->writeHTML($html, true, false, true, false, '');
        
        // Generate filename
        $filename = 'voter_credentials';
        if ($election && isset($election['title'])) {
            $filename .= '_' . preg_replace('/[^a-z0-9]/i', '_', $election['title']);
        }
        $filename .= '_' . date('Y-m-d_His') . '.pdf';
        
        // Log successful generation
        if (function_exists('logActivity')) {
            logActivity(
                $_SESSION['user_id'],
                ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                'voters_export_generated',
                'Voters PDF generated successfully',
                json_encode([
                    'filename' => $filename,
                    'voter_count' => count($voters),
                    'election_id' => $election ? $election['id'] : null,
                    'election_title' => $election ? $election['title'] : null,
                    'filters' => $filters,
                    'password_examples' => $passwordExamples
                ])
            );
        }
        
        // Output PDF for download
        $pdf->Output($filename, 'D');
        exit;
        
    } catch (Exception $e) {
        error_log("PDF Generation error: " . $e->getMessage());
        
        // Log error
        if (function_exists('logActivity')) {
            logActivity(
                $_SESSION['user_id'] ?? 'unknown',
                ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                'voters_export_pdf_error',
                'Error generating PDF',
                json_encode([
                    'error' => $e->getMessage(),
                    'voter_count' => count($voters)
                ])
            );
        }
        
        throw $e;
    }
}

// Shutdown function for fatal error logging
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log("Fatal error in export_voters_pdf.php: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
        
        if (function_exists('logActivity')) {
            logActivity(
                $_SESSION['user_id'] ?? 'system',
                ($_SESSION['first_name'] ?? 'System') . ' ' . ($_SESSION['last_name'] ?? ''),
                'voters_export_fatal_error',
                'Fatal error during export',
                json_encode([
                    'error' => $error['message'],
                    'file' => $error['file'],
                    'line' => $error['line']
                ])
            );
        }
        
        // Clear output buffer and show error
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        header('Content-Type: text/html');
        echo '<html><body style="font-family: Arial; padding: 20px;">';
        echo '<h2 style="color: #991b1b;">Export Failed</h2>';
        echo '<p>An error occurred while generating the PDF. Please try again or contact the administrator.</p>';
        echo '<p><a href="javascript:history.back()">Go Back</a></p>';
        echo '</body></html>';
    }
});
?>