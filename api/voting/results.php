<?php
// api/voting/results.php

// Load constants first so BASE_URL and ALLOWED_ORIGINS are available for the
// CORS block below. bootstrap.php also calls session_start() — we rely on that.
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

ob_start();

// Detect file-export requests BEFORE sending any Content-Type header.
// For PDF/CSV/Excel, TCPDF and the CSV/Excel code send their own headers.
// Sending Content-Type: application/json first makes headers_sent() return
// true when TCPDF checks it, causing a fatal "Some data has already been
// output, can't send PDF file" error → HTTP 500.
$_is_file_export = (
    isset($_REQUEST['export']) && $_REQUEST['export'] == '1'
    && isset($_REQUEST['format']) && in_array($_REQUEST['format'], ['pdf','csv','excel'], true)
);

if (!$_is_file_export) {
    header("Content-Type: application/json");
}

// CORS — allowlist-based, no wildcard, no reflected origin
// [FIX] Must load constants before this block so BASE_URL is defined.
$_results_allowed = defined('ALLOWED_ORIGINS') ? ALLOWED_ORIGINS : [BASE_URL];
$_results_origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($_results_origin, $_results_allowed, true)) {
    header("Access-Control-Allow-Origin: " . $_results_origin);
} else {
    header("Access-Control-Allow-Origin: " . BASE_URL);
}
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', false);
}

try {
    // Start session for user identification
    session_start();
    
    // Initialize Activity Logger
    $db = Database::getInstance()->getConnection();
    $activityLogger = new ActivityLogger($db);
    
    // Check if user is logged in for logging purposes
    $userId = $_SESSION['user_id'] ?? 'unknown';
    $userName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
    if (empty(trim($userName))) {
        $userName = $userId;
    }

    $analyticsOnly = isset($_REQUEST['analytics']) && $_REQUEST['analytics'] == '1';

    // election_id from GET or POST
    $election_id = $_REQUEST['election_id'] ?? null;
    $election_id = $election_id ? (int)$election_id : null;

    if (empty($election_id) && !$analyticsOnly) {
        throw new Exception('Election ID is required');
    }

    date_default_timezone_set('Africa/Accra');

    // Analytics-only request
    if ($analyticsOnly && $election_id) {
        $analyticsData = getAnalyticsData($db, $election_id);
        
        // Log analytics request
        $activityLogger->logActivity(
            $userId,
            $userName,
            'analytics_request',
            'Requested analytics data for election',
            json_encode(['election_id' => $election_id])
        );
        
        if (ob_get_length()) ob_clean();
        echo json_encode(['success' => true, 'analytics' => $analyticsData]);
        exit;
    }

    // Fetch election
    $electionStmt = $db->prepare('SELECT id, title, description, start_date, end_date FROM elections WHERE id = ?');
    $electionStmt->execute([$election_id]);
    $election = $electionStmt->fetch(PDO::FETCH_ASSOC);
    if (!$election) {
        throw new Exception('Election not found');
    }

    // Export flags
    $export = (isset($_REQUEST['export']) && $_REQUEST['export'] == '1');
    $format = $_REQUEST['format'] ?? 'csv';
    $includeSummary = !isset($_REQUEST['summary']) || $_REQUEST['summary'] == '1';
    $includeCandidates = !isset($_REQUEST['candidates']) || $_REQUEST['candidates'] == '1';
    $includeCharts = isset($_REQUEST['charts']) && $_REQUEST['charts'] == '1';

    // Total eligible voters (non-admin)
    $totalVoters = (int)$db->query('SELECT COUNT(*) FROM users WHERE is_admin = 0')->fetchColumn();

    // Positions for election
    $positionsStmt = $db->prepare('SELECT id, name, description, category FROM positions WHERE election_id = ? ORDER BY name');
    $positionsStmt->execute([$election_id]);
    $positions = $positionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Total distinct voters who cast a vote in this election
    $votesCastStmt = $db->prepare('SELECT COUNT(DISTINCT voter_id) FROM votes WHERE election_id = ?');
    $votesCastStmt->execute([$election_id]);
    $totalVotesCast = (int)$votesCastStmt->fetchColumn();

    // Fetch analytics early so it is available for all export paths
    $analyticsData = getAnalyticsData($db, $election_id);

    if (empty($positions)) {
        if ($export) {
            handleExportResponse($format, $election, [], $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts, $activityLogger, $userId, $userName, $analyticsData);
            exit;
        }
        if (ob_get_length()) ob_clean();
        echo json_encode([
            'success' => true,
            'positions' => [],
            'total_voters' => $totalVoters,
            'total_votes_cast' => $totalVotesCast,
            'turnout_rate' => $totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0,
            'message' => 'No positions found for this election'
        ]);
        exit;
    }

    $results = [];
    foreach ($positions as $position) {
        $positionId = (int)$position['id'];

        $candidates = []; // [FIX] initialise so previous iteration doesn't leak into next
        if ($position['id']) {
            // Special handling for Yes/No candidate positions
            $candidatesStmt = $db->prepare('
                SELECT 
                    c.id,
                    u.first_name,
                    u.last_name,
                    u.department,
                    u.level,
                    c.photo_path,
                    c.manifesto,
                    c.is_yes_no_candidate,
                    COUNT(v.id) as total_votes,
                    SUM(CASE WHEN v.rejected = 0 THEN 1 ELSE 0 END) as yes_votes,
                    SUM(CASE WHEN v.rejected = 1 THEN 1 ELSE 0 END) as no_votes
                FROM candidates c
                INNER JOIN users u ON c.user_id = u.id
                LEFT JOIN votes v 
                    ON v.election_id = ? 
                   AND v.position_id = ?
                   AND (v.candidate_id = c.id OR c.is_yes_no_candidate = 1)
                WHERE c.position_id = ?
                GROUP BY c.id, u.first_name, u.last_name, u.department, u.level, c.photo_path, c.manifesto, c.is_yes_no_candidate
                ORDER BY total_votes DESC
            ');
            $candidatesStmt->execute([$election_id, $positionId, $positionId]);
            $candidates = $candidatesStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // If a position has exactly one candidate, it was voted on as Yes/No
        // regardless of the is_yes_no_candidate flag (single-candidate positions
        // always show Yes/No buttons in the voting UI — rejected=0 = Yes, rejected=1 = No).
        if (count($candidates) === 1 && !$candidates[0]['is_yes_no_candidate']) {
            $candidates[0]['is_yes_no_candidate'] = 1;
            // yes_votes / no_votes may already be computed by the SQL — but the
            // JOIN condition only joins them when is_yes_no_candidate = 1.
            // Re-fetch explicitly for the single candidate.
            $ynStmt = $db->prepare('
                SELECT
                    SUM(CASE WHEN rejected = 0 THEN 1 ELSE 0 END) as yes_votes,
                    SUM(CASE WHEN rejected = 1 THEN 1 ELSE 0 END) as no_votes,
                    COUNT(*) as total_votes
                FROM votes
                WHERE election_id = ? AND position_id = ?
            ');
            $ynStmt->execute([$election_id, $positionId]);
            $ynRow = $ynStmt->fetch(PDO::FETCH_ASSOC);
            $candidates[0]['yes_votes']   = (int)($ynRow['yes_votes']   ?? 0);
            $candidates[0]['no_votes']    = (int)($ynRow['no_votes']    ?? 0);
            $candidates[0]['total_votes'] = (int)($ynRow['total_votes'] ?? 0);
        }

        // Normalize candidate fields and compute totals
        $totalVotes = array_sum(array_column($candidates, 'total_votes'));

        $candidatesWithPercentage = array_map(function($candidate) use ($totalVotes) {
            $isYesNo = (bool)$candidate['is_yes_no_candidate'];
            $votes = (int)$candidate['total_votes'];

            $result = [
                'id' => $candidate['id'],
                'name' => trim($candidate['first_name'] . ' ' . $candidate['last_name']),
                'department' => $candidate['department'] ?? null,
                'level' => $candidate['level'] ?? null,
                'photo_path' => formatPhotoPath($candidate['photo_path']),
                'manifesto' => $candidate['manifesto'] ?? null,
                'votes' => $votes,
                'is_yes_no_candidate' => $isYesNo
            ];

            if ($isYesNo) {
                $yes = (int)$candidate['yes_votes'];
                $no  = (int)$candidate['no_votes'];

                $result['yes_votes'] = $yes;
                $result['no_votes'] = $no;

                // Calculate percentages based only on Yes+No votes
                $totalYesNo = $yes + $no;
                $result['yes_percentage'] = $totalYesNo > 0 ? round(($yes / $totalYesNo) * 100, 1) : 0;
                $result['no_percentage'] = $totalYesNo > 0 ? round(($no / $totalYesNo) * 100, 1) : 0;

                // Overall percentage of Yes+No out of all votes in the position
                $result['percentage'] = $totalVotes > 0 ? round(($totalYesNo / $totalVotes) * 100, 1) : 0;
            } else {
                $pct = $totalVotes > 0 ? ($votes / $totalVotes) * 100 : 0;
                $result['percentage'] = round($pct, 1);
            }
            return $result;
        }, $candidates);

        $results[] = [
            'id' => $positionId,
            'name' => $position['name'],
            'description' => $position['description'],
            'category' => $position['category'],
            'total_votes' => $totalVotes,
            'candidates' => $candidatesWithPercentage
        ];
    }

    if ($export) {
        handleExportResponse($format, $election, $results, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts, $activityLogger, $userId, $userName, $analyticsData);
        exit;
    }

    // Log successful results retrieval
    $activityLogger->logActivity(
        $userId,
        $userName,
        'results_retrieved',
        'Retrieved election results',
        json_encode([
            'election_id' => $election_id,
            'election_title' => $election['title'],
            'position_count' => count($results),
            'total_votes_cast' => $totalVotesCast
        ])
    );

    if (ob_get_length()) ob_clean();
    echo json_encode([
        'success' => true,
        'positions' => $results,
        'election' => $election,
        'total_voters' => $totalVoters,
        'total_votes_cast' => $totalVotesCast,
        'turnout_rate' => $totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0,
        'analytics' => $analyticsData
    ]);

} catch (Exception $e) {
    // Log error
    if (isset($activityLogger) && isset($userId)) {
        $activityLogger->logActivity(
            $userId,
            $userName ?? 'unknown',
            'results_error',
            'Error retrieving results: ' . $e->getMessage(),
            json_encode(['election_id' => $election_id ?? null])
        );
    }
    
    if (ob_get_length()) ob_clean();
    error_log("Results API Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    http_response_code(400);
    // [FIX] Do not return exception message or debug trace to client
    echo json_encode([
        'success' => false,
        'message' => 'Failed to retrieve results. Please try again.'
    ]);
} finally {
    if (ob_get_level() > 0) ob_end_flush();
}

function getAnalyticsData($db, $election_id) {
    $analytics = [];
    
    // 1. Voting trends by hour
    $trendStmt = $db->prepare('
        SELECT 
            HOUR(timestamp) as hour,
            COUNT(*) as vote_count
        FROM votes 
        WHERE election_id = ?
        GROUP BY HOUR(timestamp)
        ORDER BY hour
    ');
    $trendStmt->execute([$election_id]);
    $hourlyData = $trendStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format hourly data for chart
    $hourlyVotes = array_fill(0, 24, 0);
    foreach ($hourlyData as $data) {
        $hourlyVotes[(int)$data['hour']] = (int)$data['vote_count'];
    }
    
    $analytics['hourly_trend'] = [
        'labels' => array_map(function($h) { 
            return sprintf('%02d:00', $h); 
        }, range(0, 23)),
        'data' => $hourlyVotes
    ];
    
    // 2. Category comparison
    $categoryStmt = $db->prepare('
        SELECT 
            p.category,
            COUNT(v.id) as vote_count
        FROM positions p
        LEFT JOIN votes v ON p.id = v.position_id AND v.election_id = ?
        WHERE p.election_id = ?
        GROUP BY p.category
        ORDER BY vote_count DESC
    ');
    $categoryStmt->execute([$election_id, $election_id]);
    $categoryData = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $analytics['category_comparison'] = [
        'labels' => array_map(function($item) { 
            return $item['category'] ?: 'Uncategorized'; 
        }, $categoryData),
        'data' => array_map(function($item) { 
            return (int)$item['vote_count']; 
        }, $categoryData)
    ];
    
    // 3. Time distribution (morning, afternoon, evening)
    $timeDistStmt = $db->prepare('
        SELECT 
            CASE 
                WHEN HOUR(timestamp) BETWEEN 6 AND 11 THEN "Morning (6AM-12PM)"
                WHEN HOUR(timestamp) BETWEEN 12 AND 17 THEN "Afternoon (12PM-6PM)"
                ELSE "Evening (6PM-6AM)"
            END as time_period,
            COUNT(*) as vote_count
        FROM votes 
        WHERE election_id = ?
        GROUP BY time_period
        ORDER BY time_period
    ');
    $timeDistStmt->execute([$election_id]);
    $timeData = $timeDistStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $analytics['time_distribution'] = [
        'labels' => array_column($timeData, 'time_period'),
        'data' => array_map(function($item) { 
            return (int)$item['vote_count']; 
        }, $timeData)
    ];
    
    // 4. Department participation
    $deptStmt = $db->prepare('
        SELECT 
            u.department,
            COUNT(DISTINCT v.voter_id) as voter_count
        FROM votes v
        INNER JOIN users u ON v.voter_id = u.id
        WHERE v.election_id = ?
        GROUP BY u.department
        ORDER BY voter_count DESC
    ');
    $deptStmt->execute([$election_id]);
    $deptData = $deptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $analytics['department_participation'] = [
        'labels' => array_column($deptData, 'department'),
        'data' => array_map(function($item) { 
            return (int)$item['voter_count']; 
        }, $deptData)
    ];
    
    return $analytics;
}

function handleExportResponse($format, $election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts, $activityLogger, $userId, $userName, $analyticsData = []) {
    // Check if user is admin before allowing export
    if (!isset($_SESSION['is_admin']) || !$_SESSION['is_admin']) {
        // Log unauthorized export attempt
        $activityLogger->logActivity(
            $userId,
            $userName,
            'export_unauthorized',
            'Unauthorized export attempt',
            json_encode(['election_id' => $election['id']])
        );
        
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied. Exporting results requires administrator privileges.']);
        exit;
    }
    
    // Log export attempt
    $activityLogger->logActivity(
        $userId,
        $userName,
        'export_attempt',
        'Exporting results',
        json_encode([
            'election_id' => $election['id'],
            'format' => $format,
            'include_summary' => $includeSummary,
            'include_candidates' => $includeCandidates,
            'include_charts' => $includeCharts
        ])
    );
    
    switch ($format) {
        case 'excel':
            exportExcel($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates);
            break;
        case 'pdf':
            exportPDF($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts, $analyticsData);
            break;
        case 'csv':
        default:
            exportCSV($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates);
            break;
    }
    
    // Log export completion (note: this may not execute if the export functions exit)
    $activityLogger->logActivity(
        $userId,
        $userName,
        'export_completed',
        'Export completed successfully',
        json_encode([
            'election_id' => $election['id'],
            'format' => $format
        ])
    );
}

function exportCSV($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates) {
    // Discard any buffered output so the CSV reaches the browser cleanly
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="election_' . $election['id'] . '_results.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8 compatibility with Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    if ($includeSummary) {
        fputcsv($output, ['Election Results Export']);
        fputcsv($output, ['Election:', $election['title']]);
        fputcsv($output, ['Description:', $election['description']]);
        fputcsv($output, ['Start Date:', $election['start_date']]);
        fputcsv($output, ['End Date:', $election['end_date']]);
        fputcsv($output, ['Total Voters:', $totalVoters]);
        fputcsv($output, ['Total Votes Cast:', $totalVotesCast]);
        fputcsv($output, ['Turnout Rate:', ($totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0) . '%']);
        fputcsv($output, []);
    }
    
    if ($includeCandidates && !empty($positions)) {
        foreach ($positions as $position) {
            fputcsv($output, ['Position:', $position['name']]);
            if (!empty($position['category'])) {
                fputcsv($output, ['Category:', $position['category']]);
            }
            if (!empty($position['description'])) {
                fputcsv($output, ['Description:', $position['description']]);
            }
            fputcsv($output, ['Total Votes:', $position['total_votes']]);
            fputcsv($output, []);
            
            // Add Level column to the header
            fputcsv($output, ['Rank', 'Candidate Name', 'Department', 'Level', 'Votes', 'Percentage', 'Status']);
            
            $voteCounts = array_column($position['candidates'], 'votes');
            $maxVotes = !empty($voteCounts) ? max($voteCounts) : 0;
            $winners = array_filter($position['candidates'], function($candidate) use ($maxVotes) {
                return $maxVotes > 0 && $candidate['votes'] === $maxVotes;
            });
            $isTie = count($winners) > 1;
            
            $rank = 1;
            $prevVotes = null;
            $actualRank = 1;

            foreach ($position['candidates'] as $index => $candidate) {
                // Determine actual rank (handle ties)
                if ($prevVotes !== null && $candidate['votes'] < $prevVotes) {
                    $actualRank = $rank;
                }
                $prevVotes = $candidate['votes'];
                
                $status = '';
                if ($candidate['votes'] === $maxVotes) {
                    $status = $isTie ? 'Tied Winner' : 'Winner';
                } else if ($candidate['votes'] > 0) {
                    // Show position for all candidates who received votes
                    $status = $actualRank . getOrdinalSuffix($actualRank);
                } else {
                    $status = 'No votes';
                }
                // In exportCSV function, update the candidate data output
                if ($candidate['is_yes_no_candidate']) {
                    fputcsv($output, [
                        $actualRank,
                        $candidate['name'] . ' (Yes/No Candidate)',
                        $candidate['department'] ?? '',
                        $candidate['level'] ?? '',
                        $candidate['votes'], // Total votes
                        $candidate['percentage'] . '%', // Total percentage
                        'Yes: ' . ($candidate['yes_votes'] ?? 0) . ' (' . ($candidate['yes_percentage'] ?? 0) . '%)',
                        'No: ' . ($candidate['no_votes'] ?? 0) . ' (' . ($candidate['no_percentage'] ?? 0) . '%)',
                        $status
                    ]);
                } else {
                    // Regular candidate
                    fputcsv($output, [
                        $actualRank,
                        $candidate['name'],
                        $candidate['department'] ?? '',
                        $candidate['level'] ?? '',
                        $candidate['votes'],
                        $candidate['percentage'] . '%',
                        $status
                    ]);
                }
            
                $rank++;
            }
            
            fputcsv($output, []);
            fputcsv($output, []);
        }
    }
    
    fclose($output);
    exit;
}

// Helper function to get ordinal suffix (1st, 2nd, 3rd, etc.)
function getOrdinalSuffix($number) {
    if ($number % 100 >= 11 && $number % 100 <= 13) {
        return 'th';
    }
    
    switch ($number % 10) {
        case 1: return 'st';
        case 2: return 'nd';
        case 3: return 'rd';
        default: return 'th';
    }
}

function exportPDF($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts, $analyticsData = []) {
    $tcpdfPath = __DIR__ . '/../../lib/tcpdf/tcpdf.php';
    
    if (!file_exists($tcpdfPath)) {
        header('Content-Type: text/html');
        header('Content-Disposition: attachment; filename="election_' . $election['id'] . '_results.html"');
        generatePDFHTML($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts, $analyticsData);
        return;
    }
    
    // Discard any buffered output (e.g. the ob_start() from the top of this
    // file) so TCPDF's ob_get_contents() check finds an empty buffer and
    // headers_sent() returns false at the point TCPDF sends its own headers.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    require_once($tcpdfPath);

    // Define CustomPDF AFTER TCPDF is loaded, guarded against redeclaration.
    if (!class_exists('CustomPDF')) {
        class CustomPDF extends TCPDF {
            public $schoolName;
            public $titleText;

            public function Header() {
                $logoPath = __DIR__ . '/../../assets/images/logo.png';
                $pageWidth = $this->getPageWidth();

                $this->SetY(10);
                $logoBottomY = 0;

                if (file_exists($logoPath)) {
                    $this->Image($logoPath, 15, 10, 20);
                    $logoBottomY = 10 + 20;
                }

                $this->SetXY(40, 12);
                $this->SetFont('helvetica', 'B', 12);
                $this->Cell(0, 6, $this->schoolName, 0, 1, 'L');

                $this->SetX(40);
                $this->SetFont('helvetica', '', 10);
                $this->Cell(0, 6, $this->titleText, 0, 1, 'L');

                $this->SetX(40);
                $this->Cell(0, 6, 'Generated on ' . date('Y-m-d g:i:s A'), 0, 1, 'L');

                if ($logoBottomY > 0) {
                    $this->SetDrawColor(0, 0, 0);
                    $this->SetLineWidth(0.2);
                    $this->Line($this->lMargin, $logoBottomY + 2, $pageWidth - $this->rMargin, $logoBottomY + 2);
                    $this->SetY($logoBottomY + 5);
                } else {
                    $this->Ln(5);
                }
            }
        }
    }

    // Create PDF object
    $pdf = new CustomPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->schoolName = "Nkoranza Senior High/Technical School";
    $pdf->titleText  = "Election Results - " . $election['title'];

    $pdf->SetCreator('Nkoranza SHT E-Voting System');
    $pdf->SetAuthor('Election Committee');
    $pdf->SetTitle('Election Results - ' . $election['title']);
    $pdf->SetSubject('Election Results Report');

    // Margins
    $pdf->SetMargins(15, 35, 15);
    $pdf->SetHeaderMargin(5);
    $pdf->SetFooterMargin(10);
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);

    // Add first page
    $pdf->AddPage();

    // Election Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Election Results: ' . $election['title'], 0, 1, 'C');
    $pdf->Ln(10);

    // Election Summary
    if ($includeSummary) {
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 10, 'Election Summary', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);

        $summaryData = [
            'Description' => $election['description'],
            'Start Date' => $election['start_date'],
            'End Date' => $election['end_date'],
            'Total Voters' => number_format($totalVoters),
            'Total Votes Cast' => number_format($totalVotesCast),
            'Turnout Rate' => ($totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0) . '%'
        ];

        foreach ($summaryData as $label => $value) {
            if (!empty($value)) {
                $pdf->Cell(50, 6, $label . ':', 0, 0, 'L');
                $pdf->Cell(0, 6, $value, 0, 1, 'L');
            }
        }

        $pdf->Ln(10);
    }

    // Candidate Results
    if ($includeCandidates && !empty($positions)) {
        foreach ($positions as $position) {
            if ($pdf->GetY() > 200) {
                $pdf->AddPage();
            }

            // Position title
            $pdf->SetFont('helvetica', 'B', 12);
            $pdf->Cell(0, 10, 'Position: ' . $position['name'], 0, 1, 'L');
            
            // Reset to normal font for details
            $pdf->SetFont('helvetica', '', 10);

            if (!empty($position['category'])) {
                $pdf->Cell(0, 6, 'Category: ' . $position['category'], 0, 1, 'L');
            }

            if (!empty($position['description'])) {
                $pdf->Cell(0, 6, 'Description: ' . $position['description'], 0, 1, 'L');
            }

            $pdf->Cell(0, 6, 'Total Votes: ' . number_format($position['total_votes']), 0, 1, 'L');
            $pdf->Ln(5);

            $isYesNo = false;
            foreach ($position['candidates'] as $c) {
                if (!empty($c['is_yes_no_candidate']) && $c['is_yes_no_candidate']) {
                    $isYesNo = true; break;
                }
            }

            if ($isYesNo) {
                // Yes/No Table
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetFillColor(240, 240, 240);
                
                $pdf->Cell(60, 8, 'Question/Candidate', 1, 0, 'L', true);
                $pdf->Cell(25, 8, 'Department', 1, 0, 'L', true);
                $pdf->Cell(20, 8, 'Level', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'Yes Votes', 1, 0, 'C', true);
                $pdf->Cell(30, 8, 'No Votes', 1, 0, 'C', true);
                $pdf->Cell(20, 8, 'Status', 1, 1, 'C', true);

                $pdf->SetFont('helvetica', '', 9);
                foreach ($position['candidates'] as $candidate) {
                    if ($candidate['is_yes_no_candidate']) {
                        $yesVotes = $candidate['yes_votes'] ?? 0;
                        $noVotes = $candidate['no_votes'] ?? 0;
                        $totalYesNoVotes = $yesVotes + $noVotes;
                        
                        // Determine status with percentages
                        if ($totalYesNoVotes > 0) {
                            if ($yesVotes > $noVotes) {
                                $status = 'Approved';
                            } else if ($noVotes > $yesVotes) {
                                $status = 'Rejected';
                            } else {
                                $status = 'Tied';
                            }
                        } else {
                            $status = 'No votes';
                        }
                        
                        $pdf->Cell(60, 8, $candidate['name'] . ' (Yes/No)', 1, 0, 'L');
                        $pdf->Cell(25, 8, $candidate['department'] ?? '', 1, 0, 'L');
                        $pdf->Cell(20, 8, $candidate['level'] ?? '', 1, 0, 'C');
                        $yesPct = $candidate['yes_percentage'] ?? 0;
                        $noPct  = $candidate['no_percentage']  ?? 0;
                        $pdf->Cell(30, 8, "{$yesVotes} ({$yesPct}%)", 1, 0, 'C');
                        $pdf->Cell(30, 8, "{$noVotes} ({$noPct}%)", 1, 0, 'C');
                        $pdf->Cell(20, 8, $status, 1, 1, 'C');
                    }
                }
            } else {
                // Normal table for regular candidates
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->SetFillColor(240, 240, 240);
                $pdf->Cell(15, 8, 'Rank', 1, 0, 'C', true);
                $pdf->Cell(50, 8, 'Candidate Name', 1, 0, 'L', true);
                $pdf->Cell(30, 8, 'Department', 1, 0, 'L', true);
                $pdf->Cell(20, 8, 'Level', 1, 0, 'C', true);
                $pdf->Cell(20, 8, 'Votes', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Percentage', 1, 0, 'C', true);
                $pdf->Cell(25, 8, 'Status', 1, 1, 'C', true);

                $pdf->SetFont('helvetica', '', 9);
                $pdfVoteCounts = array_column($position['candidates'], 'votes');
                $maxVotes = !empty($pdfVoteCounts) ? max($pdfVoteCounts) : 0;
                $winners = array_filter($position['candidates'], fn($c) => $maxVotes > 0 && $c['votes'] === $maxVotes);
                $isTie = count($winners) > 1;

                $rank = 1;
                $prevVotes = null;
                $actualRank = 1;

                foreach ($position['candidates'] as $candidate) {
                    if ($prevVotes !== null && $candidate['votes'] < $prevVotes) {
                        $actualRank = $rank;
                    }
                    $prevVotes = $candidate['votes'];

                    $status = '';
                    if ($candidate['votes'] === $maxVotes) {
                        $status = $isTie ? 'Tied Winner' : 'Winner';
                    } elseif ($candidate['votes'] > 0) {
                        $status = $actualRank . getOrdinalSuffix($actualRank);
                    } else {
                        $status = 'No votes';
                    }

                    $pdf->Cell(15, 8, $actualRank, 1, 0, 'C');
                    $pdf->Cell(50, 8, $candidate['name'], 1, 0, 'L');
                    $pdf->Cell(30, 8, $candidate['department'] ?? '', 1, 0, 'L');
                    $pdf->Cell(20, 8, $candidate['level'] ?? '', 1, 0, 'C');
                    $pdf->Cell(20, 8, $candidate['votes'], 1, 0, 'C');
                    $pdf->Cell(25, 8, $candidate['percentage'] . '%', 1, 0, 'C');
                    $pdf->Cell(25, 8, $status, 1, 1, 'C');
                    $rank++;
                }
            }
            $pdf->Ln(10);
        }
    }

    // ── Analytics section (always included in PDF export) ──────────────────
    generateAnalyticsForPDF($pdf, $analyticsData, $election, $totalVoters, $totalVotesCast);

    // Charts section - per-position bar charts if requested
    if ($includeCharts) {
        generateChartsForPDF($pdf, $positions, $election);
    }

    // ── Signature / certification page — always last ─────────────────────────
    generateSignaturePageForPDF($pdf, $election);

    // Output — TCPDF's Output('D') sends Content-Type and Content-Disposition itself.
    // Adding header() calls here would either duplicate or override TCPDF's headers
    // causing a 500. Let TCPDF handle the download headers entirely.
    $filename = 'election_' . preg_replace('/[^a-z0-9_]/i', '_', $election['title']) . '_results.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// ============================================================================
// generateSignaturePageForPDF
// Adds a dedicated final page with three commissioner signature blocks.
// Uses TCPDF 6.x setLineStyle() — SetLineDash() does not exist in this version.
// ============================================================================
// ============================================================================
// _pdfDrawSignatureSection
// Pins the certification block to the bottom of the CURRENT page so it always
// appears on the same sheet as the last content line, not on a new page.
// Only adds a page if content has already pushed past the pin point.
// ============================================================================
function _pdfDrawSignatureSection($pdf, $election) {
    // Draws immediately after the last content line on the same page.
    // Adds a new page only if the block would overflow the usable area.

    $margins  = $pdf->getMargins();
    $lMargin  = $margins['left'];
    $rMargin  = $margins['right'];
    $pageW    = $pdf->getPageWidth();   // 210mm
    $pageH    = $pdf->getPageHeight();  // 297mm
    $bMargin  = $margins['bottom'];     // 25mm (PDF_MARGIN_BOTTOM)

    // Block height (mm): header~22 + columns~81 + footer~11 = ~114
    $blockH   = 114;
    $usable   = $pageH - $bMargin;

    // Start immediately after the last content line with a small gap.
    // Only add a new page if the block would overflow the usable area.
    $startY   = $pdf->GetY() + 6;
    if ($startY + $blockH > $usable) {
        $pdf->AddPage();
        $startY = $pdf->GetY() + 4;
    }

    $solidThin  = ['width' => 0.3, 'cap' => 'butt',  'join' => 'miter', 'dash' => 0,     'color' => [150, 150, 150]];
    $dottedDate = ['width' => 0.4, 'cap' => 'round', 'join' => 'miter', 'dash' => '1,2', 'color' => [80,  80,  80 ]];
    $dottedSig  = ['width' => 0.5, 'cap' => 'round', 'join' => 'miter', 'dash' => '2,2', 'color' => [60,  60,  60 ]];
    $dottedName = ['width' => 0.4, 'cap' => 'round', 'join' => 'miter', 'dash' => '1,2', 'color' => [80,  80,  80 ]];
    $dottedBox  = ['width' => 0.5, 'cap' => 'butt',  'join' => 'miter', 'dash' => '3,2', 'color' => [120, 120, 120]];
    $resetStyle = ['width' => 0.2, 'cap' => 'butt',  'join' => 'miter', 'dash' => 0,     'color' => [0,   0,   0  ]];

    // ── Header: ruling line + title + cert text ───────────────────────────────
    $y = $startY;
    $pdf->Line($lMargin, $y, $pageW - $rMargin, $y, $solidThin);
    $y += 4;

    $pdf->SetXY($lMargin, $y);
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 7, 'Official Certification', 0, 1, 'C');
    $y += 7;

    $pdf->SetXY($lMargin, $y);
    $pdf->SetFont('helvetica', '', 7);
    $pdf->Cell(0, 3.5, 'I/We hereby certify that the results presented in this document are a true and accurate record', 0, 1, 'C');
    $y += 3.5;
    $pdf->SetXY($lMargin, $y);
    $pdf->Cell(0, 3.5, 'of the ' . $election['title'] . ' election held at Nkoranza Senior High/Technical School.', 0, 1, 'C');
    $y += 3.5 + 3;

    $pdf->Line($lMargin, $y, $pageW - $rMargin, $y, $solidThin);
    $y += 5;

    // ── Three-column signature block ──────────────────────────────────────────
    // 56mm × 3 cols + 6mm × 2 gutters = 180mm printable width
    $colW    = 56;
    $gutter  = 6;
    $signers = ['Electoral Commissioner', 'Presiding Officer', 'Head of School / Principal'];
    $colY    = $y;

    foreach ($signers as $idx => $roleTitle) {
        $cx = $lMargin + $idx * ($colW + $gutter);

        // Role title
        $pdf->SetXY($cx, $colY);
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->Cell($colW, 6, $roleTitle, 0, 0, 'C');

        // Date line
        $dy = $colY + 9;
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetXY($cx, $dy);
        $pdf->Cell(14, 4, 'Date:', 0, 0, 'L');
        $pdf->Line($cx + 14, $dy + 3.5, $cx + $colW, $dy + 3.5, $dottedDate);

        // Signature space + dotted line
        $sy = $colY + 25;
        $pdf->SetXY($cx, $sy);
        $pdf->SetFont('helvetica', 'I', 6);
        $pdf->Cell($colW, 4, 'Signature', 0, 0, 'C');
        $pdf->Line($cx, $sy + 12, $cx + $colW, $sy + 12, $dottedSig);

        // Name line
        $ny = $sy + 15;
        $pdf->SetXY($cx, $ny);
        $pdf->SetFont('helvetica', 'I', 6);
        $pdf->Cell($colW, 4, 'Name (Print clearly)', 0, 0, 'C');
        $pdf->Line($cx, $ny + 9, $cx + $colW, $ny + 9, $dottedName);

        // Stamp box
        $by = $ny + 13;
        $bh = 28;
        $pdf->SetLineStyle($dottedBox);
        $pdf->Rect($cx, $by, $colW, $bh, 'D');
        $pdf->SetLineStyle($resetStyle);
        $pdf->SetXY($cx, $by + 10);
        $pdf->SetFont('helvetica', 'I', 6);
        $pdf->SetTextColor(170, 170, 170);
        $pdf->Cell($colW, 4, 'Official Stamp / Seal', 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
    }

    // Column height: role(6)+gap(3)+date(4)+gap(12)+sig(12)+gap(3)+name(9)+gap(4)+stamp(28) = 81mm
    $pdf->SetLineStyle($resetStyle);
    $endY = $colY + 81;

    // ── Footer: rule + generated note ────────────────────────────────────────
    $pdf->Line($lMargin, $endY + 2, $pageW - $rMargin, $endY + 2, $solidThin);
    $pdf->SetXY($lMargin, $endY + 4);
    $pdf->SetFont('helvetica', 'I', 7);
    $pdf->SetTextColor(130, 130, 130);
    $pdf->Cell(0, 4,
        'Generated by the Nkoranza SHT E-Voting System on ' . date('F j, Y') . ' at ' . date('g:i A') . '.',
        0, 1, 'C');
    $pdf->SetTextColor(0, 0, 0);
}

// ============================================================================
// generateSignaturePageForPDF — kept for backward compatibility; delegates to
// the shared helper which now handles page-break logic internally.
// ============================================================================
function generateSignaturePageForPDF($pdf, $election) {
    _pdfDrawSignatureSection($pdf, $election);
}

function generateAnalyticsForPDF($pdf, $analyticsData, $election, $totalVoters, $totalVotesCast) {
    if (empty($analyticsData)) return;

    $pdf->AddPage();

    // ── Section header ───────────────────────────────────────────────────────
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Analytics Report', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Election: ' . $election['title'], 0, 1, 'C');
    $pdf->Ln(4);

    // ── Turnout summary box ──────────────────────────────────────────────────
    $turnout = $totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0;
    $pdf->SetFillColor(240, 245, 255);
    $pdf->SetDrawColor(180, 200, 230);

    $bx = $pdf->GetX();
    $by = $pdf->GetY();
    $pdf->Rect($bx, $by, 180, 18, 'FD');

    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetXY($bx + 5, $by + 3);
    $pdf->Cell(55, 6, 'Total Eligible Voters:', 0, 0, 'L');
    $pdf->Cell(30, 6, number_format($totalVoters), 0, 0, 'L');

    $pdf->Cell(55, 6, 'Votes Cast:', 0, 0, 'L');
    $pdf->Cell(30, 6, number_format($totalVotesCast), 0, 1, 'L');

    $pdf->SetXY($bx + 5, $by + 10);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(55, 6, 'Voter Turnout:', 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 6, $turnout . '%', 0, 1, 'L');

    // Turnout bar
    $pdf->SetXY($bx, $by + 20);
    $pdf->Ln(2);
    _pdfBarRow($pdf, 'Turnout', $turnout, 100, [66, 135, 245], [220, 230, 245]);
    $pdf->Ln(6);

    // ── 1. Hourly voting trend ───────────────────────────────────────────────
    if (!empty($analyticsData['hourly_trend']['data'])) {
        $hourlyData  = $analyticsData['hourly_trend']['data'];
        $hourlyMax   = max($hourlyData) ?: 1;
        $totalHourly = array_sum($hourlyData);

        if ($totalHourly > 0) {
            _pdfSectionTitle($pdf, '1. Voting Activity by Hour');

            // Compact bar chart — one thin bar per hour
            $chartX     = $pdf->GetX() + 2;
            $chartY     = $pdf->GetY();
            $barW       = 6.5;   // 24 bars × 6.5 ≈ 156 mm
            $maxBarH    = 28;
            $baseY      = $chartY + $maxBarH + 2;

            $peakHour   = array_search(max($hourlyData), $hourlyData);
            $peakCount  = max($hourlyData);

            foreach ($hourlyData as $h => $count) {
                if ($count === 0) {
                    $pdf->SetFillColor(230, 230, 230);
                } elseif ($h === $peakHour) {
                    $pdf->SetFillColor(220, 50, 50);
                } else {
                    $pdf->SetFillColor(66, 135, 245);
                }
                $barH = $maxBarH * ($count / $hourlyMax);
                $pdf->Rect($chartX + $h * $barW, $baseY - $barH, $barW - 0.5, $barH, 'F');
            }

            // X-axis labels (every 3 hours)
            $pdf->SetFont('helvetica', '', 7);
            for ($h = 0; $h < 24; $h += 3) {
                $pdf->SetXY($chartX + $h * $barW - 1, $baseY + 1);
                $pdf->Cell($barW * 3, 4, sprintf('%02d:00', $h), 0, 0, 'L');
            }

            $pdf->SetXY($chartX, $baseY + 6);
            $pdf->SetFont('helvetica', 'I', 8);
            $pdf->Cell(0, 5, sprintf('Peak: %02d:00 - %02d:59 with %d votes   |   Red bar = peak hour', $peakHour, $peakHour, $peakCount), 0, 1, 'L');
            $pdf->Ln(3);

            // Top 5 hours table
            arsort($hourlyData);
            $top5 = array_slice($hourlyData, 0, 5, true);
            _pdfSmallTableHeader($pdf, ['Hour', 'Votes', '% of Total'], [40, 40, 40]);
            foreach ($top5 as $h => $cnt) {
                $pct = $totalHourly > 0 ? round($cnt / $totalHourly * 100, 1) : 0;
                _pdfSmallTableRow($pdf, [sprintf('%02d:00 - %02d:59', $h, $h), $cnt, $pct . '%'], [40, 40, 40]);
            }
            $pdf->Ln(5);
        }
    }

    // ── 2. Time-of-day distribution ──────────────────────────────────────────
    if (!empty($analyticsData['time_distribution']['data'])) {
        $timeLabels = $analyticsData['time_distribution']['labels'];
        $timeData   = $analyticsData['time_distribution']['data'];
        $timeMax    = max($timeData) ?: 1;
        $timeTotal  = array_sum($timeData);

        if ($timeTotal > 0) {
            if ($pdf->GetY() > 220) $pdf->AddPage();
            _pdfSectionTitle($pdf, '2. Time-of-Day Distribution');

            $colors = [[66,135,245], [245,165,50], [60,180,100]];
            foreach ($timeLabels as $i => $label) {
                $count = $timeData[$i] ?? 0;
                $pct   = $timeTotal > 0 ? round($count / $timeTotal * 100, 1) : 0;
                $rgb   = $colors[$i % count($colors)];
                _pdfBarRow($pdf, $label, $pct, 100, $rgb, [230, 230, 230], $count . ' votes (' . $pct . '%)');
            }
            $pdf->Ln(4);
        }
    }

    // ── 3. Department participation ──────────────────────────────────────────
    if (!empty($analyticsData['department_participation']['data'])) {
        $deptLabels = $analyticsData['department_participation']['labels'];
        $deptData   = $analyticsData['department_participation']['data'];
        $deptMax    = max($deptData) ?: 1;
        $deptTotal  = array_sum($deptData);

        if ($deptTotal > 0) {
            if ($pdf->GetY() > 200) $pdf->AddPage();
            _pdfSectionTitle($pdf, '3. Participation by Department');

            // Bar chart
            foreach ($deptLabels as $i => $dept) {
                $count = $deptData[$i] ?? 0;
                $pct   = $deptTotal > 0 ? round($count / $deptTotal * 100, 1) : 0;
                $barPct = $deptMax > 0 ? round($count / $deptMax * 100, 1) : 0;
                _pdfBarRow($pdf, $dept ?: 'Unknown', $barPct, 100, [66, 135, 245], [230, 230, 230], $count . ' voters (' . $pct . '%)');
            }

            $pdf->Ln(2);

            // Table
            _pdfSmallTableHeader($pdf, ['Department', 'Voters', '% Share'], [70, 30, 30]);
            foreach ($deptLabels as $i => $dept) {
                $count = $deptData[$i] ?? 0;
                $pct   = $deptTotal > 0 ? round($count / $deptTotal * 100, 1) : 0;
                _pdfSmallTableRow($pdf, [$dept ?: 'Unknown', $count, $pct . '%'], [70, 30, 30]);
            }
            $pdf->Ln(5);
        }
    }

    // ── 4. Category vote comparison ──────────────────────────────────────────
    if (!empty($analyticsData['category_comparison']['data'])) {
        $catLabels = $analyticsData['category_comparison']['labels'];
        $catData   = $analyticsData['category_comparison']['data'];
        $catMax    = max($catData) ?: 1;
        $catTotal  = array_sum($catData);

        if ($catTotal > 0) {
            if ($pdf->GetY() > 200) $pdf->AddPage();
            _pdfSectionTitle($pdf, '4. Votes by Position Category');

            $colors = [[66,135,245],[245,165,50],[60,180,100],[220,80,80],[150,80,220]];
            foreach ($catLabels as $i => $cat) {
                $count  = $catData[$i] ?? 0;
                $pct    = $catTotal > 0 ? round($count / $catTotal * 100, 1) : 0;
                $barPct = $catMax   > 0 ? round($count / $catMax   * 100, 1) : 0;
                $rgb    = $colors[$i % count($colors)];
                _pdfBarRow($pdf, $cat ?: 'Uncategorized', $barPct, 100, $rgb, [230,230,230], $count . ' votes (' . $pct . '%)');
            }

            $pdf->Ln(2);
            _pdfSmallTableHeader($pdf, ['Category', 'Votes', '% Share'], [80, 30, 30]);
            foreach ($catLabels as $i => $cat) {
                $count = $catData[$i] ?? 0;
                $pct   = $catTotal > 0 ? round($count / $catTotal * 100, 1) : 0;
                _pdfSmallTableRow($pdf, [$cat ?: 'Uncategorized', $count, $pct . '%'], [80, 30, 30]);
            }
        }
    }

}

// ── Drawing helpers ──────────────────────────────────────────────────────────

// Labelled horizontal bar row: label | ████░░░░ | value text
function _pdfBarRow($pdf, $label, $pct, $maxPct, $fillRgb, $trackRgb, $valueText = null) {
    $labelW  = 52;
    $trackW  = 95;
    $valueW  = 33;
    $barH    = 6;
    $y       = $pdf->GetY();
    $x       = $pdf->GetX();

    // Label
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY($x, $y);
    $pdf->Cell($labelW, $barH, substr($label, 0, 28), 0, 0, 'L');

    // Track (background)
    $pdf->SetFillColor($trackRgb[0], $trackRgb[1], $trackRgb[2]);
    $pdf->Rect($x + $labelW, $y + 1, $trackW, $barH - 2, 'F');

    // Fill
    $fillW = max(0.5, $trackW * $pct / $maxPct);
    $pdf->SetFillColor($fillRgb[0], $fillRgb[1], $fillRgb[2]);
    $pdf->Rect($x + $labelW, $y + 1, $fillW, $barH - 2, 'F');

    // Value text
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetXY($x + $labelW + $trackW + 2, $y);
    $pdf->Cell($valueW, $barH, $valueText ?? ($pct . '%'), 0, 1, 'L');
}

function _pdfSectionTitle($pdf, $title) {
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(230, 238, 255);
    $pdf->SetDrawColor(180, 200, 230);
    $pdf->Rect($pdf->GetX(), $pdf->GetY(), 180, 8, 'FD');
    $pdf->Cell(180, 8, $title, 0, 1, 'L');
    $pdf->Ln(2);
}

function _pdfSmallTableHeader($pdf, $cols, $widths) {
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(66, 100, 180);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($cols as $i => $col) {
        $pdf->Cell($widths[$i], 7, $col, 1, 0, 'C', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);
}

function _pdfSmallTableRow($pdf, $cols, $widths, $fill = false) {
    static $toggle = false;
    $toggle = !$toggle;
    $pdf->SetFont('helvetica', '', 9);
    if ($toggle) {
        $pdf->SetFillColor(245, 248, 255);
    } else {
        $pdf->SetFillColor(255, 255, 255);
    }
    foreach ($cols as $i => $col) {
        $pdf->Cell($widths[$i], 6, $col, 1, 0, 'C', true);
    }
    $pdf->Ln();
}

// Improved function to generate charts for PDF
function generateChartsForPDF($pdf, $positions, $election) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Election Analysis Charts', 0, 1, 'C');
    $pdf->Ln(10);

    // Check if we can actually generate charts
    $canGenerateCharts = extension_loaded('gd') && function_exists('imagecreate');
    
    if (!$canGenerateCharts) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->MultiCell(0, 8, 'Chart generation requires GD extension. Charts are displayed as text-based representations instead.', 0, 'L');
        $pdf->Ln(10);
    }

    // Generate position-wise charts
    foreach ($positions as $positionIndex => $position) {
        // Start new page if we're running out of space
        if ($pdf->GetY() > 180) {
            $pdf->AddPage();
        }

        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'Position: ' . $position['name'], 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        
        if (!empty($position['category'])) {
            $pdf->Cell(0, 6, 'Category: ' . $position['category'], 0, 1, 'L');
        }
        
        $pdf->Cell(0, 6, 'Total Votes: ' . number_format($position['total_votes']), 0, 1, 'L');
        $pdf->Ln(5);

        // Check if this is a Yes/No position
        $isYesNoPosition = false;
        foreach ($position['candidates'] as $candidate) {
            if (!empty($candidate['is_yes_no_candidate']) && $candidate['is_yes_no_candidate']) {
                $isYesNoPosition = true;
                break;
            }
        }

        if ($isYesNoPosition) {
            // Generate Yes/No chart
            generateYesNoChart($pdf, $position);
        } else {
            // Generate regular bar chart
            generateBarChart($pdf, $position);
        }

        $pdf->Ln(10);
        
        // Add page break after every 2 charts or when running out of space
        if (($positionIndex + 1) % 2 === 0 && ($positionIndex + 1) < count($positions)) {
            $pdf->AddPage();
        }
    }

    // Generate summary charts on a new page
    generateSummaryCharts($pdf, $positions);
}

// Improved Yes/No chart function
function generateYesNoChart($pdf, $position) {
    if (empty($position['candidates'])) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 8, 'No candidate data available.', 0, 1, 'L');
        return;
    }

    $candidate = $position['candidates'][0]; // Yes/No positions have only one candidate
    
    $yesVotes = $candidate['yes_votes'] ?? 0;
    $noVotes = $candidate['no_votes'] ?? 0;
    $totalVotes = $yesVotes + $noVotes;
    
    if ($totalVotes === 0) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 8, 'No votes cast for this Yes/No question.', 0, 1, 'L');
        return;
    }

    $yesPercentage = round(($yesVotes / $totalVotes) * 100, 1);
    $noPercentage = round(($noVotes / $totalVotes) * 100, 1);

    // Create a visual chart representation
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Vote Distribution:', 0, 1, 'L');
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Chart container
    $chartWidth = 150;
    $chartHeight = 20;
    $x = $pdf->GetX();
    $y = $pdf->GetY();
    
    // Yes votes bar (Green)
    $yesWidth = ($yesPercentage / 100) * $chartWidth;
    $pdf->SetFillColor(75, 192, 192); // Green for Yes
    $pdf->Rect($x, $y, $yesWidth, $chartHeight, 'F');
    
    // No votes bar (Red)
    $noWidth = ($noPercentage / 100) * $chartWidth;
    $pdf->SetFillColor(255, 99, 132); // Red for No
    $pdf->Rect($x + $yesWidth, $y, $noWidth, $chartHeight, 'F');
    
    // Add text labels
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($x, $y + $chartHeight + 2);
    $pdf->Cell($yesWidth, 6, "Yes: {$yesVotes} ({$yesPercentage}%)", 0, 0, 'C');
    $pdf->Cell($noWidth, 6, "No: {$noVotes} ({$noPercentage}%)", 0, 1, 'C');
    
    $pdf->Ln(5);
    
    // Result
    $pdf->SetFont('helvetica', 'B', 12);
    if ($yesVotes > $noVotes) {
        $pdf->Cell(0, 10, 'RESULT: APPROVED', 0, 1, 'L');
    } elseif ($noVotes > $yesVotes) {
        $pdf->Cell(0, 10, 'RESULT: REJECTED', 0, 1, 'L');
    } else {
        $pdf->Cell(0, 10, 'RESULT: TIED', 0, 1, 'L');
    }
}

// Improved bar chart function
function generateBarChart($pdf, $position) {
    $candidates = $position['candidates'];
    $maxVotes = max(array_column($candidates, 'votes'));
    
    if ($maxVotes === 0) {
        $pdf->SetFont('helvetica', 'I', 10);
        $pdf->Cell(0, 8, 'No votes cast for this position.', 0, 1, 'L');
        return;
    }

    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(0, 8, 'Vote Distribution:', 0, 1, 'L');
    
    $chartWidth = 100;
    $barHeight = 8;
    $spacing = 12;
    
    $colors = [
        [255, 99, 132],    // Red
        [54, 162, 235],    // Blue
        [255, 206, 86],    // Yellow
        [75, 192, 192],    // Green
        [153, 102, 255],   // Purple
        [255, 159, 64],    // Orange
    ];
    
    $colorIndex = 0;
    
    foreach ($candidates as $candidate) {
        $percentage = $candidate['percentage'];
        $barLength = ($candidate['votes'] / $maxVotes) * $chartWidth;
        
        // Candidate name (truncate if too long)
        $name = substr($candidate['name'], 0, 25);
        if (strlen($candidate['name']) > 25) {
            $name .= '...';
        }
        
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        
        // Candidate name
        $pdf->Cell(60, $barHeight, $name, 0, 0, 'L');
        
        // Bar
        $pdf->SetFillColor($colors[$colorIndex][0], $colors[$colorIndex][1], $colors[$colorIndex][2]);
        $pdf->Rect($x + 60, $y, $barLength, $barHeight, 'F');
        
        // Votes text
        $pdf->SetXY($x + 60 + $barLength + 5, $y);
        $pdf->Cell(0, $barHeight, $candidate['votes'] . ' votes (' . $percentage . '%)', 0, 1, 'L');
        
        $colorIndex = ($colorIndex + 1) % count($colors);
        
        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }
    }
    
    // Show winner(s)
    $winners = array_filter($candidates, function($c) use ($maxVotes) {
        return $c['votes'] === $maxVotes;
    });
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Ln(8);
    
    if (count($winners) === 1) {
        $winner = reset($winners);
        $pdf->Cell(0, 10, 'WINNER: ' . $winner['name'] . ' (' . $winner['votes'] . ' votes)', 0, 1, 'L');
    } else {
        $winnerNames = array_map(function($w) { return $w['name']; }, $winners);
        $pdf->Cell(0, 10, 'TIED: ' . implode(', ', $winnerNames) . ' (' . $maxVotes . ' votes each)', 0, 1, 'L');
    }
}

// Improved summary charts function
function generateSummaryCharts($pdf, $positions) {
    $pdf->AddPage();
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, 'Election Summary Charts', 0, 1, 'C');
    $pdf->Ln(10);

    // Category distribution
    $categories = [];
    foreach ($positions as $position) {
        $category = $position['category'] ?: 'Uncategorized';
        if (!isset($categories[$category])) {
            $categories[$category] = 0;
        }
        $categories[$category] += $position['total_votes'];
    }
    
    if (!empty($categories)) {
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'Votes by Category', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 10);
        
        $totalCategoryVotes = array_sum($categories);
        $maxCategoryVotes = max($categories);
        
        $chartWidth = 120;
        $barHeight = 10;
        $spacing = 15;
        
        foreach ($categories as $category => $votes) {
            $percentage = $totalCategoryVotes > 0 ? round(($votes / $totalCategoryVotes) * 100, 1) : 0;
            $barLength = ($votes / $maxCategoryVotes) * $chartWidth;
            
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            
            // Category name
            $pdf->Cell(50, $barHeight, $category, 0, 0, 'L');
            
            // Bar
            $pdf->SetFillColor(54, 162, 235);
            $pdf->Rect($x + 50, $y, $barLength, $barHeight, 'F');
            
            // Votes text
            $pdf->SetXY($x + 50 + $barLength + 5, $y);
            $pdf->Cell(0, $barHeight, $votes . ' votes (' . $percentage . '%)', 0, 1, 'L');
            
            // Check if we need a new page
            if ($pdf->GetY() > 250) {
                $pdf->AddPage();
            }
        }
        $pdf->Ln(15);
    }
    
    // Position participation (Top 10)
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Most Contested Positions (Top 10)', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    // Sort positions by vote count
    usort($positions, function($a, $b) {
        return $b['total_votes'] - $a['total_votes'];
    });
    
    $topPositions = array_slice($positions, 0, 10);
    $maxPositionVotes = max(array_column($topPositions, 'total_votes'));
    
    $chartWidth = 100;
    $barHeight = 8;
    
    foreach ($topPositions as $position) {
        $name = substr($position['name'], 0, 35);
        if (strlen($position['name']) > 35) {
            $name .= '...';
        }
        
        $barLength = $maxPositionVotes > 0 ? ($position['total_votes'] / $maxPositionVotes) * $chartWidth : 0;
        
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        
        // Position name
        $pdf->Cell(70, $barHeight, $name, 0, 0, 'L');
        
        // Bar
        $pdf->SetFillColor(75, 192, 192);
        $pdf->Rect($x + 70, $y, $barLength, $barHeight, 'F');
        
        // Votes text
        $pdf->SetXY($x + 70 + $barLength + 5, $y);
        $pdf->Cell(0, $barHeight, number_format($position['total_votes']) . ' votes', 0, 1, 'L');
        
        // Check if we need a new page
        if ($pdf->GetY() > 250) {
            $pdf->AddPage();
        }
    }
}

function generatePDFHTML($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates, $includeCharts) {
    echo '<!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Election Results - ' . htmlspecialchars($election['title']) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            h1 { color: #333; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            .summary { background-color: #f9f9f9; padding: 15px; margin-bottom: 20px; }
            .position { margin-bottom: 30px; }
            .chart { margin: 20px 0; padding: 15px; border: 1px solid #ddd; background: #f9f9f9; }
            .chart-bar { background: #4CAF50; height: 20px; margin: 5px 0; color: white; padding: 2px 5px; }
            @media print {
                body { margin: 20px; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="no-print" style="background: #f0f0f0; padding: 10px; margin-bottom: 20px;">
            <p><strong>Note:</strong> This is an HTML version. For a proper PDF, please use the print dialog in your browser (Ctrl+P) and select "Save as PDF".</p>
        </div>
        
        <h1>Election Results: ' . htmlspecialchars($election['title']) . '</h1>';
        
        if ($includeSummary) {
            echo '<div class="summary">
                <h2>Election Summary</h2>
                <p><strong>Description:</strong> ' . htmlspecialchars($election['description']) . '</p>
                <p><strong>Start Date:</strong> ' . htmlspecialchars($election['start_date']) . '</p>
                <p><strong>End Date:</strong> ' . htmlspecialchars($election['end_date']) . '</p>
                <p><strong>Total Voters:</strong> ' . number_format($totalVoters) . '</p>
                <p><strong>Total Votes Cast:</strong> ' . number_format($totalVotesCast) . '</p>
                <p><strong>Turnout Rate:</strong> ' . ($totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0) . '%</p>
            </div>';
        }
        
        if ($includeCandidates && !empty($positions)) {
            foreach ($positions as $position) {
                echo '<div class="position">
                    <h2>' . htmlspecialchars($position['name']) . '</h2>';
                    
                if (!empty($position['category'])) {
                    echo '<p><strong>Category:</strong> ' . htmlspecialchars($position['category']) . '</p>';
                }
                
                if (!empty($position['description'])) {
                    echo '<p><strong>Description:</strong> ' . htmlspecialchars($position['description']) . '</p>';
                }
                
                echo '<p><strong>Total Votes:</strong> ' . number_format($position['total_votes']) . '</p>
                    <table>
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Candidate Name</th>
                                <th>Department</th>
                                <th>Level</th>
                                <th>Votes</th>
                                <th>Percentage</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>';
                
                $maxVotes = max(array_column($position['candidates'], 'votes'));
                $winners = array_filter($position['candidates'], function($candidate) use ($maxVotes) {
                    return $candidate['votes'] === $maxVotes;
                });
                $isTie = count($winners) > 1;
                
                $rank = 1;
                $prevVotes = null;
                $actualRank = 1;

                foreach ($position['candidates'] as $candidate) {
                    // Determine actual rank (handle ties)
                    if ($prevVotes !== null && $candidate['votes'] < $prevVotes) {
                        $actualRank = $rank;
                    }
                    $prevVotes = $candidate['votes'];
                    
                    $status = '';
                    if ($candidate['votes'] === $maxVotes) {
                        $status = $isTie ? 'Tied Winner' : 'Winner';
                    } else if ($candidate['votes'] > 0) {
                        // Show position for all candidates who received votes
                        $status = $actualRank . getOrdinalSuffix($actualRank);
                    } else {
                        $status = 'No votes';
                    }
                    
                    echo '<tr>
                        <td>' . $actualRank . '</td>
                        <td>' . htmlspecialchars($candidate['name']) . '</td>
                        <td>' . htmlspecialchars($candidate['department'] ?? '') . '</td>
                        <td>' . htmlspecialchars($candidate['level'] ?? '') . '</td>
                        <td>' . number_format($candidate['votes']) . '</td>
                        <td>' . $candidate['percentage'] . '%</td>
                        <td>' . $status . '</td>
                    </tr>';
                    
                    $rank++;
                }
                
                echo '</tbody>
                    </table>';
                
                // Add simple chart representation for HTML version
                if ($includeCharts) {
                    echo '<div class="chart">
                        <h3>Vote Distribution</h3>';
                    
                    $isYesNo = false;
                    foreach ($position['candidates'] as $c) {
                        if (!empty($c['is_yes_no_candidate']) && $c['is_yes_no_candidate']) {
                            $isYesNo = true;
                            break;
                        }
                    }
                    
                    if ($isYesNo) {
                        $candidate = $position['candidates'][0];
                        $yesVotes = $candidate['yes_votes'] ?? 0;
                        $noVotes = $candidate['no_votes'] ?? 0;
                        $totalYesNo = $yesVotes + $noVotes;
                        
                        if ($totalYesNo > 0) {
                            $yesWidth = ($yesVotes / $totalYesNo) * 100;
                            $noWidth = ($noVotes / $totalYesNo) * 100;
                            
                            echo '<div style="margin: 10px 0;">
                                <div style="background: #4CAF50; height: 25px; width: ' . $yesWidth . '%; display: inline-block; color: white; text-align: center; line-height: 25px;">
                                    Yes: ' . $yesVotes . ' (' . round($yesWidth, 1) . '%)
                                </div>
                                <div style="background: #f44336; height: 25px; width: ' . $noWidth . '%; display: inline-block; color: white; text-align: center; line-height: 25px;">
                                    No: ' . $noVotes . ' (' . round($noWidth, 1) . '%)
                                </div>
                            </div>';
                        }
                    } else {
                        foreach ($position['candidates'] as $candidate) {
                            $width = $candidate['percentage'];
                            echo '<div class="chart-bar" style="width: ' . $width . '%;">
                                ' . htmlspecialchars($candidate['name']) . ': ' . $candidate['votes'] . ' votes (' . $candidate['percentage'] . '%)
                            </div>';
                        }
                    }
                    
                    echo '</div>';
                }
                
                echo '</div>';
            }
        }
        
        if ($includeCharts) {
            echo '<div style="page-break-before: always;">
                <h2>Summary Charts</h2>
                <p>Detailed summary charts would be generated here in the PDF version.</p>
            </div>';
        }
        
    echo '</body>
    </html>';
}

function exportExcel($election, $positions, $totalVoters, $totalVotesCast, $includeSummary, $includeCandidates) {
    // Discard any buffered output so the file reaches the browser cleanly
    while (ob_get_level() > 0) { ob_end_clean(); }

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="election_' . $election['id'] . '_results.xlsx"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // Add BOM for UTF-8 compatibility with Excel
    fwrite($output, "\xEF\xBB\xBF");
    
    if ($includeSummary) {
        fputcsv($output, ['Election Results Export']);
        fputcsv($output, ['Election:', $election['title']]);
        fputcsv($output, ['Description:', $election['description']]);
        fputcsv($output, ['Start Date:', $election['start_date']]);
        fputcsv($output, ['End Date:', $election['end_date']]);
        fputcsv($output, ['Total Voters:', $totalVoters]);
        fputcsv($output, ['Total Votes Cast:', $totalVotesCast]);
        fputcsv($output, ['Turnout Rate:', ($totalVoters > 0 ? round(($totalVotesCast / $totalVoters) * 100, 1) : 0) . '%']);
        fputcsv($output, []);
    }
    
    if ($includeCandidates && !empty($positions)) {
        foreach ($positions as $position) {
            fputcsv($output, ['Position:', $position['name']]);
            if (!empty($position['category'])) {
                fputcsv($output, ['Category:', $position['category']]);
            }
            fputcsv($output, ['Total Votes:', $position['total_votes']]);
            fputcsv($output, []);
            
            fputcsv($output, ['Rank', 'Candidate Name', 'Department', 'Level', 'Votes', 'Percentage', 'Status']);
            
            $voteCounts = array_column($position['candidates'], 'votes');
            $maxVotes = !empty($voteCounts) ? max($voteCounts) : 0;
            $winners = array_filter($position['candidates'], function($candidate) use ($maxVotes) {
                return $maxVotes > 0 && $candidate['votes'] === $maxVotes;
            });
            $isTie = count($winners) > 1;
            
            $rank = 1;
            $prevVotes = null;
            $actualRank = 1;
            
            usort($position['candidates'], function($a, $b) {
                return $b['votes'] - $a['votes'];
            });
            
            foreach ($position['candidates'] as $candidate) {
                if ($prevVotes !== null && $candidate['votes'] < $prevVotes) {
                    $actualRank = $rank;
                }
                $prevVotes = $candidate['votes'];
                
                $status = '';
                if ($candidate['votes'] === $maxVotes) {
                    $status = $isTie ? 'Tied Winner' : 'Winner';
                } elseif ($candidate['votes'] > 0) {
                    $status = $actualRank . getOrdinalSuffix($actualRank);
                } else {
                    $status = 'No votes';
                }
                
                fputcsv($output, [
                    $actualRank,
                    $candidate['name'],
                    $candidate['department'] ?? '',
                    $candidate['level'] ?? '',
                    $candidate['votes'],
                    $candidate['percentage'] . '%',
                    $status
                ]);
                $rank++;
            }
            
            fputcsv($output, []);
        }
    }
    
    fclose($output);
    exit;
}

function formatPhotoPath($path) {
    if (!$path) return null;
    if (filter_var($path, FILTER_VALIDATE_URL)) return $path;
    
    // Better security validation
    $cleanPath = basename($path);
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];
    $extension = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
    
    if (!in_array($extension, $allowedExtensions)) {
        return BASE_URL . '/assets/images/default-user.jpg';
    }
    
    return BASE_URL . '/uploads/candidates/' . $cleanPath;
}
?>