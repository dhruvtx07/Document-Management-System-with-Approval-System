<?php
// initial_setup.php

// !!! IMPORTANT SECURITY WARNING !!!
// This file is designed for one-time initial setup only.
// It bypasses all authentication and allows creating colleges and admins.
// You MUST DELETE or heavily restrict access to this file immediately after use
// in any production environment. Leaving it accessible poses a severe security risk.

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Paths are relative to this file's location.
// Assuming this file is in the same directory as 'admin/' or at the root level.
// Adjust path if 'includes' directory is elsewhere.
require_once __DIR__ . '/includes/db.php'; // Your database connection ($conn)
require_once __DIR__ . '/includes/functions.php'; // Contains prepare_and_execute etc.

// Define the Super Admin User ID for created_by fields
define('SUPER_ADMIN_UID', 1);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Initial Setup Script</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; color: #333; }
        .container { background-color: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
        h1, h2 { color: #0056b3; }
        pre { background-color: #e9e9e9; padding: 10px; border-radius: 4px; overflow-x: auto; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .notice { color: orange; font-weight: bold; }
        label { display: block; margin-top: 10px; font-weight: bold; }
        input[type='text'], input[type='email'], input[type='password'], select {
            width: calc(100% - 22px); padding: 10px; margin-top: 5px; border: 1px solid #ddd; border-radius: 4px;
        }
        input[type='submit'] {
            background-color: #007bff; color: white; padding: 12px 20px; border: none; border-radius: 4px;
            cursor: pointer; margin-top: 15px; font-size: 16px;
        }
        input[type='submit']:hover { background-color: #0056b3; }
        form { margin-bottom: 30px; border: 1px solid #eee; padding: 20px; border-radius: 8px; }
        .debug { background-color: #f0f8ff; padding: 10px; border-left: 5px solid #add8e6; margin-top: 15px; }
    </style>
</head>
<body>
    <div class='container'>";

echo "<h1>EduFlow Initial Setup</h1>";
echo "<p>This script allows you to create initial colleges and admins. <strong>Please be very cautious when using this file.</strong></p>";

// Ensure database connection is established
if (!$conn) {
    die("<p class='error'>Database connection failed in initial_setup.php. Please check db.php.</p></div></body></html>");
}

/**
 * Creates a new college entry in the 'clgs' table.
 * @param mysqli $conn The database connection.
 * @param string $clg_name College name.
 * @param string $clg_address College address.
 * @param string $clg_city College city.
 * @param string $clg_state College state.
 * @param string $clg_country College country.
 * @return int|bool The new Clg_id on success, or false on failure.
 */
function createCollege($conn, $clg_name, $clg_address, $clg_city, $clg_state, $clg_country) {
    $sql = "INSERT INTO clgs (Clg_name, Clg_adress, Clg_city, Clg_state, Clg_country, Created_by, User_count, Admin_count, Teacher_count, Student_count, Is_active)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, 0, 'y')"; // Initial counts are 0, Is_active 'y'
    $params = [$clg_name, $clg_address, $clg_city, $clg_state, $clg_country, SUPER_ADMIN_UID];
    $types = "sssssi"; // 5 strings, 1 integer

    try {
        // Use the prepare_and_execute function from functions.php
        $stmt = prepare_and_execute($conn, $sql, $params, $types);
        if ($stmt->affected_rows > 0) {
            $new_clg_id = $stmt->insert_id;
            $stmt->close();
            return $new_clg_id;
        } else {
            $stmt->close();
            return false;
        }
    } catch (Exception $e) {
        error_log("Error creating college: " . $e->getMessage());
        return false;
    }
}

/**
 * Creates a new user in 'Users' table and assigns them an 'Admin' role.
 * @param mysqli $conn The database connection.
 * @param int $clg_id The College ID this admin belongs to.
 * @param string $name User's full name.
 * @param string $email User's email (must be unique).
 * @param string $phone User's phone number.
 * @param string $password User's password (will be hashed).
 * @return int|bool The new Uid on success, or false on failure.
 */
function createAdmin($conn, $clg_id, $name, $email, $phone, $password) {
    // Basic validation
    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        return false;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "<p class='error'>Invalid email format for admin creation.</p>";
        return false;
    }
    if (strlen($password) < 6) {
        echo "<p class='error'>Password must be at least 6 characters long for admin creation.</p>";
        return false;
    }

    $conn->begin_transaction();
    try {
        // 1. Check if email already exists in Users
        $stmt_check = prepare_and_execute($conn, "SELECT Uid FROM Users WHERE Email = ?", [$email], "s");
        if ($stmt_check->get_result()->num_rows > 0) {
            $stmt_check->close();
            throw new Exception("Email '$email' already exists in Users table. Cannot create duplicate user.");
        }
        $stmt_check->close();

        // 2. Insert into Users table
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $sql_user = "INSERT INTO Users (Name, Email, Phone, Pwd, Is_active) VALUES (?, ?, ?, ?, 'y')";
        // Use the prepare_and_execute function from functions.php
        $stmt_user = prepare_and_execute($conn, $sql_user, [$name, $email, $phone, $hashed_password], "ssss");
        $new_user_id = $stmt_user->insert_id;
        $stmt_user->close();

        if (!$new_user_id) {
            throw new Exception("Failed to insert user into Users table.");
        }

        // 3. Insert into Admin table
        $sql_admin = "INSERT INTO Admin (Uid, Clg_id, Created_by, Is_active) VALUES (?, ?, ?, 'y')";
        // Use the prepare_and_execute function from functions.php
        $stmt_admin = prepare_and_execute($conn, $sql_admin, [$new_user_id, $clg_id, SUPER_ADMIN_UID], "iii");
        if ($stmt_admin->affected_rows <= 0) {
            throw new Exception("Failed to insert admin into Admin table.");
        }
        $stmt_admin->close();

        // 4. Update Admin_count in clgs table
        $sql_update_clgs = "UPDATE clgs SET Admin_count = Admin_count + 1, User_count = User_count + 1 WHERE Clg_id = ?";
        // Use the prepare_and_execute function from functions.php
        $stmt_update_clgs = prepare_and_execute($conn, $sql_update_clgs, [$clg_id], "i");
        if ($stmt_update_clgs->affected_rows <= 0) {
            throw new Exception("Failed to update Admin_count for college ID $clg_id.");
        }
        $stmt_update_clgs->close();

        $conn->commit();
        return $new_user_id;

    } catch (Exception $e) {
        $conn->rollback();
        echo "<p class='error'>Admin creation failed: " . htmlspecialchars($e->getMessage()) . "</p>";
        return false;
    }
}

// --- Display Forms for Entry ---

echo "<h2>Create New College</h2>";
echo "<form method='POST'>
    <input type='hidden' name='action' value='create_college'>
    <label for='clg_name'>College Name:</label>
    <input type='text' id='clg_name' name='clg_name' required>
    <label for='clg_address'>Address:</label>
    <input type='text' id='clg_address' name='clg_address' required>
    <label for='clg_city'>City:</label>
    <input type='text' id='clg_city' name='clg_city' required>
    <label for='clg_state'>State:</label>
    <input type='text' id='clg_state' name='clg_state' required>
    <label for='clg_country'>Country:</label>
    <input type='text' id='clg_country' name='clg_country' required>
    <input type='submit' value='Create College'>
</form>";

echo "<h2>Create New Admin</h2>";
echo "<form method='POST'>
    <input type='hidden' name='action' value='create_admin'>
    <label for='admin_clg_id'>Assign to College ID:</label>
    <input type='text' id='admin_clg_id' name='admin_clg_id' placeholder='e.g., 1' required>
    <label for='admin_name'>Admin Name:</label>
    <input type='text' id='admin_name' name='admin_name' required>
    <label for='admin_email'>Admin Email:</label>
    <input type='email' id='admin_email' name='admin_email' required>
    <label for='admin_phone'>Admin Phone:</label>
    <input type='text' id='admin_phone' name='admin_phone' required>
    <label for='admin_password'>Admin Password:</label>
    <input type='password' id='admin_password' name='admin_password' pattern='.{6,}' title='Minimum 6 characters' required>
    <input type='submit' value='Create Admin'>
</form>";

// --- Handle Form Submissions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        echo "<h2>Results:</h2>";
        if ($_POST['action'] === 'create_college') {
            $clg_name = trim($_POST['clg_name'] ?? '');
            $clg_address = trim($_POST['clg_address'] ?? '');
            $clg_city = trim($_POST['clg_city'] ?? '');
            $clg_state = trim($_POST['clg_state'] ?? '');
            $clg_country = trim($_POST['clg_country'] ?? '');

            if (empty($clg_name) || empty($clg_address) || empty($clg_city) || empty($clg_state) || empty($clg_country)) {
                echo "<p class='error'>All college fields are required.</p>";
            } else {
                $new_clg_id = createCollege($conn, $clg_name, $clg_address, $clg_city, $clg_state, $clg_country);
                if ($new_clg_id) {
                    echo "<p class='success'>College '<strong>" . htmlspecialchars($clg_name) . "</strong>' created successfully with Clg_id: <strong>" . $new_clg_id . "</strong></p>";
                } else {
                    echo "<p class='error'>Failed to create college '<strong>" . htmlspecialchars($clg_name) . "</strong>'.</p>";
                }
            }
        } elseif ($_POST['action'] === 'create_admin') {
            $admin_clg_id = filter_var($_POST['admin_clg_id'] ?? '', FILTER_VALIDATE_INT);
            $admin_name = trim($_POST['admin_name'] ?? '');
            $admin_email = trim($_POST['admin_email'] ?? '');
            $admin_phone = trim($_POST['admin_phone'] ?? '');
            $admin_password = $_POST['admin_password'] ?? ''; // Don't trim password

            if ($admin_clg_id === false || empty($admin_name) || empty($admin_email) || empty($admin_phone) || empty($admin_password)) {
                echo "<p class='error'>All admin fields are required and College ID must be numeric.</p>";
            } else {
                // Use prepare_and_execute function from functions.php
                $check_clg_sql = "SELECT Clg_id FROM clgs WHERE Clg_id = ?";
                $stmt_clg = prepare_and_execute($conn, $check_clg_sql, [$admin_clg_id], "i");
                if ($stmt_clg->get_result()->num_rows === 0) {
                    echo "<p class='error'>College with ID " . htmlspecialchars($admin_clg_id) . " does not exist. Please create the college first.</p>";
                    $stmt_clg->close();
                } else {
                    $stmt_clg->close();
                    $new_admin_uid = createAdmin($conn, $admin_clg_id, $admin_name, $admin_email, $admin_phone, $admin_password);
                    if ($new_admin_uid) {
                        echo "<p class='success'>Admin '<strong>" . htmlspecialchars($admin_name) . "</strong>' created and assigned to Clg_id <strong>" . htmlspecialchars($admin_clg_id) . "</strong> with Uid: <strong>" . $new_admin_uid . "</strong>.</p>";
                        echo "<p class='notice'><strong>Make sure this admin's email and password are noted for future login.</strong></p>";
                    } else {
                        // Error message handled within createAdmin function
                    }
                }
            }
        }
    }
}

echo "</div></body></html>";

$conn->close();
?>