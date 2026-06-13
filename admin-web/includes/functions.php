<?php

function secure_session_start() {
    
    if (session_status() == PHP_SESSION_NONE) {
        $session_name = 'als_admin_session';
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'); 

        $httponly = true;
        
        ini_set('session.use_only_cookies', 1);
        $cookieParams = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => $cookieParams["lifetime"],
            'path' => '/',
            'domain' => $cookieParams["domain"],
            'secure' => $secure,
            'httponly' => $httponly,
            'samesite' => 'lax'
        ]);
        
        session_name($session_name);
        session_start();
        
      
        if (empty($_SESSION['initiated'])) {
            session_regenerate_id(true);
            $_SESSION['initiated'] = true;
        }
    }
}


function sanitize($input, $conn = null) {
    if ($conn) {
        return mysqli_real_escape_string($conn, htmlspecialchars(trim($input)));
    }
    return htmlspecialchars(trim($input));
}


function is_admin_logged_in() {
   
    if (!isset($_SESSION['admin_id'], $_SESSION['username'], $_SESSION['login_string'])) {
        return false;
    }
    
  
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    
    $expected_login_string = hash('sha256', $_SESSION['admin_id'] . $user_agent);
    
   
    return hash_equals($_SESSION['login_string'], $expected_login_string);
}


function verify_admin_login($username, $password, $conn) {
    $username = sanitize($username, $conn);
    
    $sql = "SELECT id, username, password, full_name FROM admins WHERE username = ? LIMIT 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($admin = mysqli_fetch_assoc($result)) {
        if (password_verify($password, $admin['password'])) {
            // Update last login
            $update_sql = "UPDATE admins SET last_login = NOW() WHERE id = ?";
            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $admin['id']);
            mysqli_stmt_execute($update_stmt);
            
            return $admin;
        }
    }
    return false;
}


function redirect($url) {
    if (!headers_sent()) {
        header("Location: $url");
        exit();
    } else {
     
        echo '<script>window.location.href="'.$url.'";</script>';
        exit();
    }
}


function clean_input($data) {
    $data = trim($data); // Remove extra spaces
    $data = stripslashes($data); // Remove backslashes
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); // Escape HTML
    return $data;
}

// ── FIXED: Updated log_action to use system_logs (and create it if needed) ──
function log_action($conn, $admin_id, $action_type, $description) {
    // Check if system_logs table exists, create if not
    $check_table = $conn->query("SHOW TABLES LIKE 'system_logs'");
    if ($check_table->num_rows == 0) {
        $create_sql = "
            CREATE TABLE IF NOT EXISTS `system_logs` (
                `log_id` INT(11) NOT NULL AUTO_INCREMENT,
                `user_id` INT(11) NOT NULL,
                `user_type` ENUM('admin', 'teacher') NOT NULL DEFAULT 'admin',
                `action` VARCHAR(255) NOT NULL,
                `description` TEXT DEFAULT NULL,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(255) DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`log_id`),
                INDEX `idx_user` (`user_id`, `user_type`),
                INDEX `idx_action` (`action`),
                INDEX `idx_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $conn->query($create_sql);
    }
    
    // Get client IP address
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    // Get user agent
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Determine user type
    $user_type = 'admin';
    if (isset($_SESSION['teacher_id'])) {
        $user_type = 'teacher';
    }
    
    $stmt = $conn->prepare("INSERT INTO system_logs (user_id, user_type, action, description, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param("isssss", $admin_id, $user_type, $action_type, $description, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();
}

function is_staff_logged_in() {
    return isset($_SESSION['staff_id']);
}
// Check if staff is logged in


// Get current staff ID
function get_current_staff_id() {
    return $_SESSION['staff_id'] ?? null;
}

// Get current staff name
function get_current_staff_name() {
    return $_SESSION['staff_full_name'] ?? 'Unknown';
}

// Get current staff role
function get_current_staff_role() {
    return $_SESSION['staff_role'] ?? 'guest';
}

// ── URL-safe AES-256-CBC encrypt / decrypt ────────────────────────────────
define('URL_ENC_KEY', 'YOUR_32_BYTE_SECRET_KEY_HERE!!!'); // exactly 32 chars

function encrypt_id(string $id): string {
    $iv  = random_bytes(16);
    $enc = openssl_encrypt($id, 'AES-256-CBC', URL_ENC_KEY, OPENSSL_RAW_DATA, $iv);
    return rtrim(strtr(base64_encode($iv . $enc), '+/', '-_'), '=');
}

function decrypt_id(string $token): string {
    $raw = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', 4 - strlen($token) % 4));
    if (strlen($raw) < 17) return '';
    $iv  = substr($raw, 0, 16);
    $enc = substr($raw, 16);
    return openssl_decrypt($enc, 'AES-256-CBC', URL_ENC_KEY, OPENSSL_RAW_DATA, $iv) ?: '';
}
?>