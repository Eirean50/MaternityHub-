<?php
// Saluhin ang mga ipinasa mula sa patientrecords.php
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Bumalik sa Patient Records para mag-generate ng certificate.");
}

$babyJson = $_POST['baby_json'] ?? '{}';
$baby = json_decode($babyJson, true);

$clinicName = $_POST['clinic_name'] ?? '';
$clinicAddress = $_POST['clinic_address'] ?? '';

$province = $_POST['province'] ?? '';
$city = $_POST['city'] ?? '';
$registryNo = $_POST['registry_no'] ?? '';
$birthType = $_POST['birth_type'] ?? '';
$multipleType = $_POST['multiple_type'] ?? '';
$birthOrder = $_POST['birth_order'] ?? '';

$mCitizen = $_POST['m_citizen'] ?? '';
$mJob = $_POST['m_job'] ?? '';
$mAlive = $_POST['m_alive'] ?? '';
$mLiving = $_POST['m_living'] ?? '';
$mDead = $_POST['m_dead'] ?? '';

$fCitizen = $_POST['f_citizen'] ?? '';
$fJob = $_POST['f_job'] ?? '';
$fAge = $_POST['f_age'] ?? '';

$marDate = $_POST['mar_date'] ?? '';
$marPlace = $_POST['mar_place'] ?? '';
$attType = $_POST['att_type'] ?? '';
$attendant = $_POST['attendant'] ?? '';

// Formatting Dates & Time
$bDate = !empty($baby['birth_date']) ? new DateTime($baby['birth_date']) : new DateTime();
$formattedDate = $bDate->format('d F Y');

$btime = '---';
if (!empty($baby['birth_time'])) {
    $timeParts = explode(':', $baby['birth_time']);
    $h = (int)$timeParts[0];
    $m = $timeParts[1];
    $ampm = $h >= 12 ? 'PM' : 'AM';
    $h = $h % 12; $h = $h ? $h : 12; 
    $btime = $h . ':' . $m . ' ' . $ampm;
}

$wtGrams = (floatval($baby['weight_kg'] ?? 0)) * 1000;
$today = date('M d, Y');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate - <?= htmlspecialchars($baby['infant_name'] ?? 'Baby') ?></title>
    <style>
        /* Ito ang magse-set ng sakto sa PDF paper size para hindi tabingi */
        @media print {
            @page { size: 8.5in 11in; margin: 0; }
            body { margin: 0; background: white; }
            .no-print { display: none; }
        }
        body { 
            background: #555; display: flex; justify-content: center; align-items: center; 
            margin: 0; padding: 20px; font-family: 'Courier New', Courier, monospace;
        }
        .cert-container {
            width: 816px; height: 1056px; background-color: white; position: relative;
            font-size: 14px; font-weight: bold; color: black; text-transform: uppercase;
            box-shadow: 0 0 15px rgba(0,0,0,0.5); overflow: hidden;
        }
        .overlay-text { position: absolute; z-index: 2; }
    </style>
</head>
<body onload="window.print()"> <div class="no-print" style="position: absolute; top: 10px; left: 10px; background: white; padding: 10px; border-radius: 5px;">
        <button onclick="window.print()" style="padding: 10px; font-weight: bold; cursor: pointer;">I-save as PDF Ulit</button>
    </div>

    <div class="cert-container">
        <img src="certificatenewpic-min.png" alt="Blank Form" style="width: 100%; height: 100%; position: absolute; top: 0; left: 0; z-index: 1;">

        <div class="overlay-text" style="top: 90px; left: 150px;"><?= htmlspecialchars($province) ?></div>
        <div class="overlay-text" style="top: 120px; left: 150px;"><?= htmlspecialchars($city) ?></div>
        <div class="overlay-text" style="top: 90px; left: 600px;"><?= htmlspecialchars($registryNo) ?></div>

        <div class="overlay-text" style="top: 160px; left: 120px;"><?= htmlspecialchars($baby['infant_name'] ?? 'UNNAMED') ?></div>
        <div class="overlay-text" style="top: 200px; left: 120px;"><?= htmlspecialchars($baby['gender'] ?? '---') ?></div>
        <div class="overlay-text" style="top: 200px; left: 350px;"><?= strtoupper($formattedDate) ?></div>
        <div class="overlay-text" style="top: 240px; left: 120px;"><?= htmlspecialchars($clinicName . ', ' . $clinicAddress) ?></div>
        
        <div class="overlay-text" style="top: 280px; left: 120px;"><?= htmlspecialchars($birthType) ?></div>
        <div class="overlay-text" style="top: 280px; left: 320px;"><?= htmlspecialchars($multipleType) ?></div>
        <div class="overlay-text" style="top: 280px; left: 520px;"><?= htmlspecialchars($birthOrder) ?></div>
        <div class="overlay-text" style="top: 280px; left: 700px;"><?= $wtGrams ?></div>

        <div class="overlay-text" style="top: 350px; left: 120px;"><?= htmlspecialchars($baby['mother_name'] ?? 'UNKNOWN') ?></div>
        <div class="overlay-text" style="top: 390px; left: 120px;"><?= htmlspecialchars($mCitizen) ?></div>
        <div class="overlay-text" style="top: 390px; left: 450px;"><?= htmlspecialchars($baby['religion'] ?? '---') ?></div>
        
        <div class="overlay-text" style="top: 430px; left: 120px;"><?= htmlspecialchars($mAlive) ?></div>
        <div class="overlay-text" style="top: 430px; left: 250px;"><?= htmlspecialchars($mLiving) ?></div>
        <div class="overlay-text" style="top: 430px; left: 380px;"><?= htmlspecialchars($mDead) ?></div>
        <div class="overlay-text" style="top: 430px; left: 550px;"><?= htmlspecialchars($mJob) ?></div>
        <div class="overlay-text" style="top: 430px; left: 750px;"><?= htmlspecialchars($baby['mother_age'] ?? '---') ?></div>
        <div class="overlay-text" style="top: 470px; left: 120px;"><?= htmlspecialchars($baby['address'] ?? '---') ?></div>

        <div class="overlay-text" style="top: 540px; left: 120px;"><?= htmlspecialchars($baby['father_name'] ?? 'N/A') ?></div>
        <div class="overlay-text" style="top: 580px; left: 120px;"><?= htmlspecialchars($fCitizen) ?></div>
        <div class="overlay-text" style="top: 580px; left: 350px;"><?= htmlspecialchars($baby['religion'] ?? '---') ?></div>
        <div class="overlay-text" style="top: 580px; left: 550px;"><?= htmlspecialchars($fJob) ?></div>
        <div class="overlay-text" style="top: 580px; left: 750px;"><?= htmlspecialchars($fAge) ?></div>
        <div class="overlay-text" style="top: 620px; left: 120px;"><?= htmlspecialchars($baby['address'] ?? '---') ?></div>

        <div class="overlay-text" style="top: 690px; left: 200px;"><?= htmlspecialchars($marDate) ?></div>
        <div class="overlay-text" style="top: 690px; left: 500px;"><?= htmlspecialchars($marPlace) ?></div>

        <div class="overlay-text" style="top: 750px; left: 120px;"><?= $attType == 'Physician' ? 'X' : '' ?></div>
        <div class="overlay-text" style="top: 750px; left: 220px;"><?= $attType == 'Nurse' ? 'X' : '' ?></div>
        <div class="overlay-text" style="top: 750px; left: 320px;"><?= $attType == 'Midwife' ? 'X' : '' ?></div>
        <div class="overlay-text" style="top: 750px; left: 420px;"><?= $attType == 'Hilot' ? 'X' : '' ?></div>
        <div class="overlay-text" style="top: 750px; left: 520px;"><?= $attType == 'Others' ? 'X' : '' ?></div>

        <div class="overlay-text" style="top: 780px; left: 450px;"><?= $btime ?></div>
        <div class="overlay-text" style="top: 820px; left: 180px;"><?= strtoupper(htmlspecialchars($attendant)) ?></div>
        <div class="overlay-text" style="top: 850px; left: 180px;"><?= strtoupper(htmlspecialchars($attType)) ?></div>
        <div class="overlay-text" style="top: 820px; left: 550px;"><?= htmlspecialchars($city . ', ' . $province) ?></div>
        <div class="overlay-text" style="top: 860px; left: 550px;"><?= strtoupper($today) ?></div>

        <div class="overlay-text" style="top: 960px; left: 180px;"><?= strtoupper(htmlspecialchars($baby['mother_name'] ?? '')) ?></div>
        <div class="overlay-text" style="top: 990px; left: 180px;">MOTHER</div>
        
        <div class="overlay-text" style="top: 960px; left: 550px;"><?= strtoupper(htmlspecialchars($attendant)) ?></div>
        <div class="overlay-text" style="top: 990px; left: 550px;"><?= strtoupper(htmlspecialchars($attType)) ?></div>
    </div>
</body>
</html>