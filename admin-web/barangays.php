<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

secure_session_start();
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// ── Token helpers for barangays ──────────────────────────────────────────────
if (!isset($_SESSION['_bt']) || !is_array($_SESSION['_bt'])) $_SESSION['_bt'] = [];

function issue_barangay_token(int $id): string {
    $sid = (string)$id;
    $ex = array_search($sid, $_SESSION['_bt'], true);
    if ($ex !== false) return $ex;
    $tok = bin2hex(random_bytes(20));
    $_SESSION['_bt'][$tok] = $sid;
    if (count($_SESSION['_bt']) > 500) $_SESSION['_bt'] = array_slice($_SESSION['_bt'], -500, null, true);
    return $tok;
}

function resolve_barangay_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_bt'][$token] ?? 0);
}

$success = $error = null;
$edit_token = null;

// ── HANDLE FORM SUBMISSIONS ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['add_barangay'])) {
        $name   = trim($conn->real_escape_string($_POST['name']));
        $city   = trim($conn->real_escape_string($_POST['city']));
        $status = $conn->real_escape_string($_POST['status']);

        if (!empty($name)) {
            $dup = $conn->prepare("SELECT barangay_id FROM barangays WHERE name = ? AND city = ?");
            $dup->bind_param("ss", $name, $city);
            $dup->execute();
            if ($dup->get_result()->num_rows > 0) {
                $error = "Barangay <strong>$name</strong> already exists in that city.";
            } else {
                $stmt = $conn->prepare("INSERT INTO barangays (name, city, status) VALUES (?, ?, ?)");
                $stmt->bind_param("sss", $name, $city, $status);
                if ($stmt->execute()) {
                    $success = "Barangay <strong>$name</strong> added successfully!";
                    log_action($conn, $_SESSION['admin_id'], 'Barangay Added', "Added barangay: $name");
                } else {
                    $error = "Error adding barangay: " . $stmt->error;
                }
                $stmt->close();
            }
            $dup->close();
        } else {
            $error = "Barangay name is required.";
        }
    }

    if (isset($_POST['edit_barangay'])) {
        $barangay_id = intval($_POST['barangay_id']);
        $name        = trim($conn->real_escape_string($_POST['name']));
        $city        = trim($conn->real_escape_string($_POST['city']));
        $status      = $conn->real_escape_string($_POST['status']);

        if (!empty($name)) {
            $stmt = $conn->prepare("UPDATE barangays SET name=?, city=?, status=? WHERE barangay_id=?");
            $stmt->bind_param("sssi", $name, $city, $status, $barangay_id);
            if ($stmt->execute()) {
                $success = "Barangay <strong>$name</strong> updated successfully!";
                log_action($conn, $_SESSION['admin_id'], 'Barangay Updated', "Updated barangay: $name");
            } else {
                $error = "Error updating barangay: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $error = "Barangay name is required.";
        }
    }

    // Archive instead of delete - mark as inactive
    if (isset($_POST['archive_barangay'])) {
        $barangay_id = intval($_POST['barangay_id']);

        $chk = $conn->prepare("SELECT COUNT(*) as c FROM students WHERE current_barangay_id=? OR permanent_barangay_id=?");
        $chk->bind_param("ii", $barangay_id, $barangay_id);
        $chk->execute();
        $c = $chk->get_result()->fetch_assoc()['c'];
        $chk->close();

        if ($c > 0) {
            $error = "Cannot archive — this barangay is linked to <strong>$c</strong> student(s).";
        } else {
            $stmt = $conn->prepare("UPDATE barangays SET status='inactive' WHERE barangay_id=?");
            $stmt->bind_param("i", $barangay_id);
            if ($stmt->execute()) {
                $success = "Barangay archived successfully.";
                log_action($conn, $_SESSION['admin_id'], 'Barangay Archived', "Archived barangay ID: $barangay_id");
            } else {
                $error = "Error archiving: " . $stmt->error;
            }
            $stmt->close();
        }
    }

    // Restore archived barangay
    if (isset($_POST['restore_barangay'])) {
        $barangay_id = intval($_POST['barangay_id']);
        $stmt = $conn->prepare("UPDATE barangays SET status='active' WHERE barangay_id=?");
        $stmt->bind_param("i", $barangay_id);
        if ($stmt->execute()) {
            $success = "Barangay restored successfully.";
            log_action($conn, $_SESSION['admin_id'], 'Barangay Restored', "Restored barangay ID: $barangay_id");
        } else {
            $error = "Error restoring: " . $stmt->error;
        }
        $stmt->close();
    }

    if (isset($_POST['toggle_status'])) {
        $barangay_id = intval($_POST['barangay_id']);
        $new_status  = $conn->real_escape_string($_POST['new_status']);
        $stmt = $conn->prepare("UPDATE barangays SET status=? WHERE barangay_id=?");
        $stmt->bind_param("si", $new_status, $barangay_id);
        if ($stmt->execute()) {
            $success = "Status updated to <strong>" . ucfirst($new_status) . "</strong>.";
        }
        $stmt->close();
    }
}

// ── DATA ─────────────────────────────────────────────────────────
$search    = trim($_GET['q'] ?? '');
$filter    = $_GET['status'] ?? '';
$sort      = in_array($_GET['sort'] ?? '', ['name','city','status']) ? $_GET['sort'] : 'name';
$dir       = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$next_dir  = $dir === 'asc' ? 'desc' : 'asc';

$where = "WHERE 1=1";
$params = [];
if ($search)  { $where .= " AND (b.name LIKE ? OR b.city LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($filter)  { $where .= " AND b.status = ?"; $params[] = $filter; }

$sql = "SELECT b.*, 
            (SELECT COUNT(*) FROM students WHERE current_barangay_id   = b.barangay_id) as current_count,
            (SELECT COUNT(*) FROM students WHERE permanent_barangay_id = b.barangay_id) as permanent_count
        FROM barangays b
        $where
        ORDER BY $sort $dir";

if (!empty($params)) {
    $types = str_repeat('s', count($params));
    $stmt  = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $barangays = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $barangays = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Handle edit via token (no ID in URL)
$edit_barangay = null;
if (!empty($_GET['edit'])) {
    $token = trim($_GET['edit']);
    $bid = resolve_barangay_token($token);
    if ($bid) {
        $stmt = $conn->prepare("SELECT * FROM barangays WHERE barangay_id=?");
        $stmt->bind_param("i", $bid);
        $stmt->execute();
        $edit_barangay = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$stats = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(status='active')   as active,
        SUM(status='inactive') as inactive,
        COUNT(DISTINCT city)   as cities
    FROM barangays
")->fetch_assoc();

$cities = $conn->query("SELECT DISTINCT city FROM barangays WHERE city != '' ORDER BY city")->fetch_all(MYSQLI_ASSOC);

include __DIR__ . '/includes/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Barangays — ALS System</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --bg:       #f0f4f8;
    --surface:  #ffffff;
    --surface2: #f8f9fb;
    --border:   #e2e8f0;
    --border2:  #cbd5e1;
    --ink:      #0f172a;
    --ink2:     #334155;
    --ink3:     #64748b;
    --ink4:     #94a3b8;
    --teal:     #0d9488;
    --teal2:    #14b8a6;
    --red:      #dc2626;
    --red2:     #fee2e2;
    --amber:    #d97706;
    --amber2:   #fef3c7;
    --blue:     #2563eb;
    --blue2:    #dbeafe;
    --slate:    #475569;
    --shadow:   0 1px 4px rgba(15,23,42,.07), 0 4px 16px rgba(15,23,42,.06);
    --shadow-md:0 4px 12px rgba(15,23,42,.1), 0 12px 32px rgba(15,23,42,.08);
    --r:        12px;
}

*,
*::before,
*::after {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html, body {
    height: 100%;
    width: 100%;
    overflow-x: hidden;
    overflow-y: auto;
}

body {
    font-family: 'Plus Jakarta Sans', sans-serif;
    background: var(--bg);
    color: var(--ink);
    font-size: 14px;
    line-height: 1.6;
    min-height: 100%;
}

::-webkit-scrollbar {
    width: 6px;
    height: 6px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: var(--border2);
    border-radius: 8px;
}
::-webkit-scrollbar-thumb:hover {
    background: var(--ink3);
}

.page-wrap {
    max-width: 1380px;
    margin: 0 auto;
    padding: 28px 20px 60px;
    min-height: 100%;
    width: 100%;
}

.page-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 28px;
    flex-wrap: wrap;
}
.page-top h1 {
    font-size: 26px;
    font-weight: 800;
    color: var(--ink);
    letter-spacing: -.5px;
    line-height: 1.1;
}
.page-top .breadcrumb {
    font-size: 12.5px;
    color: var(--ink3);
    margin-top: 5px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.page-top .breadcrumb .sep { opacity: .4; }
.page-top-right { display: flex; gap: 10px; align-items: center; }

.alert {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    padding: 14px 16px;
    border-radius: var(--r);
    font-size: 13.5px;
    margin-bottom: 22px;
    border: 1px solid transparent;
    animation: alertIn .3s ease;
}
@keyframes alertIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.alert i { margin-top: 2px; flex-shrink: 0; font-size: 15px; }
.alert-success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
.alert-success i { color: #22c55e; }
.alert-danger  { background: #fff1f2; border-color: #fecaca; color: #991b1b; }
.alert-danger i { color: #ef4444; }
.alert .alert-close {
    margin-left: auto;
    background: none;
    border: none;
    cursor: pointer;
    color: inherit;
    opacity: .5;
    font-size: 16px;
    padding: 0 2px;
    transition: opacity .15s;
    flex-shrink: 0;
}
.alert .alert-close:hover { opacity: 1; }

.stats-row {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 28px;
}
.stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    box-shadow: var(--shadow);
    transition: transform .2s, box-shadow .2s;
    animation: fadeUp .4s ease both;
}
.stat-card:nth-child(1){animation-delay:.05s}
.stat-card:nth-child(2){animation-delay:.10s}
.stat-card:nth-child(3){animation-delay:.15s}
.stat-card:nth-child(4){animation-delay:.20s}
@keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon {
    width: 46px; height: 46px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    flex-shrink: 0;
}
.stat-body .s-val { font-size: 32px; font-weight: 800; line-height: 1; letter-spacing: -1px; }
.stat-body .s-lbl { font-size: 12px; color: var(--ink3); font-weight: 500; margin-top: 3px; }
.si-teal  { background: #ccfbf1; color: var(--teal); }
.si-green { background: #dcfce7; color: #16a34a; }
.si-slate { background: #f1f5f9; color: var(--slate); }
.si-amber { background: var(--amber2); color: var(--amber); }
.sv-teal  { color: var(--teal); }
.sv-green { color: #16a34a; }
.sv-slate { color: var(--slate); }
.sv-amber { color: var(--amber); }

.layout-cols {
    display: grid;
    grid-template-columns: 340px 1fr;
    gap: 24px;
    align-items: start;
    overflow: visible;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r);
    box-shadow: var(--shadow);
    overflow: visible;
    animation: fadeUp .4s ease .25s both;
}

.card:last-child {
    display: flex;
    flex-direction: column;
    min-height: 0;
    max-height: 700px;
}

.card-header {
    border-radius: var(--r) var(--r) 0 0;
    padding: 18px 22px 14px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    background: var(--surface);
    flex-shrink: 0;
}
.card-header h3 {
    font-size: 15px;
    font-weight: 700;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 9px;
}
.card-header h3 i { color: var(--teal); }
.card-header .ch-sub { font-size: 12px; color: var(--ink3); margin-top: 2px; }

.card-body {
    padding: 22px;
    overflow-y: auto;
    flex: 1;
}

.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid var(--border);
    background: var(--surface2);
    flex-shrink: 0;
}

.table-wrap {
    flex: 1;
    min-height: 0;
    overflow: auto;
    width: 100%;
    -webkit-overflow-scrolling: touch;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 680px;
}

thead tr {
    background: var(--surface2);
    position: sticky;
    top: 0;
    z-index: 2;
}

th {
    padding: 11px 14px;
    font-size: 11px;
    font-weight: 700;
    color: var(--ink3);
    letter-spacing: .7px;
    text-transform: uppercase;
    text-align: left;
    border-bottom: 1.5px solid var(--border);
    white-space: nowrap;
    background: var(--surface2);
    position: sticky;
    top: 0;
    z-index: 2;
}

th a {
    color: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
th a:hover { color: var(--teal); }

td {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border);
    font-size: 13.5px;
    vertical-align: middle;
    white-space: nowrap;
    background: var(--surface);
}

tbody tr:hover td {
    background: rgba(13,148,136,.03);
}

tbody tr:last-child td {
    border-bottom: none;
}

.row-name { font-weight: 600; color: var(--ink); }
.row-id   { font-size: 11.5px; color: var(--ink4); margin-top: 1px; }

.fg { margin-bottom: 16px; }
.fg label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    color: var(--ink2);
    letter-spacing: .4px;
    text-transform: uppercase;
    margin-bottom: 6px;
}
.fg label .req { color: var(--red); margin-left: 2px; }
.fg input, .fg select {
    width: 100%;
    background: var(--surface2);
    border: 1.5px solid var(--border);
    border-radius: 9px;
    padding: 10px 13px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 13.5px;
    color: var(--ink);
    outline: none;
    transition: border-color .18s, box-shadow .18s;
}
.fg input:focus, .fg select:focus {
    border-color: var(--teal);
    box-shadow: 0 0 0 3px rgba(13,148,136,.1);
}
.fg input::placeholder { color: var(--ink4); }
.fg .hint { font-size: 11.5px; color: var(--ink3); margin-top: 4px; }

.status-pills { display: flex; gap: 8px; margin-top: 2px; }
.status-pills input[type="radio"] { display: none; }
.status-pills label {
    flex: 1;
    text-align: center;
    padding: 8px 12px;
    border-radius: 8px;
    border: 1.5px solid var(--border);
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    transition: all .15s;
    text-transform: capitalize;
    letter-spacing: normal;
    color: var(--ink3);
    background: var(--surface2);
    margin-bottom: 0;
}
.status-pills input[value="active"]:checked + label   { background: #f0fdf4; border-color: #86efac; color: #16a34a; }
.status-pills input[value="inactive"]:checked + label { background: var(--surface2); border-color: var(--border2); color: var(--slate); }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 9px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    font-size: 13.5px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all .17s;
    white-space: nowrap;
}
.btn-primary { background: var(--teal); color: #fff; }
.btn-primary:hover { background: var(--teal2); transform: translateY(-1px); box-shadow: 0 4px 12px rgba(13,148,136,.3); }
.btn-ghost { background: transparent; color: var(--ink2); border: 1.5px solid var(--border); }
.btn-ghost:hover { background: var(--surface2); }
.btn-warning { background: var(--amber); color: #fff; }
.btn-warning:hover { background: #b45309; }
.btn-danger-ghost { background: transparent; color: var(--red); border: 1.5px solid #fecaca; }
.btn-danger-ghost:hover { background: var(--red2); }
.btn-block { width: 100%; justify-content: center; }
.btn-sm { padding: 6px 11px; font-size: 12.5px; border-radius: 7px; }
.btn-icon { width: 32px; height: 32px; padding: 0; justify-content: center; border-radius: 7px; }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 10px;
    border-radius: 100px;
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: .3px;
}
.badge-active   { background: #dcfce7; color: #15803d; }
.badge-inactive { background: var(--surface2); color: var(--slate); border: 1px solid var(--border); }

.student-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 100px;
    font-size: 11.5px;
    font-weight: 600;
    background: var(--blue2);
    color: var(--blue);
}
.student-pill.zero { background: var(--surface2); color: var(--ink4); }

.empty-row td {
    text-align: center;
    padding: 40px 20px;
    color: var(--ink3);
    white-space: normal;
}
.empty-row td i { font-size: 32px; display: block; margin-bottom: 10px; opacity: .35; }

.modal-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(15,23,42,.45);
    z-index: 1000;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(3px);
}
.modal-backdrop.open { display: flex; }
.modal-box {
    background: var(--surface);
    border-radius: 16px;
    padding: 28px 28px 22px;
    max-width: 400px;
    width: 100%;
    box-shadow: 0 20px 60px rgba(15,23,42,.25);
    animation: modalIn .25s ease;
    margin: 16px;
}
@keyframes modalIn { from{opacity:0;transform:scale(.94)} to{opacity:1;transform:scale(1)} }
.modal-icon {
    width: 52px; height: 52px;
    border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    margin-bottom: 16px;
}
.modal-icon.warning { background: var(--amber2); color: var(--amber); }
.modal-icon.danger { background: var(--red2); color: var(--red); }
.modal-box h4 { font-size: 17px; font-weight: 700; margin-bottom: 8px; }
.modal-box p  { font-size: 13.5px; color: var(--ink3); line-height: 1.55; margin-bottom: 20px; }
.modal-actions { display: flex; gap: 10px; justify-content: flex-end; }

.form-mode-bar {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 14px;
    border-radius: 9px;
    margin-bottom: 18px;
    font-size: 13px;
    font-weight: 600;
}
.form-mode-bar.edit { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.form-mode-bar.add  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }

.search-wrap {
    position: relative;
    flex: 1;
    min-width: 180px;
}
.search-wrap i {
    position: absolute;
    left: 11px; top: 50%;
    transform: translateY(-50%);
    color: var(--ink4);
    font-size: 13px;
    pointer-events: none;
}
.search-wrap input {
    width: 100%;
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px 8px 34px;
    font-size: 13px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--ink);
    outline: none;
    transition: border-color .18s;
}
.search-wrap input:focus { border-color: var(--teal); }
.toolbar select {
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 8px;
    padding: 8px 12px;
    font-size: 13px;
    font-family: 'Plus Jakarta Sans', sans-serif;
    color: var(--ink2);
    outline: none;
    cursor: pointer;
}
.toolbar .count-badge {
    font-size: 12px;
    color: var(--ink3);
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 4px 10px;
    border-radius: 100px;
    white-space: nowrap;
}

.col-actions {
    min-width: 100px;
}

@media print {
    .page-top-right, .toolbar, .col-actions, .card:first-child, .btn, .modal-backdrop { display: none !important; }
    body { background: #fff; }
    .stats-row { grid-template-columns: repeat(4,1fr); }
    .layout-cols { display: block; }
    td, th { border: 1px solid #ddd !important; }
}

@media (max-width: 900px) {
    .stats-row { grid-template-columns: repeat(2,1fr); }
    .layout-cols { grid-template-columns: 1fr; }
    .card:last-child {
        max-height: 500px;
    }
}
</style>
</head>
<body>

<div class="page-wrap">

<!-- PAGE TOP -->
<div class="page-top">
    <div>
        <h1><i class="fas fa-map-marker-alt" style="color:var(--teal);font-size:22px;margin-right:8px"></i>Manage Barangays</h1>
        <div class="breadcrumb">
            <a href="/AdminDashboard" style="color:var(--ink3);text-decoration:none">ALS Admin</a>
            <span class="sep">/</span>
            <span>Barangays</span>
        </div>
    </div>
    <div class="page-top-right">
        <button class="btn btn-ghost btn-sm" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('nameInput').focus()">
            <i class="fas fa-plus"></i> Add Barangay
        </button>
    </div>
</div>

<!-- ALERTS -->
<?php if ($success): ?>
<div class="alert alert-success">
    <i class="fas fa-check-circle"></i>
    <div><?= $success ?></div>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-circle"></i>
    <div><?= $error ?></div>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<!-- STATS -->
<div class="stats-row">
    <div class="stat-card">
        <div class="stat-icon si-teal"><i class="fas fa-map-marker-alt"></i></div>
        <div class="stat-body">
            <div class="s-val sv-teal" data-count="<?= $stats['total'] ?>"><?= $stats['total'] ?></div>
            <div class="s-lbl">Total Barangays</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fas fa-check-circle"></i></div>
        <div class="stat-body">
            <div class="s-val sv-green" data-count="<?= $stats['active'] ?>"><?= $stats['active'] ?></div>
            <div class="s-lbl">Active</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-slate"><i class="fas fa-archive"></i></div>
        <div class="stat-body">
            <div class="s-val sv-slate" data-count="<?= $stats['inactive'] ?>"><?= $stats['inactive'] ?></div>
            <div class="s-lbl">Archived</div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-amber"><i class="fas fa-city"></i></div>
        <div class="stat-body">
            <div class="s-val sv-amber" data-count="<?= $stats['cities'] ?>"><?= $stats['cities'] ?></div>
            <div class="s-lbl">Cities / Municipalities</div>
        </div>
    </div>
</div>

<!-- LAYOUT -->
<div class="layout-cols">

    <!-- FORM COLUMN -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3>
                    <i class="fas fa-<?= $edit_barangay ? 'edit' : 'plus-circle' ?>"></i>
                    <?= $edit_barangay ? 'Edit Barangay' : 'Add New Barangay' ?>
                </h3>
                <div class="ch-sub"><?= $edit_barangay ? 'Modify existing record' : 'Register a new barangay' ?></div>
            </div>
        </div>
        <div class="card-body">
            <?php if ($edit_barangay): ?>
            <div class="form-mode-bar edit">
                <i class="fas fa-pen"></i> Editing: <strong><?= htmlspecialchars($edit_barangay['name']) ?></strong>
            </div>
            <?php else: ?>
            <div class="form-mode-bar add">
                <i class="fas fa-plus"></i> Fill in the details below
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php if ($edit_barangay): ?>
                <input type="hidden" name="barangay_id" value="<?= $edit_barangay['barangay_id'] ?>">
                <?php endif; ?>

                <datalist id="citiesList">
                    <?php foreach($cities as $c): ?>
                    <option value="<?= htmlspecialchars($c['city']) ?>">
                    <?php endforeach; ?>
                </datalist>

                <div class="fg">
                    <label>Barangay Name <span class="req">*</span></label>
                    <input type="text" id="nameInput" name="name"
                        value="<?= htmlspecialchars($edit_barangay['name'] ?? '') ?>"
                        placeholder="e.g. Barangay 1" autocomplete="off" required>
                </div>

                <div class="fg">
                    <label>City / Municipality</label>
                    <input type="text" name="city"
                        value="<?= htmlspecialchars($edit_barangay['city'] ?? '') ?>"
                        placeholder="e.g. Bacolod City" list="citiesList" autocomplete="off">
                    <div class="hint">Start typing to see existing cities</div>
                </div>

                <div class="fg">
                    <label>Status</label>
                    <div class="status-pills">
                        <?php $cur_status = $edit_barangay['status'] ?? 'active'; ?>
                        <input type="radio" name="status" id="s_active" value="active" <?= $cur_status==='active'?'checked':'' ?>>
                        <label for="s_active"><i class="fas fa-check-circle" style="font-size:12px"></i> Active</label>
                        <input type="radio" name="status" id="s_inactive" value="inactive" <?= $cur_status==='inactive'?'checked':'' ?>>
                        <label for="s_inactive"><i class="fas fa-archive" style="font-size:12px"></i> Archived</label>
                    </div>
                </div>

                <?php if ($edit_barangay): ?>
                <div style="display:flex;flex-direction:column;gap:8px;margin-top:20px">
                    <button type="submit" name="edit_barangay" class="btn btn-primary btn-block">
                        <i class="fas fa-save"></i> Update Barangay
                    </button>
                    <a href="barangays.php" class="btn btn-ghost btn-block">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
                <?php else: ?>
                <div style="margin-top:20px">
                    <button type="submit" name="add_barangay" class="btn btn-primary btn-block">
                        <i class="fas fa-plus"></i> Add Barangay
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- TABLE COLUMN -->
    <div class="card">
        <div class="card-header">
            <div>
                <h3><i class="fas fa-list"></i> Barangay Directory</h3>
                <div class="ch-sub"><?= count($barangays) ?> record(s) found</div>
            </div>
        </div>

        <!-- TOOLBAR -->
        <form method="GET" class="toolbar">
            <div class="search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                    placeholder="Search barangay or city…" oninput="this.form.submit()">
            </div>
            <select name="status" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                <option value="active"   <?= $filter==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $filter==='inactive'?'selected':'' ?>>Archived</option>
            </select>
            <?php if ($search || $filter): ?>
            <a href="barangays.php" class="btn btn-ghost btn-sm"><i class="fas fa-times"></i> Clear</a>
            <?php endif; ?>
            <span class="count-badge"><strong><?= count($barangays) ?></strong> shown</span>
        </form>

        <!-- TABLE — wrapped in scroll container -->
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>
                            <a href="?q=<?= urlencode($search) ?>&status=<?= $filter ?>&sort=name&dir=<?= $sort==='name'?$next_dir:'asc' ?>">
                                Barangay <?php if($sort==='name') echo $dir==='asc'?'↑':'↓'; ?>
                            </a>
                        </th>
                        <th>
                            <a href="?q=<?= urlencode($search) ?>&status=<?= $filter ?>&sort=city&dir=<?= $sort==='city'?$next_dir:'asc' ?>">
                                City/Municipality <?php if($sort==='city') echo $dir==='asc'?'↑':'↓'; ?>
                            </a>
                        </th>
                        <th>Students</th>
                        <th>
                            <a href="?q=<?= urlencode($search) ?>&status=<?= $filter ?>&sort=status&dir=<?= $sort==='status'?$next_dir:'asc' ?>">
                                Status <?php if($sort==='status') echo $dir==='asc'?'↑':'↓'; ?>
                            </a>
                        </th>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($barangays)): ?>
                <tr class="empty-row">
                    <td colspan="6">
                        <i class="fas fa-map"></i>
                        <?= $search || $filter ? 'No barangays match your search.' : 'No barangays yet. Add one using the form.' ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($barangays as $i => $b): 
                    $edit_token = issue_barangay_token($b['barangay_id']);
                    $total_s = $b['current_count'] + $b['permanent_count'];
                ?>
                <tr>
                    <td style="color:var(--ink4);font-size:12px"><?= $i+1 ?></td>
                    <td>
                        <div class="row-name"><?= htmlspecialchars($b['name']) ?></div>
                        <div class="row-id">ID #<?= $b['barangay_id'] ?></div>
                    </td>
                    <td style="color:var(--ink2)"><?= $b['city'] ? htmlspecialchars($b['city']) : '<span style="color:var(--ink4)">—</span>' ?></td>
                    <td>
                        <span class="student-pill <?= $total_s==0?'zero':'' ?>">
                            <i class="fas fa-user" style="font-size:10px"></i> <?= $total_s ?>
                        </span>
                        <?php if($total_s > 0): ?>
                        <div style="font-size:11px;color:var(--ink4);margin-top:2px">
                            <?= $b['current_count'] ?> current, <?= $b['permanent_count'] ?> permanent
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="barangay_id" value="<?= $b['barangay_id'] ?>">
                            <input type="hidden" name="new_status" value="<?= $b['status']==='active'?'inactive':'active' ?>">
                            <button type="submit" name="toggle_status"
                                class="badge <?= $b['status']==='active'?'badge-active':'badge-inactive' ?>"
                                style="border:none;cursor:pointer;transition:opacity .15s"
                                title="Click to toggle status">
                                <i class="fas fa-<?= $b['status']==='active'?'check-circle':'archive' ?>"></i>
                                <?= $b['status']==='active'?'Active':'Archived' ?>
                            </button>
                        </form>
                    </td>
                    <td class="col-actions">
                        <div style="display:flex;gap:5px">
                            <a href="?edit=<?= $edit_token ?>" class="btn btn-ghost btn-icon btn-sm" title="Edit">
                                <i class="fas fa-pen"></i>
                            </a>
                            <?php if ($b['status'] === 'active' && $total_s == 0): ?>
                            <button class="btn btn-warning btn-icon btn-sm" title="Archive"
                                onclick="confirmArchive(<?= $b['barangay_id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>')">
                                <i class="fas fa-archive"></i>
                            </button>
                            <?php elseif ($b['status'] === 'inactive'): ?>
                            <button class="btn btn-primary btn-icon btn-sm" title="Restore"
                                onclick="confirmRestore(<?= $b['barangay_id'] ?>, '<?= htmlspecialchars(addslashes($b['name'])) ?>')">
                                <i class="fas fa-undo-alt"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /.table-wrap -->
    </div>

</div><!-- /.layout-cols -->
</div><!-- /.page-wrap -->

<!-- ARCHIVE CONFIRM MODAL -->
<div class="modal-backdrop" id="archiveModal">
    <div class="modal-box">
        <div class="modal-icon warning"><i class="fas fa-archive"></i></div>
        <h4>Archive Barangay?</h4>
        <p id="archiveMsg">This barangay will be marked as archived and will not appear in active lists.</p>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeArchiveModal()">Cancel</button>
            <form method="POST" id="archiveForm" style="display:inline">
                <input type="hidden" name="barangay_id" id="archiveBarangayId">
                <button type="submit" name="archive_barangay" class="btn btn-warning">
                    <i class="fas fa-archive"></i> Archive
                </button>
            </form>
        </div>
    </div>
</div>

<!-- RESTORE CONFIRM MODAL -->
<div class="modal-backdrop" id="restoreModal">
    <div class="modal-box">
        <div class="modal-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-undo-alt"></i></div>
        <h4>Restore Barangay?</h4>
        <p id="restoreMsg">This barangay will be restored and will appear in active lists.</p>
        <div class="modal-actions">
            <button class="btn btn-ghost" onclick="closeRestoreModal()">Cancel</button>
            <form method="POST" id="restoreForm" style="display:inline">
                <input type="hidden" name="barangay_id" id="restoreBarangayId">
                <button type="submit" name="restore_barangay" class="btn btn-primary">
                    <i class="fas fa-undo-alt"></i> Restore
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function confirmArchive(id, name) {
    document.getElementById('archiveMsg').innerHTML = `Are you sure you want to archive <strong>${name}</strong>? It will be hidden from active lists but can be restored later.`;
    document.getElementById('archiveBarangayId').value = id;
    document.getElementById('archiveModal').classList.add('open');
}

function confirmRestore(id, name) {
    document.getElementById('restoreMsg').innerHTML = `Are you sure you want to restore <strong>${name}</strong>? It will reappear in active lists.`;
    document.getElementById('restoreBarangayId').value = id;
    document.getElementById('restoreModal').classList.add('open');
}

function closeArchiveModal() {
    document.getElementById('archiveModal').classList.remove('open');
}

function closeRestoreModal() {
    document.getElementById('restoreModal').classList.remove('open');
}

// Close modals when clicking outside
document.getElementById('archiveModal')?.addEventListener('click', e => {
    if (e.target === document.getElementById('archiveModal')) closeArchiveModal();
});

document.getElementById('restoreModal')?.addEventListener('click', e => {
    if (e.target === document.getElementById('restoreModal')) closeRestoreModal();
});

// Close modals on Escape key
document.addEventListener('keydown', e => { 
    if (e.key === 'Escape') {
        closeArchiveModal();
        closeRestoreModal();
    }
});

// Animate stats
document.querySelectorAll('[data-count]').forEach(el => {
    const target = +el.dataset.count;
    if (target === 0) return;
    let start = 0;
    const dur = 700, steps = 30;
    const t = setInterval(() => {
        start++;
        el.textContent = Math.round(target * start / steps);
        if (start >= steps) { el.textContent = target; clearInterval(t); }
    }, dur / steps);
});

// Auto-hide alerts
setTimeout(() => {
    document.querySelectorAll('.alert').forEach(a => {
        a.style.transition = 'opacity .5s';
        a.style.opacity = '0';
        setTimeout(() => a.remove(), 500);
    });
}, 4000);
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>