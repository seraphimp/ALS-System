<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dashboard_functions.php';

secure_session_start();

if (!isset($_SESSION['admin_id']) || !is_admin_logged_in()) {
    redirect('/admin-secure');
    exit;
}

$admin = get_admin_details($conn, $_SESSION['admin_id']);

// ── Token helpers for assessments ──────────────────────────────────────────────
if (!isset($_SESSION['_at']) || !is_array($_SESSION['_at'])) $_SESSION['_at'] = [];

function issue_assessment_token(int $id): string {
    $sid = (string)$id;
    $ex = array_search($sid, $_SESSION['_at'], true);
    if ($ex !== false) return $ex;
    $tok = bin2hex(random_bytes(20));
    $_SESSION['_at'][$tok] = $sid;
    if (count($_SESSION['_at']) > 500) $_SESSION['_at'] = array_slice($_SESSION['_at'], -500, null, true);
    return $tok;
}

function resolve_assessment_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_at'][$token] ?? 0);
}

// Fetch all active strands
$strands = $conn->query("SELECT * FROM learning_strands WHERE status = 'active' ORDER BY strand_number")->fetch_all(MYSQLI_ASSOC);

$success_msg = '';
$error_msg   = '';

// ── CREATE / UPDATE ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_test'])) {
    $strand_id   = (int)$_POST['strand_id'];
    $test_type   = isset($_POST['test_type']) ? trim($_POST['test_type']) : '';
    $title       = trim($_POST['title']);
    $description = trim($_POST['description']);
    $time_limit  = (int)$_POST['time_limit'];
    $max_score   = (float)$_POST['max_score'];
    $edit_id     = (int)(isset($_POST['test_id']) ? $_POST['test_id'] : 0);

    if ($strand_id <= 0) {
        $error_msg = "Please select a learning strand.";
    } elseif (!in_array($test_type, array('pre_test', 'post_test'))) {
        $error_msg = "Please select an assessment type (Pre-Assessment or Post-Assessment).";
    } else {
        if (empty($title)) {
            $strand_row = $conn->query("SELECT strand_number FROM learning_strands WHERE strand_id = " . (int)$strand_id)->fetch_assoc();
            $title = $strand_row['strand_number'] . ' ' . ($test_type === 'pre_test' ? 'Pre-Assessment' : 'Post-Assessment');
        }

        if ($edit_id > 0) {
            $q = $conn->prepare("UPDATE assessments SET strand_id=?, title=?, description=?, time_limit=?, max_score=?, status='active' WHERE assessment_id=? AND created_by=?");
            $q->bind_param("issidii", $strand_id, $title, $description, $time_limit, $max_score, $edit_id, $_SESSION['admin_id']);
            if ($q->execute()) {
                $success_msg = "Assessment updated successfully!";
            } else {
                $error_msg = "Error updating: " . $conn->error;
            }
        } else {
            $check = $conn->prepare("SELECT assessment_id FROM assessments WHERE strand_id=? AND assessment_type=?");
            $check->bind_param("is", $strand_id, $test_type);
            $check->execute();
            $check_result = $check->get_result();

            if ($check_result->num_rows > 0) {
                $error_msg = ucfirst(str_replace('_', ' ', $test_type)) . " already exists for this strand!";
            } else {
                $q = $conn->prepare("INSERT INTO assessments (strand_id, title, description, assessment_type, time_limit, max_score, created_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
                $q->bind_param("isssidi", $strand_id, $title, $description, $test_type, $time_limit, $max_score, $_SESSION['admin_id']);

                if ($q->execute()) {
                    $success_msg = "Assessment created successfully and is now active!";
                } else {
                    $error_msg = "Error creating: " . $conn->error;
                }
            }
        }
    }
}

// ── TOGGLE STATUS ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $assessment_id = (int)$_POST['assessment_id'];
    $new_status    = $_POST['new_status'];
    if (in_array($new_status, array('active', 'draft', 'archived'))) {
        $q = $conn->prepare("UPDATE assessments SET status=? WHERE assessment_id=? AND created_by=?");
        $q->bind_param("sii", $new_status, $assessment_id, $_SESSION['admin_id']);
        if ($q->execute()) {
            $success_msg = "Status updated to " . ucfirst($new_status);
        } else {
            $error_msg = "Error updating status: " . $conn->error;
        }
    }
}

// ── FETCH — system-wide pre/post assessments (all creators) ───────
$tests = array();
$qtbl  = $conn->query("SHOW TABLES LIKE 'assessment_questions'");
$q_join = ($qtbl && $qtbl->num_rows > 0)
    ? "(SELECT COUNT(*) FROM assessment_questions aq WHERE aq.assessment_id = a.assessment_id)"
    : "0";

$result = $conn->query("
    SELECT a.*, ls.strand_number, ls.title as strand_title,
           $q_join as q_count
    FROM assessments a
    JOIN learning_strands ls ON a.strand_id = ls.strand_id
    WHERE a.assessment_type IN ('pre_test', 'post_test')
    ORDER BY ls.strand_number, a.assessment_type
");
if ($result) {
    $tests = $result->fetch_all(MYSQLI_ASSOC);
}

// Build strand pair map  [strand_id => ['pre_test'=>row, 'post_test'=>row]]
$strand_pairs = array();
foreach ($tests as $t) {
    $strand_pairs[$t['strand_id']][$t['assessment_type']] = $t;
}

// Stats
$pre_count       = 0;
$post_count      = 0;
$total_questions = 0;
foreach ($tests as $t) {
    if ($t['assessment_type'] === 'pre_test') $pre_count++;
    else $post_count++;
    $total_questions += (int)(isset($t['q_count']) ? $t['q_count'] : 0);
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pre/Post Assessments - ALS Admin</title>
    <link rel="icon" type="image/png" href="/logo">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; background: #f8fafc; min-height: 100vh; padding: 24px 32px; }

        .back-links { display: flex; gap: 16px; margin-bottom: 24px; }
        .back-link { display: inline-flex; align-items: center; gap: 8px; color: #64748b; text-decoration: none; font-size: 0.9rem; padding: 8px 16px; background: white; border: 1.5px solid #e2e8f0; border-radius: 8px; transition: all 0.2s; }
        .back-link:hover { border-color: #1d4ed8; color: #1d4ed8; background: #eff6ff; }

        .page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; flex-wrap: wrap; gap: 16px; }
        .page-header h1 { font-size: 1.8rem; font-weight: 700; color: #0f172a; }
        .page-badge { background: #dbeafe; color: #1d4ed8; padding: 4px 12px; border-radius: 20px; font-size: 0.8rem; font-weight: 600; }

        .btn-primary { background: #1d4ed8; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: all 0.2s; font-family: inherit; font-size: 0.9rem; }
        .btn-primary:hover { background: #1e3a8a; }

        .alert { padding: 14px 18px; border-radius: 10px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; font-size: 0.9rem; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }

        /* Stats */
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
        .stat-card { background: white; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 18px; }
        .stat-label { font-size: 0.78rem; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: #0f172a; }

        /* Section title */
        .section-title { font-size: 0.9rem; font-weight: 700; color: #334155; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }

        /* Pair Grid — ALL strands */
        .pair-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 16px; margin-bottom: 32px; }
        .pair-card { background: white; border: 1.5px solid #e2e8f0; border-radius: 13px; padding: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.05); }
        .pair-strand-label { font-size: 11.5px; font-weight: 700; color: #0369a1; background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 6px; padding: 4px 12px; display: inline-flex; align-items: center; gap: 6px; margin-bottom: 12px; }
        .pair-slots { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .pair-slot { border-radius: 10px; padding: 13px; display: flex; flex-direction: column; gap: 4px; cursor: pointer; transition: all 0.18s; }
        .pair-slot:hover { transform: translateY(-1px); }
        .pair-slot.pre-has   { background: #eff6ff; border: 1.5px solid #bfdbfe; }
        .pair-slot.post-has  { background: #f0fdf4; border: 1.5px solid #bbf7d0; }
        .pair-slot.pre-empty  { background: #f8fafc; border: 1.5px dashed #bfdbfe; }
        .pair-slot.post-empty { background: #f8fafc; border: 1.5px dashed #bbf7d0; }
        .pair-slot.pre-empty:hover  { background: #eff6ff; border-color: #1d4ed8; border-style: solid; }
        .pair-slot.post-empty:hover { background: #f0fdf4; border-color: #059669; border-style: solid; }
        .ps-type  { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: .6px; }
        .ps-type.pre   { color: #1d4ed8; }
        .ps-type.post  { color: #059669; }
        .ps-type.empty { color: #94a3b8; }
        .ps-title { font-size: 12.5px; font-weight: 600; color: #0f172a; line-height: 1.3; }
        .ps-meta  { font-size: 11px; color: #64748b; }
        .ps-shared { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: #0f766e; background: #ccfbf1; border: 1px solid #99f6e4; padding: 2px 7px; border-radius: 10px; margin-top: 4px; width: fit-content; }
        .pair-footer { margin-top: 10px; padding-top: 10px; border-top: 1px solid #f1f5f9; font-size: 11px; display: flex; align-items: center; gap: 6px; }
        .pair-footer.ok   { color: #059669; }
        .pair-footer.warn { color: #f59e0b; }
        .pair-footer.none { color: #94a3b8; }

        /* Assessment cards */
        .assessments-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; margin-top: 8px; }
        .assessment-card { background: white; border: 1.5px solid #e2e8f0; border-radius: 12px; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
        .assessment-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px -5px rgba(0,0,0,.1); }
        .assessment-card.pre  { border-top: 4px solid #1d4ed8; }
        .assessment-card.post { border-top: 4px solid #059669; }
        .card-header { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 12px; }
        .card-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .pre  .card-icon { background: #dbeafe; color: #1d4ed8; }
        .post .card-icon { background: #d1fae5; color: #059669; }
        .card-info h3    { font-size: 0.93rem; font-weight: 700; color: #0f172a; }
        .card-info .slabel { font-size: 0.78rem; color: #64748b; margin-top: 2px; }
        .card-body { padding: 14px 16px; }
        .status-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
        .status-badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 0.72rem; font-weight: 700; }
        .st-active   { background: #d1fae5; color: #065f46; }
        .st-inactive { background: #fee2e2; color: #b91c1c; }
        .creator-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; font-weight: 600; color: #1d4ed8; background: #dbeafe; border: 1px solid #bfdbfe; padding: 3px 9px; border-radius: 10px; }
        .creator-other { color: #7c3aed; background: #ede9fe; border-color: #ddd6fe; }
        .card-meta { display: flex; gap: 14px; margin-bottom: 12px; }
        .card-meta span { font-size: 0.78rem; color: #64748b; display: flex; align-items: center; gap: 4px; }
        .q-stats { background: #f8fafc; border-radius: 8px; padding: 10px 12px; margin-bottom: 14px; }
        .q-count  { font-size: 1.1rem; font-weight: 700; color: #1d4ed8; }
        .q-shared { font-size: 0.75rem; color: #0f766e; display: flex; align-items: center; gap: 4px; margin-top: 3px; }
        .card-actions { display: flex; gap: 8px; }
        .btn-action { flex: 1; padding: 7px 6px; border-radius: 6px; border: 1.5px solid #e2e8f0; background: white; cursor: pointer; transition: all 0.2s; text-decoration: none; color: #475569; font-size: 0.78rem; display: inline-flex; align-items: center; justify-content: center; gap: 4px; font-family: inherit; }
        .btn-action:hover { background: #f1f5f9; border-color: #94a3b8; }
        .btn-action.locked { opacity: .4; pointer-events: none; }

        /* Empty state */
        .empty-state { grid-column: 1/-1; text-align: center; padding: 52px 32px; background: white; border-radius: 12px; border: 2px dashed #e2e8f0; }
        .empty-state i { font-size: 3rem; color: #94a3b8; margin-bottom: 14px; display: block; }
        .empty-state h3 { color: #475569; margin-bottom: 8px; font-size: 1.1rem; }
        .empty-state p  { color: #64748b; font-size: 0.88rem; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.5); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; padding: 16px; }
        .modal-overlay.open { display: flex; }
        .modal { background: white; border-radius: 16px; padding: 26px; max-width: 520px; width: 100%; max-height: 90vh; overflow-y: auto; }
        .modal-hd { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
        .modal-hd h2 { font-size: 1.15rem; font-weight: 700; color: #0f172a; display: flex; align-items: center; gap: 8px; }
        .modal-close { width: 30px; height: 30px; border-radius: 7px; border: 1.5px solid #e2e8f0; background: white; color: #64748b; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 12px; transition: all .15s; }
        .modal-close:hover { border-color: #ef4444; color: #ef4444; background: #fee2e2; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 0.78rem; font-weight: 700; color: #475569; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px 12px; border: 1.5px solid #e2e8f0; border-radius: 8px; font-family: inherit; font-size: 0.9rem; color: #0f172a; transition: border-color .2s, box-shadow .2s; }
        .form-control:focus { outline: none; border-color: #1d4ed8; box-shadow: 0 0 0 3px rgba(29,78,216,.08); }
        textarea.form-control { resize: vertical; min-height: 76px; }

        /* Type selector */
        .type-selector { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
        .type-opt { border: 2px solid #e2e8f0; border-radius: 10px; padding: 14px 10px; text-align: center; cursor: pointer; transition: all 0.2s; user-select: none; }
        .type-opt:hover { border-color: #94a3b8; background: #f8fafc; }
        .type-opt.sel-pre  { border-color: #1d4ed8 !important; background: #eff6ff !important; }
        .type-opt.sel-post { border-color: #059669 !important; background: #f0fdf4 !important; }
        .type-opt i { font-size: 1.4rem; margin-bottom: 6px; display: block; }
        .sel-pre  i { color: #1d4ed8; }
        .sel-post i { color: #059669; }
        .opt-label { font-weight: 700; font-size: 0.88rem; color: #334155; }
        .opt-sub   { font-size: 0.72rem; color: #64748b; margin-top: 2px; }

        .info-note { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 10px 12px; margin-bottom: 16px; font-size: 0.8rem; color: #1e40af; display: flex; align-items: flex-start; gap: 8px; line-height: 1.5; }
        .info-note.warn { background: #fef3c7; border-color: #fcd34d; color: #92400e; }
        .info-note i { flex-shrink: 0; margin-top: 1px; }

        .modal-actions { display: flex; gap: 10px; margin-top: 20px; }
        .btn-cancel { flex: 1; padding: 10px; border: 1.5px solid #e2e8f0; background: white; border-radius: 8px; cursor: pointer; font-weight: 500; font-family: inherit; font-size: 0.9rem; }
        .btn-cancel:hover { background: #f8fafc; }
        .btn-save { flex: 2; padding: 10px; background: #1d4ed8; color: white; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; font-family: inherit; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 6px; }
        .btn-save:hover { background: #1e3a8a; }
        .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    </style>
</head>
<body>
    <div class="back-links">
        <a href="/AdminDashboard" class="back-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
        <a href="/AdminManages" class="back-link"><i class="fas fa-arrow-left"></i> Back to Manage</a>
    </div>

    <div class="page-header">
        <div>
            <h1>Pre/Post Assessment Management</h1>
            <span class="page-badge">System-Wide</span>
        </div>
        <button class="btn-primary" onclick="openModal()">
            <i class="fas fa-plus"></i> Create New Assessment
        </button>
    </div>

    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_msg; ?></div>
    <?php endif; ?>
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Pre-Assessments</div><div class="stat-value"><?php echo $pre_count; ?></div></div>
        <div class="stat-card"><div class="stat-label">Post-Assessments</div><div class="stat-value"><?php echo $post_count; ?></div></div>
        <div class="stat-card"><div class="stat-label">Total Questions</div><div class="stat-value"><?php echo $total_questions; ?></div></div>
        <div class="stat-card"><div class="stat-label">Active Strands</div><div class="stat-value"><?php echo count($strands); ?></div></div>
    </div>

    <!-- ── PAIR GRID: ALL strands shown, including new ones with no assessments yet ── -->
    <div class="section-title">
        <i class="fas fa-link" style="color:#14b8a6"></i>
        Assessment Pairs by Strand
        <span style="background:#f0fdf4;color:#059669;font-size:11px;font-weight:700;padding:2px 8px;border-radius:8px;"><?php echo count($strands); ?> Strands</span>
    </div>
    <div class="pair-grid">
        <?php if (empty($strands)): ?>
            <div class="empty-state">
                <i class="fas fa-layer-group"></i>
                <h3>No Active Strands Found</h3>
                <p>Add learning strands first before creating assessments.</p>
            </div>
        <?php else: ?>
            <?php foreach ($strands as $s):
                $sid  = (int)$s['strand_id'];
                $pre  = isset($strand_pairs[$sid]['pre_test'])  ? $strand_pairs[$sid]['pre_test']  : null;
                $post = isset($strand_pairs[$sid]['post_test']) ? $strand_pairs[$sid]['post_test'] : null;
            ?>
            <div class="pair-card">
                <div class="pair-strand-label">
                    <i class="fas fa-layer-group" style="font-size:10px"></i>
                    <?php echo htmlspecialchars($s['strand_number'] . ' — ' . mb_substr($s['title'], 0, 38)); ?>
                </div>
                <div class="pair-slots">
                    <!-- PRE -->
                    <?php if ($pre): 
                        $pre_token = issue_assessment_token($pre['assessment_id']);
                    ?>
                    <div class="pair-slot pre-has" onclick="location.href='/AdminQuestions?<?= $pre_token ?>'">
                        <div class="ps-type pre">🏁 Pre-Assessment</div>
                        <div class="ps-title"><?php echo htmlspecialchars(mb_substr($pre['title'], 0, 30)); ?></div>
                        <div class="ps-meta"><?php echo (int)(isset($pre['q_count']) ? $pre['q_count'] : 0); ?> questions · <?php echo $pre['time_limit'] > 0 ? $pre['time_limit'].'min' : 'No limit'; ?></div>
                        <div class="ps-shared"><i class="fas fa-link" style="font-size:8px"></i> Shared Q&A</div>
                    </div>
                    <?php else: ?>
                    <div class="pair-slot pre-empty" onclick="openModal('pre_test', <?php echo $sid; ?>)">
                        <div class="ps-type empty">🏁 Pre-Assessment</div>
                        <div class="ps-title" style="color:#94a3b8;font-size:12px">+ Create Pre-Assessment</div>
                        <div class="ps-meta" style="color:#cbd5e1">Not created yet</div>
                    </div>
                    <?php endif; ?>

                    <!-- POST -->
                    <?php if ($post): 
                        $post_token = issue_assessment_token($post['assessment_id']);
                    ?>
                    <div class="pair-slot post-has" onclick="location.href='/AdminQuestions?<?= $post_token ?>'">
                        <div class="ps-type post">✅ Post-Assessment</div>
                        <div class="ps-title"><?php echo htmlspecialchars(mb_substr($post['title'], 0, 30)); ?></div>
                        <div class="ps-meta"><?php echo (int)(isset($post['q_count']) ? $post['q_count'] : 0); ?> questions</div>
                        <div class="ps-shared"><i class="fas fa-link" style="font-size:8px"></i> Shared Q&A</div>
                    </div>
                    <?php else: ?>
                    <div class="pair-slot post-empty" onclick="openModal('post_test', <?php echo $sid; ?>)">
                        <div class="ps-type empty">✅ Post-Assessment</div>
                        <div class="ps-title" style="color:#94a3b8;font-size:12px">+ Create Post-Assessment</div>
                        <div class="ps-meta" style="color:#cbd5e1">Not created yet</div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php if ($pre && $post): ?>
                    <div class="pair-footer ok"><i class="fas fa-check-circle"></i> Both active — visible to all teachers</div>
                <?php elseif (!$pre && !$post): ?>
                    <div class="pair-footer none"><i class="fas fa-circle"></i> No assessments created yet</div>
                <?php else: ?>
                    <div class="pair-footer warn"><i class="fas fa-exclamation-triangle"></i> Only <?php echo $pre ? 'pre' : 'post'; ?>-assessment created — consider adding the other</div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- All Assessments List -->
    <div class="section-title">
        <i class="fas fa-list-alt" style="color:#7c3aed"></i>
        All Pre/Post Assessments
        <span style="background:#ede9fe;color:#7c3aed;font-size:11px;font-weight:700;padding:2px 8px;border-radius:8px;"><?php echo count($tests); ?></span>
    </div>
    <div class="assessments-grid">
        <?php if (empty($tests)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No Assessments Created Yet</h3>
                <p>Click "Create New Assessment" or click any strand slot above to get started.</p>
            </div>
        <?php else: ?>
            <?php foreach ($tests as $test):
                $is_own = ((int)$test['created_by'] === (int)$_SESSION['admin_id']);
                $test_token = issue_assessment_token($test['assessment_id']);
                $distribute_token = issue_assessment_token($test['assessment_id']);
            ?>
            <div class="assessment-card <?php echo $test['assessment_type'] === 'pre_test' ? 'pre' : 'post'; ?>">
                <div class="card-header">
                    <div class="card-icon">
                        <i class="fas <?php echo $test['assessment_type'] === 'pre_test' ? 'fa-flag-checkered' : 'fa-check-double'; ?>"></i>
                    </div>
                    <div class="card-info">
                        <h3><?php echo htmlspecialchars($test['title']); ?></h3>
                        <div class="slabel"><?php echo htmlspecialchars($test['strand_number'] . ' — ' . $test['strand_title']); ?></div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="status-row">
                        <span class="status-badge <?php echo $test['status'] === 'active' ? 'st-active' : 'st-inactive'; ?>">
                            <i class="fas <?php echo $test['status'] === 'active' ? 'fa-check-circle' : 'fa-pause-circle'; ?>"></i>
                            <?php echo ucfirst($test['status']); ?>
                        </span>
                        <span class="creator-badge <?php echo $is_own ? '' : 'creator-other'; ?>">
                            <i class="fas <?php echo $is_own ? 'fa-user-shield' : 'fa-user'; ?>"></i>
                            <?php echo $is_own ? 'Created by you' : 'Other admin'; ?>
                        </span>
                    </div>
                    <div class="card-meta">
                        <span><i class="far fa-clock"></i> <?php echo (int)$test['time_limit']; ?> min</span>
                        <span><i class="fas fa-star"></i> <?php echo (float)$test['max_score']; ?> pts</span>
                    </div>
                    <div class="q-stats">
                        <div class="q-count"><?php echo (int)(isset($test['q_count']) ? $test['q_count'] : 0); ?> Questions</div>
                        <div class="q-shared">
                            <i class="fas fa-link"></i>
                            <?php echo $test['assessment_type'] === 'pre_test' ? 'Shared with Post-Assessment' : 'Shared with Pre-Assessment'; ?>
                        </div>
                    </div>
                    <div class="card-actions">
                        <a href="/AdminQuestions?<?= $test_token ?>" class="btn-action">
                            <i class="fas fa-list-ol"></i> Questions
                        </a>
                        <?php if ($is_own): ?>
                            <button class="btn-action" onclick='editAssessment(<?php echo json_encode($test); ?>)'>
                                <i class="fas fa-edit"></i> Edit
                            </button>
                        <?php else: ?>
                            <button class="btn-action locked"><i class="fas fa-lock"></i> Edit</button>
                        <?php endif; ?>
                        <a href="/AdminDistributes?<?= $distribute_token ?>" class="btn-action">
                            <i class="fas fa-share-alt"></i> Distribute
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- ── MODAL ── -->
    <div class="modal-overlay" id="assessModal">
        <div class="modal">
            <div class="modal-hd">
                <h2 id="modalTitle"><i class="fas fa-plus-circle" style="color:#1d4ed8"></i> Create New Assessment</h2>
                <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
            </div>
            <form method="POST" id="assessForm">
                <input type="hidden" name="save_test" value="1">
                <input type="hidden" name="test_id" id="editId" value="0">
                <input type="hidden" name="test_type" id="testType" value="pre_test">

                <div class="info-note" id="modalNote">
                    <i class="fas fa-info-circle"></i>
                    <span>Pre/Post assessments are automatically <strong>active</strong> once created and instantly visible to all teachers.</span>
                </div>

                <!-- Assessment Type -->
                <div class="form-group">
                    <label>Assessment Type <span style="color:#ef4444">*</span></label>
                    <div class="type-selector">
                        <div class="type-opt sel-pre" id="opt-pre" onclick="selectType('pre_test')">
                            <i class="fas fa-flag-checkered"></i>
                            <div class="opt-label">Pre-Assessment</div>
                            <div class="opt-sub">Given before the lesson</div>
                        </div>
                        <div class="type-opt" id="opt-post" onclick="selectType('post_test')">
                            <i class="fas fa-check-double"></i>
                            <div class="opt-label">Post-Assessment</div>
                            <div class="opt-sub">Given after the lesson</div>
                        </div>
                    </div>
                </div>

                <!-- Strand -->
                <div class="form-group">
                    <label>Learning Strand <span style="color:#ef4444">*</span></label>
                    <select name="strand_id" id="fStrand" class="form-control" required onchange="checkConflict()">
                        <option value="">— Select Strand —</option>
                        <?php foreach ($strands as $s): ?>
                            <option value="<?php echo $s['strand_id']; ?>">
                                <?php echo htmlspecialchars($s['strand_number'] . ' — ' . $s['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Title -->
                <div class="form-group">
                    <label>Title <span style="font-weight:400;color:#94a3b8">(auto-generated if blank)</span></label>
                    <input type="text" name="title" id="fTitle" class="form-control" placeholder="e.g. LS1 Pre-Assessment">
                </div>

                <!-- Description -->
                <div class="form-group">
                    <label>Description / Instructions</label>
                    <textarea name="description" id="fDesc" class="form-control" placeholder="Enter instructions for students…"></textarea>
                </div>

                <!-- Time & Score -->
                <div class="two-col">
                    <div class="form-group">
                        <label>Time Limit (minutes)</label>
                        <input type="number" name="time_limit" id="fTime" class="form-control" value="30" min="0" max="300">
                    </div>
                    <div class="form-group">
                        <label>Max Score</label>
                        <input type="number" name="max_score" id="fScore" class="form-control" value="100" min="1" step="0.5">
                    </div>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn-save" id="submitBtn">
                        <i class="fas fa-save"></i> Save Assessment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // ── Conflict map: existing pairs per strand ─────────────────
        var existingPairs = {};
        <?php foreach ($tests as $t): ?>
        if (!existingPairs[<?php echo (int)$t['strand_id']; ?>]) {
            existingPairs[<?php echo (int)$t['strand_id']; ?>] = {};
        }
        existingPairs[<?php echo (int)$t['strand_id']; ?>]['<?php echo $t['assessment_type']; ?>'] = true;
        <?php endforeach; ?>

        var modal = document.getElementById('assessModal');

        function selectType(type) {
            document.getElementById('testType').value = type;
            document.getElementById('opt-pre').classList.remove('sel-pre');
            document.getElementById('opt-post').classList.remove('sel-post');
            if (type === 'pre_test') {
                document.getElementById('opt-pre').classList.add('sel-pre');
            } else {
                document.getElementById('opt-post').classList.add('sel-post');
            }
            checkConflict();
        }

        function resetNote() {
            var n = document.getElementById('modalNote');
            n.className = 'info-note';
            n.innerHTML = '<i class="fas fa-info-circle"></i>' +
                '<span>Pre/Post assessments are automatically <strong>active</strong> once created and instantly visible to all teachers.</span>';
        }

        function checkConflict() {
            var type     = document.getElementById('testType').value;
            var strandId = document.getElementById('fStrand').value;
            var editId   = document.getElementById('editId').value;
            if (editId && editId !== '0') { resetNote(); return; }

            var n = document.getElementById('modalNote');
            if (strandId && existingPairs[strandId] && existingPairs[strandId][type]) {
                n.className = 'info-note warn';
                n.innerHTML = '<i class="fas fa-exclamation-triangle"></i>' +
                    '<span><strong>Warning:</strong> A ' +
                    (type === 'pre_test' ? 'Pre-Assessment' : 'Post-Assessment') +
                    ' already exists for this strand. Saving will be blocked.</span>';
            } else {
                resetNote();
            }
        }

        function openModal(presetType, presetStrand) {
            presetType   = presetType   || 'pre_test';
            presetStrand = presetStrand || '';

            document.getElementById('assessForm').reset();
            document.getElementById('editId').value = '0';
            document.getElementById('opt-pre').style.pointerEvents = '';
            document.getElementById('opt-post').style.pointerEvents = '';
            document.getElementById('opt-pre').style.opacity = '';
            document.getElementById('opt-post').style.opacity = '';

            selectType(presetType);

            if (presetStrand) {
                document.getElementById('fStrand').value = presetStrand;
                checkConflict();
            } else {
                resetNote();
            }

            document.getElementById('modalTitle').innerHTML =
                '<i class="fas fa-plus-circle" style="color:#1d4ed8"></i> Create New Assessment';
            document.getElementById('submitBtn').innerHTML =
                '<i class="fas fa-save"></i> Save Assessment';

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.classList.remove('open');
            document.body.style.overflow = '';
            document.getElementById('opt-pre').style.pointerEvents = '';
            document.getElementById('opt-post').style.pointerEvents = '';
            document.getElementById('opt-pre').style.opacity = '';
            document.getElementById('opt-post').style.opacity = '';
        }

        function editAssessment(data) {
            document.getElementById('editId').value   = data.assessment_id;
            document.getElementById('fTitle').value   = data.title        || '';
            document.getElementById('fDesc').value    = data.description  || '';
            document.getElementById('fTime').value    = data.time_limit   || 30;
            document.getElementById('fScore').value   = data.max_score    || 100;
            document.getElementById('fStrand').value  = data.strand_id;
            selectType(data.assessment_type);

            document.getElementById('opt-pre').style.pointerEvents = 'none';
            document.getElementById('opt-post').style.pointerEvents = 'none';
            document.getElementById('opt-pre').style.opacity = '0.55';
            document.getElementById('opt-post').style.opacity = '0.55';

            document.getElementById('modalTitle').innerHTML =
                '<i class="fas fa-edit" style="color:#1d4ed8"></i> Edit Assessment';
            document.getElementById('submitBtn').innerHTML =
                '<i class="fas fa-save"></i> Update Assessment';

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
        document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeModal(); });

        setTimeout(function() {
            var alerts = document.querySelectorAll('.alert');
            for (var i = 0; i < alerts.length; i++) {
                (function(el) {
                    el.style.transition = 'opacity .5s';
                    el.style.opacity    = '0';
                    setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
                })(alerts[i]);
            }
        }, 5000);
    </script>
</body>
</html>
<?php ob_end_flush(); ?>