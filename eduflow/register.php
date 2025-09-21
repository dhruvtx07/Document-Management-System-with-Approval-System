<?php
// register.php
require_once 'includes/db.php';
require_once 'includes/functions.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) redirect('/admin/dashboard.php');
    if (isStudent()) redirect('/student/dashboard.php');
    redirect('/index.php');
}

$name = '';
$email = '';
$phone = '';
$email_exists = false;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Basic validation
    $errors = [];
    if (empty($name)) $errors[] = "Full name is required";
    if (empty($email)) $errors[] = "Email is required";
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (!empty($phone) && !preg_match('/^[0-9]{7,15}$/', $phone)) $errors[] = "Invalid phone number";
    if (empty($password)) $errors[] = "Password is required";
    elseif (strlen($password) < 6) $errors[] = "Password must be at least 6 characters";
    if ($password !== $confirm_password) $errors[] = "Passwords don't match";

    if (empty($errors)) {
        // Check if email exists
        $stmt = $conn->prepare("SELECT Uid FROM Users WHERE Email = ?");
        if ($stmt) {
            $stmt->bind_param("s", $email);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $email_exists = true;
            } else {
                // Register new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert = $conn->prepare("INSERT INTO Users (Name, Email, Phone, Pwd, Is_active, Created_at) VALUES (?, ?, ?, ?, 'y', NOW())");
                if ($insert && $insert->bind_param("ssss", $name, $email, $phone, $hashed_password) && $insert->execute()) {
                    echo '<script>alert("Registration successful! You can now login."); window.location.href="login.php";</script>';
                    exit;
                }
            }
            $stmt->close();
        }
    } else {
        $error_message = implode("\\n", $errors);
        echo "<script>alert('$error_message');</script>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - EduFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #eef5ff; font-family: 'Segoe UI', sans-serif; }
        .auth-card { background: white; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.08); max-width: 520px; }
        .btn-primary { background: #0d6efd; border-radius: 8px; padding: 10px 15px; }
        .form-control { border-radius: 8px; padding: 0.75rem 1rem; }
    </style>
</head>
<body>
    <div class="container-fluid d-flex justify-content-center align-items-center min-vh-100">
        <div class="card auth-card col-12 col-md-8 col-lg-6">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="logo-container mb-4">
                    <img src="<?= htmlspecialchars(BASE_URL . 'logo.png') ?>" alt="Logo" width="140">
                </div>
                <h3 class="fw-bold text-primary mb-4">Create Your Account</h3>
                
                <form method="POST">
                    <div class="mb-3 text-start">
                        <label class="form-label">Full Name</label>
                        <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($name) ?>" required>
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" value="<?= htmlspecialchars($email) ?>" required>
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label">Phone (optional)</label>
                        <input type="tel" class="form-control" name="phone" value="<?= htmlspecialchars($phone) ?>">
                    </div>
                    <div class="mb-3 text-start">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" required minlength="6">
                    </div>
                    <div class="mb-4 text-start">
                        <label class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>
                    <button type="submit" class="btn btn-primary w-100 mb-3">Register</button>
                </form>
                
                <div class="text-center">
                    <p class="mb-0">Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </div>
        </div>
    </div>

    <script>
        <?php if ($email_exists): ?>
        window.onload = function() {
            alert("This email is already registered. Please login instead.");
            document.querySelector('input[name="email"]').focus();
        };
        <?php endif; ?>
    </script>
</body>
</html>