<?php
require_once 'includes/functions.php';

secure_session_start();

// Unset all session values
$_SESSION = array();

// Destroy session
session_destroy();

// Redirect to login page
redirect('/admin-secure');
?>