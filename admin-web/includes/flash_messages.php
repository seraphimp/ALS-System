<?php
// admin-web/includes/flash_messages.php

function display_flash_messages() {
    if (isset($_SESSION['success'])) {
        echo '<div class="alert alert-success">'.htmlspecialchars($_SESSION['success']).'</div>';
        unset($_SESSION['success']);
    }
    if (isset($_SESSION['error'])) {
        echo '<div class="alert alert-danger">'.htmlspecialchars($_SESSION['error']).'</div>';
        unset($_SESSION['error']);
    }
}