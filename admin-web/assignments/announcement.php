<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: ../../index.php');
    exit();
}

$admin_id = $_SESSION['admin_id'];

// ── DELETE announcement ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $del_id = intval($_POST['announcement_id']);
    $conn->query("DELETE FROM announcements WHERE id = $del_id");
    $_SESSION['success'] = "Announcement deleted.";
    header("Location: Announcement.php"); exit();
}

// ── CREATE / EDIT announcement ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!isset($_POST['action']) || $_POST['action'] === 'save')) {
    $edit_id        = isset($_POST['edit_id']) ? intval($_POST['edit_id']) : 0;
    $title          = trim($conn->real_escape_string($_POST['title']));
    $content        = trim($conn->real_escape_string($_POST['content']));
    $priority       = $conn->real_escape_string($_POST['priority'] ?? 'normal');
    $target_all     = isset($_POST['target_all']) ? 1 : 0;
    $target_teachers= isset($_POST['target_teachers']) ? $_POST['target_teachers'] : [];

    if ($title && $content) {
        if ($edit_id) {
            // UPDATE
            $conn->query("UPDATE announcements SET title='$title', content='$content', priority='$priority', target_all=$target_all WHERE id=$edit_id");
            $conn->query("DELETE FROM announcement_targets WHERE announcement_id=$edit_id");
            $ann_id = $edit_id;
            $_SESSION['success'] = "Announcement updated successfully!";
        } else {
            // INSERT
            $stmt = $conn->prepare("INSERT INTO announcements (title, content, admin_id, priority, target_all) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssisi", $title, $content, $admin_id, $priority, $target_all);
            $stmt->execute();
            $ann_id = $stmt->insert_id;
            $stmt->close();
            $_SESSION['success'] = "Announcement sent successfully!";
        }

        if (!$target_all && !empty($target_teachers)) {
            foreach ($target_teachers as $tid) {
                $tid = intval($tid);
                $conn->query("INSERT INTO announcement_targets (announcement_id, teacher_id) VALUES ($ann_id, $tid)");
            }
        }
    } else {
        $_SESSION['error'] = "Please fill in all required fields.";
    }

    header("Location: Announcement.php"); exit();
}

// ── FETCH data ───────────────────────────────────────────────────────────────
$teachers = $conn->query("SELECT teacher_id, full_name FROM teachers WHERE status='active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

$announcements = [];
$table_error   = null;
try {
    $res = $conn->query("
        SELECT a.*, adm.full_name AS admin_name,
               (SELECT COUNT(*) FROM announcement_targets WHERE announcement_id = a.id) AS target_count
        FROM announcements a
        LEFT JOIN admins adm ON a.admin_id = adm.id
        ORDER BY a.created_at DESC
        LIMIT 20
    ");
    if ($res) $announcements = $res->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    $table_error = "Announcements table not set up yet.";
}

// Stats
$total_ann    = count($announcements);
$all_target   = count(array_filter($announcements, fn($a) => $a['target_all']));
$urgent_count = count(array_filter($announcements, fn($a) => ($a['priority'] ?? '') === 'urgent'));

// Priority config
$priority_map = [
    'normal'  => ['label' => 'Normal',  'color' => '#6366f1', 'bg' => '#eef2ff', 'icon' => 'fa-info-circle'],
    'important'=> ['label' => 'Important','color'=> '#f59e0b', 'bg' => '#fffbeb', 'icon' => 'fa-exclamation-circle'],
    'urgent'  => ['label' => 'Urgent',  'color' => '#ef4444', 'bg' => '#fef2f2', 'icon' => 'fa-exclamation-triangle'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements — ALS Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --indigo:      #6366f1;
            --indigo-dark: #4f46e5;
            --indigo-pale: #eef2ff;
            --green:       #22c55e;
            --green-pale:  #f0fdf4;
            --red:         #ef4444;
            --red-pale:    #fef2f2;
            --amber:       #f59e0b;
            --amber-pale:  #fffbeb;
            --sky:         #0ea5e9;
            --bg:          #f8fafc;
            --surface:     #ffffff;
            --border:      #e2e8f0;
            --text-main:   #0f172a;
            --text-muted:  #64748b;
            --text-light:  #94a3b8;
            --radius-sm:   6px;
            --radius-md:   12px;
            --radius-lg:   20px;
            --shadow-sm:   0 1px 3px rgba(0,0,0,.06);
            --shadow-md:   0 4px 16px rgba(0,0,0,.08);
            --shadow-lg:   0 10px 40px rgba(99,102,241,.15);
            --transition:  .2s cubic-bezier(.4,0,.2,1);
        }

        body { font-family: 'Inter', sans-serif; background: var(--bg); color: var(--text-main); }

        /* TOP BAR */
        .top-bar {
            background: var(--surface); border-bottom: 1px solid var(--border);
            padding: .875rem 2rem; display: flex; align-items: center;
            justify-content: space-between; position: sticky; top: 0; z-index: 100; gap: 1rem;
        }
        .brand { display: flex; align-items: center; gap: .6rem; font-weight: 700; font-size: 1rem; color: var(--indigo); text-decoration: none; }
        .logo-box { width: 32px; height: 32px; background: var(--indigo); border-radius: var(--radius-sm); display: grid; place-items: center; color: #fff; font-size: .85rem; }
        .breadcrumb { font-size: .78rem; color: var(--text-muted); margin: 0; }
        .nav-actions { display: flex; align-items: center; gap: .6rem; }
        .icon-btn { width: 36px; height: 36px; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); display: grid; place-items: center; color: var(--text-muted); cursor: pointer; transition: var(--transition); font-size: .88rem; outline: none; }
        .icon-btn:hover { background: var(--indigo-pale); color: var(--indigo); border-color: var(--indigo); }
        .avatar-btn { width: 36px; height: 36px; border-radius: 50%; background: var(--indigo); display: grid; place-items: center; color: #fff; font-size: .78rem; font-weight: 700; cursor: pointer; }

        /* PAGE WRAPPER */
        .page-wrapper { padding: 2rem; max-width: 1400px; margin: 0 auto; }

        /* HERO */
        .page-hero {
            background: linear-gradient(135deg, #4f46e5 0%, #7c3aed 55%, #a855f7 100%);
            border-radius: var(--radius-lg); padding: 2.5rem 2.5rem 8rem;
            position: relative; overflow: hidden; margin-bottom: -5.75rem;
        }
        .page-hero::before { content: ''; position: absolute; inset: 0; background-image: radial-gradient(circle at 80% 20%, rgba(255,255,255,.09) 0%, transparent 50%), radial-gradient(circle at 15% 80%, rgba(255,255,255,.05) 0%, transparent 45%); }
        .page-hero::after { content: ''; position: absolute; bottom: 0; left: 0; right: 0; height: 55px; background: var(--bg); border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
        .hero-content { position: relative; z-index: 1; }
        .hero-eyebrow { display: inline-flex; align-items: center; gap: .4rem; background: rgba(255,255,255,.15); backdrop-filter: blur(8px); color: rgba(255,255,255,.95); font-size: .72rem; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; padding: .35rem .85rem; border-radius: 99px; border: 1px solid rgba(255,255,255,.2); margin-bottom: 1rem; }
        .hero-title { font-size: 2rem; font-weight: 800; color: #fff; line-height: 1.15; margin-bottom: .4rem; }
        .hero-sub { color: rgba(255,255,255,.72); font-size: .9rem; }
        .btn-hero { background: #fff; color: var(--indigo-dark); font-weight: 700; font-size: .83rem; padding: .7rem 1.5rem; border-radius: var(--radius-sm); border: none; display: inline-flex; align-items: center; gap: .5rem; text-decoration: none; transition: var(--transition); box-shadow: 0 4px 14px rgba(0,0,0,.15); cursor: pointer; }
        .btn-hero:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,0,0,.2); background: #f5f3ff; color: var(--indigo-dark); }

        /* STAT CARDS */
        .stats-row { position: relative; z-index: 10; display: grid; grid-template-columns: repeat(3,1fr); gap: 1.25rem; margin-bottom: 1.75rem; }
        .stat-card { background: var(--surface); border-radius: var(--radius-md); padding: 1.5rem; box-shadow: var(--shadow-md); border: 1px solid var(--border); display: flex; align-items: center; gap: 1.25rem; transition: var(--transition); }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); }
        .stat-icon { width: 54px; height: 54px; border-radius: var(--radius-md); display: grid; place-items: center; font-size: 1.3rem; flex-shrink: 0; }
        .si-indigo { background: var(--indigo-pale); color: var(--indigo); }
        .si-green  { background: var(--green-pale);  color: var(--green);  }
        .si-red    { background: var(--red-pale);    color: var(--red);    }
        .stat-value { font-size: 2.1rem; font-weight: 800; line-height: 1; margin-bottom: .2rem; }
        .sv-indigo { color: var(--indigo); } .sv-green { color: var(--green); } .sv-red { color: var(--red); }
        .stat-label { font-size: .8rem; font-weight: 500; color: var(--text-muted); margin-bottom: .35rem; }
        .stat-tag { display: inline-flex; align-items: center; gap: .25rem; font-size: .7rem; font-weight: 600; padding: .2rem .55rem; border-radius: 99px; }
        .tag-indigo { background: var(--indigo-pale); color: var(--indigo); }
        .tag-green  { background: var(--green-pale);  color: #16a34a; }
        .tag-red    { background: var(--red-pale);    color: #dc2626; }

        /* LAYOUT */
        .main-layout { display: grid; grid-template-columns: 1fr 380px; gap: 1.5rem; align-items: start; }
        @media (max-width: 1024px) { .main-layout { grid-template-columns: 1fr; } }

        /* COMPOSE CARD */
        .compose-card { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); overflow: hidden; }
        .card-header-bar { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; background: var(--surface); }
        .card-header-bar h5 { font-weight: 700; font-size: .95rem; margin: 0; display: flex; align-items: center; gap: .5rem; }
        .card-body-pad { padding: 1.5rem; }

        /* FORM CONTROLS */
        .form-group { margin-bottom: 1.25rem; }
        .form-label-custom { font-size: .8rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .05em; margin-bottom: .5rem; display: block; }
        .form-control-custom {
            width: 100%; padding: .65rem 1rem; border: 1px solid var(--border);
            border-radius: var(--radius-sm); font-size: .875rem; font-family: inherit;
            color: var(--text-main); background: var(--bg); transition: var(--transition);
            outline: none;
        }
        .form-control-custom:focus { border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(99,102,241,.12); background: var(--surface); }
        textarea.form-control-custom { resize: vertical; min-height: 140px; line-height: 1.6; }

        /* PRIORITY SELECTOR */
        .priority-group { display: flex; gap: .5rem; }
        .priority-option { display: none; }
        .priority-label {
            flex: 1; display: flex; align-items: center; justify-content: center; gap: .4rem;
            padding: .55rem .75rem; border-radius: var(--radius-sm); border: 1.5px solid var(--border);
            font-size: .78rem; font-weight: 600; cursor: pointer; transition: var(--transition);
            color: var(--text-muted); background: var(--bg);
        }
        .priority-option:checked + .priority-label { border-color: var(--checked-color, var(--indigo)); background: var(--checked-bg, var(--indigo-pale)); color: var(--checked-color, var(--indigo)); }
        #p-normal:checked + label   { --checked-color: #6366f1; --checked-bg: #eef2ff; }
        #p-important:checked + label{ --checked-color: #d97706; --checked-bg: #fffbeb; }
        #p-urgent:checked + label   { --checked-color: #dc2626; --checked-bg: #fef2f2; }

        /* TOGGLE SWITCH */
        .toggle-row { display: flex; align-items: center; justify-content: space-between; padding: .9rem 1rem; background: var(--bg); border-radius: var(--radius-sm); border: 1px solid var(--border); }
        .toggle-info h6 { font-size: .875rem; font-weight: 600; margin-bottom: .1rem; }
        .toggle-info p  { font-size: .775rem; color: var(--text-muted); margin: 0; }
        .switch { position: relative; width: 44px; height: 24px; flex-shrink: 0; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; inset: 0; background: var(--border); border-radius: 99px; cursor: pointer; transition: var(--transition); }
        .slider::before { content: ''; position: absolute; width: 18px; height: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: var(--transition); box-shadow: var(--shadow-sm); }
        input:checked + .slider { background: var(--indigo); }
        input:checked + .slider::before { transform: translateX(20px); }

        /* TEACHER LIST */
        .teacher-list-wrap { max-height: 220px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); }
        .teacher-list-wrap::-webkit-scrollbar { width: 4px; }
        .teacher-list-wrap::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
        .teacher-item { display: flex; align-items: center; gap: .75rem; padding: .65rem 1rem; border-bottom: 1px solid #f1f5f9; cursor: pointer; transition: background var(--transition); }
        .teacher-item:last-child { border-bottom: none; }
        .teacher-item:hover { background: var(--indigo-pale); }
        .teacher-item input[type="checkbox"] { accent-color: var(--indigo); width: 15px; height: 15px; cursor: pointer; flex-shrink: 0; }
        .teacher-item label { font-size: .845rem; font-weight: 500; cursor: pointer; margin: 0; flex: 1; }
        .select-all-bar { padding: .5rem 1rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: .5rem; background: var(--bg); }
        .select-all-bar label { font-size: .75rem; font-weight: 600; color: var(--indigo); cursor: pointer; margin: 0; }

        /* SUBMIT BTN */
        .btn-submit { width: 100%; padding: .75rem; background: var(--indigo); color: #fff; font-family: inherit; font-size: .9rem; font-weight: 700; border: none; border-radius: var(--radius-sm); cursor: pointer; transition: var(--transition); display: flex; align-items: center; justify-content: center; gap: .5rem; }
        .btn-submit:hover { background: var(--indigo-dark); transform: translateY(-1px); box-shadow: 0 4px 14px rgba(99,102,241,.35); }
        .btn-submit:disabled { opacity: .5; pointer-events: none; }

        /* ALERT */
        .alert-custom { padding: .85rem 1rem; border-radius: var(--radius-sm); font-size: .85rem; font-weight: 500; margin-bottom: 1.25rem; display: flex; align-items: flex-start; gap: .6rem; }
        .alert-success { background: var(--green-pale); color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger   { background: var(--red-pale);  color: #991b1b; border: 1px solid #fecaca; }
        .alert-warning  { background: var(--amber-pale);color: #92400e; border: 1px solid #fde68a; }

        /* SQL BOX */
        .sql-box { background: #0f172a; color: #e2e8f0; border-radius: var(--radius-md); padding: 1.25rem; font-family: 'Courier New', monospace; font-size: .76rem; line-height: 1.7; overflow-x: auto; margin-top: .75rem; }

        /* FEED PANEL */
        .feed-panel { background: var(--surface); border-radius: var(--radius-lg); border: 1px solid var(--border); box-shadow: var(--shadow-sm); overflow: hidden; position: sticky; top: 80px; }
        .feed-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
        .feed-header h5 { font-size: .95rem; font-weight: 700; margin: 0; }
        .feed-list { max-height: 600px; overflow-y: auto; }
        .feed-list::-webkit-scrollbar { width: 4px; }
        .feed-list::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

        /* ANNOUNCEMENT ITEM */
        .ann-item { padding: 1.1rem 1.25rem; border-bottom: 1px solid #f1f5f9; transition: background var(--transition); position: relative; }
        .ann-item:last-child { border-bottom: none; }
        .ann-item:hover { background: #fafbff; }
        .ann-item-header { display: flex; align-items: flex-start; justify-content: space-between; gap: .5rem; margin-bottom: .4rem; }
        .ann-title { font-size: .875rem; font-weight: 700; color: var(--text-main); line-height: 1.3; flex: 1; }
        .ann-priority { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; margin-top: 5px; }
        .ann-excerpt { font-size: .8rem; color: var(--text-muted); line-height: 1.5; margin-bottom: .6rem; }
        .ann-meta { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .4rem; }
        .ann-author { font-size: .73rem; color: var(--text-light); display: flex; align-items: center; gap: .3rem; }
        .ann-time   { font-size: .73rem; color: var(--text-light); }
        .ann-badges { display: flex; gap: .3rem; flex-wrap: wrap; }
        .badge-pill { font-size: .68rem; font-weight: 600; padding: .2rem .55rem; border-radius: 99px; }
        .bp-all      { background: var(--green-pale); color: #16a34a; }
        .bp-selected { background: var(--indigo-pale); color: var(--indigo); }
        .bp-urgent   { background: var(--red-pale);   color: #dc2626; }
        .bp-important{ background: var(--amber-pale); color: #d97706; }
        .bp-normal   { background: var(--indigo-pale);color: var(--indigo); }

        /* ITEM ACTIONS */
        .ann-actions { display: flex; gap: .3rem; margin-top: .6rem; }
        .ann-act-btn { font-size: .72rem; font-weight: 600; padding: .25rem .65rem; border-radius: var(--radius-sm); border: 1px solid var(--border); background: var(--surface); cursor: pointer; transition: var(--transition); font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; }
        .ann-act-btn.edit-btn   { color: var(--indigo); }
        .ann-act-btn.edit-btn:hover   { background: var(--indigo-pale); border-color: var(--indigo); }
        .ann-act-btn.delete-btn { color: var(--red); }
        .ann-act-btn.delete-btn:hover { background: var(--red-pale); border-color: var(--red); }

        /* MODAL */
        .modal-overlay { position: fixed; inset: 0; background: rgba(15,23,42,.5); backdrop-filter: blur(4px); z-index: 999; display: none; place-items: center; }
        .modal-overlay.show { display: grid; }
        .modal-box { background: var(--surface); border-radius: var(--radius-lg); padding: 2rem; max-width: 420px; width: 90%; box-shadow: var(--shadow-lg); }
        .modal-box h4 { font-size: 1.05rem; font-weight: 700; margin-bottom: .5rem; }
        .modal-box p  { font-size: .875rem; color: var(--text-muted); margin-bottom: 1.5rem; }
        .modal-actions { display: flex; gap: .75rem; justify-content: flex-end; }
        .btn-cancel { padding: .6rem 1.25rem; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); font-family: inherit; font-size: .85rem; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .btn-cancel:hover { background: var(--bg); }
        .btn-confirm-del { padding: .6rem 1.25rem; border: none; border-radius: var(--radius-sm); background: var(--red); color: #fff; font-family: inherit; font-size: .85rem; font-weight: 600; cursor: pointer; transition: var(--transition); }
        .btn-confirm-del:hover { background: #dc2626; }

        /* EMPTY */
        .feed-empty { padding: 3rem 1.5rem; text-align: center; color: var(--text-muted); }
        .feed-empty-icon { width: 56px; height: 56px; background: var(--indigo-pale); border-radius: var(--radius-md); display: grid; place-items: center; margin: 0 auto .75rem; font-size: 1.4rem; color: var(--indigo); }

        /* RESPONSIVE */
        @media (max-width: 992px) { .stats-row { grid-template-columns: 1fr 1fr; } }
        @media (max-width: 768px) { .page-wrapper { padding: 1rem; } .page-hero { padding: 1.75rem 1.25rem 7rem; } .hero-title { font-size: 1.5rem; } .stats-row { grid-template-columns: 1fr; } .top-bar { padding: .75rem 1rem; } .breadcrumb { display: none; } }
    </style>
</head>
<body>

<!-- TOP BAR -->
<header class="top-bar">
    <a href="dashboard.php" class="brand">
        <div class="logo-box"><i class="fas fa-graduation-cap"></i></div>
        ALS Admin
    </a>
    <nav>
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="../dashboard.php" style="color:var(--text-muted);text-decoration:none;">Dashboard</a></li>
            <li class="breadcrumb-item" style="color:var(--indigo);font-weight:600;">Announcements</li>
        </ol>
    </nav>
    <div class="nav-actions">
        <button class="icon-btn"><i class="fas fa-bell"></i></button>
        <button class="icon-btn"><i class="fas fa-cog"></i></button>
        <div class="avatar-btn">AD</div>
    </div>
</header>

<div class="page-wrapper">

    <!-- HERO -->
    <div class="page-hero">
        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="hero-eyebrow"><i class="fas fa-bullhorn"></i> Communication Center</div>
                <h1 class="hero-title">Announcements</h1>
                <p class="hero-sub">Broadcast messages to all teachers or specific individuals instantly</p>
            </div>
            <button class="btn-hero" onclick="document.getElementById('title').focus()">
                <i class="fas fa-plus"></i> New Announcement
            </button>
        </div>
    </div>

    <!-- STAT CARDS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon si-indigo"><i class="fas fa-bullhorn"></i></div>
            <div>
                <div class="stat-value sv-indigo"><?= $total_ann ?></div>
                <div class="stat-label">Total Announcements</div>
                <span class="stat-tag tag-indigo"><i class="fas fa-history"></i> Last 20 shown</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-green"><i class="fas fa-broadcast-tower"></i></div>
            <div>
                <div class="stat-value sv-green"><?= $all_target ?></div>
                <div class="stat-label">Broadcast to All</div>
                <span class="stat-tag tag-green"><i class="fas fa-users"></i> All teachers</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon si-red"><i class="fas fa-exclamation-triangle"></i></div>
            <div>
                <div class="stat-value sv-red"><?= $urgent_count ?></div>
                <div class="stat-label">Urgent Notices</div>
                <span class="stat-tag tag-red"><i class="fas fa-fire"></i> High priority</span>
            </div>
        </div>
    </div>

    <!-- MAIN LAYOUT -->
    <div class="main-layout mb-5">

        <!-- ── LEFT: COMPOSE ── -->
        <div class="compose-card">
            <div class="card-header-bar">
                <h5 id="formTitle">
                    <i class="fas fa-pen" style="color:var(--indigo)"></i>
                    Compose Announcement
                </h5>
                <button class="icon-btn" id="resetBtn" title="Reset form" onclick="resetForm()" style="display:none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="card-body-pad">

                <!-- Alerts -->
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert-custom alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?= $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error'])): ?>
                <div class="alert-custom alert-danger">
                    <i class="fas fa-times-circle"></i>
                    <?= $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
                <?php endif; ?>
                <?php if ($table_error): ?>
                <div class="alert-custom alert-warning">
                    <i class="fas fa-database"></i>
                    <div>
                        <strong>Database Setup Required</strong><br>
                        Run the SQL below to create the required tables:
                        <div class="sql-box">
CREATE TABLE announcements (<br>
&nbsp;&nbsp;id INT(11) NOT NULL AUTO_INCREMENT,<br>
&nbsp;&nbsp;title VARCHAR(255) NOT NULL,<br>
&nbsp;&nbsp;content TEXT NOT NULL,<br>
&nbsp;&nbsp;admin_id INT(11) NOT NULL,<br>
&nbsp;&nbsp;priority ENUM('normal','important','urgent') DEFAULT 'normal',<br>
&nbsp;&nbsp;target_all TINYINT(1) NOT NULL DEFAULT 0,<br>
&nbsp;&nbsp;created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,<br>
&nbsp;&nbsp;updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,<br>
&nbsp;&nbsp;PRIMARY KEY (id),<br>
&nbsp;&nbsp;FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE<br>
);<br><br>
CREATE TABLE announcement_targets (<br>
&nbsp;&nbsp;id INT(11) NOT NULL AUTO_INCREMENT,<br>
&nbsp;&nbsp;announcement_id INT(11) NOT NULL,<br>
&nbsp;&nbsp;teacher_id INT(11) NOT NULL,<br>
&nbsp;&nbsp;PRIMARY KEY (id),<br>
&nbsp;&nbsp;FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,<br>
&nbsp;&nbsp;FOREIGN KEY (teacher_id) REFERENCES teachers(teacher_id) ON DELETE CASCADE<br>
);
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <form method="POST" id="announceForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="edit_id" id="editId" value="0">

                    <!-- Title -->
                    <div class="form-group">
                        <label class="form-label-custom" for="title">Announcement Title <span style="color:var(--red)">*</span></label>
                        <input type="text" id="title" name="title" class="form-control-custom"
                               placeholder="e.g. Staff Meeting — Friday 3PM" required
                               <?= $table_error ? 'disabled' : '' ?>>
                    </div>

                    <!-- Content -->
                    <div class="form-group">
                        <label class="form-label-custom" for="content">Message <span style="color:var(--red)">*</span></label>
                        <textarea id="content" name="content" class="form-control-custom"
                                  placeholder="Write your announcement here…" required
                                  <?= $table_error ? 'disabled' : '' ?>></textarea>
                        <div style="font-size:.72rem;color:var(--text-light);margin-top:.3rem;text-align:right">
                            <span id="charCount">0</span> characters
                        </div>
                    </div>

                    <!-- Priority -->
                    <div class="form-group">
                        <label class="form-label-custom">Priority Level</label>
                        <div class="priority-group">
                            <input type="radio" name="priority" id="p-normal" value="normal" class="priority-option" checked>
                            <label for="p-normal" class="priority-label">
                                <i class="fas fa-info-circle"></i> Normal
                            </label>
                            <input type="radio" name="priority" id="p-important" value="important" class="priority-option">
                            <label for="p-important" class="priority-label">
                                <i class="fas fa-exclamation-circle"></i> Important
                            </label>
                            <input type="radio" name="priority" id="p-urgent" value="urgent" class="priority-option">
                            <label for="p-urgent" class="priority-label">
                                <i class="fas fa-exclamation-triangle"></i> Urgent
                            </label>
                        </div>
                    </div>

                    <!-- Target toggle -->
                    <div class="form-group">
                        <label class="form-label-custom">Recipients</label>
                        <div class="toggle-row">
                            <div class="toggle-info">
                                <h6>Broadcast to All Teachers</h6>
                                <p>Disable to select specific teachers</p>
                            </div>
                            <label class="switch">
                                <input type="checkbox" id="target_all" name="target_all" checked>
                                <span class="slider"></span>
                            </label>
                        </div>
                    </div>

                    <!-- Teacher selector -->
                    <div class="form-group" id="teacherSelection" style="display:none">
                        <label class="form-label-custom">Select Teachers</label>
                        <div class="teacher-list-wrap">
                            <div class="select-all-bar">
                                <input type="checkbox" id="selectAll" style="accent-color:var(--indigo);width:14px;height:14px;cursor:pointer">
                                <label for="selectAll">Select / deselect all</label>
                            </div>
                            <?php foreach ($teachers as $t): ?>
                            <div class="teacher-item" onclick="this.querySelector('input').click(); event.stopPropagation();">
                                <input type="checkbox" name="target_teachers[]"
                                       value="<?= $t['teacher_id'] ?>"
                                       id="tc_<?= $t['teacher_id'] ?>"
                                       onclick="event.stopPropagation()">
                                <label for="tc_<?= $t['teacher_id'] ?>">
                                    <?= htmlspecialchars($t['full_name']) ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="font-size:.75rem;color:var(--text-muted);margin-top:.4rem">
                            <span id="selectedCount">0</span> teacher(s) selected
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-submit" id="submitBtn"
                            <?= $table_error ? 'disabled' : '' ?>>
                        <i class="fas fa-paper-plane"></i>
                        <span id="submitText">Send Announcement</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- ── RIGHT: FEED ── -->
        <div class="feed-panel">
            <div class="feed-header">
                <h5><i class="fas fa-clock" style="color:var(--indigo);margin-right:.4rem"></i> Recent Announcements</h5>
                <span class="stat-tag tag-indigo"><?= $total_ann ?> total</span>
            </div>

            <div class="feed-list">
                <?php if ($table_error || empty($announcements)): ?>
                <div class="feed-empty">
                    <div class="feed-empty-icon"><i class="fas fa-bullhorn"></i></div>
                    <p style="font-size:.85rem">No announcements yet. <br>Compose your first one!</p>
                </div>
                <?php else: ?>
                <?php foreach ($announcements as $ann):
                    $prio   = $ann['priority'] ?? 'normal';
                    $pcfg   = $priority_map[$prio] ?? $priority_map['normal'];
                    $excerpt= mb_substr(strip_tags($ann['content']), 0, 100) . (mb_strlen($ann['content']) > 100 ? '…' : '');
                    $ago    = time() - strtotime($ann['created_at']);
                    $ago_str= $ago < 60 ? 'just now' : ($ago < 3600 ? floor($ago/60).'m ago' : ($ago < 86400 ? floor($ago/3600).'h ago' : date('M j', strtotime($ann['created_at']))));
                ?>
                <div class="ann-item">
                    <div class="ann-item-header">
                        <div class="ann-title"><?= htmlspecialchars($ann['title']) ?></div>
                        <div class="ann-priority" style="background:<?= $pcfg['color'] ?>" title="<?= $pcfg['label'] ?>"></div>
                    </div>
                    <div class="ann-excerpt"><?= htmlspecialchars($excerpt) ?></div>
                    <div class="ann-meta">
                        <div>
                            <div class="ann-author"><i class="fas fa-user-shield"></i><?= htmlspecialchars($ann['admin_name'] ?? 'Admin') ?></div>
                            <div class="ann-time"><?= $ago_str ?></div>
                        </div>
                        <div class="ann-badges">
                            <span class="badge-pill <?= $ann['target_all'] ? 'bp-all' : 'bp-selected' ?>">
                                <i class="fas fa-<?= $ann['target_all'] ? 'users' : 'user-check' ?>"></i>
                                <?= $ann['target_all'] ? 'All' : $ann['target_count'].' teachers' ?>
                            </span>
                            <span class="badge-pill bp-<?= $prio ?>"><?= $pcfg['label'] ?></span>
                        </div>
                    </div>
                    <div class="ann-actions">
                        <button class="ann-act-btn edit-btn"
                                onclick="editAnnouncement(<?= $ann['id'] ?>, <?= htmlspecialchars(json_encode($ann['title'])) ?>, <?= htmlspecialchars(json_encode($ann['content'])) ?>, '<?= $prio ?>', <?= $ann['target_all'] ?>)">
                            <i class="fas fa-pen"></i> Edit
                        </button>
                        <button class="ann-act-btn delete-btn" onclick="confirmDelete(<?= $ann['id'] ?>, <?= htmlspecialchars(json_encode($ann['title'])) ?>)">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div style="width:52px;height:52px;background:var(--red-pale);border-radius:var(--radius-md);display:grid;place-items:center;font-size:1.4rem;color:var(--red);margin-bottom:1rem">
            <i class="fas fa-trash"></i>
        </div>
        <h4>Delete Announcement?</h4>
        <p id="deleteModalText">This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="btn-cancel" onclick="closeModal()">Cancel</button>
            <form method="POST" id="deleteForm" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="announcement_id" id="deleteId" value="">
                <button type="submit" class="btn-confirm-del"><i class="fas fa-trash"></i> Delete</button>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ── TARGET ALL TOGGLE ──
document.getElementById('target_all').addEventListener('change', function () {
    document.getElementById('teacherSelection').style.display = this.checked ? 'none' : 'block';
});

// ── SELECT ALL TEACHERS ──
document.getElementById('selectAll').addEventListener('change', function () {
    document.querySelectorAll('input[name="target_teachers[]"]').forEach(cb => cb.checked = this.checked);
    updateSelectedCount();
});
document.querySelectorAll('input[name="target_teachers[]"]').forEach(cb => {
    cb.addEventListener('change', updateSelectedCount);
});
function updateSelectedCount() {
    const n = document.querySelectorAll('input[name="target_teachers[]"]:checked').length;
    document.getElementById('selectedCount').textContent = n;
}

// ── CHAR COUNT ──
const contentEl = document.getElementById('content');
contentEl.addEventListener('input', () => {
    document.getElementById('charCount').textContent = contentEl.value.length;
});

// ── EDIT ──
function editAnnouncement(id, title, content, priority, targetAll) {
    document.getElementById('editId').value     = id;
    document.getElementById('title').value      = title;
    document.getElementById('content').value    = content;
    document.getElementById('charCount').textContent = content.length;

    // Set priority radio
    const radio = document.querySelector(`input[name="priority"][value="${priority}"]`);
    if (radio) radio.checked = true;

    // Set target toggle
    const toggle = document.getElementById('target_all');
    toggle.checked = !!targetAll;
    document.getElementById('teacherSelection').style.display = targetAll ? 'none' : 'block';

    // Update UI
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-edit" style="color:var(--amber)"></i> Edit Announcement';
    document.getElementById('submitText').textContent = 'Update Announcement';
    document.getElementById('submitBtn').style.background = 'var(--amber)';
    document.getElementById('resetBtn').style.display = 'grid';

    window.scrollTo({ top: 0, behavior: 'smooth' });
    document.getElementById('title').focus();
}

function resetForm() {
    document.getElementById('announceForm').reset();
    document.getElementById('editId').value       = '0';
    document.getElementById('charCount').textContent = '0';
    document.getElementById('formTitle').innerHTML = '<i class="fas fa-pen" style="color:var(--indigo)"></i> Compose Announcement';
    document.getElementById('submitText').textContent = 'Send Announcement';
    document.getElementById('submitBtn').style.background = 'var(--indigo)';
    document.getElementById('resetBtn').style.display = 'none';
    document.getElementById('teacherSelection').style.display = 'none';
    updateSelectedCount();
}

// ── DELETE MODAL ──
function confirmDelete(id, title) {
    document.getElementById('deleteId').value     = id;
    document.getElementById('deleteModalText').textContent = `"${title}" will be permanently deleted.`;
    document.getElementById('deleteModal').classList.add('show');
}
function closeModal() {
    document.getElementById('deleteModal').classList.remove('show');
}
document.getElementById('deleteModal').addEventListener('click', function (e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>