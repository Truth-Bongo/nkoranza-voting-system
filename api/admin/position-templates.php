<?php
// api/admin/position-templates.php
// Manage reusable position templates that exist independently of any election.
// Admins define positions once here, then apply them to any election in one click.

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/ActivityLogger.php';

function jsend($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

try {
    $db = Database::getInstance()->getConnection();
    $activityLogger = new ActivityLogger($db);
} catch (Exception $e) {
    jsend(['success'=>false,'message'=>'Database connection failed'], 500);
}

// All actions require admin
if (empty($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    jsend(['success'=>false,'message'=>'Forbidden: admin only'], 403);
}

$_adminId   = $_SESSION['user_id'] ?? 'system';
$_adminName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Admin';
$method     = $_SERVER['REQUEST_METHOD'];

// CSRF for write operations
if ($method !== 'GET') {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $token   = $headers['X-CSRF-Token'] ?? $_REQUEST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !$token || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsend(['success'=>false,'message'=>'Invalid CSRF token'], 403);
    }
}

function get_json() {
    $arr = json_decode(file_get_contents('php://input'), true);
    return is_array($arr) ? $arr : null;
}

// ── Ensure table exists (auto-create if missing) ─────────────────────
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS position_templates (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            name        VARCHAR(100) NOT NULL,
            description TEXT NULL,
            category    VARCHAR(100) NULL,
            max_votes   INT NOT NULL DEFAULT 1,
            created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_name (name)
        )
    ");
    // Seed default positions if table is empty
    $count = (int)$db->query("SELECT COUNT(*) FROM position_templates")->fetchColumn();
    if ($count === 0) {
        $db->exec("
            INSERT IGNORE INTO position_templates (name, category, description) VALUES
                -- Senior Prefects
                ('Senior Prefect (Boys)',             'Senior Prefects',       'Head boy responsible for student leadership'),
                ('Senior Prefect (Girls)',            'Senior Prefects',       'Head girl responsible for student leadership'),
                -- Sports Prefects
                ('Sports Prefect (Boys)',             'Sports Prefects',       'Coordinates sports activities for boys'),
                ('Sports Prefect (Girls)',            'Sports Prefects',       'Coordinates sports activities for girls'),
                -- Health Prefects
                ('Health Prefect (Boys)',             'Health Prefects',       'Promotes health and hygiene among boys'),
                ('Health Prefect (Girls)',            'Health Prefects',       'Promotes health and hygiene among girls'),
                -- Entertainment Prefects
                ('Entertainment Prefect (Boys)',      'Entertainment Prefects','Organizes entertainment activities (Boys)'),
                ('Entertainment Prefect (Girls)',     'Entertainment Prefects','Organizes entertainment activities (Girls)'),
                -- Dining Hall Prefects
                ('Dining Hall Prefect (Boys)',        'Dining Hall Prefects',  'Responsible for managing dining hall affairs (Boys)'),
                ('Dining Hall Prefect (Girls)',       'Dining Hall Prefects',  'Responsible for managing dining hall affairs (Girls)'),
                -- Library Prefects
                ('Library Prefect (Boys)',            'Library Prefects',      'Manages library resources and maintains quiet study environment (Boys)'),
                ('Library Prefect (Girls)',           'Library Prefects',      'Manages library resources and maintains quiet study environment (Girls)'),
                -- Compound Overseers
                ('Compound Overseer (Boys)',          'Compound Overseers',    'Maintains school compound cleanliness (Boys)'),
                ('Compound Overseer (Girls)',         'Compound Overseers',    'Maintains school compound cleanliness (Girls)'),
                -- Class Prefects
                ('Class Prefect (Main)',              'Class Prefects',        'Main class representative responsible for class discipline and welfare'),
                ('Class Prefect (Assistant)',         'Class Prefects',        'Assists the main class prefect in duties'),
                -- Dormitory Prefects
                ('Dormitory Prefect (Boys)',          'Dormitory Prefects',    'Maintains order and discipline in boys dormitory'),
                ('Dormitory Prefect (Girls)',         'Dormitory Prefects',    'Maintains order and discipline in girls dormitory'),
                -- SRC
                ('SRC President',                    'SRC',                   'Student Representative Council President'),
                ('SRC Vice President',               'SRC',                   'Student Representative Council Vice President'),
                ('SRC Secretary',                    'SRC',                   'Student Representative Council Secretary'),
                ('SRC Assistant Secretary',          'SRC',                   'Assists the SRC Secretary'),
                ('SRC Financial Secretary',          'SRC',                   'Manages SRC funds and financial records'),
                ('SRC Treasurer',                    'SRC',                   'Responsible for SRC treasury and accounts'),
                ('SRC Welfare Officer',              'SRC',                   'Oversees student welfare and complaints'),
                -- Sanitation & Environment
                ('Sanitation Prefect (Boys)',         'Sanitation Prefects',   'Ensures cleanliness and proper sanitation (Boys)'),
                ('Sanitation Prefect (Girls)',        'Sanitation Prefects',   'Ensures cleanliness and proper sanitation (Girls)'),
                -- Chapel / Worship
                ('Chapel Prefect (Boys)',             'Chapel Prefects',       'Coordinates chapel and devotional activities (Boys)'),
                ('Chapel Prefect (Girls)',            'Chapel Prefects',       'Coordinates chapel and devotional activities (Girls)'),
                -- Academics
                ('Academic Prefect (Boys)',           'Academic Prefects',     'Promotes academic excellence and study culture (Boys)'),
                ('Academic Prefect (Girls)',          'Academic Prefects',     'Promotes academic excellence and study culture (Girls)')
        ");
    }
} catch (Exception $e) {
    error_log('position-templates table init: ' . $e->getMessage());
}

// ── GET: list all templates ───────────────────────────────────────────
if ($method === 'GET') {
    try {
        $stmt = $db->query("
            SELECT id, name, description, category, max_votes, created_at
            FROM position_templates
            ORDER BY category ASC, name ASC
        ");
        jsend(['success'=>true, 'templates'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        error_log('position-templates GET: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Failed to load templates'], 500);
    }
}

// ── POST: create template OR apply templates to election ──────────────
if ($method === 'POST') {
    $input  = get_json() ?? $_POST;
    $action = $input['action'] ?? 'create';

    // ── apply: copy selected templates into a real election ───────────
    if ($action === 'apply') {
        $election_id  = isset($input['election_id'])  ? (int)$input['election_id']                    : 0;
        $template_ids = isset($input['template_ids']) ? array_map('intval', $input['template_ids'])   : [];

        if (!$election_id || empty($template_ids)) {
            jsend(['success'=>false,'message'=>'election_id and template_ids are required'], 400);
        }

        $elStmt = $db->prepare("SELECT title FROM elections WHERE id = ?");
        $elStmt->execute([$election_id]);
        $election = $elStmt->fetch(PDO::FETCH_ASSOC);
        if (!$election) jsend(['success'=>false,'message'=>'Election not found'], 404);

        try {
            $db->beginTransaction();

            $tplStmt = $db->prepare("SELECT * FROM position_templates WHERE id = ?");
            $insStmt = $db->prepare("
                INSERT IGNORE INTO positions (name, election_id, description, category)
                VALUES (:name, :election_id, :description, :category)
            ");

            $created = 0; $skipped = 0;
            foreach ($template_ids as $tid) {
                $tplStmt->execute([$tid]);
                $tpl = $tplStmt->fetch(PDO::FETCH_ASSOC);
                if (!$tpl) continue;
                $insStmt->execute([
                    ':name'        => $tpl['name'],
                    ':election_id' => $election_id,
                    ':description' => $tpl['description'],
                    ':category'    => $tpl['category'],
                ]);
                if ($insStmt->rowCount() > 0) { $created++; } else { $skipped++; }
            }

            $activityLogger->logActivity(
                $_adminId, $_adminName, 'positions_applied_from_template',
                "Applied $created position templates to election: {$election['title']}",
                json_encode(['election_id'=>$election_id,'template_ids'=>$template_ids,'created'=>$created,'skipped'=>$skipped])
            );

            $db->commit();
            jsend([
                'success' => true,
                'message' => "$created position(s) added to the election" . ($skipped ? " ($skipped already existed)" : ''),
                'created' => $created,
                'skipped' => $skipped,
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            error_log('position-templates apply: ' . $e->getMessage());
            jsend(['success'=>false,'message'=>'Failed to apply templates'], 500);
        }
    }

    // ── create: add a new template ────────────────────────────────────
    $name      = trim($input['name'] ?? '');
    $desc      = trim($input['description'] ?? '');
    $category  = trim($input['category'] ?? '');
    $max_votes = isset($input['max_votes']) ? (int)$input['max_votes'] : 1;

    if (!$name) jsend(['success'=>false,'message'=>'name is required'], 400);

    try {
        $stmt = $db->prepare("
            INSERT INTO position_templates (name, description, category, max_votes)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$name, $desc ?: null, $category ?: null, $max_votes]);
        $newId = $db->lastInsertId();

        $activityLogger->logActivity(
            $_adminId, $_adminName, 'position_template_created',
            "Created position template: $name",
            json_encode(['id'=>$newId,'name'=>$name,'category'=>$category])
        );
        jsend(['success'=>true,'message'=>"Template '$name' created",'id'=>$newId]);
    } catch (Exception $e) {
        error_log('position-templates POST: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Failed to create template'], 500);
    }
}

// ── PUT: update template ──────────────────────────────────────────────
if ($method === 'PUT') {
    $input = get_json();
    if (!$input) jsend(['success'=>false,'message'=>'Invalid JSON'], 400);

    $id        = isset($input['id'])        ? (int)$input['id']        : 0;
    $name      = trim($input['name']        ?? '');
    $desc      = trim($input['description'] ?? '');
    $category  = trim($input['category']    ?? '');
    $max_votes = isset($input['max_votes']) ? (int)$input['max_votes'] : 1;

    if (!$id || !$name) jsend(['success'=>false,'message'=>'id and name required'], 400);

    try {
        $stmt = $db->prepare("
            UPDATE position_templates
            SET name=?, description=?, category=?, max_votes=?, updated_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$name, $desc ?: null, $category ?: null, $max_votes, $id]);

        $activityLogger->logActivity(
            $_adminId, $_adminName, 'position_template_updated',
            "Updated position template: $name",
            json_encode(['id'=>$id,'name'=>$name])
        );
        jsend(['success'=>true,'message'=>'Template updated']);
    } catch (Exception $e) {
        error_log('position-templates PUT: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Failed to update template'], 500);
    }
}

// ── DELETE: remove template ───────────────────────────────────────────
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$id) jsend(['success'=>false,'message'=>'id required'], 400);

    try {
        $nameStmt = $db->prepare("SELECT name FROM position_templates WHERE id = ?");
        $nameStmt->execute([$id]);
        $row = $nameStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) jsend(['success'=>false,'message'=>'Template not found'], 404);

        $db->prepare("DELETE FROM position_templates WHERE id = ?")->execute([$id]);

        $activityLogger->logActivity(
            $_adminId, $_adminName, 'position_template_deleted',
            "Deleted position template: {$row['name']}",
            json_encode(['id'=>$id,'name'=>$row['name']])
        );
        jsend(['success'=>true,'message'=>'Template deleted']);
    } catch (Exception $e) {
        error_log('position-templates DELETE: ' . $e->getMessage());
        jsend(['success'=>false,'message'=>'Failed to delete template'], 500);
    }
}

jsend(['success'=>false,'message'=>'Method not allowed'], 405);
