<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/header.php';

secure_session_start();

// Check if user is logged in as admin
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Create system_logs table if it doesn't exist
$check_table = $conn->query("SHOW TABLES LIKE 'system_logs'");
if ($check_table->num_rows == 0) {
    $create_table = "
        CREATE TABLE `system_logs` (
            `log_id` INT(11) NOT NULL AUTO_INCREMENT,
            `user_id` INT(11) NOT NULL,
            `user_type` ENUM('admin', 'teacher') NOT NULL,
            `action` VARCHAR(255) NOT NULL,
            `description` TEXT DEFAULT NULL,
            `ip_address` VARCHAR(45) DEFAULT NULL,
            `user_agent` VARCHAR(255) DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`log_id`),
            INDEX `idx_user` (`user_id`, `user_type`),
            INDEX `idx_action` (`action`),
            INDEX `idx_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $conn->query($create_table);
}

// Pagination setup
$limit = 25;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Build filter conditions
$filter_conditions = [];
$filter_params = [];
$filter_types = '';

if (isset($_GET['user_type']) && $_GET['user_type'] !== '') {
    $filter_conditions[] = "l.user_type = ?";
    $filter_params[] = $_GET['user_type'];
    $filter_types .= 's';
}

if (isset($_GET['action']) && $_GET['action'] !== '') {
    $filter_conditions[] = "l.action LIKE ?";
    $filter_params[] = '%' . $_GET['action'] . '%';
    $filter_types .= 's';
}

if (isset($_GET['date_from']) && $_GET['date_from'] !== '') {
    $filter_conditions[] = "DATE(l.created_at) >= ?";
    $filter_params[] = $_GET['date_from'];
    $filter_types .= 's';
}

if (isset($_GET['date_to']) && $_GET['date_to'] !== '') {
    $filter_conditions[] = "DATE(l.created_at) <= ?";
    $filter_params[] = $_GET['date_to'];
    $filter_types .= 's';
}

// Build WHERE clause
$where_clause = '';
if (!empty($filter_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $filter_conditions);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM system_logs l $where_clause";
$count_stmt = $conn->prepare($count_query);

if (!empty($filter_params)) {
    $count_stmt->bind_param($filter_types, ...$filter_params);
}

$count_stmt->execute();
$total_result = $count_stmt->get_result()->fetch_assoc();
$total_logs = $total_result['total'];
$total_pages = ceil($total_logs / $limit);
$count_stmt->close();

// Get logs with filters and pagination
$logs_query = "
    SELECT l.*, 
           CASE 
               WHEN l.user_type = 'admin' THEN a.full_name 
               WHEN l.user_type = 'teacher' THEN t.full_name 
           END as user_name
    FROM system_logs l
    LEFT JOIN admins a ON l.user_type = 'admin' AND l.user_id = a.id
    LEFT JOIN teachers t ON l.user_type = 'teacher' AND l.user_id = t.teacher_id
    $where_clause
    ORDER BY l.created_at DESC
    LIMIT ? OFFSET ?
";

$logs_stmt = $conn->prepare($logs_query);

// Add limit and offset to parameters
$filter_params[] = $limit;
$filter_params[] = $offset;
$filter_types .= 'ii';

if (!empty($filter_params)) {
    $logs_stmt->bind_param($filter_types, ...$filter_params);
}

$logs_stmt->execute();
$logs = $logs_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$logs_stmt->close();

// Get unique actions for filter dropdown
$actions = $conn->query("SELECT DISTINCT action FROM system_logs ORDER BY action")->fetch_all(MYSQLI_ASSOC);

// Get stats
$stats_query = "
    SELECT 
        COUNT(*) as total_logs,
        COUNT(CASE WHEN user_type = 'admin' THEN 1 END) as admin_logs,
        COUNT(CASE WHEN user_type = 'teacher' THEN 1 END) as teacher_logs,
        MAX(created_at) as last_activity
    FROM system_logs
";
$stats = $conn->query($stats_query)->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - ALS System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #f0f4f8;
            --surface: #ffffff;
            --border: #e2e8f0;
            --ink: #0f172a;
            --ink2: #334155;
            --ink3: #64748b;
            --teal: #0d9488;
            --blue: #2563eb;
        }
        
        body {
            background: var(--bg);
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        
        .page-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        
        .stat-icon.primary { background: #dbeafe; color: #2563eb; }
        .stat-icon.success { background: #dcfce7; color: #16a34a; }
        .stat-icon.warning { background: #fed7aa; color: #d97706; }
        .stat-icon.info { background: #cffafe; color: #0891b2; }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1;
        }
        
        .stat-label {
            font-size: 13px;
            color: var(--ink3);
            margin-top: 4px;
        }
        
        .stat-sub {
            font-size: 11px;
            color: var(--ink3);
            margin-top: 6px;
        }
        
        .log-table {
            font-size: 0.9rem;
        }
        
        .log-action {
            font-weight: 500;
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        
        .badge-admin {
            background: #dc3545;
        }
        
        .badge-teacher {
            background: #0d6efd;
        }
        
        .pagination {
            margin-bottom: 0;
        }
        
        .filter-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            margin-bottom: 24px;
            overflow: hidden;
        }
        
        .filter-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
        }
        
        .filter-header h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: var(--ink2);
        }
        
        .filter-body {
            padding: 20px;
        }
        
        .logs-card {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        
        .logs-header {
            padding: 16px 20px;
            background: #f8fafc;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .logs-header h5 {
            margin: 0;
            font-size: 15px;
            font-weight: 600;
            color: var(--ink2);
        }
        
        .badge-count {
            background: #e2e8f0;
            color: var(--ink2);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        table {
            margin-bottom: 0;
        }
        
        th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--ink3);
            border-bottom: 1px solid var(--border);
        }
        
        td {
            vertical-align: middle;
            border-bottom: 1px solid var(--border);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--ink3);
        }
        
        .empty-state i {
            font-size: 48px;
            opacity: 0.3;
            margin-bottom: 16px;
            display: block;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .page-wrap {
                padding: 16px;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">
    
    <!-- Page Header -->
    <div style="margin-bottom: 24px;">
        <h1 style="font-size: 26px; font-weight: 700; color: var(--ink); margin-bottom: 4px;">
            <i class="fas fa-history" style="color: var(--teal); margin-right: 10px;"></i>
            System Activity Logs
        </h1>
        <p style="color: var(--ink3);">Audit trail of all system activities and user actions</p>
    </div>
    
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon primary">
                <i class="fas fa-chart-line"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo number_format($stats['total_logs']); ?></div>
                <div class="stat-label">Total Activities</div>
                <div class="stat-sub">All time records</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon success">
                <i class="fas fa-user-shield"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo number_format($stats['admin_logs']); ?></div>
                <div class="stat-label">Admin Actions</div>
                <div class="stat-sub">Administrator activities</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon warning">
                <i class="fas fa-chalkboard-user"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo number_format($stats['teacher_logs']); ?></div>
                <div class="stat-label">Teacher Actions</div>
                <div class="stat-sub">Teacher activities</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon info">
                <i class="fas fa-clock"></i>
            </div>
            <div>
                <div class="stat-value"><?php echo $stats['last_activity'] ? date('M j, H:i', strtotime($stats['last_activity'])) : 'N/A'; ?></div>
                <div class="stat-label">Last Activity</div>
                <div class="stat-sub">Most recent log entry</div>
            </div>
        </div>
    </div>
    
    <!-- Filter Form -->
    <div class="filter-card">
        <div class="filter-header">
            <h5><i class="fas fa-filter"></i> Filter Logs</h5>
        </div>
        <div class="filter-body">
            <form method="GET" class="row g-3">
                <div class="col-md-3">
                    <label for="user_type" class="form-label">User Type</label>
                    <select class="form-select" id="user_type" name="user_type">
                        <option value="">All Users</option>
                        <option value="admin" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] == 'admin') ? 'selected' : ''; ?>>Admin</option>
                        <option value="teacher" <?php echo (isset($_GET['user_type']) && $_GET['user_type'] == 'teacher') ? 'selected' : ''; ?>>Teacher</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="action" class="form-label">Action</label>
                    <select class="form-select" id="action" name="action">
                        <option value="">All Actions</option>
                        <?php foreach ($actions as $action): ?>
                        <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo (isset($_GET['action']) && $_GET['action'] == $action['action']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($action['action']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo isset($_GET['date_from']) ? $_GET['date_from'] : ''; ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo isset($_GET['date_to']) ? $_GET['date_to'] : ''; ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Apply
                    </button>
                    <a href="system_logs.php" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Logs Table -->
    <div class="logs-card">
        <div class="logs-header">
            <h5><i class="fas fa-list"></i> System Activity Log</h5>
            <span class="badge-count">
                <i class="fas fa-database"></i> <?php echo $total_logs; ?> entries found
            </span>
        </div>
        
        <?php if (count($logs) > 0): ?>
        <div class="table-responsive">
            <table class="table table-hover log-table">
                <thead>
                    <tr>
                        <th style="width: 150px">Date & Time</th>
                        <th style="width: 150px">User</th>
                        <th style="width: 80px">Type</th>
                        <th style="width: 150px">Action</th>
                        <th>Description</th>
                        <th style="width: 130px">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td style="white-space: nowrap;">
                            <i class="far fa-calendar-alt" style="color: var(--ink3); width: 14px; margin-right: 6px;"></i>
                            <?php echo date('M j, Y', strtotime($log['created_at'])); ?>
                            <br><small style="color: var(--ink3);"><?php echo date('H:i:s', strtotime($log['created_at'])); ?></small>
                        </td>
                        <td>
                            <?php if ($log['user_name']): ?>
                            <strong><?php echo htmlspecialchars($log['user_name']); ?></strong>
                            <?php else: ?>
                            <span class="text-muted">User deleted</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?php echo $log['user_type'] == 'admin' ? 'danger' : 'primary'; ?>">
                                <?php echo ucfirst($log['user_type']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="log-action"><?php echo htmlspecialchars($log['action']); ?></span>
                        </td>
                        <td><?php echo $log['description'] ? htmlspecialchars($log['description']) : '—'; ?></td>
                        <td><code><?php echo $log['ip_address'] ? htmlspecialchars($log['ip_address']) : '—'; ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div style="padding: 16px 20px; border-top: 1px solid var(--border);">
            <nav aria-label="Logs pagination">
                <ul class="pagination justify-content-center mb-0">
                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    </li>
                    
                    <?php 
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                    </li>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                        <li class="page-item disabled"><span class="page-link">...</span></li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"><?php echo $total_pages; ?></a>
                        </li>
                    <?php endif; ?>
                    
                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
        
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-clipboard-list"></i>
            <h5>No Logs Found</h5>
            <p>No system activity logs match your filter criteria.</p>
            <a href="system_logs.php" class="btn btn-primary btn-sm">
                <i class="fas fa-sync-alt"></i> Clear Filters
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Auto-submit form when filter values change
    document.querySelectorAll('#user_type, #action, #date_from, #date_to').forEach(input => {
        input.addEventListener('change', function() {
            if (this.form) {
                this.form.submit();
            }
        });
    });
    
    // Export functionality (optional)
    document.getElementById('exportBtn')?.addEventListener('click', function() {
        window.location.href = 'system_logs_export.php?' + window.location.search.substring(1);
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>