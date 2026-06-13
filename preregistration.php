<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/als/admin-web/includes/db.php';
require_once __DIR__ . '/als/admin-web/includes/functions.php';
require_once __DIR__ . '/als/admin-web/includes/human_verification.php';
require_once __DIR__ . '/IDVerifier.php';

secure_session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ─── CAPTCHA ─────────────────────────────────────────────────────────────────
function generateImageCaptcha()
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $code;
    $_SESSION['captcha_time'] = time();
    return $code;
}

function verifyCaptcha($input)
{
    if (empty($_SESSION['captcha_code'])) return false;
    if (time() - ($_SESSION['captcha_time'] ?? 0) > 300) return false;
    return strtoupper(trim($input)) === strtoupper($_SESSION['captcha_code']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    generateImageCaptcha();
}

// ─── COUNTS CACHE ─────────────────────────────────────────────────────────────
function getCachedCounts($conn)
{
    $cache_key  = 'public_prereg_counts';
    $cache_time = 30;
    if (isset($_SESSION[$cache_key]) && time() - $_SESSION[$cache_key]['time'] < $cache_time) {
        return $_SESSION[$cache_key]['data'];
    }
    $result = $conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN DATE(submitted_at)=CURDATE() THEN 1 ELSE 0 END) as today FROM preregistrations");
    $counts = $result ? $result->fetch_assoc() : ['total' => 0, 'today' => 0];
    $_SESSION[$cache_key] = ['data' => $counts, 'time' => time()];
    return $counts;
}

// ─── SETTINGS ─────────────────────────────────────────────────────────────────
$current_version = '0';
$vr = $conn->query("SELECT setting_value FROM preregistration_settings WHERE setting_key='settings_version'");
if ($vr && $vr->num_rows > 0) $current_version = (string)$vr->fetch_assoc()['setting_value'];

if (isset($_SESSION['admin_id'])) {
    $pr_settings = [];
    $r = $conn->query("SELECT setting_key,setting_value FROM preregistration_settings");
    if ($r) while ($row = $r->fetch_assoc()) $pr_settings[$row['setting_key']] = $row['setting_value'];
} else {
    $force = !isset($_SESSION['pr_settings_cache'])
        || !isset($_SESSION['pr_settings_version'])
        || (string)$_SESSION['pr_settings_version'] !== (string)$current_version
        || time() - $_SESSION['pr_settings_cache_time'] > 30;
    if ($force) {
        $pr_settings = [];
        $r = $conn->query("SELECT setting_key,setting_value FROM preregistration_settings");
        if ($r) while ($row = $r->fetch_assoc()) $pr_settings[$row['setting_key']] = $row['setting_value'];
        $_SESSION['pr_settings_cache']      = $pr_settings;
        $_SESSION['pr_settings_cache_time'] = time();
        $_SESSION['pr_settings_version']    = (string)$current_version;
    } else {
        $pr_settings = $_SESSION['pr_settings_cache'];
    }
}

$preregistration_enabled = ($pr_settings['preregistration_enabled'] ?? 1);
$disabled_message = '';

function getDisabledMessage($icon, $title, $message)
{
    return '<div class="form-card disabled-card" style="text-align:center;padding:60px 40px;">
        <div class="disabled-icon"><i class="fas fa-' . $icon . '"></i></div>
        <h2 class="disabled-title">' . $title . '</h2>
        <p class="disabled-desc">' . $message . '</p>
        <div class="contact-box">
            <p class="contact-title"><i class="fas fa-headset"></i> Need help? Contact us</p>
            <div class="contact-items">
                <span><i class="fas fa-phone"></i> (034) 460-XXXX</span>
                <span><i class="fas fa-envelope"></i> als.lacarlota@deped.gov.ph</span>
                <span><i class="fas fa-map-marker-alt"></i> La Carlota City Division Office</span>
            </div>
        </div>
        <a href="/" class="btn-primary" style="margin-top:35px;display:inline-flex;width:auto;padding:14px 40px;">
            <i class="fas fa-home"></i> Return to Homepage
        </a>
    </div>';
}

if ($preregistration_enabled) {
    $ct = time();
    if (!empty($pr_settings['preregistration_start_date'])) {
        $start = strtotime($pr_settings['preregistration_start_date']);
        if ($ct < $start) {
            $disabled_message = getDisabledMessage('clock', 'Pre-registration Not Yet Open',
                "Pre-registration will open on <strong>" . date('F j, Y g:i A', $start) . "</strong>. Please check back then.");
        }
    }
    if (empty($disabled_message) && !empty($pr_settings['preregistration_end_date'])) {
        $end = strtotime($pr_settings['preregistration_end_date']);
        if ($ct > $end) {
            $disabled_message = getDisabledMessage('calendar-times', 'Pre-registration Has Closed',
                "Pre-registration closed on <strong>" . date('F j, Y g:i A', $end) . "</strong>. Please contact the ALS office for more information.");
        }
    }
    if (empty($disabled_message)) {
        $counts      = getCachedCounts($conn);
        $total_count = $counts['total'];
        $daily_count = $counts['today'];
        $total_limit = intval($pr_settings['preregistration_limit']       ?? 100);
        $daily_limit = intval($pr_settings['preregistration_daily_limit'] ?? 20);
        if ($total_count >= $total_limit)
            $disabled_message = getDisabledMessage('users-slash', 'Pre-registration Full',
                "We're sorry, but the maximum number of pre-registrations ($total_limit) has been reached.");
        elseif ($daily_count >= $daily_limit)
            $disabled_message = getDisabledMessage('calendar-day', 'Daily Limit Reached',
                "Today's pre-registration limit ($daily_limit) has been reached. Please try again tomorrow.");
    }
}
if (!$preregistration_enabled && empty($disabled_message))
    $disabled_message = getDisabledMessage('times-circle', 'Pre-registration Disabled',
        "Pre-registration is currently disabled. Please check back later or contact the ALS office.");

$barangays = [];
if ($preregistration_enabled && empty($disabled_message)) {
    if (!isset($_SESSION['barangays_cache']) || time() - $_SESSION['barangays_cache_time'] > 3600) {
        $br = $conn->query("SELECT * FROM barangays WHERE status='active' ORDER BY name");
        $barangays = $br ? $br->fetch_all(MYSQLI_ASSOC) : [];
        $_SESSION['barangays_cache']      = $barangays;
        $_SESSION['barangays_cache_time'] = time();
    } else {
        $barangays = $_SESSION['barangays_cache'];
    }
}

$grade_levels = ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6',
    'Grade 7','Grade 8','Grade 9','Grade 10','Never attended school'];

$valid_id_types = [
    'Philippine National ID (PhilSys)','Driver\'s License (LTO)','Philippine Passport (DFA)',
    'SSS ID / UMID Card','GSIS ID / UMID Card','PhilHealth ID','Voter\'s ID / COMELEC ID',
    'PRC ID','TIN ID (BIR)','Postal ID (PHLPost)','Barangay ID (Barangay Certification with Photo)',
    'School ID (Any Old School ID)','Senior Citizen ID (OSCA)','PWD ID (Persons with Disability)',
    'OFW ID (DMW/OWWA)','AFP ID (Armed Forces of the Philippines)',
    'PNP ID (Philippine National Police)','NBI Clearance','Police Clearance'
];

$alt_doc_types = [
    'PSA Birth Certificate (Authenticated)','NSO Birth Certificate',
    'Baptismal Certificate (with Photo)','Marriage Certificate (PSA Authenticated)',
    'DSWD Certification (with Photo)','Barangay Certificate of Residency (with Photo)',
    'School Records / Form 137 (with School ID)','Medical Certificate (with Photo)',
    'Certificate of Indigency (with Photo)','Affidavit of Identity (Notarized)',
    'Other Official Supporting Document'
];

$upload_dir = __DIR__ . '/uploads/preregistration/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

// ─── TERMS AGREEMENT LOGIC ────────────────────────────────────────────────────
$show_terms_modal = ($_SERVER['REQUEST_METHOD'] !== 'POST');

$terms_accepted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (
        isset($_POST['accept_terms_privacy']) && $_POST['accept_terms_privacy'] === 'yes' &&
        isset($_POST['accept_terms_program']) && $_POST['accept_terms_program'] === 'yes'
    ) {
        $terms_accepted = true;
    }
}

// ─── FORM SUBMISSION ──────────────────────────────────────────────────────────
$errors        = [];
$step_errors   = [1 => [], 2 => [], 3 => [], 4 => [], 5 => []]; // FIX #3: per-step errors
$success       = false;
$tracking_code = '';
$id_verify_result = null;

if ($preregistration_enabled && empty($disabled_message) && $_SERVER['REQUEST_METHOD'] === 'POST' && $terms_accepted) {

    // 1. CSRF
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))
        $step_errors[5][] = "Invalid request. Please try again.";

    // 2. CAPTCHA
    if (empty($step_errors[5])) {
        $captcha_input = trim($_POST['captcha_answer'] ?? '');
        if ($captcha_input === '') {
            $step_errors[5][] = "Please enter the CAPTCHA code.";
        } elseif (!verifyCaptcha($captcha_input)) {
            $step_errors[5][] = "Incorrect CAPTCHA code. Please try again.";
            generateImageCaptcha();
        }
    }

    // ── Step 1 validation ───────────────────────────────────────────────────
    $s1_required = ['last_name' => 'Last name', 'first_name' => 'First name', 'birthdate' => 'Birth date', 'sex' => 'Sex'];
    foreach ($s1_required as $field => $label)
        if (empty($_POST[$field])) $step_errors[1][] = "$label is required.";

    if (!empty($_POST['lrn']) && !preg_match('/^\d{12}$/', $_POST['lrn']))
        $step_errors[1][] = "LRN must be a 12-digit number if provided.";

    if (!empty($_POST['birthdate'])) {
        $bd = new DateTime($_POST['birthdate']);
        $td = new DateTime();
        $age_check = $td->diff($bd)->y;
        if ($age_check < 5 || $age_check > 100) $step_errors[1][] = "Age must be between 5 and 100 years.";
    }

    // ── Step 2 validation ───────────────────────────────────────────────────
    if (empty($_POST['contact_number'])) $step_errors[2][] = "Contact number is required.";
    if (empty($_POST['email']))          $step_errors[2][] = "Email address is required.";
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL))
        $step_errors[2][] = "Please enter a valid email address.";

    // FIX #1: Barangay + custom city
    if (empty($_POST['current_barangay_id']) && empty($_POST['current_custom_barangay']))
        $step_errors[2][] = "Please select or enter your barangay.";
    if ((!empty($_POST['current_barangay_id']) && $_POST['current_barangay_id'] === 'other') 
        && empty($_POST['current_custom_barangay']))
        $step_errors[2][] = "Please enter your barangay name.";
    if (!empty($_POST['current_barangay_id']) && $_POST['current_barangay_id'] === 'other'
        && empty($_POST['current_city_custom']))
        $step_errors[2][] = "Please enter your city or municipality.";

    // ── Step 3 validation ───────────────────────────────────────────────────
    if (empty($_POST['parent_name']))    $step_errors[3][] = "Parent/Guardian name is required.";
    if (empty($_POST['parent_contact'])) $step_errors[3][] = "Parent/Guardian contact is required.";

    // ── Step 4 validation: Selfie ──────────────────────────────────────────
    $selfie_filename = null;
    $selfie_data = $_POST['selfie_image'] ?? '';
    if (empty($selfie_data)) {
        $step_errors[4][] = "Please capture your photo using the webcam.";
    } else {
        if (preg_match('/^data:image\/(jpeg|png|jpg);base64,/', $selfie_data, $type)) {
            $img_data = base64_decode(substr($selfie_data, strpos($selfie_data, ',') + 1));
            if ($img_data === false) {
                $step_errors[4][] = "Invalid selfie image data. Please retake your photo.";
            } else {
                $selfie_filename = 'selfie_' . uniqid() . '.jpg';
                file_put_contents($upload_dir . $selfie_filename, $img_data);
            }
        } else {
            $step_errors[4][] = "Invalid image format for selfie.";
        }
    }

    // ── Step 4 validation: ID front + back ────────────────────────────────
    $valid_id_filename      = null;
    $valid_id_back_filename = null;
    $valid_id_type   = trim($_POST['valid_id_type'] ?? '');
    $has_no_valid_id = isset($_POST['has_no_valid_id']) && $_POST['has_no_valid_id'] === '1';
    $alt_doc_type    = trim($_POST['alt_doc_type'] ?? '');

    if (empty($step_errors[4])) {
        if ($has_no_valid_id) {
            if (empty($alt_doc_type)) $step_errors[4][] = "Please select the type of alternative document.";
            $id_label      = 'alternative document';
            $valid_id_type = 'ALT: ' . $alt_doc_type;
        } else {
            if (empty($valid_id_type)) $step_errors[4][] = "Please select your valid government ID type.";
            $id_label = 'valid government ID';
        }

        $file_key      = $has_no_valid_id ? 'alt_document' : 'valid_id_front';
        $file_key_back = 'valid_id_back';

        // Front
        if (!isset($_FILES[$file_key]) || $_FILES[$file_key]['error'] === UPLOAD_ERR_NO_FILE) {
            $step_errors[4][] = "Please upload a photo of the FRONT of your $id_label.";
        } elseif ($_FILES[$file_key]['error'] !== UPLOAD_ERR_OK) {
            $step_errors[4][] = "Error uploading front of $id_label.";
        } else {
            $allowed_types = ['image/jpeg','image/png','image/jpg','application/pdf'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $_FILES[$file_key]['tmp_name']);
            finfo_close($finfo);
            if (!in_array($mime, $allowed_types)) {
                $step_errors[4][] = "Front $id_label must be JPG, PNG, or PDF.";
            } elseif ($_FILES[$file_key]['size'] > 5 * 1024 * 1024) {
                $step_errors[4][] = "Front $id_label must not exceed 5 MB.";
            } else {
                $ext    = ($mime === 'application/pdf') ? 'pdf' : 'jpg';
                $prefix = $has_no_valid_id ? 'alt_' : 'id_front_';
                $temp   = $_FILES[$file_key]['tmp_name'];
                $id_verify_result = IDVerifier::verify($temp, $mime, $has_no_valid_id ? $alt_doc_type : $valid_id_type);
                if (!$id_verify_result['passed']) {
                    $msg = "Front ID verification failed (score: " . $id_verify_result['score'] . "/100).";
                    if (!empty($id_verify_result['reasons'])) $msg .= " " . implode(' ', $id_verify_result['reasons']);
                    $step_errors[4][] = $msg;
                } else {
                    $valid_id_filename = $prefix . uniqid() . '.' . $ext;
                    move_uploaded_file($temp, $upload_dir . $valid_id_filename);
                    if (!empty($id_verify_result['warnings'])) $_SESSION['id_verify_warnings'] = $id_verify_result['warnings'];
                }
            }
        }

        // Back (only for valid IDs, not alt docs)
        if (!$has_no_valid_id) {
            if (!isset($_FILES[$file_key_back]) || $_FILES[$file_key_back]['error'] === UPLOAD_ERR_NO_FILE) {
                $step_errors[4][] = "Please upload a photo of the BACK of your government ID.";
            } elseif ($_FILES[$file_key_back]['error'] !== UPLOAD_ERR_OK) {
                $step_errors[4][] = "Error uploading back of ID.";
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime_back = finfo_file($finfo, $_FILES[$file_key_back]['tmp_name']);
                finfo_close($finfo);
                $allowed_types = ['image/jpeg','image/png','image/jpg','application/pdf'];
                if (!in_array($mime_back, $allowed_types)) {
                    $step_errors[4][] = "Back of ID must be JPG, PNG, or PDF.";
                } elseif ($_FILES[$file_key_back]['size'] > 5 * 1024 * 1024) {
                    $step_errors[4][] = "Back of ID must not exceed 5 MB.";
                } else {
                    $ext_back = ($mime_back === 'application/pdf') ? 'pdf' : 'jpg';
                    $back_verify = IDVerifier::verify($_FILES[$file_key_back]['tmp_name'], $mime_back, $valid_id_type . ' (back)');
                    if (!$back_verify['passed']) {
                        $msg = "Back ID verification failed (score: " . $back_verify['score'] . "/100).";
                        if (!empty($back_verify['reasons'])) $msg .= " " . implode(' ', $back_verify['reasons']);
                        $step_errors[4][] = $msg;
                    } else {
                        $valid_id_back_filename = 'id_back_' . uniqid() . '.' . $ext_back;
                        move_uploaded_file($_FILES[$file_key_back]['tmp_name'], $upload_dir . $valid_id_back_filename);
                    }
                }
            }
        }
    }

    // Merge all step errors into flat $errors for compatibility
    $errors = array_merge(
        $step_errors[1], $step_errors[2], $step_errors[3],
        $step_errors[4], $step_errors[5]
    );

    // ── Save if no errors ───────────────────────────────────────────────────
    if (empty($errors)) {
        $tracking_code = 'PR-' . strtoupper(substr(uniqid(), -6)) . '-' . date('Ymd');
        $check = $conn->query("SELECT tracking_code FROM preregistrations WHERE tracking_code='$tracking_code'");
        while ($check->num_rows > 0) {
            $tracking_code = 'PR-' . strtoupper(substr(uniqid(), -6)) . '-' . date('Ymd');
            $check = $conn->query("SELECT tracking_code FROM preregistrations WHERE tracking_code='$tracking_code'");
        }

        $access_code = bin2hex(random_bytes(16));
        $bd  = new DateTime($_POST['birthdate']);
        $td  = new DateTime();
        $age = $td->diff($bd)->y;

        $verification_code = $verification_expires = null;
        if ($pr_settings['preregistration_require_email_verification'] ?? 1) {
            $verification_code    = bin2hex(random_bytes(32));
            $verification_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
        }

        // FIX #1: handle custom barangay + city
        $is_other_brgy = (isset($_POST['current_barangay_id']) && $_POST['current_barangay_id'] === 'other');
        $barangay_id   = $is_other_brgy ? null : (intval($_POST['current_barangay_id'] ?? 0) ?: null);
        $custom_brgy   = $is_other_brgy ? trim($_POST['current_custom_barangay'] ?? '') : null;
        $city_val      = $is_other_brgy
            ? trim($_POST['current_city_custom'] ?? 'La Carlota City')
            : ($_POST['current_city'] ?? 'La Carlota City');

        // Build ID metadata with front+back
        $id_meta = json_encode([
            'front' => $valid_id_filename,
            'back'  => $valid_id_back_filename
        ]);

        $data = [
            'tracking_code'             => $tracking_code,
            'access_code'               => $access_code,
            'status'                    => ($pr_settings['preregistration_auto_approve'] ?? 0) ? 'approved' : 'pending',
            'lrn'                       => !empty($_POST['lrn']) ? $_POST['lrn'] : null,
            'last_name'                 => trim($_POST['last_name']),
            'first_name'                => trim($_POST['first_name']),
            'middle_name'               => !empty($_POST['middle_name']) ? trim($_POST['middle_name']) : null,
            'extension_name'            => !empty($_POST['extension_name']) ? trim($_POST['extension_name']) : null,
            'birthdate'                 => $_POST['birthdate'],
            'age'                       => $age,
            'sex'                       => $_POST['sex'],
            'contact_number'            => $_POST['contact_number'],
            'email'                     => $_POST['email'],
            'current_barangay_id'       => $barangay_id,
            'current_custom_barangay'   => $custom_brgy,
            'current_city'              => $city_val,
            'parent_name'               => trim($_POST['parent_name']),
            'parent_contact'            => $_POST['parent_contact'],
            'last_grade_level'          => $_POST['last_grade_level'] ?? null,
            'verification_code'         => $verification_code,
            'verification_expires'      => $verification_expires,
            'human_verification_passed' => 1,
            'selfie_image'              => $selfie_filename,
            'valid_id_image'            => $valid_id_filename,  // front
            'valid_id_back_image'       => $valid_id_back_filename, // back (new column)
            'valid_id_meta'             => $id_meta,
            'valid_id_type'             => $valid_id_type,
            'user_agent'                => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];

        $fields       = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $types        = str_repeat('s', count($data));

        $stmt = $conn->prepare("INSERT INTO preregistrations ($fields) VALUES ($placeholders)");
        $params = array_values($data);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $preregistration_id = $stmt->insert_id;
            $success = true;
            if ($pr_settings['preregistration_notify_admin'] ?? 1)
                $conn->query("INSERT INTO preregistration_notifications (preregistration_id, notification_type) VALUES ($preregistration_id, 'new')");
            if (!empty($_POST['email'])) {
                $subject = "ALS Pre-registration Received - Tracking Code: $tracking_code";
                $message = "Dear " . $_POST['first_name'] . ",\n\nThank you for pre-registering with ALS.\n\nTracking Code: $tracking_code\n\n";
                if ($verification_code) {
                    $vl = "https://" . $_SERVER['HTTP_HOST'] . "/verified?code=$verification_code&tracking=$tracking_code";
                    $message .= "Verify your email: $vl\n(expires in 24 hours)\n\n";
                }
                $message .= "Your pre-registration is pending review.\n\nThank you,\nALS La Carlota City Division";
                mail($_POST['email'], $subject, $message, "From: noreply@als-system.online");
            }
            unset($_SESSION['public_prereg_counts'], $_SESSION['captcha_code']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        } else {
            $step_errors[5][] = "Database error: " . $stmt->error;
            $errors[] = "Database error: " . $stmt->error;
        }
        $stmt->close();
    }

    if (!empty($errors)) {
        generateImageCaptcha();
        if ($selfie_filename && file_exists($upload_dir . $selfie_filename)) unlink($upload_dir . $selfie_filename);
        if ($valid_id_filename && file_exists($upload_dir . $valid_id_filename)) unlink($upload_dir . $valid_id_filename);
        if ($valid_id_back_filename && file_exists($upload_dir . $valid_id_back_filename)) unlink($upload_dir . $valid_id_back_filename);

        $_SESSION['form_errors']      = $errors;
        $_SESSION['form_step_errors'] = $step_errors;
        $_SESSION['form_data']        = $_POST;
        if (!empty($_FILES['valid_id_front']['name']))  $_SESSION['uploaded_id_name']  = $_FILES['valid_id_front']['name'];
        if (!empty($_FILES['valid_id_back']['name']))   $_SESSION['uploaded_id_back_name'] = $_FILES['valid_id_back']['name'];
        if (!empty($_FILES['alt_document']['name']))    $_SESSION['uploaded_alt_name'] = $_FILES['alt_document']['name'];
        if ($id_verify_result !== null)                 $_SESSION['id_verify_result']  = $id_verify_result;
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$terms_accepted && empty($success)) {
    $errors[] = "You must accept both the Data Privacy Agreement and ALS Program Terms to proceed.";
    $show_terms_modal = true;
}

// Retrieve stored form data
if (isset($_SESSION['form_errors'])) {
    $errors      = $_SESSION['form_errors'];
    $step_errors = $_SESSION['form_step_errors'] ?? [1=>[], 2=>[], 3=>[], 4=>[], 5=>[]];
    $stored_post = $_SESSION['form_data'] ?? [];
    $id_verify_result = $_SESSION['id_verify_result'] ?? null;
    unset($_SESSION['form_errors'], $_SESSION['form_step_errors'], $_SESSION['form_data'], $_SESSION['id_verify_result']);
    $show_terms_modal = true;
} else {
    $stored_post = $_POST;
}

$id_verify_warnings = [];
if (isset($_SESSION['id_verify_warnings'])) {
    $id_verify_warnings = $_SESSION['id_verify_warnings'];
    unset($_SESSION['id_verify_warnings']);
}

// FIX #3: Determine error step (first step with errors)
$error_step = 1;
for ($s = 1; $s <= 5; $s++) {
    if (!empty($step_errors[$s])) { $error_step = $s; break; }
}

// ─── CAPTCHA IMAGE ENDPOINT ───────────────────────────────────────────────────
if (isset($_GET['captcha_img'])) {
    $code = $_SESSION['captcha_code'] ?? generateImageCaptcha();
    header('Content-Type: image/svg+xml');
    header('Cache-Control: no-store, no-cache');
    $colors = ['#1e4d9b','#1a3a6e','#0d6efd','#0a58ca'];
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="60" style="background:#f0f4ff;border-radius:8px;">';
    for ($i = 0; $i < 6; $i++) {
        $x1=rand(0,200); $y1=rand(0,60); $x2=rand(0,200); $y2=rand(0,60);
        $svg .= '<line x1="'.$x1.'" y1="'.$y1.'" x2="'.$x2.'" y2="'.$y2.'" stroke="#a0b4d0" stroke-width="1.5" opacity="0.5"/>';
    }
    for ($i = 0; $i < 20; $i++) {
        $cx=rand(5,195); $cy=rand(5,55);
        $svg .= '<circle cx="'.$cx.'" cy="'.$cy.'" r="2" fill="#b0c4de" opacity="0.4"/>';
    }
    $chars = str_split($code); $x = 18;
    foreach ($chars as $char) {
        $rotate=rand(-18,18); $y=rand(36,46); $size=rand(22,30); $color=$colors[array_rand($colors)];
        $svg .= '<text x="'.$x.'" y="'.$y.'" font-family="Arial Black,sans-serif" font-size="'.$size.'" font-weight="900" fill="'.$color.'" transform="rotate('.$rotate.','.$x.','.$y.')" letter-spacing="2">'.$char.'</text>';
        $x += 30;
    }
    $svg .= '</svg>';
    echo $svg;
    exit;
}
if (isset($_GET['captcha_refresh'])) {
    generateImageCaptcha();
    http_response_code(200);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALS Pre-registration - La Carlota City Division</title>
    <link rel="icon" type="image/png" href="/als/logo/als-logo-removebg-preview.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
        :root{
            --blue-950:#0a1628;--blue-900:#0d2144;--blue-800:#1a3a6e;--blue-700:#1e4d9b;
            --blue-600:#1d5cbf;--blue-500:#2563eb;--blue-400:#3b82f6;--blue-300:#60a5fa;
            --blue-200:#bfdbfe;--blue-100:#dbeafe;--blue-50:#eff6ff;
            --accent:#38bdf8;--gold:#f59e0b;--success:#10b981;--error:#ef4444;--warning:#f59e0b;
            --text:#0f172a;--text-mid:#334155;--text-light:#64748b;--text-muted:#94a3b8;
            --border:#e2e8f0;--border-focus:#3b82f6;--card-bg:#ffffff;--input-bg:#f8fafc;
            --radius-sm:8px;--radius:12px;--radius-lg:18px;--radius-xl:24px;
            --shadow-sm:0 1px 3px rgba(0,0,0,.08);--shadow:0 4px 16px rgba(15,23,42,.10);
            --shadow-lg:0 12px 40px rgba(15,23,42,.14);
        }
        html{scroll-behavior:smooth}
        body{font-family:'DM Sans',sans-serif;background:var(--blue-950);min-height:100vh;overflow-x:hidden}

        /* BG */
        .bg-scene{position:fixed;inset:0;z-index:0;pointer-events:none;overflow:hidden}
        .bg-scene::before{content:'';position:absolute;inset:0;background:
            radial-gradient(ellipse 80% 60% at 10% 0%,rgba(37,99,235,.35) 0%,transparent 60%),
            radial-gradient(ellipse 60% 50% at 90% 100%,rgba(14,165,233,.25) 0%,transparent 55%),
            radial-gradient(ellipse 50% 40% at 50% 50%,rgba(10,22,40,.8) 0%,transparent 100%)}
        .bg-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px);background-size:40px 40px}
        .bg-orb{position:absolute;border-radius:50%;filter:blur(80px);animation:drift 18s ease-in-out infinite}
        .bg-orb-1{width:500px;height:500px;background:rgba(37,99,235,.2);top:-100px;left:-100px}
        .bg-orb-2{width:350px;height:350px;background:rgba(14,165,233,.18);bottom:-80px;right:-80px;animation-delay:-6s}
        .bg-orb-3{width:250px;height:250px;background:rgba(56,189,248,.12);top:40%;left:60%;animation-delay:-12s}
        @keyframes drift{0%,100%{transform:translate(0,0) scale(1)}33%{transform:translate(30px,-20px) scale(1.05)}66%{transform:translate(-20px,30px) scale(.97)}}

        .page-wrapper{position:relative;z-index:1;min-height:100vh;padding:0 0 60px}

        /* HERO */
        .hero{padding:40px 20px 32px;text-align:center}
        .logo-wrap{display:inline-flex;align-items:center;justify-content:center;gap:16px;background:rgba(255,255,255,.07);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.12);border-radius:60px;padding:10px 24px 10px 12px;margin-bottom:24px;animation:fadeDown .6s ease both}
        .logo-img{width:52px;height:52px;object-fit:contain;filter:drop-shadow(0 2px 8px rgba(56,189,248,.4))}
        .logo-text .dept{font-family:'Sora',sans-serif;font-size:.65rem;font-weight:600;color:var(--accent);letter-spacing:.12em;text-transform:uppercase}
        .logo-text .div-name{font-family:'Sora',sans-serif;font-size:.85rem;font-weight:700;color:white}
        .hero-title{font-family:'Sora',sans-serif;font-size:clamp(1.8rem,4vw,2.8rem);font-weight:800;color:white;line-height:1.15;margin-bottom:10px;animation:fadeDown .7s .1s ease both}
        .hero-title span{background:linear-gradient(135deg,var(--accent),var(--blue-300));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
        .hero-subtitle{font-size:1rem;color:rgba(255,255,255,.6);max-width:480px;margin:0 auto;line-height:1.6;animation:fadeDown .7s .2s ease both}
        .container{max-width:900px;margin:0 auto;padding:0 20px}

        /* STEPPER */
        .stepper-wrap{background:rgba(255,255,255,.05);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius-xl);padding:28px 32px 24px;margin-bottom:20px;animation:fadeUp .6s .3s ease both}
        .stepper{display:flex;align-items:flex-start;justify-content:space-between;position:relative}
        .stepper-line{position:absolute;top:22px;left:calc(12.5% + 11px);right:calc(12.5% + 11px);height:2px;background:rgba(255,255,255,.15);z-index:0}
        .stepper-line-fill{position:absolute;top:0;left:0;height:100%;background:linear-gradient(90deg,var(--blue-400),var(--accent));border-radius:2px;transition:width .5s cubic-bezier(.4,0,.2,1)}
        .step-item{display:flex;flex-direction:column;align-items:center;gap:10px;position:relative;z-index:1;flex:1;cursor:default}
        .step-circle{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-size:.85rem;font-weight:800;border:2px solid rgba(255,255,255,.2);background:rgba(255,255,255,.07);color:rgba(255,255,255,.4);transition:all .4s cubic-bezier(.4,0,.2,1);position:relative}
        .step-item.active .step-circle{background:var(--blue-500);border-color:var(--blue-400);color:white;box-shadow:0 0 0 6px rgba(37,99,235,.25)}
        .step-item.done .step-circle{background:var(--success);border-color:#34d399;color:white}
        .step-item.error .step-circle{background:var(--error);border-color:#fca5a5;color:white;animation:pulse-err 1.5s ease infinite}
        @keyframes pulse-err{0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.4)}50%{box-shadow:0 0 0 8px rgba(239,68,68,0)}}
        .step-item.done .step-circle::after{content:'✓';font-size:.9rem;font-weight:900}
        .step-item.error .step-circle::after{content:'!';font-size:1rem;font-weight:900}
        .step-item.done .step-circle span,.step-item.error .step-circle span{display:none}
        .step-label{font-family:'Sora',sans-serif;font-size:.65rem;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.08em;text-align:center;transition:color .3s;max-width:80px;line-height:1.3}
        .step-item.active .step-label{color:var(--accent)}
        .step-item.done .step-label{color:rgba(255,255,255,.6)}
        .step-item.error .step-label{color:#fca5a5}

        /* FORM CARD */
        .form-card{background:var(--card-bg);border-radius:var(--radius-xl);box-shadow:var(--shadow-lg);overflow:hidden;margin-bottom:20px;animation:fadeUp .6s .35s ease both}
        .section-header{display:flex;align-items:center;gap:12px;padding:22px 32px;background:linear-gradient(135deg,var(--blue-600),var(--blue-800));position:relative;overflow:hidden}
        .section-header::after{content:'';position:absolute;right:-30px;top:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.05)}
        .section-header-icon{width:44px;height:44px;background:rgba(255,255,255,.15);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:white;flex-shrink:0}
        .section-header-text h2{font-family:'Sora',sans-serif;font-size:1.1rem;font-weight:700;color:white}
        .section-header-text p{font-size:.8rem;color:rgba(255,255,255,.65);margin-top:2px}
        .form-body{padding:36px 32px}
        .step-panel{display:none}
        .step-panel.active{display:block;animation:panelIn .35s ease both}
        @keyframes panelIn{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:translateX(0)}}

        /* ALERTS */
        .alert{display:flex;gap:14px;padding:18px 20px;border-radius:var(--radius);margin-bottom:28px;font-size:.9rem;line-height:1.5}
        .alert-icon{flex-shrink:0;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem}
        .alert-success{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46}
        .alert-success .alert-icon{background:#d1fae5;color:var(--success)}
        .alert-error{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
        .alert-error .alert-icon{background:#fee2e2;color:var(--error)}
        .alert-warning{background:#fffbeb;border:1px solid #fde68a;color:#78350f}
        .alert-warning .alert-icon{background:#fef3c7;color:var(--warning)}
        .alert ul{margin-top:6px;padding-left:16px}
        .alert ul li{margin-bottom:3px}

        /* FORM ELEMENTS */
        .form-section{margin-bottom:32px}
        .form-section-title{display:flex;align-items:center;gap:10px;font-family:'Sora',sans-serif;font-size:.78rem;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.1em;margin-bottom:18px;padding-bottom:12px;border-bottom:2px solid var(--blue-100)}
        .form-section-title i{width:28px;height:28px;background:var(--blue-50);border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.85rem;color:var(--blue-500)}
        .form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:20px}
        .form-group{position:relative}
        .form-group.full{grid-column:span 2}
        label.field-label{display:block;font-size:.8rem;font-weight:600;color:var(--text-mid);margin-bottom:7px}
        label.field-label .req{color:var(--error);margin-left:3px}
        label.field-label .opt{font-weight:400;color:var(--text-muted);font-size:.75rem;margin-left:4px}

        /* Modern input with validation states */
        .field-input,.field-select{width:100%;padding:12px 16px;background:var(--input-bg);border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.93rem;color:var(--text);transition:border-color .2s,box-shadow .2s,background .2s;appearance:none}
        .field-input:focus,.field-select:focus{outline:none;background:#fff;border-color:var(--blue-400);box-shadow:0 0 0 3px rgba(59,130,246,.12)}
        .field-input::placeholder{color:var(--text-muted)}
        .field-input[readonly]{background:#f1f5f9;color:var(--text-light);cursor:not-allowed}
        .field-input.is-valid,.field-select.is-valid{border-color:var(--success);background:#f0fdf4}
        .field-input.is-error,.field-select.is-error{border-color:var(--error);background:#fef2f2}
        .field-error-msg{font-size:.74rem;color:var(--error);margin-top:4px;display:none;align-items:center;gap:4px}
        .field-error-msg.show{display:flex}
        .field-success-check{position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--success);font-size:.9rem;display:none;pointer-events:none;margin-top:11px}
        .field-success-check.show{display:block}

        .select-wrapper{position:relative}
        .select-wrapper::after{content:'\f107';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);pointer-events:none;font-size:.9rem}
        .field-select{padding-right:40px;cursor:pointer}
        .field-hint{font-size:.75rem;color:var(--text-muted);margin-top:5px}
        .radio-group{display:flex;gap:14px;padding:4px 0}
        .radio-card{flex:1;position:relative}
        .radio-card input[type=radio]{position:absolute;opacity:0;width:0;height:0}
        .radio-card label{display:flex;align-items:center;justify-content:center;gap:8px;padding:11px 16px;background:var(--input-bg);border:1.5px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;font-size:.9rem;font-weight:500;color:var(--text-mid);transition:all .2s}
        .radio-card label i{color:var(--text-muted)}
        .radio-card input:checked+label{background:var(--blue-50);border-color:var(--blue-500);color:var(--blue-700)}
        .radio-card input:checked+label i{color:var(--blue-500)}

        /* FIX #1: Barangay custom fields */
        .custom-location-fields{display:none;grid-column:span 2;background:var(--blue-50);border:1.5px dashed var(--blue-300);border-radius:var(--radius);padding:18px 20px;margin-top:4px;animation:fadeIn .3s ease}
        .custom-location-fields.show{display:block}
        @keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
        .custom-location-inner{display:grid;grid-template-columns:1fr 1fr;gap:16px}
        .custom-location-notice{display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--blue-700);margin-bottom:14px;background:var(--blue-100);border-radius:6px;padding:8px 12px}

        /* WEBCAM */
        .webcam-card{background:linear-gradient(135deg,#f0f4ff,#e8f0fe);border:1.5px solid var(--blue-200);border-radius:var(--radius);padding:24px;text-align:center}
        .webcam-header{display:flex;align-items:center;gap:10px;margin-bottom:18px;justify-content:center}
        .webcam-badge{background:var(--blue-500);color:white;width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem}
        .webcam-header-text{font-family:'Sora',sans-serif;font-size:.9rem;font-weight:700;color:var(--blue-800);text-align:left}
        .webcam-header-text span{display:block;font-family:'DM Sans',sans-serif;font-weight:400;font-size:.78rem;color:var(--text-light)}
        #webcam-preview-container{position:relative;display:inline-block;border-radius:var(--radius);overflow:hidden;border:2px solid var(--blue-300);background:#1a1a2e;width:320px;max-width:100%;aspect-ratio:4/3;margin:0 auto 16px}
        #webcam-video,#webcam-canvas,#selfie-preview{width:100%;height:100%;object-fit:cover;border-radius:var(--radius-sm)}
        #webcam-canvas,#selfie-preview{display:none}
        .webcam-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:rgba(255,255,255,.7);font-size:.82rem;background:rgba(0,0,0,.5);pointer-events:none}
        .webcam-overlay i{font-size:2.5rem;opacity:.6}
        .webcam-buttons{display:flex;gap:12px;justify-content:center;flex-wrap:wrap}
        .btn-webcam{display:inline-flex;align-items:center;gap:8px;padding:10px 22px;border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:.85rem;font-weight:700;cursor:pointer;border:none;transition:all .2s}
        .btn-webcam-start{background:var(--blue-500);color:white;box-shadow:0 4px 14px rgba(37,99,235,.3)}
        .btn-webcam-start:hover{background:var(--blue-600);transform:translateY(-1px)}
        .btn-webcam-capture{background:var(--success);color:white;box-shadow:0 4px 14px rgba(16,185,129,.3)}
        .btn-webcam-capture:hover{background:#059669}
        .btn-webcam-retake{background:#f1f5f9;color:var(--text-mid);border:1.5px solid var(--border)}
        .btn-webcam-retake:hover{background:var(--border)}
        .webcam-status{margin-top:12px;font-size:.8rem;color:var(--text-light);display:flex;align-items:center;justify-content:center;gap:6px}
        .webcam-status.ok{color:var(--success)}
        .webcam-status.err{color:var(--error)}

        /* FIX #2: Front + Back ID upload */
        .id-dual-upload{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:14px}
        .id-side-card{background:#f8fafc;border:1.5px solid var(--border);border-radius:var(--radius);padding:16px;position:relative}
        .id-side-label{display:flex;align-items:center;gap:8px;font-family:'Sora',sans-serif;font-size:.78rem;font-weight:800;color:var(--blue-700);margin-bottom:12px;text-transform:uppercase;letter-spacing:.07em}
        .id-side-label .side-badge{background:var(--blue-600);color:white;border-radius:4px;padding:2px 8px;font-size:.68rem}
        .id-side-label .side-badge.back{background:#6d28d9}
        .file-drop-zone{border:2px dashed var(--blue-300);border-radius:var(--radius);padding:22px 14px;text-align:center;background:white;cursor:pointer;transition:all .2s;position:relative}
        .file-drop-zone:hover,.file-drop-zone.drag-over{background:var(--blue-50);border-color:var(--blue-500)}
        .file-drop-zone.has-file{border-color:var(--success);border-style:solid;background:#f0fdf4}
        .drop-icon{font-size:1.6rem;color:var(--blue-400);margin-bottom:8px}
        .drop-text{font-size:.83rem;color:var(--text-mid)}
        .drop-text strong{color:var(--blue-600)}
        .drop-hint{font-size:.7rem;color:var(--text-muted);margin-top:3px;line-height:1.4}
        .id-side-preview{display:none;position:relative;margin-top:10px;border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid var(--success)}
        .id-side-preview img{max-width:100%;max-height:140px;object-fit:contain;display:block;margin:0 auto;padding:4px;background:#fff}
        .id-side-preview .pdf-info{padding:12px;text-align:center;color:var(--text-mid);font-size:.8rem}
        .id-side-preview .pdf-info i{font-size:1.5rem;color:var(--error);display:block;margin-bottom:4px}
        .id-remove-btn{position:absolute;top:6px;right:6px;background:rgba(239,68,68,.9);color:white;border:none;border-radius:50%;width:24px;height:24px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.7rem;transition:background .2s}
        .id-remove-btn:hover{background:var(--error)}
        .id-verify-status{margin-top:10px;padding:10px 12px;border-radius:var(--radius-sm);font-size:.78rem;display:none}
        .id-verify-status.pass{background:#ecfdf5;border:1px solid #6ee7b7;color:#065f46;display:flex;gap:8px;align-items:flex-start}
        .id-verify-status.fail{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;display:flex;gap:8px;align-items:flex-start}
        .id-verify-status.warn{background:#fffbeb;border:1px solid #fde68a;color:#78350f;display:flex;gap:8px;align-items:flex-start}
        .id-verify-status.checking{background:var(--blue-50);border:1px solid var(--blue-200);color:var(--blue-700);display:flex;gap:8px;align-items:center}

        /* Score bar */
        .score-bar-wrap{margin-top:6px;background:rgba(0,0,0,.08);border-radius:4px;height:5px;overflow:hidden;max-width:120px}
        .score-bar{height:100%;border-radius:4px;transition:width .5s ease}
        .score-bar.high{background:var(--success)}.score-bar.mid{background:var(--warning)}.score-bar.low{background:var(--error)}

        /* Blocked banner */
        @keyframes shake{0%,100%{transform:translateX(0)}20%{transform:translateX(-8px)}40%{transform:translateX(8px)}60%{transform:translateX(-6px)}80%{transform:translateX(6px)}}
        .upload-blocked-banner{display:flex;gap:14px;align-items:flex-start;padding:14px 16px;border-radius:var(--radius);background:#fef2f2;border:2px solid #fca5a5;animation:shake .45s ease both;margin-top:10px}
        .upload-blocked-banner .ban-icon{width:34px;height:34px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;color:var(--error);flex-shrink:0}
        .upload-blocked-banner .ban-title{font-family:'Sora',sans-serif;font-size:.85rem;font-weight:800;color:#991b1b;margin-bottom:4px}
        .upload-blocked-banner .ban-body{font-size:.8rem;color:#b91c1c;line-height:1.5}
        .upload-blocked-banner .ban-retry{display:inline-flex;align-items:center;gap:6px;margin-top:10px;padding:7px 14px;background:var(--error);color:white;border:none;border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:.78rem;font-weight:700;cursor:pointer;transition:background .2s}
        .upload-blocked-banner .ban-retry:hover{background:#dc2626}

        /* ID Tips */
        .id-section-tabs{display:flex;gap:0;margin-bottom:20px;border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid var(--blue-200)}
        .id-tab-btn{flex:1;padding:12px 16px;background:var(--blue-50);border:none;font-family:'Sora',sans-serif;font-size:.83rem;font-weight:700;color:var(--blue-600);cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:8px}
        .id-tab-btn.active{background:var(--blue-600);color:white}
        .id-tab-btn:first-child{border-right:1.5px solid var(--blue-200)}
        .id-tab-pane{display:none}
        .id-tab-pane.active{display:block}
        .upload-card{background:linear-gradient(135deg,#f8fafc,#f0f4ff);border:1.5px solid var(--border);border-radius:var(--radius);padding:24px}
        .upload-tips{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius-sm);padding:12px 16px;margin-top:12px}
        .upload-tips-title{font-size:.75rem;font-weight:700;color:#15803d;margin-bottom:8px;display:flex;align-items:center;gap:6px}
        .upload-tips-list{list-style:none;padding:0}
        .upload-tips-list li{font-size:.77rem;color:#166534;padding:3px 0;display:flex;align-items:flex-start;gap:7px}
        .upload-tips-list li::before{content:'✓';color:#16a34a;font-weight:700;flex-shrink:0}
        .accepted-ids{margin-top:14px;background:var(--blue-50);border:1px solid var(--blue-100);border-radius:var(--radius-sm);padding:12px 16px}
        .accepted-ids-title{font-size:.75rem;font-weight:700;color:var(--blue-700);text-transform:uppercase;letter-spacing:.08em;margin-bottom:8px}
        .accepted-ids-list{display:flex;flex-wrap:wrap;gap:6px}
        .id-tag{background:white;border:1px solid var(--blue-200);border-radius:20px;padding:3px 10px;font-size:.72rem;color:var(--blue-700);font-weight:500}
        .no-id-notice{background:#fffbeb;border:1.5px solid #fde68a;border-radius:var(--radius);padding:16px 18px;margin-bottom:16px;display:flex;gap:12px;align-items:flex-start}
        .no-id-notice i{color:#d97706;margin-top:2px;flex-shrink:0}
        .no-id-notice-text{font-size:.85rem;color:#92400e;line-height:1.55}

        /* FIX #4: Modern step-error notification */
        .step-error-badge{display:flex;align-items:center;gap:10px;background:#fef2f2;border:1.5px solid #fca5a5;border-radius:var(--radius);padding:12px 16px;margin-bottom:16px;font-size:.83rem;color:#991b1b;cursor:pointer;transition:all .2s}
        .step-error-badge:hover{background:#fee2e2}
        .step-error-badge .seb-icon{width:28px;height:28px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;color:var(--error);flex-shrink:0}
        .step-error-badge .seb-text strong{display:block;font-weight:700;margin-bottom:1px}
        .step-error-badge .seb-arrow{margin-left:auto;color:#fca5a5;font-size:.9rem}

        /* CAPTCHA */
        .captcha-card{background:linear-gradient(135deg,var(--blue-50),#f0f9ff);border:1.5px solid var(--blue-200);border-radius:var(--radius);padding:24px}
        .captcha-header{display:flex;align-items:center;gap:10px;margin-bottom:18px}
        .captcha-badge{background:var(--blue-500);color:white;width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem}
        .captcha-header-text{font-family:'Sora',sans-serif;font-size:.85rem;font-weight:700;color:var(--blue-800)}
        .captcha-header-text span{display:block;font-family:'DM Sans',sans-serif;font-weight:400;font-size:.78rem;color:var(--text-light)}
        .captcha-image-row{display:flex;align-items:center;gap:12px;margin-bottom:14px}
        .captcha-img-wrapper{background:white;border:1.5px solid var(--blue-200);border-radius:var(--radius-sm);padding:6px;flex-shrink:0;line-height:0}
        .captcha-img-wrapper img{border-radius:4px}
        .btn-refresh-captcha{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;background:var(--blue-50);border:1.5px solid var(--blue-200);border-radius:var(--radius-sm);font-size:.8rem;font-weight:600;color:var(--blue-600);cursor:pointer;transition:all .2s;white-space:nowrap}
        .btn-refresh-captcha:hover{background:var(--blue-100);border-color:var(--blue-400)}
        .btn-refresh-captcha i{transition:transform .4s}
        .btn-refresh-captcha:hover i{transform:rotate(180deg)}

        /* Agreement accordion */
        .agreement-section{margin-bottom:28px}
        .agreement-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;background:var(--blue-50);border:1.5px solid var(--blue-200);border-radius:var(--radius);cursor:pointer;font-family:'Sora',sans-serif;font-size:.88rem;font-weight:700;color:var(--blue-700);transition:all .2s;text-align:left}
        .agreement-toggle:hover{background:var(--blue-100);border-color:var(--blue-400)}
        .agreement-toggle.open{border-radius:var(--radius) var(--radius) 0 0;border-bottom-color:transparent;background:var(--blue-100)}
        .agreement-toggle-left{display:flex;align-items:center;gap:10px}
        .agreement-toggle-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
        .agreement-toggle-icon.privacy{background:var(--blue-600);color:white}
        .agreement-toggle-icon.program{background:#059669;color:white}
        .agreement-chevron{transition:transform .3s;color:var(--blue-400);font-size:.85rem}
        .agreement-toggle.open .agreement-chevron{transform:rotate(180deg)}
        .agreement-body{display:none;background:white;border:1.5px solid var(--blue-200);border-top:none;border-radius:0 0 var(--radius) var(--radius);padding:20px 24px;font-size:.85rem;line-height:1.7;color:var(--text-mid)}
        .agreement-body.open{display:block;animation:accordionIn .25s ease both}
        @keyframes accordionIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
        .agreement-body h4{font-family:'Sora',sans-serif;font-size:.8rem;font-weight:800;color:var(--text);margin:14px 0 6px;display:flex;align-items:center;gap:7px}
        .agreement-body h4:first-child{margin-top:0}
        .agreement-body h4 i{color:var(--blue-500);font-size:.85rem}
        .agreement-body p{margin-bottom:8px;color:var(--text-light)}
        .check-row{display:flex;align-items:flex-start;gap:13px;padding:16px 20px;border-radius:var(--radius);margin-top:12px;cursor:pointer;border:2px solid transparent;transition:all .2s}
        .check-row.privacy{background:#eff6ff;border-color:var(--blue-200)}
        .check-row.program{background:#ecfdf5;border-color:#a7f3d0}
        .check-row:has(input:checked).privacy{border-color:var(--blue-500);background:#dbeafe}
        .check-row:has(input:checked).program{border-color:#10b981;background:#d1fae5}
        .check-row input[type=checkbox]{width:19px;height:19px;flex-shrink:0;accent-color:var(--blue-600);cursor:pointer;margin-top:2px}
        .check-row-text{font-size:.87rem;color:var(--text-mid);line-height:1.5}
        .check-row-text strong{color:var(--text);display:block;margin-bottom:3px}
        .check-row-text .sub{font-size:.76rem;color:var(--text-muted)}

        /* Step NAV */
        .step-nav{display:flex;gap:14px;align-items:center;margin-top:28px;padding-top:24px;border-top:1px solid var(--border)}
        .btn-step-back{display:flex;align-items:center;gap:8px;padding:13px 22px;background:white;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:.88rem;font-weight:700;color:var(--text-light);cursor:pointer;transition:all .2s}
        .btn-step-back:hover{background:var(--blue-50);border-color:var(--blue-300);color:var(--blue-600)}
        .btn-step-next{flex:1;display:flex;align-items:center;justify-content:center;gap:9px;padding:14px 24px;background:linear-gradient(135deg,var(--blue-500),var(--blue-700));color:white;border:none;border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:all .2s;box-shadow:0 4px 16px rgba(37,99,235,.3)}
        .btn-step-next:hover{background:linear-gradient(135deg,var(--blue-600),var(--blue-800));transform:translateY(-1px);box-shadow:0 6px 24px rgba(37,99,235,.4)}
        .btn-step-next:active{transform:translateY(0)}
        .btn-submit{flex:1;display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:16px 32px;background:linear-gradient(135deg,#10b981,#059669);color:white;border:none;border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;transition:all .25s;box-shadow:0 4px 20px rgba(16,185,129,.35)}
        .btn-submit:hover{background:linear-gradient(135deg,#059669,#047857);transform:translateY(-2px);box-shadow:0 8px 28px rgba(16,185,129,.45)}
        .secure-note{text-align:center;margin-top:14px;font-size:.8rem;color:var(--text-muted);display:flex;align-items:center;justify-content:center;gap:6px}
        .secure-note i{color:var(--success)}

        /* SUCCESS */
        .tracking-wrapper{background:linear-gradient(135deg,var(--blue-50),#f0f9ff);border:2px solid var(--blue-200);border-radius:var(--radius-lg);padding:30px;text-align:center;margin:28px 0;position:relative;overflow:hidden}
        .tracking-wrapper::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;border-radius:50%;background:rgba(37,99,235,.06)}
        .tracking-label{font-size:.8rem;font-weight:600;color:var(--blue-600);text-transform:uppercase;letter-spacing:.1em;margin-bottom:10px}
        .tracking-code{font-family:'Sora',sans-serif;font-size:2rem;font-weight:800;color:var(--blue-700);letter-spacing:3px}
        .tracking-note{margin-top:10px;font-size:.83rem;color:var(--text-light)}
        .success-steps{background:var(--blue-50);border-radius:var(--radius);padding:20px 24px;margin:20px 0}
        .success-step{display:flex;gap:14px;padding:10px 0;border-bottom:1px solid var(--blue-100)}
        .success-step:last-child{border-bottom:none}
        .success-step-num{width:28px;height:28px;background:var(--blue-500);color:white;border-radius:50%;display:flex;align-items:center;justify-content:center;font-family:'Sora',sans-serif;font-size:.78rem;font-weight:700;flex-shrink:0;margin-top:1px}
        .success-step-text{font-size:.88rem;color:var(--text-mid);line-height:1.5}

        /* STATUS WIDGET */
        .status-widget{background:rgba(255,255,255,.06);backdrop-filter:blur(12px);border:1px solid rgba(255,255,255,.1);border-radius:var(--radius-lg);padding:24px 28px;margin-bottom:20px;animation:fadeUp .6s .45s ease both}
        .status-widget-title{font-family:'Sora',sans-serif;font-size:.75rem;font-weight:700;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.12em;margin-bottom:16px}
        .status-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
        .stat-item{text-align:center;padding:14px;background:rgba(255,255,255,.05);border-radius:var(--radius-sm);border:1px solid rgba(255,255,255,.07)}
        .stat-value{font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:800;color:white;line-height:1;margin-bottom:4px}
        .stat-value.ok{color:#34d399}.stat-value.warn{color:#fbbf24}.stat-value.bad{color:#f87171}
        .stat-label{font-size:.72rem;color:rgba(255,255,255,.45);font-weight:500}

        /* DISABLED */
        .disabled-icon{font-size:3.5rem;color:var(--blue-400);margin-bottom:20px;opacity:.7}
        .disabled-title{font-family:'Sora',sans-serif;font-size:1.6rem;font-weight:800;color:var(--text);margin-bottom:12px}
        .disabled-desc{color:var(--text-light);font-size:1rem;line-height:1.6;margin-bottom:28px;max-width:460px;margin-left:auto;margin-right:auto}
        .contact-box{background:var(--blue-50);border:1px solid var(--blue-100);border-radius:var(--radius);padding:20px;max-width:420px;margin:0 auto;text-align:left}
        .contact-title{font-weight:700;color:var(--blue-700);font-size:.9rem;margin-bottom:12px;display:flex;align-items:center;gap:8px}
        .contact-items{display:flex;flex-direction:column;gap:7px}
        .contact-items span{font-size:.88rem;color:var(--text-mid);display:flex;align-items:center;gap:8px}
        .contact-items i{color:var(--blue-500);width:16px}
        .btn-primary{display:flex;align-items:center;justify-content:center;gap:10px;width:100%;padding:16px 32px;background:linear-gradient(135deg,var(--blue-500),var(--blue-700));color:white;border:none;border-radius:var(--radius);font-family:'Sora',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;transition:all .25s;letter-spacing:.02em;box-shadow:0 4px 20px rgba(37,99,235,.35);text-decoration:none}
        .btn-primary:hover{background:linear-gradient(135deg,var(--blue-600),var(--blue-800));transform:translateY(-2px)}

        .page-footer{text-align:center;padding:20px;color:rgba(255,255,255,.35);font-size:.8rem;animation:fadeUp .6s .5s ease both}
        .page-footer a{color:rgba(255,255,255,.5);text-decoration:none;transition:color .2s}
        .page-footer a:hover{color:rgba(255,255,255,.8)}
        .page-footer .sep{margin:0 8px;opacity:.4}

        /* FIX #4: Modern floating label / char counter */
        .char-counter{font-size:.72rem;color:var(--text-muted);text-align:right;margin-top:3px;transition:color .2s}
        .char-counter.warn{color:var(--warning)}
        .char-counter.err{color:var(--error)}

        /* Auto-format hint pill */
        .fmt-hint{display:inline-flex;align-items:center;gap:5px;font-size:.72rem;background:var(--blue-50);border:1px solid var(--blue-100);border-radius:20px;padding:3px 10px;color:var(--blue-600);margin-top:5px}

        /* TERMS MODAL */
        #terms-modal-overlay{position:fixed;inset:0;z-index:9999;background:rgba(5,15,35,.95);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:center;padding:16px;animation:modalFadeIn .35s ease both}
        #terms-modal-overlay.hiding{animation:modalFadeOut .3s ease forwards}
        @keyframes modalFadeIn{from{opacity:0}to{opacity:1}}
        @keyframes modalFadeOut{from{opacity:1}to{opacity:0}}
        #terms-modal{background:#fff;border-radius:var(--radius-xl);box-shadow:0 30px 80px rgba(0,0,0,.5);width:100%;max-width:700px;max-height:92vh;display:flex;flex-direction:column;overflow:hidden;animation:modalSlideUp .4s cubic-bezier(.34,1.56,.64,1) both}
        @keyframes modalSlideUp{from{transform:translateY(40px);opacity:0}to{transform:translateY(0);opacity:1}}
        .modal-header{background:linear-gradient(135deg,var(--blue-800),var(--blue-950));padding:22px 28px;display:flex;align-items:center;gap:16px;flex-shrink:0}
        .modal-header-icon{width:48px;height:48px;background:rgba(255,255,255,.12);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--accent);flex-shrink:0}
        .modal-header-text h2{font-family:'Sora',sans-serif;font-size:1.05rem;font-weight:800;color:white;margin-bottom:3px}
        .modal-header-text p{font-size:.78rem;color:rgba(255,255,255,.55)}
        .modal-visit-notice{display:flex;align-items:center;gap:10px;background:rgba(251,191,36,.15);border:1px solid rgba(251,191,36,.35);border-radius:var(--radius-sm);padding:10px 14px;margin-top:12px;font-size:.78rem;color:rgba(255,255,255,.85);line-height:1.4}
        .modal-visit-notice i{color:#fbbf24;flex-shrink:0;font-size:.9rem}
        .modal-steps{display:flex;gap:0;border-bottom:1px solid var(--border);background:var(--blue-50);flex-shrink:0}
        .modal-step-tab{flex:1;padding:13px 16px;display:flex;align-items:center;justify-content:center;gap:8px;font-family:'Sora',sans-serif;font-size:.77rem;font-weight:700;color:var(--text-muted);border-bottom:3px solid transparent;transition:all .2s;cursor:default}
        .modal-step-tab .step-num{width:22px;height:22px;border-radius:50%;background:var(--border);color:var(--text-muted);display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;transition:all .2s}
        .modal-step-tab.active{color:var(--blue-700);border-bottom-color:var(--blue-500)}
        .modal-step-tab.active .step-num{background:var(--blue-500);color:white}
        .modal-step-tab.done{color:var(--success);border-bottom-color:var(--success)}
        .modal-step-tab.done .step-num{background:var(--success);color:white}
        .modal-body{flex:1;overflow-y:auto;padding:28px 28px 0;scroll-behavior:smooth}
        .modal-body::-webkit-scrollbar{width:6px}
        .modal-body::-webkit-scrollbar-thumb{background:var(--blue-200);border-radius:4px}
        .modal-pane{display:none}
        .modal-pane.active{display:block}
        .modal-pane-title{display:flex;align-items:center;gap:12px;padding:16px 20px;border-radius:var(--radius);margin-bottom:20px;font-family:'Sora',sans-serif;font-size:.92rem;font-weight:800}
        .modal-pane-title.privacy{background:linear-gradient(135deg,#0f172a,#1e3a8a);color:white}
        .modal-pane-title.program{background:linear-gradient(135deg,#064e3b,#065f46);color:white}
        .modal-pane-title .pane-icon{width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
        .modal-terms-body{font-size:.875rem;line-height:1.72;color:var(--text-mid)}
        .modal-terms-body p{margin-bottom:12px}
        .modal-terms-item{display:flex;gap:14px;padding:13px 16px;margin-bottom:10px;border-radius:var(--radius-sm);background:#f8fafc;border:1px solid var(--border)}
        .modal-terms-item:last-child{margin-bottom:0}
        .modal-terms-item .titem-icon{flex-shrink:0;margin-top:1px}
        .modal-terms-item .titem-text strong{display:block;font-size:.82rem;font-weight:700;color:var(--text);margin-bottom:3px}
        .modal-terms-item .titem-text p{margin:0;font-size:.82rem;color:var(--text-light)}
        .scroll-hint{position:sticky;bottom:0;background:linear-gradient(transparent,rgba(255,255,255,.97) 40%);padding:24px 0 8px;text-align:center;pointer-events:none;transition:opacity .3s}
        .scroll-hint span{display:inline-flex;align-items:center;gap:6px;font-size:.78rem;color:var(--text-muted)}
        .modal-footer{padding:20px 28px;border-top:1px solid var(--border);background:#fafbfc;flex-shrink:0}
        .modal-agree-row{display:flex;align-items:flex-start;gap:13px;padding:14px 18px;border-radius:var(--radius);margin-bottom:14px;cursor:pointer;border:2px solid transparent;transition:all .2s}
        .modal-agree-row.privacy{background:#eff6ff;border-color:var(--blue-200)}
        .modal-agree-row.program{background:#ecfdf5;border-color:#a7f3d0}
        .modal-agree-row:has(input:checked).privacy{border-color:var(--blue-500);background:#dbeafe}
        .modal-agree-row:has(input:checked).program{border-color:#10b981;background:#d1fae5}
        .modal-agree-row input[type=checkbox]{width:19px;height:19px;flex-shrink:0;accent-color:var(--blue-600);cursor:pointer;margin-top:1px}
        .modal-agree-row-text{font-size:.87rem;color:var(--text-mid);line-height:1.5}
        .modal-agree-row-text strong{color:var(--text);font-size:.88rem}
        .modal-agree-row-text .sub{font-size:.76rem;color:var(--text-muted);margin-top:3px;display:block}
        .modal-nav{display:flex;gap:12px;align-items:center;margin-top:4px}
        .btn-modal-next,.btn-modal-accept{flex:1;display:flex;align-items:center;justify-content:center;gap:9px;padding:13px 20px;border:none;border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:all .2s}
        .btn-modal-next{background:var(--blue-500);color:white}
        .btn-modal-next:hover{background:var(--blue-600);transform:translateY(-1px)}
        .btn-modal-next:disabled{background:var(--border);color:var(--text-muted);transform:none;cursor:not-allowed}
        .btn-modal-accept{background:linear-gradient(135deg,#10b981,#059669);color:white}
        .btn-modal-accept:hover{background:linear-gradient(135deg,#059669,#047857);transform:translateY(-1px)}
        .btn-modal-accept:disabled{background:var(--border);color:var(--text-muted);transform:none;cursor:not-allowed}
        .btn-modal-back{padding:13px 16px;border:1.5px solid var(--border);background:white;border-radius:var(--radius-sm);font-family:'Sora',sans-serif;font-size:.82rem;font-weight:700;color:var(--text-light);cursor:pointer;display:flex;align-items:center;gap:7px}
        .btn-modal-back:hover{background:var(--blue-50);border-color:var(--blue-300);color:var(--blue-600)}
        .modal-progress{height:4px;background:var(--border);border-radius:2px;margin-bottom:12px;overflow:hidden}
        .modal-progress-bar{height:100%;background:linear-gradient(90deg,var(--blue-400),var(--blue-600));border-radius:2px;transition:width .4s ease}

        /* ANIMATIONS */
        @keyframes fadeDown{from{opacity:0;transform:translateY(-16px)}to{opacity:1;transform:translateY(0)}}
        @keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        @keyframes spin{to{transform:rotate(360deg)}}
        .fa-spin-me{animation:spin 1s linear infinite}

        /* RESPONSIVE */
        @media(max-width:640px){
            .form-grid{grid-template-columns:1fr}
            .form-group.full{grid-column:span 1}
            .form-body{padding:24px 20px}
            .section-header{padding:18px 20px}
            .status-stats{grid-template-columns:1fr}
            .hero-title{font-size:1.8rem}
            .webcam-buttons{flex-direction:column}
            #webcam-preview-container{width:100%}
            .id-section-tabs{flex-direction:column}
            .id-tab-btn:first-child{border-right:none;border-bottom:1.5px solid var(--blue-200)}
            .stepper{gap:4px}
            .step-label{font-size:.58rem}
            .step-circle{width:36px;height:36px;font-size:.75rem}
            .stepper-line{top:18px}
            .step-nav{flex-direction:column}
            .btn-step-back{width:100%;justify-content:center}
            .id-dual-upload{grid-template-columns:1fr}
            .custom-location-inner{grid-template-columns:1fr}
        }
    </style>
</head>
<body>

<div class="bg-scene">
    <div class="bg-grid"></div>
    <div class="bg-orb bg-orb-1"></div>
    <div class="bg-orb bg-orb-2"></div>
    <div class="bg-orb bg-orb-3"></div>
</div>

<div class="page-wrapper">
    <div class="hero">
        <div class="logo-wrap">
            <img src="/als/logo/als-logo-removebg-preview.png" alt="ALS Logo" class="logo-img">
            <div class="logo-text">
                <div class="dept">DepEd Philippines</div>
                <div class="div-name">La Carlota City Division</div>
            </div>
        </div>
        <h1 class="hero-title">ALS <span>Pre-registration</span></h1>
        <p class="hero-subtitle"><?php echo htmlspecialchars($pr_settings['preregistration_message'] ?? 'Complete this form to begin your Alternative Learning System enrollment process'); ?></p>
    </div>

    <div class="container">

    <?php if (!empty($disabled_message)): ?>
        <div class="form-card"><?php echo $disabled_message; ?></div>

    <?php elseif ($success): ?>
        <div class="form-card">
            <div class="section-header">
                <div class="section-header-icon"><i class="fas fa-check-circle"></i></div>
                <div class="section-header-text">
                    <h2>Pre-registration Successful!</h2>
                    <p>Your application has been submitted and is under review</p>
                </div>
            </div>
            <div class="form-body">
                <div class="alert alert-success">
                    <div class="alert-icon"><i class="fas fa-check"></i></div>
                    <div><strong>Submission received!</strong><br>A confirmation email has been sent to <strong><?php echo htmlspecialchars($stored_post['email'] ?? ''); ?></strong></div>
                </div>
                <?php if (!empty($id_verify_warnings)): ?>
                <div class="alert alert-warning">
                    <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div><strong>ID Verification Note:</strong><br><?php foreach ($id_verify_warnings as $w): ?><?php echo htmlspecialchars($w); ?><br><?php endforeach; ?>The ALS staff may request a clearer copy of your document.</div>
                </div>
                <?php endif; ?>
                <div class="tracking-wrapper">
                    <div class="tracking-label"><i class="fas fa-tag"></i> &nbsp;Your Tracking Code</div>
                    <div class="tracking-code"><?php echo $tracking_code; ?></div>
                    <div class="tracking-note">Save this code — you'll need it to check your application status</div>
                </div>
                <p style="font-family:'Sora',sans-serif;font-size:.82rem;font-weight:700;color:var(--blue-600);text-transform:uppercase;letter-spacing:.1em;margin-bottom:14px;">What happens next?</p>
                <div class="success-steps">
                    <div class="success-step"><div class="success-step-num">1</div><div class="success-step-text">Your pre-registration is now <strong>pending review</strong> by an ALS coordinator</div></div>
                    <div class="success-step"><div class="success-step-num">2</div><div class="success-step-text">You will receive an <strong>email notification</strong> once your application is approved</div></div>
                    <div class="success-step"><div class="success-step-num">3</div><div class="success-step-text">The approval email will contain a <strong>link to complete the full enrollment form</strong></div></div>
                    <div class="success-step"><div class="success-step-num">4</div><div class="success-step-text">Please <strong>prepare the required documents</strong> for actual enrollment</div></div>
                </div>
                <a href="/" class="btn-primary" style="margin-top:10px;text-decoration:none;"><i class="fas fa-home"></i> Return to Homepage</a>
            </div>
        </div>

    <?php else: ?>

        <!-- STEPPER -->
        <div class="stepper-wrap">
            <div class="stepper" id="stepper">
                <?php
                $step_labels = ['Personal Info','Contact & Address','Guardian','Photo & ID','Review & Submit'];
                for ($s = 1; $s <= 5; $s++):
                    $has_err = !empty($step_errors[$s]);
                ?>
                <div class="step-item" id="si-<?php echo $s; ?>">
                    <div class="step-circle"><span><?php echo $s; ?></span></div>
                    <div class="step-label"><?php echo $step_labels[$s-1]; ?></div>
                </div>
                <?php endfor; ?>
            </div>
            <div class="stepper-line">
                <div class="stepper-line-fill" id="stepper-fill"></div>
            </div>
        </div>

        <div class="form-card">
            <div class="section-header" id="section-header">
                <div class="section-header-icon" id="section-icon"><i class="fas fa-user"></i></div>
                <div class="section-header-text">
                    <h2 id="section-title">Step 1 — Personal Information</h2>
                    <p id="section-sub">Fields marked with <span style="color:#fca5a5;">*</span> are required</p>
                </div>
            </div>
            <div class="form-body">

                <!-- FIX #3: Global error banner only for current step -->
                <div id="global-error-banner" style="display:none;">
                    <div class="alert alert-error">
                        <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div>
                            <strong>Please fix the following errors:</strong>
                            <ul id="global-error-list"></ul>
                        </div>
                    </div>
                </div>

                <!-- FIX #3: Cross-step error navigation badges -->
                <div id="other-step-errors" style="display:none;margin-bottom:20px;"></div>

                <form method="POST" id="preregForm" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="selfie_image" id="selfie_image_data">
                    <input type="hidden" name="has_no_valid_id" id="has_no_valid_id_field" value="0">
                    <input type="hidden" name="accept_terms_privacy" id="hidden_accept_privacy" value="no">
                    <input type="hidden" name="accept_terms_program" id="hidden_accept_program" value="no">

                    <!-- ══ STEP 1: PERSONAL INFO ══ -->
                    <div class="step-panel" id="step-1">
                        <?php if (!empty($step_errors[1])): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><strong>Please fix:</strong><ul><?php foreach ($step_errors[1] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
                        </div>
                        <?php endif; ?>
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-user"></i> Personal Information</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="field-label">Last Name <span class="req">*</span></label>
                                    <input class="field-input" type="text" name="last_name" id="f_last_name"
                                        value="<?php echo htmlspecialchars($stored_post['last_name'] ?? ''); ?>"
                                        required placeholder="e.g. Dela Cruz" autocomplete="family-name">
                                    <div class="field-error-msg" id="err_last_name"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                    <i class="fas fa-check field-success-check" id="ok_last_name"></i>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">First Name <span class="req">*</span></label>
                                    <input class="field-input" type="text" name="first_name" id="f_first_name"
                                        value="<?php echo htmlspecialchars($stored_post['first_name'] ?? ''); ?>"
                                        required placeholder="e.g. Juan" autocomplete="given-name">
                                    <div class="field-error-msg" id="err_first_name"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                    <i class="fas fa-check field-success-check" id="ok_first_name"></i>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Middle Name <span class="opt">(optional)</span></label>
                                    <input class="field-input" type="text" name="middle_name"
                                        value="<?php echo htmlspecialchars($stored_post['middle_name'] ?? ''); ?>"
                                        placeholder="e.g. Santos">
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Extension Name <span class="opt">(optional)</span></label>
                                    <input class="field-input" type="text" name="extension_name"
                                        value="<?php echo htmlspecialchars($stored_post['extension_name'] ?? ''); ?>"
                                        placeholder="Jr., Sr., III…">
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Birth Date <span class="req">*</span></label>
                                    <input class="field-input" type="date" name="birthdate" id="f_birthdate"
                                        value="<?php echo htmlspecialchars($stored_post['birthdate'] ?? ''); ?>"
                                        required
                                        max="<?php echo date('Y-m-d', strtotime('-5 years')); ?>"
                                        min="<?php echo date('Y-m-d', strtotime('-100 years')); ?>">
                                    <div class="field-error-msg" id="err_birthdate"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                    <div class="field-hint" id="age_display" style="display:none;color:var(--success);font-weight:600;"></div>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Sex <span class="req">*</span></label>
                                    <div class="radio-group">
                                        <div class="radio-card">
                                            <input type="radio" name="sex" id="sex_male" value="male" <?php echo ($stored_post['sex'] ?? '') === 'male' ? 'checked' : ''; ?> required>
                                            <label for="sex_male"><i class="fas fa-mars"></i> Male</label>
                                        </div>
                                        <div class="radio-card">
                                            <input type="radio" name="sex" id="sex_female" value="female" <?php echo ($stored_post['sex'] ?? '') === 'female' ? 'checked' : ''; ?> required>
                                            <label for="sex_female"><i class="fas fa-venus"></i> Female</label>
                                        </div>
                                    </div>
                                    <div class="field-error-msg" id="err_sex"><i class="fas fa-circle-exclamation"></i> <span>Please select your sex.</span></div>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">LRN <span class="opt">(optional)</span></label>
                                    <input class="field-input" type="text" name="lrn" id="f_lrn"
                                        value="<?php echo htmlspecialchars($stored_post['lrn'] ?? ''); ?>"
                                        placeholder="12-digit number" maxlength="12" pattern="\d*" inputmode="numeric">
                                    <div class="char-counter" id="lrn_counter" style="display:none;"></div>
                                    <div class="field-hint"><i class="fas fa-info-circle"></i> Learner Reference Number from previous school</div>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Last Grade Level <span class="opt">(optional)</span></label>
                                    <div class="select-wrapper">
                                        <select class="field-select" name="last_grade_level">
                                            <option value="">Select grade level</option>
                                            <?php foreach ($grade_levels as $g): ?>
                                            <option value="<?php echo $g; ?>" <?php echo ($stored_post['last_grade_level'] ?? '') === $g ? 'selected' : ''; ?>><?php echo $g; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="step-nav">
                            <button type="button" class="btn-step-next" onclick="validateAndGo(1, 2)">Continue <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- ══ STEP 2: CONTACT & ADDRESS ══ -->
                    <div class="step-panel" id="step-2">
                        <?php if (!empty($step_errors[2])): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><strong>Please fix:</strong><ul><?php foreach ($step_errors[2] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
                        </div>
                        <?php endif; ?>
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-phone"></i> Contact Information</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="field-label">Contact Number <span class="req">*</span></label>
                                    <input class="field-input" type="tel" name="contact_number" id="f_contact_number"
                                        value="<?php echo htmlspecialchars($stored_post['contact_number'] ?? ''); ?>"
                                        required placeholder="09XX-XXX-XXXX" maxlength="13" inputmode="tel">
                                    <div class="fmt-hint"><i class="fas fa-magic"></i> Auto-formats as 09XX-XXX-XXXX</div>
                                    <div class="field-error-msg" id="err_contact_number"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Email Address <span class="req">*</span></label>
                                    <input class="field-input" type="email" name="email" id="f_email"
                                        value="<?php echo htmlspecialchars($stored_post['email'] ?? ''); ?>"
                                        required placeholder="you@example.com" autocomplete="email">
                                    <div class="field-error-msg" id="err_email"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                    <i class="fas fa-check field-success-check" id="ok_email"></i>
                                </div>
                            </div>
                        </div>

                        <!-- FIX #1: Barangay with "Other" → custom barangay + city inputs -->
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-map-marker-alt"></i> Current Address</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="field-label">Barangay <span class="req">*</span></label>
                                    <div class="select-wrapper">
                                        <select class="field-select" name="current_barangay_id" id="barangaySelect"
                                            onchange="toggleCustomBarangay(this)">
                                            <option value="">— Select Barangay in La Carlota City —</option>
                                            <?php foreach ($barangays as $b): ?>
                                            <option value="<?php echo $b['barangay_id']; ?>"
                                                <?php echo ($stored_post['current_barangay_id'] ?? '') == $b['barangay_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($b['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                            <option value="other" <?php echo (($stored_post['current_barangay_id'] ?? '') === 'other') ? 'selected' : ''; ?>>
                                                📍 My barangay is NOT in this list
                                            </option>
                                        </select>
                                    </div>
                                    <div class="field-hint"><i class="fas fa-info-circle"></i> Choose your barangay, or "not in list" if from outside La Carlota City</div>
                                </div>
                                <div class="form-group" id="lacarlota-city-display">
                                    <label class="field-label">City / Municipality</label>
                                    <input class="field-input" type="text" name="current_city"
                                        value="<?php echo htmlspecialchars($stored_post['current_city'] ?? 'La Carlota City'); ?>"
                                        readonly>
                                </div>
                            </div>

                            <!-- Custom barangay + city fields (shown when "Other" selected) -->
                            <div class="custom-location-fields" id="customLocationFields">
                                <div class="custom-location-notice">
                                    <i class="fas fa-info-circle" style="color:var(--blue-600);flex-shrink:0;"></i>
                                    <span>You selected <strong>"Not in list"</strong> — please type your barangay and city/municipality below. Bring proof of address during enrollment.</span>
                                </div>
                                <div class="custom-location-inner">
                                    <div class="form-group">
                                        <label class="field-label">Barangay Name <span class="req">*</span></label>
                                        <input class="field-input" type="text" name="current_custom_barangay" id="customBarangay"
                                            value="<?php echo htmlspecialchars($stored_post['current_custom_barangay'] ?? ''); ?>"
                                            placeholder="e.g. Brgy. Poblacion" autocomplete="off">
                                        <div class="field-error-msg" id="err_custom_barangay"><i class="fas fa-circle-exclamation"></i> <span>Barangay name is required.</span></div>
                                    </div>
                                    <div class="form-group">
                                        <label class="field-label">City / Municipality <span class="req">*</span></label>
                                        <input class="field-input" type="text" name="current_city_custom" id="customCity"
                                            value="<?php echo htmlspecialchars($stored_post['current_city_custom'] ?? ''); ?>"
                                            placeholder="e.g. Bago City" autocomplete="off">
                                        <div class="field-error-msg" id="err_custom_city"><i class="fas fa-circle-exclamation"></i> <span>City / municipality is required.</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="step-nav">
                            <button type="button" class="btn-step-back" onclick="goStep(1)"><i class="fas fa-arrow-left"></i> Back</button>
                            <button type="button" class="btn-step-next" onclick="validateAndGo(2, 3)">Continue <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- ══ STEP 3: GUARDIAN ══ -->
                    <div class="step-panel" id="step-3">
                        <?php if (!empty($step_errors[3])): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><strong>Please fix:</strong><ul><?php foreach ($step_errors[3] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
                        </div>
                        <?php endif; ?>
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-users"></i> Parent / Guardian Information</div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="field-label">Full Name <span class="req">*</span></label>
                                    <input class="field-input" type="text" name="parent_name" id="f_parent_name"
                                        value="<?php echo htmlspecialchars($stored_post['parent_name'] ?? ''); ?>"
                                        required placeholder="Complete name">
                                    <div class="field-error-msg" id="err_parent_name"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                    <i class="fas fa-check field-success-check" id="ok_parent_name"></i>
                                </div>
                                <div class="form-group">
                                    <label class="field-label">Contact Number <span class="req">*</span></label>
                                    <input class="field-input" type="tel" name="parent_contact" id="f_parent_contact"
                                        value="<?php echo htmlspecialchars($stored_post['parent_contact'] ?? ''); ?>"
                                        required placeholder="09XX-XXX-XXXX" maxlength="13" inputmode="tel">
                                    <div class="fmt-hint"><i class="fas fa-magic"></i> Auto-formats as 09XX-XXX-XXXX</div>
                                    <div class="field-error-msg" id="err_parent_contact"><i class="fas fa-circle-exclamation"></i> <span></span></div>
                                </div>
                            </div>
                        </div>
                        <div class="step-nav">
                            <button type="button" class="btn-step-back" onclick="goStep(2)"><i class="fas fa-arrow-left"></i> Back</button>
                            <button type="button" class="btn-step-next" onclick="validateAndGo(3, 4)">Continue <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div>

                    <!-- ══ STEP 4: PHOTO & ID ══ -->
                    <div class="step-panel" id="step-4">
                        <?php if (!empty($step_errors[4])): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><strong>Please fix:</strong><ul><?php foreach ($step_errors[4] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
                        </div>
                        <?php endif; ?>

                        <!-- SELFIE -->
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-camera"></i> Photo Capture <span style="font-size:.7rem;margin-left:6px;color:var(--error);">Required</span></div>
                            <div class="webcam-card">
                                <div class="webcam-header">
                                    <div class="webcam-badge"><i class="fas fa-camera"></i></div>
                                    <div class="webcam-header-text">Take a Selfie<span>Position your face clearly within the frame, then click Capture</span></div>
                                </div>
                                <div id="webcam-preview-container">
                                    <video id="webcam-video" autoplay playsinline></video>
                                    <canvas id="webcam-canvas" width="320" height="240"></canvas>
                                    <img id="selfie-preview" alt="Captured selfie">
                                    <div class="webcam-overlay" id="webcam-overlay"><i class="fas fa-camera-slash"></i><span>Camera not started</span></div>
                                </div>
                                <div class="webcam-buttons">
                                    <button type="button" class="btn-webcam btn-webcam-start" id="btn-start-camera"><i class="fas fa-video"></i> Open Camera</button>
                                    <button type="button" class="btn-webcam btn-webcam-capture" id="btn-capture" style="display:none;"><i class="fas fa-camera"></i> Capture Photo</button>
                                    <button type="button" class="btn-webcam btn-webcam-retake" id="btn-retake" style="display:none;"><i class="fas fa-redo"></i> Retake</button>
                                </div>
                                <div class="webcam-status" id="webcam-status"><i class="fas fa-circle-info"></i> Click "Open Camera" to begin</div>
                            </div>
                        </div>

                        <!-- FIX #2: ID front + back upload -->
                        <div class="form-section">
                            <div class="form-section-title">
                                <i class="fas fa-id-card"></i> Identity Document
                                <span style="font-size:.7rem;margin-left:6px;color:var(--error);">Required</span>
                            </div>
                            <div class="id-section-tabs">
                                <button type="button" class="id-tab-btn active" id="tab-valid-id" onclick="switchIdTab('valid-id')"><i class="fas fa-id-card"></i> I have a Valid ID</button>
                                <button type="button" class="id-tab-btn" id="tab-no-id" onclick="switchIdTab('no-id')"><i class="fas fa-file-alt"></i> I don't have a Valid ID</button>
                            </div>

                            <!-- Valid ID pane -->
                            <div class="id-tab-pane active" id="pane-valid-id">
                                <div class="upload-card">
                                    <div class="form-grid" style="margin-bottom:16px;">
                                        <div class="form-group full">
                                            <label class="field-label">ID Type <span class="req">*</span></label>
                                            <div class="select-wrapper">
                                                <select class="field-select" name="valid_id_type" id="valid_id_type_select">
                                                    <option value="">Select your government ID type</option>
                                                    <?php foreach ($valid_id_types as $it): ?>
                                                    <option value="<?php echo htmlspecialchars($it); ?>" <?php echo ($stored_post['valid_id_type'] ?? '') === $it ? 'selected' : ''; ?>><?php echo htmlspecialchars($it); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Front + Back dual upload -->
                                    <div class="id-dual-upload">
                                        <!-- FRONT -->
                                        <div class="id-side-card">
                                            <div class="id-side-label">
                                                <i class="fas fa-id-card" style="color:var(--blue-600);"></i>
                                                <span>Front Side</span>
                                                <span class="side-badge">FRONT</span>
                                            </div>
                                            <div class="file-drop-zone" id="dropZoneFront" onclick="document.getElementById('valid_id_front').click()">
                                                <input type="file" name="valid_id_front" id="valid_id_front"
                                                    accept="image/jpeg,image/png,application/pdf"
                                                    style="display:none;" onchange="handleUpload(this,'front')">
                                                <div class="drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                                <div class="drop-text"><strong>Upload Front</strong></div>
                                                <div class="drop-hint">JPG, PNG, or PDF · Max 5 MB<br>Place ID flat, landscape, all 4 corners visible</div>
                                            </div>
                                            <div class="id-side-preview" id="front-preview-container">
                                                <img id="front-preview-img" src="" alt="Front" style="display:none;">
                                                <div id="front-preview-pdf" class="pdf-info" style="display:none;"><i class="fas fa-file-pdf"></i><span id="front-pdf-name"></span></div>
                                                <button type="button" class="id-remove-btn" onclick="removeUpload('front')" title="Remove"><i class="fas fa-times"></i></button>
                                            </div>
                                            <div class="id-verify-status" id="front-verify-status"></div>
                                        </div>

                                        <!-- BACK -->
                                        <div class="id-side-card">
                                            <div class="id-side-label">
                                                <i class="fas fa-id-card-alt" style="color:#6d28d9;"></i>
                                                <span>Back Side</span>
                                                <span class="side-badge back">BACK</span>
                                            </div>
                                            <div class="file-drop-zone" id="dropZoneBack" onclick="document.getElementById('valid_id_back').click()">
                                                <input type="file" name="valid_id_back" id="valid_id_back"
                                                    accept="image/jpeg,image/png,application/pdf"
                                                    style="display:none;" onchange="handleUpload(this,'back')">
                                                <div class="drop-icon"><i class="fas fa-sync-alt" style="color:#6d28d9;opacity:.7;"></i></div>
                                                <div class="drop-text"><strong>Upload Back</strong></div>
                                                <div class="drop-hint">JPG, PNG, or PDF · Max 5 MB<br>Flip the ID over and take the same flat photo</div>
                                            </div>
                                            <div class="id-side-preview" id="back-preview-container">
                                                <img id="back-preview-img" src="" alt="Back" style="display:none;">
                                                <div id="back-preview-pdf" class="pdf-info" style="display:none;"><i class="fas fa-file-pdf"></i><span id="back-pdf-name"></span></div>
                                                <button type="button" class="id-remove-btn" onclick="removeUpload('back')" title="Remove"><i class="fas fa-times"></i></button>
                                            </div>
                                            <div class="id-verify-status" id="back-verify-status"></div>
                                        </div>
                                    </div><!-- /id-dual-upload -->

                                    <div class="upload-tips" style="margin-top:16px;">
                                        <div class="upload-tips-title"><i class="fas fa-lightbulb"></i> Tips for both sides</div>
                                        <ul class="upload-tips-list">
                                            <li>Lay the ID flat on a plain, well-lit surface — landscape orientation</li>
                                            <li>All four corners of the card must be fully visible</li>
                                            <li>Avoid glare, shadows, or fingers covering any part</li>
                                            <li>Do NOT photograph yourself holding the ID</li>
                                            <li>Flip the card for the back photo without changing your setup</li>
                                        </ul>
                                    </div>
                                    <div class="accepted-ids" style="margin-top:14px;">
                                        <div class="accepted-ids-title"><i class="fas fa-check-circle" style="color:var(--success);margin-right:4px;"></i> Accepted Government IDs</div>
                                        <div class="accepted-ids-list"><?php foreach ($valid_id_types as $it): ?><span class="id-tag"><?php echo htmlspecialchars($it); ?></span><?php endforeach; ?></div>
                                    </div>
                                </div>
                            </div><!-- /pane-valid-id -->

                            <!-- No ID pane -->
                            <div class="id-tab-pane" id="pane-no-id">
                                <div class="no-id-notice">
                                    <i class="fas fa-info-circle"></i>
                                    <div class="no-id-notice-text"><strong>No valid ID? No problem.</strong> Upload one alternative document. Bring the original during actual enrollment.</div>
                                </div>
                                <div class="upload-card">
                                    <div class="form-grid" style="margin-bottom:16px;">
                                        <div class="form-group full">
                                            <label class="field-label">Document Type <span class="req">*</span></label>
                                            <div class="select-wrapper">
                                                <select class="field-select" name="alt_doc_type" id="alt_doc_type_select">
                                                    <option value="">Select document type</option>
                                                    <?php foreach ($alt_doc_types as $doc): ?>
                                                    <option value="<?php echo htmlspecialchars($doc); ?>" <?php echo ($stored_post['alt_doc_type'] ?? '') === $doc ? 'selected' : ''; ?>><?php echo htmlspecialchars($doc); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="file-drop-zone" id="dropZoneAlt" onclick="document.getElementById('alt_document').click()">
                                        <input type="file" name="alt_document" id="alt_document"
                                            accept="image/jpeg,image/png,application/pdf"
                                            style="display:none;" onchange="handleUpload(this,'alt')">
                                        <div class="drop-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                                        <div class="drop-text"><strong>Click to upload</strong> or drag and drop</div>
                                        <div class="drop-hint">JPG, PNG, or PDF · Max 5 MB · Must be clear and readable</div>
                                    </div>
                                    <div class="id-side-preview" id="alt-preview-container" style="display:none;border-radius:var(--radius-sm);overflow:hidden;border:1.5px solid var(--success);margin-top:10px;">
                                        <img id="alt-preview-img" src="" alt="Alt Doc" class="preview-img" style="display:none;max-height:160px;object-fit:contain;display:block;margin:0 auto;padding:4px;background:#fff;">
                                        <div id="alt-preview-pdf" class="preview-pdf" style="display:none;padding:14px;text-align:center;color:var(--text-mid);font-size:.85rem;"><i class="fas fa-file-pdf" style="font-size:1.8rem;color:var(--error);display:block;margin-bottom:6px;"></i><span id="alt-pdf-name"></span></div>
                                        <button type="button" class="id-remove-btn" onclick="removeUpload('alt')" title="Remove"><i class="fas fa-times"></i></button>
                                    </div>
                                    <div class="upload-tips" style="margin-top:12px;">
                                        <div class="upload-tips-title"><i class="fas fa-lightbulb"></i> Tips</div>
                                        <ul class="upload-tips-list">
                                            <li>Scan or photograph the full document clearly</li>
                                            <li>All text must be legible — avoid blurry photos</li>
                                            <li>PSA-authenticated documents are preferred</li>
                                        </ul>
                                    </div>
                                    <div class="accepted-ids" style="margin-top:14px;">
                                        <div class="accepted-ids-title"><i class="fas fa-check-circle" style="color:var(--success);margin-right:4px;"></i> Accepted Alternative Documents</div>
                                        <div class="accepted-ids-list"><?php foreach ($alt_doc_types as $doc): ?><span class="id-tag"><?php echo htmlspecialchars($doc); ?></span><?php endforeach; ?></div>
                                    </div>
                                </div>
                            </div><!-- /pane-no-id -->
                        </div>

                        <div class="step-nav">
                            <button type="button" class="btn-step-back" onclick="goStep(3)"><i class="fas fa-arrow-left"></i> Back</button>
                            <button type="button" class="btn-step-next" onclick="validateAndGo(4, 5)">Continue <i class="fas fa-arrow-right"></i></button>
                        </div>
                    </div><!-- /step-4 -->

                    <!-- ══ STEP 5: REVIEW & SUBMIT ══ -->
                    <div class="step-panel" id="step-5">
                        <?php if (!empty($step_errors[5])): ?>
                        <div class="alert alert-error">
                            <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                            <div><strong>Please fix:</strong><ul><?php foreach ($step_errors[5] as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
                        </div>
                        <?php endif; ?>

                        <!-- CAPTCHA -->
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-shield-alt"></i> Security Verification</div>
                            <div class="captcha-card">
                                <div class="captcha-header">
                                    <div class="captcha-badge"><i class="fas fa-eye"></i></div>
                                    <div class="captcha-header-text">Type the characters you see in the image<span>Not case-sensitive</span></div>
                                </div>
                                <div class="captcha-image-row">
                                    <div class="captcha-img-wrapper">
                                        <img id="captcha-img" src="?captcha_img=1&t=<?php echo time(); ?>" alt="CAPTCHA" width="200" height="60">
                                    </div>
                                    <button type="button" class="btn-refresh-captcha" onclick="refreshCaptcha()"><i class="fas fa-sync-alt"></i> New Code</button>
                                </div>
                                <input class="field-input" type="text" name="captcha_answer" id="captcha_answer"
                                    required placeholder="Enter the 6 characters" maxlength="6"
                                    autocomplete="off" spellcheck="false"
                                    style="text-align:center;font-size:1.2rem;font-weight:700;letter-spacing:6px;text-transform:uppercase;">
                                <div class="field-hint" style="text-align:center;margin-top:8px;"><i class="fas fa-info-circle"></i> Letters and numbers only · Not case-sensitive</div>
                            </div>
                        </div>

                        <!-- AGREEMENTS -->
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-file-contract"></i> Agreements</div>
                            <div class="agreement-section">
                                <button type="button" class="agreement-toggle" id="toggle-privacy" onclick="toggleAgreement('privacy')">
                                    <div class="agreement-toggle-left">
                                        <div class="agreement-toggle-icon privacy"><i class="fas fa-shield-alt"></i></div>
                                        <span>Data Privacy Agreement (RA 10173)</span>
                                    </div>
                                    <i class="fas fa-chevron-down agreement-chevron" id="chevron-privacy"></i>
                                </button>
                                <div class="agreement-body" id="body-privacy">
                                    <h4><i class="fas fa-user-circle"></i> Personal Data Being Collected</h4>
                                    <p>Full name, birth date, age, sex, contact number, email, home address, parent/guardian info, LRN, grade level, selfie, and ID images.</p>
                                    <h4><i class="fas fa-bullseye"></i> Purpose</h4>
                                    <p>Identity verification, pre-registration processing, eligibility determination, communication, and DepEd statistical reporting.</p>
                                    <h4><i class="fas fa-database"></i> Storage</h4>
                                    <p>Secured database; access restricted to authorized ALS personnel only, per DepEd retention policy.</p>
                                    <h4><i class="fas fa-user-shield"></i> Your Rights</h4>
                                    <p>Under RA 10173, you may access, correct, and request erasure of your data, or file complaints with the National Privacy Commission.</p>
                                </div>
                                <label class="check-row privacy">
                                    <input type="checkbox" name="accept_terms_privacy_display" value="yes" id="privacy_checkbox" onchange="updateAgreementState()">
                                    <div class="check-row-text">
                                        <strong><i class="fas fa-shield-alt" style="color:var(--blue-500);margin-right:6px;"></i> I Accept the Data Privacy Agreement</strong>
                                        <span class="sub">I consent to the processing of my personal data under RA 10173. <span style="color:var(--error);">*</span></span>
                                    </div>
                                </label>
                            </div>
                            <div class="agreement-section">
                                <button type="button" class="agreement-toggle" id="toggle-program" onclick="toggleAgreement('program')">
                                    <div class="agreement-toggle-left">
                                        <div class="agreement-toggle-icon program"><i class="fas fa-graduation-cap"></i></div>
                                        <span>ALS Program Terms &amp; Conditions</span>
                                    </div>
                                    <i class="fas fa-chevron-down agreement-chevron" id="chevron-program"></i>
                                </button>
                                <div class="agreement-body" id="body-program">
                                    <h4><i class="fas fa-info-circle"></i> Pre-registration Is Not a Guarantee of Enrollment</h4>
                                    <p>Submitting does not automatically enroll me. My application is subject to review and slot availability.</p>
                                    <h4><i class="fas fa-file-check"></i> Accuracy</h4>
                                    <p>All information I provide is true. False information may result in rejection and legal liability.</p>
                                    <h4><i class="fas fa-folder-open"></i> Documentary Requirements</h4>
                                    <p>I must present original documents during actual enrollment.</p>
                                    <h4><i class="fas fa-calendar-check"></i> Attendance</h4>
                                    <p>I commit to attending sessions and completing all required ALS modules and assessments.</p>
                                </div>
                                <label class="check-row program">
                                    <input type="checkbox" name="accept_terms_program_display" value="yes" id="program_checkbox" onchange="updateAgreementState()">
                                    <div class="check-row-text">
                                        <strong><i class="fas fa-graduation-cap" style="color:#10b981;margin-right:6px;"></i> I Accept the ALS Program Terms &amp; Conditions</strong>
                                        <span class="sub">I agree to comply with all ALS program rules. <span style="color:var(--error);">*</span></span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- REVIEW SUMMARY -->
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-list-check"></i> Quick Review</div>
                            <div id="review-summary" style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:var(--radius);padding:18px 22px;font-size:.88rem;color:var(--text-mid);line-height:1.8;"></div>
                        </div>

                        <div class="step-nav">
                            <button type="button" class="btn-step-back" onclick="goStep(4)"><i class="fas fa-arrow-left"></i> Back</button>
                            <button type="submit" class="btn-submit" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit Pre-registration</button>
                        </div>
                        <div class="secure-note"><i class="fas fa-lock"></i> Your information is encrypted and securely protected under RA 10173</div>
                    </div><!-- /step-5 -->

                </form>
            </div>
        </div>

        <?php if ($preregistration_enabled && empty($disabled_message)):
            $remaining = max(0, ($daily_limit ?? 20) - ($daily_count ?? 0));
            $rem_class = $remaining > 5 ? 'ok' : ($remaining > 0 ? 'warn' : 'bad');
        ?>
        <div class="status-widget">
            <div class="status-widget-title"><i class="fas fa-chart-bar"></i> &nbsp;Registration Availability</div>
            <div class="status-stats">
                <div class="stat-item">
                    <div class="stat-value"><?php echo ($total_count ?? 0); ?> <span style="font-size:.9rem;opacity:.5;">/ <?php echo ($total_limit ?? 100); ?></span></div>
                    <div class="stat-label">Total Slots Used</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?php echo ($daily_count ?? 0); ?> <span style="font-size:.9rem;opacity:.5;">/ <?php echo ($daily_limit ?? 20); ?></span></div>
                    <div class="stat-label">Today's Registrations</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value <?php echo $rem_class; ?>"><?php echo $remaining; ?></div>
                    <div class="stat-label">Slots Remaining Today</div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

        <div class="page-footer">
            <p>Alternative Learning System &mdash; La Carlota City Division, DepEd Philippines</p>
            <p style="margin-top:6px;">
                <a href="/privacy">Privacy Policy</a><span class="sep">|</span>
                <a href="/contact">Contact Us</a><span class="sep">|</span>
                &copy; <?php echo date('Y'); ?> ALS La Carlota
            </p>
        </div>
    </div>
</div>

<!-- ═══════════════════════ TERMS MODAL ═══════════════════════ -->
<?php if ($show_terms_modal && !$success && empty($disabled_message)): ?>
<div id="terms-modal-overlay">
    <div id="terms-modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-header">
            <div class="modal-header-icon"><i class="fas fa-file-contract"></i></div>
            <div class="modal-header-text">
                <h2 id="modal-title">Before You Proceed — Terms &amp; Agreements</h2>
                <p>ALS Pre-registration · La Carlota City Division, DepEd Philippines</p>
                <div class="modal-visit-notice">
                    <i class="fas fa-info-circle"></i>
                    You must read and accept both agreements <strong>every time</strong> you visit this page.
                </div>
            </div>
        </div>
        <div class="modal-steps">
            <div class="modal-step-tab active" id="step-tab-1"><div class="step-num">1</div> Data Privacy Consent</div>
            <div class="modal-step-tab" id="step-tab-2"><div class="step-num">2</div> ALS Program Terms</div>
        </div>
        <div class="modal-body" id="modal-body-scroll">
            <div class="modal-pane active" id="modal-pane-1">
                <div class="modal-pane-title privacy">
                    <div class="pane-icon"><i class="fas fa-shield-alt"></i></div>
                    <div>Agreement 1 of 2 — Data Privacy Consent (RA 10173)</div>
                </div>
                <div class="modal-terms-body">
                    <p>I hereby give my <strong>free, voluntary, and informed consent</strong> to the ALS Division of La Carlota City to collect, use, store, and process my personal information under RA No. 10173.</p>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-user-circle" style="color:var(--blue-500);"></i></div><div class="titem-text"><strong>Personal Data Being Collected</strong><p>Full name, birth date, age, sex, contact, email, address, parent info, LRN, grade level, selfie, and valid ID or alternative document images.</p></div></div>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-bullseye" style="color:#8b5cf6;"></i></div><div class="titem-text"><strong>Purpose</strong><p>Identity verification, pre-registration processing, eligibility assessment, communication, and DepEd statistical reporting.</p></div></div>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-database" style="color:#f59e0b;"></i></div><div class="titem-text"><strong>Storage &amp; Retention</strong><p>Secured database; access limited to authorized ALS personnel, per DepEd records policy.</p></div></div>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-user-shield" style="color:#ef4444;"></i></div><div class="titem-text"><strong>Your Rights</strong><p>You may access, correct, or request erasure of your data, and file complaints with the National Privacy Commission.</p></div></div>
                </div>
                <div class="scroll-hint" id="scroll-hint-1"><span><i class="fas fa-arrow-down"></i> Scroll to read full agreement</span></div>
            </div>
            <div class="modal-pane" id="modal-pane-2">
                <div class="modal-pane-title program">
                    <div class="pane-icon"><i class="fas fa-graduation-cap"></i></div>
                    <div>Agreement 2 of 2 — ALS Program Terms &amp; Conditions</div>
                </div>
                <div class="modal-terms-body">
                    <p>I acknowledge and agree to the following terms governing my participation in the ALS program.</p>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-info-circle" style="color:var(--blue-500);"></i></div><div class="titem-text"><strong>Pre-registration Is Not a Guarantee</strong><p>Submitting this form does not automatically enroll me. My application is subject to review and slot availability.</p></div></div>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-file-check" style="color:#10b981;"></i></div><div class="titem-text"><strong>Accuracy of Information</strong><p>All information I provide is true. False information may result in rejection and legal liability.</p></div></div>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-folder-open" style="color:#f59e0b;"></i></div><div class="titem-text"><strong>Documentary Requirements</strong><p>I must present original documents during actual enrollment, or I may be denied.</p></div></div>
                    <div class="modal-terms-item"><div class="titem-icon"><i class="fas fa-calendar-check" style="color:#8b5cf6;"></i></div><div class="titem-text"><strong>Attendance &amp; Commitment</strong><p>I commit to attending sessions regularly and completing all required ALS assessments and modules.</p></div></div>
                </div>
                <div class="scroll-hint" id="scroll-hint-2"><span><i class="fas fa-arrow-down"></i> Scroll to read full agreement</span></div>
            </div>
        </div>
        <div class="modal-footer">
            <div class="modal-progress"><div class="modal-progress-bar" id="modal-progress-bar" style="width:50%"></div></div>
            <div id="modal-footer-1">
                <label class="modal-agree-row privacy">
                    <input type="checkbox" id="modal-check-privacy" onchange="onModalCheck()">
                    <div class="modal-agree-row-text">
                        <strong>I Accept the Data Privacy Agreement</strong>
                        <span class="sub">I have read Agreement 1 of 2 and voluntarily consent to data processing under RA 10173.</span>
                    </div>
                </label>
                <div class="modal-nav">
                    <button type="button" class="btn-modal-next" id="btn-modal-next-1" onclick="goToPane2()" disabled>Continue to Agreement 2 <i class="fas fa-arrow-right"></i></button>
                </div>
            </div>
            <div id="modal-footer-2" style="display:none;">
                <label class="modal-agree-row program">
                    <input type="checkbox" id="modal-check-program" onchange="onModalCheck2()">
                    <div class="modal-agree-row-text">
                        <strong>I Accept the ALS Program Terms &amp; Conditions</strong>
                        <span class="sub">I have read Agreement 2 of 2 and agree to comply with all ALS program rules.</span>
                    </div>
                </label>
                <div class="modal-nav">
                    <button type="button" class="btn-modal-back" onclick="goToPane1()"><i class="fas fa-arrow-left"></i> Back</button>
                    <button type="button" class="btn-modal-accept" id="btn-modal-accept" onclick="acceptAndClose()" disabled>
                        <i class="fas fa-check-circle"></i> Accept All &amp; Start Registration
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
/* ═══════════════════════════════════════════════════════════════
   STEP NAVIGATION — FIX #3: Per-step error isolation
═══════════════════════════════════════════════════════════════ */
const TOTAL_STEPS = 5;
let currentStep = <?php echo $error_step; ?>;

// PHP step errors passed to JS for stepper dot colouring
const phpStepErrors = {
    <?php for ($s = 1; $s <= 5; $s++): ?>
    <?php echo $s; ?>: <?php echo count($step_errors[$s]); ?>,
    <?php endfor; ?>
};

const stepHeaders = [null,
    {icon:'fa-user',        title:'Step 1 — Personal Information',    sub:'Fields marked with <span style="color:#fca5a5;">*</span> are required'},
    {icon:'fa-phone',       title:'Step 2 — Contact &amp; Address',   sub:'How should we reach you?'},
    {icon:'fa-users',       title:'Step 3 — Parent / Guardian',       sub:'Guardian information for minor applicants'},
    {icon:'fa-camera',      title:'Step 4 — Photo &amp; Identity',    sub:'Selfie capture and government ID (front &amp; back)'},
    {icon:'fa-list-check',  title:'Step 5 — Review &amp; Submit',     sub:'Verify agreements and submit your pre-registration'}
];

function goStep(target) {
    if (target < 1) target = 1;
    if (target > TOTAL_STEPS) target = TOTAL_STEPS;
    document.getElementById('step-' + currentStep)?.classList.remove('active');
    document.getElementById('step-' + target)?.classList.add('active');
    currentStep = target;
    updateStepper();
    const h = stepHeaders[target];
    if (h) {
        document.getElementById('section-icon').innerHTML = '<i class="fas ' + h.icon + '"></i>';
        document.getElementById('section-title').innerHTML = h.title;
        document.getElementById('section-sub').innerHTML = h.sub;
    }
    if (target === 5) buildReviewSummary();
    document.querySelector('.form-card')?.scrollIntoView({behavior:'smooth', block:'start'});
}

function updateStepper() {
    for (let i = 1; i <= TOTAL_STEPS; i++) {
        const si = document.getElementById('si-' + i);
        if (!si) continue;
        si.classList.remove('active','done','error');
        if (i === currentStep) { si.classList.add('active'); }
        else if (i < currentStep) {
            si.classList.add(phpStepErrors[i] > 0 ? 'error' : 'done');
        } else if (phpStepErrors[i] > 0) {
            si.classList.add('error');
        }
    }
    const fill = document.getElementById('stepper-fill');
    if (fill) fill.style.width = ((currentStep - 1) / (TOTAL_STEPS - 1) * 100) + '%';
}

/* ── FIX #3: Client-side per-step validation before advancing ── */
function validateAndGo(fromStep, toStep) {
    const errs = [];
    if (fromStep === 1) {
        const ln = document.getElementById('f_last_name')?.value.trim();
        const fn = document.getElementById('f_first_name')?.value.trim();
        const bd = document.getElementById('f_birthdate')?.value;
        const sx = document.querySelector('input[name="sex"]:checked')?.value;
        if (!ln) errs.push('Last name is required.');
        if (!fn) errs.push('First name is required.');
        if (!bd) {
            errs.push('Birth date is required.');
        } else {
            const age = calcAge(bd);
            if (age < 5 || age > 100) errs.push('Age must be between 5 and 100 years.');
        }
        if (!sx) errs.push('Please select your sex.');
        const lrn = document.getElementById('f_lrn')?.value.trim();
        if (lrn && !/^\d{12}$/.test(lrn)) errs.push('LRN must be a 12-digit number.');
    }
    if (fromStep === 2) {
        const cn = document.getElementById('f_contact_number')?.value.trim();
        const em = document.getElementById('f_email')?.value.trim();
        const bsv = document.getElementById('barangaySelect')?.value;
        if (!cn) errs.push('Contact number is required.');
        if (!em) errs.push('Email address is required.');
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) errs.push('Please enter a valid email address.');
        if (!bsv) errs.push('Please select your barangay.');
        if (bsv === 'other') {
            if (!document.getElementById('customBarangay')?.value.trim()) errs.push('Please enter your barangay name.');
            if (!document.getElementById('customCity')?.value.trim()) errs.push('Please enter your city or municipality.');
        }
    }
    if (fromStep === 3) {
        const pn = document.getElementById('f_parent_name')?.value.trim();
        const pc = document.getElementById('f_parent_contact')?.value.trim();
        if (!pn) errs.push('Parent/Guardian name is required.');
        if (!pc) errs.push('Parent/Guardian contact is required.');
    }
    if (fromStep === 4) {
        if (!document.getElementById('selfie_image_data')?.value) errs.push('Please capture your selfie photo.');
        const hasNoId = document.getElementById('has_no_valid_id_field')?.value === '1';
        if (hasNoId) {
            if (!document.getElementById('alt_document')?.files[0]) errs.push('Please upload your alternative document.');
        } else {
            if (!document.getElementById('valid_id_front')?.files[0]) errs.push('Please upload the FRONT of your government ID.');
            if (!document.getElementById('valid_id_back')?.files[0])  errs.push('Please upload the BACK of your government ID.');
        }
    }

    if (errs.length > 0) {
        showStepErrors(fromStep, errs);
        return;
    }
    clearStepErrors(fromStep);
    goStep(toStep);
}

function showStepErrors(step, errs) {
    // Show errors inside the current step (not a global banner bleeding across)
    let banner = document.getElementById('client-err-' + step);
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'client-err-' + step;
        banner.className = 'alert alert-error';
        banner.style.animation = 'panelIn .3s ease';
        const panel = document.getElementById('step-' + step);
        panel.insertBefore(banner, panel.firstChild);
    }
    banner.innerHTML = '<div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>' +
        '<div><strong>Please fix the following:</strong><ul>' +
        errs.map(e => '<li>' + e + '</li>').join('') + '</ul></div>';
    banner.scrollIntoView({behavior:'smooth', block:'center'});
    phpStepErrors[step] = errs.length;
    updateStepper();
}

function clearStepErrors(step) {
    const b = document.getElementById('client-err-' + step);
    if (b) b.remove();
    phpStepErrors[step] = 0;
    updateStepper();
}

function calcAge(dateStr) {
    const bd = new Date(dateStr), td = new Date();
    let a = td.getFullYear() - bd.getFullYear();
    const m = td.getMonth() - bd.getMonth();
    if (m < 0 || (m === 0 && td.getDate() < bd.getDate())) a--;
    return a;
}

document.addEventListener('DOMContentLoaded', function() {
    goStep(<?php echo $error_step; ?>);
    updateStepper();

    const sel = document.getElementById('barangaySelect');
    if (sel && sel.value === 'other') toggleCustomBarangay(sel);

    switchIdTab('valid-id');
    if (document.getElementById('terms-modal-overlay')) document.body.style.overflow = 'hidden';

    // FIX #4: Real-time field validation
    setupLiveValidation();
    setupPhoneFormatting();
    setupLRNCounter();
    setupAgeFeedback();

    <?php if (isset($_SESSION['uploaded_id_name']) && !empty($_SESSION['uploaded_id_name'])): ?>
    (function(){
        const pc = document.getElementById('front-preview-container'), dz = document.getElementById('dropZoneFront');
        if (pc) { pc.style.display='block'; } if (dz) dz.style.display='none';
        const pd = document.getElementById('front-preview-pdf');
        if (pd) { pd.style.display='block'; document.getElementById('front-preview-img').style.display='none';
            document.getElementById('front-pdf-name').textContent='<?php echo addslashes($_SESSION['uploaded_id_name']); ?>'; }
    })();
    <?php unset($_SESSION['uploaded_id_name']); endif; ?>
});

/* ═══════════════════════════════════════════════════════════════
   FIX #4: MODERN TECHNIQUES
═══════════════════════════════════════════════════════════════ */

// Real-time field validation with success/error states
function setupLiveValidation() {
    const rules = {
        f_last_name:  { required: true, minLen: 2 },
        f_first_name: { required: true, minLen: 2 },
        f_parent_name:{ required: true, minLen: 2 },
        f_email:      { required: true, type: 'email' },
    };

    for (const [id, rule] of Object.entries(rules)) {
        const el = document.getElementById(id);
        if (!el) continue;
        el.addEventListener('blur', () => liveValidateField(el, rule));
        el.addEventListener('input', () => {
            el.classList.remove('is-error','is-valid');
            const errEl = document.getElementById('err_' + id.replace('f_',''));
            if (errEl) errEl.classList.remove('show');
        });
    }
}

function liveValidateField(el, rule) {
    const val = el.value.trim();
    const id = el.id.replace('f_','');
    const errEl = document.getElementById('err_' + id);
    const okEl = document.getElementById('ok_' + id);
    let msg = '';
    if (rule.required && !val) msg = 'This field is required.';
    else if (rule.minLen && val.length < rule.minLen) msg = 'Must be at least ' + rule.minLen + ' characters.';
    else if (rule.type === 'email' && val && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) msg = 'Please enter a valid email address.';

    if (msg) {
        el.classList.add('is-error'); el.classList.remove('is-valid');
        if (errEl) { errEl.querySelector('span').textContent = msg; errEl.classList.add('show'); }
        if (okEl) okEl.classList.remove('show');
    } else if (val) {
        el.classList.add('is-valid'); el.classList.remove('is-error');
        if (errEl) errEl.classList.remove('show');
        if (okEl) okEl.classList.add('show');
    }
}

// Phone number auto-formatter: 09XX-XXX-XXXX
function setupPhoneFormatting() {
    ['f_contact_number','f_parent_contact'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('input', function() {
            let v = this.value.replace(/\D/g,'');
            if (v.length > 11) v = v.slice(0,11);
            if (v.length >= 5 && v.length <= 7) v = v.slice(0,4) + '-' + v.slice(4);
            else if (v.length > 7) v = v.slice(0,4) + '-' + v.slice(4,7) + '-' + v.slice(7);
            this.value = v;
        });
        el.addEventListener('blur', function() {
            const cleaned = this.value.replace(/\D/g,'');
            if (cleaned.length > 0 && cleaned.length !== 11) {
                this.classList.add('is-error');
                const errEl = document.getElementById('err_' + id.replace('f_',''));
                if (errEl) { errEl.querySelector('span').textContent = 'Must be an 11-digit phone number.'; errEl.classList.add('show'); }
            } else if (cleaned.length === 11) {
                this.classList.remove('is-error'); this.classList.add('is-valid');
                const errEl = document.getElementById('err_' + id.replace('f_',''));
                if (errEl) errEl.classList.remove('show');
            }
        });
    });
}

// LRN character counter
function setupLRNCounter() {
    const lrn = document.getElementById('f_lrn');
    const counter = document.getElementById('lrn_counter');
    if (!lrn || !counter) return;
    lrn.addEventListener('input', function() {
        const len = this.value.replace(/\D/g,'').length;
        this.value = this.value.replace(/\D/g,'');
        counter.style.display = 'block';
        counter.textContent = len + ' / 12 digits';
        counter.className = 'char-counter' + (len === 12 ? ' ok' : (len > 0 ? ' warn' : ''));
        if (len === 12) { this.classList.add('is-valid'); this.classList.remove('is-error'); }
        else if (len > 0) { this.classList.remove('is-valid','is-error'); }
    });
}

// Age live feedback under birthdate
function setupAgeFeedback() {
    const bd = document.getElementById('f_birthdate');
    const display = document.getElementById('age_display');
    if (!bd || !display) return;
    bd.addEventListener('change', function() {
        if (!this.value) { display.style.display='none'; return; }
        const age = calcAge(this.value);
        display.style.display = 'block';
        if (age < 5 || age > 100) {
            display.textContent = '⚠ Age: ' + age + ' — must be between 5 and 100.';
            display.style.color = 'var(--error)';
            this.classList.add('is-error');
        } else {
            display.textContent = '✓ Age computed: ' + age + ' years old';
            display.style.color = 'var(--success)';
            this.classList.remove('is-error');
            this.classList.add('is-valid');
        }
    });
    // Trigger if pre-filled
    if (bd.value) bd.dispatchEvent(new Event('change'));
}

/* ═══════════════════════════════════════════════════════════════
   REVIEW SUMMARY
═══════════════════════════════════════════════════════════════ */
function buildReviewSummary() {
    const form = document.getElementById('preregForm');
    if (!form) return;
    const get = k => (form.querySelector('[name="' + k + '"]')?.value || '').trim();
    const selfieOk = !!document.getElementById('selfie_image_data')?.value;
    const hasNoId = document.getElementById('has_no_valid_id_field')?.value === '1';
    const frontFile = document.getElementById('valid_id_front')?.files[0];
    const backFile  = document.getElementById('valid_id_back')?.files[0];
    const altFile   = document.getElementById('alt_document')?.files[0];
    const brgyEl = document.getElementById('barangaySelect');
    const brgyTxt = brgyEl?.value === 'other'
        ? (get('current_custom_barangay') + ', ' + get('current_city_custom'))
        : ((brgyEl?.options[brgyEl?.selectedIndex]?.text || '') + ', ' + get('current_city'));
    const rows = [
        ['<i class="fas fa-user"></i> Full Name', [get('last_name'), get('first_name'), get('middle_name')].filter(Boolean).join(', ')],
        ['<i class="fas fa-calendar"></i> Birthdate', get('birthdate')],
        ['<i class="fas fa-venus-mars"></i> Sex', get('sex') ? (get('sex').charAt(0).toUpperCase() + get('sex').slice(1)) : ''],
        ['<i class="fas fa-phone"></i> Contact', get('contact_number')],
        ['<i class="fas fa-envelope"></i> Email', get('email')],
        ['<i class="fas fa-map-marker-alt"></i> Address', brgyTxt],
        ['<i class="fas fa-users"></i> Guardian', get('parent_name') + (get('parent_contact') ? ' — ' + get('parent_contact') : '')],
        ['<i class="fas fa-camera"></i> Selfie', selfieOk
            ? '<span style="color:var(--success);font-weight:700;"><i class="fas fa-check-circle"></i> Captured</span>'
            : '<span style="color:var(--error);">Not captured yet</span>'],
        ['<i class="fas fa-id-card"></i> ID Front', frontFile
            ? '<span style="color:var(--success);font-weight:700;"><i class="fas fa-check-circle"></i> ' + frontFile.name + '</span>'
            : (hasNoId ? '—' : '<span style="color:var(--error);">Not uploaded</span>')],
        ['<i class="fas fa-id-card-alt"></i> ID Back', hasNoId ? '—' : (backFile
            ? '<span style="color:var(--success);font-weight:700;"><i class="fas fa-check-circle"></i> ' + backFile.name + '</span>'
            : '<span style="color:var(--error);">Not uploaded</span>')],
        ['<i class="fas fa-file-alt"></i> Alt Doc', hasNoId
            ? (altFile ? '<span style="color:var(--success);font-weight:700;"><i class="fas fa-check-circle"></i> ' + altFile.name + '</span>' : '<span style="color:var(--error);">Not uploaded</span>')
            : '—'],
    ];
    const container = document.getElementById('review-summary');
    if (!container) return;
    container.innerHTML = rows.map(([label, val]) =>
        '<div style="display:flex;gap:10px;padding:6px 0;border-bottom:1px solid var(--blue-100);">' +
        '<div style="min-width:130px;font-weight:600;color:var(--text-mid);font-size:.82rem;">' + label + '</div>' +
        '<div style="flex:1;color:var(--text);font-size:.84rem;">' + (val || '<span style="color:var(--text-muted);">—</span>') + '</div></div>'
    ).join('') +
    '<div style="margin-top:10px;font-size:.77rem;color:var(--text-muted);"><i class="fas fa-info-circle"></i> Review above. Go back to any step to make changes.</div>';
}

/* ═══════════════════════════════════════════════════════════════
   AGREEMENT ACCORDIONS
═══════════════════════════════════════════════════════════════ */
function toggleAgreement(which) {
    const body = document.getElementById('body-' + which);
    const toggle = document.getElementById('toggle-' + which);
    const chevron = document.getElementById('chevron-' + which);
    const open = body.classList.contains('open');
    body.classList.toggle('open', !open);
    toggle.classList.toggle('open', !open);
    if (chevron) chevron.style.transform = open ? '' : 'rotate(180deg)';
}

function updateAgreementState() {
    const p = document.getElementById('privacy_checkbox')?.checked || false;
    const g = document.getElementById('program_checkbox')?.checked || false;
    const hp = document.getElementById('hidden_accept_privacy');
    const hg = document.getElementById('hidden_accept_program');
    if (hp) hp.value = p ? 'yes' : 'no';
    if (hg) hg.value = g ? 'yes' : 'no';
}

/* ═══════════════════════════════════════════════════════════════
   FIX #1: BARANGAY TOGGLE (custom barangay + city)
═══════════════════════════════════════════════════════════════ */
function toggleCustomBarangay(select) {
    const cf = document.getElementById('customLocationFields');
    const ci = document.getElementById('customBarangay');
    const cc = document.getElementById('customCity');
    const lc = document.getElementById('lacarlota-city-display');

    if (select.value === 'other') {
        cf.classList.add('show');
        if (ci) ci.required = true;
        if (cc) cc.required = true;
        if (lc) lc.style.display = 'none';
        // Remove name from the barangay_id select so it doesn't POST a bad value
        select.setAttribute('name', '_brgy_id_disabled');
    } else {
        cf.classList.remove('show');
        if (ci) { ci.required = false; ci.value = ''; }
        if (cc) { cc.required = false; cc.value = ''; }
        if (lc) lc.style.display = '';
        select.setAttribute('name', 'current_barangay_id');
    }
}

/* ═══════════════════════════════════════════════════════════════
   ID TAB SWITCHER
═══════════════════════════════════════════════════════════════ */
function switchIdTab(tab) {
    const pV = document.getElementById('pane-valid-id'), pN = document.getElementById('pane-no-id');
    const tV = document.getElementById('tab-valid-id'),  tN = document.getElementById('tab-no-id');
    const hf = document.getElementById('has_no_valid_id_field');
    const vs = document.getElementById('valid_id_type_select'), as = document.getElementById('alt_doc_type_select');
    const vf = document.getElementById('valid_id_front'), vb = document.getElementById('valid_id_back'), ai = document.getElementById('alt_document');
    if (tab === 'valid-id') {
        pV.classList.add('active'); pN.classList.remove('active');
        tV.classList.add('active'); tN.classList.remove('active');
        hf.value = '0';
        vs.name = 'valid_id_type'; as.name = '_alt_doc_type_off';
        vf.name = 'valid_id_front'; vb.name = 'valid_id_back'; ai.name = '_alt_document_off';
    } else {
        pN.classList.add('active'); pV.classList.remove('active');
        tN.classList.add('active'); tV.classList.remove('active');
        hf.value = '1';
        as.name = 'alt_doc_type'; vs.name = '_valid_id_type_off';
        ai.name = 'alt_document'; vf.name = '_valid_id_front_off'; vb.name = '_valid_id_back_off';
    }
}

/* ═══════════════════════════════════════════════════════════════
   FIX #2: STRONGER ID IMAGE ANALYSIS (front + back)
═══════════════════════════════════════════════════════════════ */
function analyzeIDImage(img, sideLabel) {
    const W = img.naturalWidth, H = img.naturalHeight;
    const ratio = W / H;

    // Minimum resolution check
    if (W < 250 || H < 120) return { passed: false, score: 4,
        reason: sideLabel + ' image resolution too low (' + W + '×' + H + 'px). Use a higher-quality photo.',
        warnings: [] };

    // Must be landscape
    if (H > W * 1.05) return { passed: false, score: 8,
        reason: 'The ' + sideLabel + ' image is in portrait orientation. Rotate the card and re-take the photo in landscape.',
        warnings: [] };

    // Downscale for analysis
    const canvas = document.createElement('canvas');
    const MAX = 300;
    canvas.width = Math.min(W, MAX);
    canvas.height = Math.round(H * canvas.width / W);
    const ctx = canvas.getContext('2d');
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    const idata = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    const totalPx = canvas.width * canvas.height;

    let skinPx=0, brightPx=0, darkPx=0, graySum=0;
    const gm = [];
    for (let y=0;y<canvas.height;y++) {
        gm[y] = [];
        for (let x=0;x<canvas.width;x++) {
            const idx=(y*canvas.width+x)*4;
            const r=idata[idx], g=idata[idx+1], b=idata[idx+2];
            const gray = 0.299*r + 0.587*g + 0.114*b;
            gm[y][x] = gray; graySum += gray;
            if (gray>215) brightPx++;
            if (gray<25)  darkPx++;
            const Cb=-0.169*r-0.331*g+0.500*b+128, Cr=0.500*r-0.419*g-0.081*b+128;
            if (gray>50 && Cb>=82 && Cb<=132 && Cr>=135 && Cr<=170) skinPx++;
        }
    }

    // Edge density (Sobel)
    let edgePx = 0;
    for (let y=1;y<canvas.height-1;y++) {
        for (let x=1;x<canvas.width-1;x++) {
            const gx=-gm[y-1][x-1]+gm[y-1][x+1]-2*gm[y][x-1]+2*gm[y][x+1]-gm[y+1][x-1]+gm[y+1][x+1];
            const gy=-gm[y-1][x-1]-2*gm[y-1][x]-gm[y-1][x+1]+gm[y+1][x-1]+2*gm[y+1][x]+gm[y+1][x+1];
            if (Math.sqrt(gx*gx+gy*gy) > 24) edgePx++;
        }
    }

    // Colour entropy (measures variety typical of ID cards)
    const hist = new Uint32Array(64);
    for (let i=0;i<idata.length;i+=4) {
        const ri=Math.floor(idata[i]/64), gi=Math.floor(idata[i+1]/64), bi=Math.floor(idata[i+2]/64);
        hist[ri*16+gi*4+bi]++;
    }
    let entropy=0;
    for (let h of hist) { if (h>0) { const p=h/totalPx; entropy -= p*Math.log2(p); } }

    const skinRatio   = skinPx / totalPx;
    const edgeDensity = edgePx / totalPx;
    const avgGray     = graySum / totalPx;
    const brightRatio = brightPx / totalPx;
    const darkRatio   = darkPx / totalPx;

    // Hard rejections
    if (skinRatio > 0.48) return { passed: false, score: 10,
        reason: 'The ' + sideLabel + ' image appears to be a selfie or person photo (' + Math.round(skinRatio*100) + '% skin tones). Upload a flat, top-down photo of the card ONLY.',
        warnings: [] };
    if (edgeDensity < 0.015) return { passed: false, score: 6,
        reason: 'The ' + sideLabel + ' image appears blank or contains almost no detail. A real ID has text, borders, and a photo.',
        warnings: [] };
    if (brightRatio > 0.92) return { passed: false, score: 6,
        reason: 'The ' + sideLabel + ' image is overexposed (too bright). Reduce glare and retake.',
        warnings: [] };
    if (darkRatio > 0.82) return { passed: false, score: 6,
        reason: 'The ' + sideLabel + ' image is underexposed (too dark). Improve lighting and retake.',
        warnings: [] };
    if (entropy < 2.5) return { passed: false, score: 8,
        reason: 'The ' + sideLabel + ' image has very low colour variety. Upload a proper scan or clear photo of the ID card.',
        warnings: [] };

    // Scoring
    let score = 0, warnings = [];

    // Resolution score
    if (W >= 1200 && H >= 600) score += 22;
    else if (W >= 700 && H >= 350) score += 16;
    else { score += 9; warnings.push('Higher resolution photo recommended for ' + sideLabel + '.'); }

    // Aspect ratio check — standard card is ~1.585:1
    const knownRatios = [85.6/54.0, 125/88, 210/297, 297/210, 215.9/279.4];
    const rLabels = ['Credit-card (CR80)', 'Passport page', 'A4 Portrait', 'A4 Landscape', 'Letter size'];
    let ratioOk = false, ratioLabel = '';
    for (let i=0;i<knownRatios.length;i++) {
        if (Math.abs(ratio - knownRatios[i]) / knownRatios[i] <= 0.25) { ratioOk=true; ratioLabel=rLabels[i]; break; }
    }
    if (ratioOk)        { score += 26; }
    else if (ratio>=1.0){ score += 14; warnings.push('Unusual card proportions for ' + sideLabel + '. Ensure all corners are visible.'); }
    else                { score += 5;  warnings.push('Image proportions look wrong for ' + sideLabel + '. Re-take flat, landscape, close-up.'); }

    // Edge density
    if (edgeDensity >= 0.030 && edgeDensity <= 0.60) score += 24;
    else if (edgeDensity > 0.60) { score += 10; warnings.push('Very cluttered frame on ' + sideLabel + '. Use a plain background.'); }
    else { score += 8; warnings.push('Low detail on ' + sideLabel + '. Ensure the card is in focus.'); }

    // Entropy bonus (colour variety)
    if (entropy >= 4.0) score += 14;
    else if (entropy >= 3.0) score += 9;
    else { score += 4; warnings.push('Low colour variety on ' + sideLabel + ' — may indicate a blank or poor-quality image.'); }

    // Skin bonus
    if (skinRatio >= 0.02 && skinRatio <= 0.30) score += 10;
    else if (skinRatio < 0.02) score += 7;
    else { score += 3; warnings.push('Relatively high skin tones on ' + sideLabel + ' — ensure your hand is not in frame.'); }

    // Brightness
    if (avgGray >= 65 && avgGray <= 200) score += 4;
    else if (avgGray < 40) warnings.push(sideLabel + ' appears dark — improve lighting.');
    else warnings.push(sideLabel + ' appears overexposed — reduce glare.');

    score = Math.min(100, Math.max(0, Math.round(score)));
    return { passed: true, score, reason: '', warnings, label: ratioLabel };
}

function handleUpload(input, side) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { alert('File is too large. Maximum 5 MB.'); input.value=''; return; }

    // For alt document
    if (side === 'alt') {
        const pc = document.getElementById('alt-preview-container');
        const pi = document.getElementById('alt-preview-img');
        const pp = document.getElementById('alt-preview-pdf');
        const pn = document.getElementById('alt-pdf-name');
        const dz = document.getElementById('dropZoneAlt');
        if (pc) pc.style.display = 'block';
        if (dz) dz.style.display = 'none';
        if (file.type === 'application/pdf') {
            if (pi) pi.style.display='none';
            if (pp) { pp.style.display='block'; if (pn) pn.textContent=file.name; }
        } else {
            const r = new FileReader();
            r.onload = e => { if (pi) { pi.src=e.target.result; pi.style.display='block'; } if (pp) pp.style.display='none'; };
            r.readAsDataURL(file);
        }
        return;
    }

    const isFront = side === 'front';
    const sideLabel = isFront ? 'Front' : 'Back';
    const previewC = document.getElementById(side + '-preview-container');
    const previewI = document.getElementById(side + '-preview-img');
    const previewP = document.getElementById(side + '-preview-pdf');
    const pdfName  = document.getElementById(side + '-pdf-name');
    const dropZone = document.getElementById('dropZone' + (isFront ? 'Front' : 'Back'));
    const statusEl = document.getElementById(side + '-verify-status');

    if (file.type === 'application/pdf') {
        if (previewC) previewC.style.display='block';
        if (dropZone) dropZone.style.display='none';
        if (dropZone) dropZone.classList.add('has-file');
        if (previewI) previewI.style.display='none';
        if (previewP) { previewP.style.display='block'; if (pdfName) pdfName.textContent=file.name; }
        if (statusEl) { statusEl.className='id-verify-status checking'; statusEl.innerHTML='<i class="fas fa-file-pdf"></i> PDF uploaded. Server will verify this file.'; }
        return;
    }

    if (statusEl) { statusEl.className='id-verify-status checking'; statusEl.innerHTML='<i class="fas fa-spinner fa-spin-me"></i> Analyzing ' + sideLabel + ' image…'; }

    const reader = new FileReader();
    reader.onload = function(e) {
        const img = new Image();
        img.onerror = function() {
            input.value='';
            if (statusEl) { statusEl.className='id-verify-status fail'; statusEl.innerHTML='<i class="fas fa-times-circle"></i> Cannot read image. Upload a valid JPG or PNG.'; }
            if (dropZone) dropZone.style.display='block';
            if (previewC) previewC.style.display='none';
        };
        img.onload = function() {
            const result = analyzeIDImage(img, sideLabel);
            if (!result.passed) {
                input.value='';
                if (statusEl) statusEl.className='id-verify-status fail';
                if (statusEl) statusEl.innerHTML = buildSideBlockedBanner(sideLabel, result.reason);
                if (previewC) previewC.style.display='none';
                if (dropZone) { dropZone.style.display='block'; dropZone.classList.remove('has-file'); }
                return;
            }
            // Success
            if (previewC) previewC.style.display='block';
            if (dropZone) { dropZone.style.display='none'; dropZone.classList.add('has-file'); }
            if (previewI) { previewI.src=e.target.result; previewI.style.display='block'; }
            if (previewP) previewP.style.display='none';
            const hasW = result.warnings.length > 0;
            const cls  = hasW ? 'warn' : 'pass';
            const icon = hasW ? 'exclamation-triangle' : 'check-circle';
            const lbl  = hasW ? 'Accepted with warnings' : (sideLabel + ' verified ✓');
            const msg  = hasW ? result.warnings.join(' ') : ('Score: ' + result.score + '/100' + (result.label ? ' — ' + result.label : ''));
            const barC = result.score >= 70 ? 'high' : 'mid';
            if (statusEl) {
                statusEl.className = 'id-verify-status ' + cls;
                statusEl.innerHTML = '<div><strong>' + lbl + '</strong><br>' + msg +
                    '<div class="score-bar-wrap"><div class="score-bar ' + barC + '" style="width:' + result.score + '%"></div></div></div>';
            }
        };
        img.src = e.target.result;
    };
    reader.readAsDataURL(file);
}

function buildSideBlockedBanner(side, reason) {
    return '<div class="upload-blocked-banner"><div class="ban-icon"><i class="fas fa-ban"></i></div><div style="flex:1;">' +
        '<div class="ban-title">' + side + ' Rejected</div>' +
        '<div class="ban-body">' + reason + '</div>' +
        '<button type="button" class="ban-retry" onclick="retryUpload(\'' + side.toLowerCase() + '\')">' +
        '<i class="fas fa-redo"></i> Try a Different Photo</button></div></div>';
}

function retryUpload(side) {
    const inputId = side === 'front' ? 'valid_id_front' : 'valid_id_back';
    const dropId  = 'dropZone' + (side === 'front' ? 'Front' : 'Back');
    const input   = document.getElementById(inputId);
    const dropZone= document.getElementById(dropId);
    const statusEl= document.getElementById(side + '-verify-status');
    if (input) { input.value=''; }
    if (statusEl) { statusEl.className='id-verify-status'; statusEl.innerHTML=''; }
    if (dropZone) { dropZone.style.display='block'; dropZone.classList.remove('has-file'); }
    const pc = document.getElementById(side + '-preview-container');
    if (pc) pc.style.display='none';
    if (input) input.click();
}

function removeUpload(side) {
    if (side === 'alt') {
        const ai = document.getElementById('alt_document');
        if (ai) ai.value='';
        const pc = document.getElementById('alt-preview-container');
        if (pc) pc.style.display='none';
        const dz = document.getElementById('dropZoneAlt');
        if (dz) dz.style.display='block';
        return;
    }
    const inputId = side === 'front' ? 'valid_id_front' : 'valid_id_back';
    const dropId  = 'dropZone' + (side === 'front' ? 'Front' : 'Back');
    const fi = document.getElementById(inputId);
    if (fi) fi.value='';
    const pc = document.getElementById(side + '-preview-container');
    if (pc) pc.style.display='none';
    const dz = document.getElementById(dropId);
    if (dz) { dz.style.display='block'; dz.classList.remove('has-file'); }
    const st = document.getElementById(side + '-verify-status');
    if (st) { st.className='id-verify-status'; st.innerHTML=''; }
}

// Drag-and-drop for all zones
[['dropZoneFront','valid_id_front','front'],['dropZoneBack','valid_id_back','back'],['dropZoneAlt','alt_document','alt']].forEach(([zid, iid, side]) => {
    const zone = document.getElementById(zid);
    if (!zone) return;
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag-over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag-over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('drag-over');
        const f = e.dataTransfer.files[0];
        if (f) {
            const inp = document.getElementById(iid);
            const dt = new DataTransfer(); dt.items.add(f); inp.files = dt.files;
            handleUpload(inp, side);
        }
    });
});

/* ═══════════════════════════════════════════════════════════════
   WEBCAM
═══════════════════════════════════════════════════════════════ */
let mediaStream = null;
const video=document.getElementById('webcam-video'), canvas=document.getElementById('webcam-canvas'),
      preview=document.getElementById('selfie-preview'), overlay=document.getElementById('webcam-overlay');
const btnStart=document.getElementById('btn-start-camera'), btnCapture=document.getElementById('btn-capture'),
      btnRetake=document.getElementById('btn-retake'), statusEl2=document.getElementById('webcam-status'),
      selfieData=document.getElementById('selfie_image_data');

if (btnStart) btnStart.addEventListener('click', async () => {
    try {
        statusEl2.className='webcam-status';
        statusEl2.innerHTML='<i class="fas fa-spinner fa-spin-me"></i> Accessing camera…';
        mediaStream = await navigator.mediaDevices.getUserMedia({video:{width:{ideal:640},height:{ideal:480},facingMode:'user'},audio:false});
        video.srcObject = mediaStream;
        video.style.display='block'; canvas.style.display='none'; preview.style.display='none'; overlay.style.display='none';
        btnStart.style.display='none'; btnCapture.style.display='inline-flex';
        statusEl2.className='webcam-status ok';
        statusEl2.innerHTML='<i class="fas fa-circle"></i> Camera ready — position your face and click Capture';
    } catch(err) {
        statusEl2.className='webcam-status err';
        statusEl2.innerHTML='<i class="fas fa-exclamation-triangle"></i> Camera access denied. Please allow permission.';
    }
});

if (btnCapture) btnCapture.addEventListener('click', () => {
    const ctx=canvas.getContext('2d');
    canvas.width=video.videoWidth||320; canvas.height=video.videoHeight||240;
    ctx.drawImage(video,0,0,canvas.width,canvas.height);
    const dataURL=canvas.toDataURL('image/jpeg',0.85);
    selfieData.value=dataURL;
    preview.src=dataURL; preview.style.display='block'; video.style.display='none';
    if (mediaStream) { mediaStream.getTracks().forEach(t=>t.stop()); mediaStream=null; }
    btnCapture.style.display='none'; btnRetake.style.display='inline-flex';
    statusEl2.className='webcam-status ok';
    statusEl2.innerHTML='<i class="fas fa-check-circle"></i> Photo captured successfully!';
});

if (btnRetake) btnRetake.addEventListener('click', () => {
    selfieData.value=''; preview.src=''; preview.style.display='none';
    btnRetake.style.display='none'; btnCapture.style.display='none'; btnStart.style.display='inline-flex';
    overlay.style.display='flex';
    statusEl2.className='webcam-status';
    statusEl2.innerHTML='<i class="fas fa-circle-info"></i> Click "Open Camera" to begin';
});

function refreshCaptcha() {
    const img=document.getElementById('captcha-img'), btn=document.querySelector('.btn-refresh-captcha i');
    if (btn) btn.style.transform='rotate(180deg)';
    fetch('?captcha_refresh=1',{credentials:'same-origin'}).then(()=>{
        if (img) img.src='?captcha_img=1&t='+Date.now();
        const ci=document.getElementById('captcha_answer'); if (ci) ci.value='';
        if (btn) setTimeout(()=>btn.style.transform='',500);
    }).catch(()=>{ if (img) img.src='?captcha_img=1&t='+Date.now(); });
}

/* ═══════════════════════════════════════════════════════════════
   TERMS MODAL
═══════════════════════════════════════════════════════════════ */
const modalBody = document.getElementById('modal-body-scroll');
function checkScrollBottom(paneNum) {
    if (!modalBody) return;
    const atBottom = (modalBody.scrollTop + modalBody.clientHeight) >= (modalBody.scrollHeight - 80);
    if (atBottom) { const h=document.getElementById('scroll-hint-'+paneNum); if(h) h.style.opacity='0'; }
}
if (modalBody) {
    modalBody.addEventListener('scroll',()=>{
        const p1=document.getElementById('modal-pane-1')?.classList.contains('active');
        checkScrollBottom(p1?1:2);
    });
    setTimeout(()=>checkScrollBottom(1),400);
}
function onModalCheck() {
    const b=document.getElementById('btn-modal-next-1');
    if (b) b.disabled=!document.getElementById('modal-check-privacy')?.checked;
}
function onModalCheck2() {
    const b=document.getElementById('btn-modal-accept');
    if (b) b.disabled=!document.getElementById('modal-check-program')?.checked;
}
function goToPane1() {
    document.getElementById('modal-pane-1')?.classList.add('active');
    document.getElementById('modal-pane-2')?.classList.remove('active');
    document.getElementById('modal-footer-1').style.display='block';
    document.getElementById('modal-footer-2').style.display='none';
    document.getElementById('step-tab-1')?.classList.add('active');
    document.getElementById('step-tab-1')?.classList.remove('done');
    document.getElementById('step-tab-2')?.classList.remove('active');
    document.getElementById('modal-progress-bar').style.width='50%';
    if (modalBody) modalBody.scrollTop=0;
    setTimeout(()=>checkScrollBottom(1),200);
}
function goToPane2() {
    if (!document.getElementById('modal-check-privacy')?.checked) return;
    document.getElementById('modal-pane-1')?.classList.remove('active');
    document.getElementById('modal-pane-2')?.classList.add('active');
    document.getElementById('modal-footer-1').style.display='none';
    document.getElementById('modal-footer-2').style.display='block';
    document.getElementById('step-tab-1')?.classList.remove('active');
    document.getElementById('step-tab-1')?.classList.add('done');
    document.getElementById('step-tab-2')?.classList.add('active');
    document.getElementById('modal-progress-bar').style.width='100%';
    if (modalBody) modalBody.scrollTop=0;
    setTimeout(()=>checkScrollBottom(2),200);
}
function acceptAndClose() {
    if (!document.getElementById('modal-check-privacy')?.checked) return;
    if (!document.getElementById('modal-check-program')?.checked) return;
    document.getElementById('hidden_accept_privacy').value='yes';
    document.getElementById('hidden_accept_program').value='yes';
    const pc=document.getElementById('privacy_checkbox'); if(pc) pc.checked=true;
    const pg=document.getElementById('program_checkbox'); if(pg) pg.checked=true;
    updateAgreementState();
    const ov=document.getElementById('terms-modal-overlay');
    if (ov) { ov.classList.add('hiding'); setTimeout(()=>{ ov.style.display='none'; document.body.style.overflow=''; },300); }
}
document.getElementById('terms-modal-overlay')?.addEventListener('click', function(e) {
    if (e.target === this) {
        const modal=document.getElementById('terms-modal');
        if (modal) { modal.style.animation='none'; modal.offsetHeight; modal.style.animation='modalShake .4s ease'; }
    }
});

/* ═══════════════════════════════════════════════════════════════
   FORM SUBMIT GUARD
═══════════════════════════════════════════════════════════════ */
const form2 = document.getElementById('preregForm');
if (form2) {
    form2.addEventListener('submit', function(e) {
        updateAgreementState();
        const termsOk = document.getElementById('hidden_accept_privacy')?.value==='yes' &&
                        document.getElementById('hidden_accept_program')?.value==='yes';
        if (!termsOk) { e.preventDefault(); alert('⚠ Please accept both agreements before submitting.'); return; }
        if (!document.getElementById('selfie_image_data')?.value) { e.preventDefault(); alert('⚠ Please capture your selfie (Step 4).'); goStep(4); return; }
        const hasNoId = document.getElementById('has_no_valid_id_field')?.value==='1';
        if (hasNoId) {
            if (!document.getElementById('alt_document')?.files[0]) { e.preventDefault(); alert('⚠ Please upload your alternative document (Step 4).'); goStep(4); return; }
        } else {
            if (!document.getElementById('valid_id_front')?.files[0]) { e.preventDefault(); alert('⚠ Please upload the FRONT of your government ID (Step 4).'); goStep(4); return; }
            if (!document.getElementById('valid_id_back')?.files[0])  { e.preventDefault(); alert('⚠ Please upload the BACK of your government ID (Step 4).'); goStep(4); return; }
        }
        const captcha=document.getElementById('captcha_answer')?.value.trim();
        if (!captcha || captcha.length < 6) { e.preventDefault(); alert('⚠ Please enter the 6-character CAPTCHA code.'); document.getElementById('captcha_answer')?.focus(); return; }
    });
}
</script>

<style>
@keyframes modalShake{
    0%,100%{transform:translateY(0) scale(1)}
    15%{transform:translateX(-10px) scale(1.01)}30%{transform:translateX(10px) scale(1.01)}
    45%{transform:translateX(-7px)}60%{transform:translateX(7px)}
    75%{transform:translateX(-4px)}90%{transform:translateX(4px)}
}
/* FIX #4 live validation tick colour */
.field-success-check{color:var(--success);font-size:.85rem;position:absolute;right:12px;top:50%;transform:translateY(-50%);pointer-events:none;display:none;margin-top:11px}
.field-success-check.show{display:block}
</style>
</body>
</html>