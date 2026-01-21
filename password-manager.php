<?php
// password-manager.php
require_once 'config.php';
date_default_timezone_set('Asia/Manila');

require 'vendor/autoload.php'; // PHPMailer autoload
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ---------------- CONFIG ----------------
$smtpFromEmail = 'bungagretchennnn36@gmail.com';
$smtpFromName  = 'AU iTrace Admin';
$smtpUsername  = 'bungagretchennnn36@gmail.com';
$smtpAppPass   = 'ocdr zpud upol jxkh'; // Gmail App Password
$resetBaseURL  = 'http://localhost:8012/au-itrace/password-manager.php';
// ----------------------------------------

$token = $_GET['token'] ?? '';
$mode = 'forgot';
$message = '';
$message_type = '';
$user_info = null;

// Step 1: Token validation
if (!empty($token)) {
    $mode = 'reset';
    $stmt = $link->prepare("
        SELECT studentID, email, fullname, tokenexpiration
        FROM tblregistration 
        WHERE resettoken = ? AND resettoken IS NOT NULL
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res = $stmt->get_result();

    if ($res && $res->num_rows === 1) {
        $user_info = $res->fetch_assoc();
        if (empty($user_info['tokenexpiration']) || strtotime($user_info['tokenexpiration']) < time()) {
            $message = "This password reset link has expired.";
            $message_type = 'danger';
            $mode = 'error';
            $user_info = null;
        }
    } else {
        $message = "Invalid or expired password reset link.";
        $message_type = 'danger';
        $mode = 'error';
    }
    $stmt->close();
}

// Step 2: Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Forgot password → generate token + email
    if (isset($_POST['forgot_password'])) {
        $email = trim($_POST['email']);

        $stmt = $link->prepare("
            SELECT T1.studentID, T1.fullname, T1.email
            FROM tblregistration T1
            JOIN tblsystemusers T2 ON T2.username = CONCAT('student', T1.studentID)
            WHERE T1.email = ? AND UPPER(T2.status) = 'ACTIVE'
            LIMIT 1
        ");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $row = $res->fetch_assoc()) {
            $studentID = (int)$row['studentID'];
            $fullname = $row['fullname'];
            $studentEmail = $row['email'];

            $token = bin2hex(random_bytes(32));
            $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));

            $update = $link->prepare("UPDATE tblregistration SET resettoken = ?, tokenexpiration = ? WHERE studentID = ?");
            $update->bind_param("ssi", $token, $expires, $studentID);
            $ok = $update->execute();
            $update->close();

            if ($ok) {
                $reset_link = $resetBaseURL . '?token=' . urlencode($token);
                $subject = "AU iTrace Account Password Reset";
                $expiryText = "0 hour(s), 59 minute(s)";

                $htmlBody = "
                <html>
                <head>
                    <style>
                        .email-container { font-family: Arial, sans-serif; line-height:1.6; color:#333; max-width:600px; margin:auto; border:1px solid #ddd; padding:20px; border-radius:8px; background:#f9f9f9; }
                        .email-header { font-size:20px; font-weight:bold; color:#004085; margin-bottom:20px; }
                        .btn { display:inline-block; background:#007bff; color:#fff; padding:10px 20px; text-decoration:none; border-radius:5px; font-weight:bold; }
                        .footer { margin-top:20px; font-size:13px; color:#555; }
                    </style>
                </head>
                <body>
                    <div class='email-container'>
                        <div class='email-header'>AU iTrace Account Password Reset</div>
                        <p>Good Day,</p>
                        <p>We have received a request to reset the password for your AU iTrace account: <strong>" . htmlspecialchars($studentEmail) . "</strong>.</p>
                        <p>If you submitted this request, please click the button below to proceed:</p>
                        <p style='text-align:center; margin: 20px 0;'>
                            <a href=\"" . htmlspecialchars($reset_link) . "\" class='btn'>Reset Password</a>
                        </p>
                        <p>This button will be active for " . htmlspecialchars($expiryText) . ".</p>
                        <p>Thank You,<br>AU iTrace Admin</p>
                    </div>
                </body>
                </html>
                ";

                $altBody = "Good Day,\n\nWe have received a request to reset the password for your AU iTrace account: {$studentEmail}.\n\nReset link: {$reset_link}\n\nThis link will expire in 1 hour.\n\nThank you,\nAU iTrace Admin";

                try {
                    $mail = new PHPMailer(true);
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpUsername;
                    $mail->Password = $smtpAppPass;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port = 587;

                    $mail->setFrom($smtpFromEmail, $smtpFromName);
                    $mail->addAddress($studentEmail, $fullname);
                    $mail->addReplyTo($smtpFromEmail, $smtpFromName);

                    $mail->isHTML(true);
                    $mail->Subject = $subject;
                    $mail->Body    = $htmlBody;
                    $mail->AltBody = $altBody;

                    $mail->send();
                    $message = "A reset link has been sent to your email.";
                    $message_type = 'success';

                } catch (Exception $e) {
                    $message = "Mailer Error: " . $mail->ErrorInfo;
                    $message_type = 'danger';
                }
            } else {
                $message = "Database error while creating reset token.";
                $message_type = 'danger';
            }
        } else {
            $message = "No active account found for that email.";
            $message_type = 'danger';
        }
        $stmt->close();
    }

    // Reset password (after clicking email link)
    if (isset($_POST['reset_password'])) {
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if ($mode !== 'reset' || !$user_info) {
            $message = "Invalid or expired reset session.";
            $message_type = 'danger';
        } elseif ($new_password !== $confirm_password) {
            $message = "Passwords do not match.";
            $message_type = 'danger';
        } elseif (strlen($new_password) < 8) {
            $message = "Password must be at least 8 characters.";
            $message_type = 'danger';
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $studentID = (int)$user_info['studentID'];
            $username = "student" . $studentID;
            $now = date("Y-m-d H:i:s");
            $email = $user_info['email'];

            // Update tblsystemusers
            $update_sys = $link->prepare("UPDATE tblsystemusers SET password = ? WHERE username = ?");
            $update_sys->bind_param("ss", $hashed, $username);
            $update_sys->execute();
            $update_sys->close();

            // Update tblactivestudents
            $update_act = $link->prepare("UPDATE tblactivestudents SET password = ?, dateapproved = ? WHERE studentID = ?");
            $update_act->bind_param("ssi", $hashed, $now, $studentID);
            $update_act->execute();
            $update_act->close();

            // Update tblregistration
            $update_reg = $link->prepare("UPDATE tblregistration SET password = ?, resettoken = NULL, tokenexpiration = NULL WHERE studentID = ?");
            $update_reg->bind_param("si", $hashed, $studentID);
            $update_reg->execute();
            $update_reg->close();

            $success = "Your password has been reset. You may now log in as <strong>{$username}</strong>.";
            header("Location: au_itrace_portal.php?tab=login&message=" . urlencode($success));
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Password Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style> body { background:#f5f7fb; } .card { border-radius: 8px; } </style>
</head>
<body>
<div class="container mt-5" style="max-width: 540px;">
    <div class="card shadow p-4">
        <h3 class="text-center mb-3 text-primary">
            <i class="fas fa-key me-2"></i> Password Manager
        </h3>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo htmlspecialchars($message_type ?: 'info'); ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <?php if ($mode === 'forgot' || $mode === 'error'): ?>
            <form method="POST" class="mb-0">
                <input type="hidden" name="forgot_password" value="1">
                <div class="mb-3">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <button class="btn btn-primary w-100" type="submit">Send Reset Link</button>
            </form>
        <?php endif; ?>

        <?php if ($mode === 'reset' && $user_info): ?>
            <p class="text-muted">Resetting password for <strong><?php echo htmlspecialchars($user_info['email']); ?></strong></p>
            <form method="POST" class="mb-0">
                <input type="hidden" name="reset_password" value="1">
                <div class="mb-3">
                    <label>New Password</label>
                    <input type="password" name="new_password" class="form-control" required minlength="8">
                </div>
                <div class="mb-3">
                    <label>Confirm Password</label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="8">
                </div>
                <button class="btn btn-success w-100" type="submit">Set Password</button>
            </form>
        <?php endif; ?>

        <div class="mt-3 text-center">
            <a href="au_itrace_portal.php?tab=login" class="text-decoration-none">← Back to Login</a>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
