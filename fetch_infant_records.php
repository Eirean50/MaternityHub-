<?php
// fetch_infant_records.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

date_default_timezone_set('Asia/Manila');
require 'db.php'; 

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $patient_id = isset($_POST['patient_id']) ? trim($_POST['patient_id']) : '';
    $tenant_id = isset($_POST['tenant_id']) ? trim($_POST['tenant_id']) : '';

    if (empty($patient_id) || empty($tenant_id)) {
        echo json_encode(["status" => "error", "message" => "Missing credentials."]);
        exit();
    }

    try {
        $sql = "SELECT * FROM infants WHERE TenantID = :tenant AND mother_patient_id = :pid ORDER BY birth_date DESC, birth_time DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['tenant' => $tenant_id, 'pid' => $patient_id]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $final_data = [];

        foreach ($results as $row) {
            $display_date = "";
            if (!empty($row['birth_date']) && $row['birth_date'] !== '0000-00-00') {
                $display_date = date("F j, Y", strtotime($row['birth_date']));
            }

            $display_time = "";
            if (!empty($row['birth_time']) && $row['birth_time'] !== '00:00:00') {
                $display_time = date("h:i A", strtotime($row['birth_time']));
            }

            $weight = trim((string)($row['weight_kg'] ?? ''));
            if ($weight !== '') {
                $weight = $weight . " kg";
            } else {
                $weight = "--";
            }

            $cert_url = trim((string)($row['certificate_of_live_birth'] ?? ''));
            if (!empty($cert_url) && $cert_url !== 'NULL') {
                if (strpos($cert_url, 'http') === false) {
                    $cert_url = "https://maternityhub.alwaysdata.net/" . ltrim($cert_url, '/');
                }
            } else {
                $cert_url = "";
            }

            // ✨ THE FIX: We are now sending the Apgar Score and Staff to the app!
            $final_data[] = [
                "id" => $row['id'],
                "infant_name" => $row['infant_name'] ?? 'Baby',
                "display_date" => $display_date,
                "time_of_birth" => $display_time,
                "gender" => $row['gender'] ?? 'Unknown',
                "weight" => $weight,
                "apgar_score" => $row['apgar_score'] ?? '--',
                "attending_staff" => $row['attending_staff'] ?? 'Unknown',
                "certificate_url" => $cert_url
            ];
        }

        if (count($final_data) > 0) {
            echo json_encode(["status" => "success", "data" => $final_data]);
        } else {
            echo json_encode(["status" => "empty", "message" => "No infant records found."]);
        }

    } catch (PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>