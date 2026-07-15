<?php
// forgot_password.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 
// Make sure these match exactly where your PHPMailer folder is located!
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    // ✨ NEW: Grab the clinic's theme color from Android (Defaults to Green if not sent)
    $theme_color = isset($_POST['theme_color']) ? trim($_POST['theme_color']) : '#394b3b';

    if (empty($email) || empty($tenant_id)) {
        echo json_encode(array("status" => "error", "message" => "Please provide an email address and clinic context."));
        exit();
    }

    try {
        // 1. Check if the email exists INSIDE THIS SPECIFIC CLINIC
        $stmt = $pdo->prepare("
            SELECT p.id, p.full_name, t.clinic_name 
            FROM patients p
            LEFT JOIN tenants t ON p.TenantID = t.TenantID
            WHERE p.email_address = :email AND p.TenantID = :tenant 
            LIMIT 1
        ");
        $stmt->execute(['email' => $email, 'tenant' => $tenant_id]);

        if ($stmt->rowCount() > 0) {
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            $full_name = $patient['full_name'];
            $clinic_name = $patient['clinic_name'] ?? 'Maternity Clinic'; 
            
            // 2. Generate a random 6-digit reset code
            $reset_code = rand(100000, 999999);

            // 3. Save the code into the database FOR THIS SPECIFIC CLINIC ACCOUNT
            $updateStmt = $pdo->prepare("UPDATE patients SET verification_code = :code WHERE email_address = :email AND TenantID = :tenant");
            $updateStmt->execute(['code' => $reset_code, 'email' => $email, 'tenant' => $tenant_id]);

            // 4. Send the Email using PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();                                            
                $mail->Host       = 'smtp.gmail.com';                     
                $mail->SMTPAuth   = true;                                   
                // ✨ UPGRADE: Your new professional email!
                $mail->Username   = 'ellisfordead1@gmail.com';  
                // 🚨 IMPORTANT: You must generate a new 16-character "App Password" from this Google Account's settings!
                $mail->Password   = 'buqr ibjv iekk hnib';             
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            
                $mail->Port       = 465;                                    

                // ✨ UPGRADE: Make the email look like it came from their specific clinic!
                $mail->setFrom('ellisfordead1@gmail.com', "$clinic_name System"); 
                $mail->addAddress($email, $full_name);     

                $mail->isHTML(true);                                  
                $mail->Subject = "$clinic_name - Your Verification Code";
                
                // ✨ UPGRADE: The Beautiful, Responsive HTML Email Template
                $htmlBody = "
                <div style='font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f9faf3; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
                    
                    <div style='background-color: {$theme_color}; padding: 32px 20px; text-align: center;'>
                        <h1 style='color: #ffffff; margin: 0; font-size: 24px; letter-spacing: 1px;'>{$clinic_name}</h1>
                    </div>

                    <div style='padding: 40px 32px; background-color: #ffffff; text-align: center;'>
                        <h2 style='color: #1a1c18; font-size: 20px; margin-top: 0;'>Account Verification</h2>
                        <p style='color: #556756; font-size: 16px; line-height: 1.5; margin-bottom: 32px;'>
                            Hello <b>{$full_name}</b>,<br><br>
                            We received a request regarding your account. Please use the secure verification code below to proceed:
                        </p>

                        <div style='background-color: #f3f4ed; border: 2px dashed {$theme_color}; border-radius: 12px; padding: 24px; margin: 0 auto; display: inline-block;'>
                            <span style='font-family: \"Courier New\", Courier, monospace; font-size: 38px; font-weight: bold; letter-spacing: 12px; color: #1a1c18; padding-left: 12px;'>{$reset_code}</span>
                        </div>
                        
                        <p style='color: #737872; font-size: 13px; margin-top: 16px;'>
                            <em>(Tip: Long-press the code above to easily copy it on your phone)</em>
                        </p>

                        <hr style='border: none; border-top: 1px solid #e2e3dc; margin: 40px 0 24px;'>

                        <p style='color: #a0a5a0; font-size: 12px; line-height: 1.5; margin: 0;'>
                            If you didn't request this code, please ignore this email or contact clinic support if you have concerns.<br>
                            This is an automated message, please do not reply directly to this email.
                        </p>
                    </div>
                </div>
                ";

                $mail->Body = $htmlBody;
                
                // Fallback for extremely old email clients that don't support HTML
                $mail->AltBody = "Hello $full_name,\n\nYour verification code for $clinic_name is: $reset_code\n\nIf you did not request this, please secure your account.";

                $mail->send();

                echo json_encode(array("status" => "success", "message" => "Reset code sent to email."));

            } catch (Exception $e) {
                echo json_encode(array("status" => "error", "message" => "Failed to send email. Check SMTP settings."));
            }

        } else {
            echo json_encode(array("status" => "error", "message" => "No account found in this clinic with that email."));
        }

    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }
} else {
    echo json_encode(array("status" => "error", "message" => "Invalid request method."));
}
$pdo = null;
?>