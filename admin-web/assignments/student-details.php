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

function issue_student_token(string $student_id): string {
    $ex = array_search($student_id, $_SESSION['_st'], true);
    if ($ex !== false) return $ex;
    $tok = bin2hex(random_bytes(20));
    $_SESSION['_st'][$tok] = $student_id;
    if (count($_SESSION['_st']) > 500) $_SESSION['_st'] = array_slice($_SESSION['_st'], -500, null, true);
    return $tok;
}

function resolve_student_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_st'][$token] ?? 0);
}

// ── Resolve student token from query string ────────────────────────────────────
$raw = trim($_SERVER['QUERY_STRING'] ?? '');
if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));
if (empty($raw) && isset($_GET['_t'])) $raw = trim($_GET['_t']);

if (empty($raw)) {
    $_SESSION['error'] = "Invalid or expired link.";
    redirect('/AdminTeacherMonitor'); exit;
}

$student_numeric_id = resolve_student_token($raw);
if (!$student_numeric_id) {
    $_SESSION['error'] = "Invalid or expired link.";
    redirect('/AdminTeacherMonitor'); exit;
}

// ── Fetch student details ──────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT s.*, t.full_name as teacher_name, t.email as teacher_email,
            t.phone as teacher_phone, t.specialization as teacher_specialization,
            b.name as barangay_name
     FROM students s
     LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
     LEFT JOIN barangays b ON s.current_barangay_id = b.barangay_id
     WHERE s.id = ?"
);
$stmt->bind_param("i", $student_numeric_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    $_SESSION['error'] = "Student not found.";
    redirect('/AdminTeacherMonitor'); exit;
}

// ── Fetch student's strands and activities ─────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT ls.strand_id, ls.strand_number, ls.title, ls.color_code,
            COUNT(DISTINCT act.activity_id) as total_activities,
            COUNT(DISTINCT sub.submission_id) as submitted_activities,
            ROUND(AVG(CASE WHEN sub.score IS NOT NULL THEN sub.score END), 2) as average_score
     FROM learning_strands ls
     LEFT JOIN student_strands ss ON ls.strand_id = ss.strand_id
     LEFT JOIN activities act ON act.strand_id = ls.strand_id AND act.status = 'published'
     LEFT JOIN activity_submissions sub ON sub.activity_id = act.activity_id AND sub.student_id = ?
     WHERE ss.student_id = ?
     GROUP BY ls.strand_id, ls.strand_number, ls.title, ls.color_code
     ORDER BY ls.strand_number"
);
$stmt->bind_param("ii", $student_numeric_id, $student_numeric_id);
$stmt->execute();
$strands = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch student's submissions ────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT sub.*, a.title as activity_title, a.activity_type, a.due_date,
            ls.strand_number, ls.title as strand_title
     FROM activity_submissions sub
     JOIN activities a ON sub.activity_id = a.activity_id
     JOIN learning_strands ls ON a.strand_id = ls.strand_id
     WHERE sub.student_id = ?
     ORDER BY sub.submitted_at DESC
     LIMIT 20"
);
$stmt->bind_param("i", $student_numeric_id);
$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch student's activity violations ────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT * FROM activity_violations 
     WHERE student_id = ? 
     ORDER BY created_at DESC 
     LIMIT 20"
);
$stmt->bind_param("i", $student_numeric_id);
$stmt->execute();
$violations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch assessment results ───────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT ar.*, a.title as assessment_title, a.assessment_type
     FROM assessment_results ar
     JOIN assessments a ON ar.assessment_id = a.assessment_id
     WHERE ar.student_id = ?
     ORDER BY ar.completed_at DESC
     LIMIT 10"
);
$stmt->bind_param("i", $student_numeric_id);
$stmt->execute();
$assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Stats ──────────────────────────────────────────────────────────────────────
$total_activities = 0;
$total_submitted = 0;
$total_score = 0;
$graded_count = 0;

foreach ($strands as $strand) {
    $total_activities += $strand['total_activities'];
    $total_submitted += $strand['submitted_activities'];
    if ($strand['average_score'] > 0) {
        $total_score += $strand['average_score'];
        $graded_count++;
    }
}

$average_score = $graded_count > 0 ? round($total_score / $graded_count, 2) : 0;
$completion_rate = $total_activities > 0 ? round(($total_submitted / $total_activities) * 100, 2) : 0;

// Get teacher token for back button
$teacher_tok = issue_teacher_token($student['teacher_id']);

if (ob_get_length()) ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <link rel="icon" type="image/png" href="/logo">
    <title>Student Details - <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{--blue:#1d4ed8;--blue-dark:#1e3a8a;--blue-mid:#2563eb;--blue-light:#eff6ff;--blue-soft:#dbeafe;--blue-border:#bfdbfe;--emerald:#059669;--emerald-light:#d1fae5;--amber:#d97706;--amber-light:#fef3c7;--violet:#7c3aed;--violet-light:#ede9fe;--rose:#e11d48;--rose-light:#fff1f2;--bg:#f0f4ff;--bg-mid:#e4eaf6;--border:#e2e8f0;--white:#ffffff;--text-dark:#0f172a;--text-mid:#334155;--text-light:#64748b;--text-xlight:#94a3b8;--r-sm:8px;--r-md:14px;--r-lg:20px;--shadow-sm:0 1px 3px rgba(0,0,0,.07);--shadow-md:0 4px 16px rgba(29,78,216,.11);--ease:cubic-bezier(0.4,0,0.2,1)}
        body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text-dark);overflow-x:hidden}
        .simple-header{background:white;border-bottom:1.5px solid var(--border);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;backdrop-filter:blur(14px);background:rgba(255,255,255,.94)}
        .back-button{display:inline-flex;align-items:center;gap:10px;padding:8px 16px;background:var(--bg);border:1.5px solid var(--border);border-radius:40px;color:var(--text-mid);text-decoration:none;font-size:.9rem;font-weight:500;transition:all .18s var(--ease)}
        .back-button:hover{background:var(--blue-light);border-color:var(--blue-border);color:var(--blue)}
        .header-title{font-size:1.2rem;font-weight:700;color:var(--text-dark)}
        .header-title i{color:var(--blue);margin-right:8px}
        .content{padding:24px 32px;max-width:1400px;margin:0 auto}
        .profile-header{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-lg);padding:32px;margin-bottom:24px;position:relative;overflow:hidden;animation:fadeUp .42s ease both}
        .profile-header::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:linear-gradient(90deg,var(--blue),var(--violet))}
        .profile-info{display:flex;gap:24px;align-items:center}
        .profile-avatar{width:100px;height:100px;background:linear-gradient(135deg,var(--blue-dark),var(--blue-mid));border-radius:50%;display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:2.5rem;box-shadow:var(--shadow-md);border:4px solid var(--white)}
        .profile-details h2{font-size:2rem;font-weight:700;margin-bottom:8px}
        .profile-meta{display:flex;gap:16px;color:var(--text-light);font-size:.95rem;margin-bottom:8px;flex-wrap:wrap}
        .profile-meta i{width:20px;color:var(--blue)}
        .profile-badge{display:inline-flex;align-items:center;padding:6px 16px;border-radius:40px;font-weight:600;font-size:.85rem}
        .badge-active{background:var(--emerald-light);color:var(--emerald)}.badge-inactive{background:var(--amber-light);color:var(--amber)}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:24px;animation:fadeUp .42s .07s ease both}
        .stat-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:20px;transition:all .2s var(--ease)}
        .stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--blue-border)}
        .stat-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:12px}
        .stat-icon.blue{background:var(--blue-light);color:var(--blue)}.stat-icon.emerald{background:var(--emerald-light);color:var(--emerald)}.stat-icon.amber{background:var(--amber-light);color:var(--amber)}.stat-icon.violet{background:var(--violet-light);color:var(--violet)}
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
        .score-badge{padding:4px 10px;border-radius:40px;font-size:.8rem;font-weight:600}
        .score-high{background:var(--emerald-light);color:var(--emerald)}.score-mid{background:var(--amber-light);color:var(--amber)}.score-low{background:var(--rose-light);color:var(--rose)}
        .status-badge{padding:4px 10px;border-radius:40px;font-size:.8rem;font-weight:600;display:inline-flex;align-items:center}
        .status-active{background:rgba(76,201,240,.15);color:#28a745}.status-inactive{background:rgba(247,37,133,.15);color:#dc3545}
        .violation-badge{padding:4px 10px;border-radius:40px;font-size:.7rem;font-weight:600;display:inline-block}
        .violation-tab{background:var(--amber-light);color:var(--amber)}.violation-face{background:var(--rose-light);color:var(--rose)}.violation-copy{background:var(--violet-light);color:var(--violet)}
        .empty-state{text-align:center;padding:40px;color:var(--text-light)}
        .empty-state i{font-size:2.5rem;opacity:.3;margin-bottom:10px;display:block}
        @media(max-width:992px){.stats-grid{grid-template-columns:repeat(2,1fr)}.profile-info{flex-direction:column;text-align:center}.profile-meta{justify-content:center}}
        @media(max-width:768px){.content{padding:18px 16px}.simple-header{padding:12px 16px}.back-button span{display:none}}
        @media(max-width:576px){.stats-grid{grid-template-columns:1fr}}
        @keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
    </style>
</head>
<body>

<div class="simple-header">
    <a href="/AdminTeacherMonitor?<?= $teacher_tok ?>" class="back-button">
        <i class="fas fa-arrow-left"></i><span>Back to Teacher</span>
    </a>
    <div class="header-title"><i class="fas fa-user-graduate"></i> Student Details</div>
</div>

<div class="content">

    <!-- Profile Header -->
    <div class="profile-header">
        <div class="profile-info">
            <div class="profile-avatar"><?= strtoupper(substr($student['first_name'], 0, 1)) ?></div>
            <div style="flex:1">
                <h2><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h2>
                <div class="profile-meta">
                    <span><i class="fas fa-id-card"></i><?= htmlspecialchars($student['student_id']) ?></span>
                    <?php if ($student['lrn']): ?>
                        <span><i class="fas fa-graduation-cap"></i>LRN: <?= htmlspecialchars($student['lrn']) ?></span>
                    <?php endif; ?>
                    <?php if ($student['contact_number']): ?>
                        <span><i class="fas fa-phone"></i><?= htmlspecialchars($student['contact_number']) ?></span>
                    <?php endif; ?>
                    <?php if ($student['barangay_name']): ?>
                        <span><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($student['barangay_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($student['teacher_name']): ?>
                        <span><i class="fas fa-chalkboard-teacher"></i>Teacher: <?= htmlspecialchars($student['teacher_name']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:12px;flex-wrap:wrap">
                    <span class="profile-badge <?= in_array($student['status'], ['active','enrolled'])?'badge-active':'badge-inactive' ?>">
                        <i class="fas fa-circle" style="font-size:.6rem;margin-right:5px"></i><?= ucfirst($student['status']) ?>
                    </span>
                    <?php if ($student['birthdate']): ?>
                        <span class="profile-badge" style="background:var(--blue-light);color:var(--blue)">
                            <i class="fas fa-birthday-cake" style="margin-right:5px"></i><?= date('M d, Y', strtotime($student['birthdate'])) ?>
                        </span>
                    <?php endif; ?>
                    <span class="profile-badge" style="background:var(--violet-light);color:var(--violet)">
                        <i class="fas fa-calendar-alt" style="margin-right:5px"></i>Enrolled: <?= date('M d, Y', strtotime($student['enrollment_date'])) ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-book"></i></div>
            <div class="stat-number"><?= $total_activities ?></div>
            <div class="stat-label">Total Activities</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon emerald"><i class="fas fa-check-circle"></i></div>
            <div class="stat-number"><?= $total_submitted ?></div>
            <div class="stat-label">Submitted</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fas fa-chart-line"></i></div>
            <div class="stat-number"><?= $completion_rate ?>%</div>
            <div class="stat-label">Completion Rate</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon violet"><i class="fas fa-star"></i></div>
            <div class="stat-number"><?= $average_score ?>%</div>
            <div class="stat-label">Avg. Score</div>
        </div>
    </div>

    <!-- Strands Performance -->
    <?php if (!empty($strands)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-layer-group"></i>
            <h3>Strand Performance</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Strand</th>
                            <th>Activities</th>
                            <th>Submitted</th>
                            <th>Avg. Score</th>
                            <th>Progress</th>
                        </thead>
                    <tbody>
                        <?php foreach ($strands as $strand):
                            $strand_progress = $strand['total_activities'] > 0 ? round(($strand['submitted_activities'] / $strand['total_activities']) * 100, 2) : 0;
                            $score_class = 'score-low';
                            if ($strand['average_score'] >= 75) $score_class = 'score-high';
                            elseif ($strand['average_score'] >= 50) $score_class = 'score-mid';
                        ?>
                        <tr>
                            <td><span class="strand-chip"><?= htmlspecialchars($strand['strand_number'] . ' - ' . $strand['title']) ?></span></td>
                            <td><?= $strand['total_activities'] ?></td>
                            <td><?= $strand['submitted_activities'] ?></td>
                            <td>
                                <?php if ($strand['average_score'] > 0): ?>
                                    <span class="score-badge <?= $score_class ?>"><?= round($strand['average_score'], 1) ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">N/A</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display:flex;align-items:center;gap:8px">
                                    <div style="flex:1;height:6px;background:var(--bg-mid);border-radius:3px;overflow:hidden">
                                        <div style="width:<?= $strand_progress ?>%;height:100%;background:var(--blue);border-radius:3px"></div>
                                    </div>
                                    <span style="font-size:.8rem;color:var(--text-light)"><?= $strand_progress ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Submissions -->
    <?php if (!empty($submissions)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-clock-rotate-left"></i>
            <h3>Recent Submissions</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Activity</th>
                            <th>Strand</th>
                            <th>Type</th>
                            <th>Score</th>
                            <th>Status</th>
                            <th>Submitted</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission):
                            $score_class = 'score-low';
                            if ($submission['score'] >= 75) $score_class = 'score-high';
                            elseif ($submission['score'] >= 50) $score_class = 'score-mid';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($submission['activity_title']) ?></strong></td>
                            <td><span class="strand-chip"><?= htmlspecialchars($submission['strand_number']) ?></span></td>
                            <td><?= ucfirst(str_replace('_', ' ', $submission['activity_type'])) ?></td>
                            <td>
                                <?php if ($submission['score'] !== null): ?>
                                    <span class="score-badge <?= $score_class ?>"><?= round($submission['score'], 1) ?>%</span>
                                <?php else: ?>
                                    <span style="color:var(--text-light)">Not graded</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $submission['status'] == 'graded' ? 'status-active' : 'status-inactive' ?>">
                                    <?= ucfirst($submission['status']) ?>
                                </span>
                            </td>
                            <td><?= date('M d, Y H:i', strtotime($submission['submitted_at'])) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Violations -->
    <?php if (!empty($violations)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Security Violations</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Violation Type</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($violations as $violation):
                            $violation_class = 'violation-tab';
                            if (strpos($violation['violation_type'], 'face') !== false) $violation_class = 'violation-face';
                            elseif (strpos($violation['violation_type'], 'copy') !== false) $violation_class = 'violation-copy';
                        ?>
                        <tr>
                            <td><?= date('M d, Y H:i', strtotime($violation['created_at'])) ?></td>
                            <td><span class="violation-badge <?= $violation_class ?>"><?= ucfirst(str_replace('_', ' ', $violation['violation_type'])) ?></span></td>
                            <td><?= htmlspecialchars($violation['reason'] ?? 'No reason provided') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Assessments -->
    <?php if (!empty($assessments)): ?>
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-file-alt"></i>
            <h3>Assessment Results</h3>
        </div>
        <div class="section-body">
            <div class="table-responsive">
                <table class="details-table">
                    <thead>
                        <tr>
                            <th>Assessment</th>
                            <th>Type</th>
                            <th>Score</th>
                            <th>Percentage</th>
                            <th>Status</th>
                            <th>Completed</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assessments as $assessment):
                            $score_class = 'score-low';
                            if ($assessment['percentage'] >= 75) $score_class = 'score-high';
                            elseif ($assessment['percentage'] >= 50) $score_class = 'score-mid';
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($assessment['assessment_title']) ?></strong></td>
                            <td><?= ucfirst(str_replace('_', ' ', $assessment['assessment_type'])) ?></td>
                            <td><?= $assessment['total_score'] ?> / <?= $assessment['max_possible_score'] ?></td>
                            <td><span class="score-badge <?= $score_class ?>"><?= round($assessment['percentage'], 1) ?>%</span></td>
                            <td><span class="status-badge status-active">Completed</span></td>
                            <td><?= date('M d, Y', strtotime($assessment['completed_at'])) ?></td>
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