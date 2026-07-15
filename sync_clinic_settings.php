<?php
// sync_clinic_settings.php
ini_set('display_errors', 0);
error_reporting(E_ALL);
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
        // ✨ THE FIX: Changed 'tenant_id' to 'TenantID' here as well!
        $stmt = $pdo->prepare("SELECT clinic_name, theme_color, clinic_logo FROM tenants WHERE TenantID = :tenant LIMIT 1");
        $stmt->execute(['tenant' => $tenant_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($settings) {
            echo json_encode([
                "status" => "success", 
                "data" => [
                    "theme_color" => $settings['theme_color'],
                    "clinic_name" => $settings['clinic_name'],
                    "clinic_logo" => $settings['clinic_logo']
                ]
            ]);
        } else {
            echo json_encode(["status" => "error", "message" => "Clinic settings not found."]);
        }

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>