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

// Get test info
$stmt = $conn->prepare("SELECT a.*, ls.strand_number, ls.title as strand_title 
                       FROM assessments a 
                       JOIN learning_strands ls ON a.strand_id = ls.strand_id 
                       WHERE a.assessment_id = ? AND a.created_by = ?");
$stmt->bind_param("ii", $test_id, $_SESSION['admin_id']);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();

if (!$test) {
    $_SESSION['error'] = "Test not found or you don't have permission.";
    header('Location:/AdminManages');
    exit();
}

// Get paired test info
$pair_type = $test['assessment_type'] == 'pre_test' ? 'post_test' : 'pre_test';
$paired = $conn->query("SELECT * FROM assessments WHERE strand_id = {$test['strand_id']} AND assessment_type = '$pair_type' AND created_by = {$_SESSION['admin_id']}")->fetch_assoc();

$success_msg = '';
$error_msg = '';

// Handle save question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question'])) {
    $question_text = trim($_POST['question_text']);
    $question_type = $_POST['question_type'];
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    
    if ($question_type === 'multiple_choice') {
        $correct_answer = $_POST['correct_answer_mc'] ?? '';
    } elseif ($question_type === 'true_false') {
        $correct_answer = $_POST['correct_answer_tf'] ?? '';
    } else {
        $correct_answer = trim($_POST['correct_answer_id'] ?? '');
    }
    
    $points = (float)($_POST['points'] ?? 1);
    $question_id = (int)($_POST['question_id'] ?? 0);
    
    if (empty($question_text)) {
        $error_msg = "Question text is required!";
    } elseif (empty($correct_answer)) {
        $error_msg = "Correct answer is required!";
    } elseif ($question_type === 'multiple_choice' && (empty($option_a) || empty($option_b))) {
        $error_msg = "Options A and B are required for multiple choice questions!";
    } else {
        if ($question_id === 0) {
            $order_result = $conn->query("SELECT COALESCE(MAX(question_order), 0) + 1 as next FROM assessment_questions WHERE assessment_id = $test_id");
            $max_order = $order_result->fetch_assoc()['next'];
        } else {
            $max_order = 0;
        }
        
        if ($question_id > 0) {
            $q = $conn->prepare("UPDATE assessment_questions SET 
                question_text = ?, 
                question_type = ?, 
                option_a = ?, 
                option_b = ?, 
                option_c = ?, 
                option_d = ?, 
                correct_answer = ?, 
                points = ? 
                WHERE question_id = ?");
            $q->bind_param("sssssssdi", 
                $question_text, 
                $question_type, 
                $option_a, 
                $option_b, 
                $option_c, 
                $option_d, 
                $correct_answer, 
                $points, 
                $question_id
            );
            if ($q->execute()) {
                $success_msg = "Question updated successfully!";
            } else {
                $error_msg = "Error updating question: " . $conn->error;
            }
        } else {
            $q = $conn->prepare("INSERT INTO assessment_questions 
                (assessment_id, question_text, question_type, option_a, option_b, option_c, option_d, correct_answer, points, question_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $q->bind_param("isssssssdi", 
                $test_id, 
                $question_text, 
                $question_type, 
                $option_a, 
                $option_b, 
                $option_c, 
                $option_d, 
                $correct_answer, 
                $points, 
                $max_order
            );
            if ($q->execute()) {
                $success_msg = "Question added successfully!";
                
                if ($paired) {
                    $paired_id = $paired['assessment_id'];
                    $ins_pair = $conn->prepare("INSERT INTO assessment_questions 
                        (assessment_id, question_text, question_type, option_a, option_b, option_c, option_d, correct_answer, points, question_order) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins_pair->bind_param("isssssssdi", 
                        $paired_id, 
                        $question_text, 
                        $question_type, 
                        $option_a, 
                        $option_b, 
                        $option_c, 
                        $option_d, 
                        $correct_answer, 
                        $points, 
                        $max_order
                    );
                    $ins_pair->execute();
                }
            } else {
                $error_msg = "Error adding question: " . $conn->error;
            }
        }
    }
}

// Handle delete question
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $question_id = (int)$_POST['question_id'];
    
    $order_q = $conn->query("SELECT question_order FROM assessment_questions WHERE question_id = $question_id");
    if ($order_q && $order_q->num_rows > 0) {
        $order = $order_q->fetch_assoc()['question_order'];
        
        $del = $conn->prepare("DELETE FROM assessment_questions WHERE question_id = ? AND assessment_id = ?");
        $del->bind_param("ii", $question_id, $test_id);
        if ($del->execute()) {
            $success_msg = "Question deleted successfully!";
            
            if ($paired) {
                $conn->query("DELETE FROM assessment_questions WHERE assessment_id = {$paired['assessment_id']} AND question_order = $order");
            }
        } else {
            $error_msg = "Error deleting question: " . $conn->error;
        }
    }
}

// Fetch questions
$questions = $conn->query("SELECT * FROM assessment_questions WHERE assessment_id = $test_id ORDER BY question_order")->fetch_all(MYSQLI_ASSOC);

$total_points = 0;
foreach ($questions as $q) {
    $total_points += $q['points'];
}

// Generate tokens
$test_token = issue_assessment_token($test_id);
$distribute_token = issue_assessment_token($test_id);

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Questions - <?php echo htmlspecialchars($test['title']); ?></title>
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
        
        .test-meta {
            display: flex;
            gap: 20px;
            color: #64748b;
            font-size: 0.9rem;
            flex-wrap: wrap;
        }
        
        .paired-info {
            background: #f0f9ff;
            border: 1px solid #bae6fd;
            border-radius: 10px;
            padding: 16px;
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .paired-link {
            color: #0369a1;
            font-weight: 600;
            text-decoration: none;
        }
        .paired-link:hover {
            text-decoration: underline;
        }
        
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .btn-primary {
            background: #1d4ed8;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary:hover { background: #1e3a8a; }
        
        .stats-summary {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .stat-chip {
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 30px;
            padding: 6px 14px;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
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
        
        .questions-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .question-card {
            background: white;
            border: 1.5px solid #e2e8f0;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .question-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }
        
        .question-number {
            width: 32px;
            height: 32px;
            background: #eff6ff;
            color: #1d4ed8;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.9rem;
            flex-shrink: 0;
        }
        
        .question-text {
            flex: 1;
            font-weight: 500;
            color: #0f172a;
            line-height: 1.5;
        }
        
        .question-type {
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.7rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
            flex-shrink: 0;
        }
        
        .question-body {
            padding: 16px 20px;
        }
        
        .options-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .option-item {
            padding: 10px 14px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
        }
        
        .option-item.correct {
            background: #d1fae5;
            border-color: #10b981;
        }
        
        .option-label {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            background: #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 700;
            color: #475569;
        }
        
        .option-item.correct .option-label {
            background: #10b981;
            color: white;
        }
        
        .tf-container {
            display: flex;
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .tf-option {
            padding: 10px 24px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-weight: 600;
        }
        
        .tf-option.correct {
            background: #d1fae5;
            border-color: #10b981;
        }
        
        .identification-answer {
            background: #d1fae5;
            padding: 10px 16px;
            border-radius: 8px;
            display: inline-block;
            font-weight: 600;
            color: #065f46;
            margin-bottom: 16px;
        }
        
        .question-footer {
            padding: 12px 20px;
            border-top: 1px solid #f1f5f9;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .points-badge {
            background: #eff6ff;
            color: #1d4ed8;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.85rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            background: white;
            color: #64748b;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .btn-icon:hover {
            border-color: #1d4ed8;
            color: #1d4ed8;
            background: #eff6ff;
        }
        .btn-icon.delete:hover {
            border-color: #ef4444;
            color: #ef4444;
            background: #fee2e2;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border: 2px dashed #e2e8f0;
            border-radius: 12px;
        }
        
        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-overlay.open { display: flex; }
        
        .modal {
            background: white;
            border-radius: 16px;
            padding: 24px;
            max-width: 600px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal h2 {
            font-size: 1.3rem;
            margin-bottom: 20px;
            color: #0f172a;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: #475569;
            margin-bottom: 4px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-family: inherit;
            font-size: 0.9rem;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #1d4ed8;
        }
        
        textarea.form-control {
            resize: vertical;
            min-height: 80px;
        }
        
        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn-cancel {
            flex: 1;
            padding: 10px;
            border: 1.5px solid #e2e8f0;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
        }
        .btn-cancel:hover {
            background: #f1f5f9;
        }
        
        .btn-save {
            flex: 2;
            padding: 10px;
            background: #1d4ed8;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        .btn-save:hover {
            background: #1e3a8a;
        }
        
        .section-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: #475569;
            margin: 16px 0 8px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .correct-picker {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        
        .correct-option {
            padding: 8px 16px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        .correct-option:hover {
            border-color: #1d4ed8;
            background: #eff6ff;
        }
        .correct-option.selected {
            background: #1d4ed8;
            color: white;
            border-color: #1d4ed8;
        }
        
        @media (max-width: 768px) {
            body { padding: 16px; }
            .options-grid { grid-template-columns: 1fr; }
            .back-links { flex-direction: column; }
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
        <a href="/AdminDistributes?<?= $distribute_token ?>" class="back-link">
            <i class="fas fa-share-alt"></i> Distribute Test
        </a>
    </div>
    
    <div class="test-header">
        <div class="test-title">
            <h1><?php echo htmlspecialchars($test['title']); ?></h1>
            <span class="test-type-badge badge-<?php echo $test['assessment_type'] == 'pre_test' ? 'pre' : 'post'; ?>">
                <?php echo $test['assessment_type'] == 'pre_test' ? 'Pre-Test' : 'Post-Test'; ?>
            </span>
        </div>
        <div class="test-meta">
            <span><i class="far fa-clock"></i> <?php echo (int)$test['time_limit']; ?> minutes</span>
            <span><i class="fas fa-star"></i> Max Score: <?php echo (float)$test['max_score']; ?></span>
            <span><i class="fas fa-layer-group"></i> <?php echo htmlspecialchars($test['strand_number']); ?></span>
        </div>
        
        <?php if ($paired): ?>
        <div class="paired-info">
            <div>
                <i class="fas fa-link"></i>
                <strong>Paired with:</strong> 
                <a href="/AdminQuestions?<?= issue_assessment_token($paired['assessment_id']) ?>" class="paired-link">
                    <?php echo htmlspecialchars($paired['title']); ?>
                </a>
                (<?php echo $pair_type == 'pre_test' ? 'Pre-Test' : 'Post-Test'; ?>)
            </div>
            <span style="color: #059669; font-size: 0.85rem;">
                <i class="fas fa-check-circle"></i> Questions are automatically shared
            </span>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="action-bar">
        <div class="stats-summary">
            <span class="stat-chip"><i class="fas fa-list-ol"></i> <?php echo count($questions); ?> Questions</span>
            <span class="stat-chip"><i class="fas fa-star"></i> <?php echo $total_points; ?> Total Points</span>
        </div>
        <button class="btn-primary" onclick="openQuestionModal()">
            <i class="fas fa-plus"></i> Add Question
        </button>
    </div>
    
    <?php if (!empty($success_msg)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success_msg); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error_msg)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?php echo htmlspecialchars($error_msg); ?>
        </div>
    <?php endif; ?>
    
    <div class="questions-list">
        <?php if (empty($questions)): ?>
            <div class="empty-state">
                <i class="fas fa-question-circle"></i>
                <h3>No Questions Yet</h3>
                <p>Click "Add Question" to start building your test.</p>
            </div>
        <?php else: ?>
            <?php foreach ($questions as $index => $q): ?>
                <div class="question-card">
                    <div class="question-header">
                        <div class="question-number"><?php echo $index + 1; ?></div>
                        <div class="question-text"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></div>
                        <span class="question-type">
                            <?php 
                                echo $q['question_type'] == 'multiple_choice' ? 'Multiple Choice' : 
                                     ($q['question_type'] == 'true_false' ? 'True/False' : 'Identification'); 
                            ?>
                        </span>
                    </div>
                    <div class="question-body">
                        <?php if ($q['question_type'] == 'multiple_choice'): ?>
                            <div class="options-grid">
                                <?php 
                                $letters = ['A', 'B', 'C', 'D'];
                                foreach ($letters as $l):
                                    $opt = 'option_' . strtolower($l);
                                    if (!empty($q[$opt])):
                                ?>
                                    <div class="option-item <?php echo strtoupper($q['correct_answer']) == $l ? 'correct' : ''; ?>">
                                        <div class="option-label"><?php echo $l; ?></div>
                                        <?php echo htmlspecialchars($q[$opt]); ?>
                                    </div>
                                <?php endif; endforeach; ?>
                            </div>
                        <?php elseif ($q['question_type'] == 'true_false'): ?>
                            <div class="tf-container">
                                <div class="tf-option <?php echo strtolower($q['correct_answer']) == 'true' ? 'correct' : ''; ?>">
                                    True
                                </div>
                                <div class="tf-option <?php echo strtolower($q['correct_answer']) == 'false' ? 'correct' : ''; ?>">
                                    False
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="identification-answer">
                                <strong>Answer:</strong> <?php echo htmlspecialchars($q['correct_answer']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="question-footer">
                        <span class="points-badge">
                            <i class="fas fa-star"></i> <?php echo (float)$q['points']; ?> points
                        </span>
                        <div class="action-buttons">
                            <button class="btn-icon" onclick='editQuestion(<?php echo htmlspecialchars(json_encode($q)); ?>)'>
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this question? This cannot be undone.');">
                                <input type="hidden" name="delete_question" value="1">
                                <input type="hidden" name="question_id" value="<?php echo $q['question_id']; ?>">
                                <button type="submit" class="btn-icon delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Question Modal -->
    <div class="modal-overlay" id="questionModal">
        <div class="modal">
            <h2 id="modalTitle">Add Question</h2>
            <form method="POST" id="questionForm" onsubmit="return validateQuestionForm()">
                <input type="hidden" name="save_question" value="1">
                <input type="hidden" name="question_id" id="questionId" value="">
                
                <div class="form-group">
                    <label>Question Type</label>
                    <select name="question_type" id="questionType" class="form-control" onchange="toggleQuestionType()" required>
                        <option value="multiple_choice">Multiple Choice</option>
                        <option value="true_false">True/False</option>
                        <option value="identification">Identification</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Question Text</label>
                    <textarea name="question_text" id="questionText" class="form-control" rows="3" required></textarea>
                </div>
                
                <!-- Multiple Choice Options -->
                <div id="mcOptions">
                    <div class="section-title">Answer Options</div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div class="form-group">
                            <label>Option A</label>
                            <input type="text" name="option_a" id="optionA" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Option B</label>
                            <input type="text" name="option_b" id="optionB" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Option C (optional)</label>
                            <input type="text" name="option_c" id="optionC" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Option D (optional)</label>
                            <input type="text" name="option_d" id="optionD" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Correct Answer</label>
                        <div class="correct-picker" id="mcCorrectPicker">
                            <div class="correct-option" data-val="A" onclick="selectCorrectMC('A')">A</div>
                            <div class="correct-option" data-val="B" onclick="selectCorrectMC('B')">B</div>
                            <div class="correct-option" data-val="C" onclick="selectCorrectMC('C')">C</div>
                            <div class="correct-option" data-val="D" onclick="selectCorrectMC('D')">D</div>
                        </div>
                        <input type="hidden" name="correct_answer_mc" id="correctAnswerMC">
                    </div>
                </div>
                
                <!-- True/False Options -->
                <div id="tfOptions" style="display: none;">
                    <div class="form-group">
                        <label>Correct Answer</label>
                        <div class="correct-picker">
                            <div class="correct-option" data-val="true" onclick="selectCorrectTF('true')">True</div>
                            <div class="correct-option" data-val="false" onclick="selectCorrectTF('false')">False</div>
                        </div>
                        <input type="hidden" name="correct_answer_tf" id="correctAnswerTF">
                    </div>
                </div>
                
                <!-- Identification Options -->
                <div id="idOptions" style="display: none;">
                    <div class="form-group">
                        <label>Correct Answer</label>
                        <input type="text" name="correct_answer_id" id="correctAnswerID" class="form-control">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Points</label>
                    <input type="number" name="points" id="points" class="form-control" value="1" min="0.5" step="0.5" required>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeQuestionModal()">Cancel</button>
                    <button type="submit" class="btn-save">Save Question</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const qModal = document.getElementById('questionModal');
        
        document.addEventListener('DOMContentLoaded', function() {
            toggleQuestionType();
        });
        
        function toggleQuestionType() {
            const type = document.getElementById('questionType').value;
            document.getElementById('mcOptions').style.display = type === 'multiple_choice' ? 'block' : 'none';
            document.getElementById('tfOptions').style.display = type === 'true_false' ? 'block' : 'none';
            document.getElementById('idOptions').style.display = type === 'identification' ? 'block' : 'none';
            
            if (type !== 'multiple_choice') {
                document.querySelectorAll('#mcCorrectPicker .correct-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                document.getElementById('correctAnswerMC').value = '';
            }
            if (type !== 'true_false') {
                document.querySelectorAll('#tfOptions .correct-option').forEach(opt => {
                    opt.classList.remove('selected');
                });
                document.getElementById('correctAnswerTF').value = '';
            }
            if (type !== 'identification') {
                document.getElementById('correctAnswerID').value = '';
            }
        }
        
        function selectCorrectMC(val) {
            document.getElementById('correctAnswerMC').value = val;
            document.querySelectorAll('#mcCorrectPicker .correct-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.querySelector(`#mcCorrectPicker .correct-option[data-val="${val}"]`).classList.add('selected');
        }
        
        function selectCorrectTF(val) {
            document.getElementById('correctAnswerTF').value = val;
            document.querySelectorAll('#tfOptions .correct-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.querySelector(`#tfOptions .correct-option[data-val="${val}"]`).classList.add('selected');
        }
        
        function validateQuestionForm() {
            const type = document.getElementById('questionType').value;
            
            if (type === 'multiple_choice') {
                const optionA = document.getElementById('optionA').value.trim();
                const optionB = document.getElementById('optionB').value.trim();
                const correctAnswer = document.getElementById('correctAnswerMC').value;
                
                if (!optionA || !optionB) {
                    alert('Options A and B are required for multiple choice questions.');
                    return false;
                }
                if (!correctAnswer) {
                    alert('Please select the correct answer.');
                    return false;
                }
            } else if (type === 'true_false') {
                const correctAnswer = document.getElementById('correctAnswerTF').value;
                if (!correctAnswer) {
                    alert('Please select True or False as the correct answer.');
                    return false;
                }
            } else if (type === 'identification') {
                const correctAnswer = document.getElementById('correctAnswerID').value.trim();
                if (!correctAnswer) {
                    alert('Please enter the correct answer.');
                    return false;
                }
            }
            
            return true;
        }
        
        function openQuestionModal() {
            document.getElementById('questionForm').reset();
            document.getElementById('questionId').value = '';
            document.getElementById('modalTitle').innerText = 'Add Question';
            document.getElementById('questionType').value = 'multiple_choice';
            
            document.querySelectorAll('.correct-option').forEach(opt => {
                opt.classList.remove('selected');
            });
            document.getElementById('correctAnswerMC').value = '';
            document.getElementById('correctAnswerTF').value = '';
            document.getElementById('correctAnswerID').value = '';
            
            toggleQuestionType();
            qModal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        
        function closeQuestionModal() {
            qModal.classList.remove('open');
            document.body.style.overflow = '';
        }
        
        function editQuestion(data) {
            document.getElementById('questionId').value = data.question_id;
            document.getElementById('questionText').value = data.question_text;
            document.getElementById('questionType').value = data.question_type;
            document.getElementById('points').value = data.points;
            
            toggleQuestionType();
            
            if (data.question_type === 'multiple_choice') {
                document.getElementById('optionA').value = data.option_a || '';
                document.getElementById('optionB').value = data.option_b || '';
                document.getElementById('optionC').value = data.option_c || '';
                document.getElementById('optionD').value = data.option_d || '';
                if (data.correct_answer) {
                    selectCorrectMC(data.correct_answer.toUpperCase());
                }
            } else if (data.question_type === 'true_false') {
                if (data.correct_answer) {
                    selectCorrectTF(data.correct_answer.toLowerCase());
                }
            } else {
                document.getElementById('correctAnswerID').value = data.correct_answer || '';
            }
            
            document.getElementById('modalTitle').innerText = 'Edit Question';
            qModal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
        
        qModal.addEventListener('click', function(e) {
            if (e.target === qModal) {
                closeQuestionModal();
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && qModal.classList.contains('open')) {
                closeQuestionModal();
            }
        });
    </script>
</body>
</html>