<?php
// request_email_change.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';
    $new_email = isset($_POST['new_email']) ? trim($_POST['new_email']) : '';
    $theme_color = isset($_POST['theme_color']) ? trim($_POST['theme_color']) : '#394b3b';

    if (empty($patient_id) || empty($tenant_id) || empty($new_email)) {
        echo json_encode(array("status" => "error", "message" => "Missing required information."));
        exit();
    }

    try {
        // 1. Duplicate check (Safely ignoring the current user whether they send '15' or 'PT-123')
        $checkEmailStmt = $pdo->prepare("SELECT id FROM patients WHERE email_address = :email AND TenantID = :tenant AND id != :pid AND patient_id != :pid LIMIT 1");
        $checkEmailStmt->execute([
            'email' => $new_email, 
            'tenant' => $tenant_id,
            'pid' => $patient_id
        ]);
        
        if ($checkEmailStmt->rowCount() > 0) {
            echo json_encode(array("status" => "error", "message" => "This email is already registered to another account in this clinic."));
            exit();
        }

        // 2. Fetch the Patient (Bulletproof check for both ID types)
        $stmt = $pdo->prepare("
            SELECT p.full_name, t.clinic_name 
            FROM patients p
            LEFT JOIN tenants t ON p.TenantID = t.TenantID
            WHERE (p.id = :pid OR p.patient_id = :pid) AND p.TenantID = :tenant 
            LIMIT 1
        ");
        $stmt->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);

        if ($stmt->rowCount() > 0) {
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);
            $full_name = $patient['full_name'];
            $clinic_name = $patient['clinic_name'] ?? 'Maternity Clinic'; 
            
            $verification_code = rand(100000, 999999);

            // 3. Save the code
            $updateStmt = $pdo->prepare("UPDATE patients SET verification_code = :code WHERE (id = :pid OR patient_id = :pid) AND TenantID = :tenant");
            $updateStmt->execute(['code' => $verification_code, 'pid' => $patient_id, 'tenant' => $tenant_id]);

            // 4. Send the Email using PHPMailer
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();                                            
                $mail->Host       = 'smtp.gmail.com';                     
                $mail->SMTPAuth   = true;                                   
                $mail->Username   = 'ellisfordead1@gmail.com';  
                
                // 🚨 CRITICAL: YOU MUST CHANGE THE LINE BELOW TO YOUR REAL GOOGLE APP PASSWORD 🚨
                $mail->Password   = 'buqr ibjv iekk hnib'; 
                
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            
                $mail->Port       = 465;                                    

                $mail->setFrom('ellisfordead1@gmail.com', "$clinic_name System"); 
                $mail->addAddress($new_email, $full_name);     

                $mail->isHTML(true);                                  
                $mail->Subject = "$clinic_name - Verify New Email Address";
                
                $htmlBody = "
                <div style='font-family: \"Helvetica Neue\", Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; background-color: #f9faf3; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1);'>
                    <div style='background-color: {$theme_color}; padding: 32px 20px; text-align: center;'>
                        <h1 style='color: #ffffff; margin: 0; font-size: 24px; letter-spacing: 1px;'>{$clinic_name}</h1>
                    </div>
                    <div style='padding: 40px 32px; background-color: #ffffff; text-align: center;'>
                        <h2 style='color: #1a1c18; font-size: 20px; margin-top: 0;'>Confirm Your New Email</h2>
                        <p style='color: #556756; font-size: 16px; line-height: 1.5; margin-bottom: 32px;'>
                            Hello <b>{$full_name}</b>,<br><br>
                            Please use the verification code below to confirm this change:
                        </p>
                        <div style='background-color: #f3f4ed; border: 2px dashed {$theme_color}; border-radius: 12px; padding: 24px; margin: 0 auto; display: inline-block;'>
                            <span style='font-family: \"Courier New\", Courier, monospace; font-size: 38px; font-weight: bold; letter-spacing: 12px; color: #1a1c18; padding-left: 12px;'>{$verification_code}</span>
                        </div>
                    </div>
                </div>
                ";

                $mail->Body = $htmlBody;
                $mail->AltBody = "Hello $full_name,\n\nYour code to verify this new email for $clinic_name is: $verification_code\n\nIf you did not request this, please ignore this message.";

                $mail->send();

                echo json_encode(array("status" => "success", "message" => "Verification code sent to new email."));

            } catch (Exception $e) {
                // If it crashes here, the password is wrong!
                echo json_encode(array("status" => "error", "message" => "Failed to send email. Check SMTP settings."));
            }

        } else {
            echo json_encode(array("status" => "error", "message" => "Patient account not found."));
        }

    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }
} else {
    echo json_encode(array("status" => "error", "message" => "Invalid request method."));
}
$pdo = null;
?>