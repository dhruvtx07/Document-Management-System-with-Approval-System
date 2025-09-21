
<?php
// admin_auth.php
// This file should be included at the top of every admin-only page
// IMPORTANT: Remove the conditional session_start() here.
// The main entry point file (e.g., admin/dashboard.php) should include db.php first,
// which will handle session_start().
// if (session_status() == PHP_SESSION_NONE) { session_start(); } // REMOVE THIS LINE

// Ensure db.php is required first if this file is the initial script to run
// on an admin page that relies on the DB connection and session.
require_once dirname(__DIR__) . '/includes/db.php'; // Add this line if not already present
require_once dirname(__DIR__) . '/includes/functions.php'; // Adjust path as necessary

if (!isAdmin()) {
    set_flash_message('You must be logged in as an admin to access this page.', 'danger');
    redirect('login.php'); // Changed from /login.php to login.php
}
// Ensure clg_id is set for admin operations
if (!isset($_SESSION['clg_id'])) {
    set_flash_message('Admin College ID not set. Please re-login.', 'danger');
    // Potentially log them out or redirect to a specific error page
    redirect('logout.php'); // Changed from /logout.php to logout.php
}
?>