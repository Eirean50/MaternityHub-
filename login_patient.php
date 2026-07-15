<?php
// login_patient.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';

    if (empty($email) || empty($password) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing login credentials or clinic ID."]);
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT id, patient_id, full_name, password, account_status, reject_reason, is_email_verified FROM patients WHERE email_address = :email AND TenantID = :tenant LIMIT 1");
        $stmt->execute(['email' => $email, 'tenant' => $tenant_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($patient) {
            if (password_verify($password, $patient['password'])) {
                
                // Set default fallbacks
                $theme = '#394b3b';
                $cName = 'Maternity Clinic';
                $cLogo = '';

                try {
                    $stmtTenant = $pdo->prepare("SELECT clinic_name, theme_color, clinic_logo FROM tenants WHERE TenantID = :tenant LIMIT 1");
                    $stmtTenant->execute(['tenant' => $tenant_id]);
                    $tenantData = $stmtTenant->fetch(PDO::FETCH_ASSOC);

                    if ($tenantData) {
                        // ✨ THE FIX: Check if values are null or empty and use fallbacks
                        $theme = !empty($tenantData['theme_color']) ? $tenantData['theme_color'] : '#394b3b';
                        $cName = !empty($tenantData['clinic_name']) ? $tenantData['clinic_name'] : 'Maternity Clinic';
                        $cLogo = !empty($tenantData['clinic_logo']) ? $tenantData['clinic_logo'] : '';
                    }
                } catch(PDOException $e) {
                    echo json_encode(["status" => "error", "message" => "Tenants DB Error: " . $e->getMessage()]);
                    exit(); 
                }

                $status = !empty($patient['account_status']) ? $patient['account_status'] : "Approved";
                $reason = !empty($patient['reject_reason']) ? $patient['reject_reason'] : "";

                echo json_encode([
                    "status" => "success",
                    "message" => "Login successful!",
                    "data" => [
                        "patient_db_id" => $patient['id'],
                        "patient_id" => $patient['patient_id'],
                        "full_name" => $patient['full_name'],
                        "account_status" => $status,    
                        "reject_reason" => $reason,
                        "is_email_verified" => (int)$patient['is_email_verified'],
                        "theme_color" => $theme,
                        "clinic_name" => $cName,
                        "clinic_logo" => $cLogo
                    ]
                ]);
            } else {
                echo json_encode(["status" => "error", "message" => "Incorrect password."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "No account found in this clinic with that email."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Patients DB Error: " . $e->getMessage()]);
    }

} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}

$pdo = null;
?>