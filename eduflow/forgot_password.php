<?php
// forgot_password.php
require_once 'includes/db.php';
require_once 'includes/functions.php';

// --- PHPMailer ---
require_once __DIR__ . '/includes/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/includes/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/includes/PHPMailer/src/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// ---- Helper functions ----
if (!function_exists('generateOTP')) {
    function generateOTP($length = 6) {
        if (function_exists('random_int')) {
            return str_pad(random_int(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(ceil($length / 2));
            return substr(bin2hex($bytes), 0, $length);
        }
        $characters = '0123456789';
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= $characters[rand(0, strlen($characters) - 1)];
        }
        return $otp;
    }
}

if (!function_exists('sendPasswordResetEmail')) {
    function sendPasswordResetEmail($toEmail, $toName, $subject, $body) {
        $mailHost = 'smtp.gmail.com';
        $mailPort = 587;
        $mailUsername = 'official.docsmg@gmail.com';
        $mailPassword = 'ovmp ptdf ieyl dhqo';
        $mailFromEmail = 'official.docsmg@gmail.com';
        $mailSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailFromName = 'EduFlow Support';

        $mail = new PHPMailer(true);
        try {
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->isSMTP();
            $mail->Host       = $mailHost;
            $mail->SMTPAuth   = true;
            $mail->Username   = $mailUsername;
            $mail->Password   = $mailPassword;
            $mail->SMTPSecure = $mailSecure; 
            $mail->Port       = $mailPort;

            $mail->setFrom($mailFromEmail, $mailFromName);
            $mail->addAddress($toEmail, $toName); 

            $mail->isHTML(true); 
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body); 

            $mail->send();
            return true;
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            error_log("EduFlow Mailer Error: {$mail->ErrorInfo} (Recipient: {$toEmail})");
            return false;
        }
    }
}

// --- Main page logic ---
if (isLoggedIn()) {
    if (isAdmin()) redirect('/admin/dashboard.php');
    if (isStudent()) redirect('/student/dashboard.php');
    redirect('/index.php');
}

$current_step = 'request_email';
if (isset($_SESSION['reset_otp_user_id'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_hashed'], $_SESSION['reset_otp_expiry']) && time() < $_SESSION['reset_otp_expiry']) {
    $current_step = 'verify_otp';
} elseif (isset($_SESSION['reset_otp_user_id'])) { 
    unset($_SESSION['reset_otp_user_id'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_hashed'], $_SESSION['reset_otp_expiry']);
    set_flash_message("Your previous OTP has expired or was invalid. Please request a new one.", "warning");
    $current_step = 'request_email'; 
}

if (isset($_GET['action']) && $_GET['action'] == 'reset_flow') {
    unset($_SESSION['reset_otp_user_id'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_hashed'], $_SESSION['reset_otp_expiry']);
    set_flash_message("Please enter your email to restart the password reset process.", "info");
    redirect('/forgot_password.php');
    exit;
}

$show_alert = false;
$alert_message = '';
$alert_type = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'] ?? '';

    if ($action == 'send_otp') {
        $email = trim($_POST['email'] ?? '');
        
        if (empty($email)) {
            $show_alert = true;
            $alert_message = "Please enter your email address.";
            $alert_type = "error";
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $show_alert = true;
            $alert_message = "Please enter a valid email address.";
            $alert_type = "error";
        } else {
            $stmt = $conn->prepare("SELECT Uid, Name FROM Users WHERE Email = ? AND Is_active = 'y'");
            if (!$stmt) {
                error_log("EduFlow Forgot Password (Send OTP) Prepare Failed: " . $conn->error);
                $show_alert = true;
                $alert_message = "A system error occurred. Please try again later.";
                $alert_type = "error";
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $user = $result->fetch_assoc();
                    $otp = generateOTP();
                    $_SESSION['reset_otp_user_id'] = $user['Uid'];
                    $_SESSION['reset_otp_email'] = $email; 
                    $_SESSION['reset_otp_hashed'] = password_hash($otp, PASSWORD_DEFAULT);
                    $_SESSION['reset_otp_expiry'] = time() + (10 * 60); 

                    $subject = 'EduFlow: Password Reset OTP';
                    $body = "<p>Dear {$user['Name']},</p>"
                          . "<p>Your One-Time Password (OTP) for EduFlow password reset is: <strong>{$otp}</strong></p>"
                          . "<p>This OTP is valid for 10 minutes.</p>"
                          . "<p>If you did not request this, please ignore this email.</p>"
                          . "<p>Regards,<br>The EduFlow Team</p>";
                    
                    if (sendPasswordResetEmail($email, $user['Name'], $subject, $body)) {
                        $show_alert = true;
                        $alert_message = "OTP sent to your email!";
                        $alert_type = "success";
                    } else {
                        unset($_SESSION['reset_otp_user_id'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_hashed'], $_SESSION['reset_otp_expiry']);
                        $show_alert = true;
                        $alert_message = "Failed to send OTP. Please try again later.";
                        $alert_type = "error";
                    }
                } else {
                    $show_alert = true;
                    $alert_message = "No active account found with this email.";
                    $alert_type = "error";
                }
                $stmt->close();
            }
        }
    } elseif ($action == 'reset_password' && $current_step == 'verify_otp') {
        $submitted_otp = trim($_POST['otp'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_new_password'] ?? '';

        if (empty($submitted_otp) || empty($new_password) || empty($confirm_password)) {
            $show_alert = true;
            $alert_message = "All fields are required!";
            $alert_type = "error";
        } elseif (strlen($new_password) < 6) { 
            $show_alert = true;
            $alert_message = "Password must be at least 6 characters!";
            $alert_type = "error";
        } elseif ($new_password !== $confirm_password) {
            $show_alert = true;
            $alert_message = "Passwords don't match!";
            $alert_type = "error";
        } elseif (time() > ($_SESSION['reset_otp_expiry'] ?? 0)) { 
            $show_alert = true;
            $alert_message = "OTP expired! Request a new one.";
            $alert_type = "error";
            unset($_SESSION['reset_otp_user_id'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_hashed'], $_SESSION['reset_otp_expiry']); 
        } elseif (!isset($_SESSION['reset_otp_hashed']) || !password_verify($submitted_otp, $_SESSION['reset_otp_hashed'])) {
            $show_alert = true;
            $alert_message = "Invalid OTP!";
            $alert_type = "error";
        } else { 
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $user_id_to_update = $_SESSION['reset_otp_user_id'];

            $stmt = $conn->prepare("UPDATE Users SET Pwd = ? WHERE Uid = ?");
            if (!$stmt) {
                error_log("EduFlow Forgot Password (Reset Pwd) Prepare Failed: " . $conn->error);
                $show_alert = true;
                $alert_message = "System error. Please try again.";
                $alert_type = "error";
            } else {
                $stmt->bind_param("si", $hashed_password, $user_id_to_update);
                if ($stmt->execute()) {
                    set_flash_message("Your password has been successfully reset. Please login.", 'success');
                    unset($_SESSION['reset_otp_user_id'], $_SESSION['reset_otp_email'], $_SESSION['reset_otp_hashed'], $_SESSION['reset_otp_expiry']);
                    redirect('/login.php'); 
                    exit;
                } else {
                    error_log("EduFlow Forgot Password (Reset Pwd) Execute Failed: " . $stmt->error);
                    $show_alert = true;
                    $alert_message = "Failed to update password. Try again.";
                    $alert_type = "error";
                }
                $stmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - EduFlow</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        html, body { height: 100%; margin: 0; padding: 0; }
        body { background: linear-gradient(to right, #eef5ff 0%, #c4e1ff 100%); font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        .container-fluid.d-flex { min-height: 100vh; padding: 15px; }
        .auth-card { background-color: rgba(255, 255, 255, 0.98); border-radius: 12px; box-shadow: 0 8px 20px rgba(0, 0, 0, 0.08); max-width: 480px; width: 100%; }
        .logo-container { margin-bottom: 25px; padding-top: 15px; }
        .logo-container img { max-width: 140px; width: 100%; height: auto; }
        .auth-card h3 { color: #0d6efd; font-weight: 700; margin-bottom: 1.75rem; }
        .btn-eduflow-primary { background-color: #0d6efd; border-color: #0d6efd; color: white; font-weight: 600; padding: 10px 15px; border-radius: 8px; }
        .btn-eduflow-primary:hover { background-color: #0a58ca; border-color: #0a53be; color: white; }
        
        /* Loader styles */
        .loader {
            border: 3px solid #f3f3f3;
            border-radius: 50%;
            border-top: 3px solid #3498db;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            margin-left: 10px;
            display: none;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .btn-with-loader {
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body>
    <div class="container-fluid d-flex justify-content-center align-items-center min-vh-100">
        <div class="card auth-card col-12 col-md-8 col-lg-5 col-xl-4">
            <div class="card-body p-4 p-md-5 text-center">
                <div class="logo-container">
                    <img src="<?php echo htmlspecialchars(BASE_URL . 'logo.png'); ?>" alt="EduFlow Logo" class="img-fluid">
                </div>
                <h3 class="fw-bold">Reset Your Password</h3>
                
                <?php if ($current_step == 'request_email'): ?>
                    <p class="mb-4 text-muted small">Enter your email to receive an OTP</p>
                    <form id="otpForm" method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'forgot_password.php'); ?>">
                        <input type="hidden" name="action" value="send_otp">
                        <div class="mb-3 text-start">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" class="form-control" id="email" name="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                        <button type="submit" class="btn btn-eduflow-primary w-100 mb-3 btn-with-loader" id="submitBtn">
                            <span id="btnText">Send OTP</span>
                            <div class="loader" id="loader"></div>
                        </button>
                    </form>
                <?php elseif ($current_step == 'verify_otp'): ?>
                    <p class="mb-3 text-muted small">OTP sent to <strong><?= htmlspecialchars($_SESSION['reset_otp_email'] ?? 'your email') ?></strong></p>
                    <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'forgot_password.php'); ?>">
                        <input type="hidden" name="action" value="reset_password">
                        <div class="mb-3 text-start">
                            <label for="otp" class="form-label">OTP</label>
                            <input type="text" class="form-control" id="otp" name="otp" required minlength="6" maxlength="6" pattern="\d{6}">
                        </div>
                        <div class="mb-3 text-start">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6">
                        </div>
                        <div class="mb-4 text-start">
                            <label for="confirm_new_password" class="form-label">Confirm Password</label>
                            <input type="password" class="form-control" id="confirm_new_password" name="confirm_new_password" required minlength="6">
                        </div>
                        <button type="submit" class="btn btn-eduflow-primary w-100 mb-3">Reset Password</button>
                    </form>
                <?php endif; ?>
                
                <div class="form-links text-center">
                    <?php if ($current_step == 'verify_otp'): ?>
                        <p class="mb-2"><a href="<?php echo htmlspecialchars(BASE_URL . 'forgot_password.php?action=reset_flow'); ?>" class="fw-bold">Request New OTP</a></p>
                    <?php endif; ?>
                    <p class="mb-0"><a href="<?php echo htmlspecialchars(BASE_URL . 'login.php'); ?>" class="fw-bold">Back to Login</a></p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Show alerts from PHP
            <?php if ($show_alert): ?>
                alert("<?php echo $alert_message; ?>");
            <?php endif; ?>
            
            // Add loader when submitting OTP form
            const otpForm = document.getElementById('otpForm');
            if (otpForm) {
                otpForm.addEventListener('submit', function() {
                    const submitBtn = document.getElementById('submitBtn');
                    const loader = document.getElementById('loader');
                    const btnText = document.getElementById('btnText');
                    
                    submitBtn.disabled = true;
                    btnText.textContent = 'Sending...';
                    loader.style.display = 'inline-block';
                });
            }
            
            // Simple client-side validation
            const emailInput = document.getElementById('email');
            if (emailInput) {
                emailInput.addEventListener('blur', function() {
                    if (this.value && !this.validity.valid) {
                        alert('Please enter a valid email address');
                    }
                });
            }
        });
    </script>
</body>
</html>