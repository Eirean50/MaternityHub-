<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 0); 
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

// ==============================================================
// AUDIT LOG HELPER FUNCTION
// ==============================================================
function log_audit($pdo, $user_name, $role, $action_type, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s'); 
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (Exception $e) {
        // Silent fail
    }
}
// ==============================================================

// ==============================================================
// 100% RELIABLE EMAIL SENDER FUNCTION (ALWAYSDATA SMTP)
// ==============================================================
if (!function_exists('send_email_via_smtp_gmail')) {
    function send_email_via_smtp_gmail($to, $subject, $body) {
        $username = 'maternityhub@alwaysdata.net'; 
        $password = 'Eirean252004!'; 
        $host = 'ssl://smtp-maternityhub.alwaysdata.net'; 
        $port = 465;
        
        $socket = fsockopen($host, $port, $errno, $errstr, 15);
        if (!$socket) return "Socket Error: $errstr ($errno)";
        
        $read_res = function($socket) {
            $res = '';
            while ($line = fgets($socket, 515)) {
                $res .= $line;
                if (substr($line, 3, 1) == ' ') { break; }
            }
            return $res;
        };

        $read_res($socket); 
        fputs($socket, "EHLO " . $_SERVER['HTTP_HOST'] . "\r\n"); $read_res($socket); 
        fputs($socket, "AUTH LOGIN\r\n"); $read_res($socket); 
        fputs($socket, base64_encode($username) . "\r\n"); $read_res($socket); 
        fputs($socket, base64_encode($password) . "\r\n"); 
        $auth_response = $read_res($socket); 
        
        if (strpos($auth_response, '235') === false) {
            fclose($socket);
            return false;
        }
        
        fputs($socket, "MAIL FROM: <$username>\r\n"); $read_res($socket); 
        fputs($socket, "RCPT TO: <$to>\r\n"); $read_res($socket);
        fputs($socket, "DATA\r\n"); $read_res($socket);
        
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/html; charset=UTF-8\r\n";
        $headers .= "From: MaternityHub System <$username>\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        
        fputs($socket, "$headers\r\n\r\n$body\r\n.\r\n"); 
        $send_response = $read_res($socket); 
        fputs($socket, "QUIT\r\n"); fclose($socket);
        
        return (strpos($send_response, '250') !== false) ? true : false;
    }
}
// ==============================================================

// ==============================================================
// --- LOGOUT HANDLER (BULLETPROOF VERSION) ---
// ==============================================================
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    if (isset($_SESSION['full_name'])) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $isSuperAdmin = (strtolower($logoutRole) === 'superadmin');
            $auditRole = $isSuperAdmin ? 'SuperAdmin' : $logoutRole;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');
            
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, 'Logout', 'Super Admin safely logged out of system settings.', ?, ?)");
            $stmtLog->execute([$logoutName, $auditRole, $ip, $currentTime]);
        } catch (Exception $e) {
            die("<div style='background:black; color:red; padding:50px; text-align:center; font-size:24px; font-family:sans-serif;'><b>DATABASE ERROR SA LOGOUT:</b><br><br>" . $e->getMessage() . "</div>");
        }
    }
    
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}
// ==============================================================

// --- SYSTEM SETTINGS (JSON BASED) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';

// Default Terms and Conditions (used if walang naka-save sa JSON)
$defaultTerms = <<<TERMS
MATERNITYHUB - TERMS AND CONDITIONS
Last Updated: May 2026

1. Acceptance of Terms
By accessing and using the MaternityHub Platform ("System"), the subscribing Clinic, its administration, and its authorized medical staff ("Users") agree to be bound by these Terms and Conditions. If you do not agree to these terms, you must strictly refrain from using the System.

2. Nature of Service & Medical Disclaimer
MaternityHub is a Software-as-a-Service (SaaS) platform designed solely to assist healthcare facilities with administrative workflow, billing computation, and Electronic Medical Record (EMR) management.

Not Medical Advice: The System is an administrative tool, not a diagnostic one. Automated calculations such as Age of Gestation (AOG) and Estimated Date of Delivery (EDD) must always be manually verified by a licensed medical professional.

No Medical Liability: The developers of MaternityHub shall not be held liable for any clinical decisions, medical malpractice, patient complications, or adverse outcomes resulting from the use or misuse of the data encoded in the System.

3. Data Privacy and Patient Records
As a healthcare platform, MaternityHub operates in strict compliance with the Republic Act No. 10173 (Data Privacy Act of 2012).

User Responsibility: The subscribing Clinic acts as the sole Personal Information Controller (PIC). It is the exclusive responsibility of the Clinic to obtain explicit, informed consent from patients before encoding sensitive obstetric history (e.g., Gravida, Para, Prenatal Logs) into the System.

Data Security: Users are responsible for safeguarding their login credentials to prevent unauthorized access to patient records.

4. Financial and Billing Accuracy
The System provides automated billing modules based on predefined Maternity Packages, room tiering, and PhilHealth Case Rate deductions.

Bundled Pricing & Manual Input: The System computes patient bills based on the fixed package rates established by the Clinic. MaternityHub is not liable for revenue losses, double-charging, or erroneous billing resulting from untracked room transfers or the improper selection of maternity packages by the clinic's staff.

Regulatory Compliance: It is the Clinic's exclusive responsibility to ensure that their bundled maternity packages and selected PhilHealth case rates (e.g., NSD01 for Normal Delivery) comply with the latest government circulars and the No Balance Billing policy.

5. Account Deactivation and Termination
MaternityHub enforces strict subscription validity and reserves the right to terminate or restrict a Clinic's access to the System under the following circumstances:

a. Automatic Subscription Expiration: Access to the System is bound by an active subscription timeframe. Once the subscription period lapses, the System will automatically deactivate the Clinic's account. During this deactivated state, the Clinic will automatically be downgraded to "Read-Only" access—allowing them to retrieve existing patient records for continuity of care, but restricting them from encoding new admissions or generating new bills until the subscription is renewed.

b. Fraudulent Activities and PhilHealth Anomalies: Any proven or highly suspected use of the System to generate fake patient records, manipulate obstetric data, or create anomalous billing reports intended to defraud the Philippine Health Insurance Corporation (PhilHealth) or any other HMO.

c. Data Privacy Violations: Unauthorized extraction of patient Electronic Medical Records (EMR), sharing of administrative login credentials, or any action that breaches patient confidentiality.

d. Illegal Medical Practices: Utilization of the System to document, track, or manage medical procedures that are prohibited under Philippine law.

In the event of account termination due to system abuse or fraud, MaternityHub is legally obligated to fully cooperate with government authorities and may turn over necessary system logs for investigation.

6. Limitation of Liability
In no event shall MaternityHub, its developers, or affiliates be liable for any direct, indirect, incidental, or consequential damages—including but not limited to loss of data, loss of revenue, or clinical disputes—arising out of the use or inability to use the System.
TERMS;

if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode([
        'maintenance_mode' => false,
        'super_theme_color' => '#10b981',
        'super_logo' => null, 
        'super_hero_image' => null, 
        'allow_new_registrations' => true,
        'system_email' => 'support@maternityhub.com',
        'session_timeout' => 30,
        'platform_owner_email' => 'admin@maternityhub.com', // Generic default
        'terms_and_conditions' => $defaultTerms
    ]));
}

$settings = json_decode(file_get_contents($settingsFile), true);
$maintenanceMode = $settings['maintenance_mode'] ?? false;
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';
$superLogo = $settings['super_logo'] ?? null; 
$superHero = $settings['super_hero_image'] ?? null; 
$allowRegistrations = $settings['allow_new_registrations'] ?? true;
$systemEmail = $settings['system_email'] ?? 'support@maternityhub.com';
$sessionTimeout = $settings['session_timeout'] ?? 30;
$platformOwnerEmail = strtolower(trim($settings['platform_owner_email'] ?? 'admin@maternityhub.com'));
$termsAndConditions = $settings['terms_and_conditions'] ?? $defaultTerms;

$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;
$superHeroPath = ($superHero && file_exists(__DIR__ . '/uploads/logos/' . $superHero)) ? 'uploads/logos/' . $superHero : null;

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR
// ==============================================================
$hex = ltrim($superThemeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);

$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerBgOp = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/10';
$headerBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
$headerHoverOp = $isLightTheme ? 'hover:bg-slate-900/20' : 'hover:bg-white/20';
$badgeTheme = $isLightTheme ? 'text-amber-800 bg-amber-400/40 border-amber-500/30' : 'text-amber-300 bg-amber-400/20 border-amber-300/30';

// --- FETCH ALL SUPER ADMINS MULA SA DATABASE ---
try {
    $stmtAdmins = $pdo->query("SELECT id, first_name, last_name, email, created_at, role, status FROM users WHERE LOWER(role) = 'superadmin' ORDER BY id ASC");
    $superAdmins = $stmtAdmins->fetchAll(PDO::FETCH_ASSOC);

    // I-check kung nag-eexist ba yung Platform Owner sa loob ng Database
    $ownerIndex = -1;
    foreach ($superAdmins as $index => $admin) {
        if (strtolower(trim($admin['email'])) === $platformOwnerEmail) {
            $ownerIndex = $index;
            break;
        }
    }

    // KUNG WALA sa database ang owner email, i-auto assign natin ang pinaka-unang Super Admin bilang bagong Platform Owner
    if ($ownerIndex === -1 && count($superAdmins) > 0) {
        $platformOwnerEmail = strtolower(trim($superAdmins[0]['email']));
        $settings['platform_owner_email'] = $platformOwnerEmail;
        file_put_contents($settingsFile, json_encode($settings));
        $ownerIndex = 0;
    }

    // Ilagay ang Platform Owner sa pinakataas (Index 0) ng listahan!
    if ($ownerIndex > 0) {
        $ownerData = $superAdmins[$ownerIndex];
        unset($superAdmins[$ownerIndex]); 
        array_unshift($superAdmins, $ownerData); 
        $superAdmins = array_values($superAdmins); 
    }

    // Kung wala talagang superadmin na naka-save sa database (fresh install), magpapakita lang ng dummy account
    if (count($superAdmins) === 0) {
        $masterAccount = [
            'id' => 'MASTER',
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'email' => $platformOwnerEmail,
            'created_at' => date('Y-m-d H:i:s'), 
            'role' => 'SuperAdmin',
            'status' => 'Active'
        ];
        array_unshift($superAdmins, $masterAccount); 
    }

    // Kunin ang mga active admins na pwedeng pagpasan ng ownership
    $availableForTransfer = [];
    foreach ($superAdmins as $admin) {
        if (strtolower(trim($admin['email'])) !== $platformOwnerEmail && $admin['status'] === 'Active' && $admin['id'] !== 'MASTER') {
            $availableForTransfer[] = $admin; 
        }
    }

} catch (PDOException $e) {
    die("Query failed: " . $e->getMessage());
}

// --- SUPER ADMIN SECURITY CHECK ---
$isSuperAdmin = false;
$currentEmail = strtolower(trim($_SESSION['email'] ?? ''));
if (isset($_SESSION['user_id'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    if ($role === 'superadmin' || $currentEmail === $platformOwnerEmail) {
        $isSuperAdmin = true; 
    }
}

if (!$isSuperAdmin) {
    echo "<script>window.location.href = 'index.php';</script>";
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';
$isPlatformOwner = ($currentEmail === $platformOwnerEmail);

$error = null;
$msg = $_GET['msg'] ?? null;

// --- ACTIONS: TRANSFER OWNERSHIP ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transfer_ownership'])) {
    $newOwnerId = trim($_POST['new_owner_id']);
    $passwordConfirm = $_POST['master_password'];

    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the current Platform Owner can transfer ownership.";
    } else {
        try {
            $stmtVerify = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmtVerify->execute([$_SESSION['user_id']]);
            $currentMaster = $stmtVerify->fetch(PDO::FETCH_ASSOC);

            if ($currentMaster && password_verify($passwordConfirm, $currentMaster['password'])) {
                $stmtNew = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ? AND LOWER(role) = 'superadmin'");
                $stmtNew->execute([$newOwnerId]);
                $newOwner = $stmtNew->fetch(PDO::FETCH_ASSOC);

                if ($newOwner) {
                    $settings['platform_owner_email'] = strtolower(trim($newOwner['email']));
                    file_put_contents($settingsFile, json_encode($settings));
                    
                    $newOwnerName = trim($newOwner['first_name'] . ' ' . $newOwner['last_name']);
                    log_audit($pdo, $displayName, 'SuperAdmin', 'Security Update', "Transferred Platform Ownership to $newOwnerName ({$newOwner['email']}).");
                    
                    header("Location: systemsettings.php?msg=ownership_transferred");
                    exit();
                } else {
                    $error = "Selected user is not a valid Super Admin in the database.";
                }
            } else {
                $error = "Incorrect password. Ownership transfer failed.";
            }
        } catch (PDOException $e) {
            $error = "Transfer Error: " . $e->getMessage();
        }
    }
}

// --- ACTIONS: TOGGLE MAINTENANCE MODE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance'])) {
    $maintenanceMode = !$maintenanceMode;
    $settings['maintenance_mode'] = $maintenanceMode;
    file_put_contents($settingsFile, json_encode($settings));
    
    $statusStr = $maintenanceMode ? 'ON' : 'OFF';
    log_audit($pdo, $displayName, 'SuperAdmin', 'Settings Change', "System Maintenance Mode turned $statusStr.");

    header("Location: systemsettings.php?msg=" . ($maintenanceMode ? 'maintenance_on' : 'maintenance_off'));
    exit();
}

// --- ACTIONS: UPDATE UI SETTINGS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ui_settings'])) {
    $newColor = trim($_POST['theme_color']);
    $uiUpdated = false;

    if (preg_match('/^#[a-f0-9]{6}$/i', $newColor)) {
        $settings['super_theme_color'] = $newColor;
        $uiUpdated = true;
    } else {
        $error = "Invalid color format.";
    }

    $uploadDir = __DIR__ . '/uploads/logos/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

    if (isset($_FILES['super_logo']) && $_FILES['super_logo']['error'] === UPLOAD_ERR_OK) {
        $fileInfo = pathinfo($_FILES['super_logo']['name']);
        $ext = strtolower($fileInfo['extension']);
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed) && $_FILES['super_logo']['size'] <= 5000000) {
            $fileName = 'super_logo_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['super_logo']['tmp_name'], $uploadDir . $fileName)) {
                $settings['super_logo'] = $fileName;
                $uiUpdated = true;
            } else { $error = "Failed to upload logo file."; }
        } else { $error = "Invalid file type or size exceeds 5MB."; }
    }

    if (isset($_FILES['super_hero_image']) && $_FILES['super_hero_image']['error'] === UPLOAD_ERR_OK) {
        $fileInfo = pathinfo($_FILES['super_hero_image']['name']);
        $ext = strtolower($fileInfo['extension']);
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed) && $_FILES['super_hero_image']['size'] <= 5000000) {
            $fileName = 'super_hero_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['super_hero_image']['tmp_name'], $uploadDir . $fileName)) {
                $settings['super_hero_image'] = $fileName;
                $uiUpdated = true;
            } else { $error = "Failed to upload hero image file."; }
        } else { $error = "Invalid file type or size exceeds 5MB for hero image."; }
    }

    if ($uiUpdated && !$error) {
        file_put_contents($settingsFile, json_encode($settings));
        log_audit($pdo, $displayName, 'SuperAdmin', 'UI Update', "Updated Platform Branding/Theme Settings (Color, Logo, or Hero Image).");
        header("Location: systemsettings.php?msg=ui_updated");
        exit();
    }
}

// --- ACTIONS: UPDATE TERMS AND CONDITIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_terms'])) {
    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the Platform Owner can update the Terms and Conditions.";
    } else {
        $newTerms = trim($_POST['terms_text'] ?? '');
        if ($newTerms === '') {
            $error = "Terms and Conditions content cannot be empty.";
        } else {
            $settings['terms_and_conditions'] = $newTerms;
            file_put_contents($settingsFile, json_encode($settings));
            log_audit($pdo, $displayName, 'SuperAdmin', 'Settings Change', "Updated platform Terms and Conditions.");
            header("Location: systemsettings.php?msg=terms_updated");
            exit();
        }
    }
}

// --- ACTIONS: ADD NEW SUPER ADMIN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_superadmin'])) {
    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the Platform Owner can add new Global Administrators.";
    } else {
    $fname = trim($_POST['first_name']);
    $lname = trim($_POST['last_name']);
    $email = strtolower(trim($_POST['email']));
    $pass = $_POST['password'];
    $cpass = $_POST['confirm_password'];

    if (empty($fname) || empty($lname) || empty($email) || empty($pass)) {
        $error = "All fields are required.";
    } elseif ($pass !== $cpass) {
        $error = "Passwords do not match.";
    } elseif (strlen($pass) < 8) {
        $error = "Password must be at least 8 characters long.";
    } else {
        try {
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmtCheck->execute([$email]);
            
            if ($stmtCheck->fetch()) {
                $error = "Email address is already used by another account.";
            } else {
                $hashed = password_hash($pass, PASSWORD_DEFAULT);
                $stmtInsert = $pdo->prepare("INSERT INTO users (first_name, last_name, email, password, role, status, TenantID) VALUES (?, ?, ?, ?, 'SuperAdmin', 'Active', NULL)");
                $stmtInsert->execute([$fname, $lname, $email, $hashed]);
                
                log_audit($pdo, $displayName, 'SuperAdmin', 'Admin Created', "Added new Super Admin account: $fname $lname ($email).");
                header("Location: systemsettings.php?msg=admin_added");
                exit();
            }
        } catch (PDOException $e) { $error = "Database Error: " . $e->getMessage(); }
    }
    }
}

// --- ACTIONS: CHANGE SUPER ADMIN PASSWORD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_admin_password'])) {
    $targetAdminId = trim($_POST['target_admin_id'] ?? '');
    $newPassword = $_POST['new_password'] ?? '';
    $confirmNewPassword = $_POST['confirm_new_password'] ?? '';

    if ($targetAdminId === '' || $newPassword === '' || $confirmNewPassword === '') {
        $error = "All password fields are required.";
    } elseif ($newPassword !== $confirmNewPassword) {
        $error = "New password and confirmation do not match.";
    } elseif (strlen($newPassword) < 8) {
        $error = "New password must be at least 8 characters long.";
    } else {
        try {
            $stmtTargetAdmin = $pdo->prepare("SELECT id, email, first_name, last_name FROM users WHERE id = ? AND LOWER(role) = 'superadmin'");
            $stmtTargetAdmin->execute([$targetAdminId]);
            $targetAdmin = $stmtTargetAdmin->fetch(PDO::FETCH_ASSOC);

            if (!$targetAdmin) {
                $error = "Selected administrator account was not found.";
            } elseif (intval($targetAdminId) !== intval($_SESSION['user_id'] ?? 0)) {
                $error = "ACCESS DENIED: You can only change your own password.";
            } else {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtPasswordUpdate = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmtPasswordUpdate->execute([$hashedPassword, $targetAdminId]);
                
                $targetName = trim($targetAdmin['first_name'] . ' ' . $targetAdmin['last_name']);
                log_audit($pdo, $displayName, 'SuperAdmin', 'Security Update', "Changed password for Super Admin: $targetName ({$targetAdmin['email']}).");

                header("Location: systemsettings.php?msg=admin_password_updated");
                exit();
            }
        } catch (PDOException $e) { $error = "Password Update Error: " . $e->getMessage(); }
    }
}

// --- ACTIONS: SUSPEND SUPER ADMIN ---
if (isset($_GET['suspend_admin'])) {
    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the Platform Owner can suspend administrators.";
    } else {
        $susp_id = $_GET['suspend_admin'];
        try {
            $stmtCheck = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $stmtCheck->execute([$susp_id]);
            $suspUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($suspUser) {
                if (strtolower($suspUser['email']) === $platformOwnerEmail) {
                    $error = "ACCESS DENIED: Cannot suspend the Master Platform Owner.";
                } elseif (strtolower($suspUser['email']) === $currentEmail) {
                    $error = "You cannot suspend your own account.";
                } else {
                    $stmtUpdate = $pdo->prepare("UPDATE users SET status = 'Suspended' WHERE id = ?");
                    $stmtUpdate->execute([$susp_id]);

                    $suspName = trim($suspUser['first_name'] . ' ' . $suspUser['last_name']);
                    log_audit($pdo, $displayName, 'SuperAdmin', 'Security Update', "Suspended Super Admin account: $suspName ({$suspUser['email']}).");

                    header("Location: systemsettings.php?msg=admin_suspended");
                    exit();
                }
            }
        } catch (PDOException $e) { $error = "Suspend Error: " . $e->getMessage(); }
    }
}

// --- ACTIONS: ACTIVATE SUPER ADMIN ---
if (isset($_GET['activate_admin'])) {
    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the Platform Owner can activate administrators.";
    } else {
        $act_id = $_GET['activate_admin'];
        try {
            $stmtCheck = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
            $stmtCheck->execute([$act_id]);
            $actUser = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($actUser) {
                $stmtUpdate = $pdo->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
                $stmtUpdate->execute([$act_id]);

                $actName = trim($actUser['first_name'] . ' ' . $actUser['last_name']);
                log_audit($pdo, $displayName, 'SuperAdmin', 'Security Update', "Reactivated Super Admin account: $actName ({$actUser['email']}).");

                header("Location: systemsettings.php?msg=admin_activated");
                exit();
            }
        } catch (PDOException $e) { $error = "Activate Error: " . $e->getMessage(); }
    }
}

// --- ACTIONS: DELETE SUPER ADMIN (WITH REASON + EMAIL) ---
if (isset($_POST['delete_admin_id'])) {
    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the Platform Owner can delete administrators.";
    } else {
        $del_id = intval($_POST['delete_admin_id']);
        $revoke_reason = trim($_POST['revoke_reason'] ?? '');
        if (empty($revoke_reason)) {
            $error = "Please provide a reason for revoking this administrator.";
        } else {
            try {
                $stmtDelCheck = $pdo->prepare("SELECT email, first_name, last_name FROM users WHERE id = ?");
                $stmtDelCheck->execute([$del_id]);
                $delUser = $stmtDelCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($delUser) {
                    if (strtolower($delUser['email']) === $platformOwnerEmail) {
                        $error = "ACCESS DENIED: You cannot delete the Master Platform Owner account.";
                    } elseif (strtolower($delUser['email']) === $currentEmail) {
                        $error = "You cannot delete your own account while logged in.";
                    } else {
                        $delName = trim($delUser['first_name'] . ' ' . $delUser['last_name']);
                        $delEmail = $delUser['email'];
                        
                        $stmtDelete = $pdo->prepare("DELETE FROM users WHERE id = ?");
                        $stmtDelete->execute([$del_id]);

                        log_audit($pdo, $displayName, 'SuperAdmin', 'Admin Deleted', "Revoked access and permanently deleted Super Admin account: $delName ($delEmail). Reason: $revoke_reason");

                        // Send email notification to the revoked admin
                        $revokeDate = date('F d, Y - h:i A');
                        $emailBody = "
                        <div style='font-family: \"Segoe UI\", Arial, sans-serif; max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; border: 1px solid #e2e8f0;'>
                            <div style='background: linear-gradient(135deg, #dc2626, #991b1b); padding: 40px 30px; text-align: center;'>
                                <div style='width: 60px; height: 60px; background: rgba(255,255,255,0.15); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;'>
                                    <span style='font-size: 28px; color: white;'>&#128274;</span>
                                </div>
                                <h1 style='color: #ffffff; font-size: 20px; font-weight: 800; margin: 0; letter-spacing: -0.5px;'>Access Revoked</h1>
                                <p style='color: rgba(255,255,255,0.8); font-size: 12px; margin-top: 6px; font-weight: 600;'>MaternityHub Platform</p>
                            </div>
                            <div style='padding: 30px;'>
                                <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 20px;'>
                                    Dear <strong>$delName</strong>,
                                </p>
                                <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 20px;'>
                                    Your Super Admin access to the <strong>MaternityHub Platform</strong> has been permanently revoked and your account has been deleted.
                                </p>
                                <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 20px; margin: 0 0 20px;'>
                                    <p style='color: #991b1b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin: 0 0 8px;'>Reason for Revocation</p>
                                    <p style='color: #dc2626; font-size: 14px; font-weight: 600; margin: 0; line-height: 1.6;'>" . htmlspecialchars($revoke_reason) . "</p>
                                </div>
                                <div style='background: #f8fafc; border-radius: 12px; padding: 16px; margin: 0 0 20px;'>
                                    <table style='width: 100%; border-collapse: collapse;'>
                                        <tr><td style='color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 0;'>Revoked By</td><td style='color: #334155; font-size: 13px; font-weight: 700; text-align: right; padding: 4px 0;'>" . htmlspecialchars($displayName) . "</td></tr>
                                        <tr><td style='color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 0;'>Date & Time</td><td style='color: #334155; font-size: 13px; font-weight: 700; text-align: right; padding: 4px 0;'>$revokeDate</td></tr>
                                    </table>
                                </div>
                                <p style='color: #94a3b8; font-size: 12px; line-height: 1.6; margin: 0;'>
                                    If you believe this was done in error, please contact the Platform Owner directly.
                                </p>
                            </div>
                            <div style='background: #f8fafc; padding: 16px 30px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                <p style='color: #cbd5e1; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin: 0;'>MaternityHub &copy; " . date('Y') . "</p>
                            </div>
                        </div>";
                        
                        send_email_via_smtp_gmail($delEmail, "Access Revoked - MaternityHub Platform", $emailBody);

                        header("Location: systemsettings.php?msg=admin_deleted");
                        exit();
                    }
                }
            } catch (PDOException $e) { $error = "Delete Error: " . $e->getMessage(); }
        }
    }
}

// --- ACTIONS: BACKUP DATA (PDF via print) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['backup_database'])) {
    if (!$isPlatformOwner) {
        $error = "ACCESS DENIED: Only the Platform Owner can backup data.";
    } else {
        try {
            // 1) TENANTS DATA
            $stmt = $pdo->query("SELECT * FROM tenants ORDER BY created_at DESC");
            $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 2) USERS (non-superadmin)
            $stmt = $pdo->query("
                SELECT u.first_name, u.last_name, u.email, u.role, u.status, u.TenantID, u.created_at, t.clinic_name
                FROM users u LEFT JOIN tenants t ON u.TenantID = t.TenantID
                WHERE LOWER(u.role) != 'superadmin'
                ORDER BY u.created_at DESC
            ");
            $userRegs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 3) PATIENTS
            $stmt = $pdo->query("SELECT * FROM patients ORDER BY id DESC");
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 4) AUDIT LOGS — Super Admin
            $stmt = $pdo->query("
                SELECT * FROM audit_logs
                WHERE (TenantID IS NULL OR TenantID = '') AND LOWER(role) IN ('superadmin', 'system')
                ORDER BY created_at DESC
            ");
            $superLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 5) AUDIT LOGS — Clinics
            $stmt = $pdo->query("
                SELECT a.*, t.clinic_name
                FROM audit_logs a LEFT JOIN tenants t ON a.TenantID = t.TenantID
                WHERE (a.TenantID IS NOT NULL AND a.TenantID != '') OR LOWER(a.role) NOT IN ('superadmin', 'system')
                ORDER BY a.created_at DESC
            ");
            $clinicLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);

            log_audit($pdo, $displayName, 'SuperAdmin', 'Data Backup', "Generated data backup PDF (tenants, users, patients, audit logs).");

            // --- Helper: mask sensitive string ---
            $maskStr = function($val) {
                $val = (string)$val;
                $len = mb_strlen($val);
                if ($len <= 2) return str_repeat('*', $len);
                if ($len <= 5) return mb_substr($val, 0, 1) . str_repeat('*', $len - 1);
                return mb_substr($val, 0, 2) . str_repeat('*', $len - 3) . mb_substr($val, -1);
            };

            // --- Helper: mask email ---
            $maskEmail = function($val) {
                $val = (string)$val;
                if (strpos($val, '@') === false) return str_repeat('*', mb_strlen($val));
                list($user, $domain) = explode('@', $val, 2);
                $uLen = mb_strlen($user);
                if ($uLen <= 2) $masked = str_repeat('*', $uLen);
                else $masked = mb_substr($user, 0, 2) . str_repeat('*', $uLen - 2);
                return $masked . '@' . $domain;
            };

            // --- Sensitive patient columns to mask ---
            $sensitivePatientCols = [
                'full_name', 'husband_name', 'father_name', 'mother_name',
                'contact_number', 'address', 'email_address',
                'medical_history', 'philhealth_id', 'occupation'
            ];

            // --- Mask patient rows ---
            foreach ($patients as &$pRow) {
                foreach ($pRow as $col => &$val) {
                    if ($val === null || $val === '') continue;
                    $colLower = strtolower($col);
                    if ($colLower === 'email_address') {
                        $val = $maskEmail($val);
                    } elseif (in_array($colLower, $sensitivePatientCols)) {
                        $val = $maskStr($val);
                    }
                }
                unset($val);
            }
            unset($pRow);

            // --- Helper: render HTML table from data ---
            $renderTable = function(array $rows) {
                if (empty($rows)) return '<p style="color:#94a3b8;font-style:italic;padding:12px 0;">No records found.</p>';
                $headers = array_keys($rows[0]);
                $html = '<table><thead><tr>';
                foreach ($headers as $h) {
                    $html .= '<th>' . htmlspecialchars($h) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($rows as $row) {
                    $html .= '<tr>';
                    foreach ($headers as $h) {
                        $val = $row[$h] ?? '';
                        $html .= '<td>' . htmlspecialchars((string)$val) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                return $html;
            };

            $genDate = date('F d, Y - h:i A');

            // Output the printable HTML page
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>MaternityHub Data Backup - ' . date('Y-m-d') . '</title>';
            echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.2/html2pdf.bundle.min.js"></script>';
            echo '<style>
                @page { size: landscape; margin: 12mm; }
                * { box-sizing: border-box; margin: 0; padding: 0; }
                body { font-family: "Segoe UI", Arial, sans-serif; font-size: 9px; color: #1e293b; background: #fff; padding: 20px; }
                .header { text-align: center; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 3px solid #0f172a; }
                .header h1 { font-size: 20px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: 1px; }
                .header p { font-size: 10px; color: #64748b; margin-top: 4px; }
                .section { margin-bottom: 20px; page-break-inside: avoid; }
                .section-title { font-size: 13px; font-weight: 900; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px; padding: 8px 12px; background: #f1f5f9; border-left: 4px solid #0f172a; }
                table { width: 100%; border-collapse: collapse; font-size: 8px; margin-bottom: 4px; }
                th { background: #0f172a; color: #fff; padding: 6px 8px; text-align: left; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; font-size: 7px; white-space: nowrap; }
                td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; word-break: break-word; max-width: 200px; }
                tr:nth-child(even) { background: #f8fafc; }
                .footer { text-align: center; margin-top: 24px; padding-top: 12px; border-top: 2px solid #e2e8f0; font-size: 9px; color: #94a3b8; }
                .top-bar { position: fixed; top: 0; left: 0; right: 0; background: #0f172a; color: #fff; padding: 12px 24px; display: flex; align-items: center; justify-content: space-between; z-index: 999; font-size: 13px; font-weight: 700; }
                .top-bar button { background: #fff; color: #0f172a; border: none; padding: 8px 24px; border-radius: 8px; font-weight: 800; font-size: 12px; cursor: pointer; text-transform: uppercase; letter-spacing: 1px; }
                .top-bar button:hover { background: #e2e8f0; }
                .top-bar button:disabled { opacity: 0.5; cursor: not-allowed; }
                .top-spacer { height: 56px; }
            </style></head><body>';
            
            echo '<div class="top-bar" id="topBar"><span>MaternityHub Data Backup</span><div><button id="dlBtn" onclick="downloadPDF()">&#128196; Download PDF</button> <button onclick="window.close()" style="margin-left:8px;background:#ef4444;color:#fff;">&#10005; Close</button></div></div>';
            echo '<div class="top-spacer"></div>';

            echo '<div id="pdfContent">';
            echo '<div class="header">';
            echo '<h1>MaternityHub Data Backup</h1>';
            echo '<p>Generated on ' . $genDate . ' by ' . htmlspecialchars($displayName) . '</p>';
            echo '</div>';

            echo '<div class="section"><div class="section-title">Tenants / Clinics (' . count($tenants) . ' records)</div>' . $renderTable($tenants) . '</div>';
            echo '<div class="section"><div class="section-title">Registered Users (' . count($userRegs) . ' records)</div>' . $renderTable($userRegs) . '</div>';
            echo '<div class="section"><div class="section-title">Patients (' . count($patients) . ' records) &mdash; <span style="color:#dc2626;font-size:10px;">&#128274; Sensitive data encrypted</span></div>' . $renderTable($patients) . '</div>';
            echo '<div class="section"><div class="section-title">Audit Logs — Super Admin (' . count($superLogs) . ' records)</div>' . $renderTable($superLogs) . '</div>';
            echo '<div class="section"><div class="section-title">Audit Logs — Clinics (' . count($clinicLogs) . ' records)</div>' . $renderTable($clinicLogs) . '</div>';

            echo '<div class="footer">MaternityHub &copy; ' . date('Y') . ' &mdash; Confidential Data Backup</div>';
            echo '</div>'; // close #pdfContent

            echo '<script>
            function downloadPDF() {
                var btn = document.getElementById("dlBtn");
                btn.textContent = "Generating...";
                btn.disabled = true;
                var el = document.getElementById("pdfContent");
                var opt = {
                    margin: [8, 8, 8, 8],
                    filename: "maternityhub_backup_' . date('Y-m-d_His') . '.pdf",
                    image: { type: "jpeg", quality: 0.98 },
                    html2canvas: { scale: 2, useCORS: true, scrollY: 0 },
                    jsPDF: { unit: "mm", format: "a4", orientation: "landscape" },
                    pagebreak: { mode: ["avoid-all", "css", "legacy"] }
                };
                html2pdf().set(opt).from(el).save().then(function() {
                    btn.textContent = "\u{1F4C4} Download PDF";
                    btn.disabled = false;
                });
            }
            </script>';

            echo '</body></html>';
            exit();
        } catch (Exception $e) {
            $error = "Backup Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>System Settings - MaternityHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($superThemeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 20%, white)",
                        "super": "#0f172a", "background-light": "#f8fafc"
                    }, 
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
                } 
            } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Closing session safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl border border-slate-100">
        <div class="text-center">
            <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-2xl">logout</span>
            </div>
            <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
            <p class="text-slate-500 text-[11px] mb-6">Are you sure you want to end your Super Admin session?</p>
            <div class="flex gap-2">
                <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
                <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
            </div>
        </div>
    </div>
</div>

<header class="h-20 bg-primary border-b border-primary-dark flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft transition-colors duration-300 <?= $headerText ?> no-print">
    <div class="flex items-center gap-4">
        <div class="size-12 rounded-2xl <?= $headerBgOp ?> flex items-center justify-center shrink-0 border <?= $headerBorderOp ?>">
            <span class="material-symbols-outlined text-2xl">admin_panel_settings</span>
        </div>
        <div class="flex flex-col justify-center">
            <h1 class="text-lg font-bold leading-none tracking-tight">MaternityHub Platform</h1>
            <p class="text-[10px] font-bold uppercase tracking-widest mt-1 opacity-80">SUPER ADMIN PORTAL</p>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="text-[9px] mt-1 uppercase tracking-widest opacity-80"><?= $isPlatformOwner ? 'Platform Owner' : 'Global Admin' ?></p>
        </div>
        <button onclick="openLogoutModal()" class="flex items-center gap-2 <?= $headerBgOp ?> <?= $headerHoverOp ?> border <?= $headerBorderOp ?> px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
            <span class="material-symbols-outlined text-sm">logout</span><span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-72 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 shadow-soft z-10 no-print">
        <nav class="flex-1 p-6 h-full flex flex-col gap-2 overflow-y-auto">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-4">Platform Management</p>
            <a href="superadmin.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span class="text-base">Dashboard</span>
            </a>
            <a href="tenantmanagement.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">domain</span> <span class="text-base">Tenant Management</span>
            </a>
            <a href="systemreports.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">bar_chart</span> <span class="text-base">System Reports</span>
            </a>
            <a href="salesreport.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">point_of_sale</span> <span class="text-base">Sales Report</span>
            </a>
            <a href="auditlogs.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">history</span> <span class="text-base">Audit Logs</span>
            </a>
            <a href="helpdesk.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">support_agent</span> <span class="text-base">Helpdesk Tickets</span>
            </a>
            <a href="systemsettings.php" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl">settings</span> <span class="text-base">System Settings</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
        <div class="max-w-7xl mx-auto space-y-6 pb-20">
            
            <?php if($msg === 'ui_updated'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200">
                    <span class="material-symbols-outlined">palette</span> Platform branding has been successfully updated!
                </div>
            <?php endif; ?>

            <?php if($msg === 'admin_added'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200">
                    <span class="material-symbols-outlined">check_circle</span> New Super Admin account successfully created!
                </div>
            <?php endif; ?>

            <?php if($msg === 'admin_deleted'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-red-100 text-red-800 border border-red-200">
                    <span class="material-symbols-outlined">delete_forever</span> Super Admin access has been revoked and the account is deleted.
                </div>
            <?php endif; ?>

            <?php if($msg === 'admin_suspended'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-amber-100 text-amber-800 border border-amber-200">
                    <span class="material-symbols-outlined">block</span> Super Admin account has been suspended.
                </div>
            <?php endif; ?>

            <?php if($msg === 'admin_activated'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200">
                    <span class="material-symbols-outlined">check_circle</span> Super Admin account has been reactivated.
                </div>
            <?php endif; ?>

            <?php if($msg === 'admin_password_updated'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-blue-100 text-blue-800 border border-blue-200">
                    <span class="material-symbols-outlined">lock_reset</span> Super Admin password updated successfully.
                </div>
            <?php endif; ?>

            <?php if($msg === 'ownership_transferred'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-indigo-100 text-indigo-800 border border-indigo-200">
                    <span class="material-symbols-outlined">verified_user</span> Platform Ownership has been successfully transferred!
                </div>
            <?php endif; ?>
            
            <?php if($msg === 'maintenance_on'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-amber-100 text-amber-800 border border-amber-200">
                    <span class="material-symbols-outlined">construction</span> Maintenance Mode is ON. All clinic logins are now blocked.
                </div>
            <?php endif; ?>

            <?php if($msg === 'maintenance_off'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200">
                    <span class="material-symbols-outlined">check_circle</span> Maintenance Mode is OFF. Clinics and staff can now log in.
                </div>
            <?php endif; ?>

            <?php if($msg === 'terms_updated'): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-emerald-100 text-emerald-800 border border-emerald-200">
                    <span class="material-symbols-outlined">gavel</span> Terms and Conditions has been successfully updated!
                </div>
            <?php endif; ?>

            <?php if($error): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-red-100 text-red-800 border border-red-200">
                    <span class="material-symbols-outlined">error</span> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <div class="mb-8">
                <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">System Settings</h2>
                <p class="text-slate-500 text-sm font-medium tracking-tight">Manage the core functionality, UI, and administrators of MaternityHub.</p>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6">
                <div class="xl:col-span-1">
                    <div class="bg-white p-7 rounded-[24px] border border-slate-200 shadow-sm flex flex-col justify-between h-full">
                        <div>
                            <div class="flex items-center gap-4 mb-5 pb-5 border-b border-slate-100/80">
                                <div class="size-[48px] rounded-2xl <?= $maintenanceMode ? 'bg-red-50 text-red-500' : 'bg-[#fff8eb] text-[#f59e0b]' ?> flex items-center justify-center shrink-0">
                                    <span class="material-symbols-outlined text-[24px]">build</span>
                                </div>
                                <div class="flex flex-col justify-center">
                                    <h3 class="text-[15px] font-black text-[#0f172a] uppercase tracking-tight leading-none mb-1.5">Maintenance Mode</h3>
                                    <p class="text-[11px] text-slate-400 font-bold uppercase tracking-widest leading-none">Platform Kill Switch</p>
                                </div>
                            </div>
                            
                            <form method="POST" action="systemsettings.php" class="mb-6">
                                <input type="hidden" name="toggle_maintenance" value="1">
                                <div class="flex items-center justify-between bg-[#f8fafc] p-5 rounded-[16px]">
                                    <div class="flex flex-col">
                                        <span class="text-[15px] font-bold text-[#0f172a] leading-none mb-2"><?= $maintenanceMode ? 'System is Offline' : 'System is Live' ?></span>
                                        <span class="text-[10px] font-bold <?= $maintenanceMode ? 'text-red-500 animate-pulse' : 'text-[#10b981]' ?> uppercase tracking-widest leading-none">
                                            <?= $maintenanceMode ? 'MAINTENANCE ON' : 'MAINTENANCE OFF' ?>
                                        </span>
                                    </div>
                                    <button type="submit" class="relative inline-flex h-[28px] w-[50px] items-center rounded-full transition-colors focus:outline-none <?= $maintenanceMode ? 'bg-red-500' : 'bg-slate-300 hover:bg-slate-400' ?>">
                                        <span class="inline-block size-[24px] transform rounded-full bg-white shadow-sm transition-transform <?= $maintenanceMode ? 'translate-x-[22px]' : 'translate-x-[2px]' ?>"></span>
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <div class="mt-auto">
                            <p class="text-[13px] text-slate-500 leading-relaxed font-medium">Turn this on to block all clinics and staff from logging in. Use this when updating the system. Super Admins can still log in.</p>
                        </div>
                    </div>
                </div>

                <div class="xl:col-span-2 bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col h-max">
                    <div class="p-6 border-b border-slate-100 bg-white shrink-0">
                        <h3 class="text-lg font-black text-slate-800">Current Platform Administrators</h3>
                        <p class="text-xs font-medium text-slate-500">List of personnel who can manage tenants and global settings.</p>
                    </div>
                    
                    <div class="overflow-y-auto overflow-x-auto max-h-[360px] w-full bg-white relative">
                        <table class="w-full text-left border-collapse">
                            <thead class="sticky top-0 z-20 shadow-sm border-b border-slate-200">
                                <tr class="text-slate-400 text-[10px] uppercase tracking-widest">
                                    <th class="p-5 font-black bg-slate-50">Administrator</th>
                                    <th class="p-5 font-black bg-slate-50">Email Address</th>
                                    <th class="p-5 font-black bg-slate-50">Role / Status</th>
                                    <th class="p-5 font-black text-right bg-slate-50">Access Control</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm font-medium text-slate-600 divide-y divide-slate-100">
                                <?php if (empty($superAdmins)): ?>
                                    <tr><td colspan="4" class="p-10 text-center text-slate-400 italic">No administrators found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($superAdmins as $admin): ?>
                                        <?php 
                                            $isThisMasterAccount = strtolower(trim($admin['email'])) === $platformOwnerEmail;
                                            $adminName = trim($admin['first_name'] . ' ' . $admin['last_name']);
                                            $adminInitials = strtoupper(substr($admin['first_name'] ?? 'P', 0, 1) . substr($admin['last_name'] ?? 'O', 0, 1));
                                            if(empty($adminName)) { $adminName = "Platform Owner"; $adminInitials = "PO"; }
                                            $status = $admin['status'] ?? 'Active';
                                        ?>
                                        <tr class="hover:bg-slate-50 transition-colors <?= $status === 'Suspended' ? 'opacity-70 grayscale-[30%]' : '' ?>">
                                            <td class="p-5">
                                                <div class="flex items-center gap-3">
                                                    <div class="size-10 rounded-full <?= $isThisMasterAccount ? 'bg-gradient-to-br from-amber-100 to-yellow-50 text-amber-700 ring-2 ring-amber-200/70 shadow-sm' : ($status === 'Suspended' ? 'bg-red-100 text-red-600' : 'bg-slate-100 text-slate-600') ?> flex items-center justify-center font-black text-xs shrink-0">
                                                        <?= $adminInitials ?>
                                                    </div>
                                                    <div>
                                                        <p class="font-black text-slate-800 leading-tight text-base tracking-tight"><?= htmlspecialchars($adminName) ?></p>
                                                        <p class="text-[9px] font-bold <?= $isThisMasterAccount ? 'text-amber-600' : 'text-slate-400' ?> uppercase tracking-widest mt-0.5">
                                                            <?= $isThisMasterAccount ? 'Master access' : 'Added: ' . date('M d, Y', strtotime($admin['created_at'])) ?>
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="p-5">
                                                <span class="font-bold text-slate-700"><?= htmlspecialchars($admin['email']) ?></span>
                                            </td>
                                            <td class="p-5">
                                                <?php if($isThisMasterAccount): ?>
                                                    <span class="inline-flex items-center gap-1.5 bg-gradient-to-r from-amber-50 to-yellow-50 text-amber-700 px-3.5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest border border-amber-200 shadow-sm">
                                                        <span class="material-symbols-outlined text-sm">workspace_premium</span> Platform Owner
                                                    </span>
                                                <?php else: ?>
                                                    <?php if($status === 'Suspended'): ?>
                                                        <span class="bg-red-50 text-red-600 px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest border border-red-100 inline-flex items-center gap-1">
                                                            <span class="material-symbols-outlined text-[14px]">block</span> Suspended
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="bg-super/5 text-super px-3 py-1.5 rounded-lg text-[10px] font-black uppercase tracking-widest border border-super/10 inline-flex items-center gap-1">
                                                            <span class="material-symbols-outlined text-[14px]">admin_panel_settings</span> Active Admin
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-5 text-right">
                                                <?php if($isThisMasterAccount): ?>
                                                    <div class="flex items-center justify-end gap-2">
                                                        <span class="text-[9px] font-black text-slate-300 uppercase tracking-widest bg-slate-50 px-3 py-2 rounded-xl">Unremovable</span>
                                                        <?php if($isPlatformOwner): ?>
                                                        <button type="button" onclick="openPasswordModal('<?= htmlspecialchars((string)$admin['id'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="inline-flex items-center justify-center h-9 px-3 gap-1.5 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase tracking-widest transition-all shadow-sm" title="Change Password">
                                                            <span class="material-symbols-outlined text-sm">password</span> Password
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php elseif(strtolower(trim($admin['email'])) === $currentEmail): ?>
                                                    <div class="flex items-center justify-end gap-2">
                                                        <span class="text-[9px] font-black text-emerald-500 uppercase tracking-widest bg-emerald-50 px-3 py-2 rounded-xl border border-emerald-100">You (Active)</span>
                                                        <button type="button" onclick="openPasswordModal('<?= htmlspecialchars((string)$admin['id'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="inline-flex items-center justify-center h-9 px-3 gap-1.5 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-600 hover:text-white font-bold text-[10px] uppercase tracking-widest transition-all shadow-sm" title="Change Password">
                                                            <span class="material-symbols-outlined text-sm">password</span> Password
                                                        </button>
                                                    </div>
                                                <?php else: ?>
                                                    <div class="flex items-center justify-end gap-2">
                                                        <?php if($isPlatformOwner): ?>
                                                            <?php if($status === 'Suspended'): ?>
                                                                <button type="button" onclick="openAdminActionModal('activate', '<?= htmlspecialchars((string)$admin['id'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="inline-flex items-center justify-center size-9 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-600 hover:text-white transition-all shadow-sm" title="Reactivate Access">
                                                                    <span class="material-symbols-outlined text-[18px]">lock_open</span>
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="button" onclick="openAdminActionModal('suspend', '<?= htmlspecialchars((string)$admin['id'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="inline-flex items-center justify-center size-9 bg-amber-50 text-amber-600 rounded-xl hover:bg-amber-600 hover:text-white transition-all shadow-sm" title="Suspend Access">
                                                                    <span class="material-symbols-outlined text-[18px]">block</span>
                                                                </button>
                                                            <?php endif; ?>

                                                            <button type="button" onclick="openAdminActionModal('delete', '<?= htmlspecialchars((string)$admin['id'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($adminName), ENT_QUOTES, 'UTF-8') ?>)" class="inline-flex items-center justify-center size-9 bg-red-50 text-red-600 rounded-xl hover:bg-red-600 hover:text-white transition-all shadow-sm" title="Revoke & Delete">
                                                                <span class="material-symbols-outlined text-[18px]">delete_forever</span>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col h-full">
                    <div class="flex items-center gap-2 mb-4 pb-4 border-b border-slate-100">
                        <div class="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center transition-colors duration-300">
                            <span class="material-symbols-outlined text-xl">palette</span>
                        </div>
                        <div>
                            <h3 class="text-base font-black text-slate-800 uppercase tracking-tight">Platform Branding</h3>
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Super Admin UI</p>
                        </div>
                    </div>
                    
                    <form method="POST" action="systemsettings.php" enctype="multipart/form-data" class="flex flex-col gap-4 flex-grow">
                        <input type="hidden" name="update_ui_settings" value="1">
                        
                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Global Theme Color</label>
                            <div class="flex items-center gap-4 bg-slate-50 p-3.5 rounded-xl border border-slate-200">
                                <input type="color" name="theme_color" value="<?= htmlspecialchars($superThemeColor) ?>" class="size-10 rounded cursor-pointer border-0 p-0 shadow-sm shrink-0">
                                <span class="text-[11px] font-medium text-slate-500 leading-tight">Change the primary color scheme of the Super Admin dashboard.</span>
                            </div>
                        </div>
                        
                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Header Logo (Small)</label>
                            <div class="flex items-center gap-4 bg-slate-50 p-3.5 rounded-xl border border-slate-200 overflow-hidden">
                                <div class="size-10 rounded-xl bg-slate-200 border border-slate-300 flex items-center justify-center shrink-0 overflow-hidden">
                                    <?php if($superLogoPath): ?>
                                        <img src="<?= htmlspecialchars($superLogoPath) ?>" alt="Preview" class="size-full object-contain bg-white">
                                    <?php else: ?>
                                        <span class="material-symbols-outlined text-slate-400 text-lg">image</span>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="super_logo" accept=".jpg, .jpeg, .png, .gif" class="w-full text-[10px] font-black text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-colors cursor-pointer">
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Hero Image (Large Circle)</label>
                            <div class="flex items-center gap-4 bg-slate-50 p-3.5 rounded-xl border border-slate-200 overflow-hidden">
                                <div class="size-10 rounded-xl bg-slate-200 border border-slate-300 flex items-center justify-center shrink-0 overflow-hidden">
                                    <?php if($superHeroPath): ?>
                                        <img src="<?= htmlspecialchars($superHeroPath) ?>" alt="Preview" class="size-full object-cover">
                                    <?php else: ?>
                                        <span class="material-symbols-outlined text-slate-400 text-lg">wallpaper</span>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="super_hero_image" accept=".jpg, .jpeg, .png, .gif" class="w-full text-[10px] font-black text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:bg-primary/10 file:text-primary hover:file:bg-primary/20 transition-colors cursor-pointer">
                            </div>
                        </div>

                        <div class="mt-auto pt-4">
                            <button type="submit" class="w-full bg-primary <?= $headerText ?> font-bold py-3.5 rounded-xl uppercase tracking-widest text-[11px] hover:opacity-90 transition-opacity shadow-md flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-sm">brush</span> Save Brand Settings
                            </button>
                        </div>
                    </form>
                </div>

                <?php if($isPlatformOwner): ?>
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col h-full">
                    <div class="flex items-center gap-2 mb-4 pb-4 border-b border-slate-100">
                        <div class="size-10 rounded-xl bg-super text-white flex items-center justify-center">
                            <span class="material-symbols-outlined text-xl">shield_person</span>
                        </div>
                        <div>
                            <h3 class="text-base font-black text-slate-800 uppercase tracking-tight">Add Global Admin</h3>
                            <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Create superadmin account</p>
                        </div>
                    </div>
                    
                    <form method="POST" action="systemsettings.php" class="flex flex-col gap-4 flex-grow">
                        <input type="hidden" name="add_superadmin" value="1">
                        
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">First Name</label>
                                <input type="text" name="first_name" required class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="e.g. Juan">
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Last Name</label>
                                <input type="text" name="last_name" required class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="e.g. Dela Cruz">
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Email Address</label>
                            <input type="email" name="email" required class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="admin@maternityhub.com">
                        </div>

                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Password</label>
                            <input type="password" name="password" required minlength="8" class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="Minimum 8 characters">
                        </div>

                        <div>
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Confirm Password</label>
                            <input type="password" name="confirm_password" required minlength="8" class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="Retype password">
                        </div>

                        <div class="mt-auto pt-4">
                            <button type="submit" class="w-full bg-super text-white font-bold py-3.5 rounded-xl uppercase tracking-widest text-[11px] hover:bg-slate-800 transition-colors shadow-md flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined text-sm">person_add</span> Create Account
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="bg-slate-50 p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col items-center justify-center h-full text-center gap-3">
                    <div class="size-12 rounded-2xl bg-slate-200 text-slate-400 flex items-center justify-center">
                        <span class="material-symbols-outlined text-2xl">lock</span>
                    </div>
                    <div>
                        <p class="font-black text-slate-500 text-sm uppercase tracking-tight">Restricted</p>
                        <p class="text-xs text-slate-400 mt-1">Only the Platform Owner can add Global Administrators.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if($isPlatformOwner): ?>
            <div class="mt-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm">
                    <div class="flex items-center gap-3 mb-4 pb-4 border-b border-slate-100">
                        <div class="size-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center border border-amber-200 shrink-0">
                            <span class="material-symbols-outlined text-2xl">gavel</span>
                        </div>
                        <div class="flex-grow">
                            <h3 class="text-lg font-black text-slate-800 uppercase tracking-tight">Terms and Conditions</h3>
                            <p class="text-xs text-slate-500 font-medium tracking-tight mt-1">
                                Edit ang legal na "Terms and Conditions" na lalabas sa landing page (index.php) ng MaternityHub. Preserved ang line breaks.
                            </p>
                        </div>
                    </div>
                    <form method="POST" action="systemsettings.php" class="flex flex-col gap-4">
                        <input type="hidden" name="update_terms" value="1">
                        <textarea name="terms_text" required rows="22" class="w-full rounded-xl border border-slate-200 text-xs leading-relaxed p-4 focus:ring-primary focus:border-primary shadow-sm bg-slate-50 font-mono text-slate-700" placeholder="Enter the full Terms and Conditions content here..."><?= htmlspecialchars($termsAndConditions) ?></textarea>
                        <div class="flex flex-col sm:flex-row gap-3 items-center justify-between">
                            <p class="text-[11px] text-slate-400 font-semibold">
                                <span class="material-symbols-outlined text-[14px] align-middle">info</span>
                                Changes will reflect immediately on the public-facing landing page modal.
                            </p>
                            <button type="submit" class="w-full sm:w-auto bg-amber-600 text-white font-bold py-3 px-6 rounded-xl uppercase tracking-widest text-[11px] hover:bg-amber-700 transition-colors shadow-md flex items-center justify-center gap-2 border border-amber-800">
                                <span class="material-symbols-outlined text-sm">save</span> Save Terms &amp; Conditions
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <?php if($isPlatformOwner): ?>
            <div class="mt-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col md:flex-row gap-6 items-center">
                    <div class="size-16 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0 border border-indigo-200">
                        <span class="material-symbols-outlined text-3xl">database</span>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-lg font-black text-slate-800 uppercase tracking-tight">Backup Data</h3>
                        <p class="text-sm text-slate-500 font-medium tracking-tight mt-1">
                            View and save a <strong>PDF</strong> backup of all tenant data, registered users, patients, and audit logs in a printable table format.
                        </p>
                    </div>
                    <div class="shrink-0 w-full md:w-auto">
                        <form method="POST" action="systemsettings.php" target="_blank">
                            <input type="hidden" name="backup_database" value="1">
                            <button type="submit" class="w-full md:w-auto bg-indigo-600 text-white font-bold py-3 px-6 rounded-xl uppercase tracking-widest text-[11px] hover:bg-indigo-700 transition-colors shadow-md flex items-center justify-center gap-2 border border-indigo-800">
                                <span class="material-symbols-outlined text-sm">picture_as_pdf</span> Generate Backup
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if($isPlatformOwner): ?>
            <div class="mt-8 border-t border-slate-200 pt-8">
                <div class="bg-red-50 p-6 rounded-[2rem] border border-red-200 shadow-sm flex flex-col md:flex-row gap-6 items-center">
                    <div class="size-16 rounded-full bg-red-100 text-red-600 flex items-center justify-center shrink-0 border border-red-200">
                        <span class="material-symbols-outlined text-3xl">key</span>
                    </div>
                    <div class="flex-grow">
                        <h3 class="text-lg font-black text-red-800 uppercase tracking-tight">Transfer Platform Ownership</h3>
                        <p class="text-sm text-red-600/80 font-medium tracking-tight mt-1">
                            Careful: Transferring ownership will remove your Master privileges and pass full control of MaternityHub to the selected administrator.
                        </p>
                    </div>
                    <div class="shrink-0 w-full md:w-auto">
                        <button type="button" onclick="openTransferModal()" class="w-full md:w-auto bg-red-600 text-white font-bold py-3 px-6 rounded-xl uppercase tracking-widest text-[11px] hover:bg-red-700 transition-colors shadow-md flex items-center justify-center gap-2 border border-red-800">
                            <span class="material-symbols-outlined text-sm">swap_horiz</span> Transfer Ownership
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
    </main>
</div>

<?php if($isPlatformOwner): ?>
<div id="transferOwnershipModal" class="fixed inset-0 z-[220] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md">
    <div class="bg-white rounded-[2rem] p-8 max-w-md w-full shadow-2xl border border-red-200 relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-2 bg-red-600"></div>
        <div class="text-center mb-6 mt-2">
            <div class="size-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mx-auto mb-4 border border-red-100">
                <span class="material-symbols-outlined text-3xl">warning</span>
            </div>
            <h3 class="text-xl font-black text-slate-900 mb-2 leading-tight">Transfer Ownership?</h3>
            <p class="text-slate-500 text-xs">This action is permanent. You are about to transfer full control of the platform to another administrator.</p>
        </div>
        
        <form id="transferForm" method="POST" action="systemsettings.php" class="space-y-5" onsubmit="handleTransferSubmit(event)">
            <input type="hidden" name="transfer_ownership" value="1">
            
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-2 block">Select New Owner</label>
                <select name="new_owner_id" id="newOwnerSelect" required class="w-full rounded-lg border-slate-300 text-sm p-2.5 focus:ring-red-500 focus:border-red-500 bg-white font-bold text-slate-800">
                    <option value="">-- Choose Administrator --</option>
                    <?php foreach($availableForTransfer as $candidate): ?>
                        <option value="<?= htmlspecialchars($candidate['id']) ?>">
                            <?= htmlspecialchars($candidate['first_name'] . ' ' . $candidate['last_name'] . ' (' . $candidate['email'] . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if(empty($availableForTransfer)): ?>
                    <p class="text-red-500 text-[10px] mt-2 font-bold uppercase tracking-widest"><span class="material-symbols-outlined text-[12px] align-text-bottom">error</span> No other active admins found.</p>
                <?php endif; ?>
            </div>

            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Verify Your Password</label>
                <input type="password" name="master_password" required class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-red-500 focus:border-red-500 shadow-sm bg-white" placeholder="Enter your current password">
            </div>

            <div class="flex gap-3 pt-2">
                <button type="button" onclick="closeTransferModal()" class="w-1/3 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <button type="submit" id="transferSubmitBtn" class="w-2/3 py-3 rounded-xl font-bold bg-red-600 text-white hover:bg-red-700 transition-all text-xs shadow-lg shadow-red-200 flex items-center justify-center gap-2" <?= empty($availableForTransfer) ? 'disabled style="opacity:0.5; cursor:not-allowed;"' : '' ?>>
                    <span class="material-symbols-outlined text-sm">verified_user</span> Transfer Access
                </button>
            </div>
        </form>
    </div>
</div>

<div id="finalTransferConfirmModal" class="fixed inset-0 z-[230] hidden items-center justify-center p-4 bg-slate-900/80 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-sm w-full shadow-2xl border border-red-300 text-center transform scale-95 opacity-0 transition-all duration-300" id="finalTransferConfirmBox">
        <div class="size-16 rounded-full bg-red-50 text-red-600 flex items-center justify-center mx-auto mb-4 border border-red-100">
            <span class="material-symbols-outlined text-4xl">gpp_bad</span>
        </div>
        <h3 class="text-lg font-black text-slate-900 mb-2">Final Confirmation</h3>
        <p class="text-slate-600 text-sm mb-6 px-2">Are you absolutely sure? <br><br><span class="text-red-600 font-bold bg-red-50 px-2 py-1 rounded-md block mt-2">You will permanently lose Master Platform privileges.</span></p>
        <div class="flex gap-2">
            <button type="button" onclick="closeFinalTransferModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
            <button type="button" onclick="executeTransfer()" class="flex-1 py-3 rounded-xl font-bold bg-red-600 text-white hover:bg-red-700 transition-all text-xs shadow-lg shadow-red-200">Yes, Transfer Now</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div id="adminActionModal" class="fixed inset-0 z-[200] hidden items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-md w-full shadow-2xl border border-slate-100 transform scale-95 opacity-0 transition-all duration-300" id="adminActionModalBox">
        <form id="adminActionForm" method="POST" action="systemsettings.php">
            <input type="hidden" name="delete_admin_id" id="deleteAdminIdField" value="" disabled>
            <input type="hidden" name="revoke_reason" id="revokeReasonHidden" value="" disabled>
            <div class="text-center">
                <div id="adminActionIconBox" class="size-12 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <span id="adminActionIcon" class="material-symbols-outlined text-2xl"></span>
                </div>
                <h3 id="adminActionTitle" class="text-base font-black text-slate-900 mb-1"></h3>
                <p id="adminActionDesc" class="text-slate-500 text-[11px] mb-2"></p>
                <p id="adminActionTarget" class="text-xl font-black text-slate-800 mb-4 leading-tight"></p>
                
                <div id="revokeReasonSection" class="hidden mb-4 text-left">
                    <label class="text-[10px] font-black text-red-500 uppercase tracking-widest ml-1 mb-1.5 block">Reason for Revocation *</label>
                    <textarea id="revokeReasonInput" rows="3" class="w-full rounded-xl border-red-200 text-sm p-3 focus:ring-red-500 focus:border-red-500 shadow-sm bg-red-50/50 resize-none placeholder:text-red-300" placeholder="State the reason why this admin is being removed..."></textarea>
                    <p id="revokeReasonError" class="hidden text-[10px] font-black text-red-500 uppercase tracking-widest mt-1.5 ml-1">Please provide a reason before proceeding.</p>
                </div>
                
                <div class="flex gap-2">
                    <button type="button" onclick="closeAdminActionModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
                    <button type="button" id="adminActionConfirmBtn" onclick="confirmAdminAction()" class="flex-1 py-2.5 rounded-xl font-bold text-white transition-all text-[11px] shadow-lg flex items-center justify-center">Confirm</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="passwordModal" class="fixed inset-0 z-[210] hidden items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-md w-full shadow-2xl border border-slate-100">
        <div class="text-center mb-5">
            <div class="size-12 rounded-2xl bg-blue-50 text-blue-500 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-2xl">lock_reset</span>
            </div>
            <h3 class="text-base font-black text-slate-900 mb-1">Change Super Admin Password</h3>
            <p class="text-slate-500 text-[11px] mb-2">Set a new password for this account.</p>
            <p id="passwordTargetName" class="text-lg font-black text-slate-800 leading-tight"></p>
        </div>
        <form method="POST" action="systemsettings.php" class="space-y-4" onsubmit="return submitPasswordChange()">
            <input type="hidden" name="change_admin_password" value="1">
            <input type="hidden" name="target_admin_id" id="passwordTargetId" value="">
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">New Password</label>
                <input type="password" name="new_password" id="newPasswordField" required minlength="8" class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="Minimum 8 characters">
            </div>
            <div>
                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1 mb-1 block">Confirm New Password</label>
                <input type="password" name="confirm_new_password" id="confirmNewPasswordField" required minlength="8" class="w-full rounded-xl border-slate-200 text-sm p-3 focus:ring-super focus:border-super shadow-sm bg-slate-50" placeholder="Retype new password">
            </div>
            <p id="passwordModalError" class="hidden text-[10px] font-black text-red-500 uppercase tracking-widest"></p>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closePasswordModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
                <button type="submit" id="passwordSubmitBtn" class="flex-1 py-2.5 rounded-xl font-bold bg-blue-600 text-white hover:bg-blue-700 transition-all text-[11px] shadow-lg shadow-blue-100">Update Password</button>
            </div>
        </form>
    </div>
</div>

<script>
    let currentAdminAction = '';
    let currentAdminActionHref = '';

    function openAdminActionModal(action, id, name) {
        const modal = document.getElementById('adminActionModal');
        const box = document.getElementById('adminActionModalBox');
        const iconBox = document.getElementById('adminActionIconBox');
        const icon = document.getElementById('adminActionIcon');
        const title = document.getElementById('adminActionTitle');
        const desc = document.getElementById('adminActionDesc');
        const target = document.getElementById('adminActionTarget');
        const btn = document.getElementById('adminActionConfirmBtn');
        const reasonSection = document.getElementById('revokeReasonSection');
        const reasonInput = document.getElementById('revokeReasonInput');
        const reasonError = document.getElementById('revokeReasonError');
        const deleteIdField = document.getElementById('deleteAdminIdField');
        const reasonHidden = document.getElementById('revokeReasonHidden');

        target.textContent = name;
        reasonSection.classList.add('hidden');
        reasonInput.value = '';
        reasonError.classList.add('hidden');
        deleteIdField.disabled = true;
        reasonHidden.disabled = true;
        currentAdminAction = action;
        currentAdminActionHref = '';

        if (action === 'suspend') {
            iconBox.className = 'size-12 rounded-2xl flex items-center justify-center mx-auto mb-4 bg-amber-50 text-amber-500';
            icon.textContent = 'block';
            title.textContent = 'Suspend Administrator?';
            desc.textContent = 'This user will not be able to log in until reactivated.';
            btn.className = 'flex-1 py-2.5 rounded-xl font-bold text-white transition-all text-[11px] shadow-lg flex items-center justify-center bg-amber-500 hover:bg-amber-600 shadow-amber-100';
            btn.textContent = 'Suspend Admin';
            currentAdminActionHref = `systemsettings.php?suspend_admin=${id}`;
        } else if (action === 'activate') {
            iconBox.className = 'size-12 rounded-2xl flex items-center justify-center mx-auto mb-4 bg-emerald-50 text-emerald-500';
            icon.textContent = 'lock_open';
            title.textContent = 'Reactivate Administrator?';
            desc.textContent = 'Restore platform access for this user?';
            btn.className = 'flex-1 py-2.5 rounded-xl font-bold text-white transition-all text-[11px] shadow-lg flex items-center justify-center bg-emerald-500 hover:bg-emerald-600 shadow-emerald-100';
            btn.textContent = 'Reactivate Admin';
            currentAdminActionHref = `systemsettings.php?activate_admin=${id}`;
        } else if (action === 'delete') {
            iconBox.className = 'size-12 rounded-2xl flex items-center justify-center mx-auto mb-4 bg-red-50 text-red-500';
            icon.textContent = 'person_remove';
            title.textContent = 'Fire this Admin?';
            desc.innerHTML = 'Permanently delete this administrator account?<br><span class="text-red-500 font-bold mt-1 block">This cannot be undone. A notification email will be sent.</span>';
            btn.className = 'flex-1 py-2.5 rounded-xl font-bold text-white transition-all text-[11px] shadow-lg flex items-center justify-center bg-red-500 hover:bg-red-600 shadow-red-100';
            btn.textContent = 'Revoke & Delete';
            reasonSection.classList.remove('hidden');
            deleteIdField.value = id;
            deleteIdField.disabled = false;
            reasonHidden.disabled = false;
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            box.classList.remove('scale-95', 'opacity-0');
            box.classList.add('scale-100', 'opacity-100');
            if (action === 'delete') reasonInput.focus();
        }, 10);
    }

    function confirmAdminAction() {
        if (currentAdminAction === 'delete') {
            const reasonInput = document.getElementById('revokeReasonInput');
            const reasonError = document.getElementById('revokeReasonError');
            const reasonHidden = document.getElementById('revokeReasonHidden');
            const reason = reasonInput.value.trim();
            if (!reason) {
                reasonError.classList.remove('hidden');
                reasonInput.classList.add('border-red-400', 'ring-2', 'ring-red-200');
                reasonInput.focus();
                return;
            }
            reasonError.classList.add('hidden');
            reasonInput.classList.remove('border-red-400', 'ring-2', 'ring-red-200');
            reasonHidden.value = reason;
            document.getElementById('adminActionForm').submit();
        } else {
            window.location.href = currentAdminActionHref;
        }
    }

    function closeAdminActionModal() {
        const modal = document.getElementById('adminActionModal');
        const box = document.getElementById('adminActionModalBox');
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }, 300);
    }

    function openPasswordModal(adminId, adminName) {
        document.getElementById('passwordTargetId').value = adminId || '';
        document.getElementById('passwordTargetName').textContent = adminName || '';
        document.getElementById('newPasswordField').value = '';
        document.getElementById('confirmNewPasswordField').value = '';
        document.getElementById('passwordModalError').textContent = '';
        document.getElementById('passwordModalError').classList.add('hidden');
        document.getElementById('passwordSubmitBtn').disabled = false;
        document.getElementById('passwordModal').classList.remove('hidden');
        document.getElementById('passwordModal').classList.add('flex');
    }

    function closePasswordModal() {
        document.getElementById('passwordModal').classList.remove('flex');
        document.getElementById('passwordModal').classList.add('hidden');
    }

    function submitPasswordChange() {
        const newPassword = document.getElementById('newPasswordField').value;
        const confirmPassword = document.getElementById('confirmNewPasswordField').value;
        const errorBox = document.getElementById('passwordModalError');

        if (newPassword.length < 8) {
            errorBox.textContent = 'Password must be at least 8 characters.';
            errorBox.classList.remove('hidden');
            return false;
        }
        if (newPassword !== confirmPassword) {
            errorBox.textContent = 'Passwords do not match.';
            errorBox.classList.remove('hidden');
            return false;
        }

        errorBox.classList.add('hidden');
        document.getElementById('passwordSubmitBtn').disabled = true;
        return true;
    }

    function openTransferModal() {
        document.getElementById('transferOwnershipModal').classList.remove('hidden');
        document.getElementById('transferOwnershipModal').classList.add('flex');
    }

    function closeTransferModal() {
        document.getElementById('transferOwnershipModal').classList.remove('flex');
        document.getElementById('transferOwnershipModal').classList.add('hidden');
    }

    function handleTransferSubmit(e) {
        e.preventDefault();
        const select = document.getElementById('newOwnerSelect');
        if(!select.value) return false;
        
        // Show final confirmation modal instead of ugly browser alert
        document.getElementById('finalTransferConfirmModal').classList.remove('hidden');
        document.getElementById('finalTransferConfirmModal').classList.add('flex');
        setTimeout(() => {
            document.getElementById('finalTransferConfirmBox').classList.remove('scale-95', 'opacity-0');
            document.getElementById('finalTransferConfirmBox').classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeFinalTransferModal() {
        const box = document.getElementById('finalTransferConfirmBox');
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            document.getElementById('finalTransferConfirmModal').classList.remove('flex');
            document.getElementById('finalTransferConfirmModal').classList.add('hidden');
        }, 300);
    }

    function executeTransfer() {
        closeFinalTransferModal();
        const btn = document.getElementById('transferSubmitBtn');
        btn.innerHTML = '<span class="material-symbols-outlined text-sm animate-spin">refresh</span> Processing...';
        btn.disabled = true;
        document.getElementById('transferForm').submit();
    }

    function openLogoutModal() {
        document.getElementById('logoutModal').classList.remove('hidden');
        document.getElementById('logoutModal').classList.add('flex');
    }
    function closeLogoutModal() {
        document.getElementById('logoutModal').classList.remove('flex');
        document.getElementById('logoutModal').classList.add('hidden');
    }
    function confirmLogout() {
        closeLogoutModal(); 
        const loading = document.getElementById('loggingOutScreen');
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        setTimeout(() => { window.location.href = '?logout=1'; }, 1500); 
    }
</script>

</body>
</html>