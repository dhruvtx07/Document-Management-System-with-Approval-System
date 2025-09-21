<?php
// PHP error reporting for development - REMOVE IN PRODUCTION
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/admin_auth.php'; // This includes db.php and functions.php
require_once '../links.php'; // ADDED: Include the links definition file

$clg_id = getCurrentUserClgId();
$current_user_id = getCurrentUserId();

// REMOVED: BASE_URL definition is handled globally in db.php via admin_auth.php and links.php
// if (!defined('BASE_URL')) {
//  define('BASE_URL', 'http://localhost/demo_docmg/'); // Adjust as per your setup's root folder
// }

$folder_id = filter_input(INPUT_GET, 'folder_id', FILTER_VALIDATE_INT);
// Also need to check if folder_id is passed in POST for AJAX calls
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['folder_id'])) {
  $folder_id_post = filter_input(INPUT_POST, 'folder_id', FILTER_VALIDATE_INT);
  // If folder_id from GET is not set, use the one from POST
  if (!$folder_id && $folder_id_post) {
    $folder_id = $folder_id_post;
  }
}


if (!$folder_id) {
  set_flash_message("No folder specified or invalid Folder ID.", "danger");
  // For AJAX calls, this redirect won't work perfectly, but for direct page load, it helps.
  // The JS error handling will catch if the PHP exits early before a JSON response.
  if (!isset($_POST['action']) || !in_array($_POST['action'], ['toggle_active_mapping', 'toggle_mandate_mapping'])) {
    redirect($admin_folders); // MODIFIED: Use variable from links.php
    exit(); // Ensure script stops here
  } else {
    // For AJAX calls, just exit with an error.
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Mandatory folder ID not provided.']);
    exit();
  }
}

// Fetch Folder details - only if not an AJAX request that directly updates status.
// This is a small optimization to avoid fetching folder name on every AJAX toggle.
if (!((isset($_POST['action']) && ($_POST['action'] === 'toggle_active_mapping' || $_POST['action'] === 'toggle_mandate_mapping')))) {
  $stmt_folder = prepare_and_execute($conn, "SELECT Folder_name FROM Folder WHERE Folder_id = ? AND Clg_id = ?", [$folder_id, $clg_id], "ii");
  $folder = $stmt_folder->get_result()->fetch_assoc();
  $stmt_folder->close();

  if (!$folder) {
    set_flash_message("Folder not found or not accessible.", "danger");
    redirect($admin_folders); // MODIFIED: Use variable from links.php
    exit();
  }
  $folder_name = $folder['Folder_name'];
} else {
  // For AJAX toggles, we assume the folder_id is valid from the client-side context.
  // The folder_name is not strictly needed for the toggle operation itself.
  $folder_name = "Unknown Folder"; // Placeholder for display context
}


// --- HELPER FUNCTIONS FOR MAPPED DOCUMENTS ---

/**
 * Updates the Is_active status for one or more document mappings.
 */
function updateMappingStatus($conn, array $mapping_ids, $is_active_status, $folder_id, $clg_id) {
  if (empty($mapping_ids)) {
    return 0; // No mappings to update
  }
  if (!in_array($is_active_status, ['y', 'n'])) {
    error_log("Invalid Is_active status provided for mapping ID(s): " . implode(', ', $mapping_ids) . " status: " . $is_active_status);
    return false;
  }

  $placeholders = implode(',', array_fill(0, count($mapping_ids), '?'));
  $sql = "UPDATE Folder_doc_mapping SET Is_active = ? WHERE Clg_id = ? AND Folder_id = ? AND Folder_doc_mapping_id IN ($placeholders)";
  // 's' for is_active_status, 'i' for clg_id, 'i' for folder_id, then 'i' for each mapping_id
  $types = "sii" . str_repeat('i', count($mapping_ids));
  $params = array_merge([$is_active_status, $clg_id, $folder_id], $mapping_ids);

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    if ($stmt === false) {
      error_log("Failed to prepare or execute statement in updateMappingStatus.");
      return false;
    }
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
  } catch (Exception $e) {
    error_log("Exception in updateMappingStatus: " . $e->getMessage());
    return false; // Return false on caught exception
  }
}

/**
 * Updates the Is_mandate status for one or more document mappings.
 */
function updateMappingMandateStatus($conn, array $mapping_ids, $is_mandate_status, $folder_id, $clg_id) {
  if (empty($mapping_ids)) {
    return 0; // No mappings to update
  }
  if (!in_array($is_mandate_status, ['y', 'n'])) {
    error_log("Invalid Is_mandate status provided for mapping ID(s): " . implode(', ', $mapping_ids) . " status: " . $is_mandate_status);
    return false;
  }
   
  $placeholders = implode(',', array_fill(0, count($mapping_ids), '?'));
  $sql = "UPDATE Folder_doc_mapping SET Is_mandate = ? WHERE Clg_id = ? AND Folder_id = ? AND Folder_doc_mapping_id IN ($placeholders)";
  $types = "sii" . str_repeat('i', count($mapping_ids));
  $params = array_merge([$is_mandate_status, $clg_id, $folder_id], $mapping_ids);

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    if ($stmt === false) {
      error_log("Failed to prepare or execute statement in updateMappingMandateStatus.");
      return false;
    }
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
  } catch (Exception $e) {
    error_log("Exception in updateMappingMandateStatus: " . $e->getMessage());
    return false; // Return false on caught exception
  }
}

/**
 * Hard deletes document mappings by Folder_doc_mapping_id. USE WITH EXTREME CAUTION.
 */
function hardDeleteMappings($conn, array $mapping_ids, $folder_id, $clg_id) {
  if (empty($mapping_ids)) {
    return 0; // No mappings to delete
  }

  $placeholders = implode(',', array_fill(0, count($mapping_ids), '?'));
  $sql = "DELETE FROM Folder_doc_mapping WHERE Clg_id = ? AND Folder_id = ? AND Folder_doc_mapping_id IN ($placeholders)";
  $types = "ii" . str_repeat('i', count($mapping_ids));
  $params = array_merge([$clg_id, $folder_id], $mapping_ids);

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    if ($stmt === false) {
      error_log("Failed to prepare or execute statement in hardDeleteMappings.");
      return false;
    }
    $affected_rows = $stmt->affected_rows;
    $stmt->close();
    return $affected_rows;
  } catch (Exception $e) {
    error_log("Exception in hardDeleteMappings: " . $e->getMessage());
    return false;
  }
}


/**
 * Fetches distinct document types (categories) for documents mapped to a specific folder.
 */
function getDistinctMappedDocTypes($conn, $folder_id, $clg_id) {
  try {
    $stmt = prepare_and_execute($conn,
      "SELECT DISTINCT d.Doc_type
       FROM doc d
       JOIN Folder_doc_mapping fdm ON d.Doc_id = fdm.Doc_id
       WHERE fdm.Folder_id = ? AND fdm.Clg_id = ?
       ORDER BY d.Doc_type",
      [$folder_id, $clg_id], "ii"
    );
    if ($stmt === false) { return []; }
    $result = $stmt->get_result();
    $types = [];
    while ($row = $result->fetch_row()) {
      if ($row[0] !== null && $row[0] !== '') {
        $types[] = $row[0];
      }
    }
    $stmt->close();
    return $types;
  } catch (Exception $e) {
    error_log("Error fetching distinct mapped document types: " . $e->getMessage());
    return [];
  }
}

/**
 * Retrieves all distinct extensions from the Doc_ext column for documents mapped to a specific folder.
 * Handles comma-separated values and normalizes them (lowercase, no leading dot, no internal spaces).
 */
function getDistinctMappedDocExtensions($conn, $folder_id, $clg_id) {
  try {
    $stmt = prepare_and_execute($conn,
      "SELECT DISTINCT d.Doc_ext
       FROM doc d
       JOIN Folder_doc_mapping fdm ON d.Doc_id = fdm.Doc_id
       WHERE fdm.Folder_id = ? AND fdm.Clg_id = ?",
      [$folder_id, $clg_id], "ii"
    );
    if ($stmt === false) { return []; }
    $all_ext_strings = $stmt->get_result()->fetch_all(MYSQLI_NUM);
    $stmt->close();

    $unique_extensions = [];
    foreach ($all_ext_strings as $row) {
      $ext_string = $row[0];
      if ($ext_string) {
        $parts = array_map(function($item) {
          return strtolower(str_replace(' ', '', trim(ltrim($item, '.'))));
        }, array_filter(explode(',', $ext_string)));
        $unique_extensions = array_merge($unique_extensions, $parts);
      }
    }
    $filtered_unique_extensions = array_values(array_unique(array_filter($unique_extensions)));
    sort($filtered_unique_extensions);
    return $filtered_unique_extensions;
  } catch (Exception $e) {
    error_log("Error fetching distinct mapped document extensions: " . $e->getMessage());
    return [];
  }
}

/**
 * Fetches mapped documents for a given folder, with optional search, category filter, extension filter, size filter, and pagination.
 */
function getMappedDocuments($conn, $folder_id, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size, $limit, $offset) {
  $sql = "SELECT fdm.Folder_doc_mapping_id, fdm.Doc_id, d.Doc_name, d.Doc_ext, d.Doc_max_size,
           fdm.Submit_before, fdm.Is_mandate, fdm.Is_active as Mapping_Is_active, u.Name as CreatorName
           FROM Folder_doc_mapping fdm
           JOIN doc d ON fdm.Doc_id = d.Doc_id
           LEFT JOIN Users u ON fdm.Created_by = u.Uid
           WHERE fdm.Folder_id = ? AND fdm.Clg_id = ?";
  $params = [$folder_id, $clg_id];
  $types = "ii";

  // Text search on Doc_name and Doc_desc
  if (!empty($search_query)) {
    $sql .= " AND (d.Doc_name LIKE ? OR d.Doc_desc LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
    $types .= "ss";
  }

  // Category filter (Doc_type)
  if (!empty($selected_doc_types)) {
    $placeholders = implode(',', array_fill(0, count($selected_doc_types), '?'));
    $sql .= " AND d.Doc_type IN ($placeholders)";
    array_push($params, ...$selected_doc_types);
    $types .= str_repeat('s', count($selected_doc_types));
  }

  // Extension filter (Doc_ext)
  if (!empty($selected_doc_extensions)) {
    $ext_conditions = [];
    foreach ($selected_doc_extensions as $ext) {
      $ext_conditions[] = "FIND_IN_SET(?, LOWER(REPLACE(REPLACE(d.Doc_ext, '.', ''), ' ', '')))";
      $params[] = $ext;
      $types .= "s";
    }
    $sql .= " AND (" . implode(' OR ', $ext_conditions) . ")";
  }

  // Max Size filter (convert MB to Bytes for DB comparison)
  if ($min_max_size !== null) {
    $sql .= " AND d.Doc_max_size >= ?";
    $params[] = floatval($min_max_size) * 1024 * 1024; // Ensure float type
    $types .= "d";
  }
  if ($max_max_size !== null) {
    $sql .= " AND d.Doc_max_size <= ?";
    $params[] = floatval($max_max_size) * 1024 * 1024; // Ensure float type
    $types .= "d";
  }

  $sql .= " ORDER BY d.Doc_name ASC LIMIT ? OFFSET ?";
  $params[] = $limit;
  $params[] = $offset;
  $types .= "ii";

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    if ($stmt === false) { return []; }
    $result = $stmt->get_result();
    $documents = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $documents;
  } catch (Exception $e) {
    error_log("Error fetching mapped documents: " . $e->getMessage());
    return [];
  }
}

/**
 * Counts total mapped documents for a given folder, with optional search, category filter, extension filter, and size filter.
 */
function countMappedDocuments($conn, $folder_id, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size) {
  $sql = "SELECT COUNT(*) FROM Folder_doc_mapping fdm JOIN doc d ON fdm.Doc_id = d.Doc_id WHERE fdm.Folder_id = ? AND fdm.Clg_id = ?";
  $params = [$folder_id, $clg_id];
  $types = "ii";

  if (!empty($search_query)) {
    $sql .= " AND (d.Doc_name LIKE ? OR d.Doc_desc LIKE ?)";
    $params[] = '%' . $search_query . '%';
    $params[] = '%' . $search_query . '%';
    $types .= "ss";
  }

  if (!empty($selected_doc_types)) {
    $placeholders = implode(',', array_fill(0, count($selected_doc_types), '?'));
    $sql .= " AND d.Doc_type IN ($placeholders)";
    array_push($params, ...$selected_doc_types);
    $types .= str_repeat('s', count($selected_doc_types));
  }

  if (!empty($selected_doc_extensions)) {
    $ext_conditions = [];
    foreach ($selected_doc_extensions as $ext) {
      $ext_conditions[] = "FIND_IN_SET(?, LOWER(REPLACE(REPLACE(d.Doc_ext, '.', ''), ' ', '')))";
      $params[] = $ext;
      $types .= "s";
    }
    $sql .= " AND (" . implode(' OR ', $ext_conditions) . ")";
  }

  if ($min_max_size !== null) {
    $sql .= " AND d.Doc_max_size >= ?";
    $params[] = floatval($min_max_size) * 1024 * 1024;
    $types .= "d";
  }
  if ($max_max_size !== null) {
    $sql .= " AND d.Doc_max_size <= ?";
    $params[] = floatval($max_max_size) * 1024 * 1024;
    $types .= "d";
  }

  try {
    $stmt = prepare_and_execute($conn, $sql, $params, $types);
    if ($stmt === false) { return 0; }
    $result = $stmt->get_result();
    $count = $result->fetch_row()[0];
    $stmt->close();
    return $count;
  } catch (Exception $e) {
    error_log("Error counting mapped documents: " . $e->getMessage());
    return 0;
  }
}
// --- End Helper Functions for mapped documents ---

$action = $_GET['action'] ?? 'list'; // Default action for this page is list/manage
$mapping_id_to_edit = null;
$mapping_data = null;


// --- Process Filter Parameters ---
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_doc_types = isset($_GET['doc_types']) && is_array($_GET['doc_types']) ? $_GET['doc_types'] : [];
$selected_doc_types = array_map('trim', array_filter($selected_doc_types));

$selected_doc_extensions = isset($_GET['doc_extensions']) && is_array($_GET['doc_extensions']) ? $_GET['doc_extensions'] : [];
$selected_doc_extensions = array_map(function($item) {
  return strtolower(str_replace(' ', '', trim(ltrim($item, '.'))));
}, array_filter($selected_doc_extensions));

$min_max_size = isset($_GET['min_size']) && is_numeric($_GET['min_size']) ? (float)$_GET['min_size'] : null;
$max_max_size = isset($_GET['max_size']) && is_numeric($_GET['max_size']) ? (float)$_GET['max_size'] : null;

// Define base URL for this specific page, including folder_id
$current_page_base_url = $_SERVER['PHP_SELF']; 
$base_query_params = array_filter([
  'folder_id' => $folder_id,
  'search' => !empty($search_query) ? $search_query : null,
  'doc_types' => !empty($selected_doc_types) ? $selected_doc_types : null,
  'doc_extensions' => !empty($selected_doc_extensions) ? $selected_doc_extensions : null,
  'min_size' => $min_max_size,
  'max_size' => $max_max_size,
  'page' => isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : null,
]);
$redirect_to_list_url = $current_page_base_url . '?' . http_build_query($base_query_params);


// --- Handle POST requests for Add/Edit/Bulk Actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $post_action = $_POST['action'] ?? ''; // Use a generic 'action' field for all POSTs
   
  // Build redirect URL preserving current filters/page
   // For AJAX requests, we don't redirect but exit after JSON output.
   // So, this $redirect_url is only for non-AJAX POSTs (add/edit/bulk form submits).
  $refresh_params = array_filter([
    'folder_id' => $folder_id_post ?? $folder_id, // Use folder_id from POST if available, else from GET
    'search' => !empty($search_query) ? $search_query : null,
    'page' => isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1,
    'doc_types' => !empty($selected_doc_types) ? $selected_doc_types : null,
    'doc_extensions' => !empty($selected_doc_extensions) ? $selected_doc_extensions : null,
    'min_size' => $min_max_size,
    'max_size' => $max_max_size,
  ]);
  $redirect_url = $current_page_base_url . '?' . http_build_query($refresh_params);

  try {
    switch ($post_action) {
      case 'add_doc_to_folder':
        $doc_id_to_add = filter_input(INPUT_POST, 'doc_id', FILTER_VALIDATE_INT);
        $submit_before_str = trim($_POST['submit_before'] ?? '');
        $is_mandate = isset($_POST['is_mandate']) ? 'y' : 'n';
        $is_active_mapping = isset($_POST['is_active_mapping']) ? 'y' : 'n';

        $submit_before_date = null;
        if (!empty($submit_before_str)) {
          try {
            $dt = new DateTime($submit_before_str);
            $submit_before_date = $dt->format('Y-m-d H:i:s');
          } catch (Exception $e) {
            set_flash_message("Invalid 'Submit Before' date format. Please use YYYY-MM-DD HH:MM or similar.", "danger");
            $_SESSION['form_data'] = $_POST; // Preserve for form
            redirect($current_page_base_url . "?folder_id={$folder_id}&action=add_mapping");
            exit();
          }
        }

        if (!$doc_id_to_add) {
          set_flash_message("Please select a document type to add.", "danger");
        } else {
          $sql = "INSERT INTO Folder_doc_mapping (Clg_id, Folder_id, Doc_id, Submit_before, Is_mandate, Is_active, Created_by)
              VALUES (?, ?, ?, ?, ?, ?, ?)
              ON DUPLICATE KEY UPDATE
                Submit_before = VALUES(Submit_before),
                Is_mandate = VALUES(Is_mandate),
                Is_active = VALUES(Is_active),
                Created_by = VALUES(Created_by),
                Created_at = NOW()";

          $stmt = prepare_and_execute($conn, $sql, [$clg_id, $folder_id, $doc_id_to_add, $submit_before_date, $is_mandate, $is_active_mapping, $current_user_id], "iiisssi");
          if ($stmt && $stmt->affected_rows > 0) {
            set_flash_message("Document mapped to folder successfully!", "success");
          } else {
            set_flash_message("Error mapping document or no changes made: " . ($stmt ? ($stmt->error ?: "No rows affected.") : "Statement failed."), "info");
          }
          if ($stmt) $stmt->close();
        }
        redirect($redirect_url);
        exit();
        break;

      case 'edit_mapping':
        $mapping_id = filter_input(INPUT_POST, 'mapping_id', FILTER_VALIDATE_INT);
        $doc_id_edit = filter_input(INPUT_POST, 'doc_id_edit', FILTER_VALIDATE_INT); // Doc ID cannot be changed for an existing mapping
        $submit_before_str = trim($_POST['submit_before'] ?? '');
        $is_mandate = isset($_POST['is_mandate']) ? 'y' : 'n';
        $is_active_mapping = isset($_POST['is_active_mapping']) ? 'y' : 'n';

        $submit_before_date = null;
        if (!empty($submit_before_str)) {
          try {
            $dt = new DateTime($submit_before_str);
            $submit_before_date = $dt->format('Y-m-d H:i:s');
          } catch (Exception $e) {
            set_flash_message("Invalid 'Submit Before' date format. Please use YYYY-MM-DD HH:MM or similar.", "danger");
            $_SESSION['form_data'] = $_POST; // Preserve for form
            $edit_redirect_params = array_merge($refresh_params, ['action' => 'edit_mapping', 'mapping_id' => $mapping_id]);
            redirect($current_page_base_url . "?" . http_build_query($edit_redirect_params));
            exit();
          }
        }

        if (!$mapping_id || !$doc_id_edit) {
          set_flash_message("Invalid mapping or document ID for update.", "danger");
        } else {
          $sql = "UPDATE Folder_doc_mapping SET Submit_before = ?, Is_mandate = ?, Is_active = ?
              WHERE Folder_doc_mapping_id = ? AND Folder_id = ? AND Doc_id = ? AND Clg_id = ?";
          $stmt = prepare_and_execute($conn, $sql, [$submit_before_date, $is_mandate, $is_active_mapping, $mapping_id, $folder_id, $doc_id_edit, $clg_id], "sssiiii");

          if ($stmt && $stmt->affected_rows > 0) {
            set_flash_message("Document mapping updated successfully!", "success");
          } elseif ($stmt && $stmt->error) {
            set_flash_message("Error updating mapping: " . $stmt->error, "danger");
          } else {
            set_flash_message("No changes made to the mapping.", "info");
          }
          if ($stmt) $stmt->close();
        }
        redirect($redirect_url);
        exit();
        break;

      case 'bulk_activate_mapping':
      case 'bulk_deactivate_mapping':
        $selected_mappings = $_POST['selected_mappings'] ?? [];
        $mapping_ids_to_process = array_filter(array_map('intval', $selected_mappings));
        $new_status = ($post_action === 'bulk_activate_mapping') ? 'y' : 'n';

        if (empty($mapping_ids_to_process)) {
          set_flash_message("No document mappings selected.", "info");
        } else {
          $affected = updateMappingStatus($conn, $mapping_ids_to_process, $new_status, $folder_id, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} mapping(s) status updated successfully!", "success");
          } else {
            set_flash_message("Failed to update selected document mappings.", "danger"); // More specific error
          }
        }
        redirect($redirect_url);
        exit();
        break;

      case 'bulk_mandate_mapping':
      case 'bulk_unmandate_mapping':
        $selected_mappings = $_POST['selected_mappings'] ?? [];
        $mapping_ids_to_process = array_filter(array_map('intval', $selected_mappings));
        $new_status = ($post_action === 'bulk_mandate_mapping') ? 'y' : 'n';

        if (empty($mapping_ids_to_process)) {
          set_flash_message("No document mappings selected.", "info");
        } else {
          $affected = updateMappingMandateStatus($conn, $mapping_ids_to_process, $new_status, $folder_id, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} mapping(s) mandate status updated successfully!", "success");
          } else {
            set_flash_message("Failed to update selected document mappings mandate status.", "danger"); // More specific error
          }
        }
        redirect($redirect_url);
        exit();
        break;

      case 'bulk_hard_delete_mapping':
        $selected_mappings = $_POST['selected_mappings'] ?? [];
        $mapping_ids_to_process = array_filter(array_map('intval', $selected_mappings));

        if (empty($mapping_ids_to_process)) {
          set_flash_message("No document mappings selected for deletion.", "info");
        } else {
          $affected = hardDeleteMappings($conn, $mapping_ids_to_process, $folder_id, $clg_id);
          if ($affected !== false) {
            set_flash_message("{$affected} document mapping(s) permanently deleted!", "success");
          } else {
            set_flash_message("Failed to permanently delete selected document mappings.", "danger"); // More specific error
          }
        }
        redirect($redirect_url);
        exit();
        break;

      case 'toggle_active_mapping': // AJAX request for single toggle
        // Debugging: Log the received POST data to PHP error log
        error_log("toggle_active_mapping: Received POST data: " . print_r($_POST, true));

        header('Content-Type: application/json'); // Set JSON header early
        $mapping_id = filter_input(INPUT_POST, 'mapping_id', FILTER_VALIDATE_INT);
        $is_active = $_POST['is_active'] ?? 'n';
        // $folder_id and $clg_id are globally available and should be valid from initial page load/auth.

        if ($mapping_id === false || $mapping_id <= 0 || !in_array($is_active, ['y', 'n'])) {
          echo json_encode(['success' => false, 'message' => 'Invalid data for toggle. Mapping ID or status invalid. Received ID: ' . var_export($mapping_id, true) . ', Status: ' . var_export($is_active, true)]);
          exit();
        }
        $affected = updateMappingStatus($conn, [$mapping_id], $is_active, $folder_id, $clg_id);
        if ($affected > 0) {
          echo json_encode(['success' => true, 'message' => 'Mapping status updated.']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Failed to update mapping status or no changes. (Affected rows: ' . ($affected === false ? 'ERROR' : $affected) . ')']);
        }
        exit();

      case 'toggle_mandate_mapping': // AJAX request for single toggle
        // Debugging: Log the received POST data to PHP error log
        error_log("toggle_mandate_mapping: Received POST data: " . print_r($_POST, true));

        header('Content-Type: application/json'); // Set JSON header early
        $mapping_id = filter_input(INPUT_POST, 'mapping_id', FILTER_VALIDATE_INT);
        $is_mandate = $_POST['is_mandate'] ?? 'n';
        // $folder_id and $clg_id are globally available and should be valid.

        if ($mapping_id === false || $mapping_id <= 0 || !in_array($is_mandate, ['y', 'n'])) {
          echo json_encode(['success' => false, 'message' => 'Invalid data for toggle. Mapping ID or mandate status invalid. Received ID: ' . var_export($mapping_id, true) . ', Mandate: ' . var_export($is_mandate, true)]);
          exit();
        }
        $affected = updateMappingMandateStatus($conn, [$mapping_id], $is_mandate, $folder_id, $clg_id);
        if ($affected > 0) {
          echo json_encode(['success' => true, 'message' => 'Mapping mandate status updated.']);
        } else {
          echo json_encode(['success' => false, 'message' => 'Failed to update mapping mandate status or no changes. (Affected rows: ' . ($affected === false ? 'ERROR' : $affected) . ')']);
        }
        exit();

      default:
        // For unhandled POST actions that are not AJAX calls, redirect
        set_flash_message("Invalid action requested.", "warning");
        redirect($redirect_url);
        exit();
        break;
    }
  } catch (Exception $e) {
    // This general catch block is mostly for non-AJAX form submissions.
    // AJAX errors are handled within their specific `case` blocks.
    set_flash_message("Operation failed: " . $e->getMessage(), "danger");
    redirect($redirect_url); // Redirect even on error to prevent re-submission
    exit();
  }
}


// --- Handle GET requests for Edit or Remove (single mapping) ---
// This part is for the main page rendering, not AJAX toggles.
if ($action === 'edit_mapping' && isset($_GET['mapping_id'])) {
  $mapping_id_to_edit = filter_var($_GET['mapping_id'], FILTER_VALIDATE_INT);
  if ($mapping_id_to_edit) {
    if(isset($_SESSION['form_data'])) {
      $mapping_data = $_SESSION['form_data'];
      $mapping_data['Folder_doc_mapping_id'] = $mapping_id_to_edit;
      $mapping_data['Doc_id'] = $mapping_data['doc_id_edit'];
      unset($_SESSION['form_data']);
    } else {
      $stmt_edit = prepare_and_execute($conn,
        "SELECT fdm.*, d.Doc_name
         FROM Folder_doc_mapping fdm
         JOIN doc d ON fdm.Doc_id = d.Doc_id
         WHERE fdm.Folder_doc_mapping_id = ? AND fdm.Folder_id = ? AND fdm.Clg_id = ?",
        [$mapping_id_to_edit, $folder_id, $clg_id],
        "iii"
      );
      if ($stmt_edit === false) {
          set_flash_message("Database error fetching mapping details.", "danger");
          redirect($redirect_to_list_url);
          exit();
      }
      $mapping_data = $stmt_edit->get_result()->fetch_assoc();
      $stmt_edit->close();
    }
    if (!$mapping_data) {
      set_flash_message("Mapping not found or not accessible.", "danger");
      redirect($redirect_to_list_url);
      exit();
    }
    if (!empty($mapping_data['Submit_before'])) {
      try {
        $dt = new DateTime($mapping_data['Submit_before']);
        $mapping_data['submit_before_formatted'] = $dt->format('Y-m-d\TH:i');
      } catch (Exception $e) {
        $mapping_data['submit_before_formatted'] = '';
      }
    } else {
      $mapping_data['submit_before_formatted'] = '';
    }
  } else {
    set_flash_message("Invalid Mapping ID for editing.", "danger");
    redirect($redirect_to_list_url);
    exit();
  }
}

// --- Fetch documents NOT YET MAPPED to this folder (for Add form dropdown) ---
// This list should NOT be affected by folder-specific filters.
$sql_available_docs = "SELECT Doc_id, Doc_name FROM doc
       WHERE Clg_id = ? AND Is_active = 'y'
       AND Doc_id NOT IN (SELECT Doc_id FROM Folder_doc_mapping WHERE Folder_id = ? AND Clg_id = ?)
       ORDER BY Doc_name DESC"; // Order by DESC to show new documents
$stmt_available_docs = prepare_and_execute($conn, $sql_available_docs, [$clg_id, $folder_id, $clg_id], "iii");
$available_docs_result = $stmt_available_docs ? $stmt_available_docs->get_result() : new mysqli_result($conn); // Fallback to empty result if query fails

// --- Pagination Configuration ---
$records_per_page = 15; // Consistent with documents.php
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// --- Fetch MAPPED documents for this folder (with filters) ---
$mapped_documents = getMappedDocuments($conn, $folder_id, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size, $records_per_page, $offset);

// --- Count total Mapped Documents (with filters) ---
$total_mapped_documents = countMappedDocuments($conn, $folder_id, $clg_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size);
$total_pages = ceil($total_mapped_documents / $records_per_page);

// For pagination stats display
$start_record = $total_mapped_documents > 0 ? ($offset + 1) : 0;
$end_record = min(($current_page * $records_per_page), $total_mapped_documents);

// --- Filter Dropdown Data ---
$distinct_mapped_doc_types = getDistinctMappedDocTypes($conn, $folder_id, $clg_id);
$distinct_mapped_doc_extensions = getDistinctMappedDocExtensions($conn, $folder_id, $clg_id);


// Helper function to build pagination query parameters for this page
function buildMappedDocPaginationQuery($currentPage, $folder_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size) {
  global $current_page_base_url; // Use the global base URL for this page
  $query_params = array_filter([
    'folder_id' => $folder_id,
    'page' => $currentPage,
    'search' => !empty($search_query) ? $search_query : null,
    'doc_types' => !empty($selected_doc_types) ? $selected_doc_types : null,
    'doc_extensions' => !empty($selected_doc_extensions) ? $selected_doc_extensions : null,
    'min_size' => $min_max_size,
    'max_size' => $max_max_size,
  ]);
  return $current_page_base_url . (!empty($query_params) ? '?' . http_build_query($query_params) : '');
}

// Page Title
$pageTitle = "Manage Documents for Folder: " . htmlspecialchars($folder_name) . " - EduFlow";
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

  <!-- CUSTOM CSS (copied from documents.php, slightly adjusted for this page's button needs) -->
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
      --edu-mandate-color: #fd7e14; /* orange */
      --edu-unmandate-color: #6c757d; /* secondary - same as select-all */


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

    /* Custom Toggle Switch for Is_mandate */
    .form-check-input.mandate-toggle {
      background-color: var(--edu-unmandate-color); /* Grey when off (not mandate) */
      border-color: var(--edu-unmandate-color);
    }
    .form-check-input.mandate-toggle:checked {
      background-color: var(--edu-mandate-color); /* Orange when on (mandate) */
      border-color: var(--edu-mandate-color);
    }
    .form-check-input.mandate-toggle:checked:hover {
      background-color: #e66a0e; /* Darker orange on hover */
      border-color: #e66a0e;
    }
    .form-check-input.mandate-toggle:focus {
      box-shadow: 0 0 0 .25rem rgb(0 123 255 / 25%);
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
      <a class="navbar-brand edu-brand" href="<?= $admin_home_page ?>"> <!-- MODIFIED -->
        <!-- Changed logo path to relative for `admin/` directory -->
        <img src="<?= $logo ?>" alt="EduFlow Logo" class="navbar-logo"> <!-- MODIFIED -->
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
            <a class="nav-link edu-nav-link" href="<?= $admin_home_page ?>">Home</a> <!-- MODIFIED -->
          </li>
          <li class="nav-item">
            <a class="nav-link edu-nav-link active" href="<?= $admin_documents ?>">Manage Documents</a> <!-- MODIFIED -->
          </li>
          <li class="nav-item">
            <a class="nav-link edu-nav-link" href="<?= $admin_folders ?>">Manage Folders</a> <!-- MODIFIED -->
          </li>
          <li class="nav-item">
            <a class="nav-link edu-nav-link" href="<?= $admin_classes ?>">Manage Classes</a> <!-- MODIFIED -->
          </li>
        </ul>
        <ul class="navbar-nav mb-2 mb-lg-0 align-items-lg-center">
          <li class="nav-item">
            <a class="nav-link edu-nav-link" href="<?= $admin_users ?>?role=student">Manage Students</a> <!-- MODIFIED -->
          </li>
          <li class="nav-item">
            <a class="nav-link edu-nav-link" href="<?= $admin_submissions ?>">View Submissions</a> <!-- MODIFIED -->
          </li>
          <!-- Logout Button -->
          <li class="nav-item ms-lg-3">
            <form action="<?= $logout_page ?>" method="POST" class="d-inline"> <!-- MODIFIED -->
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
          <h1>Manage Documents for Folder: <span class="text-primary"><?php echo htmlspecialchars($folder_name); ?></span></h1>
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

        <div class="d-flex justify-content-between align-items-center mb-3">
          <?php if ($action === 'add_mapping' || ($action === 'edit_mapping' && $mapping_data)): ?>
            <!-- Form for Add/Edit Mapping -->
          <?php else: ?>
            <!-- Action Buttons -->
            <div class="crud-buttons mb-4 w-100 d-flex flex-wrap">
              <a href="<?= htmlspecialchars($current_page_base_url) ?>?folder_id=<?php echo $folder_id; ?>&action=add_mapping" class="btn btn-insert me-2 mb-2">
                <i class="bi bi-plus-circle"></i> Add New Mapping
              </a>
              <button type="button" class="btn btn-update me-2 mb-2" id="editMappingBtn" disabled>Edit Mapping</button>
              <button type="button" class="btn btn-activate me-2 mb-2" id="activateMappingBtn" disabled>Mark Active</button>
              <button type="button" class="btn btn-delete me-2 mb-2" id="deactivateMappingBtn" disabled>Mark Inactive</button>
              <button type="button" class="btn btn-mandate me-2 mb-2" id="mandateMappingBtn" disabled>Mark Mandate</button>
              <button type="button" class="btn btn-unmandate me-2 mb-2" id="unmandateMappingBtn" disabled>Unmark Mandate</button>
              <button type="button" class="btn btn-hard-delete me-2 mb-2" id="hardDeleteMappingBtn" disabled>Hard Delete</button>
              <button type="button" class="btn btn-select-all me-2 mb-2" id="selectAllBtn">Select All</button>
              <a href="<?= $admin_folders ?>" class="btn btn-outline-secondary mb-2">Back to Folders</a> <!-- MODIFIED -->
            </div>
          <?php endif; ?>
        </div>

        <?php if ($action === 'add_mapping' || ($action === 'edit_mapping' && $mapping_data)): ?>
          <div class="card mb-4">
            <div class="card-header">
              <?php echo ($action === 'add_mapping') ? 'Add Document to Folder' : 'Edit Document Mapping'; ?>
            </div>
            <div class="card-body">
              <form method="POST" action="<?= htmlspecialchars($current_page_base_url) ?>?folder_id=<?php echo $folder_id; ?>">
                <?php if ($action === 'edit_mapping'): ?>
                  <input type="hidden" name="action" value="edit_mapping">
                  <input type="hidden" name="mapping_id" value="<?php echo htmlspecialchars($mapping_data['Folder_doc_mapping_id']); ?>">
                  <input type="hidden" name="doc_id_edit" value="<?php echo htmlspecialchars($mapping_data['Doc_id']); ?>">
                <?php else: ?>
                  <input type="hidden" name="action" value="add_doc_to_folder">
                <?php endif; ?>

                <!-- Re-add necessary query parameters to preserve search/filters on form submit -->
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
                  <label for="doc_id" class="form-label">Document Type <span class="text-danger">*</span></label>
                  <?php if ($action === 'add_mapping'): ?>
                    <select class="form-select" id="doc_id" name="doc_id" required>
                      <option value="">-- Select Document --</option>
                      <?php while ($doc_row = $available_docs_result->fetch_assoc()): ?>
                        <option value="<?php echo $doc_row['Doc_id']; ?>"
                          <?php echo (isset($_SESSION['form_data']['doc_id']) && $_SESSION['form_data']['doc_id'] == $doc_row['Doc_id']) ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars($doc_row['Doc_name']); ?>
                        </option>
                      <?php endwhile; if ($available_docs_result instanceof mysqli_result) $available_docs_result->data_seek(0); ?>
                    </select>
                    <?php if(isset($_SESSION['form_data']['doc_id'])) unset($_SESSION['form_data']['doc_id']); ?>
                  <?php else: ?>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($mapping_data['Doc_name']); ?>" readonly>
                  <?php endif; ?>
                </div>

                <div class="mb-3">
                  <label for="submit_before" class="form-label">Submit Before (Optional)</label>
                  <input type="datetime-local" class="form-control" id="submit_before" name="submit_before"
                    value="<?php echo htmlspecialchars($mapping_data['submit_before_formatted'] ?? ($_SESSION['form_data']['submit_before'] ?? '')); ?>">
                    <?php if(isset($_SESSION['form_data']['submit_before'])) unset($_SESSION['form_data']['submit_before']);?>
                </div>

                <div class="mb-3 form-check">
                  <input type="checkbox" class="form-check-input" id="is_mandate" name="is_mandate" value="y"
                    <?php echo (isset($mapping_data['Is_mandate']) && $mapping_data['Is_mandate'] == 'y') || (isset($_SESSION['form_data']['is_mandate'])) ? 'checked' : '';?>>
                  <label class="form-check-label" for="is_mandate">Is Mandatory</label>
                  <?php if(isset($_SESSION['form_data']['is_mandate'])) unset($_SESSION['form_data']['is_mandate']);?>
                </div>

                <div class="mb-3 form-check">
                  <input type="checkbox" class="form-check-input" id="is_active_mapping" name="is_active_mapping" value="y"
                    <?php echo (isset($mapping_data['Mapping_Is_active']) && $mapping_data['Mapping_Is_active'] == 'y') || ($action === 'add_mapping' && !isset($mapping_data['Mapping_Is_active'])) || (isset($_SESSION['form_data']['is_active_mapping'])) ? 'checked' : ''; ?>>
                  <label class="form-check-label" for="is_active_mapping">Mapping Active</label>
                  <?php if(isset($_SESSION['form_data'])) { unset($_SESSION['form_data']['is_active_mapping']); unset($_SESSION['form_data']); } ?>
                </div>

                <?php if ($action === 'add_mapping'): ?>
                  <button type="submit" class="btn btn-primary">Add to Folder</button>
                <?php else: ?>
                  <button type="submit" class="btn btn-primary">Update Mapping</button>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($redirect_to_list_url); ?>" class="btn btn-secondary">Cancel</a>
              </form>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!($action === 'add_mapping' || ($action === 'edit_mapping' && $mapping_data))): // Only show search/filters if not in add/edit mode ?>
          <!-- Search & Filters Area (copied and adapted from documents.php) -->
          <form class="search-area mb-4 d-flex flex-sm-row flex-column align-items-stretch align-items-sm-center gap-2" method="GET" id="searchForm" action="<?= htmlspecialchars($current_page_base_url) ?>">
            <input type="hidden" name="folder_id" value="<?= htmlspecialchars($folder_id) ?>">
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
              <?php foreach ($distinct_mapped_doc_types as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= in_array($type, $selected_doc_types) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
              <?php endforeach; ?>
            </select>

            <!-- Extension Filter (doc_extensions) -->
            <select class="selectpicker" multiple data-live-search="true" name="doc_extensions[]" id="docExtFilter" data-width="fit" title="Filter by Extension">
              <?php foreach ($distinct_mapped_doc_extensions as $ext): ?>
                <option value="<?= htmlspecialchars($ext) ?>" <?= in_array($ext, $selected_doc_extensions) ? 'selected' : '' ?>><?= htmlspecialchars(strtoupper($ext)) ?></option>
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
              <a href="<?= htmlspecialchars(buildMappedDocPaginationQuery(1, $folder_id, null, [], [], null, null)) ?>" class="btn btn-outline-secondary">Clear All Filters</a>
            <?php endif; ?>
          </form>
        <?php endif; ?>

        <h4 class="mt-4">Documents Mapped to "<?php echo htmlspecialchars($folder_name); ?>"</h4>
        <form id="docForm" method="POST" action="<?= htmlspecialchars($current_page_base_url) . '?' . http_build_query($base_query_params) ?>">
          <input type="hidden" name="action" id="formAction">
          <input type="hidden" name="folder_id" value="<?= htmlspecialchars($folder_id) ?>">
          <div class="table-responsive">
            <table class="table table-striped-edu text-nowrap">
              <thead>
                <tr>
                  <th scope="col" style="width: 50px;">
                    <input type="checkbox" id="masterCheckbox">
                  </th>
                  <th>Document Name</th>
                  <th>Extensions</th>
                  <th>Max Size</th>
                  <th>Submit Before</th>
                  <th>Mandatory?</th>
                  <th>Mapping Active?</th>
                  <th>Created By</th>
                  <th style="width: 100px;">Actions</th> <!-- Keep for single edit link, even if basic -->
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($mapped_documents)): ?>
                  <?php foreach ($mapped_documents as $row): ?>
                    <tr>
                      <td><input type="checkbox" name="selected_mappings[]" value="<?= htmlspecialchars($row['Folder_doc_mapping_id']) ?>" class="row-checkbox"></td>
                      <td class="mapping_doc_name_cell"><?php echo htmlspecialchars($row['Doc_name']); ?></td>
                      <td><?php echo htmlspecialchars($row['Doc_ext']); ?></td>
                      <td><?php echo formatSizeUnits($row['Doc_max_size']); ?></td>
                      <td><?php echo $row['Submit_before'] ? date("Y-m-d H:i", strtotime($row['Submit_before'])) : 'N/A'; ?></td>
                      <td>
                        <div class="form-check form-switch d-inline-block">
                          <input class="form-check-input mandate-toggle" type="checkbox" role="switch"
                            id="toggleMandate_<?= $row['Folder_doc_mapping_id'] ?>"
                            data-mapping-id="<?= $row['Folder_doc_mapping_id'] ?>"
                            <?= $row['Is_mandate'] == 'y' ? 'checked' : '' ?>>
                          <label class="form-check-label visually-hidden" for="toggleMandate_<?= $row['Folder_doc_mapping_id'] ?>">Toggle Mandate</label>
                        </div>
                      </td>
                      <td>
                        <div class="form-check form-switch d-inline-block">
                          <input class="form-check-input edu-toggle" type="checkbox" role="switch"
                            id="toggleActive_<?= $row['Folder_doc_mapping_id'] ?>"
                            data-mapping-id="<?= $row['Folder_doc_mapping_id'] ?>"
                            <?= $row['Mapping_Is_active'] == 'y' ? 'checked' : '' ?>>
                          <label class="form-check-label visually-hidden" for="toggleActive_<?= $row['Folder_doc_mapping_id'] ?>">Toggle Active</label>
                        </div>
                      </td>
                      <td><?php echo htmlspecialchars($row['CreatorName'] ?? 'N/A'); ?></td>
                      <td>
                        <!-- Simple Edit link, for single edit modal. Bulk edit is preferred -->
                        <a href="<?= htmlspecialchars($current_page_base_url) ?>?folder_id=<?php echo $folder_id; ?>&action=edit_mapping&mapping_id=<?php echo $row['Folder_doc_mapping_id']; ?>&<?= http_build_query($base_query_params) ?>" class="btn btn-sm btn-warning">Edit</a>
                      </td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr><td colspan="9" class="text-center">No documents are currently mapped to this folder matching your filters.</td></tr>
              <?php endif; ?>
            </tbody>
            </table>
          </div>
        </form>

        <!-- Pagination Stats -->
        <div class="d-flex justify-content-between align-items-center mt-3 mb-2">
          <?php if ($total_mapped_documents > 0): ?>
            <p class="text-muted mb-0">Showing <?= $start_record ?> to <?= $end_record ?> of <?= $total_mapped_documents ?> total records.</p>
          <?php else: ?>
            <p class="text-muted mb-0">No records found matching your criteria.</p>
          <?php endif; ?>
        </div>

        <!-- Pagination -->
        <nav aria-label="Page navigation" class="mt-4">
          <ul class="pagination justify-content-center">
            <li class="page-item <?= ($current_page <= 1) ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= htmlspecialchars(buildMappedDocPaginationQuery($current_page - 1, $folder_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size)) ?>">Previous</a>
            </li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
              <li class="page-item <?= ($i == $current_page) ? 'active' : '' ?>">
                <a class="page-link" href="<?= htmlspecialchars(buildMappedDocPaginationQuery($i, $folder_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size)) ?>"><?= $i ?></a>
              </li>
            <?php endfor; ?>
            <li class="page-item <?= ($current_page >= $total_pages) ? 'disabled' : '' ?>">
              <a class="page-link" href="<?= htmlspecialchars(buildMappedDocPaginationQuery($current_page + 1, $folder_id, $search_query, $selected_doc_types, $selected_doc_extensions, $min_max_size, $max_max_size)) ?>">Next</a>
            </li>
          </ul>
        </nav>

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

  <!-- Footer (copied from documents.php) -->
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

      // Navbar padding adjustment (copied from documents.php)
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

      // --- Toast Notification Logic (copied from documents.php) ---
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

      // --- CRUD Button Logic for Mappings ---
      const docForm = document.getElementById('docForm');
      const formAction = document.getElementById('formAction');
      const masterCheckbox = document.getElementById('masterCheckbox');
      const tableBody = document.querySelector('.table-striped-edu tbody');
      const rowCheckboxes = () => tableBody.querySelectorAll('.row-checkbox');

      const editMappingBtn = document.getElementById('editMappingBtn');
      const activateMappingBtn = document.getElementById('activateMappingBtn');
      const deactivateMappingBtn = document.getElementById('deactivateMappingBtn');
      const mandateMappingBtn = document.getElementById('mandateMappingBtn');
      const unmandateMappingBtn = document.getElementById('unmandateMappingBtn');
      const hardDeleteMappingBtn = document.getElementById('hardDeleteMappingBtn');
      const selectAllBtn = document.getElementById('selectAllBtn');

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

        editMappingBtn.disabled = !(checkedCount === 1); // Edit only enabled for single selection
        activateMappingBtn.disabled = !(checkedCount > 0);
        deactivateMappingBtn.disabled = !(checkedCount > 0);
        mandateMappingBtn.disabled = !(checkedCount > 0);
        unmandateMappingBtn.disabled = !(checkedCount > 0);
        hardDeleteMappingBtn.disabled = !(checkedCount > 0);

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

      editMappingBtn.addEventListener('click', function() {
        const selectedCheckbox = document.querySelector('.row-checkbox:checked');
        if (selectedCheckbox) {
          const mappingId = selectedCheckbox.value;

          // Build base query parameters for redirect, excluding the 'action' and 'mapping_id'
          const urlParams = new URLSearchParams(window.location.search);
          urlParams.delete('action');
          urlParams.delete('mapping_id');
          const baseQueryParamsString = urlParams.toString();
           
          // Redirect to the edit mapping page
          window.location.href = `<?= htmlspecialchars($current_page_base_url) ?>?folder_id=<?= htmlspecialchars($folder_id) ?>&action=edit_mapping&mapping_id=${mappingId}${baseQueryParamsString ? '&' + baseQueryParamsString : ''}`;
        } else {
          showToast('info', 'Please select one document mapping to edit.');
        }
      });

      activateMappingBtn.addEventListener('click', function() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount > 0) {
          currentAction = 'bulk_activate_mapping';
          confirmationModalHeader.className = ('modal-header bg-success text-white');
          confirmationModalLabel.textContent = `Confirm Activation`;
          confirmationModalBody.innerHTML = `Are you sure you want to activate <strong>${checkedCount}</strong> selected document mapping(s)?`; // MODIFIED: Added checkedCount
          confirmActionButton.className = ('btn btn-success');
          confirmationModal.show();
        } else {
          showToast('info', 'Please select at least one document mapping to activate.');
        }
      });

      deactivateMappingBtn.addEventListener('click', function() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount > 0) {
          currentAction = 'bulk_deactivate_mapping';
          confirmationModalHeader.className = ('modal-header bg-danger text-white');
          confirmationModalLabel.textContent = `Confirm Deactivation`;
          confirmationModalBody.innerHTML = `Are you sure you want to deactivate <strong>${checkedCount}</strong> selected document mapping(s)? They will no longer be visible unless explicitly managed.`; // MODIFIED: Added checkedCount
          confirmActionButton.className = ('btn btn-danger');
          confirmationModal.show();
        } else {
          showToast('info', 'Please select at least one document mapping to deactivate.');
        }
      });

      mandateMappingBtn.addEventListener('click', function() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount > 0) {
          currentAction = 'bulk_mandate_mapping';
          confirmationModalHeader.className = ('modal-header bg-warning text-dark'); // Changed to warning for orange
          confirmationModalLabel.textContent = `Confirm Mandate`;
          confirmationModalBody.innerHTML = `Are you sure you want to mark <strong>${checkedCount}</strong> selected document mapping(s) as MANDATORY?`; // MODIFIED: Added checkedCount
          confirmActionButton.className = ('btn btn-warning');
          confirmationModal.show();
        } else {
          showToast('info', 'Please select at least one document mapping to mark as mandatory.');
        }
      });

      unmandateMappingBtn.addEventListener('click', function() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount > 0) {
          currentAction = 'bulk_unmandate_mapping';
          confirmationModalHeader.className = ('modal-header bg-secondary text-white');
          confirmationModalLabel.textContent = `Confirm Unmandate`;
          confirmationModalBody.innerHTML = `Are you sure you want to mark <strong>${checkedCount}</strong> selected document mapping(s) as NOT MANDATORY?`; // MODIFIED: Added checkedCount
          confirmActionButton.className = ('btn btn-secondary');
          confirmationModal.show();
        } else {
          showToast('info', 'Please select at least one document mapping to unmark as mandatory.');
        }
      });

      hardDeleteMappingBtn.addEventListener('click', function() {
        const checkedCount = document.querySelectorAll('.row-checkbox:checked').length;
        if (checkedCount > 0) {
          currentAction = 'bulk_hard_delete_mapping';
          confirmationModalHeader.className = ('modal-header bg-danger text-white');
          confirmationModalLabel.textContent = `Confirm PERMANENT Deletion`;
          confirmationModalBody.innerHTML = `
            <p><strong>WARNING: This action cannot be undone.</strong></p>
            <p>Are you absolutely sure you want to PERMANENTLY delete <strong>${checkedCount}</strong> selected document mapping(s)? This will remove them from the database.</p> <!-- MODIFIED: Added checkedCount -->
          `;
          confirmActionButton.className = ('btn btn-danger');
          confirmationModal.show();
        } else {
          showToast('info', 'Please select at least one document mapping for permanent deletion.');
        }
      });


      confirmActionButton.addEventListener('click', function() {
        confirmationModal.hide();
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
          const mappingId = this.dataset.mappingId;
          const isActive = this.checked ? 'y' : 'n';
          const originalState = !this.checked ? 'y' : 'n'; // Store original state for rollback
          const folderId = <?= json_encode($folder_id) ?>; // Pass folder_id from PHP to JS

          fetch('<?= $admin_doc_folder_mapping ?>', { // MODIFIED: Use variable from links.php
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=toggle_active_mapping&mapping_id=${mappingId}&is_active=${isActive}&folder_id=${folderId}` // MODIFIED: Corrected template literals
          })
          .then(response => {
            // Check if HTTP response is OK (status 200-299)
            if (!response.ok) {
              // If not OK, try to parse JSON for server-side error message
              return response.json().then(errData => {
                throw new Error(errData.message || 'Server responded with an error status: ' + response.status);
              }).catch((e) => {
                console.error("Failed to parse error response as JSON:", e);
                // If it's not JSON, or parsing fails, throw a generic network error
                throw new Error('Network response was not ok. Status: ' + response.status + (e.message ? ' - ' + e.message : ''));
              });
            }
            return response.json(); // Safely parse JSON if response is OK
          })
          .then(data => {
            if (data.success) {
              showToast('success', data.message);
            } else {
              // Show specific error message from server
              showToast('danger', data.message);
              this.checked = (originalState === 'y'); // Rollback on failure
            }
          })
          .catch(error => {
            console.error('Fetch Error:', error);
            // This is the generic error message displayed when the fetch operation itself fails
            showToast('danger', error.message || 'An error occurred while updating status. Please try again.');
            this.checked = (originalState === 'y'); // Rollback on network/fetch error
          });
        });
      });

      // --- Single Toggle Mandate State (AJAX) ---
      document.querySelectorAll('.mandate-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
          const mappingId = this.dataset.mappingId;
          const isMandate = this.checked ? 'y' : 'n';
          const originalState = !this.checked ? 'y' : 'n'; // Store original state for rollback
          const folderId = <?= json_encode($folder_id) ?>; // Pass folder_id from PHP to JS

          fetch('<?= $admin_doc_folder_mapping ?>', { // MODIFIED: Use variable from links.php
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: `action=toggle_mandate_mapping&mapping_id=${mappingId}&is_mandate=${isMandate}&folder_id=${folderId}` // MODIFIED: Corrected template literals
          })
          .then(response => {
            // Check if HTTP response is OK (status 200-299)
            if (!response.ok) {
              // If not OK, try to parse JSON for server-side error message
              return response.json().then(errData => {
                throw new Error(errData.message || 'Server responded with an error status: ' + response.status);
              }).catch((e) => {
                console.error("Failed to parse error response as JSON:", e);
                // If it's not JSON, or parsing fails, throw a generic network error
                throw new Error('Network response was not ok. Status: ' + response.status + (e.message ? ' - ' + e.message : ''));
              });
            }
            return response.json(); // Safely parse JSON if response is OK
          })
          .then(data => {
            if (data.success) {
              showToast('success', data.message);
            } else {
              // Show specific error message from server
              showToast('danger', data.message);
              this.checked = (originalState === 'y'); // Rollback on failure
            }
          })
          .catch(error => {
            console.error('Fetch Error:', error);
            // This is the generic error message displayed when the fetch operation itself fails
            showToast('danger', error.message || 'An error occurred while updating mandate status. Please try again.');
            this.checked = (originalState === 'y'); // Rollback on network/fetch error
          });
        });
      });


      // --- Clear Search Input Button Logic (copied from documents.php) ---
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
      $('#docTypeFilter').on('changed.bs.select', function (e, clickedIndex, isSelected, oldValue) {
        document.getElementById('searchForm').submit();
      });
      $('#docExtFilter').on('changed.bs.select', function (e, clickedIndex, isSelected, oldValue) {
        document.getElementById('searchForm').submit();
      });
    });
  </script>
</body>
</html>