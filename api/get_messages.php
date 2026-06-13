<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database connection
$host = 'localhost';
$dbname = 'u751659586_alssystem';
$username = 'u751659586_als';
$password = 'Alsaldrin12398';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get parameters
    $student_id = $_GET['student_id'] ?? null;
    
    if (!$student_id) {
        echo json_encode(['success' => false, 'message' => 'Student ID required']);
        exit;
    }
    
    // Get messages for student
    $stmt = $pdo->prepare("
        SELECT 
            m.message_id,
            m.sender_id,
            m.receiver_id,
            m.sender_type,
            m.message,
            m.message_type,
            m.is_read,
            m.created_at,
            t.full_name as sender_name,
            t.profile_pic as sender_avatar
        FROM messages m
        LEFT JOIN teachers t ON m.sender_id = t.teacher_id AND m.sender_type = 'teacher'
        WHERE m.receiver_id = ? OR m.sender_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt->execute([$student_id, $student_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format messages
    foreach ($messages as &$msg) {
        $msg['time'] = date('h:i A', strtotime($msg['created_at']));
        $msg['date'] = date('M d, Y', strtotime($msg['created_at']));
        $msg['is_from_teacher'] = ($msg['sender_type'] == 'teacher');
    }
    
    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
?>