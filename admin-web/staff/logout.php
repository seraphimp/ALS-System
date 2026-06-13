<?php
require_once __DIR__ . '/../includes/functions.php';

secure_session_start();

// Unset all staff session variables
unset($_SESSION['staff_id']);
unset($_SESSION['staff_username']);
unset($_SESSION['staff_full_name']);
unset($_SESSION['staff_role']);

// Destroy the session
session_destroy();

// Redirect to login page
header('Location: login.php');
exit();
?>