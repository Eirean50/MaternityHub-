<?php
// register_patient.php

// Force PHP to NEVER print text warnings to the screen, keeping JSON clean
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
    
    $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $dob = isset($_POST['dob']) ? trim($_POST['dob']) : '';
    $age = isset($_POST['age']) ? (int)$_POST['age'] : 0; 
    $address = isset($_POST['address']) ? trim($_POST['address']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';

    $contact_number = isset($_POST['contact_number']) ? trim($_POST['contact_number']) : '';
    $menarche = isset($_POST['menarche']) ? trim($_POST['menarche']) : '';
    $civil_status = isset($_POST['civil_status']) ? trim($_POST['civil_status']) : '';
    $religion = isset($_POST['religion']) ? trim($_POST['religion']) : '';
    $occupation = isset($_POST['occupation']) ? trim($_POST['occupation']) : '';
    $mother_name = isset($_POST['mother_name']) ? trim($_POST['mother_name']) : '';
    $father_name = isset($_POST['father_name']) ? trim($_POST['father_name']) : '';
    $husband_name = isset($_POST['husband_name']) ? trim($_POST['husband_name']) : '';
    $spouse_phone = isset($_POST['spouse_phone']) ? trim($_POST['spouse_phone']) : ''; 
    $clinic_name = isset($_POST['clinic_name']) ? trim($_POST['clinic_name']) : 'MaternityHub Clinic';
    $theme_color = isset($_POST['theme_color']) ? trim($_POST['theme_color']) : '#394b3b';

    // ✨ THE FIX: Safely catch the optional LMP. If empty, it becomes NULL.
    $last_menstrual_period = !empty($_POST['last_menstrual_period']) ? trim($_POST['last_menstrual_period']) : NULL;

    $base64_guardian_id = isset($_POST['guardian_id_base64']) ? $_POST['guardian_id_base64'] : '';

    if (empty($full_name) || empty($email) || empty($password) || empty($tenant_id)) {
        echo json_encode(array("status" => "error", "message" => "Required fields are missing."));
        exit();
    }

    try {
        $checkStmt = $pdo->prepare("SELECT id FROM patients WHERE email_address = :email AND TenantID = :tenant");
        $checkStmt->execute(['email' => $email, 'tenant' => $tenant_id]);
        if ($checkStmt->rowCount() > 0) {
            echo json_encode(array("status" => "error", "message" => "This email is already registered."));
            exit();
        }

        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $patient_id_str = "PT-" . date("Y") . "-" . rand(1000, 9999);
        $verification_code = rand(100000, 999999);

        $account_status = 'Approved';
        $guardian_id_filename = NULL;

        if ($age < 18) {
            $account_status = 'Pending';
            
            if (empty($base64_guardian_id)) {
                echo json_encode(array("status" => "error", "message" => "Minors must upload a Guardian ID."));
                exit();
            }

            $data = base64_decode($base64_guardian_id);
            $guardian_id_filename = "GID_" . time() . "_" . uniqid() . ".jpg";
            
            $dir = "uploads/guardian_ids/";
            if (!file_exists($dir)) {
                mkdir($dir, 0777, true);
            }
            
            $filepath = $dir . $guardian_id_filename; 
            file_put_contents($filepath, $data);
        }

        // Send Email Setup
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();                                            
            $mail->Host       = 'smtp.gmail.com';                     
            $mail->SMTPAuth   = true;                                   
            $mail->Username   = 'ellisfordead1@gmail.com';   
            $mail->Password   = 'buqr ibjv iekk hnib'; 
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;            
            $mail->Port       = 465;                                    
            $mail->setFrom('ellisfordead1@gmail.com', $clinic_name); 
            $mail->addAddress($email, $full_name);     
            $mail->isHTML(true);                                  
            
            if ($account_status == 'Pending') {
                $mail->Subject = "$clinic_name - Account Under Review";
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden;'>
                    <div style='background-color: {$theme_color}; padding: 25px; text-align: center; color: #ffffff;'>
                        <h1 style='margin: 0;'>Account Pending</h1>
                    </div>
                    <div style='padding: 30px; color: #333333;'>
                        <p>Hello <strong>{$full_name}</strong>,</p>
                        <p>Because you are under 18, your account requires Guardian Verification. We have received your Guardian ID and our clinic admins are currently reviewing it.</p>
                        <p>You will receive another email with your verification code as soon as your account is approved!</p>
                    </div>
                </div>";
            } else {
                $mail->Subject = "$clinic_name - Your Verification Code";
                $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 500px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden;'>
                    <div style='background-color: {$theme_color}; padding: 25px; text-align: center; color: #ffffff;'>
                        <h1 style='margin: 0;'>Verification Code</h1>
                    </div>
                    <div style='padding: 30px; color: #333333; text-align: center;'>
                        <p>Hello <strong>{$full_name}</strong>,</p>
                        <p>Your 6-digit verification code is:</p>
                        <h2 style='color: {$theme_color}; letter-spacing: 5px;'>{$verification_code}</h2>
                    </div>
                </div>";
            }
            $mail->send();

            // ✨ THE FIX: Added last_menstrual_period to the INSERT command
            $insertStmt = $pdo->prepare("INSERT INTO patients 
                (patient_id, full_name, email_address, password, verification_code, is_email_verified, 
                age, birthday, address, contact_number, menarche, civil_status, religion, occupation, 
                mother_name, father_name, husband_name, spouse_phone, last_menstrual_period, TenantID, account_status, guardian_id_url, 
                reject_reason, reviewed_by, reviewed_at, created_at) 
                VALUES 
                (:pid, :fname, :email, :pass, :vcode, 0, 
                :age, :dob, :addr, :contact, :menarche, :civil_status, :religion, :occupation, 
                :mother, :father, :husband, :sphone, :lmp, :tenant, :status, :gid, 
                NULL, NULL, NULL, NOW())");

            $insertStmt->execute([
                'pid' => $patient_id_str,
                'fname' => $full_name,
                'email' => $email,
                'pass' => $hashed_password,
                'vcode' => $verification_code,
                'age' => $age,
                'dob' => $dob,
                'addr' => $address,
                'contact' => $contact_number,
                'menarche' => $menarche,
                'civil_status' => $civil_status,
                'religion' => $religion,
                'occupation' => $occupation,
                'mother' => $mother_name,
                'father' => $father_name,
                'husband' => $husband_name,
                'sphone' => $spouse_phone,
                'lmp' => $last_menstrual_period,
                'tenant' => $tenant_id,
                'status' => $account_status,
                'gid' => $guardian_id_filename
            ]);

            echo json_encode(array(
                "status" => "success", 
                "account_status" => $account_status,
                "message" => $account_status == 'Pending' ? "Registration received! Please wait for Admin approval." : "Registration successful! Check your email.",
                "patient_id" => $patient_id_str,
                "verification_code" => (string)$verification_code 
            ));

        } catch (Exception $e) {
            echo json_encode(array("status" => "error", "message" => "Email failed. Error: " . $mail->ErrorInfo));
        }
    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }
}
?>