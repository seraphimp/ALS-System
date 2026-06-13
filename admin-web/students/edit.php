<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once '../../phpqrcode/qrlib.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: ../../index.php');
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════════
// TOKEN SYSTEM  (shared with all-student.php and view.php via $_SESSION['_st'])
// URL format: /EditStudents?<40-hex-token>   — no key name at all
// ═══════════════════════════════════════════════════════════════════════════════

/**
 * Resolve a 40-char hex token back to a student_id via session lookup.
 * Returns '' if the token is missing, malformed, or unknown.
 */
function resolve_token(string $token): string {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return '';
    return $_SESSION['_st'][$token] ?? '';
}

/**
 * Issue (or reuse) a token for a student_id and return it.
 * Used when we need to redirect back to this page.
 */
function issue_token(string $student_id): string {
    if (!isset($_SESSION['_st']) || !is_array($_SESSION['_st'])) {
        $_SESSION['_st'] = [];
    }
    $existing = array_search($student_id, $_SESSION['_st'], true);
    if ($existing !== false) return $existing;

    $token = bin2hex(random_bytes(20)); // 40-char hex
    $_SESSION['_st'][$token] = $student_id;

    // Keep session store bounded
    if (count($_SESSION['_st']) > 500) {
        $_SESSION['_st'] = array_slice($_SESSION['_st'], -500, null, true);
    }
    return $token;
}

// ── Resolve the token from the query string ───────────────────────────────────
// The ENTIRE query string is the token (e.g. /EditStudents?a3f9c2e1d4b7...)
// We also accept ?_t=<token> as a fallback (used by internal redirects).
$raw_token = trim($_SERVER['QUERY_STRING'] ?? '');

// Strip any extra params that sneak in (e.g. &success=1) — token is before '&'
if (strpos($raw_token, '&') !== false) {
    $raw_token = substr($raw_token, 0, strpos($raw_token, '&'));
}

if (empty($raw_token) && isset($_GET['_t'])) {
    $raw_token = trim($_GET['_t']);
}

if (empty($raw_token)) {
    $_SESSION['error'] = "Invalid access — no token provided.";
    header("Location: /AllStudents");
    exit();
}

$student_id = resolve_token($raw_token);

if (empty($student_id)) {
    $_SESSION['error'] = "Invalid or expired link. Please navigate from the student list.";
    header("Location: /AllStudents");
    exit();
}

// ── Helper: safe redirect back to this page ───────────────────────────────────
// Always re-issues a fresh token so the URL stays opaque.
function redirect_back(string $student_id, string $suffix = ''): never {
    $tok = issue_token($student_id);
    header("Location: /EditStudents?" . $tok . $suffix);
    exit();
}

$page_title = "ALS Enrollment System - Edit Student";

// ── Load reference data ───────────────────────────────────────────────────────
$barangays = $conn->query("SELECT * FROM barangays WHERE status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$teachers  = $conn->query("SELECT teacher_id, full_name FROM teachers WHERE status = 'active'")->fetch_all(MYSQLI_ASSOC);

// ── Fetch student ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "SELECT s.*, t.full_name AS teacher_name
     FROM students s
     LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
     WHERE s.student_id = ?"
);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Student not found.";
    header("Location: /AllStudents");
    exit();
}
$formData = $result->fetch_assoc();

// ── Handle teacher assignment ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_teacher'])) {
    $teacher_id = clean_input($_POST['teacher_id'] ?? '');

    try {
        if (!empty($teacher_id)) {
            $st = $conn->prepare("UPDATE students SET teacher_id = ? WHERE student_id = ?");
            $st->bind_param("ss", $teacher_id, $student_id);
            if ($st->execute()) {
                $_SESSION['success'] = "Teacher assigned successfully!";
            } else {
                throw new Exception("Failed to assign teacher: " . $st->error);
            }
        } else {
            $st = $conn->prepare("UPDATE students SET teacher_id = NULL WHERE student_id = ?");
            $st->bind_param("s", $student_id);
            if ($st->execute()) {
                $_SESSION['success'] = "Teacher assignment removed!";
            } else {
                throw new Exception("Failed to remove teacher: " . $st->error);
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
    }

    redirect_back($student_id);
}

// ── Validate LRN uniqueness ───────────────────────────────────────────────────
$errors = [];
if (!empty($formData['lrn'])) {
    $st = $conn->prepare("SELECT student_id FROM students WHERE lrn = ? AND student_id != ?");
    $st->bind_param("ss", $formData['lrn'], $student_id);
    $st->execute();
    $st->store_result();
    if ($st->num_rows > 0) {
        $errors[] = "LRN already exists for another student";
    }
    $st->close();
}

// ── Handle main form submission ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['assign_teacher'])) {

    $formData = [
        'student_id'              => clean_input($_POST['student_id'] ?? ''),
        'lrn'                     => clean_input($_POST['lrn'] ?? ''),
        'enrollment_date'         => clean_input($_POST['enrollment_date'] ?? date('Y-m-d')),
        'last_name'               => clean_input($_POST['last_name'] ?? ''),
        'first_name'              => clean_input($_POST['first_name'] ?? ''),
        'middle_name'             => clean_input($_POST['middle_name'] ?? ''),
        'extension_name'          => clean_input($_POST['extension_name'] ?? ''),
        'contact_number'          => clean_input($_POST['contact_number'] ?? ''),
        'birthdate'               => clean_input($_POST['birthdate'] ?? ''),
        'age'                     => (int)($_POST['age'] ?? 0),
        'sex'                     => clean_input($_POST['sex'] ?? 'male'),
        'place_of_birth'          => clean_input($_POST['place_of_birth'] ?? ''),
        'religion'                => clean_input($_POST['religion'] ?? ''),
        'mother_tongue'           => clean_input($_POST['mother_tongue'] ?? ''),
        'indigenous_community'    => clean_input($_POST['indigenous_community'] ?? ''),
        'four_ps_beneficiary'     => clean_input($_POST['four_ps_beneficiary'] ?? 'no'),
        'four_ps_id_number'       => clean_input($_POST['four_ps_id_number'] ?? ''),
        'civil_status'            => clean_input($_POST['civil_status'] ?? 'single'),
        'current_house_no'        => clean_input($_POST['current_house_no'] ?? ''),
        'current_street'          => clean_input($_POST['current_street'] ?? ''),
        'current_barangay_id'     => (int)($_POST['current_barangay_id'] ?? 0),
        'current_city'            => clean_input($_POST['current_city'] ?? 'La Carlota City'),
        'current_province'        => clean_input($_POST['current_province'] ?? 'Negros Occidental'),
        'current_country'         => clean_input($_POST['current_country'] ?? 'Philippines'),
        'current_zip'             => clean_input($_POST['current_zip'] ?? '6130'),
        'same_address'            => clean_input($_POST['same_address'] ?? 'yes'),
        'permanent_house_no'      => clean_input($_POST['permanent_house_no'] ?? ''),
        'permanent_street'        => clean_input($_POST['permanent_street'] ?? ''),
        'permanent_barangay_id'   => (int)($_POST['permanent_barangay_id'] ?? 0),
        'permanent_city'          => clean_input($_POST['permanent_city'] ?? 'La Carlota City'),
        'permanent_province'      => clean_input($_POST['permanent_province'] ?? 'Negros Occidental'),
        'permanent_country'       => clean_input($_POST['permanent_country'] ?? 'Philippines'),
        'permanent_zip'           => clean_input($_POST['permanent_zip'] ?? '6130'),
        'father_last_name'        => clean_input($_POST['father_last_name'] ?? ''),
        'father_first_name'       => clean_input($_POST['father_first_name'] ?? ''),
        'father_middle_name'      => clean_input($_POST['father_middle_name'] ?? ''),
        'father_occupation'       => clean_input($_POST['father_occupation'] ?? ''),
        'mother_last_name'        => clean_input($_POST['mother_last_name'] ?? ''),
        'mother_first_name'       => clean_input($_POST['mother_first_name'] ?? ''),
        'mother_middle_name'      => clean_input($_POST['mother_middle_name'] ?? ''),
        'mother_occupation'       => clean_input($_POST['mother_occupation'] ?? ''),
        'guardian_last_name'      => clean_input($_POST['guardian_last_name'] ?? ''),
        'guardian_first_name'     => clean_input($_POST['guardian_first_name'] ?? ''),
        'guardian_middle_name'    => clean_input($_POST['guardian_middle_name'] ?? ''),
        'guardian_occupation'     => clean_input($_POST['guardian_occupation'] ?? ''),
        'is_pwd'                  => clean_input($_POST['is_pwd'] ?? 'no'),
        'disability_details'      => clean_input($_POST['disability_details'] ?? ''),
        'has_pwd_id'              => clean_input($_POST['has_pwd_id'] ?? 'no'),
        'last_grade_level'        => clean_input($_POST['last_grade_level'] ?? ''),
        'reason_not_in_school'    => clean_input($_POST['reason_not_in_school'] ?? ''),
        'attended_als_before'     => clean_input($_POST['attended_als_before'] ?? 'no'),
        'als_program'             => clean_input($_POST['als_program'] ?? ''),
        'completed_program'       => clean_input($_POST['completed_program'] ?? 'no'),
        'incomplete_reason'       => clean_input($_POST['incomplete_reason'] ?? ''),
        'distance_to_clc_km'      => clean_input($_POST['distance_to_clc_km'] ?? ''),
        'distance_to_clc_time'    => clean_input($_POST['distance_to_clc_time'] ?? ''),
        'transport_mode'          => clean_input($_POST['transport_mode'] ?? 'walking'),
        'transport_mode_other'    => clean_input($_POST['transport_mode_other'] ?? ''),
        'availability_schedule'   => clean_input($_POST['availability_schedule'] ?? ''),
        'prefers_blended'         => clean_input($_POST['prefers_blended'] ?? 'no'),
        'prefers_homeschooling'   => clean_input($_POST['prefers_homeschooling'] ?? 'no'),
        'prefers_modular_print'   => clean_input($_POST['prefers_modular_print'] ?? 'no'),
        'prefers_modular_digital' => clean_input($_POST['prefers_modular_digital'] ?? 'no'),
        'prefers_online'          => clean_input($_POST['prefers_online'] ?? 'no'),
        'prefers_radio_tv'        => clean_input($_POST['prefers_radio_tv'] ?? 'no'),
        'prefers_edu_tv'          => clean_input($_POST['prefers_edu_tv'] ?? 'no'),
        'status'                  => clean_input($_POST['status'] ?? 'enrolled'),
    ];

    // Copy address if same
    if ($formData['same_address'] === 'yes') {
        $formData['permanent_house_no']    = $formData['current_house_no'];
        $formData['permanent_street']      = $formData['current_street'];
        $formData['permanent_barangay_id'] = $formData['current_barangay_id'];
        $formData['permanent_city']        = $formData['current_city'];
        $formData['permanent_province']    = $formData['current_province'];
        $formData['permanent_country']     = $formData['current_country'];
        $formData['permanent_zip']         = $formData['current_zip'];
    }

    // Required field validation
    if (empty($formData['last_name']))           $errors[] = "Last name is required";
    if (empty($formData['first_name']))          $errors[] = "First name is required";
    if (empty($formData['birthdate']))           $errors[] = "Birth date is required";
    if (empty($formData['current_barangay_id'])) $errors[] = "Current barangay is required";

    if (empty($errors)) {
        try {
            $st = $conn->prepare("UPDATE students SET
                lrn=?, enrollment_date=?, last_name=?, first_name=?, middle_name=?,
                extension_name=?, contact_number=?, birthdate=?, age=?, sex=?,
                place_of_birth=?, religion=?, mother_tongue=?, indigenous_community=?,
                four_ps_beneficiary=?, four_ps_id_number=?, civil_status=?,
                current_house_no=?, current_street=?, current_barangay_id=?,
                current_city=?, current_province=?, current_country=?, current_zip=?,
                same_address=?, permanent_house_no=?, permanent_street=?,
                permanent_barangay_id=?, permanent_city=?, permanent_province=?,
                permanent_country=?, permanent_zip=?, father_last_name=?,
                father_first_name=?, father_middle_name=?, father_occupation=?,
                mother_last_name=?, mother_first_name=?, mother_middle_name=?,
                mother_occupation=?, guardian_last_name=?, guardian_first_name=?,
                guardian_middle_name=?, guardian_occupation=?, is_pwd=?,
                disability_details=?, has_pwd_id=?, last_grade_level=?,
                reason_not_in_school=?, attended_als_before=?, als_program=?,
                completed_program=?, incomplete_reason=?, distance_to_clc_km=?,
                distance_to_clc_time=?, transport_mode=?, transport_mode_other=?,
                availability_schedule=?, prefers_blended=?, prefers_homeschooling=?,
                prefers_modular_print=?, prefers_modular_digital=?, prefers_online=?,
                prefers_radio_tv=?, prefers_edu_tv=?, status=?
                WHERE student_id=?");

            if (!$st) throw new Exception("Prepare failed: " . $conn->error);

            $st->bind_param(
                "ssssssssissssssssssisssssssisssssssssssssssssssssssssssssssssssssss",
                $formData['lrn'], $formData['enrollment_date'],
                $formData['last_name'], $formData['first_name'], $formData['middle_name'],
                $formData['extension_name'], $formData['contact_number'], $formData['birthdate'],
                $formData['age'], $formData['sex'], $formData['place_of_birth'],
                $formData['religion'], $formData['mother_tongue'], $formData['indigenous_community'],
                $formData['four_ps_beneficiary'], $formData['four_ps_id_number'], $formData['civil_status'],
                $formData['current_house_no'], $formData['current_street'], $formData['current_barangay_id'],
                $formData['current_city'], $formData['current_province'], $formData['current_country'],
                $formData['current_zip'], $formData['same_address'], $formData['permanent_house_no'],
                $formData['permanent_street'], $formData['permanent_barangay_id'], $formData['permanent_city'],
                $formData['permanent_province'], $formData['permanent_country'], $formData['permanent_zip'],
                $formData['father_last_name'], $formData['father_first_name'], $formData['father_middle_name'],
                $formData['father_occupation'], $formData['mother_last_name'], $formData['mother_first_name'],
                $formData['mother_middle_name'], $formData['mother_occupation'], $formData['guardian_last_name'],
                $formData['guardian_first_name'], $formData['guardian_middle_name'], $formData['guardian_occupation'],
                $formData['is_pwd'], $formData['disability_details'], $formData['has_pwd_id'],
                $formData['last_grade_level'], $formData['reason_not_in_school'], $formData['attended_als_before'],
                $formData['als_program'], $formData['completed_program'], $formData['incomplete_reason'],
                $formData['distance_to_clc_km'], $formData['distance_to_clc_time'], $formData['transport_mode'],
                $formData['transport_mode_other'], $formData['availability_schedule'],
                $formData['prefers_blended'], $formData['prefers_homeschooling'], $formData['prefers_modular_print'],
                $formData['prefers_modular_digital'], $formData['prefers_online'], $formData['prefers_radio_tv'],
                $formData['prefers_edu_tv'], $formData['status'],
                $student_id   // ← always use the resolved real student_id, never $_POST
            );

            if ($st->execute()) {
                // Generate / refresh QR code
                $qrData = "ALS Student: {$student_id}\n"
                        . "Name: {$formData['last_name']}, {$formData['first_name']} " . ($formData['middle_name'] ?? '') . "\n"
                        . "LRN: {$formData['lrn']}\n"
                        . "Barangay: " . getBarangayName($conn, $formData['current_barangay_id']);

                $qrDir = __DIR__ . '/../qrcodes/';
                if (!file_exists($qrDir)) mkdir($qrDir, 0777, true);

                $qrFile = $qrDir . $student_id . '.png';
                QRcode::png($qrData, $qrFile, QR_ECLEVEL_L, 10);

                $qrPath = '../qrcodes/' . $student_id . '.png';
                $upd = $conn->prepare("UPDATE students SET qr_code = ? WHERE student_id = ?");
                $upd->bind_param("ss", $qrPath, $student_id);
                $upd->execute();

                $_SESSION['enrolled_student'] = [
                    'student_id' => $student_id,
                    'full_name'  => $formData['last_name'] . ', ' . $formData['first_name'] . ' ' . ($formData['middle_name'] ?? ''),
                    'qr_code'    => $qrPath,
                    'form_data'  => $formData,
                ];

                $_SESSION['success'] = "Student updated successfully!";

                // Redirect back with the same opaque token + &success=1
                $tok = issue_token($student_id);
                header("Location: /EditStudents?" . $tok . "&success=1");
                exit();
            } else {
                throw new Exception("Failed to update student: " . $st->error);
            }

        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }

    if (!empty($errors)) {
        $_SESSION['error']     = implode("<br>", $errors);
        $_SESSION['form_data'] = $formData;
        redirect_back($student_id);
    }
}

// ── Helper ────────────────────────────────────────────────────────────────────
function getBarangayName($conn, $barangay_id): string {
    if (!$barangay_id) return '';
    $st = $conn->prepare("SELECT name FROM barangays WHERE barangay_id = ?");
    $st->bind_param("i", $barangay_id);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    return $row['name'] ?? '';
}

// ── Restore form data from failed submission ──────────────────────────────────
if (isset($_SESSION['form_data'])) {
    $formData = array_merge($formData, $_SESSION['form_data']);
    unset($_SESSION['form_data']);
}

// ── Auto-calculate age ────────────────────────────────────────────────────────
if (!empty($formData['birthdate'])) {
    $formData['age'] = (new DateTime())->diff(new DateTime($formData['birthdate']))->y;
}

// ── Select option lists ───────────────────────────────────────────────────────
$civilStatuses = ['single'=>'Single','married'=>'Married','separated'=>'Separated','widow/er'=>'Widow/Widower','solo parent'=>'Solo Parent'];
$alsPrograms   = [''=>'Select Program','basic literacy'=>'Basic Literacy','a&e elementary'=>'A&E Elementary','a&e secondary'=>'A&E Secondary','als-shs'=>'ALS-SHS'];
$transportModes= ['walking'=>'Walking','motorcycle'=>'Motorcycle','bicycle'=>'Bicycle','others'=>'Others'];

// ── Current page token (for form actions) ─────────────────────────────────────
// We store the token in a hidden field so the POST can reference the right page.
$page_token = issue_token($student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALS Enrollment System - Edit Student</title>
    <link rel="icon" type="image/png" href="../../logo/als-logo-removebg-preview.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --danger-color: #f72585;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --border-radius: 0.375rem;
            --box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
        }
        body { font-family: 'Roboto', sans-serif; background-color: #f5f7fa; color: #333; line-height: 1.6; }
        .container { max-width: 1200px; margin: 2rem auto; padding: 0 1rem; }
        .card { background: white; border-radius: var(--border-radius); box-shadow: var(--box-shadow); margin-bottom: 2rem; overflow: hidden; }
        .card-header { background-color: var(--primary-color); color: white; padding: 1.25rem 1.5rem; border-bottom: 1px solid rgba(0,0,0,.125); }
        .card-body { padding: 1.5rem; }
        .page-title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
        .page-title h1 { font-size: 1.75rem; font-weight: 500; margin: 0; color: white; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: 0.5rem 1rem; border-radius: var(--border-radius); font-weight: 500; text-decoration: none; transition: all 0.2s; cursor: pointer; border: 1px solid transparent; }
        .btn i { margin-right: 0.5rem; }
        .btn-outline { color: white; border-color: white; background: transparent; }
        .btn-outline:hover { background-color: rgba(255,255,255,0.2); }
        .btn-primary { background-color: var(--primary-color); color: white; }
        .btn-primary:hover { background-color: var(--secondary-color); transform: translateY(-1px); }
        .tabs { display: flex; flex-wrap: wrap; border-bottom: 1px solid #dee2e6; margin-bottom: 1.5rem; }
        .tab { padding: 0.75rem 1.5rem; cursor: pointer; border: 1px solid transparent; border-bottom: none; border-radius: var(--border-radius) var(--border-radius) 0 0; margin-right: 0.5rem; transition: all 0.2s; white-space: nowrap; }
        .tab:hover { background-color: #f8f9fa; }
        .tab.active { background-color: white; border-color: #dee2e6; border-bottom-color: white; color: var(--primary-color); font-weight: 500; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; font-weight: 500; color: #555; }
        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="number"],
        .form-group input[type="tel"],
        .form-group select,
        .form-group textarea { width: 100%; padding: 0.75rem; border: 1px solid #ddd; border-radius: var(--border-radius); font-size: 1rem; transition: border-color 0.2s; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary-color); outline: none; box-shadow: 0 0 0 0.2rem rgba(67,97,238,.25); }
        .form-group textarea { min-height: 100px; resize: vertical; }
        .form-check { display: flex; align-items: center; margin-bottom: 0.5rem; }
        .form-check input { margin-right: 0.5rem; }
        .form-row { display: flex; flex-wrap: wrap; margin-right: -0.75rem; margin-left: -0.75rem; }
        .form-col { flex: 0 0 100%; padding: 0 0.75rem; }
        @media (min-width: 768px) { .form-col { flex: 0 0 50%; max-width: 50%; } .form-col-3 { flex: 0 0 33.333%; max-width: 33.333%; } }
        .required-field::after { content: " *"; color: var(--danger-color); }
        .form-actions { display: flex; justify-content: flex-end; margin-top: 1.5rem; padding-top: 1.5rem; border-top: 1px solid #eee; gap: 0.5rem; }
        .alert { padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1.5rem; display: flex; align-items: center; }
        .alert-success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .alert i { margin-right: 0.75rem; font-size: 1.25rem; }
        @media (max-width: 768px) {
            .tabs { flex-direction: column; }
            .tab { flex: 0 0 100%; margin-bottom: 0.5rem; border-radius: var(--border-radius); border: 1px solid #dee2e6; }
            .tab.active { border-bottom: 1px solid #dee2e6; }
            .form-actions { flex-direction: column; }
            .btn { width: 100%; margin-bottom: 0.5rem; }
        }
        .student-id-display { background-color: #f8f9fa; padding: 1rem; border-radius: var(--border-radius); margin-bottom: 1.5rem; border-left: 4px solid var(--primary-color); }
        .student-id-display h3 { margin: 0; color: var(--primary-color); font-size: 1.25rem; }
        .modal { position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); display: none; justify-content: center; align-items: center; }
        .modal-content { background-color: white; border-radius: var(--border-radius); box-shadow: var(--box-shadow); width: 90%; max-width: 500px; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; background-color: var(--primary-color); color: white; }
        .modal-header h2 { margin: 0; font-size: 1.5rem; }
        .modal-body { padding: 1.5rem; }
        .modal-footer { padding: 1rem 1.5rem; border-top: 1px solid #dee2e6; display: flex; justify-content: flex-end; gap: 0.5rem; }
        .close { color: white; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #ccc; }
        .student-info { text-align: center; margin-bottom: 1.5rem; }
        .student-info h3 { margin: 0 0 0.5rem 0; color: var(--primary-color); }
        .student-info p { margin: 0; color: #666; }
        .qr-code-container { text-align: center; }
        .qr-code-container img { max-width: 200px; border: 1px solid #ddd; border-radius: var(--border-radius); }
        #enrollmentSummary { line-height: 1.8; }
        .summary-section { margin-bottom: 1.5rem; }
        .summary-section h3 { color: var(--primary-color); border-bottom: 1px solid #eee; padding-bottom: 0.5rem; margin-bottom: 1rem; }
        .summary-row { display: flex; margin-bottom: 0.5rem; }
        .summary-label { font-weight: 500; width: 40%; color: #555; }
        .summary-value { width: 60%; }
        .teacher-assignment-section { background-color: #f8f9fa; padding: 1.5rem; border-radius: var(--border-radius); margin-bottom: 2rem; border: 1px solid #dee2e6; }
        .teacher-info { margin-top: 1rem; padding: 1rem; background-color: #e9ecef; border-radius: var(--border-radius); }
        .teacher-info p { margin: 0.5rem 0; }
        .teacher-assignment-form { display: flex; gap: 0.5rem; margin-top: 1rem; }
        .teacher-assignment-form select { flex: 1; padding: 0.75rem; border: 1px solid #ddd; border-radius: var(--border-radius); }
        @media (max-width: 768px) { .teacher-assignment-form { flex-direction: column; } .summary-row { flex-direction: column; } .summary-label,.summary-value { width: 100%; } }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <div class="card-header">
            <div class="page-title">
                <h1>ALS Enrollment System - Edit Student</h1>
                <a href="/AdminDashboard" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                <a href="/AllStudents" class="btn" style="background-color:#28a745;color:white;"><i class="fas fa-list"></i> View All Students</a>
            </div>
        </div>

        <div class="card-body">

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= $_SESSION['success'] ?><?php unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $_SESSION['error'] ?><?php unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <!-- Student ID display — shows the human-readable ID on screen, but URLs use tokens -->
            <div class="student-id-display">
                <h3>Student ID: <?= htmlspecialchars($formData['student_id']) ?></h3>
                <p>This ID is assigned to the student</p>
            </div>

            <!-- Teacher Assignment — posts to the same tokenised URL -->
            <div class="teacher-assignment-section">
                <h3 style="color:var(--primary-color);margin-top:0">Assign Teacher</h3>

                <?php if (!empty($formData['teacher_name'])): ?>
                    <div class="teacher-info">
                        <p><strong>Currently Assigned Teacher:</strong> <?= htmlspecialchars($formData['teacher_name']) ?></p>
                        <p><strong>Teacher ID:</strong> <?= htmlspecialchars($formData['teacher_id'] ?? 'N/A') ?></p>
                    </div>
                <?php else: ?>
                    <p>No teacher assigned to this student.</p>
                <?php endif; ?>

                <!--
                    POST action points to the tokenised URL — no student_id in the URL.
                    The server resolves the token from QUERY_STRING on the POST request.
                -->
                <form method="post" action="/EditStudents?<?= htmlspecialchars($page_token) ?>" class="teacher-assignment-form">
                    <input type="hidden" name="assign_teacher" value="1">
                    <select name="teacher_id">
                        <option value="">-- Select Teacher to Assign --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= htmlspecialchars($t['teacher_id']) ?>"
                                <?= ($formData['teacher_id'] ?? '') == $t['teacher_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($t['full_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-user-plus"></i> Assign Teacher</button>
                </form>
            </div>

            <!-- Main edit form -->
            <form method="post" action="/EditStudents?<?= htmlspecialchars($page_token) ?>" id="studentForm">
                <!-- student_id is needed by the UPDATE query — kept hidden but not used in URLs -->
                <input type="hidden" name="student_id" value="<?= htmlspecialchars($formData['student_id']) ?>">

                <div class="tabs">
                    <div class="tab active" data-tab="personal">Personal Information</div>
                    <div class="tab" data-tab="address">Address Information</div>
                    <div class="tab" data-tab="parents">Parent/Guardian Information</div>
                    <div class="tab" data-tab="education">Education &amp; Disability</div>
                    <div class="tab" data-tab="accessibility">Accessibility &amp; Learning</div>
                </div>

                <!-- ── Personal Information ── -->
                <div class="tab-content active" id="personalTab">
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="lrn">Learner Reference No. (LRN) <small>(Optional)</small></label>
                                <input type="text" id="lrn" name="lrn" value="<?= htmlspecialchars($formData['lrn'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="enrollment_date">Enrollment Date</label>
                                <input type="date" id="enrollment_date" name="enrollment_date" value="<?= htmlspecialchars($formData['enrollment_date'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="last_name" class="required-field">Last Name</label>
                                <input type="text" id="last_name" name="last_name" value="<?= htmlspecialchars($formData['last_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="first_name" class="required-field">First Name</label>
                                <input type="text" id="first_name" name="first_name" value="<?= htmlspecialchars($formData['first_name'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($formData['middle_name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="extension_name">Extension Name (Jr., III, etc.)</label>
                                <input type="text" id="extension_name" name="extension_name" value="<?= htmlspecialchars($formData['extension_name'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group">
                                <label for="birthdate" class="required-field">Birth Date</label>
                                <input type="date" id="birthdate" name="birthdate" value="<?= htmlspecialchars($formData['birthdate'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="age">Age</label>
                                <input type="number" id="age" name="age" readonly value="<?= htmlspecialchars($formData['age'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="sex">Sex</label>
                                <select id="sex" name="sex">
                                    <option value="male"   <?= ($formData['sex']??'')==='male'   ?'selected':'' ?>>Male</option>
                                    <option value="female" <?= ($formData['sex']??'')==='female' ?'selected':'' ?>>Female</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group">
                                <label for="civil_status">Civil Status</label>
                                <select id="civil_status" name="civil_status">
                                    <?php foreach ($civilStatuses as $v=>$l): ?>
                                        <option value="<?= $v ?>" <?= ($formData['civil_status']??'')===$v?'selected':'' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="contact_number">Contact Number</label><input type="tel" id="contact_number" name="contact_number" value="<?= htmlspecialchars($formData['contact_number']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="place_of_birth">Place of Birth</label><input type="text" id="place_of_birth" name="place_of_birth" value="<?= htmlspecialchars($formData['place_of_birth']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="religion">Religion</label><input type="text" id="religion" name="religion" value="<?= htmlspecialchars($formData['religion']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="mother_tongue">Mother Tongue</label><input type="text" id="mother_tongue" name="mother_tongue" value="<?= htmlspecialchars($formData['mother_tongue']??'') ?>"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="indigenous_community">Indigenous Community (if any)</label><input type="text" id="indigenous_community" name="indigenous_community" value="<?= htmlspecialchars($formData['indigenous_community']??'') ?>"></div></div>
                        <div class="form-col">
                            <div class="form-group"><label for="four_ps_beneficiary">4Ps Beneficiary</label>
                                <select id="four_ps_beneficiary" name="four_ps_beneficiary">
                                    <option value="no"  <?= ($formData['four_ps_beneficiary']??'')==='no' ?'selected':'' ?>>No</option>
                                    <option value="yes" <?= ($formData['four_ps_beneficiary']??'')==='yes'?'selected':'' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col"><div class="form-group"><label for="four_ps_id_number">4Ps ID Number</label><input type="text" id="four_ps_id_number" name="four_ps_id_number" value="<?= htmlspecialchars($formData['four_ps_id_number']??'') ?>"></div></div>
                    </div>
                </div>

                <!-- ── Address Information ── -->
                <div class="tab-content" id="addressTab">
                    <h3>Current Address</h3>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="current_house_no">House No./Lot/Bldg.</label><input type="text" id="current_house_no" name="current_house_no" value="<?= htmlspecialchars($formData['current_house_no']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="current_street">Street</label><input type="text" id="current_street" name="current_street" value="<?= htmlspecialchars($formData['current_street']??'') ?>"></div></div>
                        <div class="form-col">
                            <div class="form-group"><label for="current_barangay_id" class="required-field">Barangay</label>
                                <select id="current_barangay_id" name="current_barangay_id" required>
                                    <option value="">Select Barangay</option>
                                    <?php foreach ($barangays as $b): ?>
                                        <option value="<?= $b['barangay_id'] ?>" <?= ($formData['current_barangay_id']??0)==$b['barangay_id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="current_city">City</label><input type="text" id="current_city" name="current_city" value="<?= htmlspecialchars($formData['current_city']??'') ?>" readonly></div></div>
                        <div class="form-col"><div class="form-group"><label for="current_province">Province</label><input type="text" id="current_province" name="current_province" value="<?= htmlspecialchars($formData['current_province']??'') ?>" readonly></div></div>
                        <div class="form-col"><div class="form-group"><label for="current_country">Country</label><input type="text" id="current_country" name="current_country" value="<?= htmlspecialchars($formData['current_country']??'') ?>" readonly></div></div>
                        <div class="form-col"><div class="form-group"><label for="current_zip">ZIP Code</label><input type="text" id="current_zip" name="current_zip" value="<?= htmlspecialchars($formData['current_zip']??'') ?>" readonly></div></div>
                    </div>
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" id="same_address" name="same_address" value="yes" <?= ($formData['same_address']??'')==='yes'?'checked':'' ?>>
                            <label for="same_address">Permanent address is same as current address</label>
                        </div>
                    </div>
                    <div id="permanent_address_section" style="<?= ($formData['same_address']??'')==='yes'?'display:none;':'' ?>">
                        <h3>Permanent Address</h3>
                        <div class="form-row">
                            <div class="form-col"><div class="form-group"><label for="permanent_house_no">House No./Lot/Bldg.</label><input type="text" id="permanent_house_no" name="permanent_house_no" value="<?= htmlspecialchars($formData['permanent_house_no']??'') ?>"></div></div>
                            <div class="form-col"><div class="form-group"><label for="permanent_street">Street</label><input type="text" id="permanent_street" name="permanent_street" value="<?= htmlspecialchars($formData['permanent_street']??'') ?>"></div></div>
                            <div class="form-col">
                                <div class="form-group"><label for="permanent_barangay_id">Barangay</label>
                                    <select id="permanent_barangay_id" name="permanent_barangay_id">
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $b): ?>
                                            <option value="<?= $b['barangay_id'] ?>" <?= ($formData['permanent_barangay_id']??0)==$b['barangay_id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-col"><div class="form-group"><label for="permanent_city">City</label><input type="text" id="permanent_city" name="permanent_city" value="<?= htmlspecialchars($formData['permanent_city']??'') ?>"></div></div>
                            <div class="form-col"><div class="form-group"><label for="permanent_province">Province</label><input type="text" id="permanent_province" name="permanent_province" value="<?= htmlspecialchars($formData['permanent_province']??'') ?>"></div></div>
                            <div class="form-col"><div class="form-group"><label for="permanent_country">Country</label><input type="text" id="permanent_country" name="permanent_country" value="<?= htmlspecialchars($formData['permanent_country']??'') ?>"></div></div>
                            <div class="form-col"><div class="form-group"><label for="permanent_zip">ZIP Code</label><input type="text" id="permanent_zip" name="permanent_zip" value="<?= htmlspecialchars($formData['permanent_zip']??'') ?>"></div></div>
                        </div>
                    </div>
                </div>

                <!-- ── Parent/Guardian Information ── -->
                <div class="tab-content" id="parentsTab">
                    <h3>Father's Information</h3>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="father_last_name">Last Name</label><input type="text" id="father_last_name" name="father_last_name" value="<?= htmlspecialchars($formData['father_last_name']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="father_first_name">First Name</label><input type="text" id="father_first_name" name="father_first_name" value="<?= htmlspecialchars($formData['father_first_name']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="father_middle_name">Middle Name</label><input type="text" id="father_middle_name" name="father_middle_name" value="<?= htmlspecialchars($formData['father_middle_name']??'') ?>"></div></div>
                    </div>
                    <div class="form-row"><div class="form-col"><div class="form-group"><label for="father_occupation">Occupation</label><input type="text" id="father_occupation" name="father_occupation" value="<?= htmlspecialchars($formData['father_occupation']??'') ?>"></div></div></div>
                    <h3>Mother's Information</h3>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="mother_last_name">Last Name</label><input type="text" id="mother_last_name" name="mother_last_name" value="<?= htmlspecialchars($formData['mother_last_name']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="mother_first_name">First Name</label><input type="text" id="mother_first_name" name="mother_first_name" value="<?= htmlspecialchars($formData['mother_first_name']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="mother_middle_name">Middle Name</label><input type="text" id="mother_middle_name" name="mother_middle_name" value="<?= htmlspecialchars($formData['mother_middle_name']??'') ?>"></div></div>
                    </div>
                    <div class="form-row"><div class="form-col"><div class="form-group"><label for="mother_occupation">Occupation</label><input type="text" id="mother_occupation" name="mother_occupation" value="<?= htmlspecialchars($formData['mother_occupation']??'') ?>"></div></div></div>
                    <h3>Guardian's Information (if not parents)</h3>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="guardian_last_name">Last Name</label><input type="text" id="guardian_last_name" name="guardian_last_name" value="<?= htmlspecialchars($formData['guardian_last_name']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="guardian_first_name">First Name</label><input type="text" id="guardian_first_name" name="guardian_first_name" value="<?= htmlspecialchars($formData['guardian_first_name']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="guardian_middle_name">Middle Name</label><input type="text" id="guardian_middle_name" name="guardian_middle_name" value="<?= htmlspecialchars($formData['guardian_middle_name']??'') ?>"></div></div>
                    </div>
                    <div class="form-row"><div class="form-col"><div class="form-group"><label for="guardian_occupation">Occupation</label><input type="text" id="guardian_occupation" name="guardian_occupation" value="<?= htmlspecialchars($formData['guardian_occupation']??'') ?>"></div></div></div>
                </div>

                <!-- ── Education & Disability ── -->
                <div class="tab-content" id="educationTab">
                    <h3>Disability Information</h3>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group"><label for="is_pwd">Person with Disability (PWD)</label>
                                <select id="is_pwd" name="is_pwd">
                                    <option value="no"  <?= ($formData['is_pwd']??'')==='no' ?'selected':'' ?>>No</option>
                                    <option value="yes" <?= ($formData['is_pwd']??'')==='yes'?'selected':'' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group"><label for="has_pwd_id">Has PWD ID</label>
                                <select id="has_pwd_id" name="has_pwd_id">
                                    <option value="no"  <?= ($formData['has_pwd_id']??'')==='no' ?'selected':'' ?>>No</option>
                                    <option value="yes" <?= ($formData['has_pwd_id']??'')==='yes'?'selected':'' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row"><div class="form-col"><div class="form-group"><label for="disability_details">Disability Details</label><textarea id="disability_details" name="disability_details"><?= htmlspecialchars($formData['disability_details']??'') ?></textarea></div></div></div>
                    <h3>Educational Background</h3>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="last_grade_level">Last Grade Level Completed</label><input type="text" id="last_grade_level" name="last_grade_level" value="<?= htmlspecialchars($formData['last_grade_level']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="reason_not_in_school">Reason for Not Being in School</label><input type="text" id="reason_not_in_school" name="reason_not_in_school" value="<?= htmlspecialchars($formData['reason_not_in_school']??'') ?>"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group"><label for="attended_als_before">Attended ALS Before</label>
                                <select id="attended_als_before" name="attended_als_before">
                                    <option value="no"  <?= ($formData['attended_als_before']??'')==='no' ?'selected':'' ?>>No</option>
                                    <option value="yes" <?= ($formData['attended_als_before']??'')==='yes'?'selected':'' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col">
                            <div class="form-group"><label for="als_program">ALS Program</label>
                                <select id="als_program" name="als_program">
                                    <?php foreach ($alsPrograms as $v=>$l): ?>
                                        <option value="<?= $v ?>" <?= ($formData['als_program']??'')===$v?'selected':'' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group"><label for="completed_program">Completed Program</label>
                                <select id="completed_program" name="completed_program">
                                    <option value="no"  <?= ($formData['completed_program']??'')==='no' ?'selected':'' ?>>No</option>
                                    <option value="yes" <?= ($formData['completed_program']??'')==='yes'?'selected':'' ?>>Yes</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-col"><div class="form-group"><label for="incomplete_reason">Reason for Not Completing</label><input type="text" id="incomplete_reason" name="incomplete_reason" value="<?= htmlspecialchars($formData['incomplete_reason']??'') ?>"></div></div>
                    </div>
                </div>

                <!-- ── Accessibility & Learning ── -->
                <div class="tab-content" id="accessibilityTab">
                    <h3>Accessibility to CLC</h3>
                    <div class="form-row">
                        <div class="form-col"><div class="form-group"><label for="distance_to_clc_km">Distance to CLC (km)</label><input type="number" step="0.1" id="distance_to_clc_km" name="distance_to_clc_km" value="<?= htmlspecialchars($formData['distance_to_clc_km']??'') ?>"></div></div>
                        <div class="form-col"><div class="form-group"><label for="distance_to_clc_time">Travel Time to CLC (minutes)</label><input type="number" id="distance_to_clc_time" name="distance_to_clc_time" value="<?= htmlspecialchars($formData['distance_to_clc_time']??'') ?>"></div></div>
                    </div>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-group"><label for="transport_mode">Mode of Transportation</label>
                                <select id="transport_mode" name="transport_mode">
                                    <?php foreach ($transportModes as $v=>$l): ?>
                                        <option value="<?= $v ?>" <?= ($formData['transport_mode']??'')===$v?'selected':'' ?>><?= $l ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-col" id="transport_mode_other_container" style="<?= ($formData['transport_mode']??'')==='others'?'':'display:none;' ?>">
                            <div class="form-group"><label for="transport_mode_other">Specify Other Transportation</label><input type="text" id="transport_mode_other" name="transport_mode_other" value="<?= htmlspecialchars($formData['transport_mode_other']??'') ?>"></div>
                        </div>
                    </div>
                    <div class="form-row"><div class="form-col"><div class="form-group"><label for="availability_schedule">Preferred Learning Schedule/Availability</label><textarea id="availability_schedule" name="availability_schedule"><?= htmlspecialchars($formData['availability_schedule']??'') ?></textarea></div></div></div>
                    <h3>Preferred Learning Modalities</h3>
                    <div class="form-row">
                        <div class="form-col">
                            <div class="form-check"><input type="checkbox" id="prefers_blended"     name="prefers_blended"     value="yes" <?= ($formData['prefers_blended']??'')==='yes'    ?'checked':'' ?>><label for="prefers_blended">Blended Learning</label></div>
                            <div class="form-check"><input type="checkbox" id="prefers_homeschooling" name="prefers_homeschooling" value="yes" <?= ($formData['prefers_homeschooling']??'')==='yes'?'checked':'' ?>><label for="prefers_homeschooling">Homeschooling</label></div>
                            <div class="form-check"><input type="checkbox" id="prefers_modular_print" name="prefers_modular_print" value="yes" <?= ($formData['prefers_modular_print']??'')==='yes' ?'checked':'' ?>><label for="prefers_modular_print">Modular (Print)</label></div>
                        </div>
                        <div class="form-col">
                            <div class="form-check"><input type="checkbox" id="prefers_modular_digital" name="prefers_modular_digital" value="yes" <?= ($formData['prefers_modular_digital']??'')==='yes'?'checked':'' ?>><label for="prefers_modular_digital">Modular (Digital)</label></div>
                            <div class="form-check"><input type="checkbox" id="prefers_online"    name="prefers_online"    value="yes" <?= ($formData['prefers_online']??'')==='yes'   ?'checked':'' ?>><label for="prefers_online">Online Learning</label></div>
                            <div class="form-check"><input type="checkbox" id="prefers_radio_tv"  name="prefers_radio_tv"  value="yes" <?= ($formData['prefers_radio_tv']??'')==='yes' ?'checked':'' ?>><label for="prefers_radio_tv">Radio/TV-Based Instruction</label></div>
                            <div class="form-check"><input type="checkbox" id="prefers_edu_tv"    name="prefers_edu_tv"    value="yes" <?= ($formData['prefers_edu_tv']??'')==='yes'   ?'checked':'' ?>><label for="prefers_edu_tv">Educational TV</label></div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="reset" class="btn" style="background-color:#6c757d;color:white;"><i class="fas fa-redo"></i> Reset Form</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Student</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div id="successModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h2>Student Updated Successfully!</h2><span class="close">&times;</span></div>
        <div class="modal-body">
            <div class="student-info"><h3 id="modalStudentName"></h3><p id="modalStudentId"></p></div>
            <div class="qr-code-container"><img id="modalQrCode" src="" alt="Student QR Code"></div>
        </div>
        <div class="modal-footer">
            <button type="button" id="summaryBtn" class="btn btn-primary"><i class="fas fa-file-alt"></i> View Summary</button>
            <button type="button" class="btn btn-outline close-modal"><i class="fas fa-times"></i> Close</button>
        </div>
    </div>
</div>

<!-- Summary Modal -->
<div id="summaryModal" class="modal">
    <div class="modal-content" style="max-width:800px">
        <div class="modal-header"><h2>Student Summary</h2><span class="close">&times;</span></div>
        <div class="modal-body"><div id="enrollmentSummary"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-outline close-modal"><i class="fas fa-times"></i> Close</button></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Tabs ──
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const id = tab.getAttribute('data-tab');
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(id + 'Tab').classList.add('active');
        });
    });

    // ── Auto-calculate age ──
    document.getElementById('birthdate').addEventListener('change', function () {
        if (!this.value) return;
        const bd = new Date(this.value), today = new Date();
        let age = today.getFullYear() - bd.getFullYear();
        const m = today.getMonth() - bd.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < bd.getDate())) age--;
        document.getElementById('age').value = age;
    });

    // ── Same address toggle ──
    document.getElementById('same_address').addEventListener('change', function () {
        document.getElementById('permanent_address_section').style.display = this.checked ? 'none' : 'block';
    });

    // ── Transport other toggle ──
    document.getElementById('transport_mode').addEventListener('change', function () {
        document.getElementById('transport_mode_other_container').style.display = this.value === 'others' ? 'block' : 'none';
    });

    // ── Form validation ──
    document.getElementById('studentForm').addEventListener('submit', function (e) {
        let valid = true;
        this.querySelectorAll('[required]').forEach(f => {
            if (!f.value.trim()) { valid = false; f.style.borderColor = '#f72585'; }
            else f.style.borderColor = '';
        });
        if (!valid) { e.preventDefault(); alert('Please fill in all required fields.'); }
    });

    // ── Modals ──
    const successModal = document.getElementById('successModal');
    const summaryModal = document.getElementById('summaryModal');

    document.querySelectorAll('.close, .close-modal').forEach(btn => {
        btn.addEventListener('click', () => { successModal.style.display = 'none'; summaryModal.style.display = 'none'; });
    });
    window.addEventListener('click', e => {
        if (e.target === successModal) successModal.style.display = 'none';
        if (e.target === summaryModal) summaryModal.style.display = 'none';
    });

    document.getElementById('summaryBtn').addEventListener('click', () => {
        successModal.style.display = 'none';
        showSummaryModal();
    });

    function showSuccessModal() {
        const data = <?= json_encode($_SESSION['enrolled_student'] ?? []) ?>;
        if (!Object.keys(data).length) return;
        document.getElementById('modalStudentName').textContent = data.full_name || '';
        document.getElementById('modalStudentId').textContent   = 'ID: ' + (data.student_id || '');
        const img = document.getElementById('modalQrCode');
        img.src = data.qr_code ? '../' + data.qr_code : '';
        img.onerror = () => img.style.display = 'none';
        img.onload  = () => img.style.display = 'block';
        successModal.style.display = 'flex';
    }

    function showSummaryModal() {
        const data = <?= json_encode($_SESSION['enrolled_student'] ?? []) ?>;
        if (!data.form_data) return;
        const f = data.form_data;
        document.getElementById('enrollmentSummary').innerHTML = `
            <div class="summary-section">
                <h3>Personal Information</h3>
                <div class="summary-row"><div class="summary-label">Student ID:</div><div class="summary-value">${f.student_id||''}</div></div>
                <div class="summary-row"><div class="summary-label">Full Name:</div><div class="summary-value">${f.last_name}, ${f.first_name} ${f.middle_name||''}</div></div>
                <div class="summary-row"><div class="summary-label">Birth Date:</div><div class="summary-value">${f.birthdate||''}</div></div>
                <div class="summary-row"><div class="summary-label">Contact:</div><div class="summary-value">${f.contact_number||''}</div></div>
                <div class="summary-row"><div class="summary-label">Sex:</div><div class="summary-value">${f.sex||''}</div></div>
                <div class="summary-row"><div class="summary-label">Civil Status:</div><div class="summary-value">${f.civil_status||''}</div></div>
            </div>
            <div class="summary-section">
                <h3>Address</h3>
                <div class="summary-row"><div class="summary-label">Current:</div><div class="summary-value">
                    ${(f.current_house_no||'')+' '+(f.current_street||'')},
                    ${document.querySelector('#current_barangay_id option[value="'+f.current_barangay_id+'"]')?.text||''},
                    ${f.current_city||''}
                </div></div>
            </div>
            <div class="summary-section">
                <h3>Education</h3>
                <div class="summary-row"><div class="summary-label">Last Grade Level:</div><div class="summary-value">${f.last_grade_level||''}</div></div>
                <div class="summary-row"><div class="summary-label">ALS Program:</div><div class="summary-value">${document.querySelector('#als_program option[value="'+f.als_program+'"]')?.text||''}</div></div>
            </div>
            <div class="summary-section">
                <h3>Learning Modalities</h3>
                <div class="summary-row"><div class="summary-label">Preferred:</div><div class="summary-value">${
                    [f.prefers_blended==='yes'?'Blended':'',f.prefers_homeschooling==='yes'?'Homeschooling':'',
                     f.prefers_modular_print==='yes'?'Modular (Print)':'',f.prefers_modular_digital==='yes'?'Modular (Digital)':'',
                     f.prefers_online==='yes'?'Online':'',f.prefers_radio_tv==='yes'?'Radio/TV':'',f.prefers_edu_tv==='yes'?'Edu TV':'']
                    .filter(Boolean).join(', ')||'None'
                }</div></div>
            </div>`;
        summaryModal.style.display = 'flex';
    }

    <?php if (isset($_SESSION['enrolled_student']) && isset($_GET['success'])): ?>
        showSuccessModal();
        <?php unset($_SESSION['enrolled_student']); ?>
    <?php endif; ?>
});
</script>
</body>
</html>
<?php $conn->close(); ?>