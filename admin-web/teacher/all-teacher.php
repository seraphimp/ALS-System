<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/teacher_functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    redirect('../index.php');
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOKEN SYSTEM  — shared across all teacher pages via $_SESSION['_tt']
// URL format:  /AdminViewTeachers?<40-hex-token>   (no key name)
//              /AdminEditTeachers?<40-hex-token>
//              delete.php?_act=del&_t=<token>
//              activate.php?_act=act&_t=<token>
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Issue (or reuse) a token for a teacher_id.
 * Stored in $_SESSION['_tt'] as [token => teacher_id_string].
 */
function issue_teacher_token(int $teacher_id): string {
    if (!isset($_SESSION['_tt']) || !is_array($_SESSION['_tt'])) {
        $_SESSION['_tt'] = [];
    }
    $sid = (string)$teacher_id;
    $existing = array_search($sid, $_SESSION['_tt'], true);
    if ($existing !== false) return $existing;

    $token = bin2hex(random_bytes(20)); // 40-char hex
    $_SESSION['_tt'][$token] = $sid;

    if (count($_SESSION['_tt']) > 500) {
        $_SESSION['_tt'] = array_slice($_SESSION['_tt'], -500, null, true);
    }
    return $token;
}

// Get filter parameters
$status      = $_GET['status']      ?? 'active';
$search      = $_GET['search']      ?? '';
$barangay_id = isset($_GET['barangay_id']) ? (int)$_GET['barangay_id'] : null;

// Get all teachers and barangays
$teachers  = get_all_teachers($conn, $status, $search, $barangay_id);
$barangays = get_active_barangays($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teacher Management | ALS System</title>
    <link rel="icon" type="image/png" href="/logo">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4361ee;
            --primary-light: #e6ecfe;
            --secondary: #3f37c9;
            --success: #4cc9f0;
            --danger: #f72585;
            --warning: #f8961e;
            --info: #4895ef;
            --dark: #212529;
            --light: #f8f9fa;
            --gray: #6c757d;
            --gray-light: #e9ecef;
            --white: #ffffff;
            --transition: all 0.3s ease;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.05);
            --shadow-md: 0 4px 6px rgba(0,0,0,.08);
            --border-radius: 12px;
            --border-radius-sm: 6px;
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Poppins',sans-serif;background-color:#f5f7fb;color:var(--dark);line-height:1.6}
        .container{max-width:1400px;margin:0 auto;padding:2rem}
        .page-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid rgba(0,0,0,.05)}
        .page-header h1{font-size:1.8rem;font-weight:600;color:var(--dark)}
        .btn{display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.2rem;border-radius:var(--border-radius-sm);font-size:.9rem;font-weight:500;cursor:pointer;transition:var(--transition);text-decoration:none;border:none}
        .btn-primary{background-color:var(--primary);color:white;box-shadow:0 2px 5px rgba(67,97,238,.3)}
        .btn-primary:hover{background-color:var(--secondary);transform:translateY(-2px)}
        .btn-outline{background:transparent;border:1px solid var(--gray-light);color:var(--gray)}
        .btn-outline:hover{background:var(--gray-light);color:var(--dark)}
        .btn-outline-danger{border:1px solid var(--danger);color:var(--danger);background:transparent}
        .btn-outline-danger:hover{background:rgba(247,37,133,.1)}
        .btn-outline-success{border:1px solid var(--success);color:var(--success);background:transparent}
        .btn-outline-success:hover{background:rgba(76,201,240,.1)}
        .btn-sm{padding:.4rem .8rem;font-size:.8rem}
        .filter-card{background:var(--white);border-radius:var(--border-radius);padding:1.5rem;margin-bottom:2rem;box-shadow:var(--shadow-sm);border:1px solid rgba(0,0,0,.03)}
        .filter-form .form-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1.5rem;align-items:flex-end}
        .form-group{margin-bottom:0}
        .form-group label{display:block;margin-bottom:.5rem;font-size:.85rem;color:var(--gray);font-weight:500}
        .form-control{width:100%;padding:.7rem 1rem;border:1px solid var(--gray-light);border-radius:var(--border-radius-sm);font-size:.9rem;transition:var(--transition);background-color:var(--white)}
        .form-control:focus{border-color:var(--primary);outline:none;box-shadow:0 0 0 3px rgba(67,97,238,.1)}
        .table-responsive{overflow-x:auto;border-radius:var(--border-radius);box-shadow:var(--shadow-sm);background:var(--white);border:1px solid rgba(0,0,0,.03)}
        table{width:100%;border-collapse:collapse;min-width:800px}
        th{background-color:#f8f9fa;padding:1rem;text-align:left;font-weight:600;font-size:.85rem;color:var(--gray);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--gray-light)}
        td{padding:1rem;border-bottom:1px solid var(--gray-light);font-size:.95rem;vertical-align:middle}
        tr:last-child td{border-bottom:none}
        tr:hover td{background-color:var(--primary-light)}
        .badge{display:inline-block;padding:.35rem .65rem;border-radius:50px;font-size:.75rem;font-weight:600;text-transform:capitalize}
        .badge-success{background-color:rgba(76,201,240,.15);color:var(--success);border:1px solid rgba(76,201,240,.3)}
        .badge-secondary{background-color:rgba(108,117,125,.15);color:var(--gray);border:1px solid rgba(108,117,125,.3)}
        .badge-danger{background-color:rgba(247,37,133,.15);color:var(--danger);border:1px solid rgba(247,37,133,.3)}
        .badge i{font-size:.6rem;margin-right:.3rem}
        .text-center{text-align:center}
        .alert{padding:1rem;margin-bottom:1.5rem;border-radius:var(--border-radius-sm);font-size:.9rem}
        .alert-success{background-color:rgba(76,201,240,.1);color:var(--success);border-left:4px solid var(--success)}
        .alert-danger{background-color:rgba(247,37,133,.1);color:var(--danger);border-left:4px solid var(--danger)}
        .breadcrumb{display:flex;align-items:center;margin-bottom:1.5rem;font-size:.9rem;color:var(--gray)}
        .breadcrumb a{color:var(--primary);text-decoration:none;transition:var(--transition)}
        .breadcrumb a:hover{text-decoration:underline}
        .breadcrumb .separator{margin:0 .5rem;color:var(--gray-light)}
        @media(max-width:768px){.page-header{flex-direction:column;align-items:flex-start;gap:1rem}.filter-form .form-row{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="container">
    <div class="breadcrumb">
        <a href="/AdminDashboard"><i class="fas fa-home"></i> Dashboard</a>
        <span class="separator">/</span>
        <span>Teacher Management</span>
    </div>

    <div class="page-header">
        <div>
            <h1><i class="fas fa-chalkboard-teacher"></i> Teacher Management</h1>
        </div>
        <div class="header-actions">
            <a href="/AdminDashboard" class="btn btn-outline" style="margin-right:1rem">
                <i class="fas fa-arrow-left"></i> Dashboard
            </a>
            <a href="/AdminAddteachers" class="btn btn-primary">
                <i class="fas fa-plus"></i> Add New Teacher
            </a>
        </div>
    </div>

    <?php if (file_exists('../includes/flash_messages.php')) include '../includes/flash_messages.php'; ?>

    <!-- Filter Form -->
    <div class="filter-card">
        <form method="get" class="filter-form">
            <div class="form-row">
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="all"          <?= $status==='all'          ?'selected':'' ?>>All Teachers</option>
                        <option value="active"       <?= $status==='active'       ?'selected':'' ?>>Active Only</option>
                        <option value="active_login" <?= $status==='active_login' ?'selected':'' ?>>Online Now</option>
                        <option value="inactive"     <?= $status==='inactive'     ?'selected':'' ?>>Inactive Only</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="barangay_id">Barangay</label>
                    <select id="barangay_id" name="barangay_id" class="form-control">
                        <option value="">All Barangays</option>
                        <?php foreach ($barangays as $b): ?>
                            <option value="<?= $b['barangay_id'] ?>" <?= $barangay_id===$b['barangay_id']?'selected':'' ?>>
                                <?= htmlspecialchars($b['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" class="form-control"
                           placeholder="Search teachers..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply Filters</button>
                    <a href="index.php" class="btn btn-outline"><i class="fas fa-sync-alt"></i> Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Teachers Table -->
    <div class="table-responsive">
        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Teacher</th>
                    <th>Contact</th>
                    <th>Specialization</th>
                    <th>Barangay</th>
                    <th>Status</th>
                    <th>Login Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($teachers)): ?>
                <tr><td colspan="8" class="text-center">No teachers found matching your criteria</td></tr>
            <?php else: ?>
                <?php foreach ($teachers as $index => $teacher):
                    // Issue one token per teacher — used for all action links in this row
                    $tok = issue_teacher_token((int)$teacher['teacher_id']);
                ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td>
                        <div style="font-weight:500"><?= htmlspecialchars($teacher['full_name']) ?></div>
                        <div style="font-size:.85rem;color:var(--gray)"><?= htmlspecialchars($teacher['email']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($teacher['phone']) ?></td>
                    <td><?= htmlspecialchars($teacher['specialization']) ?></td>
                    <td><?= $teacher['barangay_name'] ? htmlspecialchars($teacher['barangay_name']) : 'N/A' ?></td>
                    <td>
                        <span class="badge <?= $teacher['status']==='active'?'badge-success':'badge-secondary' ?>">
                            <?= ucfirst($teacher['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($teacher['is_logged_in'] == 1): ?>
                            <span class="badge badge-success" title="Last login: <?= $teacher['last_login'] ?>">
                                <i class="fas fa-circle"></i> Online
                            </span>
                        <?php elseif ($teacher['status'] === 'active'): ?>
                            <span class="badge badge-secondary"><i class="fas fa-circle"></i> Offline</span>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-circle"></i> Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:.5rem">
                            <!-- View: /AdminViewTeachers?<token> — no key name -->
                            <a href="/AdminViewTeachers?<?= $tok ?>" class="btn btn-sm btn-outline" title="View">
                                <i class="fas fa-eye"></i>
                            </a>
                            <!-- Edit: /AdminEditTeachers?<token> — no key name -->
                            <a href="/AdminEditTeachers?<?= $tok ?>" class="btn btn-sm btn-outline" title="Edit">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php if ($teacher['status'] === 'active'): ?>
                                <a href="delete.php?_act=del&_t=<?= $tok ?>"
                                   class="btn btn-sm btn-outline-danger" title="Deactivate"
                                   onclick="return confirm('Deactivate this teacher?')">
                                    <i class="fas fa-user-slash"></i>
                                </a>
                            <?php else: ?>
                                <a href="activate.php?_act=act&_t=<?= $tok ?>"
                                   class="btn btn-sm btn-outline-success" title="Activate"
                                   onclick="return confirm('Activate this teacher?')">
                                    <i class="fas fa-user-check"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>