<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$host     = 'localhost';
$dbname   = 'u751659586_alssystem';
$username = 'u751659586_als';
$password = 'Alsaldrin12398';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $student_id  = $_GET['student_id']  ?? null;
    $activity_id = $_GET['activity_id'] ?? null;

    if (!$student_id || !$activity_id) {
        echo json_encode(['success' => false, 'message' => 'Student ID and Activity ID required']);
        exit;
    }

    // ── Resolve student DB id ───────────────────────────────────────────────
    $stmt = $pdo->prepare("SELECT id FROM students WHERE student_id = ?");
    $stmt->execute([$student_id]);
    $student        = $stmt->fetch(PDO::FETCH_ASSOC);
    $student_db_id  = $student['id'] ?? 0;

    // ── Activity details ────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT a.*, m.title AS module_title,
               ls.strand_number, ls.title AS strand_title
        FROM   activities a
        JOIN   modules         m  ON a.module_id  = m.module_id
        JOIN   learning_strands ls ON a.strand_id = ls.strand_id
        WHERE  a.activity_id = ?
    ");
    $stmt->execute([$activity_id]);
    $activity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        echo json_encode(['success' => false, 'message' => 'Activity not found']);
        exit;
    }

    // ── Questions / Tasks ───────────────────────────────────────────────────
    if ($activity['activity_type'] === 'quiz') {

        // Fetch questions — try with question_type/correct_answer first,
        // fall back to basic columns if those don't exist yet in the DB.
        try {
            $stmt = $pdo->prepare("
                SELECT question_id,
                       question_text,
                       question_type,
                       option_1, option_2, option_3, option_4,
                       correct_option,
                       correct_answer,
                       points
                FROM   quiz_questions
                WHERE  activity_id = ?
                ORDER  BY question_id ASC
            ");
            $stmt->execute([$activity_id]);
        } catch (PDOException $qe) {
            // question_type / correct_answer columns may not exist yet — use basic query
            error_log("get_activity_details: falling back to basic question query: " . $qe->getMessage());
            $stmt = $pdo->prepare("
                SELECT question_id,
                       question_text,
                       option_1, option_2, option_3, option_4,
                       correct_option,
                       points
                FROM   quiz_questions
                WHERE  activity_id = ?
                ORDER  BY question_id ASC
            ");
            $stmt->execute([$activity_id]);
        }
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // DEBUG: log question count to PHP error log
        error_log("get_activity_details: activity_id=$activity_id found " . count($questions) . " questions");

        // Cast numeric fields so Flutter receives the right types
        foreach ($questions as &$q) {
            $q['correct_option'] = isset($q['correct_option'])
                ? (int) $q['correct_option']
                : null;
            $q['points'] = isset($q['points'])
                ? (float) $q['points']
                : 1.0;
            // Ensure question_type has a default
            if (empty($q['question_type'])) {
                $q['question_type'] = 'multiple_choice';
            }
        }
        unset($q);

        $activity['questions'] = $questions;
        $activity['question_count'] = count($questions); // expose for debugging

    } else {

        $stmt = $pdo->prepare("
            SELECT task_id, description, points, task_type,
                   options, correct_answer, task_file
            FROM   activity_tasks
            WHERE  activity_id = ?
            ORDER  BY task_id ASC
        ");
        $stmt->execute([$activity_id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($tasks as &$task) {
            if (!empty($task['options'])) {
                $task['options'] = json_decode($task['options'], true);
            }
            $task['points'] = (float) ($task['points'] ?? 1.0);
        }
        unset($task);

        $activity['tasks'] = $tasks;
    }

    // ── Latest submission ───────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT submission_id, file_path, submission_text, score,
               feedback, status, submitted_at, graded_at,
               security_violation, violation_count
        FROM   activity_submissions
        WHERE  activity_id = ? AND student_id = ?
        ORDER  BY submitted_at DESC
        LIMIT  1
    ");
    $stmt->execute([$activity_id, $student_db_id]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── Quiz answers (if already submitted) ────────────────────────────────
    $quiz_answers = [];
    if ($activity['activity_type'] === 'quiz' && $submission) {
        $stmt = $pdo->prepare("
            SELECT question_id, answer, is_correct, points_earned
            FROM   quiz_submissions
            WHERE  activity_id = ? AND student_id = ?
        ");
        $stmt->execute([$activity_id, $student_db_id]);
        $quiz_answers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Violation count ─────────────────────────────────────────────────────
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS violation_count
        FROM   activity_violations
        WHERE  activity_id = ? AND student_id = ?
    ");
    $stmt->execute([$activity_id, $student_db_id]);
    $violations = $stmt->fetch(PDO::FETCH_ASSOC);

    // ── Can attempt? ────────────────────────────────────────────────────────
    $can_attempt = true;
    $attempt_count = 0;

    if ((int)$activity['max_attempts'] > 0) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS attempt_count
            FROM   activity_submissions
            WHERE  activity_id = ? AND student_id = ?
        ");
        $stmt->execute([$activity_id, $student_db_id]);
        $attempts      = $stmt->fetch(PDO::FETCH_ASSOC);
        $attempt_count = (int)($attempts['attempt_count'] ?? 0);
        $can_attempt   = $attempt_count < (int)$activity['max_attempts'];
    }

    // ── Response ────────────────────────────────────────────────────────────
    echo json_encode([
        'success' => true,
        'data'    => [
            'activity'        => $activity,
            'submission'      => $submission ?: null,
            'quiz_answers'    => $quiz_answers,
            'violation_count' => (int)($violations['violation_count'] ?? 0),
            'can_attempt'     => $can_attempt,
            'attempt_count'   => $attempt_count,
            'max_attempts'    => (int)$activity['max_attempts'],
        ]
    ]);

} catch (PDOException $e) {
    error_log("DB Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>