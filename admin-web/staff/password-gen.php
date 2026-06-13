<?php
// Run this script once to generate the password hash for 'registrar123'
// Then copy the hash to use in your SQL insert statement

$password = 'registrar123';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

echo "Password: " . $password . "\n";
echo "Hashed Password: " . $hashed_password . "\n";
echo "\nSQL INSERT statement:\n";
echo "INSERT INTO staff (username, password_hash, full_name, email, role) \n";
echo "VALUES ('registrar', '" . $hashed_password . "', 'Registrar User', 'registrar@als.edu.ph', 'registrar');\n";

// Verify the hash
if (password_verify($password, $hashed_password)) {
    echo "\n✓ Password verification successful!\n";
} else {
    echo "\n✗ Password verification failed!\n";
}
?>