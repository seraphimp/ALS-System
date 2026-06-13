<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/als/admin-web/includes/db.php';
require_once __DIR__ . '/als/admin-web/includes/functions.php';

secure_session_start();

$error = '';
$preregistration = null;

// Check if access code is provided (from email link)
if (isset($_GET['code']) && isset($_GET['tracking'])) {
    $access_code = $_GET['code'];
    $tracking = $_GET['tracking'];
    
    $columns_result = $conn->query("SHOW COLUMNS FROM preregistrations");
    $columns = [];
    while ($col = $columns_result->fetch_assoc()) {
        $columns[] = $col['Field'];
    }
    
    if (in_array('access_code', $columns)) {
        $stmt = $conn->prepare("
            SELECT * FROM preregistrations 
            WHERE tracking_code = ? AND access_code = ? AND (status = 'approved' OR status = 'pending')
        ");
        $stmt->bind_param("ss", $tracking, $access_code);
    } else {
        $stmt = $conn->prepare("
            SELECT * FROM preregistrations 
            WHERE tracking_code = ? AND (status = 'approved' OR status = 'pending')
        ");
        $stmt->bind_param("s", $tracking);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $preregistration = $result->fetch_assoc();
        
        if ($preregistration['status'] === 'completed' || !empty($preregistration['converted_to_student_id'])) {
            $error = "This pre-registration has already been used for enrollment.";
        } else {
            $_SESSION['approved_preregistration'] = [
                'id' => $preregistration['preregistration_id'],
                'tracking_code' => $preregistration['tracking_code'],
                'first_name' => $preregistration['first_name'],
                'last_name' => $preregistration['last_name'],
                'email' => $preregistration['email']
            ];
            
            header("Location: /enrollment?pr=true&tracking=" . urlencode($tracking));
            exit();
        }
    } else {
        $error = "Invalid or expired access link. Please contact ALS office.";
    }
    $stmt->close();
}

// Handle manual tracking code submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tracking = trim($_POST['tracking_code'] ?? '');
    $email = trim($_POST['email'] ?? '');
    
    if (empty($tracking) || empty($email)) {
        $error = "Please enter both tracking code and email address.";
    } else {
        $check_used = $conn->prepare("SELECT status, converted_to_student_id FROM preregistrations WHERE tracking_code = ? AND email = ?");
        $check_used->bind_param("ss", $tracking, $email);
        $check_used->execute();
        $used_result = $check_used->get_result();
        
        if ($used_result->num_rows > 0) {
            $used_data = $used_result->fetch_assoc();
            
            if ($used_data['status'] === 'completed' || !empty($used_data['converted_to_student_id'])) {
                $error = "This pre-registration has already been used for enrollment.";
                $check_used->close();
            } else {
                $check_used->close();
                
                $stmt = $conn->prepare("
                    SELECT * FROM preregistrations 
                    WHERE tracking_code = ? AND email = ? AND (status = 'approved' OR status = 'pending')
                ");
                $stmt->bind_param("ss", $tracking, $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $preregistration = $result->fetch_assoc();
                    
                    $_SESSION['approved_preregistration'] = [
                        'id' => $preregistration['preregistration_id'],
                        'tracking_code' => $preregistration['tracking_code'],
                        'first_name' => $preregistration['first_name'],
                        'last_name' => $preregistration['last_name'],
                        'email' => $preregistration['email']
                    ];
                    
                    header("Location: /enrollment?pr=true&tracking=" . urlencode($tracking));
                    exit();
                } else {
                    $error = "Invalid tracking code or email. Please check your information.";
                }
                $stmt->close();
            }
        } else {
            $error = "No pre-registration found with these credentials.";
        }
    }
}

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'used') {
        $error = "This pre-registration has already been used for enrollment. Each tracking code can only be used once.";
    } elseif ($_GET['error'] === 'invalid') {
        $error = "Invalid tracking code or email. Please check your information.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Enrollment - ALS La Carlota</title>
    <link rel="icon" type="image/png" href="/als/logo/als-logo-removebg-preview.png">
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;500;600;700;800&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --sky-50:  #f0f9ff;
            --sky-100: #e0f2fe;
            --sky-200: #bae6fd;
            --sky-300: #7dd3fc;
            --sky-400: #38bdf8;
            --sky-500: #0ea5e9;
            --sky-600: #0284c7;
            --sky-700: #0369a1;
            --sky-800: #075985;

            --blue-500: #2563eb;
            --blue-600: #1d4ed8;
            --blue-700: #1e3a8a;

            --text:       #0f172a;
            --text-mid:   #334155;
            --text-light: #64748b;
            --text-muted: #94a3b8;
            --border:     #e2e8f0;
            --input-bg:   #f8fafc;
            --white:      #ffffff;

            --success: #10b981;
            --error:   #ef4444;

            --radius-sm: 8px;
            --radius:    14px;
            --radius-lg: 20px;
            --radius-xl: 28px;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            background: var(--sky-50);
            display: flex;
            flex-direction: column;
            position: relative;
            overflow-x: hidden;
        }

        /* ── Decorative background ── */
        .bg-deco {
            position: fixed;
            inset: 0;
            z-index: 0;
            pointer-events: none;
        }
        .bg-deco::before {
            content: '';
            position: absolute;
            top: -120px; left: -120px;
            width: 500px; height: 500px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(14,165,233,0.15) 0%, transparent 70%);
        }
        .bg-deco::after {
            content: '';
            position: absolute;
            bottom: -100px; right: -100px;
            width: 420px; height: 420px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(37,99,235,0.12) 0%, transparent 70%);
        }
        .bg-wave {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 340px;
            background: linear-gradient(160deg, var(--sky-600) 0%, var(--sky-500) 50%, var(--blue-500) 100%);
            clip-path: ellipse(110% 100% at 50% 0%);
            z-index: 0;
        }
        .bg-wave-dots {
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 340px;
            background-image: radial-gradient(rgba(255,255,255,0.12) 1.5px, transparent 1.5px);
            background-size: 28px 28px;
            clip-path: ellipse(110% 100% at 50% 0%);
            z-index: 0;
        }

        /* ── Layout ── */
        .page-wrap {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 100vh;
            padding: 0 20px 60px;
        }

        /* ── Top bar ── */
        .top-bar {
            width: 100%;
            max-width: 500px;
            padding: 32px 0 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0;
        }

        .logo-pill {
            display: inline-flex;
            align-items: center;
            gap: 12px;
            background: rgba(255,255,255,0.18);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 50px;
            padding: 8px 20px 8px 10px;
            margin-bottom: 24px;
            animation: fadeDown 0.5s ease both;
        }
        .logo-pill img {
            width: 40px; height: 40px;
            object-fit: contain;
            filter: drop-shadow(0 2px 6px rgba(0,0,0,0.15));
        }
        .logo-pill-text .org {
            font-family: 'Sora', sans-serif;
            font-size: 0.6rem;
            font-weight: 600;
            color: rgba(255,255,255,0.75);
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }
        .logo-pill-text .div {
            font-family: 'Sora', sans-serif;
            font-size: 0.8rem;
            font-weight: 700;
            color: white;
        }

        .hero-title {
            font-family: 'Sora', sans-serif;
            font-size: 1.85rem;
            font-weight: 800;
            color: white;
            text-align: center;
            line-height: 1.2;
            margin-bottom: 8px;
            animation: fadeDown 0.55s 0.05s ease both;
        }
        .hero-sub {
            font-size: 0.95rem;
            color: rgba(255,255,255,0.75);
            text-align: center;
            margin-bottom: 36px;
            animation: fadeDown 0.55s 0.1s ease both;
        }

        /* ── Card ── */
        .card {
            width: 100%;
            max-width: 500px;
            background: var(--white);
            border-radius: var(--radius-xl);
            box-shadow:
                0 0 0 1px rgba(14,165,233,0.08),
                0 20px 60px rgba(15,23,42,0.12),
                0 4px 16px rgba(14,165,233,0.08);
            overflow: hidden;
            animation: fadeUp 0.55s 0.15s ease both;
        }

        .card-header {
            padding: 26px 32px 22px;
            border-bottom: 1px solid var(--sky-100);
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .card-header-icon {
            width: 46px; height: 46px;
            background: linear-gradient(135deg, var(--sky-500), var(--sky-600));
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem; color: white;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(14,165,233,0.35);
        }
        .card-header-text h2 {
            font-family: 'Sora', sans-serif;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--text);
        }
        .card-header-text p {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .card-body {
            padding: 28px 32px 32px;
        }

        /* ── Alert ── */
        .alert {
            display: flex;
            gap: 12px;
            padding: 14px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 22px;
            font-size: 0.88rem;
            line-height: 1.5;
            animation: shake 0.4s ease;
        }
        .alert-icon {
            flex-shrink: 0;
            width: 32px; height: 32px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem;
        }
        .alert-error {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        .alert-error .alert-icon { background: #fee2e2; color: var(--error); }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%       { transform: translateX(-5px); }
            40%       { transform: translateX(5px); }
            60%       { transform: translateX(-3px); }
            80%       { transform: translateX(3px); }
        }

        /* ── Info notice ── */
        .info-notice {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            background: var(--sky-50);
            border: 1px solid var(--sky-200);
            border-left: 3px solid var(--sky-400);
            border-radius: var(--radius-sm);
            padding: 14px 16px;
            margin-bottom: 26px;
            font-size: 0.85rem;
            color: var(--sky-800);
            line-height: 1.55;
        }
        .info-notice i {
            color: var(--sky-500);
            margin-top: 2px;
            flex-shrink: 0;
        }

        /* ── Form fields ── */
        .form-group {
            margin-bottom: 18px;
        }
        .field-label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-mid);
            margin-bottom: 7px;
            letter-spacing: 0.01em;
        }
        .field-wrap {
            position: relative;
        }
        .field-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            font-size: 0.9rem;
            pointer-events: none;
            transition: color 0.2s;
        }
        .field-input {
            width: 100%;
            padding: 12px 16px 12px 42px;
            background: var(--input-bg);
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.93rem;
            color: var(--text);
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
        }
        .field-input:focus {
            outline: none;
            background: white;
            border-color: var(--sky-400);
            box-shadow: 0 0 0 3px rgba(14,165,233,0.12);
        }
        .field-input:focus ~ .field-icon,
        .field-wrap:focus-within .field-icon {
            color: var(--sky-500);
        }
        .field-input::placeholder { color: var(--text-muted); }

        /* ── Submit button ── */
        .btn-submit {
            width: 100%;
            padding: 14px 24px;
            background: linear-gradient(135deg, var(--sky-500), var(--sky-700));
            color: white;
            border: none;
            border-radius: var(--radius-sm);
            font-family: 'Sora', sans-serif;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            letter-spacing: 0.02em;
            box-shadow: 0 4px 16px rgba(14,165,233,0.35);
            margin-top: 8px;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, var(--sky-600), var(--sky-800));
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(14,165,233,0.4);
        }
        .btn-submit:active { transform: translateY(0); }

        /* ── Divider ── */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 24px 0;
        }
        .divider-line {
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        .divider-text {
            font-size: 0.78rem;
            color: var(--text-muted);
            font-weight: 500;
            white-space: nowrap;
        }

        /* ── Footer link ── */
        .footer-link {
            text-align: center;
        }
        .footer-link a {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--sky-600);
            font-size: 0.88rem;
            font-weight: 600;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: var(--radius-sm);
            border: 1.5px solid var(--sky-200);
            background: var(--sky-50);
            transition: all 0.2s;
        }
        .footer-link a:hover {
            background: var(--sky-100);
            border-color: var(--sky-300);
            color: var(--sky-700);
            transform: translateY(-1px);
        }

        /* ── Help section ── */
        .help-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 20px;
            justify-content: center;
        }
        .help-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.7);
            border: 1px solid rgba(14,165,233,0.2);
            border-radius: 20px;
            padding: 6px 14px;
            font-size: 0.75rem;
            color: var(--sky-700);
            font-weight: 500;
        }
        .help-chip i { color: var(--sky-500); font-size: 0.7rem; }

        /* ── Page footer ── */
        .page-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-muted);
            animation: fadeUp 0.55s 0.25s ease both;
        }
        .page-footer a {
            color: var(--sky-600);
            text-decoration: none;
            font-weight: 500;
        }
        .page-footer a:hover { text-decoration: underline; }
        .page-footer .sep { margin: 0 8px; }

        /* ── Animations ── */
        @keyframes fadeDown {
            from { opacity: 0; transform: translateY(-14px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── Responsive ── */
        @media (max-width: 480px) {
            .card-body { padding: 22px 20px 26px; }
            .card-header { padding: 20px 20px 18px; }
            .hero-title { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<div class="bg-deco"></div>
<div class="bg-wave"></div>
<div class="bg-wave-dots"></div>

<div class="page-wrap">
    <div class="top-bar">
        <!-- Logo pill -->
        <div class="logo-pill">
            <img src="/als/logo/als-logo-removebg-preview.png" alt="ALS Logo">
            <div class="logo-pill-text">
                <div class="org">DepEd Philippines</div>
                <div class="div">La Carlota City Division</div>
            </div>
        </div>

        <h1 class="hero-title">Enrollment Access</h1>
        <p class="hero-sub">Enter your tracking code to proceed to the enrollment form</p>
    </div>

    <!-- Card -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-icon">
                <i class="fas fa-key"></i>
            </div>
            <div class="card-header-text">
                <h2>Access Your Enrollment Form</h2>
                <p>Use your approved pre-registration credentials</p>
            </div>
        </div>

        <div class="card-body">

            <?php if ($error): ?>
            <div class="alert alert-error">
                <div class="alert-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
            <?php endif; ?>

            <div class="info-notice">
                <i class="fas fa-envelope-open-text"></i>
                <span>After your pre-registration is <strong>approved</strong>, you'll receive an email with your tracking code. Enter it below together with your email address.</span>
            </div>

            <form method="POST" novalidate>
                <div class="form-group">
                    <label class="field-label">Tracking Code</label>
                    <div class="field-wrap">
                        <input
                            class="field-input"
                            type="text"
                            name="tracking_code"
                            required
                            placeholder="e.g. PR-ABC123-20240315"
                            value="<?php echo isset($_GET['tracking']) ? htmlspecialchars($_GET['tracking']) : (isset($_POST['tracking_code']) ? htmlspecialchars($_POST['tracking_code']) : ''); ?>"
                            autocomplete="off"
                            spellcheck="false"
                            style="text-transform: uppercase; letter-spacing: 0.05em; font-family: 'Sora', monospace; font-weight: 600;">
                        <i class="fas fa-hashtag field-icon"></i>
                    </div>
                </div>

                <div class="form-group">
                    <label class="field-label">Email Address</label>
                    <div class="field-wrap">
                        <input
                            class="field-input"
                            type="email"
                            name="email"
                            required
                            placeholder="you@example.com"
                            value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        <i class="fas fa-envelope field-icon"></i>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-arrow-right-to-bracket"></i>
                    Proceed to Enrollment
                </button>
            </form>

            <div class="divider">
                <div class="divider-line"></div>
                <span class="divider-text">Not yet pre-registered?</span>
                <div class="divider-line"></div>
            </div>

            <div class="footer-link">
                <a href="/preregistration">
                    <i class="fas fa-file-signature"></i>
                    Start Pre-registration Here
                </a>
            </div>

        </div>
    </div>

    <!-- Help chips -->
    <div class="help-chips">
        <div class="help-chip"><i class="fas fa-phone"></i> (034) 460-XXXX</div>
        <div class="help-chip"><i class="fas fa-envelope"></i> als.lacarlota@deped.gov.ph</div>
        <div class="help-chip"><i class="fas fa-map-marker-alt"></i> La Carlota City Division</div>
    </div>

    <div class="page-footer">
        <p>Alternative Learning System &mdash; La Carlota City Division, DepEd Philippines</p>
        <p style="margin-top: 5px;">
            <a href="/privacy">Privacy Policy</a>
            <span class="sep">|</span>
            <a href="/contact">Contact Us</a>
            <span class="sep">|</span>
            &copy; <?php echo date('Y'); ?> ALS La Carlota
        </p>
    </div>
</div>

<script>
    // Auto-uppercase tracking code input
    document.querySelector('input[name="tracking_code"]').addEventListener('input', function() {
        const pos = this.selectionStart;
        this.value = this.value.toUpperCase();
        this.setSelectionRange(pos, pos);
    });
</script>
</body>
</html>