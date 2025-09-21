<?php
require_once 'includes/functions.php'; // For redirect and session status
require_once 'includes/db.php'; // For BASE_URL

if (session_status() !== PHP_SESSION_NONE) {
    $_SESSION = array(); // Clear all session variables
    if (ini_get("session.use_cookies")) { // Destroy cookie
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
set_flash_message('You have been logged out successfully.', 'success');
redirect($BASE_URL.'login.php');
?>