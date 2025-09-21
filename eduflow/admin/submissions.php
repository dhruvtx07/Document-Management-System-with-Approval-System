<?php
// PHP error reporting for development - REMOVE IN PRODUCTION
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Ensure session is started BEFORE ANY OUTPUT
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/admin_auth.php';
require_once '../includes/db.php'; // Ensure db connection is available
require_once '../includes/functions.php'; // Explicitly include functions.php for formatSizeUnits() and other helpers.
require_once '../links.php'; // Include links.php for variable-based URLs

// Removed the following redundant BASE_URL definition.
// It is already defined in includes/db.php.
// if (!defined('BASE_URL')) {
//     define('BASE_URL', 'http://localhost/demo_docmg/'); // ADJUST THIS BASE_URL TO YOUR PROJECT'S ROOT URL
// }

$clg_id = getCurrentUserClgId();
$current_user_id = getCurrentUserId(); // Admin performing the review

$action = $_GET['action'] ?? 'list';
$submission_id_to_review = null;
$submission_data = null;

// --- Handle POST requests for Reviewing Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['review_submission'])) {
        $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
        $review_status = $_POST['review_status'] ?? ''; // 'y' for approved, 'n' for rejected
        $comments = trim($_POST['comments']);

        if (!$submission_id) {
            set_flash_message("Invalid submission ID.", "danger");
        } elseif (empty($review_status) || !in_array($review_status, ['y', 'n'])) {
            set_flash_message("Please select an approval status (Approved/Rejected).", "danger");
        } else {
            $sql_update_submission = "UPDATE Folder_docs_submitted_students 
                                    SET Is_accepted = ?, Comments = ?, Commented_by = ?, reviewed_at = NOW(), Status = ?
                                    WHERE folder_doc_submission_id = ? AND Clg_id = ?";
            $new_status_text = ($review_status === 'y') ? 'Approved' : 'Rejected';
            
            $stmt = prepare_and_execute($conn, $sql_update_submission, 
                [$review_status, $comments, $current_user_id, $new_status_text, $submission_id, $clg_id], 
                "ssisii"
            );

            if ($stmt->affected_rows > 0) {
                set_flash_message("Submission reviewed successfully.", "success");
                // Potentially send a notification to the student here
            } elseif(isset($stmt->error) && !empty($stmt->error)) { // Check if error property exists and is not empty
                set_flash_message("Error reviewing submission: " . $stmt->error, "danger");
            } else {
                set_flash_message("No changes made to submission status (it might already be in the selected state).", "info");
            }
            $stmt->close();
            
            // Redirect to the list, possibly with filters preserved
            // Build the redirect URL using existing GET parameters
            $query_params = $_GET;
            unset($query_params['action']); // Remove 'action' from GET params
            unset($query_params['id']);     // Remove 'id' from GET params
            $redirect_url = $admin_submissions; // Use variable from links.php
            if (!empty($query_params)) {
                $redirect_url .= "?" . http_build_query($query_params);
            }
            redirect($redirect_url);
        }
        // If validation fails, stay on review page (requires fetching submission data again)
        // The current (invalid) action remains 'review' due to the action variable holding its value
        // No explicit redirect, so the page re-renders, and the flash message displays.
    }
}

// --- Handle GET requests for Reviewing a specific submission ---
if ($action === 'review' && isset($_GET['id'])) {
    $submission_id_to_review = filter_var($_GET['id'], FILTER_VALIDATE_INT);
    if ($submission_id_to_review) {
        $sql_fetch_submission = "SELECT fdss.*, u_student.Name as StudentName, u_student.Email as StudentEmail,
                                    f.Folder_name, d.Doc_name, d.Doc_ext,
                                    u_commenter.Name as CommenterName
                               FROM Folder_docs_submitted_students fdss
                               JOIN Users u_student ON fdss.Student_id = u_student.Uid
                               JOIN Folder f ON fdss.Folder_id = f.Folder_id
                               JOIN doc d ON fdss.Doc_id = d.Doc_id
                               LEFT JOIN Users u_commenter ON fdss.Commented_by = u_commenter.Uid
                               WHERE fdss.folder_doc_submission_id = ? AND fdss.Clg_id = ?";
        $stmt_fetch = prepare_and_execute($conn, $sql_fetch_submission, [$submission_id_to_review, $clg_id], "ii");
        $submission_data = $stmt_fetch->get_result()->fetch_assoc();
        $stmt_fetch->close();

        if (!$submission_data) {
            set_flash_message("Submission not found or not accessible.", "danger");
            redirect($admin_submissions); // Use variable from links.php
        }
    } else {
        set_flash_message("Invalid Submission ID for review.", "danger");
        redirect($admin_submissions); // Use variable from links.php
    }
}

// --- Dynamic Navigation Active State ---
$active_nav_item = 'submissions'; // This page is always "View Submissions"

// --- Fetch data for filters ---
// Folders
$folders_stmt = prepare_and_execute($conn, "SELECT Folder_id, Folder_name FROM Folder WHERE Clg_id = ? AND Is_active = 'y' ORDER BY Folder_name", [$clg_id], "i");
$folders_result = $folders_stmt->get_result();
$all_folders = [];
while ($f_row = $folders_result->fetch_assoc()) {
    $all_folders[] = $f_row;
}
$folders_stmt->close();

// Students (who have submitted something)
$students_stmt = prepare_and_execute($conn, "SELECT DISTINCT u.Uid, u.Name FROM Users u JOIN Folder_docs_submitted_students fdss ON u.Uid = fdss.Student_id WHERE fdss.Clg_id = ? ORDER BY u.Name", [$clg_id], "i");
$students_result = $students_stmt->get_result();
$all_students = [];
while ($s_row = $students_result->fetch_assoc()) {
    $all_students[] = $s_row;
}
$students_stmt->close();


// --- Fetch Submissions for Listing ---
$filter_folder = $_GET['filter_folder'] ?? '';
$filter_student = $_GET['filter_student'] ?? ''; // Student's Uid
$filter_status = $_GET['filter_status'] ?? 'pending'; // Default to 'pending'
$search_term = $_GET['search'] ?? ''; // Search by student name, doc name, folder name

$sql_list_submissions = "SELECT fdss.folder_doc_submission_id, fdss.document_URL, fdss.file_name, fdss.file_type, fdss.file_size,
                                    fdss.Is_accepted, fdss.Status, fdss.submitted_at,
                                    u.Name as StudentName, u.Email as StudentEmail,
                                    f.Folder_name, d.Doc_name
                              FROM Folder_docs_submitted_students fdss
                              JOIN Users u ON fdss.Student_id = u.Uid
                              JOIN Folder f ON fdss.Folder_id = f.Folder_id
                              JOIN doc d ON fdss.Doc_id = d.Doc_id
                              WHERE fdss.Clg_id = ?";
$params_list = [$clg_id];
$types_list = "i";

if (!empty($filter_folder)) {
    $sql_list_submissions .= " AND fdss.Folder_id = ?";
    array_push($params_list, $filter_folder); $types_list .= "i";
}
if (!empty($filter_student)) {
    $sql_list_submissions .= " AND fdss.Student_id = ?";
    array_push($params_list, $filter_student); $types_list .= "i";
}
if ($filter_status !== 'all' && !empty($filter_status)) {
    $sql_list_submissions .= " AND fdss.Is_accepted = ?"; // 'y', 'n', 'pending'
    array_push($params_list, $filter_status); $types_list .= "s";
}
if (!empty($search_term)) {
    $like_search = "%{$search_term}%";
    $sql_list_submissions .= " AND (u.Name LIKE ? OR d.Doc_name LIKE ? OR f.Folder_name LIKE ?)";
    array_push($params_list, $like_search, $like_search, $like_search);
    $types_list .= "sss";
}

$sql_list_submissions .= " ORDER BY fdss.submitted_at DESC";
// Add pagination later if needed (as per original code, we'll omit for now to keep focus)

$stmt_list = prepare_and_execute($conn, $sql_list_submissions, $params_list, $types_list);
$submissions_list_result = $stmt_list->get_result();

$pageTitle = "Manage Student Submissions - EduFlow";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <!-- Bootstrap CSS 5.3.3 from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap-select CSS for Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">

    <!-- CUSTOM CSS (copied from map_docs_to_folder.php for consistency) -->
    <style>
        /* Custom Color Variables - Shades of Blue and White */
        :root {
            --edu-white: #ffffff;
            --edu-lightest-blue: #e0f2f7; /* Very light sky blue for background */
            --edu-light-blue: #add8e6; /* Light blue borders/accents */
            --edu-medium-blue: #87ceeb; /* Sky blue for secondary buttons/highlights */
            --edu-primary-blue: #007bff; /* Standard Bootstrap primary blue */
            --edu-dark-blue: #0056b3; /* Darker blue for hovers/active states */
            --edu-darkest-blue: #003366; /* Deep blue for brand/headings */
            --edu-secondary-text-color: #6c757d; /* Bootstrap's muted color */

            /* Colors for new buttons introduced in map_docs_to_folder.php */
            --edu-insert-color: #007bff; /* primary */
            --edu-update-color: #ffc107; /* warning */
            --edu-delete-color: #dc3545; /* danger - for deactivation */
            --edu-hard-delete-color: #bb2d3b; /* darker danger */
            --edu-select-all-color: #6c757d; /* secondary */
            --edu-activate-color: #28a745; /* success */
            --edu-mandate-color: #fd7e14; /* orange */
            --edu-unmandate-color: #6c757d; /* secondary - same as select-all */


            /* New variable for navbar height for sticky behavior */
            --edu-navbar-initial-height: 56px;
        }

        html {
            border-top: 5px solid var(--edu-light-blue);
        }

        body {
            background-color: var(--edu-lightest-blue);
            color: #333;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            padding-top: var(--edu-navbar-initial-height);
        }

        /* Navbar Styling */
        .edu-navbar {
            background-color: var(--edu-white) !important;
            border-bottom: 2px solid var(--edu-light-blue) !important;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030;
        }

        /* Logo in Navbar */
        .navbar-brand .navbar-logo {
            height: 40px;
            width: auto;
            margin-right: 10px;
            object-fit: contain;
        }

        .edu-brand {
            color: var(--edu-darkest-blue) !important;
            font-weight: bold;
            font-size: 1.75rem;
            transition: color 0.3s ease;
            display: flex;
            align-items: center;
        }

        .edu-brand:hover {
            color: var(--edu-dark-blue) !important;
        }

        .edu-nav-link {
            color: var(--edu-primary-blue) !important;
            font-weight: 500;
            margin-right: 15px;
            transition: color 0.3s ease, background-color 0.3s ease, border-radius 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem;
            white-space: nowrap;
        }

        .edu-nav-link:not(.active):hover {
            color: var(--edu-dark-blue) !important;
            background-color: transparent !important;
        }

        .edu-nav-link.active {
            color: var(--edu-white) !important;
            background-color: var(--edu-primary-blue) !important;
            border-radius: 2rem;
            padding: 0.5rem 1.25rem;
        }

        /* Custom Button Styles (Logout Button) */
        .btn-edu-secondary {
            background-color: var(--edu-medium-blue);
            border-color: var(--edu-medium-blue);
            color: var(--edu-white);
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease, border-radius 0.3s ease;
            border-radius: 0.5rem;
            padding: 0.5rem 1.25rem;
        }

        .btn-edu-secondary:hover {
            background-color: var(--edu-dark-blue);
            border-color: var(--edu-dark-blue);
            color: var(--edu-white);
        }

        .logout-btn {
            text-decoration: none;
        }

        /* Auth Card (reused for dashboard container) */
        .auth-card {
            background-color: var(--edu-white);
            border-radius: 0.75rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08);
            animation: fadeIn 0.5s ease-out;
            border: none;
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--edu-darkest-blue);
        }

        .text-primary {
            color: var(--edu-primary-blue) !important;
        }

        .text-muted {
            color: var(--edu-secondary-text-color) !important;
        }

        /* Custom container for width consistency */
        .container-fixed-width {
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding-left: var(--bs-gutter-x, 0.75rem);
            padding-right: var(--bs-gutter-x, 0.75rem);
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Utility classes for direct color application */
        .bg-edu-blue-4 { background-color: var(--edu-primary-blue) !important; }
        .text-edu-white { color: var(--edu-white) !important; }
        .text-edu-blue-6 { color: var(--edu-darkest-blue) !important; }
        .text-edu-primary-blue { color: var(--edu-primary-blue) !important; }


        /* Responsive adjustments */
        @media (max-width: 991.98px) { /* Applies to screens smaller than 992px (Bootstrap's lg breakpoint) */
            .navbar-toggler {
                border-color: var(--edu-light-blue);
            }
            .navbar-toggler-icon {
                background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%23007bff' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
            }
            .logout-btn {
                margin-left: 0;
                margin-top: 15px;
                width: 100%;
                border-radius: 0.25rem;
            }
            .edu-nav-link {
                width: 100%;
                text-align: center;
                margin-right: 0;
                border-radius: 0.25rem;
                padding: 0.5rem 1rem;
            }
            .edu-nav-link.active {
                border-radius: 0.25rem;
            }

            /* Adjust fixed-width container for smaller screens to let Bootstrap containers take over */
            .container-fixed-width {
                max-width: 100% !important;
                padding-top: var(--bs-navbar-padding-y);
                padding-bottom: var(--bs-navbar-padding-y);
            }

            /* Remove main navbar border-bottom on smaller screens */
            .edu-navbar {
                border-bottom: none !important;
            }

            /* Add border to the .container-fixed-width which now acts as the header in mobile */
            .container-fixed-width {
                border-bottom: 1px solid var(--edu-light-blue);
            }

            /* Padding for the navbar-collapse items when expanded */
            .navbar-collapse {
                padding-top: 0.5rem !important;
            }

            /* Specific padding for ul within .navbar-collapse for consistency */
            .navbar-collapse .navbar-nav {
                padding-top: 0.5rem;
                padding-bottom: 0.5rem;
            }

            /* Specific styles for "Manage Document Types" page */
            .page-header {
                display: flex;
                align-items: center;
                margin-bottom: 2rem;
                padding-left: 1rem;
                border-left: 5px solid var(--edu-primary-blue);
                flex-direction: column; /* Stack on small screens */
                align-items: flex-start; /* Align text left */
            }

            .page-header h1 {
                margin-bottom: 0;
                padding-left: 0.5rem;
                font-size: 1.75rem; /* Smaller font on mobile */
            }

            /* CRUD Buttons mobile adjustments */
            .crud-buttons {
                display: flex;
                flex-wrap: wrap; /* Allow buttons to wrap */
                justify-content: center;
                gap: 0.5rem; /* Space between buttons */
            }
            .crud-buttons .btn {
                /* On small screens, make buttons full width and remove side margins */
                width: 100%;
                margin-right: 0;
                margin-bottom: 0.5rem; /* Maintain vertical margin */
            }

            /* Search & Filter area */
            .search-area {
                flex-direction: column;
                align-items: stretch;
            }
            .search-area .input-group,
            .search-area .bootstrap-select {
                max-width: 100%;
                width: 100%; /* Force selectpickers to full width */
            }
            .search-area .btn-primary {
                width: 100%; /* Make search buttons full width on small screens */
            }
            .search-area .form-control {
                width: 100%; /* Ensure input fields take full width */
            }
        }

        /* Footer Styles */
        .footer {
            background-color: var(--edu-darkest-blue);
            color: var(--edu-white);
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto;
            flex-shrink: 0;
        }

        .footer p {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .footer a {
            color: var(--edu-light-blue);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer a:hover {
            color: var(--edu-white);
            text-decoration: underline;
        }

        /* Toast notifications container positioning */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1080;
        }

        .toast {
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border: none;
        }
        .toast .btn-close {
            filter: invert(1);
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }
        .toast.text-bg-warning .btn-close,
        .toast.text-bg-info .btn-close,
        .toast.text-bg-light .btn-close,
        .toast.text-bg-secondary .btn-close {
            filter: none;
            opacity: 0.8;
        }
        .toast .btn-close:hover {
            opacity: 1;
        }

        /* Specific styles for table (submissions list) */
        .table-striped-edu thead th {
            background-color: var(--edu-light-blue);
            color: var(--edu-darkest-blue);
            font-weight: bold;
            border-bottom: 2px solid var(--edu-medium-blue);
        }
        .table-striped-edu tbody tr:nth-of-type(odd) {
            background-color: rgba(0, 0, 0, 0.05); /* Lighter gray for odd rows */
        }
        .table-striped-edu tbody tr:nth-of-type(even) {
            background-color: var(--edu-white); /* White for even rows */
        }
        .table-striped-edu td, .table-striped-edu th {
            padding: 0.75rem;
            vertical-align: middle;
            border-top: 1px solid var(--edu-light-blue);
        }
        /* General button styles for actions */
        .btn-action-review {
            padding: 0.5rem 1rem;
            border-radius: 0.3rem;
            font-weight: 500;
            transition: all 0.2s ease-in-out;
            white-space: nowrap; /* Prevent button text from wrapping */
        }
        /* No specific edu-toggle classes needed here as it's not a toggle table/checkbox */
    </style>
</head>
<body>
    <!-- jQuery is required for Bootstrap-select, must be loaded FIRST in the <body> -->
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.5.1/jquery.min.js"></script>
    <!-- Bootstrap JS Bundle (includes Popper.js) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <!-- Bootstrap-select JS for Bootstrap 5 -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/js/bootstrap-select.min.js"></script>

    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light edu-navbar">
        <div class="container-fixed-width d-flex justify-content-between align-items-center">
            <!-- Brand Logo/Name -->
            <a class="navbar-brand edu-brand" href="<?= $admin_home_page ?>">
                <img src="<?= $logo ?>" alt="EduFlow Logo" class="navbar-logo">
                EduFlow
            </a>

            <!-- Navbar Toggler for small screens -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navigation Links -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'dashboard') ? 'active' : '' ?>" href="<?= $admin_home_page ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'documents') ? 'active' : '' ?>" href="<?= $admin_documents ?>">Manage Documents</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'folders') ? 'active' : '' ?>" href="<?= $admin_folders ?>">Manage Folders</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'classes') ? 'active' : '' ?>" href="<?= $admin_classes ?>">Manage Classes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'users') ? 'active' : '' ?>" href="<?= $admin_users ?>?role=student">Manage Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'submissions') ? 'active' : '' ?>" href="<?= $admin_submissions ?>">View Submissions</a>
                    </li>
                </ul>
                <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
                    <!-- Logout Button -->
                    <li class="nav-item ms-lg-3">
                        <form action="<?= $logout_page ?>" method="POST" class="d-inline">
                            <button type="submit" class="btn btn-edu-secondary logout-btn">Logout</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content area -->
    <main class="container-fluid py-4 flex-grow-1">
        <div class="card auth-card p-4 p-md-5">
            <div class="card-body">
                <!-- Page Header with blue bar -->
                <div class="page-header">
                    <h1>Manage Student Submissions</h1>
                </div>

                <!-- Toast Container (for displaying success/error/info messages) -->
                <div class="toast-container position-fixed top-0 end-0 p-3">
                    <?php
                    // Display flash messages set by PHP using the JS showToast function
                    if (isset($_SESSION['flash_message'])) {
                        $f_type = $_SESSION['flash_message_type'] ?? 'info';
                        $f_text = addslashes($_SESSION['flash_message']); // Escape quotes for JavaScript string
                        echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast('$f_type', '$f_text'); });</script>\n";
                        unset($_SESSION['flash_message']);
                        unset($_SESSION['flash_message_type']);
                    }
                    ?>
                </div>

                <?php if ($action === 'review' && $submission_data): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        Review Submission: <?php echo htmlspecialchars($submission_data['Doc_name']); ?>
                        by <?php echo htmlspecialchars($submission_data['StudentName']); ?>
                        for Folder: <?php echo htmlspecialchars($submission_data['Folder_name']); ?>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <p><strong>Student:</strong> <?php echo htmlspecialchars($submission_data['StudentName'] . " (" . $submission_data['StudentEmail'] . ")"); ?></p>
                                <p><strong>Folder:</strong> <?php echo htmlspecialchars($submission_data['Folder_name']); ?></p>
                                <p><strong>Document Type:</strong> <?php echo htmlspecialchars($submission_data['Doc_name']); ?></p>
                                <p><strong>Submitted At:</b> <?php echo date("Y-m-d H:i A", strtotime($submission_data['submitted_at'])); ?></p>
                                <p><strong>Current Status:</strong> 
                                    <span class="badge bg-<?php 
                                        if($submission_data['Is_accepted'] === 'y') echo 'success'; 
                                        elseif($submission_data['Is_accepted'] === 'n') echo 'danger'; 
                                        else echo 'warning text-dark'; 
                                    ?>">
                                        <?php echo ucfirst($submission_data['Is_accepted'] === 'y' ? 'Approved' : ($submission_data['Is_accepted'] === 'n' ? 'Rejected' : 'Pending')); ?>
                                    </span>
                                    <?php if ($submission_data['Status'] && $submission_data['Is_accepted'] !== 'pending'): echo ' (' . htmlspecialchars($submission_data['Status']) . ')'; endif; ?>
                                </p>
                                <?php if (!empty($submission_data['Comments'])): ?>
                                    <p><strong>Previous Comments (by <?php echo htmlspecialchars($submission_data['CommenterName'] ?? 'N/A'); ?>):</strong></p>
                                    <div class="alert alert-secondary"><?php echo nl2br(htmlspecialchars($submission_data['Comments'])); ?></div>
                                <?php endif; ?>

                                <p>
                                    <strong>Submitted File:</strong> 
                                    <a href="<?php echo BASE_URL . '/' . htmlspecialchars($submission_data['document_URL']); ?>" target="_blank" class="text-decoration-none">
                                        <i class="bi bi-file-earmark-fill"></i> <?php echo htmlspecialchars($submission_data['file_name'] ?: 'View/Download File'); ?>
                                    </a>
                                    (Type: <?php echo htmlspecialchars($submission_data['file_type'] ?? 'N/A'); ?>, 
                                    Size: <?php echo formatSizeUnits($submission_data['file_size'] ?? 0); ?>)
                                </p>
                                <hr>
                                <!-- The form keeps the existing GET params for filters -->
                                <form method="POST" action="<?= $admin_submissions ?>?<?php echo http_build_query($_GET); ?>">
                                    <input type="hidden" name="submission_id" value="<?php echo $submission_data['folder_doc_submission_id']; ?>">
                                    <div class="mb-3">
                                        <label class="form-label"><strong>New Review Status:</strong> <span class="text-danger">*</span></label><br>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="review_status" id="status_approved" value="y" required>
                                            <label class="form-check-label" for="status_approved">Approve</label>
                                        </div>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="radio" name="review_status" id="status_rejected" value="n" required>
                                            <label class="form-check-label" for="status_rejected">Reject</label>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label for="comments" class="form-label">Comments (Optional for Approve, Recommended for Reject)</label>
                                        <textarea class="form-control" id="comments" name="comments" rows="4"></textarea>
                                    </div>
                                    <button type="submit" name="review_submission" class="btn btn-primary">Submit Review</button>
                                    <!-- Use $_GET to preserve all filters on cancel -->
                                    <a href="<?= $admin_submissions ?>?<?php echo http_build_query(array_filter($_GET, function($k) { return !in_array($k, ['action', 'id']); }, ARRAY_FILTER_USE_KEY)); ?>" class="btn btn-secondary">Cancel / Back to List</a>
                                </form>

                            </div>
                            <div class="col-md-4">
                                <h5>File Preview (if applicable)</h5>
                                <?php
                                $file_url = BASE_URL . '/' . $submission_data['document_URL'];
                                $file_ext = strtolower(pathinfo($submission_data['document_URL'], PATHINFO_EXTENSION));
                                if (in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                    <img src="<?php echo htmlspecialchars($file_url); ?>" class="img-fluid rounded" alt="Preview">
                                <?php elseif ($file_ext === 'pdf'): ?>
                                    <iframe src="<?php echo htmlspecialchars($file_url); ?>" width="100%" height="300px" style="border: none;"></iframe>
                                <?php else: ?>
                                    <div class="alert alert-info text-center py-5">
                                        <i class="bi bi-file-earmark-text display-4 d-block mb-2"></i>
                                        <p>No preview available for this file type.</p>
                                        <a href="<?php echo htmlspecialchars($file_url); ?>" target="_blank" class="btn btn-sm btn-outline-primary"> <i class="bi bi-download"></i> Download to view</a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Form (always visible) -->
                <h4 class="mt-4 mb-3">Filter Submissions</h4>
                <form method="GET" action="<?= $admin_submissions ?>" class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2">
                    <!-- Text search on Doc, Folder, Student Name -->
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search by name, doc, folder..." value="<?= htmlspecialchars($search_term) ?>">
                        <?php if (!empty($search_term)): ?>
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchInput" title="Clear Search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Apply Search</button>
                    </div>

                    <!-- Filter by Folder -->
                    <select name="filter_folder" id="filter_folder" class="selectpicker" data-live-search="true" title="Filter by Folder">
                        <option value="">All Folders</option>
                        <?php foreach ($all_folders as $folder): ?>
                            <option value="<?php echo $folder['Folder_id']; ?>" <?php if ($filter_folder == $folder['Folder_id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($folder['Folder_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Filter by Student -->
                    <select name="filter_student" id="filter_student" class="selectpicker" data-live-search="true" title="Filter by Student">
                        <option value="">All Students</option>
                        <?php foreach ($all_students as $student): ?>
                            <option value="<?php echo $student['Uid']; ?>" <?php if ($filter_student == $student['Uid']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($student['Name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Filter by Status -->
                    <select name="filter_status" id="filter_status" class="selectpicker" title="Filter by Status">
                        <option value="all" <?php if ($filter_status == 'all') echo 'selected'; ?>>All Statuses</option>
                        <option value="pending" <?php if ($filter_status == 'pending') echo 'selected'; ?>>Pending</option>
                        <option value="y" <?php if ($filter_status == 'y') echo 'selected'; ?>>Approved</option>
                        <option value="n" <?php if ($filter_status == 'n') echo 'selected'; ?>>Rejected</option>
                    </select>
                    
                    <?php
                    $has_filters = !empty($search_term) || !empty($filter_folder) || !empty($filter_student) || ($filter_status !== 'pending' && $filter_status !== 'all');
                    if ($has_filters):
                    ?>
                        <a href="<?= htmlspecialchars($admin_submissions) ?>" class="btn btn-outline-secondary">Clear All Filters</a>
                    <?php endif; ?>

                </form>

                <div class="table-responsive">
                    <table class="table table-striped-edu">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Folder</th>
                                <th>Document</th>
                                <th>Submitted File</th>
                                <th>Submitted At</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($submissions_list_result->num_rows > 0): ?>
                                <?php while ($row = $submissions_list_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['StudentName']); ?><br><small><?php echo htmlspecialchars($row['StudentEmail']); ?></small></td>
                                        <td><?php echo htmlspecialchars($row['Folder_name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['Doc_name']); ?></td>
                                        <td>
                                            <a href="<?php echo BASE_URL . '/' . htmlspecialchars($row['document_URL']); ?>" target="_blank" class="text-decoration-none">
                                                <i class="bi bi-file-earmark-fill"></i> <?php echo htmlspecialchars($row['file_name'] ?: 'View File'); ?>
                                            </a>
                                        </td>
                                        <td><?php echo date("Y-m-d H:i", strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                if($row['Is_accepted'] === 'y') echo 'success'; 
                                                elseif($row['Is_accepted'] === 'n') echo 'danger'; 
                                                else echo 'warning text-dark'; 
                                            ?>">
                                                <?php echo ucfirst($row['Is_accepted'] === 'y' ? 'Approved' : ($row['Is_accepted'] === 'n' ? 'Rejected' : 'Pending')); ?>
                                            </span>
                                            <?php if ($row['Status'] && $row['Is_accepted'] !== 'pending'): echo '<br><small>(' . htmlspecialchars($row['Status']) . ')</small>'; endif; ?>
                                        </td>
                                        <td>
                                            <!-- The button action should preserve all current GET parameters (filters, search) -->
                                            <?php 
                                            $current_get_params = $_GET; // Get all current GET params
                                            $current_get_params['action'] = 'review';
                                            $current_get_params['id'] = $row['folder_doc_submission_id'];
                                            $review_link_query = http_build_query($current_get_params);
                                            ?>
                                            <a href="<?= $admin_submissions ?>?<?php echo $review_link_query; ?>" 
                                            class="btn btn-sm btn-action-review btn-<?php echo ($row['Is_accepted'] === 'pending') ? 'primary' : 'secondary'; ?>">
                                                <i class="bi bi-eye"></i> <?php echo ($row['Is_accepted'] === 'pending') ? 'Review' : 'View/Re-Review'; ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="7" class="text-center py-4">No submissions found matching your criteria.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Placeholder (as per original code, not fully implemented for submissions) -->
                   <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                       <?php if ($submissions_list_result->num_rows > 0): ?>
                           <p class="text-muted mb-0">Showing <?php echo $submissions_list_result->num_rows; ?> records match your filters.</p>
                       <?php else: ?>
                           <p class="text-muted mb-0">No records found matching your criteria.</p>
                       <?php endif; ?>
                   </div>

            </div>
        </div>
    </main>

    <!-- Footer (copied from map_docs_to_folder.php for consistency) -->
    <footer class="footer py-3">
        <div class="container-fixed-width text-center">
            <p>&copy; <?= date('Y') ?> EduFlow. All rights reserved.</p>
            <p>Document Management System for Educational Institutions.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap-select for all relevant dropdowns
            $('.selectpicker').selectpicker();

            // Navbar padding adjustment (copied from map_docs_to_folder.php for consistency)
            var eduNavbar = document.querySelector('.edu-navbar');
            var body = document.body;

            function adjustBodyPadding() {
                var currentNavbarHeight = eduNavbar.offsetHeight;
                body.style.paddingTop = currentNavbarHeight + 'px';
            }

            var initialNavbarHeight = eduNavbar.offsetHeight;
            document.documentElement.style.setProperty('--edu-navbar-initial-height', initialNavbarHeight + 'px');

            adjustBodyPadding();
            var navbarCollapse = document.getElementById('navbarNav');
            if (navbarCollapse) {
                navbarCollapse.addEventListener('shown.bs.collapse', adjustBodyPadding);
                navbarCollapse.addEventListener('hidden.bs.collapse', adjustBodyPadding);
            }
            window.addEventListener('resize', adjustBodyPadding);

            // --- Toast Notification Logic (copied from map_docs_to_folder.php) ---
            window.showToast = function(type, message) { // Make it global so PHP generated script can call it
                const toastContainer = document.querySelector('.toast-container');
                if (!toastContainer) {
                    console.error('Toast container not found!');
                    return;
                }

                const toastClasses = {
                    'success': 'text-bg-success',
                    'danger': 'text-bg-danger',
                    'error': 'text-bg-danger', // Map generic error to danger
                    'info': 'text-bg-info',
                    'warning': 'text-bg-warning'
                };

                const toastElement = document.createElement('div');
                toastElement.classList.add('toast', 'align-items-center', toastClasses[type] || 'text-bg-info', 'border-0');
                toastElement.setAttribute('role', 'alert');
                toastElement.setAttribute('aria-live', 'assertive');
                toastElement.setAttribute('aria-atomic', 'true');
                // Corrected string interpolation using template literals (backticks)
                toastElement.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message}
                        </div>
                        <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                    </div>
                `;

                toastContainer.appendChild(toastElement);

                const toast = new bootstrap.Toast(toastElement, {
                    autohide: true,
                    delay: 5000 // 5 seconds
                });
                toast.show();
            }

            // --- Clear Search Input Button Logic (copied from documents.php) ---
            const clearSearchInputBtn = document.getElementById('clearSearchInput');
            if (clearSearchInputBtn) {
                clearSearchInputBtn.addEventListener('click', function() {
                    // Clear the search input
                    document.querySelector('input[name="search"]').value = '';
                    // Submit form to clear the search filter.
                    document.querySelector('.search-area').submit();
                });
            }

            // Attach change listener to all selectpickers that trigger filtering
            $('#filter_folder, #filter_student, #filter_status').on('changed.bs.select', function (e, clickedIndex, isSelected, oldValue) {
                // Submit the form to apply selected filters
                document.querySelector('.search-area').submit();
            });
        });
    </script>
</body>
</html>