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

// ── Query ──────────────────────────────────────────────────────────────────────
$teachers = [];
$result = $conn->query(
    "SELECT t.teacher_id, t.full_name, t.email, t.phone, t.specialization,
            t.status, t.date_joined,
            COUNT(s.id) as student_count
     FROM teachers t
     LEFT JOIN students s ON t.teacher_id = s.teacher_id
     GROUP BY t.teacher_id
     ORDER BY t.full_name"
);
if ($result) $teachers = $result->fetch_all(MYSQLI_ASSOC);

$total_teachers        = count($teachers);
$active_teachers_count = count(array_filter($teachers, fn($t) => $t['status'] === 'active'));
$inactive_teachers_count = $total_teachers - $active_teachers_count;

function getInitials($name) {
    $words = explode(' ', trim($name)); $i = '';
    foreach ($words as $w) $i .= strtoupper(substr($w, 0, 1));
    return substr($i, 0, 2);
}

$avatar_colors = ['#6366f1','#8b5cf6','#ec4899','#f43f5e','#f97316','#eab308','#22c55e','#14b8a6','#0ea5e9','#3b82f6'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/logo">
    <title>Teacher Monitoring — ALS Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{--indigo:#6366f1;--indigo-dark:#4f46e5;--indigo-pale:#eef2ff;--green:#22c55e;--green-pale:#f0fdf4;--red:#ef4444;--red-pale:#fef2f2;--amber:#f59e0b;--amber-pale:#fffbeb;--sky:#0ea5e9;--bg:#f8fafc;--surface:#ffffff;--border:#e2e8f0;--text-main:#0f172a;--text-muted:#64748b;--text-light:#94a3b8;--radius-sm:6px;--radius-md:12px;--radius-lg:20px;--shadow-sm:0 1px 3px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);--shadow-md:0 4px 16px rgba(0,0,0,.08);--shadow-lg:0 10px 40px rgba(99,102,241,.15);--transition:.2s cubic-bezier(.4,0,.2,1)}
        body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text-main);min-height:100vh}
        .top-bar{background:var(--surface);border-bottom:1px solid var(--border);padding:.875rem 2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;gap:1rem}
        .brand{display:flex;align-items:center;gap:.6rem;font-weight:700;font-size:1rem;color:var(--indigo);text-decoration:none}
        .logo-box{width:32px;height:32px;background:var(--indigo);border-radius:var(--radius-sm);display:grid;place-items:center;color:#fff;font-size:.85rem}
        .breadcrumb{font-size:.78rem;color:var(--text-muted);margin:0}
        .breadcrumb-item+.breadcrumb-item::before{color:var(--text-light)}
        .nav-actions{display:flex;align-items:center;gap:.6rem}
        .icon-btn{width:36px;height:36px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);display:grid;place-items:center;color:var(--text-muted);cursor:pointer;transition:var(--transition);font-size:.88rem;outline:none}
        .icon-btn:hover{background:var(--indigo-pale);color:var(--indigo);border-color:var(--indigo)}
        .avatar-btn{width:36px;height:36px;border-radius:50%;background:var(--indigo);display:grid;place-items:center;color:#fff;font-size:.78rem;font-weight:700;cursor:pointer}
        .page-wrapper{padding:2rem;max-width:1400px;margin:0 auto}
        .page-hero{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 55%,#a855f7 100%);border-radius:var(--radius-lg);padding:2.5rem 2.5rem 8rem;position:relative;overflow:hidden;margin-bottom:-5.75rem}
        .page-hero::before{content:'';position:absolute;inset:0;background-image:radial-gradient(circle at 80% 20%,rgba(255,255,255,.09) 0%,transparent 50%),radial-gradient(circle at 15% 80%,rgba(255,255,255,.05) 0%,transparent 45%)}
        .page-hero::after{content:'';position:absolute;bottom:0;left:0;right:0;height:55px;background:var(--bg);border-radius:var(--radius-lg) var(--radius-lg) 0 0}
        .hero-content{position:relative;z-index:1}
        .hero-eyebrow{display:inline-flex;align-items:center;gap:.4rem;background:rgba(255,255,255,.15);backdrop-filter:blur(8px);color:rgba(255,255,255,.95);font-size:.72rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;padding:.35rem .85rem;border-radius:99px;border:1px solid rgba(255,255,255,.2);margin-bottom:1rem}
        .hero-title{font-size:2rem;font-weight:800;color:#fff;line-height:1.15;margin-bottom:.4rem}
        .hero-sub{color:rgba(255,255,255,.72);font-size:.9rem;font-weight:400}
        .btn-add{background:#fff;color:var(--indigo-dark);font-weight:700;font-size:.83rem;padding:.7rem 1.5rem;border-radius:var(--radius-sm);border:none;display:inline-flex;align-items:center;gap:.5rem;text-decoration:none;transition:var(--transition);box-shadow:0 4px 14px rgba(0,0,0,.15)}
        .btn-add:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.2);color:var(--indigo-dark);background:#f5f3ff}
        .stats-row{position:relative;z-index:10;display:grid;grid-template-columns:repeat(3,1fr);gap:1.25rem;margin-bottom:1.75rem}
        .stat-card{background:var(--surface);border-radius:var(--radius-md);padding:1.5rem;box-shadow:var(--shadow-md);border:1px solid var(--border);display:flex;align-items:center;gap:1.25rem;transition:var(--transition)}
        .stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg)}
        .stat-icon{width:54px;height:54px;border-radius:var(--radius-md);display:grid;place-items:center;font-size:1.3rem;flex-shrink:0}
        .stat-icon.total{background:var(--indigo-pale);color:var(--indigo)}.stat-icon.active{background:var(--green-pale);color:var(--green)}.stat-icon.inactive{background:var(--red-pale);color:var(--red)}
        .stat-value{font-size:2.1rem;font-weight:800;line-height:1;margin-bottom:.2rem}
        .stat-value.total{color:var(--indigo)}.stat-value.active{color:var(--green)}.stat-value.inactive{color:var(--red)}
        .stat-label{font-size:.8rem;font-weight:500;color:var(--text-muted);margin-bottom:.4rem}
        .stat-tag{display:inline-flex;align-items:center;gap:.25rem;font-size:.7rem;font-weight:600;padding:.2rem .55rem;border-radius:99px}
        .tag-indigo{background:var(--indigo-pale);color:var(--indigo)}.tag-green{background:var(--green-pale);color:#16a34a}.tag-red{background:var(--red-pale);color:#dc2626}
        .table-card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow-sm);overflow:hidden}
        .table-toolbar{padding:1.1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem}
        .toolbar-left{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
        .toolbar-title{font-weight:700;font-size:.95rem;color:var(--text-main)}
        .count-pill{background:var(--indigo-pale);color:var(--indigo);font-size:.7rem;font-weight:700;padding:.25rem .65rem;border-radius:99px}
        .search-wrap{position:relative;width:240px}
        .search-wrap input{width:100%;padding:.55rem 1rem .55rem 2.4rem;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.83rem;font-family:inherit;background:var(--bg);color:var(--text-main);transition:var(--transition)}
        .search-wrap input:focus{outline:none;border-color:var(--indigo);box-shadow:0 0 0 3px rgba(99,102,241,.12);background:var(--surface)}
        .search-wrap input::placeholder{color:var(--text-light)}
        .search-icon{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.78rem;pointer-events:none}
        .filter-tabs{display:flex;gap:.25rem;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.25rem}
        .filter-tab{padding:.3rem .85rem;border-radius:4px;font-size:.78rem;font-weight:500;color:var(--text-muted);cursor:pointer;border:none;background:transparent;transition:var(--transition);font-family:inherit}
        .filter-tab.active{background:var(--surface);color:var(--indigo);font-weight:700;box-shadow:var(--shadow-sm)}
        .filter-tab:not(.active):hover{color:var(--indigo);background:rgba(99,102,241,.05)}
        .table-wrap{overflow-x:auto}
        table{width:100%;border-collapse:collapse}
        thead th{background:#f8fafc;padding:.9rem 1.25rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);border-bottom:1px solid var(--border);white-space:nowrap}
        thead th:first-child{padding-left:1.5rem}thead th:last-child{padding-right:1.5rem;text-align:center}
        tbody tr{border-bottom:1px solid #f1f5f9;transition:background var(--transition)}
        tbody tr:last-child{border-bottom:none}tbody tr:hover{background:#fafbff}
        tbody td{padding:1rem 1.25rem;font-size:.845rem;vertical-align:middle}
        tbody td:first-child{padding-left:1.5rem}tbody td:last-child{padding-right:1.5rem}
        .row-num{color:var(--text-light);font-size:.78rem;font-weight:600}
        .teacher-avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;font-weight:700;font-size:.85rem;color:#fff;flex-shrink:0}
        .teacher-name{font-weight:600;font-size:.875rem;margin-bottom:.1rem}
        .teacher-email{font-size:.775rem;color:var(--text-muted)}
        .spec-badge{background:var(--indigo-pale);color:var(--indigo);font-size:.72rem;font-weight:600;padding:.3rem .75rem;border-radius:99px;white-space:nowrap}
        .student-chip{display:inline-flex;align-items:center;gap:.4rem;background:#f8fafc;border:1px solid var(--border);border-radius:99px;padding:.3rem .8rem;font-size:.8rem;font-weight:600;color:var(--text-main);white-space:nowrap}
        .student-chip i{color:var(--sky);font-size:.72rem}
        .status-pill{display:inline-flex;align-items:center;gap:.4rem;font-size:.74rem;font-weight:600;padding:.3rem .85rem;border-radius:99px;white-space:nowrap}
        .status-pill .dot{width:6px;height:6px;border-radius:50%}
        .status-pill.active{background:var(--green-pale);color:#16a34a}.status-pill.active .dot{background:var(--green)}
        .status-pill.inactive{background:var(--red-pale);color:#dc2626}.status-pill.inactive .dot{background:var(--red)}
        .date-text{font-size:.8rem;color:var(--text-muted);white-space:nowrap}
        .actions{display:flex;gap:.4rem;justify-content:center}
        .act-btn{width:32px;height:32px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);display:grid;place-items:center;font-size:.76rem;cursor:pointer;text-decoration:none;transition:var(--transition);color:inherit}
        .act-btn:hover{transform:scale(1.12)}
        .act-btn.view{color:var(--sky)}.act-btn.report{color:var(--amber)}.act-btn.edit{color:var(--indigo)}
        .act-btn.view:hover{background:#e0f2fe;border-color:var(--sky)}
        .act-btn.report:hover{background:var(--amber-pale);border-color:var(--amber)}
        .act-btn.edit:hover{background:var(--indigo-pale);border-color:var(--indigo)}
        .empty-state{text-align:center;padding:5rem 2rem;color:var(--text-muted)}
        .empty-icon{width:72px;height:72px;background:var(--indigo-pale);border-radius:var(--radius-lg);display:grid;place-items:center;margin:0 auto 1.25rem;font-size:1.75rem;color:var(--indigo)}
        .empty-state h4{font-size:1.1rem;font-weight:700;color:var(--text-main);margin-bottom:.4rem}
        .empty-state p{font-size:.85rem;margin-bottom:1.25rem}
        .table-footer{padding:1rem 1.5rem;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;background:var(--bg)}
        .showing-text{font-size:.78rem;color:var(--text-muted)}
        .pagination-wrap{display:flex;gap:.3rem}
        .pg-btn{width:32px;height:32px;border-radius:var(--radius-sm);border:1px solid var(--border);background:var(--surface);font-size:.78rem;font-weight:600;font-family:inherit;color:var(--text-muted);cursor:pointer;display:grid;place-items:center;transition:var(--transition)}
        .pg-btn:hover,.pg-btn.active{background:var(--indigo);color:#fff;border-color:var(--indigo)}
        .pg-btn:disabled{opacity:.35;pointer-events:none}
        @media(max-width:992px){.stats-row{grid-template-columns:repeat(2,1fr)}}
        @media(max-width:768px){.page-wrapper{padding:1rem}.page-hero{padding:1.75rem 1.25rem 7rem}.hero-title{font-size:1.5rem}.stats-row{grid-template-columns:1fr}.table-toolbar{flex-direction:column;align-items:stretch}.toolbar-left{flex-direction:column;align-items:stretch}.search-wrap{width:100%}.filter-tabs{justify-content:center}.top-bar{padding:.75rem 1rem}.breadcrumb{display:none}}
    </style>
</head>
<body>

<header class="top-bar">
    <a href="/AdminDashboard" class="brand">
        <div class="logo-box"><i class="fas fa-graduation-cap"></i></div>
        ALS Admin
    </a>
    <nav>
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="/AdminDashboard" style="color:var(--text-muted);text-decoration:none">Dashboard</a></li>
            <li class="breadcrumb-item" style="color:var(--indigo);font-weight:600">Teachers</li>
        </ol>
    </nav>
    <div class="nav-actions">
        <button class="icon-btn" title="Notifications"><i class="fas fa-bell"></i></button>
        <button class="icon-btn" title="Settings"><i class="fas fa-cog"></i></button>
        <div class="avatar-btn" title="Admin">AD</div>
    </div>
</header>

<div class="page-wrapper">

    <div class="page-hero">
        <div class="hero-content d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <div class="hero-eyebrow"><i class="fas fa-chalkboard-teacher"></i> Teacher Management</div>
                <h1 class="hero-title">Teacher Dashboard</h1>
                <p class="hero-sub">Monitor, manage and track all your teachers from one place</p>
            </div>
            <a href="/AdminAddteachers" class="btn-add"><i class="fas fa-plus"></i> Add New Teacher</a>
        </div>
    </div>

    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon total"><i class="fas fa-users"></i></div>
            <div>
                <div class="stat-value total"><?= $total_teachers ?></div>
                <div class="stat-label">Total Teachers</div>
                <span class="stat-tag tag-indigo"><i class="fas fa-layer-group"></i> All enrolled</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon active"><i class="fas fa-user-check"></i></div>
            <div>
                <div class="stat-value active"><?= $active_teachers_count ?></div>
                <div class="stat-label">Active Teachers</div>
                <span class="stat-tag tag-green"><i class="fas fa-circle" style="font-size:.45rem"></i> Currently teaching</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon inactive"><i class="fas fa-user-clock"></i></div>
            <div>
                <div class="stat-value inactive"><?= $inactive_teachers_count ?></div>
                <div class="stat-label">Inactive Teachers</div>
                <span class="stat-tag tag-red"><i class="fas fa-circle" style="font-size:.45rem"></i> On leave / inactive</span>
            </div>
        </div>
    </div>

    <div class="table-card mb-5">
        <div class="table-toolbar">
            <div class="toolbar-left">
                <span class="toolbar-title">All Teachers</span>
                <span class="count-pill" id="countBadge"><?= $total_teachers ?> records</span>
            </div>
            <div class="toolbar-left">
                <div class="filter-tabs">
                    <button class="filter-tab active" data-filter="all">All</button>
                    <button class="filter-tab" data-filter="active">Active</button>
                    <button class="filter-tab" data-filter="inactive">Inactive</button>
                </div>
                <div class="search-wrap">
                    <i class="fas fa-search search-icon"></i>
                    <input type="text" id="searchInput" placeholder="Search by name, email…">
                </div>
            </div>
        </div>

        <div class="table-wrap">
            <?php if (empty($teachers)): ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-users"></i></div>
                <h4>No Teachers Found</h4>
                <p>Add your first teacher to get started</p>
                <a href="/AdminAddteachers" class="btn-add" style="display:inline-flex"><i class="fas fa-plus"></i> Add Teacher</a>
            </div>
            <?php else: ?>
            <table id="teachersTable">
                <thead>
                    <tr>
                        <th>#</th><th>Teacher</th><th>Specialization</th>
                        <th>Students</th><th>Status</th><th>Date Joined</th><th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($teachers as $index => $teacher):
                    $initials = getInitials($teacher['full_name']);
                    $color    = $avatar_colors[$index % count($avatar_colors)];
                    // One token per teacher — reused for both action links
                    $tok      = issue_teacher_token((int)$teacher['teacher_id']);
                ?>
                <tr class="teacher-row" data-status="<?= htmlspecialchars($teacher['status']) ?>">
                    <td><span class="row-num"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></span></td>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <div class="teacher-avatar" style="background:<?= $color ?>">
                                <?php if (!empty($teacher['profile_image'])): ?>
                                    <img src="<?= htmlspecialchars($teacher['profile_image']) ?>"
                                         alt="<?= htmlspecialchars($teacher['full_name']) ?>"
                                         style="width:100%;height:100%;border-radius:50%;object-fit:cover">
                                <?php else: ?>
                                    <?= $initials ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="teacher-name"><?= htmlspecialchars($teacher['full_name']) ?></div>
                                <div class="teacher-email"><?= htmlspecialchars($teacher['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="spec-badge"><?= htmlspecialchars($teacher['specialization']) ?></span></td>
                    <td>
                        <span class="student-chip">
                            <i class="fas fa-user-graduate"></i>
                            <?= (int)$teacher['student_count'] ?>
                        </span>
                    </td>
                    <td>
                        <span class="status-pill <?= htmlspecialchars($teacher['status']) ?>">
                            <span class="dot"></span><?= ucfirst($teacher['status']) ?>
                        </span>
                    </td>
                    <td><span class="date-text"><?= date('M d, Y', strtotime($teacher['date_joined'])) ?></span></td>
                    <td>
                        <div class="actions">
                            <!-- Details: /AdminTeacherDetails?<token> — no id= -->
                            <a href="/AdminTeacherDetails?<?= $tok ?>" class="act-btn view" title="View Details">
                                <i class="fas fa-eye"></i>
                            </a>
                            <!-- Reports: /AdminTeacherReports?<token> — no teacher_id= -->
                            <a href="/AdminTeacherReports?<?= $tok ?>" class="act-btn report" title="View Reports">
                                <i class="fas fa-chart-bar"></i>
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php if (!empty($teachers)): ?>
        <div class="table-footer">
            <span class="showing-text" id="showingText">
                Showing <?= $total_teachers ?> of <?= $total_teachers ?> teachers
            </span>
            <div class="pagination-wrap">
                <button class="pg-btn" id="prevBtn" disabled><i class="fas fa-chevron-left"></i></button>
                <button class="pg-btn active">1</button>
                <button class="pg-btn" id="nextBtn"><i class="fas fa-chevron-right"></i></button>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const rows=document.querySelectorAll('.teacher-row');
    const searchEl=document.getElementById('searchInput');
    const badgeEl=document.getElementById('countBadge');
    const showingEl=document.getElementById('showingText');
    const filterTabs=document.querySelectorAll('.filter-tab');
    const total=rows.length;
    let currentFilter='all', currentTerm='';

    function applyFilters(){
        let visible=0;
        rows.forEach(row=>{
            const status=row.dataset.status;
            const name=row.querySelector('.teacher-name')?.textContent.toLowerCase()||'';
            const email=row.querySelector('.teacher-email')?.textContent.toLowerCase()||'';
            const spec=row.querySelector('.spec-badge')?.textContent.toLowerCase()||'';
            const matchFilter=currentFilter==='all'||status===currentFilter;
            const matchSearch=!currentTerm||name.includes(currentTerm)||email.includes(currentTerm)||spec.includes(currentTerm);
            const show=matchFilter&&matchSearch;
            row.style.display=show?'':'none';
            if(show)visible++;
        });
        if(badgeEl) badgeEl.textContent=visible+' records';
        if(showingEl) showingEl.textContent=`Showing ${visible} of ${total} teachers`;
    }

    if(searchEl) searchEl.addEventListener('input', e=>{ currentTerm=e.target.value.trim().toLowerCase(); applyFilters(); });

    filterTabs.forEach(tab=>{
        tab.addEventListener('click',()=>{
            filterTabs.forEach(t=>t.classList.remove('active'));
            tab.classList.add('active');
            currentFilter=tab.dataset.filter;
            applyFilters();
        });
    });
});
</script>
</body>
</html>