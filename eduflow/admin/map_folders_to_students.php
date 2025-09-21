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

// Removed this line, BASE_URL is now defined in includes/db.php
// if (!defined('BASE_URL')) {
//     define('BASE_URL', 'http://localhost/demo_docmg/'); // ADJUST THIS BASE_URL TO YOUR PROJECT'S ROOT URL
// }

$clg_id = getCurrentUserClgId();
$current_user_id = getCurrentUserId(); // Admin performing the action

$page_title = "Map Folders to Students"; // Default title, will be updated if a folder is selected
$folder_name_context = ''; // Name of the folder for current context

// --- Determine the folder_id to work with ---
$requested_folder_id = filter_input(INPUT_GET, 'folder_id', FILTER_VALIDATE_INT);
$posted_folder_id = filter_input(INPUT_POST, 'folder_id_to_map', FILTER_VALIDATE_INT);

$folder_id_context = null; // Initialize the main variable that holds the current folder ID

// 1. Prioritize folder_id from GET parameters
if ($requested_folder_id) {
    $folder_id_context = $requested_folder_id;
}
// 2. Fallback to POST parameters (relevant after form submissions if GET wasn't set)
elseif ($posted_folder_id) {
    $folder_id_context = $posted_folder_id;
}
// 3. Fallback to stored session variable if neither GET nor POST has it
elseif (isset($_SESSION['last_folder_id'])) {
    $folder_id_context = $_SESSION['last_folder_id'];
}

// --- Validate the determined folder_id_context and ensure URL consistency ---
if ($folder_id_context) {
    // Verify if the folder is valid and active for the current college
    $stmt_folder_check = prepare_and_execute($conn,
        "SELECT Folder_name FROM Folder WHERE Folder_id = ? AND Clg_id = ? AND Is_active = 'y'",
        [$folder_id_context, $clg_id], "ii"
    );
    $folder_data = $stmt_folder_check->get_result()->fetch_assoc();
    $stmt_folder_check->close();

    if ($folder_data) {
        $folder_name_context = $folder_data['Folder_name'];
        $_SESSION['last_folder_id'] = $folder_id_context; // Update session with the currently active valid folder ID

        // If the folder_id was determined from POST or SESSION (i.e., not from GET),
        // then redirect to ensure the URL always reflects the current folder_id.
        // This is only for non-AJAX requests (e.g. initial form submission, not a toggle).
        if (!$requested_folder_id && $_SERVER['REQUEST_METHOD'] === 'GET') { // Added $_SERVER['REQUEST_METHOD'] === 'GET'
            $current_page_url = $_SERVER['PHP_SELF'];
            $existing_query_params = $_GET; // Start with existing GET params to preserve filters like search/page
            unset($existing_query_params['folder_id']); // Remove 'folder_id' if it was originally missing or invalid in GET
            $existing_query_params['folder_id'] = $folder_id_context; // Add the confirmed valid folder_id

            $redirect_url = $current_page_url . '?' . http_build_query($existing_query_params);
            header("Location: " . $redirect_url);
            exit(); // Crucial to exit after a redirect
        }
    } else {
        // The folder_id_context is invalid (not found or inactive). Clear it.
        set_flash_message("The specified folder (ID: {$folder_id_context}) was not found or is inactive. You need to select a valid folder.", "warning");
        $folder_id_context = null; // This will trigger the "no folder selected" message in HTML
        if (isset($_SESSION['last_folder_id'])) {
            unset($_SESSION['last_folder_id']); // Also clear from session to prevent future invalid redirects
        }
    }
} else {
    // No folder_id was available from GET, POST, or SESSION.
    // Set a general info message if no folder_id has ever been set.
    // If a flash message was already set by the "invalid folder" check, prioritize it.
    // if (!has_flash_message()) { // Check if a flash message is already present
    //     set_flash_message("Please navigate from the 'Manage Folders' page to select a folder for mapping students.", "info");
    // }
}
// --- End Folder ID Determination and Validation ---

// --- Base URL for this page to preserve context on forms and filters ---
// Ensure folder_id_context is included if it's valid
$current_page_base_url = $_SERVER['PHP_SELF'];
$base_query_params = array_filter([
    'folder_id' => $folder_id_context, // Always include folder_id in base params if it's set
    'search' => !empty($search_query) ? $search_query : null,
    'class_ids' => !empty($selected_classes_filter) ? $selected_classes_filter : null,
    'religions' => !empty($selected_religions_filter) ? $selected_religions_filter : null,
    'castes' => !empty($selected_castes_filter) ? $selected_castes_filter : null,
    'page' => isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : null,
    ], function($value) { return $value !== null && $value !== ''; }); // Remove null/empty string values

// Reconstruct redirect URL for form submissions to preserve filters
$redirect_to_list_url = $current_page_base_url . (!empty($base_query_params) ? '?' . http_build_query($base_query_params) : '');

// --- Dynamic Navigation Active State ---
$current_page_name = basename($_SERVER['PHP_SELF']);
$active_nav_item = '';
if ($current_page_name === 'dashboard.php') {
    $active_nav_item = 'dashboard';
} elseif ($current_page_name === 'documents.php') {
    $active_nav_item = 'documents';
} elseif ($current_page_name === 'folders.php' || $current_page_name === 'map_folders_to_students.php') {
    $active_nav_item = 'folders'; // Make 'Manage Folders' active
} elseif ($current_page_name === 'classes.php') {
    $active_nav_item = 'classes';
} elseif ($current_page_name === 'users.php' && (isset($_GET['role']) && $_GET['role'] == 'student')) {
    $active_nav_item = 'students';
} elseif ($current_page_name === 'submissions.php') {
    $active_nav_item = 'submissions';
}
// --- End Dynamic Navigation Active State ---


// --- Fetch Classes for dropdown (for the 'Map Folder' form) ---
// This is always needed for the 'Map Students' form even if no folder is currently selected for displaying table.
$stmt_classes = prepare_and_execute($conn, "SELECT Class_id, Class_name, Semester, Session FROM Classes WHERE Clg_id = ? AND Is_active = 'y' ORDER BY Class_name ASC", [$clg_id], "i");
$all_classes_result = $stmt_classes->get_result();
$college_classes = [];
while ($c_row = $all_classes_result->fetch_assoc()) {
    $college_classes[] = $c_row;
}
$stmt_classes->close();

// --- Process Filter Parameters for the Mapped Students Table and now also for the student selection dropdown ---
// These filters are only applied if a folder context is active.
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_classes_filter = isset($_GET['class_ids']) && is_array($_GET['class_ids']) ? $_GET['class_ids'] : [];
$selected_classes_filter = array_map('intval', array_filter($selected_classes_filter)); // Ensure integers

$selected_religions_filter = isset($_GET['religions']) && is_array($_GET['religions']) ? $_GET['religions'] : [];
$selected_religions_filter = array_map('trim', array_filter($selected_religions_filter));

$selected_castes_filter = isset($_GET['castes']) && is_array($_GET['castes']) ? $_GET['castes'] : [];
$selected_castes_filter = array_map('trim', array_filter($selected_castes_filter));

// --- Fetch Students for dropdown (active students in this college - applying filters) ---
// Only fetch if a folder is selected, otherwise this dropdown is not shown
$college_students = [];
if ($folder_id_context && !empty($folder_name_context)) {
    $sql_students = "SELECT u.Uid, u.Name, u.Email, cl.Class_name, s.Class_id,
        r.Religion_name AS Religion, ca.Caste_name AS Caste
        FROM Users u
        JOIN Students s ON u.Uid = s.Student_id
        LEFT JOIN Classes cl ON s.Class_id = cl.Class_id AND s.Clg_id = cl.Clg_id
        LEFT JOIN Religion r ON s.Religion_id = r.Religion_id
        LEFT JOIN Caste ca ON s.Caste_id = ca.Caste_id
        WHERE s.Clg_id = ? AND u.Is_active = 'y' AND s.Is_active = 'y'";
    $params_students = [$clg_id];
    $types_students = "i";

    // Apply search filter
    if (!empty($search_query)) {
        $sql_students .= " AND (u.Name LIKE ? OR u.Email LIKE ?)";
        $params_students[] = '%' . $search_query . '%';
        $params_students[] = '%' . $search_query . '%';
        $types_students .= "ss";
    }

    // Apply class filter
    if (!empty($selected_classes_filter)) {
        $placeholders = implode(',', array_fill(0, count($selected_classes_filter), '?'));
        $sql_students .= " AND s.Class_id IN ($placeholders)";
        array_push($params_students, ...$selected_classes_filter);
        $types_students .= str_repeat('i', count($selected_classes_filter));
    }

    // Apply religion filter
    if (!empty($selected_religions_filter)) {
        $placeholders = implode(',', array_fill(0, count($selected_religions_filter), '?'));
        $sql_students .= " AND r.Religion_name IN ($placeholders)";
        array_push($params_students, ...$selected_religions_filter);
        $types_students .= str_repeat('s', count($selected_religions_filter));
    }

    // Apply caste filter
    if (!empty($selected_castes_filter)) {
        $placeholders = implode(',', array_fill(0, count($selected_castes_filter), '?'));
        $sql_students .= " AND ca.Caste_name IN ($placeholders)";
        array_push($params_students, ...$selected_castes_filter);
        $types_students .= str_repeat('s', count($selected_castes_filter));
    }

    $sql_students .= " ORDER BY u.Name ASC";

    $stmt_students = prepare_and_execute($conn, $sql_students, $params_students, $types_students);
    $all_students_result = $stmt_students->get_result();
    while($s_row = $all_students_result->fetch_assoc()) {
        $college_students[] = $s_row;
    }
    $stmt_students->close();
}


// --- Handle POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Determine if it's an AJAX request by checking the X-Requested-With header
    $is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($is_ajax_request) {
        header('Content-Type: application/json');
        $response = ['status' => 'error', 'message' => 'Invalid or no action specified.'];
        $folder_id_context_for_ajax = filter_input(INPUT_POST, 'folder_id_context', FILTER_VALIDATE_INT);
        if (!$folder_id_context_for_ajax) {
            $response = ['status' => 'error', 'message' => 'Missing folder context for AJAX operation.'];
            echo json_encode($response);
            exit;
        }

        if (isset($_POST['bulk_action_mapping_submit'])) {
            $bulk_action = $_POST['bulk_action_mapping'];
            $selected_mapping_ids = $_POST['selected_mapping_ids'] ?? [];

            if (!empty($selected_mapping_ids)) {
                $ids_placeholder = implode(',', array_fill(0, count($selected_mapping_ids), '?'));
                $types = ''; // This will be built dynamically
                $sql = '';
                $params = [];
                $affected_rows = 0;

                switch ($bulk_action) {
                    case 'activate_mapping':
                    case 'deactivate_mapping':
                        $new_status = ($bulk_action === 'activate_mapping') ? 'y' : 'n';
                        $sql = "UPDATE Folder_mapping_to_students SET Is_active = ?
                            WHERE Folder_mapping_id IN ($ids_placeholder) AND Clg_id = ? AND Folder_id = ?";
                        $types = "s" . str_repeat('i', count($selected_mapping_ids)) . "ii";
                        $params = array_merge([$new_status], $selected_mapping_ids, [$clg_id, $folder_id_context_for_ajax]);
                        break;
                    case 'mandate_mapping': // NEW: Mark as mandatory
                    case 'unmandate_mapping': // NEW: Unmark as mandatory
                        $new_mandate_status = ($bulk_action === 'mandate_mapping') ? 'y' : 'n';
                        $sql = "UPDATE Folder_mapping_to_students SET Is_mandate = ?
                            WHERE Folder_mapping_id IN ($ids_placeholder) AND Clg_id = ? AND Folder_id = ?";
                        $types = "s" . str_repeat('i', count($selected_mapping_ids)) . "ii";
                        $params = array_merge([$new_mandate_status], $selected_mapping_ids, [$clg_id, $folder_id_context_for_ajax]);
                        break;
                    case 'delete_mapping':
                        function hardDeleteStudentMappingsAjax($conn, $mapping_ids, $clg_id, $folder_id) {
                            if (empty($mapping_ids)) return 0;
                            $placeholders = implode(',', array_fill(0, count($mapping_ids), '?'));
                            $sql = "DELETE FROM Folder_mapping_to_students WHERE Folder_mapping_id IN ($placeholders) AND Clg_id = ? AND Folder_id = ?";
                            $types = str_repeat('i', count($mapping_ids)) . 'ii';
                            $params = array_merge($mapping_ids, [$clg_id, $folder_id]);
                            try {
                                $stmt = prepare_and_execute($conn, $sql, $params, $types);
                                if ($stmt === false) { return 0; }
                                $affected_rows = $stmt->affected_rows;
                                $stmt->close();
                                return $affected_rows;
                            } catch (Exception $e) {
                                error_log("Error in hardDeleteStudentMappingsAjax: " . $e->getMessage());
                                return 0;
                            }
                        }
                        $affected_rows = hardDeleteStudentMappingsAjax($conn, $selected_mapping_ids, $clg_id, $folder_id_context_for_ajax);
                        $response = ['status' => 'success', 'message' => "{$affected_rows} mapping(s) permanently deleted."];
                        echo json_encode($response);
                        exit; // Exit after delete is processed
                    default:
                        $response = ['status' => 'error', 'message' => 'Unsupported bulk action.'];
                        echo json_encode($response);
                        exit; // Exit for unsupported action
                }

                if (!empty($sql)) {
                    $stmt = $conn->prepare($sql);
                    if ($stmt) {
                        $bind_params = [];
                        for ($i = 0; $i < count($params); $i++) {
                            $bind_params[$i] = &$params[$i];
                        }
                        call_user_func_array([$stmt, 'bind_param'], array_merge([$types], $bind_params));

                        if ($stmt->execute()) {
                            $affected_rows = $stmt->affected_rows;
                            if ($affected_rows > 0) {
                                $action_verb = str_replace(['_mapping', 'activate', 'deactivate', 'mandate', 'unmandate'], ['', 'activated', 'deactivated', 'mandated', 'unmandated'], $bulk_action);
                                $response = ['status' => 'success', 'message' => "$affected_rows mapping(s) {$action_verb}."];
                            } else {
                                // This is the message the user is seeing.
                                $response = ['status' => 'info', 'message' => "No mappings were updated. Already in desired state or no qualifying records."];
                            }
                        } else {
                            $response = ['status' => 'error', 'message' => "Database error: " . $stmt->error];
                        }
                        $stmt->close();
                    } else {
                        $response = ['status' => 'error', 'message' => "Failed to prepare statement: " . $conn->error];
                    }
                }
            } else {
                $response = ['status' => 'info', 'message' => 'No mappings selected for bulk action.'];
            }
        }
        echo json_encode($response);
        exit; // !! IMPORTANT: ALWAYS EXIT AFTER AJAX RESPONSE !!
    }

    // --- NON-AJAX POST REQUESTS START HERE ---
    // This block handles the initial form submission to map students to a folder.
    // Single item activate/deactivate via GET parameter is now handled by AJAX, so we can remove those.
    // Any redirection here will result in a full page reload, which is expected for forms.

    if (isset($_POST['map_folder_action'])) {
        $folder_to_map = filter_input(INPUT_POST, 'folder_id_to_map', FILTER_VALIDATE_INT);
        $map_to_type = $_POST['map_to_type'] ?? '';
        $selected_student_uids_from_form = $_POST['student_ids'] ?? [];
        $selected_class_id_for_class_mapping = filter_input(INPUT_POST, 'class_id_to_map', FILTER_VALIDATE_INT);
        $is_active_mapping = isset($_POST['is_active_mapping']) ? 'y' : 'n';
        $is_mandate_mapping = isset($_POST['is_mandate_mapping']) ? 'y' : 'n'; // NEW: Added is_mandate_mapping from form

        if (!$folder_to_map) {
            set_flash_message("Please select a folder to map.", "danger");
            redirect($redirect_to_list_url);
        }

        $mappings_to_create = [];
        $skipped_students_no_class_msg = "";

        if ($map_to_type === 'individual_students' && !empty($selected_student_uids_from_form)) {
            foreach ($selected_student_uids_from_form as $s_uid_str) {
                $student_uid = filter_var($s_uid_str, FILTER_VALIDATE_INT);
                if ($student_uid) {
                    $stmt_get_student_class = prepare_and_execute($conn,
                        "SELECT s.Class_id, u.Name as StudentName FROM Students s JOIN Users u ON s.Student_id = u.Uid
                        WHERE s.Student_id = ? AND s.Clg_id = ? AND s.Is_active = 'y' AND u.Is_active = 'y'",
                        [$student_uid, $clg_id], "ii"
                    );
                    $student_data = $stmt_get_student_class->get_result()->fetch_assoc();
                    $stmt_get_student_class->close();

                    if ($student_data && $student_data['Class_id'] !== null) {
                        $mappings_to_create[] = ['student_uid' => $student_uid, 'class_id' => $student_data['Class_id']];
                    } else {
                        $student_name_skipped = $student_data['StudentName'] ?? "ID {$student_uid}";
                        $skipped_students_no_class_msg .= "Student '{$student_name_skipped}' was skipped (inactive or no class assigned). ";
                    }
                }
            }
        } elseif ($map_to_type === 'class' && $selected_class_id_for_class_mapping) {
            $stmt_class_students = prepare_and_execute($conn,
                "SELECT s.Student_id FROM Students s JOIN Users u ON s.Student_id = u.Uid
                WHERE s.Class_id = ? AND s.Clg_id = ? AND s.Is_active = 'y' AND u.Is_active = 'y'",
                [$selected_class_id_for_class_mapping, $clg_id], "ii");
            $class_students_result = $stmt_class_students->get_result();
            while ($row = $class_students_result->fetch_assoc()) {
                $mappings_to_create[] = ['student_uid' => $row['Student_id'], 'class_id' => $selected_class_id_for_class_mapping];
            }
            $stmt_class_students->close();
        }

        if (!empty($skipped_students_no_class_msg)) {
            set_flash_message(trim($skipped_students_no_class_msg), "warning");
        }

        if (empty($mappings_to_create)) {
            if (empty($skipped_students_no_class_msg)) { // Only show this if no specific skip messages were generated
                set_flash_message("No eligible students selected or found for mapping.", "warning");
            }
        } else {
            $mapped_count = 0;
            $already_mapped_count = 0;
            $error_count = 0;

            // IMPORTANT FOR PREVENTING DUPLICATE ROWS:
            // Ensure your `Folder_mapping_to_students` database table has a
            // UNIQUE constraint on the combination of columns: `(Folder_id, Student_id, Clg_id)`.
            // This is the most critical step to prevent true duplicate entries.
            // Example SQL for creating such a constraint (after table creation and cleaning existing duplicates):
            // ALTER TABLE Folder_mapping_to_students ADD CONSTRAINT UQ_FolderStudentClg UNIQUE (Folder_id, Student_id, Clg_id);

            foreach ($mappings_to_create as $mapping_info) {
                $student_uid_to_map = $mapping_info['student_uid'];
                $class_id_for_this_mapping = $mapping_info['class_id'];

                $check_sql = "SELECT Folder_mapping_id FROM Folder_mapping_to_students
                    WHERE Folder_id = ? AND Student_id = ? AND Clg_id = ?";
                $stmt_check = prepare_and_execute($conn, $check_sql, [$folder_to_map, $student_uid_to_map, $clg_id], "iii");
                $existing_mapping = $stmt_check->get_result()->fetch_assoc();
                $stmt_check->close();

                if ($existing_mapping) {
                    // Update instead of insert if mapping already exists
                    $sql_update_existing = "UPDATE Folder_mapping_to_students
                            SET Class_id = ?, mapped_by = ?, Is_active = ?, Is_mandate = ?, mapped_at = NOW()
                            WHERE Student_id = ? AND Folder_id = ? AND Clg_id = ?";

                    $stmt_update_attempt = prepare_and_execute($conn, $sql_update_existing, [
                        $class_id_for_this_mapping, $current_user_id, $is_active_mapping, $is_mandate_mapping, // NEW: Added Is_mandate
                        $student_uid_to_map, $folder_to_map, $clg_id
                    ], "iissiii"); // NEW: types for Is_mandate (s)

                    if ($stmt_update_attempt) {
                        if ($stmt_update_attempt->affected_rows > 0) {
                            $mapped_count++; // Count as updated
                        } else {
                            // Affected rows is 0, meaning the data was already in the desired state.
                            $already_mapped_count++;
                        }
                        $stmt_update_attempt->close();
                    } else {
                        $error_count++;
                        error_log("Failed to prepare update statement for folder mapping: " . $conn->error);
                    }
                } else {
                    $sql_insert = "INSERT INTO Folder_mapping_to_students
                            (Clg_id, Class_id, Student_id, Folder_id, mapped_by, Is_active, Is_mandate, mapped_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"; // NEW: Added Is_mandate
                    $stmt_insert_attempt = $conn->prepare($sql_insert);
                    if ($stmt_insert_attempt) {
                        // FIX: Ensure bind_param success is checked before execute
                        if ($stmt_insert_attempt->bind_param("iiiiiss", // NEW: types for Is_mandate (s)
                            $clg_id, $class_id_for_this_mapping, $student_uid_to_map,
                            $folder_to_map, $current_user_id, $is_active_mapping, $is_mandate_mapping // NEW: Added Is_mandate
                        )) {
                            if ($stmt_insert_attempt->execute()) {
                                $mapped_count++;
                            } else {
                                // FIX: Differentiate duplicate key errors (MySQL error code 1062)
                                if (isset($conn->errno) && $conn->errno == 1062) { // check if errno is set
                                    $already_mapped_count++; // Mark as already existing if unique constraint violated
                                    error_log("Duplicate entry attempted for Folder mapping. Possibly a race condition. Skipped to prevent duplicates. Details: " . $stmt_insert_attempt->error);
                                } else {
                                    $error_count++;
                                    error_log("Error inserting folder mapping: " . $stmt_insert_attempt->error);
                                }
                            }
                        } else {
                            $error_count++;
                            error_log("Failed to bind parameters for insert statement: " . $stmt_insert_attempt->error);
                        }
                        $stmt_insert_attempt->close();
                    } else {
                        $error_count++;
                        error_log("Failed to prepare insert statement for folder mapping: " . $conn->error);
                    }
                }
            }
            $message_summary = "";
            if ($mapped_count > 0) $message_summary .= "$mapped_count folder mapping(s) created/updated successfully. ";
            if ($already_mapped_count > 0) $message_summary .= "$already_mapped_count student(s) already had this mapping in desired state (or updated) and were skipped. ";
            if ($error_count > 0) $message_summary .= "$error_count errors occurred. ";

            if(empty($message_summary) && $already_mapped_count == 0 && empty($skipped_students_no_class_msg)) {
                $message_summary = "No new mapping actions were performed.";
            } elseif (empty($message_summary) && $already_mapped_count > 0) {
                // Refined message if only already mapped students were processed
                $message_summary = "$already_mapped_count student(s) already had this mapping in desired state (or updated) and were skipped. ";
            }

            if(!empty($message_summary)) { // Only set flash if there's something to say, beyond individual skips
                set_flash_message(trim($message_summary), ($error_count > 0 ? "warning" : ($mapped_count > 0 ? "success" : "info")));
            }
        }
        redirect($redirect_to_list_url);
    }
}
// --- Helper Functions for Mapped Students Table ---
/**
 * Fetches distinct religions for students in the college.
 * Assumes 'Religion_id' column exists in the 'Students' table and joins via 'Religion' table.
 */
function getDistinctStudentReligions($conn, $clg_id) {
    try {
        $stmt = prepare_and_execute($conn,
            "SELECT DISTINCT r.Religion_name FROM Students s JOIN Users u ON s.Student_id = u.Uid JOIN Religion r ON s.Religion_id = r.Religion_id WHERE s.Clg_id = ? AND u.Is_active = 'y' AND s.Is_active = 'y' AND r.Religion_name IS NOT NULL AND r.Religion_name != '' ORDER BY r.Religion_name",
            [$clg_id], "i"
        );
        if ($stmt === false) { return []; }
        $result = $stmt->get_result();
        $religions = [];
        while ($row = $result->fetch_row()) {
            $religions[] = $row[0];
        }
        $stmt->close();
        return $religions;
    } catch (Exception $e) {
        error_log("Error fetching distinct student religions (ensure 'Religion_id' column in Students table and 'Religion_name' in Religion table exist): " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches distinct castes for students in the college.
 * Assumes 'Caste_id' column exists in the 'Students' table and joins via 'Caste' table.
 */
function getDistinctStudentCastes($conn, $clg_id) {
    try {
        $stmt = prepare_and_execute($conn,
            "SELECT DISTINCT ca.Caste_name FROM Students s JOIN Users u ON s.Student_id = u.Uid JOIN Caste ca ON s.Caste_id = ca.Caste_id WHERE s.Clg_id = ? AND u.Is_active = 'y' AND s.Is_active = 'y' AND ca.Caste_name IS NOT NULL AND ca.Caste_name != '' ORDER BY ca.Caste_name",
            [$clg_id], "i"
        );
        if ($stmt === false) { return []; }
        $result = $stmt->get_result();
        $castes = [];
        while ($row = $result->fetch_row()) {
            $castes[] = $row[0];
        }
        $stmt->close();
        return $castes;
    } catch (Exception $e) {
        error_log("Error fetching distinct student castes (ensure 'Caste_id' column in Students table and 'Caste_name' in Caste table exist): " . $e->getMessage());
        return [];
    }
}

/**
 * Fetches existing mappings for a given folder, with optional search and filters.
 * Modified to use `Religion_name` and `Caste_name` from joined tables.
 * Removed Scholarship_status related columns and filters.
 */
function getExistingStudentMappings($conn, $folder_id, $clg_id, $search_query, $selected_class_ids, $selected_religions, $selected_castes, $limit, $offset) {
    $sql = "SELECT fms.Folder_mapping_id, fms.Is_active as MappingIsActive, fms.Is_mandate as MappingIsMandate, fms.mapped_at,
    u.Uid as StudentUid, u.Name as StudentName, u.Email as StudentEmail,
    c.Class_name as MappedClassName, -- Class name from the mapping record itself
    cl.Class_name as StudentActualClassName, -- Student's current actual class name
    r.Religion_name AS Religion, ca.Caste_name AS Caste, -- Get names from joined tables
    creator.Name as MappedByName
    FROM Folder_mapping_to_students fms
    JOIN Users u ON fms.Student_id = u.Uid
    JOIN Students s ON u.Uid = s.Student_id -- This join here needs Students.Student_id to be unique for each Uid
    LEFT JOIN Classes c ON fms.Class_id = c.Class_id AND fms.Clg_id = c.Clg_id
    LEFT JOIN Classes cl ON s.Class_id = cl.Class_id AND s.Clg_id = cl.Clg_id
    LEFT JOIN Religion r ON s.Religion_id = r.Religion_id
    LEFT JOIN Caste ca ON s.Caste_id = ca.Caste_id
    LEFT JOIN Users creator ON fms.mapped_by = creator.Uid
    WHERE fms.Folder_id = ? AND fms.Clg_id = ?";
    $params = [$folder_id, $clg_id];
    $types = "ii";

    // Text search on Student Name and Email
    if (!empty($search_query)) {
        $sql .= " AND (u.Name LIKE ? OR u.Email LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    // Class Filter
    if (!empty($selected_class_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_class_ids), '?'));
        // Filter by the class associated with the mapping OR the student's actual class
        $sql .= " AND (fms.Class_id IN ($placeholders) OR s.Class_id IN ($placeholders))";
        array_push($params, ...$selected_class_ids);
        array_push($params, ...$selected_class_ids);
        $types .= str_repeat('i', count($selected_class_ids)) . str_repeat('i', count($selected_class_ids));
    }

    // Religion Filter
    if (!empty($selected_religions)) {
        $placeholders = implode(',', array_fill(0, count($selected_religions), '?'));
        $sql .= " AND r.Religion_name IN ($placeholders)"; // Filter by joined name
        array_push($params, ...$selected_religions);
        $types .= str_repeat('s', count($selected_religions));
    }

    // Caste Filter
    if (!empty($selected_castes)) {
        $placeholders = implode(',', array_fill(0, count($selected_castes), '?'));
        $sql .= " AND ca.Caste_name IN ($placeholders)"; // Filter by joined name
        array_push($params, ...$selected_castes);
        $types .= str_repeat('s', count($selected_castes));
    }

    $sql .= " ORDER BY u.Name ASC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        if ($stmt === false) { return []; }
        $result = $stmt->get_result();
        $mappings = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $mappings;
    } catch (Exception $e) {
        error_log("Error fetching existing student mappings: " . $e->getMessage());
        return [];
    }
}

/**
 * Counts total existing mappings for a given folder, with optional search and filters.
 * Modified to use `Religion_name` and `Caste_name` from joined tables.
 * Removed Scholarship_status related columns and filters.
 */
function countExistingStudentMappings($conn, $folder_id, $clg_id, $search_query, $selected_class_ids, $selected_religions, $selected_castes) {
    $sql = "SELECT COUNT(*) FROM Folder_mapping_to_students fms
    JOIN Users u ON fms.Student_id = u.Uid
    JOIN Students s ON u.Uid = s.Student_id -- This join here needs Students.Student_id to be unique for each Uid
    LEFT JOIN Religion r ON s.Religion_id = r.Religion_id
    LEFT JOIN Caste ca ON s.Caste_id = ca.Caste_id
    WHERE fms.Folder_id = ? AND fms.Clg_id = ?";
    $params = [$folder_id, $clg_id];
    $types = "ii";

    if (!empty($search_query)) {
        $sql .= " AND (u.Name LIKE ? OR u.Email LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    if (!empty($selected_class_ids)) {
        $placeholders = implode(',', array_fill(0, count($selected_class_ids), '?'));
        $sql .= " AND (fms.Class_id IN ($placeholders) OR s.Class_id IN ($placeholders))";
        array_push($params, ...$selected_class_ids);
        array_push($params, ...$selected_class_ids); // Add placeholders and params for s.Class_id too
        $types .= str_repeat('i', count($selected_class_ids)) . str_repeat('i', count($selected_class_ids));
    }

    if (!empty($selected_religions)) {
        $placeholders = implode(',', array_fill(0, count($selected_religions), '?'));
        $sql .= " AND r.Religion_name IN ($placeholders)"; // Filter by joined name
        array_push($params, ...$selected_religions);
        $types .= str_repeat('s', count($selected_religions));
    }

    if (!empty($selected_castes)) {
        $placeholders = implode(',', array_fill(0, count($selected_castes), '?'));
        $sql .= " AND ca.Caste_name IN ($placeholders)"; // Filter by joined name
        array_push($params, ...$selected_castes);
        $types .= str_repeat('s', count($selected_castes));
    }

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        if ($stmt === false) { return 0; }
        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Exception $e) {
        error_log("Error counting existing student mappings: " . $e->getMessage());
        return 0;
    }
}
// --- End Helper Functions for Mapped Students Table ---

// --- Pagination Configuration ---
$records_per_page = 15;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- Fetch existing mappings based on $folder_id_context (with filters) ---
$existing_mappings = [];
$total_existing_mappings = 0;
if ($folder_id_context && !empty($folder_name_context)) { // Only fetch if a valid folder context is active
    $existing_mappings = getExistingStudentMappings($conn, $folder_id_context, $clg_id, $search_query, $selected_classes_filter, $selected_religions_filter, $selected_castes_filter, $records_per_page, $offset);
    $total_existing_mappings = countExistingStudentMappings($conn, $folder_id_context, $clg_id, $search_query, $selected_classes_filter, $selected_religions_filter, $selected_castes_filter);
    $page_title = "Map Folder \"".htmlspecialchars($folder_name_context)."\" to Students";
} else {
    // If $folder_id_context is null or folder name is empty, the mapping section won't be displayed.
    // The flash message will already be set if an invalid ID was attempted.
}


$total_pages = ceil($total_existing_mappings / $records_per_page);

// For pagination stats display
$start_record = $total_existing_mappings > 0 ? ($offset + 1) : 0;
$end_record = min(($current_page * $records_per_page), $total_existing_mappings);

// --- Filter Dropdown Data (for filtering the mapped students table) ---
// $college_classes is already fetched.
$distinct_student_religions = getDistinctStudentReligions($conn, $clg_id);
$distinct_student_castes = getDistinctStudentCastes($conn, $clg_id);


// Helper function to build pagination query parameters for this page
function buildStudentMappingPaginationQuery($currentPage, $folder_id, $search_query, $selected_class_ids, $selected_religions, $selected_castes) {
    global $current_page_base_url;
    $query_params = array_filter([
        'folder_id' => $folder_id,
        'page' => $currentPage,
        'search' => !empty($search_query) ? $search_query : null,
        'class_ids' => !empty($selected_class_ids) ? $selected_class_ids : null,
        'religions' => !empty($selected_religions) ? $selected_religions : null,
        'castes' => !empty($selected_castes) ? $selected_castes : null,
    ], function($value) { return $value !== null && $value !== ''; });
    return $current_page_base_url . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
}

// Page Title is set above, now ensuring it combines with site name
$pageTitle_full = $page_title . " - EduFlow";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle_full ?></title>
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

            /* Colors for new buttons introduced in manage_documents.php (adapted for student mappings) */
            --edu-insert-color: #007bff; /* primary */
            --edu-update-color: #ffc107; /* warning */
            --edu-delete-color: #dc3545; /* danger - for deactivation */
            --edu-hard-delete-color: #bb2d3b; /* darker danger */
            --edu-select-all-color: #6c757d; /* secondary */
            --edu-activate-color: #28a745; /* success */
            --edu-mandate-color: #6f42c1; /* Purple for mandatory */
            --edu-unmandate-color: #fd7e14; /* Orange for unmandate */


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

        /* Custom Button Styles (Logout Button and others) */
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

            /* Paddington for the navbar-collapse items when expanded */
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

        /* Specific styles for Managed Mappings table */
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
            /* Add margin-top for vertical alignment in tables */
            margin-top: calc(0.375rem + 2px); /* Adjust based on Bootstrap form-check-input */
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

        /* NEW: Styles for Is_mandate toggle */
        .form-check-input.edu-toggle-mandate {
            background-color: var(--edu-unmandate-color); /* Orange when off (not mandatory) */
            border-color: var(--edu-unmandate-color);
        }
        .form-check-input.edu-toggle-mandate:checked {
            background-color: var(--edu-mandate-color); /* Purple when on (mandatory) */
            border-color: var(--edu-mandate-color);
        }
        .form-check-input.edu-toggle-mandate:checked:hover {
            background-color: #5d359f; /* Darker purple on hover */
            border-color: #5d359f;
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
            background-color: #92d19f;
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
            background-color: #e8a7ae;
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
            background-color: #e3a9b0;
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

        /* NEW: Styles for Mandatory buttons */
        .btn-mandate {
            background-color: var(--edu-mandate-color);
            color: var(--edu-white);
            border-color: var(--edu-mandate-color);
        }
        .btn-mandate:hover {
            background-color: #5d359f;
            border-color: #5d359f;
        }
        .btn-mandate:disabled {
            background-color: #b592e3;
            border-color: #b592e3;
            cursor: not-allowed;
            opacity: 0.65;
        }

        .btn-unmandate {
            background-color: var(--edu-unmandate-color);
            color: var(--edu-white);
            border-color: var(--edu-unmandate-color);
        }
        .btn-unmandate:hover {
            background-color: #e36b0f;
            border-color: #e36b0f;
        }
        .btn-unmandate:disabled {
            background-color: #f7a46f;
            border-color: #f7a46f;
            cursor: not-allowed;
            opacity: 0.65;
        }


        /* Confirmation Modal Header Styling */
        #confirmationModalHeader.bg-danger {
            background-color: var(--edu-delete-color) !important;
        }
        #confirmationModalHeader.bg-success {
            background-color: var(--edu-activate-color) !important;
        }
        #confirmationModalHeader.bg-info { /* New for mandate */
            background-color: var(--edu-mandate-color) !important;
        }
        #confirmationModalHeader.custom-orange { /* New for unmandate */
            background-color: var(--edu-unmandate-color) !important;
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
                <img src="<?= $assets_img_logo ?>" alt="EduFlow Logo" class="navbar-logo">
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
                        <a class="nav-link edu-nav-link <?= ($active_nav_item === 'students') ? 'active' : '' ?>" href="<?= $admin_users ?>?role=student">Manage Students</a>
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
        <?php if ($folder_id_context && !empty($folder_name_context)): // Only show back button if a folder is successfully selected ?>
            <div class="container-fixed-width mb-3">
                <a href="<?= $admin_folders ?>" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Manage Folders</a>
            </div>
        <?php endif; ?>
        <div class="card auth-card p-4 p-md-5">
            <div class="card-body">
                <!-- Page Header with blue bar -->
                <div class="page-header">
                    <h1><?= $page_title ?></h1>
                </div>

                <!-- Toast Container (for displaying flash messages) -->
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

                <?php if ($folder_id_context && !empty($folder_name_context)): // Only show mapping UI if a valid folder is selected ?>

                <!-- Search & Filters Area - MOVED TO TOP -->
                <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchForm" action="<?= htmlspecialchars($current_page_base_url) ?>">
                    <input type="hidden" name="folder_id" value="<?= htmlspecialchars($folder_id_context) ?>">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search by student name or email..." value="<?= htmlspecialchars($search_query) ?>">
                        <?php if (!empty($search_query)): ?>
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchInput" title="Clear Search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Apply Search</button>
                    </div>

                    <!-- Class Filter -->
                    <select class="selectpicker" multiple data-live-search="true" name="class_ids[]" id="classFilter" data-width="fit" title="Filter by Class">
                        <?php foreach ($college_classes as $class_item_filter): ?>
                            <option value="<?= htmlspecialchars($class_item_filter['Class_id']) ?>" <?= in_array($class_item_filter['Class_id'], $selected_classes_filter) ? 'selected' : '' ?>><?= htmlspecialchars($class_item_filter['Class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>

                    <!-- Religion Filter -->
                    <?php if (!empty($distinct_student_religions)): ?>
                        <select class="selectpicker" multiple data-live-search="true" name="religions[]" id="religionFilter" data-width="fit" title="Filter by Religion">
                            <?php foreach ($distinct_student_religions as $religion): ?>
                                <option value="<?= htmlspecialchars($religion) ?>" <?= in_array($religion, $selected_religions_filter) ? 'selected' : '' ?>><?= htmlspecialchars($religion) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: // Provide a placeholder if no data, or if column doesn't exist ?>
                        <div class="alert alert-info py-2 px-3 m-0" role="alert" data-bs-toggle="tooltip" data-bs-placement="top" title="To enable religion filter, ensure 'Religion_id' column in Students table and Religion table with 'Religion_name' exist and contain data.">
                            No Religion Data <i class="bi bi-info-circle"></i>
                        </div>
                    <?php endif; ?>

                    <!-- Caste Filter -->
                    <?php if (!empty($distinct_student_castes)): ?>
                        <select class="selectpicker" multiple data-live-search="true" name="castes[]" id="casteFilter" data-width="fit" title="Filter by Caste">
                            <?php foreach ($distinct_student_castes as $caste): ?>
                                <option value="<?= htmlspecialchars($caste) ?>" <?= in_array($caste, $selected_castes_filter) ? 'selected' : '' ?>><?= htmlspecialchars($caste) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <div class="alert alert-info py-2 px-3 m-0" role="alert" data-bs-toggle="tooltip" data-bs-placement="top" title="To enable caste filter, ensure 'Caste_id' column in Students table and Caste table with 'Caste_name' exist and contain data.">
                            No Caste Data <i class="bi bi-info-circle"></i>
                        </div>
                    <?php endif; ?>

                    <?php
                        $has_filters = !empty($search_query) || !empty($selected_classes_filter) || !empty($selected_religions_filter) || !empty($selected_castes_filter);
                        if ($has_filters):
                    ?>
                        <a href="<?= htmlspecialchars(buildStudentMappingPaginationQuery(1, $folder_id_context, '', [], [], [])) ?>" class="btn btn-outline-secondary">Clear All Filters</a>
                    <?php endif; ?>
                </form>

                <div class="card mb-4">
                    <div class="card-header">Map Students to "<?= htmlspecialchars($folder_name_context) ?>"</div>
                    <div class="card-body">
                    <form method="POST" action="<?= htmlspecialchars($current_page_base_url) ?>">
                        <input type="hidden" name="folder_id_to_map" value="<?= htmlspecialchars($folder_id_context) ?>">
                        <!-- Include current filter params for redirection context -->
                        <?php foreach($base_query_params as $key => $value): ?>
                            <?php if (is_array($value)): ?>
                                <?php foreach($value as $v): ?>
                                <input type="hidden" name="<?= htmlspecialchars($key) ?>[]" value="<?= htmlspecialchars($v) ?>">
                                <?php endforeach; ?>
                            <?php else: ?>
                                <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <div class="mb-3">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="map_to_type" id="map_to_individual" value="individual_students" checked>
                                <label class="form-check-label" for="map_to_individual">Individual Student(s)</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="map_to_type" id="map_to_class" value="class">
                                <label class="form-check-label" for="map_to_class">Entire Class</label>
                            </div>
                        </div>

                        <div id="studentSelectionDiv" class="mb-3">
                            <label for="student_ids" class="form-label">Select Student(s) <span class="text-danger">*</span></label>
                            <select class="form-select selectpicker" id="student_ids" name="student_ids[]" multiple data-live-search="true" title="Choose one or more students">
                                <?php foreach ($college_students as $student): ?>
                                <option value="<?php echo $student['Uid']; ?>">
                                    <?php echo htmlspecialchars($student['Name'] . " (" . $student['Email'] . ")" . ($student['Class_name'] ? ' - ' . $student['Class_name'] : ' - No Class Listed')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Hold Ctrl (Cmd on Mac) to select multiple students. Students without an assigned class in their profile will be skipped if selected individually. The list above is filtered by your current search and filter selections.</small>
                        </div>

                        <div id="classSelectionDiv" class="mb-3" style="display:none;">
                            <label for="class_id_to_map" class="form-label">Select Class <span class="text-danger">*</span></label>
                            <select class="form-select selectpicker" id="class_id_to_map" name="class_id_to_map" data-live-search="true" title="Choose a class">
                                <option value="">-- Select Class --</option>
                                <?php foreach ($college_classes as $class_item): ?>
                                <option value="<?php echo $class_item['Class_id']; ?>">
                                    <?php echo htmlspecialchars($class_item['Class_name'] . ($class_item['Semester'] ? ' Sem ' . $class_item['Semester'] : '') . ($class_item['Session'] ? ' (' . $class_item['Session'] . ')' : '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Students mapped via class must have an active class assigned to their profile.</small>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_active_mapping" name="is_active_mapping" value="y" checked>
                            <label class="form-check-label" for="is_active_mapping">Set Mapping as Active</label>
                        </div>
                        <div class="mb-3 form-check"> <!-- NEW: Is_mandate checkbox -->
                            <input type="checkbox" class="form-check-input" id="is_mandate_mapping" name="is_mandate_mapping" value="y" checked>
                            <label class="form-check-label" for="is_mandate_mapping">Set Mapping as Mandatory</label>
                        </div>
                        <button type="submit" name="map_folder_action" class="btn btn-primary">Map Folder</button>
                    </form>
                    </div>
                </div>

                <h4 class="mt-4">Students Mapped to Folder: "<?php echo htmlspecialchars($folder_name_context); ?>"</h4>

                <div class="d-flex justify-content-between align-items-start mb-3 mt-4 flex-wrap">
                    <div class="crud-buttons mb-4 w-100 d-flex flex-wrap">
                    <button type="button" class="btn btn-activate me-2 mb-2" id="activateMappingsBtn" disabled>Activate Selected</button>
                    <button type="button" class="btn btn-delete me-2 mb-2" id="deactivateMappingsBtn" disabled>Deactivate Selected</button>
                    <button type="button" class="btn btn-mandate me-2 mb-2" id="mandateMappingsBtn" disabled>Mark Mandatory</button> <!-- NEW: Mandate Button -->
                    <button type="button" class="btn btn-unmandate me-2 mb-2" id="unmandateMappingsBtn" disabled>Unmark Mandatory</button> <!-- NEW: Unmandate Button -->
                    <button type="button" class="btn btn-hard-delete me-2 mb-2" id="deleteMappingsBtn" disabled>Delete Selected</button>
                    <button type="button" class="btn btn-select-all me-2 mb-2" id="selectAllBtn">Select All</button>
                    </div>
                </div>

                <!-- Removed the form element around the table, it's not needed for AJAX operations -->
                <!-- The JavaScript will collect selected IDs and send via fetch -->
                <div class="table-responsive">
                    <table class="table table-striped-edu">
                        <thead>
                        <tr>
                            <th scope="col" style="width: 50px;">
                            <input type="checkbox" id="masterCheckbox">
                            </th>
                            <th>Student Name</th>
                            <th>Student Email</th>
                            <th>Student Current Class</th>
                            <th>Mapped Via Class Name</th>
                            <th>Religion</th>
                            <th>Caste</th>
                            <th>Mapped By</th>
                            <th>Mapped At</th>
                            <th>Mapping Active?</th>
                            <th>Mandatory?</th> <!-- NEW: Mandatory column -->
                            <!-- Removed the 'Actions' column as individual activate/deactivate are now handled by toggles -->
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($existing_mappings)): ?>
                            <?php foreach ($existing_mappings as $row): ?>
                            <tr>
                                <td><input type="checkbox" name="selected_mapping_ids[]" value="<?php echo $row['Folder_mapping_id']; ?>" class="row-checkbox"></td>
                                <td><?php echo htmlspecialchars($row['StudentName']); ?></td>
                                <td><?php echo htmlspecialchars($row['StudentEmail']); ?></td>
                                <td><?php echo htmlspecialchars($row['StudentActualClassName'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['MappedClassName'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['Religion'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['Caste'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($row['MappedByName'] ?? 'N/A'); ?></td>
                                <td><?php echo date("Y-m-d H:i", strtotime($row['mapped_at'])); ?></td>
                                
                                <td>
                            <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input edu-toggle" type="checkbox" role="switch"
                                id="toggleActive_<?= $row['Folder_mapping_id'] ?>"
                                data-mapping-id="<?= $row['Folder_mapping_id'] ?>"
                                data-folder-id="<?= $folder_id_context ?>"
                                data-field="is_active"
                                <?= $row['MappingIsActive'] == 'y' ? 'checked' : '' ?> > <!-- ADD THIS LINE -->
                                <label class="form-check-label visually-hidden" for="toggleActive_<?= $row['Folder_mapping_id'] ?>">Toggle Active</label>
                            </div>
                            </td>

                                <td>
                            <div class="form-check form-switch d-inline-block"> <!-- NEW: Is_mandate toggle -->
                                <input class="form-check-input edu-toggle-mandate" type="checkbox" role="switch"
                                id="toggleMandate_<?= $row['Folder_mapping_id'] ?>"
                                data-mapping-id="<?= $row['Folder_mapping_id'] ?>"
                                data-folder-id="<?= $folder_id_context ?>"
                                data-field="is_mandate"
                                <?= $row['MappingIsMandate'] == 'y' ? 'checked' : '' ?> > <!-- ADD THIS LINE -->
                                <label class="form-check-label visually-hidden" for="toggleMandate_<?= $row['Folder_mapping_id'] ?>">Toggle Mandatory</label>
                            </div>
                            </td>

                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="11" class="text-center">No students are currently mapped to this folder matching your filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination Stats -->
                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                    <?php if ($total_existing_mappings > 0): ?>
                        <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $total_existing_mappings ?> total records.</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No records found matching your criteria.</p>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                    <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(buildStudentMappingPaginationQuery($current_page - 1, $folder_id_context, $search_query, $selected_classes_filter, $selected_religions_filter, $selected_castes_filter)) ?>">Previous</a>
                    </li>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(buildStudentMappingPaginationQuery($i, $folder_id_context, $search_query, $selected_classes_filter, $selected_religions_filter, $selected_castes_filter)) ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars(buildStudentMappingPaginationQuery($current_page + 1, $folder_id_context, $search_query, $selected_classes_filter, $selected_religions_filter, $selected_castes_filter)) ?>">Next</a>
                    </li>
                    </ul>
                </nav>
                <?php else: // Display message if no valid folder is selected ?>
                    <div class="alert alert-warning mb-4" role="alert">
                        No folder is currently selected or the selected folder is invalid/inactive.
                        <p>To start mapping students, please navigate to the <a href="<?= $admin_folders ?>" class="alert-link">Manage Folders</a> page and click the "Map Students" button next to the desired folder.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- Confirmation Modal (for Bulk Actions) -->
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
    function toggleStudentClassSelection() {
        const mapToType = document.querySelector('input[name="map_to_type"]:checked').value;
        const studentSelectDiv = document.getElementById('studentSelectionDiv');
        const classSelectDiv = document.getElementById('classSelectionDiv');
        const studentSelect = document.getElementById('student_ids');
        const classSelect = document.getElementById('class_id_to_map');

        if (mapToType === 'individual_students') {
            studentSelectDiv.style.display = 'block';
            classSelectDiv.style.display = 'none';
            if (studentSelect) {
                studentSelect.required = true;
                $(studentSelect).selectpicker('refresh'); // Refresh to ensure selectpicker applies required
            }
            if (classSelect) {
                classSelect.required = false;
                $(classSelect).selectpicker('val', ''); // Clear selected class in selectpicker
            }
        } else if (mapToType === 'class') {
            studentSelectDiv.style.display = 'none';
            classSelectDiv.style.display = 'block';
            if (studentSelect) {
                studentSelect.required = false;
                $(studentSelect).selectpicker('val', ''); // Clear selected students in selectpicker
            }
            if (classSelect) {
                classSelect.required = true;
                $(classSelect).selectpicker('refresh'); // Refresh to ensure selectpicker applies required
            }
        }
    }

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

        // --- Map Folder Form Logic (already present and improved) ---
        if (document.querySelector('input[name="map_to_type"]')) {
            toggleStudentClassSelection();
            document.querySelectorAll('input[name="map_to_type"]').forEach(radio => {
                radio.addEventListener('change', toggleStudentClassSelection);
            });
        }

        // --- Bulk Action and Checkbox Logic ---
        // There's no longer a mappingForm element for bulk actions as they are now AJAX.
        // Let's use a temporary form data object for fetch calls.
        const bulkActionMappingInput = document.getElementById('bulkActionMapping'); // This is from the old hidden input, will repurpose its value
        const masterCheckbox = document.getElementById('masterCheckbox');
        const tableBody = document.querySelector('.table-striped-edu tbody');
        const rowCheckboxes = () => tableBody ? tableBody.querySelectorAll('.row-checkbox') : [];

        // Get the current folder ID from PHP variable for AJAX sends
        const currentFolderId = <?= json_encode($folder_id_context); ?>;


        const activateMappingsBtn = document.getElementById('activateMappingsBtn');
        const deactivateMappingsBtn = document.getElementById('deactivateMappingsBtn');
        const mandateMappingsBtn = document.getElementById('mandateMappingsBtn'); // NEW
        const unmandateMappingsBtn = document.getElementById('unmandateMappingsBtn'); // NEW
        const deleteMappingsBtn = document.getElementById('deleteMappingsBtn');
        const selectAllBtn = document.getElementById('selectAllBtn');

        const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
        const confirmationModalBody = document.getElementById('confirmationModalBody');
        const confirmationModalHeader = document.getElementById('confirmationModalHeader');
        const confirmationModalLabel = document.getElementById('confirmationModalLabel');
        const confirmActionButton = document.getElementById('confirmActionButton');

        let currentBulkAction = ''; // To store the action type (e.g., 'activate_mapping')

        if (masterCheckbox) {
            masterCheckbox.addEventListener('change', function() {
                rowCheckboxes().forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
                toggleActionButtons();
            });
        }

        if (tableBody) {
            tableBody.addEventListener('change', function(event) {
                if (event.target.classList.contains('row-checkbox')) {
                    const allChecked = Array.from(rowCheckboxes()).every(checkbox => checkbox.checked);
                    if (masterCheckbox) masterCheckbox.checked = allChecked && rowCheckboxes().length > 0;
                    toggleActionButtons();
                }
            });
        }

        function toggleActionButtons() {
            const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
            const totalRows = rowCheckboxes().length; // Get the live count of rows

            if (activateMappingsBtn) activateMappingsBtn.disabled = !(checkedCount > 0);
            if (deactivateMappingsBtn) deactivateMappingsBtn.disabled = !(checkedCount > 0);
            if (mandateMappingsBtn) mandateMappingsBtn.disabled = !(checkedCount > 0); // NEW
            if (unmandateMappingsBtn) unmandateMappingsBtn.disabled = !(checkedCount > 0); // NEW
            if (deleteMappingsBtn) deleteMappingsBtn.disabled = !(checkedCount > 0);

            if (selectAllBtn) {
                // Check if all *displayed* rows are selected
                const allDisplayedSelected = checkedCount > 0 && checkedCount === totalRows;
                if (allDisplayedSelected) {
                    selectAllBtn.textContent = 'Deselect All';
                } else {
                    selectAllBtn.textContent = 'Select All';
                }
            }
        }
        toggleActionButtons(); // Initialize button states on page load


        if (selectAllBtn) {
            selectAllBtn.addEventListener('click', function() {
                const allSelected = document.querySelectorAll('.row-checkbox:checked').length === rowCheckboxes().length && rowCheckboxes().length > 0;
                if (masterCheckbox) masterCheckbox.checked = !allSelected; // Toggle master checkbox state
                rowCheckboxes().forEach(checkbox => {
                    checkbox.checked = !allSelected; // Set all checkboxes based on new master state
                });
                toggleActionButtons(); // Update button states
            });
        }

        function setupBulkActionButton(button, action, headerClass, btnClass, modalTitle, modalMessage) {
            if (button) {
                button.addEventListener('click', function() {
                    const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                    if (checkedCount > 0) {
                        currentBulkAction = action;
                        confirmationModalHeader.className = `modal-header ${headerClass} text-white`; // Corrected interpolation here
                        confirmationModalLabel.textContent = modalTitle;
                        confirmationModalBody.innerHTML = modalMessage.replace('$', checkedCount); // Corrected string interpolation
                        confirmActionButton.className = `btn ${btnClass}`; // Corrected interpolation here
                        confirmationModal.show();
                    } else {
                        showToast('info', `Please select at least one mapping to ${action.split('_')[0]}.`);
                    }
                });
            }
        }

        setupBulkActionButton(activateMappingsBtn, 'activate_mapping', 'bg-success', 'btn-success', 'Confirm Activation', 'Are you sure you want to activate <strong>$</strong> selected mapping(s)?');
        setupBulkActionButton(deactivateMappingsBtn, 'deactivate_mapping', 'bg-danger', 'btn-danger', 'Confirm Deactivation', 'Are you sure you want to deactivate <strong>$</strong> selected mapping(s)?');
        setupBulkActionButton(mandateMappingsBtn, 'mandate_mapping', 'bg-info', 'btn-mandate', 'Confirm Marking as Mandatory', 'Are you sure you want to mark <strong>$</strong> selected mapping(s) as MANDATORY?'); // NEW
        setupBulkActionButton(unmandateMappingsBtn, 'unmandate_mapping', 'custom-orange', 'btn-unmandate', 'Confirm Unmarking as Mandatory', 'Are you sure you want to unmark <strong>$</strong> selected mapping(s) as MANDATORY?'); // NEW
        setupBulkActionButton(deleteMappingsBtn, 'delete_mapping', 'bg-danger', 'btn-danger', 'Confirm Permanent Deletion', `
            <p><strong>WARNING: This action cannot be undone.</strong></p>
            <p>Are you absolutely sure you want to PERMANENTLY delete <strong>$</strong> selected mapping(s)? This will remove them from the database.</p>
        `);

        if (confirmActionButton) {
            confirmActionButton.addEventListener('click', async function() {
                confirmationModal.hide(); // Hide the modal quickly

                // Get selected IDs for the bulk action
                const selectedMappingIds = Array.from(document.querySelectorAll('.row-checkbox:checked'))
                    .map(checkbox => checkbox.value);

                if (selectedMappingIds.length === 0) {
                    showToast('info', 'No items selected for this operation.');
                    return;
                }

                const formData = new URLSearchParams();
                formData.append('bulk_action_mapping_submit', '1'); // Indicate this is a bulk action submit
                formData.append('bulk_action_mapping', currentBulkAction);
                formData.append('folder_id_context', currentFolderId); // Pass the current folder ID

                selectedMappingIds.forEach(id => {
                    formData.append('selected_mapping_ids[]', id);
                });

                // Add current filter parameters to formData so they can be repopulated after reload
                // CRITICAL FIX: Get the *current* URL including any new filters right before redirect
                const currentUrlForRedirect = new URL(window.location.href); 
                currentUrlForRedirect.searchParams.forEach((value, key) => {
                    // Prevent duplicate folder_id and other bulk action specifics
                    if (!['folder_id', 'bulk_action_mapping_submit', 'bulk_action_mapping', 'selected_mapping_ids[]'].includes(key)) {
                        // Handle array parameters (like class_ids[])
                        if (key.endsWith('[]')) {
                            // Append all values for multi-value parameters
                            currentUrlForRedirect.searchParams.getAll(key).forEach(val => {
                                formData.append(key, val);
                            });
                        } else {
                            formData.append(key, value);
                        }
                    }
                });


                try {
                    const response = await fetch('map_folders_to_students.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest' // Important for PHP to detect AJAX
                        },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }

                    const data = await response.json();
                    showToast(data.status, data.message);

                    // If successful, reload the page to reflect changes and apply filters
                    if (data.status === 'success' || data.status === 'info') {
                        setTimeout(() => {
                            // Construct the URL to redirect to, preserving filters
                            // Use the freshly retrieved URL to ensure filters are maintained
                            const params = new URLSearchParams(currentUrlForRedirect.search); 
                            params.set('folder_id', currentFolderId); // Ensure folder_id is always present
                            
                            // Optional: Clean up any bulk_action related parameters from params if they were added
                            params.delete('bulk_action_mapping_submit');
                            params.delete('bulk_action_mapping');
                            params.delete('selected_mapping_ids[]'); // Ensure no remnant of this array param

                            window.location.href = window.location.pathname + '?' + params.toString();
                        }, 500); // Small delay for toast visibility
                    }


                } catch (error) {
                    console.error('Fetch Error:', error);
                    showToast('danger', 'An error occurred during the bulk update. Please check console.');
                }
            });
        }


        // --- Single Toggle Active State (AJAX) ---
        document.querySelectorAll('.edu-toggle, .edu-toggle-mandate').forEach(toggle => { // Select both toggle types
            toggle.addEventListener('change', async function() { // Use async/await
                const mappingId = this.dataset.mappingId;
                const folderId = this.dataset.folderId;
                const field = this.dataset.field; // 'is_active' or 'is_mandate'
                const newState = this.checked ? 'y' : 'n';
                const originalState = !this.checked; // Store original state for rollback (the state *before* change)

                const formData = new URLSearchParams();
                formData.append('bulk_action_mapping_submit', '1'); // PHP detects this POST param
                formData.append('selected_mapping_ids[]', mappingId);
                formData.append('folder_id_context', folderId); // Pass along the folder ID for security in PHP

                let actionType;
                if (field === 'is_active') {
                    actionType = newState === 'y' ? 'activate_mapping' : 'deactivate_mapping';
                } else if (field === 'is_mandate') { // NEW
                    actionType = newState === 'y' ? 'mandate_mapping' : 'unmandate_mapping';
                }
                formData.append('bulk_action_mapping', actionType);

                try {
                    const response = await fetch('map_folders_to_students.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest' // Crucial for PHP to detect AJAX
                        },
                        body: formData.toString()
                    });

                    if (!response.ok) {
                        // If the HTTP status is not OK (e.g., 404, 500), throw an error.
                        // This is distinct from a server-side JSON error message.
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }

                    const data = await response.json(); // Expect JSON response

                    showToast(data.status, data.message); // Display toast based on response

                    // FIX: Only revert the toggle if the server explicitly indicates an 'error'.
                    // If the status is 'info' (e.g., "already in desired state"), the toggle should remain in its new, user-intended position.
                    if (data.status === 'error') {
                        toggle.checked = originalState; // Revert to original check state on a true server-side error
                    }
                    // For 'success', 'info', or 'warning' statuses, the toggle state (which already changed locally)
                    // should remain as is, as the server indicates the action was conceptually handled
                    // (either successfully applied or already in the requested state).

                } catch (error) {
                    console.error('Fetch Error:', error);
                    showToast('danger', 'An error occurred while updating status. Please check console (network/parsing error).');
                    toggle.checked = originalState; // Rollback on network/fetch error or JSON parsing error
                }
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

        // Attach change listener to all selectpickers that trigger filtering
        $('#classFilter, #religionFilter, #casteFilter').on('changed.bs.select', function(e, clickedIndex, isSelected, oldValue) {
            document.getElementById('searchForm').submit();
        });

        // Tooltip Initialization
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })

    });
    </script>
</body>
</html>
