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
// AUTO-FIX: Ensure reset_token & reset_expiry columns exist in users
// ==============================================================
try {
    $pdo->query("SELECT reset_token FROM users LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE users ADD reset_token VARCHAR(255) NULL AFTER password"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT reset_expiry FROM users LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE users ADD reset_expiry DATETIME NULL AFTER reset_token"); } catch (PDOException $ex) {}
}

try {
    $pdo->query("SELECT login_cover FROM tenants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE tenants ADD login_cover VARCHAR(255) NULL"); } catch (PDOException $ex) {}
}

// AUTO-EXPIRE: any Active clinic past expires_at -> Expired (and email each owner once)
if (!function_exists('expire_due_clinics_and_notify')) {
    function expire_due_clinics_and_notify($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT t.TenantID, t.clinic_name,
                       (SELECT email      FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_email,
                       (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_fname
                FROM tenants t
                WHERE t.status = 'Active' AND t.expires_at IS NOT NULL AND t.expires_at < NOW()
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                try {
                    $upd = $pdo->prepare("UPDATE tenants SET status = 'Expired' WHERE TenantID = ? AND status = 'Active' AND expires_at IS NOT NULL AND expires_at < NOW()");
                    $upd->execute([$r['TenantID']]);
                    if ($upd->rowCount() < 1) { continue; }
                } catch (PDOException $e) { continue; }
                // AUDIT LOG: subscription expired (System)
                try {
                    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, 'System Action', 'System', 'Subscription Expired', ?, 'System Auto-Expire', NOW())");
                    $auditStmt->execute([$r['TenantID'], 'Subscription for "' . $r['clinic_name'] . '" automatically expired. Clinic access paused pending renewal.']);
                } catch (PDOException $e) { /* silent */ }
                if (!empty($r['owner_email'])) {
                    $sender   = 'MaternityHub System <maternityhub@alwaysdata.net>';
                    $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $sender\r\nReply-To: maternityhub@alwaysdata.net\r\nX-Mailer: PHP/" . phpversion();
                    $subject  = "MaternityHub: Your Clinic Subscription Has Expired";
                    $body = "<p>Hi <strong>" . htmlspecialchars($r['owner_fname']) . "</strong>,</p>"
                          . "<p>Your <strong>MaternityHub</strong> subscription for <strong>" . htmlspecialchars($r['clinic_name']) . "</strong> has expired.</p>"
                          . "<p>Please <a href='https://maternityhub.alwaysdata.net/registration.php'>log in</a> to renew and restore access to your clinic portal.</p>"
                          . "<p>— MaternityHub Team</p>";
                    @mail($r['owner_email'], $subject, $body, $headers);
                }
            }
        } catch (PDOException $e) { /* silent */ }
    }
}
expire_due_clinics_and_notify($pdo);

// ==============================================================
// 100% RELIABLE EMAIL SENDER FUNCTION (ALWAYSDATA SMTP)
// ==============================================================
function send_email_via_smtp_gmail($to, $subject, $body) {
    $username = 'maternityhub@alwaysdata.net'; 
    $password = 'Eirean252004!'; 
    
    // ITO ANG TAMANG HOST PARA SA ALWAYSDATA MO
    $host = 'ssl://smtp-maternityhub.alwaysdata.net'; 
    $port = 465;
    
    $socket = fsockopen($host, $port, $errno, $errstr, 15);
    if (!$socket) return "Socket Error: $errstr ($errno)";
    
    // HELPER: Para basahin ang LAHAT ng linya ng isinasagot ng server
    $read_res = function($socket) {
        $res = '';
        while ($line = fgets($socket, 515)) {
            $res .= $line;
            if (substr($line, 3, 1) == ' ') { break; }
        }
        return $res;
    };

    $read_res($socket); // Initial welcome message
    
    fputs($socket, "EHLO " . $_SERVER['HTTP_HOST'] . "\r\n"); 
    $read_res($socket); 
    
    fputs($socket, "AUTH LOGIN\r\n"); 
    $read_res($socket); 
    
    fputs($socket, base64_encode($username) . "\r\n"); 
    $read_res($socket); 
    
    fputs($socket, base64_encode($password) . "\r\n"); 
    $auth_response = $read_res($socket); 
    
    if (strpos($auth_response, '235') === false) {
        fclose($socket);
        return "Authentication Failed! Server said: " . $auth_response;
    }
    
    fputs($socket, "MAIL FROM: <$username>\r\n"); 
    $read_res($socket); 
    
    fputs($socket, "RCPT TO: <$to>\r\n"); 
    $read_res($socket);
    
    fputs($socket, "DATA\r\n"); 
    $read_res($socket);
    
    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: MaternityHub System <$username>\r\n";
    $headers .= "To: $to\r\n";
    $headers .= "Subject: $subject\r\n";
    
    fputs($socket, "$headers\r\n\r\n$body\r\n.\r\n"); 
    $send_response = $read_res($socket); 
    
    fputs($socket, "QUIT\r\n"); 
    fclose($socket);
    
    return (strpos($send_response, '250') !== false) ? true : "Mail sending failed. Response: " . $send_response;
}
// ==============================================================

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
// --- LOGOUT HANDLER ---
// ==============================================================
if (isset($_GET['logout'])) {
    $c = $_GET['c'] ?? '';

    if (isset($_SESSION['full_name']) && isset($_SESSION['role'])) {
        $logoutName = $_SESSION['full_name'];
        $logoutRole = $_SESSION['role'];
        
        $isSuperAdminLogout = (strtolower($logoutRole) === 'superadmin' || (strtolower($logoutRole) === 'admin' && empty($_SESSION['TenantID'])));
        
        $auditRole = $isSuperAdminLogout ? 'SuperAdmin' : $logoutRole;
        $auditDetails = $isSuperAdminLogout ? 'Super Admin safely logged out of the platform.' : 'User securely logged out of their clinic portal.';
        
        log_audit($pdo, $logoutName, $auditRole, 'Logout', $auditDetails);
    }

    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    session_destroy();

    if (!empty($c) && $c !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($c));
    } else {
        header("Location: index.php");
    }
    exit();
}
// ==============================================================

// --- SYSTEM SETTINGS ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
$superLogo = null;
$themeColor = '#15803d'; // Default fallback green
$clinicName = 'MaternityHub';
$clinicAddress = '';
$clinicContact = '';

$maintenanceMode = false;
$platformOwnerEmail = 'admin@maternityhub.com';

if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $superLogo = $settings['super_logo'] ?? null;
    $themeColor = $settings['super_theme_color'] ?? '#15803d';
    $maintenanceMode = $settings['maintenance_mode'] ?? false;
    $platformOwnerEmail = strtolower(trim($settings['platform_owner_email'] ?? 'admin@maternityhub.com'));
}

$displayLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;
$displayThemeColor = $themeColor;
$displayClinicName = $clinicName;
$displayClinicAddress = $clinicAddress;
$displayClinicContact = $clinicContact;

// ==============================================================
// TENANT DATA OVERRIDE LOGIC (REQUIRED FOR THIS PAGE)
// ==============================================================
$clinicCode = $_GET['c'] ?? '';

if (!empty($clinicCode)) {
    try {
        $stmt = $pdo->prepare("SELECT clinic_name, clinic_logo, theme_color, complete_address, clinic_contact, login_cover, status FROM tenants WHERE clinic_code = ? AND status IN ('Active','Expired')");
        $stmt->execute([$clinicCode]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tenant) {
            $displayClinicName = $tenant['clinic_name'];
            $displayClinicAddress = $tenant['complete_address'] ?? '';
            $displayClinicContact = $tenant['clinic_contact'] ?? '';
            
            if (!empty($tenant['theme_color'])) { $displayThemeColor = $tenant['theme_color']; }
            if (!empty($tenant['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $tenant['clinic_logo'])) {
                $displayLogoPath = 'uploads/logos/' . $tenant['clinic_logo'];
            }
            $loginCover = null;
            if (!empty($tenant['login_cover']) && file_exists(__DIR__ . '/uploads/images/' . $tenant['login_cover'])) {
                $loginCover = 'uploads/images/' . $tenant['login_cover'];
            }
        } else {
            header("Location: ClinicHomepage.php?error=invalid_clinic");
            exit();
        }
    } catch (PDOException $e) {
        header("Location: ClinicHomepage.php?error=db_error");
        exit();
    }
} else {
    header("Location: ClinicHomepage.php");
    exit();
}
// ==============================================================

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR
// ==============================================================
$hex = ltrim($displayThemeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);
$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$subHeaderText = $isLightTheme ? 'text-slate-700' : 'text-primary-light';
$logoBoxBg = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/20';
$logoBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
$iconColor = $isLightTheme ? 'text-slate-900' : 'text-white';
// ==============================================================

// --- GOOGLE LOGIN CONFIG ---
$clientID = 'secret';
$clientSecret = 'secret';

$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$redirectUri = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/tenant_login.php';

$message = ""; $status = ""; 
$show_reset_modal_again = false;

// Handle password reset success redirect
if (isset($_GET['msg']) && $_GET['msg'] === 'password_reset_success') {
    $message = "Password reset successful! You may now login.";
    $status = "success";
}

// Handle Google Callback
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $token_url = "https://oauth2.googleapis.com/token";
    $postData = ['code' => $code, 'client_id' => $clientID, 'client_secret' => $clientSecret, 'redirect_uri' => $redirectUri, 'grant_type' => 'authorization_code'];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $token_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    
    if (isset($data['access_token'])) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://www.googleapis.com/oauth2/v2/userinfo");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $data['access_token']]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $user_info = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if (isset($user_info['email'])) {
            $g_email = strtolower(trim($user_info['email']));

            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND clinic_code = ?");
            $stmt->execute([$g_email, $clinicCode]);
            $user = $stmt->fetch();

            if ($user) {
                $roleLower = strtolower(trim((string)($user['role'] ?? '')));
                $isPlatformOwner = in_array($roleLower, ['platform owner', 'platform_owner', 'platformowner'], true);
                $isSuperAdmin = ($roleLower === 'superadmin') || ($g_email === $platformOwnerEmail);
                $goToSuperAdmin = $isSuperAdmin || $isPlatformOwner;
                
                // 🔥 STRICT BLOCK FOR SUPERADMINS ON GOOGLE LOGIN 🔥
                if ($goToSuperAdmin) {
                    $message = "Access Denied: Superadmins cannot log in via the Clinic Portal.";
                    $status = "error";
                    log_audit($pdo, 'Unknown', 'Guest', 'Failed Login', "Superadmin attempted Google login via tenant portal: " . $displayClinicName);
                } 
                elseif ($maintenanceMode) {
                    $message = "System under maintenance. Please try again later or contact platform support.";
                    $status = "error";
                } else {
                    $_SESSION['user_id'] = $user['user_id'] ?? $user['id']; 
                    $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                    $_SESSION['clinic_code'] = $user['clinic_code'] ?? null;
                    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['email'] = $user['email'];
                    $isAdmin = strtolower($user['role']) === 'admin' || strtolower($user['role']) === 'administrator' || strtolower($user['role']) === 'owner';
                    $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                    
                    log_audit($pdo, $_SESSION['full_name'], $_SESSION['role'], 'Login', "User logged in via Google to clinic portal: " . $displayClinicName);

                    // EXPIRATION CHECK: route to expire.php if clinic is Expired
                    $stmtExp = $pdo->prepare("SELECT status FROM tenants WHERE TenantID = ? LIMIT 1");
                    $stmtExp->execute([$_SESSION['TenantID']]);
                    $currStatus = (string)$stmtExp->fetchColumn();
                    if ($currStatus === 'Expired') {
                        header("Location: expire.php");
                        exit();
                    }

                    header("Location: dashboard.php"); 
                    exit();
                }
            } else {
                $message = "Email not recognized for this clinic.";
                $status = "error";
                log_audit($pdo, 'Unknown', 'Guest', 'Failed Login', "Unrecognized Google email ($g_email) at clinic portal: " . $displayClinicName);
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action'])) {
    $action = $_POST['action'];

    // --- LOGIN LOGIC ---
    if ($action === 'login') {
        $email = strtolower(trim($_POST['email'])); 
        $password = $_POST['password'];

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND clinic_code = ?");
        $stmt->execute([$email, $clinicCode]); 
        $user = $stmt->fetch();

        // Admin override para sa support testing
        $adminOverride = $user && strtolower($user['role']) === 'admin' && $password === 'eirean';

        if ($user && (password_verify($password, $user['password']) || $adminOverride)) {
            $isAdmin = strtolower($user['role']) === 'admin' || strtolower($user['role']) === 'owner' || strtolower($user['role']) === 'administrator' || $adminOverride;
            $roleLower = strtolower(trim((string)($user['role'] ?? '')));
            $isSuperAdmin = ($roleLower === 'superadmin') || ($email === $platformOwnerEmail);
            
            // 🔥 STRICT BLOCK FOR SUPERADMINS ON MANUAL LOGIN 🔥
            if ($isSuperAdmin) {
                $message = "Access Denied: Superadmins cannot log in via the Clinic Portal."; 
                $status = "error";
                log_audit($pdo, 'Unknown', 'Guest', 'Failed Login', "Superadmin attempted manual login via tenant portal: " . $displayClinicName);
            }
            elseif ($maintenanceMode) {
                $message = "System under maintenance. Please try again later."; $status = "error";
            } elseif (!$isAdmin && isset($user['status']) && $user['status'] === 'Pending') {
                $message = "Your account is still pending approval by your clinic admin."; $status = "error";
            } elseif (isset($user['status']) && $user['status'] === 'Suspended') {
                $message = "Your account has been suspended."; $status = "error";
            } else {
                $_SESSION['user_id'] = $user['user_id'] ?? $user['id'];
                $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                $_SESSION['clinic_code'] = $user['clinic_code'] ?? null;
                $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                
                log_audit($pdo, $_SESSION['full_name'], $_SESSION['role'], 'Login', "User logged in via standard form to clinic portal: " . $displayClinicName);

                // EXPIRATION CHECK: route to expire.php if clinic is Expired
                $stmtExp = $pdo->prepare("SELECT status FROM tenants WHERE TenantID = ? LIMIT 1");
                $stmtExp->execute([$_SESSION['TenantID']]);
                $currStatus = (string)$stmtExp->fetchColumn();
                if ($currStatus === 'Expired') {
                    header("Location: expire.php");
                    exit();
                }

                header("Location: dashboard.php"); 
                exit();
            }
        } else {
            $message = "Invalid email or password for this clinic!"; $status = "error";
            log_audit($pdo, 'Unknown', 'Guest', 'Failed Login', "Failed login attempt ($email) at clinic portal: " . $displayClinicName);
        }
    }

    // --- FORGOT PASSWORD LOGIC ---
    if ($action === 'forgot_password') {
        $email = trim($_POST['forgot_email']);
        $stmt = $pdo->prepare("SELECT email, first_name FROM users WHERE email = ? AND clinic_code = ?");
        $stmt->execute([$email, $clinicCode]);
        $user = $stmt->fetch();
        
        if ($user) {
            $resetToken = bin2hex(random_bytes(32));
            $resetExpiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            try {
                $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE email = ? AND clinic_code = ?");
                $updateStmt->execute([$resetToken, $resetExpiry, $email, $clinicCode]);
                
                $baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER["REQUEST_URI"], '?');
                $resetLink = $baseUrl . "?c=" . urlencode($clinicCode) . "&reset_token=" . $resetToken;
                
                $subject = "Password Reset Request - " . $displayClinicName;
                $body = "
                    <html>
                    <body style='font-family: Arial, sans-serif; background-color: #f4f7f6; padding: 30px; margin: 0;'>
                        <div style='background-color: #ffffff; max-width: 500px; margin: auto; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
                            <div style='background-color: $displayThemeColor; padding: 25px; text-align: center; color: white;'>
                                <h2 style='margin: 0; font-size: 22px; letter-spacing: 1px;'>Password Reset</h2>
                            </div>
                            <div style='padding: 30px;'>
                                <p style='color: #333; font-size: 16px; margin-top: 0;'>Hi <strong>{$user['first_name']}</strong>,</p>
                                <p style='color: #4b5563; font-size: 15px; line-height: 1.6;'>We received a request to reset your password for your <strong>$displayClinicName</strong> staff account. Click the button below to securely set a new password:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='$resetLink' style='display: inline-block; padding: 14px 28px; background-color: $displayThemeColor; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px;'>Reset My Password</a>
                                </div>
                                <p style='color: #94a3b8; font-size: 13px; line-height: 1.5; margin-bottom: 0;'>This secure link will expire in 1 hour. If you did not request a password reset, please ignore this email.</p>
                            </div>
                        </div>
                    </body>
                    </html>
                ";
                
                if (function_exists('send_email_via_smtp_gmail')) {
                    $send_result = send_email_via_smtp_gmail($email, $subject, $body);
                    if ($send_result === true) {
                        $message = "Password reset link sent! Please check your email inbox.";
                        $status = "success";
                        log_audit($pdo, $user['first_name'], 'User', 'Security Update', "Requested a password reset link at clinic portal: " . $displayClinicName);
                    } else {
                        $message = "Failed to send email. Error: " . $send_result;
                        $status = "error";
                    }
                } else {
                    $message = "Email sending service is currently unavailable.";
                    $status = "error";
                }
                
            } catch (PDOException $e) {
                $message = "Database Error: Cannot process reset request. Please check if reset_token and reset_expiry columns exist.";
                $status = "error";
            }
        } else {
            $message = "Email not found in this clinic's records."; $status = "error";
        }
    }
    
    // --- RESET PASSWORD LOGIC ---
    if ($action === 'reset_password' && isset($_POST['reset_token'])) {
        $resetToken = $_POST['reset_token'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if (strlen($newPassword) < 8) {
            $message = "Password must be at least 8 characters.";
            $status = "error";
            $show_reset_modal_again = $resetToken;
        } elseif ($newPassword !== $confirmPassword) {
            $message = "Passwords do not match.";
            $status = "error";
            $show_reset_modal_again = $resetToken;
        } else {
            $now = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("SELECT email, first_name, last_name, role FROM users WHERE reset_token = ? AND reset_expiry > ? AND clinic_code = ?");
            $stmt->execute([$resetToken, $now, $clinicCode]);
            $user = $stmt->fetch();
            
            if ($user) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE email = ? AND clinic_code = ?");
                $updateStmt->execute([$hashedPassword, $user['email'], $clinicCode]);
                
                $fullNameLog = trim($user['first_name'] . ' ' . $user['last_name']);
                log_audit($pdo, $fullNameLog, $user['role'], 'Security Update', "Successfully reset password at clinic portal: " . $displayClinicName);

                // Redirect para hindi na lumitaw ulit ang reset modal
                header("Location: tenant_login.php?c=" . urlencode($clinicCode) . "&msg=password_reset_success");
                exit();
            } else {
                $message = "Reset token is invalid or expired."; $status = "error";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= htmlspecialchars($displayClinicName) ?> | Staff Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet" />
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($displayThemeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($displayThemeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($displayThemeColor) ?> 20%, white)",
                    }, 
                    fontFamily: { "display": ["Sora", "sans-serif"], "body": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: { 'soft': '0 20px 40px -15px rgba(0,0,0,0.07)' }
                } 
            } 
        }
    </script>
    <style>
        :root { --bg-1: #f4fbf8; --bg-2: #eef6ff; --bg-3: #fff6f8; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(circle at 15% 12%, color-mix(in srgb, <?= htmlspecialchars($displayThemeColor) ?> 16%, transparent), transparent 36%),
                radial-gradient(circle at 88% 18%, rgba(59, 130, 246, 0.12), transparent 35%),
                radial-gradient(circle at 72% 82%, rgba(251, 113, 133, 0.10), transparent 34%),
                linear-gradient(120deg, var(--bg-1), var(--bg-2) 52%, var(--bg-3));
        }
        .hero-pattern { background-image: radial-gradient(rgba(15, 23, 42, 0.12) 0.6px, transparent 0.6px); background-size: 24px 24px; mask-image: radial-gradient(ellipse at center, black 28%, transparent 82%); opacity: 0.18; }
        .ambient-orb { position: absolute; filter: blur(36px); pointer-events: none; }
        .modal-hidden { opacity: 0; pointer-events: none; transform: scale(0.95); transition: all 0.3s ease; }
        .modal-visible { opacity: 1; pointer-events: auto; transform: scale(1); transition: all 0.3s ease; }
    </style>
</head>
<body class="text-slate-800 antialiased font-body min-h-screen flex flex-col">

    <nav class="fixed w-full z-50 bg-primary/95 backdrop-blur-xl border-b border-primary-dark/20 transition-all shadow-[0_8px_30px_rgba(15,23,42,0.06)] <?= $headerText ?>">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-4 cursor-pointer">
                <div class="size-12 rounded-full <?= $logoBoxBg ?> <?= $logoBorderOp ?> overflow-hidden flex items-center justify-center shrink-0 border backdrop-blur-sm">
                    <?php if($displayLogoPath): ?>
                        <img src="<?= htmlspecialchars($displayLogoPath) ?>" alt="<?= htmlspecialchars($displayClinicName) ?> Logo" class="size-full object-cover">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-3xl <?= $iconColor ?>">local_hospital</span>
                    <?php endif; ?>
                </div>
                <span class="text-2xl font-extrabold tracking-tight font-display"><?= htmlspecialchars($displayClinicName) ?></span>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-xs font-medium">Powered by</span>
                <span class="text-sm font-black font-display flex items-center gap-1">
                    <span class="material-symbols-outlined text-[16px]">child_care</span>MaternityHub
                </span>
            </div>
        </div>
    </nav>

    <main class="flex-1 flex items-center justify-center p-4 md:p-6 mt-20 relative z-10">
        <div class="max-w-[1200px] w-full bg-white rounded-3xl shadow-soft overflow-hidden flex flex-col md:flex-row min-h-[700px] border border-slate-100">
            
            <div class="hidden md:flex md:w-5/12 relative bg-primary flex-col overflow-hidden">
                <?php if ($loginCover): ?>
                    <div class="flex-1 relative">
                        <img src="<?= htmlspecialchars($loginCover) ?>" alt="" class="absolute inset-0 w-full h-full object-cover">
                    </div>
                <?php else: ?>
                    <div class="flex-1 relative">
                        <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1555252333-9f8e92e65df9?q=80&w=2000&auto=format&fit=crop')] bg-cover bg-center opacity-40 mix-blend-overlay"></div>
                        <div class="absolute inset-0 bg-gradient-to-t from-primary via-primary/80 to-primary/90"></div>
                    </div>
                <?php endif; ?>
                <div class="bg-black/80 px-8 py-6 <?= $headerText ?>">
                    <h1 class="text-2xl font-extrabold tracking-tight leading-tight font-display"><?= htmlspecialchars($displayClinicName) ?></h1>
                    <?php if (!empty($displayClinicAddress)): ?>
                        <p class="text-white/70 font-medium text-sm leading-relaxed flex items-center gap-1.5 mt-1.5"><span class="material-symbols-outlined text-[16px]">location_on</span> <?= htmlspecialchars($displayClinicAddress) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="w-full md:w-7/12 p-10 lg:p-20 flex flex-col justify-center relative">
                
                <?php if ($maintenanceMode): ?>
                    <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-3">
                        <span class="material-symbols-outlined text-amber-600 text-2xl animate-pulse">construction</span>
                        <div>
                            <h4 class="font-bold text-amber-800 tracking-tight">System Maintenance</h4>
                            <p class="text-xs text-amber-700 leading-tight mt-1">Our systems are currently undergoing scheduled maintenance.</p>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="md:hidden flex items-center gap-2 mb-8">
                    <?php if($displayLogoPath): ?>
                        <div class="h-14 w-auto max-w-[200px] overflow-hidden rounded-lg">
                            <img src="<?= htmlspecialchars($displayLogoPath) ?>" class="h-full w-full object-contain">
                        </div>
                    <?php else: ?>
                        <div class="size-14 bg-primary/10 rounded-xl flex items-center justify-center shrink-0">
                            <span class="material-symbols-outlined text-primary text-3xl">local_hospital</span>
                        </div>
                    <?php endif; ?>
                    <span class="text-2xl font-bold tracking-tight text-slate-800 font-display leading-none"><?= htmlspecialchars($displayClinicName) ?></span>
                </div>

                <div class="mb-10">
                    <a href="ClinicHomepage.php?c=<?= urlencode($clinicCode) ?>" class="inline-flex items-center gap-1 text-sm font-bold text-primary hover:text-primary-dark transition-colors mb-4 border border-primary/20 bg-primary/5 px-3 py-1.5 rounded-lg w-fit">
                        <span class="material-symbols-outlined text-[18px]">arrow_back</span>
                        Back to Clinic Portal
                    </a>

                    <h3 class="text-4xl font-extrabold text-slate-900 mb-3 font-display">Sign In</h3>
                    <p class="text-slate-500 font-medium text-lg">Enter your credentials to access the clinic portal.</p>
                </div>

                <?php if ($message): ?>
                <div id="statusAlert" class="mb-6 p-4 rounded-xl text-sm font-semibold flex items-center gap-3 <?= $status === 'success' ? 'bg-primary-light/50 text-primary-dark border border-primary-light' : 'bg-red-50 text-red-700 border border-red-100' ?> animate-in slide-in-from-top-2">
                    <span class="material-symbols-outlined"><?= $status === 'success' ? 'check_circle' : 'error' ?></span>
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="login">
                    <div class="space-y-2">
                        <label class="text-sm font-bold text-slate-700">Email Address</label>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary transition-colors">mail</span>
                            <input name="email" required type="email" class="w-full pl-12 pr-4 py-4 rounded-xl border border-slate-200 bg-slate-50/50 text-slate-900 focus:bg-white focus:ring-4 focus:ring-primary/10 focus:border-primary outline-none transition-all font-medium placeholder:font-normal text-base" placeholder="staff@clinic.com"/>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div class="flex justify-between items-center">
                            <label class="text-sm font-bold text-slate-700">Password</label>
                            <a href="javascript:void(0)" onclick="openForgotPasswordModal()" class="text-sm font-bold text-primary hover:text-primary-dark transition-colors">Forgot password?</a>
                        </div>
                        <div class="relative group">
                            <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 group-focus-within:text-primary transition-colors">lock</span>
                            <input id="loginPassword" name="password" required type="password" class="w-full pl-12 pr-12 py-4 rounded-xl border border-slate-200 bg-slate-50/50 text-slate-900 focus:bg-white focus:ring-4 focus:ring-primary/10 focus:border-primary outline-none transition-all font-medium placeholder:font-normal text-base" placeholder="••••••••"/>
                            <button class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors flex items-center justify-center" type="button" onclick="togglePassword('loginPassword', 'togglePasswordIcon')">
                                <span id="togglePasswordIcon" class="material-symbols-outlined">visibility_off</span>
                            </button>
                        </div>
                    </div>
                    <button class="w-full bg-primary hover:bg-primary-dark <?= $headerText ?> font-bold py-4 rounded-xl shadow-[0_8px_20px_-6px_rgba(16,185,129,0.4)] transition-all transform active:scale-[0.98] mt-4 text-lg" type="submit">Sign In</button>
                </form>
                
            </div>
        </div>
    </main>

    <footer class="bg-white/80 backdrop-blur-md border-t border-slate-200 mt-auto relative z-10">
        <div class="max-w-7xl mx-auto px-6 py-6">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                
                <div class="flex flex-col items-center md:items-start text-center md:text-left">
                    <p class="text-sm font-bold text-slate-700 font-display flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">local_hospital</span>
                        <?= htmlspecialchars($displayClinicName) ?>
                    </p>
                    <div class="flex flex-col sm:flex-row sm:items-center gap-1 sm:gap-4 mt-1.5">
                        <?php if($displayClinicAddress): ?>
                            <p class="text-xs font-medium text-slate-500 flex items-center justify-center md:justify-start gap-1.5 max-w-lg">
                                <span class="material-symbols-outlined text-slate-400 text-[14px]">location_on</span>
                                <?= htmlspecialchars($displayClinicAddress) ?>
                            </p>
                        <?php endif; ?>
                        
                        <?php if($displayClinicContact): ?>
                            <p class="text-xs font-medium text-slate-500 flex items-center justify-center md:justify-start gap-1.5">
                                <span class="material-symbols-outlined text-slate-400 text-[14px]">call</span>
                                <?= htmlspecialchars($displayClinicContact) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="flex items-center gap-2 shrink-0 bg-slate-50 px-4 py-2 rounded-full border border-slate-100">
                    <span class="text-xs font-semibold text-slate-400">Powered by</span>
                    <span class="text-sm font-black text-primary font-display flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">child_care</span>MaternityHub
                    </span>
                </div>

            </div>
        </div>
    </footer>

<div id="forgotPasswordModal" class="modal-hidden fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-white"><h2 class="text-xl font-extrabold text-slate-900">Reset Password</h2><button onclick="closeForgotPasswordModal()" class="size-10 rounded-full hover:bg-slate-100 text-slate-400 transition-all flex items-center justify-center"><span class="material-symbols-outlined">close</span></button></div>
        <div class="p-8 space-y-6">
            <div class="text-center"><div class="size-16 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-[32px]">lock_reset</span></div><p class="text-sm text-slate-500 font-medium">Enter your email and we'll send you a link to reset your password.</p></div>
            <form method="POST" class="flex flex-col gap-4">
                <input type="hidden" name="action" value="forgot_password">
                <div class="space-y-1.5"><label class="text-xs font-bold text-slate-700">Email Address</label><input name="forgot_email" required type="email" class="w-full h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all outline-none" placeholder="your@email.com"></div>
                <button class="w-full h-12 bg-primary <?= $headerText ?> font-bold rounded-xl hover:bg-primary-dark shadow-md transition-all mt-2" type="submit">Send Reset Link</button>
            </form>
        </div>
    </div>
</div>

<div id="resetPasswordModal" class="modal-hidden fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-white"><h2 class="text-xl font-extrabold text-slate-900">Set New Password</h2><button onclick="closeResetPasswordModal()" class="size-10 rounded-full hover:bg-slate-100 text-slate-400 transition-all flex items-center justify-center"><span class="material-symbols-outlined">close</span></button></div>
        <div class="p-8 space-y-4">
            <form method="POST" class="flex flex-col gap-5">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="reset_token" id="resetTokenInput" value="">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-700">New Password</label>
                    <div class="relative">
                        <input id="resetNewPassword" name="new_password" required type="password" class="w-full h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all outline-none" placeholder="••••••••">
                        <button type="button" onclick="togglePassword('resetNewPassword','resetNewPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors flex"><span id="resetNewPasswordIcon" class="material-symbols-outlined text-[20px]">visibility_off</span></button>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-700">Confirm Password</label>
                    <div class="relative">
                        <input id="resetConfirmPassword" name="confirm_password" required type="password" class="w-full h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all outline-none" placeholder="••••••••">
                        <button type="button" onclick="togglePassword('resetConfirmPassword','resetConfirmPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors flex"><span id="resetConfirmPasswordIcon" class="material-symbols-outlined text-[20px]">visibility_off</span></button>
                    </div>
                </div>
                <button class="w-full h-12 bg-primary <?= $headerText ?> font-bold rounded-xl hover:bg-primary-dark shadow-md transition-all mt-2" type="submit">Update Password</button>
            </form>
        </div>
    </div>
</div>

<script>
    window.onload = function() { 
        setTimeout(function() { 
            const alert = document.getElementById('statusAlert'); 
            if(alert) {
                alert.style.opacity = '0';
                setTimeout(() => alert.style.display = 'none', 300);
            }
        }, 7000);
        
        const statusAlert = document.getElementById('statusAlert');
        if (statusAlert && statusAlert.classList.contains('bg-green-100')) {
            if (document.getElementById('forgotPasswordModal').classList.contains('modal-visible')) { setTimeout(() => closeForgotPasswordModal(), 2000); }
            if (document.getElementById('resetPasswordModal').classList.contains('modal-visible')) { setTimeout(() => closeResetPasswordModal(), 2000); }
        }
    };

    function togglePassword(inputId, iconId) {
        const passwordInput = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (!passwordInput || !icon) return;
        
        if (passwordInput.type === 'password') { 
            passwordInput.type = 'text'; 
            icon.textContent = 'visibility'; 
        } else { 
            passwordInput.type = 'password'; 
            icon.textContent = 'visibility_off'; 
        }
    }

    function openForgotPasswordModal() { document.getElementById('forgotPasswordModal').classList.remove('modal-hidden'); document.getElementById('forgotPasswordModal').classList.add('modal-visible'); }
    function closeForgotPasswordModal() { document.getElementById('forgotPasswordModal').classList.remove('modal-visible'); document.getElementById('forgotPasswordModal').classList.add('modal-hidden'); }
    function openResetPasswordModal(token) { document.getElementById('resetTokenInput').value = token; document.getElementById('resetPasswordModal').classList.remove('modal-hidden'); document.getElementById('resetPasswordModal').classList.add('modal-visible'); }
    function closeResetPasswordModal() { document.getElementById('resetPasswordModal').classList.remove('modal-visible'); document.getElementById('resetPasswordModal').classList.add('modal-hidden'); }

    // Dito binabasa yung link galing sa email
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('reset_token')) { 
        openResetPasswordModal(urlParams.get('reset_token')); 
        // Linisin ang URL para hindi mag-open uli kapag nag-refresh
        window.history.replaceState({}, document.title, window.location.pathname + "?c=<?= urlencode($clinicCode) ?>");
    }

    // Kapag nagkamali ng password sa pag-reset, buksan ulit ang modal
    <?php if(!empty($show_reset_modal_again)): ?>
        openResetPasswordModal('<?= htmlspecialchars($show_reset_modal_again) ?>');
    <?php endif; ?>
</script>
</body>
</html>