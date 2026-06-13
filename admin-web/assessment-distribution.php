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

// ── Token helpers for assessments ──────────────────────────────────────────────
if (!isset($_SESSION['_at']) || !is_array($_SESSION['_at'])) $_SESSION['_at'] = [];

function issue_assessment_token(int $id): string {
    $sid = (string)$id;
    $ex = array_search($sid, $_SESSION['_at'], true);
    if ($ex !== false) return $ex;
    $tok = bin2hex(random_bytes(20));
    $_SESSION['_at'][$tok] = $sid;
    if (count($_SESSION['_at']) > 500) $_SESSION['_at'] = array_slice($_SESSION['_at'], -500, null, true);
    return $tok;
}

function resolve_assessment_token(string $token): int {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return 0;
    return (int)($_SESSION['_at'][$token] ?? 0);
}

// ── Resolve assessment token from query string ────────────────────────────────────
$raw = trim($_SERVER['QUERY_STRING'] ?? '');
if (strpos($raw, '&') !== false) $raw = substr($raw, 0, strpos($raw, '&'));
if (empty($raw) && isset($_GET['_t'])) $raw = trim($_GET['_t']);

if (empty($raw)) {
    header('Location:/AdminManages?error=no_token');
    exit();
}

$test_id = resolve_assessment_token($raw);
if (!$test_id) {
    $_SESSION['error'] = "Invalid or expired link.";
    header('Location:/AdminManages');
    exit();
}

// Get test info with prepared statement for security
$stmt = $conn->prepare("SELECT a.*, ls.strand_number, ls.title as strand_title 
                       FROM assessments a 
                       JOIN learning_strands ls ON a.strand_id = ls.strand_id 
                       WHERE a.assessment_id = ? AND a.created_by = ?");
$stmt->bind_param("ii", $test_id, $_SESSION['admin_id']);
$stmt->execute();
$result = $stmt->get_result();
$test = $result->fetch_assoc();

if (!$test) {
    $_SESSION['error'] = "Test not found or you don't have permission.";
    header('Location:/AdminManages');
    exit();
}

// Check if distribution table exists
$table_check = $conn->query("SHOW TABLES LIKE 'assessment_distribution'");
if ($table_check->num_rows == 0) {
    $table_error = "The assessment_distribution table doesn't exist. Please run the SQL to create it first.";
    $teachers = [];
    $distributed = [];
} else {
    // Get all active teachers
    $teachers_result = $conn->query("
        SELECT tc.teacher_id, t.full_name, tc.username 
        FROM teacher_credentials tc 
        JOIN teachers t ON tc.teacher_id = t.teacher_id 
        WHERE t.status = 'active' 
        ORDER BY t.full_name
    ");
    
    if ($teachers_result) {
        $teachers = [];
        while ($row = $teachers_result->fetch_assoc()) {
            $teachers[] = [
                'teacher_id' => $row['teacher_id'],
                'full_name' => $row['full_name'],
                'email' => $row['username']
            ];
        }
    } else {
        $teachers = [];
    }
    
    // Get distribution status
    $distributed = [];
    $dist_result = $conn->query("SELECT teacher_id FROM assessment_distribution WHERE assessment_id = $test_id");
    if ($dist_result) {
        while ($row = $dist_result->fetch_assoc()) {
            $distributed[] = $row['teacher_id'];
        }
    }
}

// Handle distribution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['distribute'])) {
    $selected = $_POST['teachers'] ?? [];
    
    // Clear existing distributions
    $conn->query("DELETE FROM assessment_distribution WHERE assessment_id = $test_id");
    
    // Add new distributions
    $success_count = 0;
    foreach ($selected as $teacher_id) {
        $insert = $conn->prepare("INSERT INTO assessment_distribution (assessment_id, teacher_id, status, distributed_at) VALUES (?, ?, 'pending', NOW())");
        $insert->bind_param("ii", $test_id, $teacher_id);
        if ($insert->execute()) {
            $success_count++;
        }
    }
    
    $success_msg = "Test distributed to $success_count teachers successfully!";
    
    // Refresh distributed list
    $distributed = [];
    $dist_result = $conn->query("SELECT teacher_id FROM assessment_distribution WHERE assessment_id = $test_id");
    if ($dist_result) {
        while ($row = $dist_result->fetch_assoc()) {
            $distributed[] = $row['teacher_id'];
        }
    }
}

// Generate token for this test for links
$test_token = issue_assessment_token($test_id);

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assessment Distribution - <?php echo htmlspecialchars($test['title']); ?></title>
    <link rel="icon" type="image/png" href="/logo">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: #f8fafc; 
            min-height: 100vh;
            padding: 24px 32px;
        }
        
        .back-links {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: #64748b;
            text-decoration: none;
            font-size: 0.9rem;
            padding: 8px 16px;
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .back-link:hover {
            border-color: #1d4ed8;
            color: #1d4ed8;
            background: #eff6ff;
        }
        
        .test-header {
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .test-title {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .test-title h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: #0f172a;
        }
        
        .test-type-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-pre { background: #dbeafe; color: #1d4ed8; }
        .badge-post { background: #d1fae5; color: #059669; }
        
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        
        .distribute-card {
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
        }
        
        .teacher-list {
            margin-top: 20px;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 8px;
        }
        
        .teacher-item {
            display: flex;
            align-items: center;
            padding: 12px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            margin-bottom: 8px;
            transition: all 0.2s;
        }
        .teacher-item:hover {
            background: #f8fafc;
            border-color: #1d4ed8;
        }
        
        .teacher-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 12px;
            cursor: pointer;
        }
        
        .teacher-info {
            flex: 1;
        }
        
        .teacher-name {
            font-weight: 600;
            color: #0f172a;
        }
        
        .teacher-email {
            font-size: 0.85rem;
            color: #64748b;
        }
        
        .select-all {
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
            margin-bottom: 16px;
        }
        
        .btn-primary {
            background: #1d4ed8;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover { background: #1e3a8a; }
        
        .btn-secondary {
            background: white;
            border: 1.5px solid #e2e8f0;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            color: #475569;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-secondary:hover {
            border-color: #1d4ed8;
            color: #1d4ed8;
            background: #eff6ff;
        }
        
        .stats {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .stat {
            background: #f8fafc;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .sql-box {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 10px;
            font-family: monospace;
            white-space: pre-wrap;
            margin: 20px 0;
            font-size: 0.85rem;
            border: 1px solid #334155;
        }
        
        .copy-btn {
            background: #334155;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 10px;
        }
        .copy-btn:hover {
            background: #475569;
        }
        
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .summary-card {
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        
        .summary-number {
            font-size: 2rem;
            font-weight: 700;
            color: #1d4ed8;
        }
        
        .summary-label {
            color: #64748b;
            font-size: 0.85rem;
        }
        
        @media (max-width: 768px) {
            body { padding: 16px; }
            .back-links { flex-direction: column; }
            .btn-primary, .btn-secondary { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="back-links">
        <a href="/AdminDashboard" class="back-link">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
        <a href="/AdminManages" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Manage Tests
        </a>
        <a href="/AdminQuestions?<?= $test_token ?>" class="back-link">
            <i class="fas fa-list-ol"></i> Manage Questions
        </a>
    </div>
    
    <div class="test-header">
        <div class="test-title">
            <h1><?php echo htmlspecialchars($test['title']); ?></h1>
            <span class="test-type-badge badge-<?php echo $test['assessment_type'] == 'pre_test' ? 'pre' : 'post'; ?>">
                <?php echo $test['assessment_type'] == 'pre_test' ? 'Pre-Test' : 'Post-Test'; ?>
            </span>
        </div>
        <div class="stats">
            <span class="stat"><i class="fas fa-list-ol"></i> Strand: <?php echo htmlspecialchars($test['strand_number']); ?></span>
            <span class="stat"><i class="fas fa-clock"></i> <?php echo (int)$test['time_limit']; ?> min</span>
            <span class="stat"><i class="fas fa-star"></i> Max: <?php echo (float)$test['max_score']; ?></span>
        </div>
    </div>
    
    <?php if (isset($success_msg)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($table_error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Database Error:</strong> <?php echo $table_error; ?>
        </div>
        
        <div class="distribute-card">
            <h3 style="color: #b91c1c; margin-bottom: 16px;">Missing Database Table</h3>
            <p style="margin-bottom: 16px;">The <code>assessment_distribution</code> table is required for distributing tests. Please run this SQL in your database:</p>
            
            <div class="sql-box" id="sqlQuery">
CREATE TABLE IF NOT EXISTS `assessment_distribution` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `assessment_id` INT(11) NOT NULL,
    `teacher_id` INT(11) NOT NULL,
    `status` ENUM('pending', 'accepted', 'rejected') DEFAULT 'pending',
    `distributed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `accepted_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    KEY `assessment_id` (`assessment_id`),
    KEY `teacher_id` (`teacher_id`),
    UNIQUE KEY `unique_distribution` (`assessment_id`, `teacher_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            </div>
            
            <button class="copy-btn" onclick="copySQL()">
                <i class="fas fa-copy"></i> Copy SQL
            </button>
            
            <div style="margin-top: 24px;">
                <a href="/AdminManages" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Manage Tests
                </a>
            </div>
        </div>
    <?php else: ?>
    
        <!-- Summary Stats -->
        <div class="summary-stats">
            <div class="summary-card">
                <div class="summary-number"><?php echo count($teachers); ?></div>
                <div class="summary-label">Total Active Teachers</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo count($distributed); ?></div>
                <div class="summary-label">Currently Distributed</div>
            </div>
            <div class="summary-card">
                <div class="summary-number"><?php echo count($teachers) - count($distributed); ?></div>
                <div class="summary-label">Not Yet Distributed</div>
            </div>
        </div>
        
        <div class="distribute-card">
            <h2 style="margin-bottom: 16px;">Distribute Test to Teachers</h2>
            <p style="color: #64748b; margin-bottom: 20px;">Select teachers who should have access to this test. Once distributed, they can use it with their students.</p>
            
            <form method="POST">
                <div class="select-all">
                    <label style="display: flex; align-items: center; cursor: pointer;">
                        <input type="checkbox" id="selectAll" style="width: 18px; height: 18px; margin-right: 12px;">
                        <strong>Select All Teachers</strong>
                    </label>
                </div>
                
                <div class="teacher-list">
                    <?php if (empty($teachers)): ?>
                        <p style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fas fa-user-slash" style="font-size: 2rem; display: block; margin-bottom: 10px;"></i>
                            No active teachers found.
                        </p>
                    <?php else: ?>
                        <?php foreach ($teachers as $teacher): ?>
                            <div class="teacher-item">
                                <input type="checkbox" name="teachers[]" value="<?php echo $teacher['teacher_id']; ?>" 
                                       <?php echo in_array($teacher['teacher_id'], $distributed) ? 'checked' : ''; ?>>
                                <div class="teacher-info">
                                    <div class="teacher-name"><?php echo htmlspecialchars($teacher['full_name']); ?></div>
                                    <div class="teacher-email"><?php echo htmlspecialchars($teacher['email']); ?></div>
                                </div>
                                <?php if (in_array($teacher['teacher_id'], $distributed)): ?>
                                    <span style="color: #059669; font-size: 0.8rem; background: #d1fae5; padding: 4px 8px; border-radius: 20px;">
                                        <i class="fas fa-check-circle"></i> Distributed
                                    </span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div style="display: flex; gap: 12px; margin-top: 24px; flex-wrap: wrap;">
                    <button type="submit" name="distribute" value="1" class="btn-primary" <?php echo empty($teachers) ? 'disabled' : ''; ?>>
                        <i class="fas fa-share-alt"></i> Distribute to Selected Teachers
                    </button>
                    <a href="/AdminManages" class="btn-secondary">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                </div>
            </form>
            
            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                <div style="background: #f0f9ff; padding: 16px; border-radius: 8px; font-size: 0.9rem; color: #0369a1;">
                    <i class="fas fa-info-circle"></i> 
                    <strong>Note:</strong> 
                    <ul style="margin-top: 8px; margin-left: 20px;">
                        <li>Teachers will see this test in their dashboard immediately after distribution.</li>
                        <li>Pre-tests and Post-tests for the same strand share questions automatically.</li>
                        <li>You can update distribution settings anytime by reselecting teachers.</li>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>
    
    <script>
        document.getElementById('selectAll')?.addEventListener('change', function(e) {
            const checkboxes = document.querySelectorAll('input[name="teachers[]"]');
            checkboxes.forEach(cb => cb.checked = e.target.checked);
        });
        
        function copySQL() {
            const sql = document.getElementById('sqlQuery').innerText;
            navigator.clipboard.writeText(sql).then(function() {
                alert('SQL copied to clipboard!');
            }).catch(function() {
                alert('Failed to copy SQL. Please select and copy manually.');
            });
        }
        
        document.querySelectorAll('input[name="teachers[]"]').forEach(cb => {
            cb.addEventListener('change', function() {
                const allCheckboxes = document.querySelectorAll('input[name="teachers[]"]');
                const selectAll = document.getElementById('selectAll');
                const checkedCount = document.querySelectorAll('input[name="teachers[]"]:checked').length;
                
                if (selectAll) {
                    selectAll.checked = checkedCount === allCheckboxes.length;
                    selectAll.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
                }
            });
        });
    </script>
</body>
</html>