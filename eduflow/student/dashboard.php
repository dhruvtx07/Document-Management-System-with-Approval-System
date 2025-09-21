<?php
// dashboard.php - Student side, modified to match EduFlow/docMG UI

// Ensure student is logged in, session started. This file should set $_SESSION['clg_id'] and other user info.
require_once '../includes/student_auth.php'; 

// Manual include for database and functions.
// If student_auth.php includes header.php, and header.php includes db.php & functions.php, then these are redundant.
// To be safe, we'll keep them here. If you get 'redeclare' errors, check student_auth.php or remove these lines.
require_once '../includes/db.php'; // Required for $conn
require_once '../includes/functions.php'; // Required for prepare_and_execute, set_flash_message, formatSizeUnits etc.
require_once '../links.php'; // Import defined URL variables from links.php

// --- Helper Function: Display Flash Messages as Toasts ---
// This function is directly copied from login.php/admin_dashboard.php to ensure consistent toast display.
if (!function_exists('displayFlashMessagesAsToasts')) {
    function displayFlashMessagesAsToasts() {
        if (isset($_SESSION['flash_message'])) {
            $alert_type = htmlspecialchars($_SESSION['flash_message_type'] ?? 'info');
            $message = htmlspecialchars($_SESSION['flash_message']);

            // Determine text color based on background color for readability
            $text_class = match($alert_type) {
                'warning', 'info', 'light', 'secondary' => 'text-dark',
                default => 'text-white', // success, danger, primary, dark
            };
            ?>
            <div class="toast align-items-center bg-<?php echo $alert_type; ?> border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body <?php echo $text_class; ?>">
                        <?php echo $message; ?>
                    </div>
                    <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
            <?php
            unset($_SESSION['flash_message']);
            unset($_SESSION['flash_message_type']);
        }
    }
}

// Retrieve user and college ID from session
$student_uid = getCurrentUserId(); // Assuming this is defined in functions.php or student_auth.php
$clg_id = getCurrentUserClgId();   // Assuming this is defined in functions.php or student_auth.php
$student_name = htmlspecialchars($_SESSION['name'] ?? 'Student'); // Assuming $_SESSION['name'] holds the student's name

// --- Student-specific statistics ---
// Total assigned active folders
$assigned_folders_stmt = prepare_and_execute($conn, "SELECT COUNT(*) FROM Folder_mapping_to_students WHERE Student_id = ? AND Clg_id = ? AND Is_active = 'y'", [$student_uid, $clg_id], "ii");
$total_assigned_folders = $assigned_folders_stmt->get_result()->fetch_row()[0];
$assigned_folders_stmt->close();

// Total unique documents submitted by this student (any status, considering latest submission)
// This counts distinct (folder_id, doc_id) pairs for which a submission exists.
$total_submitted_docs_stmt = prepare_and_execute($conn, "
    SELECT COUNT(DISTINCT CONCAT(fdss.Folder_id, '-', fdss.Doc_id)) 
    FROM Folder_docs_submitted_students fdss
    WHERE fdss.Student_id = ? 
    AND fdss.Clg_id = ? 
    AND fdss.Is_active = 'y'
", [$student_uid, $clg_id], "ii");
$total_submitted_docs = $total_submitted_docs_stmt->get_result()->fetch_row()[0];
$total_submitted_docs_stmt->close();

// Total unique approved documents by this student (latest submission is 'y')
$approved_docs_stmt = prepare_and_execute($conn, "
    SELECT COUNT(DISTINCT CONCAT(fdss.Folder_id, '-', fdss.Doc_id)) 
    FROM Folder_docs_submitted_students fdss
    WHERE fdss.Student_id = ? 
    AND fdss.Clg_id = ? 
    AND fdss.Is_active = 'y' 
    AND fdss.Is_accepted = 'y'
    AND fdss.submitted_at = (
        SELECT MAX(fdss_inner.submitted_at)
        FROM Folder_docs_submitted_students fdss_inner
        WHERE fdss_inner.Folder_id = fdss.Folder_id 
        AND fdss_inner.Doc_id = fdss.Doc_id 
        AND fdss_inner.Student_id = ? 
        AND fdss_inner.Clg_id = ? 
        AND fdss_inner.Is_active = 'y'
    )
", [$student_uid, $clg_id, $student_uid, $clg_id], "iiii");
$total_approved_docs = $approved_docs_stmt->get_result()->fetch_row()[0];
$approved_docs_stmt->close();

// Total unique pending documents by this student (latest submission is 'pending')
$pending_docs_stmt = prepare_and_execute($conn, "
    SELECT COUNT(DISTINCT CONCAT(fdss.Folder_id, '-', fdss.Doc_id)) 
    FROM Folder_docs_submitted_students fdss
    WHERE fdss.Student_id = ? 
    AND fdss.Clg_id = ? 
    AND fdss.Is_active = 'y' 
    AND fdss.Is_accepted = 'pending'
    AND fdss.submitted_at = (
        SELECT MAX(fdss_inner.submitted_at)
        FROM Folder_docs_submitted_students fdss_inner
        WHERE fdss_inner.Folder_id = fdss.Folder_id 
        AND fdss_inner.Doc_id = fdss.Doc_id 
        AND fdss_inner.Student_id = ? 
        AND fdss_inner.Clg_id = ? 
        AND fdss_inner.Is_active = 'y'
    )
", [$student_uid, $clg_id, $student_uid, $clg_id], "iiii");
$total_pending_docs = $pending_docs_stmt->get_result()->fetch_row()[0];
$pending_docs_stmt->close();


// Fetch college name for display
$clg_name_stmt = prepare_and_execute($conn, "SELECT Clg_name FROM Clgs WHERE Clg_id = ?", [$clg_id], "i");
$clg_info = $clg_name_stmt->get_result()->fetch_assoc();
$clg_name = $clg_info ? $clg_info['Clg_name'] : 'Your College';
$clg_name_stmt->close();

$pageTitle = "My Dashboard - " . htmlspecialchars($clg_name);

// Fetch active folders assigned to this student (original logic)
$sql_assigned_folders = "SELECT 
                            f.Folder_id, f.Folder_name, f.folder_desc,
                            fmts.mapped_at, fmts.Is_active as MappingIsActive,
                            (SELECT COUNT(*) 
                               FROM Folder_doc_mapping fdm_count 
                               WHERE fdm_count.Folder_id = f.Folder_id AND fdm_count.Clg_id = f.Clg_id AND fdm_count.Is_active = 'y'
                            ) as total_docs_in_folder,
                            (SELECT COUNT(*) 
                               FROM Folder_docs_submitted_students fdss_count 
                               WHERE fdss_count.Folder_id = f.Folder_id AND fdss_count.Student_id = ? AND fdss_count.Clg_id = f.Clg_id AND fdss_count.Is_accepted = 'y' AND fdss_count.Is_active = 'y'
                               AND fdss_count.submitted_at = (SELECT MAX(fdss_inner_count.submitted_at) FROM Folder_docs_submitted_students fdss_inner_count WHERE fdss_inner_count.Folder_id = fdss_count.Folder_id AND fdss_inner_count.Doc_id = fdss_count.Doc_id AND fdss_inner_count.Student_id = fdss_count.Student_id AND fdss_inner_count.Clg_id = fdss_count.Clg_id AND fdss_inner_count.Is_active = 'y')
                            ) as approved_docs_count
                          FROM Folder_mapping_to_students fmts
                          JOIN Folder f ON fmts.Folder_id = f.Folder_id
                          WHERE fmts.Student_id = ? AND fmts.Clg_id = ? AND fmts.Is_active = 'y' AND f.Is_active = 'y'
                          ORDER BY f.Folder_name ASC";

$stmt_assigned_folders = prepare_and_execute($conn, $sql_assigned_folders, [$student_uid, $student_uid, $clg_id], "iii");
$assigned_folders_result = $stmt_assigned_folders->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <!-- Bootstrap CSS 5.3.3 from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Bootstrap Icons CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- CUSTOM CSS (Combined and adapted from admin_dashboard.php) -->
    <style>
        /* Custom Color Variables - Shades of Blue and White */
        :root {
            --docmg-white: #ffffff;
            --docmg-lightest-blue: #e0f2f7;   /* Very light sky blue for background */
            --docmg-light-blue: #add8e6;      /* Light blue borders/accents */
            --docmg-medium-blue: #87ceeb;    /* Sky blue for secondary buttons/highlights */
            --docmg-primary-blue: #007bff;   /* Standard Bootstrap primary blue */
            --docmg-dark-blue: #0056b3;      /* Darker blue for hovers/active states */
            --docmg-darkest-blue: #003366;   /* Deep blue for brand/headings */
            --docmg-secondary-text-color: #6c757d; /* Bootstrap's muted color */

            /* New variable for navbar height for sticky behavior */
            --docmg-navbar-initial-height: 56px; /* A common initial height for Bootstrap navbars */
        }

        html {
            /* Adds a thin blue line at the very top, as hinted by the image */
            border-top: 5px solid var(--docmg-light-blue);
        }

        body {
            background-color: var(--docmg-lightest-blue);
            color: #333; /* Default text color for better contrast on light backgrounds */
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; /* Clean, modern font stack */
            min-height: 100vh; /* Ensure body takes full viewport height */
            display: flex;
            flex-direction: column; /* For proper sticky footer positioning */
            padding-top: var(--docmg-navbar-initial-height); /* Add padding to body to clear the fixed navbar */
        }

        /* Navbar Styling */
        .docmg-navbar {
            background-color: var(--docmg-white) !important;
            border-bottom: 2px solid var(--docmg-light-blue) !important; /* This creates the initial border below navbar on large screens */
            box-shadow: 0 2px 5px rgba(0,0,0,0.05); /* Subtle shadow */
            /* Ensure fixed navbar always stays on top during scroll */
            top: 0;
            left: 0;
            right: 0;
            z-index: 1030; /* Bootstrap's default z-index for fixed navbars */
        }

        /* Logo in Navbar */
        .navbar-brand .navbar-logo {
            height: 40px; /* Fixed height for navbar logo */
            width: auto; /* Maintain aspect ratio */
            margin-right: 10px;
            object-fit: contain; /* Ensures the image is not distorted */
        }

        .docmg-brand {
            color: var(--docmg-darkest-blue) !important;
            font-weight: bold;
            font-size: 1.75rem;
            transition: color 0.3s ease;
            display: flex; /* Makes logo and text align horizontally */
            align-items: center;
        }

        .docmg-brand:hover {
            color: var(--docmg-dark-blue) !important;
        }

        .docmg-nav-link {
            color: var(--docmg-primary-blue) !important; /* Links themselves are primary blue */
            font-weight: 500;
            margin-right: 15px; /* Spacing between nav links */
            transition: color 0.3s ease, background-color 0.3s ease, border-radius 0.3s ease;
            padding: 0.5rem 1rem;
            border-radius: 0.25rem; /* Default for non-active links */
            white-space: nowrap; /* Prevent wrapping for nav links */
        }

        .docmg-nav-link:not(.active):hover { /* Apply hover effect only to non-active links */
            color: var(--docmg-dark-blue) !important;
            background-color: transparent !important;
        }

        .docmg-nav-link.active {
            color: var(--docmg-white) !important;
            background-color: var(--docmg-primary-blue) !important; /* Primary blue for active state, as per image */
            border-radius: 2rem; /* Pill shape for active link */
            padding: 0.5rem 1.25rem; /* Slightly more horizontal padding for pill effect */
        }

        /* Custom Button Styles (Logout Button) */
        .btn-docmg-secondary {
            background-color: var(--docmg-medium-blue); /* Sky Blue for secondary button */
            border-color: var(--docmg-medium-blue);
            color: var(--docmg-white);
            transition: background-color 0.3s ease, border-color 0.3s ease, color 0.3s ease, border-radius 0.3s ease;
            border-radius: 0.5rem; /* More rounded corners for the logout button */
            padding: 0.5rem 1.25rem; /* Adjust padding for better look in image */
        }

        .btn-docmg-secondary:hover {
            background-color: var(--docmg-dark-blue);
            border-color: var(--docmg-dark-blue);
            color: var(--docmg-white);
        }

        .logout-btn {
            text-decoration: none; /* Ensure no underline */
        }

        /* Auth Card (reused for dashboard container) */
        .auth-card {
            background-color: var(--docmg-white);
            border-radius: 0.75rem;
            box-shadow: 0 0.5rem 1.5rem rgba(0, 0, 0, 0.08); /* More prominent shadow */
            animation: fadeIn 0.5s ease-out;
            border: none;
            max-width: 1200px; /* Set max-width here */
            margin-left: auto; /* And centering here */
            margin-right: auto;
        }

        /* Specific fixed size for dashboard logo */
        .dashboard-logo {
            width: 250px; /* Fixed width */
            height: 100px; /* Fixed height */
            object-fit: contain; /* Keeps aspect ratio and fits within bounds */
            margin-bottom: 2rem;
        }

        h1, h2, h3, h4, h5, h6 {
            color: var(--docmg-darkest-blue); /* Dark blue for all headings */
        }

        .text-primary {
            color: var(--docmg-primary-blue) !important; /* Override Bootstrap primary color */
        }

        .text-muted {
            color: var(--docmg-secondary-text-color) !important;
        }

        /* Custom container for width consistency */
        /* Applied to navbar inner container and footer inner container */
        .container-fixed-width {
            max-width: 1200px;
            margin-left: auto;
            margin-right: auto;
            padding-left: var(--bs-gutter-x, 0.75rem);
            padding-right: var(--bs-gutter-x, 0.75rem);
        }

        /* Dashboard Specific Statistic Card Styles */
        .stats-card {
            background-color: var(--docmg-white);
            border: 1px solid var(--docmg-light-blue);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
           /* min-height: 180px; */ /* Ensure cards have similar height and space for content - removed to let content dictate height */
        }

        .stats-card:hover {
            transform: translateY(-8px); /* Lift more on hover */
            box-shadow: 0 0.75rem 1.75rem rgba(0, 0, 0, 0.15); /* More intense shadow */
        }

        .stats-card .card-title {
            font-size: 1.35rem; /* Slightly larger title */
            font-weight: 600;
            color: var(--docmg-dark-blue);
            margin-bottom: 0.75rem;
        }

        .stats-card .card-value {
            font-size: 3rem; /* Slightly reduced for compactness */
            font-weight: bold;
            color: var(--docmg-primary-blue);
            margin-top: auto;
            line-height: 1; /* Tighter line height */
        }

        .stats-card .card-text {
            color: var(--docmg-secondary-text-color);
            font-size: 0.9em;
        }

        /* See All Stats Link */
        .see-all-link {
            color: var(--docmg-primary-blue);
            font-weight: 600;
            transition: color 0.3s ease, text-decoration 0.3s ease;
            text-decoration: none;
            padding-right: 5px; /* Space for arrow */
        }

        .see-all-link:hover {
            color: var(--docmg-dark-blue);
            text-decoration: underline;
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Utility classes for direct color application */
        .bg-docmg-blue-4 { background-color: var(--docmg-primary-blue) !important; }
        .text-docmg-white { color: var(--docmg-white) !important; }
        .text-docmg-blue-6 { color: var(--docmg-darkest-blue) !important; }
        .text-docmg-primary-blue { color: var(--docmg-primary-blue) !important; }


        /* Responsive adjustments */
        @media (max-width: 991.98px) { /* Applies to screens smaller than 992px (Bootstrap's lg breakpoint) */
            .navbar-toggler {
                border-color: var(--docmg-light-blue);
            }
            .navbar-toggler-icon {
                background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='%23007bff' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
            }
            .logout-btn {
                margin-left: 0;
                margin-top: 15px;
                width: 100%;
                border-radius: 0.25rem; /* Revert to standard for full-width btn on small screens */
            }
            .docmg-nav-link {
                width: 100%;
                text-align: center;
                margin-right: 0;
                border-radius: 0.25rem; /* Revert activepill for full-width nav-item on small screens */
                padding: 0.5rem 1rem;
            }
            .docmg-nav-link.active {
                border-radius: 0.25rem; /* Make active link normal rectangle on small screens */
            }

            /* Adjust fixed-width container for smaller screens to let Bootstrap containers take over */
            .container-fixed-width {
                max-width: 100% !important; /* Allow it to be full-width on small screens */
                padding-top: var(--bs-navbar-padding-y); /* Provide consistent spacing around brand/toggler */
                padding-bottom: var(--bs-navbar-padding-y); /* Provide consistent spacing around brand/toggler */
            }

            /* Remove main navbar border-bottom on smaller screens */
            .docmg-navbar {
                border-bottom: none !important;
            }

            /* Add border to the .container-fixed-width which now acts as the header in mobile */
            .container-fixed-width {
                border-bottom: 1px solid var(--docmg-light-blue); /* This border will be under the brand/toggler */
            }

            /* No need for border-top for .navbar-collapse.show with new structure */
            /* Padding for the navbar-collapse items when expanded */
            .navbar-collapse {
                padding-top: 0.5rem !important; /* Spacing between the border and first nav item */
            }

            /* Specific padding for ul within .navbar-collapse for consistency */
            .navbar-collapse .navbar-nav {
                padding-top: 0.5rem; /* Additional spacing for items within the collapsible menu */
                padding-bottom: 0.5rem;
            }
        }

        /* Footer Styles */
        .footer {
            background-color: var(--docmg-darkest-blue);
            color: var(--docmg-white);
            padding: 1.5rem 0;
            text-align: center;
            margin-top: auto; /* Pushes the footer to the bottom when main content is short */
            flex-shrink: 0; /* Prevents shrink in flex column layout */
        }

        .footer p {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }

        .footer a {
            color: var(--docmg-light-blue);
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer a:hover {
            color: var(--docmg-white);
            text-decoration: underline;
        }

        /* Toast notifications container positioning from login.php */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1080; /* Ensures toasts are above most content */
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
        .toast.bg-warning .btn-close,
        .toast.bg-info .btn-close,
        .toast.bg-light .btn-close,
        .toast.bg-secondary .btn-close {
            filter: none;
            opacity: 0.8;
        }
        .toast .btn-close:hover {
            opacity: 1;
        }

        /* Specific styling for the document list within folder cards */
        .list-group-item strong {
            font-size: 1.1rem;
        }
        .list-group-item small {
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light docmg-navbar fixed-top">
        <div class="container-fixed-width d-flex justify-content-between align-items-center">
            <!-- Brand Logo/Name -->
            <a class="navbar-brand docmg-brand" href="<?= $student_home_page ?>">
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
                        <a class="nav-link docmg-nav-link active" href="<?= $student_home_page ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link" href="<?= $student_profile ?>">My Profile</a>
                    </li>
                    <!-- Add more student-specific links as needed -->
                </ul>
                <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
                    <!-- Logout Button -->
                    <li class="nav-item ms-lg-3">
                        <a class="btn btn-docmg-secondary logout-btn" href="<?= $logout_page ?>">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content area -->
    <main class="container-fluid py-4 flex-grow-1">
        <div class="card auth-card p-4 p-md-5">
            <div class="card-body text-center">
                <!-- EduFlow Logo for Dashboard (larger than navbar logo) -->
                <div class="logo-container">
                    <img src="<?= $logo ?>" alt="EduFlow Logo" class="img-fluid dashboard-logo">
                </div>

                <!-- Welcome Message -->
                <h1 class="mb-4 text-primary fw-bold">Welcome, <?= $student_name ?>!</h1>

                <p class="lead mb-4 text-muted">This is your personalized Student Dashboard for <?php echo htmlspecialchars($clg_name); ?>.</p>
                <p class="mb-5 text-muted">Here you can view your assigned document collection folders, check submission statuses, and upload required documents.</p>

                <h2 class="text-start mb-4 text-docmg-blue-6">My Document Overview</h2>

                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4 mb-4">
                    <!-- Assigned Folders Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Assigned Folders</h5>
                                <p class="card-value"><?php echo $total_assigned_folders; ?></p>
                                <p class="card-text text-muted">Active folders assigned to you.</p>
                                <a href="#assignedFolders" class="see-all-link">View All &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Documents Submitted Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Documents Submitted</h5>
                                <p class="card-value"><?php echo $total_submitted_docs; ?></p>
                                <p class="card-text text-muted">Total documents you've submitted.</p>
                                <a href="<?= $student_submission_history ?>" class="see-all-link">View History &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Approved Documents Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Approved Documents</h5>
                                <p class="card-value"><?php echo $total_approved_docs; ?></p>
                                <p class="card-text text-muted">Your documents successfully approved.</p>
                                <a href="<?= $student_submission_history ?>?status=approved" class="see-all-link">View Approved &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Review Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Pending Review</h5>
                                <p class="card-value"><?php echo $total_pending_docs; ?></p>
                                <p class="card-text text-muted">Documents awaiting administrator review.</p>
                                <a href="<?= $student_submission_history ?>?status=pending" class="see-all-link">View Pending &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>

                <h2 class="text-start mb-4 text-docmg-blue-6">Quick Actions</h2>
                <div class="d-flex flex-wrap justify-content-start gap-3 mb-5">
                    <a href="<?= $student_profile ?>" class="btn btn-outline-primary btn-lg rounded-pill px-4">Update My Profile</a>
                    <a href="<?= $student_contact_admin ?>" class="btn btn-outline-success btn-lg rounded-pill px-4">Contact Administrator</a>
                    <!-- Add more quick actions specific to students -->
                </div>

                <h2 class="text-start mb-4 text-docmg-blue-6" id="assignedFolders">Folders Assigned to You</h2>

                <?php if ($assigned_folders_result->num_rows > 0): ?>
                    <?php while ($folder = $assigned_folders_result->fetch_assoc()): ?>
                        <div class="card mb-4 stats-card"> 
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-folder-fill text-primary"></i> <?php echo htmlspecialchars($folder['Folder_name']); ?>
                                </h4>
                                <span class="badge bg-<?php echo ($folder['approved_docs_count'] == $folder['total_docs_in_folder'] && $folder['total_docs_in_folder'] > 0) ? 'success' : 'info'; ?>">
                                    <?php echo $folder['approved_docs_count']; ?> / <?php echo $folder['total_docs_in_folder']; ?> Docs Approved
                                </span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($folder['folder_desc'])): ?>
                                    <p class="card-text text-muted mb-3"><?php echo nl2br(htmlspecialchars($folder['folder_desc'])); ?></p>
                                <?php endif; ?>

                                <?php
                                // Fetch documents required for this folder and their submission status for this student
                                // IMPORTANT CHANGE: Modified to fetch only the LATEST submission for each document,
                                // including rejected ones.
                                $sql_folder_docs = "SELECT 
                                                        fdm.Doc_id, d.Doc_name, d.Doc_desc as DocTypeDesc, d.Doc_ext, d.Doc_max_size,
                                                        fdm.Submit_before, fdm.Is_mandate,
                                                        fdss.folder_doc_submission_id, fdss.document_URL, fdss.file_name, 
                                                        CASE
                                                            WHEN fdss.Is_accepted IS NULL THEN NULL 
                                                            ELSE CAST(fdss.Is_accepted AS CHAR)
                                                        END AS Submission_Is_accepted, 
                                                        fdss.Comments as Submission_Comments, 
                                                        fdss.submitted_at as Submission_submitted_at, 
                                                        fdss.Status as Submission_Status_Text,
                                                        u_commenter.Name AS CommenterName -- Added to fetch the name of the person who commented
                                                    FROM Folder_doc_mapping fdm
                                                    JOIN doc d ON fdm.Doc_id = d.Doc_id AND fdm.Clg_id = d.Clg_id
                                                    LEFT JOIN Folder_docs_submitted_students fdss 
                                                        ON fdm.Folder_id = fdss.Folder_id 
                                                        AND fdm.Doc_id = fdss.Doc_id 
                                                        AND fdss.Student_id = ? 
                                                        AND fdss.Clg_id = ?
                                                        AND fdss.Is_active = 'y'
                                                        AND fdss.submitted_at = ( -- Correlated subquery to get the maximum (latest) submitted_at
                                                            SELECT MAX(fdss_inner.submitted_at)
                                                            FROM Folder_docs_submitted_students fdss_inner
                                                            WHERE fdss_inner.Folder_id = fdm.Folder_id 
                                                            AND fdss_inner.Doc_id = fdm.Doc_id 
                                                            AND fdss_inner.Student_id = ? 
                                                            AND fdss_inner.Clg_id = ?
                                                            AND fdss_inner.Is_active = 'y'
                                                        )
                                                    LEFT JOIN Users u_commenter ON fdss.Commented_by = u_commenter.Uid
                                                    WHERE fdm.Folder_id = ? AND fdm.Clg_id = ? AND fdm.Is_active = 'y' AND d.Is_active = 'y'
                                                    ORDER BY d.Doc_name ASC";
                                // Parameters for: fdss.Student_id, fdss.Clg_id (for LEFT JOIN)
                                // then fdss_inner.Student_id, fdss_inner.Clg_id (for subquery)
                                // then fdm.Folder_id, fdm.Clg_id (for WHERE clause)
                                $stmt_folder_docs = prepare_and_execute($conn, $sql_folder_docs, [$student_uid, $clg_id, $student_uid, $clg_id, $folder['Folder_id'], $clg_id], "iiiiii");
                                $folder_docs_result = $stmt_folder_docs->get_result();
                                ?>

                                <?php if ($folder_docs_result->num_rows > 0): ?>
                                    <ul class="list-group list-group-flush border-top border-bottom">
                                        <?php while ($doc_item = $folder_docs_result->fetch_assoc()): ?>
                                            <li class="list-group-item d-flex flex-column flex-md-row align-items-md-center justify-content-between py-3 px-0">
                                                <div class="mb-2 mb-md-0 me-md-3 text-start flex-grow-1">
                                                    <strong><?php echo htmlspecialchars($doc_item['Doc_name']); ?></strong>
                                                    <?php if ($doc_item['Is_mandate'] == 'y'): ?>
                                                        <span class="badge bg-danger ms-2">Mandatory</span>
                                                    <?php endif; ?>
                                                    <div class="small text-muted mt-1">
                                                        Allowed: .<?php echo str_replace(',', ', .', htmlspecialchars($doc_item['Doc_ext'])); ?>, Max Size: <?php echo formatSizeUnits($doc_item['Doc_max_size']); ?>
                                                        <?php if ($doc_item['Submit_before']): ?>
                                                            <br><span class="text-warning"><i class="bi bi-clock-fill"></i> Due: <?php echo date("D, M j, Y, g:i A", strtotime($doc_item['Submit_before'])); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="text-md-center flex-shrink-0 mb-2 mb-md-0">
                                                    <?php if ($doc_item['folder_doc_submission_id']): // Document has been submitted at least once ?>
                                                        Status: 
                                                        <span class="badge fs-6 bg-<?php 
                                                            if ($doc_item['Submission_Is_accepted'] === 'y') echo 'success';
                                                            elseif ($doc_item['Submission_Is_accepted'] === 'n') echo 'danger';
                                                            else echo 'warning text-dark'; // Pending
                                                        ?>">
                                                            <?php 
                                                            if ($doc_item['Submission_Is_accepted'] === 'y') echo '<i class="bi bi-check-circle-fill"></i> Approved'; 
                                                            elseif ($doc_item['Submission_Is_accepted'] === 'n') echo '<i class="bi bi-x-circle-fill"></i> Rejected'; 
                                                            else echo '<i class="bi bi-hourglass-split"></i> Pending Review'; 
                                                            ?>
                                                        </span>
                                                        <?php if ($doc_item['Submission_Status_Text']): ?>
                                                            <div class="small text-muted ms-1">(<?php echo htmlspecialchars($doc_item['Submission_Status_Text']); ?>)</div>
                                                        <?php endif; ?>
                                                        <div class="small text-muted">Submitted: <?php echo date("M j, Y H:i", strtotime($doc_item['Submission_submitted_at'])); ?></div>
                                                        <?php if (!empty($doc_item['Submission_Comments'])): ?>
                                                            <?php 
                                                                $comment_tooltip = htmlspecialchars($doc_item['Submission_Comments']);
                                                                if ($doc_item['CommenterName']) {
                                                                    $comment_tooltip .= " (by " . htmlspecialchars($doc_item['CommenterName']) . ")";
                                                                } else {
                                                                    $comment_tooltip .= " (by Admin)"; // Fallback if commenter name is null
                                                                }
                                                            ?>
                                                            <a href="#" class="ms-1 text-info small" data-bs-toggle="tooltip" data-bs-placement="top" title="<?php echo $comment_tooltip; ?>">
                                                                <i class="bi bi-info-circle-fill"></i> Comments
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary fs-6">Not Submitted</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-md-end text-start mt-2 mt-md-0 flex-shrink-0">
                                                    <?php
                                                    $is_overdue = false;
                                                    if ($doc_item['Submit_before'] && time() > strtotime($doc_item['Submit_before']) && $doc_item['Submission_Is_accepted'] !== 'y') {
                                                        $is_overdue = true;
                                                    }
                                                    ?>
                                                    <?php if ($doc_item['Submission_Is_accepted'] === 'y'): // Always show view if approved, change button appearance ?>
                                                        <a href="<?= $submit_documents ?>?folder_id=<?php echo $folder['Folder_id']; ?>&doc_id=<?php echo $doc_item['Doc_id']; ?>&submission_id=<?php echo $doc_item['folder_doc_submission_id']; ?>" 
                                                            class="btn btn-sm btn-outline-primary w-100 w-md-auto">
                                                            <i class="bi bi-eye-fill"></i> View Approved
                                                        </a>
                                                    <?php elseif ($doc_item['folder_doc_submission_id']): // Submitted but rejected or pending ?>
                                                        <a href="<?= $submit_documents ?>?folder_id=<?php echo $folder['Folder_id']; ?>&doc_id=<?php echo $doc_item['Doc_id']; ?>&submission_id=<?php echo $doc_item['folder_doc_submission_id']; ?>" 
                                                            class="btn btn-sm <?php echo ($doc_item['Submission_Is_accepted'] === 'n') ? 'btn-danger' : 'btn-info'; ?> w-100 w-md-auto">
                                                            <?php echo ($doc_item['Submission_Is_accepted'] === 'n') ? '<i class="bi bi-arrow-clockwise"></i> Re-submit' : '<i class="bi bi-eye-fill"></i> View/Edit'; ?>
                                                        </a>
                                                        <?php if ($is_overdue): ?>
                                                            <div class="small text-danger mt-1">Past Due Date!</div>
                                                        <?php endif; ?>
                                                    <?php else: // Not submitted yet ?>
                                                        <a href="<?= $submit_documents ?>?folder_id=<?php echo $folder['Folder_id']; ?>&doc_id=<?php echo $doc_item['Doc_id']; ?>" 
                                                            class="btn btn-sm btn-primary w-100 w-md-auto">
                                                            <i class="bi bi-upload"></i> Submit Document
                                                        </a>
                                                        <?php if ($is_overdue): ?>
                                                            <div class="small text-danger mt-1">Past Due Date!</div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </div>
                                            </li>
                                        <?php endwhile; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="text-center text-muted">No documents are currently configured for this folder.</p>
                                <?php endif; ?>
                                <?php $stmt_folder_docs->close(); ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="alert alert-info py-4" role="alert">
                        <h4 class="alert-heading">No Folders Assigned Yet!</h4>
                        <p>It seems you haven't been assigned any active document collection folders by your college administrator yet.</p>
                        <hr>
                        <p class="mb-0">Please check back later or contact your college administrator if you believe this is an error.</p>
                    </div>
                <?php endif; ?>
                <?php $stmt_assigned_folders->close(); ?>

            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="footer py-3">
        <div class="container-fixed-width text-center">
            <p>&copy; <?= date('Y') ?> EduFlow. All rights reserved.</p>
            <p>Document Management System for Educational Institutions.</p>
        </div>
    </footer>

    <!-- Toast Container for Notifications -->
    <div aria-live="polite" aria-atomic="true" class="toast-container">
        <?php displayFlashMessagesAsToasts(); // Display flash messages as Bootstrap Toasts ?>
    </div>

    <!-- Bootstrap JS (Popper.js & Bootstrap JS bundle) 5.3.3 from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>

    <!-- JavaScript to initialize and show toasts and adjust body padding for fixed navbar -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Toast initialization
            var toastElList = [].slice.call(document.querySelectorAll('.toast'));
            var toastList = toastElList.map(function (toastEl) {
                return new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
            });
            toastList.forEach(toast => toast.show()); // Show each toast

            // Navbar padding adjustment
            var docmgNavbar = document.querySelector('.docmg-navbar');
            var navbarCollapse = document.getElementById('navbarNav');
            var body = document.body;

            function adjustBodyPadding() {
                var currentNavbarHeight = docmgNavbar.offsetHeight;
                body.style.paddingTop = currentNavbarHeight + 'px';
            }

            // Set initial navbar height CSS variable for body padding calculation
            var initialNavbarHeight = docmgNavbar.offsetHeight;
            document.documentElement.style.setProperty('--docmg-navbar-initial-height', initialNavbarHeight + 'px');

            adjustBodyPadding(); // Initial adjustment
            // Adjust padding when navbar collapses/expands
            navbarCollapse.addEventListener('shown.bs.collapse', adjustBodyPadding);
            navbarCollapse.addEventListener('hidden.bs.collapse', adjustBodyPadding);
            // Adjust padding on window resize
            window.addEventListener('resize', adjustBodyPadding);

            // Initialize Bootstrap tooltips (original script from old dashboard.php)
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            })
        });
    </script>
</body>
</html>