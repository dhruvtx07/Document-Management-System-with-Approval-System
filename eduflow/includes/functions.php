<?php
// functions.php

// Ensure session_start() is called if not already started,
// as some functions might rely on session variables (like flash messages).
// It's generally good practice to have session_start() at the very top
// of files that require sessions, or in a common include like db.php.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Function to set flash messages
function set_flash_message($message, $type = 'success') {
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_message_type'] = $type;
}

// Function to redirect
function redirect($url) {
    // Check if it's a fully qualified URL (starts with http/https)
    // Using substr for broad PHP compatibility.
    if (substr($url, 0, 7) === 'http://' || substr($url, 0, 8) === 'https://') {
        header("Location: " . $url);
        exit();
    }

    // Ensure BASE_URL is defined
    if (!defined('BASE_URL')) {
        error_log("BASE_URL not defined in redirect function. URL: " . $url);
        header("Location: " . $url); // Fallback: try direct redirect, might be wrong path.
        exit();
    }

    $final_url = '';
    // BASE_URL is like /eduflow/
    // Get the path segment from BASE_URL (e.g., '/eduflow' from '/eduflow/')
    $base_path_segment = rtrim(BASE_URL, '/'); 

    // Check if $url already starts with the normalized BASE_URL path segment.
    // This handles cases like $_SERVER['PHP_SELF'] which is already an absolute path
    // from the web server's document root that includes the base directory.
    if (substr($url, 0, strlen($base_path_segment)) === $base_path_segment) {
        $final_url = $url;
    } elseif (substr($url, 0, 1) === '/') {
        // If it starts with / but not the BASE_URL path segment, treat as an absolute path from server root.
        // This is a general absolute path (e.g., /another_app/some_file.php)
        $final_url = $url;
    } else {
        // Otherwise, it's a path relative to BASE_URL (e.g., 'admin/dashboard.php' or 'dashboard.php').
        // Prepend BASE_URL.
        $final_url = rtrim(BASE_URL, '/') . '/' . ltrim($url, '/');
    }
    
    header("Location: " . $final_url);
    exit();
}

// Function to check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['uid']);
}

// Function to get current user's ID
function getCurrentUserId() {
    return $_SESSION['uid'] ?? null;
}

// Function to get current user's college ID
function getCurrentUserClgId() {
    // For admins and students, Clg_id is set during login.
    return $_SESSION['clg_id'] ?? null;
}

// NEW: Function to get current user's Class ID
// This is specifically for student users. Admins do not have a Class_id.
function getCurrentUserClassId() {
    return $_SESSION['class_id'] ?? null; // Will be null for admins, which is correct.
}

// Function to check if current user is admin
function isAdmin() {
    return (isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] == 'admin');
}

// Function to check if current user is student
function isStudent() {
    return (isLoggedIn() && isset($_SESSION['role']) && $_SESSION['role'] == 'student');
}

// Helper function for preparing SQL statements to prevent SQL injection
// $conn should be your MySQLi connection object
function prepare_and_execute($conn, $sql, $params = [], $types = "") {
    if (!$conn) {
        error_log("Database connection not available in prepare_and_execute for SQL: " . $sql);
        die("A critical database connection error occurred.");
    }

    $stmt = $conn->prepare($sql);
    if ($stmt === false) {
        // IMPORTANT: In a production environment, you should log the actual error
        // but avoid exposing sensitive database error details to the user.
        error_log("Prepare failed: (" . $conn->errno . ") " . $conn->error . " SQL: " . $sql);
        die("An internal database error occurred during preparation."); 
    }
    if (!empty($params) && !empty($types)) {
        // The `...$params` syntax requires PHP 5.6+ or later.
        // It's the most common way to bind parameters in modern PHP.
        try {
            if (!$stmt->bind_param($types, ...$params)) {
                 error_log("Parameter binding failed: (" . $stmt->errno . ") " . $stmt->error . " for SQL: " . $sql . " with types: " . $types . " and params: " . json_encode($params));
                 die("An internal error occurred during parameter binding.");
            }
        } catch (TypeError $e) {
            error_log("Parameter binding type error: " . $e->getMessage() . " for SQL: " . $sql . " with types: " . $types . " and params: " . json_encode($params));
            die("An internal error occurred during parameter binding. Please check logs.");
        }
    }
    if (!$stmt->execute()) {
        // IMPORTANT: Log the error, don't display to user in production
        error_log("Execute failed: (" . $stmt->errno . ") " . $stmt->error . " for SQL: " . $sql . " with types: " . $types . " and params: " . json_encode($params));
        die("An internal database error occurred during execution.");
    }
    return $stmt;
}

// Function to format bytes to readable string
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
?>