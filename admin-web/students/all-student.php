<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Authentication required.']);
        exit();
    }
    header('Location: /admin-secure');
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOKEN SYSTEM
// ═══════════════════════════════════════════════════════════════════════════════

function issue_token(string $student_id): string {
    if (!isset($_SESSION['_st']) || !is_array($_SESSION['_st'])) $_SESSION['_st'] = [];
    $existing = array_search($student_id, $_SESSION['_st'], true);
    if ($existing !== false) return $existing;
    $token = bin2hex(random_bytes(20));
    $_SESSION['_st'][$token] = $student_id;
    if (count($_SESSION['_st']) > 500) $_SESSION['_st'] = array_slice($_SESSION['_st'], -500, null, true);
    return $token;
}

function resolve_token(string $token): string {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return '';
    return $_SESSION['_st'][$token] ?? '';
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX: AF2 CHECKLIST SAVE
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['_act']) && $_GET['_act'] === 'af2_check' && isset($_GET['_t'])) {
    header('Content-Type: application/json');
    $student_id = resolve_token($_GET['_t']);
    if (empty($student_id)) { echo json_encode(['success' => false, 'error' => 'Invalid token']); exit(); }

    $hasPSA = (isset($_GET['psa']) && $_GET['psa'] === '1') ? 1 : 0;
    $hasLRN = (isset($_GET['lrn_ok']) && $_GET['lrn_ok'] === '1') ? 1 : 0;

    $st = $conn->prepare("UPDATE students SET af2_psa_submitted = ?, af2_lrn_provided = ? WHERE student_id = ?");
    $st->bind_param("iis", $hasPSA, $hasLRN, $student_id);
    $ok = $st->execute();
    $st->close();

    $autoEnrolled = false;
    if ($hasPSA && $hasLRN) {
        $en = $conn->prepare("UPDATE students SET status = 'enrolled' WHERE student_id = ? AND status = 'pending'");
        $en->bind_param("s", $student_id);
        $en->execute();
        $autoEnrolled = $en->affected_rows > 0;
        $en->close();
    }

    echo json_encode(['success' => $ok, 'auto_enrolled' => $autoEnrolled]);
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX: GET AF2 STATUS
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['_act']) && $_GET['_act'] === 'af2_get' && isset($_GET['_t'])) {
    header('Content-Type: application/json');
    $student_id = resolve_token($_GET['_t']);
    if (empty($student_id)) { echo json_encode(['success' => false, 'error' => 'Invalid token']); exit(); }

    $st = $conn->prepare("SELECT af2_psa_submitted, af2_lrn_provided, lrn, birth_certificate_path FROM students WHERE student_id = ?");
    $st->bind_param("s", $student_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();

    echo json_encode([
        'success'           => true,
        'psa'               => (int)($row['af2_psa_submitted'] ?? 0),
        'lrn_ok'            => (int)($row['af2_lrn_provided'] ?? 0),
        'has_lrn'           => !empty($row['lrn']),
        'has_psa_file'      => !empty($row['birth_certificate_path']),
        'lrn_value'         => $row['lrn'] ?? '',
    ]);
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX: LRN BULK CSV UPLOAD
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['bulk_lrn_upload'])) {
    header('Content-Type: application/json');

    if (!isset($_FILES['lrn_file']) || $_FILES['lrn_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error.']);
        exit();
    }

    $file = $_FILES['lrn_file'];
    $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['csv'])) {
        echo json_encode(['success' => false, 'error' => 'Only CSV files are supported. Format: Column A = Student ID, Column B = LRN']);
        exit();
    }

    $rows = [];
    $handle = fopen($file['tmp_name'], 'r');
    $firstRow = true;
    while (($row = fgetcsv($handle)) !== false) {
        if ($firstRow) { $firstRow = false; continue; }
        $sid = trim($row[0] ?? '');
        $lrn = trim($row[1] ?? '');
        if (!empty($sid) && !empty($lrn)) $rows[] = ['student_id' => $sid, 'lrn' => $lrn];
    }
    fclose($handle);

    if (empty($rows)) {
        echo json_encode(['success' => false, 'error' => 'No valid data found. Ensure: Row 1 = header, Column A = Student ID, Column B = LRN']);
        exit();
    }

    $updated = 0; $notFound = 0;
    foreach ($rows as $item) {
        $st = $conn->prepare("UPDATE students SET lrn = ?, af2_lrn_provided = 1, status = CASE WHEN status = 'pending' AND af2_psa_submitted = 1 THEN 'enrolled' ELSE status END WHERE student_id = ?");
        $st->bind_param("ss", $item['lrn'], $item['student_id']);
        $st->execute();
        if ($st->affected_rows > 0) $updated++;
        else $notFound++;
        $st->close();
    }

    echo json_encode([
        'success'   => true,
        'updated'   => $updated,
        'not_found' => $notFound,
        'message'   => "$updated student(s) updated with LRN. $notFound row(s) not matched."
    ]);
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// STATUS UPDATE
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['_act']) && $_GET['_act'] === 'status' && isset($_GET['_t'], $_GET['_s'])) {
    $student_id = resolve_token($_GET['_t']);
    $new_status = $_GET['_s'];
    if (empty($student_id)) { $_SESSION['error'] = "Invalid or expired request."; header("Location: /AllStudents"); exit(); }
    if (in_array($new_status, ['pending', 'enrolled', 'active', 'inactive', 'completed'])) {
        $st = $conn->prepare("UPDATE students SET status = ? WHERE student_id = ?");
        $st->bind_param("ss", $new_status, $student_id);
        $st->execute();
        $_SESSION['success'] = "Status updated to " . ucfirst($new_status) . " successfully!";
        $st->close();
    } else {
        $_SESSION['error'] = "Invalid status value.";
    }
    header("Location: /AllStudents"); exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// SINGLE DELETE
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['_act']) && $_GET['_act'] === 'del' && isset($_GET['_t'])) {
    $student_id = resolve_token($_GET['_t']);
    if (empty($student_id)) { $_SESSION['error'] = "Invalid or expired request."; header("Location: /AllStudents"); exit(); }
    $check = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $check->bind_param("s", $student_id);
    $check->execute();
    $row = $check->get_result()->fetch_assoc();
    $check->close();
    if ($row) {
        if (!empty($row['qr_code'])) {
            $f = basename($row['qr_code']);
            foreach ([__DIR__.'/../qrcodes/'.$f, __DIR__.'/../../qrcodes/'.$f] as $p) { if (file_exists($p)) { unlink($p); break; } }
        }
        $del = $conn->prepare("DELETE FROM students WHERE student_id = ?");
        $del->bind_param("s", $student_id);
        $del->execute();
        $_SESSION['success'] = "Student deleted successfully!";
        $del->close();
    } else {
        $_SESSION['error'] = "Student not found.";
    }
    header("Location: /AllStudents"); exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// BULK DELETE
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_POST['bulk_delete'], $_POST['selected_students'])) {
    $tokens = $_POST['selected_students'];
    if (is_array($tokens) && !empty($tokens)) {
        $ids = [];
        foreach ($tokens as $tok) { $real = resolve_token(trim($tok)); if (!empty($real)) $ids[] = $conn->real_escape_string($real); }
        if (!empty($ids)) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $types = str_repeat('s', count($ids));
            $qr = $conn->prepare("SELECT qr_code FROM students WHERE student_id IN ($ph)");
            $qr->bind_param($types, ...$ids); $qr->execute();
            $qrRes = $qr->get_result();
            while ($r = $qrRes->fetch_assoc()) {
                if (!empty($r['qr_code'])) { $f = basename($r['qr_code']); foreach ([__DIR__.'/../qrcodes/'.$f, __DIR__.'/../../qrcodes/'.$f] as $p) { if (file_exists($p)) { unlink($p); break; } } }
            }
            $qr->close();
            $del = $conn->prepare("DELETE FROM students WHERE student_id IN ($ph)");
            $del->bind_param($types, ...$ids); $del->execute();
            $_SESSION['success'] = "Deleted " . $del->affected_rows . " student(s) successfully!";
            $del->close();
        }
    }
    header("Location: /AllStudents"); exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// AJAX: QR GENERATION
// ═══════════════════════════════════════════════════════════════════════════════

if (isset($_GET['_act']) && $_GET['_act'] === 'gen_qr' && isset($_GET['_t'])) {
    header('Content-Type: application/json');
    $student_id = resolve_token($_GET['_t']);
    if (empty($student_id)) { echo json_encode(['success' => false, 'error' => 'Invalid token.']); exit(); }

    $stmt = $conn->prepare("SELECT * FROM students WHERE student_id = ?");
    $stmt->bind_param("s", $student_id); $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$student) { echo json_encode(['success' => false, 'error' => 'Student not found.']); exit(); }

    if (!empty($student['qr_code'])) {
        $ep = __DIR__ . '/../' . $student['qr_code'];
        if (file_exists($ep)) { echo json_encode(['success' => true, 'qr_url' => getQRCodeUrl($student['qr_code'])]); exit(); }
    }

    $qrDir = __DIR__ . '/../qrcodes/';
    if (!file_exists($qrDir)) mkdir($qrDir, 0777, true);
    $qrFilename = 'qr_' . $student_id . '_' . time() . '.png';
    $qrPath     = $qrDir . $qrFilename;
    $qrDbPath   = 'qrcodes/' . $qrFilename;
    $qrData     = json_encode(['student_id' => $student_id, 'lrn' => $student['lrn'] ?? '', 'name' => $student['first_name'] . ' ' . $student['last_name']]);
    $qrGenerated = false;

    if (class_exists('QRcode')) { QRcode::png($qrData, $qrPath, QR_ECLEVEL_L, 10); $qrGenerated = file_exists($qrPath) && filesize($qrPath) > 100; }
    if (!$qrGenerated && function_exists('imagecreate')) { $qrGenerated = generateStyledQRPlaceholder($qrData, $qrPath, $student); }

    if ($qrGenerated) {
        $upd = $conn->prepare("UPDATE students SET qr_code = ? WHERE student_id = ?");
        $upd->bind_param("ss", $qrDbPath, $student_id); $upd->execute(); $upd->close();
        echo json_encode(['success' => true, 'qr_url' => getQRCodeUrl($qrDbPath)]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to generate QR code.']);
    }
    exit();
}

function generateStyledQRPlaceholder($data, $outputPath, $student) {
    if (!function_exists('imagecreate')) return false;
    $size = 300; $img = imagecreatetruecolor($size, $size);
    $bg = imagecolorallocate($img,255,255,255); $black = imagecolorallocate($img,0,0,0);
    $blue = imagecolorallocate($img,26,86,219); $gray = imagecolorallocate($img,200,200,200);
    imagefill($img,0,0,$bg);
    $bs = 20;
    for ($r=0;$r<10;$r++) for ($c=0;$c<10;$c++) { $x=$c*$bs+50;$y=$r*$bs+50; if(($r+$c)%3==0||($r%2==0&&$c%2==0)) imagefilledrectangle($img,$x,$y,$x+$bs-2,$y+$bs-2,$black); }
    imagefilledrectangle($img,50,50,110,110,$black); imagefilledrectangle($img,55,55,105,105,$bg); imagefilledrectangle($img,60,60,100,100,$black);
    imagefilledrectangle($img,$size-110,50,$size-50,110,$black); imagefilledrectangle($img,$size-105,55,$size-55,105,$bg); imagefilledrectangle($img,$size-100,60,$size-60,100,$black);
    imagefilledrectangle($img,50,$size-110,110,$size-50,$black); imagefilledrectangle($img,55,$size-105,105,$size-55,$bg); imagefilledrectangle($img,60,$size-100,100,$size-60,$black);
    imagestring($img,5,70,180,"ID: ".substr($student['student_id'],0,12),$blue);
    imagestring($img,4,85,210,substr($student['first_name'].' '.$student['last_name'],0,20),$gray);
    imagepng($img,$outputPath); imagedestroy($img);
    return file_exists($outputPath) && filesize($outputPath) > 500;
}

function getQRCodeUrl($p) {
    if (empty($p)) return '';
    if (strpos($p,'http')===0) return $p;
    $c = ltrim($p,'./');
    if (strpos($c,'als/admin-web/')===0) return 'https://als-system.online/'.$c;
    return 'https://als-system.online/als/admin-web/'.$c;
}

// ═══════════════════════════════════════════════════════════════════════════════
// FETCH DATA
// ═══════════════════════════════════════════════════════════════════════════════

$mainStudents = $conn->query("SELECT s.*, b.name AS barangay_name, t.full_name AS teacher_name FROM students s LEFT JOIN barangays b ON s.current_barangay_id = b.barangay_id LEFT JOIN teachers t ON s.teacher_id = t.teacher_id WHERE s.status IN ('enrolled','active') ORDER BY s.last_name, s.first_name")->fetch_all(MYSQLI_ASSOC);
$pendingStudents = $conn->query("SELECT s.*, b.name AS barangay_name, t.full_name AS teacher_name FROM students s LEFT JOIN barangays b ON s.current_barangay_id = b.barangay_id LEFT JOIN teachers t ON s.teacher_id = t.teacher_id WHERE s.status = 'pending' ORDER BY s.last_name, s.first_name")->fetch_all(MYSQLI_ASSOC);
$oldStudents = $conn->query("SELECT s.*, b.name AS barangay_name, t.full_name AS teacher_name FROM students s LEFT JOIN barangays b ON s.current_barangay_id = b.barangay_id LEFT JOIN teachers t ON s.teacher_id = t.teacher_id WHERE s.status IN ('completed','inactive') ORDER BY s.last_name, s.first_name")->fetch_all(MYSQLI_ASSOC);

$totalStudents  = $conn->query("SELECT COUNT(*) c FROM students")->fetch_assoc()['c'];
$pendingCount   = $conn->query("SELECT COUNT(*) c FROM students WHERE status='pending'")->fetch_assoc()['c'];
$enrolledCount  = $conn->query("SELECT COUNT(*) c FROM students WHERE status='enrolled'")->fetch_assoc()['c'];
$activeCount    = $conn->query("SELECT COUNT(*) c FROM students WHERE status='active'")->fetch_assoc()['c'];
$completedCount = $conn->query("SELECT COUNT(*) c FROM students WHERE status='completed'")->fetch_assoc()['c'];
$inactiveCount  = $conn->query("SELECT COUNT(*) c FROM students WHERE status='inactive'")->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/logo">
    <title>Student Management — ALS System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --ink:#0d1117;--ink-soft:#3d4450;--ink-muted:#7b8494;
            --surface:#fff;--surface-2:#f4f6f9;--surface-3:#eaeef4;--border:#dde2ea;
            --gold:#f5a623;--gold-light:#fff7e6;
            --blue:#1a56db;--blue-light:#e8f0ff;--blue-dim:rgba(26,86,219,.12);
            --green:#0e9f6e;--green-light:#e9f7f2;
            --red:#e02424;--red-light:#fdf2f2;
            --orange:#f97316;--orange-light:#fff3e6;
            --purple:#8b5cf6;--purple-light:#f0eaff;
            --teal:#14b8a6;--teal-light:#e6faf8;
            --radius-sm:8px;--radius:12px;--radius-lg:18px;--radius-xl:24px;
            --shadow-sm:0 1px 3px rgba(0,0,0,.07);--shadow:0 4px 14px rgba(0,0,0,.09);--shadow-lg:0 12px 32px rgba(0,0,0,.12);
            --speed:.22s
        }
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Poppins',sans-serif;background:var(--surface-2);color:var(--ink);min-height:100vh;-webkit-font-smoothing:antialiased}
        .wrap{max-width:1520px;margin:0 auto;padding:0 28px 60px}
        .page-header{padding:36px 0 32px;display:flex;align-items:flex-end;justify-content:space-between;gap:20px;flex-wrap:wrap}
        .breadcrumb{font-size:13px;color:var(--ink-muted);margin-bottom:12px;display:flex;align-items:center;gap:6px}
        .breadcrumb a{color:var(--blue);text-decoration:none}.breadcrumb a:hover{text-decoration:underline}.breadcrumb span{opacity:.5}
        .page-title{font-size:clamp(26px,3vw,38px);font-weight:800;letter-spacing:-1px;color:var(--ink);line-height:1.1}
        .page-title em{font-style:normal;color:var(--gold)}
        .header-actions{display:flex;gap:12px;flex-shrink:0}
        .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:var(--radius-sm);font-family:'Poppins',sans-serif;font-size:14px;font-weight:600;cursor:pointer;border:1.5px solid transparent;text-decoration:none;transition:all var(--speed);white-space:nowrap;line-height:1}
        .btn-primary{background:var(--blue);color:#fff}.btn-primary:hover{background:#1446b8;transform:translateY(-1px);box-shadow:0 6px 18px rgba(26,86,219,.35)}
        .btn-gold{background:var(--gold);color:#fff}.btn-gold:hover{background:#e0941a;transform:translateY(-1px)}
        .btn-green{background:var(--green);color:#fff}.btn-green:hover{background:#0a8560;transform:translateY(-1px)}
        .btn-outline{background:transparent;border-color:var(--border);color:var(--ink-soft)}.btn-outline:hover{border-color:var(--blue);color:var(--blue);background:var(--blue-light)}
        .btn-danger{background:var(--red);color:#fff;border-color:var(--red)}.btn-danger:hover{background:#c11f1f;transform:translateY(-1px)}
        .btn-sm{padding:8px 14px;font-size:13px}
        .btn-xs{padding:5px 10px;font-size:12px}

        /* Stats */
        .stats-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-bottom:32px}
        .stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-lg);padding:22px 24px;display:flex;align-items:center;gap:18px;transition:transform var(--speed),box-shadow var(--speed);position:relative;overflow:hidden}
        .stat-card::after{content:'';position:absolute;bottom:0;left:0;right:0;height:3px;border-radius:0 0 var(--radius-lg) var(--radius-lg);opacity:0;transition:opacity var(--speed)}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow)}.stat-card:hover::after{opacity:1}
        .stat-card.s-total::after{background:var(--blue)}.stat-card.s-pending::after{background:var(--orange)}.stat-card.s-enrolled::after{background:var(--gold)}.stat-card.s-active::after{background:var(--green)}.stat-card.s-completed::after{background:var(--purple)}.stat-card.s-inactive::after{background:var(--red)}
        .stat-icon{width:52px;height:52px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0}
        .stat-card.s-total .stat-icon{background:var(--blue-light);color:var(--blue)}.stat-card.s-pending .stat-icon{background:var(--orange-light);color:var(--orange)}.stat-card.s-enrolled .stat-icon{background:var(--gold-light);color:var(--gold)}.stat-card.s-active .stat-icon{background:var(--green-light);color:var(--green)}.stat-card.s-completed .stat-icon{background:var(--purple-light);color:var(--purple)}.stat-card.s-inactive .stat-icon{background:var(--red-light);color:var(--red)}
        .stat-label{font-size:13px;color:var(--ink-muted);font-weight:500;margin-bottom:4px}.stat-value{font-size:32px;font-weight:800;letter-spacing:-1px;line-height:1}

        /* Panel */
        .panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-xl);overflow:hidden;box-shadow:var(--shadow-sm);margin-bottom:32px}
        .panel-header{padding:20px 28px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:linear-gradient(135deg,var(--surface) 0%,var(--surface-2) 100%)}
        .panel-title{font-size:18px;font-weight:700;display:flex;align-items:center;gap:12px}
        .panel-title .count-badge{background:var(--blue-light);color:var(--blue);padding:2px 10px;border-radius:30px;font-size:13px;margin-left:10px}

        /* Table */
        .table-wrap{overflow-x:auto;max-height:600px;overflow-y:auto}
        table{width:100%;border-collapse:collapse;min-width:1000px}
        thead th{position:sticky;top:0;z-index:3;padding:14px 18px;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--ink-soft);background:var(--surface-2);border-bottom:2px solid var(--border);white-space:nowrap}
        tbody td{padding:0;border-bottom:1px solid var(--border);font-size:14px;vertical-align:middle}
        .td-inner{padding:14px 18px;transition:background var(--speed)}
        tbody tr:hover>td .td-inner{background:#f0f4ff}
        tbody tr:last-child td{border-bottom:none}

        /* Badges */
        .badge{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11.5px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;white-space:nowrap}
        .badge::before{content:'';width:6px;height:6px;border-radius:50%}
        .badge-pending{background:var(--orange-light);color:var(--orange)}.badge-pending::before{background:var(--orange)}
        .badge-enrolled{background:var(--gold-light);color:var(--gold)}.badge-enrolled::before{background:var(--gold)}
        .badge-active{background:var(--green-light);color:var(--green)}.badge-active::before{background:var(--green)}
        .badge-inactive{background:var(--red-light);color:var(--red)}.badge-inactive::before{background:var(--red)}
        .badge-completed{background:var(--purple-light);color:var(--purple)}.badge-completed::before{background:var(--purple)}

        /* Student name cell */
        .student-name strong{font-weight:600;font-size:14.5px}.student-name small{color:var(--ink-muted);font-size:12px;display:block;margin-top:1px}
        .student-avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;margin-right:10px}
        .name-cell{display:flex;align-items:center}
        .av-0{background:#4f46e5}.av-1{background:#0891b2}.av-2{background:#059669}.av-3{background:#d97706}.av-4{background:#dc2626}.av-5{background:#7c3aed}.av-6{background:#0f766e}.av-7{background:#b45309}.av-8{background:#1d4ed8}.av-9{background:#be185d}

        /* QR */
        .qr-thumb{width:48px;height:48px;border-radius:6px;border:2px solid var(--border);cursor:pointer;transition:all var(--speed);object-fit:cover}
        .qr-thumb:hover{border-color:var(--blue);transform:scale(1.08);box-shadow:var(--shadow)}
        .qr-placeholder{display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;background:var(--surface-3);border-radius:6px;border:2px dashed var(--border);color:var(--ink-muted);cursor:pointer;transition:all var(--speed)}
        .qr-placeholder:hover{background:var(--teal-light);border-color:var(--teal);color:var(--teal)}

        /* Actions */
        .actions{display:flex;gap:5px;align-items:center;flex-wrap:wrap}
        .act-btn{width:33px;height:33px;border-radius:7px;display:flex;align-items:center;justify-content:center;text-decoration:none;cursor:pointer;border:none;font-size:14px;transition:all var(--speed);background:transparent}
        .act-btn:hover{transform:translateY(-2px)}
        .act-view{color:var(--green)}.act-view:hover{background:var(--green-light)}
        .act-edit{color:var(--blue)}.act-edit:hover{background:var(--blue-light)}
        .act-del{color:var(--red)}.act-del:hover{background:var(--red-light)}
        .act-complete{color:var(--purple)}.act-complete:hover{background:var(--purple-light)}
        .act-qr{color:var(--teal)}.act-qr:hover{background:var(--teal-light)}

        /* Section tabs */
        .section-tabs{display:flex;gap:4px;background:var(--surface-2);padding:6px;border-radius:50px;margin-bottom:24px;flex-wrap:wrap}
        .section-tab{background:transparent;border:none;padding:10px 28px;border-radius:40px;font-weight:600;font-size:14px;cursor:pointer;transition:all var(--speed);color:var(--ink-soft);font-family:'Poppins',sans-serif}
        .section-tab.active{background:var(--surface);color:var(--blue);box-shadow:var(--shadow-sm)}.section-tab:hover:not(.active){background:var(--surface-3)}
        .section-content{display:none}.section-content.active{display:block;animation:fadeSlide .25s ease}
        @keyframes fadeSlide{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

        /* Filter bar */
        .filter-bar{padding:16px 24px;border-bottom:1px solid var(--border);display:flex;gap:12px;align-items:center;flex-wrap:wrap;background:var(--surface-2)}
        .search-mini{position:relative;flex:1;min-width:200px}
        .search-mini input{padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:40px;width:100%;font-family:'Poppins',sans-serif;font-size:14px;background:var(--surface);transition:border-color .2s}
        .search-mini input:focus{outline:none;border-color:var(--blue)}
        .search-mini i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--ink-muted)}

        /* ── AF2 MODAL ── */
        .af2-modal-backdrop{display:none;position:fixed;inset:0;background:rgba(13,17,23,.6);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
        .af2-modal-backdrop.open{display:flex;animation:fadeIn .2s ease}
        .af2-modal{background:var(--surface);border-radius:var(--radius-xl);width:92%;max-width:520px;box-shadow:var(--shadow-lg);overflow:hidden;animation:modalUp .28s cubic-bezier(.34,1.56,.64,1)}
        .af2-modal-head{padding:22px 28px;background:linear-gradient(135deg,#0d2045,#1a56db);color:#fff;display:flex;align-items:flex-start;justify-content:space-between;gap:16px}
        .af2-modal-head h3{font-size:16px;font-weight:700;letter-spacing:.03em;margin-bottom:4px}
        .af2-modal-head p{font-size:12.5px;opacity:.75}
        .af2-modal-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:30px;height:30px;border-radius:50%;cursor:pointer;font-size:14px;display:flex;align-items:center;justify-content:center;flex-shrink:0;transition:background .2s}
        .af2-modal-close:hover{background:rgba(255,255,255,.3)}
        .af2-modal-body{padding:24px 28px}
        .af2-student-card{display:flex;align-items:center;gap:14px;padding:14px 18px;background:var(--surface-2);border-radius:var(--radius);border:1px solid var(--border);margin-bottom:20px}
        .af2-student-card .av{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;flex-shrink:0}
        .af2-student-card .info strong{font-size:15px;font-weight:700;display:block}
        .af2-student-card .info span{font-size:12px;color:var(--ink-muted)}
        .af2-req-item{display:flex;align-items:flex-start;gap:16px;padding:16px;border:1.5px solid var(--border);border-radius:var(--radius);margin-bottom:12px;transition:border-color .2s,background .2s;cursor:pointer}
        .af2-req-item:hover{border-color:var(--blue);background:var(--blue-light)}
        .af2-req-item.done{border-color:var(--green);background:var(--green-light)}
        .af2-req-item.done:hover{border-color:var(--green)}
        .af2-req-toggle{width:24px;height:24px;border-radius:50%;border:2px solid var(--border);display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;transition:all .2s;font-size:12px;color:transparent;background:var(--surface)}
        .af2-req-item.done .af2-req-toggle{background:var(--green);border-color:var(--green);color:#fff}
        .af2-req-info{flex:1}
        .af2-req-title{font-size:14px;font-weight:700;display:flex;align-items:center;gap:8px;margin-bottom:4px}
        .af2-req-desc{font-size:12.5px;color:var(--ink-muted);line-height:1.5}
        .af2-req-badge{font-size:11px;padding:3px 10px;border-radius:20px;font-weight:700;margin-top:6px;display:inline-block}
        .af2-req-badge.found{background:var(--green-light);color:var(--green)}
        .af2-req-badge.missing{background:var(--red-light);color:var(--red)}
        .af2-req-badge.waiting{background:var(--orange-light);color:var(--orange)}
        .af2-progress-wrap{margin:20px 0 0}
        .af2-progress-top{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}
        .af2-progress-top span{font-size:13px;font-weight:600;color:var(--ink-soft)}
        .af2-prog-bar{height:8px;background:var(--border);border-radius:10px;overflow:hidden}
        .af2-prog-fill{height:100%;background:linear-gradient(90deg,var(--blue),var(--green));border-radius:10px;transition:width .5s cubic-bezier(.4,0,.2,1)}
        .af2-modal-foot{padding:18px 28px;border-top:1px solid var(--border);background:var(--surface-2);display:flex;gap:10px;justify-content:flex-end;align-items:center}
        .af2-enroll-btn{padding:11px 24px;background:var(--green);color:#fff;border:none;border-radius:var(--radius-sm);font-size:14px;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;display:flex;align-items:center;gap:8px}
        .af2-enroll-btn:hover:not(:disabled){background:#0a8560;transform:translateY(-1px);box-shadow:0 4px 14px rgba(14,159,110,.35)}
        .af2-enroll-btn:disabled{background:var(--border);color:var(--ink-muted);cursor:not-allowed;transform:none;box-shadow:none}
        .af2-loading-state{display:flex;align-items:center;justify-content:center;gap:12px;padding:40px;color:var(--ink-muted);font-size:14px}

        /* LRN Upload Panel */
        .lrn-upload-panel{background:var(--surface);border:1.5px solid var(--blue);border-radius:var(--radius-lg);padding:20px 24px;margin-bottom:20px;display:none}
        .lrn-upload-panel.open{display:block;animation:fadeSlide .25s ease}
        .lrn-upload-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
        .lrn-upload-header h3{font-size:15px;font-weight:700;color:var(--blue);display:flex;align-items:center;gap:8px}
        .lrn-drop-zone{border:2px dashed var(--border);border-radius:var(--radius);padding:24px;text-align:center;cursor:pointer;transition:all .2s;background:var(--surface-2)}
        .lrn-drop-zone:hover,.lrn-drop-zone.dragover{border-color:var(--blue);background:var(--blue-light)}
        .lrn-drop-zone i{font-size:28px;color:var(--ink-muted);margin-bottom:8px;display:block}
        .lrn-drop-zone p{font-size:13px;color:var(--ink-muted);margin-bottom:4px}
        .lrn-drop-zone span{font-size:12px;color:var(--blue);font-weight:600;cursor:pointer;text-decoration:underline}
        .lrn-template-note{margin-top:12px;padding:10px 14px;background:var(--gold-light);border-radius:var(--radius-sm);font-size:12px;color:#7a5000;display:flex;align-items:flex-start;gap:8px}
        .lrn-result{margin-top:12px;padding:12px 16px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;display:none}
        .lrn-result.success{background:var(--green-light);color:var(--green);border:1px solid #a7f3d0}
        .lrn-result.error{background:var(--red-light);color:var(--red);border:1px solid #fecaca}

        /* Toast */
        .toast-wrap{position:fixed;top:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px}
        .toast{min-width:300px;max-width:400px;background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-lg);padding:16px 20px;display:flex;align-items:flex-start;gap:14px;border-left:4px solid var(--green);animation:toastIn .35s cubic-bezier(.34,1.56,.64,1) both}
        .toast.error{border-left-color:var(--red)}.toast.warn{border-left-color:var(--orange)}
        .toast-icon{font-size:20px;margin-top:1px}
        .toast.error .toast-icon{color:var(--red)}.toast.warn .toast-icon{color:var(--orange)}.toast:not(.error):not(.warn) .toast-icon{color:var(--green)}
        .toast-body h4{font-weight:700;font-size:14px;margin-bottom:2px}.toast-body p{font-size:13px;color:var(--ink-soft)}
        .toast-close{margin-left:auto;background:none;border:none;cursor:pointer;color:var(--ink-muted);font-size:16px;padding:2px 4px;border-radius:4px}
        @keyframes toastIn{from{opacity:0;transform:translateX(60px)}to{opacity:1;transform:translateX(0)}}

        /* Modals */
        .modal-backdrop{display:none;position:fixed;inset:0;background:rgba(13,17,23,.55);z-index:1000;align-items:center;justify-content:center;backdrop-filter:blur(2px)}
        .modal-backdrop.open{display:flex;animation:fadeIn .2s ease}
        @keyframes fadeIn{from{opacity:0}to{opacity:1}}
        .modal{background:var(--surface);border-radius:var(--radius-xl);padding:32px;max-width:480px;width:92%;box-shadow:var(--shadow-lg);animation:modalUp .25s cubic-bezier(.34,1.56,.64,1)}
        @keyframes modalUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
        .modal-icon{font-size:40px;color:var(--red);margin-bottom:16px;display:block}
        .modal h3{font-size:20px;font-weight:800;margin-bottom:10px}
        .modal p{color:var(--ink-soft);line-height:1.6;font-size:14px}
        .modal-warn{margin-top:14px;padding:12px 16px;background:var(--red-light);border-radius:var(--radius-sm);color:var(--red);font-size:13px;font-weight:500;display:flex;align-items:flex-start;gap:8px}
        .modal-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:24px}
        .qr-modal-body{text-align:center;padding:8px}
        .qr-modal-body img{max-width:240px;max-height:240px;border-radius:var(--radius);border:1px solid var(--border)}
        .qr-modal-body h4{margin:16px 0 4px;font-weight:700}
        .qr-modal-body p{color:var(--ink-muted);font-size:13px}
        .qr-modal-actions{display:flex;gap:8px;margin-top:16px;justify-content:center;flex-wrap:wrap}

        /* Empty state */
        .empty-state{text-align:center;padding:60px 32px;color:var(--ink-muted)}
        .empty-state .empty-icon{font-size:56px;color:var(--border);margin-bottom:20px;display:block}
        .empty-state h3{font-weight:700;font-size:18px;color:var(--ink-soft);margin-bottom:6px}

        @media(max-width:768px){
            .wrap{padding:0 16px 40px}
            .stats-row{grid-template-columns:repeat(2,1fr)}
            .section-tab{padding:6px 16px;font-size:12px}
            .af2-modal{width:96%}
        }
    </style>
</head>
<body>
<div class="toast-wrap" id="toastWrap"></div>

<div class="wrap">
    <div class="page-header">
        <div>
            <div class="breadcrumb">
                <a href="/AdminDashboard"><i class="fas fa-home"></i> Dashboard</a>
                <span>/</span><span>Student Management</span>
            </div>
            <h1 class="page-title">Student <em>Management</em></h1>
        </div>
        <div class="header-actions">
            <button class="btn btn-outline btn-sm" id="globalExportBtn"><i class="fas fa-file-csv"></i> Export CSV</button>
            <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
            <a href="/AddStudents" class="btn btn-gold"><i class="fas fa-user-plus"></i> Enroll Student</a>
        </div>
    </div>

    <!-- STATS -->
    <div class="stats-row">
        <div class="stat-card s-total"><div class="stat-icon"><i class="fas fa-users"></i></div><div><div class="stat-label">Total Students</div><div class="stat-value" data-target="<?= $totalStudents ?>">0</div></div></div>
        <div class="stat-card s-pending"><div class="stat-icon"><i class="fas fa-clock"></i></div><div><div class="stat-label">Pending</div><div class="stat-value" data-target="<?= $pendingCount ?>">0</div></div></div>
        <div class="stat-card s-enrolled"><div class="stat-icon"><i class="fas fa-user-graduate"></i></div><div><div class="stat-label">Enrolled</div><div class="stat-value" data-target="<?= $enrolledCount ?>">0</div></div></div>
        <div class="stat-card s-active"><div class="stat-icon"><i class="fas fa-user-check"></i></div><div><div class="stat-label">Active</div><div class="stat-value" data-target="<?= $activeCount ?>">0</div></div></div>
        <div class="stat-card s-completed"><div class="stat-icon"><i class="fas fa-graduation-cap"></i></div><div><div class="stat-label">Completed</div><div class="stat-value" data-target="<?= $completedCount ?>">0</div></div></div>
        <div class="stat-card s-inactive"><div class="stat-icon"><i class="fas fa-user-slash"></i></div><div><div class="stat-label">Inactive</div><div class="stat-value" data-target="<?= $inactiveCount ?>">0</div></div></div>
    </div>

    <!-- TABS -->
    <div class="section-tabs">
        <button class="section-tab active" data-section="main"><i class="fas fa-user-check"></i> Current Learners (<?= count($mainStudents) ?>)</button>
        <button class="section-tab" data-section="pending"><i class="fas fa-hourglass-half"></i> Pending (<?= count($pendingStudents) ?>)</button>
        <button class="section-tab" data-section="old"><i class="fas fa-history"></i> Old / Completed (<?= count($oldStudents) ?>)</button>
    </div>

    <!-- CURRENT LEARNERS -->
    <div id="section-main" class="section-content active">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-user-graduate" style="color:var(--green)"></i> Current Learners <span class="count-badge">Enrolled & Active</span></div>
            </div>
            <div class="filter-bar">
                <div class="search-mini"><i class="fas fa-search"></i><input type="text" id="searchMain" placeholder="Search by name or LRN..."></div>
                <button class="btn btn-outline btn-sm" onclick="clearSearch('searchMain','mainTable')"><i class="fas fa-times"></i> Clear</button>
            </div>
            <div class="table-wrap">
                <table id="mainTable">
                    <thead><tr><th>LRN</th><th>Student ID</th><th>Full Name</th><th>Age</th><th>Gender</th><th>Barangay</th><th>Teacher</th><th>Status</th><th>QR</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($mainStudents as $i => $s): renderStudentRow($s, $i, false); endforeach; ?>
                        <?php if(empty($mainStudents)): ?><tr><td colspan="10"><div class="empty-state"><span class="empty-icon"><i class="fas fa-user-friends"></i></span><h3>No active/enrolled students</h3></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- PENDING -->
    <div id="section-pending" class="section-content">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-hourglass-half" style="color:var(--orange)"></i> Pending Approvals <span class="count-badge">Awaiting AF2 Verification</span></div>
                <button class="btn btn-primary btn-sm" onclick="toggleLrnUpload()"><i class="fas fa-file-upload"></i> Upload LRN CSV</button>
            </div>

            <!-- LRN UPLOAD PANEL -->
            <div id="lrnUploadPanel" class="lrn-upload-panel">
                <div class="lrn-upload-header">
                    <h3><i class="fas fa-table"></i> Bulk LRN Upload via CSV</h3>
                    <button class="btn btn-outline btn-xs" onclick="toggleLrnUpload()"><i class="fas fa-times"></i></button>
                </div>
                <div class="lrn-template-note">
                    <i class="fas fa-info-circle" style="flex-shrink:0;margin-top:1px"></i>
                    <div>
                        <strong>CSV Format:</strong> Row 1 = header (skip). Column A = Student ID, Column B = LRN.<br>
                        Students with PSA already submitted will be <strong>auto-enrolled</strong> once LRN is matched.
                        <br><a href="#" onclick="downloadTemplate()" style="color:var(--blue);font-weight:600"><i class="fas fa-download"></i> Download Template</a>
                    </div>
                </div>
                <div class="lrn-drop-zone" id="lrnDropZone" onclick="document.getElementById('lrnFileInput').click()">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>Drag & drop your CSV file here</p>
                    <span>or click to browse</span>
                    <input type="file" id="lrnFileInput" accept=".csv" style="display:none">
                </div>
                <div id="lrnResult" class="lrn-result"></div>
            </div>

            <div class="filter-bar">
                <div class="search-mini"><i class="fas fa-search"></i><input type="text" id="searchPending" placeholder="Search by name or LRN..."></div>
                <button class="btn btn-outline btn-sm" onclick="clearSearch('searchPending','pendingTable')"><i class="fas fa-times"></i> Clear</button>
            </div>
            <div class="table-wrap">
                <table id="pendingTable">
                    <thead><tr><th>LRN</th><th>Student ID</th><th>Full Name</th><th>Age</th><th>Gender</th><th>Barangay</th><th>Teacher</th><th>AF2 Status</th><th>QR</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($pendingStudents as $i => $s): renderStudentRow($s, $i, true); endforeach; ?>
                        <?php if(empty($pendingStudents)): ?><tr><td colspan="10"><div class="empty-state"><span class="empty-icon"><i class="fas fa-check-circle"></i></span><h3>No pending students</h3><p>All students have been processed</p></div></td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- OLD LEARNERS -->
    <div id="section-old" class="section-content">
        <div class="panel">
            <div class="panel-header">
                <div class="panel-title"><i class="fas fa-history" style="color:var(--purple)"></i> Old Learners & Completed <span class="count-badge">Graduated / Archived</span></div>
            </div>
            <div class="filter-bar">
                <div class="search-mini"><i class="fas fa-search"></i><input type="text" id="searchOld" placeholder="Search by name or LRN..."></div>
                <button class="btn btn-outline btn-sm" onclick="clearSearch('searchOld','oldTable')"><i class="fas fa-times"></i> Clear</button>
            </div>
            <div class="table-wrap">
                <table id="oldTable">
                    <thead><tr><th>LRN</th><th>Student ID</th><th>Full Name</th><th>Age</th><th>Gender</th><th>Barangay</th><th>Teacher</th><th>Status</th><th>QR</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($oldStudents as $i => $s): renderStudentRow($s, $i, false); endforeach; ?>
                        <?php if(empty($oldStudents)): ?><tr><td colspan="10"><div class="empty-state"><span class="empty-icon"><i class="fas fa-smile"></i></span><h3>No archived/completed students</h3></div></td></td><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-backdrop" id="deleteModal">
    <div class="modal">
        <span class="modal-icon"><i class="fas fa-triangle-exclamation"></i></span>
        <h3>Delete Student</h3>
        <p>You are about to permanently delete <strong id="delName"></strong>.</p>
        <div class="modal-warn"><i class="fas fa-exclamation-circle" style="flex-shrink:0"></i> This action cannot be undone.</div>
        <div class="modal-actions">
            <button class="btn btn-outline btn-sm" onclick="closeModal('deleteModal')">Cancel</button>
            <button class="btn btn-danger btn-sm" onclick="execDelete()"><i class="fas fa-trash"></i> Yes, Delete</button>
        </div>
    </div>
</div>

<!-- AF2 ENROLLMENT MODAL -->
<div class="af2-modal-backdrop" id="af2Modal">
    <div class="af2-modal">
        <div class="af2-modal-head">
            <div>
                <h3><i class="fas fa-clipboard-check"></i> AF2 Enrollment Checklist</h3>
                <p id="af2ModalSubtitle">Loading student information...</p>
            </div>
            <button class="af2-modal-close" onclick="closeAF2Modal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="af2-modal-body" id="af2ModalBody">
            <div class="af2-loading-state">
                <i class="fas fa-spinner fa-pulse" style="font-size:22px;color:var(--blue)"></i>
                Loading checklist...
            </div>
        </div>
        <div class="af2-modal-foot" id="af2ModalFoot" style="display:none">
            <button class="btn btn-outline btn-sm" onclick="closeAF2Modal()">
                <i class="fas fa-times"></i> Close
            </button>
            <button class="af2-enroll-btn" id="af2EnrollBtn" disabled onclick="execAF2Enroll()">
                <i class="fas fa-user-graduate"></i> Requirements Incomplete
            </button>
        </div>
    </div>
</div>

<!-- QR MODAL -->
<div class="modal-backdrop" id="qrModal">
    <div class="modal" style="max-width:360px;text-align:center">
        <div class="qr-modal-body">
            <img id="qrImg" src="" alt="QR Code">
            <h4 id="qrName"></h4>
            <p id="qrId"></p>
        </div>
        <div class="qr-modal-actions">
            <button class="btn btn-outline btn-sm" onclick="closeModal('qrModal')"><i class="fas fa-times"></i> Close</button>
            <button class="btn btn-primary btn-sm" onclick="downloadQR()"><i class="fas fa-download"></i> Download</button>
            <button class="btn btn-gold btn-sm" onclick="printQR()"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>
</div>

<script>
// ── SECTION TABS ──
document.querySelectorAll('.section-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.section-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.section-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('section-' + tab.dataset.section).classList.add('active');
    });
});

// ── SEARCH ──
function setupTableSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        document.querySelectorAll('#' + tableId + ' tbody tr').forEach(row => {
            if (row.querySelector('.empty-state')) return;
            row.style.display = row.innerText.toLowerCase().includes(filter) ? '' : 'none';
        });
    });
}
function clearSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    if (input) { input.value = ''; input.dispatchEvent(new Event('keyup')); }
}
setupTableSearch('searchMain', 'mainTable');
setupTableSearch('searchPending', 'pendingTable');
setupTableSearch('searchOld', 'oldTable');

// ── STAT COUNTERS ──
document.querySelectorAll('.stat-value[data-target]').forEach(el => {
    const target = parseInt(el.dataset.target, 10);
    if (!target) { el.textContent = '0'; return; }
    let current = 0, step = Math.ceil(target / 40);
    const timer = setInterval(() => {
        current = Math.min(current + step, target);
        el.textContent = current.toLocaleString();
        if (current >= target) clearInterval(timer);
    }, 20);
});

// ── MODALS ──
let delToken = null, currentQR = {src:'', name:'', id:''};
function openModal(id) { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-backdrop').forEach(bd => bd.addEventListener('click', e => { if (e.target === bd) closeModal(bd.id); }));
function confirmDelete(token, name) { delToken = token; document.getElementById('delName').textContent = name; openModal('deleteModal'); }
function execDelete() { if (delToken) window.location.href = '/AllStudents?_act=del&_t=' + encodeURIComponent(delToken); }
function openQR(src, name, id) {
    currentQR = {src, name, id};
    document.getElementById('qrImg').src = src;
    document.getElementById('qrName').textContent = name;
    document.getElementById('qrId').textContent = 'ID: ' + id;
    openModal('qrModal');
}
function downloadQR() { const a = document.createElement('a'); a.href = currentQR.src; a.download = 'qr_' + currentQR.id + '.png'; a.click(); }
function printQR() {
    const w = window.open('', '_blank', 'width=400,height=500');
    w.document.write(`<!DOCTYPE html><html><head><title>QR</title><style>body{font-family:sans-serif;text-align:center;padding:40px}img{max-width:200px}h2{margin:16px 0 4px}p{color:#666;font-size:14px}</style></head><body><img src="${currentQR.src}"><h2>${currentQR.name}</h2><p>ID: ${currentQR.id}</p><script>window.onload=()=>window.print()<\/script></body></html>`);
    w.document.close();
}

// ── QR GENERATION ──
async function generateQR(token, name, btn) {
    if (!confirm('Generate QR code for ' + name + '?')) return;
    const orig = btn ? btn.innerHTML : '';
    if (btn) { btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i>'; btn.disabled = true; }
    try {
        const res = await fetch('/AllStudents?_act=gen_qr&_t=' + encodeURIComponent(token), {headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        const data = await res.json();
        if (data.success) { toast('success', 'QR Generated', 'QR code for ' + name + ' created!'); setTimeout(() => location.reload(), 1500); }
        else { toast('error', 'Failed', data.error || 'Could not generate QR code.'); if(btn){btn.innerHTML=orig;btn.disabled=false;} }
    } catch(e) { toast('error', 'Error', 'Network error.'); if(btn){btn.innerHTML=orig;btn.disabled=false;} }
}

// ══════════════════════════════════════════════════════════════════
// AF2 MODAL SYSTEM
// ══════════════════════════════════════════════════════════════════

let af2CurrentToken = null;
let af2CurrentSid   = null;
let af2CurrentName  = null;
let af2CurrentAv    = null;
let af2State        = { psa: 0, lrn_ok: 0 };

function openAF2Modal(token, sid, name, av) {
    af2CurrentToken = token;
    af2CurrentSid   = sid;
    af2CurrentName  = name;
    af2CurrentAv    = av;

    document.getElementById('af2ModalSubtitle').textContent = name + ' · ' + sid;
    document.getElementById('af2ModalBody').innerHTML = `
        <div class="af2-loading-state">
            <i class="fas fa-spinner fa-pulse" style="font-size:22px;color:var(--blue)"></i>
            Loading checklist...
        </div>`;
    document.getElementById('af2ModalFoot').style.display = 'none';
    document.getElementById('af2Modal').classList.add('open');

    loadAF2Data();
}

function closeAF2Modal() {
    document.getElementById('af2Modal').classList.remove('open');
    af2CurrentToken = af2CurrentSid = af2CurrentName = null;
}

// Close on backdrop click
document.getElementById('af2Modal').addEventListener('click', function(e) {
    if (e.target === this) closeAF2Modal();
});

async function loadAF2Data() {
    try {
        const res  = await fetch('/AllStudents?_act=af2_get&_t=' + encodeURIComponent(af2CurrentToken), {headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        const data = await res.json();
        if (data.success) renderAF2Modal(data);
        else throw new Error('Failed');
    } catch(e) {
        document.getElementById('af2ModalBody').innerHTML = `
            <div class="af2-loading-state" style="color:var(--red)">
                <i class="fas fa-exclamation-circle" style="font-size:22px"></i>
                Failed to load checklist. Please try again.
            </div>`;
    }
}

function renderAF2Modal(data) {
    af2State.psa    = data.psa;
    af2State.lrn_ok = data.lrn_ok;

    const psaDone  = data.psa === 1;
    const lrnDone  = data.lrn_ok === 1;
    const progress = (psaDone ? 50 : 0) + (lrnDone ? 50 : 0);

    const avColors = ['#4f46e5','#0891b2','#059669','#d97706','#dc2626','#7c3aed','#0f766e','#b45309','#1d4ed8','#be185d'];
    const avColor  = avColors[af2CurrentAv % 10];
    const initials = af2CurrentName.split(',').map(p => p.trim()[0] || '').join('').toUpperCase().slice(0,2);

    document.getElementById('af2ModalBody').innerHTML = `
        <div class="af2-student-card">
            <div class="av" style="background:${avColor}">${initials}</div>
            <div class="info">
                <strong>${af2CurrentName}</strong>
                <span>${af2CurrentSid}</span>
            </div>
        </div>

        <div class="af2-req-item ${psaDone ? 'done' : ''}" id="af2-psa-item" onclick="toggleAF2Req('psa')">
            <div class="af2-req-toggle" id="af2-psa-toggle">
                ${psaDone ? '<i class="fas fa-check"></i>' : ''}
            </div>
            <div class="af2-req-info">
                <div class="af2-req-title">
                    <i class="fas fa-file-alt" style="color:var(--red);font-size:15px"></i>
                    PSA Birth Certificate
                    <span style="color:var(--red);font-size:15px;font-weight:900">*</span>
                </div>
                <div class="af2-req-desc">Philippine Statistics Authority issued birth certificate required for official enrollment.</div>
                <span class="af2-req-badge ${data.has_psa_file ? 'found' : 'missing'}">
                    ${data.has_psa_file ? '📎 File uploaded' : '⚠ No file uploaded'}
                </span>
            </div>
        </div>

        <div class="af2-req-item ${lrnDone ? 'done' : ''}" id="af2-lrn-item" onclick="toggleAF2Req('lrn')">
            <div class="af2-req-toggle" id="af2-lrn-toggle">
                ${lrnDone ? '<i class="fas fa-check"></i>' : ''}
            </div>
            <div class="af2-req-info">
                <div class="af2-req-title">
                    <i class="fas fa-id-card" style="color:var(--blue);font-size:15px"></i>
                    LRN (Learner Reference Number)
                    <span style="color:var(--red);font-size:15px;font-weight:900">*</span>
                </div>
                <div class="af2-req-desc">12-digit LRN from DepEd required for official registration.</div>
                <span class="af2-req-badge ${data.has_lrn ? 'found' : 'waiting'}">
                    ${data.has_lrn ? '✓ LRN: ' + data.lrn_value : '⏳ LRN not yet assigned'}
                </span>
            </div>
        </div>

        <div class="af2-progress-wrap">
            <div class="af2-progress-top">
                <span>Completion</span>
                <span id="af2-prog-pct" style="color:${progress===100?'var(--green)':'var(--ink-soft)'}">${progress}%</span>
            </div>
            <div class="af2-prog-bar">
                <div class="af2-prog-fill" id="af2-prog-fill" style="width:${progress}%"></div>
            </div>
        </div>
    `;

    const foot = document.getElementById('af2ModalFoot');
    foot.style.display = 'flex';
    updateEnrollBtn(psaDone && lrnDone);
}

function toggleAF2Req(type) {
    if (type === 'psa') af2State.psa = af2State.psa ? 0 : 1;
    else af2State.lrn_ok = af2State.lrn_ok ? 0 : 1;

    const psaDone  = af2State.psa === 1;
    const lrnDone  = af2State.lrn_ok === 1;
    const progress = (psaDone ? 50 : 0) + (lrnDone ? 50 : 0);

    // Update PSA item
    const psaItem   = document.getElementById('af2-psa-item');
    const psaToggle = document.getElementById('af2-psa-toggle');
    psaItem.classList.toggle('done', psaDone);
    psaToggle.innerHTML = psaDone ? '<i class="fas fa-check"></i>' : '';

    // Update LRN item
    const lrnItem   = document.getElementById('af2-lrn-item');
    const lrnToggle = document.getElementById('af2-lrn-toggle');
    lrnItem.classList.toggle('done', lrnDone);
    lrnToggle.innerHTML = lrnDone ? '<i class="fas fa-check"></i>' : '';

    // Update progress
    document.getElementById('af2-prog-fill').style.width = progress + '%';
    const pctEl = document.getElementById('af2-prog-pct');
    pctEl.textContent = progress + '%';
    pctEl.style.color = progress === 100 ? 'var(--green)' : 'var(--ink-soft)';

    updateEnrollBtn(psaDone && lrnDone);
    saveAF2State();
}

function updateEnrollBtn(canEnroll) {
    const btn = document.getElementById('af2EnrollBtn');
    btn.disabled = !canEnroll;
    btn.innerHTML = canEnroll
        ? '<i class="fas fa-user-graduate"></i> Enroll Now'
        : '<i class="fas fa-lock"></i> Requirements Incomplete';
}

async function saveAF2State() {
    try {
        const res  = await fetch(`/AllStudents?_act=af2_check&_t=${encodeURIComponent(af2CurrentToken)}&psa=${af2State.psa}&lrn_ok=${af2State.lrn_ok}`, {headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin'});
        const data = await res.json();
        if (data.auto_enrolled) {
            toast('success', 'Auto-Enrolled!', af2CurrentName + ' has been enrolled — all requirements met.');
            closeAF2Modal();
            setTimeout(() => location.reload(), 2000);
        }
    } catch(e) { toast('error', 'Save Error', 'Could not save checklist state.'); }
}

async function execAF2Enroll() {
    const btn = document.getElementById('af2EnrollBtn');
    if (!confirm('Confirm enrollment for ' + af2CurrentName + '? This will move them to Enrolled status.')) return;
    btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Enrolling...';
    btn.disabled = true;
    window.location.href = '/AllStudents?_act=status&_t=' + encodeURIComponent(af2CurrentToken) + '&_s=enrolled';
}

// ══════════════════════════════════════════════════════════════════
// LRN CSV UPLOAD
// ══════════════════════════════════════════════════════════════════

function toggleLrnUpload() {
    const panel = document.getElementById('lrnUploadPanel');
    panel.classList.toggle('open');
}

function downloadTemplate() {
    const csv = 'student_id,lrn\nALS-2026-001,123456789012\nALS-2026-002,123456789013\n';
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv'}));
    a.download = 'lrn_upload_template.csv';
    a.click();
}

const lrnDropZone  = document.getElementById('lrnDropZone');
const lrnFileInput = document.getElementById('lrnFileInput');
const lrnResult    = document.getElementById('lrnResult');

if (lrnDropZone) {
    lrnDropZone.addEventListener('dragover',  e => { e.preventDefault(); lrnDropZone.classList.add('dragover'); });
    lrnDropZone.addEventListener('dragleave', () => lrnDropZone.classList.remove('dragover'));
    lrnDropZone.addEventListener('drop', e => {
        e.preventDefault(); lrnDropZone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (file) processLRNFile(file);
    });
}
if (lrnFileInput) {
    lrnFileInput.addEventListener('change', function() { if (this.files[0]) processLRNFile(this.files[0]); });
}

async function processLRNFile(file) {
    const ext = file.name.split('.').pop().toLowerCase();
    if (ext !== 'csv') { showLrnResult(false, 'Only CSV files are supported.'); return; }
    lrnDropZone.innerHTML = '<i class="fas fa-spinner fa-pulse" style="font-size:28px;color:var(--blue)"></i><p style="margin-top:8px">Processing file...</p>';
    const formData = new FormData();
    formData.append('bulk_lrn_upload', '1');
    formData.append('lrn_file', file);
    try {
        const res  = await fetch('/AllStudents', {method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
        const data = await res.json();
        lrnDropZone.innerHTML = '<i class="fas fa-cloud-upload-alt"></i><p>Drag & drop your CSV file here</p><span>or click to browse</span><input type="file" id="lrnFileInput" accept=".csv" style="display:none">';
        document.getElementById('lrnFileInput').addEventListener('change', function() { if(this.files[0]) processLRNFile(this.files[0]); });
        if (data.success) { showLrnResult(true,  `✅ ${data.message}`); if (data.updated > 0) setTimeout(() => location.reload(), 2500); }
        else              { showLrnResult(false, '❌ ' + (data.error || 'Upload failed.')); }
    } catch(e) {
        lrnDropZone.innerHTML = '<i class="fas fa-cloud-upload-alt"></i><p>Drag & drop your CSV file here</p><span>or click to browse</span>';
        showLrnResult(false, '❌ Network error. Please try again.');
    }
}

function showLrnResult(success, msg) {
    lrnResult.className = 'lrn-result ' + (success ? 'success' : 'error');
    lrnResult.textContent = msg;
    lrnResult.style.display = 'block';
}

// ── CSV EXPORT ──
function exportCSV(tableId, filename) {
    const rows = Array.from(document.querySelectorAll('#' + tableId + ' tbody tr')).filter(r => r.style.display !== 'none' && !r.querySelector('.empty-state'));
    if (!rows.length) { toast('error', 'Nothing to export', 'No visible records.'); return; }
    const headers = ['LRN','Student ID','Full Name','Age','Gender','Barangay','Teacher','Status'];
    const lines   = [headers.join(',')];
    rows.forEach(row => {
        const cells  = row.querySelectorAll('td');
        const values = [];
        for (let i = 0; i < 8; i++) { let txt = (cells[i]?.innerText?.trim()||'').replace(/"/g,'""'); values.push('"'+txt+'"'); }
        lines.push(values.join(','));
    });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([lines.join('\n')], {type:'text/csv'}));
    a.download = filename || 'students.csv';
    a.click();
    toast('success', 'Export complete', rows.length + ' records exported.');
}

document.getElementById('globalExportBtn').addEventListener('click', () => {
    const active = document.querySelector('.section-content.active').id;
    if (active === 'section-main') exportCSV('mainTable','current_learners.csv');
    else if (active === 'section-pending') exportCSV('pendingTable','pending_students.csv');
    else exportCSV('oldTable','old_learners.csv');
});

// ── TOAST ──
function toast(type, title, msg, dur=5000) {
    const icons = {success:'fa-circle-check', error:'fa-circle-xmark', warn:'fa-triangle-exclamation'};
    const el = document.createElement('div');
    el.className = 'toast' + (type !== 'success' ? ' ' + type : '');
    el.innerHTML = `<span class="toast-icon"><i class="fas ${icons[type]||icons.success}"></i></span><div class="toast-body"><h4>${title}</h4><p>${msg}</p></div><button class="toast-close" onclick="this.closest('.toast').remove()"><i class="fas fa-times"></i></button>`;
    document.getElementById('toastWrap').appendChild(el);
    setTimeout(() => { el.style.animation='toastIn .3s reverse forwards'; setTimeout(()=>el.remove(),400); }, dur-400);
}

<?php if(isset($_SESSION['success'])): ?>toast('success','Success','<?= addslashes($_SESSION['success']) ?>');<?php unset($_SESSION['success']); endif; ?>
<?php if(isset($_SESSION['error'])): ?>toast('error','Error','<?= addslashes($_SESSION['error']) ?>');<?php unset($_SESSION['error']); endif; ?>
</script>
</body>
</html>

<?php
// ═══════════════════════════════════════════════════════════════════════════════
// RENDER STUDENT ROW
// ═══════════════════════════════════════════════════════════════════════════════

function renderStudentRow($s, $idx, $isPending) {
    $qr     = getQRCodeUrl($s['qr_code'] ?? '');
    $hasQR  = !empty($s['qr_code']) && file_exists(__DIR__ . '/../' . ltrim($s['qr_code'], './'));
    $fn     = htmlspecialchars($s['first_name']);
    $ln     = htmlspecialchars($s['last_name']);
    $mid    = !empty($s['middle_name']) ? strtoupper(substr($s['middle_name'],0,1)).'.' : '';
    $ext    = !empty($s['extension_name']) ? ' '.$s['extension_name'] : '';
    $init   = strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1));
    $av     = $idx % 10;
    $status = $s['status'] ?? 'pending';
    $sid    = $s['student_id'];
    $tok    = issue_token($sid);

    // AF2 data for pending
    $psaDone = (int)($s['af2_psa_submitted'] ?? 0);
    $lrnDone = (int)($s['af2_lrn_provided'] ?? 0);
    $af2Progress = ($psaDone ? 50 : 0) + ($lrnDone ? 50 : 0);
    ?>
    <tr>
        <td><div class="td-inner"><code style="font-size:13px"><?= htmlspecialchars($s['lrn'] ?? '—') ?></code></div></td>
        <td><div class="td-inner" style="color:var(--ink-muted);font-size:13px"><?= htmlspecialchars($sid) ?></div></td>

        <!-- NAME CELL — with AF2 dropdown for pending -->
        <td><div class="td-inner">
            <?php if ($isPending): ?>
            <button class="btn btn-outline btn-sm" style="display:flex;align-items:center;gap:10px;width:100%;text-align:left;border-color:transparent;padding:4px 0"
                onclick="openAF2Modal('<?= $tok ?>','<?= addslashes($sid) ?>','<?= addslashes("$ln, $fn") ?>',<?= $av ?>)" type="button">
                <div class="student-avatar av-<?= $av ?>"><?= $init ?></div>
                <div class="student-name" style="text-align:left">
                    <strong><?= "$ln, $fn" ?></strong>
                    <?php if ($mid||$ext): ?><small><?= trim("$mid$ext") ?></small><?php endif; ?>
                </div>
                <i class="fas fa-clipboard-check" style="margin-left:auto;color:var(--blue);font-size:13px;opacity:.6"></i>
            </button>
            <?php else: ?>
            <div class="name-cell">
                <div class="student-avatar av-<?= $av ?>"><?= $init ?></div>
                <div class="student-name">
                    <strong><?= "$ln, $fn" ?></strong>
                    <?php if ($mid||$ext): ?><small><?= trim("$mid$ext") ?></small><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div></td>

        <td><div class="td-inner"><?= htmlspecialchars($s['age'] ?? '—') ?></div></td>
        <td><div class="td-inner"><?= ucfirst(htmlspecialchars($s['sex'] ?? '—')) ?></div></td>
        <td><div class="td-inner"><?= htmlspecialchars($s['barangay_name'] ?? '—') ?></div></td>
        <td><div class="td-inner" style="max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($s['teacher_name'] ?? 'Unassigned') ?></div></td>

        <!-- STATUS / AF2 PROGRESS -->
        <td><div class="td-inner">
            <?php if ($isPending): ?>
                <div style="min-width:120px">
                    <div style="display:flex;gap:6px;margin-bottom:5px;flex-wrap:wrap">
                        <span style="font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $psaDone ? 'var(--green-light)' : 'var(--red-light)' ?>;color:<?= $psaDone ? 'var(--green)' : 'var(--red)' ?>">
                            <?= $psaDone ? '✓' : '✗' ?> PSA
                        </span>
                        <span style="font-size:11px;padding:2px 8px;border-radius:20px;font-weight:700;background:<?= $lrnDone ? 'var(--green-light)' : 'var(--orange-light)' ?>;color:<?= $lrnDone ? 'var(--green)' : 'var(--orange)' ?>">
                            <?= $lrnDone ? '✓' : '⏳' ?> LRN
                        </span>
                    </div>
                    <div style="height:4px;background:var(--border);border-radius:10px;overflow:hidden">
                        <div style="height:100%;width:<?= $af2Progress ?>%;background:linear-gradient(90deg,var(--blue),var(--green));border-radius:10px;transition:width .4s"></div>
                    </div>
                    <div style="font-size:10px;color:var(--ink-muted);margin-top:3px"><?= $af2Progress ?>% complete</div>
                </div>
            <?php else: ?>
                <span class="badge badge-<?= $status ?>"><?= ucfirst($status) ?></span>
            <?php endif; ?>
        </div></td>

        <!-- QR -->
        <td><div class="td-inner">
            <?php if ($hasQR): ?>
                <img src="<?= htmlspecialchars($qr) ?>" alt="QR" class="qr-thumb"
                     onclick="openQR('<?= htmlspecialchars($qr,ENT_QUOTES) ?>','<?= addslashes("$ln, $fn") ?>','<?= htmlspecialchars($sid) ?>')"
                     onerror="this.style.display='none'">
            <?php else: ?>
                <div class="qr-placeholder" onclick="generateQR('<?= $tok ?>','<?= addslashes("$fn $ln") ?>',this)" title="Click to generate QR code">
                    <i class="fas fa-qrcode"></i>
                </div>
            <?php endif; ?>
        </div></td>

        <!-- ACTIONS -->
        <td><div class="td-inner"><div class="actions">
            <a href="/ViewStudent?<?= $tok ?>" class="act-btn act-view" title="View Profile"><i class="fas fa-eye"></i></a>
            <a href="/EditStudents?<?= $tok ?>" class="act-btn act-edit" title="Edit Student"><i class="fas fa-pen-to-square"></i></a>
            <?php if (!$hasQR): ?>
                <button type="button" class="act-btn act-qr" title="Generate QR" onclick="generateQR('<?= $tok ?>','<?= addslashes("$fn $ln") ?>',this)"><i class="fas fa-qrcode"></i></button>
            <?php endif; ?>
            <?php if ($status === 'pending'): ?>
                <a href="?_act=status&_t=<?= $tok ?>&_s=enrolled" class="act-btn" style="color:var(--gold)" title="Approve Enrollment" onclick="return confirm('Approve enrollment for <?= addslashes("$fn $ln") ?>?')"><i class="fas fa-check-circle"></i></a>
            <?php elseif ($status === 'enrolled'): ?>
                <a href="?_act=status&_t=<?= $tok ?>&_s=active" class="act-btn" style="color:var(--green)" title="Activate" onclick="return confirm('Activate <?= addslashes("$fn $ln") ?>?')"><i class="fas fa-user-check"></i></a>
                <a href="?_act=status&_t=<?= $tok ?>&_s=completed" class="act-btn act-complete" title="Mark Completed" onclick="return confirm('Mark as completed?')"><i class="fas fa-graduation-cap"></i></a>
            <?php elseif ($status === 'active'): ?>
                <a href="?_act=status&_t=<?= $tok ?>&_s=completed" class="act-btn act-complete" title="Mark Completed" onclick="return confirm('Mark as completed?')"><i class="fas fa-graduation-cap"></i></a>
            <?php endif; ?>
            <?php if ($status !== 'pending' && $status !== 'enrolled'): ?>
                <button type="button" class="act-btn act-del" title="Delete" onclick="confirmDelete('<?= $tok ?>','<?= addslashes("$fn $ln") ?>')"><i class="fas fa-trash"></i></button>
            <?php endif; ?>
        </div></div></td>
    </tr>
    <?php
}
?>