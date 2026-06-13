<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: /admin-secure'); exit();
}

// ── Token helpers ──────────────────────────────────────────────────────────────
if (!isset($_SESSION['_tt']) || !is_array($_SESSION['_tt'])) $_SESSION['_tt'] = [];

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

// ── Resolve teacher from token OR from the teacher selector form ──────────────
// The selector form posts ?teacher_id=N — we convert that to a token and redirect.
if (isset($_GET['teacher_id'])) {
    $tid = (int)$_GET['teacher_id'];
    if ($tid > 0) {
        $tok = issue_teacher_token($tid);
        header("Location: /AdminTeacherReports?" . $tok); exit();
    }
    // No teacher selected — show landing page via empty token
    header("Location: /AdminTeacherReports"); exit();
}

// Read token from raw query string (no key name)
$raw = trim($_SERVER['QUERY_STRING'] ?? '');
if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));

$teacher_id = 0;
if (!empty($raw)) {
    $teacher_id = resolve_teacher_token($raw);
    if (!$teacher_id) {
        // Bad token — redirect to landing
        header("Location: /AdminTeacherReports"); exit();
    }
}

// ── Data queries (same as original, using resolved $teacher_id) ───────────────
$teacher = null;
$students = [];
$performance_data = [];
$program_data = [];
$age_groups = [];
$monthly_enrollments = [];

if ($teacher_id > 0) {
    $stmt = $conn->prepare("SELECT * FROM teachers WHERE teacher_id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $teacher = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($teacher) {
        $stmt = $conn->prepare("SELECT * FROM students WHERE teacher_id = ? ORDER BY last_name, first_name");
        $stmt->bind_param("i", $teacher_id); $stmt->execute();
        $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $conn->prepare("
            SELECT COUNT(*) as total_students,
                SUM(CASE WHEN status='active'   THEN 1 ELSE 0 END) as active_students,
                SUM(CASE WHEN status='enrolled' THEN 1 ELSE 0 END) as enrolled_students,
                SUM(CASE WHEN status='inactive' THEN 1 ELSE 0 END) as inactive_students,
                SUM(CASE WHEN sex='male'        THEN 1 ELSE 0 END) as male_count,
                SUM(CASE WHEN sex='female'      THEN 1 ELSE 0 END) as female_count,
                AVG(age) as avg_age, MIN(age) as min_age, MAX(age) as max_age
            FROM students WHERE teacher_id = ?");
        $stmt->bind_param("i", $teacher_id); $stmt->execute();
        $performance_data = $stmt->get_result()->fetch_assoc(); $stmt->close();

        $stmt = $conn->prepare("SELECT als_program, COUNT(*) as count FROM students WHERE teacher_id = ? GROUP BY als_program ORDER BY count DESC");
        $stmt->bind_param("i", $teacher_id); $stmt->execute();
        $program_data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $conn->prepare("
            SELECT CASE WHEN age<15 THEN 'Under 15' WHEN age BETWEEN 15 AND 20 THEN '15-20'
                        WHEN age BETWEEN 21 AND 30 THEN '21-30' WHEN age BETWEEN 31 AND 40 THEN '31-40'
                        ELSE '41+' END as age_group, COUNT(*) as count
            FROM students WHERE teacher_id = ? GROUP BY age_group ORDER BY MIN(age)");
        $stmt->bind_param("i", $teacher_id); $stmt->execute();
        $age_groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

        $stmt = $conn->prepare("
            SELECT DATE_FORMAT(enrollment_date,'%b %Y') as month_label,
                   DATE_FORMAT(enrollment_date,'%Y-%m') as month_sort, COUNT(*) as count
            FROM students WHERE teacher_id = ? AND enrollment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY month_sort, month_label ORDER BY month_sort");
        $stmt->bind_param("i", $teacher_id); $stmt->execute();
        $monthly_enrollments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
    }
}

// All teachers for the dropdown (shown on page, selections re-tokenised above)
$all_teachers = $conn->query("SELECT teacher_id, full_name FROM teachers ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Chart data
$program_labels = array_column($program_data, 'als_program');
$program_counts = array_column($program_data, 'count');
$age_labels     = array_column($age_groups,   'age_group');
$age_counts     = array_column($age_groups,   'count');
$enroll_labels  = array_column($monthly_enrollments, 'month_label');
$enroll_counts  = array_column($monthly_enrollments, 'count');

$completion_rate = ($performance_data['total_students'] ?? 0) > 0
    ? round(($performance_data['active_students'] / $performance_data['total_students']) * 100) : 0;
$enrollment_rate = ($performance_data['total_students'] ?? 0) > 0
    ? round(($performance_data['enrolled_students'] / $performance_data['total_students']) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Report — ALS Admin</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root{--ink:#1a1f2e;--ink2:#3d4460;--ink3:#7b82a0;--paper:#f5f3ef;--paper2:#eceae4;--paper3:#e2dfd7;--accent:#2d6a4f;--accent2:#40916c;--accent3:#74c69d;--gold:#c77d0e;--gold2:#e9a825;--red:#c0392b;--blue:#1e6091;--rule:rgba(26,31,46,.1);--shadow:0 2px 12px rgba(26,31,46,.08);--shadow-lg:0 8px 32px rgba(26,31,46,.14)}
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
body{background:var(--paper);color:var(--ink);font-family:'DM Sans',sans-serif;font-size:14px;line-height:1.6;min-height:100vh}
.page-wrap{max-width:1300px;margin:0 auto;padding:28px 24px 60px}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:32px;padding-bottom:20px;border-bottom:1.5px solid var(--paper3);gap:16px;flex-wrap:wrap}
.page-header-left h1{font-family:'DM Serif Display',serif;font-size:32px;letter-spacing:-.5px;color:var(--ink);line-height:1.1}
.breadcrumb-trail{font-size:12.5px;color:var(--ink3);margin-top:5px;display:flex;align-items:center;gap:6px}
.breadcrumb-trail .sep{opacity:.4}
.page-header-right{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.selector-card{background:#fff;border:1px solid var(--paper3);border-radius:14px;padding:20px 24px;margin-bottom:28px;box-shadow:var(--shadow);display:flex;align-items:flex-end;gap:16px;flex-wrap:wrap}
.selector-card label{font-size:11.5px;font-weight:600;color:var(--ink3);letter-spacing:.6px;text-transform:uppercase;display:block;margin-bottom:6px}
.selector-card select{background:var(--paper);border:1.5px solid var(--paper3);border-radius:9px;padding:10px 36px 10px 14px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--ink);cursor:pointer;outline:none;min-width:280px;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237b82a0' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;transition:border-color .2s}
.selector-card select:focus{border-color:var(--accent2)}
.selector-card .fg{flex:1;min-width:220px}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .18s;white-space:nowrap}
.btn-primary{background:var(--accent);color:#fff}.btn-primary:hover{background:var(--accent2);transform:translateY(-1px);box-shadow:0 4px 12px rgba(45,106,79,.3)}
.btn-ghost{background:transparent;color:var(--ink2);border:1.5px solid var(--paper3)}.btn-ghost:hover{background:var(--paper2)}
.btn-print{background:var(--gold);color:#fff}.btn-print:hover{background:var(--gold2);transform:translateY(-1px)}
.btn-sm{padding:7px 13px;font-size:12.5px}
.teacher-hero{background:linear-gradient(135deg,var(--accent) 0%,var(--accent2) 60%,#52b788 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;color:#fff;display:flex;align-items:center;gap:24px;position:relative;overflow:hidden;box-shadow:var(--shadow-lg);animation:slideIn .5s ease both}
@keyframes slideIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}
.teacher-hero::after{content:'';position:absolute;right:-40px;top:-40px;width:220px;height:220px;background:rgba(255,255,255,.07);border-radius:50%}
.teacher-hero::before{content:'';position:absolute;right:80px;bottom:-60px;width:160px;height:160px;background:rgba(255,255,255,.05);border-radius:50%}
.teacher-avatar{width:72px;height:72px;background:rgba(255,255,255,.2);border:2px solid rgba(255,255,255,.4);border-radius:18px;display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0;position:relative;z-index:1}
.teacher-hero-info{flex:1;position:relative;z-index:1}
.teacher-hero-info h2{font-family:'DM Serif Display',serif;font-size:24px;line-height:1.2;margin-bottom:4px}
.teacher-hero-info .meta{display:flex;gap:20px;flex-wrap:wrap;margin-top:8px;font-size:13px;opacity:.88}
.teacher-hero-info .meta span{display:flex;align-items:center;gap:5px}
.hero-badge{background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);padding:4px 13px;border-radius:100px;font-size:12px;font-weight:600;letter-spacing:.5px;position:relative;z-index:1;backdrop-filter:blur(8px)}
.hero-badge.active{background:rgba(116,198,157,.35);border-color:rgba(116,198,157,.5)}
.hero-badge.inactive{background:rgba(192,57,43,.25);border-color:rgba(192,57,43,.4)}
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px}
.stat-card{background:#fff;border:1px solid var(--paper3);border-radius:14px;padding:20px 20px 18px;box-shadow:var(--shadow);position:relative;overflow:hidden;animation:fadeUp .5s ease both}
.stat-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;background:var(--card-accent,var(--accent))}
.stat-card .s-icon{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;background:var(--icon-bg,rgba(45,106,79,.1));color:var(--icon-color,var(--accent));margin-bottom:12px}
.stat-card .s-label{font-size:11px;font-weight:700;color:var(--ink3);letter-spacing:.7px;text-transform:uppercase;margin-bottom:4px}
.stat-card .s-value{font-size:36px;font-family:'DM Serif Display',serif;color:var(--ink);line-height:1}
.stat-card .s-sub{font-size:12px;color:var(--ink3);margin-top:5px}
.stat-card .s-trend{position:absolute;top:18px;right:18px;font-size:11.5px;font-weight:600;display:flex;align-items:center;gap:3px}
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.progress-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.progress-card{background:#fff;border:1px solid var(--paper3);border-radius:14px;padding:20px 22px;box-shadow:var(--shadow);animation:fadeUp .5s ease .25s both}
.progress-card .pc-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.progress-card .pc-label{font-size:13px;font-weight:600;color:var(--ink2)}
.progress-card .pc-pct{font-family:'DM Serif Display',serif;font-size:22px;color:var(--ink)}
.progress-track{background:var(--paper2);border-radius:100px;height:8px;overflow:hidden}
.progress-fill{height:100%;border-radius:100px;background:var(--fill-color,var(--accent));width:0%;transition:width 1s cubic-bezier(.23,1,.32,1)}
.progress-card .pc-sub{font-size:12px;color:var(--ink3);margin-top:6px}
.gender-bar{height:10px;border-radius:100px;overflow:hidden;background:var(--paper2);margin:10px 0 6px;display:flex}
.gender-bar .male-fill{background:var(--blue);transition:width 1s cubic-bezier(.23,1,.32,1)}
.gender-bar .female-fill{background:#c06fa0;transition:width 1s cubic-bezier(.23,1,.32,1)}
.gender-legend{display:flex;gap:16px;font-size:12px}
.gender-legend span{display:flex;align-items:center;gap:5px}
.dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.charts-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px}
.chart-card{background:#fff;border:1px solid var(--paper3);border-radius:14px;padding:22px;box-shadow:var(--shadow);animation:fadeUp .5s ease both}
.chart-card.wide{grid-column:span 2}
.chart-card .cc-title{font-family:'DM Serif Display',serif;font-size:16px;color:var(--ink);margin-bottom:4px}
.chart-card .cc-sub{font-size:12px;color:var(--ink3);margin-bottom:16px}
.chart-card canvas{max-height:240px}
.chart-card.wide canvas{max-height:200px}
.insights-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:24px}
.insight-chip{background:#fff;border:1px solid var(--paper3);border-radius:12px;padding:14px 16px;display:flex;align-items:flex-start;gap:12px;box-shadow:var(--shadow);animation:fadeUp .5s ease both}
.insight-chip .ic-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0}
.insight-chip .ic-text{font-size:13px;color:var(--ink2);line-height:1.45}
.insight-chip .ic-text strong{color:var(--ink);display:block;margin-bottom:2px;font-size:13.5px}
.ic-green{background:rgba(45,106,79,.1);color:var(--accent)}.ic-gold{background:rgba(199,125,14,.1);color:var(--gold)}.ic-blue{background:rgba(30,96,145,.1);color:var(--blue)}.ic-red{background:rgba(192,57,43,.1);color:var(--red)}
.table-card{background:#fff;border:1px solid var(--paper3);border-radius:14px;overflow:hidden;box-shadow:var(--shadow);margin-bottom:24px;animation:fadeUp .5s ease .3s both}
.table-header{padding:18px 22px;border-bottom:1px solid var(--paper2);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.table-header h3{font-family:'DM Serif Display',serif;font-size:17px;color:var(--ink)}
.table-header .th-sub{font-size:12.5px;color:var(--ink3);margin-top:2px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse}
thead tr{background:var(--paper)}
th{padding:11px 14px;font-size:11px;font-weight:700;color:var(--ink3);letter-spacing:.7px;text-transform:uppercase;text-align:left;border-bottom:1.5px solid var(--paper3);white-space:nowrap}
td{padding:11px 14px;font-size:13.5px;border-bottom:1px solid var(--paper2);vertical-align:middle}
tbody tr:hover td{background:rgba(45,106,79,.03)}
tbody tr:last-child td{border-bottom:none}
.student-name{font-weight:600;color:var(--ink)}
.student-id{font-size:11.5px;color:var(--ink3)}
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:700;letter-spacing:.3px}
.badge-active{background:rgba(45,106,79,.1);color:var(--accent)}
.badge-enrolled{background:rgba(30,96,145,.1);color:var(--blue)}
.badge-inactive{background:rgba(113,128,150,.1);color:var(--ink3)}
.badge-male{background:rgba(30,96,145,.1);color:var(--blue)}
.badge-female{background:rgba(192,111,160,.1);color:#9b4dca}
.badge-program{background:rgba(199,125,14,.1);color:var(--gold);font-size:10.5px}
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px;padding:0 22px 16px;border-bottom:1px solid var(--paper2)}
.filter-bar input,.filter-bar select{background:var(--paper);border:1.5px solid var(--paper3);border-radius:8px;padding:7px 12px;font-size:13px;font-family:'DM Sans',sans-serif;color:var(--ink);outline:none;transition:border-color .2s}
.filter-bar input{flex:1;min-width:180px}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--accent2)}
.empty-state{text-align:center;padding:60px 20px;color:var(--ink3)}
.empty-state i{font-size:48px;display:block;margin-bottom:14px;opacity:.5}
.empty-state h3{font-family:'DM Serif Display',serif;font-size:22px;color:var(--ink2);margin-bottom:6px}
@media print{body{background:#fff;font-size:12px}.page-header-right,.selector-card,.filter-bar,.btn,.no-print{display:none!important}.stats-row{grid-template-columns:repeat(4,1fr)}.charts-grid,.progress-row{grid-template-columns:1fr 1fr}.insights-row{grid-template-columns:repeat(3,1fr)}.stat-card,.chart-card,.progress-card,.table-card,.insight-chip{box-shadow:none!important;border:1px solid #ddd!important;break-inside:avoid}.teacher-hero{background:#2d6a4f!important;-webkit-print-color-adjust:exact}canvas{max-height:180px!important}}
@media(max-width:900px){.stats-row{grid-template-columns:repeat(2,1fr)}.charts-grid{grid-template-columns:1fr}.chart-card.wide{grid-column:span 1}.progress-row{grid-template-columns:1fr}.insights-row{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<?php if (file_exists(__DIR__ . '/../includes/header.php')) include __DIR__ . '/../includes/header.php'; ?>

<div class="page-wrap">

<div class="page-header">
    <div class="page-header-left">
        <h1>Teacher Report</h1>
        <div class="breadcrumb-trail">
            <span>ALS Admin</span><span class="sep">/</span>
            <span>Reports</span><span class="sep">/</span>
            <span><?= $teacher ? htmlspecialchars($teacher['full_name']) : 'Teacher Report' ?></span>
        </div>
    </div>
    <div class="page-header-right no-print">
        <?php if ($teacher_id && $teacher): ?>
        <button class="btn btn-ghost btn-sm" onclick="exportCSV()"><i class="fas fa-download"></i> Export CSV</button>
        <button class="btn btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
        <?php endif; ?>
    </div>
</div>

<!-- Teacher selector — submits teacher_id, server converts to token and redirects -->
<div class="selector-card no-print">
    <div class="fg">
        <label for="teacherSelect">Select Teacher</label>
        <form method="GET" id="teacherForm">
            <select name="teacher_id" id="teacherSelect" onchange="document.getElementById('teacherForm').submit()">
                <option value="">— Choose a teacher to generate report —</option>
                <?php foreach ($all_teachers as $t): ?>
                <option value="<?= $t['teacher_id'] ?>" <?= $t['teacher_id'] == $teacher_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($t['full_name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>
    <?php if ($teacher_id && $teacher): ?>
    <div style="font-size:12.5px;color:var(--ink3)">
        <i class="fas fa-calendar-alt"></i> Report generated: <?= date('M d, Y, h:i A') ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!$teacher_id || !$teacher): ?>
<div class="empty-state">
    <i class="fas fa-chart-bar"></i>
    <h3>Select a Teacher to Begin</h3>
    <p>Choose a teacher from the dropdown above to generate a detailed performance and student report.</p>
</div>

<?php else: ?>

<!-- Teacher Hero -->
<div class="teacher-hero">
    <div class="teacher-avatar"><i class="fas fa-chalkboard-teacher"></i></div>
    <div class="teacher-hero-info">
        <h2><?= htmlspecialchars($teacher['full_name']) ?></h2>
        <div style="font-size:13.5px;opacity:.85;margin-top:2px"><?= htmlspecialchars($teacher['specialization'] ?? 'ALS Instructor') ?></div>
        <div class="meta">
            <?php if (!empty($teacher['email'])): ?><span><i class="fas fa-envelope"></i><?= htmlspecialchars($teacher['email']) ?></span><?php endif; ?>
            <?php if (!empty($teacher['phone'])): ?><span><i class="fas fa-phone"></i><?= htmlspecialchars($teacher['phone']) ?></span><?php endif; ?>
            <?php if (!empty($teacher['date_joined'])): ?><span><i class="fas fa-calendar-check"></i>Joined <?= date('M d, Y', strtotime($teacher['date_joined'])) ?></span><?php endif; ?>
        </div>
    </div>
    <div>
        <span class="hero-badge <?= $teacher['status']==='active'?'active':'inactive' ?>">
            <i class="fas fa-circle" style="font-size:7px"></i> <?= ucfirst($teacher['status'] ?? 'N/A') ?>
        </span>
    </div>
</div>

<!-- Stats -->
<div class="stats-row">
    <div class="stat-card" style="--card-accent:#2d6a4f">
        <div class="s-icon" style="--icon-bg:rgba(45,106,79,.1);--icon-color:#2d6a4f"><i class="fas fa-users"></i></div>
        <div class="s-label">Total Students</div>
        <div class="s-value" data-count="<?= $performance_data['total_students'] ?? 0 ?>">0</div>
        <div class="s-sub">Assigned to this teacher</div>
    </div>
    <div class="stat-card" style="--card-accent:#40916c">
        <div class="s-icon" style="--icon-bg:rgba(64,145,108,.1);--icon-color:#40916c"><i class="fas fa-user-check"></i></div>
        <div class="s-label">Active Students</div>
        <div class="s-value" data-count="<?= $performance_data['active_students'] ?? 0 ?>">0</div>
        <div class="s-sub"><?= $completion_rate ?>% of total</div>
        <div class="s-trend" style="color:#40916c"><i class="fas fa-arrow-up"></i><?= $completion_rate ?>%</div>
    </div>
    <div class="stat-card" style="--card-accent:#1e6091">
        <div class="s-icon" style="--icon-bg:rgba(30,96,145,.1);--icon-color:#1e6091"><i class="fas fa-user-graduate"></i></div>
        <div class="s-label">Enrolled Students</div>
        <div class="s-value" data-count="<?= $performance_data['enrolled_students'] ?? 0 ?>">0</div>
        <div class="s-sub"><?= $enrollment_rate ?>% of total</div>
        <div class="s-trend" style="color:#1e6091"><i class="fas fa-arrow-up"></i><?= $enrollment_rate ?>%</div>
    </div>
    <div class="stat-card" style="--card-accent:#c77d0e">
        <div class="s-icon" style="--icon-bg:rgba(199,125,14,.1);--icon-color:#c77d0e"><i class="fas fa-birthday-cake"></i></div>
        <div class="s-label">Average Age</div>
        <div class="s-value" data-count-float="<?= round($performance_data['avg_age'] ?? 0, 1) ?>">0</div>
        <div class="s-sub">Range: <?= $performance_data['min_age'] ?? 0 ?> – <?= $performance_data['max_age'] ?? 0 ?> yrs</div>
    </div>
</div>

<!-- Progress -->
<div class="progress-row">
    <div class="progress-card">
        <div class="pc-header">
            <div class="pc-label"><i class="fas fa-chart-line" style="color:var(--accent);margin-right:5px"></i>Active Rate</div>
            <div class="pc-pct"><?= $completion_rate ?>%</div>
        </div>
        <div class="progress-track"><div class="progress-fill" data-width="<?= $completion_rate ?>" style="--fill-color:#2d6a4f"></div></div>
        <div class="pc-sub"><?= $performance_data['active_students'] ?? 0 ?> active out of <?= $performance_data['total_students'] ?? 0 ?> total</div>
    </div>
    <div class="progress-card">
        <div class="pc-header">
            <div class="pc-label"><i class="fas fa-user-plus" style="color:#1e6091;margin-right:5px"></i>Enrollment Rate</div>
            <div class="pc-pct"><?= $enrollment_rate ?>%</div>
        </div>
        <div class="progress-track"><div class="progress-fill" data-width="<?= $enrollment_rate ?>" style="--fill-color:#1e6091"></div></div>
        <div class="pc-sub"><?= $performance_data['enrolled_students'] ?? 0 ?> enrolled out of <?= $performance_data['total_students'] ?? 0 ?> total</div>
    </div>
    <?php
    $total = $performance_data['total_students'] ?? 0;
    $male_pct   = $total > 0 ? round(($performance_data['male_count']   ?? 0) / $total * 100) : 0;
    $female_pct = 100 - $male_pct;
    ?>
    <div class="progress-card" style="grid-column:span 2">
        <div class="pc-header">
            <div class="pc-label"><i class="fas fa-venus-mars" style="color:#9b4dca;margin-right:5px"></i>Gender Distribution</div>
            <div style="font-size:12px;color:var(--ink3)"><?= $performance_data['male_count'] ?? 0 ?> Male / <?= $performance_data['female_count'] ?? 0 ?> Female</div>
        </div>
        <div class="gender-bar">
            <div class="male-fill"   data-width="<?= $male_pct   ?>" style="width:0%"></div>
            <div class="female-fill" data-width="<?= $female_pct ?>" style="width:0%"></div>
        </div>
        <div class="gender-legend">
            <span><span class="dot" style="background:#1e6091"></span>Male <?= $male_pct ?>%</span>
            <span><span class="dot" style="background:#c06fa0"></span>Female <?= $female_pct ?>%</span>
        </div>
    </div>
</div>

<!-- Smart Insights -->
<?php
$insights = [];
if (($performance_data['total_students'] ?? 0) > 0) {
    if ($completion_rate >= 80) $insights[] = ['icon'=>'fas fa-star','cls'=>'ic-green','title'=>'High Retention','text'=>"Excellent! $completion_rate% of students are actively engaged."];
    elseif ($completion_rate >= 50) $insights[] = ['icon'=>'fas fa-chart-line','cls'=>'ic-gold','title'=>'Moderate Retention','text'=>"$completion_rate% active rate. Consider targeted follow-up with inactive students."];
    else $insights[] = ['icon'=>'fas fa-exclamation-triangle','cls'=>'ic-red','title'=>'Low Retention','text'=>"Only $completion_rate% active. Review attendance and engagement strategies."];
}
if (!empty($program_data)) {
    $top = $program_data[0];
    $pName = strtoupper($top['als_program'] ?: 'Unassigned');
    $insights[] = ['icon'=>'fas fa-graduation-cap','cls'=>'ic-blue','title'=>'Top Program','text'=>"<em>$pName</em> has the most students ({$top['count']})."];
}
if (($performance_data['total_students'] ?? 0) > 0) {
    if (abs($male_pct - $female_pct) <= 10) $insights[] = ['icon'=>'fas fa-balance-scale','cls'=>'ic-green','title'=>'Gender Balanced','text'=>"Nearly equal split ($male_pct% male, $female_pct% female)."];
    elseif ($male_pct > $female_pct) $insights[] = ['icon'=>'fas fa-mars','cls'=>'ic-blue','title'=>'Male-Dominant','text'=>"Male outnumber females by ".($male_pct-$female_pct)."%."];
    else $insights[] = ['icon'=>'fas fa-venus','cls'=>'ic-gold','title'=>'Female-Dominant','text'=>"Female outnumber males by ".($female_pct-$male_pct)."%."];
}
$inactive = $performance_data['inactive_students'] ?? 0;
if ($inactive > 0) $insights[] = ['icon'=>'fas fa-user-slash','cls'=>'ic-red','title'=>'Inactive Students','text'=>"$inactive student(s) are inactive. Review for re-engagement."];
?>
<?php if (!empty($insights)): ?>
<div style="margin-bottom:10px">
    <div style="font-family:'DM Serif Display',serif;font-size:18px;color:var(--ink);margin-bottom:12px">
        <i class="fas fa-lightbulb" style="color:var(--gold);font-size:16px;margin-right:6px"></i>Smart Insights
    </div>
    <div class="insights-row" style="grid-template-columns:repeat(<?= min(count($insights),3) ?>,1fr)">
        <?php foreach($insights as $ins): ?>
        <div class="insight-chip">
            <div class="ic-icon <?= $ins['cls'] ?>"><i class="<?= $ins['icon'] ?>"></i></div>
            <div class="ic-text"><strong><?= $ins['title'] ?></strong><?= $ins['text'] ?></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Charts -->
<div class="charts-grid" style="margin-top:10px">
    <div class="chart-card"><div class="cc-title">Student Status</div><div class="cc-sub">Breakdown by current status</div><canvas id="statusChart"></canvas></div>
    <div class="chart-card"><div class="cc-title">Program Distribution</div><div class="cc-sub">Students per ALS program</div><canvas id="programChart"></canvas></div>
    <div class="chart-card"><div class="cc-title">Age Groups</div><div class="cc-sub">Student age distribution</div><canvas id="ageChart"></canvas></div>
    <div class="chart-card wide"><div class="cc-title">Enrollment Trend</div><div class="cc-sub">Monthly enrollments over the last 12 months</div><canvas id="trendChart"></canvas></div>
</div>

<!-- Student Table -->
<div class="table-card">
    <div class="table-header">
        <div>
            <h3>Assigned Students</h3>
            <div class="th-sub"><?= count($students) ?> student(s) assigned</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center" class="no-print">
            <span style="font-size:12px;color:var(--ink3)" id="filteredCount"><?= count($students) ?> shown</span>
        </div>
    </div>
    <div class="filter-bar no-print">
        <input type="text" id="searchInput" placeholder="🔍  Search by name, ID, or program…" oninput="filterTable()">
        <select id="statusFilter" onchange="filterTable()">
            <option value="">All Statuses</option>
            <option value="active">Active</option>
            <option value="enrolled">Enrolled</option>
            <option value="inactive">Inactive</option>
        </select>
        <select id="sexFilter" onchange="filterTable()">
            <option value="">All Genders</option>
            <option value="male">Male</option>
            <option value="female">Female</option>
        </select>
        <select id="programFilter" onchange="filterTable()">
            <option value="">All Programs</option>
            <?php foreach($program_data as $pd): ?>
            <option value="<?= strtolower($pd['als_program']) ?>"><?= strtoupper($pd['als_program'] ?: 'Unassigned') ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="table-wrap">
        <table id="studentsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th style="cursor:pointer" onclick="sortTable(1)">Name ⇅</th>
                    <th style="cursor:pointer" onclick="sortTable(2)">Age ⇅</th>
                    <th>Sex</th><th>Program</th><th>Status</th>
                    <th style="cursor:pointer" onclick="sortTable(6)">Enrolled ⇅</th>
                </tr>
            </thead>
            <tbody id="studentsTbody">
                <?php if (empty($students)): ?>
                <tr><td colspan="7" style="text-align:center;padding:30px;color:var(--ink3)">
                    <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;opacity:.5"></i>No students assigned yet.
                </td></tr>
                <?php else: ?>
                <?php foreach ($students as $i => $s): ?>
                <tr data-status="<?= strtolower($s['status']) ?>"
                    data-sex="<?= strtolower($s['sex']) ?>"
                    data-program="<?= strtolower($s['als_program']) ?>">
                    <td style="color:var(--ink3);font-size:12px"><?= $i + 1 ?></td>
                    <td>
                        <div class="student-name"><?= htmlspecialchars($s['last_name'].', '.$s['first_name']) ?></div>
                        <div class="student-id">ID: <?= $s['student_id'] ?></div>
                    </td>
                    <td><?= $s['age'] ?></td>
                    <td>
                        <span class="badge badge-<?= strtolower($s['sex']) ?>">
                            <i class="fas fa-<?= strtolower($s['sex'])==='male'?'mars':'venus' ?>" style="font-size:9px"></i>
                            <?= ucfirst($s['sex']) ?>
                        </span>
                    </td>
                    <td><span class="badge badge-program"><?= $s['als_program'] ? strtoupper($s['als_program']) : 'N/A' ?></span></td>
                    <td>
                        <?php $sc=strtolower($s['status']);$cls=$sc==='active'?'badge-active':($sc==='enrolled'?'badge-enrolled':'badge-inactive');$ico=$sc==='active'?'check-circle':($sc==='enrolled'?'user-plus':'minus-circle'); ?>
                        <span class="badge <?= $cls ?>"><i class="fas fa-<?= $ico ?>" style="font-size:9px"></i> <?= ucfirst($s['status']) ?></span>
                    </td>
                    <td style="font-size:13px;color:var(--ink3)"><?= $s['enrollment_date'] ? date('M d, Y', strtotime($s['enrollment_date'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Counter animation
function animateCount(el,target,isFloat){
    const dur=900,steps=40;let step=0;
    const timer=setInterval(()=>{step++;const val=isFloat?(target*step/steps).toFixed(1):Math.round(target*step/steps);el.textContent=val;if(step>=steps){el.textContent=isFloat?target.toFixed(1):target;clearInterval(timer);}},dur/steps);
}
document.querySelectorAll('[data-count]').forEach(el=>animateCount(el,+el.dataset.count,false));
document.querySelectorAll('[data-count-float]').forEach(el=>animateCount(el,+el.dataset.countFloat,true));

// Progress bars
setTimeout(()=>{
    document.querySelectorAll('.progress-fill').forEach(el=>el.style.width=el.dataset.width+'%');
    document.querySelectorAll('.male-fill,.female-fill').forEach(el=>el.style.width=el.dataset.width+'%');
},100);

Chart.defaults.font.family="'DM Sans', sans-serif";
Chart.defaults.color='#7b82a0';
Chart.defaults.plugins.legend.labels.padding=16;
Chart.defaults.plugins.legend.labels.usePointStyle=true;
const PALETTE=['#2d6a4f','#1e6091','#c77d0e','#c0392b','#805ad5','#2980b9','#27ae60','#e67e22'];

<?php if ($teacher_id && $teacher): ?>
new Chart(document.getElementById('statusChart'),{type:'doughnut',data:{labels:['Active','Enrolled','Inactive'],datasets:[{data:[<?= $performance_data['active_students']??0 ?>,<?= $performance_data['enrolled_students']??0 ?>,<?= $performance_data['inactive_students']??0 ?>],backgroundColor:['#2d6a4f','#1e6091','#a0a0a0'],borderWidth:0,hoverOffset:8}]},options:{cutout:'62%',plugins:{legend:{position:'bottom'},tooltip:{callbacks:{label:ctx=>` ${ctx.label}: ${ctx.parsed} (${Math.round(ctx.parsed/ctx.dataset.data.reduce((a,b)=>a+b,0)*100)}%)`}}}}});

new Chart(document.getElementById('programChart'),{type:'bar',data:{labels:<?= json_encode(array_map(fn($p)=>strtoupper($p?:'Unassigned'),$program_labels)) ?>,datasets:[{label:'Students',data:<?= json_encode($program_counts) ?>,backgroundColor:PALETTE,borderRadius:7,borderSkipped:false}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'},ticks:{precision:0}},x:{grid:{display:false}}}}});

new Chart(document.getElementById('ageChart'),{type:'bar',data:{labels:<?= json_encode($age_labels) ?>,datasets:[{label:'Students',data:<?= json_encode($age_counts) ?>,backgroundColor:'#c77d0e',borderRadius:7,borderSkipped:false}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'},ticks:{precision:0}},x:{grid:{display:false}}}}});

new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:<?= json_encode($enroll_labels) ?>,datasets:[{label:'New Enrollments',data:<?= json_encode($enroll_counts) ?>,fill:true,backgroundColor:'rgba(45,106,79,.1)',borderColor:'#2d6a4f',pointBackgroundColor:'#2d6a4f',pointRadius:5,pointHoverRadius:7,tension:0.4,borderWidth:2.5}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.05)'},ticks:{precision:0}},x:{grid:{display:false}}}}});
<?php endif; ?>

function filterTable(){
    const q=document.getElementById('searchInput').value.toLowerCase();
    const status=document.getElementById('statusFilter').value.toLowerCase();
    const sex=document.getElementById('sexFilter').value.toLowerCase();
    const program=document.getElementById('programFilter').value.toLowerCase();
    const rows=document.querySelectorAll('#studentsTbody tr[data-status]');
    let shown=0;
    rows.forEach(row=>{
        const text=row.textContent.toLowerCase();
        const match=(!q||text.includes(q))&&(!status||row.dataset.status===status)&&(!sex||row.dataset.sex===sex)&&(!program||row.dataset.program===program);
        row.style.display=match?'':'none';
        if(match)shown++;
    });
    document.getElementById('filteredCount').textContent=shown+' shown';
}

let sortDir={};
function sortTable(col){
    sortDir[col]=!sortDir[col];
    const tbody=document.getElementById('studentsTbody');
    const rows=Array.from(tbody.querySelectorAll('tr[data-status]'));
    rows.sort((a,b)=>{
        const A=(a.cells[col]?.textContent||'').trim(),B=(b.cells[col]?.textContent||'').trim();
        const numA=parseFloat(A),numB=parseFloat(B);
        const cmp=!isNaN(numA)&&!isNaN(numB)?numA-numB:A.localeCompare(B);
        return sortDir[col]?cmp:-cmp;
    });
    rows.forEach(r=>tbody.appendChild(r));
}

function exportCSV(){
    const rows=document.querySelectorAll('#studentsTable tr');
    const csv=[];
    rows.forEach(row=>{
        if(row.style.display==='none')return;
        const cells=Array.from(row.querySelectorAll('th,td'));
        csv.push(cells.map(c=>'"'+c.textContent.trim().replace(/"/g,'""')+'"').join(','));
    });
    const a=document.createElement('a');
    a.href=URL.createObjectURL(new Blob([csv.join('\n')],{type:'text/csv'}));
    a.download='teacher_report_<?= $teacher_id ?>_<?= date("Ymd") ?>.csv';
    a.click();
}
</script>
</body>
</html>