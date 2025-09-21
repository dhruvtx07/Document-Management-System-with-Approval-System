<?php
// admin/dashboard.php - Modified to match EduFlow/docMG UI

// Ensure admin is logged in, session started. This file should set $_SESSION['clg_id'] and other user info.
require_once '../includes/admin_auth.php'; // This should indirectly include db.php and functions.php
require_once '../links.php'; // Include the file that defines all URL variables

// At this point, $_SESSION['uid'], $_SESSION['name'], $_SESSION['clg_id'] and common functions
// like prepare_and_execute, set_flash_message, BASE_URL should be available from admin_auth.php
// or other files included by it (e.g., includes/header.php if it's still indirectly included).

// Manual include for database and functions if not implicitly handled by admin_auth.php or a removed header.php
// If admin_auth.php includes header.php, and header.php includes db.php & functions.php, then these are redundant.
// To be safe, we'll keep them here for now, but if you get 'redeclare' errors, remove them.
// require_once '../includes/db.php'; // Required for $conn
// require_once '../includes/functions.php'; // Required for prepare_and_execute, set_flash_message (if not already included)

// --- Helper Function: Display Flash Messages as Toasts ---
// This function is directly copied from login.php to ensure consistent toast display.
// If set_flash_message function stores messages outside of $_SESSION['flash_message'],
// you will need to adapt this or the set_flash_message function.
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
$clg_id = getCurrentUserClgId(); // Assuming this is defined in functions.php or admin_auth.php
$admin_id = getCurrentUserId(); // Assuming this is defined in functions.php or admin_auth.php
$user_name = htmlspecialchars($_SESSION['name'] ?? 'Admin'); // Assuming $_SESSION['name'] holds the user's name

// Fetch stats (existing logic remains)
$total_students_stmt = prepare_and_execute($conn, "SELECT COUNT(*) as count FROM Students WHERE Clg_id = ? AND Is_active = 'y'", [$clg_id], "i");
$total_students = $total_students_stmt->get_result()->fetch_assoc()['count'];
$total_students_stmt->close();

$total_docs_stmt = prepare_and_execute($conn, "SELECT COUNT(*) as count FROM doc WHERE Clg_id = ? AND Is_active = 'y'", [$clg_id], "i");
$total_docs = $total_docs_stmt->get_result()->fetch_assoc()['count'];
$total_docs_stmt->close();

$total_folders_stmt = prepare_and_execute($conn, "SELECT COUNT(*) as count FROM Folder WHERE Clg_id = ? AND Is_active = 'y'", [$clg_id], "i");
$total_folders = $total_folders_stmt->get_result()->fetch_assoc()['count'];
$total_folders_stmt->close();

$pending_submissions_stmt = prepare_and_execute($conn, "SELECT COUNT(*) as count FROM Folder_docs_submitted_students WHERE Clg_id = ? AND Is_accepted = 'pending'", [$clg_id], "i");
$pending_submissions = $pending_submissions_stmt->get_result()->fetch_assoc()['count'];
$pending_submissions_stmt->close();

$clg_name_stmt = prepare_and_execute($conn, "SELECT Clg_name FROM Clgs WHERE Clg_id = ?", [$clg_id], "i");
$clg_info = $clg_name_stmt->get_result()->fetch_assoc();
$clg_name = $clg_info ? $clg_info['Clg_name'] : 'Your College';
$clg_name_stmt->close();

$pageTitle = "Admin Dashboard - " . htmlspecialchars($clg_name);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?></title>
    <!-- Bootstrap CSS 5.3.3 from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- CUSTOM CSS (Combined and adapted from login.php and example_dashboard.php) -->
    <style>
        /* Custom Color Variables - Shades of Blue and White */
        :root {
            --docmg-white: #ffffff;
            --docmg-lightest-blue: #e0f2f7;   /* Very light sky blue for background */
            --docmg-light-blue: #add8e6;      /* Light blue borders/accents */
            --docmg-medium-blue: #87ceeb;    /* Sky blue for secondary buttons/highlights */
            --docmg-primary-blue: #007bff;  /* Standard Bootstrap primary blue */
            --docmg-dark-blue: #0056b3;      /* Darker blue for hovers/active states */
            --docmg-darkest-blue: #003366;  /* Deep blue for brand/headings */
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
            position: fixed; /* Added missing position fixed */
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

    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-light bg-light docmg-navbar">
        <div class="container-fixed-width d-flex justify-content-between align-items-center">
            <!-- Brand Logo/Name -->
            <a class="navbar-brand docmg-brand" href="<?php echo $admin_home_page; ?>">
                <!-- Adjust logo path if 'logo.png' is not in the parent directory directly -->
                <img src="<?php echo $logo; ?>" alt="EduFlow Logo" class="navbar-logo">
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
                        <a class="nav-link docmg-nav-link active" href="<?php echo $admin_home_page; ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link" href="<?php echo $admin_documents; ?>">Manage Documents</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link" href="<?php echo $admin_folders; ?>">Manage Folders</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link" href="<?php echo $admin_classes; ?>">Manage Classes</a>
                    </li>
                </ul>
                <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link" href="<?php echo $admin_users; ?>?role=student">Manage Students</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link" href="<?php echo $admin_submissions; ?>">View Submissions</a>
                    </li>
                    <!-- Logout Button -->
                    <li class="nav-item ms-lg-3">
                        <a class="btn btn-docmg-secondary logout-btn" href="<?php echo $logout_page; ?>">Logout</a>
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
                    <!-- Adjust logo path if 'logo.png' is not in the parent directory directly -->
                    <img src="<?php echo $logo; ?>" alt="EduFlow Logo" class="img-fluid dashboard-logo">
                </div>

                <!-- Welcome Message -->
                <h1 class="mb-4 text-primary fw-bold">Welcome, <?= $user_name ?>!</h1>

                <p class="lead mb-4 text-muted">This is your personalized Admin Dashboard for <?php echo htmlspecialchars($clg_name); ?>.</p>
                <p class="mb-5 text-muted">You have successfully logged in and can manage your college's documents, students, and more.</p>

                <h2 class="text-start mb-4 text-docmg-blue-6">Overview Statistics</h2>

                <div class="row row-cols-1 row-cols-md-2 row-cols-lg-4 g-4 mb-4">
                    <!-- Active Students Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Active Students</h5>
                                <p class="card-value"><?php echo $total_students; ?></p>
                                <p class="card-text text-muted">Currently active students.</p>
                                <a href="<?php echo $admin_users; ?>?role=student" class="see-all-link">View Students &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Document Types Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Document Types</h5>
                                <p class="card-value"><?php echo $total_docs; ?></p>
                                <p class="card-text text-muted">Defined document templates.</p>
                                <a href="<?php echo $admin_documents; ?>" class="see-all-link">Manage Docs &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Active Folders Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Active Folders</h5>
                                <p class="card-value"><?php echo $total_folders; ?></p>
                                <p class="card-text text-muted">Active document collection folders.</p>
                                <a href="<?php echo $admin_folders; ?>" class="see-all-link">Manage Folders &rarr;</a>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Submissions Card -->
                    <div class="col">
                        <div class="card stats-card h-100 text-start">
                            <div class="card-body">
                                <h5 class="card-title">Pending Submissions</h5>
                                <p class="card-value"><?php echo $pending_submissions; ?></p>
                                <p class="card-text text-muted">Documents awaiting approval.</p>
                                <a href="<?php echo $admin_submissions; ?>?status=pending" class="see-all-link">View Submissions &rarr;</a>
                            </div>
                        </div>
                    </div>
                </div>

                <h2 class="text-start mb-4 text-docmg-blue-6">Quick Actions</h2>
                <div class="d-flex flex-wrap justify-content-start gap-3 mb-5">
                    <a href="<?php echo $admin_documents; ?>?action=add" class="btn btn-outline-primary btn-lg rounded-pill px-4">Add New Document Type</a>
                    <a href="<?php echo $admin_folders; ?>?action=add" class="btn btn-outline-success btn-lg rounded-pill px-4">Create New Folder</a>
                    <a href="<?php echo $admin_users; ?>?action=add_student" class="btn btn-outline-info btn-lg rounded-pill px-4">Add New Student</a>
                    <a href="<?php echo $admin_users; ?>?action=add_admin" class="btn btn-outline-secondary btn-lg rounded-pill px-4">Add New Admin</a>
                </div>

                <!-- Global Logout Button at the bottom of the dashboard content -->
                <a href="<?php echo $logout_page; ?>" class="btn btn-primary btn-lg mt-4 w-50">Logout From EduFlow</a>
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
            var navbarCollapse = document.getElementById('navbarNav');
            var docmgNavbar = document.querySelector('.docmg-navbar');
            var body = document.body;

            function adjustBodyPadding() {
                var currentNavbarHeight = docmgNavbar.offsetHeight;
                body.style.paddingTop = currentNavbarHeight + 'px';
            }

            var initialNavbarHeight = docmgNavbar.offsetHeight;
            document.documentElement.style.setProperty('--docmg-navbar-initial-height', initialNavbarHeight + 'px');

            adjustBodyPadding(); // Initial adjustment
            navbarCollapse.addEventListener('shown.bs.collapse', adjustBodyPadding);
            navbarCollapse.addEventListener('hidden.bs.collapse', adjustBodyPadding);
            window.addEventListener('resize', adjustBodyPadding);
        });
    </script>
</body>
</html>