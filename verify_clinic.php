<?php
// verify_clinic.php
header("Content-Type: application/json"); 
header("Access-Control-Allow-Origin: *"); 

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $clinic_code = isset($_POST['clinic_code']) ? trim($_POST['clinic_code']) : '';

    if (empty($clinic_code)) {
        echo json_encode(array("status" => "error", "message" => "Clinic code is required."));
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE clinic_code = :clinic_code LIMIT 1");
        $stmt->bindParam(':clinic_code', $clinic_code, PDO::PARAM_STR);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $isActive = true; 
            // Case-insensitive check just in case it's saved as 'active' or 'Active'
            if(isset($row['status']) && strtolower($row['status']) != 'active') {
                 $isActive = false;
            }

            if ($isActive) {
                echo json_encode(array(
                    "status" => "success",
                    "message" => "Clinic found.",
                    "data" => array(
                        // ✨ Matches the exact keys Kotlin is looking for
                        "tenant_id" => $row['TenantID'] ?? $row['TenantI D'], 
                        "clinic_name" => $row['clinic_name'],
                        "theme_color" => $row['theme_color'] ?? '#394b3b', 
                        "clinic_logo" => $row['clinic_logo'] ?? ''
                    )
                ));
            } else {
                echo json_encode(array("status" => "error", "message" => "This clinic account is currently inactive."));
            }
        } else {
            echo json_encode(array("status" => "error", "message" => "Invalid clinic code. Please try again."));
        }

    } catch(PDOException $e) {
        echo json_encode(array("status" => "error", "message" => "Database error: " . $e->getMessage()));
    }

} else {
    echo json_encode(array("status" => "error", "message" => "Invalid request method. Please use POST."));
}

$pdo = null; 
?>