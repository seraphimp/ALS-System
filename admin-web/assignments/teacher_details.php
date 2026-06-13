<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dashboard_functions.php';

secure_session_start();

if (!isset($_SESSION['admin_id']) || !is_admin_logged_in()) {
    redirect('index.php'); exit;
}

$admin = get_admin_details($conn, $_SESSION['admin_id']);

// ── Token helpers ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['_tt']) || !is_array($_SESSION['_tt'])) $_SESSION['_tt'] = [];
if (!isset($_SESSION['_st']) || !is_array($_SESSION['_st'])) $_SESSION['_st'] = [];

function issue_teacher_token(int $id): string {
    $sid = (string)$id;
    $ex  = array_search($sid, $_SESSION['_tt'], true);
    if ($ex !== false) return $ex;
    $tok = bin2hex(random_bytes(20));
    $_SESSION['_tt'][$tok] = $sid;
    if (count($_SESSION['_tt']) > 500) $_SESSION['_tt'] = array_slice($_SESSION['_tt'], -500, null, true);
    return $tok;
}

function resolve_teacher_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_tt'][$token] ?? 0);
}

function issue_student_token(string $numeric_id): string {
    $ex = array_search($numeric_id, $_SESSION['_st'], true);
    if ($ex !== false) return $ex;
    $tok = bin2hex(random_bytes(20));
    $_SESSION['_st'][$tok] = $numeric_id;
    if (count($_SESSION['_st']) > 500) $_SESSION['_st'] = array_slice($_SESSION['_st'], -500, null, true);
    return $tok;
}

function resolve_student_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_st'][$token] ?? 0);
}

// ── Resolve teacher token from query string ────────────────────────────────────
$raw = trim($_SERVER['QUERY_STRING'] ?? '');
if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));
if (empty($raw) && isset($_GET['_t'])) $raw = trim($_GET['_t']);

if (empty($raw)) {
    redirect('/AdminTeacherMonitor'); exit;
}

$teacher_id = resolve_teacher_token($raw);
if (!$teacher_id) {
    $_SESSION['error'] = "Invalid or expired link.";
    redirect('/AdminTeacherMonitor'); exit;
}

// ── Handle marking student as complete ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_complete']) && isset($_POST['student_id'])) {
    $student_id = intval($_POST['student_id']);
    
    // Update student status to 'completed'
    $stmt = $conn->prepare("UPDATE students SET status = 'completed', completion_date = NOW() WHERE id = ? AND teacher_id = ?");
    $stmt->bind_param("ii", $student_id, $teacher_id);
    if ($stmt->execute()) {
        $_SESSION['success'] = "Student marked as complete successfully!";
    } else {
        $_SESSION['error'] = "Failed to mark student as complete.";
    }
    $stmt->close();
    
    // Redirect to avoid form resubmission
    header("Location: " . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Fetch teacher ──────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT t.*, tc.username, tc.last_login, b.name as barangay_name
     FROM teachers t
     LEFT JOIN teacher_credentials tc ON t.teacher_id = tc.teacher_id
     LEFT JOIN barangays b ON t.barangay_id = b.barangay_id
     WHERE t.teacher_id = ?"
);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$teacher) {
    redirect('/AdminTeacherMonitor'); exit;
}

// ── Students (Active & Enrolled) ───────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT s.id, s.student_id, s.first_name, s.last_name, s.middle_name,
            s.status, s.enrollment_date, s.lrn, s.completion_date,
            COALESCE(ls.strand_number, '') as strand_number,
            COALESCE(ls.title, '') as strand_name,
            COUNT(DISTINCT act.activity_id) as total_activities,
            COUNT(DISTINCT sub.submission_id) as submitted_activities,
            ROUND(AVG(CASE WHEN sub.score IS NOT NULL THEN sub.score END), 2) as average_score
     FROM students s
     LEFT JOIN student_strands ss ON s.id = ss.student_id
     LEFT JOIN learning_strands ls ON ss.strand_id = ls.strand_id
     LEFT JOIN activities act ON act.strand_id = ls.strand_id AND act.status = 'published'
     LEFT JOIN activity_submissions sub ON sub.activity_id = act.activity_id AND sub.student_id = s.id
     WHERE s.teacher_id = ? AND s.status IN ('active', 'enrolled')
     GROUP BY s.id, s.student_id, s.first_name, s.last_name, s.middle_name, s.status, s.enrollment_date, s.lrn, s.completion_date, ls.strand_number, ls.title
     ORDER BY s.last_name, s.first_name"
);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Completed/Archived Students ───────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT s.id, s.student_id, s.first_name, s.last_name, s.middle_name,
            s.status, s.enrollment_date, s.lrn, s.completion_date,
            COALESCE(ls.strand_number, '') as strand_number,
            COALESCE(ls.title, '') as strand_name,
            COUNT(DISTINCT sub.submission_id) as submitted_activities,
            ROUND(AVG(CASE WHEN sub.score IS NOT NULL THEN sub.score END), 2) as average_score
     FROM students s
     LEFT JOIN student_strands ss ON s.id = ss.student_id
     LEFT JOIN learning_strands ls ON ss.strand_id = ls.strand_id
     LEFT JOIN activity_submissions sub ON sub.activity_id IN (
         SELECT activity_id FROM activities WHERE strand_id = ls.strand_id AND status = 'published'
     ) AND sub.student_id = s.id
     WHERE s.teacher_id = ? AND s.status = 'completed'
     GROUP BY s.id, s.student_id, s.first_name, s.last_name, s.middle_name, s.status, s.enrollment_date, s.lrn, s.completion_date, ls.strand_number, ls.title
     ORDER BY s.completion_date DESC"
);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$archived_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Strands ────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT ls.strand_id, ls.strand_number, ls.title, ls.color_code,
            COUNT(DISTINCT s.id) as student_count,
            COUNT(DISTINCT m.module_id) as module_count,
            COUNT(DISTINCT act.activity_id) as activity_count
     FROM learning_strands ls
     LEFT JOIN student_strands ss ON ls.strand_id = ss.strand_id
     LEFT JOIN students s ON ss.student_id = s.id AND s.teacher_id = ? AND s.status IN ('active', 'enrolled')
     LEFT JOIN modules m ON ls.strand_id = m.strand_id
     LEFT JOIN activities act ON ls.strand_id = act.strand_id AND act.status = 'published'
     WHERE ls.status = 'active'
     GROUP BY ls.strand_id, ls.strand_number, ls.title, ls.color_code
     HAVING student_count > 0 
     ORDER BY ls.strand_number"
);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$strands = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Recent activities ──────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT a.activity_id, a.title, a.activity_type, a.created_at,
            ls.strand_number, ls.title as strand_title,
            COUNT(DISTINCT sub.submission_id) as total_submissions,
            ROUND(AVG(CASE WHEN sub.score IS NOT NULL THEN sub.score END), 2) as average_score
     FROM activities a
     JOIN learning_strands ls ON a.strand_id = ls.strand_id
     LEFT JOIN activity_submissions sub ON a.activity_id = sub.activity_id
     LEFT JOIN students s ON sub.student_id = s.id
     WHERE a.status = 'published'
     AND s.teacher_id = ?
     GROUP BY a.activity_id, a.title, a.activity_type, a.created_at, ls.strand_number, ls.title
     ORDER BY a.created_at DESC
     LIMIT 10"
);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$recent_activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Logs ───────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT * FROM system_logs WHERE user_id = ? AND user_type = 'teacher' ORDER BY created_at DESC LIMIT 20"
);
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Stats ──────────────────────────────────────────────────────────────────────
$total_students       = count($students);
$active_students_count = count(array_filter($students, fn($s) => in_array($s['status'], ['active','enrolled'])));
$completed_students_count = count($archived_students);
$total_submissions = 0; 
$total_score = 0; 
$graded_count = 0;
foreach ($students as $s) {
    $total_submissions += $s['submitted_activities'] ?? 0;
    if (($s['average_score'] ?? 0) > 0) { 
        $total_score += $s['average_score']; 
        $graded_count++; 
    }
}
$average_student_score = $graded_count > 0 ? round($total_score / $graded_count, 2) : 0;

// Tokens for header action links
$edit_tok   = issue_teacher_token($teacher_id);
$report_tok = issue_teacher_token($teacher_id);

if (ob_get_length()) ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <link rel="icon" type="image/png" href="/logo">
    <title>Teacher Details - <?= htmlspecialchars($teacher['full_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{--blue:#1d4ed8;--blue-dark:#1e3a8a;--blue-mid:#2563eb;--blue-light:#eff6ff;--blue-soft:#dbeafe;--blue-border:#bfdbfe;--emerald:#059669;--emerald-light:#d1fae5;--amber:#d97706;--amber-light:#fef3c7;--violet:#7c3aed;--violet-light:#ede9fe;--rose:#e11d48;--rose-light:#fff1f2;--gray:#6b7280;--gray-light:#f3f4f6;--bg:#f0f4ff;--bg-mid:#e4eaf6;--border:#e2e8f0;--white:#ffffff;--text-dark:#0f172a;--text-mid:#334155;--text-light:#64748b;--text-xlight:#94a3b8;--r-sm:8px;--r-md:14px;--r-lg:20px;--shadow-sm:0 1px 3px rgba(0,0,0,.07);--shadow-md:0 4px 16px rgba(29,78,216,.11);--shadow-hero:0 8px 32px rgba(29,78,216,.28);--ease:cubic-bezier(0.4,0,0.2,1)}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text-dark);overflow-x:hidden}
        .simple-header{background:white;border-bottom:1.5px solid var(--border);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;backdrop-filter:blur(14px);background:rgba(255,255,255,.94)}
        .back-button{display:inline-flex;align-items:center;gap:10px;padding:8px 16px;background:var(--bg);border:1.5px solid var(--border);border-radius:40px;color:var(--text-mid);text-decoration:none;font-size:.9rem;font-weight:500;transition:all .18s var(--ease)}
        .back-button:hover{background:var(--blue-light);border-color:var(--blue-border);color:var(--blue)}
        .header-title{font-size:1.2rem;font-weight:700;color:var(--text-dark)}
        .header-title i{color:var(--blue);margin-right:8px}
        .header-actions-right{display:flex;gap:10px;align-items:center}
        .action-link{display:inline-flex;align-items:center;gap:7px;padding:8px 16px;border-radius:40px;font-size:.85rem;font-weight:600;text-decoration:none;transition:all .18s var(--ease);border:1.5px solid var(--border)}
        .action-link.primary{background:var(--blue);color:#fff;border-color:var(--blue)}.action-link.primary:hover{background:var(--blue-dark)}
        .action-link.secondary{background:var(--white);color:var(--text-mid)}.action-link.secondary:hover{background:var(--blue-light);border-color:var(--blue-border);color:var(--blue)}
        .content{padding:24px 32px;max-width:1400px;margin:0 auto}
        .profile-header{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-lg);padding:32px;margin-bottom:24px;position:relative;overflow:hidden;animation:fadeUp .42s ease both}
        .profile-header::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--blue),var(--violet))}
        .profile-info{display:flex;gap:24px;align-items:center}
        .profile-avatar{width:100px;height:100px;background:linear-gradient(135deg,var(--blue-dark),var(--blue-mid));border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:2.5rem;box-shadow:var(--shadow-md);border:4px solid var(--white)}
        .profile-details h2{font-size:2rem;font-weight:700;margin-bottom:8px}
        .profile-meta{display:flex;gap:16px;color:var(--text-light);font-size:.95rem;margin-bottom:8px;flex-wrap:wrap}
        .profile-meta i{width:20px;color:var(--blue)}
        .profile-badge{display:inline-flex;align-items:center;padding:6px 16px;border-radius:40px;font-weight:600;font-size:.85rem}
        .badge-active{background:var(--emerald-light);color:var(--emerald)}.badge-inactive{background:var(--amber-light);color:var(--amber)}.badge-completed{background:var(--gray-light);color:var(--gray)}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:24px;animation:fadeUp .42s .07s ease both}
        .stat-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:20px;transition:all .2s var(--ease)}
        .stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--blue-border)}
        .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:12px}
        .stat-icon.blue{background:var(--blue-light);color:var(--blue)}.stat-icon.emerald{background:var(--emerald-light);color:var(--emerald)}.stat-icon.amber{background:var(--amber-light);color:var(--amber)}.stat-icon.violet{background:var(--violet-light);color:var(--violet)}.stat-icon.gray{background:var(--gray-light);color:var(--gray)}
        .stat-number{font-size:2rem;font-weight:700;margin-bottom:4px}
        .stat-label{color:var(--text-light);font-size:.85rem;font-weight:500}
        .section-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);margin-bottom:24px;overflow:hidden;animation:fadeUp .42s .13s ease both}
        .section-header{padding:18px 24px;border-bottom:1.5px solid var(--border);background:var(--blue-light);display:flex;align-items:center;gap:12px}
        .section-header i{font-size:1.1rem;color:var(--blue);width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:var(--white);border-radius:8px;border:1.5px solid var(--blue-border)}
        .section-header h3{font-size:1rem;font-weight:700;color:var(--text-dark)}
        .section-body{padding:24px}
        .strand-chip{display:inline-block;padding:6px 14px;border-radius:40px;font-size:.8rem;font-weight:600;margin:0 8px 8px 0;background:var(--bg);color:var(--text-mid);border:1.5px solid var(--border)}
        .table-responsive{overflow-x:auto}
        .details-table{width:100%;border-collapse:collapse}
        .details-table th{text-align:left;padding:12px 12px 12px 0;font-size:.8rem;font-weight:600;color:var(--text-xlight);text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border)}
        .details-table td{padding:12px 12px 12px 0;font-size:.9rem;color:var(--text-mid);border-bottom:1px solid var(--bg-mid)}
        .details-table tr:last-child td{border-bottom:none}
        .student-name{font-weight:600;color:var(--text-dark)}
        .student-id{font-family:monospace;font-size:.8rem;color:var(--text-xlight)}
        .score-badge{padding:4px 10px;border-radius:40px;font-size:.8rem;font-weight:600}
        .score-high{background:var(--emerald-light);color:var(--emerald)}.score-mid{background:var(--amber-light);color:var(--amber)}.score-low{background:var(--rose-light);color:var(--rose)}
        .action-btn{width:34px;height:34px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;color:var(--text-light);background:var(--bg);border:1.5px solid var(--border);transition:all .18s var(--ease);text-decoration:none;margin:0 2px}
        .action-btn:hover{background:var(--blue-light);border-color:var(--blue-border);color:var(--blue)}
        .status-badge{padding:4px 10px;border-radius:40px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center}
        .status-active{background:rgba(76,201,240,.15);color:#28a745}.status-inactive{background:rgba(247,37,133,.15);color:#dc3545}.status-completed{background:var(--gray-light);color:var(--gray)}
        .complete-btn{background:var(--emerald);color:white;border:none;padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .18s var(--ease)}
        .complete-btn:hover{background:var(--emerald);opacity:.8;transform:translateY(-1px)}
        .log-badge{background:var(--blue-light);color:var(--blue);padding:3px 8px;border-radius:40px;font-size:.7rem;font-weight:600;display:inline-block}
        .empty-state{text-align:center;padding:40px;color:var(--text-light)}
        .empty-state i{font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block}
        .alert-success{background:var(--emerald-light);color:var(--emerald);padding:12px 20px;border-radius:var(--r-md);margin-bottom:20px;border-left:4px solid var(--emerald)}
        .alert-error{background:var(--rose-light);color:var(--rose);padding:12px 20px;border-radius:var(--r-md);margin-bottom:20px;border-left:4px solid var(--rose)}
        @media(max-width:992px){.stats-grid{grid-template-columns:repeat(2,1fr)}.profile-info{flex-direction:column;text-align:center}.profile-meta{justify-content:center}}
        @media(max-width:768px){.content{padding:18px 16px}.simple-header{padding:12px 16px}.back-button span{display:none}}
        @media(max-width:576px){.stats-grid{grid-template-columns:1fr}}
        @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    </style>
</head>
<body>

<div class="simple-header">
    <a href="/AdminTeacherMonitor" class="back-button">
        <i class="fas fa-arrow-left"></i><span>Back to Monitoring</span>
    </a>
    <div class="header-title"><i class="fas fa-chalkboard-teacher"></i> Teacher Details</div>
    <div class="header-actions-right">
        <a href="/AdminEditTeachers?<?= $edit_tok ?>" class="action-link primary">
            <i class="fas fa-edit"></i> Edit
        </a>
        <a href="/AdminTeacherReports?<?= $report_tok ?>" class="action-link secondary">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
    </div>
</div>

<div class="content">

    <!-- Display Messages -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert-error">
            <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-info">
            <div class="profile-avatar"><?= strtoupper(substr($teacher['full_name'], 0, 1)) ?></div>
            <div style="flex:1">
                <h2><?= htmlspecialchars($teacher['full_name']) ?></h2>
                <div class="profile-meta">
                    <span><i class="fas fa-envelope"></i><?= htmlspecialchars($teacher['email']) ?></span>
                    <?php if ($teacher['phone']): ?>
                        <span><i class="fas fa-phone"></i><?= htmlspecialchars($teacher['phone']) ?></span>
                    <?php endif; ?>
                    <?php if ($teacher['barangay_name']): ?>
                        <span><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($teacher['barangay_name']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($teacher['username'])): ?>
                        <span><i class="fas fa-user"></i><?= htmlspecialchars($teacher['username']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($teacher['last_login'])): ?>
                        <span><i class="fas fa-clock"></i>Last login: <?= date('M d, Y H:i', strtotime($teacher['last_login'])) ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <span class="profile-badge <?= $teacher['status']==='active'?'badge-active':'badge-inactive' ?>">
                        <i class="fas fa-circle" style="font-size:.6rem;margin-right:5px"></i><?= ucfirst($teacher['status']) ?>
                    </span>
                    <?php if ($teacher['specialization']): ?>
                        <span class="profile-badge" style="background:var(--violet-light);color:var(--violet)">
                            <i class="fas fa-star" style="margin-right:5px"></i><?= htmlspecialchars($teacher['specialization']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($teacher['qualification']): ?>
                        <span class="profile-badge" style="background:var(--blue-light);color:var(--blue)">
                            <i class="fas fa-graduation-cap" style="margin-right:5px"></i><?= htmlspecialchars($teacher['qualification']) ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-users"></i></div>
            <div class="stat-number"><?= $total_students ?></div>
            <div class="stat-label">Active Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon emerald"><i class="fas fa-user-check"></i></div>
            <div class="stat-number"><?= $active_students_count ?></div>
            <div class="stat-label">Enrolled Students</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon gray"><i class="fas fa-archive"></i></div>
            <div class="stat-number"><?= $completed_students_count ?></div>
            <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon violet"><i class="fas fa-star"></i></div>
            <div class="stat-number"><?= $average_student_score ?>%</div>
            <div class="stat-label">Avg. Student Score</div>
        </div>
    </div>

    <!-- Strands -->
    <?php if (!empty($strands)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-layer-group"></i>
            <h3>Assigned Learning Strands</h3>
        </div>
        <div class="section-body">
            <div style="display:flex;flex-wrap:wrap;gap:12px">
                <?php foreach ($strands as $strand): ?>
                <div style="flex:1;min-width:250px;background:var(--bg);border-radius:var(--r-md);padding:16px;border:1.5px solid var(--border)">
                    <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px">
                        <span style="width:12px;height:12px;border-radius:4px;background:<?= htmlspecialchars($strand['color_code'] ?? '#1d4ed8') ?>"></span>
                        <h4 style="font-size:1rem;font-weight:700"><?= htmlspecialchars($strand['strand_number'].' - '.$strand['title']) ?></h4>
                    </div>
                    <div style="display:flex;gap:16px">
                        <div><span class="stat-number" style="font-size:1.3rem"><?= $strand['student_count'] ?></span> <span class="stat-label">Students</span></div>
                        <div><span class="stat-number" style="font-size:1.3rem"><?= $strand['module_count'] ?></span> <span class="stat-label">Modules</span></div>
                        <div><span class="stat-number" style="font-size:1.3rem"><?= $strand['activity_count'] ?></span> <span class="stat-label">Activities</span></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Active Students Table -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-user-graduate"></i>
            <h3>Active Students (<?= $total_students ?>)</h3>
        </div>
        <div class="section-body">
            <?php if (empty($students)): ?>
                <div class="empty-state">
                    <i class="fas fa-users"></i>
                    <p>No active students assigned to this teacher</p>
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Strand</th>
                            <th>Status</th>
                            <th>Progress</th>
                            <th>Avg. Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $index => $student):
                            $score_class = 'score-low';
                            if (($student['average_score'] ?? 0) >= 75) $score_class = 'score-high';
                            elseif (($student['average_score'] ?? 0) >= 50) $score_class = 'score-mid';

                            $stok = issue_student_token((string)$student['id']);
                            $progress_percent = $student['total_activities'] > 0 
                                ? round(($student['submitted_activities'] / $student['total_activities']) * 100) 
                                : 0;
                        ?>
                        <tr>
                            <td style="color:var(--text-xlight);font-weight:600"><?= $index + 1 ?></td>
                            <td><span class="student-id"><?= htmlspecialchars($student['student_id']) ?></span></td>
                            <td><span class="student-name"><?= htmlspecialchars($student['last_name'].', '.$student['first_name']) ?></span></td>
                            <td>
                                <?php if ($student['strand_number']): ?>
                                    <span class="strand-chip"><?= htmlspecialchars($student['strand_number']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= in_array($student['status'],['active','enrolled'])?'status-active':'status-inactive' ?>">
                                    <i class="fas fa-circle" style="font-size:.6rem;margin-right:4px"></i><?= ucfirst($student['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?= (int)($student['submitted_activities'] ?? 0) ?> / <?= (int)($student['total_activities'] ?? 0) ?>
                                <div style="font-size:.7rem;color:var(--text-light)"><?= $progress_percent ?>% complete</div>
                            </td>
                            <td>
                                <?php if (($student['average_score'] ?? null) !== null && $student['average_score'] > 0): ?>
                                    <span class="score-badge <?= $score_class ?>"><?= round($student['average_score'], 1) ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/AdminStudentDetails?<?= $stok ?>" class="action-btn" title="View Student Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <form method="POST" style="display:inline-block" onsubmit="return confirm('Mark this student as complete? This will archive them.');">
                                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                    <button type="submit" name="mark_complete" class="complete-btn" title="Mark as Complete">
                                        <i class="fas fa-check"></i> Complete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Archived/Completed Students Table -->
    <?php if (!empty($archived_students)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-archive"></i>
            <h3>Completed Students Archive (<?= $completed_students_count ?>)</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Strand</th>
                            <th>Completion Date</th>
                            <th>Submitted Activities</th>
                            <th>Final Score</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archived_students as $index => $student):
                            $score_class = 'score-low';
                            if (($student['average_score'] ?? 0) >= 75) $score_class = 'score-high';
                            elseif (($student['average_score'] ?? 0) >= 50) $score_class = 'score-mid';

                            $stok = issue_student_token((string)$student['id']);
                        ?>
                        <tr>
                            <td style="color:var(--text-xlight);font-weight:600"><?= $index + 1 ?></td>
                            <td><span class="student-id"><?= htmlspecialchars($student['student_id']) ?></span></td>
                            <td><span class="student-name"><?= htmlspecialchars($student['last_name'].', '.$student['first_name']) ?></span></td>
                            <td>
                                <?php if ($student['strand_number']): ?>
                                    <span class="strand-chip"><?= htmlspecialchars($student['strand_number']) ?></span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($student['completion_date'] ?? $student['enrollment_date'])) ?></td>
                            <td><?= (int)($student['submitted_activities'] ?? 0) ?></td>
                            <td>
                                <?php if (($student['average_score'] ?? null) !== null && $student['average_score'] > 0): ?>
                                    <span class="score-badge <?= $score_class ?>"><?= round($student['average_score'], 1) ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/AdminStudentDetails?<?= $stok ?>" class="action-btn" title="View Student Details">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Activities -->
    <?php if (!empty($recent_activities)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-clock-rotate-left"></i>
            <h3>Recent Activities</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Strand</th>
                            <th>Type</th>
                            <th>Submissions</th>
                            <th>Avg. Score</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_activities as $activity): ?>
                        <tr>
                            <td><span class="student-name"><?= htmlspecialchars($activity['title']) ?></span></td>
                            <td><span class="strand-chip"><?= htmlspecialchars($activity['strand_number']) ?></span></td>
                            <td><?= ucfirst(str_replace('_', ' ', $activity['activity_type'])) ?></td>
                            <td><?= (int)$activity['total_submissions'] ?></span></td>
                            <td>
                                <?php if (($activity['average_score'] ?? null) !== null && $activity['average_score'] > 0): ?>
                                    <span class="score-badge <?= $activity['average_score'] >= 75 ? 'score-high' : 'score-mid' ?>">
                                        <?= round($activity['average_score'], 1) ?>%
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td><?= date('M d, Y', strtotime($activity['created_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-clock-rotate-left"></i>
            <h3>Recent Activities</h3>
        </div>
        <div class="section-body">
            <div class="empty-state">
                <i class="fas fa-tasks"></i>
                <p>No activities found for this teacher's students</p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Logs -->
    <?php if (!empty($logs)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-history"></i>
            <h3>Recent Activity Logs</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= date('M d, H:i', strtotime($log['created_at'])) ?></td>
                            <td><span class="log-badge"><?= htmlspecialchars($log['action']) ?></span></td>
                            <td><?= htmlspecialchars($log['description'] ?? '') ?></span></td>
                            <td><?= htmlspecialchars($log['ip_address'] ?? '') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>