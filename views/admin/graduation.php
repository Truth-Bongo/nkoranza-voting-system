<?php
// admin/graduation.php
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../helpers/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Only admin should view this page
if (empty($_SESSION['is_admin'])) {
    header('Location: ' . BASE_URL . '/login');
    exit;
}

require_once APP_ROOT . '/includes/header.php';

// Use the Database class properly
$db = Database::getInstance()->getConnection();

// Include ActivityLogger class
require_once APP_ROOT . '/includes/ActivityLogger.php';
$activityLogger = new ActivityLogger($db);

// Get user information for logging
$userId = $_SESSION['user_id'] ?? 'unknown';
$userName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
if (empty(trim($userName))) $userName = $userId;

// Set timezone
date_default_timezone_set('Africa/Accra');

// Log page access
$activityLogger->logActivity(
    $userId,
    $userName,
    'graduation_page_view',
    'Accessed graduation management page',
    json_encode([
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ])
);

// Handle graduation actions
$message = '';
$error = '';

if (isset($_POST['action']) && $_POST['action'] === 'run_graduation') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token';
        
        // Log CSRF failure
        $activityLogger->logActivity(
            $userId,
            $userName,
            'graduation_csrf_failure',
            'CSRF token validation failed for auto graduation',
            json_encode(['action' => 'run_graduation'])
        );
    } else {
        try {
            $current_year = date('Y');
            $current_date = date('Y-m-d H:i:s');
            
            $db->beginTransaction();
            
            $findStmt = $db->prepare("
                SELECT id, first_name, last_name, graduation_year 
                FROM users 
                WHERE is_admin = 0 
                AND status = 'active'
                AND graduation_year IS NOT NULL 
                AND graduation_year <= ?
            ");
            $findStmt->execute([$current_year]);
            $to_graduate = $findStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($to_graduate)) {
                $message = "No students need to be marked as graduated at this time.";
                $db->commit();
                
                // Log no students to graduate
                $activityLogger->logActivity(
                    $userId,
                    $userName,
                    'graduation_auto_no_students',
                    'Auto graduation run - no eligible students found',
                    json_encode(['current_year' => $current_year])
                );
            } else {
                $updateStmt = $db->prepare("
                    UPDATE users 
                    SET status = 'graduated',
                        graduated_at = ?
                    WHERE is_admin = 0 
                    AND status = 'active'
                    AND graduation_year IS NOT NULL 
                    AND graduation_year <= ?
                ");
                $updateStmt->execute([$current_date, $current_year]);
                $updated_count = $updateStmt->rowCount();
                
                $db->commit();
                $message = "Successfully graduated {$updated_count} students.";
                
                // Log successful auto graduation
                $activityLogger->logActivity(
                    $userId,
                    $userName,
                    'graduation_auto_completed',
                    "Auto graduated {$updated_count} students",
                    json_encode([
                        'current_year' => $current_year,
                        'students_graduated' => $updated_count,
                        'students_list' => array_column($to_graduate, 'id')
                    ])
                );
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error during graduation update: " . $e->getMessage();
            
            // Log error
            $activityLogger->logActivity(
                $userId,
                $userName,
                'graduation_auto_error',
                'Error during auto graduation',
                json_encode([
                    'error' => $e->getMessage(),
                    'current_year' => $current_year
                ])
            );
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'bulk_graduate_by_year') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token';
        
        // Log CSRF failure
        $activityLogger->logActivity(
            $userId,
            $userName,
            'graduation_bulk_csrf_failure',
            'CSRF token validation failed for bulk graduation',
            json_encode(['action' => 'bulk_graduate_by_year'])
        );
    } else {
        $target_year = $_POST['graduation_year'] ?? date('Y');
        
        try {
            $db->beginTransaction();
            
            // Get students to be graduated for logging
            $findStmt = $db->prepare("
                SELECT id, first_name, last_name 
                FROM users 
                WHERE is_admin = 0 
                AND status = 'active'
                AND graduation_year = ?
            ");
            $findStmt->execute([$target_year]);
            $students_to_graduate = $findStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $db->prepare("
                UPDATE users 
                SET status = 'graduated',
                    graduated_at = NOW()
                WHERE is_admin = 0 
                AND status = 'active'
                AND graduation_year = ?
            ");
            $stmt->execute([$target_year]);
            $count = $stmt->rowCount();
            
            $db->commit();
            $message = "Marked {$count} students from Class of {$target_year} as graduated.";
            
            // Log successful bulk graduation
            $activityLogger->logActivity(
                $userId,
                $userName,
                'graduation_bulk_completed',
                "Bulk graduated Class of {$target_year}",
                json_encode([
                    'target_year' => $target_year,
                    'students_graduated' => $count,
                    'students_list' => array_column($students_to_graduate, 'id')
                ])
            );
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error: " . $e->getMessage();
            
            // Log error
            $activityLogger->logActivity(
                $userId,
                $userName,
                'graduation_bulk_error',
                'Error during bulk graduation',
                json_encode([
                    'error' => $e->getMessage(),
                    'target_year' => $target_year
                ])
            );
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'graduate_individual') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token';
        
        // Log CSRF failure
        $activityLogger->logActivity(
            $userId,
            $userName,
            'graduation_individual_csrf_failure',
            'CSRF token validation failed for individual graduation',
            json_encode(['action' => 'graduate_individual'])
        );
    } else {
        $student_id = $_POST['student_id'] ?? '';
        $graduation_year = $_POST['graduation_year'] ?? date('Y');
        
        if (empty($student_id)) {
            $error = 'Student ID is required';
        } else {
            try {
                $db->beginTransaction();
                
                $studentStmt = $db->prepare("SELECT first_name, last_name, status FROM users WHERE id = ?");
                $studentStmt->execute([$student_id]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception('Student not found');
                }
                
                $previous_status = $student['status'];
                
                $stmt = $db->prepare("
                    UPDATE users 
                    SET status = 'graduated',
                        graduation_year = ?,
                        graduated_at = NOW()
                    WHERE id = ? AND is_admin = 0
                ");
                $stmt->execute([$graduation_year, $student_id]);
                
                if ($stmt->rowCount() > 0) {
                    $message = "Student {$student['first_name']} {$student['last_name']} has been marked as graduated.";
                    
                    // Log successful individual graduation
                    $activityLogger->logActivity(
                        $userId,
                        $userName,
                        'graduation_individual_completed',
                        "Individually graduated student",
                        json_encode([
                            'student_id' => $student_id,
                            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                            'graduation_year' => $graduation_year,
                            'previous_status' => $previous_status
                        ])
                    );
                } else {
                    $error = "Student not found or already graduated";
                    
                    // Log failure
                    $activityLogger->logActivity(
                        $userId,
                        $userName,
                        'graduation_individual_failed',
                        'Failed to individually graduate student',
                        json_encode([
                            'student_id' => $student_id,
                            'reason' => 'Student not found or already graduated'
                        ])
                    );
                }
                
                $db->commit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error: " . $e->getMessage();
                
                // Log error
                $activityLogger->logActivity(
                    $userId,
                    $userName,
                    'graduation_individual_error',
                    'Error during individual graduation',
                    json_encode([
                        'error' => $e->getMessage(),
                        'student_id' => $student_id
                    ])
                );
            }
        }
    }
}

if (isset($_POST['action']) && $_POST['action'] === 'revert_to_active') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid security token';
        
        // Log CSRF failure
        $activityLogger->logActivity(
            $userId,
            $userName,
            'graduation_revert_csrf_failure',
            'CSRF token validation failed for revert to active',
            json_encode(['action' => 'revert_to_active'])
        );
    } else {
        $student_id = $_POST['student_id'] ?? '';
        
        if (empty($student_id)) {
            $error = 'Student ID is required';
        } else {
            try {
                $db->beginTransaction();
                
                $studentStmt = $db->prepare("SELECT first_name, last_name, status, graduated_at FROM users WHERE id = ?");
                $studentStmt->execute([$student_id]);
                $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$student) {
                    throw new Exception('Student not found');
                }
                
                $previous_status = $student['status'];
                $previous_graduated_at = $student['graduated_at'];
                
                $stmt = $db->prepare("
                    UPDATE users 
                    SET status = 'active',
                        graduated_at = NULL
                    WHERE id = ? AND is_admin = 0
                ");
                $stmt->execute([$student_id]);
                
                if ($stmt->rowCount() > 0) {
                    $message = "Student {$student['first_name']} {$student['last_name']} has been reverted to active status.";
                    
                    // Log successful revert
                    $activityLogger->logActivity(
                        $userId,
                        $userName,
                        'graduation_revert_completed',
                        "Reverted student to active status",
                        json_encode([
                            'student_id' => $student_id,
                            'student_name' => $student['first_name'] . ' ' . $student['last_name'],
                            'previous_status' => $previous_status,
                            'previous_graduated_at' => $previous_graduated_at
                        ])
                    );
                } else {
                    $error = "Student not found";
                    
                    // Log failure
                    $activityLogger->logActivity(
                        $userId,
                        $userName,
                        'graduation_revert_failed',
                        'Failed to revert student',
                        json_encode([
                            'student_id' => $student_id,
                            'reason' => 'Student not found'
                        ])
                    );
                }
                
                $db->commit();
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = "Error: " . $e->getMessage();
                
                // Log error
                $activityLogger->logActivity(
                    $userId,
                    $userName,
                    'graduation_revert_error',
                    'Error during revert to active',
                    json_encode([
                        'error' => $e->getMessage(),
                        'student_id' => $student_id
                    ])
                );
            }
        }
    }
}

// Get statistics
$stats = [];
$recentGraduates = [];
$pendingGraduation = [];

try {
    $statsStmt = $db->query("
        SELECT 
            COUNT(*) as total_students,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_students,
            SUM(CASE WHEN status = 'graduated' THEN 1 ELSE 0 END) as graduated_students,
            SUM(CASE WHEN graduation_year IS NOT NULL AND graduation_year <= YEAR(CURDATE()) AND status != 'graduated' THEN 1 ELSE 0 END) as pending_graduation
        FROM users 
        WHERE is_admin = 0
    ");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    $recentStmt = $db->query("
        SELECT id, first_name, last_name, graduation_year, graduated_at
        FROM users 
        WHERE status = 'graduated' AND graduated_at IS NOT NULL
        ORDER BY graduated_at DESC
        LIMIT 5
    ");
    $recentGraduates = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pendingStmt = $db->prepare("
        SELECT id, first_name, last_name, graduation_year
        FROM users 
        WHERE is_admin = 0 
        AND status = 'active'
        AND graduation_year IS NOT NULL 
        AND graduation_year <= YEAR(CURDATE())
        ORDER BY graduation_year ASC, last_name ASC
        LIMIT 5
    ");
    $pendingStmt->execute();
    $pendingGraduation = $pendingStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error fetching graduation stats: " . $e->getMessage());
    
    // Log error
    $activityLogger->logActivity(
        $userId,
        $userName,
        'graduation_stats_error',
        'Error fetching graduation statistics',
        json_encode(['error' => $e->getMessage()])
    );
}

// Fetch distinct graduation years for the dropdown (covers all cohorts in the DB)
$graduationYears = [];
try {
    $yearsStmt = $db->query("
        SELECT DISTINCT graduation_year
        FROM users
        WHERE is_admin = 0
          AND graduation_year IS NOT NULL
        ORDER BY graduation_year DESC
    ");
    $graduationYears = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    error_log("Error fetching graduation years: " . $e->getMessage());
}
// Always ensure current year and next year are in the list
$currentYear = (int)date('Y');
foreach ([$currentYear - 1, $currentYear, $currentYear + 1] as $y) {
    if (!in_array($y, $graduationYears)) {
        $graduationYears[] = $y;
    }
}
rsort($graduationYears);

// Log statistics retrieval
$activityLogger->logActivity(
    $userId,
    $userName,
    'graduation_stats_viewed',
    'Viewed graduation statistics',
    json_encode([
        'total_students' => $stats['total_students'] ?? 0,
        'active' => $stats['active_students'] ?? 0,
        'graduated' => $stats['graduated_students'] ?? 0,
        'pending' => $stats['pending_graduation'] ?? 0
    ])
);
?>

<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

<style>
/* ── Design tokens ── */
:root {
  --pk:     #831843;
  --pk2:    #9d174d;
  --pk3:    #fce7f3;
  --pk4:    #fdf2f8;
  --pk-grd: linear-gradient(135deg,#831843 0%,#be185d 100%);
  --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --shadow:    0 4px 14px rgba(131,24,67,.10);
  --shadow-lg: 0 12px 28px rgba(131,24,67,.16);
  --radius: .875rem;
}

/* ── Stat cards ── */
.g-stat {
  position:relative;overflow:hidden;
  background:#fff;border-radius:var(--radius);
  padding:1.25rem 1.4rem;
  box-shadow:var(--shadow-sm);
  border:1px solid rgba(131,24,67,.07);
  transition:transform .2s,box-shadow .2s;
}
.g-stat:hover { transform:translateY(-3px);box-shadow:var(--shadow-lg); }
.g-stat::before {
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:var(--pk-grd);
}
.g-stat .stat-icon {
  width:2.5rem;height:2.5rem;border-radius:.65rem;
  display:flex;align-items:center;justify-content:center;
  font-size:1rem;flex-shrink:0;
}
.g-stat .stat-num {
  font-size:2rem;font-weight:800;line-height:1;
  color:#111827;letter-spacing:-.03em;
}
.g-stat .stat-label {
  font-size:.7rem;font-weight:600;text-transform:uppercase;
  letter-spacing:.08em;color:#9ca3af;margin-bottom:.25rem;
}
.g-stat .stat-sub {
  font-size:.72rem;margin-top:.4rem;display:flex;align-items:center;gap:.25rem;
}

/* ── Action tiles ── */
.g-tile {
  background:#fff;border-radius:var(--radius);padding:1.5rem;
  border:1px solid rgba(131,24,67,.08);
  box-shadow:var(--shadow-sm);
  transition:border-color .2s,box-shadow .2s;
}
.g-tile:hover { border-color:rgba(131,24,67,.3);box-shadow:var(--shadow); }
.g-tile-icon {
  width:2.75rem;height:2.75rem;border-radius:.75rem;
  background:var(--pk-grd);
  display:flex;align-items:center;justify-content:center;
  color:#fff;font-size:1rem;flex-shrink:0;
  box-shadow:0 4px 10px rgba(131,24,67,.3);
}

/* ── List cards ── */
.g-list-card {
  background:#fff;border-radius:var(--radius);
  border:1px solid rgba(131,24,67,.08);
  box-shadow:var(--shadow-sm);overflow:hidden;
}
.g-list-header {
  background:var(--pk-grd);
  padding:.9rem 1.25rem;
  display:flex;align-items:center;justify-content:space-between;
}
.g-list-header h3 { color:#fff;font-size:.9rem;font-weight:600; }
.g-list-badge {
  background:rgba(255,255,255,.2);color:#fff;
  border:1px solid rgba(255,255,255,.35);
  font-size:.7rem;font-weight:700;padding:.2rem .65rem;border-radius:99px;
}
.g-list-body { padding:.75rem;max-height:21rem;overflow-y:auto; }
.g-list-body::-webkit-scrollbar { width:4px; }
.g-list-body::-webkit-scrollbar-thumb { background:var(--pk);border-radius:4px; }

/* ── Student rows ── */
.s-row {
  display:flex;align-items:center;gap:.75rem;
  padding:.7rem .85rem;border-radius:.65rem;
  border:1px solid transparent;
  transition:background .15s,border-color .15s;
}
.s-row:hover { background:var(--pk4);border-color:rgba(131,24,67,.15); }
.s-avatar {
  width:2.35rem;height:2.35rem;border-radius:50%;
  background:var(--pk-grd);color:#fff;
  display:flex;align-items:center;justify-content:center;
  font-size:.78rem;font-weight:700;flex-shrink:0;
  letter-spacing:.02em;
}
.s-name { font-size:.875rem;font-weight:600;color:#111827;line-height:1.2; }
.s-meta { font-size:.72rem;color:#9ca3af;margin-top:.15rem; }

/* ── Badges ── */
.badge {
  display:inline-block;padding:.15rem .55rem;border-radius:99px;
  font-size:.68rem;font-weight:600;letter-spacing:.02em;
}
.badge-amber  { background:#fef3c7;color:#92400e; }
.badge-purple { background:#ede9fe;color:#4c1d95; }
.badge-green  { background:#dcfce7;color:#166534; }
.badge-pink   { background:var(--pk3);color:var(--pk); }

/* ── Buttons ── */
.btn-pk {
  display:inline-flex;align-items:center;gap:.35rem;
  background:var(--pk-grd);color:#fff;border:none;cursor:pointer;
  padding:.42rem .9rem;border-radius:.5rem;
  font-size:.78rem;font-weight:600;white-space:nowrap;
  box-shadow:0 2px 8px rgba(131,24,67,.25);
  transition:opacity .15s,transform .15s,box-shadow .15s;
}
.btn-pk:hover { opacity:.9;transform:scale(1.03);box-shadow:0 4px 14px rgba(131,24,67,.35); }

.btn-out {
  display:inline-flex;align-items:center;gap:.35rem;
  background:#fff;color:var(--pk);border:1.5px solid var(--pk);cursor:pointer;
  padding:.42rem .9rem;border-radius:.5rem;
  font-size:.78rem;font-weight:600;white-space:nowrap;
  transition:background .15s,color .15s;
}
.btn-out:hover { background:var(--pk);color:#fff; }

.btn-ghost {
  display:inline-flex;align-items:center;gap:.35rem;
  background:var(--pk3);color:var(--pk);border:none;cursor:pointer;
  padding:.42rem .9rem;border-radius:.5rem;
  font-size:.78rem;font-weight:600;
  transition:background .15s;
}
.btn-ghost:hover { background:#f9a8d4;color:var(--pk); }

/* ── Form inputs ── */
.g-input {
  width:100%;padding:.5rem .85rem;
  border:1.5px solid #e5e7eb;border-radius:.5rem;
  font-size:.875rem;outline:none;background:#fff;
  transition:border-color .2s,box-shadow .2s;
}
.g-input:focus { border-color:var(--pk);box-shadow:0 0 0 3px rgba(131,24,67,.1); }
.g-select { appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23831843' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .75rem center;padding-right:2.2rem; }

/* ── Flash messages ── */
.flash-ok  { background:#f0fdf4;border-left:4px solid #22c55e;color:#166534; }
.flash-err { background:#fef2f2;border-left:4px solid #ef4444;color:#991b1b; }
.flash-ok,.flash-err {
  padding:.85rem 1rem;border-radius:0 .65rem .65rem 0;
  display:flex;align-items:flex-start;gap:.75rem;font-size:.875rem;margin-bottom:1.5rem;
}

/* ── Pulse dot ── */
.pdot { width:.55rem;height:.55rem;border-radius:50%;background:#f59e0b;display:inline-block; animation:pd 2s infinite; }
@keyframes pd { 0%,100%{box-shadow:0 0 0 0 rgba(245,158,11,.5)} 60%{box-shadow:0 0 0 5px rgba(245,158,11,0)} }

/* ── Page header banner ── */
.g-banner {
  background:var(--pk-grd);border-radius:var(--radius);
  padding:1.5rem 2rem;margin-bottom:2rem;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;
  box-shadow:var(--shadow);position:relative;overflow:hidden;
}
.g-banner::after {
  content:'';position:absolute;right:-2rem;top:-2rem;
  width:8rem;height:8rem;border-radius:50%;
  background:rgba(255,255,255,.06);
}
.g-banner::before {
  content:'';position:absolute;right:3rem;bottom:-3rem;
  width:12rem;height:12rem;border-radius:50%;
  background:rgba(255,255,255,.04);
}

/* ── Animations ── */
@keyframes fadeUp { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
.fu  { animation:fadeUp .35s ease both; }
.fu2 { animation:fadeUp .35s .07s ease both; }
.fu3 { animation:fadeUp .35s .14s ease both; }
.fu4 { animation:fadeUp .35s .21s ease both; }
</style>

<!-- ═══════════════════════════════════════════════════════ PAGE ═══ -->
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

  <!-- Banner header -->
  <div class="g-banner fu">
    <div class="flex items-center gap-4 relative z-10">
      <div style="background:rgba(255,255,255,.15);border-radius:1rem;padding:.9rem;backdrop-filter:blur(4px)">
        <i class="fas fa-graduation-cap text-3xl text-white"></i>
      </div>
      <div>
        <h1 class="text-2xl font-bold text-white tracking-tight">Graduation Management</h1>
        <p class="text-pink-200 text-sm mt-0.5">Graduate, track and manage student completion status</p>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/index.php?page=admin/dashboard"
       style="background:rgba(255,255,255,.15);color:#fff;border:1.5px solid rgba(255,255,255,.3);backdrop-filter:blur(4px)"
       class="btn-pk relative z-10">
      <i class="fas fa-arrow-left"></i> Dashboard
    </a>
  </div>

  <!-- Flash messages -->
  <?php if ($message): ?>
  <div class="flash-ok fu">
    <i class="fas fa-check-circle text-green-500 text-lg mt-0.5 flex-shrink-0"></i>
    <span><?= htmlspecialchars($message) ?></span>
  </div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="flash-err fu">
    <i class="fas fa-exclamation-circle text-red-500 text-lg mt-0.5 flex-shrink-0"></i>
    <span><?= htmlspecialchars($error) ?></span>
  </div>
  <?php endif; ?>

  <!-- ── Stats row ── -->
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">

    <div class="g-stat fu">
      <div class="flex items-start justify-between mb-3">
        <p class="stat-label">Total</p>
        <div class="stat-icon" style="background:#fdf2f8"><i class="fas fa-users" style="color:var(--pk)"></i></div>
      </div>
      <p class="stat-num"><?= number_format($stats['total_students'] ?? 0) ?></p>
      <p class="stat-sub" style="color:#9ca3af"><i class="fas fa-database fa-xs"></i> All-time enrolment</p>
    </div>

    <div class="g-stat fu2">
      <div class="flex items-start justify-between mb-3">
        <p class="stat-label">Active</p>
        <div class="stat-icon" style="background:#f0fdf4"><i class="fas fa-user-check" style="color:#16a34a"></i></div>
      </div>
      <p class="stat-num"><?= number_format($stats['active_students'] ?? 0) ?></p>
      <p class="stat-sub" style="color:#16a34a"><span style="width:.5rem;height:.5rem;border-radius:50%;background:#22c55e;display:inline-block"></span> Currently studying</p>
    </div>

    <div class="g-stat fu3">
      <div class="flex items-start justify-between mb-3">
        <p class="stat-label">Graduated</p>
        <div class="stat-icon" style="background:#ede9fe"><i class="fas fa-graduation-cap" style="color:#7c3aed"></i></div>
      </div>
      <p class="stat-num"><?= number_format($stats['graduated_students'] ?? 0) ?></p>
      <p class="stat-sub" style="color:#7c3aed"><i class="fas fa-star fa-xs"></i> Alumni</p>
    </div>

    <div class="g-stat fu4">
      <div class="flex items-start justify-between mb-3">
        <div class="flex items-center gap-1.5">
          <p class="stat-label">Pending</p>
          <?php if (($stats['pending_graduation'] ?? 0) > 0): ?>
          <span class="pdot"></span>
          <?php endif; ?>
        </div>
        <div class="stat-icon" style="background:#fefce8"><i class="fas fa-hourglass-half" style="color:#d97706"></i></div>
      </div>
      <p class="stat-num"><?= number_format($stats['pending_graduation'] ?? 0) ?></p>
      <p class="stat-sub" style="color:#d97706"><i class="fas fa-clock fa-xs"></i> Ready to graduate</p>
    </div>

  </div>

  <!-- ── Action tiles ── -->
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-8">

    <!-- Auto graduation -->
    <div class="g-tile fu">
      <div class="flex items-center gap-3 mb-4">
        <div class="g-tile-icon"><i class="fas fa-magic"></i></div>
        <div>
          <h3 class="font-semibold text-gray-900 text-sm leading-tight">Auto Graduation</h3>
          <p class="text-xs text-gray-400 mt-0.5">Processes all eligible students at once</p>
        </div>
      </div>
      <p class="text-xs text-gray-500 mb-4 leading-relaxed">
        Marks every student whose <code class="bg-gray-100 px-1 rounded">graduation_year</code> is
        &le; <?= date('Y') ?> as graduated automatically.
      </p>
      <form method="POST"
            action="<?= htmlspecialchars(rtrim(BASE_URL,'/').'/index.php?page=admin/graduation') ?>"
            onsubmit="return confirm('Graduate ALL eligible students (graduation_year ≤ <?= date('Y') ?>)?\n\nThis cannot be undone automatically.')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="run_graduation">
        <button type="submit" class="btn-pk w-full justify-center">
          <i class="fas fa-play"></i> Run Auto Graduation
        </button>
      </form>
      <?php if (($stats['pending_graduation'] ?? 0) > 0): ?>
      <p class="text-center text-xs mt-2.5" style="color:var(--pk)">
        <i class="fas fa-info-circle mr-1"></i>
        <?= $stats['pending_graduation'] ?> student<?= $stats['pending_graduation'] > 1 ? 's' : '' ?> eligible now
      </p>
      <?php else: ?>
      <p class="text-center text-xs mt-2.5 text-gray-400">
        <i class="fas fa-check-circle mr-1 text-green-500"></i> No students pending
      </p>
      <?php endif; ?>
    </div>

    <!-- Graduate by class -->
    <div class="g-tile fu2">
      <div class="flex items-center gap-3 mb-4">
        <div class="g-tile-icon"><i class="fas fa-layer-group"></i></div>
        <div>
          <h3 class="font-semibold text-gray-900 text-sm leading-tight">Graduate by Class</h3>
          <p class="text-xs text-gray-400 mt-0.5">Graduate an entire cohort in one click</p>
        </div>
      </div>
      <p class="text-xs text-gray-500 mb-4 leading-relaxed">
        Select a graduation year and mark every active student in that cohort as graduated.
      </p>
      <form method="POST"
            action="<?= htmlspecialchars(rtrim(BASE_URL,'/').'/index.php?page=admin/graduation') ?>"
            onsubmit="return confirm('Graduate ALL active students in the selected class year?\n\nThis cannot be undone automatically.')">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
        <input type="hidden" name="action" value="bulk_graduate_by_year">
        <div class="flex gap-2">
          <select name="graduation_year" class="g-input g-select" style="flex:1">
            <?php foreach ($graduationYears as $y): ?>
            <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>>Class of <?= $y ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-pk" style="padding:.42rem 1rem">
            <i class="fas fa-graduation-cap"></i>
          </button>
        </div>
      </form>
    </div>

    <!-- Find student -->
    <div class="g-tile fu3">
      <div class="flex items-center gap-3 mb-4">
        <div class="g-tile-icon"><i class="fas fa-user-magnifying-glass" style="font-size:.9rem"></i></div>
        <div>
          <h3 class="font-semibold text-gray-900 text-sm leading-tight">Find Student</h3>
          <p class="text-xs text-gray-400 mt-0.5">Search by name or student ID</p>
        </div>
      </div>
      <p class="text-xs text-gray-500 mb-4 leading-relaxed">
        Look up any student to graduate or revert them individually.
      </p>
      <div class="flex gap-2">
        <input id="student-search" type="text" class="g-input" placeholder="e.g. GSC250001 or Kofi Mensah…">
        <button onclick="searchStudent()" class="btn-pk" style="padding:.42rem 1rem">
          <i class="fas fa-search"></i>
        </button>
      </div>
      <p class="text-xs text-gray-400 mt-2 text-center">Press Enter or click Search</p>
    </div>

  </div>

  <!-- Search results -->
  <div id="student-search-results" class="mb-8"></div>

  <!-- ── Pending + Recent ── -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    <!-- Pending graduation -->
    <div class="g-list-card fu">
      <div class="g-list-header">
        <div class="flex items-center gap-2">
          <i class="fas fa-hourglass-half text-white text-sm"></i>
          <h3>Pending Graduation</h3>
        </div>
        <?php if (($stats['pending_graduation'] ?? 0) > 0): ?>
        <span class="g-list-badge"><?= $stats['pending_graduation'] ?></span>
        <?php endif; ?>
      </div>
      <div class="g-list-body">
        <?php if (empty($pendingGraduation)): ?>
          <div class="text-center py-10">
            <div style="width:3.5rem;height:3.5rem;background:#f0fdf4;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem">
              <i class="fas fa-check-circle text-green-500 text-xl"></i>
            </div>
            <p class="text-sm text-gray-400 font-medium">All caught up!</p>
            <p class="text-xs text-gray-300 mt-1">No students pending graduation</p>
          </div>
        <?php else: ?>
          <div class="space-y-1.5">
            <?php foreach ($pendingGraduation as $s): ?>
            <div class="s-row">
              <div class="s-avatar">
                <?= strtoupper(mb_substr($s['first_name'],0,1).mb_substr($s['last_name'],0,1)) ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="s-name truncate"><?= htmlspecialchars($s['first_name'].' '.$s['last_name']) ?></p>
                <p class="s-meta">
                  <?= htmlspecialchars($s['id']) ?>
                  &middot; <span class="badge badge-amber">Class of <?= $s['graduation_year'] ?></span>
                </p>
              </div>
              <form method="POST" action="<?= htmlspecialchars(rtrim(BASE_URL,'/').'/index.php?page=admin/graduation') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="graduate_individual">
                <input type="hidden" name="student_id" value="<?= htmlspecialchars($s['id']) ?>">
                <input type="hidden" name="graduation_year" value="<?= $s['graduation_year'] ?>">
                <button type="submit" class="btn-pk">
                  <i class="fas fa-graduation-cap"></i> Graduate
                </button>
              </form>
            </div>
            <?php endforeach; ?>
            <?php if (($stats['pending_graduation'] ?? 0) > count($pendingGraduation)): ?>
            <p class="text-center text-xs text-gray-400 pt-2 pb-1">
              Showing <?= count($pendingGraduation) ?> of <?= $stats['pending_graduation'] ?> —
              use <em>Auto Graduation</em> to process all at once
            </p>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent graduates -->
    <div class="g-list-card fu2">
      <div class="g-list-header">
        <div class="flex items-center gap-2">
          <i class="fas fa-star text-white text-sm"></i>
          <h3>Recent Graduates</h3>
        </div>
        <span class="g-list-badge">Latest 5</span>
      </div>
      <div class="g-list-body">
        <?php if (empty($recentGraduates)): ?>
          <div class="text-center py-10">
            <div style="width:3.5rem;height:3.5rem;background:var(--pk4);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem">
              <i class="fas fa-graduation-cap text-xl" style="color:var(--pk)"></i>
            </div>
            <p class="text-sm text-gray-400 font-medium">No graduates yet</p>
            <p class="text-xs text-gray-300 mt-1">Graduated students will appear here</p>
          </div>
        <?php else: ?>
          <div class="space-y-1.5">
            <?php foreach ($recentGraduates as $g): ?>
            <div class="s-row">
              <div class="s-avatar" style="background:linear-gradient(135deg,#7c3aed,#a855f7)">
                <?= strtoupper(mb_substr($g['first_name'],0,1).mb_substr($g['last_name'],0,1)) ?>
              </div>
              <div class="flex-1 min-w-0">
                <p class="s-name truncate"><?= htmlspecialchars($g['first_name'].' '.$g['last_name']) ?></p>
                <p class="s-meta">
                  <?= htmlspecialchars($g['id']) ?>
                  &middot; <span class="badge badge-purple">Class of <?= $g['graduation_year'] ?></span>
                  &middot; <?= date('d M Y', strtotime($g['graduated_at'])) ?>
                </p>
              </div>
              <form method="POST"
                    action="<?= htmlspecialchars(rtrim(BASE_URL,'/').'/index.php?page=admin/graduation') ?>"
                    onsubmit="return confirm('Revert <?= htmlspecialchars(addslashes($g['first_name'].' '.$g['last_name'])) ?> to active student status?')">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="hidden" name="action" value="revert_to_active">
                <input type="hidden" name="student_id" value="<?= htmlspecialchars($g['id']) ?>">
                <button type="submit" class="btn-out">
                  <i class="fas fa-undo"></i> Revert
                </button>
              </form>
            </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>

<!-- Toast stack -->
<div id="toasts" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2" style="min-width:290px;max-width:360px"></div>

<script>
const CSRF     = document.querySelector('meta[name="csrf-token"]').content;
const BASE_URL = '<?= rtrim(BASE_URL,'/') ?>';

/* ── Toast ── */
function toast(msg, type = 'success') {
    const colors = {
        success: { bg:'#f0fdf4', border:'#22c55e', text:'#166534', icon:'fa-check-circle' },
        error:   { bg:'#fef2f2', border:'#ef4444', text:'#991b1b', icon:'fa-exclamation-circle' },
        info:    { bg:'#eff6ff', border:'#3b82f6', text:'#1e40af', icon:'fa-info-circle' },
    };
    const c = colors[type] || colors.info;
    const el = document.createElement('div');
    el.style.cssText = `background:${c.bg};border-left:4px solid ${c.border};color:${c.text};
        padding:.8rem 1rem;border-radius:.6rem;box-shadow:0 4px 14px rgba(0,0,0,.1);
        display:flex;align-items:flex-start;gap:.6rem;font-size:.85rem;
        animation:toastIn .3s cubic-bezier(.22,1,.36,1) both;`;
    el.innerHTML = `<i class="fas ${c.icon} mt-0.5 flex-shrink-0"></i>
        <span style="flex:1;line-height:1.4">${msg}</span>
        <button onclick="this.parentElement.remove()" style="background:none;border:none;cursor:pointer;opacity:.55;padding:0 0 0 .5rem;font-size:1rem">&times;</button>`;
    document.getElementById('toasts').appendChild(el);
    setTimeout(() => { el.style.opacity='0'; el.style.transform='translateX(20px)'; el.style.transition='all .3s'; setTimeout(()=>el.remove(),300); }, 6000);
}

/* ── Logging ── */
async function logActivity(type, desc, details = {}) {
    try {
        await fetch(BASE_URL + '/api/log-activity.php', {
            method:'POST',
            headers:{'Content-Type':'application/json','X-CSRF-Token':CSRF},
            body:JSON.stringify({ user_id:'<?= htmlspecialchars($_SESSION['user_id'] ?? '') ?>',
                activity_type:type, description:desc, details }),
            keepalive:true
        });
    } catch(_) {}
}

/* ── XSS helper ── */
function esc(t) { const d=document.createElement('div');d.textContent=t;return d.innerHTML; }

/* ── Student search ── */
function searchStudent() {
    const q   = document.getElementById('student-search').value.trim();
    const box = document.getElementById('student-search-results');
    if (q.length < 2) { box.innerHTML=''; return; }

    logActivity('student_search', `Searched: ${q}`);

    box.innerHTML = `<div class="g-list-card"><div class="p-10 text-center">
        <i class="fas fa-spinner fa-spin text-2xl mb-3" style="color:var(--pk)"></i>
        <p class="text-sm text-gray-400">Searching for "<strong>${esc(q)}</strong>"…</p>
    </div></div>`;

    fetch(`${BASE_URL}/api/admin/search-students.php?q=${encodeURIComponent(q)}`,
          { headers:{ 'X-CSRF-Token': CSRF } })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.students.length) {
            box.innerHTML = `<div class="g-list-card"><div class="p-10 text-center">
                <i class="fas fa-search text-3xl mb-3" style="color:var(--pk);opacity:.35"></i>
                <p class="text-sm text-gray-500 font-medium">No students found</p>
                <p class="text-xs text-gray-400 mt-1">Try a different name or ID</p>
            </div></div>`;
            return;
        }
        let rows = data.students.map(s => {
            const init = (s.name||'  ').split(' ').map(w=>w[0]||'').join('').substring(0,2).toUpperCase();
            const isGrad = s.status === 'graduated';
            const statusBadge = isGrad
                ? `<span class="badge badge-purple">graduated</span>`
                : `<span class="badge badge-green">active</span>`;
            const action = !isGrad
                ? `<form method="POST" action="${BASE_URL}/index.php?page=admin/graduation">
                     <input type="hidden" name="csrf_token" value="${CSRF}">
                     <input type="hidden" name="action" value="graduate_individual">
                     <input type="hidden" name="student_id" value="${esc(s.id)}">
                     <input type="hidden" name="graduation_year" value="${s.graduation_year||new Date().getFullYear()}">
                     <button type="submit" class="btn-pk"><i class="fas fa-graduation-cap"></i> Graduate</button>
                   </form>`
                : `<form method="POST" action="${BASE_URL}/index.php?page=admin/graduation"
                        onsubmit="return confirm('Revert ${esc(s.name)} to active?')">
                     <input type="hidden" name="csrf_token" value="${CSRF}">
                     <input type="hidden" name="action" value="revert_to_active">
                     <input type="hidden" name="student_id" value="${esc(s.id)}">
                     <button type="submit" class="btn-out"><i class="fas fa-undo"></i> Revert</button>
                   </form>`;
            return `<div class="s-row">
                <div class="s-avatar" style="${isGrad?'background:linear-gradient(135deg,#7c3aed,#a855f7)':''}">${init}</div>
                <div class="flex-1 min-w-0">
                  <p class="s-name truncate">${esc(s.name)}</p>
                  <p class="s-meta">${esc(s.id)}
                    ${s.graduation_year ? `&middot; <span class="badge badge-amber">Class of ${s.graduation_year}</span>` : ''}
                    &middot; ${statusBadge}
                  </p>
                </div>
                ${action}
            </div>`;
        }).join('');

        box.innerHTML = `<div class="g-list-card">
            <div class="g-list-header">
                <div class="flex items-center gap-2">
                    <i class="fas fa-search text-white text-sm"></i>
                    <h3>Results for "${esc(q)}"</h3>
                </div>
                <span class="g-list-badge">${data.students.length}</span>
            </div>
            <div class="g-list-body"><div class="space-y-1.5">${rows}</div></div>
        </div>`;
    })
    .catch(() => {
        box.innerHTML = `<div class="g-list-card"><div class="p-10 text-center">
            <i class="fas fa-exclamation-triangle text-red-400 text-3xl mb-3"></i>
            <p class="text-sm text-red-500 font-medium">Search failed</p>
            <p class="text-xs text-gray-400 mt-1">Please try again</p>
        </div></div>`;
    });
}

document.getElementById('student-search').addEventListener('keydown', e => { if(e.key==='Enter') searchStudent(); });

document.addEventListener('DOMContentLoaded', () => {
    logActivity('graduation_page_loaded', 'Graduation management page loaded');
    <?php if ($message): ?>
    toast(<?= json_encode(htmlspecialchars_decode($message)) ?>, 'success');
    <?php endif; ?>
    <?php if ($error): ?>
    toast(<?= json_encode($error) ?>, 'error');
    <?php endif; ?>
});

window.addEventListener('beforeunload', () => {
    logActivity('graduation_page_exit', 'Left graduation management page');
});
</script>

<style>
@keyframes toastIn { from{opacity:0;transform:translateX(24px)} to{opacity:1;transform:translateX(0)} }
</style>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
