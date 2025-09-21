<?php require_once 'db.php'; // Ensures DB connection and session start ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Management System</title>
    <link href="<?php echo BASE_URL; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo BASE_URL; ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
    <div class="container">
        <a class="navbar-brand" href="<?php echo BASE_URL; ?>/index.php">DocSys</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($_SESSION['uid'])): ?>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/admin/dashboard.php">Admin Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/admin/documents.php">Manage Docs</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/admin/folders.php">Manage Folders</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/admin/users.php">Manage Users</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/admin/submissions.php">Submissions</a></li>
                    <?php elseif ($_SESSION['role'] == 'student'): ?>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/student/dashboard.php">My Dashboard</a></li>
                        <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/student/profile.php">My Profile</a></li>
                    <?php endif; ?>
                    <li class="nav-item"><span class="nav-link text-white">Welcome, <?php echo htmlspecialchars($_SESSION['name']); ?>!</span></li>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/logout.php">Logout</a></li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?php echo BASE_URL; ?>/login.php">Login</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container">
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message_type']; ?> alert-dismissible fade show" role="alert">
            <?php echo $_SESSION['flash_message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_message_type']);
        ?>
    <?php endif; ?>