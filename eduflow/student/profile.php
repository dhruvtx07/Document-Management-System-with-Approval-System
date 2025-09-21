<?php
// profile.php - Student profile page, modified to match EduFlow/docMG UI

require_once '../includes/student_auth.php'; 
require_once '../includes/db.php';     // Required for $conn
require_once '../includes/functions.php'; // Required for prepare_and_execute, set_flash_message, redirect etc.
require_once '../links.php'; // Import defined URL variables from links.php

// --- Helper Function: Display Flash Messages as Toasts ---
// This function is directly copied from dashboard.php to ensure consistent toast display.
// It's placed here because header.php is no longer used, and functions.php might not contain it.
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


$student_uid = getCurrentUserId();
$clg_id = getCurrentUserClgId();

$pageTitle = "My Profile - EduFlow"; // Renamed for consistency with dashboard.php

// --- Handle POST requests for updating profile or password ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- UPDATE PROFILE DETAILS ---
    if (isset($_POST['update_profile_details'])) {
        $name = trim($_POST['name']);
        $phone = trim($_POST['phone']);
        // Student specific fields
        $student_address = trim($_POST['student_address']);
        $father_name = trim($_POST['father_name']);
        // Add more fields from Students table as needed:
        // $adhaar_number = trim($_POST['adhaar_number']);
        // $pan_number = trim($_POST['pan_number']);
        // ... etc.

        $errors = [];
        if (empty($name)) $errors[] = "Name cannot be empty.";
        if (empty($phone)) $errors[] = "Phone number cannot be empty.";
        // Add more validation as needed for other fields

        if (empty($errors)) {
            $conn->begin_transaction();
            try {
                // Update Users table
                $sql_update_user = "UPDATE Users SET Name = ?, Phone = ? WHERE Uid = ?";
                $stmt_user = prepare_and_execute($conn, $sql_update_user, [$name, $phone, $student_uid], "ssi");
                
                // Update Students table (ensure record exists, though it should for a logged-in student)
                // For fields like address, Adhaar, PAN etc.
                // For MVP, let's assume these fields are nullable or can be empty strings
                $sql_update_student = "UPDATE Students SET Student_address = ?, Father_name = ? 
                                       WHERE Student_id = ? AND Clg_id = ?";
                // Add more placeholders and params for other fields
                $stmt_student = prepare_and_execute($conn, $sql_update_student, 
                    [$student_address, $father_name, $student_uid, $clg_id], 
                    "ssii" // Adjust type string if more params are added
                );

                // Check if any rows were actually affected or if there were errors
                if ($stmt_user->error || $stmt_student->error) {
                    throw new Exception("DB error: " . $stmt_user->error . $stmt_student->error);
                }
                
                $conn->commit();
                // Update session name if changed IMMEDIATELY after successful update
                if ($_SESSION['name'] !== $name) {
                    $_SESSION['name'] = $name;
                }
                set_flash_message("Profile details updated successfully!", "success");

            } catch (Exception $e) {
                $conn->rollback();
                set_flash_message("Error updating profile: " . $e->getMessage(), "danger");
            }
        } else {
            foreach ($errors as $error) {
                set_flash_message($error, "danger");
            }
        }
        redirect($student_profile); // Used the variable here
    }

    // --- CHANGE PASSWORD ---
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            set_flash_message("All password fields are required.", "danger");
        } elseif ($new_password !== $confirm_password) {
            set_flash_message("New password and confirm password do not match.", "danger");
        } elseif (strlen($new_password) < 6) {
            set_flash_message("New password must be at least 6 characters long.", "danger");
        } else {
            // Fetch current hashed password
            $stmt_fetch_pwd = prepare_and_execute($conn, "SELECT Pwd FROM Users WHERE Uid = ?", [$student_uid], "i");
            $user_pwd_data = $stmt_fetch_pwd->get_result()->fetch_assoc();
            $stmt_fetch_pwd->close();

            if ($user_pwd_data && password_verify($current_password, $user_pwd_data['Pwd'])) {
                // Current password is correct, hash and update new password
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                $sql_update_pwd = "UPDATE Users SET Pwd = ? WHERE Uid = ?";
                $stmt_update_pwd = prepare_and_execute($conn, $sql_update_pwd, [$hashed_new_password, $student_uid], "si");

                if ($stmt_update_pwd->affected_rows > 0) {
                    set_flash_message("Password changed successfully!", "success");
                } else {
                    set_flash_message("Error changing password. Please try again.", "danger");
                }
                $stmt_update_pwd->close();
            } else {
                set_flash_message("Incorrect current password.", "danger");
            }
        }
        redirect($student_profile); // Used the variable here
    }
}


// Fetch student's current profile data
$sql_profile = "SELECT u.Name, u.Email, u.Phone, u.Created_at as UserRegisteredAt,
                        s.Student_address, s.Father_name,
                        cl.Clg_name, c.Class_name, c.Semester, c.Session
                FROM Users u
                JOIN Students s ON u.Uid = s.Student_id
                JOIN Clgs cl ON s.Clg_id = cl.Clg_id
                LEFT JOIN Classes c ON s.Class_id = c.Class_id AND s.Clg_id = c.Clg_id
                WHERE u.Uid = ? AND s.Clg_id = ?";
$stmt_profile = prepare_and_execute($conn, $sql_profile, [$student_uid, $clg_id], "ii");
$profile_data = $stmt_profile->get_result()->fetch_assoc();
$stmt_profile->close();

if (!$profile_data) {
    // This should ideally not happen if student_auth.php works correctly and student data is consistent
    set_flash_message("Could not retrieve your profile information. Please contact support.", "danger");
    // For now, we'll let the page render with empty fields if it gets here.
}
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

    <!-- CUSTOM CSS (Combined and adapted from dashboard.php) -->
    <style>
        /* Custom Color Variables - Shades of Blue and White */
        :root {
            --docmg-white: #ffffff;
            --docmg-lightest-blue: #e0f2f7;   /* Very light sky blue for background */
            --docmg-light-blue: #add8e6;      /* Light blue borders/accents */
            --docmg-medium-blue: #87ceeb;    /* Sky blue for secondary buttons/highlights */
            --docmg-primary-blue: #007bff;   /* Standard Bootstrap primary blue */
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

        /* Specific fixed size for dashboard logo - Not used on profile page, but keep the class in CSS */
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

        /* Dashboard Specific Statistic Card Styles (reused for profile detail cards) */
        .stats-card {
            background-color: var(--docmg-white);
            border: 1px solid var(--docmg-light-blue);
            border-radius: 0.75rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem; /* Ensure spacing between rows */
            box-shadow: 0 0.25rem 0.75rem rgba(0, 0, 0, 0.08);
            transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
            display: flex; /* Make it a flex container */
            flex-direction: column; /* Stack content vertically */
            justify-content: space-between; /* Pushes content to top/bottom */
           
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
        
        /* Ensure card headers align within stats-card */
        .stats-card .card-header {
            background-color: transparent !important; /* Make transparent to show main card background */
            border-bottom: 1px solid var(--docmg-light-blue) !important; /* Add subtle border */
            padding-bottom: 0.75rem; /* Spacing below header */
        }


        /* See All Stats Link - Not directly used on profile, but keep the class in CSS */
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
                        <a class="nav-link docmg-nav-link" href="<?= $student_home_page ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link docmg-nav-link active" href="<?= $student_profile ?>">My Profile</a>
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
                <!-- Page Title -->
                <h1 class="mb-4 text-primary fw-bold">My Profile</h1>

                <p class="lead mb-4 text-muted">Here you can view and update your personal details and change your password.</p>

                <!-- Row with align-items-stretch for consistent column heights -->
                <div class="row align-items-stretch"> 
                    <!-- Profile Details Form -->
                    <div class="col-md-7 d-flex"> <!-- d-flex to make child card expand vertically -->
                        <div class="card stats-card flex-grow-1 text-start"> <!-- flex-grow-1 to fill available vertical space -->
                            <div class="card-header">
                                <h5 class="text-docmg-blue-6 mb-0"><i class="bi bi-person-lines-fill"></i> Edit Profile Details</h5>
                            </div>
                            <div class="card-body">
                                <?php if ($profile_data): ?>
                                <!-- Form action remains 'profile.php' as it posts to itself -->
                                <form method="POST" action="profile.php"> 
                                    <div class="mb-3">
                                        <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($profile_data['Name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email (Cannot Change)</label>
                                        <input type="email" class="form-control" id="email" name="email_display" 
                                               value="<?php echo htmlspecialchars($profile_data['Email'] ?? ''); ?>" readonly>
                                    </div>
                                    <div class="mb-3">
                                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($profile_data['Phone'] ?? ''); ?>" required>
                                    </div>

                                    <hr>
                                    <h5 class="text-docmg-blue-6">College Specific Information</h5>
                                    <p class="text-muted">
                                        <strong>College:</strong> <?php echo htmlspecialchars($profile_data['Clg_name'] ?? 'N/A'); ?><br>
                                        <strong>Class:</strong> 
                                        <?php 
                                            // Handle case where Class_id is missing/null, display 'Not Assigned'
                                            echo htmlspecialchars($profile_data['Class_name'] ?? 'Not Assigned'); 
                                            if (!empty($profile_data['Semester'])) echo " - Sem " . htmlspecialchars($profile_data['Semester']);
                                            if (!empty($profile_data['Session'])) echo " (" . htmlspecialchars($profile_data['Session']) . ")";
                                        ?>
                                    </p>

                                    <div class="mb-3">
                                        <label for="student_address" class="form-label">Address</label>
                                        <textarea class="form-control" id="student_address" name="student_address" rows="3"><?php echo htmlspecialchars($profile_data['Student_address'] ?? ''); ?></textarea>
                                    </div>
                                     <div class="mb-3">
                                        <label for="father_name" class="form-label">Father's Name</label>
                                        <input type="text" class="form-control" id="father_name" name="father_name" 
                                               value="<?php echo htmlspecialchars($profile_data['Father_name'] ?? ''); ?>">
                                    </div>
                                    <!-- Add more fields here as needed from Students table (Adhaar, PAN, Occupation etc.) -->
                                    <button type="submit" name="update_profile_details" class="btn btn-primary">
                                        <i class="bi bi-check-circle"></i> Save Profile Changes <!-- Changed icon to bi-check-circle -->
                                    </button>
                                </form>
                                <?php else: ?>
                                    <div class="alert alert-warning">Profile data not available.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Change Password Form and Account Info -->
                    <div class="col-md-5 d-flex flex-column"> <!-- Use flex-column to manage vertical spacing between cards -->
                        <div class="card stats-card flex-grow-1 text-start mb-4"> <!-- mb-4 provides space between this and the next card -->
                            <div class="card-header">
                                <h5 class="text-docmg-blue-6 mb-0"><i class="bi bi-shield-lock-fill"></i> Change Password</h5>
                            </div>
                            <div class="card-body">
                                <!-- Form action remains 'profile.php' as it posts to itself -->
                                <form method="POST" action="profile.php">
                                    <div class="mb-3">
                                        <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="current_password" name="current_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="new_password" name="new_password" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                    </div>
                                    <button type="submit" name="change_password" class="btn btn-warning">
                                        <i class="bi bi-key-fill"></i> Change Password
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php if ($profile_data): ?>
                        <div class="card stats-card flex-grow-1 text-start"> <!-- Now has stats-card and flex-grow-1 -->
                            <div class="card-header">
                                <h5 class="text-docmg-blue-6 mb-0">Account Information</h5> <!-- Apply consistent header styling -->
                            </div>
                            <div class="card-body">
                                   <!-- Changed to a simple paragraph or div if more structured, but <p> is fine. -->
                                   <p class="card-text"><strong>Registered On:</strong> <span class="text-muted"><?php echo date("F j, Y, h:i A", strtotime($profile_data['UserRegisteredAt'])); ?></span></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
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