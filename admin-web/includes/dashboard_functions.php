<?php
function get_admin_details($conn, $admin_id) {
    $sql = "SELECT * FROM admins WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $admin_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    return mysqli_fetch_assoc($result);
}

function get_active_student_count($conn) {
    $sql = "SELECT COUNT(*) as count FROM students WHERE status = 'enrolled'";
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['count'];
}

function get_teacher_count($conn) {
    $sql = "SELECT COUNT(*) as count FROM teachers WHERE status = 'active'";
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['count'];
}

function get_learning_material_count($conn) {
    $sql = "SELECT COUNT(*) as count FROM learning_materials WHERE status = 'published'";
    $result = mysqli_query($conn, $sql);
    $row = mysqli_fetch_assoc($result);
    return $row['count'];
}

function get_recent_enrollments($conn, $limit = 5) {
    $sql = "SELECT 
            s.student_id, 
            s.first_name, 
            s.last_name, 
            s.middle_name, 
            s.extension_name,
            b.name AS barangay, 
            s.enrollment_date, 
             t.full_name as teacher_name  
        FROM students s
        LEFT JOIN barangays b ON s.current_barangay_id = b.barangay_id
        LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
        ORDER BY s.enrollment_date DESC 
        LIMIT ?";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $enrollments = mysqli_fetch_all($result, MYSQLI_ASSOC);
    
    // Process each enrollment to create the full student name
    foreach ($enrollments as &$enrollment) {
        $enrollment['full_name'] = build_full_name(
            $enrollment['first_name'],
            $enrollment['last_name'],
            $enrollment['middle_name'],
            $enrollment['extension_name']
        );
    }
    
    return $enrollments;
}

// Helper function to build full name from components
function build_full_name($first_name, $last_name, $middle_name = null, $extension_name = null) {
    $name = $first_name;
    
    if (!empty($middle_name)) {
        $name .= ' ' . $middle_name;
    }
    
    $name .= ' ' . $last_name;
    
    if (!empty($extension_name)) {
        $name .= ' ' . $extension_name;
    }
    
    return $name;
}