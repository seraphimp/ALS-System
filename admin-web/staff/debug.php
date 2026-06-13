<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';

echo "<h2>Staff Login Diagnostic</h2>";

// Check if staff table exists
$result = $conn->query("SHOW TABLES LIKE 'staff'");
if ($result->num_rows === 0) {
    echo "<div style='color: red;'><strong>ERROR:</strong> Staff table doesn't exist!</div>";
    echo "<p>Run this SQL to create the table:</p>";
    echo "<pre>";
    echo "CREATE TABLE staff (
    staff_id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    role ENUM('registrar', 'admin') DEFAULT 'registrar',
    is_active BOOLEAN DEFAULT TRUE,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT,
    FOREIGN KEY (created_by) REFERENCES staff(staff_id)
);";
    echo "</pre>";
} else {
    echo "<div style='color: green;'><strong>✓ Staff table exists</strong></div>";
    
    // Check staff records
    $staff_result = $conn->query("SELECT COUNT(*) as count FROM staff");
    $staff_count = $staff_result->fetch_assoc()['count'];
    echo "<p>Total staff records: $staff_count</p>";
    
    if ($staff_count > 0) {
        echo "<h3>Staff Accounts:</h3>";
        $staff_list = $conn->query("SELECT staff_id, username, full_name, role, is_active FROM staff");
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Role</th><th>Active</th></tr>";
        while ($staff = $staff_list->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $staff['staff_id'] . "</td>";
            echo "<td>" . $staff['username'] . "</td>";
            echo "<td>" . $staff['full_name'] . "</td>";
            echo "<td>" . $staff['role'] . "</td>";
            echo "<td>" . ($staff['is_active'] ? 'Yes' : 'No') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div style='color: orange;'><strong>WARNING:</strong> No staff accounts found!</div>";
    }
}

// Check if 'registrar' user exists
$user_result = $conn->query("SELECT * FROM staff WHERE username = 'registrar'");
if ($user_result->num_rows === 0) {
    echo "<div style='color: red;'><strong>ERROR:</strong> 'registrar' user doesn't exist!</div>";
    
    // Generate password hash
    $password = 'registrar123';
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    echo "<p>To create the registrar account, run this SQL:</p>";
    echo "<pre>";
    echo "INSERT INTO staff (username, password_hash, full_name, email, role) VALUES ('registrar', '" . $hashed_password . "', 'Registrar User', 'registrar@als.edu.ph', 'registrar');";
    echo "</pre>";
    
    echo "<p>Password: <strong>$password</strong></p>";
    echo "<p>Generated hash: <code>$hashed_password</code></p>";
} else {
    echo "<div style='color: green;'><strong>✓ 'registrar' user exists</strong></div>";
    
    $user = $user_result->fetch_assoc();
    echo "<h3>Registrar Account Details:</h3>";
    echo "<pre>";
    print_r($user);
    echo "</pre>";
    
    // Test password verification
    $password = 'registrar123';
    $stored_hash = $user['password_hash'];
    
    echo "<h3>Password Verification Test:</h3>";
    echo "<p>Testing password: <strong>$password</strong></p>";
    echo "<p>Stored hash: <code>$stored_hash</code></p>";
    
    if (password_verify($password, $stored_hash)) {
        echo "<div style='color: green;'><strong>✓ Password verification SUCCESSFUL!</strong></div>";
    } else {
        echo "<div style='color: red;'><strong>✗ Password verification FAILED!</strong></div>";
        echo "<p>Try creating a new hash:</p>";
        $new_hash = password_hash($password, PASSWORD_DEFAULT);
        echo "<p>New hash: <code>$new_hash</code></p>";
        echo "<p>SQL to update password:</p>";
        echo "<pre>UPDATE staff SET password_hash = '$new_hash' WHERE username = 'registrar';</pre>";
    }
}

// Check session configuration
echo "<h3>Session Configuration:</h3>";
echo "<pre>";
print_r(session_status());
echo "</pre>";

echo "<h3>PHP Info:</h3>";
echo "<pre>";
echo "PHP Version: " . phpversion() . "\n";
echo "Session Save Path: " . session_save_path() . "\n";
echo "Session Status: " . session_status() . "\n";
echo "</pre>";

echo "<h3>Quick Fixes:</h3>";
echo "<ol>";
echo "<li><a href='create_account.php' target='_blank'>Create a new staff account</a></li>";
echo "<li><a href='reset_password.php?username=registrar' target='_blank'>Reset registrar password</a></li>";
echo "<li><a href='test_login.php' target='_blank'>Test login directly</a></li>";
echo "</ol>";
?>