<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'db.php';

// --- LOGOUT HANDLER ---
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
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

//  AUTO-FIX: ADD is_active COLUMN TO clinic_services KUNG WALA PA
try {
    $pdo->query("SELECT is_active FROM clinic_services LIMIT 1");
} catch (PDOException $e) {
    try {
        // Default is 1 (Active/ON)
        $pdo->exec("ALTER TABLE clinic_services ADD is_active TINYINT(1) NOT NULL DEFAULT 1");
    } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD philhealth_rate COLUMN TO clinic_services KUNG WALA PA
try {
    $pdo->query("SELECT philhealth_rate FROM clinic_services LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE clinic_services ADD philhealth_rate DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER downpayment_percent"); } catch (PDOException $ex) {}
}

//  AUTO-FIX: ADD PHILHEALTH COLUMNS TO PAYMENTS TABLE
try {
    $pdo->query("SELECT is_philhealth FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD is_philhealth TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT philhealth_amount FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD philhealth_amount DECIMAL(10,2) NULL DEFAULT 0 AFTER is_philhealth"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT philhealth_id_front FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD philhealth_id_front VARCHAR(255) NULL AFTER philhealth_amount"); } catch (PDOException $ex) {}
}
try {
    $pdo->query("SELECT philhealth_id_back FROM payments LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE payments ADD philhealth_id_back VARCHAR(255) NULL AFTER philhealth_id_front"); } catch (PDOException $ex) {}
}

// --- SYSTEM SETTINGS (JSON BASED) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
if (!file_exists($settingsFile)) {
    file_put_contents($settingsFile, json_encode([
        'maintenance_mode' => false,
        'super_theme_color' => '#10b981',
        'super_logo' => null,
        'super_hero_image' => null,
        'allow_new_registrations' => true,
        'system_email' => 'support@maternityhub.com',
        'session_timeout' => 30
    ]));
}

$settings = json_decode(file_get_contents($settingsFile), true);
$maintenanceMode = $settings['maintenance_mode'] ?? false;
$superThemeColor = $settings['super_theme_color'] ?? '#10b981';
$superLogo = $settings['super_logo'] ?? null;
$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;

// Contrast Calculator
$hex = ltrim($superThemeColor, '#');
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

// --- SECURITY CHECK ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header("Location: registration.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Admin';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$displayRole = $userRole;
$normalizedRole = strtolower(trim((string)$userRole));
$isStaffRole = ($normalizedRole === 'staff');
$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null;
$dbError     = null;
$currentPage = basename($_SERVER['PHP_SELF']);

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];

// Auto-detect URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$baseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

// =========================================================================
// 1. FETCH CLINIC DATA
// =========================================================================
$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = $superThemeColor; // Default sa super theme kung walang tenant

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            $clinicCode = $clinicData['clinic_code'] ?? "N/A";
            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }
            if (!empty($clinicData['theme_color'])) {
                $themeColor = $clinicData['theme_color'];
            }
        }
    } catch (PDOException $e) {}
}

// PROFILE PICTURE & FULL NAME
try {
    $stmtPic = $pdo->prepare("SELECT u.first_name, u.middle_name, u.last_name, COALESCE(u.profile_image, cs.profile_image) AS profile_image FROM users u LEFT JOIN clinic_staff cs ON cs.TenantID = u.TenantID AND LOWER(TRIM(COALESCE(cs.email_address, ''))) = LOWER(TRIM(COALESCE(u.email, ''))) WHERE u.id = ? LIMIT 1");
    $stmtPic->execute([$userId]);
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

// Finish Owner/StaffAdmin detection
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $userId]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (int)($curAccess['is_admin'] ?? 0) === 1;
            $currentUserGrantedFeatures = json_decode($curAccess['granted_features'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {}
}
$_ownerAlsoMidwife = false;
if ($currentUserIsOwner && $tenant_id) {
    try { $_stmtMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1"); $_stmtMw->execute([$userId, $tenant_id]); $_ownerAlsoMidwife = ((int)$_stmtMw->fetchColumn() === 1); } catch (PDOException $e) {}
}
if ($currentUserIsOwner) { $displayRole = $_ownerAlsoMidwife ? 'Owner / Midwife' : 'Owner'; }
elseif ($currentUserIsStaffAdmin) { $displayRole = $userRole . ' | Admin'; }

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

function log_financial_service_audit($pdo, $tenant_id, $user_name, $role, $action_type, $details) {
    if (empty($tenant_id)) {
        return;
    }

    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$tenant_id, $user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (Exception $e) {
        // Silent fail
    }
}

// =========================================================================
// 2. HANDLE POST REQUESTS (ADD / UPDATE SERVICE / DELETE)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_service'])) {
        $service_name = trim($_POST['service_name']);
        $price = floatval($_POST['price']);
        $philhealth_rate_new = floatval($_POST['philhealth_rate'] ?? 0);
        if ($philhealth_rate_new < 0) $philhealth_rate_new = 0;

        if (!empty($service_name)) {
            try {
                $stmt = $pdo->prepare("INSERT INTO clinic_services (TenantID, clinic_name, service_name, price, downpayment_percent, philhealth_rate, is_active) VALUES (?, ?, ?, ?, 0, ?, 1)");
                $stmt->execute([$tenant_id, $clinicName, $service_name, $price, $philhealth_rate_new]);

                $details = 'Added service "' . $service_name . '" with price ₱' . number_format($price, 2) . ', PhilHealth rate ₱' . number_format($philhealth_rate_new, 2) . ' and downpayment 0%.';
                log_financial_service_audit($pdo, $tenant_id, $displayName, $userRole, 'Service Added', $details);

                header("Location: {$currentPage}?msg=service_added&open_modal=1");
                exit();
            } catch (PDOException $e) {
                $dbError = "Failed to add service: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_services_bulk']) && isset($_POST['services']) && is_array($_POST['services'])) {
        $anyPriceChanged = false;
        $priceChanges = []; // Collect all price changes para isang email lang
        try {
            $stmt = $pdo->prepare("UPDATE clinic_services SET downpayment_percent = ?, price = ?, philhealth_rate = ?, is_active = ? WHERE id = ? AND TenantID = ?");
            $stmtFetchService = $pdo->prepare("SELECT service_name, price, downpayment_percent, philhealth_rate, is_active FROM clinic_services WHERE id = ? AND TenantID = ? LIMIT 1");

            foreach ($_POST['services'] as $serviceId => $serviceData) {
                $service_id = intval($serviceId);
                if ($service_id <= 0) {
                    continue;
                }

                $dp_percent = isset($serviceData['dp_percent']) ? floatval($serviceData['dp_percent']) : 0;
                $new_price = isset($serviceData['new_price']) ? floatval($serviceData['new_price']) : 0;
                $ph_rate = isset($serviceData['philhealth_rate']) ? floatval($serviceData['philhealth_rate']) : 0;
                if ($ph_rate < 0) $ph_rate = 0;
                $is_active = isset($serviceData['is_active']) && $serviceData['is_active'] == '1' ? 1 : 0;

                if ($dp_percent > 100) $dp_percent = 100;
                if ($dp_percent < 0) $dp_percent = 0;

                $stmtFetchService->execute([$service_id, $tenant_id]);
                $existingService = $stmtFetchService->fetch(PDO::FETCH_ASSOC);

                $stmt->execute([$dp_percent, $new_price, $ph_rate, $is_active, $service_id, $tenant_id]);

                if ($existingService) {
                    $oldPrice = (float)($existingService['price'] ?? 0);
                    $oldDpPercent = (float)($existingService['downpayment_percent'] ?? 0);
                    $oldPhRate = (float)($existingService['philhealth_rate'] ?? 0);
                    $oldActive = (int)($existingService['is_active'] ?? 0);

                    $changes = [];
                    $statusChangeText = null;
                    $priceChanged = false;
                    $priceDirection = '';
                    if (round($oldPrice, 2) !== round($new_price, 2)) {
                        $changes[] = 'price ₱' . number_format($oldPrice, 2) . ' -> ₱' . number_format($new_price, 2);
                        $priceChanged = true;
                        $priceDirection = ($new_price > $oldPrice) ? 'increased' : 'decreased';
                    }
                    if (round($oldDpPercent, 2) !== round($dp_percent, 2)) {
                        $changes[] = 'downpayment ' . rtrim(rtrim(number_format($oldDpPercent, 2), '0'), '.') . '% -> ' . rtrim(rtrim(number_format($dp_percent, 2), '0'), '.') . '%';
                    }
                    if (round($oldPhRate, 2) !== round($ph_rate, 2)) {
                        $changes[] = 'PhilHealth rate ₱' . number_format($oldPhRate, 2) . ' -> ₱' . number_format($ph_rate, 2);
                    }
                    if ($oldActive !== (int)$is_active) {
                        $statusChangeText = $is_active ? 'enabled service' : 'disabled service';
                        $changes[] = 'status ' . ($oldActive ? 'Active' : 'Inactive') . ' -> ' . ($is_active ? 'Active' : 'Inactive') . ' (' . $statusChangeText . ')';
                    }

                    if (!empty($changes)) {
                        $serviceName = $existingService['service_name'] ?? 'Service';
                        $actionType = 'Service Updated';
                        if (count($changes) === 1 && $statusChangeText !== null) {
                            $actionType = $is_active ? 'Service Enabled' : 'Service Disabled';
                            $details = ucfirst($statusChangeText) . ' "' . $serviceName . '".';
                        } else {
                            $details = 'Updated service "' . $serviceName . '": ' . implode(', ', $changes) . '.';
                        }
                        log_financial_service_audit($pdo, $tenant_id, $displayName, $userRole, $actionType, $details);
                    }

                    // Collect price change para sa consolidated email
                    if ($priceChanged) {
                        $anyPriceChanged = true;
                        $priceChanges[] = [
                            'service' => $existingService['service_name'] ?? 'Service',
                            'old' => $oldPrice,
                            'new' => $new_price,
                            'direction' => $priceDirection
                        ];
                    }
                }
            }

            // --- SEND ONE CONSOLIDATED EMAIL TO ALL PATIENTS ---
            if ($anyPriceChanged && !empty($priceChanges)) {
                try {
                    $stmtPatients = $pdo->prepare("SELECT full_name, email_address FROM patients WHERE TenantID = ? AND (is_archived = 0 OR is_archived IS NULL) AND email_address IS NOT NULL AND TRIM(email_address) != ''");
                    $stmtPatients->execute([$tenant_id]);
                    $patientsToNotify = $stmtPatients->fetchAll(PDO::FETCH_ASSOC);

                    $safeClinicName = htmlspecialchars($clinicName);
                    $totalChanges = count($priceChanges);
                    $hasIncrease = false;
                    $hasDecrease = false;
                    foreach ($priceChanges as $pc) {
                        if ($pc['direction'] === 'increased') $hasIncrease = true;
                        if ($pc['direction'] === 'decreased') $hasDecrease = true;
                    }

                    // Subject line
                    if ($hasIncrease && $hasDecrease) {
                        $subjectIcon = '';
                        $subjectText = "Service Price Updates";
                    } elseif ($hasIncrease) {
                        $subjectIcon = '';
                        $subjectText = "Service Price Increase Notice";
                    } else {
                        $subjectIcon = '';
                        $subjectText = "Service Price Decrease Notice";
                    }

                    // Build service rows HTML
                    $serviceRowsHtml = '';
                    foreach ($priceChanges as $pc) {
                        $svcName = htmlspecialchars($pc['service']);
                        $oldF = number_format($pc['old'], 2);
                        $newF = number_format($pc['new'], 2);
                        $dirColor = ($pc['direction'] === 'increased') ? '#dc2626' : '#16a34a';
                        $dirIcon = ($pc['direction'] === 'increased') ? '▲' : '▼';
                        $dirLabel = ($pc['direction'] === 'increased') ? 'Increased' : 'Decreased';

                        $serviceRowsHtml .= "
                        <tr>
                            <td style='padding: 12px 16px; border-bottom: 1px solid #f1f5f9; font-weight: 600; color: #0f172a; font-size: 13px;'>$svcName</td>
                            <td style='padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center; color: #64748b; font-size: 13px; text-decoration: line-through;'>₱$oldF</td>
                            <td style='padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center; color: {$dirColor}; font-weight: 700; font-size: 13px;'>₱$newF</td>
                            <td style='padding: 12px 16px; border-bottom: 1px solid #f1f5f9; text-align: center;'>
                                <span style='font-size: 11px; color: {$dirColor}; font-weight: 700; background: {$dirColor}15; padding: 3px 8px; border-radius: 6px;'>$dirIcon $dirLabel</span>
                            </td>
                        </tr>";
                    }

                    $pluralText = ($totalChanges === 1) ? 'one of our services has been updated' : "$totalChanges of our services have been updated";

                    foreach ($patientsToNotify as $pt) {
                        $patEmail = trim($pt['email_address']);
                        $patName = htmlspecialchars($pt['full_name'] ?? 'Valued Patient');

                        $emailBody = "
                        <div style='font-family: Arial, sans-serif; max-width: 640px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 16px; overflow: hidden;'>
                            <div style='background: {$themeColor}; padding: 24px 30px; text-align: center;'>
                                <h1 style='margin: 0; color: #ffffff; font-size: 20px;'>$safeClinicName</h1>
                                <p style='margin: 4px 0 0; color: rgba(255,255,255,0.8); font-size: 11px; letter-spacing: 2px;'>SERVICE PRICE UPDATE</p>
                            </div>
                            <div style='padding: 30px;'>
                                <p style='margin: 0 0 16px; color: #334155; font-size: 14px;'>Dear <strong>$patName</strong>,</p>
                                <p style='margin: 0 0 20px; color: #475569; font-size: 13px; line-height: 1.6;'>
                                    We would like to inform you that the pricing for $pluralText. Please see the details below:
                                </p>
                                <div style='background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; margin-bottom: 20px;'>
                                    <table style='width: 100%; border-collapse: collapse;'>
                                        <thead>
                                            <tr style='background: #f1f5f9;'>
                                                <th style='padding: 10px 16px; text-align: left; font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;'>Service</th>
                                                <th style='padding: 10px 16px; text-align: center; font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;'>Old Price</th>
                                                <th style='padding: 10px 16px; text-align: center; font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;'>New Price</th>
                                                <th style='padding: 10px 16px; text-align: center; font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1px; font-weight: 700;'>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            $serviceRowsHtml
                                        </tbody>
                                    </table>
                                </div>
                                <p style='margin: 0 0 8px; color: #475569; font-size: 12px; line-height: 1.6;'>
                                    These changes take effect immediately. If you have any questions or concerns, please don't hesitate to contact us.
                                </p>
                                <p style='margin: 20px 0 0; color: #94a3b8; font-size: 11px;'>— $safeClinicName Management</p>
                            </div>
                            <div style='background: #f1f5f9; padding: 14px 30px; text-align: center; border-top: 1px solid #e2e8f0;'>
                                <p style='margin: 0; color: #94a3b8; font-size: 10px;'>This is an automated notification from MaternityHub. Please do not reply to this email.</p>
                            </div>
                        </div>";

                        send_email_via_smtp_gmail($patEmail, "$subjectIcon $subjectText - $clinicName", $emailBody);
                    }
                } catch (Exception $e) { /* silent */ }
            }

            $updatedMsg = $anyPriceChanged ? 'service_updated_emailed' : 'service_updated';
            header("Location: {$currentPage}?msg={$updatedMsg}&open_modal=1");
            exit();
        } catch (PDOException $e) {
            $dbError = "Failed to update services: " . $e->getMessage();
        }
    } elseif (isset($_POST['delete_service'])) {
        $service_id = intval($_POST['service_id']);
        $protectedServices = ['Prenatal Checkup', 'Postnatal Checkup', 'Normal Delivery', 'Cesarean Delivery', 'Transvaginal Ultrasound', 'Pelvic Ultrasound'];
        try {
            $stmtService = $pdo->prepare("SELECT service_name FROM clinic_services WHERE id = ? AND TenantID = ? LIMIT 1");
            $stmtService->execute([$service_id, $tenant_id]);
            $serviceToDelete = $stmtService->fetch(PDO::FETCH_ASSOC);

            if ($serviceToDelete && in_array($serviceToDelete['service_name'], $protectedServices)) {
                $dbError = 'Default services cannot be deleted.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM clinic_services WHERE id = ? AND TenantID = ?");
                $stmt->execute([$service_id, $tenant_id]);

                if ($serviceToDelete) {
                    $details = 'Deleted service "' . ($serviceToDelete['service_name'] ?? 'Service') . '".';
                    log_financial_service_audit($pdo, $tenant_id, $displayName, $userRole, 'Service Deleted', $details);
                }

                header("Location: {$currentPage}?msg=service_deleted&open_modal=1");
                exit();
            }
        } catch (PDOException $e) {
            // ignore
        }
    }
}

// =========================================================================
// AUTO-SEED DEFAULT SERVICES (KASAMA NA ANG ULTRASOUNDS)
// =========================================================================
if ($tenant_id) {
    try {
        $defaultServices = [
            'Prenatal Checkup',
            'Postnatal Checkup',
            'Normal Delivery',
            'Cesarean Delivery',
            'Transvaginal Ultrasound',
            'Pelvic Ultrasound'
        ];

        foreach ($defaultServices as $svcName) {
            $stmtCheckSvc = $pdo->prepare("SELECT id FROM clinic_services WHERE TenantID = ? AND service_name = ?");
            $stmtCheckSvc->execute([$tenant_id, $svcName]);

            if (!$stmtCheckSvc->fetch()) {
                // Default is_active = 1
                $stmtInsertSvc = $pdo->prepare("INSERT INTO clinic_services (TenantID, clinic_name, service_name, price, downpayment_percent, philhealth_rate, is_active) VALUES (?, ?, ?, 0, 0, 0, 1)");
                $stmtInsertSvc->execute([$tenant_id, $clinicName, $svcName]);
            }
        }
    } catch (PDOException $e) {}
}

// =========================================================================
// FETCH CLINIC'S CUSTOM SERVICES
// =========================================================================
$clinicCustomServices = [];
try {
    $stmtServices = $pdo->prepare("
        SELECT * FROM clinic_services
        WHERE TenantID = ?
        ORDER BY
            CASE service_name
                WHEN 'Prenatal Checkup' THEN 1
                WHEN 'Postnatal Checkup' THEN 2
                WHEN 'Normal Delivery' THEN 3
                WHEN 'Cesarean Delivery' THEN 4
                WHEN 'Transvaginal Ultrasound' THEN 5
                WHEN 'Pelvic Ultrasound' THEN 6
                ELSE 7
            END,
            service_name ASC
    ");
    $stmtServices->execute([$tenant_id]);
    $clinicCustomServices = $stmtServices->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// =========================================================================
// FETCH CLINIC'S FINANCIAL DATA
// =========================================================================
$filter = $_GET['period'] ?? 'all';
$filterLabel = 'All Time';
$currentYear = date('Y');
$specificMonthFilter = null;

if ($filter === 'daily') { $filterLabel = 'Daily (Today)'; }
elseif ($filter === 'weekly') { $filterLabel = 'Weekly (This Week)'; }
elseif ($filter === 'monthly') { $filterLabel = 'Monthly (This Month)'; }
elseif (in_array($filter, ['01','02','03','04','05','06','07','08','09','10','11','12'])) {
    $monthName = date('F', mktime(0, 0, 0, $filter, 10));
    $filterLabel = $monthName . ' ' . $currentYear;
    $specificMonthFilter = $currentYear . '-' . $filter;
}

$today = date('Y-m-d');
$dayOfWeek = date('N');
$mondayThisWeek = date('Y-m-d', strtotime('-' . ($dayOfWeek - 1) . ' days'));
$sundayThisWeek = date('Y-m-d', strtotime('+' . (7 - $dayOfWeek) . ' days'));
$currentMonth = date('Y-m');

$clinicTransactions = [];
$filteredTotalRev = 0;
$metrics = ['daily' => 0, 'weekly' => 0, 'monthly' => 0, 'total' => 0, 'philhealth_total' => 0, 'txn_total' => 0, 'txn_success' => 0, 'txn_pending' => 0];
$chartData = ['labels' => [], 'values' => []];

try {
    $stmtPayments = $pdo->prepare("SELECT * FROM payments WHERE TenantID = ? ORDER BY payment_date DESC");
    $stmtPayments->execute([$tenant_id]);
    $allPayments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allPayments as $p) {
        $amount = (float)($p['amount'] ?? 0);
        $txnDateStr = $p['payment_date'] ?? $p['created_at'];
        $txnDateOnly = date('Y-m-d', strtotime($txnDateStr));
        $txnMonthOnly = date('Y-m', strtotime($txnDateStr));
        $status = ucfirst(strtolower($p['status'] ?? 'Pending'));
        $isPhilhealth = (int)($p['is_philhealth'] ?? 0);
        $philhealthAmt = (float)($p['philhealth_amount'] ?? 0);

        // Fix for old records where philhealth_amount was saved as 0
        if ($isPhilhealth && $philhealthAmt <= 0 && !empty($p['admission_id'])) {
            try {
                $stmtAdmPrice = $pdo->prepare("SELECT total_price, remaining_balance, reason FROM admissions WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtAdmPrice->execute([$p['admission_id'], $tenant_id]);
                $admRow = $stmtAdmPrice->fetch(PDO::FETCH_ASSOC);
                if ($admRow) {
                    $philhealthAmt = (float)($admRow['total_price'] ?? 0);
                }
            } catch (PDOException $ex) {}
        }

        if ($status === 'Paid' || $status === 'Completed') {
            $metrics['total'] += $amount;
            if ($isPhilhealth && $philhealthAmt > 0) {
                $metrics['philhealth_total'] += $philhealthAmt;
            }
            if ($txnDateOnly === $today) $metrics['daily'] += $amount;
            if ($txnDateOnly >= $mondayThisWeek && $txnDateOnly <= $sundayThisWeek) $metrics['weekly'] += $amount;
            if ($txnMonthOnly === $currentMonth) $metrics['monthly'] += $amount;
        }

        $includeRecord = false;
        if ($filter === 'daily' && $txnDateOnly === $today) $includeRecord = true;
        elseif ($filter === 'weekly' && $txnDateOnly >= $mondayThisWeek && $txnDateOnly <= $sundayThisWeek) $includeRecord = true;
        elseif ($filter === 'monthly' && $txnMonthOnly === $currentMonth) $includeRecord = true;
        elseif ($specificMonthFilter !== null && $txnMonthOnly === $specificMonthFilter) $includeRecord = true;
        elseif ($filter === 'all') $includeRecord = true;

        if ($includeRecord) {
            $metrics['txn_total']++;
            if ($status === 'Paid' || $status === 'Completed') {
                $metrics['txn_success']++;
                $filteredTotalRev += $amount;
            } else {
                $metrics['txn_pending']++;
            }

            //  FIX FOR BLANK NAMES
            $patientNameDisplay = 'Unknown Patient';
            if (!empty($p['full_name'])) {
                $patientNameDisplay = $p['full_name'];
            } elseif (!empty($p['patient_name'])) {
                $patientNameDisplay = $p['patient_name'];
            }

            $clinicTransactions[] = [
                'id' => 'INV-' . str_pad($p['id'], 5, '0', STR_PAD_LEFT),
                'receipt' => !empty($p['receipt']) ? $p['receipt'] : '—',
                'patient' => $patientNameDisplay,
                'service' => !empty($p['service']) ? $p['service'] : 'General Admission',
                'method' => !empty($p['description']) ? $p['description'] : 'Over the Counter',
                'amount' => $amount,
                'date' => $txnDateStr,
                'status' => $status,
                'is_philhealth' => $isPhilhealth,
                'philhealth_amount' => $philhealthAmt
            ];
        }
    }

    for ($i = 5; $i >= 0; $i--) {
        $m = (int)date('m', strtotime("-$i months"));
        $y = (int)date('Y', strtotime("-$i months"));
        $monthName = date('M', strtotime("-$i months"));

        $stmtRev = $pdo->prepare("SELECT amount FROM payments WHERE TenantID = ? AND status IN ('Paid', 'Completed') AND MONTH(payment_date) = ? AND YEAR(payment_date) = ?");
        $stmtRev->execute([$tenant_id, $m, $y]);
        $monthlyIncome = 0;
        foreach($stmtRev->fetchAll(PDO::FETCH_ASSOC) as $inc) {
            $monthlyIncome += (float)$inc['amount'];
        }
        $chartData['labels'][] = $monthName;
        $chartData['values'][] = $monthlyIncome;
    }

} catch (PDOException $e) {
    if (!$dbError) {
        $dbError = "Table 'payments' not found or schema mismatch. Please configure your billing table.";
    }
}

function getClinicStatusBadge($status) {
    if ($status === 'Paid' || $status === 'Completed') return 'text-emerald-700 bg-emerald-50 border-emerald-200';
    if ($status === 'Cancelled' || $status === 'Refunded') return 'text-red-700 bg-red-50 border-red-200';
    return 'text-amber-600 bg-amber-50 border-amber-200'; // Pending / Unpaid
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Clinic Financials - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= htmlspecialchars($themeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)",
                        "background-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 3%, white)"
                    },
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)' }
                }
            }
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        body { font-family: 'Plus Jakarta Sans', sans-serif; overflow: hidden; }
        .material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; }
        .icon-filled { font-variation-settings: 'FILL' 1; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .scrollable-box { scroll-behavior: smooth; }

        /* Hilig ng input field sa table */
        .dp-calc-input::-webkit-outer-spin-button,
        .dp-calc-input::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        /* Toggle Switch CSS */
        .toggle-checkbox:checked { right: 0; border-color: <?= htmlspecialchars($themeColor) ?>; }
        .toggle-checkbox:checked + .toggle-label { background-color: <?= htmlspecialchars($themeColor) ?>; }

        /* Print Settings */
        @media print {
            aside, header, .no-print, #logoutModal, #servicesModal { display: none !important; }
            main { padding: 0 !important; margin: 0 !important; background: white !important; overflow: visible !important; }
            .print-container { width: 100% !important; border: none !important; box-shadow: none !important; max-width: 100% !important; }
            .scrollable-box { max-height: none !important; overflow: visible !important; }
            body { overflow: auto !important; }
        }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Closing session safely...</p>
</div>

<?php if(isset($_GET['msg'])): ?>
    <?php
        $msgText = ''; $msgColor = 'emerald'; $icon = 'check_circle';
        if($_GET['msg'] == 'service_added') { $msgText = 'New service successfully added!'; }
        elseif($_GET['msg'] == 'service_updated') { $msgText = 'Service configuration successfully updated!'; }
        elseif($_GET['msg'] == 'service_updated_emailed') { $msgText = 'Service price updated! Email notifications sent to patients.'; $icon = 'mail'; }
        elseif($_GET['msg'] == 'service_deleted') { $msgText = 'Service successfully deleted.'; $msgColor = 'amber'; $icon = 'delete'; }
    ?>
    <?php if($msgText): ?>
    <div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-<?= $msgColor ?>-500 p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce no-print">
        <span class="material-symbols-outlined text-<?= $msgColor ?>-500"><?= $icon ?></span>
        <p class="text-xs font-black text-slate-800"><?= $msgText ?></p>
    </div>
    <script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 3000);</script>
    <?php endif; ?>
<?php endif; ?>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm no-print">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl text-center border border-slate-100">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2 rounded-xl font-bold text-slate-400 hover:bg-slate-100 text-[11px]">Cancel</button>
            <button onclick="document.getElementById('loggingOutScreen').classList.remove('hidden'); document.getElementById('loggingOutScreen').classList.add('flex'); window.location.href='financials.php?action=logout&c=<?= urlencode($clinicCode) ?>'" class="flex-1 py-2 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 text-[11px] shadow-lg shadow-red-100">Logout</button>
        </div>
    </div>
</div>

<div id="servicesModal" class="fixed inset-0 z-[90] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm no-print">
    <div class="bg-white rounded-[2rem] shadow-2xl border border-slate-100 w-full max-w-4xl flex flex-col max-h-[85vh] overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="servicesModalBox">
        <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/50">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined icon-filled">medical_services</span></div>
                <div>
                    <h3 class="text-lg font-black text-slate-800 tracking-tight leading-none">Manage Services & Pricing</h3>
                    <p class="text-[10px] text-slate-500 mt-1">Configure packages, set prices, and toggle availability.</p>
                </div>
            </div>
            <button onclick="closeServicesModal()" class="size-8 rounded-full hover:bg-slate-200 text-slate-400 flex items-center justify-center transition-colors"><span class="material-symbols-outlined text-lg">close</span></button>
        </div>

        <div class="flex-1 overflow-y-auto p-6 scrollable-box bg-slate-50">

            <div class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 mb-6">
                <p class="text-[10px] font-black text-primary uppercase tracking-widest mb-3 border-b border-slate-100 pb-2">Add New Catalog Item</p>
                <form method="POST" action="<?= $currentPage ?>" class="flex flex-col sm:flex-row gap-4 items-end">
                    <input type="hidden" name="add_service" value="1">

                    <div class="flex-1 w-full">
                        <label class="text-[10px] font-bold text-slate-500 uppercase">Service / Package Name</label>
                        <input type="text" name="service_name" required placeholder="e.g., Blood Test" class="w-full mt-1 px-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-primary focus:border-primary outline-none bg-slate-50">
                    </div>

                    <div class="w-full sm:w-56">
                        <label class="text-[10px] font-bold text-slate-500 uppercase">Standard Total Price</label>
                        <div class="relative mt-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold">₱</span>
                            <input type="number" step="0.01" name="price" id="totalPrice" required placeholder="0.00" class="w-full pl-8 pr-4 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-primary focus:border-primary outline-none bg-slate-50 font-black text-slate-700">
                        </div>
                    </div>

                    <div class="w-full sm:w-56">
                        <label class="text-[10px] font-bold text-slate-500 uppercase flex items-center gap-1.5">
                            <img src="uploads/philhealth_logo.png" alt="PH" class="h-4 rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                            <span class="material-symbols-outlined text-green-600 text-[14px] hidden">health_and_safety</span>
                            <span>PhilHealth Package Rate</span>
                        </label>
                        <div class="relative mt-1">
                            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 font-bold">₱</span>
                            <input type="number" step="0.01" min="0" name="philhealth_rate" placeholder="0.00" class="w-full pl-8 pr-4 py-2.5 rounded-xl border border-green-200 text-sm focus:ring-green-500 focus:border-green-500 outline-none bg-green-50/40 font-black text-green-700">
                        </div>
                    </div>

                    <button type="submit" class="bg-primary hover:bg-primary-dark text-white font-bold py-2.5 px-8 rounded-xl transition-all shadow-md flex items-center justify-center gap-1.5 h-[42px] w-full sm:w-auto">
                        <span class="material-symbols-outlined text-lg">add</span> Add
                    </button>
                </form>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-slate-100/50">
                        <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-200">
                            <th class="p-4">Service/Package</th>
                            <th class="p-4 text-center border-l border-slate-200">Availability</th>
                            <th class="p-4 text-center border-l border-slate-200">Total Price</th>
                            <th class="p-4 text-center border-l border-slate-200 bg-amber-50/30">Set DP (%)</th>
                            <th class="p-4 text-right bg-amber-50/30">Downpayment Deposit</th>
                            <th class="p-4 text-center border-l border-slate-200 bg-green-50/40">
                                <div class="flex items-center justify-center gap-1.5">
                                    <img src="uploads/philhealth_logo.png" alt="PH" class="h-4 rounded" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                                    <span class="material-symbols-outlined text-green-600 text-[14px] hidden">health_and_safety</span>
                                    <span>PhilHealth Package Rate</span>
                                </div>
                            </th>
                            <th class="p-4 text-center border-l border-slate-200">Action</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm font-medium text-slate-700 divide-y divide-slate-100">
                        <?php if(empty($clinicCustomServices)): ?>
                            <tr><td colspan="7" class="p-8 text-center text-slate-400 italic">No custom services added yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($clinicCustomServices as $srv): ?>
                                <?php
                                    $dpPercent = (float)($srv['downpayment_percent'] ?? 0);
                                    $currentPrice = (float)$srv['price'];
                                    $dpAmt = $currentPrice * ($dpPercent / 100);
                                    $phRate = (float)($srv['philhealth_rate'] ?? 0);
                                    $isActive = (int)$srv['is_active'] === 1;
                                    $defaultServiceNames = ['Prenatal Checkup', 'Postnatal Checkup', 'Normal Delivery', 'Cesarean Delivery', 'Transvaginal Ultrasound', 'Pelvic Ultrasound'];
                                    $isDefault = in_array($srv['service_name'], $defaultServiceNames);
                                ?>
                                <tr class="hover:bg-slate-50 transition-colors service-row" data-service-id="<?= $srv['id'] ?>">
                                    <td class="p-4 font-bold text-slate-800 <?php if(!$isActive) echo 'opacity-50 line-through text-slate-400'; ?>">
                                        <?= htmlspecialchars($srv['service_name']) ?>
                                    </td>

                                    <td class="p-2 text-center border-l border-slate-100">
                                        <div class="relative inline-block w-10 align-middle select-none transition duration-200 ease-in mt-1">
                                            <input type="checkbox" id="toggle_<?= $srv['id'] ?>" class="toggle-checkbox absolute block w-5 h-5 rounded-full bg-white border-4 appearance-none cursor-pointer z-10 status-toggle" data-original-status="<?= $isActive ? '1' : '0' ?>" <?= $isActive ? 'checked' : '' ?> />
                                            <label for="toggle_<?= $srv['id'] ?>" class="toggle-label block overflow-hidden h-5 rounded-full bg-slate-300 cursor-pointer"></label>
                                        </div>
                                    </td>

                                    <td class="p-2 text-center border-l border-slate-100">
                                        <div class="relative inline-block w-28">
                                            <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-xs">₱</span>
                                            <input type="number" step="0.01" value="<?= $currentPrice ?>" class="w-full pl-5 pr-2 py-1.5 text-xs font-black text-right text-primary-dark bg-white border border-slate-200 rounded outline-none focus:ring-primary focus:border-primary dp-calc-input price-input shadow-inner" data-original-price="<?= $currentPrice ?>" data-original-percent="<?= $dpPercent ?>">
                                        </div>
                                    </td>

                                    <td class="p-2 text-center border-l border-slate-100 bg-amber-50/10">
                                        <div class="flex items-center justify-center gap-1.5">
                                            <div class="relative w-20">
                                                <input type="number" step="0.1" min="0" max="100" value="<?= $dpPercent ?>" class="w-full pl-2 pr-6 py-1.5 text-xs font-bold text-center text-amber-700 bg-white border border-amber-300 rounded outline-none focus:ring-amber-500 focus:border-amber-500 dp-calc-input percent-input shadow-inner" data-original-price="<?= $currentPrice ?>" data-original-percent="<?= $dpPercent ?>">
                                                <span class="absolute right-2 top-1/2 -translate-y-1/2 text-amber-500 font-black text-[10px]">%</span>
                                            </div>
                                        </div>
                                    </td>

                                    <td class="p-4 text-right font-black text-amber-600 tracking-tight bg-amber-50/10 dp-calc-result">
                                        ₱<?= number_format($dpAmt, 2) ?>
                                    </td>

                                    <td class="p-2 text-center border-l border-slate-100 bg-green-50/20">
                                        <div class="relative inline-block w-28">
                                            <span class="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-xs">₱</span>
                                            <input type="number" step="0.01" min="0" value="<?= $phRate ?>" class="w-full pl-5 pr-2 py-1.5 text-xs font-black text-right text-green-700 bg-white border border-green-200 rounded outline-none focus:ring-green-500 focus:border-green-500 philhealth-rate-input shadow-inner" data-original-philhealth="<?= $phRate ?>">
                                        </div>
                                    </td>

                                    <td class="p-4 text-center border-l border-slate-100">
                                        <?php if ($isDefault): ?>
                                            <span class="text-[9px] font-bold text-slate-400 uppercase tracking-widest">Default</span>
                                        <?php else: ?>
                                            <button type="button" onclick="openDeleteServiceModal(<?= $srv['id'] ?>, '<?= htmlspecialchars(addslashes($srv['service_name']), ENT_QUOTES) ?>')" class="text-slate-300 hover:text-red-500 transition-colors p-1" title="Delete this service">
                                                <span class="material-symbols-outlined text-lg">delete</span>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="p-3 bg-slate-50 border-t border-slate-200 flex items-center justify-between gap-4 flex-wrap">
                    <p class="text-[10px] text-slate-500 font-medium italic"><span class="font-bold text-amber-500">Important:</span> When you change the Price, Percentage, or Availability, click <span class="font-bold text-red-500">"Save Changes"</span>.</p>
                    <form method="POST" action="<?= $currentPage ?>" id="servicesUpdateForm" class="inline-flex">
                        <input type="hidden" name="update_services_bulk" value="1">
                        <button type="submit" id="saveAllServicesBtn" disabled class="bg-slate-200 text-slate-500 px-4 py-2 rounded-xl shadow-sm text-[10px] font-black uppercase tracking-widest cursor-not-allowed flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-[16px]">save</span>
                            <span class="btn-text">Save Changes</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- DELETE SERVICE CONFIRMATION MODAL -->
<div id="deleteServiceModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm no-print">
    <div id="deleteServiceModalBox" class="bg-white rounded-[1.75rem] shadow-2xl border border-slate-100 w-full max-w-sm transform scale-95 opacity-0 transition-all duration-300">
        <div class="p-6 text-center">
            <div class="size-14 rounded-full bg-red-50 border border-red-100 flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-red-500 text-3xl">delete_forever</span>
            </div>
            <h3 class="text-lg font-black text-slate-800 tracking-tight">Delete Service</h3>
            <p class="text-sm text-slate-500 mt-2">Are you sure you want to delete</p>
            <p id="deleteServiceName" class="text-sm font-black text-red-600 mt-1"></p>
            <p class="text-[11px] text-slate-400 mt-2">This action cannot be undone. Any admission records using this service will not be affected.</p>
        </div>
        <div class="px-6 pb-6 flex items-center gap-3">
            <button type="button" onclick="closeDeleteServiceModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-600 bg-white border border-slate-200 hover:bg-slate-50 transition-colors text-sm">Cancel</button>
            <form method="POST" action="<?= $currentPage ?>" id="deleteServiceForm" class="flex-1">
                <input type="hidden" name="delete_service" value="1">
                <input type="hidden" name="service_id" id="deleteServiceId" value="">
                <button type="submit" class="w-full py-2.5 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 transition-all shadow-md text-sm flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[16px]">delete</span>
                    Delete
                </button>
            </form>
        </div>
    </div>
</div>

<header class="h-20 bg-primary <?= $isLightTheme ? 'border-b border-slate-200' : 'border-b border-primary-dark' ?> flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft relative no-print transition-colors duration-300">
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
    <aside class="w-80 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 overflow-hidden no-print" style="visibility:hidden">
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
                <a href="staffmanagement.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                    <span class="material-symbols-outlined text-2xl">badge</span> <span>Accounts</span>
                </a>

            <div class="space-y-3 mt-4 mb-4">
                <p class="text-xs font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-6">Operations</p>
                <?php if ($currentUserIsOwner || $currentUserIsStaffAdmin || in_array('financials', $currentUserGrantedFeatures)): ?>
                <a href="financials.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">payments</span> <span>Financials</span>
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

    <main class="flex-1 overflow-y-auto p-4 md:p-8 relative z-10" id="mainContentArea">
        <div class="max-w-7xl mx-auto space-y-6 print-container pb-20">

            <?php if($dbError): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-amber-100 text-amber-800 border border-amber-200 no-print mb-4">
                    <span class="material-symbols-outlined text-xl">info</span> <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-start justify-between gap-4 pb-4 screen-title-section">
                <div class="flex flex-col">
                    <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">Clinic Financials</h2>
                    <p class="text-slate-500 font-medium mt-1">Track patient payments, collections, and clinic revenue.</p>
                </div>
                <div class="no-print mt-4 md:mt-0 flex gap-3">
                    <button onclick="openServicesModal()" class="bg-white hover:bg-slate-100 text-primary-dark font-bold py-2.5 px-5 rounded-xl shadow-sm transition-all flex items-center gap-2 text-sm border border-slate-300">
                        <span class="material-symbols-outlined text-lg">medical_services</span> Manage Services
                    </button>
                    <button id="exportPdfBtn" onclick="generatePDF()" class="bg-primary hover:bg-primary-dark text-white font-bold py-2.5 px-5 rounded-xl shadow-md transition-all flex items-center gap-2 text-sm">
                        <span class="material-symbols-outlined text-lg">picture_as_pdf</span> Generate Clinic Report
                    </button>
                    <button id="exportPhilhealthBtn" onclick="generatePhilHealthReport()" class="bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 px-5 rounded-xl shadow-md transition-all flex items-center gap-2 text-sm">
                        <img src="uploads/philhealth_logo.png" alt="PhilHealth" class="h-5 rounded bg-white px-1 py-0.5" onerror="this.style.display='none';this.nextElementSibling.style.display='inline-flex';">
                        <span class="material-symbols-outlined text-lg hidden">health_and_safety</span>
                        Generate PhilHealth Report
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mt-2">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4 no-print">
                        <div class="size-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined icon-filled">today</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Today</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Daily Collection</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['daily'], 2) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4 no-print">
                        <div class="size-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined icon-filled">date_range</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Week</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Weekly Collection</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['weekly'], 2) ?></h3>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4 no-print">
                        <div class="size-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center"><span class="material-symbols-outlined icon-filled">calendar_month</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">This Month</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Monthly Collection</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['monthly'], 2) ?></h3>
                </div>

                <div class="bg-primary p-6 rounded-[2rem] shadow-md flex flex-col justify-between relative overflow-hidden print-lifetime">
                    <div class="absolute inset-0 bg-black/10 no-print"></div>
                    <div class="relative z-10 flex items-center justify-between mb-4 no-print">
                        <div class="size-12 rounded-xl bg-white/20 text-white flex items-center justify-center"><span class="material-symbols-outlined icon-filled">account_balance_wallet</span></div>
                        <span class="text-[10px] font-black text-primary-dark bg-white/90 px-2 py-1 rounded-md uppercase tracking-widest">Lifetime</span>
                    </div>
                    <p class="relative z-10 text-white/80 text-[10px] font-black uppercase tracking-widest mb-1">Total Clinic Revenue</p>
                    <h3 class="relative z-10 text-3xl font-black text-white tracking-tighter leading-none">₱<?= number_format($metrics['total'], 2) ?></h3>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                <div class="bg-green-700 p-6 rounded-[2rem] shadow-md flex flex-col justify-between relative overflow-hidden">
                    <div class="absolute inset-0 bg-black/5"></div>
                    <div class="relative z-10 flex items-center justify-between mb-4 no-print">
                        <img src="uploads/philhealth_logo.png" alt="PhilHealth" class="h-10 rounded-lg bg-white/90 px-2 py-1" onerror="this.style.display='none';this.nextElementSibling.style.display='flex';"><div class="size-12 rounded-xl bg-white/20 text-white items-center justify-center hidden"><span class="material-symbols-outlined icon-filled">health_and_safety</span></div>
                        <span class="text-[10px] font-black text-green-900 bg-white/90 px-2 py-1 rounded-md uppercase tracking-widest">PhilHealth</span>
                    </div>
                    <p class="relative z-10 text-white/80 text-[10px] font-black uppercase tracking-widest mb-1">PhilHealth Revenue</p>
                    <h3 class="relative z-10 text-3xl font-black text-white tracking-tighter leading-none">₱<?= number_format($metrics['philhealth_total'], 2) ?></h3>
                    <p class="relative z-10 text-white/60 text-[10px] font-medium mt-2">Total amount covered by PhilHealth for all patients.</p>
                </div>

                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm flex flex-col justify-between">
                    <div class="flex items-center justify-between mb-4 no-print">
                        <div class="size-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center"><span class="material-symbols-outlined icon-filled">savings</span></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Combined</span>
                    </div>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-widest mb-1">Cash + PhilHealth Total</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter leading-none">₱<?= number_format($metrics['total'] + $metrics['philhealth_total'], 2) ?></h3>
                    <p class="text-slate-400 text-[10px] font-medium mt-2">Cash collections and PhilHealth-covered amounts combined.</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mt-6">
                <div class="bg-white p-6 rounded-[2rem] border border-slate-200 shadow-sm col-span-1 lg:col-span-3 chart-container">
                    <div class="flex justify-between items-center mb-6">
                        <div class="flex items-center gap-2">
                            <div class="size-8 bg-primary/10 text-primary rounded-lg flex items-center justify-center"><span class="material-symbols-outlined text-sm">trending_up</span></div>
                            <h3 class="text-base font-black text-slate-800 uppercase tracking-widest">Collection Trend</h3>
                        </div>
                        <span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-3 py-1.5 rounded-md">Last 6 Months</span>
                    </div>
                    <div class="relative h-[300px] w-full"><canvas id="revenueChart"></canvas></div>
                </div>

                <div class="bg-white rounded-[1.5rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col col-span-1 lg:col-span-3 mb-10">

                    <div class="p-6 border-b border-slate-300 bg-slate-50 flex flex-col sm:flex-row sm:items-center justify-between gap-4 no-print">
                        <div>
                            <h3 class="text-xl font-black text-slate-800 flex items-center gap-2">
                                <span class="material-symbols-outlined text-slate-500 icon-filled">receipt_long</span> Payment Ledger
                            </h3>
                            <p class="text-xs text-slate-500 mt-1 font-medium">Record of patient bills, consultations, and services.</p>
                        </div>

                        <div class="flex items-center gap-3 w-full sm:w-auto">
                            <form method="GET" action="financials.php" class="w-full sm:w-auto">
                                <select name="period" onchange="this.form.submit()" class="w-full rounded-md border border-slate-300 text-xs focus:ring-primary focus:border-primary outline-none bg-white font-bold text-slate-600 cursor-pointer py-2 pl-3 pr-8 shadow-sm">
                                    <optgroup label="Quick Filters">
                                        <option value="all" <?= $filter == 'all' ? 'selected' : '' ?>>All Time</option>
                                        <option value="daily" <?= $filter == 'daily' ? 'selected' : '' ?>>Today (Daily)</option>
                                        <option value="weekly" <?= $filter == 'weekly' ? 'selected' : '' ?>>This Week (Weekly)</option>
                                        <option value="monthly" <?= $filter == 'monthly' ? 'selected' : '' ?>>This Month</option>
                                    </optgroup>
                                    <optgroup label="Filter by Month (<?= $currentYear ?>)">
                                        <option value="01" <?= $filter == '01' ? 'selected' : '' ?>>January</option>
                                        <option value="02" <?= $filter == '02' ? 'selected' : '' ?>>February</option>
                                        <option value="03" <?= $filter == '03' ? 'selected' : '' ?>>March</option>
                                        <option value="04" <?= $filter == '04' ? 'selected' : '' ?>>April</option>
                                        <option value="05" <?= $filter == '05' ? 'selected' : '' ?>>May</option>
                                        <option value="06" <?= $filter == '06' ? 'selected' : '' ?>>June</option>
                                        <option value="07" <?= $filter == '07' ? 'selected' : '' ?>>July</option>
                                        <option value="08" <?= $filter == '08' ? 'selected' : '' ?>>August</option>
                                        <option value="09" <?= $filter == '09' ? 'selected' : '' ?>>September</option>
                                        <option value="10" <?= $filter == '10' ? 'selected' : '' ?>>October</option>
                                        <option value="11" <?= $filter == '11' ? 'selected' : '' ?>>November</option>
                                        <option value="12" <?= $filter == '12' ? 'selected' : '' ?>>December</option>
                                    </optgroup>
                                </select>
                            </form>

                            <div class="relative w-full sm:max-w-xs">
                                <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">search</span>
                                <input type="text" id="txnSearch" onkeyup="filterTransactions()" placeholder="Search patient or ID..." class="w-full pl-9 pr-4 py-2 rounded-md border border-slate-300 text-xs focus:ring-primary focus:border-primary outline-none bg-white shadow-inner">
                            </div>
                        </div>
                    </div>

                    <div class="grid grid-cols-3 divide-x divide-slate-200 border-b border-slate-300 bg-white no-print">
                        <div class="p-4 text-center">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Total Bills</p>
                            <p class="text-2xl font-black text-slate-800 leading-none"><?= number_format($metrics['txn_total']) ?></p>
                        </div>
                        <div class="p-4 text-center bg-emerald-50/30">
                            <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-widest mb-1">Paid / Completed</p>
                            <p class="text-2xl font-black text-emerald-600 leading-none"><?= number_format($metrics['txn_success']) ?></p>
                        </div>
                        <div class="p-4 text-center bg-amber-50/30">
                            <p class="text-[10px] font-bold text-amber-600 uppercase tracking-widest mb-1">Pending / Unpaid</p>
                            <p class="text-2xl font-black text-amber-600 leading-none"><?= number_format($metrics['txn_pending']) ?></p>
                        </div>
                    </div>

                    <div class="overflow-x-auto overflow-y-auto max-h-[600px] scrollable-box w-full" id="printTableContainer">
                        <table class="w-full text-left border-collapse bg-white" id="txnTable" style="min-width: 900px;">
                            <thead class="sticky top-0 z-10 bg-slate-200">
                                <tr>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider whitespace-nowrap">Invoice ID</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider whitespace-nowrap">Date & Time</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider">Patient Name</th>

                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider">Service</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider">Method</th>

                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider text-right">Amount (₱)</th>
                                    <th class="border border-slate-300 p-3 text-xs font-black text-slate-700 uppercase tracking-wider text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="text-sm text-slate-800">
                                <?php if(empty($clinicTransactions)): ?>
                                    <tr><td colspan="7" class="p-10 text-center text-slate-500 italic border border-slate-300">No patient payments or billing records found.</td></tr>
                                <?php else: ?>
                                    <?php foreach($clinicTransactions as $txn): ?>
                                        <tr class="hover:bg-slate-50 transition-colors txn-row">
                                            <td class="border border-slate-300 p-3 font-mono text-[12px] font-bold text-slate-700 whitespace-nowrap">
                                                <?= htmlspecialchars($txn['id']) ?>
                                            </td>
                                            <td class="border border-slate-300 p-3 text-[12px] text-slate-600 whitespace-nowrap">
                                                <?= date('M d, Y - h:i A', strtotime($txn['date'])) ?>
                                            </td>
                                            <td class="border border-slate-300 p-3 text-[13px] font-bold text-slate-900">
                                                <?= htmlspecialchars($txn['patient']) ?>
                                            </td>

                                            <td class="border border-slate-300 p-3 text-[13px] font-medium text-slate-700">
                                                <?= htmlspecialchars($txn['service']) ?>
                                            </td>
                                            <td class="border border-slate-300 p-3 text-[12px] text-slate-500">
                                                <?= htmlspecialchars($txn['method']) ?>
                                                <?php if(!empty($txn['is_philhealth'])): ?>
                                                    <span class="ml-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9px] font-black bg-blue-100 text-blue-700 border border-blue-200">
                                                        <span class="material-symbols-outlined text-[11px]">health_and_safety</span>PH
                                                    </span>
                                                <?php endif; ?>
                                            </td>

                                            <td class="border border-slate-300 p-3 text-[14px] font-black text-primary-dark text-right tracking-tight">
                                                <?php if(!empty($txn['is_philhealth']) && $txn['philhealth_amount'] > 0): ?>
                                                    <span class="text-[10px] font-bold text-green-600 block mb-0.5">PhilHealth: ₱<?= number_format($txn['philhealth_amount'], 2) ?></span>
                                                <?php endif; ?>
                                                <?= number_format($txn['amount'], 2) ?>
                                            </td>
                                            <td class="border border-slate-300 p-3 text-[11px] text-center uppercase tracking-widest">
                                                <span class="px-3 py-1 border rounded-lg <?= getClinicStatusBadge($txn['status']) ?>">
                                                    <?= htmlspecialchars($txn['status']) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="bg-slate-200 sticky bottom-0 z-10">
                                <tr>
                                    <td colspan="5" class="border border-slate-300 p-4 text-right font-black text-slate-700 text-[11px] uppercase tracking-widest">
                                        Total Collection <?= $filter !== 'all' ? '(' . $filterLabel . ')' : '' ?>:
                                    </td>
                                    <td class="border border-slate-300 p-4 text-right font-black text-primary-dark text-lg tracking-tight">
                                        ₱<?= number_format($filteredTotalRev, 2) ?>
                                    </td>
                                    <td class="border border-slate-300 bg-slate-200"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>

<script>
    // --- OPEN MODAL ON LOAD IF PARAM EXISTS ---
    document.addEventListener("DOMContentLoaded", function() {
        <?php if(isset($_GET['open_modal']) && $_GET['open_modal'] == '1'): ?>
            openServicesModal();
            window.history.replaceState({}, document.title, "<?= $currentPage ?>");
        <?php endif; ?>

        // ... (Chart Initialization)
        const ctx = document.getElementById('revenueChart').getContext('2d');

        let primaryColor = '<?= htmlspecialchars($themeColor) ?>';
        let hex = primaryColor.replace('#', '');
        let r = parseInt(hex.substring(0,2), 16);
        let g = parseInt(hex.substring(2,4), 16);
        let b = parseInt(hex.substring(4,6), 16);

        let gradient = ctx.createLinearGradient(0, 0, 0, 300);
        gradient.addColorStop(0, `rgba(${r}, ${g}, ${b}, 0.5)`);
        gradient.addColorStop(1, `rgba(${r}, ${g}, ${b}, 0)`);

        const labels = <?= json_encode($chartData['labels']) ?>;
        const dataValues = <?= json_encode($chartData['values']) ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Monthly Collection (₱)',
                    data: dataValues,
                    borderColor: primaryColor,
                    backgroundColor: gradient,
                    borderWidth: 3,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: primaryColor,
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    fill: true,
                    tension: 0.4
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return '₱' + c.parsed.y.toLocaleString(); } } } },
                scales: {
                    y: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 11 }, callback: function(v) { return '₱' + v.toLocaleString(); } }, grid: { color: '#f1f5f9', drawBorder: false } },
                    x: { ticks: { color: '#94a3b8', font: { size: 11, weight: 'bold' } }, grid: { display: false, drawBorder: false } }
                }
            }
        });
    });

    // --- DELETE SERVICE MODAL ---
    function openDeleteServiceModal(id, name) {
        document.getElementById('deleteServiceId').value = id;
        document.getElementById('deleteServiceName').textContent = '"' + name + '"?';
        document.getElementById('deleteServiceModal').classList.replace('hidden', 'flex');
        setTimeout(() => { document.getElementById('deleteServiceModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
    }
    function closeDeleteServiceModal() {
        document.getElementById('deleteServiceModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => { document.getElementById('deleteServiceModal').classList.replace('flex', 'hidden'); }, 300);
    }

    // --- MODAL FUNCTIONS FOR SERVICES ---
    function openServicesModal() {
        document.getElementById('servicesModal').classList.replace('hidden', 'flex');
        setTimeout(() => {
            document.getElementById('servicesModalBox').classList.remove('scale-95', 'opacity-0');
        }, 10);
    }
    function closeServicesModal() {
        document.getElementById('servicesModalBox').classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            document.getElementById('servicesModal').classList.replace('flex', 'hidden');
        }, 300);
    }

    // --- UPDATED REAL-TIME CALCULATOR (Price + Percent + Toggle) ---
    const bulkSaveBtn = document.getElementById('saveAllServicesBtn');
    const bulkSaveBtnText = bulkSaveBtn ? bulkSaveBtn.querySelector('.btn-text') : null;

    function updateBulkSaveButtonState() {
        if (!bulkSaveBtn) return;
        const anyChanged = document.querySelectorAll('.service-row.row-changed').length > 0;

        if (anyChanged) {
            bulkSaveBtn.disabled = false;
            bulkSaveBtn.classList.remove('bg-slate-200', 'text-slate-500', 'cursor-not-allowed');
            bulkSaveBtn.classList.add('bg-red-500', 'text-white', 'animate-pulse');
            if (bulkSaveBtnText) bulkSaveBtnText.innerText = 'Save Changes';
        } else {
            bulkSaveBtn.disabled = true;
            bulkSaveBtn.classList.remove('bg-red-500', 'text-white', 'animate-pulse');
            bulkSaveBtn.classList.add('bg-slate-200', 'text-slate-500', 'cursor-not-allowed');
            if (bulkSaveBtnText) bulkSaveBtnText.innerText = 'Save Changes';
        }
    }

    // Toggle Switch Event Listener
    document.querySelectorAll('.status-toggle').forEach(toggle => {
        toggle.addEventListener('change', function() {
            const tr = this.closest('tr');
            if (!tr) return;

            // Mark the row as changed so it gets saved
            tr.classList.add('row-changed');
            updateBulkSaveButtonState();

            // Visual indicator na naka-off yung service
            const nameCell = tr.querySelector('td:first-child');
            if (this.checked) {
                nameCell.classList.remove('opacity-50', 'line-through', 'text-slate-400');
            } else {
                nameCell.classList.add('opacity-50', 'line-through', 'text-slate-400');
            }
        });
    });

    document.querySelectorAll('.dp-calc-input').forEach(input => {
        input.addEventListener('input', function() {
            const tr = this.closest('tr');
            if (!tr) return;

            const priceInput = tr.querySelector('.price-input');
            const percentInput = tr.querySelector('.percent-input');
            if (!priceInput || !percentInput) return;

            let currentPrice = parseFloat(priceInput.value) || 0;
            let currentPercent = parseFloat(percentInput.value) || 0;

            if (currentPercent > 100) { currentPercent = 100; percentInput.value = 100; }
            if (currentPercent < 0) { currentPercent = 0; percentInput.value = 0; }

            const newDpAmt = currentPrice * (currentPercent / 100);
            const resultCell = tr.querySelector('.dp-calc-result');
            if (resultCell) {
                resultCell.innerText = '₱' + newDpAmt.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }

            tr.classList.add('row-changed');
            updateBulkSaveButtonState();
        });
    });

    document.querySelectorAll('.philhealth-rate-input').forEach(input => {
        input.addEventListener('input', function() {
            const tr = this.closest('tr');
            if (!tr) return;
            if (parseFloat(this.value) < 0) this.value = 0;
            tr.classList.add('row-changed');
            updateBulkSaveButtonState();
        });
    });

    const servicesForm = document.getElementById('servicesUpdateForm');
    if (servicesForm) {
        servicesForm.addEventListener('submit', function(e) {
            const changedRows = document.querySelectorAll('.service-row.row-changed');
            if (changedRows.length === 0) {
                e.preventDefault();
                return;
            }

            servicesForm.querySelectorAll('.dynamic-service-input').forEach(el => el.remove());

            changedRows.forEach(row => {
                const serviceId = row.getAttribute('data-service-id');
                if (!serviceId) return;

                const priceInput = row.querySelector('.price-input');
                const percentInput = row.querySelector('.percent-input');
                const toggleInput = row.querySelector('.status-toggle'); // Ang bagong toggle
                if (!priceInput || !percentInput || !toggleInput) return;

                const currentPrice = parseFloat(priceInput.value) || 0;
                let currentPercent = parseFloat(percentInput.value) || 0;
                const isActive = toggleInput.checked ? 1 : 0; // Kunin kung on o off

                if (currentPercent > 100) currentPercent = 100;
                if (currentPercent < 0) currentPercent = 0;

                const priceHidden = document.createElement('input');
                priceHidden.type = 'hidden';
                priceHidden.name = `services[${serviceId}][new_price]`;
                priceHidden.value = currentPrice;
                priceHidden.classList.add('dynamic-service-input');
                servicesForm.appendChild(priceHidden);

                const percentHidden = document.createElement('input');
                percentHidden.type = 'hidden';
                percentHidden.name = `services[${serviceId}][dp_percent]`;
                percentHidden.value = currentPercent;
                percentHidden.classList.add('dynamic-service-input');
                servicesForm.appendChild(percentHidden);

                // Idagdag ang is_active status sa ipapasa sa server
                const activeHidden = document.createElement('input');
                activeHidden.type = 'hidden';
                activeHidden.name = `services[${serviceId}][is_active]`;
                activeHidden.value = isActive;
                activeHidden.classList.add('dynamic-service-input');
                servicesForm.appendChild(activeHidden);

                // PhilHealth package rate
                const phRateInput = row.querySelector('.philhealth-rate-input');
                const phRateVal = phRateInput ? (parseFloat(phRateInput.value) || 0) : 0;
                const phRateHidden = document.createElement('input');
                phRateHidden.type = 'hidden';
                phRateHidden.name = `services[${serviceId}][philhealth_rate]`;
                phRateHidden.value = phRateVal < 0 ? 0 : phRateVal;
                phRateHidden.classList.add('dynamic-service-input');
                servicesForm.appendChild(phRateHidden);
            });
        });
    }

    function loadImageAsDataUrl(src) {
        return new Promise((resolve, reject) => {
            const img = new Image();
            img.onload = () => {
                try {
                    const canvas = document.createElement('canvas');
                    canvas.width = img.naturalWidth || img.width;
                    canvas.height = img.naturalHeight || img.height;
                    const ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0);
                    resolve(canvas.toDataURL('image/png'));
                } catch (error) {
                    reject(error);
                }
            };
            img.onerror = reject;
            img.src = src;
        });
    }

    // --- PROFESSIONAL PDF GENERATION VIA jsPDF + AutoTable ---
    function generatePDF() {
        const btn = document.getElementById('exportPdfBtn');
        const originalHtml = btn.innerHTML;

        btn.innerHTML = '<span class="material-symbols-outlined text-lg animate-spin">autorenew</span> Generating...';
        btn.classList.add('opacity-75', 'cursor-wait');
        btn.disabled = true;

        setTimeout(async () => {
            try {
                const { jsPDF } = window.jspdf;
            const philhealthLogoData = await loadImageAsDataUrl('uploads/philhealth_logo.png').catch(() => null);
                // Create A4 Landscape PDF
                const doc = new jsPDF('l', 'pt', 'a4');

                // Get Clinic Theme Colors
                const themeColor = '<?= $themeColor ?>';
                const r = parseInt(themeColor.slice(1, 3), 16);
                const g = parseInt(themeColor.slice(3, 5), 16);
                const b = parseInt(themeColor.slice(5, 7), 16);

                // HEADER SECTION
                doc.setFontSize(22);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(r, g, b);
                doc.text("<?= htmlspecialchars($clinicName) ?>", 40, 45);

                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(100, 100, 100);
                doc.text("CLINIC FINANCIAL LEDGER - <?= strtoupper(htmlspecialchars($filterLabel)) ?>", 40, 65);

                // Right aligned date/author
                doc.setFontSize(9);
                doc.setFont("helvetica", "normal");
                doc.setTextColor(50, 50, 50);
                const pageWidth = doc.internal.pageSize.width;
                doc.text("Report Date: <?= date('M d, Y h:i A') ?>", pageWidth - 40, 45, { align: 'right' });
                doc.text("Generated by: <?= htmlspecialchars($displayName) ?>", pageWidth - 40, 58, { align: 'right' });

                // Line Separator
                doc.setDrawColor(r, g, b);
                doc.setLineWidth(2);
                doc.line(40, 75, pageWidth - 40, 75);

                // REVENUE SUMMARY BOXES
                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(15, 23, 42);
                doc.text("Revenue Summary", 40, 95);

                doc.setDrawColor(203, 213, 225); // slate-300
                doc.setLineWidth(1);

                // Box 1: Lifetime
                doc.setFillColor(r, g, b); // Theme color bg
                doc.rect(40, 105, pageWidth - 80, 50, 'F');
                doc.setFontSize(9);
                doc.setTextColor(255, 255, 255);
                doc.text("TOTAL CLINIC REVENUE", 50, 120);
                doc.setFontSize(16);
                doc.text("Php <?= number_format($metrics['total'], 2) ?>", 50, 142);

                // Row 2: PhilHealth Revenue + Combined
                doc.setDrawColor(203, 213, 225);
                doc.setLineWidth(1);

                // Box 5: PhilHealth Revenue (green)
                doc.setFillColor(21, 128, 61); // green-700
                doc.rect(40, 165, 280, 50, 'F');
                doc.setFontSize(9);
                doc.setTextColor(255, 255, 255);
                doc.text("PHILHEALTH REVENUE", 50, 180);
                doc.setFontSize(16);
                doc.text("Php <?= number_format($metrics['philhealth_total'], 2) ?>", 50, 202);
                if (philhealthLogoData) {
                    doc.setFillColor(255, 255, 255);
                    doc.roundedRect(248, 174, 54, 24, 6, 6, 'F');
                    doc.addImage(philhealthLogoData, 'PNG', 252, 178, 46, 16);
                }

                // Box 6: Cash + PhilHealth Combined
                doc.setFillColor(248, 250, 252);
                doc.rect(330, 165, pageWidth - 370, 50, 'FD');
                doc.setFontSize(9);
                doc.setTextColor(100, 116, 139);
                doc.text("CASH + PHILHEALTH COMBINED", 340, 180);
                doc.setFontSize(16);
                doc.setTextColor(15, 23, 42);
                doc.text("Php <?= number_format($metrics['total'] + $metrics['philhealth_total'], 2) ?>", 340, 202);

                // Row 3: Daily / Weekly / Monthly Collection
                const collGap = 10;
                const collBoxW = (pageWidth - 80 - (collGap * 2)) / 3;
                const collY = 225;
                const collH = 55;

                // Daily Collection
                doc.setFillColor(59, 130, 246); // blue-500
                doc.rect(40, collY, collBoxW, collH, 'F');
                doc.setFontSize(9);
                doc.setTextColor(255, 255, 255);
                doc.text("DAILY COLLECTION (TODAY)", 50, collY + 18);
                doc.setFontSize(15);
                doc.text("Php <?= number_format($metrics['daily'], 2) ?>", 50, collY + 42);

                // Weekly Collection
                doc.setFillColor(168, 85, 247); // purple-500
                doc.rect(40 + collBoxW + collGap, collY, collBoxW, collH, 'F');
                doc.setFontSize(9);
                doc.setTextColor(255, 255, 255);
                doc.text("WEEKLY COLLECTION (THIS WEEK)", 50 + collBoxW + collGap, collY + 18);
                doc.setFontSize(15);
                doc.text("Php <?= number_format($metrics['weekly'], 2) ?>", 50 + collBoxW + collGap, collY + 42);

                // Monthly Collection
                doc.setFillColor(234, 88, 12); // orange-600
                doc.rect(40 + (collBoxW + collGap) * 2, collY, collBoxW, collH, 'F');
                doc.setFontSize(9);
                doc.setTextColor(255, 255, 255);
                doc.text("MONTHLY COLLECTION (THIS MONTH)", 50 + (collBoxW + collGap) * 2, collY + 18);
                doc.setFontSize(15);
                doc.text("Php <?= number_format($metrics['monthly'], 2) ?>", 50 + (collBoxW + collGap) * 2, collY + 42);

                // TABLE SECTION
                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(15, 23, 42);
                doc.text("Transaction Ledger", 40, 305);

                // Parse and draw table
                doc.autoTable({
                    html: '#txnTable',
                    startY: 315,
                    theme: 'grid',
                    styles: {
                        fontSize: 9,
                        cellPadding: 6,
                        font: 'helvetica',
                        lineColor: [203, 213, 225],
                        lineWidth: 0.5
                    },
                    headStyles: {
                        fillColor: [r, g, b],
                        textColor: 255,
                        fontStyle: 'bold',
                        halign: 'left',
                        textTransform: 'uppercase'
                    },
                    footStyles: {
                        fillColor: [226, 232, 240], // slate-200
                        textColor: [15, 23, 42], // slate-900
                        fontStyle: 'bold',
                        fontSize: 10
                    },
                    alternateRowStyles: {
                        fillColor: [248, 250, 252] // slate-50
                    },
                    columnStyles: {
                        0: { cellWidth: 90, fontStyle: 'bold' }, // ID
                        1: { cellWidth: 120 }, // Date
                        2: { cellWidth: 150 }, // Patient
                        3: { cellWidth: 140 }, // Service
                        4: { cellWidth: 110 }, // Method
                        5: { cellWidth: 80, halign: 'right', fontStyle: 'bold', textColor: [r, g, b] }, // Amount
                        6: { cellWidth: 70, halign: 'center', fontStyle: 'bold' } // Status
                    },
                    didParseCell: function(data) {
                        // Change ₱ to Php so jsPDF won't show weird character blocks
                        if(data.cell.text && data.cell.text.length > 0) {
                            for(let j=0; j<data.cell.text.length; j++){
                                data.cell.text[j] = data.cell.text[j].replace('₱', 'Php ');
                            }
                        }
                    }
                });

                // FOOTER (User, Date/Time, Page Numbers)
                const generatedBy = <?= json_encode($displayName) ?>;
                const now = new Date();
                const footerDateTime = now.toLocaleString('en-US', {
                    year: 'numeric', month: 'short', day: '2-digit',
                    hour: '2-digit', minute: '2-digit', hour12: true
                });
                const pageCount = doc.internal.getNumberOfPages();
                for(let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(8);
                    doc.setFont("helvetica", "normal");
                    doc.setTextColor(150, 150, 150);
                    const pageW = doc.internal.pageSize.width;
                    const pageH = doc.internal.pageSize.height;
                    // Left: Generated by user
                    doc.text("Generated by: " + generatedBy, 40, pageH - 20, {align: 'left'});
                    // Center: Date & Time
                    doc.text(footerDateTime, pageW / 2, pageH - 20, {align: 'center'});
                    // Right: Page numbers
                    doc.text("Page " + i + " of " + pageCount, pageW - 40, pageH - 20, {align: 'right'});
                    // Second line center: platform tag
                    doc.text("MaternityHub Platform | Computer Generated Report", pageW / 2, pageH - 10, {align: 'center'});
                }

                // Save PDF
                doc.save('Clinic_Financial_Report_<?= date('Y-m-d') ?>.pdf');

                // Reset button
                btn.innerHTML = originalHtml;
                btn.classList.remove('opacity-75', 'cursor-wait');
                btn.disabled = false;

            } catch (err) {
                console.error("PDF Error: ", err);
                alert("Failed to generate PDF. Check console for details.");
                btn.innerHTML = originalHtml;
                btn.classList.remove('opacity-75', 'cursor-wait');
                btn.disabled = false;
            }
        }, 500); // 500ms delay to let the spinner render
    }

    // --- PHILHEALTH REPORT PDF (per-patient PhilHealth amounts) ---
    const _philhealthRows = <?= json_encode(array_values(array_filter($clinicTransactions, function($t){ return !empty($t['is_philhealth']) && (float)$t['philhealth_amount'] > 0; }))) ?>;
    const _philhealthTotal = <?= json_encode((float)$metrics['philhealth_total']) ?>;
    const _philhealthFilterLabel = <?= json_encode($filterLabel) ?>;

    function generatePhilHealthReport() {
        const btn = document.getElementById('exportPhilhealthBtn');
        const originalHtml = btn.innerHTML;
        btn.innerHTML = '<span class="material-symbols-outlined text-lg animate-spin">autorenew</span> Generating...';
        btn.classList.add('opacity-75', 'cursor-wait');
        btn.disabled = true;

        setTimeout(async () => {
            try {
                const { jsPDF } = window.jspdf;
                const philhealthLogoData = await loadImageAsDataUrl('uploads/philhealth_logo.png').catch(() => null);
                const doc = new jsPDF('p', 'pt', 'a4');
                const pageWidth = doc.internal.pageSize.width;

                // PhilHealth brand colors
                const phR = 21, phG = 128, phB = 61; // emerald-700

                // ===== HEADER =====
                if (philhealthLogoData) {
                    doc.addImage(philhealthLogoData, 'PNG', 40, 30, 70, 30);
                }
                doc.setFontSize(18);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(phR, phG, phB);
                doc.text("PHILHEALTH BENEFITS REPORT", pageWidth - 40, 45, { align: 'right' });

                doc.setFontSize(10);
                doc.setFont("helvetica", "normal");
                doc.setTextColor(80, 80, 80);
                doc.text(<?= json_encode($clinicName) ?>, pageWidth - 40, 60, { align: 'right' });
                doc.text("Period: " + _philhealthFilterLabel, pageWidth - 40, 72, { align: 'right' });
                doc.text("Generated: <?= date('M d, Y h:i A') ?>", pageWidth - 40, 84, { align: 'right' });

                // separator
                doc.setDrawColor(phR, phG, phB);
                doc.setLineWidth(2);
                doc.line(40, 95, pageWidth - 40, 95);

                // ===== SUMMARY BOX =====
                doc.setFillColor(phR, phG, phB);
                doc.roundedRect(40, 110, pageWidth - 80, 60, 6, 6, 'F');
                doc.setTextColor(255, 255, 255);
                doc.setFontSize(10);
                doc.setFont("helvetica", "bold");
                doc.text("TOTAL PHILHEALTH BENEFITS COLLECTED", 55, 132);
                doc.setFontSize(22);
                doc.text("Php " + Number(_philhealthTotal).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}), 55, 158);

                doc.setFontSize(10);
                doc.setFont("helvetica", "normal");
                doc.text("Total Claims: " + _philhealthRows.length, pageWidth - 55, 132, { align: 'right' });

                // ===== TABLE (grouped per patient with breakdown) =====
                doc.setFontSize(12);
                doc.setFont("helvetica", "bold");
                doc.setTextColor(15, 23, 42);
                doc.text("PhilHealth Claims Breakdown per Patient", 40, 200);

                // Group rows by patient name
                const grouped = {};
                _philhealthRows.forEach(r => {
                    const key = (r.patient || 'Unknown Patient').trim();
                    if (!grouped[key]) grouped[key] = { patient: key, claims: [], total: 0 };
                    grouped[key].claims.push(r);
                    grouped[key].total += Number(r.philhealth_amount) || 0;
                });
                // Sort patients by total desc
                const patientGroups = Object.values(grouped).sort((a, b) => b.total - a.total);

                const tableBody = [];
                let patientCounter = 0;
                patientGroups.forEach(g => {
                    patientCounter++;
                    // Patient header row (spans visually via styling)
                    tableBody.push([
                        { content: patientCounter + '. ' + g.patient + '  (' + g.claims.length + ' claim' + (g.claims.length > 1 ? 's' : '') + ')',
                          colSpan: 4,
                          styles: { fillColor: [220, 252, 231], textColor: [15, 23, 42], fontStyle: 'bold', fontSize: 10 } },
                        { content: 'Subtotal',
                          styles: { fillColor: [220, 252, 231], textColor: [15, 23, 42], fontStyle: 'bold', halign: 'right', fontSize: 9 } },
                        { content: 'Php ' + g.total.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2}),
                          styles: { fillColor: [220, 252, 231], textColor: [phR, phG, phB], fontStyle: 'bold', halign: 'right', fontSize: 10 } }
                    ]);
                    // Sort claims by date asc
                    g.claims.sort((a, b) => new Date(a.date || 0) - new Date(b.date || 0));
                    g.claims.forEach((r, idx) => {
                        const dt = r.date ? new Date(r.date) : null;
                        const dateStr = dt ? dt.toLocaleDateString('en-US', {year:'numeric', month:'short', day:'2-digit'}) : '—';
                        tableBody.push([
                            '',
                            String(idx + 1),
                            r.id || '—',
                            r.service || '—',
                            dateStr,
                            'Php ' + Number(r.philhealth_amount).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                        ]);
                    });
                });

                if (tableBody.length === 0) {
                    tableBody.push([{ content: 'No PhilHealth claims found for this period.', colSpan: 6, styles: { halign: 'center', textColor: [100,116,139] } }]);
                }

                doc.autoTable({
                    startY: 210,
                    head: [['#', 'No.', 'Invoice', 'Service', 'Date', 'PhilHealth Amount']],
                    body: tableBody,
                    theme: 'grid',
                    styles: { fontSize: 9, cellPadding: 5, font: 'helvetica', lineColor: [203, 213, 225], lineWidth: 0.5, valign: 'middle' },
                    headStyles: { fillColor: [phR, phG, phB], textColor: 255, fontStyle: 'bold', halign: 'left' },
                    columnStyles: {
                        0: { cellWidth: 22, halign: 'center' },
                        1: { cellWidth: 30, halign: 'center' },
                        2: { cellWidth: 70, fontStyle: 'bold' },
                        3: { cellWidth: 170 },
                        4: { cellWidth: 80 },
                        5: { halign: 'right', fontStyle: 'bold', textColor: [phR, phG, phB] }
                    },
                    foot: [[
                        { content: 'GRAND TOTAL (' + patientGroups.length + ' patient' + (patientGroups.length !== 1 ? 's' : '') + ', ' + _philhealthRows.length + ' claim' + (_philhealthRows.length !== 1 ? 's' : '') + ')',
                          colSpan: 5, styles: { halign: 'right' } },
                        'Php ' + Number(_philhealthTotal).toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2})
                    ]],
                    footStyles: { fillColor: [phR, phG, phB], textColor: 255, fontStyle: 'bold', fontSize: 11, halign: 'right' }
                });

                // ===== FOOTER =====
                const generatedBy = <?= json_encode($displayName) ?>;
                const now = new Date();
                const footerDateTime = now.toLocaleString('en-US', { year:'numeric', month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit', hour12:true });
                const pageCount = doc.internal.getNumberOfPages();
                for (let i = 1; i <= pageCount; i++) {
                    doc.setPage(i);
                    doc.setFontSize(8);
                    doc.setFont("helvetica", "normal");
                    doc.setTextColor(150, 150, 150);
                    const pageH = doc.internal.pageSize.height;
                    doc.text("Generated by: " + generatedBy, 40, pageH - 20);
                    doc.text(footerDateTime, pageWidth / 2, pageH - 20, { align: 'center' });
                    doc.text("Page " + i + " of " + pageCount, pageWidth - 40, pageH - 20, { align: 'right' });
                    doc.text("MaternityHub Platform | PhilHealth Benefits Report", pageWidth / 2, pageH - 10, { align: 'center' });
                }

                doc.save('PhilHealth_Report_<?= date('Y-m-d') ?>.pdf');

                btn.innerHTML = originalHtml;
                btn.classList.remove('opacity-75', 'cursor-wait');
                btn.disabled = false;
            } catch (err) {
                console.error("PhilHealth PDF Error: ", err);
                alert("Failed to generate PhilHealth report. Check console for details.");
                btn.innerHTML = originalHtml;
                btn.classList.remove('opacity-75', 'cursor-wait');
                btn.disabled = false;
            }
        }, 500);
    }

    function filterTransactions() {
        const input = document.getElementById("txnSearch").value.toLowerCase();
        const rows = document.querySelectorAll(".txn-row");
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(input) ? "" : "none";
        });
    }

    function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); document.getElementById('logoutModal').classList.add('flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('flex'); document.getElementById('logoutModal').classList.add('hidden'); }
    function confirmLogout() { closeLogoutModal(); document.getElementById('loggingOutScreen').classList.remove('hidden'); document.getElementById('loggingOutScreen').classList.add('flex'); setTimeout(() => { window.location.href = 'index.php?logout=1'; }, 1000); }
</script>
</body>
</html>