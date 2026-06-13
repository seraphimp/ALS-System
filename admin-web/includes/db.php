<?php
$db_host = 'localhost';                // always localhost on Hostinger
$db_user = 'u751659586_als';           // your db username
$db_pass = 'Alsaldrin12398';           // your db password
$db_name = 'u751659586_alssystem'; 

// Create connection with timeout handling
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

// Set charset
$conn->set_charset("utf8mb4");

// Increase timeout to prevent gone away errors
$conn->query("SET SESSION wait_timeout=300");
$conn->query("SET SESSION interactive_timeout=300");

// Check connection
if ($conn->connect_error) {
    error_log("Connection failed: " . $conn->connect_error);
    die("Database connection failed. Please try again later.");
}
?>