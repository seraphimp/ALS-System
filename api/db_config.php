<?php
$host = 'localhost';
$database = 'u751659586_alssystem';
$username = 'u751659586_als';
$password = 'Alsaldrin12398';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die(json_encode(['success' => false, 'message' => 'Database connection failed']));
}

$conn->set_charset('utf8mb4');
?>