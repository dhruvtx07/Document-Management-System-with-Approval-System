<?php
require_once '../includes/student_auth.php';
// We will remove require_once '../includes/header.php'; and '../includes/footer.php';
// as we are copying the full HTML structure from dashboard.php

// --- IMPORTANT: Production Error Reporting Settings ---
// These settings should NOT be in production code. They expose sensitive information.
// For production, configure php.ini or a central config file:
// display_errors = Off
// log_errors = On
// error_log = /path/to/your/php-error.log
// error_reporting = E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_STRICT
// --- End debug settings ---

// Set the default timezone for all date/time functions
// This is crucial for localizing time() and strtotime() interpretations
date_default_timezone_set('Asia/Kolkata');

// Required includes for database connection, general functions, and now links definitions
require_once '../includes/db.php';     // Required for $conn
require_once '../includes/functions.php'; // Required for prepare_and_execute, set_flash_message, redirect etc.
require_once '../links.php'; // Import defined URL variables from links.php

// Utility function (assuming it's not already in includes/header.php or utils.php)
// It's generally better to place reusable functions like this in functions.php
if (!function_exists('formatSizeUnits')) {
    function formatSizeUnits($bytes) {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }
        return $bytes;
    }
}

// --- Helper Function: Display Flash Messages as Toasts ---
// Copied from dashboard.php to ensure consistent toast display.
// It's generally better to place reusable functions like this in functions.php
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
// NEW: Fetch the Class_id for the current student
$student_class_id = getCurrentUserClassId(); // Assuming this function exists.

$folder_id = filter_input(INPUT_GET, 'folder_id', FILTER_VALIDATE_INT);
$doc_id = filter_input(INPUT_GET, 'doc_id', FILTER_VALIDATE_INT);
$submission_id = filter_input(INPUT_GET, 'submission_id', FILTER_VALIDATE_INT); // Optional, for viewing/editing existing

if (!$folder_id || !$doc_id) {
    set_flash_message("Folder or Document ID not specified.", "danger");
    redirect($student_home_page); // Use link variable
}

// Define Project Root consistently. This should resolve to your main application directory.
if (!defined('PROJECT_ROOT')) {
    define('PROJECT_ROOT', dirname(dirname(__FILE__)));
}

// Fetch Folder and Document Type details (and ensure student is mapped to this folder)
$sql_context = "SELECT f.Folder_name, d.Doc_name, d.Doc_desc, d.Doc_ext, d.Doc_max_size,
                             fdm.Submit_before, fdm.Is_mandate,
                             fmts.Folder_mapping_id
                FROM Folder f
                JOIN doc d ON d.Clg_id = f.Clg_id
                JOIN Folder_doc_mapping fdm ON fdm.Folder_id = f.Folder_id AND fdm.Doc_id = d.Doc_id
                JOIN Folder_mapping_to_students fmts ON fmts.Folder_id = f.Folder_id AND fmts.Student_id = ?
                WHERE f.Folder_id = ? AND d.Doc_id = ? AND f.Clg_id = ?
                  AND f.Is_active = 'y' AND d.Is_active = 'y' AND fdm.Is_active = 'y' AND fmts.Is_active = 'y'";

$stmt_context = prepare_and_execute($conn, $sql_context, [$student_uid, $folder_id, $doc_id, $clg_id], "iiii");
$context_data = $stmt_context->get_result()->fetch_assoc();
$stmt_context->close();

if (!$context_data) {
    set_flash_message("The requested document or folder is not assigned to you, is inactive, or does not exist.", "danger");
    redirect($student_home_page); // Use link variable
}

$page_title = "Submit: " . htmlspecialchars($context_data['Doc_name']) . " for " . htmlspecialchars($context_data['Folder_name']);

// --- Correctly process allowed extensions for validation ---
$raw_doc_ext = strtolower($context_data['Doc_ext']);
$cleaned_doc_ext_for_validation = str_replace([' ', '.'], '', $raw_doc_ext);
$allowed_extensions = explode(',', $cleaned_doc_ext_for_validation);

// Define allowed MIME types for better security and actual file content validation
$allowed_mimes_map = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
    'xls'  => ['application/vnd.ms-excel'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
    'ppt'  => ['application/vnd.ms-powerpoint'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    'txt'  => ['text/plain'],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'gif'  => ['image/gif'],
    // Add more as needed, ensure you have the correct MIME types for each extension
];

$display_extensions = [];
$accept_attribute_extensions = [];
foreach ($allowed_extensions as $ext) {
    if (!empty($ext)) {
        $display_extensions[] = '.' . $ext;
        $accept_attribute_extensions[] = '.' . $ext;
    }
}
$display_allowed_types = implode(', ', $display_extensions);
$accept_attribute_types = implode(',', $accept_attribute_extensions);
// --- End extension processing ---

$max_file_size_bytes = $context_data['Doc_max_size'];

// Fetch existing submission details if submission_id is provided, or the latest one otherwise
$existing_submission = null;
if ($submission_id) {
    $sql_fetch_submission = "SELECT fdss.*, u_commenter.Name as CommenterName
                             FROM Folder_docs_submitted_students fdss
                             LEFT JOIN Users u_commenter ON fdss.Commented_by = u_commenter.Uid
                             WHERE fdss.folder_doc_submission_id = ? AND fdss.Student_id = ? AND fdss.Folder_id = ? AND fdss.Doc_id = ? AND fdss.Clg_id = ? AND fdss.Is_active = 'y'";
    $stmt_fetch_sub = prepare_and_execute($conn, $sql_fetch_submission, [$submission_id, $student_uid, $folder_id, $doc_id, $clg_id], "iiiii");
    $existing_submission = $stmt_fetch_sub->get_result()->fetch_assoc();
    $stmt_fetch_sub->close();

    if (!$existing_submission) {
        set_flash_message("The specified submission record was not found or is inactive. Attempting to fetch the latest active submission.", "warning");
        $submission_id = null; // Fallback to fetching latest
    }
}

if (!$submission_id) { // If no specific submission was found or none initially provided
    $sql_fetch_latest_submission = "SELECT fdss.*, u_commenter.Name as CommenterName
                                 FROM Folder_docs_submitted_students fdss
                                 LEFT JOIN Users u_commenter ON fdss.Commented_by = u_commenter.Uid
                                 WHERE fdss.Student_id = ? AND fdss.Folder_id = ? AND fdss.Doc_id = ? AND fdss.Clg_id = ? AND fdss.Is_active = 'y'
                                 ORDER BY fdss.submitted_at DESC LIMIT 1";
    $stmt_fetch_latest_sub = prepare_and_execute($conn, $sql_fetch_latest_submission, [$student_uid, $folder_id, $doc_id, $clg_id], "iiii");
    $existing_submission = $stmt_fetch_latest_sub->get_result()->fetch_assoc();
    $stmt_fetch_latest_sub->close();
    if ($existing_submission) {
        $submission_id = $existing_submission['folder_doc_submission_id']; // Update $submission_id
    }
}

$is_overdue = false;
$is_readonly_mode = false;
$reason_cant_submit = "";

// Due date check - Check if current time is past the submit_before time
// time() and strtotime() will now use the 'Asia/Kolkata' timezone
if ($context_data['Submit_before'] && time() > strtotime($context_data['Submit_before'])) {
    // A submission is considered "overdue" if it's past the deadline AND not yet approved.
    if (!$existing_submission || ($existing_submission && $existing_submission['Is_accepted'] !== 'y')) {
        $is_overdue = true;
    }
}

// Determine if the form should be read-only (prevents new uploads)
if ($existing_submission && $existing_submission['Is_accepted'] === 'y') {
    $is_readonly_mode = true; // Document is approved
    $reason_cant_submit = "This document has been approved and cannot be re-submitted. You can only view the submission.";
} elseif ($is_overdue) {
    // If it's overdue AND not approved (new or rejected/pending), prevent submission
    $is_readonly_mode = true;
    $reason_cant_submit = "The deadline for this document has passed. No new submissions can be made.";
}


// --- Handle POST request for File Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_document_file'])) {

    // Prevent submission if in read-only mode (approved or overdue and not approved)
    if ($is_readonly_mode) {
        set_flash_message("Submission not allowed: " . $reason_cant_submit, "danger");
        redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
    }

    if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] != UPLOAD_ERR_OK) {
        $uploadError = $_FILES['document_file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errorMessage = "No file uploaded or an error occurred during upload: ";
        switch ($uploadError) {
            case UPLOAD_ERR_INI_SIZE:   $errorMessage .= "The uploaded file exceeds the upload_max_filesize directive in php.ini."; break;
            case UPLOAD_ERR_FORM_SIZE:  $errorMessage .= "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form."; break;
            case UPLOAD_ERR_PARTIAL:    $errorMessage .= "The uploaded file was only partially uploaded."; break;
            case UPLOAD_ERR_NO_FILE:    $errorMessage .= "No file was uploaded."; break;
            case UPLOAD_ERR_NO_TMP_DIR: $errorMessage .= "Missing a temporary folder (PHP's upload_tmp_dir)."; break;
            case UPLOAD_ERR_CANT_WRITE: $errorMessage .= "Failed to write file to disk. (Check permissions on temporary dir)."; break;
            case UPLOAD_ERR_EXTENSION:  $errorMessage .= "A PHP extension stopped the file upload. (This usually means a misconfigured extension)."; break;
            default:                    $errorMessage .= "An unknown error occurred (Error code: " . $uploadError . ")."; break;
        }
        set_flash_message($errorMessage, "danger");
    } else {
        $file = $_FILES['document_file'];
        $fileName = $file['name'];
        $fileTmpName = $file['tmp_name'];
        $fileSize = $file['size'];
        // $fileType = $file['type']; // This is client-provided and unreliable
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        // 1. Validate file extension
        if (!in_array($fileExt, $allowed_extensions)) {
            set_flash_message("Invalid file type. Allowed extensions: " . htmlspecialchars($display_allowed_types) . ".", "danger");
        } 
        // 2. Validate file size
        elseif ($fileSize > $max_file_size_bytes) {
            set_flash_message("File is too large. Maximum size allowed: " . formatSizeUnits($max_file_size_bytes) . ". Your file: " . formatSizeUnits($fileSize), "danger");
        } 
        // All checks passed, proceed with upload
        else {
            $newFileName = uniqid('', true) . '.' . $student_uid . '.' . $folder_id . '.' . $doc_id . '.' . $fileExt;

            $physicalUploadBaseDir = PROJECT_ROOT . DIRECTORY_SEPARATOR . "assets" . DIRECTORY_SEPARATOR . "uploads";
            $physicalClgDir = $physicalUploadBaseDir . DIRECTORY_SEPARATOR . "clg_" . $clg_id;
            $physicalStudentDir = $physicalClgDir . DIRECTORY_SEPARATOR . "student_" . $student_uid;

            $destination = $physicalStudentDir . DIRECTORY_SEPARATOR . $newFileName;
            $db_document_url = "assets/uploads/clg_" . $clg_id . "/student_" . $student_uid . "/" . $newFileName;

            $permissions = 0755; // Changed from 0775 for safer permissions

            // Create directories if they don't exist
            if (!is_dir($physicalClgDir)) {
                @mkdir($physicalClgDir, $permissions, true); // @ suppresses warnings to handle manually
                if (!is_dir($physicalClgDir)) { // Check if creation was successful
                    $error = error_get_last();
                    set_flash_message("Failed to create college upload directory at `" . htmlspecialchars($physicalClgDir) . "`. Error: " . htmlspecialchars($error['message'] ?? 'Unknown error') . ". Check server permissions.", "danger");
                    redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
                }
            }
            if (!is_dir($physicalStudentDir)) {
                @mkdir($physicalStudentDir, $permissions, true);
                if (!is_dir($physicalStudentDir)) {
                    $error = error_get_last();
                    set_flash_message("Failed to create student upload directory at `" . htmlspecialchars($physicalStudentDir) . "`. Error: " . htmlspecialchars($error['message'] ?? 'Unknown error') . ". Check server permissions.", "danger");
                    redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
                }
            }

            if (!is_writable($physicalStudentDir)) {
                set_flash_message("The destination directory is not writable. Please check file permissions of `" . htmlspecialchars($physicalStudentDir) . "`.", "danger");
                redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
            }

            // Attempt to move the uploaded file
            if (move_uploaded_file($fileTmpName, $destination)) {
                // Perform actual MIME type validation
                if (!extension_loaded('fileinfo')) {
                    set_flash_message("PHP fileinfo extension is not enabled. Cannot perform advanced MIME type validation.", "warning");
                } else {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE); // Return MIME type
                    $actual_mime_type = finfo_file($finfo, $destination);
                    finfo_close($finfo);

                    if (!isset($allowed_mimes_map[$fileExt]) || !in_array($actual_mime_type, $allowed_mimes_map[$fileExt])) {
                        // Mismatch between extension and actual MIME type, or MIME type not allowed for this extension
                        unlink($destination); // Delete the suspicious file
                        set_flash_message("File content type mismatch or not allowed. Expected " . implode(' or ', $allowed_mimes_map[$fileExt] ?? ['N/A']) . " for ." . $fileExt . ", but detected " . htmlspecialchars($actual_mime_type) . ".", "danger");
                        redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
                    }
                }

                // If file is moved and validated, proceed with database transaction
                $conn->begin_transaction();
                try {
                    $new_status_text = "Submitted";
                    $new_is_accepted = 'pending';

                    if ($existing_submission) {
                        // UPDATE existing submission
                        $sql_update_db = "UPDATE Folder_docs_submitted_students
                                         SET document_URL = ?, file_name = ?, file_size = ?, file_type = ?,
                                             Is_accepted = ?, Comments = NULL, Commented_by = NULL, reviewed_at = NULL,
                                             Status = ?, submitted_at = NOW(), submitted_by = ?, Class_id = ?
                                         WHERE folder_doc_submission_id = ? AND Student_id = ? AND Clg_id = ? AND Folder_id = ? AND Doc_id = ?";
                        $stmt_db = prepare_and_execute($conn, $sql_update_db,
                            [$db_document_url, $fileName, $fileSize, $actual_mime_type, $new_is_accepted, $new_status_text, $student_uid, $student_class_id, $existing_submission['folder_doc_submission_id'], $student_uid, $clg_id, $folder_id, $doc_id],
                            "ssisssiiiiiii" // s(url), s(filename), i(filesize), s(filetype), s(is_accepted), s(status), i(submitted_by), i(class_id), i(submission_id), i(student_id), i(clg_id), i(folder_id), i(doc_id)
                        );
                    } else {
                        // INSERT new submission
                        $sql_insert_db = "INSERT INTO Folder_docs_submitted_students
                                         (Clg_id, Class_id, Student_id, Folder_id, Doc_id, document_URL, file_name, file_size, file_type,
                                          Is_accepted, Status, submitted_by, submitted_at, Is_active)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'y')";
                        $stmt_db = prepare_and_execute($conn, $sql_insert_db,
                            [$clg_id, $student_class_id, $student_uid, $folder_id, $doc_id, $db_document_url, $fileName, $fileSize, $actual_mime_type, $new_is_accepted, $new_status_text, $student_uid],
                            "iiiiisssissi" // i(clg_id), i(class_id), i(student_id), i(folder_id), i(doc_id), s(url), s(filename), i(filesize), s(filetype), s(is_accepted), s(status), i(submitted_by)
                        );
                        $submission_id = $conn->insert_id; // Get the ID of the newly inserted submission
                    }

                    if ($stmt_db->affected_rows > 0) {
                        $conn->commit();
                        set_flash_message("Document '" . htmlspecialchars($fileName) . "' submitted successfully! It is now pending review.", "success");
                        // We redirect back to the submit_document page to show updated status
                        // and ensure the form isn't re-submitted on refresh.
                        redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
                    } else {
                        throw new Exception("Database update/insert failed (0 affected rows). Last error: " . $conn->error);
                    }
                    $stmt_db->close();

                } catch (Exception $e) {
                    $conn->rollback();
                    set_flash_message("An error occurred while saving submission details: " . $e->getMessage(), "danger");
                    if (file_exists($destination)) {
                        unlink($destination); // Clean up the physically uploaded file
                    }
                }
            } else {
                 set_flash_message("Failed to upload file to the server. Check file permissions or server space.", "danger");
            }
        }
    }
    // Redirect ensures flash message is displayed and form isn't re-submitted on refresh
    redirect($submit_documents . '?' . http_build_query(['folder_id' => $folder_id, 'doc_id' => $doc_id, 'submission_id' => $submission_id]));
}
$student_name = htmlspecialchars($_SESSION['name'] ?? 'Student'); // Assuming $_SESSION['name'] holds the student's name
$clg_name_stmt = prepare_and_execute($conn, "SELECT Clg_name FROM Clgs WHERE Clg_id = ?", [$clg_id], "i");
$clg_info = $clg_name_stmt->get_result()->fetch_assoc();
$clg_name = $clg_info ? $clg_info['Clg_name'] : 'Your College';
$clg_name_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
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

        /* Dashboard Specific Statistic Card Styles (not all used on this page, but good to keep consistent) */
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

        /* See All Stats Link (not directly used on this page, but good to keep consistent) */
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

        /* Specific styling for the document list within folder cards (not directly used on this page) */
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
                        <a class="nav-link docmg-nav-link" href="<?= $student_home_page ?>">Home</a>
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
            <div class="card-body">
                <!-- Start of original submit_document.php content -->
                <h2 class="text-docmg-blue-6"><?php echo $page_title; ?></h2>
                <p>
                    <a href="<?= $student_home_page ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left-circle"></i> Back to Dashboard
                    </a>
                </p>

                <div class="card">
                    <div class="card-header">Document Details & Submission</div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-7">
                                <p><strong>Document Type:</strong> <?php echo htmlspecialchars($context_data['Doc_name']); ?></p>
                                <?php if ($context_data['Doc_desc']): ?>
                                    <p class="text-muted"><small><?php echo nl2br(htmlspecialchars($context_data['Doc_desc'])); ?></small></p>
                                <?php endif; ?>
                                <p>
                                    <strong>Allowed File Types:</strong> <?php echo htmlspecialchars($display_allowed_types); ?><br>
                                    <strong>Maximum File Size:</strong> <?php echo formatSizeUnits($max_file_size_bytes); ?>
                                </p>
                                <?php if ($context_data['Is_mandate'] == 'y'): ?>
                                    <p><span class="badge bg-danger">Mandatory</span></p>
                                <?php endif; ?>
                                <?php if ($context_data['Submit_before']): ?>
                                    <p class="text-<?php echo $is_overdue ? 'danger fw-bold' : 'warning'; ?>">
                                        <strong>Due Date:</strong> <?php echo date("l, F j, Y, g:i A", strtotime($context_data['Submit_before'])); ?>
                                        <?php if ($is_overdue) echo " (Past Due!)"; ?>
                                    </p>
                                <?php endif; ?>

                                <hr>
                                <?php if ($existing_submission): ?>
                                    <h4>Current Submission Status:</h4>
                                    <p>
                                        <strong>Status:</strong>
                                        <span class="badge bg-<?php
                                            if ($existing_submission['Is_accepted'] === 'y') echo 'success';
                                            elseif ($existing_submission['Is_accepted'] === 'n') echo 'danger';
                                            else echo 'warning text-dark'; ?>">
                                            <?php
                                            if ($existing_submission['Is_accepted'] === 'y') echo 'Approved';
                                            elseif ($existing_submission['Is_accepted'] === 'n') echo 'Rejected';
                                            else echo 'Pending Review';
                                            ?>
                                        </span>
                                        <?php if ($existing_submission['Status']): echo ' (' . htmlspecialchars($existing_submission['Status']) . ')'; endif; ?>
                                    </p>
                                    <p><strong>Submitted File:</strong>
                                        <a href="<?= $base_url . htmlspecialchars($existing_submission['document_URL']); ?>" target="_blank">
                                            <?php echo htmlspecialchars($existing_submission['file_name']); ?>
                                        </a>
                                            (<?php echo formatSizeUnits($existing_submission['file_size']); ?>)
                                    </p>
                                    <p><strong>Submitted At:</strong> <?php echo date("F j, Y, g:i a", strtotime($existing_submission['submitted_at'])); ?></p>

                                    <?php if (!empty($existing_submission['Comments'])): ?>
                                        <p><strong>Admin Comments (by <?php echo htmlspecialchars($existing_submission['CommenterName'] ?? 'Admin'); ?>):</strong></p>
                                        <div class="alert alert-info">
                                            <?php echo nl2br(htmlspecialchars($existing_submission['Comments'])); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p class="alert alert-secondary">You have not submitted this document yet.</p>
                                <?php endif; ?>

                                <?php if (!$is_readonly_mode): ?>
                                    <hr>
                                    <h4><?php echo $existing_submission ? 'Upload New Version (Re-submit Document)' : 'Upload Document'; ?></h4>
                                    <?php if ($existing_submission && $existing_submission['Is_accepted'] === 'n'): ?>
                                        <p class="alert alert-danger">Your previous submission was rejected. Please review comments and upload a corrected version.</p>
                                    <?php elseif ($existing_submission && $existing_submission['Is_accepted'] === 'pending'): ?>
                                        <p class="alert alert-warning">Your document is pending review. Uploading a new file will replace the current one.</p>
                                    <?php endif; ?>

                                    <form method="POST" action="<?= $submit_documents ?>?folder_id=<?php echo $folder_id; ?>&doc_id=<?php echo $doc_id; ?><?php if($submission_id) echo '&submission_id='.$submission_id; ?>" enctype="multipart/form-data">
                                        <div class="mb-3">
                                            <label for="document_file" class="form-label">Select File:</label>
                                            <input type="file" class="form-control" id="document_file" name="document_file" required
                                                            accept="<?php echo htmlspecialchars($accept_attribute_types); ?>">
                                            <small class="form-text text-muted">
                                                Ensure file type is one of: <?php echo htmlspecialchars($display_allowed_types); ?>
                                                and size is less than <?php echo formatSizeUnits($max_file_size_bytes); ?>.
                                            </small>
                                        </div>
                                        <button type="submit" name="submit_document_file" class="btn btn-primary">
                                            <i class="bi bi-upload"></i> <?php echo $existing_submission ? 'Submit New Version' : 'Submit Document'; ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <div class="alert alert-info mt-3"><?php echo htmlspecialchars($reason_cant_submit); ?></div>
                                <?php endif; ?>
                            </div>

                            <?php if ($existing_submission && !empty($existing_submission['document_URL'])): ?>
                            <div class="col-md-5">
                                <h5>Preview of Current Submission</h5>
                                <?php
                                $file_url = $base_url . htmlspecialchars($existing_submission['document_URL']); // Use $base_url
                                $file_ext_preview = strtolower(pathinfo($existing_submission['document_URL'], PATHINFO_EXTENSION));
                                if (in_array($file_ext_preview, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                                    <img src="<?php echo $file_url; ?>" class="img-fluid border" alt="Preview">
                                <?php elseif ($file_ext_preview === 'pdf'): ?>
                                    <iframe src="<?php echo $file_url; ?>" width="100%" height="600px" class="border"></iframe>
                                <?php else: ?>
                                    <p>No preview available for this file type. <a href="<?php echo $file_url; ?>" target="_blank">Download current submission</a>.</p>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- End of original submit_document.php content -->
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

            // Initialize Bootstrap tooltips
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            })
        });
    </script>
</body>
</html>