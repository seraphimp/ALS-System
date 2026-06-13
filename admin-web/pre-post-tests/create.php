<?php
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/dashboard_functions.php';

secure_session_start();

if (!isset($_SESSION['admin_id']) || !is_admin_logged_in()) {
    redirect('/admin-secure');
    exit;
}

$admin = get_admin_details($conn, $_SESSION['admin_id']);

$strands = $conn->query("SELECT * FROM learning_strands WHERE status = 'active' ORDER BY strand_number")->fetch_all(MYSQLI_ASSOC);

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_test'])) {
    $strand_id = (int)$_POST['strand_id'];
    $test_type = $_POST['test_type'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $time_limit = (int)$_POST['time_limit'];
    $max_score = (float)$_POST['max_score'];
    
    if (empty($title)) {
        $strand = $conn->query("SELECT strand_number FROM learning_strands WHERE strand_id = $strand_id")->fetch_assoc();
        $title = $strand['strand_number'] . ' ' . ($test_type === 'pre_test' ? 'Pre-Test' : 'Post-Test');
    }
    
    $check = $conn->prepare("SELECT assessment_id FROM assessments WHERE strand_id = ? AND assessment_type = ? AND created_by = ?");
    $check->bind_param("isi", $strand_id, $test_type, $_SESSION['admin_id']);
    $check->execute();
    
    if ($check->get_result()->num_rows > 0) {
        $error_msg = ucfirst(str_replace('_', ' ', $test_type)) . " already exists for this strand!";
    } else {
        $q = $conn->prepare("INSERT INTO assessments (strand_id, title, description, assessment_type, time_limit, max_score, created_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())");
        $q->bind_param("ississi", $strand_id, $title, $description, $test_type, $time_limit, $max_score, $_SESSION['admin_id']);
        
        if ($q->execute()) {
            $new_test_id = $q->insert_id;
            header("Location: /AdminQuestions?test_id=$new_test_id&created=1");
            exit();
        } else {
            $error_msg = "Error creating test: " . $conn->error;
        }
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Test - ALS Admin</title>
    <link rel="icon" type="image/png" href="../logo/als-logo-removebg-preview.png">
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
        
        .page-header {
            margin-bottom: 24px;
        }
        
        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 8px;
        }
        
        .page-header p {
            color: #64748b;
            font-size: 0.95rem;
        }
        
        .create-card {
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 32px;
            max-width: 700px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }
        
        .form-group label .required {
            color: #ef4444;
            margin-left: 2px;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-family: inherit;
            font-size: 0.95rem;
            transition: all 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1d4ed8;
            box-shadow: 0 0 0 3px rgba(29,78,216,0.1);
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 100px;
        }
        
        .type-selector {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 8px;
        }
        
        .type-option {
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .type-option:hover {
            border-color: #1d4ed8;
            background: #eff6ff;
        }
        
        .type-option.selected {
            border-color: #1d4ed8;
            background: #eff6ff;
        }
        
        .type-option.pre i {
            font-size: 2rem;
            color: #1d4ed8;
            margin-bottom: 12px;
        }
        
        .type-option.post i {
            font-size: 2rem;
            color: #059669;
            margin-bottom: 12px;
        }
        
        .type-option h3 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 4px;
            color: #0f172a;
        }
        
        .type-option p {
            font-size: 0.8rem;
            color: #64748b;
        }
        
        .row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 32px;
        }
        
        .btn-primary {
            flex: 2;
            background: #1d4ed8;
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover { background: #1e3a8a; }
        
        .btn-secondary {
            flex: 1;
            background: white;
            color: #475569;
            border: 1.5px solid #e2e8f0;
            padding: 14px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .btn-secondary:hover {
            border-color: #1d4ed8;
            color: #1d4ed8;
            background: #eff6ff;
        }
        
        .info-note {
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 16px;
            margin-top: 24px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        .info-note i {
            color: #1d4ed8;
            font-size: 1.2rem;
        }
        .info-note p {
            color: #1e3a8a;
            font-size: 0.9rem;
            line-height: 1.5;
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
    </div>
    
    <div class="page-header">
        <h1>Create New Test</h1>
        <p>Create a pre-test or post-test for a learning strand. Questions can be added after creation.</p>
    </div>
    
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>
    
    <div class="create-card">
        <form method="POST" id="createForm">
            <input type="hidden" name="create_test" value="1">
            <input type="hidden" name="test_type" id="testType" value="pre_test">
            
            <div class="form-group">
                <label>Test Type <span class="required">*</span></label>
                <div class="type-selector" id="typeSelector">
                    <div class="type-option pre" data-type="pre_test" onclick="selectType('pre_test')">
                        <i class="fas fa-flag-checkered"></i>
                        <h3>Pre-Test</h3>
                        <p>Given before the lesson to assess prior knowledge</p>
                    </div>
                    <div class="type-option post" data-type="post_test" onclick="selectType('post_test')">
                        <i class="fas fa-check-double"></i>
                        <h3>Post-Test</h3>
                        <p>Given after the lesson to measure learning progress</p>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label>Learning Strand <span class="required">*</span></label>
                <select name="strand_id" id="strandId" class="form-control" required>
                    <option value="">-- Select Learning Strand --</option>
                    <?php foreach ($strands as $s): ?>
                        <option value="<?php echo $s['strand_id']; ?>">
                            <?php echo htmlspecialchars($s['strand_number'] . ' — ' . $s['title']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Test Title (Optional)</label>
                <input type="text" name="title" id="title" class="form-control" 
                       placeholder="Leave empty for auto-generated title (e.g., LS1 Pre-Test)">
            </div>
            
            <div class="form-group">
                <label>Description / Instructions</label>
                <textarea name="description" id="description" class="form-control" 
                          placeholder="Enter test instructions, guidelines, or notes for students..."></textarea>
            </div>
            
            <div class="row-2">
                <div class="form-group">
                    <label>Time Limit (minutes)</label>
                    <input type="number" name="time_limit" id="timeLimit" class="form-control" 
                           value="30" min="0" step="1">
                    <small style="color: #94a3b8; font-size: 0.75rem;">0 = no time limit</small>
                </div>
                
                <div class="form-group">
                    <label>Maximum Score</label>
                    <input type="number" name="max_score" id="maxScore" class="form-control" 
                           value="100" min="1" step="0.5">
                </div>
            </div>
            
            <div class="info-note">
                <i class="fas fa-info-circle"></i>
                <p>
                    <strong>Note:</strong> After creating the test, you'll be redirected to add questions. 
                    Pre-tests and post-tests for the same strand share the same question bank.
                </p>
            </div>
            
            <div class="form-actions">
                <a href="/AdminManages" class="btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-save"></i> Create Test
                </button>
            </div>
        </form>
    </div>
    
    <script>
        function selectType(type) {
            document.getElementById('testType').value = type;
            document.querySelectorAll('.type-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.querySelector(`.type-option[data-type="${type}"]`).classList.add('selected');
        }
        
        selectType('pre_test');
    </script>
</body>
</html>
<?php ob_end_flush(); ?>