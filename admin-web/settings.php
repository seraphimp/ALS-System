<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/dashboard_functions.php';

secure_session_start();

if (!isset($_SESSION['admin_id']) || !is_admin_logged_in()) {
    redirect('index.php');
    exit;
}

$admin = get_admin_details($conn, $_SESSION['admin_id']);

// ============================================================
// CREATE SETTINGS TABLE IF IT DOESN'T EXIST
// ============================================================

$settingsTableExists = $conn->query("SHOW TABLES LIKE 'settings'")->num_rows > 0;

if (!$settingsTableExists) {
    $conn->query("CREATE TABLE IF NOT EXISTS settings (
        setting_id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        setting_type VARCHAR(50) DEFAULT 'text',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

$prSettingsTableExists = $conn->query("SHOW TABLES LIKE 'preregistration_settings'")->num_rows > 0;

if (!$prSettingsTableExists) {
    $conn->query("CREATE TABLE IF NOT EXISTS preregistration_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value TEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
    
    // Insert default settings including version
    $conn->query("INSERT INTO preregistration_settings (setting_key, setting_value) VALUES
        ('preregistration_enabled', '1'),
        ('preregistration_start_date', NULL),
        ('preregistration_end_date', NULL),
        ('preregistration_limit', '100'),
        ('preregistration_daily_limit', '20'),
        ('preregistration_require_human_verification', '1'),
        ('preregistration_require_email_verification', '1'),
        ('preregistration_notify_admin', '1'),
        ('preregistration_auto_approve', '0'),
        ('preregistration_message', 'Please complete the pre-registration form to begin your enrollment process.'),
        ('settings_version', '1')
    ");
}

// ============================================================
// HELPERS
// ============================================================

function log_system_action($conn, $user_id, $action, $description = '') {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $tableExists = $conn->query("SHOW TABLES LIKE 'system_logs'")->num_rows > 0;
    if (!$tableExists) return;
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, user_type, action, description, ip_address, user_agent) VALUES (?, 'admin', ?, ?, ?, ?)");
    $stmt->bind_param("issss", $user_id, $action, $description, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
}

function human_time_diff_simple(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('M d', $ts);
}

// ============================================================
// HANDLE FORM SUBMISSIONS - WITH CACHE CLEARING
// ============================================================

$success_message = '';
$error_message   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_preregistration'])) {
    $new_status  = isset($_POST['new_status']) ? intval($_POST['new_status']) : 0;
    $query = "INSERT INTO preregistration_settings (setting_key, setting_value) VALUES ('preregistration_enabled', '$new_status')
              ON DUPLICATE KEY UPDATE setting_value = '$new_status'";
    if ($conn->query($query)) {
        $status_text     = $new_status ? 'enabled' : 'disabled';
        $success_message = "Pre-registration has been {$status_text} successfully!";
        log_system_action($conn, $_SESSION['admin_id'], 'Pre-registration Toggled', "Pre-registration was {$status_text}");
        
        // IMPORTANT: Clear the session cache so it takes effect immediately
        unset($_SESSION['pr_settings_cache']);
        unset($_SESSION['pr_settings_cache_time']);
        
        // Update version to force cache refresh on all users
        $version = time();
        $conn->query("INSERT INTO preregistration_settings (setting_key, setting_value) VALUES ('settings_version', '$version')
                      ON DUPLICATE KEY UPDATE setting_value = '$version'");
    } else {
        $error_message = "Error toggling pre-registration: " . $conn->error;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_system_settings'])) {
    $site_name         = $conn->real_escape_string($_POST['site_name']);
    $site_description  = $conn->real_escape_string($_POST['site_description']);
    $admin_email       = $conn->real_escape_string($_POST['admin_email']);
    $items_per_page    = intval($_POST['items_per_page']);
    $enable_registration = isset($_POST['enable_registration']) ? 1 : 0;
    $maintenance_mode  = isset($_POST['maintenance_mode']) ? 1 : 0;
    $session_timeout   = intval($_POST['session_timeout']);
    $allow_teacher_reg = isset($_POST['allow_teacher_reg']) ? 1 : 0;

    $settings_to_save = [
        'site_name'           => $site_name,
        'site_description'    => $site_description,
        'admin_email'         => $admin_email,
        'items_per_page'      => $items_per_page,
        'enable_registration' => $enable_registration,
        'maintenance_mode'    => $maintenance_mode,
        'session_timeout'     => $session_timeout,
        'allow_teacher_reg'   => $allow_teacher_reg
    ];

    $updateSuccess = true;
    foreach ($settings_to_save as $key => $value) {
        $q = "INSERT INTO settings (setting_key, setting_value) VALUES ('$key', '$value')
              ON DUPLICATE KEY UPDATE setting_value = '$value'";
        if (!$conn->query($q)) { $updateSuccess = false; $error_message = "Error: " . $conn->error; break; }
    }
    if ($updateSuccess) {
        $success_message = "System settings updated successfully!";
        log_system_action($conn, $_SESSION['admin_id'], 'Settings Updated', 'System configuration was modified');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $full_name       = $conn->real_escape_string($_POST['full_name']);
    $email           = $conn->real_escape_string($_POST['email']);
    $current_password = $_POST['current_password'];
    $new_password    = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    $stmt = $conn->prepare("SELECT password FROM admins WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['admin_id']);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (password_verify($current_password, $result['password'])) {
        if (!empty($new_password)) {
            if ($new_password === $confirm_password) {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE admins SET full_name = ?, email = ?, password = ? WHERE id = ?");
                $stmt->bind_param("sssi", $full_name, $email, $password_hash, $_SESSION['admin_id']);
            } else {
                $error_message = "New passwords do not match.";
            }
        } else {
            $stmt = $conn->prepare("UPDATE admins SET full_name = ?, email = ? WHERE id = ?");
            $stmt->bind_param("ssi", $full_name, $email, $_SESSION['admin_id']);
        }
        if (isset($stmt) && empty($error_message) && $stmt->execute()) {
            $success_message = "Profile updated successfully!";
            $_SESSION['admin_name']  = $full_name;
            $_SESSION['admin_email'] = $email;
            log_system_action($conn, $_SESSION['admin_id'], 'Profile Updated', 'Admin profile was modified');
        } elseif (empty($error_message)) {
            $error_message = "Error updating profile.";
        }
        if (isset($stmt)) $stmt->close();
    } else {
        $error_message = "Current password is incorrect.";
    }
    $admin = get_admin_details($conn, $_SESSION['admin_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_database'])) {
    $backup_file     = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $success_message = "Database backup created successfully: " . $backup_file;
    log_system_action($conn, $_SESSION['admin_id'], 'Database Backup', 'Database backup was created');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cache'])) {
    // Clear all session caches
    unset($_SESSION['pr_settings_cache']);
    unset($_SESSION['pr_settings_cache_time']);
    unset($_SESSION['pr_counts_cache']);
    unset($_SESSION['barangays_cache']);
    unset($_SESSION['public_prereg_counts']);
    
    $success_message = "System cache cleared successfully!";
    log_system_action($conn, $_SESSION['admin_id'], 'Cache Cleared', 'System cache was cleared');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_preregistration_settings'])) {
    $pr_settings_data = [
        'preregistration_enabled'                  => isset($_POST['preregistration_enabled']) ? 1 : 0,
        'preregistration_start_date'               => !empty($_POST['preregistration_start_date']) ? $_POST['preregistration_start_date'] : null,
        'preregistration_end_date'                 => !empty($_POST['preregistration_end_date']) ? $_POST['preregistration_end_date'] : null,
        'preregistration_limit'                    => intval($_POST['preregistration_limit']),
        'preregistration_daily_limit'              => intval($_POST['preregistration_daily_limit']),
        'preregistration_require_human_verification' => isset($_POST['preregistration_require_human_verification']) ? 1 : 0,
        'preregistration_require_email_verification' => isset($_POST['preregistration_require_email_verification']) ? 1 : 0,
        'preregistration_notify_admin'             => isset($_POST['preregistration_notify_admin']) ? 1 : 0,
        'preregistration_auto_approve'             => isset($_POST['preregistration_auto_approve']) ? 1 : 0,
        'preregistration_message'                  => $conn->real_escape_string($_POST['preregistration_message'])
    ];
    $update_success = true;
    foreach ($pr_settings_data as $key => $value) {
        if (is_null($value)) {
            $query = "INSERT INTO preregistration_settings (setting_key, setting_value) VALUES ('$key', NULL)
                      ON DUPLICATE KEY UPDATE setting_value = NULL";
        } else {
            $v     = $conn->real_escape_string($value);
            $query = "INSERT INTO preregistration_settings (setting_key, setting_value) VALUES ('$key', '$v')
                      ON DUPLICATE KEY UPDATE setting_value = '$v'";
        }
        if (!$conn->query($query)) { $update_success = false; $error_message = "Error: " . $conn->error; break; }
    }
    if ($update_success) {
        $success_message = "Pre-registration settings updated successfully!";
        log_system_action($conn, $_SESSION['admin_id'], 'Pre-registration Settings Updated', 'Pre-registration configuration was modified');
        
        // Clear the session cache
        unset($_SESSION['pr_settings_cache']);
        unset($_SESSION['pr_settings_cache_time']);
        
        // Update version
        $version = time();
        $conn->query("INSERT INTO preregistration_settings (setting_key, setting_value) VALUES ('settings_version', '$version')
                      ON DUPLICATE KEY UPDATE setting_value = '$version'");
    }
}

// ============================================================
// FETCH CURRENT SETTINGS
// ============================================================

$settings = [
    'site_name'          => 'ALS Enrollment System',
    'site_description'   => 'Alternative Learning System Enrollment and E-Learning Platform',
    'admin_email'        => $admin['email'] ?? 'admin@als-system.com',
    'items_per_page'     => 10,
    'enable_registration'=> 1,
    'maintenance_mode'   => 0,
    'session_timeout'    => 30,
    'allow_teacher_reg'  => 1,
    'system_version'     => '1.0.0'
];

if ($settingsTableExists) {
    $result = $conn->query("SELECT setting_key, setting_value FROM settings");
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$pr_settings = [];
$pr_result   = $conn->query("SELECT setting_key, setting_value FROM preregistration_settings");
if ($pr_result) { while ($row = $pr_result->fetch_assoc()) $pr_settings[$row['setting_key']] = $row['setting_value']; }

$totalUsers    = $conn->query("SELECT COUNT(*) as count FROM admins")->fetch_assoc()['count'] ?? 1;
$totalStudents = $conn->query("SELECT COUNT(*) as count FROM students WHERE status = 'enrolled'")->fetch_assoc()['count'] ?? 0;
$totalTeachers = $conn->query("SELECT COUNT(*) as count FROM teachers WHERE status = 'active'")->fetch_assoc()['count'] ?? 0;

$dbSize = 0;
$dbSizeResult = $conn->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as size_mb
                              FROM information_schema.tables WHERE table_schema = DATABASE()");
if ($dbSizeResult && $dbSizeResult->num_rows > 0) $dbSize = $dbSizeResult->fetch_assoc()['size_mb'] ?? 0;

$logs = [];
$logResult = $conn->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 50");
if ($logResult && $logResult->num_rows > 0) { while ($row = $logResult->fetch_assoc()) $logs[] = $row; }

$today           = date('Y-m-d');
$total_pr_count  = $conn->query("SELECT COUNT(*) as count FROM preregistrations")->fetch_assoc()['count'] ?? 0;
$today_pr_count  = $conn->query("SELECT COUNT(*) as count FROM preregistrations WHERE DATE(submitted_at) = '$today'")->fetch_assoc()['count'] ?? 0;
$pending_pr_count = $conn->query("SELECT COUNT(*) as count FROM preregistrations WHERE status = 'pending'")->fetch_assoc()['count'] ?? 0;
$approved_pr_count = $conn->query("SELECT COUNT(*) as count FROM preregistrations WHERE status = 'approved'")->fetch_assoc()['count'] ?? 0;

if (ob_get_length()) ob_end_flush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <link rel="icon" type="image/png" href="../logo/als-logo-removebg-preview.png">
    <title>System Settings - ALS Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;1,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --blue:           #1d4ed8;
            --blue-dark:      #1e3a8a;
            --blue-darker:    #172554;
            --blue-mid:       #2563eb;
            --blue-light:     #eff6ff;
            --blue-soft:      #dbeafe;
            --blue-border:    #bfdbfe;
            --emerald:        #059669;
            --emerald-light:  #d1fae5;
            --amber:          #d97706;
            --amber-light:    #fef3c7;
            --amber-border:   #fcd34d;
            --violet:         #7c3aed;
            --violet-light:   #ede9fe;
            --rose:           #e11d48;
            --rose-light:     #fff1f2;
            --bg:             #f0f4ff;
            --bg-mid:         #e4eaf6;
            --border:         #e2e8f0;
            --white:          #ffffff;
            --text-dark:      #0f172a;
            --text-mid:       #334155;
            --text-light:     #64748b;
            --text-xlight:    #94a3b8;
            --sidebar-w:      270px;
            --r-sm:           8px;
            --r-md:           14px;
            --r-lg:           20px;
            --shadow-sm:      0 1px 3px rgba(0,0,0,0.07);
            --shadow-md:      0 4px 16px rgba(29,78,216,0.11), 0 2px 4px rgba(0,0,0,0.04);
            --ease:           cubic-bezier(0.4,0,0.2,1);
        }

        body { font-family: 'Plus Jakarta Sans', sans-serif; background: var(--bg); color: var(--text-dark); overflow-x: hidden; }
        .app { display: flex; min-height: 100vh; }
        .main { flex: 1; margin-left: var(--sidebar-w); width: calc(100% - var(--sidebar-w)); padding-bottom: 48px; }

        /* ── TOPBAR ── */
        .topbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(240,244,255,0.94);
            backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
            border-bottom: 1.5px solid var(--border);
            padding: 13px 32px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .page-breadcrumb { display: flex; align-items: center; gap: 5px; font-size: 0.74rem; color: var(--text-xlight); margin-bottom: 2px; }
        .page-breadcrumb .bc-icon { color: var(--blue); font-size: 0.65rem; }
        .page-breadcrumb .bc-sep  { font-size: 0.55rem; }
        .page-breadcrumb .bc-cur  { color: var(--text-mid); font-weight: 600; }
        .topbar h1 { font-size: 1.25rem; font-weight: 800; letter-spacing: -0.02em; }
        .topbar-right { display: flex; align-items: center; gap: 10px; }
        .tb-date { display: flex; align-items: center; gap: 7px; background: var(--white); padding: 7px 14px; border-radius: 40px; font-size: 0.8rem; font-weight: 500; color: var(--text-mid); border: 1.5px solid var(--border); }
        .tb-date i { color: var(--blue); }
        .user-chip { display: flex; align-items: center; gap: 9px; background: var(--white); border: 1.5px solid var(--border); padding: 5px 14px 5px 5px; border-radius: 40px; cursor: pointer; transition: all 0.18s var(--ease); }
        .user-chip:hover { border-color: var(--blue-border); background: var(--blue-light); }
        .uc-avatar { width: 30px; height: 30px; background: linear-gradient(135deg, var(--blue-dark), var(--blue-mid)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 0.78rem; box-shadow: 0 2px 8px rgba(29,78,216,0.3); }
        .uc-name { font-size: 0.82rem; font-weight: 600; }
        .uc-chev { font-size: 0.58rem; color: var(--text-xlight); }

        /* ── CONTENT ── */
        .content { padding: 26px 32px; }
        .settings-header { margin-bottom: 24px; animation: fadeUp 0.42s ease both; }
        .settings-header h2 { font-size: 1.5rem; font-weight: 700; color: var(--text-dark); margin-bottom: 6px; }
        .settings-header p  { color: var(--text-light); font-size: 0.9rem; }

        /* ── STATS GRID ── */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 24px; animation: fadeUp 0.42s 0.05s ease both; }
        .stat-card { background: var(--white); border: 1.5px solid var(--border); border-radius: var(--r-md); padding: 16px; }
        .stat-card .stat-label { font-size: 0.75rem; color: var(--text-xlight); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
        .stat-card .stat-value { font-size: 1.6rem; font-weight: 700; color: var(--text-dark); line-height: 1.2; }
        .stat-card .stat-unit  { font-size: 0.8rem; color: var(--text-light); margin-left: 4px; }

        /* ── SETTINGS NAV ── */
        .settings-nav { display: flex; gap: 8px; margin-bottom: 24px; flex-wrap: wrap; border-bottom: 1.5px solid var(--border); padding-bottom: 12px; animation: fadeUp 0.42s 0.1s ease both; }
        .settings-nav-item { padding: 8px 18px; border-radius: 40px; font-size: 0.85rem; font-weight: 600; cursor: pointer; transition: all 0.18s var(--ease); color: var(--text-mid); background: transparent; border: 1.5px solid transparent; }
        .settings-nav-item:hover { background: var(--blue-light); border-color: var(--blue-border); color: var(--blue); }
        .settings-nav-item.active { background: var(--blue); color: white; border-color: var(--blue); }

        /* ── SETTINGS CARD ── */
        .settings-card { background: var(--white); border: 1.5px solid var(--border); border-radius: var(--r-md); margin-bottom: 24px; overflow: hidden; animation: fadeUp 0.42s 0.15s ease both; }
        .settings-card-header { padding: 18px 24px; border-bottom: 1.5px solid var(--border); background: var(--blue-light); display: flex; align-items: center; gap: 12px; }
        .settings-card-header i { font-size: 1.2rem; color: var(--blue); width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; background: var(--white); border-radius: 8px; border: 1.5px solid var(--blue-border); }
        .settings-card-header h3 { font-size: 1rem; font-weight: 700; color: var(--text-dark); }
        .settings-card-body { padding: 24px; }

        /* ── FORM ── */
        .form-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 18px; }
        .form-group { margin-bottom: 18px; }
        .form-group.full-width { grid-column: span 2; }
        .form-label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-mid); margin-bottom: 6px; }
        .form-control { width: 100%; padding: 12px 14px; font-size: 0.9rem; font-family: 'Plus Jakarta Sans', sans-serif; border: 1.5px solid var(--border); border-radius: var(--r-sm); background: var(--white); transition: all 0.18s var(--ease); color: var(--text-dark); }
        .form-control:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-soft); }
        .form-control[readonly] { background: var(--bg); color: var(--text-light); cursor: not-allowed; }
        .form-select { width: 100%; padding: 12px 14px; font-size: 0.9rem; font-family: 'Plus Jakarta Sans', sans-serif; border: 1.5px solid var(--border); border-radius: var(--r-sm); background: var(--white); cursor: pointer; }
        .form-check { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
        .form-check-input { width: 18px; height: 18px; cursor: pointer; accent-color: var(--blue); }
        .form-check-label { font-size: 0.9rem; color: var(--text-mid); cursor: pointer; }
        .form-text { font-size: 0.75rem; color: var(--text-xlight); margin-top: 4px; }

        /* ── BUTTONS ── */
        .btn { padding: 12px 24px; border-radius: 40px; font-size: 0.85rem; font-weight: 600; border: 1.5px solid transparent; cursor: pointer; transition: all 0.18s var(--ease); display: inline-flex; align-items: center; gap: 8px; font-family: 'Plus Jakarta Sans', sans-serif; }
        .btn-primary { background: var(--blue); color: white; }
        .btn-primary:hover { background: var(--blue-dark); transform: translateY(-1px); box-shadow: var(--shadow-md); }
        .btn-secondary { background: var(--bg); color: var(--text-mid); border-color: var(--border); }
        .btn-secondary:hover { background: var(--blue-light); border-color: var(--blue-border); color: var(--blue); }
        .btn-danger { background: var(--rose); color: white; }
        .btn-danger:hover { background: #b91c1c; }
        .btn-success { background: var(--emerald); color: white; }
        .btn-success:hover { background: #047857; }
        .btn-sm { padding: 8px 16px; font-size: 0.75rem; }

        /* ── QUICK TOGGLE ── */
        .quick-toggle { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding: 15px; border-radius: var(--r-md); border-left: 4px solid; }
        .quick-toggle.enabled { background: var(--emerald-light); border-left-color: var(--emerald); }
        .quick-toggle.disabled { background: var(--rose-light); border-left-color: var(--rose); }
        .toggle-status { font-size: 1rem; font-weight: 700; }
        .toggle-status i { margin-right: 8px; }
        .toggle-status.enabled { color: var(--emerald); }
        .toggle-status.disabled { color: var(--rose); }
        .toggle-description { font-size: 0.8rem; margin-top: 5px; color: var(--text-light); }

        /* ── STATS BOX ── */
        .stats-box { background: var(--blue-light); padding: 16px; border-radius: var(--r-md); margin: 20px 0; }
        .stats-grid-mini { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .stat-mini { text-align: center; }
        .stat-mini-label { font-size: 0.7rem; color: var(--text-light); }
        .stat-mini-value { font-size: 1.2rem; font-weight: 700; color: var(--text-dark); }

        /* ── MOBILE ── */
        .mobile-bar { display: none; align-items: center; justify-content: space-between; background: var(--white); padding: 12px 16px; border-bottom: 1.5px solid var(--border); position: sticky; top: 0; z-index: 200; }
        .mob-btn { width: 38px; height: 38px; background: var(--bg); border: 1.5px solid var(--border); border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.05rem; color: var(--text-mid); }
        .mob-logo { font-size: 0.93rem; font-weight: 800; color: var(--blue); }
        .mob-av   { width: 36px; height: 36px; background: linear-gradient(135deg, var(--blue-dark), var(--blue-mid)); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-weight: 700; font-size: 0.8rem; }
        .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(15,23,42,0.45); z-index: 999; opacity: 0; transition: opacity 0.3s; }
        .sidebar-overlay.show { opacity: 1; }

        /* ════════════════════════════════════════
           TOAST NOTIFICATIONS
        ════════════════════════════════════════ */
        #toast-container {
            position: fixed; top: 20px; right: 20px; z-index: 99999;
            display: flex; flex-direction: column; gap: 10px;
            pointer-events: none;
        }
        .toast {
            display: flex; align-items: flex-start; gap: 14px;
            min-width: 320px; max-width: 420px;
            padding: 16px 18px;
            border-radius: 16px;
            box-shadow: 0 12px 40px rgba(0,0,0,0.18), 0 2px 8px rgba(0,0,0,0.08);
            pointer-events: all; cursor: pointer;
            position: relative; overflow: hidden;
            animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1) both;
            border: 1px solid rgba(255,255,255,0.2);
        }
        .toast.hiding { animation: toastOut 0.3s ease forwards; }
        @keyframes toastIn {
            from { opacity: 0; transform: translateX(60px) scale(0.88); }
            to   { opacity: 1; transform: translateX(0) scale(1); }
        }
        @keyframes toastOut {
            from { opacity: 1; transform: translateX(0) scale(1); max-height: 120px; }
            to   { opacity: 0; transform: translateX(60px) scale(0.9); max-height: 0; padding: 0; margin: 0; }
        }
        /* progress bar */
        .toast-progress {
            position: absolute; bottom: 0; left: 0;
            height: 3px; border-radius: 0 0 16px 16px;
            animation: toastProg var(--dur, 4.5s) linear forwards;
        }
        @keyframes toastProg { from { width: 100%; } to { width: 0%; } }

        .toast-success { background: linear-gradient(135deg, #065f46 0%, #059669 100%); color: #ecfdf5; }
        .toast-success .toast-progress { background: rgba(167,243,208,0.7); }

        .toast-error { background: linear-gradient(135deg, #9f1239 0%, #e11d48 100%); color: #fff1f2; }
        .toast-error .toast-progress { background: rgba(253,164,175,0.7); }

        .toast-info { background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 100%); color: #eff6ff; }
        .toast-info .toast-progress { background: rgba(147,197,253,0.7); }

        .toast-warning { background: linear-gradient(135deg, #92400e 0%, #d97706 100%); color: #fffbeb; }
        .toast-warning .toast-progress { background: rgba(252,211,77,0.7); }

        .toast-icon-wrap {
            width: 38px; height: 38px; border-radius: 50%;
            background: rgba(255,255,255,0.18);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; flex-shrink: 0;
        }
        .toast-body { flex: 1; min-width: 0; }
        .toast-title { font-size: 0.84rem; font-weight: 700; margin-bottom: 3px; opacity: 0.95; }
        .toast-msg   { font-size: 0.79rem; opacity: 0.85; line-height: 1.45; }
        .toast-x {
            background: rgba(255,255,255,0.15); border: none; color: inherit;
            width: 26px; height: 26px; border-radius: 7px;
            cursor: pointer; display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; opacity: 0.7; flex-shrink: 0;
            transition: opacity 0.15s, background 0.15s;
            font-family: inherit;
        }
        .toast-x:hover { opacity: 1; background: rgba(255,255,255,0.28); }

        /* ════════════════════════════════════════
           ACTIVITY LOG MODAL
        ════════════════════════════════════════ */
        .log-backdrop {
            position: fixed; inset: 0;
            background: rgba(15,23,42,0.55);
            backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px);
            z-index: 9000;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; pointer-events: none;
            transition: opacity 0.25s ease;
        }
        .log-backdrop.open { opacity: 1; pointer-events: all; }

        .log-modal {
            background: var(--white);
            border-radius: 22px;
            box-shadow: 0 32px 80px rgba(29,78,216,0.22), 0 8px 24px rgba(0,0,0,0.1);
            width: 90%; max-width: 800px; max-height: 84vh;
            display: flex; flex-direction: column;
            transform: translateY(40px) scale(0.96);
            transition: transform 0.32s cubic-bezier(0.34,1.56,0.64,1);
            overflow: hidden;
            border: 1.5px solid var(--border);
        }
        .log-backdrop.open .log-modal { transform: translateY(0) scale(1); }

        .log-modal-hd {
            padding: 22px 26px 18px;
            background: linear-gradient(135deg, var(--blue-darker) 0%, #1e40af 100%);
            color: white;
            display: flex; align-items: center; justify-content: space-between;
            flex-shrink: 0;
        }
        .log-modal-hd-left { display: flex; align-items: center; gap: 14px; }
        .log-hd-icon {
            width: 44px; height: 44px;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.2rem;
        }
        .log-modal-hd h3 { font-size: 1.1rem; font-weight: 800; margin-bottom: 2px; }
        .log-modal-hd p  { font-size: 0.74rem; opacity: 0.65; }
        .log-close-btn {
            width: 36px; height: 36px;
            background: rgba(255,255,255,0.12);
            border: 1.5px solid rgba(255,255,255,0.2);
            border-radius: 10px; color: white; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; transition: background 0.18s;
            font-family: inherit;
        }
        .log-close-btn:hover { background: rgba(255,255,255,0.26); }

        .log-toolbar {
            padding: 13px 22px;
            border-bottom: 1.5px solid var(--border);
            background: var(--bg);
            display: flex; align-items: center; gap: 8px;
            flex-wrap: wrap; flex-shrink: 0;
        }
        .log-search-wrap { position: relative; flex: 1; min-width: 160px; }
        .log-search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-xlight); font-size: 0.78rem; pointer-events: none; }
        .log-search { width: 100%; padding: 8px 14px 8px 34px; border: 1.5px solid var(--border); border-radius: 40px; font-size: 0.82rem; font-family: 'Plus Jakarta Sans', sans-serif; background: var(--white); transition: border-color 0.18s, box-shadow 0.18s; color: var(--text-dark); }
        .log-search:focus { outline: none; border-color: var(--blue); box-shadow: 0 0 0 3px var(--blue-soft); }
        .log-filter {
            padding: 7px 15px; border-radius: 40px; font-size: 0.76rem; font-weight: 600;
            border: 1.5px solid var(--border); background: var(--white); color: var(--text-mid);
            cursor: pointer; transition: all 0.15s; font-family: 'Plus Jakarta Sans', sans-serif;
        }
        .log-filter:hover, .log-filter.active { background: var(--blue-light); border-color: var(--blue-border); color: var(--blue); }
        .log-filter.active { font-weight: 700; }

        .log-body { flex: 1; overflow-y: auto; }

        .log-entry {
            display: flex; align-items: flex-start; gap: 14px;
            padding: 14px 22px;
            border-bottom: 1px solid var(--bg-mid);
            transition: background 0.14s;
        }
        .log-entry:hover { background: var(--bg); }
        .log-entry:last-child { border-bottom: none; }
        .log-entry[style*="display: none"] { display: none !important; }

        .log-e-icon {
            width: 36px; height: 36px; border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.85rem; flex-shrink: 0; margin-top: 1px;
        }
        .le-toggle   { background: var(--amber-light);   color: var(--amber); }
        .le-settings { background: var(--blue-light);    color: var(--blue); }
        .le-profile  { background: var(--violet-light);  color: var(--violet); }
        .le-backup   { background: var(--emerald-light); color: var(--emerald); }
        .le-cache    { background: var(--rose-light);    color: var(--rose); }
        .le-default  { background: var(--bg-mid);        color: var(--text-light); }

        .log-e-body { flex: 1; min-width: 0; }
        .log-e-action { font-size: 0.84rem; font-weight: 700; color: var(--text-dark); margin-bottom: 3px; }
        .log-e-desc { font-size: 0.76rem; color: var(--text-light); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 6px; }
        .log-e-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
        .log-chip {
            display: inline-flex; align-items: center; gap: 4px;
            background: var(--bg); padding: 2px 8px;
            border-radius: 40px; font-size: 0.67rem; color: var(--text-xlight);
            border: 1px solid var(--border);
        }
        .log-e-time { font-size: 0.71rem; color: var(--text-xlight); white-space: nowrap; flex-shrink: 0; padding-top: 3px; }

        .log-empty-state { padding: 52px 24px; text-align: center; color: var(--text-light); }
        .log-empty-state i { font-size: 2.8rem; opacity: 0.18; display: block; margin-bottom: 12px; }

        .log-footer {
            padding: 13px 22px;
            border-top: 1.5px solid var(--border);
            background: var(--bg);
            display: flex; align-items: center; justify-content: space-between;
            font-size: 0.78rem; color: var(--text-xlight);
            flex-shrink: 0;
        }
        .log-live-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: var(--emerald-light); color: var(--emerald);
            padding: 4px 12px; border-radius: 40px; font-size: 0.72rem; font-weight: 700;
            border: 1px solid rgba(5,150,105,0.25);
        }
        .log-live-dot {
            width: 7px; height: 7px; background: var(--emerald);
            border-radius: 50%;
            animation: logPulse 1.5s ease infinite;
        }
        @keyframes logPulse {
            0%,100% { opacity: 1; transform: scale(1); }
            50%      { opacity: 0.5; transform: scale(1.3); }
        }

        /* ── LOG TAB (inline preview) ── */
        .open-log-btn {
            display: inline-flex; align-items: center; gap: 9px;
            padding: 12px 24px;
            background: linear-gradient(135deg, var(--blue-darker), var(--blue-mid));
            color: white; border-radius: 40px; font-size: 0.85rem; font-weight: 700;
            border: none; cursor: pointer;
            box-shadow: 0 4px 18px rgba(29,78,216,0.32);
            transition: all 0.18s var(--ease);
            font-family: 'Plus Jakarta Sans', sans-serif;
            margin-bottom: 22px;
        }
        .open-log-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(29,78,216,0.4); }

        .log-preview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 12px;
        }
        .log-preview-card {
            background: var(--white);
            border: 1.5px solid var(--border);
            border-radius: var(--r-md);
            padding: 14px 16px;
            display: flex; align-items: flex-start; gap: 12px;
            transition: border-color 0.18s, box-shadow 0.18s;
            cursor: pointer;
        }
        .log-preview-card:hover { border-color: var(--blue-border); box-shadow: var(--shadow-md); }
        .lpc-action { font-size: 0.8rem; font-weight: 700; color: var(--text-dark); }
        .lpc-time   { font-size: 0.69rem; color: var(--text-xlight); margin-top: 3px; }

        /* ── RESPONSIVE ── */
        @media (max-width: 992px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-grid  { grid-template-columns: 1fr; }
            .form-group.full-width { grid-column: span 1; }
        }
        @media (max-width: 768px) {
            .main { margin-left: 0; width: 100%; }
            .mobile-bar { display: flex; }
            .topbar { display: none; }
            .content { padding: 18px 16px; }
            .sidebar-overlay { display: block; pointer-events: none; }
            .sidebar-overlay.show { pointer-events: auto; }
            .log-modal { width: 96%; max-height: 88vh; }
        }
        @media (max-width: 576px) {
            .stats-grid { grid-template-columns: 1fr; }
            .stats-grid-mini { grid-template-columns: 1fr; }
            .toast { min-width: 280px; }
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<!-- Inject PHP messages for JS -->
<script>
window.__TOAST__ = <?php
    if ($success_message) echo json_encode(['type'=>'success','msg'=>$success_message]);
    elseif ($error_message) echo json_encode(['type'=>'error','msg'=>$error_message]);
    else echo 'null';
?>;
</script>

<div class="app">
    <div class="sidebar-overlay" id="overlay"></div>
    <?php include 'includes/sidebar.php'; ?>

    <main class="main">

        <!-- Mobile topbar -->
        <div class="mobile-bar">
            <button class="mob-btn" id="mobToggle"><i class="fas fa-bars"></i></button>
            <span class="mob-logo"><i class="fas fa-cog"></i> Settings</span>
            <div class="mob-av"><?php echo strtoupper(substr($admin['full_name'] ?? 'A', 0, 1)); ?></div>
        </div>

        <!-- Desktop topbar -->
        <div class="topbar">
            <div>
                <div class="page-breadcrumb">
                    <i class="fas fa-home bc-icon"></i>
                    <i class="fas fa-chevron-right bc-sep"></i>
                    <i class="fas fa-cog bc-icon"></i>
                    <i class="fas fa-chevron-right bc-sep"></i>
                    <span class="bc-cur">System Settings</span>
                </div>
                <h1>Settings</h1>
            </div>
            <div class="topbar-right">
                <div class="tb-date"><i class="fas fa-calendar-alt"></i><?php echo date('D, M j, Y'); ?></div>
                <div class="user-chip">
                    <div class="uc-avatar"><?php echo strtoupper(substr($admin['full_name'] ?? 'A', 0, 1)); ?></div>
                    <span class="uc-name"><?php echo htmlspecialchars(explode(' ', $admin['full_name'] ?? 'Admin')[0]); ?></span>
                    <i class="fas fa-chevron-down uc-chev"></i>
                </div>
            </div>
        </div>

        <div class="content">

            <div class="settings-header">
                <h2>System Configuration</h2>
                <p>Manage your ALS system settings, profile, and preferences</p>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Database Size</div>
                    <div class="stat-value"><?php echo number_format($dbSize, 2); ?><span class="stat-unit">MB</span></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Total Admins</div>
                    <div class="stat-value"><?php echo $totalUsers; ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Students</div>
                    <div class="stat-value"><?php echo number_format($totalStudents); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active Teachers</div>
                    <div class="stat-value"><?php echo number_format($totalTeachers); ?></div>
                </div>
            </div>

            <!-- Nav tabs -->
            <div class="settings-nav" id="settingsNav">
                <span class="settings-nav-item active" data-tab="general"><i class="fas fa-cog"></i> General</span>
                <span class="settings-nav-item" data-tab="profile"><i class="fas fa-user"></i> Profile</span>
                <span class="settings-nav-item" data-tab="security"><i class="fas fa-shield-alt"></i> Security</span>
                <span class="settings-nav-item" data-tab="system"><i class="fas fa-server"></i> System</span>
                <span class="settings-nav-item" data-tab="preregistration"><i class="fas fa-user-plus"></i> Pre-registration</span>
                <span class="settings-nav-item" data-tab="logs"><i class="fas fa-history"></i> Activity Logs</span>
            </div>

            <!-- ── GENERAL ── -->
            <div class="settings-tab" id="tab-general">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-globe"></i>
                        <h3>General System Settings</h3>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST">
                            <input type="hidden" name="update_system_settings" value="1">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Site Name</label>
                                    <input type="text" class="form-control" name="site_name" value="<?php echo htmlspecialchars($settings['site_name']); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Admin Email</label>
                                    <input type="email" class="form-control" name="admin_email" value="<?php echo htmlspecialchars($settings['admin_email']); ?>" required>
                                </div>
                                <div class="form-group full-width">
                                    <label class="form-label">Site Description</label>
                                    <textarea class="form-control" name="site_description" rows="3"><?php echo htmlspecialchars($settings['site_description']); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Items Per Page</label>
                                    <select class="form-select" name="items_per_page">
                                        <?php foreach ([5,10,25,50,100] as $n): ?>
                                        <option value="<?php echo $n; ?>" <?php echo $settings['items_per_page'] == $n ? 'selected' : ''; ?>><?php echo $n; ?> items</option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Session Timeout (minutes)</label>
                                    <input type="number" class="form-control" name="session_timeout" value="<?php echo $settings['session_timeout']; ?>" min="5" max="480">
                                </div>
                            </div>
                            <div class="form-grid" style="margin-top: 12px;">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="enable_registration" id="enable_registration" <?php echo $settings['enable_registration'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="enable_registration">Enable Public Registration</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="allow_teacher_reg" id="allow_teacher_reg" <?php echo $settings['allow_teacher_reg'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="allow_teacher_reg">Allow Teacher Registration</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" name="maintenance_mode" id="maintenance_mode" <?php echo $settings['maintenance_mode'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="maintenance_mode">Maintenance Mode</label>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top: 16px;">
                                <i class="fas fa-save"></i> Save General Settings
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ── PROFILE ── -->
            <div class="settings-tab" id="tab-profile" style="display:none;">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-user-circle"></i>
                        <h3>Admin Profile</h3>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST">
                            <input type="hidden" name="update_profile" value="1">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Full Name</label>
                                    <input type="text" class="form-control" name="full_name" value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Email Address</label>
                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Username</label>
                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>" readonly>
                                    <div class="form-text">Username cannot be changed</div>
                                </div>
                            </div>
                            <hr style="margin: 22px 0; border-color: var(--border);">
                            <h4 style="font-size: 0.95rem; margin-bottom: 16px;">Change Password</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Current Password</label>
                                    <input type="password" class="form-control" name="current_password">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">New Password</label>
                                    <input type="password" class="form-control" name="new_password">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" name="confirm_password">
                                </div>
                            </div>
                            <div class="form-text" style="margin-bottom: 16px;">Leave blank to keep current password</div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-user-edit"></i> Update Profile</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ── SECURITY ── -->
            <div class="settings-tab" id="tab-security" style="display:none;">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-shield-alt"></i>
                        <h3>Security Settings</h3>
                    </div>
                    <div class="settings-card-body">
                        <form method="POST">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="two_factor_auth" checked>
                                <label class="form-check-label" for="two_factor_auth">Enable Two-Factor Authentication</label>
                            </div>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="login_notifications" checked>
                                <label class="form-check-label" for="login_notifications">Email on New Login</label>
                            </div>
                            <div class="form-group" style="margin-top: 16px; max-width: 280px;">
                                <label class="form-label">Password Expiry (days)</label>
                                <input type="number" class="form-control" value="90" min="0" max="365">
                                <div class="form-text">0 = never expires</div>
                            </div>
                            <button type="submit" class="btn btn-primary" style="margin-top: 12px;"><i class="fas fa-shield-alt"></i> Save Security Settings</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ── SYSTEM ── -->
            <div class="settings-tab" id="tab-system" style="display:none;">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-server"></i>
                        <h3>System Maintenance</h3>
                    </div>
                    <div class="settings-card-body">
                        <div class="form-group" style="max-width: 280px;">
                            <label class="form-label">System Version</label>
                            <input type="text" class="form-control" value="<?php echo $settings['system_version']; ?>" readonly>
                        </div>
                        <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-top: 24px;">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="backup_database" value="1">
                                <button type="submit" class="btn btn-secondary"><i class="fas fa-database"></i> Backup Database</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="clear_cache" value="1">
                                <button type="submit" class="btn btn-secondary"><i class="fas fa-broom"></i> Clear Cache</button>
                            </form>
                            <button class="btn btn-danger" onclick="return confirm('Are you sure you want to clear all logs?')">
                                <i class="fas fa-trash-alt"></i> Clear Logs
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── PRE-REGISTRATION ── -->
            <div class="settings-tab" id="tab-preregistration" style="display:none;">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-user-plus"></i>
                        <h3>Pre-registration Settings</h3>
                    </div>
                    <div class="settings-card-body">
                        <?php $pr_enabled = ($pr_settings['preregistration_enabled'] ?? 1); ?>
                        <div class="quick-toggle <?php echo $pr_enabled ? 'enabled' : 'disabled'; ?>">
                            <div>
                                <div class="toggle-status <?php echo $pr_enabled ? 'enabled' : 'disabled'; ?>">
                                    <i class="fas fa-<?php echo $pr_enabled ? 'check-circle' : 'times-circle'; ?>"></i>
                                    Pre-registration is currently <strong><?php echo $pr_enabled ? 'ENABLED' : 'DISABLED'; ?></strong>
                                </div>
                                <div class="toggle-description">
                                    <?php echo $pr_enabled ? 'Students can submit pre-registration forms.' : 'Pre-registration is turned off.'; ?>
                                </div>
                            </div>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Are you sure?')">
                                <input type="hidden" name="toggle_preregistration" value="1">
                                <input type="hidden" name="new_status" value="<?php echo $pr_enabled ? '0' : '1'; ?>">
                                <button type="submit" class="btn btn-<?php echo $pr_enabled ? 'danger' : 'success'; ?> btn-sm">
                                    <i class="fas fa-<?php echo $pr_enabled ? 'times' : 'check'; ?>"></i>
                                    <?php echo $pr_enabled ? 'Disable Now' : 'Enable Now'; ?>
                                </button>
                            </form>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="update_preregistration_settings" value="1">
                            <div class="form-grid">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="preregistration_enabled" id="preregistration_enabled" <?php echo $pr_enabled ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="preregistration_enabled">Enable Pre-registration</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="preregistration_auto_approve" id="preregistration_auto_approve" <?php echo ($pr_settings['preregistration_auto_approve'] ?? 0) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="preregistration_auto_approve">Auto-approve Pre-registrations</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label">Start Date</label>
                                    <input type="datetime-local" class="form-control" name="preregistration_start_date" value="<?php echo htmlspecialchars($pr_settings['preregistration_start_date'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">End Date</label>
                                    <input type="datetime-local" class="form-control" name="preregistration_end_date" value="<?php echo htmlspecialchars($pr_settings['preregistration_end_date'] ?? ''); ?>">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Total Registration Limit</label>
                                    <input type="number" class="form-control" name="preregistration_limit" value="<?php echo htmlspecialchars($pr_settings['preregistration_limit'] ?? 100); ?>" min="1" max="10000">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Daily Registration Limit</label>
                                    <input type="number" class="form-control" name="preregistration_daily_limit" value="<?php echo htmlspecialchars($pr_settings['preregistration_daily_limit'] ?? 20); ?>" min="1" max="500">
                                </div>
                            </div>
                            <h4 style="font-size:0.95rem;margin:18px 0 12px;">Verification Settings</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="preregistration_require_human_verification" id="require_human" <?php echo ($pr_settings['preregistration_require_human_verification'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_human">Require Human Verification</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="preregistration_require_email_verification" id="require_email" <?php echo ($pr_settings['preregistration_require_email_verification'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="require_email">Require Email Verification</label>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" name="preregistration_notify_admin" id="notify_admin" <?php echo ($pr_settings['preregistration_notify_admin'] ?? 1) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="notify_admin">Notify Admin on New Submission</label>
                                    </div>
                                </div>
                            </div>
                            <div class="form-group" style="margin-top:16px;">
                                <label class="form-label">Pre-registration Message</label>
                                <textarea class="form-control" name="preregistration_message" rows="3"><?php echo htmlspecialchars($pr_settings['preregistration_message'] ?? 'Please complete the pre-registration form to begin your enrollment process.'); ?></textarea>
                            </div>

                            <div class="stats-box">
                                <h4 style="font-size:0.9rem;margin-bottom:10px;">Current Pre-registration Statistics</h4>
                                <div class="stats-grid-mini">
                                    <div class="stat-mini"><div class="stat-mini-label">Total</div><div class="stat-mini-value"><?php echo $total_pr_count; ?></div></div>
                                    <div class="stat-mini"><div class="stat-mini-label">Today</div><div class="stat-mini-value"><?php echo $today_pr_count; ?></div></div>
                                    <div class="stat-mini"><div class="stat-mini-label">Pending</div><div class="stat-mini-value"><?php echo $pending_pr_count; ?></div></div>
                                    <div class="stat-mini"><div class="stat-mini-label">Approved</div><div class="stat-mini-value"><?php echo $approved_pr_count; ?></div></div>
                                    <div class="stat-mini"><div class="stat-mini-label">Limit</div><div class="stat-mini-value"><?php echo $pr_settings['preregistration_limit'] ?? 100; ?></div></div>
                                    <div class="stat-mini"><div class="stat-mini-label">Daily Limit</div><div class="stat-mini-value"><?php echo $pr_settings['preregistration_daily_limit'] ?? 20; ?></div></div>
                                </div>
                            </div>
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Pre-registration Settings</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- ── ACTIVITY LOGS TAB ── -->
            <div class="settings-tab" id="tab-logs" style="display:none;">
                <div class="settings-card">
                    <div class="settings-card-header">
                        <i class="fas fa-history"></i>
                        <h3>Activity Logs</h3>
                    </div>
                    <div class="settings-card-body">
                        <button class="open-log-btn" id="openLogBtn">
                            <i class="fas fa-list-alt"></i> View Full Activity Log
                            <?php if (!empty($logs)): ?>
                            <span style="background:rgba(255,255,255,0.2);padding:2px 9px;border-radius:40px;font-size:0.72rem;"><?php echo count($logs); ?></span>
                            <?php endif; ?>
                        </button>

                        <?php if (!empty($logs)): ?>
                        <p style="font-size:0.78rem;color:var(--text-light);margin-bottom:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Recent events</p>
                        <div class="log-preview-grid">
                            <?php foreach(array_slice($logs, 0, 6) as $log):
                                $a = strtolower($log['action']);
                                if (str_contains($a,'toggle'))       { $ic='fa-toggle-on';  $cl='le-toggle'; }
                                elseif(str_contains($a,'settings'))  { $ic='fa-cog';        $cl='le-settings'; }
                                elseif(str_contains($a,'profile'))   { $ic='fa-user-edit';  $cl='le-profile'; }
                                elseif(str_contains($a,'backup'))    { $ic='fa-database';   $cl='le-backup'; }
                                elseif(str_contains($a,'cache'))     { $ic='fa-broom';      $cl='le-cache'; }
                                else                                  { $ic='fa-history';    $cl='le-default'; }
                                $ts = strtotime($log['created_at']);
                            ?>
                            <div class="log-preview-card" onclick="openLogModal()">
                                <div class="log-e-icon <?php echo $cl; ?>"><i class="fas <?php echo $ic; ?>"></i></div>
                                <div>
                                    <div class="lpc-action"><?php echo htmlspecialchars($log['action']); ?></div>
                                    <div class="lpc-time"><i class="fas fa-clock" style="font-size:0.6rem;"></i> <?php echo human_time_diff_simple($ts); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div style="text-align:center;padding:40px;color:var(--text-light);">
                            <i class="fas fa-history" style="font-size:2.5rem;opacity:0.2;display:block;margin-bottom:10px;"></i>
                            <p>No activity logs found</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /content -->
    </main>
</div><!-- /app -->


<!-- ════════════════════════════════════════════
     TOAST CONTAINER
════════════════════════════════════════════ -->
<div id="toast-container"></div>


<!-- ════════════════════════════════════════════
     ACTIVITY LOG MODAL
════════════════════════════════════════════ -->
<div class="log-backdrop" id="logBackdrop">
    <div class="log-modal">

        <div class="log-modal-hd">
            <div class="log-modal-hd-left">
                <div class="log-hd-icon"><i class="fas fa-shield-alt"></i></div>
                <div>
                    <h3>Activity Log</h3>
                    <p>System events and admin actions</p>
                </div>
            </div>
            <button class="log-close-btn" id="closeLogBtn"><i class="fas fa-times"></i></button>
        </div>

        <div class="log-toolbar">
            <div class="log-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="log-search" id="logSearch" placeholder="Search logs…">
            </div>
            <button class="log-filter active" data-f="all">All</button>
            <button class="log-filter" data-f="toggle">Toggle</button>
            <button class="log-filter" data-f="settings">Settings</button>
            <button class="log-filter" data-f="profile">Profile</button>
            <button class="log-filter" data-f="backup">Backup</button>
        </div>

        <div class="log-body" id="logBody">
            <?php if (!empty($logs)):
                foreach ($logs as $i => $log):
                    $a = strtolower($log['action']);
                    if (str_contains($a,'toggle'))       { $ic='fa-toggle-on';  $cl='le-toggle'; }
                    elseif(str_contains($a,'settings'))  { $ic='fa-cog';        $cl='le-settings'; }
                    elseif(str_contains($a,'profile'))   { $ic='fa-user-edit';  $cl='le-profile'; }
                    elseif(str_contains($a,'backup'))    { $ic='fa-database';   $cl='le-backup'; }
                    elseif(str_contains($a,'cache'))     { $ic='fa-broom';      $cl='le-cache'; }
                    else                                  { $ic='fa-history';    $cl='le-default'; }
                    $ts     = strtotime($log['created_at']);
                    $t_rel  = human_time_diff_simple($ts);
                    $t_full = date('M d, Y — H:i:s', $ts);
                    $data_action = strtolower($log['action']);
            ?>
            <div class="log-entry"
                 data-action="<?php echo htmlspecialchars($data_action); ?>"
                 data-text="<?php echo htmlspecialchars(strtolower($log['action'] . ' ' . ($log['description'] ?? '') . ' ' . ($log['ip_address'] ?? ''))); ?>"
                 style="animation-delay:<?php echo $i * 0.03; ?>s">
                <div class="log-e-icon <?php echo $cl; ?>">
                    <i class="fas <?php echo $ic; ?>"></i>
                </div>
                <div class="log-e-body">
                    <div class="log-e-action"><?php echo htmlspecialchars($log['action']); ?></div>
                    <div class="log-e-desc"><?php echo htmlspecialchars($log['description'] ?? '—'); ?></div>
                    <div class="log-e-chips">
                        <span class="log-chip"><i class="fas fa-user-shield"></i> <?php echo ucfirst($log['user_type']); ?> #<?php echo $log['user_id']; ?></span>
                        <?php if (!empty($log['ip_address'])): ?>
                        <span class="log-chip"><i class="fas fa-network-wired"></i> <?php echo htmlspecialchars($log['ip_address']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="log-e-time" title="<?php echo $t_full; ?>"><?php echo $t_rel; ?></div>
            </div>
            <?php endforeach;
            else: ?>
            <div class="log-empty-state">
                <i class="fas fa-clipboard-list"></i>
                <p>No activity logs recorded yet.</p>
            </div>
            <?php endif; ?>
        </div>

        <div class="log-footer">
            <span id="logCount">Showing <strong><?php echo count($logs); ?></strong> event<?php echo count($logs) !== 1 ? 's' : ''; ?></span>
            <span class="log-live-badge">
                <span class="log-live-dot"></span> Live
            </span>
        </div>
    </div>
</div>


<script>
/* ════════════════════════════════════════
   TOAST ENGINE
════════════════════════════════════════ */
const TOAST_CFG = {
    success: { title: 'Success',  icon: 'fa-check-circle' },
    error:   { title: 'Error',    icon: 'fa-exclamation-circle' },
    info:    { title: 'Notice',   icon: 'fa-info-circle' },
    warning: { title: 'Warning',  icon: 'fa-exclamation-triangle' }
};

function showToast(type, msg, dur = 5000) {
    const c = document.getElementById('toast-container');
    const cfg = TOAST_CFG[type] || TOAST_CFG.info;
    const t = document.createElement('div');
    t.className = `toast toast-${type}`;
    t.style.setProperty('--dur', dur + 'ms');
    t.innerHTML = `
        <div class="toast-icon-wrap"><i class="fas ${cfg.icon}"></i></div>
        <div class="toast-body">
            <div class="toast-title">${cfg.title}</div>
            <div class="toast-msg">${msg}</div>
        </div>
        <button class="toast-x" aria-label="Dismiss"><i class="fas fa-times"></i></button>
        <div class="toast-progress"></div>`;
    c.appendChild(t);

    const dismiss = () => {
        t.classList.add('hiding');
        t.addEventListener('animationend', () => t.remove(), { once: true });
    };
    t.querySelector('.toast-x').addEventListener('click', e => { e.stopPropagation(); dismiss(); });
    t.addEventListener('click', dismiss);
    setTimeout(dismiss, dur);
}

// Fire PHP-generated toast on page load
if (window.__TOAST__) {
    requestAnimationFrame(() => setTimeout(() => {
        showToast(window.__TOAST__.type, window.__TOAST__.msg);
    }, 300));
}


/* ════════════════════════════════════════
   ACTIVITY LOG MODAL
════════════════════════════════════════ */
const backdrop   = document.getElementById('logBackdrop');
const openLogBtn = document.getElementById('openLogBtn');
const closeLogBtn= document.getElementById('closeLogBtn');
const logSearch  = document.getElementById('logSearch');
const logBody    = document.getElementById('logBody');
const logCountEl = document.getElementById('logCount');

function openLogModal() {
    backdrop.classList.add('open');
    document.body.style.overflow = 'hidden';
    logSearch.focus();
}
function closeLogModal() {
    backdrop.classList.remove('open');
    document.body.style.overflow = '';
}

if (openLogBtn) openLogBtn.addEventListener('click', openLogModal);
closeLogBtn.addEventListener('click', closeLogModal);
backdrop.addEventListener('click', e => { if (e.target === backdrop) closeLogModal(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLogModal(); });

// Filter state
let currentFilter = 'all';
let currentSearch = '';

document.querySelectorAll('.log-filter').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.log-filter').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = btn.dataset.f;
        applyFilters();
    });
});

logSearch.addEventListener('input', () => {
    currentSearch = logSearch.value.trim().toLowerCase();
    applyFilters();
});

function applyFilters() {
    const entries = logBody.querySelectorAll('.log-entry');
    let visible = 0;

    entries.forEach(e => {
        const action = e.dataset.action || '';
        const text   = e.dataset.text   || '';
        const matchF = currentFilter === 'all' || action.includes(currentFilter);
        const matchS = !currentSearch || text.includes(currentSearch);

        if (matchF && matchS) { e.style.display = ''; visible++; }
        else                   { e.style.display = 'none'; }
    });

    // empty state for filter
    let emEl = logBody.querySelector('.filter-empty');
    if (visible === 0 && entries.length > 0) {
        if (!emEl) {
            emEl = document.createElement('div');
            emEl.className = 'log-empty-state filter-empty';
            emEl.innerHTML = '<i class="fas fa-search"></i><p>No matching log entries found.</p>';
            logBody.appendChild(emEl);
        }
        emEl.style.display = '';
    } else if (emEl) {
        emEl.style.display = 'none';
    }

    logCountEl.innerHTML = `Showing <strong>${visible}</strong> event${visible !== 1 ? 's' : ''}`;
}


/* ════════════════════════════════════════
   SIDEBAR (mobile)
════════════════════════════════════════ */
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
    document.querySelectorAll('.sidebar a').forEach(a => a.addEventListener('click', () => {
        sidebar.classList.remove('open');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
    }));
}


/* ════════════════════════════════════════
   SETTINGS TAB SWITCHING
════════════════════════════════════════ */
const navItems = document.querySelectorAll('.settings-nav-item');
const tabs     = document.querySelectorAll('.settings-tab');

navItems.forEach(item => {
    item.addEventListener('click', () => {
        navItems.forEach(n => n.classList.remove('active'));
        item.classList.add('active');
        tabs.forEach(t => t.style.display = 'none');
        const tab = document.getElementById('tab-' + item.dataset.tab);
        if (tab) tab.style.display = 'block';
    });
});

if (window.location.hash) {
    const h = window.location.hash.substring(1);
    const n = document.querySelector(`.settings-nav-item[data-tab="${h}"]`);
    if (n) n.click();
}
</script>

<?php include 'includes/footer.php'; ?>