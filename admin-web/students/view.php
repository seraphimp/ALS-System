<?php
/**
 * ALS Enrollment Form (AF2) – Learner's Basic Profile
 *
 * URL format: /ViewStudent?<40-hex-token>
 * There is NO parameter key — the entire query string IS the token.
 * Plain student IDs like ALS-2026-030 are rejected entirely.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();
if (!is_admin_logged_in()) {
    header('Location: /admin-secure');
    exit();
}

// ── Token resolver ────────────────────────────────────────────────────────────
// Shared with all-student.php via the same session key '_st'.
function resolve_token(string $token): string {
    $token = preg_replace('/[^a-f0-9]/', '', strtolower(trim($token)));
    if (strlen($token) !== 40) return '';
    return $_SESSION['_st'][$token] ?? '';
}

// ── Read the token ────────────────────────────────────────────────────────────
// The entire QUERY_STRING is the token — no key=value pair at all.
// /ViewStudent?a3f9c2e1d4b7a3f9c2e1d4b7a3f9c2e1d4b7a3f9
$raw_token = trim($_SERVER['QUERY_STRING'] ?? '');

// Fallback: also accept ?t=<token> so bookmarks/redirects with _t still work
if (empty($raw_token) && isset($_GET['_t'])) {
    $raw_token = trim($_GET['_t']);
}

if (empty($raw_token)) {
    http_response_code(400);
    die(error_page("No Token Provided", "Please access this page through the student list."));
}

$student_id = resolve_token($raw_token);

if (empty($student_id)) {
    http_response_code(403);
    die(error_page(
        "Invalid or Expired Link",
        "This link is invalid, expired, or has beenpri tampered with.<br>Direct access using student IDs is not permitted."
    ));
}

function error_page(string $title, string $msg): string {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title>
    <style>body{font-family:Arial,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f4f6f9}
    .box{background:#fff;border-radius:12px;padding:48px;max-width:480px;text-align:center;box-shadow:0 4px 24px rgba(0,0,0,.1)}
    h2{color:#721c24;margin:0 0 12px}p{color:#555;margin:0 0 24px;line-height:1.6}
    a{background:#1a56db;color:#fff;padding:10px 22px;border-radius:6px;text-decoration:none;font-size:14px}</style></head>
    <body><div class="box"><h2>'.$title.'</h2><p>'.$msg.'</p>
    <a href="/AllStudents">← Back to Student List</a></div></body></html>';
}

// ── Fetch student ─────────────────────────────────────────────────────────────
$q = $conn->prepare(
    "SELECT s.*, b1.name AS current_barangay_name, b2.name AS permanent_barangay_name
     FROM students s
     LEFT JOIN barangays b1 ON b1.barangay_id = s.current_barangay_id
     LEFT JOIN barangays b2 ON b2.barangay_id = s.permanent_barangay_id
     WHERE s.student_id = ? LIMIT 1"
);
$q->bind_param("s", $student_id);
$q->execute();
$student = $q->get_result()->fetch_assoc();
$q->close();

if (!$student) {
    http_response_code(404);
    die(error_page("Student Not Found", "No record matches this link."));
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function h($v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

function charBoxes(string $value, int $count): string {
    $chars = str_split(strtoupper(substr($value, 0, $count)));
    $html  = '<div class="char-boxes">';
    for ($i = 0; $i < $count; $i++) {
        $html .= '<div class="cbox">' . h($chars[$i] ?? '') . '</div>';
    }
    return $html . '</div>';
}

function parseDate(?string $d): array {
    if (!$d) return ['', '', ''];
    try { $dt = new DateTime($d); return [$dt->format('m'), $dt->format('d'), $dt->format('Y')]; }
    catch (Exception $e) { return ['', '', '']; }
}

function cb(bool $checked): string {
    return $checked
        ? '<span class="checkbox-sq checked">✓</span>'
        : '<span class="checkbox-sq"></span>';
}

function wl(?string $val): string {
    return '<div class="write-line">' . h($val) . '</div>';
}

// ── Parse data ────────────────────────────────────────────────────────────────
[$eM,$eD,$eY] = parseDate($student['enrollment_date']);
[$bM,$bD,$bY] = parseDate($student['birthdate']);

$schedule          = json_decode($student['availability_schedule'] ?? '[]', true) ?? [];
$disabilityDetails = $student['disability_details'] ?? '';

$isPwd      = strtolower($student['is_pwd']              ?? '') === 'yes';
$hasPwdId   = strtolower($student['has_pwd_id']          ?? '') === 'yes';
$isIp       = !empty($student['indigenous_community']);
$is4Ps      = strtolower($student['four_ps_beneficiary'] ?? '') === 'yes';
$sameAddr   = strtolower($student['same_address']        ?? '') === 'yes';
$attendedAls= strtolower($student['attended_als_before'] ?? '') === 'yes';
$completedP = strtolower($student['completed_program']   ?? '') === 'yes';

$gradeLevel = strtolower($student['last_grade_level']     ?? '');
$reasonList = strtolower($student['reason_not_in_school'] ?? '');
$transport  = strtolower($student['transport_mode']       ?? '');
$sex        = strtolower($student['sex']                  ?? '');
$civil      = strtolower($student['civil_status']         ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <link rel="icon" href="/logo">
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>ALS AF2 – <?= h($student['last_name']) ?>, <?= h($student['first_name']) ?></title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:Arial,Helvetica,sans-serif;font-size:10.5px;background:#ccc;color:#000;line-height:1.25}
    .print-bar{position:fixed;top:0;left:0;right:0;background:#1a3a6e;color:#fff;padding:8px 20px;display:flex;align-items:center;justify-content:space-between;z-index:999;font-size:13px;gap:12px}
    .print-bar strong{font-size:14px}
    .print-bar .bar-actions{display:flex;gap:10px;align-items:center}
    .print-bar button{background:#f0a500;color:#000;border:none;padding:6px 20px;border-radius:4px;font-size:13px;font-weight:bold;cursor:pointer}
    .print-bar button:hover{background:#d4920a}
    .btn-back{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);padding:6px 16px;border-radius:4px;font-size:13px;cursor:pointer;text-decoration:none}
    .btn-back:hover{background:rgba(255,255,255,.25)}
    .page{width:215mm;min-height:297mm;margin:56px auto 12px;background:#fff;padding:12mm 12mm 10mm;border:1px solid #888;box-shadow:0 4px 12px rgba(0,0,0,.2)}
    .form-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:4px}
    .form-header .enclosure{font-size:9px;font-style:italic}
    .form-header .annex-box{text-align:right;font-size:9px}
    .header-main{display:flex;align-items:center;justify-content:center;gap:2px;margin:3px 0;width:100%}
    .header-logo{width:45px;height:45px;border:1px solid #999;display:flex;align-items:center;justify-content:center;font-size:8px;text-align:center;color:#555;border-radius:50%;overflow:hidden;flex-shrink:0;background:#fff}
    .header-logo-right{width:45px;height:45px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .header-logo img,.header-logo-right img{width:100%;height:100%;object-fit:contain}
    .header-center{text-align:center;white-space:nowrap;padding:0 2px}
    .header-center .main-title{font-size:14px;font-weight:bold;text-transform:uppercase;letter-spacing:.3px;color:#000;line-height:1.2}
    .header-center .sub-title{font-size:11px;font-weight:bold;margin:0;line-height:1.2}
    .header-center .not-sale{font-size:8px;font-style:italic;color:#555;line-height:1.2}
    .instructions{border:1px solid #000;padding:5px 7px;font-size:9.5px;font-style:italic;margin:6px 0 8px;line-height:1.4}
    .instructions strong{font-style:normal}
    .date-lrn-row{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:7px;gap:20px}
    .field-label{font-weight:bold;font-size:9.5px;display:block;margin-bottom:3px}
    .char-boxes{display:flex;align-items:center;gap:0}
    .cbox{width:17px;height:20px;border:1px solid #000;flex-shrink:0;border-right:none;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:bold;background:#fff}
    .cbox:last-child{border-right:1px solid #000}
    .sep{font-size:15px;padding:0 2px;font-weight:bold}
    .section-head{font-weight:bold;font-size:11px;background:#eaeaea;border:1px solid #000;border-bottom:none;padding:3px 7px;margin-top:8px}
    table.ft{width:100%;border-collapse:collapse;font-size:10px}
    table.ft td,table.ft th{border:1px solid #000;padding:3px 5px;vertical-align:top}
    table.ft th{background:#f0f0f0;font-weight:bold;font-size:9.5px;padding:4px 5px}
    .cell-label{font-size:8.5px;font-weight:bold;display:block;margin-bottom:2px}
    .write-line{border-bottom:1px solid #000;min-height:16px;display:block;font-size:10px;padding:1px 2px}
    .write-area{min-height:18px;font-size:10px;padding:1px 2px}
    .checkbox-sq{display:inline-flex;align-items:center;justify-content:center;width:11px;height:11px;border:1px solid #000;vertical-align:middle;flex-shrink:0;margin-right:2px;font-size:9px;font-weight:bold}
    .checkbox-sq.checked{background:#000;color:#fff}
    .cb-wrap{display:flex;align-items:center;gap:4px;white-space:nowrap}
    .cb-label{font-size:9.5px}
    .row{display:flex;gap:8px;align-items:flex-end}.grow{flex:1}.mt4{margin-top:4px}
    .disability-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:2px 10px;margin-top:4px}
    table.grade-table{width:100%;border-collapse:collapse;font-size:9.5px;margin-top:3px}
    table.grade-table td,table.grade-table th{border:1px solid #000;padding:3px 5px;vertical-align:middle}
    table.grade-table th{text-align:center;font-weight:bold;background:#f8f8f8}
    .grade-check-row{display:flex;flex-wrap:wrap;gap:4px 12px;padding:3px 4px}
    table.sched{width:100%;border-collapse:collapse;font-size:9.5px;margin-top:4px}
    table.sched td,table.sched th{border:1px solid #000;padding:3px 5px;text-align:center;height:26px}
    table.sched th{font-style:italic;font-weight:normal;background:#fafafa}
    .modality-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:3px 10px;padding:4px 6px}
    .sig-row{display:flex;justify-content:space-between;align-items:flex-end;margin-top:24px;gap:40px}
    .sig-block{flex:1}.sig-line{border-top:1px solid #000;margin-top:28px;padding-top:2px}
    .sig-caption{font-size:9px;font-style:italic;text-align:center;margin-top:2px}
    .cert-text{font-size:9.5px;text-align:justify;margin-top:14px;line-height:1.5}
    .page2{margin-top:12px}
    .id-strip{font-size:9px;text-align:right;color:#555;margin-bottom:2px;font-style:italic}

    @media print {
      body{background:none}
      .print-bar{display:none!important}

      .page{
        margin:0!important;
        border:none!important;
        box-shadow:none!important;
        /* CRITICAL: force exact A4 content width */
        width:190mm!important;
        padding:0!important;
        min-height:auto!important;
        page-break-after:always;
      }
      .page:last-child{page-break-after:avoid}
      .page2{margin-top:0!important}

      /* CRITICAL: shrink char boxes so birthdate fits in its cell */
      .cbox{
        width:11px!important;
        min-width:11px!important;
        max-width:11px!important;
        height:13px!important;
        font-size:7.5px!important;
        flex-shrink:0!important;
      }
      .sep{
        font-size:10px!important;
        padding:0 1px!important;
      }

      /* CRITICAL: fix table layout so columns don't overflow */
      table.ft{
        table-layout:fixed!important;
        width:100%!important;
      }
      table.ft td{
        overflow:hidden!important;
        word-break:break-all!important;
      }

      /* Personal info: left 60%, right 40% — gives right cell enough room */
      table.ft > tbody > tr > td:nth-child(1){width:60%!important;}
      table.ft > tbody > tr > td:nth-child(2){width:40%!important;}

      /* Address rows: lock column widths */
      table.ft td[style*="18%"]{width:15%!important;}
      table.ft td[style*="42%"]{width:45%!important;}
      table.ft td[style*="40%"]{width:40%!important;}

      /* Parent section: 4 equal columns */
      table.ft td[style*="25%"]{width:25%!important;}

      @page{size:A4 portrait;margin:10mm 10mm}
    }
</style>
</head>
<body>

<div class="print-bar">
  <div>
    <strong>ALS Enrollment Form (AF2)</strong> &nbsp;|&nbsp;
    <?= h($student['last_name']) ?>, <?= h($student['first_name']) ?> <?= h($student['middle_name']) ?>
  </div>
  <div class="bar-actions">
    <a href="/AllStudents" class="btn-back">← Back to List</a>
    <button onclick="window.print()">🖨 Print / Save PDF</button>
  </div>
</div>

<!-- PAGE 1 -->
<div class="page">
  <div class="id-strip">Ref: <?= h($student['lrn'] ?? '—') ?></div>

  <div class="form-header">
    <span class="enclosure">(Enclosure No. 3 to DepEd Memorandum No. <strong>032</strong>, s. 2024)</span>
    <div class="annex-box">Revised as of 02/12/2024<br><strong>ANNEX 2</strong></div>
  </div>

  <div class="header-main">
    <div class="header-logo">
      <img src="/Logo" alt="DepEd Logo" onerror="this.style.display='none';this.parentNode.innerHTML='DepEd<br>LOGO';">
    </div>
    <div class="header-center">
      <div class="main-title">Modified ALS Enrollment Form</div>
      <div class="sub-title">(AF2) Learner's Basic Profile</div>
      <div class="not-sale">THIS FORM IS NOT FOR SALE.</div>
    </div>
    <div class="header-logo-right">
      <img src="/logo" alt="ALS Logo" onerror="this.style.display='none';this.parentNode.innerHTML='ALS<br>LOGO';">
    </div>
  </div>

  <div class="instructions">
    <strong>Instructions:</strong> Print legibly all information required in CAPITAL letters and check all appropriate boxes.
    Submit accomplished form to the Person-in-Charge/ALS Teacher/Community ALS Implementor/Learning Facilitator.
    Use black or blue pen only.
  </div>

  <!-- DATE / LRN -->
  <div class="date-lrn-row">
    <div>
      <span class="field-label">Date: (mm/dd/yyyy)</span>
      <div class="char-boxes">
        <div class="cbox"><?= h($eM[0]??'') ?></div><div class="cbox"><?= h($eM[1]??'') ?></div>
        <span class="sep">/</span>
        <div class="cbox"><?= h($eD[0]??'') ?></div><div class="cbox"><?= h($eD[1]??'') ?></div>
        <span class="sep">/</span>
        <?php foreach (str_split(str_pad($eY,4,'0',STR_PAD_LEFT)) as $c): ?><div class="cbox"><?= h($c) ?></div><?php endforeach; ?>
      </div>
    </div>
    <div>
      <span class="field-label">Learner Reference No. (LRN)? If available:</span>
      <?= charBoxes($student['lrn'] ?? '', 12) ?>
    </div>
  </div>

  <!-- SECTION 1 -->
  <div class="section-head">1. Learner's Personal Information</div>
  <table class="ft">
    <tr>
      <td style="width:62%;vertical-align:top">
        <span class="cell-label">Last Name</span><?= charBoxes($student['last_name'], 30) ?>
        <span class="cell-label" style="margin-top:5px;display:block">First Name</span><?= charBoxes($student['first_name'], 30) ?>
        <span class="cell-label" style="margin-top:5px;display:block">Middle Name</span><?= charBoxes($student['middle_name']??'', 30) ?>
        <div class="row mt4">
          <div><span class="cell-label">Extension Name e.g. Jr., III</span><?= charBoxes($student['extension_name']??'', 6) ?></div>
          <div class="grow"><span class="cell-label">Contact Number/s</span><?= wl($student['contact_number']) ?></div>
        </div>
        <div style="margin-top:6px;font-size:9.5px">Belonging to any Indigenous Peoples (IP) Community?
          <div class="row mt4" style="align-items:center">
            <?= cb($isIp) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$isIp) ?><span class="cb-label">No</span>&nbsp;
            <span style="font-size:9px">If Yes, specify:</span>
            <div class="grow"><?= wl($isIp ? $student['indigenous_community'] : '') ?></div>
          </div>
        </div>
        <div style="margin-top:5px;font-size:9.5px">Is your family a 4Ps beneficiary?&nbsp;
          <?= cb($is4Ps) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$is4Ps) ?><span class="cb-label">No</span>
        </div>
        <div style="font-size:9px;margin-top:3px">If Yes, write the 4Ps Household ID Number</div>
        <?= charBoxes($student['four_ps_id_number']??'', 20) ?>
      </td>
      <td style="width:38%;vertical-align:top">
        <span class="cell-label">Birthdate (mm/dd/yyyy)</span>
        <div class="char-boxes">
          <div class="cbox"><?= h($bM[0]??'') ?></div><div class="cbox"><?= h($bM[1]??'') ?></div>
          <span class="sep">/</span>
          <div class="cbox"><?= h($bD[0]??'') ?></div><div class="cbox"><?= h($bD[1]??'') ?></div>
          <span class="sep">/</span>
          <?php foreach (str_split(str_pad($bY,4,'0',STR_PAD_LEFT)) as $c): ?><div class="cbox"><?= h($c) ?></div><?php endforeach; ?>
        </div>
        <div class="row mt4">
          <div><span class="cell-label">Age</span>
            <div class="char-boxes">
              <?php $a=str_split(str_pad((string)($student['age']??''),2,'0',STR_PAD_LEFT)); ?>
              <div class="cbox"><?= h($a[0]??'') ?></div><div class="cbox"><?= h($a[1]??'') ?></div>
            </div>
          </div>
          <div><span class="cell-label">Sex</span>
            <div class="row"><?= cb($sex==='male') ?><span class="cb-label">Male</span>&nbsp;<?= cb($sex==='female') ?><span class="cb-label">Female</span></div>
          </div>
        </div>
        <span class="cell-label" style="margin-top:5px;display:block">Place of Birth</span><?= wl($student['place_of_birth']) ?>
        <span class="cell-label" style="margin-top:5px;display:block">Religion</span><?= wl($student['religion']) ?>
        <span class="cell-label" style="margin-top:5px;display:block">Mother Tongue</span><?= wl($student['mother_tongue']) ?>
        <span class="cell-label" style="margin-top:5px;display:block">Civil Status</span>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 8px;margin-top:2px">
          <?= cb($civil==='single') ?>    <span class="cb-label">Single</span>
          <?= cb($civil==='married') ?>   <span class="cb-label">Married</span>
          <?= cb($civil==='separated') ?> <span class="cb-label">Separated</span>
          <?= cb($civil==='widow/er') ?>  <span class="cb-label">Widow/er</span>
          <?= cb($civil==='solo parent') ?><span class="cb-label">Solo Parent</span>
        </div>
      </td>
    </tr>
  </table>

  <!-- CURRENT ADDRESS -->
  <div style="font-weight:bold;font-size:9.5px;border:1px solid #000;border-top:none;border-bottom:none;padding:3px 5px;background:#f5f5f5">Current Address</div>
  <table class="ft" style="border-top:none">
    <tr>
      <td style="width:18%"><span class="cell-label">House No.</span><div class="write-area"><?= h($student['current_house_no']) ?></div></td>
      <td style="width:42%"><span class="cell-label">Sitio/Street Name</span><div class="write-area"><?= h($student['current_street']) ?></div></td>
      <td style="width:40%"><span class="cell-label">Barangay</span><div class="write-area"><?= h($student['current_barangay_name']) ?></div></td>
    </tr>
    <tr>
      <td><span class="cell-label">Municipality/City</span><div class="write-area"><?= h($student['current_city']) ?></div></td>
      <td><span class="cell-label">Province</span><div class="write-area"><?= h($student['current_province']) ?></div></td>
      <td><table style="width:100%;border:none;border-collapse:collapse"><tr>
        <td style="border:none;padding:0;width:60%"><span class="cell-label">Country</span><div class="write-area"><?= h($student['current_country']) ?></div></td>
        <td style="border:none;padding:0 0 0 5px;width:40%"><span class="cell-label">Zip Code</span><div class="write-area"><?= h($student['current_zip']) ?></div></td>
      </tr></table></td>
    </tr>
  </table>

  <!-- PERMANENT ADDRESS -->
  <table class="ft" style="border-top:none">
    <tr><td colspan="3">
      <span style="font-weight:bold;font-size:9.5px">Permanent Address</span>&nbsp;&nbsp;
      <span style="font-size:9.5px">Same as Current?</span>&nbsp;
      <?= cb($sameAddr) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$sameAddr) ?><span class="cb-label">No</span>
      <span style="font-size:9px;margin-left:5px">If Yes, proceed to item 2</span>
    </td></tr>
    <?php
    $pH = $sameAddr?$student['current_house_no']:$student['permanent_house_no'];
    $pS = $sameAddr?$student['current_street']:$student['permanent_street'];
    $pB = $sameAddr?$student['current_barangay_name']:$student['permanent_barangay_name'];
    $pC = $sameAddr?$student['current_city']:$student['permanent_city'];
    $pP = $sameAddr?$student['current_province']:$student['permanent_province'];
    $pCo= $sameAddr?$student['current_country']:$student['permanent_country'];
    $pZ = $sameAddr?$student['current_zip']:$student['permanent_zip'];
    ?>
    <tr>
      <td style="width:18%"><span class="cell-label">House No.</span><div class="write-area"><?= h($pH) ?></div></td>
      <td style="width:42%"><span class="cell-label">Sitio/Street Name</span><div class="write-area"><?= h($pS) ?></div></td>
      <td style="width:40%"><span class="cell-label">Barangay</span><div class="write-area"><?= h($pB) ?></div></td>
    </tr>
    <tr>
      <td><span class="cell-label">Municipality/City</span><div class="write-area"><?= h($pC) ?></div></td>
      <td><span class="cell-label">Province</span><div class="write-area"><?= h($pP) ?></div></td>
      <td><table style="width:100%;border:none;border-collapse:collapse"><tr>
        <td style="border:none;padding:0;width:60%"><span class="cell-label">Country</span><div class="write-area"><?= h($pCo) ?></div></td>
        <td style="border:none;padding:0 0 0 5px;width:40%"><span class="cell-label">Zip Code</span><div class="write-area"><?= h($pZ) ?></div></td>
      </tr></table></td>
    </tr>
  </table>

  <!-- SECTION 2 -->
  <div class="section-head" style="margin-top:8px">2. Parent's/Guardian's Information</div>
  <table class="ft">
    <tr><td colspan="4" style="font-weight:bold;font-size:9.5px;background:#fafafa;padding:2px 5px">Father's Name</td></tr>
    <tr>
      <td style="width:25%"><span class="cell-label">Last Name</span><div class="write-area"><?= h($student['father_last_name']) ?></div></td>
      <td style="width:25%"><span class="cell-label">First Name</span><div class="write-area"><?= h($student['father_first_name']) ?></div></td>
      <td style="width:25%"><span class="cell-label">Middle Name</span><div class="write-area"><?= h($student['father_middle_name']) ?></div></td>
      <td style="width:25%"><span class="cell-label">Occupation</span><div class="write-area"><?= h($student['father_occupation']) ?></div></td>
    </tr>
    <tr><td colspan="4" style="font-weight:bold;font-size:9.5px;background:#fafafa;padding:2px 5px">Mother's Maiden Name</td></tr>
    <tr>
      <td><span class="cell-label">Last Name</span><div class="write-area"><?= h($student['mother_last_name']) ?></div></td>
      <td><span class="cell-label">First Name</span><div class="write-area"><?= h($student['mother_first_name']) ?></div></td>
      <td><span class="cell-label">Middle Name</span><div class="write-area"><?= h($student['mother_middle_name']) ?></div></td>
      <td><span class="cell-label">Occupation</span><div class="write-area"><?= h($student['mother_occupation']) ?></div></td>
    </tr>
    <tr><td colspan="4" style="font-weight:bold;font-size:9.5px;background:#fafafa;padding:2px 5px">Legal Guardian's Name</td></tr>
    <tr>
      <td><span class="cell-label">Last Name</span><div class="write-area"><?= h($student['guardian_last_name']) ?></div></td>
      <td><span class="cell-label">First Name</span><div class="write-area"><?= h($student['guardian_first_name']) ?></div></td>
      <td><span class="cell-label">Middle Name</span><div class="write-area"><?= h($student['guardian_middle_name']) ?></div></td>
      <td><span class="cell-label">Occupation</span><div class="write-area"><?= h($student['guardian_occupation']) ?></div></td>
    </tr>
  </table>
</div><!-- /page 1 -->

<!-- PAGE 2 -->
<div class="page page2">

  <!-- PWD -->
  <div style="border:1px solid #000;padding:6px 8px;margin-bottom:0">
    <div style="font-size:10.5px"><strong>a. Is the Learner PWD?</strong>&nbsp;
      <?= cb($isPwd) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$isPwd) ?><span class="cb-label">No</span>
    </div>
    <div style="font-size:9.5px;margin-top:3px">If Yes, specify the type of disability</div>
    <?php
    $dList = array_map('trim', explode(',', strtolower($disabilityDetails)));
    function hasDisability(array $list, string $key): bool {
        foreach ($list as $item) { if (str_contains($item, strtolower($key))) return true; }
        return false;
    }
    ?>
    <div class="disability-grid" style="margin-top:5px">
      <label class="cb-wrap"><?= cb(hasDisability($dList,'attention deficit')) ?><span class="cb-label">Attention Deficit Hyperactivity Disorder</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'intellectual')) ?><span class="cb-label">Intellectual Disability</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'special health')||hasDisability($dList,'chronic')) ?><span class="cb-label">Special Health Problem/Chronic Disease</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'autism')) ?><span class="cb-label">Autism Spectrum Disorder</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'learning disability')) ?><span class="cb-label">Learning Disability</span></label>
      <div><?= cb(hasDisability($dList,'cancer')&&!hasDisability($dList,'non-cancer')) ?><span class="cb-label">Cancer</span>&nbsp;<?= cb(hasDisability($dList,'non-cancer')) ?><span class="cb-label">Non-Cancer</span></div>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'cerebral')) ?><span class="cb-label">Cerebral Palsy</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'multiple')) ?><span class="cb-label">Multiple Disabilities</span></label>
      <div><div style="font-size:9.5px;margin-bottom:2px">Visual Impairment</div>
        <div style="padding-left:14px;display:flex;gap:10px"><?= cb(hasDisability($dList,'blind')&&!hasDisability($dList,'low vision')) ?><span class="cb-label">Blind</span>&nbsp;<?= cb(hasDisability($dList,'low vision')) ?><span class="cb-label">Low Vision</span></div>
      </div>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'emotional')) ?><span class="cb-label">Emotional-Behavior Disorder</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'orthopedic')||hasDisability($dList,'physical handicap')) ?><span class="cb-label">Orthopedic/Physical Handicap</span></label>
      <div></div>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'hearing')) ?><span class="cb-label">Hearing Impairment</span></label>
      <label class="cb-wrap"><?= cb(hasDisability($dList,'speech')||hasDisability($dList,'language')) ?><span class="cb-label">Speech/Language Disorder</span></label>
      <div></div>
    </div>
    <div style="font-size:10.5px;margin-top:7px"><strong>b. Does the Learner have a PWD ID?</strong>&nbsp;
      <?= cb($hasPwdId) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$hasPwdId) ?><span class="cb-label">No</span>
    </div>
  </div>

  <!-- SECTION 3 -->
  <div class="section-head">3. Educational Information</div>
  <table class="ft">
    <tr><td colspan="3">
      <div style="text-align:center;font-weight:bold;font-size:10px;margin-bottom:4px">Last grade level completed</div>
      <table class="grade-table">
        <tr><th style="width:40%">ELEMENTARY</th><th style="width:35%">JUNIOR HIGH SCHOOL</th><th style="width:25%">SENIOR HIGH SCHOOL</th></tr>
        <tr>
          <td>
            <div class="grade-check-row"><?php foreach(['kinder','grade 1','grade 3','grade 5'] as $g): ?><label class="cb-wrap"><?= cb($gradeLevel===$g) ?><span class="cb-label"><?= ucwords($g) ?></span></label><?php endforeach;?></div>
            <div class="grade-check-row"><span style="width:40px;display:inline-block"></span><?php foreach(['grade 2','grade 4','grade 6'] as $g): ?><label class="cb-wrap"><?= cb($gradeLevel===$g) ?><span class="cb-label"><?= ucwords($g) ?></span></label><?php endforeach;?></div>
          </td>
          <td>
            <div class="grade-check-row"><?php foreach(['grade 7','grade 9'] as $g): ?><label class="cb-wrap"><?= cb($gradeLevel===$g) ?><span class="cb-label"><?= ucwords($g) ?></span></label><?php endforeach;?></div>
            <div class="grade-check-row"><?php foreach(['grade 8','grade 10'] as $g): ?><label class="cb-wrap"><?= cb($gradeLevel===$g) ?><span class="cb-label"><?= ucwords($g) ?></span></label><?php endforeach;?></div>
          </td>
          <td><div class="grade-check-row"><?php foreach(['grade 11','grade 12'] as $g): ?><label class="cb-wrap"><?= cb($gradeLevel===$g) ?><span class="cb-label"><?= ucwords($g) ?></span></label><?php endforeach;?></div></td>
        </tr>
      </table>
    </td></tr>
    <tr>
      <td style="width:48%;vertical-align:top">
        <div style="font-size:9.5px;margin-bottom:4px">Why did you not attend/complete schooling <em>(For OSY only)</em></div>
        <?php $reasons=['no school in barangay'=>'No school in barangay','school too far from home'=>'School too far from home','needed to help family'=>'Needed to help family','unable to pay'=>'Unable to pay for miscellaneous and other expenses'];
        foreach($reasons as $key=>$label): ?>
          <label class="cb-wrap mt4" style="display:flex;gap:4px;align-items:center;margin-top:3px"><?= cb(str_contains($reasonList,$key)) ?><span class="cb-label"><?= h($label) ?></span></label>
        <?php endforeach;
        $isOther=$reasonList&&!array_reduce(array_keys($reasons),fn($c,$k)=>$c||str_contains($reasonList,$k),false); ?>
        <div class="row mt4" style="gap:2px;align-items:flex-end"><?= cb($isOther) ?><span class="cb-label">Others:</span><div class="grow"><?= wl($isOther?$student['reason_not_in_school']:'') ?></div></div>
      </td>
      <td style="width:52%;vertical-align:top" colspan="2">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:6px;margin-bottom:4px">
          <span style="font-size:9.5px">Have you attended ALS before?</span>
          <div><?= cb($attendedAls) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$attendedAls) ?><span class="cb-label">No</span></div>
        </div>
        <div style="font-size:9px;margin-bottom:4px">If Yes, check the appropriate program:</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:3px 10px;padding:0 8px">
          <?php $progMap=['basic literacy'=>'Basic Literacy','a&e elementary'=>'A&E Elementary','a&e secondary'=>'A&E Secondary','als-shs'=>'ALS SHS'];
          $sp=strtolower($student['als_program']??'');
          foreach($progMap as $key=>$label): ?>
            <label class="cb-wrap"><?= cb(str_contains($sp,$key)) ?><span class="cb-label"><?= $label ?></span></label>
          <?php endforeach;?>
        </div>
        <div style="display:flex;align-items:center;gap:6px;margin-top:6px">
          <span style="font-size:9.5px">Completed the program?</span>
          <?= cb($completedP) ?><span class="cb-label">Yes</span>&nbsp;<?= cb(!$completedP) ?><span class="cb-label">No</span>
        </div>
        <div style="display:flex;align-items:flex-end;gap:4px;margin-top:4px">
          <span style="font-size:9px;white-space:nowrap">If No, reason:</span>
          <div class="grow"><?= wl(!$completedP?$student['incomplete_reason']:'') ?></div>
        </div>
      </td>
    </tr>
  </table>

  <!-- SECTION 4 -->
  <div class="section-head">4. Accessibility and Availability of CLC</div>
  <table class="ft"><tr><td>
    <div style="font-size:9.5px;margin-bottom:3px">1. Distance to Learning Center:
      <span style="font-size:9px">kms</span> <span style="border-bottom:1px solid #000;display:inline-block;min-width:70px;padding:0 3px"><?= h($student['distance_to_clc_km']) ?></span>
      &nbsp;<span style="font-size:9px">hrs/mins</span> <span style="border-bottom:1px solid #000;display:inline-block;min-width:70px;padding:0 3px"><?= h($student['distance_to_clc_time']) ?></span>
    </div>
    <div style="font-size:9.5px;margin-bottom:3px">2. Mode of transport:</div>
    <div style="display:flex;gap:12px;padding-left:12px;align-items:center;margin-bottom:4px">
      <?= cb($transport==='walking') ?><span class="cb-label">Walking</span>
      <?= cb($transport==='motorcycle') ?><span class="cb-label">Motorcycle</span>
      <?= cb($transport==='bicycle') ?><span class="cb-label">Bicycle</span>
      <?= cb($transport==='others') ?><span style="font-size:9px">Others:</span>
      <span style="border-bottom:1px solid #000;display:inline-block;min-width:80px;padding:0 3px"><?= $transport==='others'?h($student['transport_mode_other']):'' ?></span>
    </div>
    <div style="font-size:9.5px;margin-bottom:3px">3. Availability schedule at Learning Center:</div>
    <table class="sched">
      <tr><?php foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d): ?><th><?= ucfirst($d) ?></th><?php endforeach;?></tr>
      <tr><?php foreach(['monday','tuesday','wednesday','thursday','friday','saturday','sunday'] as $d): ?><td style="font-size:9px"><?= h($schedule[$d]??'') ?></td><?php endforeach;?></tr>
    </table>
  </td></tr></table>

  <!-- SECTION 5 -->
  <div class="section-head">5. Preferred distance learning modality:</div>
  <table class="ft"><tr><td>
    <div style="font-size:9.5px;margin-bottom:4px">Check all that applies:</div>
    <div class="modality-grid">
      <?php $modalities=['prefers_blended'=>'Blended','prefers_homeschooling'=>'Homeschooling','prefers_modular_print'=>'Modular (Print)','prefers_radio_tv'=>'Radio/TV','prefers_edu_tv'=>'Educational TV','prefers_modular_digital'=>'Modular (Digital)','prefers_online'=>'Online'];
      foreach($modalities as $col=>$label): $checked=strtolower($student[$col]??'no')==='yes'; ?>
        <label class="cb-wrap"><?= cb($checked) ?><span class="cb-label"><?= h($label) ?></span></label>
      <?php endforeach;?>
    </div>
  </td></tr></table>

  <div class="cert-text">I hereby certify that the information provided above is true and accurate to the best of my knowledge. I authorize the Department of Education to utilize the details specified above for the purpose of creating and/or updating his/her profile in the Learner Information System.</div>
  <div class="cert-text" style="margin-top:6px">The information herein shall be treated as confidential in compliance with the Data Privacy Act of 2012.</div>

  <div class="sig-row">
    <div class="sig-block"><div class="sig-line"></div><div class="sig-caption">Signature over Printed Name and Date</div></div>
    <div class="sig-block"><div class="sig-line"></div><div class="sig-caption">ALS Teacher/Community ALS Implementor/Learning Facilitator<br>Signature over Printed Name and Date</div></div>
  </div>

</div><!-- /page 2 -->
</body>
</html>