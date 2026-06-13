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
    if (!is_admin_logged_in()) {
        header('Location: /admin-secure');
        exit();
    }

    // Clear enrolled student data from session if page is refreshed without the success parameter
    if (!isset($_GET['success']) && isset($_SESSION['enrolled_student'])) {
        unset($_SESSION['enrolled_student']);
    }
    // Set page title
    $page_title = "ALS Enrollment System - Add Student";

    // Function to generate the next student ID
    function generateStudentId($conn) {
        $current_year = date('Y');
        $prefix = "ALS-$current_year-";
        
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
    $teachers = $conn->query("SELECT teacher_id, full_name FROM teachers WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);

    // Initialize form data
    $formData = [
        'student_id' => generateStudentId($conn),
        'lrn' => '',
        'enrollment_date' => date('Y-m-d'),
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
        'mother_tongue' => '',
        'indigenous_community' => '',
        'four_ps_beneficiary' => 'no',
        'four_ps_id_number' => '',
        'civil_status' => 'single',
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
        'father_last_name' => '',
        'father_first_name' => '',
        'father_middle_name' => '',
        'father_occupation' => '',
        'mother_last_name' => '',
        'mother_first_name' => '',
        'mother_middle_name' => '',
        'mother_occupation' => '',
        'guardian_last_name' => '',
        'guardian_first_name' => '',
        'guardian_middle_name' => '',
        'guardian_occupation' => '',
        'is_pwd' => 'no',
        'disability_details' => '',
        'has_pwd_id' => 'no',
        'last_grade_level' => '',
        'reason_not_in_school' => '',
        'attended_als_before' => 'no',
        'als_program' => '',
        'level_of_literacy' => '',
        'incomplete_reason' => '',
        'distance_to_clc_km' => '',
        'distance_to_clc_time' => '',
        'transport_mode' => 'walking',
        'transport_mode_other' => '',
        'availability_schedule' => '',
        'prefers_blended' => 'no',
        'prefers_homeschooling' => 'no',
        'prefers_modular_print' => 'no',
        'prefers_modular_digital' => 'no',
        'prefers_online' => 'no',
        'prefers_radio_tv' => 'no',
        'prefers_edu_tv' => 'no',
        'status' => 'active'
    ];

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $formData = [
            'student_id' => clean_input($_POST['student_id'] ?? ''),
            'lrn' => clean_input($_POST['lrn'] ?? ''),
            'enrollment_date' => clean_input($_POST['enrollment_date'] ?? date('Y-m-d')),
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
            'mother_tongue' => clean_input($_POST['mother_tongue'] ?? ''),
            'indigenous_community' => clean_input($_POST['indigenous_community'] ?? ''),
            'four_ps_beneficiary' => clean_input($_POST['four_ps_beneficiary'] ?? 'no'),
            'four_ps_id_number' => clean_input($_POST['four_ps_id_number'] ?? ''),
            'civil_status' => clean_input($_POST['civil_status'] ?? 'single'),
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
            'father_last_name' => clean_input($_POST['father_last_name'] ?? ''),
            'father_first_name' => clean_input($_POST['father_first_name'] ?? ''),
            'father_middle_name' => clean_input($_POST['father_middle_name'] ?? ''),
            'father_occupation' => clean_input($_POST['father_occupation'] ?? ''),
            'mother_last_name' => clean_input($_POST['mother_last_name'] ?? ''),
            'mother_first_name' => clean_input($_POST['mother_first_name'] ?? ''),
            'mother_middle_name' => clean_input($_POST['mother_middle_name'] ?? ''),
            'mother_occupation' => clean_input($_POST['mother_occupation'] ?? ''),
            'guardian_last_name' => clean_input($_POST['guardian_last_name'] ?? ''),
            'guardian_first_name' => clean_input($_POST['guardian_first_name'] ?? ''),
            'guardian_middle_name' => clean_input($_POST['guardian_middle_name'] ?? ''),
            'guardian_occupation' => clean_input($_POST['guardian_occupation'] ?? ''),
            'is_pwd' => clean_input($_POST['is_pwd'] ?? 'no'),
            'disability_details' => clean_input($_POST['disability_details'] ?? ''),
            'has_pwd_id' => clean_input($_POST['has_pwd_id'] ?? 'no'),
            'last_grade_level' => clean_input($_POST['last_grade_level'] ?? ''),
            'reason_not_in_school' => clean_input($_POST['reason_not_in_school'] ?? ''),
            'attended_als_before' => clean_input($_POST['attended_als_before'] ?? 'no'),
            'als_program' => clean_input($_POST['als_program'] ?? ''),
            'level_of_literacy' => clean_input($_POST['level_of_literacy'] ?? ''),
            'incomplete_reason' => clean_input($_POST['incomplete_reason'] ?? ''),
            'distance_to_clc_km' => clean_input($_POST['distance_to_clc_km'] ?? ''),
            'distance_to_clc_time' => clean_input($_POST['distance_to_clc_time'] ?? ''),
            'transport_mode' => clean_input($_POST['transport_mode'] ?? 'walking'),
            'transport_mode_other' => clean_input($_POST['transport_mode_other'] ?? ''),
            'availability_schedule' => ($_POST['availability_schedule'] ?? ''),
            'prefers_blended' => clean_input($_POST['prefers_blended'] ?? 'no'),
            'prefers_homeschooling' => clean_input($_POST['prefers_homeschooling'] ?? 'no'),
            'prefers_modular_print' => clean_input($_POST['prefers_modular_print'] ?? 'no'),
            'prefers_modular_digital' => clean_input($_POST['prefers_modular_digital'] ?? 'no'),
            'prefers_online' => clean_input($_POST['prefers_online'] ?? 'no'),
            'prefers_radio_tv' => clean_input($_POST['prefers_radio_tv'] ?? 'no'),
            'prefers_edu_tv' => clean_input($_POST['prefers_edu_tv'] ?? 'no'),
            'status' => 'pending'
        ];

        if(empty(trim($formData['lrn']))){
            $formData['lrn'] = null;
        }

        if ($formData['same_address'] === 'yes') {
            $formData['permanent_house_no'] = $formData['current_house_no'];
            $formData['permanent_street'] = $formData['current_street'];
            $formData['permanent_barangay_id'] = $formData['current_barangay_id'];
            $formData['permanent_city'] = $formData['current_city'];
            $formData['permanent_province'] = $formData['current_province'];
            $formData['permanent_country'] = $formData['current_country'];
            $formData['permanent_zip'] = $formData['current_zip'];
        }

        $errors = [];
        if (empty($formData['last_name'])) $errors[] = "Last name is required";
        if (empty($formData['first_name'])) $errors[] = "First name is required";
        if (empty($formData['birthdate'])) $errors[] = "Birth date is required";
        if (empty($formData['current_barangay_id'])) $errors[] = "Current barangay is required";

        if (empty($errors)) {
            try {
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

$stmt = $conn->prepare("INSERT INTO students (
    student_id, lrn, enrollment_date, last_name, first_name, middle_name, extension_name, 
    contact_number, birthdate, age, sex, place_of_birth, religion, mother_tongue, 
    indigenous_community, four_ps_beneficiary, four_ps_id_number, civil_status, 
    current_house_no, current_street, current_barangay_id, current_city, current_province, 
    current_country, current_zip, same_address, permanent_house_no, permanent_street, 
    permanent_barangay_id, permanent_city, permanent_province, permanent_country, 
    permanent_zip, father_last_name, father_first_name, father_middle_name, 
    father_occupation, mother_last_name, mother_first_name, mother_middle_name, 
    mother_occupation, guardian_last_name, guardian_first_name, guardian_middle_name, 
    guardian_occupation, is_pwd, disability_details, has_pwd_id, last_grade_level, 
    reason_not_in_school, attended_als_before, als_program, level_of_literacy, 
    incomplete_reason, distance_to_clc_km, distance_to_clc_time, transport_mode, 
    transport_mode_other, availability_schedule, prefers_blended, prefers_homeschooling, 
    prefers_modular_print, prefers_modular_digital, prefers_online, prefers_radio_tv, 
    prefers_edu_tv, teacher_id, status
) VALUES (
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
)");

if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
}

// 68 params: i for age(10), current_barangay_id(21), permanent_barangay_id(29), teacher_id(67)
$stmt->bind_param(
    "sssssssssissssssssisssssssissssssssssssssssssssssssssssssssssssssiss",
    $formData['student_id'],           // 1  s
    $formData['lrn'],                  // 2  s
    $formData['enrollment_date'],      // 3  s
    $formData['last_name'],            // 4  s
    $formData['first_name'],           // 5  s
    $formData['middle_name'],          // 6  s
    $formData['extension_name'],       // 7  s
    $formData['contact_number'],       // 8  s
    $formData['birthdate'],            // 9  s
    $formData['age'],                  // 10 i
    $formData['sex'],                  // 11 s
    $formData['place_of_birth'],       // 12 s
    $formData['religion'],             // 13 s
    $formData['mother_tongue'],        // 14 s
    $formData['indigenous_community'], // 15 s
    $formData['four_ps_beneficiary'],  // 16 s
    $formData['four_ps_id_number'],    // 17 s
    $formData['civil_status'],         // 18 s
    $formData['current_house_no'],     // 19 s
    $formData['current_street'],       // 20 s
    $formData['current_barangay_id'],  // 21 i
    $formData['current_city'],         // 22 s
    $formData['current_province'],     // 23 s
    $formData['current_country'],      // 24 s
    $formData['current_zip'],          // 25 s
    $formData['same_address'],         // 26 s
    $formData['permanent_house_no'],   // 27 s
    $formData['permanent_street'],     // 28 s
    $formData['permanent_barangay_id'],// 29 i
    $formData['permanent_city'],       // 30 s
    $formData['permanent_province'],   // 31 s
    $formData['permanent_country'],    // 32 s
    $formData['permanent_zip'],        // 33 s
    $formData['father_last_name'],     // 34 s
    $formData['father_first_name'],    // 35 s
    $formData['father_middle_name'],   // 36 s
    $formData['father_occupation'],    // 37 s
    $formData['mother_last_name'],     // 38 s
    $formData['mother_first_name'],    // 39 s
    $formData['mother_middle_name'],   // 40 s
    $formData['mother_occupation'],    // 41 s
    $formData['guardian_last_name'],   // 42 s
    $formData['guardian_first_name'],  // 43 s
    $formData['guardian_middle_name'], // 44 s
    $formData['guardian_occupation'],  // 45 s
    $formData['is_pwd'],               // 46 s
    $formData['disability_details'],   // 47 s
    $formData['has_pwd_id'],           // 48 s
    $formData['last_grade_level'],     // 49 s
    $formData['reason_not_in_school'], // 50 s
    $formData['attended_als_before'],  // 51 s
    $formData['als_program'],          // 52 s
    $formData['level_of_literacy'],    // 53 s
    $formData['incomplete_reason'],    // 54 s
    $formData['distance_to_clc_km'],   // 55 s
    $formData['distance_to_clc_time'], // 56 s
    $formData['transport_mode'],       // 57 s
    $formData['transport_mode_other'], // 58 s
    $formData['availability_schedule'],// 59 s
    $formData['prefers_blended'],      // 60 s
    $formData['prefers_homeschooling'],// 61 s
    $formData['prefers_modular_print'],// 62 s
    $formData['prefers_modular_digital'],// 63 s
    $formData['prefers_online'],       // 64 s
    $formData['prefers_radio_tv'],     // 65 s
    $formData['prefers_edu_tv'],       // 66 s
    $teacher_id,                       // 67 i
    $formData['status']                // 68 s
);

                if ($stmt->execute()) {
                    $student_id = $formData['student_id'];
                    
                    // Generate QR Code
                    $qrData = "ALS Student: " . $formData['student_id'] . "\n";
                    $qrData .= "Name: " . $formData['last_name'] . ", " . $formData['first_name'] . " " . $formData['middle_name'] . "\n";
                    $qrData .= "LRN: " . ($formData['lrn'] ?? 'N/A') . "\n";
                    $qrData .= "Barangay: " . getBarangayName($conn, $formData['current_barangay_id']);
                    
                    $qrDir = __DIR__ . '/../qrcodes/';
                    if (!file_exists($qrDir)) {
                        mkdir($qrDir, 0777, true);
                    }
                    
                    $qrFile = $qrDir . $student_id . '.png';
                    $qrPath = '';
                    if (QRcode::png($qrData, $qrFile, QR_ECLEVEL_L, 10)) {
                        $qrPath = '../qrcodes/' . $student_id . '.png';
                        $updateStmt = $conn->prepare("UPDATE students SET qr_code = ? WHERE student_id = ?");
                        $updateStmt->bind_param("ss", $qrPath, $student_id);
                        $updateStmt->execute();
                    }
                    
                    // Handle birth certificate upload
                    if (isset($_FILES['birth_certificate']) && $_FILES['birth_certificate']['error'] === UPLOAD_ERR_OK) {
                        $file = $_FILES['birth_certificate'];
                        $allowedTypes = ['image/jpeg', 'image/png', 'application/pdf'];
                        $maxSize = 5 * 1024 * 1024; // 5MB
                    
                        if (in_array($file['type'], $allowedTypes) && $file['size'] <= $maxSize) {
                            $bcDir = __DIR__ . '/../birth_certificates/';
                            if (!file_exists($bcDir)) mkdir($bcDir, 0777, true);
                    
                            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                            $bcFile = $student_id . '_bc.' . $ext;
                            $bcPath = '../birth_certificates/' . $bcFile;
                    
                            if (move_uploaded_file($file['tmp_name'], $bcDir . $bcFile)) {
                                $bcStmt = $conn->prepare("UPDATE students SET birth_certificate_path = ? WHERE student_id = ?");
                                $bcStmt->bind_param("ss", $bcPath, $student_id);
                                $bcStmt->execute();
                            }
                        }
                    }
                    
                    $_SESSION['enrolled_student'] = [
                        'student_id' => $student_id,
                        'full_name' => $formData['last_name'] . ', ' . $formData['first_name'] . ' ' . $formData['middle_name'],
                        'qr_code' => $qrPath,
                        'form_data' => $formData
                    ];
                    
                    $_SESSION['success'] = "Student enrolled successfully!";
                    
                    header("Location: /AddStudents?success=1");
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
            header("Location: /AddStudents");
            exit();
        }
    }

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

    if (isset($_SESSION['form_data'])) {
        $formData = array_merge($formData, $_SESSION['form_data']);
        unset($_SESSION['form_data']);
    }

    if (!empty($formData['birthdate'])) {
        $birthDate = new DateTime($formData['birthdate']);
        $today = new DateTime();
        $age = $today->diff($birthDate)->y;
        $formData['age'] = $age;
    }

    $civilStatuses = [
        'single' => 'Single',
        'married' => 'Married',
        'separated' => 'Separated',
        'widow/er' => 'Widow/Widower',
        'solo parent' => 'Solo Parent'
    ];

    $alsPrograms = [
        '' => 'Select Program',
        'basic literacy' => 'Basic Literacy',
        'a&e elementary' => 'A&E Elementary',
        'a&e secondary' => 'A&E Secondary',
        'als-shs' => 'ALS-SHS'
    ];

    $transportModes = [
        'walking' => 'Walking',
        'motorcycle' => 'Motorcycle',
        'bicycle' => 'Bicycle',
        'others' => 'Others'
    ];

    $barangays = $conn->query("SELECT * FROM barangays WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

    $barangayCities = [];
    foreach ($barangays as $barangay) {
        $barangayCities[$barangay['barangay_id']] = $barangay['city'] ?? 'La Carlota';
    }
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALS Enrollment System - Enroll Learner</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Fraunces:ital,opsz,wght@0,9..144,300;0,9..144,600;1,9..144,400&display=swap" rel="stylesheet">
    <style>
        
        :root {
            --blue-900: #0a1628;
            --blue-800: #0d2045;
            --blue-700: #1a3a6e;
            --blue-600: #1e4d9b;
            --blue-500: #2563c4;
            --blue-400: #4a83e0;
            --blue-100: #dbeafe;
            --blue-50: #eff6ff;
            --gold: #f0a500;
            --gold-light: #fde68a;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --surface-3: #f1f5f9;
            --border: #e2e8f0;
            --border-focus: #2563c4;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;
            --success: #059669;
            --success-bg: #ecfdf5;
            --error: #dc2626;
            --error-bg: #fef2f2;
            --radius-sm: 6px;
            --radius: 10px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.04);
            --shadow: 0 4px 16px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.04);
            --shadow-lg: 0 12px 40px rgba(0,0,0,.1), 0 4px 16px rgba(0,0,0,.06);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--surface-2);
            color: var(--text-primary);
            min-height: 100vh;
            background-image: radial-gradient(ellipse 80% 60% at 50% -10%, rgba(37,99,196,.08) 0%, transparent 60%);
        }

        /* ─── TOP NAV ─── */
        .topnav {
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 100;
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }

        .topnav-inner {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 2rem;
            height: 72px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
        }

        .brand-logo {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--blue-600), var(--blue-800));
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(30,77,155,.3);
            flex-shrink: 0;
            overflow: hidden;
        }

        .brand-logo img { width: 32px; height: auto; }

        .brand-text h1 {
            font-family: 'Fraunces', serif;
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--blue-800);
            line-height: 1.2;
        }

        .brand-text p {
            font-size: .72rem;
            color: var(--text-muted);
            font-weight: 500;
            letter-spacing: .02em;
        }

        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .5rem 1.1rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: .85rem;
            font-weight: 600;
            transition: all .2s;
            white-space: nowrap;
        }

        .back-btn:hover {
            border-color: var(--blue-500);
            color: var(--blue-600);
            background: var(--blue-50);
        }

        /* ─── PAGE LAYOUT ─── */
        .page-wrap {
            max-width: 1100px;
            margin: 0 auto;
            padding: 2rem 1.5rem 4rem;
        }

        /* ─── PAGE HERO ─── */
        .page-hero {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            margin-bottom: 2rem;
            padding: 1.75rem 2rem;
            background: linear-gradient(135deg, var(--blue-800) 0%, var(--blue-600) 100%);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            position: relative;
            overflow: hidden;
        }

        .page-hero::before {
            content: '';
            position: absolute;
            right: -40px;
            top: -40px;
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: rgba(255,255,255,.05);
            pointer-events: none;
        }

        .page-hero::after {
            content: '';
            position: absolute;
            right: 60px;
            bottom: -60px;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(255,255,255,.04);
            pointer-events: none;
        }

        .hero-left { position: relative; z-index: 1; }

        .hero-eyebrow {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--gold-light);
            margin-bottom: .4rem;
        }

        .hero-title {
            font-family: 'Fraunces', serif;
            font-size: 1.9rem;
            font-weight: 600;
            color: #fff;
            line-height: 1.2;
        }

        .hero-sub {
            font-size: .85rem;
            color: rgba(255,255,255,.65);
            margin-top: .4rem;
        }

        .id-badge {
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,.1);
            border: 1px solid rgba(255,255,255,.2);
            border-radius: var(--radius-lg);
            padding: 1rem 1.5rem;
            text-align: right;
            backdrop-filter: blur(8px);
            flex-shrink: 0;
        }

        .id-badge-label {
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: rgba(255,255,255,.6);
        }

        .id-badge-value {
            font-family: 'Fraunces', serif;
            font-size: 1.4rem;
            font-weight: 600;
            color: #fff;
            letter-spacing: .02em;
        }

        .id-badge-note {
            font-size: .7rem;
            color: rgba(255,255,255,.5);
            margin-top: .15rem;
        }

        /* ─── ALERTS ─── */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: .75rem;
            padding: 1rem 1.25rem;
            border-radius: var(--radius);
            margin-bottom: 1.5rem;
            font-size: .9rem;
            font-weight: 500;
            animation: slideDown .3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .alert-success { background: var(--success-bg); color: var(--success); border: 1px solid #a7f3d0; }
        .alert-danger { background: var(--error-bg); color: var(--error); border: 1px solid #fecaca; }
        .alert i { margin-top: .1rem; flex-shrink: 0; }

        /* ─── CARD ─── */
        .card {
            background: var(--surface);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            overflow: hidden;
        }

        /* ─── TAB NAV ─── */
        .tab-nav {
            display: flex;
            border-bottom: 1px solid var(--border);
            background: var(--surface-2);
            padding: 0 .5rem;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .tab-nav::-webkit-scrollbar { display: none; }

        .tab {
            display: flex;
            align-items: center;
            gap: .45rem;
            padding: 1rem 1.1rem;
            cursor: pointer;
            font-size: .83rem;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
            border-bottom: 2.5px solid transparent;
            transition: all .2s;
            position: relative;
            top: 1px;
        }

        .tab:hover { color: var(--blue-600); }

        .tab.active {
            color: var(--blue-600);
            border-bottom-color: var(--blue-500);
            background: transparent;
        }

        .tab i { font-size: .8rem; }

        /* ─── TAB CONTENT ─── */
        .tab-content { display: none; padding: 2rem; }
        .tab-content.active { display: block; animation: fadeIn .25s ease; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* ─── SECTION TITLE ─── */
        .section-title {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--blue-600);
            margin-bottom: 1.25rem;
            padding-bottom: .6rem;
            border-bottom: 2px solid var(--blue-50);
            display: flex;
            align-items: center;
            gap: .5rem;
        }

        .section-title::before {
            content: '';
            display: block;
            width: 4px;
            height: 14px;
            background: var(--gold);
            border-radius: 2px;
        }

        /* ─── FORM GRID ─── */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }

        .form-grid-2 { grid-template-columns: repeat(2, 1fr); }
        .form-grid-3 { grid-template-columns: repeat(3, 1fr); }
        .form-grid-4 { grid-template-columns: repeat(4, 1fr); }
        .col-span-2 { grid-column: span 2; }
        .col-span-3 { grid-column: span 3; }
        .col-span-full { grid-column: 1 / -1; }

        @media (max-width: 768px) {
            .form-grid, .form-grid-2, .form-grid-3, .form-grid-4 {
                grid-template-columns: 1fr;
            }
            .col-span-2, .col-span-3, .col-span-full { grid-column: span 1; }
        }

        /* ─── FORM GROUPS ─── */
        .form-group { display: flex; flex-direction: column; gap: .4rem; }

        .form-group label {
            font-size: .8rem;
            font-weight: 700;
            color: var(--text-secondary);
            letter-spacing: .01em;
        }

        .required-marker::after {
            content: ' *';
            color: var(--error);
        }

        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="number"],
        .form-group input[type="tel"],
        .form-group input[type="time"],
        .form-group select,
        .form-group textarea {
            padding: .65rem .85rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            font-size: .875rem;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--surface);
            transition: border-color .2s, box-shadow .2s;
            width: 100%;
            appearance: none;
            -webkit-appearance: none;
        }

        .form-group select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right .75rem center;
            padding-right: 2.2rem;
            cursor: pointer;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--blue-400);
            box-shadow: 0 0 0 3px rgba(37,99,196,.12);
        }

        .form-group input[readonly],
        .form-group input[disabled] {
            background: var(--surface-3);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        .form-group textarea {
            min-height: 100px;
            resize: vertical;
        }

        .input-hint {
            font-size: .73rem;
            color: var(--text-muted);
        }

        /* ─── CHECKBOX TOGGLE (custom) ─── */
        .check-group {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .75rem 1rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            cursor: pointer;
            transition: all .2s;
            user-select: none;
        }

        .check-group:hover { border-color: var(--blue-400); background: var(--blue-50); }
        .check-group input[type="checkbox"] { display: none; }

        .check-box {
            width: 18px;
            height: 18px;
            border: 2px solid var(--border);
            border-radius: 4px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .2s;
        }

        .check-group input:checked ~ .check-box {
            background: var(--blue-500);
            border-color: var(--blue-500);
        }

        .check-group input:checked ~ .check-box::after {
            content: '';
            width: 5px;
            height: 9px;
            border: 2px solid white;
            border-top: none;
            border-left: none;
            transform: rotate(45deg) translateY(-1px);
        }

        .check-label {
            font-size: .83rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        /* Same Address Checkbox (inline) */
        .inline-check {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .9rem 1.1rem;
            background: var(--blue-50);
            border: 1.5px solid var(--blue-100);
            border-radius: var(--radius);
            cursor: pointer;
            user-select: none;
            margin-bottom: 1.5rem;
        }

        .inline-check input { width: 16px; height: 16px; accent-color: var(--blue-500); cursor: pointer; }
        .inline-check span { font-size: .85rem; font-weight: 600; color: var(--blue-700); }

        /* ─── MODALITIES GRID ─── */
        .modalities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: .75rem;
            margin-bottom: 1.5rem;
        }

        /* ─── SCHEDULE TABLE ─── */
        .schedule-table-wrap {
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }

        .schedule-table {
            width: 100%;
            border-collapse: collapse;
        }

        .schedule-table thead th {
            background: var(--surface-3);
            padding: .75rem 1rem;
            text-align: left;
            font-size: .75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--border);
        }

        .schedule-table tbody td {
            padding: .65rem 1rem;
            border-bottom: 1px solid var(--border);
        }

        .schedule-table tbody tr:last-child td { border-bottom: none; }

        .schedule-table tbody tr:hover td { background: var(--blue-50); }

        .schedule-table .day-label {
            font-size: .85rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .schedule-table input[type="time"] {
            padding: .5rem .75rem;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: .85rem;
            font-family: inherit;
            color: var(--text-primary);
            background: var(--surface);
            transition: border-color .2s, box-shadow .2s;
        }

        .schedule-table input[type="time"]:focus {
            outline: none;
            border-color: var(--blue-400);
            box-shadow: 0 0 0 3px rgba(37,99,196,.12);
        }

        /* ─── FORM ACTIONS ─── */
        .form-actions {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: .75rem;
            padding: 1.5rem 2rem;
            background: var(--surface-2);
            border-top: 1px solid var(--border);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .65rem 1.4rem;
            border-radius: var(--radius);
            font-size: .875rem;
            font-weight: 700;
            font-family: inherit;
            cursor: pointer;
            border: 1.5px solid transparent;
            text-decoration: none;
            transition: all .2s;
            white-space: nowrap;
        }

        .btn-ghost {
            background: transparent;
            border-color: var(--border);
            color: var(--text-secondary);
        }

        .btn-ghost:hover {
            background: var(--surface-3);
            border-color: var(--text-muted);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--blue-500), var(--blue-700));
            color: #fff;
            box-shadow: 0 2px 10px rgba(30,77,155,.3);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--blue-600), var(--blue-800));
            box-shadow: 0 4px 16px rgba(30,77,155,.4);
            transform: translateY(-1px);
        }

        .btn-primary:active { transform: translateY(0); }

        /* ─── MODAL ─── */
        .modal {
            position: fixed;
            inset: 0;
            background: rgba(10,22,40,.5);
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }

        .modal-box {
            background: var(--surface);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 480px;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalIn .3s cubic-bezier(.34,1.56,.64,1);
        }

        .modal-box.wide { max-width: 780px; }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(.9) translateY(20px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header {
            padding: 1.5rem 1.75rem 1.25rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            font-family: 'Fraunces', serif;
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .modal-close {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: none;
            background: var(--surface-3);
            color: var(--text-muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all .2s;
        }

        .modal-close:hover { background: var(--error-bg); color: var(--error); }

        .modal-body { padding: 1.75rem; }

        .modal-footer {
            padding: 1.25rem 1.75rem;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: .6rem;
        }

        .student-info-banner {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .student-info-banner h3 {
            font-family: 'Fraunces', serif;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }

        .student-info-banner p {
            font-size: .85rem;
            color: var(--text-muted);
            margin-top: .25rem;
        }

        .success-icon {
            width: 56px;
            height: 56px;
            background: var(--success-bg);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            font-size: 1.5rem;
            color: var(--success);
        }

        .qr-frame {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1.25rem;
            border: 2px dashed var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface-2);
        }

        .qr-frame img {
            max-width: 180px;
            border-radius: var(--radius);
        }

        /* Summary table */
        .summary-section { margin-bottom: 1.5rem; }

        .summary-section h3 {
            font-size: .72rem;
            font-weight: 800;
            letter-spacing: .1em;
            text-transform: uppercase;
            color: var(--blue-600);
            margin-bottom: .75rem;
            padding-bottom: .5rem;
            border-bottom: 2px solid var(--blue-50);
        }

        .summary-row {
            display: flex;
            gap: 1rem;
            padding: .5rem 0;
            border-bottom: 1px solid var(--border);
            font-size: .85rem;
        }

        .summary-row:last-child { border-bottom: none; }

        .summary-label {
            font-weight: 700;
            color: var(--text-secondary);
            width: 40%;
            flex-shrink: 0;
        }

        .summary-value { color: var(--text-primary); }

        /* ─── FOOTER ─── */
        .footer {
            text-align: center;
            padding: 2rem 1.5rem;
            font-size: .8rem;
            color: var(--text-muted);
        }

        /* ─── RESPONSIVE ─── */
        @media (max-width: 640px) {
            .topnav-inner { padding: 0 1rem; }
            .page-hero { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .id-badge { text-align: left; width: 100%; }
            .tab-content { padding: 1.25rem; }
            .form-actions { padding: 1.25rem; flex-direction: column; }
            .btn { width: 100%; justify-content: center; }
        }
        
        /* ─── FILE UPLOAD ─── */
        .file-drop-zone {
            border: 2px dashed var(--border);
            border-radius: var(--radius-lg);
            background: var(--surface-2);
            transition: border-color .2s, background .2s;
            cursor: pointer;
            overflow: hidden;
        }

        .file-drop-zone.dragover {
            border-color: var(--blue-400);
            background: var(--blue-50);
        }

        .file-drop-inner {
            padding: 2rem 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .5rem;
            text-align: center;
        }

        .file-drop-icon {
            font-size: 2rem;
            color: var(--text-muted);
            margin-bottom: .25rem;
        }

        .file-drop-text {
            font-size: .875rem;
            font-weight: 600;
            color: var(--text-secondary);
        }

        .file-browse-link {
            color: var(--blue-500);
            text-decoration: underline;
            cursor: pointer;
        }

        .file-drop-hint {
            font-size: .75rem;
            color: var(--text-muted);
        }

        .file-preview {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            background: var(--blue-50);
            border-top: 1px solid var(--blue-100);
        }

        .file-preview-icon {
            width: 40px;
            height: 40px;
            border-radius: var(--radius);
            background: var(--blue-100);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.1rem;
            color: var(--blue-600);
            flex-shrink: 0;
        }

        .file-preview-info { flex: 1; min-width: 0; }

        .file-preview-name {
            font-size: .85rem;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .file-preview-size {
            font-size: .75rem;
            color: var(--text-muted);
            margin-top: .1rem;
        }

        .file-remove-btn {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            border: none;
            background: var(--error-bg);
            color: var(--error);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .8rem;
            flex-shrink: 0;
            transition: all .2s;
        }

        .file-remove-btn:hover { background: var(--error); color: #fff; }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <div class="topnav-inner">
        <a href="/AdminDashboard" class="brand">
            <div class="brand-logo">
                <img src="/logo" alt="ALS Logo">
            </div>
            <div class="brand-text">
                <h1>Alternative Learning System</h1>
                <p>La Carlota City Division</p>
            </div>
        </a>
        <a href="/AdminDashboard" class="back-btn">
            <i class="fas fa-arrow-left"></i> Dashboard
        </a>
    </div>
</nav>

<div class="page-wrap">

    <!-- HERO HEADER -->
    <div class="page-hero">
        <div class="hero-left">
            <div class="hero-eyebrow">Enrollment</div>
            <h1 class="hero-title">Enroll a Learner</h1>
            <p class="hero-sub">Complete all sections to register a new ALS student.</p>
        </div>
        <div class="id-badge">
            <div class="id-badge-label">Assigned Student ID</div>
            <div class="id-badge-value"><?= htmlspecialchars($formData['student_id']) ?></div>
            <div class="id-badge-note">Auto-generated upon enrollment</div>
        </div>
    </div>

    <!-- ALERTS -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?= $_SESSION['success'] ?></span>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <span><?= $_SESSION['error'] ?></span>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- MAIN CARD -->
    <div class="card">
        <form method="post" id="studentForm" enctype="multipart/form-data">
            <input type="hidden" name="student_id" value="<?= htmlspecialchars($formData['student_id']) ?>">

            <!-- TAB NAV -->
            <div class="tab-nav">
                <div class="tab active" data-tab="personal">
                    <i class="fas fa-user"></i> Personal
                </div>
                <div class="tab" data-tab="address">
                    <i class="fas fa-map-marker-alt"></i> Address
                </div>
                <div class="tab" data-tab="parents">
                    <i class="fas fa-users"></i> Parents / Guardian
                </div>
                <div class="tab" data-tab="education">
                    <i class="fas fa-graduation-cap"></i> Education &amp; Disability
                </div>
                <div class="tab" data-tab="accessibility">
                    <i class="fas fa-route"></i> Accessibility &amp; Learning
                </div>
            </div>

            <!-- ── TAB: PERSONAL ── -->
            <div class="tab-content active" id="personalTab">

                <div class="section-title">Basic Details</div>
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label for="lrn">Learner Reference No. (LRN) <span class="input-hint">(Optional)</span></label>
                        <input type="text" id="lrn" name="lrn" placeholder="e.g. 123456789012" value="<?= htmlspecialchars($formData['lrn']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="enrollment_date">Enrollment Date</label>
                        <input type="date" id="enrollment_date" name="enrollment_date" value="<?= htmlspecialchars($formData['enrollment_date']) ?>">
                    </div>
                </div>

                <div class="section-title">Name</div>
                <div class="form-grid form-grid-4">
                    <div class="form-group">
                        <label for="last_name" class="required-marker">Last Name</label>
                        <input type="text" id="last_name" name="last_name" placeholder="Dela Cruz" value="<?= htmlspecialchars($formData['last_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="first_name" class="required-marker">First Name</label>
                        <input type="text" id="first_name" name="first_name" placeholder="Juan" value="<?= htmlspecialchars($formData['first_name']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="middle_name">Middle Name</label>
                        <input type="text" id="middle_name" name="middle_name" placeholder="Santos" value="<?= htmlspecialchars($formData['middle_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="extension_name">Extension (Jr., III…)</label>
                        <input type="text" id="extension_name" name="extension_name" placeholder="Jr." value="<?= htmlspecialchars($formData['extension_name']) ?>">
                    </div>
                </div>

                <div class="section-title">Demographics</div>
                <div class="form-grid form-grid-4">
                    <div class="form-group">
                        <label for="birthdate" class="required-marker">Birth Date</label>
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
                    <div class="form-group">
                        <label for="civil_status">Civil Status</label>
                        <select id="civil_status" name="civil_status">
                            <?php foreach ($civilStatuses as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData['civil_status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid form-grid-4">
                    <div class="form-group">
                        <label for="contact_number">Contact Number</label>
                        <input type="tel" id="contact_number" name="contact_number" placeholder="09XX XXX XXXX" value="<?= htmlspecialchars($formData['contact_number']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="place_of_birth">Place of Birth</label>
                        <input type="text" id="place_of_birth" name="place_of_birth" value="<?= htmlspecialchars($formData['place_of_birth']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="religion">Religion</label>
                        <input type="text" id="religion" name="religion" value="<?= htmlspecialchars($formData['religion']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="mother_tongue">Mother Tongue</label>
                        <input type="text" id="mother_tongue" name="mother_tongue" value="<?= htmlspecialchars($formData['mother_tongue']) ?>">
                    </div>
                </div>

                <div class="section-title">Socio-Economic Info</div>
                <div class="form-grid form-grid-3">
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
                    <div class="form-group">
                        <label for="four_ps_id_number">4Ps ID Number</label>
                        <input type="text" id="four_ps_id_number" name="four_ps_id_number"
                            value="<?= htmlspecialchars($formData['four_ps_id_number']) ?>"
                            <?= $formData['four_ps_beneficiary'] === 'no' ? 'disabled' : '' ?>>
                    </div>
                </div>
                <div class="section-title">Supporting Document</div>
                <div class="form-grid form-grid-2" style="margin-bottom:1.5rem;">
                    <div class="form-group col-span-full">
                        <label for="birth_certificate">PSA / Birth Certificate <span class="input-hint">(Optional — JPG, PNG, or PDF, max 5MB)</span></label>
                        <div class="file-drop-zone" id="fileDropZone">
                            <input type="file" id="birth_certificate" name="birth_certificate"
                                   accept=".jpg,.jpeg,.png,.pdf" style="display:none;">
                            <div class="file-drop-inner" id="fileDropInner">
                                <div class="file-drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                <div class="file-drop-text">Drag & drop file here or <span class="file-browse-link">browse</span></div>
                                <div class="file-drop-hint">PSA Birth Certificate · JPG, PNG, PDF · Max 5MB</div>
                            </div>
                            <div class="file-preview" id="filePreview" style="display:none;">
                                <div class="file-preview-icon" id="filePreviewIcon"></div>
                                <div class="file-preview-info">
                                    <div class="file-preview-name" id="filePreviewName"></div>
                                    <div class="file-preview-size" id="filePreviewSize"></div>
                                </div>
                                <button type="button" class="file-remove-btn" id="fileRemoveBtn">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /personalTab -->

            <!-- ── TAB: ADDRESS ── -->
            <div class="tab-content" id="addressTab">

                <div class="section-title">Current Address</div>
                <div class="form-grid form-grid-3">
                    <div class="form-group">
                        <label for="current_house_no">House No. / Lot / Bldg.</label>
                        <input type="text" id="current_house_no" name="current_house_no" value="<?= htmlspecialchars($formData['current_house_no']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="current_street">Street</label>
                        <input type="text" id="current_street" name="current_street" value="<?= htmlspecialchars($formData['current_street']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="current_barangay_id" class="required-marker">Barangay</label>
                        <select id="current_barangay_id" name="current_barangay_id" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?= $barangay['barangay_id'] ?>"
                                        data-city="<?= htmlspecialchars($barangayCities[$barangay['barangay_id']]) ?>"
                                        <?= $formData['current_barangay_id'] == $barangay['barangay_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($barangay['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid form-grid-4">
                    <div class="form-group">
                        <label for="current_city">City</label>
                        <input type="text" id="current_city" name="current_city" value="<?= htmlspecialchars($formData['current_city']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="current_province">Province</label>
                        <input type="text" id="current_province" name="current_province" value="<?= htmlspecialchars($formData['current_province']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="current_country">Country</label>
                        <input type="text" id="current_country" name="current_country" value="<?= htmlspecialchars($formData['current_country']) ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label for="current_zip">ZIP Code</label>
                        <input type="text" id="current_zip" name="current_zip" value="<?= htmlspecialchars($formData['current_zip']) ?>" readonly>
                    </div>
                </div>

                <label class="inline-check">
                    <input type="checkbox" id="same_address" name="same_address" value="yes" <?= $formData['same_address'] === 'yes' ? 'checked' : '' ?>>
                    <i class="fas fa-clone" style="color:var(--blue-500);font-size:.9rem;"></i>
                    <span>Permanent address is the same as current address</span>
                </label>

                <div id="permanent_address_section" style="<?= $formData['same_address'] === 'yes' ? 'display:none;' : '' ?>">
                    <div class="section-title">Permanent Address</div>
                    <div class="form-grid form-grid-3">
                        <div class="form-group">
                            <label for="permanent_house_no">House No. / Lot / Bldg.</label>
                            <input type="text" id="permanent_house_no" name="permanent_house_no" value="<?= htmlspecialchars($formData['permanent_house_no']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="permanent_street">Street</label>
                            <input type="text" id="permanent_street" name="permanent_street" value="<?= htmlspecialchars($formData['permanent_street']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="permanent_barangay_id">Barangay</label>
                            <select id="permanent_barangay_id" name="permanent_barangay_id">
                                <option value="">Select Barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?= $barangay['barangay_id'] ?>"
                                            data-city="<?= htmlspecialchars($barangayCities[$barangay['barangay_id']]) ?>"
                                            <?= $formData['permanent_barangay_id'] == $barangay['barangay_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($barangay['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid form-grid-4">
                        <div class="form-group">
                            <label for="permanent_city">City</label>
                            <input type="text" id="permanent_city" name="permanent_city" value="<?= htmlspecialchars($formData['permanent_city']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="permanent_province">Province</label>
                            <input type="text" id="permanent_province" name="permanent_province" value="<?= htmlspecialchars($formData['permanent_province']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="permanent_country">Country</label>
                            <input type="text" id="permanent_country" name="permanent_country" value="<?= htmlspecialchars($formData['permanent_country']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="permanent_zip">ZIP Code</label>
                            <input type="text" id="permanent_zip" name="permanent_zip" value="<?= htmlspecialchars($formData['permanent_zip']) ?>">
                        </div>
                    </div>
                </div>

            </div><!-- /addressTab -->

            <!-- ── TAB: PARENTS ── -->
            <div class="tab-content" id="parentsTab">

                <div class="section-title">Father's Information</div>
                <div class="form-grid form-grid-4">
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
                    <div class="form-group">
                        <label for="father_occupation">Occupation</label>
                        <input type="text" id="father_occupation" name="father_occupation" value="<?= htmlspecialchars($formData['father_occupation']) ?>">
                    </div>
                </div>

                <div class="section-title">Mother's Information</div>
                <div class="form-grid form-grid-4">
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
                    <div class="form-group">
                        <label for="mother_occupation">Occupation</label>
                        <input type="text" id="mother_occupation" name="mother_occupation" value="<?= htmlspecialchars($formData['mother_occupation']) ?>">
                    </div>
                </div>

                <div class="section-title">Guardian's Information <span style="font-weight:400;font-size:.75rem;color:var(--text-muted);text-transform:none;letter-spacing:0;">(if not parents)</span></div>
                <div class="form-grid form-grid-4">
                    <div class="form-group">
                        <label for="guardian_last_name">Last Name</label>
                        <input type="text" id="guardian_last_name" name="guardian_last_name" value="<?= htmlspecialchars($formData['guardian_last_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="guardian_first_name">First Name</label>
                        <input type="text" id="guardian_first_name" name="guardian_first_name" value="<?= htmlspecialchars($formData['guardian_first_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="guardian_middle_name">Middle Name</label>
                        <input type="text" id="guardian_middle_name" name="guardian_middle_name" value="<?= htmlspecialchars($formData['guardian_middle_name']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="guardian_occupation">Occupation</label>
                        <input type="text" id="guardian_occupation" name="guardian_occupation" value="<?= htmlspecialchars($formData['guardian_occupation']) ?>">
                    </div>
                </div>

            </div><!-- /parentsTab -->

            <!-- ── TAB: EDUCATION & DISABILITY ── -->
            <div class="tab-content" id="educationTab">

                <div class="section-title">Disability Information</div>
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label for="is_pwd">Person with Disability (PWD)</label>
                        <select id="is_pwd" name="is_pwd">
                            <option value="no" <?= $formData['is_pwd'] === 'no' ? 'selected' : '' ?>>No</option>
                            <option value="yes" <?= $formData['is_pwd'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="has_pwd_id">Has PWD ID</label>
                        <select id="has_pwd_id" name="has_pwd_id">
                            <option value="no" <?= $formData['has_pwd_id'] === 'no' ? 'selected' : '' ?>>No</option>
                            <option value="yes" <?= $formData['has_pwd_id'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                </div>
                <div class="form-grid" style="margin-bottom:1.5rem;">
                    <div class="form-group col-span-full">
                        <label for="disability_details">Disability Details</label>
                        <textarea id="disability_details" name="disability_details"><?= htmlspecialchars($formData['disability_details']) ?></textarea>
                    </div>
                </div>

                <div class="section-title">Educational Background</div>
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label for="last_grade_level">Last Grade Level Completed</label>
                        <select id="last_grade_level" name="last_grade_level">
                            <option value="">Select Grade Level</option>
                            <?php foreach (['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9','Grade 10','Grade 11','Grade 12','College'] as $g): ?>
                                <option value="<?= $g ?>" <?= $formData['last_grade_level'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="reason_not_in_school">Reason for Not Being in School</label>
                        <select id="reason_not_in_school" name="reason_not_in_school">
                            <option value="">Select Reason</option>
                            <?php foreach (['No school in barangay','School too far from home','Needed to help family','Unable to pay for miscellaneous and other expenses','Other'] as $r): ?>
                                <option value="<?= $r ?>" <?= $formData['reason_not_in_school'] === $r ? 'selected' : '' ?>><?= $r ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid" id="reason_other_container" style="<?= $formData['reason_not_in_school'] === 'Other' ? '' : 'display:none;' ?>margin-bottom:1.5rem;">
                    <div class="form-group col-span-full">
                        <label for="reason_other_text">Specify Other Reason</label>
                        <input type="text" id="reason_other_text" name="reason_not_in_school" value="<?= htmlspecialchars($formData['reason_not_in_school'] ?? '') ?>">
                    </div>
                </div>

                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label for="attended_als_before">Attended ALS Before</label>
                        <select id="attended_als_before" name="attended_als_before">
                            <option value="no" <?= $formData['attended_als_before'] === 'no' ? 'selected' : '' ?>>No</option>
                            <option value="yes" <?= $formData['attended_als_before'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="als_program">ALS Program</label>
                        <select id="als_program" name="als_program">
                            <?php foreach ($alsPrograms as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData['als_program'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label for="level_of_literacy">Level of Literacy</label>
                        <select id="level_of_literacy" name="level_of_literacy">
                            <option value="">Select Level</option>
                            <?php foreach (['Basic','Elementary','JHS','SHS','Infed'] as $l): ?>
                                <option value="<?= $l ?>" <?= $formData['level_of_literacy'] === $l ? 'selected' : '' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="incomplete_reason">Reason for Not Completing</label>
                        <input type="text" id="incomplete_reason" name="incomplete_reason" value="<?= htmlspecialchars($formData['incomplete_reason']) ?>">
                    </div>
                </div>

            </div><!-- /educationTab -->

            <!-- ── TAB: ACCESSIBILITY ── -->
            <div class="tab-content" id="accessibilityTab">

                <div class="section-title">Accessibility to CLC</div>
                <div class="form-grid form-grid-4">
                    <div class="form-group">
                        <label for="distance_to_clc_km">Distance (km)</label>
                        <input type="number" step="0.1" id="distance_to_clc_km" name="distance_to_clc_km" value="<?= htmlspecialchars($formData['distance_to_clc_km']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="distance_to_clc_time">Travel Time (min)</label>
                        <input type="number" id="distance_to_clc_time" name="distance_to_clc_time" value="<?= htmlspecialchars($formData['distance_to_clc_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="transport_mode">Mode of Transportation</label>
                        <select id="transport_mode" name="transport_mode">
                            <?php foreach ($transportModes as $value => $label): ?>
                                <option value="<?= $value ?>" <?= $formData['transport_mode'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="transport_mode_other_container" style="<?= $formData['transport_mode'] === 'others' ? '' : 'display:none;' ?>">
                        <label for="transport_mode_other">Specify Transportation</label>
                        <input type="text" id="transport_mode_other" name="transport_mode_other" value="<?= htmlspecialchars($formData['transport_mode_other']) ?>">
                    </div>
                </div>

                <div class="section-title">Preferred Learning Schedule</div>
                <div class="schedule-table-wrap" style="margin-bottom:1.5rem;">
                    <table class="schedule-table">
                        <thead>
                            <tr>
                                <th>Day</th>
                                <th>Preferred Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $days = ['monday'=>'Monday','tuesday'=>'Tuesday','wednesday'=>'Wednesday','thursday'=>'Thursday','friday'=>'Friday','saturday'=>'Saturday','sunday'=>'Sunday'];
                            $existingSchedule = [];
                            if (!empty($formData['availability_schedule'])) {
                                $existingSchedule = json_decode($formData['availability_schedule'], true) ?: [];
                            }
                            foreach ($days as $dayKey => $dayLabel):
                                $existingTime = $existingSchedule[$dayKey] ?? '';
                            ?>
                            <tr>
                                <td><span class="day-label"><?= $dayLabel ?></span></td>
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
                <input type="hidden" id="availability_schedule" name="availability_schedule" value="<?= htmlspecialchars($formData['availability_schedule']) ?>">
                <p class="input-hint" style="margin-bottom:1.5rem;">Leave a day empty if not available.</p>

                <div class="section-title">Preferred Learning Modalities</div>
                <div class="modalities-grid">
                    <?php
                    $modalities = [
                        'prefers_blended'        => ['icon' => 'fa-layer-group',  'label' => 'Blended Learning'],
                        'prefers_homeschooling'  => ['icon' => 'fa-home',         'label' => 'Homeschooling'],
                        'prefers_modular_print'  => ['icon' => 'fa-book',         'label' => 'Modular (Print)'],
                        'prefers_modular_digital'=> ['icon' => 'fa-tablet-alt',   'label' => 'Modular (Digital)'],
                        'prefers_online'         => ['icon' => 'fa-wifi',         'label' => 'Online Learning'],
                        'prefers_radio_tv'       => ['icon' => 'fa-broadcast-tower','label' => 'Radio / TV Instruction'],
                        'prefers_edu_tv'         => ['icon' => 'fa-tv',           'label' => 'Educational TV'],
                    ];
                    foreach ($modalities as $key => $info):
                        $checked = $formData[$key] === 'yes';
                    ?>
                    <label class="check-group">
                        <input type="checkbox" id="<?= $key ?>" name="<?= $key ?>" value="yes" <?= $checked ? 'checked' : '' ?>>
                        <div class="check-box"></div>
                        <span class="check-label"><i class="fas <?= $info['icon'] ?>" style="margin-right:.35rem;color:var(--blue-400);"></i><?= $info['label'] ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>

            </div><!-- /accessibilityTab -->

            <!-- FORM ACTIONS -->
            <div class="form-actions">
                <button type="reset" class="btn btn-ghost">
                    <i class="fas fa-rotate-left"></i> Reset
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-user-plus"></i> Enroll Student
                </button>
            </div>

        </form>
    </div><!-- /card -->

    <div class="footer">
        Alternative Learning System Enrollment System &copy; <?php echo date('Y'); ?> — La Carlota City Division
    </div>

</div><!-- /page-wrap -->

<!-- ── SUCCESS MODAL ── -->
<div id="successModal" class="modal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Enrollment Successful</h2>
            <button type="button" class="modal-close close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="student-info-banner">
                <div class="success-icon"><i class="fas fa-check"></i></div>
                <h3 id="modalStudentName"></h3>
                <p id="modalStudentId"></p>
            </div>
            <div class="qr-frame">
                <img id="modalQrCode" src="" alt="Student QR Code">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" id="summaryBtn" class="btn btn-primary">
                <i class="fas fa-file-alt"></i> View Summary
            </button>
            <button type="button" class="btn btn-ghost close-modal">Close</button>
        </div>
    </div>
</div>

<!-- ── SUMMARY MODAL ── -->
<div id="summaryModal" class="modal">
    <div class="modal-box wide">
        <div class="modal-header">
            <h2>Enrollment Summary</h2>
            <button type="button" class="modal-close close"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div id="enrollmentSummary"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-ghost close-modal">Close</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── TABS ──
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.tab + 'Tab').classList.add('active');
        });
    });

    // ── AGE AUTO-CALCULATE ──
    const birthdateInput = document.getElementById('birthdate');
    const ageInput = document.getElementById('age');

    birthdateInput.addEventListener('change', function () {
        if (!this.value) return;
        const bd = new Date(this.value), today = new Date();
        let age = today.getFullYear() - bd.getFullYear();
        const m = today.getMonth() - bd.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < bd.getDate())) age--;
        ageInput.value = age;
    });

    // ── SAME ADDRESS TOGGLE ──
    const sameAddressCheckbox = document.getElementById('same_address');
    sameAddressCheckbox.addEventListener('change', function () {
        document.getElementById('permanent_address_section').style.display = this.checked ? 'none' : 'block';
    });

    // ── TRANSPORT MODE OTHER ──
    const transportModeSelect = document.getElementById('transport_mode');
    transportModeSelect.addEventListener('change', function () {
        document.getElementById('transport_mode_other_container').style.display = this.value === 'others' ? 'block' : 'none';
    });

    // ── 4PS ID ENABLE/DISABLE ──
    const fourPs = document.getElementById('four_ps_beneficiary');
    const fourPsId = document.getElementById('four_ps_id_number');
    fourPs.addEventListener('change', function () {
        fourPsId.disabled = this.value === 'no';
        if (this.value === 'no') fourPsId.value = '';
    });
    if (fourPs.value === 'no') fourPsId.disabled = true;

    // ── REASON OTHER ──
    const reasonSelect = document.getElementById('reason_not_in_school');
    const reasonOtherContainer = document.getElementById('reason_other_container');
    if (reasonSelect) {
        reasonSelect.addEventListener('change', function () {
            reasonOtherContainer.style.display = this.value === 'Other' ? 'block' : 'none';
        });
    }

    // ── BARANGAY → CITY AUTO-FILL ──
    const cityZipCodes = {
        'La Carlota': '6130', 'San Enrique': '6104', 'Bacolod City': '6100',
        'Bago City': '6101', 'Cadiz City': '6121', 'Escalante City': '6124',
        'Himamaylan City': '6108', 'Kabankalan City': '6109', 'La Castellana': '6131',
        'Manapla': '6120', 'Pontevedra': '6105', 'Pulupandan': '6102',
        'Sagay City': '6122', 'San Carlos City': '6127', 'Silay City': '6116',
        'Sipalay City': '6113', 'Talisay City': '6115', 'Toboso': '6125',
        'Valladolid': '6103', 'Victorias City': '6119'
    };

    function formatCityName(city) {
        return ['La Carlota', 'San Enrique'].includes(city) ? city + ' City' : city;
    }

    function updateAddressFromBarangay(select, prefix) {
        const opt = select.options[select.selectedIndex];
        if (opt && opt.value !== '') {
            const city = opt.getAttribute('data-city');
            if (city) {
                const cityInput = document.getElementById(`${prefix}_city`);
                if (cityInput) cityInput.value = formatCityName(city);
                const zipInput = document.getElementById(`${prefix}_zip`);
                if (zipInput) zipInput.value = cityZipCodes[city] || '6130';
                const provInput = document.getElementById(`${prefix}_province`);
                if (provInput) provInput.value = 'Negros Occidental';
                const countryInput = document.getElementById(`${prefix}_country`);
                if (countryInput) countryInput.value = 'Philippines';
            }
        }
    }

    const currBarangay = document.getElementById('current_barangay_id');
    const permBarangay = document.getElementById('permanent_barangay_id');
    if (currBarangay) currBarangay.addEventListener('change', () => updateAddressFromBarangay(currBarangay, 'current'));
    if (permBarangay) permBarangay.addEventListener('change', () => updateAddressFromBarangay(permBarangay, 'permanent'));

    // ── SCHEDULE ──
    function updateScheduleData() {
        const scheduleData = {};
        document.querySelectorAll('input[name^="schedule"]').forEach(input => {
            const day = input.name.match(/schedule\[(\w+)\]/)[1];
            if (input.value) scheduleData[day] = input.value;
        });
        document.getElementById('availability_schedule').value = JSON.stringify(scheduleData);
    }
    document.querySelectorAll('input[name^="schedule"]').forEach(input => {
        input.addEventListener('change', updateScheduleData);
        input.addEventListener('input', updateScheduleData);
    });
    updateScheduleData();

    // ── FORM SUBMIT ──
    document.getElementById('studentForm').addEventListener('submit', function (e) {
        updateScheduleData();
        let valid = true;
        this.querySelectorAll('[required]').forEach(field => {
            if (!field.value.trim()) {
                valid = false;
                field.style.borderColor = '#dc2626';
                field.style.boxShadow = '0 0 0 3px rgba(220,38,38,.12)';
            } else {
                field.style.borderColor = '';
                field.style.boxShadow = '';
            }
        });
        if (!valid) {
            e.preventDefault();
            alert('Please fill in all required fields.');
        }
    });

    // ── MODALS ──
    function closeAllModals() {
        document.getElementById('successModal').style.display = 'none';
        document.getElementById('summaryModal').style.display = 'none';
    }

    document.querySelectorAll('.close, .close-modal').forEach(btn => {
        btn.addEventListener('click', closeAllModals);
    });

    window.addEventListener('click', function (e) {
        if (e.target === document.getElementById('successModal') ||
            e.target === document.getElementById('summaryModal')) {
            closeAllModals();
        }
    });

    document.getElementById('summaryBtn').addEventListener('click', function () {
        document.getElementById('successModal').style.display = 'none';
        showSummaryModal();
    });

    function showSuccessModal() {
        const studentData = <?php echo json_encode($_SESSION['enrolled_student'] ?? []); ?>;
        if (!Object.keys(studentData).length) return;

        document.getElementById('modalStudentName').textContent = studentData.full_name || '';
        document.getElementById('modalStudentId').textContent = 'ID: ' + (studentData.student_id || '');
        const qrImg = document.getElementById('modalQrCode');
        qrImg.src = studentData.qr_code || '';
        qrImg.onerror = () => qrImg.style.display = 'none';

        document.getElementById('successModal').style.display = 'flex';
    }

    function showSummaryModal() {
        const studentData = <?php echo json_encode($_SESSION['enrolled_student'] ?? []); ?>;
        if (!studentData.form_data) return;

        const f = studentData.form_data;
        const brSelect = document.querySelector(`#current_barangay_id option[value="${f.current_barangay_id}"]`);
        const prSelect = document.querySelector(`#permanent_barangay_id option[value="${f.permanent_barangay_id}"]`);
        const alsPrg   = document.querySelector(`#als_program option[value="${f.als_program}"]`);

        const modalities = [
            f.prefers_blended        === 'yes' ? 'Blended Learning'       : '',
            f.prefers_homeschooling  === 'yes' ? 'Homeschooling'           : '',
            f.prefers_modular_print  === 'yes' ? 'Modular (Print)'         : '',
            f.prefers_modular_digital=== 'yes' ? 'Modular (Digital)'       : '',
            f.prefers_online         === 'yes' ? 'Online Learning'         : '',
            f.prefers_radio_tv       === 'yes' ? 'Radio/TV-Based'          : '',
            f.prefers_edu_tv         === 'yes' ? 'Educational TV'          : '',
        ].filter(Boolean).join(', ') || 'None';

        document.getElementById('enrollmentSummary').innerHTML = `
            <div class="summary-section">
                <h3>Personal Information</h3>
                <div class="summary-row"><div class="summary-label">Student ID</div><div class="summary-value">${f.student_id || ''}</div></div>
                <div class="summary-row"><div class="summary-label">Full Name</div><div class="summary-value">${f.last_name}, ${f.first_name} ${f.middle_name}</div></div>
                <div class="summary-row"><div class="summary-label">Birth Date</div><div class="summary-value">${f.birthdate || ''}</div></div>
                <div class="summary-row"><div class="summary-label">Contact Number</div><div class="summary-value">${f.contact_number || '—'}</div></div>
                <div class="summary-row"><div class="summary-label">Sex</div><div class="summary-value">${f.sex || ''}</div></div>
                <div class="summary-row"><div class="summary-label">Civil Status</div><div class="summary-value">${f.civil_status || ''}</div></div>
            </div>
            <div class="summary-section">
                <h3>Address Information</h3>
                <div class="summary-row"><div class="summary-label">Current Address</div><div class="summary-value">${f.current_house_no} ${f.current_street}, ${brSelect?.text || ''}, ${f.current_city}</div></div>
                ${f.same_address === 'no' ? `<div class="summary-row"><div class="summary-label">Permanent Address</div><div class="summary-value">${f.permanent_house_no} ${f.permanent_street}, ${prSelect?.text || ''}, ${f.permanent_city}</div></div>` : ''}
            </div>
            <div class="summary-section">
                <h3>Education</h3>
                <div class="summary-row"><div class="summary-label">Last Grade Level</div><div class="summary-value">${f.last_grade_level || '—'}</div></div>
                <div class="summary-row"><div class="summary-label">ALS Program</div><div class="summary-value">${alsPrg?.text || '—'}</div></div>
            </div>
            <div class="summary-section">
                <h3>Learning Modalities</h3>
                <div class="summary-row"><div class="summary-label">Preferred Modalities</div><div class="summary-value">${modalities}</div></div>
            </div>`;

        document.getElementById('summaryModal').style.display = 'flex';
    }

    <?php if (isset($_SESSION['enrolled_student']) && isset($_GET['success'])): ?>
        showSuccessModal();
        <?php unset($_SESSION['enrolled_student']); ?>
    <?php endif; ?>
    
    // ── FILE UPLOAD ──
    const fileInput    = document.getElementById('birth_certificate');
    const dropZone     = document.getElementById('fileDropZone');
    const fileDropInner= document.getElementById('fileDropInner');
    const filePreview  = document.getElementById('filePreview');
    const filePreviewName = document.getElementById('filePreviewName');
    const filePreviewSize = document.getElementById('filePreviewSize');
    const filePreviewIcon = document.getElementById('filePreviewIcon');
    const fileRemoveBtn   = document.getElementById('fileRemoveBtn');
    
    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
    }
    
    function showFilePreview(file) {
        const isPdf = file.type === 'application/pdf';
        filePreviewIcon.innerHTML = isPdf
            ? '<i class="fas fa-file-pdf" style="color:#e53e3e;"></i>'
            : '<i class="fas fa-file-image" style="color:var(--blue-500);"></i>';
        filePreviewName.textContent = file.name;
        filePreviewSize.textContent = formatBytes(file.size);
        fileDropInner.style.display = 'none';
        filePreview.style.display   = 'flex';
        dropZone.style.borderColor  = 'var(--blue-400)';
    }
    
    function clearFile() {
        fileInput.value = '';
        fileDropInner.style.display = 'flex';
        filePreview.style.display   = 'none';
        dropZone.style.borderColor  = '';
    }
    
    dropZone.addEventListener('click', e => {
        if (!e.target.closest('#fileRemoveBtn')) fileInput.click();
    });
    
    fileInput.addEventListener('change', function () {
        if (this.files[0]) {
            if (this.files[0].size > 5 * 1024 * 1024) {
                alert('File is too large. Maximum size is 5MB.');
                clearFile(); return;
            }
            showFilePreview(this.files[0]);
        }
    });
    
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', ()  => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        const file = e.dataTransfer.files[0];
        if (!file) return;
        const allowed = ['image/jpeg','image/png','application/pdf'];
        if (!allowed.includes(file.type)) { alert('Only JPG, PNG, or PDF files are allowed.'); return; }
        if (file.size > 5 * 1024 * 1024) { alert('File is too large. Maximum size is 5MB.'); return; }
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
        showFilePreview(file);
    });
    
    fileRemoveBtn.addEventListener('click', clearFile);

});
</script>
</body>
</html>