<?php
// classes.php - Page for managing Classes, Religions, and Castes with CRUD operations.

// --- DEBUGGING: Enable full error reporting (RECOMMENDED DURING DEVELOPMENT, REMOVE IN PRODUCTION) ---
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// --- END DEBUGGING ---

// Start session if not already started - REMOVE THIS BLOCK, db.php handles it.
// if (session_status() == PHP_SESSION_NONE) {
//   session_start();
// }

// Ensure admin logged in and includes db.php, functions.php
require_once '../includes/admin_auth.php'; // admin_auth.php already includes db.php and functions.php
// require_once '../includes/db.php'; // REMOVE: Redundant, included by admin_auth.php
// require_once '../includes/functions.php'; // REMOVE: Redundant, included by admin_auth.php

// NEW: Include links.php to get all defined URL variables
require_once '../links.php'; // Assuming links.php is in the parent directory (root of the project)

// --- Helper Functions (Typically in ../includes/functions.php) ---
// If 'prepare_and_execute', 'set_flash_message', 'redirect', etc. are already
// defined in your functions.php, you can comment out or remove this block.
// Otherwise, it's recommended to move these to a central 'functions.php'.

// Keeping this block as is, assuming these functions are specific to this file or not yet fully moved to functions.php
// (However, set_flash_message, redirect, getCurrentUserClgId, getCurrentUserId are typically in functions.php)

if (!function_exists('prepare_and_execute')) {
  function prepare_and_execute(mysqli $conn, string $sql, array $params, string $types): mysqli_stmt
  {
    if (!$stmt = $conn->prepare($sql)) {
      // Log the error and throw an exception to prevent display of sensitive DB info in production
      error_log("SQL prepare failed: (" . $conn->errno . ") " . $conn->error . " Query: " . $sql);
      throw new Exception("Database query preparation failed. Please check logs for details.");
    }

    if (!empty($params) && !empty($types)) {
      $bindParams = [$types];
      foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
      }

      // The error likely occurs here if there's type/count mismatch
      if (!call_user_func_array([$stmt, 'bind_param'], $bindParams)) {
        $stmt->close();
        error_log("Binding parameters failed: (" . $stmt->errno . ") " . $stmt->error . " Query: " . $sql . " Params: " . print_r($params, true) . " Types: " . $types);
        throw new Exception("An internal error occurred during parameter binding. Please check logs for details.");
      }
    }

    if (!$stmt->execute()) {
      $stmt->close();
      error_log("Statement execution failed: (" . $stmt->errno . ") " . $stmt->error . " Query: " . $sql . " Params: " . print_r($params, true));
      throw new Exception("Database query execution failed. Please check logs for details.");
    }

    return $stmt;
  }
}

if (!function_exists('set_flash_message')) {
  function set_flash_message(string $message, string $type = 'info') {
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_message_type'] = $type;
  }
}

if (!function_exists('redirect')) {
  function redirect(string $url) {
    header("Location: $url");
    exit();
  }
}

if (!function_exists('getCurrentUserClgId')) {
  function getCurrentUserClgId(): ?int {
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
    return isset($_SESSION['clg_id']) ? (int)$_SESSION['clg_id'] : null;
  }
}

if (!function_exists('getCurrentUserId')) {
  function getCurrentUserId(): ?int {
    if (session_status() == PHP_SESSION_NONE) { session_start(); }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; // Assuming 'user_id' is stored
  }
}

// Define BASE_URL if it's not defined elsewhere (e.g., in db.php or config.php)
// REMOVE THIS BLOCK, as BASE_URL is already defined in db.php
// if (!defined('BASE_URL')) {
//   define('BASE_URL', '/your-project-root/'); // !!! IMPORTANT: CHANGE THIS TO YOUR ACTUAL PROJECT ROOT PATH !!!
// }
// --- END Helper Functions Block ---


// ====================================================================================
// --- Generic Helpers for all CRUD operations ---
// ====================================================================================

/**
 * Fetches data for a given entity, with optional search and pagination.
 */
function getGenericData($conn, $tableName, $columns, $clg_id, $search_query, $searchColumn, $additionalConditions = [], $limit = null, $offset = null, $orderBy = null) {
  if (empty($columns)) {
    return [];
  }

  $cols = implode(', ', $columns);
  $sql = "SELECT $cols FROM `$tableName` WHERE Clg_id = ?";
  $params = [$clg_id];
  $types = "i";

  if (!empty($search_query) && !empty($searchColumn)) {
    $sql .= " AND `$searchColumn` LIKE ?";
    $params[] = '%' . $search_query . '%';
    $types .= "s";
  }

  foreach ($additionalConditions as $column => $value) {
    if ($value !== null) {
      $sql .= " AND `$column` = ?";
      $params[] = $value;
      $types .= "s"; // Assuming string type for additional conditions
    }
  }

  if (!empty($orderBy)) {
    $sql .= " ORDER BY $orderBy";
  }

  if ($limit !== null && $offset !== null) {
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= "ii";
  }

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $data;
  } catch (Exception $e) {
    error_log("Error fetching generic data from $tableName: " . $e->getMessage());
    return [];
  }
}

/**
 * Counts total records for a given entity.
 */
function countGenericData($conn, $tableName, $clg_id, $search_query, $searchColumn, $additionalConditions = []) {
  $sql = "SELECT COUNT(*) FROM `$tableName` WHERE Clg_id = ?";
  $params = [$clg_id];
  $types = "i";

  if (!empty($search_query) && !empty($searchColumn)) {
    $sql .= " AND `$searchColumn` LIKE ?";
    $params[] = '%' . $search_query . '%';
    $types .= "s";
  }

  foreach ($additionalConditions as $column => $value) {
    if ($value !== null) {
      $sql .= " AND `$column` = ?";
      $params[] = $value;
      $types .= "s"; // Assuming string type for additional conditions
    }
  }

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    $result = $stmt->get_result();
    $count = $result->fetch_row()[0];
    $stmt->close();
    return $count;
  } catch (Exception $e) {
    error_log("Error counting generic data from $tableName: " . $e->getMessage());
    return 0;
  }
}

/**
 * Updates the Is_active status for one or more records.
 */
function updateGenericStatus($conn, $tableName, $idColumn, array $ids, $is_active_status, $clg_id) {
  if (empty($ids)) {
    return 0;
  }
  if (!in_array($is_active_status, ['y', 'n'])) {
    error_log("Invalid Is_active status provided: " . $is_active_status);
    return false;
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $sql = "UPDATE `$tableName` SET Is_active = ? WHERE Clg_id = ? AND `$idColumn` IN ($placeholders)";
  $types = "si" . str_repeat('i', count($ids));
  $params = array_merge([$is_active_status, $clg_id], $ids);

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
  } catch (Exception $e) {
    error_log("Error updating $tableName status: " . $e->getMessage());
    return false;
  }
}

/**
 * Hard deletes records by ID. USE WITH EXTREME CAUTION.
 */
function hardDeleteGeneric($conn, $tableName, $idColumn, array $ids, $clg_id) {
  if (empty($ids)) {
    return 0;
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $sql = "DELETE FROM `$tableName` WHERE Clg_id = ? AND `$idColumn` IN ($placeholders)";
  $types = "i" . str_repeat('i', count($ids));
  $params = array_merge([$clg_id], $ids);

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
  } catch (Exception $e) {
    error_log("Error hard deleting from $tableName: " . $e->getMessage());
    return false;
  }
}

// ====================================================================================
// --- Specific Helper Functions for each Entity ---
// ====================================================================================

// --- Classes CRUD ---
function getClasses($conn, $clg_id, $search_query, $limit, $offset) {
  return getGenericData($conn, 'classes', ['Class_id', 'Class_name', 'Semester', 'Session', 'Is_active'], $clg_id, $search_query, 'Class_name', [], $limit, $offset, 'Class_name');
}

function countClasses($conn, $clg_id, $search_query) {
  return countGenericData($conn, 'classes', $clg_id, $search_query, 'Class_name');
}

function addClass($conn, $clg_id, $class_name, $semester, $session, $created_by) {
  $sql = "INSERT INTO classes (Clg_id, Class_name, Semester, Session, Created_by, Created_at) VALUES (?, ?, ?, ?, ?, NOW())";
  return prepare_and_execute($conn, $sql, [$clg_id, $class_name, $semester, $session, $created_by], "isssi");
}

function updateClass($conn, $clg_id, $class_id, $class_name, $semester, $session) {
  $sql = "UPDATE classes SET Class_name = ?, Semester = ?, Session = ? WHERE Class_id = ? AND Clg_id = ?";
  return prepare_and_execute($conn, $sql, [$class_name, $semester, $session, $class_id, $clg_id], "sssii");
}

// --- Religion CRUD ---
function getReligions($conn, $clg_id, $search_query, $limit, $offset) {
  return getGenericData($conn, 'religion', ['Religion_id', 'Religion_name', 'Is_active'], $clg_id, $search_query, 'Religion_name', [], $limit, $offset, 'Religion_name');
}

function countReligions($conn, $clg_id, $search_query) {
  return countGenericData($conn, 'religion', $clg_id, $search_query, 'Religion_name');
}

function addReligion($conn, $clg_id, $religion_name, $created_by) {
  $sql = "INSERT INTO religion (Clg_id, Religion_name, Created_by, Created_at) VALUES (?, ?, ?, NOW())";
  return prepare_and_execute($conn, $sql, [$clg_id, $religion_name, $created_by], "isi");
}

function updateReligion($conn, $clg_id, $religion_id, $religion_name) {
  $sql = "UPDATE religion SET Religion_name = ? WHERE Religion_id = ? AND Clg_id = ?";
  return prepare_and_execute($conn, $sql, [$religion_name, $religion_id, $clg_id], "sii");
}

// --- Caste CRUD ---
function getCastes($conn, $clg_id, $search_query, $limit, $offset) {
  return getGenericData($conn, 'caste', ['Caste_id', 'Caste_name', 'Is_active'], $clg_id, $search_query, 'Caste_name', [], $limit, $offset, 'Caste_name');
}

function countCastes($conn, $clg_id, $search_query) {
  return countGenericData($conn, 'caste', $clg_id, $search_query, 'Caste_name');
}

function addCaste($conn, $clg_id, $caste_name, $created_by) {
  $sql = "INSERT INTO caste (Clg_id, Caste_name, Created_by, Created_at) VALUES (?, ?, ?, NOW())";
  return prepare_and_execute($conn, $sql, [$clg_id, $caste_name, $created_by], "isi");
}

function updateCaste($conn, $clg_id, $caste_id, $caste_name) {
  $sql = "UPDATE caste SET Caste_name = ? WHERE Caste_id = ? AND Clg_id = ?";
  return prepare_and_execute($conn, $sql, [$caste_name, $caste_id, $clg_id], "sii");
}

// --- End Helper Functions ---

// Define page title
$pageTitle = "Manage Academic Data - EduFlow";

// Get current user and college ID
$clg_id = getCurrentUserClgId();
$current_user_id = getCurrentUserId();
$current_user_name = htmlspecialchars($_SESSION['name'] ?? 'Admin');

// --- Configuration for Pagination ---
$records_per_page = 10; // Slightly fewer records per page for a single tab
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- Filter Parameters ---
// IMPORTANT: Get active_tab BEFORE POST processing, so it always starts with the URL's tab.
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'classes'; // Default tab
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';


// Define the base URL for this specific page
// No change needed here, this refers to the current executing script
$current_page_base_url = $_SERVER['PHP_SELF'];

// --- Handle Form Submissions (CRUD Actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  // If the POST form submits a 'tab' value, use that for redirection.
  // Otherwise, $active_tab retains its value from the GET request / default.
  $active_tab = $_POST['tab'] ?? $active_tab;


  // Capture current filter/pagination/tab state to redirect back to it.
  $refresh_params = array_filter([
    'tab' => $active_tab, // Use the potentially updated active_tab
    'search' => !empty($search_query) ? $search_query : null,
    'page' => $current_page > 1 ? $current_page : null,
  ]);
  $redirect_url = $current_page_base_url;
  if (!empty($refresh_params)) {
    $redirect_url .= '?' . http_build_query($refresh_params);
  }

  try {
    switch ($action) {
      // --- Classes CRUD ---
      case 'add_class':
        $class_name = trim($_POST['class_name'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $session = trim($_POST['session'] ?? '');
        if (empty($class_name)) {
          throw new Exception('Class Name is required.');
        }
        $stmt = addClass($conn, $clg_id, $class_name, $semester, $session, $current_user_id);
        if ($stmt->affected_rows > 0) {
          set_flash_message("Class '{$class_name}' added successfully!", "success");
        } else {
          throw new Exception("Error adding class: No rows affected. It might already exist or a database error occurred.");
        }
        $stmt->close();
        redirect($redirect_url);
        break;

      case 'update_class':
        $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
        $class_name = trim($_POST['class_name'] ?? '');
        $semester = trim($_POST['semester'] ?? '');
        $session = trim($_POST['session'] ?? '');
        if (!$class_id || empty($class_name)) {
          throw new Exception('Invalid data for updating class.');
        }
        $stmt = updateClass($conn, $clg_id, $class_id, $class_name, $semester, $session);
        if ($stmt->affected_rows > 0) {
          set_flash_message("Class '{$class_name}' updated successfully!", "success");
        } else {
          set_flash_message("No changes made to class '{$class_name}'.", "info");
        }
        $stmt->close();
        redirect($redirect_url);
        break;

      case 'bulk_deactivate_class':
      case 'bulk_activate_class':
        $selected_ids = $_POST['selected_ids'] ?? [];
        $ids_to_process = array_filter(array_map('intval', $selected_ids));
        $new_status = ($action === 'bulk_activate_class') ? 'y' : 'n';
        if (empty($ids_to_process)) {
          set_flash_message("No classes selected.", "info");
        } else {
          $affected = updateGenericStatus($conn, 'classes', 'Class_id', $ids_to_process, $new_status, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} class(es) status updated successfully!", "success");
          } else {
            throw new Exception("Failed to update selected classes.");
          }
        }
        redirect($redirect_url);
        break;

      case 'hard_delete_class':
        $selected_ids = $_POST['selected_ids'] ?? [];
        $ids_to_process = array_filter(array_map('intval', $selected_ids));
        if (empty($ids_to_process)) {
          set_flash_message("No classes selected for deletion.", "info");
        } else {
          $affected = hardDeleteGeneric($conn, 'classes', 'Class_id', $ids_to_process, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} class(es) permanently deleted!", "success");
          } else {
            throw new Exception("Failed to permanently delete selected classes. (Note: May have foreign key dependencies)");
          }
        }
        redirect($redirect_url);
        break;

      // --- Religion CRUD ---
      case 'add_religion':
        $religion_name = trim($_POST['religion_name'] ?? '');
        if (empty($religion_name)) {
          throw new Exception('Religion Name is required.');
        }
        $stmt = addReligion($conn, $clg_id, $religion_name, $current_user_id);
        if ($stmt->affected_rows > 0) {
          set_flash_message("Religion '{$religion_name}' added successfully!", "success");
        } else {
          throw new Exception("Error adding religion: No rows affected. It might already exist or a database error occurred.");
        }
        $stmt->close();
        redirect($redirect_url);
        break;

      case 'update_religion':
        $religion_id = filter_input(INPUT_POST, 'religion_id', FILTER_VALIDATE_INT);
        $religion_name = trim($_POST['religion_name'] ?? '');
        if (!$religion_id || empty($religion_name)) {
          throw new Exception('Invalid data for updating religion.');
        }
        $stmt = updateReligion($conn, $clg_id, $religion_id, $religion_name);
        if ($stmt->affected_rows > 0) {
          set_flash_message("Religion '{$religion_name}' updated successfully!", "success");
        } else {
          set_flash_message("No changes made to religion '{$religion_name}'.", "info");
        }
        $stmt->close();
        redirect($redirect_url);
        break;

      case 'bulk_deactivate_religion':
      case 'bulk_activate_religion':
        $selected_ids = $_POST['selected_ids'] ?? [];
        $ids_to_process = array_filter(array_map('intval', $selected_ids));
        $new_status = ($action === 'bulk_activate_religion') ? 'y' : 'n';
        if (empty($ids_to_process)) {
          set_flash_message("No religions selected.", "info");
        } else {
          $affected = updateGenericStatus($conn, 'religion', 'Religion_id', $ids_to_process, $new_status, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} religion(s) status updated successfully!", "success");
          } else {
            throw new Exception("Failed to update selected religions.");
          }
        }
        redirect($redirect_url);
        break;

      case 'hard_delete_religion':
        $selected_ids = $_POST['selected_ids'] ?? [];
        $ids_to_process = array_filter(array_map('intval', $selected_ids));
        if (empty($ids_to_process)) {
          set_flash_message("No religions selected for deletion.", "info");
        } else {
          $affected = hardDeleteGeneric($conn, 'religion', 'Religion_id', $ids_to_process, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} religion(s) permanently deleted!", "success");
          } else {
            throw new Exception("Failed to permanently delete selected religions. (Note: May have foreign key dependencies)");
          }
        }
        redirect($redirect_url);
        break;

      // --- Caste CRUD ---
      case 'add_caste':
        $caste_name = trim($_POST['caste_name'] ?? '');
        if (empty($caste_name)) {
          throw new Exception('Caste Name is required.');
        }
        $stmt = addCaste($conn, $clg_id, $caste_name, $current_user_id);
        if ($stmt->affected_rows > 0) {
          set_flash_message("Caste '{$caste_name}' added successfully!", "success");
        } else {
          throw new Exception("Error adding caste: No rows affected. It might already exist or a database error occurred.");
        }
        $stmt->close();
        redirect($redirect_url);
        break;

      case 'update_caste':
        $caste_id = filter_input(INPUT_POST, 'caste_id', FILTER_VALIDATE_INT);
        $caste_name = trim($_POST['caste_name'] ?? '');
        if (!$caste_id || empty($caste_name)) {
          throw new Exception('Invalid data for updating caste.');
        }
        $stmt = updateCaste($conn, $clg_id, $caste_id, $caste_name);
        if ($stmt->affected_rows > 0) {
          set_flash_message("Caste '{$caste_name}' updated successfully!", "success");
        } else {
          set_flash_message("No changes made to caste '{$caste_name}'.", "info");
        }
        $stmt->close();
        redirect($redirect_url);
        break;

      case 'bulk_deactivate_caste':
      case 'bulk_activate_caste':
        $selected_ids = $_POST['selected_ids'] ?? [];
        $ids_to_process = array_filter(array_map('intval', $selected_ids));
        $new_status = ($action === 'bulk_activate_caste') ? 'y' : 'n';
        if (empty($ids_to_process)) {
          set_flash_message("No castes selected.", "info");
        } else {
          $affected = updateGenericStatus($conn, 'caste', 'Caste_id', $ids_to_process, $new_status, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} caste(s) status updated successfully!", "success");
          } else {
            throw new Exception("Failed to update selected castes.");
          }
        }
        redirect($redirect_url);
        break;

      case 'hard_delete_caste':
        $selected_ids = $_POST['selected_ids'] ?? [];
        $ids_to_process = array_filter(array_map('intval', $selected_ids));
        if (empty($ids_to_process)) {
          set_flash_message("No castes selected for deletion.", "info");
        } else {
          $affected = hardDeleteGeneric($conn, 'caste', 'Caste_id', $ids_to_process, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} c`aste(s) permanently deleted!", "success");
          } else {
            throw new Exception("Failed to permanently delete selected castes.");
          }
        }
        redirect($redirect_url);
        break;

      case 'toggle_active': // AJAX request for single toggle (generic for all entities)
        header('Content-Type: application/json');
        $entity_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
        $entity_type = trim($_POST['entity_type'] ?? ''); // e.g., 'class', 'religion'
        $is_active = $_POST['is_active'] ?? 'n';

        $isValidToggle = false;
        $tableName = '';
        $idColumn = '';

        switch ($entity_type) {
          case 'class':
            $tableName = 'classes'; $idColumn = 'Class_id'; $isValidToggle = true; break;
          case 'religion':
            $tableName = 'religion'; $idColumn = 'Religion_id'; $isValidToggle = true; break;
          case 'caste':
            $tableName = 'caste'; $idColumn = 'Caste_id'; $isValidToggle = true; break;
        }

        if (!$entity_id || !$isValidToggle || !in_array($is_active, ['y', 'n'])) {
          echo json_encode(['success' => false, 'message' => 'Invalid data for toggle.']);
          exit();
        }

        $affected = updateGenericStatus($conn, $tableName, $idColumn, [$entity_id], $is_active, $clg_id);
        if ($affected > 0) {
          echo json_encode(['success' => true, 'message' => ucfirst($entity_type) . ' status updated.']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Failed to update ' . $entity_type . ' status.']);
        }
        exit();

      default:
        set_flash_message("Invalid action requested.", "warning");
        redirect($redirect_url);
        break;
    }
  } catch (Exception $e) {
    set_flash_message("Operation failed: " . $e->getMessage(), "danger");
    redirect($redirect_url);
  }
}

// --- Fetch Data for Display (based on active tab) ---

// This line might have been updated by POST action if 'tab' was included
// in POST data. It's safe as it ensures the correct tab is displayed after redirect.
// Original value from $_GET becomes irrelevant after POST if $_POST['tab'] exists.
// By this point, $active_tab usually holds the correct tab from the URL or the last valid POST submission.

$classes = [];
$total_classes = 0;
$religions = [];
$total_religions = 0;
$castes = [];
$total_castes = 0;

// All available classes (for selection in mapping - though mapping is removed, this might be useful for other contexts)
$all_classes = getGenericData($conn, 'classes', ['Class_id', 'Class_name'], $clg_id, '', '', ['Is_active' => 'y'], null, null, 'Class_name');


// Fetch data for the currently active tab
$current_total_records = 0; // Initialize for pagination display
switch ($active_tab) {
  case 'classes':
    $total_classes = countClasses($conn, $clg_id, $search_query);
    $classes = getClasses($conn, $clg_id, $search_query, $records_per_page, $offset);
    $current_total_records = $total_classes;
    break;
  case 'religions':
    $total_religions = countReligions($conn, $clg_id, $search_query);
    $religions = getReligions($conn, $clg_id, $search_query, $records_per_page, $offset);
    $current_total_records = $total_religions;
    break;
  case 'castes':
    $total_castes = countCastes($conn, $clg_id, $search_query);
    $castes = getCastes($conn, $clg_id, $search_query, $records_per_page, $offset);
    $current_total_records = $total_castes;
    break;
  default:
    // Fallback for default tab if an invalid tab is specified
    $active_tab = 'classes'; // Ensure it defaults to 'classes' if something goes wrong
    $total_classes = countClasses($conn, $clg_id, $search_query);
    $classes = getClasses($conn, $clg_id, $search_query, $records_per_page, $offset);
    $current_total_records = $total_classes;
    break;
}

$total_pages = ceil($current_total_records / $records_per_page);
$start_record = $current_total_records > 0 ? ($offset + 1) : 0;
$end_record = min(($current_page * $records_per_page), $current_total_records);


// Helper function to build query parameters for pagination
function buildPaginationQuery($currentPage, $active_tab, $search_query) {
  global $current_page_base_url;
  $query_params = array_filter([
    'tab' => $active_tab,
    'page' => $currentPage,
    'search' => !empty($search_query) ? $search_query : null,
  ]);
  return $current_page_base_url . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
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

  <!-- CUSTOM CSS (same as documents.php) -->
  <style>
    /* Custom Color Variables - Shades of Blue and White */
    :root {
      --edu-white: #ffffff;
      --edu-lightest-blue: #e0f2f7;
      --edu-light-blue: #add8e6;
      --edu-medium-blue: #87ceeb;
      --edu-primary-blue: #007bff;
      --edu-dark-blue: #0056b3;
      --edu-darkest-blue: #003366;
      --edu-secondary-text-color: #6c757d;

      --edu-insert-color: #007bff;
      --edu-update-color: #ffc107;
      --edu-delete-color: #dc3545;
      --edu-hard-delete-color: #bb2d3b;
      --edu-select-all-color: #6c757d;
      --edu-activate-color: #28a745;

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

    .container-fixed-width {
      max-width: 1200px;
      margin-left: auto;
      margin-right: auto;
      padding-left: var(--bs-gutter-x, 0.75rem);
      padding-right: var(--bs-gutter-x, 0.75rem);
    }

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

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .bg-edu-blue-4 { background-color: var(--edu-primary-blue) !important; }
    .text-edu-white { color: var(--edu-white) !important; }
    .text-edu-blue-6 { color: var(--edu-darkest-blue) !important; }
    .text-edu-primary-blue { color: var(--edu-primary-blue) !important; }


    @media (max-width: 991.98px) {
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

      .container-fixed-width {
        max-width: 100% !important;
        padding-top: var(--bs-navbar-padding-y);
        padding-bottom: var(--bs-navbar-padding-y);
      }

      .edu-navbar {
        border-bottom: none !important;
      }

      .container-fixed-width {
        border-bottom: 1px solid var(--edu-light-blue);
      }

      .navbar-collapse {
        padding-top: 0.5rem !important;
      }

      .navbar-collapse .navbar-nav {
        padding-top: 0.5rem;
        padding-bottom: 0.5rem;
      }

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

      .crud-buttons {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 0.5rem;
      }
      .crud-buttons .btn {
        width: 100%;
        margin-right: 0;
        margin-bottom: 0.5rem;
      }

      .search-area {
        flex-direction: column;
        align-items: stretch;
      }
      .search-area .input-group,
      .search-area .bootstrap-select {
        max-width: 100%;
      }
      .search-area .btn-primary {
        width: 100%;
      }
    }

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

    .table-striped-edu thead th {
      background-color: var(--edu-light-blue);
      color: var(--edu-darkest-blue);
      font-weight: bold;
      border-bottom: 2px solid var(--edu-medium-blue);
    }
    .table-striped-edu tbody tr:nth-of-type(odd) {
      background-color: rgba(0, 0, 0, 0.05);
    }
    .table-striped-edu tbody tr:nth-of-type(even) {
      background-color: var(--edu-white);
    }
    /* FIX: Ensure consistent text alignment for table cells */
    .table-striped-edu td, .table-striped-edu th {
      padding: 0.75rem;
      vertical-align: middle;
      border-top: 1px solid var(--edu-light-blue);
      text-align: left; /* Added for consistent alignment */
    }

    .form-check.form-switch {
      min-height: 1.5rem;
      padding-left: 3rem;
    }

    .form-check-input.edu-toggle {
      height: 1.25rem;
      width: 2.25rem;
      margin-left: -2.75rem;
      vertical-align: middle;
      background-color: var(--edu-delete-color);
      border-color: var(--edu-delete-color);
      transition: background-color 0.3s ease-in-out, border-color 0.3s ease-in-out;
      box-shadow: none;
    }

    .form-check-input.edu-toggle:checked {
      background-color: var(--edu-activate-color);
      border-color: var(--edu-activate-color);
    }
    .form-check-input.edu-toggle:checked:hover {
      background-color: #218838;
      border-color: #218838;
    }
    .form-check-input.edu-toggle:focus {
      box-shadow: 0 0 0 .25rem rgb(0 123 255 / 25%);
    }
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

    .crud-buttons .btn {
      padding: 0.75rem 1.25rem;
      border-radius: 0.5rem;
      font-weight: 500;
      transition: all 0.2s ease-in-out;
      margin-right: 0.75rem;
      margin-bottom: 0.75rem;
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
      color: var(--edu-darkest-blue);
      border-color: var(--edu-update-color);
    }
    .btn-update:hover {
      background-color: #e0a800;
      border-color: #e0a800;
      color: var(--edu-darkest-blue);
    }
    .btn-update:disabled {
      background-color: #ffe8a1;
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
      background-color: #218838;
      border-color: #218838;
    }
    .btn-activate:disabled {
      background-color: #92d19f;
      border-color: #92d19f;
      cursor: not-allowed;
      opacity: 0.65;
    }

    .btn-delete {
      background-color: var(--edu-delete-color);
      color: var(--edu-white);
      border-color: var(--edu-delete-color);
    }
    .btn-delete:hover {
      background-color: #c82333;
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
      background-color: #a71d2a;
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
      background-color: #5a6268;
      border-color: #5a6268;
    }
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
        <img src="logo.png" alt="EduFlow Logo" class="navbar-logo">
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
            <a class="nav-link edu-nav-link" href="<?= $admin_folders ?>">Manage Folders</a>
          </li>
          <li class="nav-item">
            <a class="nav-link edu-nav-link active" href="<?= $admin_classes ?>">Manage Academic</a>
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
        <!-- Page Header with blue bar as in image -->
        <div class="page-header">
          <h1>Manage Academic Data</h1>
        </div>

        <!-- Toast Container -->
        <div class="toast-container position-fixed top-0 end-0 p-3">
          <?php
          if (isset($_SESSION['flash_message'])) {
            $f_type = $_SESSION['flash_message_type'] ?? 'info';
            $f_text = addslashes($_SESSION['flash_message']);
            echo "<script>document.addEventListener('DOMContentLoaded', function() { showToast('$f_type', '$f_text'); });</script>\n";
            unset($_SESSION['flash_message']);
            unset($_SESSION['flash_message_type']);
          }
          ?>
        </div>

        <!-- Nav tabs for different sections -->
        <ul class="nav nav-tabs mb-4" id="mainTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= ($active_tab == 'classes') ? 'active' : '' ?>" id="classes-tab" data-bs-toggle="tab" data-bs-target="#classes" type="button" role="tab" aria-controls="classes" aria-selected="<?= ($active_tab == 'classes') ? 'true' : 'false' ?>">Classes</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= ($active_tab == 'religions') ? 'active' : '' ?>" id="religions-tab" data-bs-toggle="tab" data-bs-target="#religions" type="button" role="tab" aria-controls="religions" aria-selected="<?= ($active_tab == 'religions') ? 'true' : 'false' ?>">Religions</button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= ($active_tab == 'castes') ? 'active' : '' ?>" id="castes-tab" data-bs-toggle="tab" data-bs-target="#castes" type="button" role="tab" aria-controls="castes" aria-selected="<?= ($active_tab == 'castes') ? 'true' : 'false' ?>">Castes</button>
          </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="mainTabContent">
          <!-- Classes Tab Content -->
          <div class="tab-pane fade <?= ($active_tab == 'classes') ? 'show active' : '' ?>" id="classes" role="tabpanel" aria-labelledby="classes-tab">
            <h2>Manage Classes</h2>
            <div class="crud-buttons mb-4">
              <button type="button" class="btn btn-insert" data-bs-toggle="modal" data-bs-target="#addClassModal">Add New Class</button>
              <button type="button" class="btn btn-update" id="updateClassBtn" disabled>Edit Class</button>
              <button type="button" class="btn btn-activate" id="activateClassBtn" disabled>Activate Selected</button>
              <button type="button" class="btn btn-delete" id="deactivateClassBtn" disabled>Deactivate Selected</button>
              <button type="button" class="btn btn-hard-delete" id="hardDeleteClassBtn" disabled>Hard Delete Selected</button>
              <button type="button" class="btn btn-select-all" id="selectAllClassesBtn">Select All</button>
            </div>
            <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchClassesForm" action="<?= htmlspecialchars($current_page_base_url) ?>">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
              <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="Search by class name..." value="<?= htmlspecialchars($search_query) ?>">
                <?php if (!empty($search_query) && $active_tab == 'classes'): ?>
                  <button type="button" class="btn btn-outline-secondary" id="clearClassSearchInput" title="Clear Search">
                    <i class="bi bi-x-lg"></i>
                  </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Apply Search</button>
              </div>
            </form>
            <form id="classForm" method="POST" action="<?= htmlspecialchars($current_page_base_url) ?>">
              <input type="hidden" name="action" id="classFormAction">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
              <div class="table-responsive">
                <table class="table table-striped-edu text-nowrap" id="classesTable">
                  <thead>
                    <tr>
                      <th scope="col" style="width: 50px;"><input type="checkbox" class="master-checkbox" data-table-id="classesTable"></th>
                      <th scope="col">Class ID</th>
                      <th scope="col">Class Name</th>
                      <th scope="col">Semester</th>
                      <th scope="col">Session</th>
                      <th scope="col">Active</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($classes): ?>
                      <?php foreach ($classes as $class): ?>
                        <tr>
                          <td><input type="checkbox" name="selected_ids[]" value="<?= htmlspecialchars($class['Class_id']) ?>" class="row-checkbox" data-table-id="classesTable"></td>
                          <td class="class_id_cell"><?= htmlspecialchars ($class['Class_id']) ?></td>
                          <td class="class_name_cell"><?= htmlspecialchars($class['Class_name']) ?></td>
                          <td class="semester_cell"><?= htmlspecialchars($class['Semester']) ?></td>
                          <td class="session_cell"><?= htmlspecialchars($class['Session']) ?></td>
                          <td>
                            <div class="form-check form-switch">
                              <input class="form-check-input edu-toggle" type="checkbox" role="switch" id="toggleClassActive_<?= $class['Class_id'] ?>"
                                data-id="<?= $class['Class_id'] ?>" data-entity-type="class" <?= $class['Is_active'] == 'y' ? 'checked' : '' ?>>
                              <label class="form-check-label visually-hidden" for="toggleClassActive_<?= $class['Class_id'] ?>">Toggle Active</label>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr><td colspan="6" class="text-center py-4">No classes found.</td></tr>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </form>
            <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
              <?php if ($current_total_records > 0): ?>
                <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $current_total_records ?> total records.</p>
              <?php else: ?>
                <p class="text-muted mb-0">No records found matching your criteria.</p>
              <?php endif; ?>
            </div>

            <nav aria-label="Page navigation" class="mt-4">
              <ul class="pagination justify-content-center">
                <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page - 1, $active_tab, $search_query)) ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                  <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($i, $active_tab, $search_query)) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page + 1, $active_tab, $search_query)) ?>">Next</a>
                </li>
              </ul>
            </nav>
          </div>

          <!-- Religions Tab Content -->
          <div class="tab-pane fade <?= ($active_tab == 'religions') ? 'show active' : '' ?>" id="religions" role="tabpanel" aria-labelledby="religions-tab">
            <h2>Manage Religions</h2>
            <div class="crud-buttons mb-4">
              <button type="button" class="btn btn-insert" data-bs-toggle="modal" data-bs-target="#addReligionModal">Add New Religion</button>
              <button type="button" class="btn btn-update" id="updateReligionBtn" disabled>Edit Religion</button>
              <button type="button" class="btn btn-activate" id="activateReligionBtn" disabled>Activate Selected</button>
              <button type="button" class="btn btn-delete" id="deactivateReligionBtn" disabled>Deactivate Selected</button>
              <button type="button" class="btn btn-hard-delete" id="hardDeleteReligionBtn" disabled>Hard Delete Selected</button>
              <button type="button" class="btn btn-select-all" id="selectAllReligionsBtn">Select All</button>
            </div>
            <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchReligionsForm" action="<?= htmlspecialchars($current_page_base_url) ?>">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
              <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="Search by religion name..." value="<?= htmlspecialchars($search_query) ?>">
                <?php if (!empty($search_query) && $active_tab == 'religions'): ?>
                  <button type="button" class="btn btn-outline-secondary" id="clearReligionSearchInput" title="Clear Search">
                    <i class="bi bi-x-lg"></i>
                  </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Apply Search</button>
              </div>
            </form>
            <form id="religionForm" method="POST" action="<?= htmlspecialchars($current_page_base_url) ?>">
              <input type="hidden" name="action" id="religionFormAction">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
              <div class="table-responsive">
                <table class="table table-striped-edu text-nowrap" id="religionsTable">
                  <thead>
                    <tr>
                      <th scope="col" style="width: 50px;"><input type="checkbox" class="master-checkbox" data-table-id="religionsTable"></th>
                      <th scope="col">Religion ID</th>
                      <th scope="col">Name</th>
                      <th scope="col">Active</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($religions): ?>
                      <?php foreach ($religions as $religion): ?>
                        <tr>
                          <td><input type="checkbox" name="selected_ids[]" value="<?= htmlspecialchars($religion['Religion_id']) ?>" class="row-checkbox" data-table-id="religionsTable"></td>
                          <td class="religion_id_cell"><?= htmlspecialchars($religion['Religion_id']) ?></td>
                          <td class="religion_name_cell"><?= htmlspecialchars($religion['Religion_name']) ?></td>
                          <td>
                            <div class="form-check form-switch">
                              <input class="form-check-input edu-toggle" type="checkbox" role="switch" id="toggleReligionActive_<?= $religion['Religion_id'] ?>"
                                data-id="<?= $religion['Religion_id'] ?>" data-entity-type="religion" <?= $religion['Is_active'] == 'y' ? 'checked' : '' ?>>
                              <label class="form-check-label visually-hidden" for="toggleReligionActive_<?= $religion['Religion_id'] ?>">Toggle Active</label>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr><td colspan="4" class="text-center py-4">No religions found.</td></tr>
                    <?php endif; ?>
                </table>
              </div>
            </form>
            <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
              <?php if ($current_total_records > 0): ?>
                <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $current_total_records ?> total records.</p>
              <?php else: ?>
                <p class="text-muted mb-0">No records found matching your criteria.</p>
              <?php endif; ?>
            </div>

            <nav aria-label="Page navigation" class="mt-4">
              <ul class="pagination justify-content-center">
                <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page - 1, $active_tab, $search_query)) ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                  <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($i, $active_tab, $search_query)) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page + 1, $active_tab, $search_query)) ?>">Next</a>
                </li>
              </ul>
            </nav>
          </div>

          <!-- Castes Tab Content -->
          <div class="tab-pane fade <?= ($active_tab == 'castes') ? 'show active' : '' ?>" id="castes" role="tabpanel" aria-labelledby="castes-tab">
            <h2>Manage Castes</h2>
            <div class="crud-buttons mb-4">
              <button type="button" class="btn btn-insert" data-bs-toggle="modal" data-bs-target="#addCasteModal">Add New Caste</button>
              <button type="button" class="btn btn-update" id="updateCasteBtn" disabled>Edit Caste</button>
              <button type="button" class="btn btn-activate" id="activateCasteBtn" disabled>Activate Selected</button>
              <button type="button" class="btn btn-delete" id="deactivateCasteBtn" disabled>Deactivate Selected</button>
              <button type="button" class="btn btn-hard-delete" id="hardDeleteCasteBtn" disabled>Hard Delete Selected</button>
              <button type="button" class="btn btn-select-all" id="selectAllCastesBtn">Select All</button>
            </div>
            <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchCastesForm" action="<?= htmlspecialchars($current_page_base_url) ?>">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
              <div class="input-group">
                <input type="text" class="form-control" name="search" placeholder="Search by caste name..." value="<?= htmlspecialchars($search_query) ?>">
                <?php if (!empty($search_query) && $active_tab == 'castes'): ?>
                  <button type="button" class="btn btn-outline-secondary" id="clearCasteSearchInput" title="Clear Search">
                    <i class="bi bi-x-lg"></i>
                  </button>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">Apply Search</button>
              </div>
            </form>
            <form id="casteForm" method="POST" action="<?= htmlspecialchars($current_page_base_url) ?>">
              <input type="hidden" name="action" id="casteFormAction">
              <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
              <div class="table-responsive">
                <table class="table table-striped-edu text-nowrap" id="castesTable">
                  <thead>
                    <tr>
                      <th scope="col" style="width: 50px;"><input type="checkbox" class="master-checkbox" data-table-id="castesTable"></th>
                      <th scope="col">Caste ID</th>
                      <th scope="col">Name</th>
                      <th scope="col">Active</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if ($castes): ?>
                      <?php foreach ($castes as $caste): ?>
                        <tr>
                          <td><input type="checkbox" name="selected_ids[]" value="<?= htmlspecialchars($caste['Caste_id']) ?>" class="row-checkbox" data-table-id="castesTable"></td>
                          <td class="caste_id_cell"><?= htmlspecialchars($caste['Caste_id']) ?></td>
                          <td class="caste_name_cell"><?= htmlspecialchars($caste['Caste_name']) ?></td>
                          <td>
                            <div class="form-check form-switch">
                              <input class="form-check-input edu-toggle" type="checkbox" role="switch" id="toggleCasteActive_<?= $caste['Caste_id'] ?>"
                                data-id="<?= $caste['Caste_id'] ?>" data-entity-type="caste" <?= $caste['Is_active'] == 'y' ? 'checked' : '' ?>>
                              <label class="form-check-label visually-hidden" for="toggleCasteActive_<?= $caste['Caste_id'] ?>">Toggle Active</label>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <tr><td colspan="4" class="text-center py-4">No castes found.</td></tr>
                    <?php endif; ?>
                </table>
              </div>
            </form>
            <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
              <?php if ($current_total_records > 0): ?>
                <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $current_total_records ?> total records.</p>
              <?php else: ?>
                <p class="text-muted mb-0">No records found matching your criteria.</p>
              <?php endif; ?>
            </div>

            <nav aria-label="Page navigation" class="mt-4">
              <ul class="pagination justify-content-center">
                <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page - 1, $active_tab, $search_query)) ?>">Previous</a>
                </li>
                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                  <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                    <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($i, $active_tab, $search_query)) ?>"><?= $i ?></a>
                  </li>
                <?php endfor; ?>
                <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
                  <a class="page-link" href="<?= htmlspecialchars(buildPaginationQuery($current_page + 1, $active_tab, $search_query)) ?>">Next</a>
                </li>
              </ul>
            </nav>
          </div>
      </div>
    </div>
  </main>

  <!-- Modals for CRUD operations -->

  <!-- Add Class Modal -->
  <div class="modal fade" id="addClassModal" tabindex="-1" aria-labelledby="addClassModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="addClassModalLabel">Add New Class</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form action="<?= htmlspecialchars($current_page_base_url) ?>" method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="add_class">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <div class="mb-3">
              <label for="addClassName" class="form-label">Class Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="addClassName" name="class_name" required>
            </div>
            <div class="mb-3">
              <label for="addSemester" class="form-label">Semester</label>
              <input type="text" class="form-control" id="addSemester" name="semester">
            </div>
            <div class="mb-3">
              <label for="addSession" class="form-label">Session (e.g., 2023-2024)</label>
              <input type="text" class="form-control" id="addSession" name="session">
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Add Class</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- Update Class Modal -->
  <div class="modal fade" id="updateClassModal" tabindex="-1" aria-labelledby="updateClassModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark"><h5 class="modal-title" id="updateClassModalLabel">Update Class</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form action="<?= htmlspecialchars($current_page_base_url) ?>" method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_class">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <input type="hidden" name="class_id" id="updateClassId">
            <div class="mb-3">
              <label for="updateClassName" class="form-label">Class Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="updateClassName" name="class_name" required>
            </div>
            <div class="mb-3">
              <label for="updateSemester" class="form-label">Semester</label>
              <input type="text" class="form-control" id="updateSemester" name="semester">
            </div>
            <div class="mb-3">
              <label for="updateSession" class="form-label">Session</label>
              <input type="text" class="form-control" id="updateSession" name="session">
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-warning">Save Changes</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- Add Religion Modal -->
  <div class="modal fade" id="addReligionModal" tabindex="-1" aria-labelledby="addReligionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="addReligionModalLabel">Add New Religion</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form action="<?= htmlspecialchars($current_page_base_url) ?>" method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="add_religion">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <div class="mb-3">
              <label for="addReligionName" class="form-label">Religion Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="addReligionName" name="religion_name" required>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Add Religion</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- Update Religion Modal -->
  <div class="modal fade" id="updateReligionModal" tabindex="-1" aria-labelledby="updateReligionModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark"><h5 class="modal-title" id="updateReligionModalLabel">Update Religion</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form action="<?= htmlspecialchars($current_page_base_url) ?>" method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_religion">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <input type="hidden" name="religion_id" id="updateReligionId">
            <div class="mb-3">
              <label for="updateReligionName" class="form-label">Religion Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="updateReligionName" name="religion_name" required>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-warning">Save Changes</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- Add Caste Modal -->
  <div class="modal fade" id="addCasteModal" tabindex="-1" aria-labelledby="addCasteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-primary text-white"><h5 class="modal-title" id="addCasteModalLabel">Add New Caste</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form action="<?= htmlspecialchars($current_page_base_url) ?>" method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="add_caste">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <div class="mb-3">
              <label for="addCasteName" class="form-label">Caste Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="addCasteName" name="caste_name" required>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-primary">Add Caste</button></div>
        </form>
      </div>
    </div>
  </div>

  <!-- Update Caste Modal -->
  <div class="modal fade" id="updateCasteModal" tabindex="-1" aria-labelledby="updateCasteModalLabel" aria-hidden="true">
    <div class="modal-dialog">
      <div class="modal-content">
        <div class="modal-header bg-warning text-dark"><h5 class="modal-title" id="updateCasteModalLabel">Update Caste</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <form action="<?= htmlspecialchars($current_page_base_url) ?>" method="POST">
          <div class="modal-body">
            <input type="hidden" name="action" value="update_caste">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($active_tab) ?>">
            <input type="hidden" name="caste_id" id="updateCasteId">
            <div class="mb-3">
              <label for="updateCasteName" class="form-label">Caste Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" id="updateCasteName" name="caste_name" required>
            </div>
          </div>
          <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><button type="submit" class="btn btn-warning">Save Changes</button></div>
        </form>
      </div>
    </div>
  </div>


  <!-- Confirmation Modal (generic for all bulk actions) -->
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
      $('.selectpicker').selectpicker(); // Kept, although specific selectpickers for scholarship mapping are gone.

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
          delay: 5000
        });
        toast.show();
      }

      // --- Generic CRUD Button Logic ---
      const confirmationModal = new bootstrap.Modal(document.getElementById('confirmationModal'));
      const confirmationModalBody = document.getElementById('confirmationModalBody');
      const confirmationModalHeader = document.getElementById('confirmationModalHeader');
      const confirmationModalLabel = document.getElementById('confirmationModalLabel');
      const confirmActionButton = document.getElementById('confirmActionButton');

      let currentActionDetails = {
        action: '', // e.g., 'bulk_deactivate', 'hard_delete'
        formId: '', // e.g., 'classForm'
        tableId: '', // e.g., 'classesTable'
        entityName: '', // e.g., 'class'
        confirmMessage: '',
        confirmColor: ''
      };

      function toggleActionButtons(tableId) {
        const table = document.getElementById(tableId);
        if (!table) return;

        const checkboxes = table.querySelectorAll('.row-checkbox[data-table-id="' + tableId + '"]:checked');
        const checkedCount = checkboxes.length;
        const totalRows = table.querySelectorAll('.row-checkbox[data-table-id="' + tableId + '"]').length;

        // Get buttons associated with this table
        let updateBtn = null, activateBtn = null, deactivateBtn = null, hardDeleteBtn = null, selectAllBtn = null;
        if (tableId === 'classesTable') {
          updateBtn = document.getElementById('updateClassBtn');
          activateBtn = document.getElementById('activateClassBtn');
          deactivateBtn = document.getElementById('deactivateClassBtn');
          hardDeleteBtn = document.getElementById('hardDeleteClassBtn');
          selectAllBtn = document.getElementById('selectAllClassesBtn');
        } else if (tableId === 'religionsTable') {
          updateBtn = document.getElementById('updateReligionBtn');
          activateBtn = document.getElementById('activateReligionBtn');
          deactivateBtn = document.getElementById('deactivateReligionBtn');
          hardDeleteBtn = document.getElementById('hardDeleteReligionBtn');
          selectAllBtn = document.getElementById('selectAllReligionsBtn');
        } else if (tableId === 'castesTable') {
          updateBtn = document.getElementById('updateCasteBtn');
          activateBtn = document.getElementById('activateCasteBtn');
          deactivateBtn = document.getElementById('deactivateCasteBtn');
          hardDeleteBtn = document.getElementById('hardDeleteCasteBtn');
          selectAllBtn = document.getElementById('selectAllCastesBtn');
        }

        if (updateBtn) updateBtn.disabled = !(checkedCount === 1);
        if (activateBtn) activateBtn.disabled = !(checkedCount > 0);
        if (deactivateBtn) deactivateBtn.disabled = !(checkedCount > 0);
        if (hardDeleteBtn) hardDeleteBtn.disabled = !(checkedCount > 0);

        if (selectAllBtn) {
          if (totalRows > 0 && checkedCount === totalRows) {
            selectAllBtn.textContent = 'Deselect All';
          } else {
            selectAllBtn.textContent = 'Select All';
          }
        }
      }

      // Event listener for master checkbox
      document.querySelectorAll('.master-checkbox').forEach(masterCheckbox => {
        masterCheckbox.addEventListener('change', function() {
          const tableId = this.dataset.tableId;
          document.querySelectorAll('.row-checkbox[data-table-id="' + tableId + '"]').forEach(checkbox => {
            checkbox.checked = this.checked;
          });
          toggleActionButtons(tableId);
        });
      });

    // Event listener for individual row checkboxes
document.querySelectorAll('table.table-striped-edu').forEach(table => {
  table.addEventListener('change', function(event) {
    if (event.target.classList.contains('row-checkbox')) {
      const tableId = event.target.dataset.tableId;

      const allRowCheckboxes = document.querySelectorAll('.row-checkbox[data-table-id="' + tableId + '"]');
      const allChecked = Array.from(allRowCheckboxes).every(checkbox => checkbox.checked);

      const masterCheckbox = document.querySelector('.master-checkbox[data-table-id="' + tableId + '"]');
      if (masterCheckbox) {
        masterCheckbox.checked = allChecked && allRowCheckboxes.length > 0;
      }

      toggleActionButtons(tableId);
    }
  });
});


      // Initial state for all tables
      document.querySelectorAll('table.table-striped-edu').forEach(table => {
        toggleActionButtons(table.id);
      });

      // Select All Button Logic
      document.querySelectorAll('.btn-select-all').forEach(button => {
        button.addEventListener('click', function() {
          let tableId = '';
          if (this.id === 'selectAllClassesBtn') tableId = 'classesTable';
          else if (this.id === 'selectAllReligionsBtn') tableId = 'religionsTable';
          else if (this.id === 'selectAllCastesBtn') tableId = 'castesTable';

          if (tableId) {
            const masterCheckbox = document.querySelector('.master-checkbox[data-table-id="' + tableId + '"]');
            const allSelected = masterCheckbox.checked;
            masterCheckbox.checked = !allSelected;
            document.querySelectorAll('.row-checkbox[data-table-id="' + tableId + '"]').forEach(checkbox => {
              checkbox.checked = !allSelected;
            });
            toggleActionButtons(tableId);
          }
        });
      });


      // --- Update/Edit Button Logic (Populate Modals) ---
      // Classes
      document.getElementById('updateClassBtn').addEventListener('click', function() {
        const selectedCheckbox = document.querySelector('#classesTable .row-checkbox:checked');
        if (selectedCheckbox) {
          const row = selectedCheckbox.closest('tr');
          document.getElementById('updateClassId').value = row.querySelector('.class_id_cell').textContent;
          document.getElementById('updateClassName').value = row.querySelector('.class_name_cell').textContent;
          document.getElementById('updateSemester').value = row.querySelector('.semester_cell').textContent;
          document.getElementById('updateSession').value = row.querySelector('.session_cell').textContent;
          new bootstrap.Modal(document.getElementById('updateClassModal')).show();
        } else { showToast('info', 'Please select one class to update.'); }
      });

      // Religions
      document.getElementById('updateReligionBtn').addEventListener('click', function() {
        const selectedCheckbox = document.querySelector('#religionsTable .row-checkbox:checked');
        if (selectedCheckbox) {
          const row = selectedCheckbox.closest('tr');
          document.getElementById('updateReligionId').value = row.querySelector('.religion_id_cell').textContent;
          document.getElementById('updateReligionName').value = row.querySelector('.religion_name_cell').textContent;
          new bootstrap.Modal(document.getElementById('updateReligionModal')).show();
        } else { showToast('info', 'Please select one religion to update.'); }
      });

      // Castes
      document.getElementById('updateCasteBtn').addEventListener('click', function() {
        const selectedCheckbox = document.querySelector('#castesTable .row-checkbox:checked');
        if (selectedCheckbox) {
          const row = selectedCheckbox.closest('tr');
          document.getElementById('updateCasteId').value = row.querySelector('.caste_id_cell').textContent;
          document.getElementById('updateCasteName').value = row.querySelector('.caste_name_cell').textContent;
          new bootstrap.Modal(document.getElementById('updateCasteModal')).show();
        } else { showToast('info', 'Please select one caste to update.'); }
      });

      // --- Confirmation Modal Setup ---
      function setupConfirmation(action, formId, tableId, entityName, confirmMessage, confirmColor) {
        currentActionDetails.action = action;
        currentActionDetails.formId = formId;
        currentActionDetails.tableId = tableId;
        currentActionDetails.entityName = entityName;
        currentActionDetails.confirmMessage = confirmMessage;
        currentActionDetails.confirmColor = confirmColor;

        const checkedCount = document.querySelectorAll('#' + tableId + ' .row-checkbox:checked').length;
        if (checkedCount === 0) {
          showToast('info', `No ${currentActionDetails.entityName}(s) selected.`);
          return;
        }

        confirmationModalHeader.classList.remove('bg-success', 'text-white', 'bg-warning', 'bg-danger');
        confirmationModalHeader.classList.add(confirmColor, 'text-white');
        confirmationModalLabel.textContent = `Confirm ${entityName.charAt(0).toUpperCase() + entityName.slice(1)} ${action.includes('hard_delete') ? 'Permanent Deletion' : (action.includes('deactivate') ? 'Deactivation' : 'Activation')}`;
        confirmationModalBody.innerHTML = confirmMessage.replace('$', checkedCount);
        confirmActionButton.classList.remove('btn-success', 'btn-warning', 'btn-danger');
        if (action.includes('hard_delete') || action.includes('deactivate')) {
          confirmActionButton.classList.add('btn-danger');
        } else if (action.includes('activate')) {
          confirmActionButton.classList.add('btn-success');
        }
        confirmationModal.show();
      }

      confirmActionButton.addEventListener('click', function() {
        confirmationModal.hide();
        setTimeout(() => {
          const form = document.getElementById(currentActionDetails.formId);
          const formActionInput = form.querySelector('[name="action"]');
          if (formActionInput) {
            formActionInput.value = currentActionDetails.action;
          }
          form.submit();
        }, 100);
      });

      // Bulk action button event listeners - Classes
      document.getElementById('deactivateClassBtn').addEventListener('click', () => setupConfirmation('bulk_deactivate_class', 'classForm', 'classesTable', 'class', 'Are you sure you want to deactivate <strong>$</strong> selected class(es)?', 'bg-danger'));
      document.getElementById('activateClassBtn').addEventListener('click', () => setupConfirmation('bulk_activate_class', 'classForm', 'classesTable', 'class', 'Are you sure you want to activate <strong>$</strong> selected class(es)?', 'bg-success'));
      document.getElementById('hardDeleteClassBtn').addEventListener('click', () => setupConfirmation('hard_delete_class', 'classForm', 'classesTable', 'class', '<strong>WARNING: This action cannot be undone.</strong><p>Are you absolutely sure you want to PERMANENTLY delete <strong>$</strong> selected class(es)?</p>', 'bg-danger'));

      // Bulk action button event listeners - Religions
      document.getElementById('deactivateReligionBtn').addEventListener('click', () => setupConfirmation('bulk_deactivate_religion', 'religionForm', 'religionsTable', 'religion', 'Are you sure you want to deactivate <strong>$</strong> selected religion(s)?', 'bg-danger'));
      document.getElementById('activateReligionBtn').addEventListener('click', () => setupConfirmation('bulk_activate_religion', 'religionForm', 'religionsTable', 'religion', 'Are you sure you want to activate <strong>$</strong> selected religion(s)?', 'bg-success'));
      document.getElementById('hardDeleteReligionBtn').addEventListener('click', () => setupConfirmation('hard_delete_religion', 'religionForm', 'religionsTable', 'religion', '<strong>WARNING: This action cannot be undone.</strong><p>Are you absolutely sure you want to PERMANENTLY delete <strong>$</strong> selected religion(s)?</p>', 'bg-danger'));

      // Bulk action button event listeners - Castes
      document.getElementById('deactivateCasteBtn').addEventListener('click', () => setupConfirmation('bulk_deactivate_caste', 'casteForm', 'castesTable', 'caste', 'Are you sure you want to deactivate <strong>$</strong> selected caste(s)?', 'bg-danger'));
      document.getElementById('activateCasteBtn').addEventListener('click', () => setupConfirmation('bulk_activate_caste', 'casteForm', 'castesTable', 'caste', 'Are you sure you want to activate <strong>$</strong> selected caste(s)?', 'bg-success'));
      document.getElementById('hardDeleteCasteBtn').addEventListener('click', () => setupConfirmation('hard_delete_caste', 'casteForm', 'castesTable', 'caste', '<strong>WARNING: This action cannot be undone.</strong><p>Are you absolutely sure you want to PERMANENTLY delete <strong>$</strong> selected caste(s)?</p>', 'bg-danger'));


      // --- Single Toggle Active State (AJAX) ---
      document.querySelectorAll('.edu-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
          const id = this.dataset.id;
          const entityType = this.dataset.entityType; // e.g., 'class', 'religion'
          const isActive = this.checked ? 'y' : 'n';
          const originalState = !this.checked ? 'y' : 'n'; // Store original state for rollback

          fetch('<?= $admin_classes ?>', { // Changed BASE_URL concatenation to $admin_classes
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=toggle_active&id=${id}&entity_type=${entityType}&is_active=${isActive}&tab=<?= htmlspecialchars($active_tab) // Added this to ensure AJAX also preserves the tab ?>`
          })
          .then(response => {
            if (!response.ok) { throw new Error('Network response was not ok'); }
            return response.json();
          })
          .then(data => {
            if (data.success) { showToast('success', data.message); }
            else { showToast('danger', data.message); this.checked = (originalState === 'y'); } // Rollback on failure
          })
          .catch(error => {
            console.error('Error:', error);
            showToast('danger', 'An error occurred while updating status. Please try again.');
            this.checked = (originalState === 'y'); // Rollback on network error
          });
        });
      });

      // --- Clear Search Input Button Logic ---
      document.getElementById('clearClassSearchInput')?.addEventListener('click', function() {
        document.querySelector('#searchClassesForm input[name="search"]').value = '';
        document.getElementById('searchClassesForm').submit();
      });
      document.getElementById('clearReligionSearchInput')?.addEventListener('click', function() {
        document.querySelector('#searchReligionsForm input[name="search"]').value = '';
        document.getElementById('searchReligionsForm').submit();
      });
      document.getElementById('clearCasteSearchInput')?.addEventListener('click', function() {
        document.querySelector('#searchCastesForm input[name="search"]').value = '';
        document.getElementById('searchCastesForm').submit();
      });

      // Tab changes (This part is already correct as it uses client-side URL manipulation)
      document.querySelectorAll('#mainTabs button').forEach(tabButton => {
        tabButton.addEventListener('shown.bs.tab', function (event) {
          const newTab = event.target.id.replace('-tab', '');
          const currentUrl = new URL(window.location.href);
          currentUrl.searchParams.set('tab', newTab);
          // No need to redirect immediately, history.pushState is enough for display
          // and forms below will pick up the correct tab for POST.
          // For this setup, we'll keep the reload for simplicity of data rendering:
          window.location.href = currentUrl.toString();
        });
      });

    });
  </script>
</body>
</html>