<?php
// PHP Debugging: Enable all error reporting (TEMPORARY - REMOVE IN PRODUCTION)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once '../includes/admin_auth.php'; // Contains getCurrentUserClgId(), getCurrentUserId(), etc.
require_once '../includes/db.php'; // Your database connection ($conn)
require_once '../includes/functions.php'; // Contains prepare_and_execute, set_flash_message, redirect etc.

// IMPORTANT FIX: Define BASE_URL directly here instead of relying on links.php
// This makes the file more self-contained and less prone to pathing issues.
if (!defined('BASE_URL')) {
    define('BASE_URL', '/'); // A safe default (adjust if your application root is a subdirectory)
}

// --- DEBUGGING START: Initial Checks (for page load issues) ---
// If you want deep debugging, uncomment these blocks temporarily.
// echo "<h2>DEBUGGING INFORMATION (REMOVE IN PRODUCTION)</h2>";
// // Check database connection status
// if (!$conn) {
//    die("ERROR: Database connection failed when db.php was included!");
// } else {
//    echo "DEBUG: Database connected successfully.<br>";
// }
// // Display current session content
// echo "<h3>DEBUG: Session Content</h3>";
// echo "<pre>";
// print_r($_SESSION);
// echo "</pre>";
// // Verify Clg ID and raw counts directly from the database
// $clg_id_debug = getCurrentUserClgId();
// echo "DEBUG: Clg_id obtained from getCurrentUserClgId(): " . htmlspecialchars((string)$clg_id_debug) . "<br>";
// if ($clg_id_debug) {
//    // Check Students table count for this Clg_id
//    $stmt_students = $conn->prepare("SELECT COUNT(*) FROM Students WHERE Clg_id = ?");
//    if ($stmt_students) {
//     $stmt_students->bind_param("i", $clg_id_debug);
//     $stmt_students->execute();
//     $stmt_students->bind_result($student_count);
//     $stmt_students->fetch();
//     $stmt_students->close();
//     echo "DEBUG: Direct query - Students in DB for Clg_id {$clg_id_debug}: " . $student_count . " record(s).<br>";
//    } else {
//     echo "DEBUG: Failed to prepare student count check (direct): " . $conn->error . "<br>";
//    }
//    // Check Admin table count for this Clg_id
//    $stmt_admin = $conn->prepare("SELECT COUNT(*) FROM Admin WHERE Clg_id = ?");
//    if ($stmt_admin) {
//     $stmt_admin->bind_param("i", $clg_id_debug);
//     $stmt_admin->execute();
//     $stmt_admin->bind_result($admin_count);
//     $stmt_admin->fetch();
//     $stmt_admin->close();
//     echo "DEBUG: Direct query - Admins in DB for Clg_id {$clg_id_debug}: " . $admin_count . " record(s).<br>";
//    } else {
//     echo "DEBUG: Failed to prepare admin count check (direct): " . $conn->error . "<br>";
//    }
// } else {
//    echo "DEBUG: Clg_id is NULL or empty. Cannot perform direct DB check.<br>";
// }
// echo "<hr>";
// --- END DEBUGGING: Initial Checks ---

// Define page title
$pageTitle = "Manage Users - EduFlow";

$clg_id = getCurrentUserClgId(); // This will be the verified $clg_id
$current_user_id = getCurrentUserId();
$current_user_name = htmlspecialchars($_SESSION['name'] ?? 'Admin');

$default_tab = 'students';
$current_tab = $_GET['tab'] ?? $default_tab;
$action = $_GET['action'] ?? 'list'; // Default action is 'list'
$user_id_to_edit = null;
$user_data = null; // For pre-filling edit form

// Helper function for select options, place this at top of script or in functions.php
if (!function_exists('selected_opt')) {
    function selected_opt($val1, $val2){
        // Ensure both values are treated consistently, e.g., for '0' vs null vs empty string if necessary
        // Also ensure val1 is not null when comparing to avoid issues with `null == 0`
        if ($val1 !== null && trim((string)$val1) === trim((string)$val2)) echo "selected";
    }
}

// --- Helper Functions Specific to Users (similar to documents.php) ---

/**
 * Fetches user data based on role (students/admins) with filters and pagination.
 */
function getUsersData($conn, $clg_id, $role, $search_query, $filter_user_active, $filter_role_active, $class_id, $religion_id, $caste_id, $limit, $offset) {
    $params = []; // Start with an empty array for parameters
    $types = "";  // Start with an empty string for types
    $join_clause = '';
    $sql_where_parts = []; // Array to build WHERE conditions dynamically
    $order_by = 'u.Name ASC'; // Default sort order

    if ($role === 'admin') {
        $select_fields = 'u.Uid, u.Name, u.Email, u.Phone, u.Is_active as UserIsActive, a.Is_active as RoleIsActive, NULL as Class_name, NULL as Class_id, NULL as Religion_name, NULL as Religion_id, NULL as Caste_name, NULL as Caste_id';
        $join_clause = 'JOIN Admin a ON u.Uid = a.Uid';
        $sql_where_parts[] = 'a.Clg_id = ?';
        $params[] = $clg_id;
        $types .= "i";
    } elseif ($role === 'student') {
        $select_fields = 'u.Uid, u.Name, u.Email, u.Phone, u.Is_active as UserIsActive, s.Is_active as RoleIsActive, c.Class_name, s.Class_id, r.Religion_name, s.Religion_id, ca.Caste_name, s.Caste_id';
        $join_clause = 'JOIN Students s ON u.Uid = s.Student_id LEFT JOIN Classes c ON s.Class_id = c.Class_id LEFT JOIN Religion r ON s.Religion_id = r.Religion_id LEFT JOIN Caste ca ON s.Caste_id = ca.Caste_id';
        $sql_where_parts[] = 's.Clg_id = ?';
        $params[] = $clg_id;
        $types .= "i";
    } else {
        return []; // Invalid role
    }

    // Search query
    if (!empty($search_query)) {
        $sql_where_parts[] = "(u.Name LIKE ? OR u.Email LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    // User account active status filter
    if ($filter_user_active !== 'all') {
        $sql_where_parts[] = "u.Is_active = ?";
        $params[] = $filter_user_active;
        $types .= "s";
    }

    // Role active status filter
    if ($filter_role_active !== 'all') {
        if ($role === 'admin') {
            $sql_where_parts[] = "a.Is_active = ?";
        } else { // student
            $sql_where_parts[] = "s.Is_active = ?";
        }
        $params[] = $filter_role_active;
        $types .= "s";
    }

    // Student-specific filters
    if ($role === 'student') {
        if ($class_id !== null) {
            $sql_where_parts[] = "s.Class_id = ?";
            $params[] = $class_id;
            $types .= "i";
        }
        if ($religion_id !== null) {
            $sql_where_parts[] = "s.Religion_id = ?";
            $params[] = $religion_id;
            $types .= "i";
        }
        if ($caste_id !== null) {
            $sql_where_parts[] = "s.Caste_id = ?";
            $params[] = $caste_id;
            $types .= "i";
        }
    }

    $sql = "SELECT $select_fields FROM Users u $join_clause";
    if (!empty($sql_where_parts)) {
        $sql .= " WHERE " . implode(' AND ', $sql_where_parts);
    }

    // Add LIMIT and OFFSET for pagination
    $sql .= " ORDER BY $order_by LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $users;
    } catch (Exception $e) {
        error_log("CRITICAL ERROR fetching users in getUsersData: " . $e->getMessage());
        return [];
    }
}

/**
 * Counts total users based on role (students/admins) with filters.
 */
function countUsersData($conn, $clg_id, $role, $search_query, $filter_user_active, $filter_role_active, $class_id, $religion_id, $caste_id) {
    $params = []; // Start with an empty array for parameters
    $types = "";  // Start with an empty string for types
    $join_clause = '';
    $sql_where_parts = []; // Array to build WHERE conditions dynamically

    if ($role === 'admin') {
        $join_clause = 'JOIN Admin a ON u.Uid = a.Uid';
        $sql_where_parts[] = 'a.Clg_id = ?';
        $params[] = $clg_id;
        $types .= "i";
    } elseif ($role === 'student') {
        $join_clause = 'JOIN Students s ON u.Uid = s.Student_id LEFT JOIN Classes c ON s.Class_id = c.Class_id LEFT JOIN Religion r ON s.Religion_id = r.Religion_id LEFT JOIN Caste ca ON s.Caste_id = ca.Caste_id';
        $sql_where_parts[] = 's.Clg_id = ?';
        $params[] = $clg_id;
        $types .= "i";
    } else {
        return 0; // Invalid role
    }

    // Search query
    if (!empty($search_query)) {
        $sql_where_parts[] = "(u.Name LIKE ? OR u.Email LIKE ?)";
        $params[] = '%' . $search_query . '%';
        $params[] = '%' . $search_query . '%';
        $types .= "ss";
    }

    // User account active status filter
    if ($filter_user_active !== 'all') {
        $sql_where_parts[] = "u.Is_active = ?";
        $params[] = $filter_user_active;
        $types .= "s";
    }

    // Role active status filter
    if ($filter_role_active !== 'all') {
        if ($role === 'admin') {
            $sql_where_parts[] = "a.Is_active = ?";
        } else { // student
            $sql_where_parts[] = "s.Is_active = ?";
        }
        $params[] = $filter_role_active;
        $types .= "s";
    }

    // Student-specific filters
    if ($role === 'student') {
        if ($class_id !== null) {
            $sql_where_parts[] = "s.Class_id = ?";
            $params[] = $class_id;
            $types .= "i";
        }
        if ($religion_id !== null) {
            $sql_where_parts[] = "s.Religion_id = ?";
            $params[] = $religion_id;
            $types .= "i";
        }
        if ($caste_id !== null) {
            $sql_where_parts[] = "s.Caste_id = ?";
            $params[] = $caste_id;
            $types .= "i";
        }
    }

    $sql = "SELECT COUNT(*) FROM Users u $join_clause";
    if (!empty($sql_where_parts)) {
        $sql .= " WHERE " . implode(' AND ', $sql_where_parts);
    }

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $result = $stmt->get_result();
        $count = $result->fetch_row()[0];
        $stmt->close();
        return $count;
    } catch (Exception $e) {
        error_log("CRITICAL ERROR counting users in countUsersData: " . $e->getMessage());
        return 0;
    }
}

/**
 * Updates the Is_active status for user's role (Students or Admin).
 */
function updateUserRoleStatus($conn, array $user_ids, $role, $is_active_status, $clg_id) {
    if (empty($user_ids)) {
        return 0;
    }
    if (!in_array($is_active_status, ['y', 'n'])) {
        error_log("Invalid Is_active status provided for role: " . $is_active_status);
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $sql = "";
    if ($role === 'admin') {
        $sql = "UPDATE Admin SET Is_active = ? WHERE Clg_id = ? AND Uid IN ($placeholders)";
    } elseif ($role === 'student') {
        $sql = "UPDATE Students SET Is_active = ? WHERE Clg_id = ? AND Student_id IN ($placeholders)";
    } else {
        return false; // Invalid role
    }

    $types = "si" . str_repeat('i', count($user_ids));
    $params = array_merge([$is_active_status, $clg_id], $user_ids);

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $affected_rows;
    } catch (Exception $e) {
        error_log("Error updating user role status: " . $e->getMessage());
        return false;
    }
}

/**
 * Hard deletes user roles (Students or Admin). Does NOT delete from Users table.
 */
function hardDeleteUserRoles($conn, array $user_ids, $role, $clg_id) {
    if (empty($user_ids)) {
        return 0;
    }

    $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
    $sql = "";
    if ($role === 'admin') {
        $sql = "DELETE FROM Admin WHERE Clg_id = ? AND Uid IN ($placeholders)";
    } elseif ($role === 'student') {
        $sql = "DELETE FROM Students WHERE Clg_id = ? AND Student_id IN ($placeholders)";
    } else {
        return false; // Invalid role
    }

    $types = "i" . str_repeat('i', count($user_ids));
    $params = array_merge([$clg_id], $user_ids);

    try {
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        $affected_rows = $stmt->affected_rows;
        $stmt->close();
        return $affected_rows;
    } catch (Exception $e) {
        error_log("Error hard deleting user roles: " . $e->getMessage());
        return false;
    }
}


// --- Fetch data for dropdowns (Classes, Religions, Castes) ---
$college_classes = [];
$classes_stmt = prepare_and_execute($conn, "SELECT Class_id, Class_name, Semester, Session FROM Classes WHERE Clg_id = ? AND Is_active = 'y' ORDER BY Class_name", [$clg_id], "i");
$classes_result = $classes_stmt->get_result();
while ($class_row = $classes_result->fetch_assoc()) {
    $college_classes[] = $class_row;
}
$classes_stmt->close();

$college_religions = [];
$religions_stmt = prepare_and_execute($conn, "SELECT Religion_id, Religion_name FROM Religion WHERE Clg_id = ? AND Is_active = 'y' ORDER BY Religion_name", [$clg_id], "i");
$religions_result = $religions_stmt->get_result();
while ($religion_row = $religions_result->fetch_assoc()) {
    $college_religions[] = $religion_row;
}
$religions_stmt->close();

$college_castes = [];
$castes_stmt = prepare_and_execute($conn, "SELECT Caste_id, Caste_name FROM Caste WHERE Clg_id = ? AND Is_active = 'y' ORDER BY Caste_name", [$clg_id], "i");
$castes_result = $castes_stmt->get_result();
while ($caste_row = $castes_result->fetch_assoc()) {
    $college_castes[] = $caste_row;
}
$castes_stmt->close();
// --- End Fetch data for dropdowns ---

// --- Configuration for Pagination ---
$records_per_page = 15;
$current_page_num = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page_num - 1) * $records_per_page;

// --- Filter Parameters ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$filter_user_active = $_GET['filter_user_active'] ?? 'all';
$filter_role_active = $_GET['filter_role_active'] ?? 'all';

// Student-specific filters
$filter_class_id = filter_var($_GET['filter_class'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false ? (int)$_GET['filter_class'] : null;
$filter_religion_id = filter_var($_GET['filter_religion'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false ? (int)$_GET['filter_religion'] : null;
$filter_caste_id = filter_var($_GET['filter_caste'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false ? (int)$_GET['filter_caste'] : null;


// Define the base URL for this specific page (for redirects)
$current_page_base_url = $_SERVER['PHP_SELF'];


// --- Handle POST requests ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect current filter/pagination state to redirect back to it.
    // Use POST['tab'] if available and valid; otherwise, fall back to GET or default.
    $processed_tab = $_POST['tab'] ?? ($_GET['tab'] ?? $default_tab);

    $refresh_params = array_filter([
        'tab' => $processed_tab,
        'search' => $_GET['search'] ?? null,
        'page' => $_GET['page'] ?? null,
        'filter_user_active' => $_GET['filter_user_active'] ?? null,
        'filter_role_active' => $_GET['filter_role_active'] ?? null,
        'filter_class' => ($filter_class_id !== null) ? $_GET['filter_class'] : null,
        'filter_religion' => ($filter_religion_id !== null) ? $_GET['filter_religion'] : null,
        'filter_caste' => ($filter_caste_id !== null) ? $_GET['filter_caste'] : null,
    ], fn($value) => $value !== null && $value !== '');

    $redirect_url = BASE_URL . 'admin/users.php'; // Use BASE_URL directly
    if (!empty($refresh_params)) {
        $redirect_url .= '?' . http_build_query($refresh_params);
    }

    $action = $_POST['action'] ?? ''; // Get action from POST

    try {
        // --- ADD NEW USER (and then assign role) ---
        if ($action === 'add_new_user_submit') {
            $name = trim($_POST['name']);
            $email = trim($_POST['email']);
            $phone = trim($_POST['phone']);
            $password = $_POST['password'];
            $role_to_add = $_POST['role_to_add'];
            $is_active_user = isset($_POST['is_active_user']) ? 'y' : 'n';
            $is_active_role = isset($_POST['is_active_role']) ? 'y' : 'n';
            
            $class_id_student = filter_var($_POST['class_id_student'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $class_id_student = ($class_id_student !== false) ? $class_id_student : null;

            $religion_id_student = null;
            $caste_id_student = null;

            $validation_passed = true;
            if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($role_to_add)) {
                set_flash_message("Name, Email, Phone, Password, and Role are required.", "danger");
                $validation_passed = false;
            }
            if ($validation_passed && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                set_flash_message("Invalid email format.", "danger");
                $validation_passed = false;
            }
            if ($validation_passed && strlen($password) < 6) {
                set_flash_message("Password must be at least 6 characters long.", "danger");
                $validation_passed = false;
            }

            if ($role_to_add === 'student') {
                $religion_id_student = filter_var($_POST['religion_id_student'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $caste_id_student = filter_var($_POST['caste_id_student'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                
                if ($validation_passed && ($religion_id_student === false || $religion_id_student === null)) {
                    set_flash_message("Religion is required for students.", "danger");
                    $validation_passed = false;
                }
                if ($validation_passed && ($caste_id_student === false || $caste_id_student === null)) {
                    set_flash_message("Caste is required for students.", "danger");
                    $validation_passed = false;
                }
            }
            
            if (!$validation_passed) {
                $_SESSION['form_data'] = $_POST;
                redirect($redirect_url); // Redirect without specific action to prevent modal auto-reopen
            }
            
            $stmt_check_email = prepare_and_execute($conn, "SELECT Uid FROM Users WHERE Email = ?", [$email], "s");
            if ($stmt_check_email->get_result()->num_rows > 0) {
                set_flash_message("Email already exists. Try assigning an existing user to a role or use a different email.", "danger");
                $stmt_check_email->close();
                $_SESSION['form_data'] = $_POST;
                redirect($redirect_url); // Redirect without specific action to prevent modal auto-reopen
            }
            $stmt_check_email->close();

            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $conn->begin_transaction();
            try {
                $sql_user = "INSERT INTO Users (Name, Email, Phone, Pwd, Is_active) VALUES (?, ?, ?, ?, ?)";
                $stmt_user = prepare_and_execute($conn, $sql_user, [$name, $email, $phone, $hashed_password, $is_active_user], "sssss");
                $new_user_id = $stmt_user->insert_id;
                $stmt_user->close();
                if (!$new_user_id) throw new Exception("Failed to create user in Users table.");

                if ($role_to_add === 'admin') {
                    $sql_role = "INSERT INTO Admin (Uid, Clg_id, Created_by, Is_active) VALUES (?, ?, ?, ?)";
                    $stmt_role = prepare_and_execute($conn, $sql_role, [$new_user_id, $clg_id, $current_user_id, $is_active_role], "iiis");
                } elseif ($role_to_add === 'student') {
                    $sql_role = "INSERT INTO Students 
                                 (Student_id, Clg_id, Class_id, Religion_id, Caste_id, Created_by, Is_active) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt_role = prepare_and_execute($conn, $sql_role, 
                        [$new_user_id, $clg_id, $class_id_student, $religion_id_student, $caste_id_student, $current_user_id, $is_active_role],
                        "iiiiiss"
                    );
                } else { throw new Exception("Invalid role specified."); }

                if ($stmt_role->affected_rows <= 0) throw new Exception("Failed to assign role: " . $stmt_role->error);
                $stmt_role->close();
                $conn->commit();
                set_flash_message(ucfirst($role_to_add) . " '{$name}' added successfully!", "success");
                redirect($redirect_url);
            } catch (Exception $e) {
                $conn->rollback();
                set_flash_message("Error adding user: " . $e->getMessage(), "danger");
                $_SESSION['form_data'] = $_POST;
                redirect($redirect_url); // Redirect without specific action to prevent modal auto-reopen
            }
        }
        // --- ASSIGN EXISTING USER TO ROLE ---
        elseif ($action === 'assign_existing_user_submit') {
            $user_email_to_assign = trim($_POST['user_email_to_assign']);
            $role_to_assign = $_POST['role_to_assign'];
            $is_active_role_assign = isset($_POST['is_active_role_assign']) ? 'y' : 'n';
            
            $class_id_student_assign = filter_var($_POST['class_id_student_assign'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $class_id_student_assign = ($class_id_student_assign !== false) ? $class_id_student_assign : null;

            $religion_id_assign_student = null;
            $caste_id_assign_student = null;
            
            $validation_passed = true;
            if (empty($user_email_to_assign) || empty($role_to_assign)) {
                set_flash_message("Email and Role are required for assignment.", "danger");
                $validation_passed = false;
            }

            if ($role_to_assign === 'student') {
                $religion_id_assign_student = filter_var($_POST['religion_id_assign_student'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $caste_id_assign_student = filter_var($_POST['caste_id_assign_student'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                
                if ($validation_passed && ($religion_id_assign_student === false || $religion_id_assign_student === null)) {
                    set_flash_message("Religion is required when assigning an existing user as a student.", "danger");
                    $validation_passed = false;
                }
                if ($validation_passed && ($caste_id_assign_student === false || $caste_id_assign_student === null)) {
                    set_flash_message("Caste is required when assigning an existing user as a student.", "danger");
                    $validation_passed = false;
                }
            }

            if (!$validation_passed) {
                $_SESSION['form_data_assign'] = $_POST;
                redirect($redirect_url); // Redirect without specific action to prevent modal auto-reopen
            }
            
            $stmt_find_user = prepare_and_execute($conn, "SELECT Uid, Name FROM Users WHERE Email = ? AND Is_active = 'y'", [$user_email_to_assign], "s");
            $user_to_assign_data = $stmt_find_user->get_result()->fetch_assoc();
            $stmt_find_user->close();

            if (!$user_to_assign_data) {
                set_flash_message("No active user found with email '{$user_email_to_assign}'. Try adding as new user.", "danger");
                $_SESSION['form_data_assign'] = $_POST;
                redirect($redirect_url); // Redirect without specific action to prevent modal auto-reopen
            }
            $user_id_to_assign = $user_to_assign_data['Uid'];
            $user_name_to_assign = $user_to_assign_data['Name'];

            // Check if user already has this role in this college
            $already_assigned = false;
            if ($role_to_assign === 'admin') {
                $stmt_check = prepare_and_execute($conn, "SELECT Uid FROM Admin WHERE Uid = ? AND Clg_id = ?", [$user_id_to_assign, $clg_id], "ii");
                if ($stmt_check->get_result()->num_rows > 0) $already_assigned = true;
                $stmt_check->close();
            } elseif ($role_to_assign === 'student') {
                $stmt_check = prepare_and_execute($conn, "SELECT Student_id FROM Students WHERE Student_id = ? AND Clg_id = ?", [$user_id_to_assign, $clg_id], "ii");
                if ($stmt_check->get_result()->num_rows > 0) $already_assigned = true;
                $stmt_check->close();
            }

            if ($already_assigned) {
                set_flash_message("User '{$user_name_to_assign}' is already assigned as " . ucfirst($role_to_assign) . " in this college.", "warning");
                redirect($redirect_url);
            }

            $conn->begin_transaction();
            $stmt_assign = null;
            try {
                if ($role_to_assign === 'admin') {
                    $sql_assign = "INSERT INTO Admin (Uid, Clg_id, Created_by, Is_active) VALUES (?, ?, ?, ?)";
                    $stmt_assign = prepare_and_execute($conn, $sql_assign, [$user_id_to_assign, $clg_id, $current_user_id, $is_active_role_assign], "iiis");
                } elseif ($role_to_assign === 'student') {
                    $sql_assign = "INSERT INTO Students (Student_id, Clg_id, Class_id, Religion_id, Caste_id, Created_by, Is_active)
                                     VALUES (?, ?, ?, ?, ?, ?, ?)";
                    $stmt_assign = prepare_and_execute($conn, $sql_assign,
                        [$user_id_to_assign, $clg_id, $class_id_student_assign, $religion_id_assign_student, $caste_id_assign_student, $current_user_id, $is_active_role_assign],
                        "iiiiiss"
                    );
                } else {
                    throw new Exception("Invalid role specified for assignment.");
                }

                if ($stmt_assign && $stmt_assign->affected_rows > 0) {
                    $conn->commit();
                    set_flash_message("User '{$user_name_to_assign}' successfully assigned as " . ucfirst($role_to_assign) . ".", "success");
                } else {
                    throw new Exception("Failed to assign role: " . ($stmt_assign ? $stmt_assign->error : $conn->error));
                }
            } catch (Exception $e) {
                $conn->rollback();
                set_flash_message("Error assigning role: " . $e->getMessage(), "danger");
                $_SESSION['form_data_assign'] = $_POST;
                redirect($redirect_url); // Redirect without specific action to prevent modal auto-reopen
            } finally {
                if ($stmt_assign) $stmt_assign->close();
            }
            redirect($redirect_url);
        }
        // --- EDIT USER DETAILS / ROLE STATUS ---
        elseif ($action === 'edit_user_submit') {
            $old_tab = $_POST['tab'] ?? $default_tab; // This should be correct now
            $uid_edit = filter_input(INPUT_POST, 'uid_edit', FILTER_VALIDATE_INT);
            $role_edit = $_POST['role_edit'];
            $name_edit = trim($_POST['name_edit']);
            $phone_edit = trim($_POST['phone_edit']);
            $is_active_user_edit = isset($_POST['is_active_user_edit']) ? 'y' : 'n';
            $is_active_role_edit = isset($_POST['is_active_role_edit']) ? 'y' : 'n';
            
            $class_id_student_edit = filter_var($_POST['class_id_student_edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            $class_id_student_edit = ($class_id_student_edit !== false) ? $class_id_student_edit : null; // Ensure null if not set or invalid

            $religion_id_student_edit = null;
            $caste_id_student_edit = null;

            if (!$uid_edit || empty($name_edit) || empty($phone_edit) || empty($role_edit)) {
                set_flash_message("Invalid data for user update.", "danger");
                redirect(BASE_URL . "admin/users.php?tab={$old_tab}&action=edit_user&uid={$uid_edit}&role={$role_edit}");
            }

            if ($role_edit === 'student') {
                $religion_id_student_edit = filter_var($_POST['religion_id_student_edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $caste_id_student_edit = filter_var($_POST['caste_id_student_edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                
                if (($religion_id_student_edit === false || $religion_id_student_edit === null)) {
                    set_flash_message("Religion is required for students during edit.", "danger");
                    redirect(BASE_URL . "admin/users.php?tab={$old_tab}&action=edit_user&uid={$uid_edit}&role=student");
                }
                if (($caste_id_student_edit === false || $caste_id_student_edit === null)) {
                    set_flash_message("Caste is required for students during edit.", "danger");
                    redirect(BASE_URL . "admin/users.php?tab={$old_tab}&action=edit_user&uid={$uid_edit}&role=student");
                }
            }
            
            $conn->begin_transaction();
            try {
                $sql_update_user = "UPDATE Users SET Name = ?, Phone = ?, Is_active = ? WHERE Uid = ?";
                $stmt_update_user = prepare_and_execute($conn, $sql_update_user, [$name_edit, $phone_edit, $is_active_user_edit, $uid_edit], "sssi");

                $stmt_update_role = null;
                if ($role_edit === 'admin') {
                    $sql_update_role = "UPDATE Admin SET Is_active = ? WHERE Uid = ? AND Clg_id = ?";
                    $stmt_update_role = prepare_and_execute($conn, $sql_update_role, [$is_active_role_edit, $uid_edit, $clg_id], "sii");
                }
                elseif ($role_edit === 'student') {
                    $sql_update_role = "UPDATE Students SET Is_active = ?, Class_id = ?, Religion_id = ?, Caste_id = ?
                                         WHERE Student_id = ? AND Clg_id = ?";
                    $stmt_update_role = prepare_and_execute($conn, $sql_update_role,
                        [$is_active_role_edit, $class_id_student_edit, $religion_id_student_edit, $caste_id_student_edit, $uid_edit, $clg_id],
                        "siiiii"
                    );
                } else { throw new Exception("Invalid role for update."); }
                
                // IMPORTANT: Check if any statement failed, not just if there was an error message
                if (!$stmt_update_user || ($stmt_update_role && !$stmt_update_role)) {
                    throw new Exception("One or more update statements failed without specific error messages. Check DB logs.");
                }

                $conn->commit();
                set_flash_message(ucfirst($role_edit) . " '{$name_edit}' details updated successfully!", "success");
            } catch (Exception $e) {
                $conn->rollback(); set_flash_message("Error updating user: " . $e->getMessage(), "danger");
            }
            redirect($redirect_url);
        }
        // --- BULK ACTIONS ---
        elseif (in_array($action, ['bulk_activate_users', 'bulk_deactivate_users', 'bulk_hard_delete_users'])) {
            $selected_uids = $_POST['selected_uids'] ?? [];
            $selected_uids_filtered = array_filter(array_map('intval', $selected_uids));

            if (empty($selected_uids_filtered)) {
                set_flash_message("No users selected for bulk action.", "info");
            } else {
                $role_for_bulk = rtrim($current_tab, 's'); // 'students' -> 'student', 'admins' -> 'admin'
                $affected = 0;
                if ($action === 'bulk_activate_users') {
                    $affected = updateUserRoleStatus($conn, $selected_uids_filtered, $role_for_bulk, 'y', $clg_id);
                    if ($affected !== false) {
                        set_flash_message("{$affected} " . ucfirst($role_for_bulk) . "(s) activated successfully!", "success");
                    } else { throw new Exception("Failed to activate selected " . ucfirst($role_for_bulk) . "s."); }
                } elseif ($action === 'bulk_deactivate_users') {
                    $affected = updateUserRoleStatus($conn, $selected_uids_filtered, $role_for_bulk, 'n', $clg_id);
                    if ($affected !== false) {
                        set_flash_message("{$affected} " . ucfirst($role_for_bulk) . "(s) deactivated successfully!", "success");
                    } else { throw new Exception("Failed to deactivate selected " . ucfirst($role_for_bulk) . "s."); }
                } elseif ($action === 'bulk_hard_delete_users') {
                    $conn->begin_transaction();
                    try {
                        $affected = hardDeleteUserRoles($conn, $selected_uids_filtered, $role_for_bulk, $clg_id);
                        if ($affected !== false) {
                            $conn->commit();
                            set_flash_message("{$affected} " . ucfirst($role_for_bulk) . "(s) permanently removed from this role!", "success");
                        } else { throw new Exception("Failed to permanently remove selected " . ucfirst($role_for_bulk) . "s from this role."); }
                    } catch (Exception $e) {
                        $conn->rollback();
                        throw $e; // Re-throw to be caught by outer try-catch
                    }
                }
            }
            redirect($redirect_url);
        }
        // --- AJAX: Toggle single user role status ---
        elseif ($action === 'toggle_role_active') {
            header('Content-Type: application/json');
            $uid = filter_input(INPUT_POST, 'uid', FILTER_VALIDATE_INT);
            $role_tog = $_POST['role'] ?? '';
            $is_active = $_POST['is_active'] ?? 'n';

            if ($uid === false || empty($role_tog) || !in_array($is_active, ['y', 'n'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid data for toggle.']);
                exit();
            }
            
            $affected = updateUserRoleStatus($conn, [$uid], $role_tog, $is_active, $clg_id);
            if ($affected > 0) {
                echo json_encode(['success' => true, 'message' => ucfirst($role_tog) . ' role status updated.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to update ' . ucfirst($role_tog) . ' role status.']);
            }
            exit();
        }
        
    } catch (Exception $e) {
        set_flash_message("Operation failed: " . $e->getMessage(), "danger");
        redirect($redirect_url); // Redirect even on error to prevent re-submission
    }
}

// --- Handle GET requests for Edit user ---
if ($action === 'edit_user' && isset($_GET['uid']) && isset($_GET['role'])) {
    $user_id_to_edit = filter_var($_GET['uid'], FILTER_VALIDATE_INT);
    $role_to_edit = $_GET['role'];
    if ($user_id_to_edit && ($role_to_edit === 'admin' || $role_to_edit === 'student')) {
        if ($role_to_edit === 'admin') {
            $sql_fetch = "SELECT u.Uid, u.Name, u.Email, u.Phone, u.Is_active as UserIsActive, a.Is_active as RoleIsActive
                         FROM Users u JOIN Admin a ON u.Uid = a.Uid WHERE u.Uid = ? AND a.Clg_id = ?";
        } else { // student
            $sql_fetch = "SELECT u.Uid, u.Name, u.Email, u.Phone, u.Is_active as UserIsActive,
                                 s.Is_active as RoleIsActive, s.Class_id, s.Religion_id, s.Caste_id
                         FROM Users u JOIN Students s ON u.Uid = s.Student_id
                         WHERE u.Uid = ? AND s.Clg_id = ?";
        }
        $stmt_fetch = prepare_and_execute($conn, $sql_fetch, [$user_id_to_edit, $clg_id], "ii");
        $user_data = $stmt_fetch->get_result()->fetch_assoc();
        $stmt_fetch->close();
        if (!$user_data) {
            set_flash_message("User or role not found for this college.", "danger");
            redirect(BASE_URL . "admin/users.php?tab={$role_to_edit}s");
        }
        $user_data['role'] = $role_to_edit;
    } else {
        set_flash_message("Invalid user ID or role for edit.", "danger");
        redirect(BASE_URL . "admin/users.php?tab={$current_tab}");
    }
}

// --- Fetch Users for Listing based on tab and filters ---
$users_list = getUsersData(
    $conn,
    $clg_id,
    rtrim($current_tab, 's'), // e.g., 'students' becomes 'student'
    $search_query,
    $filter_user_active,
    $filter_role_active,
    $filter_class_id,
    $filter_religion_id,
    $filter_caste_id,
    $records_per_page,
    $offset
);

$total_user_records = countUsersData(
    $conn,
    $clg_id,
    rtrim($current_tab, 's'),
    $search_query,
    $filter_user_active,
    $filter_role_active,
    $filter_class_id,
    $filter_religion_id,
    $filter_caste_id
);
$total_pages = ceil($total_user_records / $records_per_page);

// Calculate stats for "Showing X of Y total records"
$start_record = $total_user_records > 0 ? ($offset + 1) : 0;
$end_record = min(($current_page_num * $records_per_page), $total_user_records);


// Preserve form data from session if validation failed on POST
$form_data_add_user = $_SESSION['form_data'] ?? []; // For add new user
$form_data_assign_user = $_SESSION['form_data_assign'] ?? []; // For assign existing user
unset($_SESSION['form_data']);
unset($_SESSION['form_data_assign']);


// Helper function to build query parameters for pagination and filters
function buildPaginationQuery($currentPage, $current_tab, $search_query, $filter_user_active, $filter_role_active, $filter_class_id, $filter_religion_id, $filter_caste_id) {
    if ($filter_class_id === 0) $filter_class_id = null; // Filter_var makes 0 valid, but it should be null if not selected
    if ($filter_religion_id === 0) $filter_religion_id = null;
    if ($filter_caste_id === 0) $filter_caste_id = null;


    $query_params = array_filter([
        'tab' => $current_tab,
        'page' => ($currentPage == 1 && $search_query === '' && $filter_user_active === 'all' && $filter_role_active === 'all' && $filter_class_id === null && $filter_religion_id === null && $filter_caste_id === null) ? null : $currentPage,
        'search' => !empty($search_query) ? $search_query : null,
        'filter_user_active' => $filter_user_active !== 'all' ? $filter_user_active : null,
        'filter_role_active' => $filter_role_active !== 'all' ? $filter_role_active : null,
        'filter_class' => $filter_class_id !== null ? $filter_class_id : null,
        'filter_religion' => $filter_religion_id !== null ? $filter_religion_id : null,
        'filter_caste' => $filter_caste_id !== null ? $filter_caste_id : null,
    ], fn($value) => $value !== null && $value !== '');

    return BASE_URL . 'admin/users.php' . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
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
            background-color: '#e3a9b0'; /* Lighter dark-red for disabled */
            border-color: '#e3a9b0';
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
        /* Custom tabs */
        .nav-tabs .nav-link {
            border: 1px solid transparent;
            border-top-left-radius: 0.5rem;
            border-top-right-radius: 0.5rem;
            color: var(--edu-primary-blue);
            transition: all 0.3s ease;
            box-shadow: none;
        }
        .nav-tabs .nav-link:hover {
            border-color: var(--edu-light-blue) var(--edu-light-blue) var(--edu-white);
        }
        .nav-tabs .nav-link.active {
            color: var(--edu-darkest-blue);
            background-color: var(--edu-white);
            border-color: var(--edu-light-blue) var(--edu-light-blue) var(--edu-white);
            border-bottom-color: var(--edu-white); /* active tab has white border-bottom */
            font-weight: bold;
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
            <a class="navbar-brand edu-brand" href="<?= BASE_URL ?>admin/dashboard.php">
                <!-- Changed logo path to relative for `admin/` directory -->
                <img src="<?= BASE_URL ?>admin/logo.png" alt="EduFlow Logo" class="navbar-logo">
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
                        <a class="nav-link edu-nav-link" href="<?= BASE_URL ?>admin/dashboard.php">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= BASE_URL ?>admin/documents.php">Manage Documents</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= BASE_URL ?>admin/folders.php">Manage Folders</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= BASE_URL ?>admin/classes.php">Manage Classes</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link active" href="<?= BASE_URL ?>admin/users.php?tab=students">Manage Users</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link edu-nav-link" href="<?= BASE_URL ?>admin/submissions.php">View Submissions</a>
                    </li>
                    <!-- Add more navigation links as needed -->
                </ul>
                <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
                    <!-- Logout Button -->
                    <li class="nav-item ms-lg-3">
                        <form action="<?= BASE_URL ?>logout.php" method="POST" class="d-inline">
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
                    <h1>Manage Users</h1>
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

                <!-- Tabs for Students and Admins -->
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item"><a class="nav-link <?= ($current_tab === 'students') ? 'active' : ''; ?>" href="?tab=students">Students</a></li>
                    <li class="nav-item"><a class="nav-link <?= ($current_tab === 'admins') ? 'active' : ''; ?>" href="?tab=admins">Admins</a></li>
                </ul>

                <!-- Action Buttons -->
                <div class="crud-buttons mb-4">
                    <button type="button" class="btn btn-insert" data-bs-toggle="modal" data-bs-target="#addUserModal">
                        Add New <?= ucfirst(rtrim($current_tab, 's')); ?>
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assignUserModal">
                        Assign Existing User as <?= ucfirst(rtrim($current_tab, 's')); ?>
                    </button>
                    <button type="button" class="btn btn-update" id="updateUserBtn" disabled>Edit User</button>
                    <button type="button" class="btn btn-activate" id="activateRoleBtn" disabled>Activate Selected Role</button>
                    <button type="button" class="btn btn-delete" id="deactivateRoleBtn" disabled>Deactivate Selected Role</button>
                    <button type="button" class="btn btn-hard-delete" id="hardDeleteRoleBtn" disabled>Hard Delete Selected Role</button>
                    <button type="button" class="btn btn-select-all" id="selectAllBtn">Select All</button>
                </div>

                <!-- Search & Filters Area -->
                <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchForm" action="<?= htmlspecialchars(BASE_URL . 'admin/users.php') ?>">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab) ?>">
                    <div class="input-group">
                        <input type="text" class="form-control" name="search" placeholder="Search by name or email..." value="<?= htmlspecialchars($search_query) ?>">
                        <?php if (!empty($search_query)): ?>
                            <button type="button" class="btn btn-outline-secondary" id="clearSearchInput" title="Clear Search">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Apply Search</button>
                    </div>

                    <!-- User Account Active Filter -->
                    <select class="selectpicker" data-width="fit" name="filter_user_active" id="filterUserActive" title="User Account Active">
                        <option value="all" <?= selected_opt($filter_user_active, 'all'); ?>>User Account: All</option>
                        <option value="y" <?= selected_opt($filter_user_active, 'y'); ?>>User Account: Active</option>
                        <option value="n" <?= selected_opt($filter_user_active, 'n'); ?>>User Account: Inactive</option>
                    </select>

                    <!-- Role Active Filter -->
                    <select class="selectpicker" data-width="fit" name="filter_role_active" id="filterRoleActive" title="Role Active">
                        <option value="all" <?= selected_opt($filter_role_active, 'all'); ?>><?= ucfirst(rtrim($current_tab, 's')); ?> Role: All</option>
                        <option value="y" <?= selected_opt($filter_role_active, 'y'); ?>><?= ucfirst(rtrim($current_tab, 's')); ?> Role: Active</option>
                        <option value="n" <?= selected_opt($filter_role_active, 'n'); ?>><?= ucfirst(rtrim($current_tab, 's')); ?> Role: Inactive</option>
                    </select>

                    <?php if ($current_tab === 'students'): ?>
                    <!-- Student Specific Filters -->
                    <select class="selectpicker" data-width="fit" name="filter_class" id="filterClass" title="Filter by Class">
                        <option value="">Filter by Class</option>
                        <?php foreach ($college_classes as $class): ?>
                            <option value="<?= $class['Class_id']; ?>" <?= selected_opt($filter_class_id, $class['Class_id']); ?>>
                                <?= htmlspecialchars($class['Class_name'] . ($class['Semester'] ? ' Sem ' . $class['Semester'] : '') . ($class['Session'] ? ' (' . $class['Session'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select class="selectpicker" data-width="fit" name="filter_religion" id="filterReligion" title="Filter by Religion">
                        <option value="">Filter by Religion</option>
                        <?php foreach ($college_religions as $religion): ?>
                            <option value="<?= $religion['Religion_id']; ?>" <?= selected_opt($filter_religion_id, $religion['Religion_id']); ?>>
                                <?= htmlspecialchars($religion['Religion_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select class="selectpicker" data-width="fit" name="filter_caste" id="filterCaste" title="Filter by Caste">
                        <option value="">Filter by Caste</option>
                        <?php foreach ($college_castes as $caste): ?>
                            <option value="<?= $caste['Caste_id']; ?>" <?= selected_opt($filter_caste_id, $caste['Caste_id']); ?>>
                                <?= htmlspecialchars($caste['Caste_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>

                    <?php
                       $has_filters = !empty($search_query) || ($filter_user_active !== 'all') || ($filter_role_active !== 'all') ||
                       ($current_tab === 'students' && ($filter_class_id !== null || $filter_religion_id !== null || $filter_caste_id !== null));
                       if ($has_filters):
                    ?>
                        <a href="<?= htmlspecialchars(buildPaginationQuery(1, $current_tab, null, 'all', 'all', null, null, null)) ?>" class="btn btn-outline-secondary">Clear All Filters</a>
                    <?php endif; ?>
                </form>

                <!-- Users Listing Table -->
                <form id="userForm" method="POST" action="<?= htmlspecialchars(BASE_URL . 'admin/users.php') ?>">
                    <input type="hidden" name="action" id="formAction">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab) ?>"> <!-- Pass current tab for bulk actions -->
                    <div class="table-responsive">
                        <table class="table table-striped-edu text-nowrap">
                            <thead>
                                <tr>
                                    <th scope="col" style="width: 50px;">
                                        <input type="checkbox" id="masterCheckbox">
                                    </th>
                                    <th scope="col" class="fw-bold">Name</th>
                                    <th scope="col" class="fw-bold">Email</th>
                                    <th scope="col" class="fw-bold">Phone</th>
                                    <th scope="col" class="fw-bold">User Acct. Active</th>
                                    <th scope="col" class="fw-bold"><?= ucfirst(rtrim($current_tab, 's')); ?> Role Active</th>
                                    <?php if ($current_tab === 'students'): ?>
                                    <th scope="col" class="fw-bold">Class</th>
                                    <th scope="col" class="fw-bold">Religion</th>
                                    <th scope="col" class="fw-bold">Caste</th>
                                    <?php endif; ?>
                                    <th scope="col" class="fw-bold">UserID</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($users_list)): ?>
                                    <?php foreach ($users_list as $user): ?>
                                        <tr>
                                            <td><input type="checkbox" name="selected_uids[]" value="<?= htmlspecialchars($user['Uid']) ?>" class="row-checkbox"></td>
                                            <td class="user_name_cell"><?= htmlspecialchars($user['Name']) ?></td>
                                            <td class="user_email_cell"><?= htmlspecialchars($user['Email']) ?></td>
                                            <td class="user_phone_cell"><?= htmlspecialchars($user['Phone']) ?></td>
                                            <td class="user_is_active_cell"><?= $user['UserIsActive'] == 'y' ? 'Yes' : 'No' ?></td>
                                            <td class="role_is_active_cell">
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input edu-toggle" type="checkbox" role="switch" id="toggleRoleActive_<?= $user['Uid'] ?>" data-uid="<?= $user['Uid'] ?>" data-role="<?= rtrim($current_tab, 's'); ?>" <?= $user['RoleIsActive'] == 'y' ? 'checked' : '' ?>>
                                                    <label class="form-check-label visually-hidden" for="toggleRoleActive_<?= $user['Uid'] ?>">Toggle Role Active</label>
                                                </div>
                                            </td>
                                            <?php if ($current_tab === 'students'): ?>
                                            <td class="user_class_cell" data-class-id="<?= htmlspecialchars($user['Class_id'] ?? '') ?>"><?= htmlspecialchars($user['Class_name'] ?? 'N/A') ?></td>
                                            <td class="user_religion_cell" data-religion-id="<?= htmlspecialchars($user['Religion_id'] ?? '') ?>"><?= htmlspecialchars($user['Religion_name'] ?? 'N/A') ?></td>
                                            <td class="user_caste_cell" data-caste-id="<?= htmlspecialchars($user['Caste_id'] ?? '') ?>"><?= htmlspecialchars($user['Caste_name'] ?? 'N/A') ?></td>
                                            <?php endif; ?>
                                            <td class="user_uid_cell"><?= htmlspecialchars($user['Uid']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="<?= ($current_tab === 'students') ? 9 : 6 ?>" class="text-center py-4">No users found matching your criteria.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </form>

                <!-- Pagination Stats -->
                <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
                    <?php if ($total_user_records > 0): ?>
                        <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $total_user_records ?> total records.</p>
                    <?php else: ?>
                        <p class="text-muted mb-0">No records found matching your criteria.</p>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <nav aria-label="Page navigation" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <li class="page-item <?= ($current_page_num <= 1) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page_num - 1, $current_tab, $search_query, $filter_user_active, $filter_role_active, $filter_class_id, $filter_religion_id, $filter_caste_id)) ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?= ($i == $current_page_num) ? 'active' : '' ?>">
                                <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($i, $current_tab, $search_query, $filter_user_active, $filter_role_active, $filter_class_id, $filter_religion_id, $filter_caste_id)) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= ($current_page_num >= $total_pages) ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page_num + 1, $current_tab, $search_query, $filter_user_active, $filter_role_active, $filter_class_id, $filter_religion_id, $filter_caste_id)) ?>">Next</a>
                        </li>
                    </ul>
                </nav>

            </div>
        </div>
    </main>

    <!-- Modals for CRUD operations -->

    <!-- Add User Modal -->
    <div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="addUserModalLabel">Add New <?= ucfirst(rtrim($current_tab, 's')); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars(BASE_URL . 'admin/users.php') ?>" method="POST">
                    <input type="hidden" name="action" value="add_new_user_submit">
                    <input type="hidden" name="role_to_add" value="<?= htmlspecialchars(rtrim($current_tab, 's')); ?>">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab); ?>"> <!-- Hidden field for tab -->
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" value="<?= htmlspecialchars($form_data_add_user['name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($form_data_add_user['email'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?= htmlspecialchars($form_data_add_user['phone'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="password" name="password" required>
                            </div>
                        </div>

                        <?php if (rtrim($current_tab, 's') === 'student'): ?>
                            <div class="mb-3">
                                <label for="class_id_student" class="form-label">Assign to Class (Optional)</label>
                                <select class="form-select selectpicker" data-live-search="true" id="class_id_student" name="class_id_student">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($college_classes as $class): ?>
                                        <option value="<?= $class['Class_id']; ?>" <?= selected_opt($form_data_add_user['class_id_student'] ?? null, $class['Class_id']); ?>>
                                            <?= htmlspecialchars($class['Class_name'] . ($class['Semester'] ? ' Sem ' . $class['Semester'] : '') . ($class['Session'] ? ' (' . $class['Session'] . ')' : '')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="religion_id_student" class="form-label">Religion <span class="text-danger">*</span></label>
                                    <select class="form-select selectpicker" data-live-search="true" id="religion_id_student" name="religion_id_student" required>
                                        <option value="">-- Select Religion --</option>
                                        <?php foreach ($college_religions as $religion): ?>
                                        <option value="<?= $religion['Religion_id']; ?>" <?= selected_opt($form_data_add_user['religion_id_student'] ?? null, $religion['Religion_id']); ?>>
                                            <?= htmlspecialchars($religion['Religion_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="caste_id_student" class="form-label">Caste <span class="text-danger">*</span></label>
                                    <select class="form-select selectpicker" data-live-search="true" id="caste_id_student" name="caste_id_student" required>
                                        <option value="">-- Select Caste --</option>
                                        <?php foreach ($college_castes as $caste): ?>
                                        <option value="<?= $caste['Caste_id']; ?>" <?= selected_opt($form_data_add_user['caste_id_student'] ?? null, $caste['Caste_id']); ?>>
                                            <?= htmlspecialchars($caste['Caste_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6 mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active_user" name="is_active_user" value="y" <?= (isset($form_data_add_user['is_active_user']) && $form_data_add_user['is_active_user'] == 'y') || !isset($form_data_add_user['name']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active_user">User Account Active</label>
                            </div>
                            <div class="col-md-6 mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active_role" name="is_active_role" value="y" <?= (isset($form_data_add_user['is_active_role']) && $form_data_add_user['is_active_role'] == 'y') || !isset($form_data_add_user['name']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="is_active_role"><?= ucfirst(rtrim($current_tab, 's')); ?> Role Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="add_new_user_submit_modal" class="btn btn-primary">Add New <?= ucfirst(rtrim($current_tab, 's')); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assign Existing User Modal -->
    <div class="modal fade" id="assignUserModal" tabindex="-1" aria-labelledby="assignUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="assignUserModalLabel">Assign Existing User as <?= ucfirst(rtrim($current_tab, 's')); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars(BASE_URL . 'admin/users.php') ?>" method="POST">
                    <input type="hidden" name="action" value="assign_existing_user_submit">
                    <input type="hidden" name="role_to_assign" value="<?= htmlspecialchars(rtrim($current_tab, 's')); ?>">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab); ?>"> <!-- Hidden field for tab -->
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="user_email_to_assign" class="form-label">User Email to Assign <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="user_email_to_assign" name="user_email_to_assign" value="<?= htmlspecialchars($form_data_assign_user['user_email_to_assign'] ?? ''); ?>" placeholder="Enter email of existing user" required>
                        </div>
                        <?php if (rtrim($current_tab, 's') === 'student'): ?>
                            <div class="mb-3">
                                <label for="class_id_student_assign" class="form-label">Assign to Class (Optional)</label>
                                <select class="form-select selectpicker" data-live-search="true" id="class_id_student_assign" name="class_id_student_assign">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($college_classes as $class): ?>
                                    <option value="<?= $class['Class_id']; ?>" <?= selected_opt($form_data_assign_user['class_id_student_assign'] ?? null, $class['Class_id']); ?>>
                                        <?= htmlspecialchars($class['Class_name'] . ($class['Semester'] ? ' Sem ' . $class['Semester'] : '') . ($class['Session'] ? ' (' . $class['Session'] . ')' : '')); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="religion_id_assign_student" class="form-label">Religion <span class="text-danger">*</span></label>
                                    <select class="form-select selectpicker" data-live-search="true" id="religion_id_assign_student" name="religion_id_assign_student" required>
                                        <option value="">-- Select Religion --</option>
                                        <?php foreach ($college_religions as $religion): ?>
                                        <option value="<?= $religion['Religion_id']; ?>" <?= selected_opt($form_data_assign_user['religion_id_assign_student'] ?? null, $religion['Religion_id']); ?>>
                                            <?= htmlspecialchars($religion['Religion_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="caste_id_assign_student" class="form-label">Caste <span class="text-danger">*</span></label>
                                    <select class="form-select selectpicker" data-live-search="true" id="caste_id_assign_student" name="caste_id_assign_student" required>
                                        <option value="">-- Select Caste --</option>
                                        <?php foreach ($college_castes as $caste): ?>
                                        <option value="<?= $caste['Caste_id']; ?>" <?= selected_opt($form_data_assign_user['caste_id_assign_student'] ?? null, $caste['Caste_id']); ?>>
                                            <?= htmlspecialchars($caste['Caste_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="is_active_role_assign" name="is_active_role_assign" value="y" <?= (isset($form_data_assign_user['is_active_role_assign']) && $form_data_assign_user['is_active_role_assign'] == 'y') || !isset($form_data_assign_user['user_email_to_assign']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active_role_assign"><?= ucfirst(rtrim($current_tab, 's')); ?> Role Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="assign_existing_user_submit_modal" class="btn btn-primary">Assign Role</button>
                    </div>
                </form>
            </div>
        </div>
    </div>


    <!-- Update User Modal -->
    <div class="modal fade" id="updateUserModal" tabindex="-1" aria-labelledby="updateUserModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark">
                    <h5 class="modal-title" id="updateUserModalLabel">Edit User Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="<?= htmlspecialchars(BASE_URL . 'admin/users.php') ?>" method="POST">
                    <input type="hidden" name="action" value="edit_user_submit">
                    <input type="hidden" name="uid_edit" id="updateUidEdit">
                    <input type="hidden" name="role_edit" id="updateRoleEdit">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($current_tab); ?>"> <!-- Hidden field for tab -->

                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="name_edit" class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name_edit" name="name_edit" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email_edit" class="form-label">Email (Cannot Change)</label>
                                <input type="email" class="form-control" id="email_edit" name="email_edit" readonly>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="phone_edit" class="form-label">Phone <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="phone_edit" name="phone_edit" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <!-- This space can be used for other fields if needed, or left empty -->
                            </div>
                        </div>
                        
                        <div id="student_edit_fields">
                            <!-- These fields will be shown/hidden by JS depending on role_edit -->
                            <div class="mb-3">
                                <label for="class_id_student_edit" class="form-label">Assign to Class (Optional)</label>
                                <select class="form-select selectpicker" data-live-search="true" id="class_id_student_edit" name="class_id_student_edit">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($college_classes as $class): ?>
                                    <option value="<?= $class['Class_id']; ?>"><?= htmlspecialchars($class['Class_name'] . ($class['Semester'] ? ' Sem ' . $class['Semester'] : '') . ($class['Session'] ? ' (' . $class['Session'] . ')' : '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="religion_id_student_edit" class="form-label">Religion <span class="text-danger">*</span></label>
                                    <select class="form-select selectpicker" data-live-search="true" id="religion_id_student_edit" name="religion_id_student_edit">
                                        <option value="">-- Select Religion --</option>
                                        <?php foreach ($college_religions as $religion): ?>
                                        <option value="<?= $religion['Religion_id']; ?>"><?= htmlspecialchars($religion['Religion_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="caste_id_student_edit" class="form-label">Caste <span class="text-danger">*</span></label>
                                    <select class="form-select selectpicker" data-live-search="true" id="caste_id_student_edit" name="caste_id_student_edit">
                                        <option value="">-- Select Caste --</option>
                                        <?php foreach ($college_castes as $caste): ?>
                                        <option value="<?= $caste['Caste_id']; ?>"><?= htmlspecialchars($caste['Caste_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active_user_edit" name="is_active_user_edit" value="y">
                                <label class="form-check-label" for="is_active_user_edit">User Account Active</label>
                            </div>
                            <div class="col-md-6 mb-3 form-check">
                                <input type="checkbox" class="form-check-input" id="is_active_role_edit" name="is_active_role_edit" value="y">
                                <label class="form-check-label" for="is_active_role_edit"><span id="role_edit_label"></span> Role Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="edit_user_submit_modal" class="btn btn-warning">Update Details</button>
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
            <p>User Management System for Educational Institutions.</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Bootstrap-select
            $('.selectpicker').selectpicker();

            // Navbar padding adjustment
            var eduNavbar = document.querySelector('.edu-navbar');
            var body = document.body;
            function setBodyPadding() {
                // Ensure eduNavbar height is calculated after any potential dynamic changes (e.g., mobile menu expand)
                const navbarHeight = eduNavbar.offsetHeight;
                body.style.paddingTop = navbarHeight + 'px';
            }
            // Adjust padding on load and resize
            // Also adjust on Bootstrap collapse events to handle navbar height changes
            window.addEventListener('load', setBodyPadding);
            window.addEventListener('resize', setBodyPadding);
            // Listen for Bootstrap's collapse events to re-calculate padding
            $('#navbarNav').on('shown.bs.collapse hidden.bs.collapse', setBodyPadding);
            // Initial call
            setBodyPadding();


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

            // --- CRUD Button Logic ---
            const userForm = document.getElementById('userForm');
            const formAction = document.getElementById('formAction');
            const masterCheckbox = document.getElementById('masterCheckbox');
            const tableBody = document.querySelector('.table-striped-edu tbody');
            const rowCheckboxes = () => tableBody.querySelectorAll('.row-checkbox');
            const updateUserBtn = document.getElementById('updateUserBtn');
            const deactivateRoleBtn = document.getElementById('deactivateRoleBtn');
            const activateRoleBtn = document.getElementById('activateRoleBtn');
            const hardDeleteRoleBtn = document.getElementById('hardDeleteRoleBtn');
            const selectAllBtn = document.getElementById('selectAllBtn');

            const addUserModal = new bootstrap.Modal(document.getElementById('addUserModal')); 
            const assignUserModal = new bootstrap.Modal(document.getElementById('assignUserModal')); 
            const updateUserModal = new bootstrap.Modal(document.getElementById('updateUserModal'));
            const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
            const confirmationModalBody = document.getElementById('confirmationModalBody');
            const confirmationModalHeader = document.getElementById('confirmationModalHeader');
            const confirmationModalLabel = document.getElementById('confirmationModalLabel');
            const confirmActionButton = document.getElementById('confirmActionButton');

            // For update user form fields
            const updateUidEdit = document.getElementById('updateUidEdit');
            const updateRoleEdit = document.getElementById('updateRoleEdit');
            const nameEdit = document.getElementById('name_edit');
            const emailEdit = document.getElementById('email_edit');
            const phoneEdit = document.getElementById('phone_edit');
            const is_active_user_edit = document.getElementById('is_active_user_edit');
            const is_active_role_edit = document.getElementById('is_active_role_edit');
            const roleEditLabel = document.getElementById('role_edit_label');
            const studentEditFields = document.getElementById('student_edit_fields');

            const classIdStudentEdit = document.getElementById('class_id_student_edit');
            const religionIdStudentEdit = document.getElementById('religion_id_student_edit');
            const casteIdStudentEdit = document.getElementById('caste_id_student_edit');

            let currentAction = '';
            const currentTabRoleName = "<?= rtrim($current_tab, 's'); ?>"; // 'student' or 'admin'
            const currentTabFullName = "<?= $current_tab; ?>"; // 'students' or 'admins'

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

                updateUserBtn.disabled = !(checkedCount === 1);
                deactivateRoleBtn.disabled = !(checkedCount > 0);
                activateRoleBtn.disabled = !(checkedCount > 0);
                hardDeleteRoleBtn.disabled = !(checkedCount > 0);

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

            updateUserBtn.addEventListener('click', function() {
                const selectedCheckbox = document.querySelector('.row-checkbox:checked');
                if (selectedCheckbox) {
                    const row = selectedCheckbox.closest('tr');
                    const uid = row.querySelector('.user_uid_cell').textContent;
                    const role = currentTabRoleName; // Get role from current tab
                    
                    // Populate modal fields
                    updateUidEdit.value = uid;
                    updateRoleEdit.value = role;
                    nameEdit.value = row.querySelector('.user_name_cell').textContent;
                    emailEdit.value = row.querySelector('.user_email_cell').textContent;
                    phoneEdit.value = row.querySelector('.user_phone_cell').textContent;
                    is_active_user_edit.checked = row.querySelector('.user_is_active_cell').textContent === 'Yes';
                    // Check if the toggle switch itself is checked for the role status
                    is_active_role_edit.checked = row.querySelector('.role_is_active_cell input.edu-toggle').checked;
                    roleEditLabel.textContent = ucfirst(role);

                    if (role === 'student') {
                        studentEditFields.style.display = 'block';
                        // Use dataset for custom data attributes, ensure null/empty check
                        // Convert empty string back to null if it implies no selection
                        // Use jQuery's val() for selectpicker to handle correct value setting
                        $(classIdStudentEdit).val(row.querySelector('.user_class_cell')?.dataset.classId || '').selectpicker('refresh');
                        $(religionIdStudentEdit).val(row.querySelector('.user_religion_cell')?.dataset.religionId || '').selectpicker('refresh');
                        $(casteIdStudentEdit).val(row.querySelector('.user_caste_cell')?.dataset.casteId || '').selectpicker('refresh');
                    } else {
                        studentEditFields.style.display = 'none';
                        // Clear values and refresh selectpickers for student-specific fields when hiding
                        $(classIdStudentEdit).val('').selectpicker('refresh');
                        $(religionIdStudentEdit).val('').selectpicker('refresh');
                        $(casteIdStudentEdit).val('').selectpicker('refresh');
                    }

                    updateUserModal.show();
                } else {
                    showToast('info', 'Please select one user to update.');
                }
            });

            deactivateRoleBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_deactivate_users';
                    confirmationModalHeader.classList.remove('bg-success', 'text-white', 'bg-warning');
                    confirmationModalHeader.classList.add('bg-danger', 'text-white');
                    confirmationModalLabel.textContent = `Confirm Deactivation`;
                    confirmationModalBody.innerHTML = `Are you sure you want to deactivate <strong>${checkedCount}</strong> selected ${ucfirst(currentTabRoleName)}${checkedCount > 1 ? 's' : ''} role(s)?`;
                    confirmActionButton.classList.remove('btn-success', 'btn-warning');
                    confirmActionButton.classList.add('btn-danger');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one user role to deactivate.');
                }
            });

            activateRoleBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_activate_users';
                    confirmationModalHeader.classList.remove('bg-danger', 'text-white', 'bg-warning');
                    confirmationModalHeader.classList.add('bg-success', 'text-white');
                    confirmationModalLabel.textContent = `Confirm Activation`;
                    confirmationModalBody.innerHTML = `Are you sure you want to activate <strong>${checkedCount}</strong> selected ${ucfirst(currentTabRoleName)}${checkedCount > 1 ? 's' : ''} role(s)?`;
                    confirmActionButton.classList.remove('btn-danger', 'btn-warning');
                    confirmActionButton.classList.add('btn-success');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one user role to activate.');
                }
            });

            hardDeleteRoleBtn.addEventListener('click', function() {
                const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
                if (checkedCount > 0) {
                    currentAction = 'bulk_hard_delete_users';
                    confirmationModalHeader.classList.remove('bg-success', 'text-white', 'bg-warning');
                    confirmationModalHeader.classList.add('bg-danger', 'text-white');
                    confirmationModalLabel.textContent = `Confirm PERMANENT Deletion`;
                    confirmationModalBody.innerHTML = `
                        <p><strong>WARNING: This action cannot be undone.</strong></p>
                        <p>Are you absolutely sure you want to PERMANENTLY remove <strong>${checkedCount}</strong> selected ${ucfirst(currentTabRoleName)}${checkedCount > 1 ? 's' : ''} from this role? This will NOT delete their user account, only their specific role for this college.</p>
                    `;
                    confirmActionButton.classList.remove('btn-success', 'btn-warning');
                    confirmActionButton.classList.add('btn-danger');
                    confirmationModal.show();
                } else {
                    showToast('info', 'Please select at least one user role for permanent deletion.');
                }
            });

            confirmActionButton.addEventListener('click', function() {
                confirmationModal.hide();
                setTimeout(() => {
                    if (currentAction) {
                        formAction.value = currentAction;
                        userForm.submit();
                    }
                }, 100);
            });

            // --- Single Toggle Active State (AJAX) ---
            document.querySelectorAll('.edu-toggle').forEach(toggle => {
                toggle.addEventListener('change', function() {
                    const uid = this.dataset.uid;
                    const role = this.dataset.role;
                    const isActive = this.checked ? 'y' : 'n';
                    const originalState = !this.checked ? 'y' : 'n'; // Store original state for rollback

                    fetch('<?= BASE_URL ?>admin/users.php', { // Use BASE_URL + relative path for AJAX calls
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: `action=toggle_role_active&uid=${uid}&role=${role}&is_active=${isActive}&tab=${currentTabFullName}`
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
                    document.getElementById('searchForm').submit(); // Resubmit form to clear filter
                });
            }

            // Attach change listener to all selectpickers that trigger filtering
            // Note: The `changed.bs.select` event is triggered by bootstrap-select library when the selection changes.
            $('#filterUserActive').on('changed.bs.select', function () { $('#searchForm').submit(); });
            $('#filterRoleActive').on('changed.bs.select', function () { $('#searchForm').submit(); });
            $('#filterClass').on('changed.bs.select', function () { $('#searchForm').submit(); });
            $('#filterReligion').on('changed.bs.select', function () { $('#searchForm').submit(); });
            $('#filterCaste').on('changed.bs.select', function () { $('#searchForm').submit(); });

            // Helper function to capitalize first letter
            function ucfirst(string) {
                if (!string) return '';
                return string.charAt(0).toUpperCase() + string.slice(1);
            }

            // Check if populating a modal from failed form submission (no reliance on action in URL)
            <?php if (!empty($form_data_add_user)): ?>
                addUserModal.show();
            <?php endif; ?>

            <?php if (!empty($form_data_assign_user)): ?>
                assignUserModal.show();
            <?php endif; ?>
            
            <?php if (!empty($user_data) && $action === 'edit_user'): // Populate edit modal on page load if action is edit_user (from GET) ?>
                updateUidEdit.value = '<?= htmlspecialchars($user_data['Uid']); ?>';
                updateRoleEdit.value = '<?= htmlspecialchars($user_data['role']); ?>';
                nameEdit.value = '<?= htmlspecialchars($user_data['Name']); ?>';
                emailEdit.value = '<?= htmlspecialchars($user_data['Email']); ?>';
                phoneEdit.value = '<?= htmlspecialchars($user_data['Phone']); ?>';
                is_active_user_edit.checked = ('<?= $user_data['UserIsActive']; ?>' === 'y');
                is_active_role_edit.checked = ('<?= $user_data['RoleIsActive']; ?>' === 'y');
                roleEditLabel.textContent = ucfirst('<?= htmlspecialchars($user_data['role']); ?>');

                if ('<?= htmlspecialchars($user_data['role']); ?>' === 'student') {
                    studentEditFields.style.display = 'block';
                    // Need to refresh selectpicker for values to show up on page load
                    $(classIdStudentEdit).val('<?= htmlspecialchars($user_data['Class_id'] ?? ''); ?>').selectpicker('refresh');
                    $(religionIdStudentEdit).val('<?= htmlspecialchars($user_data['Religion_id'] ?? ''); ?>').selectpicker('refresh');
                    $(casteIdStudentEdit).val('<?= htmlspecialchars($user_data['Caste_id'] ?? ''); ?>').selectpicker('refresh');
                } else {
                    studentEditFields.style.display = 'none';
                    // Clear values and refresh selectpickers for student-specific fields when hiding
                    $(classIdStudentEdit).val('').selectpicker('refresh');
                    $(religionIdStudentEdit).val('').selectpicker('refresh');
                    $(casteIdStudentEdit).val('').selectpicker('refresh');
                }
                updateUserModal.show();
            <?php endif; ?>

        });
    </script>
</body>
</html>