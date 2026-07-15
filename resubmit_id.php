<?php
// resubmit_id.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
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
    $base64_guardian_id = isset($_POST['guardian_id_base64']) ? $_POST['guardian_id_base64'] : '';

    if (empty($patient_id) || empty($tenant_id) || empty($base64_guardian_id)) {
        echo json_encode(["status" => "error", "message" => "Missing required fields."]);
        exit();
    }

    try {
        // 1. Process the Image
        $data = base64_decode($base64_guardian_id);
        if ($data === false) {
            echo json_encode(["status" => "error", "message" => "Invalid image data format."]);
            exit();
        }

        $guardian_id_filename = "GID_RESUBMIT_" . time() . "_" . uniqid() . ".jpg";
        $filepath = "uploads/guardian_ids/" . $guardian_id_filename;
        file_put_contents($filepath, $data);

        // 2. Fetch Patient Info for Email
        $stmtInfo = $pdo->prepare("SELECT full_name, email_address FROM patients WHERE patient_id = :pid AND TenantID = :tenant LIMIT 1");
        $stmtInfo->execute(['pid' => $patient_id, 'tenant' => $tenant_id]);
        $patient = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        // 3. Update Database to Pending
        $stmt = $pdo->prepare("
            UPDATE patients 
            SET account_status = 'Pending', 
                reject_reason = NULL, 
                reviewed_by = NULL, 
                reviewed_at = NULL,
                guardian_id_url = :gid
            WHERE patient_id = :pid AND TenantID = :tenant
        ");

        $stmt->execute(['gid' => $guardian_id_filename, 'pid' => $patient_id, 'tenant' => $tenant_id]);

        if ($stmt->rowCount() > 0) {
            
            // 4. Send Confirmation Email
            if ($patient) {
                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();                                            
                    $mail->Host       = 'smtp.gmail.com';                     
                    $mail->SMTPAuth   = true;                                   
                    $mail->Username   = 'ellisfordead1@gmail.com';   
                    $mail->Password   = 'buqr ibjv iekk hnib'; 
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            
                    $mail->Port       = 465;                                    
                    $mail->setFrom('ellisfordead1@gmail.com', 'MaternityHub Clinic'); 
                    $mail->addAddress($patient['email_address'], $patient['full_name']);     
                    $mail->isHTML(true);                                  
                    
                    $mail->Subject = "MaternityHub - New ID Received";
                    $mail->Body = "
                    <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden;'>
                        <div style='background-color: #394b3b; padding: 25px; text-align: center; color: #ffffff;'>
                            <h1 style='margin: 0;'>ID Submitted</h1>
                        </div>
                        <div style='padding: 30px; color: #333333;'>
                            <p>Hello <strong>{$patient['full_name']}</strong>,</p>
                            <p>We have successfully received your newly uploaded Guardian ID. Your account has been moved back to the review queue.</p>
                            <p>We will email you as soon as our administrators have reviewed your submission!</p>
                        </div>
                    </div>";
                    $mail->send();
                } catch (Exception $e) {}
            }

            echo json_encode(["status" => "success", "message" => "ID successfully updated. Account is now pending review."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Failed to update database record."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
?>