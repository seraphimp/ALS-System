<?php
// generate_als_form_fixed.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: ../../index.php');
    exit();
}

require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

// ══════════════════════════════════════════════════════════════════════════
// CONSTANTS — defined here at the TOP before anything else runs
// Adjust AF1_ROWS_PER_PAGE to match the number of blank data rows in your
// Excel template (count the rows between the header and summary section).
// ══════════════════════════════════════════════════════════════════════════
define('AF1_ROWS_PER_PAGE', 20);   // how many students fit on one page
define('AF1_START_ROW',     9);    // first data row in the template

// ── Filter parameters ──────────────────────────────────────────────────────
$school_year     = isset($_GET['sy'])       ? trim($_GET['sy'])       : '';
$barangay_filter = isset($_GET['barangay']) ? trim($_GET['barangay']) : '';
$teacher_filter  = isset($_GET['teacher'])  ? trim($_GET['teacher'])  : '';
$form_type       = isset($_GET['form'])     ? trim($_GET['form'])     : 'AF-1';

// ── Lookup data ────────────────────────────────────────────────────────────
$barangays = $conn->query(
    "SELECT * FROM barangays WHERE status = 'active' ORDER BY name"
)->fetch_all(MYSQLI_ASSOC);

// ── Build WHERE clause ── IMPORTANT FIX: Use current_barangay_id to match reports.php ──
$where_conditions = ["s.status = 'enrolled'"];
$params      = [];
$param_types = "";

if (!empty($school_year)) {
    $start_year = explode('-', $school_year)[0];
    $where_conditions[] = "YEAR(s.enrollment_date) = ?";
    $params[]      = (int)$start_year;
    $param_types  .= "i";
}

if (!empty($barangay_filter)) {
    // FIX: Changed from permanent_barangay_id to current_barangay_id to match reports.php
    $where_conditions[] = "s.current_barangay_id = ?";
    $params[]      = (int)$barangay_filter;
    $param_types  .= "i";
}

if (!empty($teacher_filter)) {
    $where_conditions[] = "s.teacher_id = ?";
    $params[]      = (int)$teacher_filter;
    $param_types  .= "i";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// ── Fetch students ─────────────────────────────────────────────────────────
$query = "
    SELECT s.*, t.full_name AS teacher_name
    FROM students s
    LEFT JOIN teachers t ON s.teacher_id = t.teacher_id
    $where_clause
    ORDER BY s.last_name, s.first_name
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($param_types, ...$params);
}
$stmt->execute();
$students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

if (empty($students)) {
    die("No student data found for the selected criteria.");
}

// ── Load template ──────────────────────────────────────────────────────────
$template_path = __DIR__ . '/templates/Alternative Learning System Forms.xlsx';
if (!file_exists($template_path)) {
    die("Template file not found at: $template_path<br>
         Please upload 'Alternative Learning System Forms.xlsx' to the templates folder.");
}

$spreadsheet = IOFactory::load($template_path);

// ── Fill the form ──────────────────────────────────────────────────────────
fillAF1Form($spreadsheet, $students, $barangays, $school_year);

// ── Output ─────────────────────────────────────────────────────────────────
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="ALS_AF1_Form_' . date('Ymd_His') . '.xlsx"');
header('Cache-Control: max-age=0');

$writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
$writer->save('php://output');
exit();

// ══════════════════════════════════════════════════════════════════════════
// FUNCTIONS  (constants are already defined above, so no errors here)
// ══════════════════════════════════════════════════════════════════════════

/**
 * Split students across one or more template pages and fill each one.
 */
function fillAF1Form($spreadsheet, $students, $barangays, $school_year = '') {

    // Split into page-sized chunks using the constant defined at the top
    $chunks     = array_chunk($students, AF1_ROWS_PER_PAGE);
    $totalPages = count($chunks);

    // Hold a reference to the original sheet before any cloning
    $originalSheet = $spreadsheet->getSheet(0);

    foreach ($chunks as $pageIndex => $chunk) {

        if ($pageIndex === 0) {
            $sheet = $originalSheet;
        } else {
            // Clone the original template for every extra page
            $cloned = clone $originalSheet;
            $cloned->setTitle('AF1 Page ' . ($pageIndex + 1));
            $spreadsheet->addSheet($cloned);
            $sheet = $cloned;
        }

        // ── Header fields ────────────────────────────────────────────────
        $sheet->setCellValue('E5', 'DISTRICT 4');
        $sheet->setCellValue('M5', 'LA CARLOTA');
        $sheet->setCellValue('V5', 'VI');
        $sheet->setCellValue('AB5', !empty($school_year) ? $school_year : date('Y'));

        if ($totalPages > 1) {
            $sheet->setCellValue('AC5', 'Page ' . ($pageIndex + 1) . ' of ' . $totalPages);
        }

        // ── Student rows ─────────────────────────────────────────────────
        foreach ($chunk as $rowOffset => $student) {
            fillStudentRow($sheet, AF1_START_ROW + $rowOffset, $student, $barangays);
        }

        // ── Per-page summary statistics ───────────────────────────────────
        updateSummaryStatistics($sheet, $chunk);
    }

    $spreadsheet->setActiveSheetIndex(0);
}

/**
 * Write one student's data into a specific row.
 */
function fillStudentRow($sheet, $current_row, $student, $barangays) {

    // FIX: Use current_barangay_id instead of permanent_barangay_id for address display
    $barangay_name = getBarangayName($student['current_barangay_id'] ?? null, $barangays);

    // Column A — Full name
    $full_name = $student['last_name'] . ', ' . $student['first_name'];
    if (!empty($student['extension_name'])) $full_name .= ' ' . $student['extension_name'];
    if (!empty($student['middle_name']))    $full_name .= ' ' . $student['middle_name'];
    $sheet->setCellValue('A' . $current_row, strtoupper($full_name));

    // Column D — Sex
    $sheet->setCellValue('D' . $current_row,
        !empty($student['sex']) ? strtoupper(substr($student['sex'], 0, 1)) : '');

    // Column E — Date of birth
    if (!empty($student['birthdate'])) {
        $sheet->setCellValue('E' . $current_row, date('m/d/Y', strtotime($student['birthdate'])));
    }

    // Column F — Age
    if (isset($student['age']) && $student['age'] !== '') {
        $sheet->setCellValue('F' . $current_row, $student['age']);
    } elseif (!empty($student['birthdate'])) {
        $sheet->setCellValue('F' . $current_row,
            (new DateTime($student['birthdate']))->diff(new DateTime())->y);
    }

    // Column G — Mother tongue
    $sheet->setCellValue('G' . $current_row, $student['mother_tongue'] ?? '');

    // Column H — IP status
    $ip = (!empty($student['indigenous_community']) &&
           strtolower($student['indigenous_community']) !== 'none') ? 'Yes' : 'No';
    $sheet->setCellValue('H' . $current_row, $ip);

    // Column I — Religion
    $sheet->setCellValue('I' . $current_row, $student['religion'] ?? '');

    // Column J — House / Street (use current address, not permanent)
    $sheet->setCellValue('J' . $current_row,
        trim(($student['current_house_no'] ?? $student['permanent_house_no'] ?? '') . ' ' . 
             ($student['current_street'] ?? $student['permanent_street'] ?? '')));

    // Column K — Barangay (use current barangay)
    $sheet->setCellValue('K' . $current_row, $barangay_name);

    // Columns N & O — Municipality / City (use current city)
    $city = $student['current_city'] ?? $student['permanent_city'] ?? '';
    $sheet->setCellValue('N' . $current_row, $city);
    $sheet->setCellValue('O' . $current_row, $city);

    // Columns P & Q — Province (use current province)
    $province = $student['current_province'] ?? $student['permanent_province'] ?? '';
    $sheet->setCellValue('P' . $current_row, $province);
    $sheet->setCellValue('Q' . $current_row, $province);

    // Column R — Father's name
    $sheet->setCellValue('R' . $current_row, buildParentName(
        $student['father_last_name']   ?? '',
        $student['father_first_name']  ?? '',
        $student['father_middle_name'] ?? ''
    ));

    // Column U — Mother's maiden name
    $sheet->setCellValue('U' . $current_row, buildParentName(
        $student['mother_last_name']   ?? '',
        $student['mother_first_name']  ?? '',
        $student['mother_middle_name'] ?? ''
    ));

    // Column W — Contact number
    $sheet->setCellValue('W' . $current_row, $student['contact_number'] ?? '');

    // Column Y — Last grade level completed
    $sheet->setCellValue('Y' . $current_row, $student['last_grade_level'] ?? '');

    // Column Z — Date mapped (enrollment date)
    if (!empty($student['enrollment_date'])) {
        $sheet->setCellValue('Z' . $current_row, date('m/d/y', strtotime($student['enrollment_date'])));
    } else {
        $sheet->setCellValue('Z' . $current_row, date('m/d/y'));
    }

    // Column AB — ALS preferred program
    $program = strtolower(trim($student['als_program'] ?? ''));
    if ($program !== '') {
        $map = [
            'basic literacy' => 'Basic Literacy Program (BLP)',
            'a&e elementary' => 'A&E Elementary Level',
            'a&e secondary'  => 'A&E Secondary Level',
            'als-shs'        => 'ALS Senior High School',
        ];
        $sheet->setCellValue('AB' . $current_row, $map[$program] ?? $student['als_program']);
    }

    // Column AC — LRN (force text to prevent scientific notation)
    $lrn = trim($student['lrn'] ?? '');
    if ($lrn !== '') {
        $sheet->setCellValueExplicit(
            'AC' . $current_row,
            $lrn,
            \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING
        );
        $sheet->getStyle('AC' . $current_row)->getNumberFormat()->setFormatCode('@');
    } else {
        $sheet->setCellValue('AC' . $current_row, '');
    }
}

/**
 * Fill the summary statistics block at the bottom of a sheet page.
 */
function updateSummaryStatistics($sheet, $students) {
    $total  = count($students);
    $male   = count(array_filter($students, fn($s) => in_array(strtolower($s['sex'] ?? ''), ['male', 'm'])));
    $female = count(array_filter($students, fn($s) => in_array(strtolower($s['sex'] ?? ''), ['female', 'f'])));
    $date   = date('m/d/y');

    // Mapped learners block
    $sheet->setCellValue('J29', $male);
    $sheet->setCellValue('J30', $female);
    $sheet->setCellValue('J31', $total);

    // Enrolled learners block
    $sheet->setCellValue('Q29', $male);
    $sheet->setCellValue('Q30', $female);
    $sheet->setCellValue('Q31', $total);

    // Scan for "Date Mapped" label
    for ($row = 25; $row <= 35; $row++) {
        if (stripos((string)$sheet->getCell('G' . $row)->getValue(), 'date mapped') !== false) {
            $sheet->setCellValue('H' . $row, $date);
            break;
        }
    }

    // Scan for "Enrollment" date label
    for ($row = 25; $row <= 35; $row++) {
        if (stripos((string)$sheet->getCell('M' . $row)->getValue(), 'enroll') !== false) {
            $sheet->setCellValue('N' . $row, $date);
            break;
        }
    }
}

// ── Helpers ────────────────────────────────────────────────────────────────

function buildParentName($last, $first, $middle) {
    $name = '';
    if (!empty($last))   $name .= $last . ', ';
    if (!empty($first))  $name .= $first . ' ';
    if (!empty($middle)) $name .= strtoupper(substr($middle, 0, 1)) . '.';
    return trim($name, ', ');
}

function getBarangayName($barangay_id, $barangays) {
    if (empty($barangay_id)) return '';
    foreach ($barangays as $b) {
        if ($b['barangay_id'] == $barangay_id) return $b['name'];
    }
    return '';
}
?>