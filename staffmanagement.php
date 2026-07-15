<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    if (!isset($pdo)) {
        require_once 'db.php';
        if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    }
    // --- AUDIT LOG LOGOUT ---
    if (isset($_SESSION['full_name']) && isset($pdo)) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $auditTenant = $_SESSION['TenantID'] ?? null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');
            $auditDetails = 'User securely logged out of their clinic portal.';
            $stmtLogoutLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', ?, ?, ?)");
            $stmtLogoutLog->execute([$auditTenant, $logoutName, $logoutRole, $auditDetails, $ip, $currentTime]);
        } catch (Exception $e) {}
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
    $clinicCode = $_GET['c'] ?? 'N/A';
    if (!empty($clinicCode) && $clinicCode !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($clinicCode)); 
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'db.php'; 
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (Exception $e) {}

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

$current_staff_id = (int)($_SESSION['user_id'] ?? 0);

// ?? STRICT TENANT ID FETCHING ??
try {
    $stmtAdmin = $pdo->prepare("SELECT TenantID FROM users WHERE id = ?");
    $stmtAdmin->execute([$current_staff_id]);
    $db_tenant_id = $stmtAdmin->fetchColumn();

    if ($db_tenant_id !== false) {
        $_SESSION['TenantID'] = $db_tenant_id;
    }
} catch (PDOException $e) {}

$tenant_id = $_SESSION['TenantID'] ?? null;

// ?? SAFETY BLOCKER: Kapag ang TenantID ay 0 pa rin, i-block agad! ??
if (empty($tenant_id) || $tenant_id == '0') {
    die("<div style='padding: 2rem; font-family: sans-serif; text-align: center; color: #b91c1c; background: #fef2f2; border: 1px solid #fecaca; border-radius: 1rem; margin: 2rem;'>
        <h2>?? SYSTEM ERROR: INVALID TENANT ID</h2>
        <p>Ang Tenant ID na nakukuha ng system mula sa admin account mo ay: <b style='font-size: 1.5rem;'>{$tenant_id}</b></p>
        <p>Hindi ka pwedeng gumawa ng staff account hangga't 0 ito.</p>
        <p><b>Pano ayusin:</b> Pumunta sa phpMyAdmin > `users` table > hanapin ang account mo at palitan ang TenantID mula 0 papuntang <b>T001</b> (Siguraduhing naka-VARCHAR ang column type). Pagkatapos, mag-logout at mag-login ulit sa system.</p>
        </div>");
}

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

function isAdministratorRole($role) {
    $normalizedRole = strtolower(trim((string)$role));
    return in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
}

$currentRole = $_SESSION['role'] ?? 'Staff';
$currentIsAdmin = isAdministratorRole($currentRole);
$isStaffRole = (strtolower(trim((string)$currentRole)) === 'staff');

// Determine if current user is the clinic Owner (original admin/administrator)
$currentUserIsOwner = in_array(strtolower(trim((string)$currentRole)), ['admin', 'administrator', 'owner', 'owner/midwife'], true);

// Ensure is_admin and granted_features columns exist in clinic_staff
try { $pdo->exec("ALTER TABLE clinic_staff ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE clinic_staff ADD COLUMN granted_features TEXT DEFAULT NULL"); } catch (PDOException $e) {}
// Ensure fire_reason and fired_at columns exist in clinic_staff
try { $pdo->exec("ALTER TABLE clinic_staff ADD COLUMN fire_reason TEXT DEFAULT NULL"); } catch (PDOException $e) {}
try { $pdo->exec("ALTER TABLE clinic_staff ADD COLUMN fired_at DATETIME DEFAULT NULL"); } catch (PDOException $e) {}
// Ensure also_midwife column exists in users table (for owner acting as midwife)
try { $pdo->exec("ALTER TABLE users ADD COLUMN also_midwife TINYINT(1) NOT NULL DEFAULT 0"); } catch (PDOException $e) {}
// Auto-repair: sync status='Fired' for any rows where fired_at is set but status wasn't updated
try { $pdo->prepare("UPDATE clinic_staff SET status = 'Fired' WHERE TenantID = ? AND fired_at IS NOT NULL AND (status IS NULL OR status != 'Fired')")->execute([$tenant_id]); } catch (PDOException $e) {}

// Check if current staff user has been granted admin privileges
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $current_staff_id]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (int)($curAccess['is_admin'] ?? 0) === 1;
            $currentUserGrantedFeatures = json_decode($curAccess['granted_features'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {}
}

$canAccessClinicStaff = $currentUserIsOwner || $currentUserIsStaffAdmin;
// Non-admin/non-owner staff can access the page but only see Pending Patients

// FETCH CLINIC NAME & CODE
$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = "#15803d";
if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            if (!empty($clinicData['clinic_code'])) {
                $clinicCode = $clinicData['clinic_code'];
            }
            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }
            if (!empty($clinicData['theme_color'])) {
                $themeColor = $clinicData['theme_color'];
            }
        }
    } catch (PDOException $e) {}
}

// Dynamic sidebar active style based on theme brightness (match tenantsettings)
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

$headerTextPrimary = $isLightTheme ? "text-slate-900" : "text-white";
$headerTextSecondary = $isLightTheme ? "text-slate-700" : "text-primary-light";
$headerTextMuted = $isLightTheme ? "text-slate-400" : "text-white/50";
$headerBadgeBg = $isLightTheme ? "bg-slate-200 text-slate-800" : "bg-black/20 text-white";
$headerIconBox = $isLightTheme ? "bg-white border-slate-200" : "bg-white/15 border-white/25";
$headerIconColor = $isLightTheme ? "text-slate-700" : "text-white/90";
$headerBtn = $isLightTheme ? "bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm" : "bg-white/15 hover:bg-white/25 text-white border-white/30";

$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

// Ensure clinic creator/admin accounts are also present in clinic_staff.
if ($tenant_id) {
    try {
        $stmtTenantAdmins = $pdo->prepare("SELECT first_name, last_name, email, password, role, status FROM users WHERE TenantID = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'administrator', 'owner', 'owner/midwife')");
        $stmtTenantAdmins->execute([$tenant_id]);
        $tenantAdmins = $stmtTenantAdmins->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($tenantAdmins)) {
            $stmtCheckCreator = $pdo->prepare("SELECT id FROM clinic_staff WHERE TenantID = ? AND LOWER(TRIM(email_address)) = LOWER(TRIM(?)) LIMIT 1");
            $stmtInsertCreator = $pdo->prepare("INSERT INTO clinic_staff (TenantID, first_name, middle_name, last_name, email_address, password, role, status, credentials_file) VALUES (?, ?, '', ?, ?, ?, ?, ?, NULL)");

            foreach ($tenantAdmins as $adminRow) {
                $adminEmail = trim((string)($adminRow['email'] ?? ''));
                if ($adminEmail === '') {
                    continue;
                }

                $stmtCheckCreator->execute([$tenant_id, $adminEmail]);
                if (!$stmtCheckCreator->fetch()) {
                    $stmtInsertCreator->execute([
                        $tenant_id,
                        $adminRow['first_name'] ?? 'Clinic',
                        $adminRow['last_name'] ?? 'Admin',
                        $adminEmail,
                        $adminRow['password'] ?? '',
                        $adminRow['role'] ?? 'Admin',
                        $adminRow['status'] ?? 'Active'
                    ]);
                }
            }
        }
    } catch (PDOException $e) {
        // Silent fail to avoid blocking staff page on legacy schemas.
    }
}

// --- CREATE STAFF LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_staff'])) {
    if (!$currentIsAdmin) {
        header("Location: staffmanagement.php?msg=not_allowed&type=error");
        exit();
    }

    $firstName = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $role = trim($_POST['role'] ?? 'Receptionist'); 
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($firstName === '' || $lastName === '' || $email === '' || $password === '') {
        header("Location: staffmanagement.php?msg=missing_staff_fields&type=error");
        exit();
    }

    if ($password !== $confirmPassword) {
        header("Location: staffmanagement.php?msg=password_mismatch&type=error");
        exit();
    }

    $resumeFileName = null;
    if (isset($_FILES['resume_file']) && $_FILES['resume_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $extension = strtolower(pathinfo($_FILES['resume_file']['name'], PATHINFO_EXTENSION));
        if (!is_dir(__DIR__ . '/uploads/staff_docs/')) {
            mkdir(__DIR__ . '/uploads/staff_docs/', 0777, true);
        }
        $resumeFileName = 'cred_' . $tenant_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $resumeTarget = __DIR__ . '/uploads/staff_docs/' . $resumeFileName;
        move_uploaded_file($_FILES['resume_file']['tmp_name'], $resumeTarget);
    }

    $profileImageName = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $extension = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        if (!is_dir(__DIR__ . '/uploads/profiles/')) {
            mkdir(__DIR__ . '/uploads/profiles/', 0777, true);
        }
        $profileImageName = 'profile_' . $tenant_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $profileTarget = __DIR__ . '/uploads/profiles/' . $profileImageName;
        move_uploaded_file($_FILES['profile_image']['tmp_name'], $profileTarget);
    }

    try {
        $stmtExistingStaff = $pdo->prepare("SELECT id FROM clinic_staff WHERE email_address = ? AND TenantID = ? LIMIT 1");
        $stmtExistingStaff->execute([$email, $tenant_id]);
        if ($stmtExistingStaff->fetch()) {
            header("Location: staffmanagement.php?msg=email_exists&type=error");
            exit();
        }

        $stmtExistingUser = $pdo->prepare("SELECT id FROM users WHERE email = ? AND TenantID = ? LIMIT 1");
        $stmtExistingUser->execute([$email, $tenant_id]);
        if ($stmtExistingUser->fetch()) {
            header("Location: staffmanagement.php?msg=email_exists&type=error");
            exit();
        }

        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();

        // Create in clinic_staff
        $stmtCreate = $pdo->prepare("INSERT INTO clinic_staff (TenantID, first_name, middle_name, last_name, email_address, password, role, status, credentials_file, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?)");
        $stmtCreate->execute([
            $tenant_id, $firstName, $middleName, $lastName, $email, $hashedPassword, $role, $resumeFileName, $profileImageName
        ]);

        // Mirror account in users for authentication/login parity
        $stmtCreateUser = $pdo->prepare("INSERT INTO users (TenantID, clinic_code, clinic_name, first_name, last_name, email, password, role, status, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?)");
        $stmtCreateUser->execute([
            $tenant_id,
            $clinicCode,
            $clinicName,
            $firstName,
            $lastName,
            $email,
            $hashedPassword,
            $role,
            $profileImageName
        ]);
        $pdo->commit();
        header("Location: staffmanagement.php?msg=staff_created&type=success");
        exit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        die("<h3>DATABASE ERROR IN CREATE STAFF:</h3> <p>" . $e->getMessage() . "</p>");
    }
}

// --- EDIT STAFF ROLE LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_staff_role'])) {
    $staff_id = (int)$_POST['edit_staff_id'];
    $new_role = trim($_POST['edit_role']);

    try {
        // Get staff email to sync role to users table
        $stmtEmail = $pdo->prepare("SELECT email_address FROM clinic_staff WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtEmail->execute([$staff_id, $tenant_id]);
        $staffEmail = trim((string)$stmtEmail->fetchColumn());

        $stmt = $pdo->prepare("UPDATE clinic_staff SET role = ? WHERE id = ? AND TenantID = ?");
        $stmt->execute([$new_role, $staff_id, $tenant_id]);

        // Sync role to users table so it reflects on login
        if (!empty($staffEmail)) {
            $stmtSyncRole = $pdo->prepare("UPDATE users SET role = ? WHERE LOWER(TRIM(email)) = LOWER(?) AND TenantID = ?");
            $stmtSyncRole->execute([$new_role, $staffEmail, $tenant_id]);
        }

        header("Location: staffmanagement.php?msg=role_updated&type=success");
        exit();
    } catch (PDOException $e) {
        die("<h3>DATABASE ERROR IN EDIT ROLE:</h3> <p>" . $e->getMessage() . "</p>");
    }
}

// --- MANAGE STAFF ACCESS LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manage_access'])) {
    if (!$currentUserIsOwner) {
        header("Location: staffmanagement.php?msg=not_allowed&type=error");
        exit();
    }
    $access_staff_id = (int)$_POST['access_staff_id'];
    $make_admin = isset($_POST['make_admin']) ? 1 : 0;
    $features = [];
    if (isset($_POST['features']) && is_array($_POST['features'])) {
        $allowed = ['financials', 'reports', 'help_support', 'feedback'];
        $features = array_values(array_intersect($_POST['features'], $allowed));
    }
    $featuresJson = json_encode($features);
    try {
        $stmtAccess = $pdo->prepare("UPDATE clinic_staff SET is_admin = ?, granted_features = ? WHERE id = ? AND TenantID = ?");
        $stmtAccess->execute([$make_admin, $featuresJson, $access_staff_id, $tenant_id]);
        header("Location: staffmanagement.php?msg=access_updated&type=success");
        exit();
    } catch (PDOException $e) {
        header("Location: staffmanagement.php?msg=access_error&type=error");
        exit();
    }
}

// --- TOGGLE OWNER ALSO_MIDWIFE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_also_midwife'])) {
    if (!$currentUserIsOwner) {
        header("Location: staffmanagement.php?msg=not_allowed&type=error");
        exit();
    }
    $newVal = (int)$_POST['also_midwife_val'];
    try {
        // Update also_midwife flag on users table
        $stmtMw = $pdo->prepare("UPDATE users SET also_midwife = ? WHERE id = ? AND TenantID = ?");
        $stmtMw->execute([$newVal ? 1 : 0, $_SESSION['user_id'], $tenant_id]);

        // Get owner email to sync role to clinic_staff
        $stmtOwnerEmail = $pdo->prepare("SELECT email FROM users WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtOwnerEmail->execute([$_SESSION['user_id'], $tenant_id]);
        $ownerEmail = trim((string)$stmtOwnerEmail->fetchColumn());

        // Update role in users table
        $newRole = $newVal ? 'Owner/Midwife' : 'Owner';
        $stmtRole = $pdo->prepare("UPDATE users SET role = ? WHERE id = ? AND TenantID = ?");
        $stmtRole->execute([$newRole, $_SESSION['user_id'], $tenant_id]);

        // Update role in clinic_staff table (if owner has a matching row)
        if (!empty($ownerEmail)) {
            $stmtCsRole = $pdo->prepare("UPDATE clinic_staff SET role = ? WHERE LOWER(TRIM(email_address)) = LOWER(?) AND TenantID = ?");
            $stmtCsRole->execute([$newRole, $ownerEmail, $tenant_id]);
        }

        // Update session role so it reflects immediately
        $_SESSION['role'] = $newRole;

        header("Location: staffmanagement.php?msg=midwife_updated&type=success");
        exit();
    } catch (PDOException $e) {
        header("Location: staffmanagement.php?msg=access_error&type=error");
        exit();
    }
}

// --- UPDATE CREDENTIALS FILE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_credentials'])) {
    if (!$currentUserIsOwner) { header("Location: staffmanagement.php?msg=not_allowed&type=error"); exit(); }
    $staffId = (int)$_POST['cred_staff_id'];
    $acctType = trim($_POST['cred_account_type'] ?? 'staff');
    if (isset($_FILES['new_credentials']) && $_FILES['new_credentials']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['new_credentials']['name'], PATHINFO_EXTENSION));
        $allowed_ext = ['pdf','jpg','jpeg','png','webp','doc','docx'];
        if (!in_array($ext, $allowed_ext)) { header("Location: staffmanagement.php?msg=invalid_file&type=error"); exit(); }
        $folder = ($acctType === 'admin') ? 'resumes' : 'staff_docs';
        if (!is_dir(__DIR__ . "/uploads/{$folder}/")) { mkdir(__DIR__ . "/uploads/{$folder}/", 0777, true); }
        $newName = 'cred_' . $tenant_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        move_uploaded_file($_FILES['new_credentials']['tmp_name'], __DIR__ . "/uploads/{$folder}/" . $newName);
        try {
            if ($acctType === 'admin') {
                $pdo->prepare("UPDATE users SET resume_path = ? WHERE id = ? AND TenantID = ?")->execute([$newName, $staffId, $tenant_id]);
            } else {
                $pdo->prepare("UPDATE clinic_staff SET credentials_file = ? WHERE id = ? AND TenantID = ?")->execute([$newName, $staffId, $tenant_id]);
            }
            header("Location: staffmanagement.php?msg=credentials_updated&type=success"); exit();
        } catch (PDOException $e) { header("Location: staffmanagement.php?msg=credentials_error&type=error"); exit(); }
    } else {
        header("Location: staffmanagement.php?msg=no_file&type=error"); exit();
    }
}

// --- UPDATE PROFILE PICTURE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile_pic'])) {
    if (!$currentUserIsOwner) { header("Location: staffmanagement.php?msg=not_allowed&type=error"); exit(); }
    $staffId = (int)$_POST['pic_staff_id'];
    $acctType = trim($_POST['pic_account_type'] ?? 'staff');
    if (isset($_FILES['new_profile_pic']) && $_FILES['new_profile_pic']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['new_profile_pic']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp'])) { header("Location: staffmanagement.php?msg=invalid_image&type=error"); exit(); }
        if (!is_dir(__DIR__ . '/uploads/profiles/')) { mkdir(__DIR__ . '/uploads/profiles/', 0777, true); }
        $newName = 'prof_' . $tenant_id . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        move_uploaded_file($_FILES['new_profile_pic']['tmp_name'], __DIR__ . '/uploads/profiles/' . $newName);
        $imgPath = 'uploads/profiles/' . $newName;
        try {
            if ($acctType === 'admin') {
                $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ? AND TenantID = ?")->execute([$imgPath, $staffId, $tenant_id]);
            } else {
                $pdo->prepare("UPDATE clinic_staff SET profile_image = ? WHERE id = ? AND TenantID = ?")->execute([$imgPath, $staffId, $tenant_id]);
                // Also update users table if linked
                $stmtEmail = $pdo->prepare("SELECT email_address FROM clinic_staff WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtEmail->execute([$staffId, $tenant_id]);
                $staffEmail = $stmtEmail->fetchColumn();
                if ($staffEmail) {
                    $pdo->prepare("UPDATE users SET profile_image = ? WHERE email = ? AND TenantID = ?")->execute([$imgPath, $staffEmail, $tenant_id]);
                }
            }
            header("Location: staffmanagement.php?msg=profile_pic_updated&type=success"); exit();
        } catch (PDOException $e) { header("Location: staffmanagement.php?msg=pic_error&type=error"); exit(); }
    } else {
        header("Location: staffmanagement.php?msg=no_file&type=error"); exit();
    }
}

// --- UPDATE OWNER NAME ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_owner_name'])) {
    if (!$currentUserIsOwner) { header("Location: staffmanagement.php?msg=not_allowed&type=error"); exit(); }
    $newFirst = trim($_POST['owner_first_name'] ?? '');
    $newMiddle = trim($_POST['owner_middle_name'] ?? '');
    $newLast = trim($_POST['owner_last_name'] ?? '');
    if ($newFirst === '' || $newLast === '') {
        header("Location: staffmanagement.php?msg=name_missing&type=error"); exit();
    }
    try {
        $stmtUpdName = $pdo->prepare("UPDATE users SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ? AND TenantID = ?");
        $builtFullName = trim($newFirst . ($newMiddle ? ' ' . $newMiddle : '') . ' ' . $newLast);
        $stmtUpdName->execute([$newFirst, $newMiddle, $newLast, $_SESSION['user_id'], $tenant_id]);

        // Also sync to clinic_staff if owner has a matching row
        $stmtOwnerEmail = $pdo->prepare("SELECT email FROM users WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtOwnerEmail->execute([$_SESSION['user_id'], $tenant_id]);
        $ownerEmail = trim((string)$stmtOwnerEmail->fetchColumn());
        if (!empty($ownerEmail)) {
            $stmtSyncCs = $pdo->prepare("UPDATE clinic_staff SET first_name = ?, middle_name = ?, last_name = ? WHERE LOWER(TRIM(email_address)) = LOWER(?) AND TenantID = ?");
            $stmtSyncCs->execute([$newFirst, $newMiddle, $newLast, $ownerEmail, $tenant_id]);
        }

        // Update session
        $_SESSION['full_name'] = $builtFullName;

        header("Location: staffmanagement.php?msg=name_updated&type=success"); exit();
    } catch (PDOException $e) {
        header("Location: staffmanagement.php?msg=name_error&type=error"); exit();
    }
}

// --- INTERNAL APPROVE LOGIC ---
if (isset($_GET['approve_id'])) {
    $id = (int)$_GET['approve_id'];
    try {
        $stmt = $pdo->prepare("UPDATE clinic_staff SET status = 'Active' WHERE id = ? AND TenantID = ?");
        $stmt->execute([$id, $tenant_id]);
        header("Location: staffmanagement.php?msg=approved&type=success");
        exit();
    } catch (PDOException $e) { die($e->getMessage()); }
}

// --- SUSPEND/UNSUSPEND LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['suspend_staff_id'])) {
    $id = (int)$_POST['suspend_staff_id'];
    $suspend_reason = trim($_POST['suspend_reason'] ?? '');
    if (empty($suspend_reason)) {
        $error = "Please provide a reason for suspending this staff account.";
    } else {
        try {
            $stmtSuspCheck = $pdo->prepare("SELECT first_name, middle_name, last_name, email_address FROM clinic_staff WHERE id = ? AND TenantID = ?");
            $stmtSuspCheck->execute([$id, $tenant_id]);
            $suspStaff = $stmtSuspCheck->fetch(PDO::FETCH_ASSOC);

            if ($suspStaff) {
                $suspName = trim($suspStaff['first_name'] . ' ' . ($suspStaff['middle_name'] ? $suspStaff['middle_name'] . ' ' : '') . $suspStaff['last_name']);
                $suspEmail = trim($suspStaff['email_address'] ?? '');

                $stmt = $pdo->prepare("UPDATE clinic_staff SET status = 'Inactive' WHERE id = ? AND TenantID = ?");
                $stmt->execute([$id, $tenant_id]);

                $currentUserName = $_SESSION['full_name'] ?? 'Admin';
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmtAudit = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Staff Suspended', ?, ?, NOW())");
                $stmtAudit->execute([$tenant_id, $currentUserName, $currentRole, "Suspended staff account: $suspName ($suspEmail). Reason: $suspend_reason", $ip]);

                // Send suspension email
                if (!empty($suspEmail)) {
                    $suspendDate = date('F d, Y - h:i A');
                    $emailBody = "
                    <div style='font-family: \"Segoe UI\", Arial, sans-serif; max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; border: 1px solid #e2e8f0;'>
                        <div style='background: linear-gradient(135deg, #f59e0b, #d97706); padding: 40px 30px; text-align: center;'>
                            <div style='width: 60px; height: 60px; background: rgba(255,255,255,0.15); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;'>
                                <span style='font-size: 28px; color: white;'>&#9888;</span>
                            </div>
                            <h1 style='color: #ffffff; font-size: 20px; font-weight: 800; margin: 0; letter-spacing: -0.5px;'>Account Suspended</h1>
                            <p style='color: rgba(255,255,255,0.8); font-size: 12px; margin-top: 6px; font-weight: 600;'>" . htmlspecialchars($clinicName) . "</p>
                        </div>
                        <div style='padding: 30px;'>
                            <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 20px;'>
                                Dear <strong>" . htmlspecialchars($suspName) . "</strong>,
                            </p>
                            <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 20px;'>
                                Your staff access to <strong>" . htmlspecialchars($clinicName) . "</strong> has been temporarily suspended.
                            </p>
                            <div style='background: #fffbeb; border: 1px solid #fde68a; border-radius: 12px; padding: 20px; margin: 0 0 20px;'>
                                <p style='color: #92400e; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin: 0 0 8px;'>Reason for Suspension</p>
                                <p style='color: #d97706; font-size: 14px; font-weight: 600; margin: 0; line-height: 1.6;'>" . htmlspecialchars($suspend_reason) . "</p>
                            </div>
                            <div style='background: #f8fafc; border-radius: 12px; padding: 16px; margin: 0 0 20px;'>
                                <table style='width: 100%; border-collapse: collapse;'>
                                    <tr><td style='color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 0;'>Suspended By</td><td style='color: #334155; font-size: 13px; font-weight: 700; text-align: right; padding: 4px 0;'>" . htmlspecialchars($currentUserName) . "</td></tr>
                                    <tr><td style='color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 0;'>Date & Time</td><td style='color: #334155; font-size: 13px; font-weight: 700; text-align: right; padding: 4px 0;'>$suspendDate</td></tr>
                                </table>
                            </div>
                            <p style='color: #94a3b8; font-size: 12px; line-height: 1.6; margin: 0;'>
                                If you believe this was done in error, please contact the clinic administrator directly.
                            </p>
                        </div>
                        <div style='background: #f8fafc; padding: 16px 30px; text-align: center; border-top: 1px solid #e2e8f0;'>
                            <p style='color: #cbd5e1; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin: 0;'>MaternityHub &copy; " . date('Y') . "</p>
                        </div>
                    </div>";

                    send_email_via_smtp_gmail($suspEmail, "Account Suspended - " . $clinicName, $emailBody);
                }

                header("Location: staffmanagement.php?msg=suspended&type=success");
                exit();
            }
        } catch (PDOException $e) { die($e->getMessage()); }
    }
}

if (isset($_GET['unsuspend_id'])) {
    $id = (int)$_GET['unsuspend_id'];
    try {
        $stmt = $pdo->prepare("UPDATE clinic_staff SET status = 'Active' WHERE id = ? AND TenantID = ?");
        $stmt->execute([$id, $tenant_id]);
        header("Location: staffmanagement.php?msg=unsuspended&type=success");
        exit();
    } catch (PDOException $e) { die($e->getMessage()); }
}

// --- FIRE STAFF (SOFT DELETE - set status to Fired) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_staff_id'])) {
    $del_id = (int)$_POST['delete_staff_id'];
    $revoke_reason = trim($_POST['revoke_reason'] ?? '');
    if (empty($revoke_reason)) {
        $error = "Please provide a reason for firing this staff account.";
    } else {
        try {
            $stmtDelCheck = $pdo->prepare("SELECT first_name, middle_name, last_name, email_address FROM clinic_staff WHERE id = ? AND TenantID = ?");
            $stmtDelCheck->execute([$del_id, $tenant_id]);
            $delStaff = $stmtDelCheck->fetch(PDO::FETCH_ASSOC);

            if ($delStaff) {
                $delName = trim($delStaff['first_name'] . ' ' . ($delStaff['middle_name'] ? $delStaff['middle_name'] . ' ' : '') . $delStaff['last_name']);
                $delEmail = trim($delStaff['email_address'] ?? '');

                // Soft delete: set status to Fired and store reason (two statements for reliability)
                $stmtFire = $pdo->prepare("UPDATE clinic_staff SET fire_reason = ?, fired_at = NOW() WHERE id = ? AND TenantID = ?");
                $stmtFire->execute([$revoke_reason, $del_id, $tenant_id]);
                $stmtFireStatus = $pdo->prepare("UPDATE clinic_staff SET status = 'Fired' WHERE id = ? AND TenantID = ?");
                $stmtFireStatus->execute([$del_id, $tenant_id]);

                // Also deactivate users table account
                if (!empty($delEmail)) {
                    $stmtDeactUsers = $pdo->prepare("UPDATE users SET status = 'Fired' WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND TenantID = ?");
                    $stmtDeactUsers->execute([$delEmail, $tenant_id]);
                }

                $currentUserName = $_SESSION['full_name'] ?? 'Admin';
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                $stmtAudit = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Staff Fired', ?, ?, NOW())");
                $stmtAudit->execute([$tenant_id, $currentUserName, $currentRole, "Fired staff account: $delName ($delEmail). Reason: $revoke_reason", $ip]);

                // Send email notification
                if (!empty($delEmail)) {
                    $revokeDate = date('F d, Y - h:i A');
                    $emailBody = "
                    <div style='font-family: \"Segoe UI\", Arial, sans-serif; max-width: 560px; margin: 0 auto; background: #ffffff; border-radius: 20px; overflow: hidden; border: 1px solid #e2e8f0;'>
                        <div style='background: linear-gradient(135deg, #dc2626, #991b1b); padding: 40px 30px; text-align: center;'>
                            <div style='width: 60px; height: 60px; background: rgba(255,255,255,0.15); border-radius: 16px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;'>
                                <span style='font-size: 28px; color: white;'>&#128274;</span>
                            </div>
                            <h1 style='color: #ffffff; font-size: 20px; font-weight: 800; margin: 0; letter-spacing: -0.5px;'>Access Revoked</h1>
                            <p style='color: rgba(255,255,255,0.8); font-size: 12px; margin-top: 6px; font-weight: 600;'>" . htmlspecialchars($clinicName) . "</p>
                        </div>
                        <div style='padding: 30px;'>
                            <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 20px;'>
                                Dear <strong>" . htmlspecialchars($delName) . "</strong>,
                            </p>
                            <p style='color: #334155; font-size: 14px; line-height: 1.7; margin: 0 0 20px;'>
                                Your employment at <strong>" . htmlspecialchars($clinicName) . "</strong> has been terminated and your system access has been revoked.
                            </p>
                            <div style='background: #fef2f2; border: 1px solid #fecaca; border-radius: 12px; padding: 20px; margin: 0 0 20px;'>
                                <p style='color: #991b1b; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1.5px; margin: 0 0 8px;'>Reason for Termination</p>
                                <p style='color: #dc2626; font-size: 14px; font-weight: 600; margin: 0; line-height: 1.6;'>" . htmlspecialchars($revoke_reason) . "</p>
                            </div>
                            <div style='background: #f8fafc; border-radius: 12px; padding: 16px; margin: 0 0 20px;'>
                                <table style='width: 100%; border-collapse: collapse;'>
                                    <tr><td style='color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 0;'>Terminated By</td><td style='color: #334155; font-size: 13px; font-weight: 700; text-align: right; padding: 4px 0;'>" . htmlspecialchars($currentUserName) . "</td></tr>
                                    <tr><td style='color: #94a3b8; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 4px 0;'>Date & Time</td><td style='color: #334155; font-size: 13px; font-weight: 700; text-align: right; padding: 4px 0;'>$revokeDate</td></tr>
                                </table>
                            </div>
                            <p style='color: #94a3b8; font-size: 12px; line-height: 1.6; margin: 0;'>
                                If you believe this was done in error, please contact the clinic administrator directly.
                            </p>
                        </div>
                        <div style='background: #f8fafc; padding: 16px 30px; text-align: center; border-top: 1px solid #e2e8f0;'>
                            <p style='color: #cbd5e1; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin: 0;'>MaternityHub &copy; " . date('Y') . "</p>
                        </div>
                    </div>";

                    try { send_email_via_smtp_gmail($delEmail, "Employment Terminated - " . $clinicName, $emailBody); } catch (Exception $emailErr) {}
                }

                header("Location: staffmanagement.php?msg=fired&type=success&tab=fired");
                exit();
            }
        } catch (PDOException $e) { $error = "Fire Error: " . $e->getMessage(); }
    }
}

// --- RESTORE FIRED STAFF ---
if (isset($_GET['restore_fired_id'])) {
    $restore_id = (int)$_GET['restore_fired_id'];
    try {
        $stmtRestoreCheck = $pdo->prepare("SELECT first_name, middle_name, last_name, email_address FROM clinic_staff WHERE id = ? AND TenantID = ? AND (status = 'Fired' OR fired_at IS NOT NULL)");
        $stmtRestoreCheck->execute([$restore_id, $tenant_id]);
        $restoreStaff = $stmtRestoreCheck->fetch(PDO::FETCH_ASSOC);

        if ($restoreStaff) {
            $restoreEmail = trim($restoreStaff['email_address'] ?? '');
            $restoreName = trim($restoreStaff['first_name'] . ' ' . ($restoreStaff['middle_name'] ? $restoreStaff['middle_name'] . ' ' : '') . $restoreStaff['last_name']);

            // Clear fire data first
            $stmtRestoreClear = $pdo->prepare("UPDATE clinic_staff SET fire_reason = NULL, fired_at = NULL WHERE id = ? AND TenantID = ?");
            $stmtRestoreClear->execute([$restore_id, $tenant_id]);
            // Then set status Active
            $stmtRestoreStatus = $pdo->prepare("UPDATE clinic_staff SET status = 'Active' WHERE id = ? AND TenantID = ?");
            $stmtRestoreStatus->execute([$restore_id, $tenant_id]);

            // Also reactivate users table account
            if (!empty($restoreEmail)) {
                $stmtRestoreUser = $pdo->prepare("UPDATE users SET status = 'Active' WHERE LOWER(TRIM(email)) = LOWER(TRIM(?)) AND TenantID = ?");
                $stmtRestoreUser->execute([$restoreEmail, $tenant_id]);
            }

            // Audit log
            $currentUserName = $_SESSION['full_name'] ?? 'Admin';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $stmtRestoreAudit = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Staff Restored', ?, ?, NOW())");
            $stmtRestoreAudit->execute([$tenant_id, $currentUserName, $currentRole, "Restored fired staff account: $restoreName ($restoreEmail)", $ip]);

            header("Location: staffmanagement.php?msg=restored&type=success&tab=staff");
            exit();
        }
    } catch (PDOException $e) { $error = "Restore Error: " . $e->getMessage(); }
}

// ==============================================================
// PENDING PATIENT ACCOUNTS (Minors from App Registration)
// Auto-add reject_reason, reviewed_by, reviewed_at columns kung wala pa
// ==============================================================
try { $pdo->query("SELECT reject_reason FROM patients LIMIT 1"); } catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE patients ADD COLUMN reject_reason TEXT DEFAULT NULL AFTER account_status"); } catch (PDOException $ex) {}
}
try { $pdo->query("SELECT reviewed_by FROM patients LIMIT 1"); } catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE patients ADD COLUMN reviewed_by VARCHAR(200) DEFAULT NULL AFTER reject_reason"); } catch (PDOException $ex) {}
}
// Auto-fix: change reviewed_by from INT to VARCHAR kung INT pa
try {
    $colCheck = $pdo->prepare("SELECT DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME = 'reviewed_by'");
    $colCheck->execute();
    $colType = strtolower($colCheck->fetchColumn() ?: '');
    if ($colType === 'int') {
        $pdo->exec("ALTER TABLE patients MODIFY COLUMN reviewed_by VARCHAR(200) DEFAULT NULL");
    }
} catch (PDOException $e) {}
try { $pdo->query("SELECT reviewed_at FROM patients LIMIT 1"); } catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE patients ADD COLUMN reviewed_at DATETIME DEFAULT NULL AFTER reviewed_by"); } catch (PDOException $ex) {}
}

$reviewerName = $_SESSION['full_name'] ?? 'Unknown Staff';

// --- APPROVE PATIENT ACCOUNT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve_patient'])) {
    $patientId = (int)$_POST['patient_id'];
    try {
        // Get patient info for audit log and email
        $stmtPatInfo = $pdo->prepare("SELECT full_name, patient_id, email_address FROM patients WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtPatInfo->execute([$patientId, $tenant_id]);
        $patientInfo = $stmtPatInfo->fetch(PDO::FETCH_ASSOC);
        $approvedPatientName = $patientInfo['full_name'] ?? 'Unknown Patient';
        $approvedPatientCode = $patientInfo['patient_id'] ?? '';
        $approvedPatientEmail = $patientInfo['email_address'] ?? '';

        $stmtApprove = $pdo->prepare("UPDATE patients SET account_status = 'Approved', reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND TenantID = ?");
        $stmtApprove->execute([$reviewerName, $patientId, $tenant_id]);

        // Audit Log
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $auditDetails = "Approved minor patient account of \"{$approvedPatientName}\" after Guardian ID verification.";
            $stmtAudit = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Approve Patient', ?, ?, NOW())");
            $stmtAudit->execute([$tenant_id, $reviewerName, $currentRole, $auditDetails, $ip]);
        } catch (PDOException $auditEx) {}

        // Send verification email via remote endpoint
        if (!empty($approvedPatientEmail)) {
            try {
                $postFields = http_build_query([
                    'patient_id' => $approvedPatientCode,
                    'patient_db_id' => $patientId,
                    'tenant_id' => $tenant_id,
                    'clinic_name' => $clinicName,
                    'theme_color' => $themeColor,
                    'email' => $approvedPatientEmail,
                    'patient_name' => $approvedPatientName
                ]);
                $ch = curl_init('https://maternityhub.alwaysdata.net/approve_patient.php');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $postFields,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_SSL_VERIFYPEER => true
                ]);
                $emailResult = curl_exec($ch);
                curl_close($ch);
            } catch (Exception $emailEx) {}
        }

        header("Location: staffmanagement.php?tab=pending_patients&msg=patient_approved&type=success");
        exit();
    } catch (PDOException $e) {
        header("Location: staffmanagement.php?tab=pending_patients&msg=patient_error&type=error");
        exit();
    }
}

// --- REJECT PATIENT ACCOUNT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reject_patient'])) {
    $patientId = (int)$_POST['patient_id'];
    $rejectReason = trim($_POST['reject_reason'] ?? '');
    if ($rejectReason === '') $rejectReason = 'Guardian ID verification failed.';
    try {
        // Get patient name for audit log
        $stmtPatName = $pdo->prepare("SELECT full_name FROM patients WHERE id = ? AND TenantID = ? LIMIT 1");
        $stmtPatName->execute([$patientId, $tenant_id]);
        $rejectedPatientName = $stmtPatName->fetchColumn() ?: 'Unknown Patient';

        $stmtReject = $pdo->prepare("UPDATE patients SET account_status = 'Rejected', reject_reason = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ? AND TenantID = ?");
        $stmtReject->execute([$rejectReason, $reviewerName, $patientId, $tenant_id]);

        // Audit Log
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $auditDetails = "Rejected minor patient account of \"{$rejectedPatientName}\". Reason: {$rejectReason}";
            $stmtAudit = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Reject Patient', ?, ?, NOW())");
            $stmtAudit->execute([$tenant_id, $reviewerName, $currentRole, $auditDetails, $ip]);
        } catch (PDOException $auditEx) {}

        header("Location: staffmanagement.php?tab=pending_patients&msg=patient_rejected&type=success");
        exit();
    } catch (PDOException $e) {
        header("Location: staffmanagement.php?tab=pending_patients&msg=patient_error&type=error");
        exit();
    }
}

// --- FETCH PENDING PATIENT ACCOUNTS ---
$pending_patients = [];
try {
    $stmtPending = $pdo->prepare("SELECT * FROM patients WHERE TenantID = ? AND (account_status = 'Pending' OR (guardian_id_url IS NOT NULL AND guardian_id_url != '')) ORDER BY FIELD(account_status, 'Pending', 'Rejected', 'Approved'), created_at DESC");
    $stmtPending->execute([$tenant_id]);
    $pending_patients = $stmtPending->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
$pendingCount = count(array_filter($pending_patients, fn($p) => ($p['account_status'] ?? '') === 'Pending'));
$approvedCount = count(array_filter($pending_patients, fn($p) => ($p['account_status'] ?? '') === 'Approved'));
$rejectedCount = count(array_filter($pending_patients, fn($p) => ($p['account_status'] ?? '') === 'Rejected'));

$displayName = $_SESSION['full_name'] ?? 'User';
$_ownerAlsoMidwife = false;
if ($currentUserIsOwner && $tenant_id) {
    try { $_stmtMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1"); $_stmtMw->execute([$current_staff_id, $tenant_id]); $_ownerAlsoMidwife = ((int)$_stmtMw->fetchColumn() === 1); } catch (PDOException $e) {}
}
$displayRole = $currentUserIsOwner ? ($_ownerAlsoMidwife ? 'Owner / Midwife' : 'Owner') : ($currentUserIsStaffAdmin ? $currentRole . ' | Admin' : $currentRole);

try {
    $stmtPic = $pdo->prepare("SELECT u.first_name, u.middle_name, u.last_name, COALESCE(u.profile_image, cs.profile_image) AS profile_image FROM users u LEFT JOIN clinic_staff cs ON cs.TenantID = u.TenantID AND LOWER(TRIM(COALESCE(cs.email_address, ''))) = LOWER(TRIM(COALESCE(u.email, ''))) WHERE u.id = ? LIMIT 1");
    $stmtPic->execute([$current_staff_id]);
    $userRow = $stmtPic->fetch(PDO::FETCH_ASSOC);
    $fn = trim($userRow['first_name'] ?? ''); $mn = trim($userRow['middle_name'] ?? ''); $ln = trim($userRow['last_name'] ?? '');
    $builtName = trim($fn . ($mn ? ' ' . $mn : '') . ' ' . $ln);
    if ($builtName !== '') { $displayName = $builtName; }
    $dbPic = $userRow['profile_image'] ?? null;
    if (!empty($dbPic)) {
        if (str_starts_with((string)$dbPic, 'http') || str_starts_with((string)$dbPic, 'uploads/')) { $profilePic = (string)$dbPic; }
        elseif (file_exists(__DIR__ . '/uploads/profiles/' . $dbPic)) { $profilePic = 'uploads/profiles/' . $dbPic; }
        else { $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff"; }
    } else {
        $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff";
    }
} catch (PDOException $e) {
    $profilePic = "https://ui-avatars.com/api/?name=" . urlencode($displayName) . "&background=" . ltrim($themeColor, '#') . "&color=fff";
}

// FETCH STAFF LIST (UNION) - exclude fired staff — only for admin/owner
$staff_list = [];
$fired_list = [];
$firedCount = 0;
if ($canAccessClinicStaff) {
    try {
        $stmt = $pdo->prepare(" 
            SELECT id, first_name, middle_name, last_name, email as email_address, role, created_at, status, resume_path, profile_image, 'admin' as account_type, 0 as is_admin, '[]' as granted_features, COALESCE(also_midwife, 0) as also_midwife 
            FROM users 
            WHERE TenantID = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'administrator', 'owner', 'owner/midwife')
            UNION ALL
            SELECT id, first_name, middle_name, last_name, email_address, role, created_at, status, credentials_file as resume_path, profile_image, 'staff' as account_type, COALESCE(is_admin, 0) as is_admin, COALESCE(granted_features, '[]') as granted_features, 0 as also_midwife 
            FROM clinic_staff 
            WHERE TenantID = ?
              AND COALESCE(status, '') != 'Fired'
              AND fired_at IS NULL
              AND LOWER(TRIM(COALESCE(email_address, ''))) NOT IN (
                  SELECT LOWER(TRIM(COALESCE(email, '')))
                  FROM users
                  WHERE TenantID = ? AND LOWER(TRIM(COALESCE(role, ''))) IN ('admin', 'administrator', 'owner', 'owner/midwife')
              )
            ORDER BY created_at DESC
        ");
        $stmt->execute([$tenant_id, $tenant_id, $tenant_id]);
        $staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        die("Error fetching staff: " . $e->getMessage());
    }

    // FETCH FIRED STAFF LIST
    try {
        $stmtFired = $pdo->prepare("
            SELECT id, first_name, middle_name, last_name, email_address, role, created_at, status, credentials_file as resume_path, profile_image, fire_reason, fired_at
            FROM clinic_staff 
            WHERE TenantID = ? AND (status = 'Fired' OR fired_at IS NOT NULL)
            ORDER BY fired_at DESC
        ");
        $stmtFired->execute([$tenant_id]);
        $fired_list = $stmtFired->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $fired_list = [];
    }
    $firedCount = count($fired_list);
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Staff Management - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($themeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)",
                        "background-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 4%, white)"
                    }, 
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: {
                        'soft': '0 10px 40px -10px rgba(0,0,0,0.08)'
                    }
                } 
            } 
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        
        .modal-active { opacity: 1; pointer-events: auto; }
        .modal-inactive { opacity: 0; pointer-events: none; }
        
        .staff-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .staff-card:hover { transform: translateY(-5px); border-color: var(--theme-primary); }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen overflow-hidden flex flex-col relative text-sm antialiased font-display">

<style>
    :root { --theme-primary: <?= htmlspecialchars($themeColor) ?>; }
</style>

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl border border-slate-100 text-center">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-50 transition-all text-[11px]">Cancel</button>
            <button onclick="confirmLogout()" class="flex-1 py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all text-[11px] shadow-lg shadow-red-100">Logout</button>
        </div>
    </div>
</div>

<header class="h-20 bg-primary <?= $isLightTheme ? 'border-b border-slate-200' : 'border-b border-primary-dark' ?> flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft relative transition-colors duration-300">
    <div class="flex items-center gap-4">
        <div class="size-12 rounded-full <?= $headerIconBox ?> overflow-hidden flex items-center justify-center shrink-0 border">
            <?php if ($clinicLogo): ?>
                <img src="<?= htmlspecialchars($clinicLogo) ?>" alt="Clinic Logo" class="size-full object-cover">
            <?php else: ?>
                <span class="material-symbols-outlined <?= $headerIconColor ?> text-2xl">domain</span>
            <?php endif; ?>
        </div>
        <div class="flex flex-col justify-center <?= $headerTextPrimary ?>">
            <h1 class="text-lg font-bold leading-none tracking-tight"><?= htmlspecialchars($clinicName) ?></h1>
            <div class="flex items-center gap-2 mt-1">
                <p class="<?= $headerTextSecondary ?> text-[10px] font-bold uppercase tracking-widest opacity-90">POWERED BY MATERNITYHUB</p>
                <span class="<?= $headerTextMuted ?> text-[10px]">|</span>
                <p class="<?= $headerBadgeBg ?> px-2 py-0.5 rounded text-[10px] font-black tracking-widest flex items-center gap-1">
                    CODE: <?= htmlspecialchars($clinicCode) ?>
                </p>
            </div>
        </div>
    </div>
    
    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center <?= $headerTextPrimary ?>">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="<?= $headerTextSecondary ?> text-[9px] italic opacity-80 mt-1 uppercase tracking-tighter\"><?= htmlspecialchars($displayRole) ?></p>
        </div>
        <button onclick="openLogoutModal()" class="flex items-center gap-2 <?= $headerBtn ?> border px-4 py-2 rounded-xl text-xs font-bold transition-all active:scale-95">
            <span class="material-symbols-outlined text-sm">logout</span>
            <span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-80 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 overflow-hidden" style="visibility:hidden">
        <nav id="sidebarNav" class="space-y-3 flex-1 p-6 overflow-y-auto">
            <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2">Main Menu</p>
            
            <a href="dashboard.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span>Dashboard</span>
            </a>
            <a href="appointments.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">calendar_today</span> <span>Appointments</span>
            </a>
            <a href="admissions.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">how_to_reg</span> <span>Admissions</span>
            </a>
            <a href="room.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">bed</span> <span>Rooms</span>
            </a>
            <a href="patientrecords.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">folder_shared</span> <span>Patients</span>
            </a>
                <a href="staffmanagement.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">badge</span> <span>Accounts</span>
                </a>

            <div class="space-y-3 mt-4 mb-4">
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-6">Operations</p>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('financials', $currentUserGrantedFeatures)): ?>
                <a href="financials.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">payments</span> <span>Financials</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('reports', $currentUserGrantedFeatures)): ?>
                <a href="report.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">bar_chart</span> <span>Reports</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('help_support', $currentUserGrantedFeatures)): ?>
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help & Support</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('feedback', $currentUserGrantedFeatures)): ?>
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">feedback</span> <span>Feedback</span>
                </a>
                <?php endif; ?>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin): ?>
                <a href="tenantauditlogs.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">history</span> <span>Audit Logs</span>
                </a>
                <?php endif; ?>
                <a href="<?= $currentUserIsOwner ? 'tenantsettings.php' : 'staffsettings.php' ?>" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">settings</span> <span>Settings</span>
                </a>
            </div>
        </nav>
        <script>!function(){var s=document.getElementById('sidebarNav');if(!s)return;var k='sidebarScroll';s.scrollTop=parseInt(sessionStorage.getItem(k)||'0',10);s.closest('aside').style.visibility='visible';window.addEventListener('beforeunload',function(){sessionStorage.setItem(k,s.scrollTop)})}();</script>
        
        <div class="mt-auto px-6 pt-6 pb-4 border-t border-slate-100">
            <div class="bg-slate-50 rounded-3xl p-4 flex items-center gap-4">
                <div class="size-12 rounded-full bg-cover bg-center border-2 border-white shadow-sm" style="background-image: url('<?= htmlspecialchars($profilePic) ?>');"></div>
                <div class="overflow-hidden">
                    <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($displayName) ?></p>
                    <p class="text-[10px] text-slate-500 italic">Online</p>
                </div>
            </div>
        </div>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-background-light">
        <div class="max-w-7xl mx-auto space-y-8">
            
            <?php if(isset($_GET['msg'])): ?>
                <div id="flash-message" class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 <?php echo $_GET['type'] == 'success' ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-red-100 text-red-700 border border-red-200'; ?>">
                    <span class="material-symbols-outlined"><?php echo $_GET['type'] == 'success' ? 'check_circle' : 'error'; ?></span>
                    <?php 
                        if($_GET['msg'] == 'approved') echo "Staff account activated successfully.";
                        if($_GET['msg'] == 'staff_created') echo "New staff account created successfully.";
                        if($_GET['msg'] == 'suspended') echo "Staff account has been set to Inactive.";
                        if($_GET['msg'] == 'unsuspended') echo "Staff account has been Reactivated.";
                        if($_GET['msg'] == 'deleted') echo "Account successfully removed from the database.";
                        if($_GET['msg'] == 'fired') echo "Staff account has been fired successfully.";
                        if($_GET['msg'] == 'restored') echo "Staff account has been restored successfully.";
                        if($_GET['msg'] == 'role_updated') echo "Staff role updated successfully.";
                        if($_GET['msg'] == 'access_updated') echo "Staff access permissions updated successfully.";
                        if($_GET['msg'] == 'access_error') echo "Error updating staff access permissions.";
                        if($_GET['msg'] == 'midwife_updated') echo "Owner midwife role updated successfully.";
                        if($_GET['msg'] == 'missing_staff_fields') echo "Pakicomplete ang required staff account fields.";
                        if($_GET['msg'] == 'email_exists') echo "May gumagamit na ng email na ito.";
                        if($_GET['msg'] == 'credentials_updated') echo "Credentials file updated successfully.";
                        if($_GET['msg'] == 'profile_pic_updated') echo "Profile picture updated successfully.";
                        if($_GET['msg'] == 'invalid_file') echo "Invalid file type. Allowed: PDF, JPG, PNG, WEBP, DOC.";
                        if($_GET['msg'] == 'invalid_image') echo "Invalid image type. Allowed: JPG, PNG, WEBP.";
                        if($_GET['msg'] == 'no_file') echo "No file was selected.";
                        if($_GET['msg'] == 'patient_approved') echo "Patient account has been approved successfully.";
                        if($_GET['msg'] == 'patient_rejected') echo "Patient account has been rejected.";
                        if($_GET['msg'] == 'patient_error') echo "Error processing patient account.";
                        if($_GET['msg'] == 'name_updated') echo "Owner name updated successfully.";
                        if($_GET['msg'] == 'name_missing') echo "First name and last name are required.";
                        if($_GET['msg'] == 'name_error') echo "Error updating owner name.";
                    ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h1 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">Clinic User Accounts</h1>
                    <p class="text-slate-500 text-sm font-medium tracking-tight">Manage hospital personnel and system access roles.</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="relative">
                        <span class="material-symbols-outlined absolute left-4 top-3 text-slate-400 text-lg">search</span>
                        <input type="text" id="staffSearch" onkeyup="filterStaff()" placeholder="Search staff name..." class="pl-11 pr-4 py-3 rounded-2xl border-slate-200 text-sm w-full md:w-64 focus:ring-primary focus:border-primary shadow-sm">
                    </div>
                    <?php if($currentUserIsOwner): ?>
                    <button onclick="openCreateStaffModal()" class="bg-primary text-white px-5 py-3 rounded-2xl font-bold text-xs flex items-center gap-2 hover:bg-primary-dark transition-all shadow-lg active:scale-95 uppercase tracking-wider">
                        <span class="material-symbols-outlined text-lg">person_add</span> Register Staff
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- TAB NAVIGATION -->
            <?php $activeTab = $canAccessClinicStaff ? ($_GET['tab'] ?? 'staff') : 'pending_patients'; ?>
            <div class="flex items-center gap-2 border-b border-slate-200 pb-0">
                <?php if($canAccessClinicStaff): ?>
                <button onclick="switchTab('staff')" id="tabStaff" class="tab-btn px-6 py-3 rounded-t-2xl font-black text-xs uppercase tracking-widest transition-all border-b-2 <?= $activeTab === 'staff' ? 'border-primary text-primary bg-white' : 'border-transparent text-slate-400 hover:text-slate-600' ?>">
                    <span class="material-symbols-outlined text-base align-middle mr-1">badge</span> Clinic Staff
                </button>
                <?php endif; ?>
                <button onclick="switchTab('pending_patients')" id="tabPendingPatients" class="tab-btn px-6 py-3 rounded-t-2xl font-black text-xs uppercase tracking-widest transition-all border-b-2 <?= $activeTab === 'pending_patients' ? 'border-primary text-primary bg-white' : 'border-transparent text-slate-400 hover:text-slate-600' ?> relative">
                    <span class="material-symbols-outlined text-base align-middle mr-1">pending_actions</span> Pending Patients
                    <?php if($pendingCount > 0): ?>
                    <span class="absolute -top-1 -right-1 size-5 bg-red-500 text-white rounded-full text-[9px] font-black flex items-center justify-center animate-pulse"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </button>
                <?php if($currentUserIsOwner): ?>
                <button onclick="switchTab('fired')" id="tabFired" class="tab-btn px-6 py-3 rounded-t-2xl font-black text-xs uppercase tracking-widest transition-all border-b-2 <?= $activeTab === 'fired' ? 'border-primary text-primary bg-white' : 'border-transparent text-slate-400 hover:text-slate-600' ?> relative">
                    <span class="material-symbols-outlined text-base align-middle mr-1">person_off</span> Fired Employees
                    <?php if($firedCount > 0): ?>
                    <span class="absolute -top-1 -right-1 size-5 bg-red-500 text-white rounded-full text-[9px] font-black flex items-center justify-center"><?= $firedCount ?></span>
                    <?php endif; ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- TAB 1: CLINIC STAFF -->
            <?php if($canAccessClinicStaff): ?>
            <div id="sectionStaff" class="tab-section <?= $activeTab !== 'staff' ? 'hidden' : '' ?>">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" id="staffGrid">
                <?php foreach ($staff_list as $staff): 
                    $fullName = trim($staff['first_name'] . ' ' . $staff['middle_name'] . ' ' . $staff['last_name']);
                    $isAdminRow = ($staff['account_type'] === 'admin');
                    $staffAvatar = null;
                    if (!empty($staff['profile_image'])) {
                        $profileImageValue = trim((string)$staff['profile_image']);
                        if (preg_match('~^https?://~i', $profileImageValue) || str_starts_with($profileImageValue, 'uploads/')) {
                            $staffAvatar = $profileImageValue;
                        } else {
                            $staffAvatar = 'uploads/profiles/' . $profileImageValue;
                        }
                    }
                    
                    $folder = $isAdminRow ? 'resumes' : 'staff_docs';
                    $resumePath = !empty($staff['resume_path']) ? "uploads/{$folder}/" . htmlspecialchars($staff['resume_path']) : "";
                ?>
                 <div class="staff-card bg-white rounded-3xl border border-slate-200 p-6 shadow-sm cursor-pointer relative group flex flex-col items-center text-center" 
                     onclick="openResumeModal('<?= $resumePath ?>', '<?= addslashes($fullName) ?>', '<?= addslashes($staffAvatar ?? '') ?>', <?= $staff['id'] ?>, '<?= $staff['account_type'] ?>', '<?= addslashes($staff['role']) ?>', <?= (int)($staff['is_admin'] ?? 0) ?>, '<?= htmlspecialchars($staff['granted_features'] ?? '[]', ENT_QUOTES) ?>')">
                    
                    <?php if(!$isAdminRow): ?>
                    <div class="absolute top-4 right-4 flex gap-1">
                        <?php if($currentUserIsOwner): ?>
                        <button onclick="event.stopPropagation(); openManageAccessModal(<?= $staff['id'] ?>, '<?= addslashes($fullName) ?>', '<?= addslashes($staff['role']) ?>', <?= (int)($staff['is_admin'] ?? 0) ?>, '<?= htmlspecialchars($staff['granted_features'] ?? '[]', ENT_QUOTES) ?>')" 
                                class="size-8 rounded-full bg-slate-50 text-purple-500 hover:bg-purple-500 hover:text-white flex items-center justify-center transition-all" title="Manage Access">
                            <span class="material-symbols-outlined text-sm">admin_panel_settings</span>
                        </button>
                        <?php endif; ?>
                        <button onclick="event.stopPropagation(); openEditRoleModal(<?= $staff['id'] ?>, '<?= addslashes($fullName) ?>', '<?= addslashes($staff['role']) ?>')" 
                                class="size-8 rounded-full bg-slate-50 text-blue-500 hover:bg-blue-500 hover:text-white flex items-center justify-center transition-all" title="Edit Role">
                            <span class="material-symbols-outlined text-sm">edit</span>
                        </button>
                        <button onclick="event.stopPropagation(); confirmDelete(<?= $staff['id'] ?>, '<?= addslashes($fullName) ?>')" 
                                class="size-8 rounded-full bg-slate-50 text-slate-400 hover:bg-red-500 hover:text-white flex items-center justify-center transition-all" title="Fire Employee">
                            <span class="text-[10px] inline-flex whitespace-nowrap">👤❌</span>
                        </button>
                    </div>
                    <?php elseif($isAdminRow && $currentUserIsOwner && (int)$staff['id'] === $current_staff_id): ?>
                    <div class="absolute top-4 right-4 flex gap-1">
                        <button type="button" onclick="event.stopPropagation(); openEditOwnerNameModal('<?= addslashes($staff['first_name']) ?>', '<?= addslashes($staff['middle_name'] ?? '') ?>', '<?= addslashes($staff['last_name']) ?>')" class="size-8 rounded-full bg-slate-50 text-blue-500 hover:bg-blue-500 hover:text-white flex items-center justify-center transition-all" title="Edit Name">
                            <span class="material-symbols-outlined text-sm">edit</span>
                        </button>
                        <button type="button" onclick="event.stopPropagation(); confirmMidwifeToggle(<?= (int)($staff['also_midwife'] ?? 0) ?>)" class="size-8 rounded-full <?= (int)($staff['also_midwife'] ?? 0) === 1 ? 'bg-green-500 text-white' : 'bg-slate-50 text-green-500 hover:bg-green-500 hover:text-white' ?> flex items-center justify-center transition-all" title="<?= (int)($staff['also_midwife'] ?? 0) === 1 ? 'Remove Midwife Role' : 'Set as Midwife' ?>">
                            <span class="material-symbols-outlined text-sm">medical_services</span>
                        </button>
                    </div>
                    <?php endif; ?>

                    <?php if ($staffAvatar): ?>
                        <img src="<?= htmlspecialchars($staffAvatar) ?>" alt="<?= htmlspecialchars($fullName) ?>" class="size-20 rounded-2xl object-cover mb-4 border-2 border-white shadow-inner">
                    <?php else: ?>
                        <div class="size-20 rounded-2xl bg-slate-100 flex items-center justify-center font-black text-slate-400 text-2xl mb-4 border-2 border-white shadow-inner group-hover:bg-primary/10 group-hover:text-primary transition-colors">
                            <?php echo strtoupper(substr($staff['first_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>

                    <h3 class="font-black text-slate-800 tracking-tight text-lg leading-tight mb-1 truncate w-full">
                        <?php echo htmlspecialchars($fullName); ?>
                    </h3>
                    <p class="text-[10px] text-slate-500 font-medium mb-3 truncate w-full">
                        <?php echo htmlspecialchars($staff['email_address']); ?>
                    </p>
                    
                    <?php 
                        $role = $staff['role'];
                        $staffIsAdmin = (int)($staff['is_admin'] ?? 0) === 1;
                        $badgeClass = "bg-slate-100 text-slate-600";
                        if($isAdminRow) $badgeClass = "bg-red-50 text-red-600";
                        else if($role == 'OB-Gynecologist') $badgeClass = "bg-blue-50 text-blue-600";
                        else if($role == 'Midwife') $badgeClass = "bg-green-50 text-green-600";
                        else if($role == 'Receptionist') $badgeClass = "bg-amber-50 text-amber-600";
                        $displayRoleLabel = $isAdminRow ? ((int)($staff['also_midwife'] ?? 0) === 1 ? 'Owner / Midwife' : 'Owner') : htmlspecialchars($role);
                        if ($isAdminRow && (int)($staff['also_midwife'] ?? 0) === 1) { $badgeClass = "bg-gradient-to-r from-red-50 to-green-50 text-green-700"; }
                    ?>
                    <div class="flex items-center gap-1.5 mb-4 flex-wrap justify-center">
                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase <?php echo $badgeClass; ?> tracking-widest">
                            <?php echo $displayRoleLabel; ?>
                        </span>
                        <?php if($staffIsAdmin && !$isAdminRow): ?>
                        <span class="px-2 py-1 rounded-full text-[9px] font-black uppercase bg-purple-50 text-purple-600 tracking-widest">
                            Admin
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="w-full border-t border-slate-50 pt-4 mt-auto flex flex-col gap-3">
                        <div class="flex justify-between items-center px-2">
                             <?php if($staff['status'] == 'Pending'): ?>
                                <span class="text-amber-600 font-bold text-[10px] flex items-center gap-1.5 uppercase tracking-tighter">
                                    <span class="size-1.5 rounded-full bg-amber-500 animate-pulse"></span> Pending
                                </span>
                            <?php elseif($staff['status'] == 'Inactive' || $staff['status'] == 'Suspended'): ?>
                                <span class="text-red-600 font-bold text-[10px] flex items-center gap-1.5 uppercase tracking-tighter">
                                    <span class="size-1.5 rounded-full bg-red-500"></span> Inactive
                                </span>
                            <?php else: ?>
                                <span class="text-green-600 font-bold text-[10px] flex items-center gap-1.5 uppercase tracking-tighter">
                                    <span class="size-1.5 rounded-full bg-green-500"></span> Active
                                </span>
                            <?php endif; ?>
                            <span class="text-[9px] font-bold text-slate-400 uppercase"><?php echo date("M Y", strtotime($staff['created_at'])); ?></span>
                        </div>

                        <?php if($staff['status'] == 'Pending'): ?>
                                <button onclick="event.stopPropagation(); confirmApprove(<?php echo $staff['id']; ?>, '<?php echo addslashes($fullName); ?>')" 
                                    class="w-full py-2.5 bg-primary text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-primary-dark transition-all shadow-md">
                                Activate Account
                            </button>
                        <?php elseif($staff['status'] == 'Inactive' || $staff['status'] == 'Suspended'): ?>
                            <button onclick="event.stopPropagation(); confirmUnsuspend(<?php echo $staff['id']; ?>, '<?php echo addslashes($fullName); ?>')" 
                                    class="w-full py-2.5 bg-amber-600 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-amber-700 transition-all shadow-md">
                                Reactivate Account
                            </button>
                        <?php else: ?>
                            <div class="flex gap-2">
                                <button class="flex-1 py-2.5 bg-slate-900 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-primary transition-all shadow-md group-hover:bg-primary">
                                    View Profile
                                </button>
                                <?php if(!$isAdminRow): ?>
                                    <button onclick="event.stopPropagation(); confirmSuspend(<?php echo $staff['id']; ?>, '<?php echo addslashes($fullName); ?>')" 
                                            class="flex-1 py-2.5 bg-red-50 text-red-600 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-red-600 hover:text-white transition-all shadow-md">
                                        Suspend
                                    </button>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            </div>
            <?php endif; ?>

            <!-- TAB 2: PENDING PATIENT ACCOUNTS -->
            <div id="sectionPendingPatients" class="tab-section <?= $activeTab !== 'pending_patients' ? 'hidden' : '' ?>">
                <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-6 flex items-center gap-3">
                    <span class="material-symbols-outlined text-amber-600 text-2xl">info</span>
                    <p class="text-amber-800 text-xs font-bold">For minor patients, guardian id must be provided and verified before the account can be activated.</p>
                </div>

                <!-- Sub-tab breadcrumbs -->
                <div class="flex gap-2 mb-6" id="patientSubTabs">
                    <button onclick="filterPatientStatus('Pending')" data-subtab="Pending" class="patient-subtab px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border-2 border-amber-500 bg-amber-500 text-white shadow-md flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">schedule</span> Pending
                        <?php if($pendingCount > 0): ?><span class="size-5 bg-white/30 rounded-full text-[9px] font-black flex items-center justify-center"><?= $pendingCount ?></span><?php endif; ?>
                    </button>
                    <button onclick="filterPatientStatus('Approved')" data-subtab="Approved" class="patient-subtab px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border-2 border-slate-200 bg-white text-slate-400 hover:border-green-300 hover:text-green-600 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">check_circle</span> Approved
                        <?php if($approvedCount > 0): ?><span class="size-5 bg-green-100 text-green-600 rounded-full text-[9px] font-black flex items-center justify-center"><?= $approvedCount ?></span><?php endif; ?>
                    </button>
                    <button onclick="filterPatientStatus('Rejected')" data-subtab="Rejected" class="patient-subtab px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border-2 border-slate-200 bg-white text-slate-400 hover:border-red-300 hover:text-red-600 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm">cancel</span> Rejected
                        <?php if($rejectedCount > 0): ?><span class="size-5 bg-red-100 text-red-600 rounded-full text-[9px] font-black flex items-center justify-center"><?= $rejectedCount ?></span><?php endif; ?>
                    </button>
                </div>

                <?php if(empty($pending_patients)): ?>
                <div class="flex flex-col items-center justify-center py-16 text-center">
                    <span class="material-symbols-outlined text-6xl text-slate-200 mb-4">how_to_reg</span>
                    <h3 class="text-lg font-black text-slate-400 uppercase tracking-tight">No Pending Accounts</h3>
                    <p class="text-slate-400 text-xs mt-1">Walang minor patient accounts na nag-aantay ng verification.</p>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" id="pendingPatientsGrid">
                    <?php foreach($pending_patients as $patient):
                        $patientFullName = $patient['full_name'] ?? trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''));
                        $dob = $patient['birthday'] ?? $patient['date_of_birth'] ?? '';
                        $age = '';
                        if ($dob) {
                            $dobDate = new DateTime($dob);
                            $now = new DateTime();
                            $age = $dobDate->diff($now)->y . ' yrs old';
                        }
                        $gidUrl = $patient['guardian_id_url'] ?? '';
                        $guardianImg = !empty($gidUrl) ? (preg_match('~^https?://~i', $gidUrl) ? $gidUrl : 'uploads/guardian_ids/' . $gidUrl) : '';
                        $patientStatus = $patient['account_status'] ?? 'Pending';
                    ?>
                    <div class="patient-status-card staff-card bg-white rounded-3xl border border-slate-200 p-6 shadow-sm relative group flex flex-col items-center text-center
                        <?= $patientStatus === 'Approved' ? 'opacity-60' : '' ?>
                        <?= $patientStatus === 'Rejected' ? 'opacity-50' : '' ?>" data-status="<?= htmlspecialchars($patientStatus) ?>">
                        
                        <?php if($patientStatus === 'Approved'): ?>
                        <div class="absolute top-3 left-3">
                            <span class="px-2 py-1 rounded-full text-[9px] font-black uppercase bg-green-100 text-green-600 tracking-widest flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">check_circle</span> Approved
                            </span>
                        </div>
                        <?php elseif($patientStatus === 'Rejected'): ?>
                        <div class="absolute top-3 left-3">
                            <span class="px-2 py-1 rounded-full text-[9px] font-black uppercase bg-red-100 text-red-600 tracking-widest flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">cancel</span> Rejected
                            </span>
                        </div>
                        <?php else: ?>
                        <div class="absolute top-3 left-3">
                            <span class="px-2 py-1 rounded-full text-[9px] font-black uppercase bg-amber-100 text-amber-600 tracking-widest flex items-center gap-1 animate-pulse">
                                <span class="material-symbols-outlined text-xs">schedule</span> Pending
                            </span>
                        </div>
                        <?php endif; ?>

                        <div class="size-20 rounded-2xl bg-amber-50 flex items-center justify-center font-black text-amber-500 text-2xl mb-4 border-2 border-white shadow-inner">
                            <span class="material-symbols-outlined text-3xl">child_care</span>
                        </div>

                        <h3 class="font-black text-slate-800 tracking-tight text-lg leading-tight mb-1 truncate w-full">
                            <?= htmlspecialchars($patientFullName) ?>
                        </h3>
                        <p class="text-[10px] text-slate-500 font-medium mb-1">
                            <?= htmlspecialchars($patient['email_address'] ?? 'No email') ?>
                        </p>
                        <p class="text-[10px] text-slate-400 font-bold mb-3">
                            <span class="material-symbols-outlined text-xs align-middle">cake</span>
                            <?= htmlspecialchars($dob ? date('M d, Y', strtotime($dob)) : 'N/A') ?>
                            <?php if($age): ?> &middot; <?= $age ?><?php endif; ?>
                        </p>

                        <div class="w-full bg-slate-50 rounded-xl p-3 mb-4 text-left">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Email</p>
                            <p class="text-xs font-bold text-slate-700 truncate">
                                <span class="material-symbols-outlined text-xs align-middle text-slate-400">mail</span>
                                <?= htmlspecialchars($patient['email_address'] ?? 'N/A') ?>
                            </p>
                        </div>

                        <div class="w-full border-t border-slate-50 pt-4 mt-auto flex flex-col gap-2">
                            <button onclick="openPatientDetailModal(<?= $patient['id'] ?>, '<?= addslashes($patientFullName) ?>', '<?= addslashes($patient['email_address'] ?? '') ?>', '<?= addslashes($dob) ?>', '<?= addslashes($age) ?>', '<?= addslashes($patient['contact_number'] ?? '') ?>', '<?= addslashes($guardianImg) ?>', '<?= addslashes($patientStatus) ?>', '<?= addslashes($patient['reject_reason'] ?? '') ?>', '<?= addslashes($patient['created_at'] ?? '') ?>')" 
                                class="w-full py-2.5 bg-slate-900 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-primary transition-all shadow-md flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-sm">visibility</span> View Details & Guardian ID
                            </button>
                            <?php if($patientStatus === 'Pending'): ?>
                            <div class="flex gap-2">
                                <button type="button" onclick="openApprovePatientModal(<?= $patient['id'] ?>, '<?= addslashes($patientFullName) ?>')" class="flex-1 py-2.5 bg-green-500 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-green-600 transition-all shadow-md flex items-center justify-center gap-1">
                                    <span class="material-symbols-outlined text-sm">check_circle</span> Approve
                                </button>
                                <button onclick="openRejectModal(<?= $patient['id'] ?>, '<?= addslashes($patientFullName) ?>')" class="flex-1 py-2.5 bg-red-50 text-red-600 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-red-600 hover:text-white transition-all">
                                    Reject
                                </button>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- TAB 3: FIRED EMPLOYEES -->
            <?php if($currentUserIsOwner): ?>
            <div id="sectionFired" class="tab-section <?= $activeTab !== 'fired' ? 'hidden' : '' ?>">
                <div class="bg-red-50 border border-red-200 rounded-2xl p-4 mb-6 flex items-center gap-3">
                    <span class="material-symbols-outlined text-red-600 text-2xl">person_off</span>
                    <p class="text-red-800 text-xs font-bold">These employees have been terminated. You can restore them anytime to reactivate their accounts.</p>
                </div>

                <?php if(empty($fired_list)): ?>
                <div class="flex flex-col items-center justify-center py-20 text-center">
                    <span class="material-symbols-outlined text-6xl text-slate-200 mb-4">sentiment_satisfied</span>
                    <p class="font-black text-slate-300 uppercase text-xs tracking-widest">No fired employees</p>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                    <?php foreach ($fired_list as $firedStaff): 
                        $firedFullName = trim($firedStaff['first_name'] . ' ' . $firedStaff['middle_name'] . ' ' . $firedStaff['last_name']);
                        $firedAvatar = null;
                        if (!empty($firedStaff['profile_image'])) {
                            $firedPicVal = trim((string)$firedStaff['profile_image']);
                            if (preg_match('~^https?://~i', $firedPicVal) || str_starts_with($firedPicVal, 'uploads/')) {
                                $firedAvatar = $firedPicVal;
                            } else {
                                $firedAvatar = 'uploads/profiles/' . $firedPicVal;
                            }
                        }
                    ?>
                    <div class="bg-white rounded-3xl border border-red-100 p-6 shadow-sm relative flex flex-col items-center text-center opacity-75">
                        <div class="absolute top-4 right-4">
                            <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase bg-red-100 text-red-600 tracking-widest">Fired</span>
                        </div>

                        <?php if ($firedAvatar): ?>
                            <img src="<?= htmlspecialchars($firedAvatar) ?>" alt="<?= htmlspecialchars($firedFullName) ?>" class="size-20 rounded-2xl object-cover mb-4 border-2 border-white shadow-inner grayscale">
                        <?php else: ?>
                            <div class="size-20 rounded-2xl bg-red-50 flex items-center justify-center font-black text-red-300 text-2xl mb-4 border-2 border-white shadow-inner">
                                <?= strtoupper(substr($firedStaff['first_name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>

                        <h3 class="font-black text-slate-600 tracking-tight text-lg leading-tight mb-1 truncate w-full"><?= htmlspecialchars($firedFullName) ?></h3>
                        <p class="text-[10px] text-slate-400 font-medium mb-2 truncate w-full"><?= htmlspecialchars($firedStaff['email_address'] ?? '') ?></p>
                        
                        <?php 
                            $firedRole = $firedStaff['role'];
                            $firedBadge = "bg-slate-100 text-slate-600";
                            if($firedRole == 'Midwife') $firedBadge = "bg-green-50 text-green-600";
                            else if($firedRole == 'Receptionist') $firedBadge = "bg-amber-50 text-amber-600";
                        ?>
                        <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase <?= $firedBadge ?> tracking-widest mb-3"><?= htmlspecialchars($firedRole) ?></span>

                        <?php if(!empty($firedStaff['fire_reason'])): ?>
                        <div class="w-full bg-red-50 rounded-xl p-3 mb-3 text-left border border-red-100">
                            <p class="text-[9px] font-black uppercase tracking-widest text-red-400 mb-1">Reason</p>
                            <p class="text-xs text-red-600 font-medium leading-relaxed"><?= htmlspecialchars($firedStaff['fire_reason']) ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="w-full border-t border-slate-50 pt-3 mt-auto flex flex-col gap-2">
                            <div class="flex justify-between items-center px-2 mb-2">
                                <span class="text-red-500 font-bold text-[10px] flex items-center gap-1.5 uppercase tracking-tighter">
                                    <span class="size-1.5 rounded-full bg-red-500"></span> Fired
                                </span>
                                <span class="text-[9px] font-bold text-slate-400 uppercase"><?= !empty($firedStaff['fired_at']) ? date("M d, Y", strtotime($firedStaff['fired_at'])) : date("M Y", strtotime($firedStaff['created_at'])) ?></span>
                            </div>
                            <button onclick="confirmRestoreFired(<?= $firedStaff['id'] ?>, '<?= addslashes($firedFullName) ?>')" 
                                    class="w-full py-2.5 bg-emerald-600 text-white rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-emerald-700 transition-all shadow-md flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-sm">settings_backup_restore</span> Restore Employee
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<div id="editRoleModal" class="fixed inset-0 z-[95] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeEditRoleModal()"></div>
    <div class="bg-white w-full max-w-sm rounded-[32px] shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div>
                <h3 class="text-lg font-black text-slate-800 tracking-tight">Edit Staff Role</h3>
                <p id="editRoleName" class="text-xs text-primary font-bold"></p>
            </div>
            <button type="button" onclick="closeEditRoleModal()" class="size-8 flex items-center justify-center rounded-full hover:bg-red-50 text-red-500 transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-6">
            <input type="hidden" name="edit_staff_role" value="1">
            <input type="hidden" name="edit_staff_id" id="edit_staff_id">
            
            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Designated Role</label>
                <select name="edit_role" id="edit_role_select" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary font-bold text-slate-700">
                    <option value="Midwife">Midwife</option>
                    <option value="Receptionist">Receptionist</option>
                </select>
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeEditRoleModal()" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-blue-500 text-white font-black text-[11px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg shadow-blue-500/20">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="createStaffModal" class="fixed inset-0 z-[85] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeCreateStaffModal()"></div>
    <div class="bg-white w-full max-w-3xl rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div>
                <h3 class="text-xl font-black text-slate-800 tracking-tight">Create Staff Account</h3>
                <p class="text-xs text-slate-500 font-medium">Owner can register staff directly under this clinic.</p>
            </div>
            <button type="button" onclick="closeCreateStaffModal()" class="size-10 flex items-center justify-center rounded-full hover:bg-red-50 text-red-500">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="p-6 md:p-8 space-y-6">
            <input type="hidden" name="create_staff" value="1">

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">First Name</label>
                    <input type="text" name="first_name" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" placeholder="Enter first name">
                </div>
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Middle Name</label>
                    <input type="text" name="middle_name" class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" placeholder="Optional">
                </div>
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Last Name</label>
                    <input type="text" name="last_name" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" placeholder="Enter last name">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Email Address</label>
                    <input type="email" name="email" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary" placeholder="staff@clinic.com">
                </div>
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Assign Role</label>
                    <select name="role" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary font-bold text-slate-700">
                        <option value="Midwife">Midwife</option>
                        <option value="Receptionist">Receptionist</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Password</label>
                    <div class="relative">
                        <input type="password" name="password" id="createStaffPassword" required minlength="8" class="w-full rounded-2xl border-slate-200 px-4 py-3 pr-12 text-sm focus:border-primary focus:ring-primary" placeholder="Minimum 8 characters">
                        <span onclick="togglePw('createStaffPassword','toggleIconPw')" class="absolute right-3 top-1/2 -translate-y-1/2 z-10 cursor-pointer text-slate-400 hover:text-slate-600 select-none">
                            <span class="material-symbols-outlined text-xl" id="toggleIconPw">visibility_off</span>
                        </span>
                    </div>
                </div>
                <div class="space-y-2">
                    <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Confirm Password</label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="createStaffConfirmPassword" required minlength="8" class="w-full rounded-2xl border-slate-200 px-4 py-3 pr-12 text-sm focus:border-primary focus:ring-primary" placeholder="Re-enter password">
                        <span onclick="togglePw('createStaffConfirmPassword','toggleIconCpw')" class="absolute right-3 top-1/2 -translate-y-1/2 z-10 cursor-pointer text-slate-400 hover:text-slate-600 select-none">
                            <span class="material-symbols-outlined text-xl" id="toggleIconCpw">visibility_off</span>
                        </span>
                    </div>
                </div>
            </div>

            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Profile Picture</label>
                <input type="file" name="profile_image" accept=".jpg,.jpeg,.png,.webp" class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm file:mr-4 file:rounded-xl file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:font-bold file:text-primary hover:file:bg-primary/20">
                <p class="text-[11px] text-slate-400">Optional. Allowed: JPG, JPEG, PNG, WEBP.</p>
            </div>

            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Credentials File</label>
                <input type="file" name="resume_file" accept=".pdf,.jpg,.jpeg,.png" class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm file:mr-4 file:rounded-xl file:border-0 file:bg-primary/10 file:px-4 file:py-2 file:font-bold file:text-primary hover:file:bg-primary/20">
                <p class="text-[11px] text-slate-400">Optional. Allowed: PDF, JPG, JPEG, PNG.</p>
            </div>

            <div class="flex flex-col-reverse md:flex-row md:justify-end gap-3 pt-2">
                <button type="button" onclick="closeCreateStaffModal()" class="px-6 py-3 rounded-2xl bg-slate-100 text-slate-600 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="px-6 py-3 rounded-2xl bg-primary text-white font-black text-[11px] uppercase tracking-widest hover:bg-primary-dark transition-all shadow-lg shadow-primary/20 flex items-center justify-center gap-2"><span class="material-symbols-outlined text-base">person_add</span> Create Staff Account</button>
            </div>
        </form>
    </div>
</div>

<div id="resumePreviewModal" class="fixed inset-0 z-[70] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/80 backdrop-blur-md" onclick="closeResumeModal()"></div>
    <div class="bg-white w-full max-w-5xl h-[85vh] rounded-[32px] shadow-2xl relative z-10 overflow-hidden flex flex-col">
        <div class="p-6 border-b flex justify-between items-center bg-slate-50 gap-4">
            <div class="flex items-center gap-4 min-w-0">
                <div id="previewStaffAvatarWrap" class="size-14 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden flex items-center justify-center shrink-0 relative group/avatar">
                    <img id="previewStaffAvatar" src="" alt="Staff profile picture" class="size-full object-cover hidden">
                    <span id="previewStaffAvatarFallback" class="material-symbols-outlined text-2xl text-slate-400">person</span>
                    <button id="btnEditAvatar" type="button" onclick="event.stopPropagation(); document.getElementById('profilePicInput').click();" 
                            class="hidden absolute inset-0 bg-black/50 items-center justify-center rounded-2xl opacity-0 group-hover/avatar:opacity-100 transition-opacity cursor-pointer">
                        <span class="material-symbols-outlined text-white text-lg">photo_camera</span>
                    </button>
                </div>
                <div class="min-w-0">
                    <h3 class="font-black text-slate-800 flex items-center gap-2 uppercase text-sm tracking-tight">
                        <span class="material-symbols-outlined text-primary">badge</span> Staff Profile
                    </h3>
                    <p id="previewStaffName" class="text-xs text-slate-500 font-medium"></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <?php if($currentUserIsOwner): ?>
                <button id="btnManageAccess" type="button" onclick="manageAccessFromProfile()" class="hidden px-4 py-2.5 rounded-xl bg-purple-50 text-purple-600 font-black text-[10px] uppercase tracking-widest hover:bg-purple-100 transition-all flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">admin_panel_settings</span> Manage Access
                </button>
                <button id="btnEditCred" type="button" onclick="event.stopPropagation(); document.getElementById('credentialInput').click();" class="hidden px-4 py-2.5 rounded-xl bg-blue-50 text-blue-600 font-black text-[10px] uppercase tracking-widest hover:bg-blue-100 transition-all flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">upload_file</span> Edit Credentials
                </button>
                <button id="btnEditPic" type="button" onclick="event.stopPropagation(); document.getElementById('profilePicInput').click();" class="hidden px-4 py-2.5 rounded-xl bg-green-50 text-green-600 font-black text-[10px] uppercase tracking-widest hover:bg-green-100 transition-all flex items-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">photo_camera</span> Edit Photo
                </button>
                <?php endif; ?>
                <button onclick="closeResumeModal()" class="size-10 flex items-center justify-center rounded-full hover:bg-red-50 text-red-500">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        <div class="flex-1 bg-slate-100 flex items-center justify-center">
            <iframe id="resumeFrame" src="" class="w-full h-full border-none hidden"></iframe>
            <div id="noResumePlaceholder" class="hidden flex flex-col items-center text-center p-8">
                <span class="material-symbols-outlined text-6xl text-slate-300 mb-4">folder_off</span>
                <p class="font-bold text-slate-400 uppercase text-xs">No Document Attached</p>
            </div>
        </div>
    </div>
    <!-- Hidden forms for file uploads -->
    <form id="credentialForm" method="POST" enctype="multipart/form-data" class="hidden">
        <input type="hidden" name="update_credentials" value="1">
        <input type="hidden" name="cred_staff_id" id="cred_staff_id">
        <input type="hidden" name="cred_account_type" id="cred_account_type">
        <input type="file" name="new_credentials" id="credentialInput" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx" onchange="document.getElementById('credentialForm').submit();">
    </form>
    <form id="profilePicForm" method="POST" enctype="multipart/form-data" class="hidden">
        <input type="hidden" name="update_profile_pic" value="1">
        <input type="hidden" name="pic_staff_id" id="pic_staff_id">
        <input type="hidden" name="pic_account_type" id="pic_account_type">
        <input type="file" name="new_profile_pic" id="profilePicInput" accept=".jpg,.jpeg,.png,.webp" onchange="document.getElementById('profilePicForm').submit();">
    </form>
</div>

<!-- MIDWIFE TOGGLE CONFIRMATION MODAL -->
<div id="midwifeModal" class="fixed inset-0 z-[80] flex items-center justify-center modal-inactive transition-all duration-300">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeMidwifeModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 p-8 text-center">
        <form id="midwifeToggleForm" method="POST" action="staffmanagement.php">
        <input type="hidden" name="toggle_also_midwife" value="1">
        <input type="hidden" name="also_midwife_val" id="midwifeValField" value="">
        <div id="midwifeModalIcon" class="size-20 rounded-3xl flex items-center justify-center mx-auto mb-6">
            <span class="material-symbols-outlined text-5xl">medical_services</span>
        </div>
        <h3 id="midwifeModalTitle" class="text-2xl font-black text-slate-800 mb-2"></h3>
        <p id="midwifeModalDesc" class="text-slate-500 text-sm mb-6 px-4"></p>
        <div class="flex gap-3">
            <button type="button" onclick="closeMidwifeModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black uppercase text-[10px] hover:bg-slate-200 transition-colors">Cancel</button>
            <button type="submit" id="midwifeModalBtn" class="flex-1 py-4 rounded-2xl font-black uppercase text-[10px] transition-colors"></button>
        </div>
        </form>
    </div>
</div>

<!-- EDIT OWNER NAME MODAL -->
<div id="editOwnerNameModal" class="fixed inset-0 z-[95] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeEditOwnerNameModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div>
                <h3 class="text-lg font-black text-slate-800 tracking-tight">Edit Your Name</h3>
                <p class="text-[11px] text-slate-500 font-medium">Update your display name.</p>
            </div>
            <button type="button" onclick="closeEditOwnerNameModal()" class="size-8 flex items-center justify-center rounded-full hover:bg-red-50 text-red-500 transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-4">
            <input type="hidden" name="update_owner_name" value="1">
            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">First Name <span class="text-red-500">*</span></label>
                <input type="text" name="owner_first_name" id="ownerFirstName" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary font-bold text-slate-700" placeholder="First name">
            </div>
            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Middle Name</label>
                <input type="text" name="owner_middle_name" id="ownerMiddleName" class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary font-bold text-slate-700" placeholder="Optional">
            </div>
            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Last Name <span class="text-red-500">*</span></label>
                <input type="text" name="owner_last_name" id="ownerLastName" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-primary focus:ring-primary font-bold text-slate-700" placeholder="Last name">
            </div>
            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeEditOwnerNameModal()" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-blue-500 text-white font-black text-[11px] uppercase tracking-widest hover:bg-blue-600 transition-all shadow-lg shadow-blue-500/20">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 z-[80] flex items-center justify-center modal-inactive transition-all duration-300">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 p-8 text-center">
        <form id="deleteStaffForm" method="POST" action="staffmanagement.php">
        <input type="hidden" name="delete_staff_id" id="deleteStaffIdField" value="">
        <div class="size-20 rounded-3xl bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-6"><span class="text-4xl inline-flex whitespace-nowrap">👤❌</span></div>
        <h3 class="text-2xl font-black text-slate-800 mb-2">Fire this employee?</h3>
        <p class="text-slate-500 text-sm mb-4 px-4">Account of <span id="deleteStaffName" class="font-bold text-slate-900"></span> will be deactivated. You can restore them later from the Fired Employees tab.</p>
        <div class="text-left mb-6">
            <label class="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-2">Reason for Removal <span class="text-red-500">*</span></label>
            <textarea name="revoke_reason" id="deleteReasonField" rows="3" required
                class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-red-500/30 focus:border-red-400 resize-none" 
                placeholder="Please provide a reason for removing this staff account..."></textarea>
            <p id="deleteReasonError" class="text-red-500 text-xs mt-1 hidden">Please provide a reason before proceeding.</p>
        </div>
        <div class="flex gap-3">
            <button type="button" onclick="closeDeleteModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black uppercase text-[10px]">Cancel</button>
            <button type="button" onclick="submitDeleteStaff()" class="flex-1 py-4 rounded-2xl bg-red-600 text-white font-black uppercase text-[10px] hover:bg-red-700 transition-colors">Fire</button>
        </div>
        </form>
    </div>
</div>

<div id="suspendModal" class="fixed inset-0 z-[80] flex items-center justify-center modal-inactive transition-all duration-300">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeSuspendModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 p-8 text-center">
        <form id="suspendStaffForm" method="POST" action="staffmanagement.php">
        <input type="hidden" name="suspend_staff_id" id="suspendStaffIdField" value="">
        <div class="size-20 rounded-3xl bg-red-100 text-red-600 flex items-center justify-center mx-auto mb-6"><span class="material-symbols-outlined text-5xl">block</span></div>
        <h3 class="text-2xl font-black text-slate-800 mb-2">Suspend Account?</h3>
        <p class="text-slate-500 text-sm mb-4 px-4"><span id="suspendStaffName" class="font-bold text-slate-900"></span> will be unable to access the system.</p>
        <div class="text-left mb-6">
            <label class="block text-[11px] font-black uppercase tracking-widest text-slate-400 mb-2">Reason for Suspension <span class="text-red-500">*</span></label>
            <textarea name="suspend_reason" id="suspendReasonField" rows="3" required
                class="w-full rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-amber-500/30 focus:border-amber-400 resize-none" 
                placeholder="Please provide a reason for suspending this staff account..."></textarea>
            <p id="suspendReasonError" class="text-red-500 text-xs mt-1 hidden">Please provide a reason before proceeding.</p>
        </div>
        <div class="flex gap-3">
            <button type="button" onclick="closeSuspendModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black uppercase text-[10px]">Cancel</button>
            <button type="button" onclick="submitSuspendStaff()" class="flex-1 py-4 rounded-2xl bg-red-600 text-white font-black uppercase text-[10px] hover:bg-red-700 transition-colors">Suspend Now</button>
        </div>
        </form>
    </div>
</div>

<div id="unsuspendModal" class="fixed inset-0 z-[80] flex items-center justify-center modal-inactive transition-all duration-300">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeUnsuspendModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 p-8 text-center">
        <div class="size-20 rounded-3xl bg-amber-100 text-amber-600 flex items-center justify-center mx-auto mb-6"><span class="material-symbols-outlined text-5xl">check_circle</span></div>
        <h3 class="text-2xl font-black text-slate-800 mb-2">Reactivate Account?</h3>
        <p class="text-slate-500 text-sm mb-8 px-4"><span id="unsuspendStaffName" class="font-bold text-slate-900"></span> will regain system access.</p>
        <div class="flex gap-3">
            <button onclick="closeUnsuspendModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black uppercase text-[10px]">Cancel</button>
            <a id="confirmUnsuspendBtn" href="#" class="flex-1 py-4 rounded-2xl bg-amber-600 text-white font-black uppercase text-[10px]">Reactivate Now</a>
        </div>
    </div>
</div>

<div id="restoreFiredModal" class="fixed inset-0 z-[80] flex items-center justify-center modal-inactive transition-all duration-300">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeRestoreFiredModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 p-8 text-center">
        <div class="size-20 rounded-3xl bg-emerald-100 text-emerald-600 flex items-center justify-center mx-auto mb-6"><span class="material-symbols-outlined text-5xl">settings_backup_restore</span></div>
        <h3 class="text-2xl font-black text-slate-800 mb-2">Restore Employee?</h3>
        <p class="text-slate-500 text-sm mb-8 px-4"><span id="restoreFiredName" class="font-bold text-slate-900"></span> will be reactivated and regain system access.</p>
        <div class="flex gap-3">
            <button onclick="closeRestoreFiredModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black uppercase text-[10px]">Cancel</button>
            <a id="confirmRestoreFiredBtn" href="#" class="flex-1 py-4 rounded-2xl bg-emerald-600 text-white font-black uppercase text-[10px] hover:bg-emerald-700 transition-colors">Restore Now</a>
        </div>
    </div>
</div>

<div id="approveModal" class="fixed inset-0 z-[80] flex items-center justify-center modal-inactive transition-all duration-300">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeApproveModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[40px] shadow-2xl relative z-10 overflow-hidden border border-slate-100 p-8 text-center text-sm">
        <div class="size-20 rounded-3xl bg-green-100 text-green-600 flex items-center justify-center mx-auto mb-6"><span class="material-symbols-outlined text-5xl">verified_user</span></div>
        <h3 class="text-2xl font-black text-slate-800 mb-2">Authorize User?</h3>
        <p class="text-slate-500 text-sm mb-8 px-4">Grant system access to <span id="approveStaffName" class="font-bold text-slate-900"></span>?</p>
        <div class="flex gap-3">
            <button onclick="closeApproveModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 text-slate-600 font-black uppercase text-[10px]">No, Wait</button>
            <a id="confirmApproveBtn" href="#" class="flex-1 py-4 rounded-2xl bg-primary text-white font-black uppercase text-[10px]">Yes, Activate</a>
        </div>
    </div>
</div>

<div id="manageAccessModal" class="fixed inset-0 z-[90] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeManageAccessModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[32px] shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div>
                <h3 class="text-lg font-black text-slate-800 tracking-tight">Manage Access</h3>
                <p id="accessStaffName" class="text-xs text-primary font-bold"></p>
                <p id="accessStaffRole" class="text-[10px] text-slate-400 font-medium"></p>
            </div>
            <button type="button" onclick="closeManageAccessModal()" class="size-8 flex items-center justify-center rounded-full hover:bg-red-50 text-red-500 transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-6">
            <input type="hidden" name="manage_access" value="1">
            <input type="hidden" name="access_staff_id" id="access_staff_id">

            <!-- Revoke Admin Banner (visible only when staff is currently admin) -->
            <div id="revokeAdminBanner" class="hidden bg-red-50 border border-red-200 rounded-2xl p-4">
                <div class="flex items-center gap-3 mb-3">
                    <div class="size-10 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-red-500">shield_person</span>
                    </div>
                    <div>
                        <p class="font-black text-sm text-red-700">This staff has Admin Access</p>
                        <p class="text-[10px] text-red-400">They can currently access all system features.</p>
                    </div>
                </div>
                <button type="button" onclick="revokeAdminAccess()" class="w-full py-2.5 rounded-xl bg-red-500 text-white font-black text-[10px] uppercase tracking-widest hover:bg-red-600 transition-all flex items-center justify-center gap-1.5">
                    <span class="material-symbols-outlined text-sm">remove_moderator</span> Revoke Admin Access
                </button>
            </div>

            <div class="bg-purple-50 rounded-2xl p-4 flex items-center justify-between">
                <div>
                    <p class="font-black text-sm text-purple-800">Grant Admin Access</p>
                    <p class="text-[10px] text-purple-500">Admin can access all system features.</p>
                </div>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" name="make_admin" id="make_admin_toggle" value="1" onchange="toggleAdminAccess()" class="sr-only peer">
                    <div class="w-11 h-6 bg-slate-200 peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:bg-purple-600 after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:after:translate-x-full"></div>
                </label>
            </div>

            <div class="space-y-3">
                <p class="text-[11px] font-black uppercase tracking-widest text-slate-500">Feature Access</p>
                <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition-all">
                    <input type="checkbox" name="features[]" value="financials" class="feature-checkbox rounded border-slate-300 text-primary focus:ring-primary">
                    <span class="material-symbols-outlined text-lg text-slate-400">payments</span>
                    <span class="font-bold text-sm text-slate-700">Financials</span>
                </label>
                <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition-all">
                    <input type="checkbox" name="features[]" value="reports" class="feature-checkbox rounded border-slate-300 text-primary focus:ring-primary">
                    <span class="material-symbols-outlined text-lg text-slate-400">bar_chart</span>
                    <span class="font-bold text-sm text-slate-700">Reports</span>
                </label>
                <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition-all">
                    <input type="checkbox" name="features[]" value="help_support" class="feature-checkbox rounded border-slate-300 text-primary focus:ring-primary">
                    <span class="material-symbols-outlined text-lg text-slate-400">support_agent</span>
                    <span class="font-bold text-sm text-slate-700">Help & Support</span>
                </label>
                <label class="flex items-center gap-3 p-3 rounded-xl hover:bg-slate-50 cursor-pointer transition-all">
                    <input type="checkbox" name="features[]" value="feedback" class="feature-checkbox rounded border-slate-300 text-primary focus:ring-primary">
                    <span class="material-symbols-outlined text-lg text-slate-400">feedback</span>
                    <span class="font-bold text-sm text-slate-700">Feedback</span>
                </label>
            </div>

            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeManageAccessModal()" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-primary text-white font-black text-[11px] uppercase tracking-widest hover:bg-primary-dark transition-all shadow-lg shadow-primary/20">Save Access</button>
            </div>
        </form>
    </div>
</div>

<!-- PATIENT DETAIL MODAL -->
<div id="patientDetailModal" class="fixed inset-0 z-[90] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/80 backdrop-blur-md" onclick="closePatientDetailModal()"></div>
    <div class="bg-white w-full max-w-2xl max-h-[90vh] rounded-[32px] shadow-2xl relative z-10 overflow-hidden flex flex-col border border-slate-100">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-amber-50 to-white shrink-0">
            <div class="flex items-center gap-4">
                <div class="size-14 rounded-2xl bg-amber-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-amber-600 text-2xl">child_care</span>
                </div>
                <div>
                    <h3 class="text-lg font-black text-slate-800 tracking-tight">Patient Account Details</h3>
                    <p id="patientDetailName" class="text-xs text-primary font-bold"></p>
                </div>
            </div>
            <button onclick="closePatientDetailModal()" class="size-10 flex items-center justify-center rounded-full hover:bg-red-50 text-red-500 transition-all">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-6 space-y-6">
            <!-- Patient Info -->
            <div class="grid grid-cols-2 gap-4">
                <div class="bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Full Name</p>
                    <p id="pdFullName" class="text-sm font-bold text-slate-800">-</p>
                </div>
                <div class="bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Email</p>
                    <p id="pdEmail" class="text-sm font-bold text-slate-800">-</p>
                </div>
                <div class="bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Date of Birth</p>
                    <p id="pdDob" class="text-sm font-bold text-slate-800">-</p>
                </div>
                <div class="bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Age</p>
                    <p id="pdAge" class="text-sm font-bold text-slate-800">-</p>
                </div>
                <div class="bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Phone</p>
                    <p id="pdPhone" class="text-sm font-bold text-slate-800">-</p>
                </div>
                <div class="bg-slate-50 rounded-2xl p-4">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1">Registered On</p>
                    <p id="pdCreatedAt" class="text-sm font-bold text-slate-800">-</p>
                </div>
            </div>

            <!-- Account Status -->
            <div class="bg-blue-50 rounded-2xl p-5 space-y-3">
                <h4 class="text-xs font-black uppercase tracking-widest text-blue-600 flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">verified_user</span> Account Status
                </h4>
                <div>
                    <p class="text-[9px] font-black uppercase tracking-widest text-blue-400 mb-0.5">Status</p>
                    <p id="pdStatus" class="text-sm font-bold">-</p>
                </div>
            </div>

            <!-- Reject Reason (if rejected) -->
            <div id="pdRejectReasonWrap" class="hidden bg-red-50 rounded-2xl p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-red-400 mb-1">Rejection Reason</p>
                <p id="pdRejectReason" class="text-sm font-bold text-red-700">-</p>
            </div>

            <!-- Guardian ID Image -->
            <div class="space-y-3">
                <h4 class="text-xs font-black uppercase tracking-widest text-slate-600 flex items-center gap-2">
                    <span class="material-symbols-outlined text-base">badge</span> Guardian ID (Submitted Photo)
                </h4>
                <div id="pdGuardianIdWrap" class="bg-slate-100 rounded-2xl overflow-hidden border border-slate-200 flex items-center justify-center min-h-[250px]">
                    <img id="pdGuardianIdImg" src="" alt="Guardian ID" class="max-w-full max-h-[400px] object-contain hidden cursor-pointer" onclick="window.open(this.src, '_blank')">
                    <div id="pdNoGuardianId" class="flex flex-col items-center text-center p-8">
                        <span class="material-symbols-outlined text-5xl text-slate-300 mb-3">image_not_supported</span>
                        <p class="font-bold text-slate-400 text-xs">No Guardian ID image uploaded</p>
                    </div>
                </div>
                <p class="text-[10px] text-slate-400 text-center">Click the image to view full size in a new tab.</p>
            </div>

            <!-- Action Buttons -->
            <div id="pdActionButtons" class="flex gap-3 pt-2">
                <button type="button" onclick="closePatientDetailModal(); openApprovePatientModal(window._pdPatientId, window._pdPatientName)" class="flex-1 py-3.5 rounded-2xl bg-green-500 text-white font-black text-xs uppercase tracking-widest hover:bg-green-600 transition-all shadow-lg shadow-green-500/20 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-base">check_circle</span> Approve Account
                </button>
                <button onclick="closePatientDetailModal(); openRejectModal(window._pdPatientId, window._pdPatientName)" class="flex-1 py-3.5 rounded-2xl bg-red-50 text-red-600 font-black text-xs uppercase tracking-widest hover:bg-red-600 hover:text-white transition-all border border-red-200 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-base">cancel</span> Reject Account
                </button>
            </div>
        </div>
    </div>
</div>

<!-- APPROVE PATIENT MODAL -->
<div id="approvePatientModal" class="fixed inset-0 z-[95] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeApprovePatientModal()"></div>
    <div class="bg-white w-full max-w-sm rounded-[32px] shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-6 border-b border-slate-100 bg-green-50 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-green-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-green-600">how_to_reg</span>
                </div>
                <div>
                    <h3 class="text-base font-black text-slate-800 tracking-tight">Approve Patient Record?</h3>
                    <p id="approvePatientName" class="text-xs text-green-600 font-bold"></p>
                </div>
            </div>
            <button onclick="closeApprovePatientModal()" class="size-8 flex items-center justify-center rounded-full hover:bg-green-100 text-green-600 transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <div class="p-6 space-y-5">
            <p class="text-sm text-slate-600">This patient's account will be <span class="font-bold text-green-600">approved</span> and they will appear in the <span class="font-bold">Patient Records</span> list.</p>
            <div class="flex gap-2">
                <button type="button" onclick="closeApprovePatientModal()" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <form method="POST" id="approvePatientForm" class="flex-1">
                    <input type="hidden" name="approve_patient" value="1">
                    <input type="hidden" name="patient_id" id="approvePatientId">
                    <button type="submit" id="approvePatientBtn" class="w-full py-3 rounded-xl bg-green-500 text-white font-black text-[11px] uppercase tracking-widest hover:bg-green-600 transition-all shadow-lg shadow-green-500/20 flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-sm">check_circle</span> Approve
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- REJECT PATIENT MODAL -->
<div id="rejectPatientModal" class="fixed inset-0 z-[95] flex items-center justify-center modal-inactive transition-all duration-300 p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeRejectModal()"></div>
    <div class="bg-white w-full max-w-md rounded-[32px] shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-6 border-b border-slate-100 bg-red-50 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-red-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-red-600">cancel</span>
                </div>
                <div>
                    <h3 class="text-base font-black text-slate-800 tracking-tight">Reject Patient Account</h3>
                    <p id="rejectPatientName" class="text-xs text-red-600 font-bold"></p>
                </div>
            </div>
            <button onclick="closeRejectModal()" class="size-8 flex items-center justify-center rounded-full hover:bg-red-100 text-red-500 transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        <form method="POST" class="p-6 space-y-5">
            <input type="hidden" name="reject_patient" value="1">
            <input type="hidden" name="patient_id" id="rejectPatientId">
            <div class="space-y-2">
                <label class="text-[11px] font-black uppercase tracking-widest text-slate-500">Reason for Rejection</label>
                <textarea name="reject_reason" rows="4" required class="w-full rounded-2xl border-slate-200 px-4 py-3 text-sm focus:border-red-400 focus:ring-red-400 resize-none" placeholder="e.g., Hindi malinaw ang Guardian ID, expired ID, hindi tugma ang pangalan..."></textarea>
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-3 rounded-xl bg-slate-100 text-slate-600 font-black text-[11px] uppercase tracking-widest hover:bg-slate-200 transition-all">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl bg-red-600 text-white font-black text-[11px] uppercase tracking-widest hover:bg-red-700 transition-all shadow-lg shadow-red-500/20">Reject Account</button>
            </div>
        </form>
    </div>
</div>

<script>
    function filterStaff() {
        let input = document.getElementById("staffSearch").value.toLowerCase();
        let cards = document.querySelectorAll(".staff-card");
        cards.forEach(card => {
            card.style.display = card.innerText.toLowerCase().includes(input) ? "" : "none";
        });
    }

    function openEditRoleModal(id, name, currentRole) {
        document.getElementById('edit_staff_id').value = id;
        document.getElementById('editRoleName').innerText = name;
        
        let select = document.getElementById('edit_role_select');
        for(let i = 0; i < select.options.length; i++) {
            if(select.options[i].value === currentRole) {
                select.selectedIndex = i;
                break;
            }
        }
        
        const modal = document.getElementById('editRoleModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closeEditRoleModal() {
        const modal = document.getElementById('editRoleModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }

    function openEditOwnerNameModal(firstName, middleName, lastName) {
        document.getElementById('ownerFirstName').value = firstName;
        document.getElementById('ownerMiddleName').value = middleName;
        document.getElementById('ownerLastName').value = lastName;
        const modal = document.getElementById('editOwnerNameModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closeEditOwnerNameModal() {
        const modal = document.getElementById('editOwnerNameModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }

    // Store original features so we can restore when toggling admin off
    var _originalGrantedFeatures = [];

    function openManageAccessModal(id, name, role, isAdmin, grantedFeatures) {
        document.getElementById('access_staff_id').value = id;
        document.getElementById('accessStaffName').innerText = name;
        document.getElementById('accessStaffRole').innerText = role;

        let features = [];
        try { features = JSON.parse(grantedFeatures); } catch(e) { features = []; }
        _originalGrantedFeatures = features.slice();

        const adminToggle = document.getElementById('make_admin_toggle');
        adminToggle.checked = (isAdmin == 1);

        const checkboxes = document.querySelectorAll('.feature-checkbox');
        checkboxes.forEach(cb => {
            cb.checked = (isAdmin == 1) || features.includes(cb.value);
            cb.disabled = (isAdmin == 1);
        });

        // Show/hide revoke banner
        const revokeBanner = document.getElementById('revokeAdminBanner');
        if (revokeBanner) {
            if (isAdmin == 1) { revokeBanner.classList.remove('hidden'); } 
            else { revokeBanner.classList.add('hidden'); }
        }

        const modal = document.getElementById('manageAccessModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closeManageAccessModal() {
        const modal = document.getElementById('manageAccessModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }

    function toggleAdminAccess() {
        const isAdmin = document.getElementById('make_admin_toggle').checked;
        const checkboxes = document.querySelectorAll('.feature-checkbox');
        const revokeBanner = document.getElementById('revokeAdminBanner');

        if (isAdmin) {
            // Granting admin: check all, disable all
            checkboxes.forEach(cb => { cb.checked = true; cb.disabled = true; });
            if (revokeBanner) revokeBanner.classList.add('hidden');
        } else {
            // Revoking admin: restore original granted features
            checkboxes.forEach(cb => {
                cb.checked = _originalGrantedFeatures.includes(cb.value);
                cb.disabled = false;
            });
            if (revokeBanner) revokeBanner.classList.add('hidden');
        }
    }

    function revokeAdminAccess() {
        const adminToggle = document.getElementById('make_admin_toggle');
        adminToggle.checked = false;
        toggleAdminAccess();
    }

    function openResumeModal(path, name, avatar, staffId, accountType, role, isAdmin, grantedFeatures) {
        const frame = document.getElementById('resumeFrame');
        const modal = document.getElementById('resumePreviewModal');
        const placeholder = document.getElementById('noResumePlaceholder');
        const nameLabel = document.getElementById('previewStaffName');
        const avatarImg = document.getElementById('previewStaffAvatar');
        const avatarFallback = document.getElementById('previewStaffAvatarFallback');
        nameLabel.innerText = name;
        if (avatar && avatar.trim() !== '') {
            avatarImg.src = avatar;
            avatarImg.classList.remove('hidden');
            avatarFallback.classList.add('hidden');
        } else {
            avatarImg.src = '';
            avatarImg.classList.add('hidden');
            avatarFallback.classList.remove('hidden');
        }
        if(path && path !== "") {
            frame.src = path;
            frame.classList.remove('hidden');
            placeholder.classList.add('hidden');
        } else {
            frame.src = "";
            frame.classList.add('hidden');
            placeholder.classList.remove('hidden');
        }

        // Store staff data for action buttons
        window._profileStaffId = staffId;
        window._profileAccountType = accountType;
        window._profileStaffName = name;
        window._profileStaffRole = role;
        window._profileIsAdmin = isAdmin;
        window._profileGrantedFeatures = grantedFeatures;

        // Set hidden form IDs
        const credStaffId = document.getElementById('cred_staff_id');
        const credAcctType = document.getElementById('cred_account_type');
        const picStaffId = document.getElementById('pic_staff_id');
        const picAcctType = document.getElementById('pic_account_type');
        if (credStaffId) credStaffId.value = staffId;
        if (credAcctType) credAcctType.value = accountType;
        if (picStaffId) picStaffId.value = staffId;
        if (picAcctType) picAcctType.value = accountType;

        // Show/hide action buttons (only for non-admin staff, owner only)
        const btnAccess = document.getElementById('btnManageAccess');
        const btnCred = document.getElementById('btnEditCred');
        const btnPic = document.getElementById('btnEditPic');
        const btnEditAvatar = document.getElementById('btnEditAvatar');
        const isStaffRow = (accountType !== 'admin');
        if (btnAccess) { btnAccess.classList.toggle('hidden', !isStaffRow); if(isStaffRow) btnAccess.classList.add('flex'); else btnAccess.classList.remove('flex'); }
        if (btnCred) { btnCred.classList.remove('hidden'); btnCred.classList.add('flex'); }
        if (btnPic) { btnPic.classList.remove('hidden'); btnPic.classList.add('flex'); }
        if (btnEditAvatar) { btnEditAvatar.classList.remove('hidden'); btnEditAvatar.classList.add('flex'); }

        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function manageAccessFromProfile() {
        closeResumeModal();
        if (window._profileStaffId && window._profileAccountType !== 'admin') {
            openManageAccessModal(
                window._profileStaffId,
                window._profileStaffName,
                window._profileStaffRole,
                window._profileIsAdmin,
                window._profileGrantedFeatures
            );
        }
    }
    function closeResumeModal() {
        const modal = document.getElementById('resumePreviewModal');
        document.getElementById('resumeFrame').src = "";
        document.getElementById('previewStaffAvatar').src = "";
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }
    function confirmApprove(id, name) {
        document.getElementById('approveStaffName').innerText = name;
        document.getElementById('confirmApproveBtn').href = 'staffmanagement.php?approve_id=' + id;
        const m = document.getElementById('approveModal');
        m.classList.remove('modal-inactive'); m.classList.add('modal-active');
    }
    function closeApproveModal() {
        const m = document.getElementById('approveModal');
        m.classList.remove('modal-active'); m.classList.add('modal-inactive');
    }
    function openCreateStaffModal() {
        const modal = document.getElementById('createStaffModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }
    function closeCreateStaffModal() {
        const modal = document.getElementById('createStaffModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }
    function confirmMidwifeToggle(currentVal) {
        const isAdding = currentVal === 0;
        const icon = document.getElementById('midwifeModalIcon');
        const title = document.getElementById('midwifeModalTitle');
        const desc = document.getElementById('midwifeModalDesc');
        const btn = document.getElementById('midwifeModalBtn');
        document.getElementById('midwifeValField').value = isAdding ? '1' : '0';
        if (isAdding) {
            icon.className = 'size-20 rounded-3xl bg-green-100 text-green-600 flex items-center justify-center mx-auto mb-6';
            title.textContent = 'Set yourself as Midwife?';
            desc.innerHTML = 'You will gain <span class="font-bold text-green-700">Midwife privileges</span> — including performing checkups, recording vitals, and registering newborns. Your role will display as <span class="font-bold text-green-700">Owner / Midwife</span>.';
            btn.className = 'flex-1 py-4 rounded-2xl bg-green-600 text-white font-black uppercase text-[10px] hover:bg-green-700 transition-colors';
            btn.textContent = 'Yes, Set as Midwife';
        } else {
            icon.className = 'size-20 rounded-3xl bg-amber-100 text-amber-600 flex items-center justify-center mx-auto mb-6';
            title.textContent = 'Remove Midwife Role?';
            desc.innerHTML = 'You will <span class="font-bold text-amber-700">lose Midwife privileges</span> — you will no longer be able to perform checkups, record vitals, or register newborns. Your role will revert to <span class="font-bold text-amber-700">Owner</span> only.';
            btn.className = 'flex-1 py-4 rounded-2xl bg-amber-600 text-white font-black uppercase text-[10px] hover:bg-amber-700 transition-colors';
            btn.textContent = 'Yes, Remove Midwife';
        }
        const m = document.getElementById('midwifeModal');
        m.classList.remove('modal-inactive'); m.classList.add('modal-active');
    }
    function closeMidwifeModal() {
        const m = document.getElementById('midwifeModal');
        m.classList.remove('modal-active'); m.classList.add('modal-inactive');
    }
    function confirmDelete(id, name) {
        document.getElementById('deleteStaffName').innerText = name;
        document.getElementById('deleteStaffIdField').value = id;
        document.getElementById('deleteReasonField').value = '';
        document.getElementById('deleteReasonError').classList.add('hidden');
        const m = document.getElementById('deleteModal');
        m.classList.remove('modal-inactive'); m.classList.add('modal-active');
    }
    function closeDeleteModal() {
        const m = document.getElementById('deleteModal');
        m.classList.remove('modal-active'); m.classList.add('modal-inactive');
    }
    function submitDeleteStaff() {
        const reason = document.getElementById('deleteReasonField').value.trim();
        if (!reason) {
            document.getElementById('deleteReasonError').classList.remove('hidden');
            document.getElementById('deleteReasonField').focus();
            return;
        }
        document.getElementById('deleteReasonError').classList.add('hidden');
        document.getElementById('deleteStaffForm').submit();
    }
    function confirmSuspend(id, name) {
        document.getElementById('suspendStaffName').innerText = name;
        document.getElementById('suspendStaffIdField').value = id;
        document.getElementById('suspendReasonField').value = '';
        document.getElementById('suspendReasonError').classList.add('hidden');
        const m = document.getElementById('suspendModal');
        m.classList.remove('modal-inactive'); m.classList.add('modal-active');
    }
    function closeSuspendModal() {
        const m = document.getElementById('suspendModal');
        m.classList.remove('modal-active'); m.classList.add('modal-inactive');
    }
    function submitSuspendStaff() {
        const reason = document.getElementById('suspendReasonField').value.trim();
        if (!reason) {
            document.getElementById('suspendReasonError').classList.remove('hidden');
            document.getElementById('suspendReasonField').focus();
            return;
        }
        document.getElementById('suspendReasonError').classList.add('hidden');
        document.getElementById('suspendStaffForm').submit();
    }
    function confirmUnsuspend(id, name) {
        document.getElementById('unsuspendStaffName').innerText = name;
        document.getElementById('confirmUnsuspendBtn').href = 'staffmanagement.php?unsuspend_id=' + id;
        const m = document.getElementById('unsuspendModal');
        m.classList.remove('modal-inactive'); m.classList.add('modal-active');
    }
    function closeUnsuspendModal() {
        const m = document.getElementById('unsuspendModal');
        m.classList.remove('modal-active'); m.classList.add('modal-inactive');
    }
    function confirmRestoreFired(id, name) {
        document.getElementById('restoreFiredName').innerText = name;
        document.getElementById('confirmRestoreFiredBtn').href = 'staffmanagement.php?restore_fired_id=' + id;
        const m = document.getElementById('restoreFiredModal');
        m.classList.remove('modal-inactive'); m.classList.add('modal-active');
    }
    function closeRestoreFiredModal() {
        const m = document.getElementById('restoreFiredModal');
        m.classList.remove('modal-active'); m.classList.add('modal-inactive');
    }

    setTimeout(function() {
        var flashMessage = document.getElementById('flash-message');
        if (flashMessage) {
            flashMessage.style.transition = "opacity 0.5s ease"; 
            flashMessage.style.opacity = "0";
            setTimeout(() => flashMessage.style.display = "none", 500);
        }
    }, 7000);

    function openLogoutModal() {
        const modal = document.getElementById('logoutModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeLogoutModal() {
        const modal = document.getElementById('logoutModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    function confirmLogout() {
        closeLogoutModal();
        const loading = document.getElementById('loggingOutScreen');
        loading.classList.remove('hidden');
        loading.classList.add('flex');
        setTimeout(() => {
            window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>';
        }, 1500);
    }

    // ===== PENDING PATIENTS TAB & MODALS =====
    let currentPatientFilter = 'Pending';

    function filterPatientStatus(status) {
        currentPatientFilter = status;
        const cards = document.querySelectorAll('.patient-status-card');
        let visibleCount = 0;
        cards.forEach(card => {
            if (card.dataset.status === status) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // Update empty state
        const grid = document.getElementById('pendingPatientsGrid');
        let emptyMsg = document.getElementById('patientEmptyFilterMsg');
        if (visibleCount === 0 && grid) {
            if (!emptyMsg) {
                emptyMsg = document.createElement('div');
                emptyMsg.id = 'patientEmptyFilterMsg';
                emptyMsg.className = 'col-span-full flex flex-col items-center justify-center py-16 text-center';
                grid.appendChild(emptyMsg);
            }
            const icons = { Pending: 'schedule', Approved: 'check_circle', Rejected: 'cancel' };
            const labels = { Pending: 'pending', Approved: 'approved', Rejected: 'rejected' };
            emptyMsg.innerHTML = `<span class="material-symbols-outlined text-6xl text-slate-200 mb-4">${icons[status]}</span><h3 class="text-lg font-black text-slate-400 uppercase tracking-tight">No ${labels[status]} accounts</h3>`;
            emptyMsg.style.display = '';
        } else if (emptyMsg) {
            emptyMsg.style.display = 'none';
        }

        // Update sub-tab styles
        const colors = { Pending: 'amber', Approved: 'green', Rejected: 'red' };
        document.querySelectorAll('.patient-subtab').forEach(btn => {
            const tab = btn.dataset.subtab;
            if (tab === status) {
                const c = colors[tab];
                btn.className = `patient-subtab px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border-2 border-${c}-500 bg-${c}-500 text-white shadow-md flex items-center gap-1.5`;
            } else {
                btn.className = `patient-subtab px-5 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border-2 border-slate-200 bg-white text-slate-400 hover:border-slate-300 hover:text-slate-600 flex items-center gap-1.5`;
            }
        });
    }

    function switchTab(tab) {
        document.querySelectorAll('.tab-section').forEach(s => s.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('border-primary', 'text-primary', 'bg-white');
            b.classList.add('border-transparent', 'text-slate-400');
        });
        if (tab === 'staff') {
            document.getElementById('sectionStaff').classList.remove('hidden');
            const btn = document.getElementById('tabStaff');
            btn.classList.add('border-primary', 'text-primary', 'bg-white');
            btn.classList.remove('border-transparent', 'text-slate-400');
        } else if (tab === 'fired') {
            var firedSection = document.getElementById('sectionFired');
            if (firedSection) firedSection.classList.remove('hidden');
            var firedBtn = document.getElementById('tabFired');
            if (firedBtn) {
                firedBtn.classList.add('border-primary', 'text-primary', 'bg-white');
                firedBtn.classList.remove('border-transparent', 'text-slate-400');
            }
        } else {
            document.getElementById('sectionPendingPatients').classList.remove('hidden');
            const btn = document.getElementById('tabPendingPatients');
            btn.classList.add('border-primary', 'text-primary', 'bg-white');
            btn.classList.remove('border-transparent', 'text-slate-400');
            filterPatientStatus(currentPatientFilter);
        }
    }

    // Auto-switch to tab if URL has tab param
    document.addEventListener('DOMContentLoaded', function() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('tab') === 'pending_patients') {
            switchTab('pending_patients');
        } else if (params.get('tab') === 'fired') {
            switchTab('fired');
        }
        // Apply initial filter to hide non-Pending cards
        filterPatientStatus('Pending');
    });

    function openPatientDetailModal(id, name, email, dob, age, phone, guardianImg, status, rejectReason, createdAt) {
        window._pdPatientId = id;
        window._pdPatientName = name;

        document.getElementById('patientDetailName').innerText = name;
        document.getElementById('pdFullName').innerText = name || '-';
        document.getElementById('pdEmail').innerText = email || 'N/A';
        document.getElementById('pdDob').innerText = dob || 'N/A';
        document.getElementById('pdAge').innerText = age || 'N/A';
        document.getElementById('pdPhone').innerText = phone || 'N/A';
        document.getElementById('pdCreatedAt').innerText = createdAt ? new Date(createdAt).toLocaleDateString('en-US', {year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit'}) : 'N/A';

        // Status
        const statusEl = document.getElementById('pdStatus');
        if (status === 'Approved') {
            statusEl.innerHTML = '<span class="text-green-600">Approved</span>';
        } else if (status === 'Rejected') {
            statusEl.innerHTML = '<span class="text-red-600">Rejected</span>';
        } else {
            statusEl.innerHTML = '<span class="text-amber-600">Pending Verification</span>';
        }

        // Reject reason
        const rejectWrap = document.getElementById('pdRejectReasonWrap');
        if (status === 'Rejected' && rejectReason) {
            rejectWrap.classList.remove('hidden');
            document.getElementById('pdRejectReason').innerText = rejectReason;
        } else {
            rejectWrap.classList.add('hidden');
        }

        // Guardian ID Image
        const img = document.getElementById('pdGuardianIdImg');
        const noImg = document.getElementById('pdNoGuardianId');
        if (guardianImg && guardianImg.trim() !== '') {
            img.src = guardianImg;
            img.classList.remove('hidden');
            noImg.classList.add('hidden');
        } else {
            img.src = '';
            img.classList.add('hidden');
            noImg.classList.remove('hidden');
        }

        // Action buttons visibility
        const actionBtns = document.getElementById('pdActionButtons');
        if (status === 'Pending') {
            actionBtns.classList.remove('hidden');
        } else {
            actionBtns.classList.add('hidden');
        }

        const modal = document.getElementById('patientDetailModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closePatientDetailModal() {
        const modal = document.getElementById('patientDetailModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
        document.getElementById('pdGuardianIdImg').src = '';
    }

    function openRejectModal(id, name) {
        document.getElementById('rejectPatientId').value = id;
        document.getElementById('rejectPatientName').innerText = name;
        const modal = document.getElementById('rejectPatientModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closeRejectModal() {
        const modal = document.getElementById('rejectPatientModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }

    function openApprovePatientModal(id, name) {
        document.getElementById('approvePatientId').value = id;
        document.getElementById('approvePatientName').innerText = name;
        const modal = document.getElementById('approvePatientModal');
        modal.classList.remove('modal-inactive');
        modal.classList.add('modal-active');
    }

    function closeApprovePatientModal() {
        const modal = document.getElementById('approvePatientModal');
        modal.classList.remove('modal-active');
        modal.classList.add('modal-inactive');
    }

    // Handle approve form submit with loading state
    document.getElementById('approvePatientForm').addEventListener('submit', function() {
        const btn = document.getElementById('approvePatientBtn');
        btn.disabled = true;
        btn.classList.add('opacity-60', 'cursor-not-allowed');
        btn.innerHTML = '<svg class="animate-spin h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path></svg> <span class="text-[10px]">APPROVING...</span>';
    });
</script>

<script>
function togglePw(inputId, iconId) {
    var inp = document.getElementById(inputId);
    var ico = document.getElementById(iconId);
    if (!inp || !ico) return;
    if (inp.type === 'password') {
        inp.type = 'text';
        ico.textContent = 'visibility';
    } else {
        inp.type = 'password';
        ico.textContent = 'visibility_off';
    }
}
</script>

</body>
</html>