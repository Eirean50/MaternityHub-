<?php
// fetch_services.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';

    if (empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing Tenant ID."]);
        exit();
    }

    try {
        // ✨ THE FIX: Added "AND is_active = 1" to only fetch active services!
        $sql = "SELECT id, service_name, price, downpayment_percent 
                FROM clinic_services 
                WHERE TenantID = :tenant 
                AND is_active = 1
                AND LOWER(service_name) NOT LIKE '%delivery%'
                ORDER BY service_name ASC";
                
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant' => $tenant_id]);
        $services = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(["status" => "success", "data" => $services]);
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "DB Error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>