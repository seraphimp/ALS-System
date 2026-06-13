<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard_functions.php';

secure_session_start();

if (!isset($_SESSION['admin_id']) || !is_admin_logged_in()) {
    redirect('/admin-secure');
    exit;
}

$admin = get_admin_details($conn, $_SESSION['admin_id']);

// ============================================================
// AJAX: Poll — returns ALL current unread items
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'poll_notifications') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $table_check = $conn->query("SHOW TABLES LIKE 'preregistration_notifications'");
    if ($table_check->num_rows == 0) {
        echo json_encode(['error' => 'Notifications table not found', 'unread_count' => 0, 'items' => []]);
        ob_end_clean();
        exit;
    }

    $count_result = $conn->query("SELECT COUNT(DISTINCT preregistration_id) as c FROM preregistration_notifications WHERE is_read = 0");
    $unread_count = $count_result ? (int)$count_result->fetch_assoc()['c'] : 0;

    $items = [];
    $query = "SELECT n.preregistration_id,
                     MAX(n.created_at) as created_at,
                     p.tracking_code, p.first_name, p.last_name, p.status
              FROM preregistration_notifications n
              JOIN preregistrations p ON n.preregistration_id = p.preregistration_id
              WHERE n.is_read = 0
              GROUP BY n.preregistration_id
              ORDER BY MAX(n.created_at) DESC
              LIMIT 20";

    $result = $conn->query($query);
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Convert MySQL DATETIME to ISO format so JS new Date() parses it correctly
            $row['created_at'] = str_replace(' ', 'T', $row['created_at']);
            $items[] = $row;
        }
    }

    echo json_encode(['unread_count' => $unread_count, 'items' => $items, 'timestamp' => time()]);
    ob_end_clean();
    exit;
}

// ============================================================
// AJAX: Mark read
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'mark_read') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    $body     = json_decode(file_get_contents('php://input'), true);
    $mode     = $body['mode'] ?? 'all';
    $id       = isset($body['preregistration_id']) ? (int)$body['preregistration_id'] : 0;
    $admin_id = (int)$_SESSION['admin_id'];

    if ($mode === 'one' && $id > 0) {
        $conn->query("UPDATE preregistration_notifications SET is_read=1, admin_id=$admin_id WHERE preregistration_id=$id");
    } else {
        $conn->query("UPDATE preregistration_notifications SET is_read=1, admin_id=$admin_id WHERE is_read=0");
    }

    $count_result = $conn->query("SELECT COUNT(DISTINCT preregistration_id) as c FROM preregistration_notifications WHERE is_read=0");
    $new_count    = $count_result ? (int)$count_result->fetch_assoc()['c'] : 0;

    echo json_encode(['success' => true, 'unread_count' => $new_count]);
    ob_end_clean();
    exit;
}

// ============================================================
// PRE-REGISTRATION ACTIONS
// ============================================================
$pr_message      = '';
$pr_message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pr_action'])) {
    $prereg_id = intval($_POST['prereg_id']);
    $action    = $_POST['pr_action'];
    $notes     = $conn->real_escape_string($_POST['notes'] ?? '');

    if ($action === 'approve' || $action === 'reject') {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $stmt   = $conn->prepare("UPDATE preregistrations SET status=?, reviewed_by=?, reviewed_at=NOW(), review_notes=? WHERE preregistration_id=?");
        $stmt->bind_param("sisi", $status, $_SESSION['admin_id'], $notes, $prereg_id);

        if ($stmt->execute()) {
            $pr_message      = "Pre-registration #$prereg_id has been {$status} successfully!";
            $pr_message_type = 'success';

            $conn->query("UPDATE preregistration_notifications SET is_read=1, admin_id={$_SESSION['admin_id']} WHERE preregistration_id=$prereg_id");

            $pr_details = $conn->query("SELECT * FROM preregistrations WHERE preregistration_id=$prereg_id")->fetch_assoc();
            if ($pr_details && !empty($pr_details['email'])) {
                $subject = "ALS Pre-registration Update - " . ucfirst($status);
                $body2   = "Dear {$pr_details['first_name']} {$pr_details['last_name']},\n\n";
                if ($status === 'approved') {
                    $link   = "https://{$_SERVER['HTTP_HOST']}/accessenrollment?code={$pr_details['access_code']}&tracking={$pr_details['tracking_code']}";
                    $body2 .= "Congratulations! Your pre-registration has been APPROVED.\n\nEnrollment link:\n$link\n\nTracking: {$pr_details['tracking_code']}\n\n";
                } else {
                    $body2 .= "Your pre-registration has been REJECTED.\n\n";
                    if (!empty($notes)) $body2 .= "Reason: $notes\n\n";
                }
                $body2 .= "Thank you,\nALS La Carlota City Division";
                mail($pr_details['email'], $subject, $body2, "From: noreply@als-system.online");
            }
        } else {
            $pr_message      = "Error: " . $conn->error;
            $pr_message_type = 'error';
        }
        $stmt->close();
    }
}

// ============================================================
// STATS
// ============================================================
$activeStudents     = get_active_student_count($conn);
$registeredTeachers = get_teacher_count($conn);
$learningMaterials  = get_learning_material_count($conn);
$today              = date('Y-m-d');
$enrolledToday      = (int)($conn->query("SELECT COUNT(*) c FROM students WHERE DATE(enrollment_date)='$today' AND status='enrolled'")->fetch_assoc()['c'] ?? 0);
$monthStart         = date('Y-m-01');
$totalThisMonth     = (int)($conn->query("SELECT COUNT(*) c FROM students WHERE enrollment_date>='$monthStart' AND status='enrolled'")->fetch_assoc()['c'] ?? 0);
$unassigned         = (int)($conn->query("SELECT COUNT(*) c FROM students WHERE teacher_id IS NULL AND status='enrolled'")->fetch_assoc()['c'] ?? 0);
$ungraded           = (int)($conn->query("SELECT COUNT(*) c FROM activity_submissions WHERE status='submitted' AND score IS NULL")->fetch_assoc()['c'] ?? 0);

$barangayData = [];
$bgRes = $conn->query("SELECT b.name, COUNT(s.id) as count FROM students s JOIN barangays b ON s.current_barangay_id=b.barangay_id WHERE s.status='enrolled' GROUP BY b.barangay_id ORDER BY count DESC LIMIT 5");
if ($bgRes) while ($row = $bgRes->fetch_assoc()) $barangayData[] = $row;

$trendCounts = $trendLabels = [];
for ($i = 6; $i >= 0; $i--) {
    $d            = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('M d', strtotime($d));
    $trendCounts[] = (int)($conn->query("SELECT COUNT(*) c FROM students WHERE DATE(enrollment_date)='$d'")->fetch_assoc()['c'] ?? 0);
}
$hasTrendData = array_sum($trendCounts) > 0;

$recentEnrollments = [];
$reRes = $conn->query("SELECT s.student_id,s.first_name,s.last_name,b.name as barangay,t.full_name as teacher_name,s.enrollment_date FROM students s LEFT JOIN barangays b ON s.current_barangay_id=b.barangay_id LEFT JOIN teachers t ON s.teacher_id=t.teacher_id WHERE s.status='enrolled' ORDER BY s.enrollment_date DESC LIMIT 5");
if ($reRes) while ($row = $reRes->fetch_assoc()) $recentEnrollments[] = $row;

$unread_notifications = (int)($conn->query("SELECT COUNT(DISTINCT preregistration_id) c FROM preregistration_notifications WHERE is_read=0")->fetch_assoc()['c'] ?? 0);
$pending_pr_count     = (int)($conn->query("SELECT COUNT(*) c FROM preregistrations WHERE status='pending'")->fetch_assoc()['c'] ?? 0);

$recent_preregistrations = [];
$prRes = $conn->query("SELECT p.*, b.name as barangay_name,
                       (SELECT COUNT(*) FROM preregistration_notifications
                        WHERE preregistration_id=p.preregistration_id AND is_read=0) as has_unread
                       FROM preregistrations p
                       LEFT JOIN barangays b ON p.current_barangay_id=b.barangay_id
                       WHERE p.status='pending'
                       ORDER BY p.submitted_at DESC LIMIT 10");
if ($prRes) while ($row = $prRes->fetch_assoc()) $recent_preregistrations[] = $row;

// Current unread notifications for dropdown (PHP render)
$notifications = [];
$notifRes = $conn->query("SELECT n.notification_id, n.preregistration_id,
                          MAX(n.created_at) as created_at,
                          p.tracking_code, p.first_name, p.last_name
                          FROM preregistration_notifications n
                          JOIN preregistrations p ON n.preregistration_id=p.preregistration_id
                          WHERE n.is_read=0
                          GROUP BY n.preregistration_id
                          ORDER BY MAX(n.created_at) DESC LIMIT 5");
if ($notifRes) while ($row = $notifRes->fetch_assoc()) $notifications[] = $row;

// IDs already rendered — JS must NOT toast these on load
$initialRenderedIds = array_map('intval', array_column($notifications, 'preregistration_id'));

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<link rel="icon" type="image/png" href="/logo">
<title>ALS Admin Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
--blue:#1d4ed8;--blue-dark:#1e3a8a;--blue-darker:#172554;--blue-mid:#2563eb;
--blue-light:#eff6ff;--blue-soft:#dbeafe;--blue-border:#bfdbfe;
--emerald:#059669;--emerald-light:#d1fae5;
--amber:#d97706;--amber-light:#fef3c7;--amber-border:#fcd34d;
--violet:#7c3aed;--violet-light:#ede9fe;
--rose:#e11d48;--rose-light:#fff1f2;
--bg:#f0f4ff;--bg-mid:#e4eaf6;--border:#e2e8f0;--white:#fff;
--text-dark:#0f172a;--text-mid:#334155;--text-light:#64748b;--text-xlight:#94a3b8;
--sidebar-w:270px;--r-md:14px;--r-lg:20px;
--shadow-md:0 4px 16px rgba(29,78,216,.11),0 2px 4px rgba(0,0,0,.04);
--shadow-lg:0 8px 32px rgba(29,78,216,.16),0 2px 8px rgba(0,0,0,.07);
--ease:cubic-bezier(.4,0,.2,1)}
body{font-family:'Plus Jakarta Sans',sans-serif;background:var(--bg);color:var(--text-dark);overflow-x:hidden}
.app{display:flex;min-height:100vh}
.main{flex:1;margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w));padding-bottom:48px}

/* PUSH BANNER */
#pushBanner{display:none;align-items:center;gap:12px;background:linear-gradient(135deg,#172554,#1d4ed8);color:#fff;padding:12px 24px;font-size:.84rem;font-weight:500;border-bottom:2px solid var(--blue-border)}
#pushBanner.show{display:flex}
.push-allow-btn{margin-left:auto;background:#fff;color:var(--blue-dark);border:none;padding:6px 18px;border-radius:40px;font-size:.79rem;font-weight:700;cursor:pointer;font-family:inherit;flex-shrink:0;transition:all .18s}
.push-allow-btn:hover{background:var(--amber);color:#fff}
.push-dismiss-btn{background:rgba(255,255,255,.12);color:rgba(255,255,255,.7);border:none;width:26px;height:26px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.75rem;flex-shrink:0}
.push-dismiss-btn:hover{background:rgba(255,255,255,.22);color:#fff}

/* TOPBAR */
.topbar{position:sticky;top:0;z-index:100;background:rgba(240,244,255,.94);backdrop-filter:blur(14px);border-bottom:1.5px solid var(--border);padding:13px 32px;display:flex;align-items:center;justify-content:space-between}
.page-breadcrumb{display:flex;align-items:center;gap:5px;font-size:.74rem;color:var(--text-xlight);margin-bottom:2px}
.bc-icon{color:var(--blue);font-size:.65rem}.bc-sep{font-size:.55rem}.bc-cur{color:var(--text-mid);font-weight:600}
.topbar h1{font-size:1.25rem;font-weight:800;letter-spacing:-.02em}
.topbar-right{display:flex;align-items:center;gap:10px}
.tb-date{display:flex;align-items:center;gap:7px;background:var(--white);padding:7px 14px;border-radius:40px;font-size:.8rem;font-weight:500;color:var(--text-mid);border:1.5px solid var(--border)}
.tb-date i{color:var(--blue)}

/* BELL */
.tb-notif{width:38px;height:38px;background:var(--white);border:1.5px solid var(--border);border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-light);font-size:.92rem;position:relative;transition:all .18s var(--ease);user-select:none}
.tb-notif:hover{background:var(--blue-light);border-color:var(--blue-border);color:var(--blue)}
.notif-dot{position:absolute;top:7px;right:7px;width:7px;height:7px;background:var(--amber);border-radius:50%;border:1.5px solid var(--bg);display:none}
@keyframes bellPulse{0%,100%{transform:scale(1) rotate(0)}20%{transform:scale(1.18) rotate(-14deg)}40%{transform:scale(1.22) rotate(12deg)}60%{transform:scale(1.16) rotate(-8deg)}80%{transform:scale(1.1) rotate(6deg)}}
.bell-ring{animation:bellPulse .6s ease}

/* DROPDOWN */
.notif-wrap{position:relative}
.notif-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:340px;background:var(--white);border-radius:var(--r-md);box-shadow:var(--shadow-lg);border:1.5px solid var(--border);z-index:9999;display:none;overflow:hidden}
.notif-dropdown.show{display:block;animation:notifIn .22s cubic-bezier(.34,1.56,.64,1)}
@keyframes notifIn{from{opacity:0;transform:translateY(-10px) scale(.97)}to{opacity:1;transform:none}}
.notif-header{padding:13px 16px;border-bottom:1.5px solid var(--border);font-weight:700;font-size:.88rem;display:flex;justify-content:space-between;align-items:center;background:var(--blue-light);gap:8px}
.notif-header-left{display:flex;align-items:center;gap:8px}
.notif-badge{background:var(--amber);color:#fff;padding:2px 8px;border-radius:20px;font-size:.72rem;font-weight:700}
.notif-badge.bump{animation:badgeBump .28s cubic-bezier(.34,1.56,.64,1)}
@keyframes badgeBump{from{transform:scale(.6)}to{transform:scale(1)}}
.notif-mark-all{font-size:.72rem;font-weight:600;color:var(--blue);cursor:pointer;padding:4px 10px;border-radius:40px;background:var(--white);border:1.5px solid var(--blue-border);transition:all .18s;white-space:nowrap;display:flex;align-items:center;gap:4px;font-family:inherit}
.notif-mark-all:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.notif-mark-all:disabled{opacity:.5;pointer-events:none}
.notif-list{max-height:280px;overflow-y:auto}
.notif-item{padding:12px 18px;border-bottom:1px solid var(--bg-mid);cursor:pointer;transition:background .15s;display:flex;gap:12px;align-items:flex-start}
.notif-item:hover{background:var(--blue-light)}
.notif-item:last-child{border-bottom:none}
.notif-item.unread{background:#fffbeb}
.notif-item.unread:hover{background:var(--amber-light)}
.notif-item-icon{width:34px;height:34px;background:var(--amber-light);color:var(--amber);border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0}
.notif-item-name{font-size:.82rem;font-weight:700;color:var(--text-dark);margin-bottom:2px}
.notif-item-sub{font-size:.76rem;color:var(--text-light)}
.notif-item-time{font-size:.68rem;color:var(--text-xlight);margin-top:3px}
.notif-empty{padding:28px 18px;text-align:center;color:var(--text-light);font-size:.83rem}
.notif-empty i{font-size:1.8rem;opacity:.25;display:block;margin-bottom:8px}
.notif-footer{padding:11px 18px;border-top:1.5px solid var(--border);text-align:center;background:var(--bg)}
.notif-footer a{color:var(--blue);text-decoration:none;font-size:.8rem;font-weight:600}
.notif-footer a:hover{text-decoration:underline}

/* TOAST */
#toastContainer{position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column-reverse;gap:10px;pointer-events:none}
.toast{pointer-events:auto;display:flex;align-items:flex-start;gap:12px;background:#0f172a;border:1.5px solid #1e3a8a;border-left:4px solid #f59e0b;border-radius:13px;padding:13px 16px;min-width:300px;max-width:360px;box-shadow:0 8px 32px rgba(0,0,0,.45);cursor:pointer;animation:toastIn .38s cubic-bezier(.34,1.56,.64,1) both;position:relative;overflow:hidden;transition:transform .18s}
.toast:hover{transform:translateY(-2px)}
.toast.removing{animation:toastOut .28s ease forwards}
@keyframes toastIn{from{opacity:0;transform:translateX(60px) scale(.92)}to{opacity:1;transform:none}}
@keyframes toastOut{from{opacity:1;max-height:120px}to{opacity:0;transform:translateX(60px);max-height:0;padding:0;margin:0;border:none}}
.toast-icon{width:36px;height:36px;background:rgba(245,158,11,.15);border:1.5px solid rgba(245,158,11,.3);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#f59e0b;font-size:.9rem;flex-shrink:0}
.toast-title{font-size:.84rem;font-weight:700;color:#f1f5f9;margin-bottom:2px}
.toast-sub{font-size:.75rem;color:#64748b}
.toast-time{font-size:.68rem;color:#475569;margin-top:4px}
.toast-close{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.06);border:none;color:#64748b;font-size:.72rem;width:20px;height:20px;border-radius:5px;cursor:pointer;display:flex;align-items:center;justify-content:center}
.toast-close:hover{background:rgba(255,255,255,.14);color:#f1f5f9}
.toast-progress{position:absolute;bottom:0;left:0;height:3px;background:linear-gradient(90deg,#f59e0b,#fbbf24);animation:toastProgress 6s linear forwards}
@keyframes toastProgress{from{width:100%}to{width:0%}}

/* USER CHIP */
.user-chip{display:flex;align-items:center;gap:9px;background:var(--white);border:1.5px solid var(--border);padding:5px 14px 5px 5px;border-radius:40px;cursor:pointer;transition:all .18s var(--ease)}
.user-chip:hover{border-color:var(--blue-border);background:var(--blue-light)}
.uc-avatar{width:30px;height:30px;background:linear-gradient(135deg,var(--blue-dark),var(--blue-mid));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.78rem}
.uc-name{font-size:.82rem;font-weight:600}
.uc-chev{font-size:.58rem;color:var(--text-xlight)}

/* CONTENT */
.content{padding:26px 32px}
.alert{padding:16px 20px;border-radius:var(--r-md);margin-bottom:24px;border-left:5px solid;font-weight:500;font-size:.9rem;display:flex;align-items:center;gap:12px}
.alert-success{background:var(--emerald-light);border-left-color:var(--emerald);color:var(--emerald)}
.alert-error{background:var(--rose-light);border-left-color:var(--rose);color:var(--rose)}

/* HERO */
.hero{background:linear-gradient(135deg,var(--blue-darker) 0%,var(--blue-mid) 100%);border-radius:var(--r-lg);padding:30px 36px;color:#fff;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:24px;position:relative;overflow:hidden;box-shadow:0 8px 32px rgba(29,78,216,.28)}
.hero .deco-a{position:absolute;top:-55px;right:-55px;width:230px;height:230px;border-radius:50%;background:rgba(255,255,255,.07);pointer-events:none}
.hero .deco-b{position:absolute;bottom:-75px;right:150px;width:170px;height:170px;border-radius:50%;background:rgba(255,255,255,.05);pointer-events:none}
.hero-left{position:relative;z-index:1}
.hero-tag{display:inline-flex;align-items:center;gap:6px;font-size:.73rem;font-weight:600;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.22);padding:4px 12px;border-radius:40px;margin-bottom:12px}
.hero-title{font-family:'DM Serif Display',serif;font-size:2rem;line-height:1.2;margin-bottom:7px}
.hero-sub{font-size:.87rem;opacity:.76}
.hero-stats{display:flex;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.2);border-radius:var(--r-md);overflow:hidden;position:relative;z-index:1;flex-shrink:0;backdrop-filter:blur(8px)}
.hstat{text-align:center;padding:16px 26px;position:relative}
.hstat+.hstat::before{content:'';position:absolute;left:0;top:18%;bottom:18%;width:1px;background:rgba(255,255,255,.2)}
.hstat .hval{font-size:1.9rem;font-weight:800;line-height:1}
.hstat .hlbl{font-size:.69rem;opacity:.7;margin-top:4px;font-weight:500}

/* METRICS */
.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:15px;margin-bottom:22px}
.mcard{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:20px 20px 17px;position:relative;overflow:hidden;transition:all .2s var(--ease)}
.mcard:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);border-color:var(--blue-border)}
.mcard::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r-md) var(--r-md) 0 0}
.mcard.cb::before{background:var(--blue)}.mcard.ce::before{background:var(--emerald)}.mcard.ca::before{background:var(--amber)}.mcard.cv::before{background:var(--violet)}
.mcard-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:15px}
.mcard-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0}
.cb .mcard-icon{background:var(--blue-light);color:var(--blue)}.ce .mcard-icon{background:var(--emerald-light);color:var(--emerald)}.ca .mcard-icon{background:var(--amber-light);color:var(--amber)}.cv .mcard-icon{background:var(--violet-light);color:var(--violet)}
.mcard-badge{font-size:.68rem;font-weight:600;padding:3px 8px;border-radius:40px;background:var(--bg);color:var(--text-light);border:1px solid var(--border)}
.mcard-num{font-size:2.1rem;font-weight:800;line-height:1;letter-spacing:-.02em;margin-bottom:4px}
.mcard-desc{font-size:.81rem;color:var(--text-light);font-weight:500}

.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:13px}
.sec-head h2{font-size:.93rem;font-weight:700;color:var(--text-dark);display:flex;align-items:center;gap:8px}
.sec-head h2 i{color:var(--blue);font-size:.85rem}
.sec-meta{font-size:.77rem;color:var(--text-xlight);font-weight:500}
.view-all{font-size:.81rem;font-weight:600;color:var(--blue);text-decoration:none;display:flex;align-items:center;gap:4px}
.view-all:hover{color:var(--blue-dark)}

.charts-row{display:grid;grid-template-columns:1fr 330px;gap:15px;margin-bottom:22px}
.chart-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:22px}
.chart-wrap{position:relative;width:100%;height:220px}
.bgy-list{display:flex;flex-direction:column;gap:13px}
.bgy-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:5px}
.bgy-name{font-size:.84rem;font-weight:600;color:var(--text-dark)}
.bgy-count{font-size:.74rem;font-weight:700;background:var(--blue-light);color:var(--blue);padding:2px 9px;border-radius:40px;border:1px solid var(--blue-soft)}
.bgy-track{height:6px;background:var(--bg-mid);border-radius:40px;overflow:hidden}
.bgy-fill{height:100%;border-radius:40px;background:linear-gradient(90deg,var(--blue-dark),var(--blue-mid));transition:width 1s var(--ease)}

.actions-row{margin-bottom:22px}
.action-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.acard{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:16px;text-decoration:none;color:var(--text-dark);display:flex;align-items:center;gap:13px;transition:all .18s var(--ease)}
.acard:hover{box-shadow:var(--shadow-md);transform:translateY(-2px);border-color:var(--blue-border);background:var(--blue-light)}
.acard.primary{background:linear-gradient(135deg,var(--blue-darker),var(--blue-mid));border:none;color:#fff;box-shadow:0 4px 16px rgba(29,78,216,.3)}
.acard.primary:hover{background:linear-gradient(135deg,#0d1f4e,var(--blue-dark));transform:translateY(-2px)}
.acard-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.98rem;flex-shrink:0;background:var(--bg);color:var(--blue);transition:all .18s var(--ease)}
.acard:not(.primary):hover .acard-icon{background:var(--blue-soft)}
.acard.primary .acard-icon{background:rgba(255,255,255,.18);color:#fff}
.acard-title{font-size:.87rem;font-weight:700}.acard-sub{font-size:.73rem;margin-top:2px;color:var(--text-light)}
.acard.primary .acard-sub{color:rgba(255,255,255,.62)}

.pr-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);margin-bottom:24px;overflow:hidden}
.pr-card-header{padding:18px 24px;border-bottom:1.5px solid var(--border);background:var(--amber-light);display:flex;align-items:center;gap:12px}
.pr-card-header i{font-size:1.2rem;color:var(--amber);width:32px;height:32px;display:flex;align-items:center;justify-content:center;background:var(--white);border-radius:8px;border:1.5px solid var(--amber-border)}
.pr-card-header h3{font-size:1rem;font-weight:700;color:var(--text-dark)}
.pr-card-body{padding:24px;overflow-x:auto}
.pr-table{width:100%;border-collapse:collapse;min-width:640px}
.pr-table th{text-align:left;padding:12px 8px 8px 0;font-size:.7rem;font-weight:700;text-transform:uppercase;color:var(--text-xlight);border-bottom:2px solid var(--border)}
.pr-table td{padding:10px 8px 10px 0;font-size:.8rem;color:var(--text-mid);border-bottom:1px solid var(--bg-mid);vertical-align:middle}
.pr-table tr:last-child td{border-bottom:none}
.pr-badge-pending{background:var(--amber-light);color:var(--amber);padding:3px 8px;border-radius:40px;font-size:.7rem;font-weight:600}
.action-buttons{display:flex;gap:5px}
.btn-sm{padding:5px 10px;border-radius:6px;font-size:.7rem;font-weight:600;border:none;cursor:pointer;transition:all .2s;font-family:inherit}
.btn-approve{background:var(--emerald-light);color:var(--emerald)}.btn-approve:hover{background:var(--emerald);color:#fff}
.btn-reject{background:var(--rose-light);color:var(--rose)}.btn-reject:hover{background:var(--rose);color:#fff}
.btn-view{background:var(--blue-light);color:var(--blue)}.btn-view:hover{background:var(--blue);color:#fff}

.modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);backdrop-filter:blur(5px);z-index:1000;display:none;align-items:center;justify-content:center;padding:16px}
.modal-backdrop.open{display:flex}
.modal-box{background:#fff;border-radius:var(--r-lg);box-shadow:var(--shadow-lg);width:100%;max-width:540px;max-height:90vh;overflow:hidden;display:flex;flex-direction:column;animation:modalIn .28s cubic-bezier(.34,1.56,.64,1)}
@keyframes modalIn{from{opacity:0;transform:scale(.93) translateY(18px)}to{opacity:1;transform:none}}
.modal-head{padding:18px 22px 16px;background:linear-gradient(135deg,var(--blue-dark),var(--blue));color:#fff;display:flex;align-items:center;justify-content:space-between}
.modal-head h2{font-size:1rem;font-weight:700}
.modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;font-size:1rem;cursor:pointer;width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center}
.modal-close:hover{background:rgba(255,255,255,.28)}
.modal-body{padding:22px;overflow-y:auto}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end}
.btn-modal-close{padding:8px 20px;border-radius:40px;font-size:.83rem;font-weight:600;border:1.5px solid var(--border);background:var(--bg);color:var(--text-mid);cursor:pointer;font-family:inherit}
.btn-modal-close:hover{background:var(--blue-light);border-color:var(--blue-border);color:var(--blue)}
.info-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-bottom:18px}
.info-label{font-size:.7rem;color:var(--text-light);margin-bottom:2px;font-weight:600;text-transform:uppercase}
.info-value{font-weight:600;font-size:.88rem;color:var(--text-dark)}
.modal-section-title{font-size:.82rem;font-weight:700;color:var(--text-mid);margin:18px 0 10px;padding-bottom:6px;border-bottom:1.5px solid var(--border);text-transform:uppercase;letter-spacing:.4px}

.bottom-grid{display:grid;grid-template-columns:282px 1fr;gap:15px}
.status-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:22px}
.sc-title{font-size:.92rem;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.sc-title i{color:var(--blue)}
.sc-row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--bg-mid)}
.sc-row:last-child{border-bottom:none}
.sc-lbl{font-size:.81rem;color:var(--text-mid);font-weight:500}
.sc-val{font-size:.81rem;font-weight:700;color:var(--text-dark)}
.pill{padding:3px 10px;border-radius:40px;font-size:.7rem;font-weight:700}
.p-blue{background:var(--blue-light);color:var(--blue);border:1px solid var(--blue-soft)}
.p-green{background:var(--emerald-light);color:var(--emerald)}
.p-amber{background:var(--amber-light);color:var(--amber);border:1px solid var(--amber-border)}
.table-card{background:var(--white);border:1.5px solid var(--border);border-radius:var(--r-md);padding:22px;overflow-x:auto}
.dtable{width:100%;border-collapse:collapse;min-width:540px}
.dtable thead th{text-align:left;padding:0 12px 10px 0;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.55px;color:var(--text-xlight);border-bottom:2px solid var(--border)}
.dtable tbody tr{transition:background .15s}
.dtable tbody tr:hover td{background:var(--blue-light)}
.dtable tbody td{padding:11px 12px 11px 0;font-size:.84rem;color:var(--text-mid);border-bottom:1px solid var(--bg-mid);vertical-align:middle}
.dtable tbody tr:last-child td{border-bottom:none}
.chip-id{font-family:'Courier New',monospace;font-size:.73rem;font-weight:700;background:var(--bg);color:var(--text-mid);padding:3px 8px;border-radius:5px;border:1px solid var(--border)}
.chip-name{font-weight:600;color:var(--text-dark)}
.chip-bgy{background:var(--blue-light);color:var(--blue);padding:3px 9px;border-radius:40px;font-size:.74rem;font-weight:600;border:1px solid var(--blue-soft)}
.chip-teach{background:var(--emerald-light);color:var(--emerald);padding:3px 9px;border-radius:40px;font-size:.74rem;font-weight:600}
.chip-none{background:var(--amber-light);color:var(--amber);padding:3px 9px;border-radius:40px;font-size:.74rem;font-weight:600;border:1px solid var(--amber-border);display:inline-flex;align-items:center;gap:4px}
.chip-date{color:var(--text-xlight);font-size:.78rem}
.empty-state{text-align:center;padding:44px 20px;color:var(--text-xlight)}
.empty-state i{font-size:2rem;opacity:.28;margin-bottom:10px;display:block}
.empty-state p{font-size:.85rem}

.mobile-bar{display:none;align-items:center;justify-content:space-between;background:var(--white);padding:12px 16px;border-bottom:1.5px solid var(--border);position:sticky;top:0;z-index:200}
.mob-btn{width:38px;height:38px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.05rem;color:var(--text-mid)}
.mob-logo{font-size:.93rem;font-weight:800;color:var(--blue)}
.mob-av{width:36px;height:36px;background:linear-gradient(135deg,var(--blue-dark),var(--blue-mid));border-radius:50%;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:.8rem}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;opacity:0;transition:opacity .3s}
.sidebar-overlay.show{opacity:1}

@media(max-width:1120px){.metrics{grid-template-columns:repeat(2,1fr)}.charts-row{grid-template-columns:1fr}.action-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:840px){.bottom-grid{grid-template-columns:1fr}}
@media(max-width:768px){.main{margin-left:0;width:100%}.mobile-bar{display:flex}.topbar{display:none}.content{padding:18px 16px}.hero{flex-direction:column;align-items:flex-start}.hero-stats{width:100%}.sidebar-overlay{display:block;pointer-events:none}.sidebar-overlay.show{pointer-events:auto}}
@media(max-width:520px){.metrics{grid-template-columns:1fr}.action-grid{grid-template-columns:1fr 1fr}.hero-stats{flex-wrap:wrap}.hstat{flex:1 1 40%}}
@media(max-width:420px){#toastContainer{left:12px;right:12px;bottom:12px}.toast{min-width:0;max-width:100%}}
@keyframes fadeUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.hero{animation:fadeUp .42s ease both}.metrics{animation:fadeUp .42s .07s ease both}.charts-row{animation:fadeUp .42s .13s ease both}.actions-row{animation:fadeUp .42s .18s ease both}.bottom-grid{animation:fadeUp .42s .23s ease both}
</style>
</head>
<body>

<div id="pushBanner">
    <i class="fas fa-bell"></i>
    <span>Get notified about new pre-registrations even when you're on other tabs.</span>
    <button class="push-allow-btn" id="pushAllowBtn"><i class="fas fa-bell"></i> Enable Notifications</button>
    <button class="push-dismiss-btn" id="pushDismissBtn"><i class="fas fa-times"></i></button>
</div>

<div class="app">
<div class="sidebar-overlay" id="overlay"></div>
<?php include 'includes/sidebar.php'; ?>

<main class="main">
<div class="mobile-bar">
    <button class="mob-btn" id="mobToggle"><i class="fas fa-bars"></i></button>
    <span class="mob-logo"><i class="fas fa-graduation-cap"></i> ALS Admin</span>
    <div class="mob-av"><?php echo strtoupper(substr($admin['full_name'],0,1)); ?></div>
</div>

<div class="topbar">
    <div>
        <div class="page-breadcrumb"><i class="fas fa-home bc-icon"></i><i class="fas fa-chevron-right bc-sep"></i><span class="bc-cur">Dashboard</span></div>
        <h1>Overview</h1>
    </div>
    <div class="topbar-right">
        <div class="tb-date"><i class="fas fa-calendar-alt"></i><?php echo date('D, M j, Y'); ?></div>

        <div class="notif-wrap">
            <div class="tb-notif" id="notifBell">
                <i class="fas fa-bell" id="bellIcon"></i>
                <span class="notif-dot" id="notifDot"></span>
            </div>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-header">
                    <div class="notif-header-left">
                        <span>Notifications</span>
                        <span class="notif-badge" id="notifBadge"><?php echo $unread_notifications; ?> new</span>
                    </div>
                    <button class="notif-mark-all" id="markAllBtn"><i class="fas fa-check-double"></i> Mark all read</button>
                </div>
                <div class="notif-list" id="notifList">
                    <?php if (!empty($notifications)): ?>
                        <?php foreach ($notifications as $n): ?>
                        <div class="notif-item unread"
                             data-id="<?php echo (int)$n['preregistration_id']; ?>"
                             data-created-at="<?php echo htmlspecialchars(str_replace(' ','T',$n['created_at'])); ?>"
                             onclick="handleNotifClick(<?php echo (int)$n['preregistration_id']; ?>,this)">
                            <div class="notif-item-icon"><i class="fas fa-user-plus"></i></div>
                            <div>
                                <div class="notif-item-name"><?php echo htmlspecialchars($n['first_name'].' '.$n['last_name']); ?></div>
                                <div class="notif-item-sub">New pre-registration &mdash; <?php echo htmlspecialchars($n['tracking_code']); ?></div>
                                <div class="notif-item-time"><i class="fas fa-clock" style="font-size:.6rem"></i> <span class="rel-time" data-ts="<?php echo htmlspecialchars(str_replace(' ','T',$n['created_at'])); ?>"></span></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="notif-empty" id="notifEmpty"><i class="fas fa-bell-slash"></i>No new notifications</div>
                    <?php endif; ?>
                </div>
                <div class="notif-footer"><a href="/PreRegistration">View All Pre-registrations &rarr;</a></div>
            </div>
        </div>

        <div class="user-chip">
            <div class="uc-avatar"><?php echo strtoupper(substr($admin['full_name'],0,1)); ?></div>
            <span class="uc-name"><?php echo htmlspecialchars(explode(' ',$admin['full_name'])[0]); ?></span>
            <i class="fas fa-chevron-down uc-chev"></i>
        </div>
    </div>
</div>

<div class="content">
<?php if ($pr_message): ?>
<div class="alert alert-<?php echo $pr_message_type; ?>"><i class="fas fa-<?php echo $pr_message_type==='success'?'check-circle':'exclamation-circle'; ?>"></i><?php echo htmlspecialchars($pr_message); ?></div>
<?php endif; ?>

<div class="hero">
    <div class="deco-a"></div><div class="deco-b"></div>
    <div class="hero-left">
        <div class="hero-tag"><i class="fas fa-graduation-cap"></i> Alternative Learning System</div>
        <div class="hero-title">Welcome back, <?php echo htmlspecialchars(explode(' ',$admin['full_name'])[0]); ?>!</div>
        <div class="hero-sub">Here's a snapshot of ALS activity for today &mdash; <?php echo date('F j, Y'); ?></div>
    </div>
    <div class="hero-stats">
        <div class="hstat"><div class="hval"><?php echo $activeStudents; ?></div><div class="hlbl">Students</div></div>
        <div class="hstat"><div class="hval"><?php echo $registeredTeachers; ?></div><div class="hlbl">Teachers</div></div>
        <div class="hstat"><div class="hval"><?php echo $learningMaterials; ?></div><div class="hlbl">Materials</div></div>
        <div class="hstat"><div class="hval"><?php echo $enrolledToday; ?></div><div class="hlbl">Today</div></div>
    </div>
</div>

<div class="metrics">
    <div class="mcard cb"><div class="mcard-top"><div class="mcard-icon"><i class="fas fa-user-graduate"></i></div><span class="mcard-badge">Enrolled</span></div><div class="mcard-num"><?php echo number_format($activeStudents); ?></div><div class="mcard-desc">Active Students</div></div>
    <div class="mcard ce"><div class="mcard-top"><div class="mcard-icon"><i class="fas fa-chalkboard-teacher"></i></div><span class="mcard-badge">Registered</span></div><div class="mcard-num"><?php echo number_format($registeredTeachers); ?></div><div class="mcard-desc">Active Teachers</div></div>
    <div class="mcard ca"><div class="mcard-top"><div class="mcard-icon"><i class="fas fa-book-open"></i></div><span class="mcard-badge">Available</span></div><div class="mcard-num"><?php echo number_format($learningMaterials); ?></div><div class="mcard-desc">Learning Materials</div></div>
    <div class="mcard cv"><div class="mcard-top"><div class="mcard-icon"><i class="fas fa-user-plus"></i></div><span class="mcard-badge">Pending</span></div><div class="mcard-num" id="prCount"><?php echo $pending_pr_count; ?></div><div class="mcard-desc">Pre-registrations</div></div>
</div>

<div class="charts-row">
    <div class="chart-card">
        <div class="sec-head">
            <h2><i class="fas fa-chart-line"></i> Enrollment Trend &mdash; Last 7 Days</h2>
            <?php if ($totalThisMonth > 0): ?><span class="sec-meta"><?php echo $totalThisMonth; ?> this month</span><?php endif; ?>
        </div>
        <?php if ($hasTrendData): ?>
        <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
        <?php else: ?><div class="empty-state"><i class="fas fa-chart-line"></i><p>No enrollment data yet this week</p></div><?php endif; ?>
    </div>
    <div class="chart-card">
        <div class="sec-head"><h2><i class="fas fa-map-marker-alt"></i> By Barangay</h2></div>
        <?php if (!empty($barangayData)):
            $maxCount = max(array_column($barangayData,'count')); ?>
        <div class="bgy-list">
            <?php foreach ($barangayData as $b): ?>
            <div>
                <div class="bgy-row"><span class="bgy-name"><?php echo htmlspecialchars($b['name']); ?></span><span class="bgy-count"><?php echo $b['count']; ?></span></div>
                <div class="bgy-track"><div class="bgy-fill" style="width:<?php echo ($b['count']/$maxCount)*100; ?>%"></div></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?><div class="empty-state"><i class="fas fa-map"></i><p>No barangay data available</p></div><?php endif; ?>
    </div>
</div>

<div class="actions-row">
    <div class="sec-head"><h2><i class="fas fa-bolt"></i> Quick Actions</h2></div>
    <div class="action-grid">
        <a href="/AddStudents" class="acard primary"><div class="acard-icon"><i class="fas fa-user-plus"></i></div><div><div class="acard-title">New Enrollment</div><div class="acard-sub">Register a student</div></div></a>
        <a href="/AllStudents" class="acard"><div class="acard-icon"><i class="fas fa-users"></i></div><div><div class="acard-title">View Students</div><div class="acard-sub"><?php echo $activeStudents; ?> enrolled</div></div></a>
        <a href="/PreRegistration" class="acard" style="background:var(--amber-light);border-color:var(--amber-border)"><div class="acard-icon" style="background:var(--amber);color:#fff"><i class="fas fa-user-clock"></i></div><div><div class="acard-title">Pre-registrations</div><div class="acard-sub" id="prCardSub"><?php echo $pending_pr_count; ?> pending</div></div></a>
        <a href="reports/index.php" class="acard"><div class="acard-icon"><i class="fas fa-file-alt"></i></div><div><div class="acard-title">Reports</div><div class="acard-sub">Export &amp; view data</div></div></a>
    </div>
</div>

<?php if (!empty($recent_preregistrations)): ?>
<div class="pr-card">
    <div class="pr-card-header"><i class="fas fa-user-clock"></i><h3>Pending Pre-registrations (<?php echo count($recent_preregistrations); ?>)</h3><a href="/PreRegistration" class="view-all" style="margin-left:auto">View All <i class="fas fa-arrow-right"></i></a></div>
    <div class="pr-card-body">
        <table class="pr-table">
            <thead><tr><th>Tracking Code</th><th>Name</th><th>Contact</th><th>Barangay</th><th>Submitted</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($recent_preregistrations as $pr): ?>
            <tr>
                <td><span class="chip-id"><?php echo htmlspecialchars($pr['tracking_code']); ?></span><?php if ($pr['has_unread']>0): ?><span style="display:inline-block;width:7px;height:7px;background:var(--amber);border-radius:50%;vertical-align:middle;margin-left:4px"></span><?php endif; ?></td>
                <td><span class="chip-name"><?php echo htmlspecialchars($pr['last_name'].', '.$pr['first_name']); ?></span></td>
                <td><?php echo htmlspecialchars($pr['contact_number']); ?><br><small style="color:var(--text-xlight)"><?php echo htmlspecialchars($pr['email']); ?></small></td>
                <td><?php if (!empty($pr['barangay_name'])) echo htmlspecialchars($pr['barangay_name']); elseif (!empty($pr['current_custom_barangay'])) echo htmlspecialchars($pr['current_custom_barangay']).' <small>(custom)</small>'; else echo '&mdash;'; ?></td>
                <td><span class="chip-date"><?php echo date('M d, Y',strtotime($pr['submitted_at'])); ?></span><br><small style="color:var(--text-xlight)"><?php echo date('h:i A',strtotime($pr['submitted_at'])); ?></small></td>
                <td><div class="action-buttons">
                    <button class="btn-sm btn-view" onclick='viewDetails(<?php echo json_encode($pr); ?>)'><i class="fas fa-eye"></i></button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Approve this pre-registration?')"><input type="hidden" name="prereg_id" value="<?php echo $pr['preregistration_id']; ?>"><input type="hidden" name="pr_action" value="approve"><button type="submit" class="btn-sm btn-approve"><i class="fas fa-check"></i></button></form>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Reject this pre-registration?')"><input type="hidden" name="prereg_id" value="<?php echo $pr['preregistration_id']; ?>"><input type="hidden" name="pr_action" value="reject"><button type="submit" class="btn-sm btn-reject"><i class="fas fa-times"></i></button></form>
                </div></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="bottom-grid">
    <div class="status-card">
        <div class="sc-title"><i class="fas fa-server"></i> System Status</div>
        <div class="sc-row"><span class="sc-lbl">System Health</span><span class="pill p-green">&#9679; Healthy</span></div>
        <div class="sc-row"><span class="sc-lbl">Active Students</span><span class="sc-val"><?php echo number_format($activeStudents); ?></span></div>
        <div class="sc-row"><span class="sc-lbl">Active Teachers</span><span class="sc-val"><?php echo number_format($registeredTeachers); ?></span></div>
        <div class="sc-row"><span class="sc-lbl">Enrolled This Month</span><span class="pill p-blue"><?php echo $totalThisMonth; ?></span></div>
        <div class="sc-row"><span class="sc-lbl">Pending Pre-reg</span><span class="pill p-amber" id="statusPrPill"><?php echo $pending_pr_count; ?></span></div>
        <?php if ($unassigned>0): ?><div class="sc-row"><span class="sc-lbl">Unassigned Students</span><span class="pill p-amber"><?php echo $unassigned; ?></span></div><?php endif; ?>
        <?php if ($ungraded>0): ?><div class="sc-row"><span class="sc-lbl">Ungraded Submissions</span><span class="pill p-amber"><?php echo $ungraded; ?></span></div><?php endif; ?>
    </div>
    <div class="table-card">
        <div class="sec-head" style="margin-bottom:16px">
            <h2><i class="fas fa-clock-rotate-left"></i> Recent Enrollments</h2>
            <?php if (!empty($recentEnrollments)): ?><a href="/AllStudents" class="view-all">View all <i class="fas fa-arrow-right"></i></a><?php endif; ?>
        </div>
        <?php if (!empty($recentEnrollments)): ?>
        <table class="dtable">
            <thead><tr><th>ID</th><th>Student</th><th>Barangay</th><th>Teacher</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach ($recentEnrollments as $e): ?>
            <tr>
                <td><span class="chip-id"><?php echo htmlspecialchars($e['student_id']); ?></span></td>
                <td><span class="chip-name"><?php echo htmlspecialchars($e['first_name'].' '.$e['last_name']); ?></span></td>
                <td><span class="chip-bgy"><?php echo htmlspecialchars($e['barangay']??'N/A'); ?></span></td>
                <td><?php if ($e['teacher_name']): ?><span class="chip-teach"><?php echo htmlspecialchars($e['teacher_name']); ?></span><?php else: ?><span class="chip-none"><i class="fas fa-exclamation-circle" style="font-size:.63rem"></i> Unassigned</span><?php endif; ?></td>
                <td><span class="chip-date"><?php echo date('M d, Y',strtotime($e['enrollment_date'])); ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?><div class="empty-state"><i class="fas fa-inbox"></i><p>No recent enrollments to show</p></div><?php endif; ?>
    </div>
</div>
</div><!-- /content -->
</main>
</div><!-- /app -->

<div id="detailsModal" class="modal-backdrop">
    <div class="modal-box">
        <div class="modal-head"><h2>Pre-registration Details</h2><button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button></div>
        <div class="modal-body" id="modalContent"></div>
        <div class="modal-foot"><button class="btn-modal-close" onclick="closeModal()">Close</button></div>
    </div>
</div>

<div id="toastContainer"></div>

<script>
/* ── Mobile sidebar ── */
const overlay   = document.getElementById('overlay');
const sidebar   = document.querySelector('.sidebar');
const mobToggle = document.getElementById('mobToggle');
if (mobToggle) {
    mobToggle.addEventListener('click', () => {
        sidebar.classList.add('open');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    });
}
overlay.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';
});
if (window.innerWidth <= 768) {
    document.querySelectorAll('.sidebar a').forEach(a => {
        a.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            document.body.style.overflow = '';
        });
    });
}

/* ================================================================
   NOTIFICATION ENGINE — FULL FIX
   - Real notification sound via Web Audio API
   - Accurate relative timestamps ("just now", "3 min ago")
   - Proper ISO date parsing from server
   - No-cache fetch headers so PHP always runs fresh
   - Exponential backoff on server errors
   - Bell rings per notification not per poll
   - syncCount updates ALL UI elements including quick-action card
   ================================================================ */

/* ── Notification Sound (Web Audio API — no external file needed) ── */
function playNotifSound() {
    try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const playTone = (freq, startTime, duration, gain) => {
            const osc      = ctx.createOscillator();
            const gainNode = ctx.createGain();
            osc.connect(gainNode);
            gainNode.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(freq, startTime);
            gainNode.gain.setValueAtTime(0, startTime);
            gainNode.gain.linearRampToValueAtTime(gain, startTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.001, startTime + duration);
            osc.start(startTime);
            osc.stop(startTime + duration);
        };
        const now = ctx.currentTime;
        playTone(880, now,        0.18, 0.35);
        playTone(660, now + 0.15, 0.28, 0.25);
        playTone(880, now + 0.35, 0.22, 0.20);
    } catch (e) { /* AudioContext blocked before user gesture — silent */ }
}

/* ── Relative time formatter ── */
function relativeTime(iso) {
    if (!iso) return 'just now';
    const then = new Date(iso).getTime();
    if (isNaN(then)) return 'just now';
    const diff = Math.floor((Date.now() - then) / 1000);
    if (diff < 10)    return 'just now';
    if (diff < 60)    return diff + 's ago';
    if (diff < 3600)  return Math.floor(diff / 60) + ' min ago';
    if (diff < 86400) {
        const h = Math.floor(diff / 3600);
        return h + ' hr' + (h !== 1 ? 's' : '') + ' ago';
    }
    return new Date(iso).toLocaleDateString('en-PH', {
        month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
}

/* ── Refresh all relative timestamps every 30s ── */
function refreshRelativeTimes() {
    document.querySelectorAll('.rel-time[data-ts]').forEach(el => {
        el.textContent = relativeTime(el.dataset.ts);
    });
}
// Run once immediately on page load
refreshRelativeTimes();
setInterval(refreshRelativeTimes, 30000);

/* ── IDs PHP already rendered — do NOT toast on first poll ── */
const renderedIds = new Set(<?php echo json_encode($initialRenderedIds); ?>);
const toastedIds  = new Set();
renderedIds.forEach(id => toastedIds.add(id));

let notifUnreadCount  = <?php echo (int)$unread_notifications; ?>;
let pollTimer         = null;
let swReg             = null;
let audioUnlocked     = false;
let consecutiveErrors = 0;

/* Unlock audio context on first user interaction (browser requirement) */
['click','keydown','touchstart'].forEach(evt =>
    document.addEventListener(evt, () => { audioUnlocked = true; }, { once: true, passive: true })
);

/* ── Init dot visibility on load ── */
(function () {
    const dot = document.getElementById('notifDot');
    if (dot) dot.style.display = notifUnreadCount > 0 ? 'block' : 'none';
})();

/* ── BroadcastChannel: keep multiple open admin tabs in sync ── */
const bc = ('BroadcastChannel' in window) ? new BroadcastChannel('als_notifications') : null;
if (bc) {
    bc.onmessage = ev => {
        if (ev.data.type === 'mark_all_read') applyMarkAllRead(false);
        if (ev.data.type === 'count_update')  syncCount(ev.data.count, false);
        if (ev.data.type === 'new_notif') {
            const pid = parseInt(ev.data.notif.preregistration_id);
            toastedIds.add(pid);
            renderedIds.add(pid);
            addDropdownItem(ev.data.notif);
        }
    };
}

/* ── Service Worker / Push ── */
const pushBanner = document.getElementById('pushBanner');

document.getElementById('pushAllowBtn').addEventListener('click', async () => {
    pushBanner.classList.remove('show');
    audioUnlocked = true;
    const r = await Notification.requestPermission();
    if (r === 'granted') {
        localStorage.removeItem('als_push_dismissed');
        showToast({
            first_name: '✓ Notifications', last_name: 'enabled!',
            tracking_code: "You'll be alerted on any tab.", preregistration_id: null
        }, true);
    }
});
document.getElementById('pushDismissBtn').addEventListener('click', () => {
    pushBanner.classList.remove('show');
    localStorage.setItem('als_push_dismissed', '1');
});

async function initSW() {
    if (!('serviceWorker' in navigator) || !('Notification' in window)) return;
    if (Notification.permission === 'denied') return;
    if (localStorage.getItem('als_push_dismissed') === '1') return;
    try { swReg = await navigator.serviceWorker.register('/sw.js'); }
    catch (e) { swReg = null; }
    if (Notification.permission === 'default') pushBanner.classList.add('show');
}

function fireOSNotification(notif) {
    if (Notification.permission !== 'granted') return;
    const opts = {
        body    : notif.first_name + ' ' + notif.last_name + '\nTracking: ' + notif.tracking_code,
        icon    : '/logo', badge: '/logo',
        tag     : 'prereg-' + notif.preregistration_id,
        data    : { url: '/PreRegistration?view=' + notif.preregistration_id },
        vibrate : [200, 100, 200],
        renotify: true
    };
    try {
        if (swReg && swReg.active) {
            swReg.showNotification('New Pre-registration \uD83D\uDD14', opts);
        } else {
            const n = new Notification('New Pre-registration \uD83D\uDD14', opts);
            n.onclick = () => { window.focus(); window.location.href = opts.data.url; };
        }
    } catch (e) { /* permission revoked mid-session */ }
}

/* ── Bell toggle ── */
const notifBell = document.getElementById('notifBell');
const notifDD   = document.getElementById('notifDropdown');

notifBell.addEventListener('click', e => {
    e.stopPropagation();
    audioUnlocked = true;
    notifDD.classList.toggle('show');
});
document.addEventListener('click', e => {
    if (!notifBell.contains(e.target) && !notifDD.contains(e.target))
        notifDD.classList.remove('show');
});

/* ── Mark one as read ── */
async function handleNotifClick(pid, el) {
    try {
        const res  = await fetch('/AdminDashboard?ajax=mark_read', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body   : JSON.stringify({ mode: 'one', preregistration_id: pid })
        });
        const data = await res.json();
        el.classList.remove('unread');
        syncCount(typeof data.unread_count === 'number'
            ? data.unread_count
            : Math.max(0, notifUnreadCount - 1), true);
    } catch (e) { /* silent */ }
    window.location.href = '/PreRegistration?view=' + pid;
}

/* ── Mark all as read ── */
document.getElementById('markAllBtn').addEventListener('click', async e => {
    e.stopPropagation();
    audioUnlocked = true;
    const btn = document.getElementById('markAllBtn');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Marking\u2026';
    try {
        const res  = await fetch('/AdminDashboard?ajax=mark_read', {
            method : 'POST',
            headers: { 'Content-Type': 'application/json' },
            body   : JSON.stringify({ mode: 'all' })
        });
        const data = await res.json();
        applyMarkAllRead(true);
        syncCount(typeof data.unread_count === 'number' ? data.unread_count : 0, true);
    } catch (e) { /* silent */ }
    btn.innerHTML = '<i class="fas fa-check"></i> All read';
    btn.disabled  = false;
});

function applyMarkAllRead(broadcast) {
    document.querySelectorAll('#notifList .notif-item.unread').forEach(el => el.classList.remove('unread'));
    if (broadcast && bc) bc.postMessage({ type: 'mark_all_read' });
}

/* ── Sync ALL unread count UI elements ── */
function syncCount(n, broadcast) {
    notifUnreadCount = Math.max(0, n);

    const badge       = document.getElementById('notifBadge');
    const dot         = document.getElementById('notifDot');
    const prCount     = document.getElementById('prCount');
    const prCardSub   = document.getElementById('prCardSub');
    const statusPill  = document.getElementById('statusPrPill');

    if (badge) {
        badge.textContent = notifUnreadCount + ' new';
        badge.classList.remove('bump');
        void badge.offsetWidth;
        if (notifUnreadCount > 0) badge.classList.add('bump');
    }
    if (dot)        dot.style.display  = notifUnreadCount > 0 ? 'block' : 'none';
    if (prCount)    prCount.textContent = notifUnreadCount;
    if (prCardSub)  prCardSub.textContent = notifUnreadCount + ' pending';
    if (statusPill) statusPill.textContent = notifUnreadCount;

    if (broadcast && bc) bc.postMessage({ type: 'count_update', count: notifUnreadCount });
}

/* ── Add item to dropdown (skip if already present) ── */
function addDropdownItem(notif) {
    const list  = document.getElementById('notifList');
    const empty = document.getElementById('notifEmpty');
    if (empty) empty.remove();

    const pid = parseInt(notif.preregistration_id);
    if (list.querySelector('[data-id="' + pid + '"]')) return;

    const iso = notif.created_at || new Date().toISOString();
    const div = document.createElement('div');
    div.className  = 'notif-item unread';
    div.dataset.id = pid;
    div.setAttribute('onclick', 'handleNotifClick(' + pid + ',this)');
    div.innerHTML  =
        '<div class="notif-item-icon"><i class="fas fa-user-plus"></i></div>'
        + '<div>'
        +   '<div class="notif-item-name">' + escapeHtml(notif.first_name + ' ' + notif.last_name) + '</div>'
        +   '<div class="notif-item-sub">New pre-registration &mdash; ' + escapeHtml(notif.tracking_code) + '</div>'
        +   '<div class="notif-item-time"><i class="fas fa-clock" style="font-size:.6rem"></i>'
        +   ' <span class="rel-time" data-ts="' + escapeHtml(iso) + '">' + relativeTime(iso) + '</span></div>'
        + '</div>';
    list.prepend(div);

    const items = list.querySelectorAll('.notif-item');
    if (items.length > 5) items[items.length - 1].remove();
}

/* ── Toast ── */
function showToast(notif, isConfirm) {
    const container = document.getElementById('toastContainer');
    const toast     = document.createElement('div');
    toast.className = 'toast';

    if (isConfirm) {
        toast.style.borderLeftColor = '#059669';
        toast.innerHTML =
            '<div class="toast-progress" style="background:linear-gradient(90deg,#059669,#34d399);animation-duration:3s"></div>'
            + '<div class="toast-icon" style="background:rgba(5,150,105,.15);border-color:rgba(5,150,105,.3);color:#059669"><i class="fas fa-check"></i></div>'
            + '<div style="flex:1">'
            +   '<div class="toast-title">'  + escapeHtml(notif.first_name)     + '</div>'
            +   '<div class="toast-sub">'    + escapeHtml(notif.last_name)       + '</div>'
            +   '<div class="toast-time">'   + escapeHtml(notif.tracking_code)  + '</div>'
            + '</div>'
            + '<button class="toast-close" onclick="dismissToast(this.closest(\'.toast\'),event)"><i class="fas fa-times"></i></button>';
        setTimeout(() => dismissToast(toast), 3000);
    } else {
        toast.innerHTML =
            '<div class="toast-progress"></div>'
            + '<div class="toast-icon"><i class="fas fa-user-plus"></i></div>'
            + '<div style="flex:1;min-width:0">'
            +   '<div class="toast-title">New Pre-registration</div>'
            +   '<div class="toast-sub">' + escapeHtml(notif.first_name + ' ' + notif.last_name) + '</div>'
            +   '<div class="toast-time">Tracking: ' + escapeHtml(notif.tracking_code) + '</div>'
            + '</div>'
            + '<button class="toast-close" onclick="dismissToast(this.closest(\'.toast\'),event)"><i class="fas fa-times"></i></button>';

        toast.addEventListener('click', ev => {
            if (ev.target.closest('.toast-close')) return;
            window.location.href = '/PreRegistration?view=' + notif.preregistration_id;
        });

        /* Ring bell — per notification */
        const bellIcon = document.getElementById('bellIcon');
        if (bellIcon) {
            bellIcon.classList.remove('bell-ring');
            void bellIcon.offsetWidth;
            bellIcon.classList.add('bell-ring');
            bellIcon.addEventListener('animationend', () => bellIcon.classList.remove('bell-ring'), { once: true });
        }
        setTimeout(() => dismissToast(toast), 6000);
    }
    container.appendChild(toast);
}

function dismissToast(toast, ev) {
    if (ev) ev.stopPropagation();
    if (!toast || toast.classList.contains('removing')) return;
    toast.classList.add('removing');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
}

/* ================================================================
   POLLING — production-hardened
   ================================================================ */
async function poll() {
    try {
        const res = await fetch('/AdminDashboard?ajax=poll_notifications', {
            headers: { 'Cache-Control': 'no-cache, no-store', 'Pragma': 'no-cache' },
            cache  : 'no-store'
        });

        if (!res.ok) throw new Error('HTTP ' + res.status);

        const data = await res.json();
        consecutiveErrors = 0;

        if (data.error || !Array.isArray(data.items)) return;

        let newCount = 0;

        data.items.forEach(notif => {
            const pid = parseInt(notif.preregistration_id);

            /* Update relative timestamp if item already in dropdown */
            const existing = document.querySelector('#notifList [data-id="' + pid + '"]');
            if (existing && notif.created_at) {
                const relEl = existing.querySelector('.rel-time');
                if (relEl) {
                    relEl.dataset.ts  = notif.created_at;
                    relEl.textContent = relativeTime(notif.created_at);
                }
            }

            /* Add to dropdown if not already shown */
            if (!renderedIds.has(pid)) {
                renderedIds.add(pid);
                addDropdownItem(notif);
            }

            /* Toast + sound + OS notification — only for brand-new arrivals */
            if (!toastedIds.has(pid)) {
                toastedIds.add(pid);
                newCount++;
                showToast(notif, false);
                if (audioUnlocked) playNotifSound();
                fireOSNotification(notif);
                if (bc) bc.postMessage({ type: 'new_notif', notif });
            }
        });

        /* Always trust the server's authoritative unread count */
        if (typeof data.unread_count === 'number') {
            syncCount(data.unread_count, newCount > 0);
        }

    } catch (e) {
        consecutiveErrors++;
        if (consecutiveErrors > 3) {
            clearInterval(pollTimer);
            const backoff = Math.min(120000, 8000 * Math.pow(2, consecutiveErrors - 3));
            setTimeout(() => {
                startPolling(8000);
                poll();
            }, backoff);
        }
    }
}

function startPolling(interval) {
    clearInterval(pollTimer);
    pollTimer = setInterval(poll, interval);
}

/* First poll immediately, then every 8 s */
poll();
startPolling(8000);

/* Slow down when tab hidden, snap back + catch-up when visible */
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        startPolling(30000);
    } else {
        consecutiveErrors = 0;
        poll();
        startPolling(8000);
    }
});

initSW();

/* ── Details modal ── */
function viewDetails(d) {
    const bgy = d.barangay_name || d.current_custom_barangay || 'N/A';
    document.getElementById('modalContent').innerHTML =
        '<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">'
        + '<div style="width:44px;height:44px;background:var(--blue-light);color:var(--blue);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.1rem"><i class="fas fa-user"></i></div>'
        + '<div>'
        +   '<div style="font-size:1.05rem;font-weight:800;color:var(--text-dark)">' + escapeHtml(d.last_name + ', ' + d.first_name + ' ' + (d.middle_name || '')) + '</div>'
        +   '<span class="pr-badge-pending">' + escapeHtml((d.status || '').toUpperCase()) + '</span>'
        +   '<span style="font-size:.75rem;color:var(--text-xlight);margin-left:8px">Code: ' + escapeHtml(d.tracking_code) + '</span>'
        + '</div></div>'
        + '<div class="modal-section-title">Personal Information</div>'
        + '<div class="info-grid">'
        + '<div><div class="info-label">Full Name</div><div class="info-value">' + escapeHtml(d.last_name + ', ' + d.first_name + ' ' + (d.middle_name||'') + ' ' + (d.extension_name||'')) + '</div></div>'
        + '<div><div class="info-label">Birthdate / Age</div><div class="info-value">' + escapeHtml(d.birthdate) + ' (' + escapeHtml(d.age) + ' yrs)</div></div>'
        + '<div><div class="info-label">Sex</div><div class="info-value" style="text-transform:capitalize">' + escapeHtml(d.sex) + '</div></div>'
        + '<div><div class="info-label">LRN</div><div class="info-value">' + escapeHtml(d.lrn || 'N/A') + '</div></div>'
        + '</div>'
        + '<div class="modal-section-title">Contact</div>'
        + '<div class="info-grid">'
        + '<div><div class="info-label">Contact Number</div><div class="info-value">' + escapeHtml(d.contact_number) + '</div></div>'
        + '<div><div class="info-label">Email</div><div class="info-value">' + escapeHtml(d.email) + '</div></div>'
        + '</div>'
        + '<div class="modal-section-title">Address</div>'
        + '<div class="info-grid">'
        + '<div><div class="info-label">Barangay</div><div class="info-value">' + escapeHtml(bgy) + '</div></div>'
        + '<div><div class="info-label">City</div><div class="info-value">' + escapeHtml(d.current_city || 'N/A') + '</div></div>'
        + '</div>'
        + '<div class="modal-section-title">Parent / Guardian</div>'
        + '<div class="info-grid">'
        + '<div><div class="info-label">Name</div><div class="info-value">' + escapeHtml(d.parent_name || 'N/A') + '</div></div>'
        + '<div><div class="info-label">Contact</div><div class="info-value">' + escapeHtml(d.parent_contact || 'N/A') + '</div></div>'
        + '</div>'
        + '<div class="modal-section-title">Education</div>'
        + '<div class="info-grid">'
        + '<div><div class="info-label">Last Grade Level</div><div class="info-value">' + escapeHtml(d.last_grade_level || 'N/A') + '</div></div>'
        + '<div><div class="info-label">Submitted</div><div class="info-value">' + new Date(d.submitted_at).toLocaleString('en-PH') + '</div></div>'
        + '</div>';
    document.getElementById('detailsModal').classList.add('open');
}
function closeModal() { document.getElementById('detailsModal').classList.remove('open'); }
window.addEventListener('click', e => { if (e.target === document.getElementById('detailsModal')) closeModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

/* ── HTML escape helper ── */
function escapeHtml(unsafe) {
    if (unsafe == null) return '';
    return String(unsafe)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}

/* ── Trend Chart ── */
<?php if ($hasTrendData): ?>
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('trendChart');
    if (!canvas) return;
    const ctx      = canvas.getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 220);
    gradient.addColorStop(0, 'rgba(29,78,216,.24)');
    gradient.addColorStop(1, 'rgba(29,78,216,0)');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels  : <?php echo json_encode($trendLabels); ?>,
            datasets: [{
                data                : <?php echo json_encode($trendCounts); ?>,
                borderColor         : '#1d4ed8',
                backgroundColor     : gradient,
                borderWidth         : 2.5,
                pointBackgroundColor: '#1d4ed8',
                pointBorderColor    : '#fff',
                pointBorderWidth    : 2.5,
                pointRadius         : 5,
                pointHoverRadius    : 7,
                tension             : .4,
                fill                : true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend : { display: false },
                tooltip: {
                    backgroundColor: '#0f172a', titleColor: '#e2e8f0',
                    bodyColor: '#94a3b8', padding: 11, cornerRadius: 9,
                    borderColor: '#1e3a8a', borderWidth: 1,
                    callbacks: { label: c => '  ' + c.parsed.y + ' enrollment' + (c.parsed.y !== 1 ? 's' : '') }
                }
            },
            scales: {
                x: { grid: { display: false }, border: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 } } },
                y: { beginAtZero: true, grid: { color: '#e4eaf6' }, border: { display: false }, ticks: { color: '#94a3b8', font: { size: 11 }, stepSize: 1, precision: 0 } }
            }
        }
    });
});
<?php endif; ?>
</script>
<?php include 'includes/footer.php'; ?>