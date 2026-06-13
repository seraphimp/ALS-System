<?php
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: ../../index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = "Invalid request method";
    header('Location: index.php');
    exit();
}

$studentId = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
if ($studentId <= 0) {
    $_SESSION['error'] = "Invalid student ID";
    header('Location: index.php');
    exit();
}

// Validate and sanitize input data
$requiredFields = [
    'lrn', 'last_name', 'first_name', 'birth_date', 'age', 'gender',
    'current_address', 'current_barangay_id', 'enrollment_date'
];

foreach ($requiredFields as $field) {
    if (empty($_POST[$field])) {
        $_SESSION['error'] = "Please fill in all required fields";
        header("Location: edit.php?id=$studentId");
        exit();
    }
}

// Prepare data for update
$studentData = [
    'lrn' => trim($_POST['lrn']),
    'last_name' => trim($_POST['last_name']),
    'first_name' => trim($_POST['first_name']),
    'middle_name' => trim($_POST['middle_name'] ?? ''),
    'extension_name' => trim($_POST['extension_name'] ?? ''),
    'birth_date' => $_POST['birth_date'],
    'age' => (int)$_POST['age'],
    'gender' => $_POST['gender'],
    'birth_place' => trim($_POST['birth_place'] ?? ''),
    'nationality' => trim($_POST['nationality'] ?? ''),
    'religion' => trim($_POST['religion'] ?? ''),
    'ethnic_group' => trim($_POST['ethnic_group'] ?? ''),
    'contact_number' => trim($_POST['contact_number'] ?? ''),
    'current_address' => trim($_POST['current_address']),
    'current_barangay_id' => (int)$_POST['current_barangay_id'],
    'permanent_address' => isset($_POST['permanent_address_same']) && $_POST['permanent_address_same'] ? 
        trim($_POST['current_address']) : trim($_POST['permanent_address'] ?? ''),
    'permanent_barangay_id' => isset($_POST['permanent_address_same']) && $_POST['permanent_address_same'] ? 
        (int)$_POST['current_barangay_id'] : (int)($_POST['permanent_barangay_id'] ?? 0),
    'permanent_country' => trim($_POST['permanent_country'] ?? ''),
    'permanent_zip_code' => trim($_POST['permanent_zip_code'] ?? ''),
    'father_last_name' => trim($_POST['father_last_name'] ?? ''),
    'father_first_name' => trim($_POST['father_first_name'] ?? ''),
    'father_middle_name' => trim($_POST['father_middle_name'] ?? ''),
    'father_occupation' => trim($_POST['father_occupation'] ?? ''),
    'mother_last_name' => trim($_POST['mother_last_name'] ?? ''),
    'mother_first_name' => trim($_POST['mother_first_name'] ?? ''),
    'mother_middle_name' => trim($_POST['mother_middle_name'] ?? ''),
    'mother_occupation' => trim($_POST['mother_occupation'] ?? ''),
    'guardian_last_name' => trim($_POST['guardian_last_name'] ?? ''),
    'guardian_first_name' => trim($_POST['guardian_first_name'] ?? ''),
    'guardian_middle_name' => trim($_POST['guardian_middle_name'] ?? ''),
    'guardian_occupation' => trim($_POST['guardian_occupation'] ?? ''),
    'last_grade_completed' => trim($_POST['last_grade_completed'] ?? ''),
    'education_level' => trim($_POST['education_level'] ?? ''),
    'is_pwd' => isset($_POST['is_pwd']) ? 1 : 0,
    'pwd_type' => isset($_POST['is_pwd']) ? trim($_POST['pwd_type'] ?? '') : '',
    'has_pwd_id' => isset($_POST['has_pwd_id']) ? 1 : 0,
    'enrollment_date' => $_POST['enrollment_date'],
    'has_birth_certificate' => isset($_POST['has_birth_certificate']) ? 1 : 0,
    'has_report_card' => isset($_POST['has_report_card']) ? 1 : 0,
    'has_good_moral' => isset($_POST['has_good_moral']) ? 1 : 0,
    'has_photo' => isset($_POST['has_photo']) ? 1 : 0,
    'teacher_id' => !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null,
    'status' => trim($_POST['status'] ?? 'active')
];

// Handle file upload (photo)
if (!empty($_FILES['photo']['name'])) {
    $uploadDir = '../../uploads/students/';
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $fileName = uniqid() . '_' . basename($_FILES['photo']['name']);
    $targetPath = $uploadDir . $fileName;
    
    if (move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
        $studentData['photo_path'] = $targetPath;
        
        // Delete old photo if exists
        $stmt = $conn->prepare("SELECT photo_path FROM students WHERE student_id = ?");
        $stmt->bind_param("i", $studentId);
        $stmt->execute();
        $result = $stmt->get_result();
        $oldPhoto = $result->fetch_assoc()['photo_path'];
        
        if (!empty($oldPhoto) && file_exists($oldPhoto)) {
            unlink($oldPhoto);
        }
    }
}

// Update student in database
try {
    $conn->begin_transaction();
    
    $sql = "UPDATE students SET 
        lrn = ?, last_name = ?, first_name = ?, middle_name = ?, extension_name = ?,
        birth_date = ?, age = ?, gender = ?, birth_place = ?, nationality = ?,
        religion = ?, ethnic_group = ?, contact_number = ?, current_address = ?,
        current_barangay_id = ?, permanent_address = ?, permanent_barangay_id = ?,
        permanent_country = ?, permanent_zip_code = ?, father_last_name = ?,
        father_first_name = ?, father_middle_name = ?, father_occupation = ?,
        mother_last_name = ?, mother_first_name = ?, mother_middle_name = ?,
        mother_occupation = ?, guardian_last_name = ?, guardian_first_name = ?,
        guardian_middle_name = ?, guardian_occupation = ?, last_grade_completed = ?,
        education_level = ?, is_pwd = ?, pwd_type = ?, has_pwd_id = ?,
        enrollment_date = ?, has_birth_certificate = ?, has_report_card = ?,
        has_good_moral = ?, has_photo = ?, teacher_id = ?, status = ?";
    
    // Add photo_path if updated
    if (isset($studentData['photo_path'])) {
        $sql .= ", photo_path = ?";
    }
    
    $sql .= " WHERE student_id = ?";
    
    $stmt = $conn->prepare($sql);
    
    $params = [
        $studentData['lrn'], $studentData['last_name'], $studentData['first_name'],
        $studentData['middle_name'], $studentData['extension_name'], $studentData['birth_date'],
        $studentData['age'], $studentData['gender'], $studentData['birth_place'],
        $studentData['nationality'], $studentData['religion'], $studentData['ethnic_group'],
        $studentData['contact_number'], $studentData['current_address'],
        $studentData['current_barangay_id'], $studentData['permanent_address'],
        $studentData['permanent_barangay_id'], $studentData['permanent_country'],
        $studentData['permanent_zip_code'], $studentData['father_last_name'],
        $studentData['father_first_name'], $studentData['father_middle_name'],
        $studentData['father_occupation'], $studentData['mother_last_name'],
        $studentData['mother_first_name'], $studentData['mother_middle_name'],
        $studentData['mother_occupation'], $studentData['guardian_last_name'],
        $studentData['guardian_first_name'], $studentData['guardian_middle_name'],
        $studentData['guardian_occupation'], $studentData['last_grade_completed'],
        $studentData['education_level'], $studentData['is_pwd'], $studentData['pwd_type'],
        $studentData['has_pwd_id'], $studentData['enrollment_date'],
        $studentData['has_birth_certificate'], $studentData['has_report_card'],
        $studentData['has_good_moral'], $studentData['has_photo'],
        $studentData['teacher_id'], $studentData['status']
    ];
    
    if (isset($studentData['photo_path'])) {
        $params[] = $studentData['photo_path'];
    }
    
    $params[] = $studentId;
    
    $stmt->bind_param(str_repeat('s', count($params)), ...$params);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("No changes made or student not found");
    }
    
    $conn->commit();
    
    $_SESSION['success'] = "Student updated successfully";
    header("Location: view.php?id=$studentId");
    exit();
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = "Error updating student: " . $e->getMessage();
    header("Location: edit.php?id=$studentId");
    exit();
}