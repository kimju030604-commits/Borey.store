<?php
session_start();

// If logged in, go to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}
// Otherwise redirect to login
header('Location: login.php');
exit;
