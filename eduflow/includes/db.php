<?php
// db.php
// This MUST BE THE VERY FIRST PHP CODE in the file, no spaces or newlines before "<?php"
// It ensures the session is started properly when db.php is required.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$db_host = "localhost";
$db_user = "root"; // Replace with your DB username
$db_pass = "";      // Replace with your DB password
$db_name = "doc_MG";

$conn = new mysqli($db_host, $db_user, $db_pass, $db_name);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Ensure BASE_URL is defined here, as this is often included first.
if (!defined('BASE_URL')) {
    define('BASE_URL', '/eduflow/'); // IMPORTANT: Use a trailing slash for consistency
    // If your project is at http://localhost/ (root), use:
    // define('BASE_URL', '/');
}
?>