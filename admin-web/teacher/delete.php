<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/teacher_functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    redirect('../../index.php');
}

// Get teacher ID from URL
$teacher_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$teacher_id) {
    $_SESSION['error'] = "Invalid teacher ID";
    redirect('all-teacher.php');
}

// Delete teacher (set status to inactive)
if (delete_teacher($conn, $teacher_id)) {
    $_SESSION['success'] = "Teacher deactivated successfully";
} else {
    $_SESSION['error'] = "Failed to deactivate teacher";
}

redirect('all-teacher.php');