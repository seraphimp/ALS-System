<?php
session_start();
require_once '../e-learning-web/config/database.php';

// Update login status based on user type
if (isset($_SESSION['user_type'])) {
    if ($_SESSION['user_type'] === 'teacher' && isset($_SESSION['user_id'])) {
        // Update teacher login status to offline
        $update_query = "UPDATE teachers SET is_logged_in = 0 WHERE teacher_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("i", $_SESSION['user_id']);
        $update_stmt->execute();
    } elseif ($_SESSION['user_type'] === 'student' && isset($_SESSION['student_id'])) {
        // Update student login status to offline if you have that field
        $update_query = "UPDATE students SET is_logged_in = 0 WHERE student_id = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("s", $_SESSION['student_id']);
        $update_stmt->execute();
    }
}

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: /ElearningLogin');
exit();
?>