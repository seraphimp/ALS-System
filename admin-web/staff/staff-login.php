<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();

// Redirect if already logged in
if (is_staff_logged_in()) {
    header('Location: dashboard.php');
    exit();
}

$page_title = "ALS Enrollment System - Staff Login";
$error = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = "Please enter username and password";
    } else {
        // Authenticate staff
        $query = "SELECT staff_id, username, password_hash, full_name, role, is_active 
                  FROM staff 
                  WHERE username = ?";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $staff = $result->fetch_assoc();
            
            // Check if account is active
            if (!$staff['is_active']) {
                $error = "Account is inactive. Please contact administrator.";
            }
            // Verify password
            elseif (password_verify($password, $staff['password_hash'])) {
                // Update last login
                $updateStmt = $conn->prepare("UPDATE staff SET last_login = NOW() WHERE staff_id = ?");
                $updateStmt->bind_param("i", $staff['staff_id']);
                $updateStmt->execute();
                
                // Set session variables
                $_SESSION['staff_id'] = $staff['staff_id'];
                $_SESSION['staff_username'] = $staff['username'];
                $_SESSION['staff_full_name'] = $staff['full_name'];
                $_SESSION['staff_role'] = $staff['role'];
                
                // Redirect to dashboard
                header('Location: dashboard.php');
                exit();
            } else {
                $error = "Invalid username or password";
            }
        } else {
            $error = "Invalid username or password";
        }
    }
}

// If you need to create a default account, you can use this temporary code
// Remove this after creating accounts through database
if (isset($_GET['create_default'])) {
    $hashed_password = password_hash('registrar123', PASSWORD_DEFAULT);
    echo "<pre>Generated hash for 'registrar123': " . $hashed_password . "</pre>";
}
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
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background: linear-gradient(135deg, var(--light-blue) 0%, #ffffff 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
        }
        
        .login-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border-top: 5px solid var(--primary-color);
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--dark-blue) 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: white;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        
        .logo img {
            width: 60px;
            height: auto;
        }
        
        .login-header h1 {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .login-header p {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .login-body {
            padding: 2rem;
        }
        
        .alert {
            padding: 0.75rem;
            border-radius: 5px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-danger i {
            color: #dc3545;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #555;
        }
        
        .input-with-icon {
            position: relative;
        }
        
        .input-with-icon i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
        }
        
        .input-with-icon input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .input-with-icon input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(0, 86, 179, 0.25);
        }
        
        .btn {
            display: block;
            width: 100%;
            padding: 0.75rem;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background-color: var(--dark-blue);
        }
        
        .btn i {
            margin-right: 0.5rem;
        }
        
        .login-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
            color: #666;
            font-size: 0.9rem;
        }
        
        .back-to-home {
            display: inline-block;
            margin-top: 1rem;
            color: var(--primary-color);
            text-decoration: none;
        }
        
        .back-to-home:hover {
            text-decoration: underline;
        }
        
        .test-credentials {
            background-color: #e7f3ff;
            border: 1px solid #b3d9ff;
            border-radius: 5px;
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
        }
        
        .test-credentials h4 {
            margin: 0 0 0.5rem 0;
            color: var(--primary-color);
        }
        
        .test-credentials p {
            margin: 0.25rem 0;
        }
        
        @media (max-width: 480px) {
            .login-header {
                padding: 1.5rem;
            }
            
            .login-body {
                padding: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <img src="../../logo/als-logo-removebg-preview.png" alt="ALS Logo">
                </div>
                <h1>Alternative Learning System</h1>
                <p>La Carlota City Division - Staff Portal</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" id="loginForm">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <div class="input-with-icon">
                            <i class="fas fa-user"></i>
                            <input type="text" id="username" name="username" required 
                                   placeholder="Enter your username" value="registrar">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <div class="input-with-icon">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="password" name="password" required 
                                   placeholder="Enter your password" value="registrar123">
                        </div>
                    </div>
                    
                    <button type="submit" class="btn">
                        <i class="fas fa-sign-in-alt"></i> Sign In
                    </button>
                </form>
                
                <!-- Test Credentials (Remove in production) -->
                <div class="test-credentials">
                    <h4>Test Credentials:</h4>
                    <p><strong>Username:</strong> registrar</p>
                    <p><strong>Password:</strong> registrar123</p>
                    <p><small>Make sure the staff table exists and contains this user.</small></p>
                </div>
                
                <div class="login-footer">
                    <p>Staff & Registrar Access Only</p>
                    <a href="../../index.php" class="back-to-home">
                        <i class="fas fa-home"></i> Back to Home
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value.trim();
            
            if (!username || !password) {
                e.preventDefault();
                alert('Please fill in all fields');
            }
        });
        
        // Auto-focus the username field
        document.getElementById('username').focus();
    </script>
</body>
</html>