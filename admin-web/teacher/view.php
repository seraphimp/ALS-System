<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/teacher_functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    redirect('/admin-secure');
}

// ── Token helpers (shared with all teacher pages via $_SESSION['_tt']) ─────────

function issue_teacher_token(int $teacher_id): string {
    if (!isset($_SESSION['_tt']) || !is_array($_SESSION['_tt'])) $_SESSION['_tt'] = [];
    $sid = (string)$teacher_id;
    $existing = array_search($sid, $_SESSION['_tt'], true);
    if ($existing !== false) return $existing;
    $token = bin2hex(random_bytes(20));
    $_SESSION['_tt'][$token] = $sid;
    if (count($_SESSION['_tt']) > 500) $_SESSION['_tt'] = array_slice($_SESSION['_tt'], -500, null, true);
    return $token;
}

function resolve_teacher_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_tt'][$token] ?? 0);
}

// ── Resolve token from raw query string ────────────────────────────────────────
// URL format: /AdminViewTeachers?a3f9c2e1d4b7...  (no key name)
$raw = trim($_SERVER['QUERY_STRING'] ?? '');
// Strip any extra params like &success=1
if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));
// Fallback: also accept ?_t=<token>
if (empty($raw) && isset($_GET['_t'])) $raw = trim($_GET['_t']);

if (empty($raw)) {
    $_SESSION['error'] = "Invalid access — no token provided.";
    header('Location: /AdminAllTeachers'); exit();
}

$teacher_id = resolve_teacher_token($raw);

if (!$teacher_id) {
    $_SESSION['error'] = "Invalid or expired link.";
    header('Location: /AdminAllTeachers'); exit();
}

$teacher = get_teacher($conn, $teacher_id);
if (!$teacher) {
    $_SESSION['error'] = "Teacher not found.";
    header('Location: /AdminAllTeachers'); exit();
}

// Issue a fresh token for the edit link on this page
$edit_tok = issue_teacher_token($teacher_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Profile - <?= htmlspecialchars($teacher['full_name']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root{--primary:#4e73df;--secondary:#6c757d;--success:#1cc88a;--info:#36b9cc;--warning:#f6c23e;--danger:#e74a3b;--light:#f8f9fc;--dark:#5a5c69;--bg-color:#f8f9fc;--card-shadow:0 .15rem 1.75rem 0 rgba(58,59,69,.15)}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif}
        body{background-color:var(--bg-color);color:#333;line-height:1.6}
        .container{max-width:1200px;margin:0 auto;padding:20px}
        .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;padding-bottom:15px;border-bottom:1px solid #e3e6f0;flex-wrap:wrap;gap:15px}
        .page-header h1{color:var(--dark);font-weight:600;font-size:1.8rem}
        .header-actions{display:flex;gap:10px;flex-wrap:wrap}
        .btn{display:inline-flex;align-items:center;gap:5px;padding:10px 15px;border-radius:6px;text-decoration:none;font-weight:500;cursor:pointer;transition:all .3s;border:none}
        .btn-outline{color:var(--secondary);background:transparent;border:1px solid var(--secondary)}.btn-outline:hover{background-color:var(--secondary);color:white}
        .btn-primary{background-color:var(--primary);color:white}.btn-primary:hover{background-color:#3a5fc8;transform:translateY(-2px)}
        .profile-view{background:white;border-radius:10px;box-shadow:var(--card-shadow);overflow:hidden;margin-bottom:25px}
        .profile-header{background:linear-gradient(180deg,var(--primary) 10%,#3a5fc8 100%);color:white;padding:30px;display:flex;align-items:center;gap:20px;flex-wrap:wrap}
        .profile-avatar{width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:bold;border:3px solid white}
        .profile-title h2{font-size:1.8rem;margin-bottom:5px}
        .profile-title .text-muted{color:rgba(255,255,255,.8);font-size:1.1rem;margin-bottom:10px}
        .badge{padding:5px 12px;border-radius:20px;font-size:.85rem;font-weight:600}
        .badge-success{background-color:var(--success);color:white}.badge-secondary{background-color:var(--secondary);color:white}
        .profile-details{padding:30px;display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:30px}
        .detail-section{display:flex;flex-direction:column;gap:15px}
        .detail-section h3{color:var(--dark);font-size:1.3rem;padding-bottom:10px;border-bottom:2px solid #e3e6f0;margin-bottom:5px}
        .detail-row{display:flex;flex-direction:column;gap:5px}
        .detail-label{font-weight:600;color:var(--dark);font-size:.9rem}
        .detail-value{color:#555;font-size:1.05rem}
        .alert{padding:15px;margin-bottom:25px;border-radius:6px;font-weight:500}
        .alert-error{background-color:#f8d7da;color:#721c24;border:1px solid #f5c6cb}
        .alert-success{background-color:#d4edda;color:#155724;border:1px solid #c3e6cb}
        @media(max-width:768px){.profile-header{flex-direction:column;text-align:center;padding:20px}.profile-details{grid-template-columns:1fr;padding:20px}.page-header{flex-direction:column;align-items:flex-start}.header-actions{width:100%;justify-content:flex-start}}
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <h1><i class="fas fa-user-graduate"></i> Teacher Profile: <?= htmlspecialchars($teacher['full_name']) ?></h1>
        <div class="header-actions">
            <!-- Edit link uses token — no id= visible -->
            <a href="/AdminEditTeachers?<?= $edit_tok ?>" class="btn btn-primary">
                <i class="fas fa-edit"></i> Edit Profile
            </a>
            <a href="/AdminAllTeachers" class="btn btn-outline">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>
    </div>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="profile-view">
        <div class="profile-header">
            <div class="profile-avatar">
                <span><?= strtoupper(substr($teacher['full_name'], 0, 1)) ?></span>
            </div>
            <div class="profile-title">
                <h2><?= htmlspecialchars($teacher['full_name']) ?></h2>
                <p class="text-muted"><?= htmlspecialchars($teacher['specialization']) ?></p>
                <span class="badge <?= $teacher['status']==='active'?'badge-success':'badge-secondary' ?>">
                    <?= ucfirst($teacher['status']) ?>
                </span>
            </div>
        </div>

        <div class="profile-details">
            <div class="detail-section">
                <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                <div class="detail-row"><span class="detail-label">Email:</span><span class="detail-value"><?= htmlspecialchars($teacher['email']) ?></span></div>
                <div class="detail-row"><span class="detail-label">Phone:</span><span class="detail-value"><?= htmlspecialchars($teacher['phone']) ?></span></div>
                <div class="detail-row"><span class="detail-label">Address:</span><span class="detail-value"><?= !empty($teacher['address'])?htmlspecialchars($teacher['address']):'Not provided' ?></span></div>
                <div class="detail-row"><span class="detail-label">Assigned Barangay:</span><span class="detail-value"><?= $teacher['barangay_name']?htmlspecialchars($teacher['barangay_name']):'Not assigned' ?></span></div>
            </div>

            <div class="detail-section">
                <h3><i class="fas fa-briefcase"></i> Professional Information</h3>
                <div class="detail-row"><span class="detail-label">Qualification:</span><span class="detail-value"><?= htmlspecialchars($teacher['qualification']) ?></span></div>
                <div class="detail-row"><span class="detail-label">Date Joined:</span><span class="detail-value"><?= date('F j, Y', strtotime($teacher['date_joined'])) ?></span></div>
                <div class="detail-row"><span class="detail-label">Active Assignments:</span><span class="detail-value"><?= $teacher['active_assignments'] ?? '0' ?></span></div>
            </div>

            <?php if ($teacher['username']): ?>
            <div class="detail-section">
                <h3><i class="fas fa-key"></i> Login Credentials</h3>
                <div class="detail-row"><span class="detail-label">Username:</span><span class="detail-value"><?= htmlspecialchars($teacher['username']) ?></span></div>
                <div class="detail-row">
                    <span class="detail-label">Last Login:</span>
                    <span class="detail-value"><?= $teacher['last_login'] ? date('F j, Y g:i a', strtotime($teacher['last_login'])) : 'Never logged in' ?></span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
</body>
</html>