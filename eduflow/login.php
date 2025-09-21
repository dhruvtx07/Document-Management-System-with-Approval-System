<?php
// login.php

// Ensure session_start() is handled by db.php.
require_once 'includes/db.php';       // Ensures DB connection and session start, defines BASE_URL
require_once 'includes/functions.php'; // For redirect, set_flash_message, isLoggedIn, isAdmin, isStudent, etc.
require_once 'links.php';              // Include the file that defines all URL variables

// The BASE_URL check below is now redundant as it's defined reliably in db.php.
// If you uncommented this in your original file for testing, it can now be safely removed.
/*
if (!defined('BASE_URL')) {
    define('BASE_URL', '/'); // This line was problematic if your actual base is /eduflow/
}
*/

// If the user is already logged in, redirect them to their respective dashboard based on their role.
if (isLoggedIn()) {
    if (isAdmin()) redirect($admin_home_page);   // Use variable from links.php
    if (isStudent()) redirect($student_home_page); // Use variable from links.php
    // If somehow logged in but no role, it might be an unhandled state, redirect to index or logout.
    redirect($index_page); // Fallback: Redirect to index if logged in but no specific role-based dashboard applies
}

// Handle login form submission when the request method is POST.
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Sanitize and retrieve email and password from the POST request.
    $email = trim($_POST['email'] ?? ''); // Use null coalescing for robustness against undefined index
    $password = $_POST['password'] ?? '';

    // Validate if both email and password are provided.
    if (empty($email) || empty(strval($password))) { // Explicitly cast password to string for empty check
        set_flash_message("Email and password are required.", 'danger');
    } else {
        // Prepare a SQL statement to fetch user details including Is_active status.
        // We fetch all details now to potentially use them later, and check Is_active in PHP.
        $stmt = $conn->prepare("SELECT Uid, Name, Pwd, Email, Is_active FROM Users WHERE Email = ?");
        if (!$stmt) {
            // Log database preparation errors for debugging.
            error_log("EduFlow Login Query Prepare Failed: " . $conn->error);
            set_flash_message("A system error occurred. Please try again later.", 'danger');
        } else {
            // Bind the email parameter and execute the statement.
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            // Check if a user with the provided email was found.
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();

                // --- NEW FEEDBACK: Check if account is inactive (on hold) ---
                if ($user['Is_active'] == 'n') {
                    set_flash_message("Your account is currently inactive. Please contact support.", 'warning'); // Specific message for inactive
                }
                // --- If account is active, proceed to verify password ---
                else {
                    if (password_verify($password, $user['Pwd'])) {
                        // Authentication successful. Store basic user info in the session.
                        $_SESSION['uid'] = $user['Uid'];
                        $_SESSION['name'] = $user['Name'];
                        $_SESSION['email'] = $user['Email'];
                        $_SESSION['role'] = null; // Initialize role as null; will be set if admin/student.

                        // --- ADMIN LOGIN LOGIC ---
                        // Check if the user is an active administrator.
                        $stmt_admin = $conn->prepare("SELECT Clg_id FROM Admin WHERE Uid = ? AND Is_active = 'y'");
                        if ($stmt_admin) {
                            $stmt_admin->bind_param("i", $user['Uid']);
                            $stmt_admin->execute();
                            $admin_result = $stmt_admin->get_result();

                            if ($admin_result->num_rows > 0) {
                                // User is an admin. Set admin-specific session info and redirect.
                                $admin_data = $admin_result->fetch_assoc();
                                $_SESSION['role'] = 'admin';
                                $_SESSION['clg_id'] = $admin_data['Clg_id'];
                                if ($stmt_admin) $stmt_admin->close(); // Close admin statement early
                                set_flash_message('Welcome back, Admin!', 'success');
                                redirect($admin_home_page); // Use variable from links.php
                            }
                            if ($stmt_admin) $stmt_admin->close(); // Ensure closure if no redirect happened yet
                        } else {
                            error_log("Failed to prepare EduFlow admin check statement: " . $conn->error);
                        }

                        // --- STUDENT LOGIN LOGIC ---
                        // Only check for student role if the user hasn't been identified as an admin.
                        if ($_SESSION['role'] !== 'admin') {
                            $stmt_student = $conn->prepare("SELECT Clg_id, Class_id FROM Students WHERE Student_id = ? AND Is_active = 'y'");
                            if ($stmt_student) {
                                $stmt_student->bind_param("i", $user['Uid']);
                                $stmt_student->execute();
                                $student_result = $stmt_student->get_result();
                                if ($student_result->num_rows > 0) {
                                    // User is a student. Set student-specific session info and redirect.
                                    $student_data = $student_result->fetch_assoc();
                                    $_SESSION['role'] = 'student';
                                    $_SESSION['clg_id'] = $student_data['Clg_id'];
                                    $_SESSION['class_id'] = $student_data['Class_id'];
                                    if ($stmt_student) $stmt_student->close(); // Close student statement early
                                    set_flash_message('Welcome, Student!', 'success');
                                    redirect($student_home_page); // Use variable from links.php
                                }
                                if ($stmt_student) $stmt_student->close(); // Ensure closure if no redirect happened yet
                            } else {
                                error_log("Failed to prepare EduFlow student check statement: " . $conn->error);
                            }
                        }

                        // --- FALLBACK FOR USERS WITH NO ASSIGNED ROLE ---
                        // If the user was authenticated but has no active admin or student role.
                        if ($_SESSION['role'] === null) {
                            // Destroy session to prevent a user from having a uid/name/email but no role.
                            session_destroy();
                            $_SESSION = array(); // Clear the $_SESSION array as well post-destroy for immediate effect.
                            set_flash_message("Your EduFlow account was found, but no active role (Admin/Student) is assigned for any college. Please contact EduFlow support for assistance.", 'warning');
                            // No redirect here, stays on login page to show message.
                        }

                    } else {
                        // --- UPDATED FEEDBACK: Password does not match ---
                        // For security, give a generic "incorrect email/password" message
                        // to prevent user enumeration (telling an attacker if an email exists).
                        set_flash_message("Incorrect email or password.", 'danger');
                    }
                }
            } else {
                // --- UPDATED FEEDBACK: Email not found at all ---
                // For security, give a generic "incorrect email/password" message
                set_flash_message("Incorrect email or password.", 'danger');
            }
            // Ensure the main user lookup statement is closed.
            if ($stmt) $stmt->close();
        }
    }
    // No explicit redirect here after POST if an error occurs.
    // This allows the flash message to be displayed on the current login page.
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - EduFlow</title>
    <!-- Bootstrap CSS 5.3.3 from CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Embedded Custom CSS styles for EduFlow professional theme -->
    <style>
        /* Base styles for HTML and Body */
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
        }

        body {
            /* A clean, professional, light blue gradient for the background */
            background: linear-gradient(to right, #eef5ff 0%, #c4e1ff 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; /* Clean, modern font stack */
            color: #333; /* Default text color for better contrast on light backgrounds */
        }

        /* Container for full-page centering using flexbox */
        .container-fluid.d-flex {
            min-height: 100vh; /* Ensures the container takes full viewport height */
            padding: 15px; /* Add some padding on smaller screens */
            box-sizing: border-box; /* Include padding in element's total width and height */
        }

        /* Authentication Card Styling: White, rounded, soft shadow, elegant */
        .auth-card {
            background-color: rgba(255, 255, 255, 0.98); /* Near opaque white for crispness */
            border-radius: 12px; /* Softly rounded corners */
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08); /* A soft, subtle shadow */
            border: none; /* Remove default card border */
            overflow: hidden; /* Ensures contents respect border-radius */
            max-width: 480px; /* Max width for the card on larger screens */
            width: 100%; /* Ensures responsiveness on smaller screens */
            transition: box-shadow 0.3s ease-in-out; /* Smooth transition for any potential card shadow change */
        }

        /* Logo container */
        .logo-container {
            margin-bottom: 25px;
            padding-top: 15px; /* A little extra space above the logo for balance */
        }

        .logo-container img {
            max-width: 140px; /* Ideal max width for the logo */
            width: 100%; /* Allows it to scale down to 100% of its parent's width */
            height: auto; /* Crucial to maintain aspect ratio, prevents distortion */
            display: block; /* Ensures margin auto works for centering */
            margin: 0 auto; /* Center the logo horizontally */
            object-fit: contain; /* Ensures the entire image is visible, scaled to fit within its bounds */
        }

        /* Main Heading Style: Professional blue, bold, with clear spacing */
        .auth-card h3 {
            color: #0d6efd; /* Bootstrap primary blue */
            font-weight: 700; /* Extra bold for emphasis */
            margin-bottom: 1.75rem; /* More space below heading */
        }

        /* Custom Primary Button Style: EduFlow's brand blue with professional touches */
        .btn-eduflow-primary {
            background-color: #0d6efd; /* Bootstrap primary blue */
            border-color: #0d6efd;
            color: white;
            font-weight: 600; /* Medium bold for a professional feel */
            padding: 10px 15px;
            border-radius: 8px; /* Consistent rounded corners */
            transition: background-color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-eduflow-primary:hover {
            background-color: #0a58ca; /* Darker blue on hover */
            border-color: #0a53be;
            color: white; /* Keep text white */
            box-shadow: 0 4px 10px rgba(13, 110, 253, 0.2); /* Subtle shadow on hover */
        }

        .btn-eduflow-primary:focus {
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); /* Standard Bootstrap focus ring */
        }

        /* Form Labels: Slightly bolder with soft grey color */
        .form-label {
            font-weight: 500;
            color: #555; /* Soft dark grey for labels */
            margin-bottom: 0.4rem; /* Reduced space below label for a compact look */
        }

        /* Form Input Fields: Clean, consistent, and user-friendly */
        .form-control {
            border-radius: 8px; /* Consistent rounded corners */
            padding: 0.75rem 1rem; /* More vertical padding for better touch targets */
            border: 1px solid #ced4da; /* Standard, subtle border */
            box-shadow: none; /* No default shadow */
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .form-control:focus {
            border-color: #86b7fe; /* Light blue border on focus */
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25); /* Standard Bootstrap focus shadow */
            outline: 0; /* Remove default outline */
        }

        /* Form Links (Forgot Password, Register): Match primary blue, subtle hover effect */
        .form-links a {
            color: #0d6efd; /* Match primary blue */
            text-decoration: none; /* Default no underline */
            font-weight: 600; /* Medium bold */
            transition: color 0.2s ease, text-decoration 0.2s ease;
        }

        .form-links a:hover {
            color: #0a58ca; /* Darker on hover */
            text-decoration: underline; /* Underline on hover */
        }

        /* Footer Branding Text */
        .footer-branding {
            margin-top: 25px; /* Space above the text */
            font-size: 0.85rem;
            color: #777; /* Softer grey for subtle branding */
        }

        /* Toast notifications container positioning */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1080; /* Ensures toasts are above most content */
        }

        /* Specific toast styling for better integration with theme */
        .toast {
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
            border: none; /* Remove default toast border */
        }

        /* Ensure texts are visible based on background color */
        .toast.bg-success,
        .toast.bg-danger,
        .toast.bg-primary,
        .toast.bg-dark {
            color: #fff !important; /* White text for dark backgrounds */
        }

        .toast.bg-warning,
        .toast.bg-info,
        .toast.bg-light,
        .toast.bg-secondary {
            color: #333 !important; /* Dark text for light backgrounds */
        }

        .toast .btn-close {
            filter: invert(1); /* Invert color of close button to match text for dark backgrounds */
            opacity: 0.8;
            transition: opacity 0.2s ease;
        }

        .toast.bg-warning .btn-close,
        .toast.bg-info .btn-close,
        .toast.bg-light .btn-close,
        .toast.bg-secondary .btn-close {
            filter: none; /* Revert filter for light backgrounds */
            opacity: 0.8;
        }

        .toast .btn-close:hover {
            opacity: 1; /* Full opacity on hover */
        }

        /* Responsive adjustments for very small screens (e.g., portrait mobile phones) */
        @media (max-width: 575.98px) {
            .card-body {
                padding: 1.25rem !important; /* Slightly reduce padding on very small screens (from p-4/1.5rem to 1.25rem) */
            }
            .auth-card h3 {
                font-size: 1.6rem; /* Slightly smaller heading */
                margin-bottom: 1.25rem; /* Less space below heading */
            }
            .logo-container {
                margin-bottom: 18px; /* Reduce space below logo */
                padding-top: 10px; /* Reduce space above logo */
            }
            .btn-eduflow-primary {
                padding: 9px 14px; /* Slightly smaller button padding */
            }
            .form-links p {
                font-size: 0.95rem; /* Slightly smaller text for links */
            }
            .footer-branding {
                margin-top: 18px;
                font-size: 0.8rem;
            }
        }

        /* Responsive adjustments for large screens (Laptops, Desktops) to make it more compact */
        @media (min-width: 992px) {
            .auth-card .card-body {
                padding-top: 1.5rem !important; /* From p-md-5 (3rem) to 1.5rem */
                padding-bottom: 1.5rem !important; /* From p-md-5 (3rem) to 1.5rem */
                padding-right: 2.5rem !important; /* Keep horizontal padding generous */
                padding-left: 2.5rem !important; /* Keep horizontal padding generous */
            }
            .logo-container {
                margin-bottom: 1.25rem; /* Reduced from 25px (~1.56rem) to ~20px */
                padding-top: 1rem; /* Reduced from 15px (0.9375rem) to ~16px */
            }
            .auth-card h3 {
                margin-bottom: 1.25rem; /* Reduced from 1.75rem (~28px) to ~20px */
            }
            .mb-3 { /* Targets email input and login button */
                margin-bottom: 0.75rem !important; /* Reduced from 1rem (~16px) to ~12px */
            }
            .mb-4 { /* Targets password input */
                margin-bottom: 1rem !important; /* Reduced from 1.5rem (~24px) to ~16px */
            }
            .footer-branding {
                margin-top: 1.25rem; /* Reduced from 25px (~1.56rem) to ~20px */
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid d-flex justify-content-center align-items-center min-vh-100">
        <div class="card auth-card col-12 col-md-8 col-lg-5 col-xl-4">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="logo-container">
                    <!-- EduFlow Logo - Ensure 'logo.png' exists in your root directory -->
                    <img src="<?php echo $logo; ?>" alt="EduFlow Logo" class="img-fluid">
                </div>

                <h3 class="fw-bold">Login to Your EduFlow Account</h3>

                <!-- OPTION 1: In-Page Bootstrap Alert (Uncomment this section and remove the Toast container and JS to use) -->
                <?php if (isset($_SESSION['flash_message']) && !empty($_SESSION['flash_message'])): ?>
                    <div class="alert alert-<?php echo htmlspecialchars($_SESSION['flash_message_type']); ?> alert-dismissible fade show mb-4" role="alert">
                        <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php
                    // Unset messages here immediately if you use this option.
                    // If you're using toasts, leave unset at the bottom.
                    // unset($_SESSION['flash_message']);
                    // unset($_SESSION['flash_message_type']);
                    ?>
                <?php endif; ?>
                <!-- END OPTION 1 -->

                <form method="POST" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                    <div class="mb-3 text-start">
                        <label for="email" class="form-label">Email address</label>
                        <input type="email" class="form-control" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <!-- Preserve entered email on error -->
                    </div>
                    <div class="mb-4 text-start">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>

                    <button type="submit" class="btn btn-eduflow-primary w-100 mb-3">Login</button>

                    <div class="form-links text-center">
                        <p class="mb-2"><a href="<?php echo $forgot_pass; ?>" class="fw-bold">Forgot Password?</a></p>
                        <p class="mb-0">Don't have an account? <a href="<?php echo $register_page; ?>" class="fw-bold">Register here</a></p>
                    </div>
                    <p class="footer-branding">System by EduFlow</p>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS (Popper.js & Bootstrap JS) 5.3.3 from CDN -->
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTFyMfwGpOfVTAfKfwgy62mEkHj/K7PN+V6QvynwQ/hGU/fFDyS0s" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlcofrjXcdyBhufxze6LMP7g8NEnCqXpj/wS/K5fKFNbO/Zsx" crossorigin="anonymous"></script>

    <!--
        To use one of the alert/popup options below, you must comment out the other options
        and be sure to unset the session messages at the end of the PHP if block for flash messages.
    -->

    <?php if (isset($_SESSION['flash_message']) && !empty($_SESSION['flash_message'])): ?>
        <!-- OPTION 2: Browser Alert Pop-up (Uncomment this section and remove the Toast container and JavaScript to use) -->
        <!--
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Display the alert message
                alert("<?php echo htmlspecialchars($_SESSION['flash_message']); ?>");
            });
        </script>
        -->
        <!-- END OPTION 2 -->

        <!-- ORIGINAL TOAST CONTAINER AND JAVASCRIPT (Keep this uncommented for Toast functionality) -->
        <div aria-live="polite" aria-atomic="true" class="toast-container position-fixed top-0 end-0 p-3">
            <?php
            $alert_type = htmlspecialchars($_SESSION['flash_message_type']);
            // Determine text color based on background color for readability
            // Default to dark text for light types, white for dark types
            $text_class = match($alert_type) {
                'warning', 'info', 'light', 'secondary' => 'text-dark',
                default => 'text-white', // success, danger, primary, dark
            };
            ?>
            <div class="toast align-items-center bg-<?php echo $alert_type; ?> border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body <?php echo $text_class; ?>">
                        <?php echo htmlspecialchars($_SESSION['flash_message']); ?>
                    </div>
                    <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
                </div>
            </div>
        </div>

        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var toastElList = [].slice.call(document.querySelectorAll('.toast'));
                var toastList = toastElList.map(function (toastEl) {
                    // Autohide after 5 seconds (5000 milliseconds)
                    return new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
                });
                toastList.forEach(toast => toast.show()); // Show each toast
            });
        </script>
        <!-- END ORIGINAL TOAST CONTAINER AND JAVASCRIPT -->

        <?php
        // Unset messages after displaying to prevent them from showing up again on refresh for ANY of the options.
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_message_type']);
        ?>
    <?php endif; ?>
</body>
</html>