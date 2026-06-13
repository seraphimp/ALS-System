<?php
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
require_once '../e-learning-web/config/database.php';

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // 1. Check Student Login
    $query = "SELECT * FROM students WHERE student_id = ? AND status IN ('active', 'enrolled')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $student = $result->fetch_assoc();

    if ($student) {
        if ($password === $username) {
            $_SESSION['user_id'] = $student['id'];
            $_SESSION['user_type'] = 'student';
            $_SESSION['full_name'] = $student['first_name'] . ' ' . $student['last_name'];
            $_SESSION['student_id'] = $student['student_id'];
            
            // ===== FIXED: Check for pending pre-tests using ALL learning strands =====
            $student_db_id = $student['id'];
            
            // Get ALL active learning strands (students study all strands)
            $strand_query = "SELECT strand_id, strand_number, title FROM learning_strands WHERE status = 'active'";
            $strands_result = $conn->query($strand_query);
            
            if (!$strands_result) {
                // If query fails, create a default pending check
                error_log("Learning strands query failed: " . $conn->error);
                header('Location: /StudentDashboard');
                exit();
            }
            
            $pending_pretests = [];
            
            while ($strand = $strands_result->fetch_assoc()) {
                $strand_id = $strand['strand_id'];
                
                // Check if there's an active pre-test for this strand
                $pretest_query = "SELECT assessment_id, title FROM assessments 
                                 WHERE strand_id = ? AND assessment_type = 'pre_test' 
                                 AND status = 'active'";
                $pretest_stmt = $conn->prepare($pretest_query);
                if ($pretest_stmt) {
                    $pretest_stmt->bind_param("i", $strand_id);
                    $pretest_stmt->execute();
                    $pretest_result = $pretest_stmt->get_result();
                    
                    while ($pretest = $pretest_result->fetch_assoc()) {
                        $assessment_id = $pretest['assessment_id'];
                        
                        // Check if student has already taken this pre-test
                        $taken_query = "SELECT result_id FROM assessment_results 
                                       WHERE assessment_id = ? AND student_id = ? 
                                       AND status = 'completed'";
                        $taken_stmt = $conn->prepare($taken_query);
                        if ($taken_stmt) {
                            $taken_stmt->bind_param("ii", $assessment_id, $student_db_id);
                            $taken_stmt->execute();
                            $taken_result = $taken_stmt->get_result();
                            
                            if ($taken_result->num_rows === 0) {
                                $pending_pretests[] = [
                                    'id' => $assessment_id,
                                    'title' => $pretest['title'],
                                    'strand_id' => $strand_id,
                                    'strand_number' => $strand['strand_number']
                                ];
                            }
                        }
                    }
                }
            }
            
            // If there are pending pre-tests, redirect to the first one
            if (!empty($pending_pretests)) {
                $_SESSION['pending_pretests'] = $pending_pretests;
                $_SESSION['current_pretest_index'] = 0;
                header('Location: /TakePreTest?id=' . $pending_pretests[0]['id']);
                exit();
            }
            
            // No pending pre-tests, go to dashboard
            header('Location: /StudentDashboard');
            exit();
        } else {
            $error_message = "Invalid Student ID or password";
        }
    } else {
        // 2. Check Teacher Login
        $query = "SELECT tc.*, t.full_name, t.teacher_id 
                  FROM teacher_credentials tc 
                  JOIN teachers t ON tc.teacher_id = t.teacher_id 
                  WHERE tc.username = ? AND t.status = 'active'";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $teacher = $result->fetch_assoc();

        if ($teacher && password_verify($password, $teacher['password_hash'])) {
            $update_query = "UPDATE teachers SET is_logged_in = 1, last_login = NOW() WHERE teacher_id = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("i", $teacher['teacher_id']);
            $update_stmt->execute();

            $_SESSION['user_id'] = $teacher['teacher_id'];
            $_SESSION['user_type'] = 'teacher';
            $_SESSION['full_name'] = $teacher['full_name'];
            $_SESSION['username'] = $teacher['username'];

            header('Location: /TeacherDashboard');
            exit();
        } else {
            $error_message = "Invalid Username or Password";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ALS System - Login</title>
  <link rel="icon" type="image/png" href="/logo">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    /* Your existing CSS remains exactly the same */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    :root {
      --primary: #2563eb;
      --primary-dark: #1d4ed8;
      --primary-light: #3b82f6;
      --secondary: #0d9488;
      --secondary-dark: #0f766e;
      --accent: #7c3aed;
      --light: #f8fafc;
      --dark: #0f172a;
      --gray: #64748b;
      --gray-light: #e2e8f0;
      --gray-lighter: #f1f5f9;
      --success: #10b981;
      --error: #ef4444;
      --warning: #f59e0b;
      --border-radius: 16px;
      --border-radius-lg: 24px;
      --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
      --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
      --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
      --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
      --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      --transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    }

    body {
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      background: linear-gradient(135deg, #0d9488 0%, #2563eb 50%, #7c3aed 100%);
      position: relative;
      overflow-x: hidden;
      padding: 20px;
    }

    .bg-shapes {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      overflow: hidden;
      z-index: 0;
    }

    .shape {
      position: absolute;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.08);
      backdrop-filter: blur(40px);
      animation: float 20s infinite ease-in-out;
    }

    .shape-1 {
      width: 400px;
      height: 400px;
      top: -150px;
      left: -150px;
      animation-delay: 0s;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.15), transparent);
    }

    .shape-2 {
      width: 300px;
      height: 300px;
      bottom: -100px;
      right: 5%;
      animation-delay: 4s;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.12), transparent);
    }

    .shape-3 {
      width: 250px;
      height: 250px;
      top: 15%;
      right: -80px;
      animation-delay: 8s;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1), transparent);
    }

    .shape-4 {
      width: 180px;
      height: 180px;
      bottom: 30%;
      left: 8%;
      animation-delay: 12s;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.1), transparent);
    }

    .shape-5 {
      width: 220px;
      height: 220px;
      top: 50%;
      left: 50%;
      animation-delay: 16s;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.08), transparent);
    }

    @keyframes float {
      0%, 100% { transform: translate(0, 0) rotate(0deg) scale(1); }
      33% { transform: translate(30px, -30px) rotate(120deg) scale(1.1); }
      66% { transform: translate(-20px, 20px) rotate(240deg) scale(0.9); }
    }

    .login-container {
      display: flex;
      width: 1100px;
      max-width: 95%;
      min-height: 650px;
      background: rgba(255, 255, 255, 0.98);
      backdrop-filter: blur(20px);
      border-radius: var(--border-radius-lg);
      box-shadow: var(--shadow-xl);
      overflow: hidden;
      position: relative;
      z-index: 1;
      border: 1px solid rgba(255, 255, 255, 0.5);
    }

    .login-left {
      flex: 1.1;
      background: linear-gradient(160deg, #0d9488 0%, #0f766e 30%, #2563eb 100%);
      color: #fff;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: 60px 50px;
      position: relative;
      overflow: hidden;
    }

    .login-left::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -50%;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle, rgba(255, 255, 255, 0.15) 0%, transparent 70%);
      animation: pulse 8s infinite ease-in-out;
    }

    @keyframes pulse {
      0%, 100% { opacity: 0.5; transform: scale(1); }
      50% { opacity: 0.8; transform: scale(1.1); }
    }

    .login-left::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100" preserveAspectRatio="none"><path d="M0,100 Q50,80 100,100 L100,100 L0,100 Z" fill="rgba(255,255,255,0.03)"/></svg>');
      background-size: cover;
      background-position: bottom;
    }

    .brand {
      position: relative;
      z-index: 1;
      text-align: center;
      animation: fadeInDown 0.8s ease-out;
    }

    @keyframes fadeInDown {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .logo {
      width: 110px;
      height: 110px;
      margin: 0 auto 24px;
      background: rgba(255, 255, 255, 0.12);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      backdrop-filter: blur(10px);
      border: 2px solid rgba(255, 255, 255, 0.25);
      box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
      transition: var(--transition);
    }

    .logo:hover {
      transform: scale(1.05) rotate(5deg);
      box-shadow: 0 12px 40px rgba(0, 0, 0, 0.15);
    }

    .logo img {
      max-width: 4rem;
      filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.1));
    }

    .brand h1 {
      font-size: 32px;
      font-weight: 800;
      margin-bottom: 12px;
      letter-spacing: -0.8px;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
      line-height: 1.2;
    }

    .brand p {
      font-size: 17px;
      opacity: 0.95;
      font-weight: 400;
      letter-spacing: 0.3px;
    }

    .illustration {
      position: relative;
      z-index: 1;
      text-align: center;
      margin-top: 40px;
      animation: fadeIn 1s ease-out 0.3s both;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .illustration img {
      max-width: 300px;
      filter: drop-shadow(0 15px 30px rgba(0, 0, 0, 0.15));
      animation: floatImage 6s infinite ease-in-out;
    }

    @keyframes floatImage {
      0%, 100% { transform: translateY(0px); }
      50% { transform: translateY(-15px); }
    }

    .features {
      position: relative;
      z-index: 1;
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 20px;
      margin-top: 50px;
      animation: fadeInUp 0.8s ease-out 0.5s both;
    }

    @keyframes fadeInUp {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .feature {
      text-align: center;
      padding: 20px 15px;
      background: rgba(255, 255, 255, 0.08);
      border-radius: 12px;
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.15);
      transition: var(--transition);
    }

    .feature:hover {
      background: rgba(255, 255, 255, 0.15);
      transform: translateY(-5px);
    }

    .feature i {
      font-size: 28px;
      margin-bottom: 12px;
      opacity: 0.95;
      display: block;
    }

    .feature p {
      font-size: 14px;
      opacity: 0.9;
      font-weight: 500;
      letter-spacing: 0.3px;
    }

    .login-right {
      flex: 1;
      padding: 60px 55px;
      display: flex;
      flex-direction: column;
      justify-content: center;
      background: #fff;
      overflow-y: auto;
      animation: fadeInRight 0.8s ease-out;
    }

    @keyframes fadeInRight {
      from { opacity: 0; transform: translateX(20px); }
      to { opacity: 1; transform: translateX(0); }
    }

    .login-header {
      margin-bottom: 40px;
    }

    .login-header h2 {
      font-size: 32px;
      color: var(--dark);
      margin-bottom: 10px;
      font-weight: 800;
      letter-spacing: -0.8px;
      background: linear-gradient(135deg, var(--secondary), var(--primary));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .login-header p {
      font-size: 16px;
      color: var(--gray);
      font-weight: 400;
      letter-spacing: 0.2px;
    }

    .login-form {
      width: 100%;
    }

    .form-group {
      margin-bottom: 28px;
      position: relative;
    }

    .form-group label {
      font-weight: 600;
      font-size: 14px;
      color: var(--dark);
      margin-bottom: 10px;
      display: block;
      letter-spacing: 0.3px;
    }

    .input-with-icon {
      position: relative;
    }

    .input-icon {
      position: absolute;
      left: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--gray);
      font-size: 18px;
      transition: var(--transition);
      pointer-events: none;
    }

    .form-group input {
      width: 100%;
      padding: 16px 18px 16px 52px;
      border: 2px solid var(--gray-light);
      border-radius: 14px;
      font-size: 15px;
      transition: var(--transition);
      background: var(--gray-lighter);
      font-weight: 400;
      color: var(--dark);
    }

    .form-group input:hover {
      border-color: var(--gray);
      background: #fff;
    }

    .form-group input:focus {
      border-color: var(--primary);
      background: #fff;
      box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.08);
      outline: none;
    }

    .form-group input:focus + .input-icon,
    .input-with-icon:has(input:focus) .input-icon {
      color: var(--primary);
      transform: translateY(-50%) scale(1.1);
    }

    .form-group input::placeholder {
      color: #94a3b8;
      font-weight: 400;
    }

    .password-toggle {
      position: absolute;
      right: 18px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--gray);
      cursor: pointer;
      font-size: 18px;
      transition: var(--transition);
      z-index: 2;
    }

    .password-toggle:hover {
      color: var(--primary);
    }

    .btn-login {
      width: 100%;
      padding: 18px;
      background: linear-gradient(135deg, var(--secondary) 0%, var(--primary) 100%);
      color: #fff;
      font-weight: 700;
      border: none;
      border-radius: 14px;
      font-size: 16px;
      cursor: pointer;
      transition: var(--transition);
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      margin-top: 15px;
      box-shadow: 0 8px 16px rgba(37, 99, 235, 0.25);
      letter-spacing: 0.5px;
      position: relative;
      overflow: hidden;
    }

    .btn-login::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s;
    }

    .btn-login:hover::before {
      left: 100%;
    }

    .btn-login:hover {
      transform: translateY(-3px);
      box-shadow: 0 12px 24px rgba(37, 99, 235, 0.35);
    }

    .btn-login:active {
      transform: translateY(-1px);
      box-shadow: 0 6px 12px rgba(37, 99, 235, 0.3);
    }

    .forgot-password {
      text-align: center;
      margin-top: 24px;
    }

    .forgot-password a {
      color: var(--primary);
      font-size: 14px;
      text-decoration: none;
      font-weight: 600;
      transition: var(--transition);
      position: relative;
      letter-spacing: 0.2px;
    }

    .forgot-password a::after {
      content: '';
      position: absolute;
      bottom: -2px;
      left: 0;
      width: 0;
      height: 2px;
      background: var(--primary);
      transition: width 0.3s;
    }

    .forgot-password a:hover::after {
      width: 100%;
    }

    .forgot-password a:hover {
      color: var(--primary-dark);
    }

    .error-message {
      color: var(--error);
      background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(239, 68, 68, 0.12));
      padding: 16px 18px;
      border-radius: 12px;
      margin-top: 24px;
      font-size: 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-left: 4px solid var(--error);
      animation: shake 0.5s ease-in-out, fadeIn 0.3s ease-out;
      font-weight: 500;
      box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15);
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-8px); }
      75% { transform: translateX(8px); }
    }

    .demo-credentials {
      margin-top: 36px;
      background: linear-gradient(135deg, #f8fafc, #f1f5f9);
      padding: 24px;
      border-radius: 14px;
      font-size: 14px;
      border: 2px solid var(--gray-light);
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
    }

    .demo-credentials:hover {
      box-shadow: var(--shadow);
      border-color: var(--primary-light);
    }

    .demo-credentials h3 {
      margin-bottom: 16px;
      font-size: 15px;
      color: var(--dark);
      display: flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    .demo-credentials h3 i {
      color: var(--primary);
      font-size: 18px;
    }

    .credential {
      margin-bottom: 12px;
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px;
      background: white;
      border-radius: 10px;
      transition: var(--transition);
      border: 1px solid transparent;
    }

    .credential:hover {
      transform: translateX(5px);
      border-color: var(--gray-light);
      box-shadow: var(--shadow-sm);
    }

    .credential:last-child {
      margin-bottom: 0;
    }

    .credential i {
      color: var(--success);
      font-size: 18px;
    }

    .user-type {
      display: inline-block;
      background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(16, 185, 129, 0.15));
      color: var(--success);
      padding: 4px 10px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 700;
      margin-right: 8px;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .credential span {
      color: var(--dark);
      font-weight: 500;
      font-family: 'Courier New', monospace;
      font-size: 13px;
    }

    .btn-login.loading {
      pointer-events: none;
      opacity: 0.7;
    }

    .btn-login.loading i {
      animation: spin 1s linear infinite;
    }

    @keyframes spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    @media (max-width: 1024px) {
      .login-container { width: 950px; }
      .login-left, .login-right { padding: 50px 40px; }
    }

    @media (max-width: 900px) {
      .login-container { flex-direction: column; min-height: auto; height: auto; }
      .login-left { padding: 50px 40px; flex: none; }
      .features { margin-top: 40px; gap: 15px; }
      .feature { padding: 16px 12px; }
      .illustration img { max-width: 240px; }
      .login-right { padding: 50px 40px; overflow-y: visible; }
    }

    @media (max-width: 480px) {
      body { padding: 10px; align-items: flex-start; }
      .login-container { max-width: 100%; margin: 20px 0; }
      .login-left, .login-right { padding: 40px 24px; }
      .brand h1 { font-size: 26px; }
      .brand p { font-size: 15px; }
      .login-header h2 { font-size: 26px; }
      .logo { width: 90px; height: 90px; }
      .logo img { max-width: 60px; }
      .features { gap: 12px; }
      .feature { padding: 14px 10px; }
      .feature i { font-size: 24px; margin-bottom: 8px; }
      .feature p { font-size: 13px; }
      .form-group { margin-bottom: 24px; }
      .demo-credentials { margin-top: 30px; padding: 20px; }
    }

    @media (max-height: 700px) and (max-width: 900px) {
      body { align-items: flex-start; }
      .login-container { margin: 20px 0; }
    }

    @media (prefers-reduced-motion: reduce) {
      *, *::before, *::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
      }
    }

    *:focus-visible {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
    }
  </style>
</head>
<body>
  <div class="bg-shapes">
    <div class="shape shape-1"></div>
    <div class="shape shape-2"></div>
    <div class="shape shape-3"></div>
    <div class="shape shape-4"></div>
    <div class="shape shape-5"></div>
  </div>

  <div class="login-container">
    <div class="login-left">
      <div class="brand">
        <div class="logo">
          <img src="/logo" alt="ALS Logo">
        </div>
        <h1>Alternative Learning System</h1>
        <p>Empowering Learners Through Technology</p>
      </div>
      <div class="illustration">
        <img src="/image" alt="ALS Learning Illustration" />
      </div>
      <div class="features">
        <div class="feature"><i class="fas fa-graduation-cap"></i><p>Quality Education</p></div>
        <div class="feature"><i class="fas fa-laptop"></i><p>Digital Learning</p></div>
        <div class="feature"><i class="fas fa-users"></i><p>Community Support</p></div>
      </div>
    </div>

    <div class="login-right">
      <div class="login-header">
        <h2>Welcome Back</h2>
        <p>Sign in to continue your learning journey</p>
      </div>

      <form class="login-form" method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="loginForm">
        <div class="form-group">
          <label for="username">Student ID / Username</label>
          <div class="input-with-icon">
            <i class="fas fa-user input-icon"></i>
            <input type="text" id="username" name="username" placeholder="Enter your ID or username" required autocomplete="username">
          </div>
        </div>

        <div class="form-group">
          <label for="password">Password</label>
          <div class="input-with-icon">
            <i class="fas fa-lock input-icon"></i>
            <input type="password" id="password" name="password" placeholder="Enter your password" required autocomplete="current-password">
            <i class="fas fa-eye password-toggle" id="passwordToggle"></i>
          </div>
        </div>

        <button type="submit" class="btn-login" id="loginBtn">
          <i class="fas fa-sign-in-alt"></i>
          Login to Account
        </button>

        <?php if ($error_message): ?>
          <div class="error-message" id="errorMessage">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo $error_message; ?></span>
          </div>
        <?php endif; ?>

        <div class="forgot-password">
          <a href="#">Forgot your password?</a>
        </div>

        <div class="demo-credentials">
          <h3><i class="fas fa-info-circle"></i> Demo Credentials</h3>
          <div class="credential">
            <i class="fas fa-user-graduate"></i>
            <span><span class="user-type">Student</span>ALS-2025-001 / ALS-2025-001</span>
          </div>
          <div class="credential">
            <i class="fas fa-chalkboard-teacher"></i>
            <span><span class="user-type">Teacher</span>aldrin123 / password123</span>
          </div>
        </div>
      </form>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const inputs = document.querySelectorAll('input');
      const loginForm = document.getElementById('loginForm');
      const loginBtn = document.getElementById('loginBtn');
      const passwordToggle = document.getElementById('passwordToggle');
      const passwordInput = document.getElementById('password');
      
      inputs.forEach(input => {
        input.addEventListener('focus', function() {
          this.parentElement.classList.add('focused');
        });
        
        input.addEventListener('blur', function() {
          if (!this.value) {
            this.parentElement.classList.remove('focused');
          }
        });
      });
      
      if (passwordToggle && passwordInput) {
        passwordToggle.addEventListener('click', function() {
          const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
          passwordInput.setAttribute('type', type);
          this.classList.toggle('fa-eye');
          this.classList.toggle('fa-eye-slash');
        });
      }
      
      loginForm.addEventListener('submit', function() {
        loginBtn.classList.add('loading');
        loginBtn.querySelector('i').classList.remove('fa-sign-in-alt');
        loginBtn.querySelector('i').classList.add('fa-spinner');
      });
      
      const errorMessage = document.getElementById('errorMessage');
      if (errorMessage) {
        setTimeout(() => {
          errorMessage.style.opacity = '0';
          errorMessage.style.transform = 'translateY(-10px)';
          errorMessage.style.transition = 'all 0.5s ease';
          setTimeout(() => {
            if (errorMessage.parentNode) {
              errorMessage.parentNode.removeChild(errorMessage);
            }
          }, 500);
        }, 5000);
      }
      
      const credentials = document.querySelectorAll('.credential');
      credentials.forEach(credential => {
        credential.style.cursor = 'pointer';
        credential.addEventListener('click', function() {
          const text = this.querySelector('span').textContent;
          const parts = text.split(' / ');
          if (parts.length === 2) {
            const username = parts[0].replace(/^(STUDENT|TEACHER)/, '').trim();
            const password = parts[1].trim();
            
            document.getElementById('username').value = username;
            document.getElementById('password').value = password;
            
            this.style.background = 'rgba(16, 185, 129, 0.1)';
            setTimeout(() => {
              this.style.background = 'white';
            }, 300);
          }
        });
      });
    });
  </script>
</body>
</html>