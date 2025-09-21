<?php
// folders.php - Page for managing folders with CRUD operations, search, filters, and pagination

// Include admin authentication and common functions
require_once '../includes/admin_auth.php'; // This should set up $conn, BASE_URL, and related functions.
require_once '../includes/db.php'; // Ensure $conn is available from db.php
require_once '../links.php'; // NEW: Include links.php to get all defined URLs

// --- Helper Functions ---
// If 'prepare_and_execute', 'set_flash_message', 'redirect' etc. are not
// defined in functions.php, you MUST define them here or include a custom file.

/**
 * Fetches folders for a given college, with optional search and active status filter.
 */
function getFolders($conn, $clg_id, $search_query, $filter_active, $limit, $offset) {
    $sql = "SELECT f.*, u.Name as CreatorName,
            (SELECT COUNT(*) FROM Folder_doc_mapping fdm WHERE fdm.Folder_id = f.Folder_id AND fdm.Is_active = 'y') as active_docs_count,
            (SELECT SUM(d.Doc_max_size) FROM doc d JOIN Folder_doc_mapping fdm ON d.Doc_id = fdm.Doc_id WHERE fdm.Folder_id = f.Folder_id AND fdm.Is_active = 'y' AND d.Is_active = 'y') as total_folder_size
            FROM Folder f
            LEFT JOIN Users u ON f.Created_by = u.Uid
            WHERE f.Clg_id = ?";
    $params = [$clg_id];
    $types = "i";

    // Text search on Folder_name and folder_desc
    if (!empty($search_query)) {
        $sql .= " AND (f.Folder_name LIKE ? OR f.folder_desc LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    // Active status filter
    if ($filter_active === 'y' || $filter_active === 'n') {
        $sql .= " AND f.Is_active = ?";
        array_push($params, $filter_active);
        $types .= "s";
    }

    $sql .= " ORDER BY f.Folder_name LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $result = $stmt->get_result();
        $folders = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $folders;
    } catch (Exception $e) {
        error_log("Error fetching folders: " . $e->getMessage());
        return [];
    }
}

/**
 * Counts total folders for a given college, with optional search and active status filter.
 */
function countFolders($conn, $clg_id, $search_query, $filter_active) {
    $sql = "SELECT COUNT(*) FROM Folder f WHERE f.Clg_id = ?";
    $params = [$clg_id];
    $types = "i";

    if (!empty($search_query)) {
        $sql .= " AND (f.Folder_name LIKE ? OR f.folder_desc LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    if ($filter_active === 'y' || $filter_active === 'n') {
        $sql .= " AND f.Is_active = ?";
        array_push($params, $filter_active);
        $types .= "s";
    }

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Exception $e) {
        error_log("Error counting folders: " . $e->getMessage());
        return 0;
    }
}

/**
 * Updates the Is_active status for one or more folders.
 */
function updateFolderStatus($conn, array $folder_ids, $is_active_status, $clg_id) {
    if (empty($folder_ids)) {
        return 0; // No folders to update
    }
    if (!in_array($is_active_status, ['y', 'n'])) {
        error_log("Invalid Is_active status provided: " . $is_active_status);
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($folder_ids), '?'));
    $sql = "UPDATE Folder SET Is_active = ? WHERE Clg_id = ? AND Folder_id IN ($placeholders)";
    // 's' for is_active_status, 'i' for clg_id, then 'i' for each doc_id
    $types = "si" . str_repeat('i', count($folder_ids));
    $params = array_merge([$is_active_status, $clg_id], $folder_ids);

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $affected_rows;
    } catch (Exception $e) {
        error_log("Error updating folder status: " . $e->getMessage());
        return false;
    }
}

/**
 * Hard deletes folders by Folder_id. USE WITH EXTREME CAUTION.
 * Also removes associated Folder_doc_mapping entries.
 */
function hardDeleteFolders($conn, array $folder_ids, $clg_id) {
    if (empty($folder_ids)) {
        return 0; // No folders to delete
    }

    $conn->begin_transaction();
    try {
        $placeholders = implode(',', array_fill(0, count($folder_ids), '?'));
        $types = "i" . str_repeat('i', count($folder_ids)); // For Clg_id + Folder_ids
        
        // 1. Delete associated `Folder_doc_mapping` entries
        $sql_delete_mappings = "DELETE FROM Folder_doc_mapping WHERE Clg_id = ? AND Folder_id IN ($placeholders)";
        $params_mappings = array_merge([$clg_id], $folder_ids);
        $stmt_mappings = prepare_and_execute($conn, $sql_delete_mappings, $params_mappings, $types);
        $stmt_mappings->close();

        // 2. Delete the folders themselves
        $sql_delete_folders = "DELETE FROM Folder WHERE Clg_id = ? AND Folder_id IN ($placeholders)";
        $params_folders = array_merge([$clg_id], $folder_ids);
        $stmt_folders = prepare_and_execute($conn, $sql_delete_folders, $params_folders, $types);
        $affected_rows = $stmt_folders->affected_rows;
        $stmt_folders->close();

        $conn->commit();
        return $affected_rows;
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Error hard deleting folders: " . $e->getMessage());
        return false;
    }
}
// --- End Helper Functions ---

// Define page title
$pageTitle = "Manage Folders - EduFlow";

// Get current user and college ID
$clg_id = getCurrentUserClgId();
$current_user_id = getCurrentUserId();
$current_user_name = htmlspecialchars($_SESSION['name'] ?? 'Admin'); // Assuming $_SESSION['name'] exists

// --- Configuration for Pagination ---
$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- Filter Parameters ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_active = isset($_GET['filter_active']) ? trim($_GET['filter_active']) : 'all'; // 'y', 'n', or 'all'

// Define the base URL for this specific page - REPLACED WITH $admin_folders
// $current_page_base_url = $_SERVER['PHP_SELF'];

// --- Handle Form Submissions (CRUD Actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Collect current filter/pagination state to redirect back to it.
    $refresh_params = array_filter([
        'search'        => !empty($search_query) ? $search_query : null,
        'page'          => $current_page > 1 ? $current_page : null,
        'filter_active' => ($filter_active !== 'all') ? $filter_active : null,
    ]);
    // Use $admin_folders directly instead of $current_page_base_url
    $redirect_url = $admin_folders;
    if (!empty($refresh_params)) {
        $redirect_url .= '?' . http_build_query($refresh_params);
    }

    try {
        switch ($action) {
            case 'add':
                $folder_name = trim($_POST['folder_name'] ?? '');
                $folder_desc = trim($_POST['folder_desc'] ?? '');

                if (empty($folder_name)) {
                    throw new Exception('Folder name is required.');
                }
                
                // Simplified INSERT statement
                $sql = "INSERT INTO Folder (Clg_id, Folder_name, folder_desc, Is_active, Created_by, Created_at) VALUES (?, ?, ?, 'y', ?, NOW())";
                $stmt = prepare_and_execute($conn, $sql, [
                    $clg_id, $folder_name, $folder_desc, $current_user_id
                ], "issi"); // i for Clg_id, s for Folder_name, s for folder_desc, i for Created_by

                if ($stmt->affected_rows > 0) {
                    set_flash_message("Folder '{$folder_name}' added successfully!", "success");
                } else {
                    throw new Exception("Error adding folder: No rows affected.");
                }
                $stmt->close();
                redirect($redirect_url);
                break;

            case 'update':
                $folder_id = filter_input(INPUT_POST, 'folder_id', FILTER_VALIDATE_INT);
                $folder_name = trim($_POST['folder_name'] ?? '');
                $folder_desc = trim($_POST['folder_desc'] ?? '');

                if (!$folder_id || empty($folder_name)) {
                    throw new Exception('Invalid data for update.');
                }

                // Simplified UPDATE statement
                $sql = "UPDATE Folder SET Folder_name = ?, folder_desc = ? WHERE Folder_id = ? AND Clg_id = ?";
                $stmt = prepare_and_execute($conn, $sql, [
                    $folder_name, $folder_desc, $folder_id, $clg_id
                ], "ssii"); // s for Folder_name, s for folder_desc, i for Folder_id, i for Clg_id

                if ($stmt->affected_rows > 0) {
                    set_flash_message("Folder '{$folder_name}' updated successfully!", "success"); // Changed to success for consistent feedback
                } else {
                    set_flash_message("No changes made to folder '{$folder_name}'.", "info");
                }
                $stmt->close();
                redirect($redirect_url);
                break;

            case 'bulk_deactivate':
            case 'bulk_activate':
                $selected_folders = $_POST['selected_folders'] ?? [];
                $folder_ids_to_process = array_filter(array_map('intval', $selected_folders));
                $new_status = ($action === 'bulk_activate') ? 'y' : 'n';

                if (empty($folder_ids_to_process)) {
                    set_flash_message("No folders selected.", "info");
                } else {
                    $affected = updateFolderStatus($conn, $folder_ids_to_process, $new_status, $clg_id);
                    if ($affected !== false) {
                        set_flash_message("{$affected} folder(s) status updated successfully!", "success");
                    } else {
                        throw new Exception("Failed to update selected folders.");
                    }
                }
                redirect($redirect_url);
                break;

            case 'bulk_hard_delete':
                $selected_folders = $_POST['selected_folders'] ?? [];
                $folder_ids_to_process = array_filter(array_map('intval', $selected_folders));

                if (empty($folder_ids_to_process)) {
                    set_flash_message("No folders selected for deletion.", "info");
                } else {
                    $affected = hardDeleteFolders($conn, $folder_ids_to_process, $clg_id);
                    if ($affected !== false) {
                        set_flash_message("{$affected} folder(s) permanently deleted!", "success");
                    } else {
                        throw new Exception("Failed to permanently delete selected folders.");
                    }
                }
                redirect($redirect_url);
                break;

            case 'toggle_active': // AJAX request for single toggle
                header('Content-Type: application/json');
                $folder_id = filter_input(INPUT_POST, 'folder_id', FILTER_VALIDATE_INT);
                $is_active = $_POST['is_active'] ?? 'n';

                if ($folder_id === false || !in_array($is_active, ['y', 'n'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid data for toggle.']);
                    exit();
                }
                $affected = updateFolderStatus($conn, [$folder_id], $is_active, $clg_id);
                if ($affected > 0) {
                    echo json_encode(['success' => true, 'message' => 'Folder status updated.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update folder status.']);
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
$total_folders = countFolders($conn, $clg_id, $search_query, $filter_active);
$total_pages = ceil($total_folders / $records_per_page);
$folders = getFolders($conn, $clg_id, $search_query, $filter_active, $records_per_page, $offset);


// Calculate stats for "Showing X of Y total records"
$start_record = $total_folders > 0 ? ($offset + 1) : 0;
$end_record = min(($current_page * $records_per_page), $total_folders);

// Helper function to build query parameters for pagination
function buildPaginationQuery($currentPage, $search_query, $filter_active) {
    global $admin_folders; // Use the global variable from links.php
    $query_params = array_filter([
        'page'           => $currentPage,
        'search'         => !empty($search_query) ? $search_query : null,
        'filter_active'  => ($filter_active !== 'all') ? $filter_active : null,
    ]);
    return $admin_folders . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
}

// Ensure formatSizeUnits is available, e.g., defined in includes/functions.php
if (!function_exists('formatSizeUnits')) {
    function formatSizeUnits($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            return $bytes . ' bytes';
        } elseif ($bytes == 1) {
            return '1 byte';
        } else {
            return '0 bytes';
        }
    }
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

        /* Specific styles for Manage Documents table (reused for Folders) */
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
            margin-left: 0.55rem; /* Align left */
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
            <a class="navbar-brand edu-brand" href="<?= $admin_home_page ?>">
                <img src="<?= BASE_URL ?>assets/img/logo.png" alt="EduFlow Logo" class="navbar-logo">
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
                        <a class="nav-link edu-nav-link" href="<?= $admin_home_page ?>">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= $admin_documents ?>">Manage Documents</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link active" href="<?= $admin_folders ?>">Manage Folders</a>
                    </li>
                     <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= $admin_classes ?>">Manage Classes</a>
                    </li>
                </ul>
                <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
                     <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= $admin_users ?>?role=student">Manage Students</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= $admin_submissions ?>">View Submissions</a>
                    </li>
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
                    <h1>Manage Folders</h1>
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
                    <button type="button" class="btn btn-insert" data-bs-toggle="modal" data-bs-target="#addFolderModal">Add New</button>
                    <button type="button" class="btn btn-update" id="updateBtn" disabled>Edit</button>
                    <button type="button" class="btn btn-activate" id="activateBtn" disabled>Activate Selected</button>
                    <button type="button" class="btn btn-delete" id="deactivateBtn" disabled>Deactivate Selected</button>
                    <button type="button" class="btn btn-hard-delete" id="hardDeleteBtn" disabled>Hard Delete Selected</button>
                    <button type="button" class="btn btn-select-all" id="selectAllBtn">Select All</button>
                </div>

                <!-- Search & Filters Area -->
                <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchForm" action="<?= htmlspecialchars($admin_folders) ?>">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search by name or description..." value="<?= htmlspecialchars($search_query) ?>">
                        <?php if (!empty($search_query)): ?>
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchInput" title="Clear Search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Apply Search</button>
                    </div>

                    <!-- Filter by Active Status -->
                    <select class="selectpicker" name="filter_active" id="filterActive" data-width="fit" title="Filter by Status">
                        <option value="all" <?= ($filter_active == 'all') ? 'selected' : '' ?>>All Statuses</option>
                        <option value="y" <?= ($filter_active == 'y') ? 'selected' : '' ?>>Active</option>
                        <option value="n" <?= ($filter_active == 'n') ? 'selected' : '' ?>>Inactive</option>
                    </select>

                    <?php
                        $has_filters = !empty($search_query) || ($filter_active !== 'all');
                        if ($has_filters):
                    ?>
                        <a href="<?= htmlspecialchars($admin_folders) ?>" class="btn btn-outline-secondary">Clear All Filters</a>
                    <?php endif; ?>
                </form>

                <!-- Folders Table -->
                <form id="folderForm" method="POST" action="<?= htmlspecialchars($admin_folders) ?>">
                    <input type="hidden" name="action" id="formAction">
                    <div class="table-responsive">
                        <table class="table table-striped-edu text-nowrap">
                            <thead>
                                <tr>
                                    <th scope="col" style="width: 50px;">
                                        <input type="checkbox" id="masterCheckbox">
                                    </th>
                                    <th scope="col" class="fw-bold">Name</th>
                                    <th scope="col" class="fw-bold">Description</th>
                                    <th scope="col" class="fw-bold">Docs Mapped</th>
                                    <th scope="col" class="fw-bold">Total Size</th>
                                    <th scope="col" class="fw-bold">Status</th>
                                    <th scope="col" class="fw-bold">Created By</th>
                                    <th scope="col" class="fw-bold">Created At</th>
                                    <th scope="col" class="fw-bold">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($folders): ?>
                                    <?php foreach ($folders as $folder): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_folders[]" value="<?= htmlspecialchars($folder['Folder_id']) ?>" class="row-checkbox"></td>
                                            <td class="folder_name_cell"><?= htmlspecialchars($folder['Folder_name']) ?></td>
                                            <td class="folder_desc_cell"><?= htmlspecialchars(mb_strimwidth($folder['folder_desc'], 0, 100, "...")) ?></td>
                                            <td>
                                                <?= htmlspecialchars($folder['active_docs_count']) ?> 
                                                <a href="<?= $admin_doc_folder_mapping ?>?folder_id=<?= $folder['Folder_id']; ?>" class="ms-2 badge bg-secondary text-decoration-none">Manage Docs</a>
                                            </td>
                                            <td><?= htmlspecialchars(formatSizeUnits($folder['total_folder_size'] ?? 0)) ?></td>
                                            <td>
                                                <div class="form-check form-switch p-0">
                                                    <input class="form-check-input edu-toggle" type="checkbox" role="switch" id="toggleActive_<?= $folder['Folder_id'] ?>" data-folder-id="<?= $folder['Folder_id'] ?>" <?= $folder['Is_active'] == 'y' ? 'checked' : '' ?>>
                                                    <label class="form-check-label visually-hidden" for="toggleActive_<?= $folder['Folder_id'] ?>">Toggle Active</label>
                                                </div>
                                            </td>
                                            <td><?= htmlspecialchars($folder['CreatorName'] ?? 'N/A') ?></td>
                                            <td><?= htmlspecialchars(date("Y-m-d H:i", strtotime($folder['Created_at']))) ?></td>
                                            <td>
                                                <input type="hidden" class="folder_id_cell" value="<?= $folder['Folder_id'] ?>">
                                                <a href="<?= $admin_folder_student_mapping ?>?folder_id=<?= $folder['Folder_id']; ?>" class="btn btn-sm btn-info mt-1">Map to Students</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">No folders found for your college.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <!-- Pagination Stats -->
                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                    <?php if ($total_folders > 0): ?>
                        <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $total_folders ?> total records.</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No records found matching your criteria.</p>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page - 1, $search_query, $filter_active)) ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($i, $search_query, $filter_active)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page + 1, $search_query, $filter_active)) ?>">Next</a>
                        </li>
                    </ul>
                </nav>

            </div>
        </div>
    </main>

    <!-- Add Folder Modal -->
    <div class="modal fade" id="addFolderModal" tabindex="-1" aria-labelledby="addFolderModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addFolderModalLabel">Add New Folder</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars($admin_folders) ?>" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <div class="mb-3">
                            <label for="addFolderName" class="form-label">Folder Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="addFolderName" name="folder_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="addFolderDesc" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="addFolderDesc" name="folder_desc" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-primary">Add Folder</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Folder Modal -->
    <div class="modal fade" id="updateFolderModal" tabindex="-1" aria-labelledby="updateFolderModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="updateFolderModalLabel">Update Folder</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars($admin_folders) ?>" method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="folder_id" id="updateFolderId">
                        <div class="mb-3">
                            <label for="updateFolderName" class="form-label">Folder Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="updateFolderName" name="folder_name" required>
                        </div>
                        <div class="mb-3">
                            <label for="updateFolderDesc" class="form-label">Description (Optional)</label>
                            <textarea class="form-control" id="updateFolderDesc" name="folder_desc" rows="3"></textarea>
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
            // Initialize Bootstrap-select (still needed for filterActive dropdown)
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

            window.showToast = showToast; // Make it globally available


            // --- CRUD Button Logic ---
            const folderForm = document.getElementById('folderForm');
            const formAction = document.getElementById('formAction');
            const masterCheckbox = document.getElementById('masterCheckbox');
            const tableBody = document.querySelector('.table-striped-edu tbody');
            const rowCheckboxes = () => tableBody.querySelectorAll('.row-checkbox');
            const updateBtn = document.getElementById('updateBtn');
            const deactivateBtn = document.getElementById('deactivateBtn');
            const activateBtn = document.getElementById('activateBtn');
            const hardDeleteBtn = document.getElementById('hardDeleteBtn');
            const selectAllBtn = document.getElementById('selectAllBtn');
            const updateFolderModal = new bootstrap.Modal(document.getElementById('updateFolderModal'));
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
                    document.getElementById('updateFolderId').value = row.querySelector('.folder_id_cell').value;
                    document.getElementById('updateFolderName').value = row.querySelector('.folder_name_cell').textContent;
                    document.getElementById('updateFolderDesc').value = row.querySelector('.folder_desc_cell').textContent;
                    
                    updateFolderModal.show();
                } else {
                    showToast('info', 'Please select one folder to update.');
                }
            });

            deactivateBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_deactivate';
                    confirmationModalHeader.classList.remove('bg-success', 'text-white');
                    confirmationModalHeader.classList.add('bg-danger', 'text-white');
                    confirmationModalLabel.textContent = `Confirm Deactivation`;
                    confirmationModalBody.innerHTML = `Are you sure you want to deactivate <strong>${checkedCount}</strong> selected folder(s)? They will no longer be visible to students unless explicitly managed.`;
                    confirmActionButton.classList.remove('btn-success', 'btn-warning');
                    confirmActionButton.classList.add('btn-danger');

                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one folder to deactivate.');
                }
            });

            activateBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_activate';
                    confirmationModalHeader.classList.remove('bg-danger', 'text-white');
                    confirmationModalHeader.classList.add('bg-success', 'text-white');
                    confirmationModalLabel.textContent = `Confirm Activation`;
                    confirmationModalBody.innerHTML = `Are you sure you want to activate <strong>${checkedCount}</strong> selected folder(s)?`;
                    confirmActionButton.classList.remove('btn-danger', 'btn-warning');
                    confirmActionButton.classList.add('btn-success');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one folder to activate.');
                }
            });

            hardDeleteBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_hard_delete';
                    confirmationModalHeader.classList.remove('bg-success', 'text-white');
                    confirmationModalHeader.classList.add('bg-danger', 'text-white');
                    confirmationModalLabel.textContent = `Confirm PERMANENT Deletion`;
                    confirmationModalBody.innerHTML = `
                        <p><strong>WARNING: This action cannot be undone.</strong></p>
                        <p>Are you absolutely sure you want to PERMANENTLY delete <strong>${checkedCount}</strong> selected folder(s)? This will also remove all associated document mappings from the database.</p>
                    `;
                    confirmActionButton.classList.remove('btn-success', 'btn-warning');
                    confirmActionButton.classList.add('btn-danger');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one folder for permanent deletion.');
                }
            });

            confirmActionButton.addEventListener('click', function() {
                confirmationModal.hide();
                setTimeout(() => {
                    if (currentAction) {
                        formAction.value = currentAction;
                        folderForm.submit();
                    }
                }, 100);
            });

            // --- Single Toggle Active State (AJAX) ---
            document.querySelectorAll('.edu-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const folderId = this.dataset.folderId;
                    const isActive = this.checked ? 'y' : 'n';
                    const originalState = !this.checked ? 'y' : 'n'; // Store original state for rollback

                    fetch('<?= $admin_folders ?>', { // Changed from BASE_URL to $admin_folders
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `action=toggle_active&folder_id=${folderId}&is_active=${isActive}`
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
                    document.querySelector('input[name="search"]').value = '';
                    document.getElementById('searchForm').submit();
                });
            }

            // Attach change listener for status filter
            $('#filterActive').on('changed.bs.select', function (e, clickedIndex, isSelected, oldValue) {
                document.getElementById('searchForm').submit();
            });
        });
    </script>
</body>
</html>