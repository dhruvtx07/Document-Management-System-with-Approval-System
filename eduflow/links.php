<?php
// links.php

// This file defines URL variables for various pages and assets.
// It relies on the BASE_URL constant being defined elsewhere (e.g., in includes/db.php).
// If BASE_URL is not defined, it sets a default for internal use, though it should ideally come from db.php.
if (!defined('BASE_URL')) {
    // Fallback if BASE_URL constant is not defined by db.php.
    // Ensure this matches your actual project root path if db.php isn't always included before links.php.
    define('BASE_URL', '/eduflow/');
}

// Use the defined constant BASE_URL to construct all specific links
$base_url = BASE_URL;

// Original: $logo = $base_url . "logo.png"; // This seems to be a placeholder for a different logo scenario.
// New: Specific variable for the image path used in map_folders_to_students.php
$assets_img_logo = $base_url . "logo.png";

$logo = $base_url . "logo.png";


$login_page = $base_url . "login.php";
$logout_page = $base_url . "logout.php";
$register_page = $base_url . "register.php";
$forgot_pass = $base_url . "forgot_password.php"; // Changed to full path

$index_page = $base_url . "index.php";

// Admin Panel Links
$admin_home_page = $base_url . "admin/dashboard.php";
$admin_classes = $base_url . "admin/classes.php";
$admin_users = $base_url . "admin/users.php"; // General users page, can be used for Students/Admins
$admin_documents = $base_url . "admin/documents.php";
$admin_folders = $base_url . "admin/folders.php";
$admin_doc_folder_mapping = $base_url . "admin/map_docs_to_folder.php";
$admin_folder_student_mapping = $base_url . "admin/map_folders_to_students.php";
$admin_submissions = $base_url . "admin/submissions.php";

// Includes Paths (these are typically for 'require_once' and not for URLs, but kept for consistency)
// The actual require paths will use relative paths like '../includes/filename.php'
// These variables are primarily for documentation if you ever need to reference them as URLs.
$includes_admin_auth = $base_url . "includes/admin_auth.php";
$includes_functions = $base_url . "includes/functions.php";
$includes_db = $base_url . "includes/db.php";
$includes_header = $base_url . "includes/header.php";
$includes_footer = $base_url . "includes/footer.php";
$includes_student_auth = $base_url . "includes/student_auth.php";

// Student Panel Links
$student_home_page = $base_url . "student/dashboard.php";
$student_profile = $base_url . "student/profile.php";
$submit_documents = $base_url . "student/submit_document.php"; // Corrected typo in original ($submit_documents was submit_documents not submit_document)
// NEWLY ADDED for student dashboard.php links
$student_submission_history = $base_url . "student/submission_history.php";
$student_contact_admin = $base_url . "student/contact_admin.php";


// CSS file link
$css = $base_url . "css/style.css";

?>