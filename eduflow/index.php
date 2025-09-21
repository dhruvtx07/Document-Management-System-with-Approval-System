<?php
require_once 'includes/functions.php';
require_once 'includes/db.php'; // For BASE_URL and session start

if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('/admin/dashboard.php');
    } elseif (isStudent()) {
        redirect('/student/dashboard.php');
    } else {
        // Should not happen if login logic is correct, but as a fallback
        redirect('/logout.php');
    }
} else {
    redirect('/login.php');
}
?>