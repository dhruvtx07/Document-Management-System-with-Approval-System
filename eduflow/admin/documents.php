<?php
// documents.php - Page for managing document types with CRUD operations

// Ensure admin logged in and includes db.php, functions.php
// Assuming admin_auth.php sets up session, user ID, college ID, and includes db.php & functions.php
// It should also define BASE_URL
require_once '../includes/admin_auth.php';

// At this point, $conn (from db.php) should be available, and common functions
// like prepare_and_execute, set_flash_message, getCurrentUserClgId, getCurrentUserId should be available.
// And BASE_URL should be available.

// Explicitly use $conn if your db.php sets it to something like $db
require_once '../includes/db.php'; // This is included by admin_auth.php, but can stay for clarity if needed by other parts not related to auth.
require_once '../links.php'; // <--- ADDED: Include the links definition file

// --- Helper Functions (Expected to be in ../includes/functions.php) ---
// If 'prepare_and_execute', 'set_flash_message', 'redirect' etc. are not
// defined in functions.php, you MUST define them here or include a custom file.

/**
 * Fetches documents for a given college, with optional search, category filter, extension filter, size filter, and pagination.
 */
function getDocuments($conn, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size, $limit, $offset) {
    global $BASE_URL; // Assuming BASE_URL is for redirects, not used here for query itself.

    $sql = "SELECT Doc_id, Doc_type, Doc_name, Doc_desc, Doc_ext, Doc_max_size, Is_active FROM doc WHERE Clg_id = ?";
    $params = [$clg_id];
    $types = "i";

    // Text search on Doc_name and Doc_desc
    if (!empty($search_query)) {
        $sql .= " AND (Doc_name LIKE ? OR Doc_desc LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    // Category filter (Doc_type)
    if (!empty($selected_doc_types)) {
        $placeholders = implode(',', array_fill(0, count($selected_doc_types), '?'));
        $sql .= " AND Doc_type IN ($placeholders)";
        array_push($params, ...$selected_doc_types);
        $types .= str_repeat('s', count($selected_doc_types));
    }

    // Extension filter (Doc_ext)
    // THIS IS THE CRUCIAL PART FOR THE FIX
    if (!empty($selected_doc_extensions)) {
        $ext_conditions = [];
        // Important: For each selected extension, add a FIND_IN_SET condition.
        // LOWER(REPLACE(REPLACE(Doc_ext, '.', ''), ' ', '')) is the new normalization.
        // It removes dots, then ALL spaces, then lowercases.
        foreach ($selected_doc_extensions as $ext) {
            $ext_conditions[] = "FIND_IN_SET(?, LOWER(REPLACE(REPLACE(Doc_ext, '.', ''), ' ', '')))";
            $params[] = $ext;
            $types .= "s";
        }
        $sql .= " AND (" . implode(' OR ', $ext_conditions) . ")";
    }

    // Max Size filter (convert MB to Bytes for DB comparison)
    if ($min_max_size !== null) {
        $sql .= " AND Doc_max_size >= ?";
        $params[] = $min_max_size * 1024 * 1024;
        $types .= "d"; // 'd' for double/float type for bind_param
    }
    if ($max_max_size !== null) {
        $sql .= " AND Doc_max_size <= ?";
        $params[] = $max_max_size * 1024 * 1024;
        $types .= "d";
    }

    $sql .= " ORDER BY Doc_type, Doc_name LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    try {
        // For debugging: Temporarily uncomment these lines to see the SQL and params
        // error_log("getDocuments SQL: " . $sql);
        // error_log("getDocuments Params: " . print_r($params, true));
        // error_log("getDocuments Types: " . $types);

        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $result = $stmt->get_result();
        $documents = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $documents;
    } catch (Exception $e) {
        error_log("Error fetching documents: " . $e->getMessage());
        return []; // Return empty array on error
    }
}

/**
 * Counts total documents for a given college, with optional search, category filter, extension filter, and size filter.
 */
function countDocuments($conn, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size) {
    $sql = "SELECT COUNT(*) FROM doc WHERE Clg_id = ?";
    $params = [$clg_id];
    $types = "i";

    if (!empty($search_query)) {
        $sql .= " AND (Doc_name LIKE ? OR Doc_desc LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    if (!empty($selected_doc_types)) {
        $placeholders = implode(',', array_fill(0, count($selected_doc_types), '?'));
        $sql .= " AND Doc_type IN ($placeholders)";
        array_push($params, ...$selected_doc_types);
        $types .= str_repeat('s', count($selected_doc_types));
    }

    // THIS IS THE CRUCIAL PART FOR THE FIX
    if (!empty($selected_doc_extensions)) {
        $ext_conditions = [];
        foreach ($selected_doc_extensions as $ext) {
            $ext_conditions[] = "FIND_IN_SET(?, LOWER(REPLACE(REPLACE(Doc_ext, '.', ''), ' ', '')))";
            $params[] = $ext;
            $types .= "s";
        }
        $sql .= " AND (" . implode(' OR ', $ext_conditions) . ")";
    }

    if ($min_max_size !== null) {
        $sql .= " AND Doc_max_size >= ?";
        $params[] = $min_max_size * 1024 * 1024;
        $types .= "d";
    }
    if ($max_max_size !== null) {
        $sql .= " AND Doc_max_size <= ?";
        $params[] = $max_max_size * 1024 * 1024;
        $types .= "d";
    }

    try {
        // For debugging: Temporarily uncomment these lines to see the SQL and params
        // error_log("countDocuments SQL: " . $sql);
        // error_log("countDocuments Params: " . print_r($params, true));
        // error_log("countDocuments Types: " . $types);

        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Exception $e) {
        error_log("Error counting documents: " . $e->getMessage());
        return 0;
    }
}

/**
 * Retrieves all distinct document types (categories) for a given college.
 */
function getDistinctDocTypes($conn, $clg_id) {
    try {
        $stmt = prepare_and_execute($conn, "SELECT DISTINCT Doc_type FROM doc WHERE Clg_id = ? ORDER BY Doc_type", [$clg_id], "i");
        $result = $stmt->get_result();
        $types = [];
        while ($row = $result->fetch_row()) {
            if ($row[0] !== null && $row[0] !== '') { // Filter out null or empty types
                $types[] = $row[0];
            }
        }
        $stmt->close();
        return $types;
    } catch (Exception $e) {
        error_log("Error fetching distinct document types: " . $e->getMessage());
        return [];
    }
}

/**
 * Retrieves all distinct extensions from the Doc_ext column for a given college.
 * Handles comma-separated values and normalizes them (lowercase, no leading dot, no internal spaces).
 */
function getDistinctDocExtensions($conn, $clg_id) {
    try {
        $stmt = prepare_and_execute($conn, "SELECT DISTINCT Doc_ext FROM doc WHERE Clg_id = ?", [$clg_id], "i");
        $all_ext_strings = $stmt->get_result()->fetch_all(MYSQLI_NUM);
        $stmt->close();

        $unique_extensions = [];
        foreach ($all_ext_strings as $row) {
            $ext_string = $row[0];
            if ($ext_string) { // Only process non-empty strings
                // Normalize each part: lowercase, trim whitespace, remove leading dot, remove ANY spaces within the extension
                $parts = array_map(function($item) {
                    return strtolower(str_replace(' ', '', trim(ltrim($item, '.'))));
                }, explode(',', $ext_string));
                $unique_extensions = array_merge($unique_extensions, $parts);
            }
        }
        // Return only unique, non-empty extensions, sorted
        $filtered_unique_extensions = array_values(array_unique(array_filter($unique_extensions)));
        sort($filtered_unique_extensions);
        return $filtered_unique_extensions;
    } catch (Exception $e) {
        error_log("Error fetching distinct document extensions: " . $e->getMessage());
        return [];
    }
}

/**
 * Updates the Is_active status for one or more documents.
 */
function updateDocumentStatus($conn, array $doc_ids, $is_active_status, $clg_id) { // $clg_id moved to end for consistency
    if (empty($doc_ids)) {
        return 0; // No documents to update
    }
    if (!in_array($is_active_status, ['y', 'n'])) {
        error_log("Invalid Is_active status provided: " . $is_active_status);
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($doc_ids), '?'));
    $sql = "UPDATE doc SET Is_active = ? WHERE Clg_id = ? AND Doc_id IN ($placeholders)";
    // 's' for is_active_status, 'i' for clg_id, then 'i' for each doc_id
    $types = "si" . str_repeat('i', count($doc_ids));
    $params = array_merge([$is_active_status, $clg_id], $doc_ids);

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $affected_rows;
    } catch (Exception $e) {
        error_log("Error updating document status: " . $e->getMessage());
        return false;
    }
}

/**
 * Hard deletes documents by Doc_id. USE WITH EXTREME CAUTION.
 */
function hardDeleteDocuments($conn, array $doc_ids, $clg_id) { // $clg_id moved to end for consistency
    if (empty($doc_ids)) {
        return 0; // No documents to delete
    }

    $placeholders = implode(',', array_fill(0, count($doc_ids), '?'));
    $sql = "DELETE FROM doc WHERE Clg_id = ? AND Doc_id IN ($placeholders)";
    $types = "i" . str_repeat('i', count($doc_ids));
    $params = array_merge([$clg_id], $doc_ids);

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $affected_rows;
    } catch (Exception $e) {
        error_log("Error hard deleting documents: " . $e->getMessage());
        return false;
    }
}
// --- End Helper Functions ---


// Define page title
$pageTitle = "Manage Document Types - EduFlow"; // Updated Brand Name

// Get current user and college ID
$clg_id = getCurrentUserClgId();
$current_user_id = getCurrentUserId();
$current_user_name = htmlspecialchars($_SESSION['name'] ?? 'Admin'); // Assuming $_SESSION['name'] exists

// --- Configuration for Pagination ---
$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- Filter Parameters ---
// Capture current $_GET parameters to preserve filtration state across redirects
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_doc_types = isset($_GET['doc_types']) && is_array($_GET['doc_types']) ? $_GET['doc_types'] : [];
$selected_doc_types = array_map('trim', array_filter($selected_doc_types));

$selected_doc_extensions = isset($_GET['doc_extensions']) && is_array($_GET['doc_extensions']) ? $_GET['doc_extensions'] : [];
// Normalize the input filter extensions by trimming, lowercasing, and removing leading dots AND any spaces
$selected_doc_extensions = array_map(function($item) {
    return strtolower(str_replace(' ', '', trim(ltrim($item, '.'))));
}, array_filter($selected_doc_extensions));

$min_max_size = isset($_GET['min_size']) && is_numeric($_GET['min_size']) ? (float)$_GET['min_size'] : null;
$max_max_size = isset($_GET['max_size']) && is_numeric($_GET['max_size']) ? (float)$_GET['max_size'] : null;

// Define the base URL for this specific page now that BASE_URL is guaranteed
// BUG FIX: Use $_SERVER['PHP_SELF'] to get the exact absolute path to the current script
// This avoids potential double-prepends of the BASE_URL directory segment.
// Removed: $current_page_base_url = $_SERVER['PHP_SELF'];
// NEW: Using the predefined link from links.php
$current_page_base_url = $admin_documents;


// --- Handle Form Submissions (CRUD Actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Collect current filter/pagination state to redirect back to it.
    // http_build_query handles array parameters correctly.
    $refresh_params = array_filter([
        'search' => !empty($search_query) ? $search_query : null,
        'page' => $current_page > 1 ? $current_page : null,
        'doc_types' => !empty($selected_doc_types) ? $selected_doc_types : null,
        'doc_extensions' => !empty($selected_doc_extensions) ? $selected_doc_extensions : null,
        'min_size' => $min_max_size,
        'max_size' => $max_max_size,
    ]);
    // Construct the redirect URL using the defined current_page_base_url
    $redirect_url = $current_page_base_url;
    if (!empty($refresh_params)) {
        $redirect_url .= '?' . http_build_query($refresh_params);
    }

    try {
        switch ($action) {
            case 'add':
                $doc_name = trim($_POST['doc_name'] ?? '');
                $doc_type = trim($_POST['doc_type'] ?? '');
                $doc_desc = trim($_POST['doc_desc'] ?? '');
                // Normalize doc_ext input for storage before saving to DB
                $doc_ext = implode(',', array_map(function($item) {
                    return strtolower(str_replace(' ', '', trim(ltrim($item, '.'))));
                }, array_filter(explode(',', $_POST['doc_ext'] ?? ''))));

                $doc_max_size_mb = filter_input(INPUT_POST, 'doc_max_size', FILTER_VALIDATE_FLOAT);

                if (empty($doc_name) || empty($doc_type) || ($doc_max_size_mb === false || $doc_max_size_mb <= 0)) {
                    throw new Exception('All required fields must be filled and max size must be a positive number.');
                }
                $doc_max_size_bytes = $doc_max_size_mb * 1024 * 1024; // Convert MB to Bytes

                // Corrected type string to 'issssdi' for 7 parameters:
                // Clg_id (i), Doc_name (s), Doc_desc (s), Doc_type (s), Doc_ext (s), Doc_max_size (d), Created_by (i)
                $sql = "INSERT INTO doc (Clg_id, Doc_name, Doc_desc, Doc_type, Doc_ext, Doc_max_size, Is_active, Created_by, Created_at) VALUES (?, ?, ?, ?, ?, ?, 'y', ?, NOW())";
                $stmt = prepare_and_execute($conn, $sql, [
                    $clg_id, $doc_name, $doc_desc, $doc_type, $doc_ext, $doc_max_size_bytes, $current_user_id
                ], "issssdi");

                if ($stmt->affected_rows > 0) {
                    set_flash_message("Document type '{$doc_name}' added successfully!", "success");
                } else {
                    throw new Exception("Error adding document type: No rows affected.");
                }
                $stmt->close();
                redirect($redirect_url);
                break;

            case 'update':
                $doc_id = filter_input(INPUT_POST, 'doc_id', FILTER_VALIDATE_INT);
                $doc_name = trim($_POST['doc_name'] ?? '');
                $doc_type = trim($_POST['doc_type'] ?? '');
                $doc_desc = trim($_POST['doc_desc'] ?? '');
                // Normalize doc_ext input for storage before saving to DB
                $doc_ext = implode(',', array_map(function($item) {
                    return strtolower(str_replace(' ', '', trim(ltrim($item, '.'))));
                }, array_filter(explode(',', $_POST['doc_ext'] ?? ''))));

                $doc_max_size_mb = filter_input(INPUT_POST, 'doc_max_size', FILTER_VALIDATE_FLOAT);

                if (!$doc_id || empty($doc_name) || empty($doc_type) || ($doc_max_size_mb === false || $doc_max_size_mb <= 0)) {
                    throw new Exception('Invalid data for update.');
                }
                $doc_max_size_bytes = $doc_max_size_mb * 1024 * 1024; // Convert MB to Bytes

                $sql = "UPDATE doc SET Doc_name = ?, Doc_desc = ?, Doc_type = ?, Doc_ext = ?, Doc_max_size = ? WHERE Doc_id = ? AND Clg_id = ?";
                $stmt = prepare_and_execute($conn, $sql, [
                    $doc_name, $doc_desc, $doc_type, $doc_ext, $doc_max_size_bytes, $doc_id, $clg_id
                ], "ssssdii");

                if ($stmt->affected_rows > 0) {
                    set_flash_message("Document type '{$doc_name}' updated successfully!", "success");
                } else {
                    set_flash_message("No changes made to document type '{$doc_name}'.", "info");
                }
                $stmt->close();
                redirect($redirect_url);
                break;

            case 'bulk_deactivate':
            case 'bulk_activate':
                $selected_docs = $_POST['selected_docs'] ?? [];
                $doc_ids_to_process = array_filter(array_map('intval', $selected_docs));
                $new_status = ($action === 'bulk_activate') ? 'y' : 'n';

                if (empty($doc_ids_to_process)) {
                    set_flash_message("No document types selected.", "info");
                } else {
                    $affected = updateDocumentStatus($conn, $doc_ids_to_process, $new_status, $clg_id);
                    if ($affected !== false) {
                        set_flash_message("{$affected} document(s) status updated successfully!", "success");
                    } else {
                        throw new Exception("Failed to update selected document types.");
                    }
                }
                redirect($redirect_url);
                break;

            case 'bulk_hard_delete':
                $selected_docs = $_POST['selected_docs'] ?? [];
                $doc_ids_to_process = array_filter(array_map('intval', $selected_docs));

                if (empty($doc_ids_to_process)) {
                    set_flash_message("No document types selected for deletion.", "info");
                } else {
                    $affected = hardDeleteDocuments($conn, $doc_ids_to_process, $clg_id);
                    if ($affected !== false) {
                        set_flash_message("{$affected} document(s) permanently deleted!", "success");
                    } else {
                        throw new Exception("Failed to permanently delete selected document types.");
                    }
                }
                redirect($redirect_url);
                break;

            case 'toggle_active': // AJAX request for single toggle
                header('Content-Type: application/json');
                $doc_id = filter_input(INPUT_POST, 'doc_id', FILTER_VALIDATE_INT);
                $is_active = $_POST['is_active'] ?? 'n';

                if ($doc_id === false || !in_array($is_active, ['y', 'n'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid data for toggle.']);
                    exit();
                }
                // Pass $clg_id to the function
                $affected = updateDocumentStatus($conn, [$doc_id], $is_active, $clg_id);
                if ($affected > 0) {
                    echo json_encode(['success' => true, 'message' => 'Document status updated.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update document status.']);
                }
                exit();

            default:
                set_flash_message("Invalid action requested.", "warning");
                redirect($redirect_url);
                break;
        }
    } catch (Exception $e) {
        set_flash_message("Operation failed: " . $e->getMessage(), "danger");
        redirect($redirect_url); // Redirect even on error to prevent re-submission
    }
}

// --- Fetch Data for Display ---
$total_documents = countDocuments($conn, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size);
$total_pages = ceil($total_documents / $records_per_page);
$documents = getDocuments($conn, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size, $records_per_page, $offset);

$distinct_doc_types = getDistinctDocTypes($conn, $clg_id);
$distinct_doc_extensions = getDistinctDocExtensions($conn, $clg_id);

// Calculate stats for "Showing X of Y total records"
$start_record = $total_documents > 0 ? ($offset + 1) : 0;
$end_record = min(($current_page * $records_per_page), $total_documents);


// Helper function to build query parameters for pagination
function buildPaginationQuery($currentPage, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size) {
    global $admin_documents; // <--- MODIFIED: Use the global admin_documents URL from links.php
    $query_params = array_filter([
        'page' => $currentPage,
        'search' => !empty($search_query) ? $search_query : null,
        // Ensure array parameters are correctly preserved
        'doc_types' => !empty($selected_doc_types) ? $selected_doc_types : null,
        'doc_extensions' => !empty($selected_doc_extensions) ? $selected_doc_extensions : null,
        'min_size' => $min_max_size,
        'max_size' => $max_max_size,
    ]);
    return $admin_documents . (!empty($query_params) ? '?' . http_build_query($query_params) : ''); // <--- MODIFIED
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
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Bootstrap-select CSS for Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta3/dist/css/bootstrap-select.min.css">

    <!-- CUSTOM CSS -->
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

            /* Colors for new buttons introduced in manage_documents.php */
            --edu-insert-color: #007bff; /* primary */
            --edu-update-color: #ffc107; /* warning */
            --edu-delete-color: #dc3545; /* danger - for deactivation */
            --edu-hard-delete-color: #bb2d3b; /* darker danger */
            --edu-select-all-color: #6c757d; /* secondary */
            --edu-activate-color: #28a745; /* success */

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

        /* Specific fixed size for dashboard logo */
        .dashboard-logo {
            width: 250px;
            height: 100px;
            object-fit: contain;
            margin-bottom: 2rem;
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

        /* Dashboard Specific Statistic Card Styles (if repurposed, still valid) */
        .stats-card {
            background-color: var(--edu-white);
            border: 1px solid var(--edu-light-blue);
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
            transform: translateY(-8px);
            box-shadow: 0 0.75rem 1.75rem rgba(0, 0, 0, 0.15);
        }

        .stats-card .card-title {
            font-size: 1.35rem;
            font-weight: 600;
            color: var(--edu-dark-blue);
            margin-bottom: 0.75rem;
        }

        .stats-card .card-value {
            font-size: 3rem;
            font-weight: bold;
            color: var(--edu-primary-blue);
            margin-top: auto;
            line-height: 1;
        }

        .stats-card .card-text {
            color: var(--edu-secondary-text-color);
            font-size: 0.9em;
        }

        /* See All Stats Link */
        .see-all-link {
            color: var(--edu-primary-blue);
            font-weight: 600;
            transition: color 0.3s ease, text-decoration 0.3s ease;
            text-decoration: none;
            padding-right: 5px;
        }

        .see-all-link:hover {
            color: var(--edu-dark-blue);
            text-decoration: underline;
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

            /* No need for border-top for .navbar-collapse.show with new structure */
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
            }

            .page-header h1 {
                margin-bottom: 0;
                padding-left: 0.5rem;
                font-size: 2rem;
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
            }
            .search-area .btn-primary {
                width: 100%; /* Make search buttons full width on small screens */
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

        /* Specific styles for Manage Documents table */
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
        /* Custom Toggle Switch for Is_active */
        .form-check.form-switch {
            min-height: 1.5rem; /* Adjust as needed */
            padding-left: 3rem; /* Space for the toggle switch to the left of the label */
        }

        .form-check-input.edu-toggle {
            height: 1.25rem; /* Standard Bootstrap switch height */
            width: 2.25rem; /* Standard Bootstrap switch width */
            margin-left: -2.75rem; /* Align left */
            vertical-align: middle;
            background-color: var(--edu-delete-color); /* Red when off (inactive) */
            border-color: var(--edu-delete-color);
            transition: background-color 0.3s ease-in-out, border-color 0.3s ease-in-out;
            box-shadow: none; /* Remove default focus glow */
        }

        .form-check-input.edu-toggle:checked {
            background-color: var(--edu-activate-color); /* Green when on (active) */
            border-color: var(--edu-activate-color);
        }
        .form-check-input.edu-toggle:checked:hover {
            background-color: #218838; /* Darker green on hover */
            border-color: #218838;
        }
        .form-check-input.edu-toggle:focus {
            box-shadow: 0 0 0 .25rem rgb(0 123 255 / 25%); /* Custom focus glow, using Bootstrap's default primary */
        }
        /* Pagination styles */
        .pagination .page-item .page-link {
            color: var(--edu-primary-blue);
            border-color: var(--edu-light-blue);
            background-color: var(--edu-white);
        }
        .pagination .page-item.active .page-link {
            background-color: var(--edu-primary-blue);
            border-color: var(--edu-primary-blue);
            color: var(--edu-white);
        }
        .pagination .page-item.disabled .page-link {
            color: var(--edu-secondary-text-color);
            background-color: var(--edu-white);
            border-color: var(--edu-light-blue);
        }

        /* Custom Button Styles for CRUD actions */
        .crud-buttons .btn {
            padding: 0.75rem 1.25rem;
            border-radius: 0.5rem; /* Rounded corners for all these buttons */
            font-weight: 500;
            transition: all 0.2s ease-in-out;
            margin-right: 0.75rem; /* Add some margin between buttons */
            margin-bottom: 0.75rem; /* For responsive layout in case of wrapping */
        }

        .btn-insert {
            background-color: var(--edu-insert-color);
            color: var(--edu-white);
            border-color: var(--edu-insert-color);
        }
        .btn-insert:hover {
            background-color: var(--edu-dark-blue);
            border-color: var(--edu-dark-blue);
        }

        .btn-update {
            background-color: var(--edu-update-color);
            color: var(--edu-darkest-blue); /* Dark text for yellow background */
            border-color: var(--edu-update-color);
        }
        .btn-update:hover {
            background-color: #e0a800; /* Darker yellow for hover */
            border-color: #e0a800;
            color: var(--edu-darkest-blue);
        }
        .btn-update:disabled {
            background-color: #ffe8a1; /* Lighter yellow for disabled */
            border-color: #ffe8a1;
            color: #8c722f;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-activate {
            background-color: var(--edu-activate-color);
            color: var(--edu-white);
            border-color: var(--edu-activate-color);
        }
        .btn-activate:hover {
            background-color: #218838; /* Darker green for hover */
            border-color: #218838;
        }
        .btn-activate:disabled {
            background-color: #92d19f; /* Lighter green for disabled */
            border-color: #92d19f;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-delete { /* For deactivate */
            background-color: var(--edu-delete-color);
            color: var(--edu-white);
            border-color: var(--edu-delete-color);
        }
        .btn-delete:hover {
            background-color: #c82333; /* Darker red for hover */
            border-color: #c82333;
        }
        .btn-delete:disabled {
            background-color: #e8a7ae; /* Lighter red for disabled */
            border-color: #e8a7ae;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-hard-delete {
            background-color: var(--edu-hard-delete-color);
            color: var(--edu-white);
            border-color: var(--edu-hard-delete-color);
        }
        .btn-hard-delete:hover {
            background-color: #a71d2a; /* Even darker red for hover */
            border-color: #a71d2a;
        }
        .btn-hard-delete:disabled {
            background-color: #e3a9b0; /* Lighter dark-red for disabled */
            border-color: #e3a9b0;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-select-all {
            background-color: var(--edu-select-all-color);
            color: var(--edu-white);
            border-color: var(--edu-select-all-color);
        }
        .btn-select-all:hover {
            background-color: #5a6268; /* Darker gray for hover */
            border-color: #5a6268;
        }
        /* Confirmation Modal Header Styling */
        #confirmationModalHeader.bg-danger {
            background-color: var(--edu-delete-color) !important;
        }
        #confirmationModalHeader.bg-success {
            background-color: var(--edu-activate-color) !important;
        }
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
            <a class="navbar-brand edu-brand" href="<?= htmlspecialchars($admin_home_page) ?>">
                <img src="<?= htmlspecialchars($logo) ?>" alt="EduFlow Logo" class="navbar-logo"> <!-- MODIFIED -->
                EduFlow <!-- Changed from docMG -->
            </a>

            <!-- Navbar Toggler for small screens -->
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>

            <!-- Navigation Links -->
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= htmlspecialchars($admin_home_page) ?>">Home</a> <!-- MODIFIED -->
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link active" href="<?= htmlspecialchars($admin_documents) ?>">Manage Documents</a> <!-- MODIFIED -->
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= htmlspecialchars($admin_folders) ?>">Manage Folders</a> <!-- MODIFIED -->
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= htmlspecialchars($admin_classes) ?>">Manage Classes</a> <!-- MODIFIED -->
                    </li>
                </ul>
                <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= htmlspecialchars($admin_users) ?>?role=student">Manage Students</a> <!-- MODIFIED -->
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= htmlspecialchars($admin_submissions) ?>">View Submissions</a> <!-- MODIFIED -->
                    </li>
                    <!-- Logout Button -->
                    <li class="nav-item ms-lg-3">
                        <form action="<?= htmlspecialchars($logout_page) ?>" method="POST" class="d-inline"> <!-- MODIFIED -->
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
                <!-- Page Header with blue bar as in image -->
                <div class="page-header">
                    <h1>Manage Document Types</h1>
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

                <!-- Action Buttons -->
                <div class="crud-buttons mb-4">
                    <button type="button" class="btn btn-insert" data-bs-toggle="modal" data-bs-target="#addDocumentModal">Add New</button>
                    <button type="button" class="btn btn-update" id="updateBtn" disabled>Edit</button>
                    <button type="button" class="btn btn-activate" id="activateBtn" disabled>Activate Selected</button>
                    <button type="button" class="btn btn-delete" id="deactivateBtn" disabled>Deactivate Selected</button>
                    <button type="button" class="btn btn-hard-delete" id="hardDeleteBtn" disabled>Hard Delete Selected</button>
                    <button type="button" class="btn btn-select-all" id="selectAllBtn">Select All</button>
                </div>

                <!-- Search & Filters Area -->
                <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchForm" action="<?= htmlspecialchars($admin_documents) ?>"> <!-- MODIFIED -->
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search by name or description..." value="<?= htmlspecialchars($search_query) ?>">
                        <?php if (!empty($search_query)): ?>
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchInput" title="Clear Search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Apply Search</button>
                    </div>

                    <!-- Category Filter (doc_types) -->
                    <select class="selectpicker" multiple data-live-search="true" name="doc_types[]" id="docTypeFilter" data-width="fit" title="Filter by Category">
                        <?php foreach ($distinct_doc_types as $type): ?>
                            <option value="<?= htmlspecialchars($type) ?>" <?= in_array($type, $selected_doc_types) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Extension Filter (doc_extensions) -->
                    <select class="selectpicker" multiple data-live-search="true" name="doc_extensions[]" id="docExtFilter" data-width="fit" title="Filter by Extension">
                        <?php foreach ($distinct_doc_extensions as $ext): ?>
                            <option value="<?= htmlspecialchars($ext) ?>" <?= in_array($ext, $selected_doc_extensions) ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($ext)) ?></option>
                            <!-- Note: Changed $ext to strtoupper($ext) for display, was just $ext -->
                        <?php endforeach; ?>
                    </select>

                    <!-- Max Size Filter (min_size, max_size) -->
                    <div class="input-group">
                        <input type="number" step="0.1" class="form-control" name="min_size" placeholder="Min MB" value="<?= htmlspecialchars($min_max_size ?? '') ?>">
                        <input type="number" step="0.1" class="form-control" name="max_size" placeholder="Max MB" value="<?= htmlspecialchars($max_max_size ?? '') ?>">
                        <button type="submit" class="btn btn-primary">Filter Size</button>
                    </div>
                    <?php
                        $has_filters = !empty($search_query) || !empty($selected_doc_types) || !empty($selected_doc_extensions) || ($min_max_size !== null) || ($max_max_size !== null);
                        if ($has_filters):
                    ?>
                        <a href="<?= htmlspecialchars($admin_documents) ?>" class="btn btn-outline-secondary">Clear All Filters</a> <!-- MODIFIED -->
                    <?php endif; ?>
                </form>

                <!-- Document Types Table -->
                <form id="docForm" method="POST" action="<?= htmlspecialchars($admin_documents) ?>"> <!-- MODIFIED -->
                    <input type="hidden" name="action" id="formAction">
                    <div class="table-responsive">
                        <table class="table table-striped-edu text-nowrap">
                            <thead>
                                <tr>
                                    <th scope="col" style="width: 50px;">
                                        <input type="checkbox" id="masterCheckbox">
                                    </th>
                                    <th scope="col" class="fw-bold">Category</th>
                                    <th scope="col" class="fw-bold">Document Type</th>
                                    <th scope="col" class="fw-bold">Max Size (MB)</th>
                                    <th scope="col" class="fw-bold">Active</th>
                                    <th scope="col" class="fw-bold">Doc ID</th>
                                    <th scope="col" class="fw-bold">Description</th>
                                    <th scope="col" class="fw-bold">Extensions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($documents): ?>
                                    <?php foreach ($documents as $doc): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_docs[]" value="<?= htmlspecialchars($doc['Doc_id']) ?>" class="row-checkbox"></td>
                                            <td class="doc_type_cell"><?= htmlspecialchars($doc['Doc_type']) ?></td>
                                            <td class="doc_name_cell"><?= htmlspecialchars($doc['Doc_name']) ?></td>
                                            <td class="doc_max_size_cell"><?= htmlspecialchars(round($doc['Doc_max_size'] / (1024 * 1024), 2)) ?></td><!-- Convert bytes to MB -->
                                            <td>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input edu-toggle" type="checkbox" role="switch" id="toggleActive_<?= $doc['Doc_id'] ?>" data-doc-id="<?= $doc['Doc_id'] ?>" <?= $doc['Is_active'] == 'y' ? 'checked' : '' ?>>
                                                    <label class="form-check-label visually-hidden" for="toggleActive_<?= $doc['Doc_id'] ?>">Toggle Active</label>
                                                </div>
                                            </td>
                                            <td class="doc_id_cell"><?= htmlspecialchars($doc['Doc_id']) ?></td>
                                            <td class="doc_desc_cell"><?= htmlspecialchars($doc['Doc_desc']) ?></td>
                                            <td class="doc_ext_cell"><?= htmlspecialchars($doc['Doc_ext']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">No documents found for your college.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <!-- Pagination Stats -->
                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                    <?php if ($total_documents > 0): ?>
                        <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $total_documents ?> total records.</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No records found matching your criteria.</p>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page - 1, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size)) ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($i, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page + 1, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size)) ?>">Next</a>
                        </li>
                    </ul>
                </nav>

            </div>
        </div>
    </main>

    <!-- Insert Document Modal -->
    <div class="modal fade" id="addDocumentModal" tabindex="-1" aria-labelledby="addDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addDocumentModalLabel">Add New Document Type</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars($admin_documents) ?>" method="POST"> <!-- MODIFIED -->
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="addDocType" class="form-label">Category <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="addDocType" name="doc_type" required>
                        </div>
                        <div class="mb-3">
                            <label for="addDocName" class="form-label">Document Type Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="addDocName" name="doc_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="addDocDesc" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="addDocDesc" name="doc_desc" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="addDocExt" class="form-label">Allowed Extensions (e.g., pdf,doc,png)</label>
                            <input type="text" class="form-control" id="addDocExt" name="doc_ext">
                        </div>
                        <div class="mb-3">
                            <label for="addDocMaxSize" class="form-label">Max Size in MB <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" class="form-control" id="addDocMaxSize" name="doc_max_size" min="0.1" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Add Document</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Document Modal -->
    <div class="modal fade" id="updateDocumentModal" tabindex="-1" aria-labelledby="updateDocumentModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="updateDocumentModalLabel">Update Document Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars($admin_documents) ?>" method="POST"> <!-- MODIFIED -->
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="doc_id" id="updateDocId">
                        <div class="mb-3">
                            <label for="updateDocType" class="form-label">Category <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="updateDocType" name="doc_type" required>
                        </div>
                        <div class="mb-3">
                            <label for="updateDocName" class="form-label">Document Type Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="updateDocName" name="doc_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="updateDocDesc" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="updateDocDesc" name="doc_desc" rows="3"></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="updateDocExt" class="form-label">Allowed Extensions (e.g., pdf,doc,png)</label>
                            <input type="text" class="form-control" id="updateDocExt" name="doc_ext">
                        </div>
                        <div class="mb-3">
                            <label for="updateDocMaxSize" class="form-label">Max Size in MB <span class="text-danger">*</span></label>
                            <input type="number" step="0.1" class="form-control" id="updateDocMaxSize" name="doc_max_size" min="0.1" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-warning">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal (for Bulk Deactivate/Activate/Hard Delete) -->
    <div class="modal fade" id="confirmationModal" tabindex="-1" aria-labelledby="confirmationModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" id="confirmationModalHeader">
                    <h5 class="modal-title" id="confirmationModalLabel">Confirm Action</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="confirmationModalBody">
                    <!-- Message content will be set by JavaScript -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="confirmActionButton">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="footer py-3">
        <div class="container-fixed-width text-center">
            <p>&copy; <?= date('Y') ?> EduFlow. All rights reserved.</p>
            <p>Document Management System for Educational Institutions.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap-select
            $('.selectpicker').selectpicker();

            // Navbar padding adjustment
            var navbarCollapse = document.getElementById('navbarNav');
            var eduNavbar = document.querySelector('.edu-navbar');
            var body = document.body;

            function adjustBodyPadding() {
                var currentNavbarHeight = eduNavbar.offsetHeight;
                body.style.paddingTop = currentNavbarHeight + 'px';
            }

            var initialNavbarHeight = eduNavbar.offsetHeight;
            document.documentElement.style.setProperty('--edu-navbar-initial-height', initialNavbarHeight + 'px');

            adjustBodyPadding();
            navbarCollapse.addEventListener('shown.bs.collapse', adjustBodyPadding);
            navbarCollapse.addEventListener('hidden.bs.collapse', adjustBodyPadding);
            window.addEventListener('resize', adjustBodyPadding);

            // --- Toast Notification Logic ---
            function showToast(type, message) {
                const toastContainer = document.querySelector('.toast-container');
                if (!toastContainer) {
                    console.error('Toast container not found!');
                    return;
                }

                const toastClasses = {
                    'success': 'text-bg-success',
                    'danger': 'text-bg-danger',
                    'info': 'text-bg-info',
                    'warning': 'text-bg-warning'
                };

                const toastElement = document.createElement('div');
                toastElement.classList.add('toast', 'align-items-center', toastClasses[type] || 'text-bg-info', 'border-0');
                toastElement.setAttribute('role', 'alert');
                toastElement.setAttribute('aria-live', 'assertive');
                toastElement.setAttribute('aria-atomic', 'true');
                toastElement.innerHTML = `
                    <div class="d-flex">
                        <div class="toast-body">
                            ${message} <!-- Corrected JavaScript string interpolation -->
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

            // --- CRUD Button Logic ---
            const docForm = document.getElementById('docForm');
            const formAction = document.getElementById('formAction');
            const masterCheckbox = document.getElementById('masterCheckbox');
            const tableBody = document.querySelector('.table-striped-edu tbody');
            const rowCheckboxes = () => tableBody.querySelectorAll('.row-checkbox');
            const updateBtn = document.getElementById('updateBtn');
            const deactivateBtn = document.getElementById('deactivateBtn');
            const activateBtn = document.getElementById('activateBtn');
            const hardDeleteBtn = document.getElementById('hardDeleteBtn');
            const selectAllBtn = document.getElementById('selectAllBtn');
            const updateDocumentModal = new bootstrap.Modal(document.getElementById('updateDocumentModal'));
            const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
            const confirmationModalBody = document.getElementById('confirmationModalBody');
            const confirmationModalHeader = document.getElementById('confirmationModalHeader');
            const confirmationModalLabel = document.getElementById('confirmationModalLabel');
            const confirmActionButton = document.getElementById('confirmActionButton');

            let currentAction = '';

            masterCheckbox.addEventListener('change', function() {
                rowCheckboxes().forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                toggleActionButtons();
            });

            tableBody.addEventListener('change', function(event) {
                if (event.target.classList.contains('row-checkbox')) {
                    const allChecked = Array.from(rowCheckboxes()).every(checkbox => checkbox.checked);
                    masterCheckbox.checked = allChecked && rowCheckboxes().length > 0;
                    toggleActionButtons();
                }
            });

            function toggleActionButtons() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                const totalRows = rowCheckboxes().length;

                updateBtn.disabled = !(checkedCount === 1);
                deactivateBtn.disabled = !(checkedCount > 0);
                activateBtn.disabled = !(checkedCount > 0);
                hardDeleteBtn.disabled = !(checkedCount > 0);

                if (totalRows > 0 && checkedCount === totalRows) {
                    selectAllBtn.textContent = 'Deselect All';
                } else {
                    selectAllBtn.textContent = 'Select All';
                }
            }
            toggleActionButtons(); // Initialize button states on page load

            selectAllBtn.addEventListener('click', function() {
                const allSelected = document.querySelectorAll('.row-checkbox:checked').length === rowCheckboxes().length && rowCheckboxes().length > 0;
                masterCheckbox.checked = !allSelected; // Toggle master checkbox state
                rowCheckboxes().forEach(checkbox => {
                    checkbox.checked = !allSelected; // Set all checkboxes based on new master state
                });
                toggleActionButtons(); // Update button states
            });

            updateBtn.addEventListener('click', function() {
                const selectedCheckbox = document.querySelector('.row-checkbox:checked');
                if (selectedCheckbox) {
                    const row = selectedCheckbox.closest('tr');
                    document.getElementById('updateDocId').value = row.querySelector('.doc_id_cell').textContent;
                    document.getElementById('updateDocType').value = row.querySelector('.doc_type_cell').textContent;
                    document.getElementById('updateDocName').value = row.querySelector('.doc_name_cell').textContent;
                    document.getElementById('updateDocExt').value = row.querySelector('.doc_ext_cell').textContent;
                    document.getElementById('updateDocMaxSize').value = row.querySelector('.doc_max_size_cell').textContent;
                    document.getElementById('updateDocDesc').value = row.querySelector('.doc_desc_cell').textContent;
                    updateDocumentModal.show();
                } else {
                    showToast('info', 'Please select one document type to update.');
                }
            });

            deactivateBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_deactivate';
                    confirmationModalHeader.classList.remove('bg-success', 'text-white', 'bg-warning');
                    confirmationModalHeader.classList.add('bg-danger', 'text-white');
                    confirmationModalLabel.textContent = `Confirm Deactivation`;
                    confirmationModalBody.innerHTML = `Are you sure you want to deactivate <strong>${checkedCount}</strong> selected document type(s)? They will no longer be visible unless explicitly managed.`;
                    confirmActionButton.classList.remove('btn-success', 'btn-warning');
                    confirmActionButton.classList.add('btn-danger');

                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one document type to deactivate.');
                }
            });

            activateBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_activate';
                    confirmationModalHeader.classList.remove('bg-danger', 'text-white', 'bg-warning');
                    confirmationModalHeader.classList.add('bg-success', 'text-white');
                    confirmationModalLabel.textContent = `Confirm Activation`;
                    confirmationModalBody.innerHTML = `Are you sure you want to activate <strong>${checkedCount}</strong> selected document type(s)?`; 
                    confirmActionButton.classList.remove('btn-danger', 'btn-warning');
                    confirmActionButton.classList.add('btn-success');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one document type to activate.');
                }
            });

            hardDeleteBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_hard_delete';
                    confirmationModalHeader.classList.remove('bg-success', 'text-white', 'bg-warning');
                    confirmationModalHeader.classList.add('bg-danger', 'text-white');
                    confirmationModalLabel.textContent = `Confirm PERMANENT Deletion`;
                    confirmationModalBody.innerHTML = `
                        <p><strong>WARNING: This action cannot be undone.</strong></p>
                        <p>Are you absolutely sure you want to PERMANENTLY delete <strong>${checkedCount}</strong> selected document type(s)? This will remove them from the database.</p> <!-- Corrected JS interpolation -->
                    `;
                    confirmActionButton.classList.remove('btn-success', 'btn-warning');
                    confirmActionButton.classList.add('btn-danger');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one document type for permanent deletion.');
                }
            });

            confirmActionButton.addEventListener('click', function() {
                // Dimiss the modal immediately
                confirmationModal.hide();
                // A small delay before submitting the form to ensure modal closes visually
                setTimeout(() => {
                    if (currentAction) {
                        formAction.value = currentAction;
                        docForm.submit();
                    }
                }, 100);
            });

            // --- Single Toggle Active State (AJAX) ---
            document.querySelectorAll('.edu-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const docId = this.dataset.docId;
                    const isActive = this.checked ? 'y' : 'n';
                    const originalState = !this.checked ? 'y' : 'n'; // Store original state for rollback

                    // Use the admin_documents link from PHP directly in JS
                    const ajaxUrl = '<?= htmlspecialchars($admin_documents) ?>'; // <--- MODIFIED

                    fetch(ajaxUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `action=toggle_active&doc_id=${docId}&is_active=${isActive}`
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showToast('success', data.message);
                        } else {
                            showToast('danger', data.message);
                            this.checked = (originalState === 'y'); // Rollback on failure
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('danger', 'An error occurred while updating status. Please try again.');
                        this.checked = (originalState === 'y'); // Rollback on network/fetch error
                    });
                });
            });

            // --- Clear Search Input Button Logic ---
            const clearSearchInputBtn = document.getElementById('clearSearchInput');
            if (clearSearchInputBtn) {
                clearSearchInputBtn.addEventListener('click', function() {
                    // Clear the search input
                    document.querySelector('input[name="search"]').value = '';
                    // Submit form to clear the search filter.
                    // This will also re-apply any other active filters (doc_types, etc.)
                    document.getElementById('searchForm').submit();
                });
            }

            // Attach change listener to all selectpickers that trigger filtering
            // For selectpickers, listening directly on the underlying select element is not enough,
            // bootstrap-select throws custom events. The `changed.bs.select` event is suitable.
            $('#docTypeFilter').on('changed.bs.select', function (e, clickedIndex, isSelected, oldValue) {
                document.getElementById('searchForm').submit();
            });
            $('#docExtFilter').on('changed.bs.select', function (e, clickedIndex, isSelected, oldValue) {
                document.getElementById('searchForm').submit();
            });

            // Event listener for the "Filter Size" button (which already performs a submit)
            // No additional JS needed here as it's part of the form's natural submit via its type="submit" button.
        });
    </script>
</body>
</html>