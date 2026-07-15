<?php
// update_profile.php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

require 'db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 1. Grab the exact Patient ID we are updating
    $patient_id = $_POST['patient_id'] ?? '';

    if (empty($patient_id)) {
        echo json_encode(["status" => "error", "message" => "Missing Patient ID."]);
        exit();
    }

    // 2. Catch all the newly edited data from Android
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $dob = $_POST['dob'] ?? '';
    $age = isset($_POST['age']) ? (int)$_POST['age'] : 0;
    $address = $_POST['address'] ?? '';
    
    $contact_number = $_POST['contact_number'] ?? '';
    $menarche = $_POST['menarche'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $religion = $_POST['religion'] ?? '';
    $occupation = $_POST['occupation'] ?? '';
    $mother_name = $_POST['mother_name'] ?? '';
    $father_name = $_POST['father_name'] ?? '';
    $husband_name = $_POST['husband_name'] ?? '';
    $spouse_phone = $_POST['spouse_phone'] ?? ''; 

    // ✨ NEW: Catch the profile picture URL if sent as a standard text string
    $profile_pic_url = $_POST['profile_pic_url'] ?? '';

    // ✨ UNIVERSAL CATCH: If Android sends the actual image file instead, process it and build the URL automatically!
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $target_dir = "uploads/";
        if(!is_dir($target_dir)) mkdir($target_dir, 0777, true); // Ensure the folder exists
        
        $file_name = "profile_" . time() . "_" . basename($_FILES["profile_pic"]["name"]);
        $target_file = $target_dir . $file_name;
        
        if (move_uploaded_file($_FILES["profile_pic"]["tmp_name"], $target_file)) {
            $profile_pic_url = "https://maternityhub.alwaysdata.net/" . $target_file;
        }
    }

    try {
        // 3. ✨ THE SQL SHIELD: Added profile_pic_url with COALESCE protection!
        $stmt = $pdo->prepare("UPDATE patients SET 
            full_name = :fname, 
            email_address = COALESCE(NULLIF(:email, ''), email_address), 
            birthday = :dob, 
            age = :age, 
            address = :addr, 
            contact_number = :contact, 
            menarche = :menarche, 
            civil_status = :civil, 
            religion = :religion, 
            occupation = :occupation, 
            mother_name = :mother, 
            father_name = :father, 
            husband_name = :husband, 
            spouse_phone = :sphone,
            profile_pic_url = COALESCE(NULLIF(:profile_pic, ''), profile_pic_url)
            WHERE patient_id = :pid");

        $stmt->execute([
            'fname' => $full_name,
            'email' => $email,
            'dob' => $dob,
            'age' => $age,
            'addr' => $address,
            'contact' => $contact_number,
            'menarche' => $menarche,
            'civil' => $civil_status,
            'religion' => $religion,
            'occupation' => $occupation,
            'mother' => $mother_name,
            'father' => $father_name,
            'husband' => $husband_name,
            'sphone' => $spouse_phone,
            'profile_pic' => $profile_pic_url,
            'pid' => $patient_id
        ]);

        echo json_encode([
            "status" => "success", 
            "message" => "Profile updated successfully.",
            "new_profile_pic" => $profile_pic_url 
        ]);

    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request method."]);
}
$pdo = null;
?>