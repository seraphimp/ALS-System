<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php';

echo "<h2>Create Staff Account</h2>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $role = $_POST['role'] ?? 'registrar';
    
    if ($username && $password && $full_name) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        $query = "INSERT INTO staff (username, password_hash, full_name, email, role) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("sssss", $username, $hashed_password, $full_name, $email, $role);
        
        if ($stmt->execute()) {
            echo "<div style='color: green;'><strong>Account created successfully!</strong></div>";
            echo "<p>Username: $username</p>";
            echo "<p>Password: $password</p>";
            echo "<p>Hash: $hashed_password</p>";
        } else {
            echo "<div style='color: red;'><strong>Error creating account: " . $conn->error . "</strong></div>";
        }
    } else {
        echo "<div style='color: red;'><strong>Please fill in all required fields</strong></div>";
    }
}
?>

<form method="post" style="max-width: 400px; margin: 20px 0;">
    <div style="margin-bottom: 10px;">
        <label>Username:</label><br>
        <input type="text" name="username" value="registrar" required style="width: 100%; padding: 5px;">
    </div>
    
    <div style="margin-bottom: 10px;">
        <label>Password:</label><br>
        <input type="text" name="password" value="registrar123" required style="width: 100%; padding: 5px;">
    </div>
    
    <div style="margin-bottom: 10px;">
        <label>Full Name:</label><br>
        <input type="text" name="full_name" value="Registrar User" required style="width: 100%; padding: 5px;">
    </div>
    
    <div style="margin-bottom: 10px;">
        <label>Email:</label><br>
        <input type="email" name="email" value="registrar@als.edu.ph" style="width: 100%; padding: 5px;">
    </div>
    
    <div style="margin-bottom: 10px;">
        <label>Role:</label><br>
        <select name="role" style="width: 100%; padding: 5px;">
            <option value="registrar">Registrar</option>
            <option value="admin">Admin</option>
        </select>
    </div>
    
    <button type="submit" style="background: #0056b3; color: white; padding: 10px 20px; border: none; cursor: pointer;">
        Create Account
    </button>
</form>

<?php
// List existing accounts
$result = $conn->query("SELECT username, full_name, role, is_active FROM staff ORDER BY staff_id");
if ($result->num_rows > 0) {
    echo "<h3>Existing Accounts:</h3>";
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Username</th><th>Full Name</th><th>Role</th><th>Active</th></tr>";
    while ($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row['username'] . "</td>";
        echo "<td>" . $row['full_name'] . "</td>";
        echo "<td>" . $row['role'] . "</td>";
        echo "<td>" . ($row['is_active'] ? 'Yes' : 'No') . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>