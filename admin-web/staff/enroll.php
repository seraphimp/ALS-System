<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Include QR code library
require_once '../../phpqrcode/qrlib.php';

secure_session_start();
if (!is_staff_logged_in()) {
    header('Location: login.php');
    exit();
}

// Clear enrolled student data from session if page is refreshed without the success parameter
if (!isset($_GET['success']) && isset($_SESSION['enrolled_student'])) {
    unset($_SESSION['enrolled_student']);
}

$page_title = "ALS Enrollment System - New Enrollment";
$staff_id = $_SESSION['staff_id'];

// Function to generate the next student ID
function generateStudentId($conn) {
    $current_year = date('Y');
    $prefix = "ALS-$current_year-year-";
    
    // Get the latest student ID for this year
    $sql = "SELECT student_id FROM students WHERE student_id LIKE '$prefix%' ORDER BY student_id DESC LIMIT 1";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $last_id = $result->fetch_assoc()['student_id'];
        $last_number = intval(substr($last_id, -3));
        $next_number = $last_number + 1;
    } else {
        $next_number = 1;
    }
    
    return $prefix . str_pad($next_number, 3, '0', STR_PAD_LEFT);
}

// Get all active barangays
$barangays = $conn->query("SELECT * FROM barangays WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Initialize form data with complete fields
$formData = [
    'student_id' => generateStudentId($conn),
    'lrn' => '',
    'enrollment_date' => date('Y-m-d'),
    
    // Personal Information
    'last_name' => '',
    'first_name' => '',
    'middle_name' => '',
    'extension_name' => '',
    'contact_number' => '',
    'birthdate' => '',
    'age' => '',
    'sex' => 'male',
    'place_of_birth' => '',
    'religion' => '',
    'nationality' => 'Filipino',
    'ethnicity' => '',
    'mother_tongue' => '',
    'indigenous_community' => '',
    'four_ps_beneficiary' => 'no',
    'four_ps_id_number' => '',
    'civil_status' => 'single',
    
    // Address
    'current_house_no' => '',
    'current_street' => '',
    'current_barangay_id' => '',
    'current_city' => 'La Carlota City',
    'current_province' => 'Negros Occidental',
    'current_country' => 'Philippines',
    'current_zip' => '6130',
    'same_address' => 'yes',
    'permanent_house_no' => '',
    'permanent_street' => '',
    'permanent_barangay_id' => '',
    'permanent_city' => 'La Carlota City',
    'permanent_province' => 'Negros Occidental',
    'permanent_country' => 'Philippines',
    'permanent_zip' => '6130',
    
    // Parents/Guardians
    'father_last_name' => '',
    'father_first_name' => '',
    'father_middle_name' => '',
    'father_occupation' => '',
    'father_contact' => '',
    'mother_last_name' => '',
    'mother_first_name' => '',
    'mother_middle_name' => '',
    'mother_occupation' => '',
    'mother_contact' => '',
    'guardian_name' => '',
    'guardian_relationship' => '',
    'guardian_contact' => '',
    'guardian_address' => '',
    
    // Disability
    'is_pwd' => 'no',
    'disability_type' => '',
    'disability_details' => '',
    'has_pwd_id' => 'no',
    'pwd_id_number' => '',
    
    // Education
    'last_grade_level' => '',
    'last_school_attended' => '',
    'last_school_year' => '',
    'reason_not_in_school' => '',
    'reason_other' => '',
    'attended_als_before' => 'no',
    'als_program' => '',
    'level_of_literacy' => '',
    'incomplete_reason' => '',
    
    // Accessibility
    'distance_to_clc_km' => '',
    'distance_to_clc_time' => '',
    'transport_mode' => 'walking',
    'transport_mode_other' => '',
    'availability_schedule' => '',
    
    // Learning Modalities
    'prefers_blended' => 'no',
    'prefers_homeschooling' => 'no',
    'prefers_modular_print' => 'no',
    'prefers_modular_digital' => 'no',
    'prefers_online' => 'no',
    'prefers_radio_tv' => 'no',
    'prefers_edu_tv' => 'no',
    
    // Medical Information
    'blood_type' => '',
    'medical_conditions' => '',
    'allergies' => '',
    'medications' => '',
    
    'status' => 'enrolled'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and collect form data
    $formData = [
        'student_id' => clean_input($_POST['student_id'] ?? ''),
        'lrn' => clean_input($_POST['lrn'] ?? ''),
        'enrollment_date' => clean_input($_POST['enrollment_date'] ?? date('Y-m-d')),
        
        // Personal Information
        'last_name' => clean_input($_POST['last_name'] ?? ''),
        'first_name' => clean_input($_POST['first_name'] ?? ''),
        'middle_name' => clean_input($_POST['middle_name'] ?? ''),
        'extension_name' => clean_input($_POST['extension_name'] ?? ''),
        'contact_number' => clean_input($_POST['contact_number'] ?? ''),
        'birthdate' => clean_input($_POST['birthdate'] ?? ''),
        'age' => (int)($_POST['age'] ?? 0),
        'sex' => clean_input($_POST['sex'] ?? 'male'),
        'place_of_birth' => clean_input($_POST['place_of_birth'] ?? ''),
        'religion' => clean_input($_POST['religion'] ?? ''),
        'nationality' => clean_input($_POST['nationality'] ?? 'Filipino'),
        'ethnicity' => clean_input($_POST['ethnicity'] ?? ''),
        'mother_tongue' => clean_input($_POST['mother_tongue'] ?? ''),
        'indigenous_community' => clean_input($_POST['indigenous_community'] ?? ''),
        'four_ps_beneficiary' => clean_input($_POST['four_ps_beneficiary'] ?? 'no'),
        'four_ps_id_number' => clean_input($_POST['four_ps_id_number'] ?? ''),
        'civil_status' => clean_input($_POST['civil_status'] ?? 'single'),
        
        // Address
        'current_house_no' => clean_input($_POST['current_house_no'] ?? ''),
        'current_street' => clean_input($_POST['current_street'] ?? ''),
        'current_barangay_id' => (int)($_POST['current_barangay_id'] ?? 0),
        'current_city' => clean_input($_POST['current_city'] ?? 'La Carlota City'),
        'current_province' => clean_input($_POST['current_province'] ?? 'Negros Occidental'),
        'current_country' => clean_input($_POST['current_country'] ?? 'Philippines'),
        'current_zip' => clean_input($_POST['current_zip'] ?? '6130'),
        'same_address' => clean_input($_POST['same_address'] ?? 'yes'),
        'permanent_house_no' => clean_input($_POST['permanent_house_no'] ?? ''),
        'permanent_street' => clean_input($_POST['permanent_street'] ?? ''),
        'permanent_barangay_id' => (int)($_POST['permanent_barangay_id'] ?? 0),
        'permanent_city' => clean_input($_POST['permanent_city'] ?? 'La Carlota City'),
        'permanent_province' => clean_input($_POST['permanent_province'] ?? 'Negros Occidental'),
        'permanent_country' => clean_input($_POST['permanent_country'] ?? 'Philippines'),
        'permanent_zip' => clean_input($_POST['permanent_zip'] ?? '6130'),
        
        // Parents/Guardians
        'father_last_name' => clean_input($_POST['father_last_name'] ?? ''),
        'father_first_name' => clean_input($_POST['father_first_name'] ?? ''),
        'father_middle_name' => clean_input($_POST['father_middle_name'] ?? ''),
        'father_occupation' => clean_input($_POST['father_occupation'] ?? ''),
        'father_contact' => clean_input($_POST['father_contact'] ?? ''),
        'mother_last_name' => clean_input($_POST['mother_last_name'] ?? ''),
        'mother_first_name' => clean_input($_POST['mother_first_name'] ?? ''),
        'mother_middle_name' => clean_input($_POST['mother_middle_name'] ?? ''),
        'mother_occupation' => clean_input($_POST['mother_occupation'] ?? ''),
        'mother_contact' => clean_input($_POST['mother_contact'] ?? ''),
        'guardian_name' => clean_input($_POST['guardian_name'] ?? ''),
        'guardian_relationship' => clean_input($_POST['guardian_relationship'] ?? ''),
        'guardian_contact' => clean_input($_POST['guardian_contact'] ?? ''),
        'guardian_address' => clean_input($_POST['guardian_address'] ?? ''),
        
        // Disability
        'is_pwd' => clean_input($_POST['is_pwd'] ?? 'no'),
        'disability_type' => clean_input($_POST['disability_type'] ?? ''),
        'disability_details' => clean_input($_POST['disability_details'] ?? ''),
        'has_pwd_id' => clean_input($_POST['has_pwd_id'] ?? 'no'),
        'pwd_id_number' => clean_input($_POST['pwd_id_number'] ?? ''),
        
        // Education
        'last_grade_level' => clean_input($_POST['last_grade_level'] ?? ''),
        'last_school_attended' => clean_input($_POST['last_school_attended'] ?? ''),
        'last_school_year' => clean_input($_POST['last_school_year'] ?? ''),
        'reason_not_in_school' => clean_input($_POST['reason_not_in_school'] ?? ''),
        'reason_other' => clean_input($_POST['reason_other'] ?? ''),
        'attended_als_before' => clean_input($_POST['attended_als_before'] ?? 'no'),
        'als_program' => clean_input($_POST['als_program'] ?? ''),
        'level_of_literacy' => clean_input($_POST['level_of_literacy'] ?? ''),
        'incomplete_reason' => clean_input($_POST['incomplete_reason'] ?? ''),
        
        // Accessibility
        'distance_to_clc_km' => clean_input($_POST['distance_to_clc_km'] ?? ''),
        'distance_to_clc_time' => clean_input($_POST['distance_to_clc_time'] ?? ''),
        'transport_mode' => clean_input($_POST['transport_mode'] ?? 'walking'),
        'transport_mode_other' => clean_input($_POST['transport_mode_other'] ?? ''),
        'availability_schedule' => $_POST['availability_schedule'] ?? '',
        
        // Learning Modalities
        'prefers_blended' => clean_input($_POST['prefers_blended'] ?? 'no'),
        'prefers_homeschooling' => clean_input($_POST['prefers_homeschooling'] ?? 'no'),
        'prefers_modular_print' => clean_input($_POST['prefers_modular_print'] ?? 'no'),
        'prefers_modular_digital' => clean_input($_POST['prefers_modular_digital'] ?? 'no'),
        'prefers_online' => clean_input($_POST['prefers_online'] ?? 'no'),
        'prefers_radio_tv' => clean_input($_POST['prefers_radio_tv'] ?? 'no'),
        'prefers_edu_tv' => clean_input($_POST['prefers_edu_tv'] ?? 'no'),
        
        // Medical Information
        'blood_type' => clean_input($_POST['blood_type'] ?? ''),
        'medical_conditions' => clean_input($_POST['medical_conditions'] ?? ''),
        'allergies' => clean_input($_POST['allergies'] ?? ''),
        'medications' => clean_input($_POST['medications'] ?? ''),
        
        'status' => 'enrolled'
    ];

    if(empty(trim($formData['lrn']))){
        $formData['lrn'] = null;
    }

    // If permanent address is same as current address
    if ($formData['same_address'] === 'yes') {
        $formData['permanent_house_no'] = $formData['current_house_no'];
        $formData['permanent_street'] = $formData['current_street'];
        $formData['permanent_barangay_id'] = $formData['current_barangay_id'];
        $formData['permanent_city'] = $formData['current_city'];
        $formData['permanent_province'] = $formData['current_province'];
        $formData['permanent_country'] = $formData['current_country'];
        $formData['permanent_zip'] = $formData['current_zip'];
    }

    // Validate required fields
    $errors = [];
    if (empty($formData['last_name'])) $errors[] = "Last name is required";
    if (empty($formData['first_name'])) $errors[] = "First name is required";
    if (empty($formData['birthdate'])) $errors[] = "Birth date is required";
    if (empty($formData['current_barangay_id'])) $errors[] = "Current barangay is required";
    if (empty($formData['als_program'])) $errors[] = "ALS Program is required";

    if (empty($errors)) {
        try {
            // Get teacher assigned to the barangay
            $teacher_id = null;
            if ($formData['current_barangay_id']) {
                $stmt = $conn->prepare("SELECT teacher_id FROM teachers WHERE barangay_id = ? AND status = 'active' LIMIT 1");
                $stmt->bind_param("i", $formData['current_barangay_id']);
                $stmt->execute();
                $result = $stmt->get_result();
                if ($result->num_rows > 0) {
                    $teacher = $result->fetch_assoc();
                    $teacher_id = $teacher['teacher_id'];
                }
            }

            // Insert student with complete fields
            $stmt = $conn->prepare("INSERT INTO students (
                student_id, lrn, enrollment_date, last_name, first_name, middle_name, extension_name,
                contact_number, birthdate, age, sex, place_of_birth, religion, nationality, ethnicity,
                mother_tongue, indigenous_community, four_ps_beneficiary, four_ps_id_number, civil_status,
                current_house_no, current_street, current_barangay_id, current_city, current_province,
                current_country, current_zip, same_address, permanent_house_no, permanent_street,
                permanent_barangay_id, permanent_city, permanent_province, permanent_country, permanent_zip,
                father_last_name, father_first_name, father_middle_name, father_occupation, father_contact,
                mother_last_name, mother_first_name, mother_middle_name, mother_occupation, mother_contact,
                guardian_name, guardian_relationship, guardian_contact, guardian_address,
                is_pwd, disability_type, disability_details, has_pwd_id, pwd_id_number,
                last_grade_level, last_school_attended, last_school_year, reason_not_in_school, reason_other,
                attended_als_before, als_program, level_of_literacy, incomplete_reason,
                distance_to_clc_km, distance_to_clc_time, transport_mode, transport_mode_other,
                availability_schedule, prefers_blended, prefers_homeschooling, prefers_modular_print,
                prefers_modular_digital, prefers_online, prefers_radio_tv, prefers_edu_tv,
                blood_type, medical_conditions, allergies, medications,
                teacher_id, processed_by, status, qr_code
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )");

            $qr_code = '';

            $stmt->bind_param(
                "sssssssssisssssssssssssissssssissssssssssssssssssssssssssssssssssssssssssssssssssssssiss",
                $formData['student_id'],
                $formData['lrn'],
                $formData['enrollment_date'],
                $formData['last_name'],
                $formData['first_name'],
                $formData['middle_name'],
                $formData['extension_name'],
                $formData['contact_number'],
                $formData['birthdate'],
                $formData['age'],
                $formData['sex'],
                $formData['place_of_birth'],
                $formData['religion'],
                $formData['nationality'],
                $formData['ethnicity'],
                $formData['mother_tongue'],
                $formData['indigenous_community'],
                $formData['four_ps_beneficiary'],
                $formData['four_ps_id_number'],
                $formData['civil_status'],
                $formData['current_house_no'],
                $formData['current_street'],
                $formData['current_barangay_id'],
                $formData['current_city'],
                $formData['current_province'],
                $formData['current_country'],
                $formData['current_zip'],
                $formData['same_address'],
                $formData['permanent_house_no'],
                $formData['permanent_street'],
                $formData['permanent_barangay_id'],
                $formData['permanent_city'],
                $formData['permanent_province'],
                $formData['permanent_country'],
                $formData['permanent_zip'],
                $formData['father_last_name'],
                $formData['father_first_name'],
                $formData['father_middle_name'],
                $formData['father_occupation'],
                $formData['father_contact'],
                $formData['mother_last_name'],
                $formData['mother_first_name'],
                $formData['mother_middle_name'],
                $formData['mother_occupation'],
                $formData['mother_contact'],
                $formData['guardian_name'],
                $formData['guardian_relationship'],
                $formData['guardian_contact'],
                $formData['guardian_address'],
                $formData['is_pwd'],
                $formData['disability_type'],
                $formData['disability_details'],
                $formData['has_pwd_id'],
                $formData['pwd_id_number'],
                $formData['last_grade_level'],
                $formData['last_school_attended'],
                $formData['last_school_year'],
                $formData['reason_not_in_school'],
                $formData['reason_other'],
                $formData['attended_als_before'],
                $formData['als_program'],
                $formData['level_of_literacy'],
                $formData['incomplete_reason'],
                $formData['distance_to_clc_km'],
                $formData['distance_to_clc_time'],
                $formData['transport_mode'],
                $formData['transport_mode_other'],
                $formData['availability_schedule'],
                $formData['prefers_blended'],
                $formData['prefers_homeschooling'],
                $formData['prefers_modular_print'],
                $formData['prefers_modular_digital'],
                $formData['prefers_online'],
                $formData['prefers_radio_tv'],
                $formData['prefers_edu_tv'],
                $formData['blood_type'],
                $formData['medical_conditions'],
                $formData['allergies'],
                $formData['medications'],
                $teacher_id,
                $staff_id,
                $formData['status'],
                $qr_code
            );

            if ($stmt->execute()) {
                $student_id = $formData['student_id'];
                
                // Generate QR code
                $qrData = "ALS Student: " . $formData['student_id'] . "\n";
                $qrData .= "Name: " . $formData['last_name'] . ", " . $formData['first_name'] . " " . $formData['middle_name'] . "\n";
                $qrData .= "LRN: " . $formData['lrn'] . "\n";
                $qrData .= "Barangay: " . getBarangayName($conn, $formData['current_barangay_id']);
                
                $qrDir = __DIR__ . '/../qrcodes/';
                if (!file_exists($qrDir)) {
                    mkdir($qrDir, 0777, true);
                }
                
                $qrFile = $qrDir . $student_id . '.png';
                QRcode::png($qrData, $qrFile, QR_ECLEVEL_L, 10);
                
                // Update student record with QR code path
                $qrPath = '../qrcodes/' . $student_id . '.png';
                $updateStmt = $conn->prepare("UPDATE students SET qr_code = ? WHERE student_id = ?");
                $updateStmt->bind_param("ss", $qrPath, $student_id);
                $updateStmt->execute();
                
                // Store student data in session for modal display
                $_SESSION['enrolled_student'] = [
                    'student_id' => $student_id,
                    'full_name' => $formData['last_name'] . ', ' . $formData['first_name'] . ' ' . $formData['middle_name'],
                    'qr_code' => $qrPath,
                    'form_data' => $formData
                ];
                
                // Set success message
                $_SESSION['success'] = "Student enrolled successfully!";
                
                // Redirect back to the same page with success parameter
                header("Location: enroll.php?success=1");
                exit();
            } else {
                throw new Exception("Failed to enroll student: " . $stmt->error);
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        $_SESSION['form_data'] = $formData;
        header("Location: enroll.php");
        exit();
    }
}

// Helper function to get barangay name
function getBarangayName($conn, $barangay_id) {
    if (!$barangay_id) return '';
    
    $stmt = $conn->prepare("SELECT name FROM barangays WHERE barangay_id = ?");
    $stmt->bind_param("i", $barangay_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $barangay = $result->fetch_assoc();
        return $barangay['name'];
    }
    
    return '';
}

// Check for stored form data from failed submission
if (isset($_SESSION['form_data'])) {
    $formData = array_merge($formData, $_SESSION['form_data']);
    unset($_SESSION['form_data']);
}

// Calculate age if birth date is set
if (!empty($formData['birthdate'])) {
    $birthDate = new DateTime($formData['birthdate']);
    $today = new DateTime();
    $age = $today->diff($birthDate)->y;
    $formData['age'] = $age;
}

// Civil status options
$civilStatuses = [
    'single' => 'Single',
    'married' => 'Married',
    'separated' => 'Separated',
    'widow/er' => 'Widow/Widower',
    'solo parent' => 'Solo Parent'
];

// ALS Programs
$alsPrograms = [
    '' => 'Select Program',
    'basic literacy' => 'Basic Literacy',
    'a&e elementary' => 'A&E Elementary',
    'a&e secondary' => 'A&E Secondary',
    'als-shs' => 'ALS-SHS'
];

// Transport modes
$transportModes = [
    'walking' => 'Walking',
    'motorcycle' => 'Motorcycle',
    'bicycle' => 'Bicycle',
    'others' => 'Others'
];

// Disability types
$disabilityTypes = [
    '' => 'Select Type',
    'visual' => 'Visual Impairment',
    'hearing' => 'Hearing Impairment',
    'physical' => 'Physical Disability',
    'intellectual' => 'Intellectual Disability',
    'learning' => 'Learning Disability',
    'mental' => 'Mental Health Condition',
    'speech' => 'Speech/Language Impairment',
    'multiple' => 'Multiple Disabilities',
    'other' => 'Other'
];

// Blood types
$bloodTypes = [
    '' => 'Select Blood Type',
    'A+' => 'A+',
    'A-' => 'A-',
    'B+' => 'B+',
    'B-' => 'B-',
    'AB+' => 'AB+',
    'AB-' => 'AB-',
    'O+' => 'O+',
    'O-' => 'O-'
];

// Guardian relationships
$guardianRelationships = [
    '' => 'Select Relationship',
    'parent' => 'Parent',
    'grandparent' => 'Grandparent',
    'sibling' => 'Sibling',
    'aunt_uncle' => 'Aunt/Uncle',
    'other_relative' => 'Other Relative',
    'family_friend' => 'Family Friend',
    'foster_parent' => 'Foster Parent',
    'other' => 'Other'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #0056b3;
            --secondary-color: #ffcc00;
            --light-blue: #e6f0ff;
            --dark-blue: #003d82;
            --border-radius: 0.375rem;
            --box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, var(--light-blue) 0%, #ffffff 100%);
            color: #333;
            line-height: 1.6;
            background-attachment: fixed;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 0 1rem;
        }
        
        .header-container {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: white;
            padding: 1rem 2rem;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
        }
        
        .logo-container {
            display: flex;
            align-items: center;
        }
        
        .logo-text {
            color: var(--primary-color);
            display: flex;
            flex-direction: column;
            margin-left: 1rem;
        }
        
        .logo {
            width: 60px;
            height: 60px;
        }
        
        .logo img {
            width: 100%;
            height: auto;
        }
        
        .logo-title h1 {
            font-size: 1.5rem;
            margin: 0;
            font-weight: 700;
        }
        
        .logo-title p {
            margin: 0;
            font-size: 0.9rem;
            color: #666;
        }
        
        .back-btn {
            padding: 0.5rem 1rem;
            background-color: #6c757d;
            color: white;
            border: none;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s;
        }
        
        .back-btn:hover {
            background-color: #5a6268;
        }
        
        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin-bottom: 2rem;
            overflow: hidden;
            border-top: 5px solid var(--primary-color);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--dark-blue) 100%);
            color: white;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(0, 0, 0, 0.125);
        }
        
        .card-header h2 {
            margin: 0;
            font-size: 1.25rem;
            font-weight: 500;
        }
        
        .card-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }
        
        .alert {
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .student-id-display {
            background-color: var(--light-blue);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary-color);
        }
        
        .student-id-display h3 {
            margin: 0;
            color: var(--primary-color);
            font-size: 1.25rem;
        }
        
        .student-id-display p {
            margin: 0.5rem 0 0 0;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #555;
        }
        
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="number"],
        .form-group input[type="tel"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 1rem;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--primary-color);
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(0, 86, 179, 0.25);
        }
        
        .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        @media (min-width: 768px) {
            .form-row {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row-3 {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .form-row-4 {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: var(--border-radius);
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-reset {
            background-color: #6c757d;
            color: white;
        }
        
        .btn-reset:hover {
            background-color: #5a6268;
        }
        
        .btn-submit {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-submit:hover {
            background-color: var(--dark-blue);
        }
        
        .footer {
            text-align: center;
            padding: 1.5rem;
            color: #666;
            font-size: 0.9rem;
        }
        
        .section-title {
            color: var(--primary-color);
            border-bottom: 2px solid var(--secondary-color);
            padding-bottom: 0.5rem;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        
        .form-check input {
            margin-right: 0.5rem;
        }
        
        /* Modal Styles */
        .modal {
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            border-top: 5px solid var(--primary-color);
        }

        .modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--dark-blue) 100%);
            color: white;
        }

        .modal-header h2 {
            margin: 0;
            font-size: 1.5rem;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1px solid #dee2e6;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }

        .close {
            color: white;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #ccc;
        }

        .student-info {
            text-align: center;
            margin-bottom: 1.5rem;
        }

        .student-info h3 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-color);
        }

        .student-info p {
            margin: 0;
            color: #666;
        }

        .qr-code-container {
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .qr-code-container img {
            max-width: 200px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
        }
        
        .tabs {
            display: flex;
            flex-wrap: wrap;
            border-bottom: 2px solid var(--primary-color);
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }
        
        .tab {
            padding: 0.75rem 1.5rem;
            cursor: pointer;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: var(--border-radius) var(--border-radius) 0 0;
            margin-right: 0.5rem;
            transition: all 0.2s;
            white-space: nowrap;
            background: #f0f0f0;
        }
        
        .tab:hover {
            background-color: #e0e0e0;
        }
        
        .tab.active {
            background-color: white;
            border-color: var(--primary-color);
            border-bottom-color: white;
            color: var(--primary-color);
            font-weight: 500;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .schedule-table {
            overflow-x: auto;
        }
        
        .schedule-table table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        .schedule-table th,
        .schedule-table td {
            padding: 10px;
            border: 1px solid #dee2e6;
            text-align: center;
        }
        
        .schedule-table th {
            background-color: #f8f9fa;
            font-weight: 500;
        }
        
        .schedule-table input[type="time"] {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 100%;
            max-width: 150px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-container">
            <div class="logo-container">
                <div class="logo">
                    <img src="../../logo/als-logo-removebg-preview.png" alt="ALS Logo">
                </div>
                <div class="logo-text">
                    <div class="logo-title">
                        <h1>Alternative Learning System</h1>
                        <p>La Carlota City Division - New Enrollment</p>
                    </div>
                </div>
            </div>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h2>New Student Enrollment</h2>
            </div>
            
            <div class="card-body">
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?>
                        <?php unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?>
                        <?php unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <div class="student-id-display">
                    <h3>Student ID: <?= htmlspecialchars($formData['student_id']) ?></h3>
                    <p>This ID will be automatically assigned to the student</p>
                </div>
                
                <form method="post" id="enrollmentForm">
                    <input type="hidden" name="student_id" value="<?= htmlspecialchars($formData['student_id']) ?>">
                    <input type="hidden" id="availability_schedule" name="availability_schedule" value="<?= htmlspecialchars($formData['availability_schedule']) ?>">
                    
                    <div class="tabs">
                        <div class="tab active" data-tab="personal">Personal Information</div>
                        <div class="tab" data-tab="address">Address Information</div>
                        <div class="tab" data-tab="parents">Parent/Guardian Information</div>
                        <div class="tab" data-tab="education">Education & Disability</div>
                        <div class="tab" data-tab="accessibility">Accessibility & Learning</div>
                        <div class="tab" data-tab="medical">Medical Information</div>
                    </div>
                    
                    <!-- Personal Information Tab -->
                    <div class="tab-content active" id="personalTab">
                        <h3 class="section-title">Personal Details</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="lrn">Learner Reference No. (LRN) <small>(Optional)</small></label>
                                <input type="text" id="lrn" name="lrn" value="<?= htmlspecialchars($formData['lrn']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="enrollment_date">Enrollment Date</label>
                                <input type="date" id="enrollment_date" name="enrollment_date" value="<?= htmlspecialchars($formData['enrollment_date']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label for="last_name" class="required-field">Last Name</label>
                                <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($formData['last_name']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="first_name" class="required-field">First Name</label>
                                <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($formData['first_name']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($formData['middle_name']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="extension_name">Extension Name (Jr., III, etc.)</label>
                                <input type="text" id="extension_name" name="extension_name" value="<?= htmlspecialchars($formData['extension_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="contact_number">Contact Number</label>
                                <input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars($formData['contact_number']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label for="birthdate" class="required-field">Birth Date</label>
                                <input type="date" id="birthdate" name="birthdate" value="<?= htmlspecialchars($formData['birthdate']) ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="age">Age</label>
                                <input type="number" id="age" name="age" readonly value="<?= htmlspecialchars($formData['age']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="sex">Sex</label>
                                <select id="sex" name="sex">
                                    <option value="male" <?= $formData['sex'] === 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= $formData['sex'] === 'female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="civil_status">Civil Status</label>
                                <select id="civil_status" name="civil_status">
                                    <?php foreach ($civilStatuses as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $formData['civil_status'] === $value ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="nationality">Nationality</label>
                                <input type="text" id="nationality" name="nationality" value="<?= htmlspecialchars($formData['nationality']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="ethnicity">Ethnicity</label>
                                <input type="text" id="ethnicity" name="ethnicity" value="<?= htmlspecialchars($formData['ethnicity']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="religion">Religion</label>
                                <input type="text" id="religion" name="religion" value="<?= htmlspecialchars($formData['religion']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="place_of_birth">Place of Birth</label>
                                <input type="text" id="place_of_birth" name="place_of_birth" value="<?= htmlspecialchars($formData['place_of_birth']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="mother_tongue">Mother Tongue</label>
                                <input type="text" id="mother_tongue" name="mother_tongue" value="<?= htmlspecialchars($formData['mother_tongue']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="indigenous_community">Indigenous Community</label>
                                <input type="text" id="indigenous_community" name="indigenous_community" value="<?= htmlspecialchars($formData['indigenous_community']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="four_ps_beneficiary">4Ps Beneficiary</label>
                                <select id="four_ps_beneficiary" name="four_ps_beneficiary">
                                    <option value="no" <?= $formData['four_ps_beneficiary'] === 'no' ? 'selected' : '' ?>>No</option>
                                    <option value="yes" <?= $formData['four_ps_beneficiary'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="four_ps_id_number">4Ps ID Number</label>
                                <input type="text" id="four_ps_id_number" name="four_ps_id_number" 
                                       value="<?= htmlspecialchars($formData['four_ps_id_number']) ?>"
                                       <?= $formData['four_ps_beneficiary'] === 'no' ? 'disabled' : '' ?>>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Address Information Tab -->
                    <div class="tab-content" id="addressTab">
                        <h3 class="section-title">Current Address</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="current_house_no">House No./Lot/Bldg.</label>
                                <input type="text" id="current_house_no" name="current_house_no" value="<?= htmlspecialchars($formData['current_house_no']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="current_street">Street</label>
                                <input type="text" id="current_street" name="current_street" value="<?= htmlspecialchars($formData['current_street']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="current_barangay_id" class="required-field">Barangay</label>
                                <select id="current_barangay_id" name="current_barangay_id" required>
                                    <option value="">Select Barangay</option>
                                    <?php foreach ($barangays as $barangay): ?>
                                        <option value="<?= $barangay['barangay_id'] ?>" 
                                                <?= $formData['current_barangay_id'] == $barangay['barangay_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($barangay['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label for="current_city">City</label>
                                <input type="text" id="current_city" name="current_city" value="<?= htmlspecialchars($formData['current_city']) ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="current_province">Province</label>
                                <input type="text" id="current_province" name="current_province" value="<?= htmlspecialchars($formData['current_province']) ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label for="current_zip">ZIP Code</label>
                                <input type="text" id="current_zip" name="current_zip" value="<?= htmlspecialchars($formData['current_zip']) ?>" readonly>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <div class="form-check">
                                <input type="checkbox" id="same_address" name="same_address" value="yes" <?= $formData['same_address'] === 'yes' ? 'checked' : '' ?>>
                                <label for="same_address">Permanent address is same as current address</label>
                            </div>
                        </div>
                        
                        <div id="permanent_address_section" style="<?= $formData['same_address'] === 'yes' ? 'display: none;' : '' ?>">
                            <h3 class="section-title">Permanent Address</h3>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="permanent_house_no">House No./Lot/Bldg.</label>
                                    <input type="text" id="permanent_house_no" name="permanent_house_no" value="<?= htmlspecialchars($formData['permanent_house_no']) ?>">
                                </div>
                                <div class="form-group">
                                    <label for="permanent_street">Street</label>
                                    <input type="text" id="permanent_street" name="permanent_street" value="<?= htmlspecialchars($formData['permanent_street']) ?>">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="permanent_barangay_id">Barangay</label>
                                    <select id="permanent_barangay_id" name="permanent_barangay_id">
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?= $barangay['barangay_id'] ?>" 
                                                    <?= $formData['permanent_barangay_id'] == $barangay['barangay_id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($barangay['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row form-row-3">
                                <div class="form-group">
                                    <label for="permanent_city">City</label>
                                    <input type="text" id="permanent_city" name="permanent_city" value="<?= htmlspecialchars($formData['permanent_city']) ?>">
                                </div>
                                <div class="form-group">
                                    <label for="permanent_province">Province</label>
                                    <input type="text" id="permanent_province" name="permanent_province" value="<?= htmlspecialchars($formData['permanent_province']) ?>">
                                </div>
                                <div class="form-group">
                                    <label for="permanent_zip">ZIP Code</label>
                                    <input type="text" id="permanent_zip" name="permanent_zip" value="<?= htmlspecialchars($formData['permanent_zip']) ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Parent/Guardian Information Tab -->
                    <div class="tab-content" id="parentsTab">
                        <h3 class="section-title">Father's Information</h3>
                        
                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label for="father_last_name">Last Name</label>
                                <input type="text" id="father_last_name" name="father_last_name" value="<?= htmlspecialchars($formData['father_last_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="father_first_name">First Name</label>
                                <input type="text" id="father_first_name" name="father_first_name" value="<?= htmlspecialchars($formData['father_first_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="father_middle_name">Middle Name</label>
                                <input type="text" id="father_middle_name" name="father_middle_name" value="<?= htmlspecialchars($formData['father_middle_name']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="father_occupation">Occupation</label>
                                <input type="text" id="father_occupation" name="father_occupation" value="<?= htmlspecialchars($formData['father_occupation']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="father_contact">Contact Number</label>
                                <input type="tel" id="father_contact" name="father_contact" value="<?= htmlspecialchars($formData['father_contact']) ?>">
                            </div>
                        </div>
                        
                        <h3 class="section-title">Mother's Information</h3>
                        
                        <div class="form-row form-row-3">
                            <div class="form-group">
                                <label for="mother_last_name">Last Name</label>
                                <input type="text" id="mother_last_name" name="mother_last_name" value="<?= htmlspecialchars($formData['mother_last_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="mother_first_name">First Name</label>
                                <input type="text" id="mother_first_name" name="mother_first_name" value="<?= htmlspecialchars($formData['mother_first_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="mother_middle_name">Middle Name</label>
                                <input type="text" id="mother_middle_name" name="mother_middle_name" value="<?= htmlspecialchars($formData['mother_middle_name']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="mother_occupation">Occupation</label>
                                <input type="text" id="mother_occupation" name="mother_occupation" value="<?= htmlspecialchars($formData['mother_occupation']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="mother_contact">Contact Number</label>
                                <input type="tel" id="mother_contact" name="mother_contact" value="<?= htmlspecialchars($formData['mother_contact']) ?>">
                            </div>
                        </div>
                        
                        <h3 class="section-title">Guardian's Information (if not parents)</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="guardian_name">Guardian Name</label>
                                <input type="text" id="guardian_name" name="guardian_name" value="<?= htmlspecialchars($formData['guardian_name']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="guardian_relationship">Relationship to Student</label>
                                <select id="guardian_relationship" name="guardian_relationship">
                                    <?php foreach ($guardianRelationships as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $formData['guardian_relationship'] === $value ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="guardian_contact">Guardian Contact Number</label>
                                <input type="tel" id="guardian_contact" name="guardian_contact" value="<?= htmlspecialchars($formData['guardian_contact']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="guardian_address">Guardian Address</label>
                                <input type="text" id="guardian_address" name="guardian_address" value="<?= htmlspecialchars($formData['guardian_address']) ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Education & Disability Tab -->
                    <div class="tab-content" id="educationTab">
                        <h3 class="section-title">Disability Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="is_pwd">Person with Disability (PWD)</label>
                                <select id="is_pwd" name="is_pwd">
                                    <option value="no" <?= $formData['is_pwd'] === 'no' ? 'selected' : '' ?>>No</option>
                                    <option value="yes" <?= $formData['is_pwd'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="disability_type">Type of Disability</label>
                                <select id="disability_type" name="disability_type">
                                    <?php foreach ($disabilityTypes as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $formData['disability_type'] === $value ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="disability_details">Disability Details</label>
                                <textarea id="disability_details" name="disability_details"><?= htmlspecialchars($formData['disability_details']) ?></textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="has_pwd_id">Has PWD ID</label>
                                <select id="has_pwd_id" name="has_pwd_id">
                                    <option value="no" <?= $formData['has_pwd_id'] === 'no' ? 'selected' : '' ?>>No</option>
                                    <option value="yes" <?= $formData['has_pwd_id'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="pwd_id_number">PWD ID Number</label>
                                <input type="text" id="pwd_id_number" name="pwd_id_number" 
                                       value="<?= htmlspecialchars($formData['pwd_id_number']) ?>"
                                       <?= $formData['has_pwd_id'] === 'no' ? 'disabled' : '' ?>>
                            </div>
                        </div>
                        
                        <h3 class="section-title">Educational Background</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_grade_level">Last Grade Level Completed</label>
                                <select id="last_grade_level" name="last_grade_level">
                                    <option value="">Select Grade Level</option>
                                    <option value="Kinder" <?= $formData['last_grade_level'] === 'Kinder' ? 'selected' : '' ?>>Kinder</option>
                                    <option value="Grade 1" <?= $formData['last_grade_level'] === 'Grade 1' ? 'selected' : '' ?>>Grade 1</option>
                                    <option value="Grade 2" <?= $formData['last_grade_level'] === 'Grade 2' ? 'selected' : '' ?>>Grade 2</option>
                                    <option value="Grade 3" <?= $formData['last_grade_level'] === 'Grade 3' ? 'selected' : '' ?>>Grade 3</option>
                                    <option value="Grade 4" <?= $formData['last_grade_level'] === 'Grade 4' ? 'selected' : '' ?>>Grade 4</option>
                                    <option value="Grade 5" <?= $formData['last_grade_level'] === 'Grade 5' ? 'selected' : '' ?>>Grade 5</option>
                                    <option value="Grade 6" <?= $formData['last_grade_level'] === 'Grade 6' ? 'selected' : '' ?>>Grade 6</option>
                                    <option value="Grade 7" <?= $formData['last_grade_level'] === 'Grade 7' ? 'selected' : '' ?>>Grade 7</option>
                                    <option value="Grade 8" <?= $formData['last_grade_level'] === 'Grade 8' ? 'selected' : '' ?>>Grade 8</option>
                                    <option value="Grade 9" <?= $formData['last_grade_level'] === 'Grade 9' ? 'selected' : '' ?>>Grade 9</option>
                                    <option value="Grade 10" <?= $formData['last_grade_level'] === 'Grade 10' ? 'selected' : '' ?>>Grade 10</option>
                                    <option value="Grade 11" <?= $formData['last_grade_level'] === 'Grade 11' ? 'selected' : '' ?>>Grade 11</option>
                                    <option value="Grade 12" <?= $formData['last_grade_level'] === 'Grade 12' ? 'selected' : '' ?>>Grade 12</option>
                                    <option value="College" <?= $formData['last_grade_level'] === 'College' ? 'selected' : '' ?>>College</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="last_school_attended">Last School Attended</label>
                                <input type="text" id="last_school_attended" name="last_school_attended" value="<?= htmlspecialchars($formData['last_school_attended']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="last_school_year">Last School Year Attended</label>
                                <input type="text" id="last_school_year" name="last_school_year" value="<?= htmlspecialchars($formData['last_school_year']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="reason_not_in_school">Reason for Not Being in School</label>
                                <select id="reason_not_in_school" name="reason_not_in_school">
                                    <option value="">Select Reason</option>
                                    <option value="No school in barangay" <?= $formData['reason_not_in_school'] === 'No school in barangay' ? 'selected' : '' ?>>No school in barangay</option>
                                    <option value="School too far from home" <?= $formData['reason_not_in_school'] === 'School too far from home' ? 'selected' : '' ?>>School too far from home</option>
                                    <option value="Needed to help family" <?= $formData['reason_not_in_school'] === 'Needed to help family' ? 'selected' : '' ?>>Needed to help family</option>
                                    <option value="Unable to pay for miscellaneous and other expenses" <?= $formData['reason_not_in_school'] === 'Unable to pay for miscellaneous and other expenses' ? 'selected' : '' ?>>Unable to pay for miscellaneous and other expenses</option>
                                    <option value="Other" <?= $formData['reason_not_in_school'] === 'Other' ? 'selected' : '' ?>>Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row" id="reason_other_container" style="<?= $formData['reason_not_in_school'] === 'Other' ? '' : 'display: none;' ?>">
                            <div class="form-group">
                                <label for="reason_other">Specify Other Reason</label>
                                <input type="text" id="reason_other" name="reason_other" value="<?= htmlspecialchars($formData['reason_other']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="attended_als_before">Attended ALS Before</label>
                                <select id="attended_als_before" name="attended_als_before">
                                    <option value="no" <?= $formData['attended_als_before'] === 'no' ? 'selected' : '' ?>>No</option>
                                    <option value="yes" <?= $formData['attended_als_before'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="als_program" class="required-field">ALS Program</label>
                                <select id="als_program" name="als_program" required>
                                    <?php foreach ($alsPrograms as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $formData['als_program'] === $value ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="level_of_literacy">Level of Literacy</label>
                                <select id="level_of_literacy" name="level_of_literacy">
                                    <option value="">Select Level</option>
                                    <option value="Basic" <?= $formData['level_of_literacy'] === 'Basic' ? 'selected' : '' ?>>Basic</option>
                                    <option value="Elementary" <?= $formData['level_of_literacy'] === 'Elementary' ? 'selected' : '' ?>>Elementary</option>
                                    <option value="JHS" <?= $formData['level_of_literacy'] === 'JHS' ? 'selected' : '' ?>>JHS</option>
                                    <option value="SHS" <?= $formData['level_of_literacy'] === 'SHS' ? 'selected' : '' ?>>SHS</option>
                                    <option value="Infed" <?= $formData['level_of_literacy'] === 'Infed' ? 'selected' : '' ?>>Infed</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="incomplete_reason">Reason for Not Completing</label>
                                <input type="text" id="incomplete_reason" name="incomplete_reason" value="<?= htmlspecialchars($formData['incomplete_reason']) ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Accessibility & Learning Tab -->
                    <div class="tab-content" id="accessibilityTab">
                        <h3 class="section-title">Accessibility to CLC</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="distance_to_clc_km">Distance to CLC (km)</label>
                                <input type="number" step="0.1" id="distance_to_clc_km" name="distance_to_clc_km" value="<?= htmlspecialchars($formData['distance_to_clc_km']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="distance_to_clc_time">Travel Time to CLC (minutes)</label>
                                <input type="number" id="distance_to_clc_time" name="distance_to_clc_time" value="<?= htmlspecialchars($formData['distance_to_clc_time']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="transport_mode">Mode of Transportation</label>
                                <select id="transport_mode" name="transport_mode">
                                    <?php foreach ($transportModes as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $formData['transport_mode'] === $value ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" id="transport_mode_other_container" style="<?= $formData['transport_mode'] === 'others' ? '' : 'display: none;' ?>">
                                <label for="transport_mode_other">Specify Other Transportation</label>
                                <input type="text" id="transport_mode_other" name="transport_mode_other" value="<?= htmlspecialchars($formData['transport_mode_other']) ?>">
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Preferred Learning Schedule/Availability</label>
                                <div class="schedule-table">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Day</th>
                                                <th>Preferred Time</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $days = [
                                                'monday' => 'Monday',
                                                'tuesday' => 'Tuesday', 
                                                'wednesday' => 'Wednesday',
                                                'thursday' => 'Thursday',
                                                'friday' => 'Friday',
                                                'saturday' => 'Saturday',
                                                'sunday' => 'Sunday'
                                            ];
                                            
                                            $existingSchedule = [];
                                            if (!empty($formData['availability_schedule'])) {
                                                $existingSchedule = json_decode($formData['availability_schedule'], true) ?: [];
                                            }
                                            
                                            foreach ($days as $dayKey => $dayLabel): 
                                                $existingTime = $existingSchedule[$dayKey] ?? '';
                                            ?>
                                            <tr>
                                                <td><?= $dayLabel ?></td>
                                                <td>
                                                    <input type="time" 
                                                           id="schedule_<?= $dayKey ?>" 
                                                           name="schedule[<?= $dayKey ?>]" 
                                                           value="<?= htmlspecialchars($existingTime) ?>">
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <small class="text-muted">Select the preferred time for each day. Leave empty if not available.</small>
                            </div>
                        </div>
                        
                        <h3 class="section-title">Preferred Learning Modalities</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_blended" name="prefers_blended" value="yes" <?= $formData['prefers_blended'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_blended">Blended Learning</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_homeschooling" name="prefers_homeschooling" value="yes" <?= $formData['prefers_homeschooling'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_homeschooling">Homeschooling</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_modular_print" name="prefers_modular_print" value="yes" <?= $formData['prefers_modular_print'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_modular_print">Modular (Print)</label>
                                </div>
                            </div>
                            <div class="form-group">
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_modular_digital" name="prefers_modular_digital" value="yes" <?= $formData['prefers_modular_digital'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_modular_digital">Modular (Digital)</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_online" name="prefers_online" value="yes" <?= $formData['prefers_online'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_online">Online Learning</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_radio_tv" name="prefers_radio_tv" value="yes" <?= $formData['prefers_radio_tv'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_radio_tv">Radio/TV-Based Instruction</label>
                                </div>
                                <div class="form-check">
                                    <input type="checkbox" id="prefers_edu_tv" name="prefers_edu_tv" value="yes" <?= $formData['prefers_edu_tv'] === 'yes' ? 'checked' : '' ?>>
                                    <label for="prefers_edu_tv">Educational TV</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Medical Information Tab -->
                    <div class="tab-content" id="medicalTab">
                        <h3 class="section-title">Medical Information</h3>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="blood_type">Blood Type</label>
                                <select id="blood_type" name="blood_type">
                                    <?php foreach ($bloodTypes as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $formData['blood_type'] === $value ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="medical_conditions">Medical Conditions</label>
                                <textarea id="medical_conditions" name="medical_conditions"><?= htmlspecialchars($formData['medical_conditions']) ?></textarea>
                                <small class="text-muted">List any known medical conditions (e.g., asthma, diabetes, heart condition)</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="allergies">Allergies</label>
                                <textarea id="allergies" name="allergies"><?= htmlspecialchars($formData['allergies']) ?></textarea>
                                <small class="text-muted">List any allergies (food, medicine, environmental)</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="medications">Current Medications</label>
                                <textarea id="medications" name="medications"><?= htmlspecialchars($formData['medications']) ?></textarea>
                                <small class="text-muted">List any medications currently being taken</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="reset" class="btn btn-reset">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                        <button type="submit" class="btn btn-submit">
                            <i class="fas fa-user-plus"></i> Enroll Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="footer">
            <p>Alternative Learning System Enrollment System &copy; <?php echo date('Y'); ?> - La Carlota City Division</p>
        </div>
    </div>

    <!-- Success Modal -->
    <div id="successModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Student Enrolled Successfully!</h2>
                <span class="close">&times;</span>
            </div>
            <div class="modal-body">
                <div class="student-info">
                    <h3 id="modalStudentName"></h3>
                    <p id="modalStudentId"></p>
                </div>
                <div class="qr-code-container">
                    <img id="modalQrCode" src="" alt="Student QR Code">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-submit close-modal">
                    <i class="fas fa-check"></i> OK
                </button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab functionality
            const tabs = document.querySelectorAll('.tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            tabs.forEach(tab => {
                tab.addEventListener('click', () => {
                    const tabId = tab.getAttribute('data-tab');
                    tabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(c => c.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById(tabId + 'Tab').classList.add('active');
                });
            });
            
            // Calculate age when birthdate changes
            const birthdateInput = document.getElementById('birthdate');
            const ageInput = document.getElementById('age');
            
            birthdateInput.addEventListener('change', function() {
                if (this.value) {
                    const birthDate = new Date(this.value);
                    const today = new Date();
                    let age = today.getFullYear() - birthDate.getFullYear();
                    const monthDiff = today.getMonth() - birthDate.getMonth();
                    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                        age--;
                    }
                    ageInput.value = age;
                }
            });
            
            // Toggle permanent address section
            const sameAddressCheckbox = document.getElementById('same_address');
            const permanentAddressSection = document.getElementById('permanent_address_section');
            
            sameAddressCheckbox.addEventListener('change', function() {
                permanentAddressSection.style.display = this.checked ? 'none' : 'block';
            });
            
            // Show/hide transport mode other field
            const transportModeSelect = document.getElementById('transport_mode');
            const transportModeOtherContainer = document.getElementById('transport_mode_other_container');
            
            transportModeSelect.addEventListener('change', function() {
                transportModeOtherContainer.style.display = this.value === 'others' ? 'block' : 'none';
            });
            
            // 4Ps ID Number - Enable/Disable based on beneficiary status
            const fourPsBeneficiary = document.getElementById('four_ps_beneficiary');
            const fourPsIdNumber = document.getElementById('four_ps_id_number');
            
            if (fourPsBeneficiary && fourPsIdNumber) {
                fourPsBeneficiary.addEventListener('change', function() {
                    if (this.value === 'no') {
                        fourPsIdNumber.disabled = true;
                        fourPsIdNumber.value = '';
                    } else {
                        fourPsIdNumber.disabled = false;
                    }
                });
            }
            
            // PWD ID Number - Enable/Disable based on has_pwd_id status
            const hasPwdId = document.getElementById('has_pwd_id');
            const pwdIdNumber = document.getElementById('pwd_id_number');
            
            if (hasPwdId && pwdIdNumber) {
                hasPwdId.addEventListener('change', function() {
                    if (this.value === 'no') {
                        pwdIdNumber.disabled = true;
                        pwdIdNumber.value = '';
                    } else {
                        pwdIdNumber.disabled = false;
                    }
                });
            }
            
            // Reason for not in school - show other reason field
            const reasonNotInSchoolSelect = document.getElementById('reason_not_in_school');
            const reasonOtherContainer = document.getElementById('reason_other_container');
            
            if (reasonNotInSchoolSelect && reasonOtherContainer) {
                reasonNotInSchoolSelect.addEventListener('change', function() {
                    reasonOtherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
                });
            }
            
            // Handle schedule data
            function updateScheduleData() {
                const timeInputs = document.querySelectorAll('input[name^="schedule"]');
                const scheduleData = {};
                
                timeInputs.forEach(input => {
                    const day = input.name.match(/schedule\[(\w+)\]/)[1];
                    const time = input.value;
                    if (time) {
                        scheduleData[day] = time;
                    }
                });
                
                document.getElementById('availability_schedule').value = JSON.stringify(scheduleData);
            }
            
            // Update schedule data when time inputs change
            const scheduleInputs = document.querySelectorAll('input[name^="schedule"]');
            scheduleInputs.forEach(input => {
                input.addEventListener('change', updateScheduleData);
                input.addEventListener('input', updateScheduleData);
            });
            
            // Initialize schedule data on page load
            updateScheduleData();
            
            // Form validation
            const form = document.getElementById('enrollmentForm');
            form.addEventListener('submit', function(e) {
                updateScheduleData();
                
                let valid = true;
                const requiredFields = form.querySelectorAll('[required]');
                
                requiredFields.forEach(field => {
                    if (!field.value.trim()) {
                        valid = false;
                        field.style.borderColor = '#dc3545';
                    } else {
                        field.style.borderColor = '';
                    }
                });
                
                if (!valid) {
                    e.preventDefault();
                    alert('Please fill in all required fields marked with *');
                }
            });
            
            // Modal functionality
            const successModal = document.getElementById('successModal');
            const closeButtons = document.querySelectorAll('.close, .close-modal');
            
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    successModal.style.display = 'none';
                });
            });
            
            window.addEventListener('click', function(event) {
                if (event.target === successModal) {
                    successModal.style.display = 'none';
                }
            });
            
            // Show success modal if session data exists and success parameter is present
            <?php if (isset($_SESSION['enrolled_student']) && isset($_GET['success'])): ?>
                function showSuccessModal() {
                    const modal = document.getElementById('successModal');
                    const studentName = document.getElementById('modalStudentName');
                    const studentId = document.getElementById('modalStudentId');
                    const qrCode = document.getElementById('modalQrCode');
                    
                    const studentData = <?php echo json_encode($_SESSION['enrolled_student'] ?? []); ?>;
                    
                    if (Object.keys(studentData).length > 0) {
                        studentName.textContent = studentData.full_name || '';
                        studentId.textContent = "Student ID: " + (studentData.student_id || '');
                        qrCode.src = studentData.qr_code ? '' + studentData.qr_code : '';
                        
                        qrCode.onerror = function() {
                            this.style.display = 'none';
                        };
                        qrCode.onload = function() {
                            this.style.display = 'block';
                        };
                        
                        modal.style.display = 'flex';
                    }
                }
                
                showSuccessModal();
                <?php unset($_SESSION['enrolled_student']); ?>
            <?php endif; ?>
            
            // Set city, province, and zip based on barangay
            const currentBarangaySelect = document.getElementById('current_barangay_id');
            const currentCityInput = document.getElementById('current_city');
            const currentProvinceInput = document.getElementById('current_province');
            const currentZipInput = document.getElementById('current_zip');
            
            const permanentBarangaySelect = document.getElementById('permanent_barangay_id');
            const permanentCityInput = document.getElementById('permanent_city');
            const permanentProvinceInput = document.getElementById('permanent_province');
            const permanentZipInput = document.getElementById('permanent_zip');
            
            function updateAddressFromBarangay(barangaySelect, prefix) {
                const cityInput = document.getElementById(`${prefix}_city`);
                const provinceInput = document.getElementById(`${prefix}_province`);
                const zipInput = document.getElementById(`${prefix}_zip`);
                
                if (barangaySelect.value) {
                    // For La Carlota barangays, set to La Carlota City
                    cityInput.value = 'La Carlota City';
                    provinceInput.value = 'Negros Occidental';
                    zipInput.value = '6130';
                } else {
                    cityInput.value = '';
                    provinceInput.value = '';
                    zipInput.value = '';
                }
            }
            
            if (currentBarangaySelect) {
                currentBarangaySelect.addEventListener('change', function() {
                    updateAddressFromBarangay(this, 'current');
                });
            }
            
            if (permanentBarangaySelect) {
                permanentBarangaySelect.addEventListener('change', function() {
                    updateAddressFromBarangay(this, 'permanent');
                });
            }
            
            // Initialize form states on page load
            function initializeFormStates() {
                // Initialize 4Ps ID Number state
                if (fourPsBeneficiary && fourPsIdNumber) {
                    if (fourPsBeneficiary.value === 'no') {
                        fourPsIdNumber.disabled = true;
                    }
                }
                
                // Initialize PWD ID Number state
                if (hasPwdId && pwdIdNumber) {
                    if (hasPwdId.value === 'no') {
                        pwdIdNumber.disabled = true;
                    }
                }
                
                // Initialize reason other container
                if (reasonNotInSchoolSelect && reasonOtherContainer) {
                    reasonOtherContainer.style.display = reasonNotInSchoolSelect.value === 'Other' ? 'block' : 'none';
                }
                
                // Initialize transport mode other container
                if (transportModeSelect && transportModeOtherContainer) {
                    transportModeOtherContainer.style.display = transportModeSelect.value === 'others' ? 'block' : 'none';
                }
            }
            
            // Run initialization
            initializeFormStates();
        });
    </script>
</body>
</html>