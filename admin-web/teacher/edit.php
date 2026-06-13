<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/teacher_functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: /admin-secure'); exit();
}

// ── Token helpers ──────────────────────────────────────────────────────────────

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

// ── Resolve token ──────────────────────────────────────────────────────────────
$raw = trim($_SERVER['QUERY_STRING'] ?? '');
if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));
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

// ── Helper: redirect back to this page with a fresh token ─────────────────────
function redirect_back(int $teacher_id): never {
    $tok = issue_teacher_token($teacher_id);
    header("Location: /AdminEditTeachers?" . $tok);
    exit();
}

// ── Load data ──────────────────────────────────────────────────────────────────
$teacher       = get_teacher($conn, $teacher_id);
$barangays     = get_active_barangays($conn);
$school_levels = get_school_levels();

if (!$teacher) {
    $_SESSION['error'] = "Teacher not found.";
    header('Location: /AdminAllTeachers'); exit();
}

$handled_levels = $teacher['handled_levels'] ? explode(',', $teacher['handled_levels']) : [];

// ── Handle form submission ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [
        'full_name'      => clean_input($_POST['full_name']      ?? ''),
        'email'          => clean_input($_POST['email']          ?? ''),
        'phone'          => clean_input($_POST['phone']          ?? ''),
        'specialization' => clean_input($_POST['specialization'] ?? ''),
        'qualification'  => clean_input($_POST['qualification']  ?? ''),
        'address'        => clean_input($_POST['address']        ?? ''),
        'barangay_id'    => isset($_POST['barangay_id']) ? (int)$_POST['barangay_id'] : null,
        'date_joined'    => clean_input($_POST['date_joined']    ?? ''),
        'status'         => clean_input($_POST['status']         ?? 'active'),
        'username'       => clean_input($_POST['username']       ?? ''),
        'password'       => $_POST['password'] ?? '',
        'handled_levels' => isset($_POST['handled_levels']) ? $_POST['handled_levels'] : [],
    ];

    $errors = [];

    if (empty($formData['full_name']))
        $errors[] = "Full name is required";
    elseif (strlen($formData['full_name']) > 100)
        $errors[] = "Full name must be less than 100 characters";

    if (empty($formData['email']))
        $errors[] = "Email is required";
    elseif (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = "Invalid email format";
    else {
        $st = $conn->prepare("SELECT teacher_id FROM teachers WHERE email = ? AND teacher_id != ?");
        $st->bind_param("si", $formData['email'], $teacher_id);
        $st->execute(); $st->store_result();
        if ($st->num_rows > 0) $errors[] = "Email already exists for another teacher";
        $st->close();
    }

    if (empty($formData['phone']))
        $errors[] = "Phone number is required";
    elseif (!preg_match('/^[0-9]{10,15}$/', $formData['phone']))
        $errors[] = "Invalid phone number format";

    if (empty($formData['specialization']))
        $errors[] = "Specialization is required";
    elseif (strlen($formData['specialization']) > 100)
        $errors[] = "Specialization must be less than 100 characters";

    if (empty($formData['handled_levels']))
        $errors[] = "At least one school level must be selected";

    if (!empty($formData['username'])) {
        $st = $conn->prepare("SELECT id FROM teacher_credentials WHERE username = ? AND teacher_id != ?");
        $st->bind_param("si", $formData['username'], $teacher_id);
        $st->execute(); $st->store_result();
        if ($st->num_rows > 0) $errors[] = "Username already exists for another user";
        $st->close();
    }

    if (!empty($formData['password']) && strlen($formData['password']) < 8)
        $errors[] = "Password must be at least 8 characters";

    if (empty($errors)) {
        try {
            $levels_str = implode(',', $formData['handled_levels']);

            $st = $conn->prepare("UPDATE teachers SET
                full_name=?, email=?, phone=?, specialization=?, qualification=?,
                address=?, barangay_id=?, date_joined=?, status=?, handled_levels=?
                WHERE teacher_id=?");
            $st->bind_param("ssssssisssi",
                $formData['full_name'], $formData['email'], $formData['phone'],
                $formData['specialization'], $formData['qualification'], $formData['address'],
                $formData['barangay_id'], $formData['date_joined'], $formData['status'],
                $levels_str, $teacher_id
            );

            if (!$st->execute()) throw new Exception("Failed to update teacher: " . $st->error);
            $st->close();

            // Update credentials if username provided
            if (!empty($formData['username'])) {
                $chk = $conn->prepare("SELECT id FROM teacher_credentials WHERE teacher_id = ?");
                $chk->bind_param("i", $teacher_id); $chk->execute();
                $exists = $chk->get_result()->num_rows > 0;
                $chk->close();

                if ($exists) {
                    if (!empty($formData['password'])) {
                        $hp = password_hash($formData['password'], PASSWORD_DEFAULT);
                        $us = $conn->prepare("UPDATE teacher_credentials SET username=?, password=? WHERE teacher_id=?");
                        $us->bind_param("ssi", $formData['username'], $hp, $teacher_id);
                    } else {
                        $us = $conn->prepare("UPDATE teacher_credentials SET username=? WHERE teacher_id=?");
                        $us->bind_param("si", $formData['username'], $teacher_id);
                    }
                } else {
                    if (empty($formData['password']))
                        throw new Exception("Password is required when creating new login credentials");
                    $hp = password_hash($formData['password'], PASSWORD_DEFAULT);
                    $us = $conn->prepare("INSERT INTO teacher_credentials (username, password, teacher_id, role) VALUES (?,?,?,'teacher')");
                    $us->bind_param("ssi", $formData['username'], $hp, $teacher_id);
                }

                if (!$us->execute()) throw new Exception("Failed to update login credentials");
                $us->close();
            }

            $_SESSION['success'] = "Teacher updated successfully!";

            // Redirect to view page — token URL, no id=
            $view_tok = issue_teacher_token($teacher_id);
            header("Location: /AdminViewTeachers?" . $view_tok); exit();

        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['error']     = implode("<br>", $errors);
        $_SESSION['form_data'] = $formData;
        redirect_back($teacher_id);
    }
}

// Repopulate on failed submission
if (isset($_SESSION['form_data'])) {
    $teacher = array_merge($teacher, $_SESSION['form_data']);
    if (isset($teacher['handled_levels']) && is_array($teacher['handled_levels'])) {
        $handled_levels = $teacher['handled_levels'];
    }
    unset($_SESSION['form_data']);
}

// Token for form action
$page_tok  = issue_teacher_token($teacher_id);
$view_tok  = issue_teacher_token($teacher_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Teacher - ALS System</title>
    <link rel="icon" type="image/png" href="/logo">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root{--primary:#4361ee;--primary-dark:#3a56d4;--secondary:#7209b7;--success:#4cc9f0;--danger:#e63946;--dark:#1d3557;--gray:#6c757d;--gray-light:#adb5bd;--white:#ffffff;--border-radius:12px;--border-radius-sm:8px;--box-shadow:0 4px 20px rgba(0,0,0,.08);--box-shadow-lg:0 8px 30px rgba(0,0,0,.12);--transition:all .3s cubic-bezier(.4,0,.2,1);--gradient-primary:linear-gradient(135deg,var(--primary) 0%,var(--secondary) 100%);--senior-high:#dc3545;--junior-high:#0d6efd;--elementary:#198754}
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif}
        body{background-color:#f8fafc;color:#334155;min-height:100vh;line-height:1.6}
        .container{max-width:1400px;margin:0 auto;padding:20px}
        .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:30px;padding:20px;background:white;border-radius:var(--border-radius);box-shadow:var(--box-shadow)}
        .page-title h1{font-size:28px;font-weight:700;color:var(--dark);display:flex;align-items:center;gap:12px;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .teacher-info{display:flex;align-items:center;gap:16px;margin-top:8px}
        .teacher-badge{padding:6px 12px;border-radius:20px;font-size:13px;font-weight:600;background:rgba(67,97,238,.1);color:var(--primary)}
        .btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;border-radius:var(--border-radius-sm);font-weight:600;text-decoration:none;transition:var(--transition);cursor:pointer;border:none;font-size:14px}
        .btn-outline{background:transparent;color:var(--primary);border:2px solid var(--primary)}.btn-outline:hover{background:var(--primary);color:white;transform:translateY(-2px)}
        .btn-primary{background:var(--gradient-primary);color:white;box-shadow:0 4px 15px rgba(67,97,238,.3)}.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 25px rgba(67,97,238,.4)}
        .form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(350px,1fr));gap:24px;margin-top:20px}
        .form-section{background:white;border-radius:var(--border-radius);padding:28px;box-shadow:var(--box-shadow);border:1px solid rgba(226,232,240,.8);transition:var(--transition)}
        .form-section:hover{box-shadow:var(--box-shadow-lg)}
        .section-header{display:flex;align-items:center;gap:12px;margin-bottom:24px;padding-bottom:16px;border-bottom:1px solid #f1f5f9}
        .section-header h3{font-size:20px;font-weight:700;color:var(--dark)}
        .section-header i{color:var(--primary);font-size:20px}
        .form-group{margin-bottom:20px}
        .form-group label{display:block;margin-bottom:8px;font-weight:600;color:var(--dark);font-size:14px}
        .form-control{width:100%;padding:14px 16px;border:2px solid #e2e8f0;border-radius:var(--border-radius-sm);font-size:15px;transition:var(--transition);background:white}
        .form-control:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 4px rgba(67,97,238,.1)}
        textarea.form-control{min-height:100px;resize:vertical}
        select.form-control{cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236c757d' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 16px center;background-size:16px;padding-right:40px}
        .required-field::after{content:" *";color:var(--danger)}
        .form-actions{display:flex;gap:12px;margin-top:24px;padding-top:20px;border-top:1px solid #f1f5f9}
        .alert{padding:16px 20px;border-radius:var(--border-radius-sm);margin-bottom:24px;display:flex;align-items:center;gap:12px;animation:slideIn .3s ease-out}
        .alert-success{background:rgba(76,201,240,.1);color:var(--success);border-left:4px solid var(--success)}
        .alert-error{background:rgba(230,57,70,.1);color:var(--danger);border-left:4px solid var(--danger)}
        .alert i{font-size:18px}
        @keyframes slideIn{from{opacity:0;transform:translateX(-20px)}to{opacity:1;transform:translateX(0)}}
        .levels-container{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-top:16px}
        .level-checkbox{position:relative}
        .level-checkbox input[type="checkbox"]{position:absolute;opacity:0;width:0;height:0}
        .level-label{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px 16px;border:2px solid #e2e8f0;border-radius:var(--border-radius);cursor:pointer;transition:var(--transition);text-align:center;background:white;height:100%}
        .level-label:hover{transform:translateY(-4px);box-shadow:var(--box-shadow)}
        .level-checkbox input:checked+.level-label{border-color:var(--primary);background:rgba(67,97,238,.05);box-shadow:0 8px 25px rgba(67,97,238,.15)}
        .level-icon{font-size:32px;margin-bottom:12px;width:64px;height:64px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:rgba(67,97,238,.1);color:var(--primary)}
        .level-name{font-weight:700;font-size:16px;margin-bottom:4px;color:var(--dark)}
        .level-description{font-size:13px;color:var(--gray);line-height:1.4}
        .level-checkbox.senior-high .level-icon{background:rgba(220,53,69,.1);color:var(--senior-high)}
        .level-checkbox.junior-high .level-icon{background:rgba(13,110,253,.1);color:var(--junior-high)}
        .level-checkbox.elementary .level-icon{background:rgba(25,135,84,.1);color:var(--elementary)}
        .level-checkbox.senior-high input:checked+.level-label{border-color:var(--senior-high);background:rgba(220,53,69,.05)}
        .level-checkbox.junior-high input:checked+.level-label{border-color:var(--junior-high);background:rgba(13,110,253,.05)}
        .level-checkbox.elementary input:checked+.level-label{border-color:var(--elementary);background:rgba(25,135,84,.05)}
        .select-all-btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;background:#f8fafc;border:2px solid #e2e8f0;border-radius:var(--border-radius-sm);cursor:pointer;font-weight:600;color:var(--gray);transition:var(--transition);margin-bottom:16px;font-size:14px}
        .select-all-btn:hover{background:#e2e8f0;border-color:var(--primary);color:var(--primary)}
        .info-text{color:var(--gray);font-size:14px;line-height:1.6;margin:8px 0}
        @media(max-width:768px){.page-header{flex-direction:column;align-items:flex-start;gap:16px;padding:16px}.form-section{padding:20px}.levels-container{grid-template-columns:1fr}.form-actions{flex-direction:column}.btn{width:100%;justify-content:center}}
    </style>
</head>
<body>
<div class="container">
    <div class="page-header">
        <div class="page-title">
            <h1><i class="fas fa-user-edit"></i> Edit Teacher: <?= htmlspecialchars($teacher['full_name']) ?></h1>
            <div class="teacher-info">
                <span class="teacher-badge">Status: <?= ucfirst($teacher['status']) ?></span>
            </div>
        </div>
        <!-- View Profile button — token URL, no id= -->
        <a href="/AdminViewTeachers?<?= $view_tok ?>" class="btn btn-outline">
            <i class="fas fa-eye"></i> View Profile
        </a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i><div><strong>Success!</strong> <?= $_SESSION['success'] ?></div></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i><div><strong>Error!</strong> <?= $_SESSION['error'] ?></div></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Form action points to the tokenised URL — no id= in the URL -->
    <form method="post" action="/AdminEditTeachers?<?= $page_tok ?>">
        <div class="form-grid">

            <!-- Personal Information -->
            <div class="form-section">
                <div class="section-header"><i class="fas fa-user-circle"></i><h3>Personal Information</h3></div>
                <div class="form-group"><label for="full_name" class="required-field">Full Name</label><input type="text" id="full_name" name="full_name" class="form-control" required value="<?= htmlspecialchars($teacher['full_name']) ?>" placeholder="Enter teacher's full name"></div>
                <div class="form-group"><label for="email" class="required-field">Email Address</label><input type="email" id="email" name="email" class="form-control" required value="<?= htmlspecialchars($teacher['email']) ?>" placeholder="teacher@example.com"></div>
                <div class="form-group"><label for="phone" class="required-field">Phone Number</label><input type="tel" id="phone" name="phone" class="form-control" required value="<?= htmlspecialchars($teacher['phone']) ?>" placeholder="09123456789"></div>
                <div class="form-group"><label for="date_joined" class="required-field">Date Joined</label><input type="date" id="date_joined" name="date_joined" class="form-control" required value="<?= htmlspecialchars($teacher['date_joined']) ?>"></div>
                <div class="form-group"><label for="status" class="required-field">Status</label>
                    <select id="status" name="status" class="form-control" required>
                        <option value="active"   <?= $teacher['status']==='active'  ?'selected':'' ?>>Active</option>
                        <option value="inactive" <?= $teacher['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                </div>
            </div>

            <!-- Professional Information -->
            <div class="form-section">
                <div class="section-header"><i class="fas fa-briefcase"></i><h3>Professional Information</h3></div>
                <div class="form-group"><label for="specialization" class="required-field">Specialization</label><input type="text" id="specialization" name="specialization" class="form-control" required value="<?= htmlspecialchars($teacher['specialization']) ?>" placeholder="e.g., Mathematics, English"></div>
                <div class="form-group"><label for="qualification" class="required-field">Qualification</label><input type="text" id="qualification" name="qualification" class="form-control" required value="<?= htmlspecialchars($teacher['qualification']) ?>" placeholder="e.g., Bachelor's Degree"></div>
                <div class="form-group"><label for="barangay_id">Assigned Barangay</label>
                    <select id="barangay_id" name="barangay_id" class="form-control">
                        <option value="">-- Select Barangay --</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= $b['barangay_id'] ?>" <?= $teacher['barangay_id']==$b['barangay_id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label for="address">Address</label><textarea id="address" name="address" class="form-control" placeholder="Enter complete address"><?= htmlspecialchars($teacher['address']) ?></textarea></div>
            </div>

            <!-- School Levels & Credentials -->
            <div class="form-section">
                <div class="section-header"><i class="fas fa-graduation-cap"></i><h3>School Level Assignment</h3></div>
                <p class="info-text">Select the school levels this teacher will handle.</p>
                <div class="form-group">
                    <label class="required-field">Handled School Levels</label>
                    <button type="button" class="select-all-btn" onclick="toggleAllLevels()">
                        <i class="fas fa-check-square"></i> Select All Levels
                    </button>
                    <div class="levels-container">
                        <?php foreach ($school_levels as $level_key => $level_info): ?>
                        <div class="level-checkbox <?= $level_key ?>">
                            <input type="checkbox" name="handled_levels[]" value="<?= $level_key ?>"
                                   id="level_<?= $level_key ?>"
                                   <?= in_array($level_key, $handled_levels)?'checked':'' ?>>
                            <label class="level-label" for="level_<?= $level_key ?>">
                                <div class="level-icon"><i class="<?= $level_info['icon'] ?>"></i></div>
                                <div class="level-name"><?= $level_info['name'] ?></div>
                                <div class="level-description"><?= $level_info['description'] ?></div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-top:32px">
                    <div class="section-header"><i class="fas fa-lock"></i><h3>Login Credentials</h3></div>
                    <p class="info-text">Leave password blank to keep current password.</p>
                    <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" class="form-control" value="<?= htmlspecialchars($teacher['username'] ?? '') ?>" placeholder="Leave blank if no access needed"></div>
                    <div class="form-group"><label for="password">Password</label><input type="password" id="password" name="password" class="form-control" placeholder="Leave blank to keep current password">
                        <div class="info-text" style="margin-top:4px">Minimum 8 characters. Only required if creating new account.</div>
                    </div>
                </div>

                <div class="form-actions">
                    <!-- Cancel goes back to view — token URL -->
                    <a href="/AdminViewTeachers?<?= $view_tok ?>" class="btn btn-outline"><i class="fas fa-times"></i> Cancel</a>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Teacher</button>
                </div>
            </div>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<script>
function toggleAllLevels(){
    const cbs=document.querySelectorAll('input[name="handled_levels[]"]');
    const allChecked=Array.from(cbs).every(c=>c.checked);
    const btn=document.querySelector('.select-all-btn');
    cbs.forEach(c=>c.checked=!allChecked);
    btn.innerHTML=allChecked?'<i class="fas fa-check-square"></i> Select All Levels':'<i class="fas fa-minus-square"></i> Deselect All';
}

document.addEventListener('DOMContentLoaded',function(){
    document.querySelectorAll('input[name="handled_levels[]"]').forEach(cb=>{
        const update=()=>{
            const lbl=cb.closest('.level-checkbox').querySelector('.level-label');
            if(cb.checked){lbl.style.transform='translateY(-4px)';lbl.style.boxShadow='0 8px 25px rgba(0,0,0,.15)'}
            else{lbl.style.transform='translateY(0)';lbl.style.boxShadow='none'}
        };
        cb.addEventListener('change',update);
        update();
    });
});

document.querySelector('form').addEventListener('submit',function(e){
    const username=document.getElementById('username').value.trim();
    const password=document.getElementById('password').value;
    const currentUsername="<?= htmlspecialchars($teacher['username'] ?? '') ?>";
    if(document.querySelectorAll('input[name="handled_levels[]"]:checked').length===0){
        e.preventDefault();alert('Please select at least one school level.');return false;
    }
    if(username!==''&&currentUsername===''&&password===''){
        e.preventDefault();alert('Password is required when creating new login credentials.');
        document.getElementById('password').focus();return false;
    }
    if(password!==''&&password.length<8){
        e.preventDefault();alert('Password must be at least 8 characters long.');
        document.getElementById('password').focus();return false;
    }
});
</script>
</body>
</html>