<?php
require_once __DIR__ . '/../../includes/db.php';

/**
 * Get all teachers with optional filtering
 */
function get_all_teachers($conn, $status = 'active', $search = '', $barangay_id = null) {
    $sql = "SELECT t.*, b.name as barangay_name, 
            CASE 
                WHEN t.is_logged_in = 1 THEN 'active_login' 
                WHEN t.status = 'active' THEN 'active' 
                ELSE 'inactive' 
            END as login_status
            FROM teachers t 
            LEFT JOIN barangays b ON t.barangay_id = b.barangay_id 
            WHERE 1=1";
    
    $params = [];
    $types = '';
    
    if ($status !== 'all') {
        if ($status === 'active_login') {
            $sql .= " AND t.is_logged_in = 1";
        } else {
            $sql .= " AND t.status = ?";
            $params[] = $status;
            $types .= 's';
        }
    }
    
    if (!empty($search)) {
        $sql .= " AND (t.full_name LIKE ? OR t.email LIKE ? OR t.phone LIKE ?)";
        $search_term = "%$search%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
        $types .= 'sss';
    }
    
    if ($barangay_id) {
        $sql .= " AND t.barangay_id = ?";
        $params[] = $barangay_id;
        $types .= 'i';
    }
    
    $sql .= " ORDER BY t.full_name";
    
    $stmt = $conn->prepare($sql);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * Get teacher by ID
 */
function get_teacher($conn, $teacher_id) {
    $sql = "SELECT t.*, b.name as barangay_name, tc.username,
                   (SELECT COUNT(*) FROM teacher_assignments WHERE teacher_id = t.teacher_id AND status = 'active') as active_assignments
            FROM teachers t
            LEFT JOIN barangays b ON t.barangay_id = b.barangay_id
            LEFT JOIN teacher_credentials tc ON t.teacher_id = tc.teacher_id
            WHERE t.teacher_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

/**
 * Add new teacher
 */
/*function add_teacher($conn, $data) {
    $sql = "INSERT INTO teachers (
                full_name, email, phone, specialization, 
                qualification, address, barangay_id, 
                date_joined, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssssssiss',
        $data['full_name'],
        $data['email'],
        $data['phone'],
        $data['specialization'],
        $data['qualification'],
        $data['address'],
        $data['barangay_id'],
        $data['date_joined'],
        $data['status']
    );

    if (!$stmt->execute()) {
        return false;
    }

    $teacher_id = $conn->insert_id;

    if (!empty($data['username']) && !empty($data['password'])) {
        $sql = "INSERT INTO teacher_credentials (teacher_id, username, password_hash) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
        $stmt->bind_param('iss', $teacher_id, $data['username'], $password_hash);
        $stmt->execute();
    }

    return $teacher_id;
} */

/**
 * Update teacher
 */
function update_teacher($conn, $teacher_id, $data) {
    $sql = "UPDATE teachers SET 
                full_name = ?, email = ?, phone = ?, specialization = ?, 
                qualification = ?, address = ?, barangay_id = ?, 
                date_joined = ?, status = ? 
            WHERE teacher_id = ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'ssssssissi',
        $data['full_name'],
        $data['email'],
        $data['phone'],
        $data['specialization'],
        $data['qualification'],
        $data['address'],
        $data['barangay_id'],
        $data['date_joined'],
        $data['status'],
        $teacher_id
    );
    if (!$stmt->execute()) {
        return false;
    }

    if (!empty($data['username'])) {
        // Check if credentials exist
        $check_sql = "SELECT id FROM teacher_credentials WHERE teacher_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param('i', $teacher_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            // Update credentials
            if (!empty($data['password'])) {
                $sql = "UPDATE teacher_credentials SET username = ?, password_hash = ? WHERE teacher_id = ?";
                $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('ssi', $data['username'], $password_hash, $teacher_id);
            } else {
                $sql = "UPDATE teacher_credentials SET username = ? WHERE teacher_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('si', $data['username'], $teacher_id);
            }
            $stmt->execute();
        } elseif (!empty($data['password'])) {
            // Insert credentials
            $sql = "INSERT INTO teacher_credentials (teacher_id, username, password_hash) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
            $stmt->bind_param('iss', $teacher_id, $data['username'], $password_hash);
            $stmt->execute();
        }
    }

    return true;
}

/**
 * Soft delete a teacher
 */
function delete_teacher($conn, $teacher_id) {
    $sql = "UPDATE teachers SET status = 'inactive' WHERE teacher_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $teacher_id);
    return $stmt->execute();
}

/**
 * Get active barangays
 */
function get_active_barangays($conn) {
    $sql = "SELECT * FROM barangays WHERE status = 'active' ORDER BY name";
    $result = $conn->query($sql);
    $barangays = [];
    while ($row = $result->fetch_assoc()) {
        $barangays[] = $row;
    }
    return $barangays;
}
function add_teacher($conn, $data) {
    try {
        // Start transaction
        $conn->begin_transaction();
        
        // Convert handled_levels array to string
        $handled_levels_str = implode(',', $data['handled_levels']);
        
        // Insert into teachers table
        $teacher_query = "INSERT INTO teachers (
            full_name, email, phone, specialization, qualification,
            address, barangay_id, date_joined, status, handled_levels
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $conn->prepare($teacher_query);
        $stmt->bind_param(
            "ssssssisss",
            $data['full_name'],
            $data['email'],
            $data['phone'],
            $data['specialization'],
            $data['qualification'],
            $data['address'],
            $data['barangay_id'],
            $data['date_joined'],
            $data['status'],
            $handled_levels_str
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert teacher: " . $stmt->error);
        }
        
        $teacher_id = $stmt->insert_id;
        
        // Create login credentials if username and password provided
        if (!empty($data['username']) && !empty($data['password'])) {
            $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $credential_query = "INSERT INTO teacher_credentials (
                teacher_id, username, password_hash
            ) VALUES (?, ?, ?)";
            
            $stmt2 = $conn->prepare($credential_query);
            $stmt2->bind_param("iss", $teacher_id, $data['username'], $password_hash);
            
            if (!$stmt2->execute()) {
                throw new Exception("Failed to create login credentials: " . $stmt2->error);
            }
        }
        
        // Commit transaction
        $conn->commit();
        return $teacher_id;
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        error_log("Add teacher error: " . $e->getMessage());
        return false;
    }
}

// includes/teacher_functions.php

/**
 * Get teacher by ID
 */

/**
 * Get all teachers
 */

/**
 * Check if email exists (for validation)
 */
function email_exists($conn, $email, $exclude_id = null) {
    $query = "SELECT teacher_id FROM teachers WHERE email = ?";
    $params = [$email];
    $types = "s";
    
    if ($exclude_id) {
        $query .= " AND teacher_id != ?";
        $params[] = $exclude_id;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}
function username_exists($conn, $username, $exclude_id = null) {
    $query = "SELECT id FROM teacher_credentials WHERE username = ?";
    $params = [$username];
    $types = "s";
    
    if ($exclude_id) {
        // We need to get teacher_id from credentials to exclude
        $query = "SELECT tc.id FROM teacher_credentials tc 
                  JOIN teachers t ON tc.teacher_id = t.teacher_id 
                  WHERE tc.username = ? AND t.teacher_id != ?";
        $params[] = $exclude_id;
        $types .= "i";
    }
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->num_rows > 0;
}

/**
 * Get available school levels with descriptions
 */
function get_school_levels() {
    return [
        'senior_high' => [
            'name' => 'Senior High School',
            'description' => 'Grade 11-12',
            'color' => '#dc3545',
            'icon' => 'fas fa-university'
        ],
        'junior_high' => [
            'name' => 'Junior High School',
            'description' => 'Grade 7-10',
            'color' => '#0d6efd',
            'icon' => 'fas fa-school'
        ],
        'elementary' => [
            'name' => 'Elementary School',
            'description' => 'Grade 1-6',
            'color' => '#198754',
            'icon' => 'fas fa-child'
        ]
    ];

    
}

// Get teacher's handled school levels
function get_teacher_handled_levels($conn, $teacher_id) {
    $handled_levels = [];
    $stmt = $conn->prepare("SELECT school_level FROM teacher_levels WHERE teacher_id = ?");
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $handled_levels[] = $row['school_level'];
    }
    $stmt->close();
    return $handled_levels;
}
?>
