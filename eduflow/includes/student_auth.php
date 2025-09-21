<?php
// student_auth.php
// This file should be included at the top of every student-only page

// IMPORTANT: Remove the conditional session_start() here.
// db.php (included next) will handle session_start().
// if (session_status() == PHP_SESSION_NONE) { session_start(); } // REMOVE THIS LINE

// Adjust the path to functions.php and db.php (for BASE_URL if not already defined) as necessary
// Assuming student_auth.php is in a directory like /includes/ or at the root of /student/
require_once dirname(__DIR__) . '/includes/db.php'; // db.php now starts session, so good.
require_once dirname(__DIR__) . '/includes/functions.php'; // For functions like isStudent(), set_flash_message(), redirect(), getCurrentUserId(), etc.


if (!isStudent()) {
    set_flash_message('You must be logged in as a student to access this page.', 'danger');
    redirect('/login.php');
}

// Ensure clg_id is set for student operations, similar to admin
// This is important as students are also associated with a specific college.
if (!isset($_SESSION['clg_id'])) {
    set_flash_message('Student College ID not set. Please re-login or contact support.', 'danger');
    // Log them out to force re-authentication with correct role and college assignment
    redirect('/logout.php');
}

// NEW: Ensure class_id is set for student operations.
// This is important for context and for features that might filter by class.
if (!isset($_SESSION['class_id'])) {
    set_flash_message('Student Class ID not set. Please re-login or contact support.', 'danger');
    // Log them out to force re-authentication with correct data
    redirect('/logout.php');
}

// $_SESSION['uid'] is checked by isLoggedIn() within isStudent(), so explicit check isn't strictly necessary here.
?>