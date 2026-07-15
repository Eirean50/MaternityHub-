<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ob_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// --- LOGOUT HANDLER ---
if (isset($_GET['logout'])) {
    $c = $_GET['c'] ?? '';

    if (!isset($pdo)) {
        require_once 'db.php';
        if (!isset($pdo) && isset($conn)) { $pdo = $conn; }
    }

    if (isset($_SESSION['full_name']) && isset($pdo)) {
        try {
            $logoutName = $_SESSION['full_name'];
            $logoutRole = $_SESSION['role'] ?? 'User';
            $isSuperAdmin = (strtolower(trim((string)$logoutRole)) === 'superadmin' || strpos(strtolower((string)$logoutName), 'eirean') !== false);
            $auditRole = $isSuperAdmin ? 'SuperAdmin' : $logoutRole;
            $auditTenant = $isSuperAdmin ? null : ($_SESSION['TenantID'] ?? null);
            $auditDetails = $isSuperAdmin ? 'Super Admin safely logged out of the platform.' : 'User securely logged out of their clinic portal.';
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');

            $stmtLogoutLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Logout', ?, ?, ?)");
            $stmtLogoutLog->execute([$auditTenant, $logoutName, $auditRole, $auditDetails, $ip, $currentTime]);
        } catch (Exception $e) {
            // Silent fail
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

    if (!empty($c) && $c !== 'N/A') {
        header("Location: tenant_login.php?c=" . urlencode($c));
    } else {
        header("Location: tenant_login.php");
    }
    exit();
}


if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient' || strtolower(trim($_SESSION['role'])) === 'staff') {
    header("Location: dashboard.php");
    exit();
}

require_once 'db.php';

if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

// Central pricing & duration map for the 3 subscription tiers.
if (!function_exists('mh_plan_info')) {
    function mh_plan_info($plan) {
        $p = strtolower(trim((string)$plan));
        if (in_array($p, ['semi-annual','semi annual','semiannual','semi'], true)) {
            return ['key' => 'Semi-Annual', 'label' => 'Semi-Annual', 'price' => 13499, 'days' => 180];
        }
        if (in_array($p, ['annual','yearly','year'], true)) {
            return ['key' => 'Annual',      'label' => 'Annual',      'price' => 24999, 'days' => 360];
        }
        return ['key' => 'Monthly', 'label' => 'Monthly', 'price' => 2499, 'days' => 30];
    }
}
// Plan tier ranking (used to enforce upgrade-only — no downgrades)
if (!function_exists('mh_plan_rank')) {
    function mh_plan_rank($planKey) {
        $k = mh_plan_info($planKey)['key'];
        return $k === 'Annual' ? 3 : ($k === 'Semi-Annual' ? 2 : 1);
    }
}

// EXPIRATION GUARD: kick out to expire.php if clinic subscription has lapsed
if (!function_exists('expire_one_clinic_and_notify')) {
    function expire_one_clinic_and_notify($pdo, $tenantId) {
        try {
            $upd = $pdo->prepare("UPDATE tenants SET status = 'Expired' WHERE TenantID = ? AND status = 'Active' AND expires_at IS NOT NULL AND expires_at < NOW()");
            $upd->execute([$tenantId]);
            if ($upd->rowCount() < 1) { return; } // not flipped this time -> don't email
            $info = $pdo->prepare("SELECT t.clinic_name,
                       (SELECT email      FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_email,
                       (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_fname
                FROM tenants t WHERE t.TenantID = ? LIMIT 1");
            $info->execute([$tenantId]);
            $r = $info->fetch(PDO::FETCH_ASSOC);
            // AUDIT LOG: subscription expired (System)
            if ($r) {
                try {
                    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, 'System Action', 'System', 'Subscription Expired', ?, 'System Auto-Expire', NOW())");
                    $auditStmt->execute([$tenantId, 'Subscription for "' . $r['clinic_name'] . '" automatically expired. Clinic access paused pending renewal.']);
                } catch (PDOException $e) { /* silent */ }
            }
            if ($r && !empty($r['owner_email'])) {
                $sender   = 'MaternityHub System <maternityhub@alwaysdata.net>';
                $headers  = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $sender\r\nReply-To: maternityhub@alwaysdata.net\r\nX-Mailer: PHP/" . phpversion();
                $subject  = "MaternityHub: Your Clinic Subscription Has Expired";
                $body = "<p>Hi <strong>" . htmlspecialchars($r['owner_fname']) . "</strong>,</p>"
                      . "<p>Your <strong>MaternityHub</strong> subscription for <strong>" . htmlspecialchars($r['clinic_name']) . "</strong> has expired.</p>"
                      . "<p>Please <a href='https://maternityhub.alwaysdata.net/registration.php'>log in</a> to renew and restore access to your clinic portal.</p>"
                      . "<p>— MaternityHub Team</p>";
                @mail($r['owner_email'], $subject, $body, $headers);
            }
        } catch (PDOException $e) { /* silent */ }
    }
}
if (!empty($_SESSION['TenantID'])) {
    try {
        // Auto-flip if past expires_at (and notify owner once)
        expire_one_clinic_and_notify($pdo, $_SESSION['TenantID']);
        $stmtExpGuard = $pdo->prepare("SELECT status FROM tenants WHERE TenantID = ? LIMIT 1");
        $stmtExpGuard->execute([$_SESSION['TenantID']]);
        if ((string)$stmtExpGuard->fetchColumn() === 'Expired') {
            header("Location: expire.php");
            exit();
        }
    } catch (PDOException $e) { /* silent */ }
}

$displayName = $_SESSION['full_name'] ?? 'Admin';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$displayRole = $userRole;
$normalizedRole = strtolower(trim((string)$userRole));
$isStaffRole = ($normalizedRole === 'staff');
$activeRoleLabel = $isStaffRole ? 'Active Staff' : 'Active Admin';
$roleModeLabel = $isStaffRole ? 'Staff Mode' : 'Administrator Mode';
$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null;
$msgError    = null;

// --- HANDLE PORTAL CUSTOMIZATION (WHITE-LABELING) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'customize_portal') {
    $newThemeColor = trim($_POST['theme_color']);
    $newHeroHeadline = trim($_POST['hero_headline']);
    $newHeroSubtitle = trim($_POST['hero_subtitle']);
    $newAboutText = trim($_POST['about_text']);
    $newOpeningTime = trim($_POST['opening_time']);
    $newClosingTime = trim($_POST['closing_time']);

    // LOGO UPLOAD LOGIC
    $logo_name = null;
    if (isset($_FILES['clinic_logo']) && $_FILES['clinic_logo']['error'] == 0) {
        $logo_name = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['clinic_logo']['name']);
        if (!is_dir('uploads/logos/')) { mkdir('uploads/logos/', 0777, true); }
        move_uploaded_file($_FILES['clinic_logo']['tmp_name'], 'uploads/logos/' . $logo_name);
    }

    try {
        // UPDATE QUERY: Kasama na ang opening_time at closing_time
        if ($logo_name) {
            $stmtCustomize = $pdo->prepare("UPDATE tenants SET theme_color = ?, hero_headline = ?, hero_subtitle = ?, about_text = ?, opening_time = ?, closing_time = ?, clinic_logo = ? WHERE TenantID = ?");
            $success = $stmtCustomize->execute([$newThemeColor, $newHeroHeadline, $newHeroSubtitle, $newAboutText, $newOpeningTime, $newClosingTime, $logo_name, $tenant_id]);
        } else {
            $stmtCustomize = $pdo->prepare("UPDATE tenants SET theme_color = ?, hero_headline = ?, hero_subtitle = ?, about_text = ?, opening_time = ?, closing_time = ? WHERE TenantID = ?");
            $success = $stmtCustomize->execute([$newThemeColor, $newHeroHeadline, $newHeroSubtitle, $newAboutText, $newOpeningTime, $newClosingTime, $tenant_id]);
        }

        if ($success) {
            header("Location: tenantsettings.php?msg=PortalUpdated");
            exit();
        } else {
            $msgError = "Failed to update portal settings.";
        }
    } catch (PDOException $e) {
        $msgError = "Database Error: " . $e->getMessage();
    }
}

// ===========================================================
// PLAN UPGRADE FLOW (Upgrade-only — Monthly < Semi-Annual < Annual)
// ===========================================================
$upgradeFlash = null; $upgradeFlashType = 'success';

// (A) Return from PayMongo upgrade checkout — finalize the upgrade
if (isset($_GET['upgrade']) && $_GET['upgrade'] === 'success' && !empty($_SESSION['pending_upgrade']) && $tenant_id) {
    $pu = $_SESSION['pending_upgrade'];
    if (($pu['tenant_id'] ?? null) == $tenant_id) {
        $newInfo  = mh_plan_info($pu['target_plan'] ?? '');
        $newKey   = $newInfo['key'];
        $newDays  = (int)$newInfo['days'];
        $newPrice = (int)$newInfo['price'];

        try {
            // Extend from current expires_at (if still in the future), so the user does not lose remaining time
            $stmtUp = $pdo->prepare("UPDATE tenants
                                     SET plan = ?, status = 'Active',
                                         expires_at = DATE_ADD(GREATEST(NOW(), COALESCE(expires_at, NOW())), INTERVAL $newDays DAY)
                                     WHERE TenantID = ?");
            $stmtUp->execute([$newKey, $tenant_id]);
        } catch (PDOException $e) { /* silent */ }

        // SALES LEDGER entry
        try {
            $payerName = trim(($_SESSION['full_name'] ?? '') ?: 'Clinic User');
            $stmtPay = $pdo->prepare("INSERT INTO subscription_payments (TenantID, user_id, payer_name, plan, amount, payment_type, paid_at) VALUES (?, ?, ?, ?, ?, 'upgrade', NOW())");
            $stmtPay->execute([$tenant_id, $userId, $payerName, $newInfo['label'], $newPrice]);
        } catch (PDOException $e) { /* silent */ }

        // AUDIT LOG
        try {
            $payerName = trim(($_SESSION['full_name'] ?? '') ?: 'Clinic User');
            $payerRole = $_SESSION['role'] ?? 'Admin';
            $fromLabel = mh_plan_info($pu['from_plan'] ?? '')['label'];
            $details = $payerName . ' upgraded the clinic subscription from ' . $fromLabel . ' to ' . $newInfo['label']
                     . ' via PayMongo. Amount paid: ₱' . number_format($newPrice, 2)
                     . '. Subscription extended by ' . $newDays . ' days.';
            $stmtAud = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Subscription Upgraded', ?, ?, NOW())");
            $stmtAud->execute([$tenant_id, $payerName, $payerRole, $details, ($_SERVER['REMOTE_ADDR'] ?? 'Unknown')]);
        } catch (PDOException $e) { /* silent */ }

        unset($_SESSION['pending_upgrade']);
        header("Location: dashboard.php?upgraded=1");
        exit();
    }
    unset($_SESSION['pending_upgrade']);
}

if (isset($_GET['upgraded']) && $_GET['upgraded'] === '1') {
    $upgradeFlash = "Subscription upgraded successfully! Your new plan is now active.";
    $upgradeFlashType = 'success';
} elseif (isset($_GET['upgrade']) && $_GET['upgrade'] === 'cancel') {
    $upgradeFlash = "Plan upgrade was cancelled.";
    $upgradeFlashType = 'error';
    unset($_SESSION['pending_upgrade']);
}

// (B) Submit upgrade — start PayMongo checkout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upgrade_plan' && $tenant_id) {
    if (($_SESSION['role'] ?? '') === 'Patient' || strtolower(trim($_SESSION['role'] ?? '')) === 'staff') {
        $upgradeFlash = "Only clinic admins can change the subscription plan.";
        $upgradeFlashType = 'error';
    } else {
        $targetPlanRaw = $_POST['target_plan'] ?? '';
        $targetInfo    = mh_plan_info($targetPlanRaw);
        $targetKey     = $targetInfo['key'];

        // Read current plan from DB
        $currentKey = 'Monthly';
        try {
            $stmtCp = $pdo->prepare("SELECT plan FROM tenants WHERE TenantID = ? LIMIT 1");
            $stmtCp->execute([$tenant_id]);
            $currentKey = mh_plan_info((string)$stmtCp->fetchColumn())['key'];
        } catch (PDOException $e) {}

        if (mh_plan_rank($targetKey) <= mh_plan_rank($currentKey)) {
            $upgradeFlash = "You can only upgrade to a higher-tier plan, not downgrade or stay on the same plan.";
            $upgradeFlashType = 'error';
        } else {
            // Stash pending upgrade so we can finalize on PayMongo success callback
            $_SESSION['pending_upgrade'] = [
                'tenant_id'   => $tenant_id,
                'user_id'     => $userId,
                'from_plan'   => $currentKey,
                'target_plan' => $targetKey,
                'amount'      => (int)$targetInfo['price'],
                'created_at'  => time(),
            ];

            $paymongoSecretKey = '';
            $protocol  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $base      = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);
            $successUrl = $base . '/dashboard.php?upgrade=success';
            $cancelUrl  = $base . '/dashboard.php?upgrade=cancel';

            $payload = [
                'data' => [
                    'attributes' => [
                        'send_email_receipt' => true,
                        'show_description'   => true,
                        'show_line_items'    => true,
                        'description'        => 'MaternityHub ' . $targetInfo['label'] . ' Plan Upgrade',
                        'line_items'         => [[
                            'name'     => 'MaternityHub ' . $targetInfo['label'] . ' Upgrade (' . $targetInfo['days'] . ' days)',
                            'amount'   => (int)$targetInfo['price'] * 100,
                            'currency' => 'PHP',
                            'quantity' => 1,
                        ]],
                        'payment_method_types' => ['gcash', 'paymaya', 'card'],
                        'success_url' => $successUrl,
                        'cancel_url'  => $cancelUrl,
                    ]
                ]
            ];

            $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($paymongoSecretKey . ':')
            ]);
            $response = curl_exec($ch);
            $errCurl  = curl_error($ch);
            curl_close($ch);

            if ($errCurl) {
                $upgradeFlash = "Unable to start upgrade payment: $errCurl";
                $upgradeFlashType = 'error';
                unset($_SESSION['pending_upgrade']);
            } else {
                $rd = json_decode($response, true);
                if (!empty($rd['data']['attributes']['checkout_url'])) {
                    header("Location: " . $rd['data']['attributes']['checkout_url']);
                    exit();
                }
                $upgradeFlash = "PayMongo error: " . ($rd['errors'][0]['detail'] ?? 'Unknown error.');
                $upgradeFlashType = 'error';
                unset($_SESSION['pending_upgrade']);
            }
        }
    }
}

// --- FETCH CLINIC NAME, CODE, LOGO & CUSTOMIZATIONS ---
$clinicName = "MaternityHub";
$clinicCode = "N/A";
$clinicLogo = null;
$themeColor = "#15803d"; // Default Green
$clinicPlan = null;
$clinicExpiresAt = null;
$clinicDaysLeft = null; // null = unknown / no expiry on file

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color, plan, expires_at FROM tenants WHERE TenantID = ?");
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
            if (!empty($clinicData['theme_color'])) $themeColor = $clinicData['theme_color'];
            if (!empty($clinicData['plan'])) $clinicPlan = $clinicData['plan'];
            if (!empty($clinicData['expires_at']) && $clinicData['expires_at'] !== '0000-00-00 00:00:00') {
                $clinicExpiresAt = $clinicData['expires_at'];
                try {
                    $now    = new DateTime('now');
                    $expDt  = new DateTime($clinicExpiresAt);
                    // Compare by date so "today" shows 0 days, not partial-hour negative
                    $nowD   = new DateTime($now->format('Y-m-d'));
                    $expD   = new DateTime($expDt->format('Y-m-d'));
                    $diff   = (int)$nowD->diff($expD)->format('%r%a'); // signed days
                    $clinicDaysLeft = $diff;
                } catch (Exception $eDt) { $clinicDaysLeft = null; }
            }
        }
    } catch (PDOException $e) {}
}

// =========================================================
// ?? DYNAMIC CONTRAST CALCULATOR PARA SA HEADER & SIDEBAR ??
// =========================================================
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

// Dynamic Tailwind-like classes based on luminance
$headerTextPrimary = $isLightTheme ? "text-slate-900" : "text-white";
$headerTextSecondary = $isLightTheme ? "text-slate-700" : "text-primary-light";
$headerTextMuted = $isLightTheme ? "text-slate-400" : "text-white/50";
$headerBadgeBg = $isLightTheme ? "bg-slate-200 text-slate-800" : "bg-black/20 text-white";
$headerIconBox = $isLightTheme ? "bg-white border border-slate-200" : "bg-white/15 border border-white/25";
$headerIconColor = $isLightTheme ? "text-slate-700" : "text-white/90";
$headerBtn = $isLightTheme ? "bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm" : "bg-white/15 hover:bg-white/25 text-white border-white/30";

// --- GENERATE THE UNIQUE CLINIC PORTAL LINK ---
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$domain = $_SERVER['HTTP_HOST'];
$currentPath = $_SERVER['PHP_SELF'];
$directory = dirname($currentPath);
$base_url = $protocol . "://" . $domain . ($directory === DIRECTORY_SEPARATOR ? "" : $directory);

$isLinkValid = ($clinicCode !== "N/A" && !empty($clinicCode));
$clinicPortalLink = $isLinkValid
    ? $base_url . "/ClinicHomepage.php?c=" . urlencode($clinicCode)
    : "No Clinic Code Assigned";

// --- PROFILE PICTURE UPLOAD LOGIC ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['profile_pic'])) {
    $file = $_FILES['profile_pic'];
    if ($file['error'] == 0) {
        $upload_dir = __DIR__ . '/uploads/profiles/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($ext, $allowed)) {
            if ($file['size'] <= 5000000) {
                $filename = 'profile_' . $userId . '_' . time() . '.' . $ext;
                $target_file = $upload_dir . $filename;
                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    try {
                        $stmtUpdate = $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?");
                        if ($stmtUpdate->execute([$filename, $userId])) {
                            header("Location: dashboard.php?msg=ProfileUpdated"); exit();
                        } else { $msgError = "Database update failed."; }
                    } catch (PDOException $e) { $msgError = "SQL Error: " . $e->getMessage(); }
                } else { $msgError = "Failed to move uploaded file."; }
            } else { $msgError = "File is too large. Maximum size is 5MB."; }
        } else { $msgError = "Invalid image format."; }
    } else { $msgError = "Upload error code: " . $file['error']; }
}

// FETCH CURRENT PROFILE PICTURE & FULL NAME
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

// =========================================================================
// BACKEND QUERIES
// =========================================================================
$countToday = 0; $countPatients = 0; $countInfants = 0; $countInfantsMale = 0; $countInfantsFemale = 0; $countStaff = 0; $revenueThisMonth = 0.0; $countAdmittedToday = 0;
try {
    $today = date('Y-m-d');
    $stmt1 = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND TenantID = ?");
    $stmt1->execute([$today, $tenant_id]);
    $countToday = $stmt1->fetchColumn() ?: 0;
} catch (PDOException $e) {}

try {
    $stmtAdmToday = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE TenantID = ? AND status != 'Discharged' AND stage != 'Discharged' AND (is_archived = 0 OR is_archived IS NULL)");
    $stmtAdmToday->execute([$tenant_id]);
    $countAdmittedToday = $stmtAdmToday->fetchColumn() ?: 0;
} catch (PDOException $e) {}

try {
    $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE TenantID = ?");
    $stmt2->execute([$tenant_id]);
    $countPatients = $stmt2->fetchColumn() ?: 0;
} catch (PDOException $e) {}

try {
    $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM infants WHERE TenantID = ?");
    $stmt3->execute([$tenant_id]);
    $countInfants = $stmt3->fetchColumn() ?: 0;
} catch (PDOException $e) {}

try {
    $stmt3m = $pdo->prepare("SELECT COUNT(*) FROM infants WHERE TenantID = ? AND LOWER(gender) IN ('male','boy')");
    $stmt3m->execute([$tenant_id]);
    $countInfantsMale = $stmt3m->fetchColumn() ?: 0;
} catch (PDOException $e) {}

try {
    $stmt3f = $pdo->prepare("SELECT COUNT(*) FROM infants WHERE TenantID = ? AND LOWER(gender) IN ('female','girl')");
    $stmt3f->execute([$tenant_id]);
    $countInfantsFemale = $stmt3f->fetchColumn() ?: 0;
} catch (PDOException $e) {}

try {
    $stmt4 = $pdo->prepare("SELECT COUNT(*) FROM users WHERE TenantID = ?");
    $stmt4->execute([$tenant_id]);
    $countStaff = $stmt4->fetchColumn() ?: 0;
} catch (PDOException $e) {}

// Total revenue for this month (Paid payments only)
try {
    $startOfMonth = date('Y-m-01 00:00:00');
    $endOfMonth = date('Y-m-t 23:59:59');
    $stmtRev = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE TenantID = ? AND status = 'Paid' AND payment_date BETWEEN ? AND ?");
    $stmtRev->execute([$tenant_id, $startOfMonth, $endOfMonth]);
    $revenueThisMonth = (float)($stmtRev->fetchColumn() ?: 0);
} catch (PDOException $e) {}

// Patient growth (last 6 months)
$userGrowthLabels = [];
$userGrowthValues = [];
$userGrowthHasDateSource = false;

for ($i = 5; $i >= 0; $i--) {
    $dt = (new DateTime('first day of this month'))->modify("-{$i} month");
    $userGrowthLabels[] = $dt->format('M Y');
    $userGrowthValues[] = 0;
}

try {
    $stmtUserDateCol = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'patients' AND COLUMN_NAME IN ('created_at', 'created_on', 'date_created', 'registered_at', 'registration_date') ORDER BY FIELD(COLUMN_NAME, 'created_at', 'created_on', 'date_created', 'registered_at', 'registration_date') LIMIT 1");
    $userDateColumn = $stmtUserDateCol ? $stmtUserDateCol->fetchColumn() : null;

    if ($userDateColumn) {
        $userGrowthHasDateSource = true;
        $startWindow = (new DateTime('first day of this month'))->modify('-5 month')->format('Y-m-01 00:00:00');

        $stmtGrowth = $pdo->prepare("SELECT DATE_FORMAT({$userDateColumn}, '%Y-%m') AS ym, COUNT(*) AS total FROM patients WHERE TenantID = ? AND {$userDateColumn} IS NOT NULL AND {$userDateColumn} >= ? GROUP BY ym ORDER BY ym ASC");
        $stmtGrowth->execute([$tenant_id, $startWindow]);
        $rows = $stmtGrowth->fetchAll(PDO::FETCH_ASSOC);

        $growthMap = [];
        foreach ($rows as $r) {
            $ym = (string)($r['ym'] ?? '');
            if ($ym !== '') {
                $growthMap[$ym] = (int)($r['total'] ?? 0);
            }
        }

        foreach ($userGrowthLabels as $idx => $lbl) {
            $ymKey = DateTime::createFromFormat('M Y', $lbl)->format('Y-m');
            $userGrowthValues[$idx] = isset($growthMap[$ymKey]) ? (int)$growthMap[$ymKey] : 0;
        }
    }
} catch (PDOException $e) {}

// =========================================================================
// ANNOUNCEMENTS & BROADCAST LOGIC
// =========================================================================
$fetchLatestAnnouncement = function() use ($pdo, $tenant_id) {
    $result = ['hasBroadcast' => false, 'message' => '', 'id' => null];
    try {
        $stmtAnnouncementCol = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME IN ('TenantID', 'tenant_id') LIMIT 1");
        $announcementTenantColumn = $stmtAnnouncementCol ? $stmtAnnouncementCol->fetchColumn() : null;

        if ($announcementTenantColumn && !empty($tenant_id)) {
            $stmtAnnounce = $pdo->prepare("SELECT id, message FROM announcements WHERE is_active = 1 AND ({$announcementTenantColumn} = ? OR {$announcementTenantColumn} IS NULL) ORDER BY CASE WHEN {$announcementTenantColumn} = ? THEN 0 ELSE 1 END, id DESC LIMIT 1");
            $stmtAnnounce->execute([$tenant_id, $tenant_id]);
        } else {
            $stmtAnnounce = $pdo->query("SELECT id, message FROM announcements WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
        }

        if ($stmtAnnounce) {
            $announceData = $stmtAnnounce->fetch(PDO::FETCH_ASSOC);
            if ($announceData && !empty($announceData['message'])) {
                $result['hasBroadcast'] = true;
                $result['message'] = (string)$announceData['message'];
                $result['id'] = isset($announceData['id']) ? (int)$announceData['id'] : null;
            }
        }
    } catch (PDOException $e) {}

    return $result;
};

if (isset($_GET['ajax']) && $_GET['ajax'] === 'latest_announcement') {
    header('Content-Type: application/json');
    $latestAnnouncement = $fetchLatestAnnouncement();
    echo json_encode([
        'success' => true,
        'hasBroadcast' => $latestAnnouncement['hasBroadcast'],
        'latestBroadcast' => $latestAnnouncement['message'],
        'latestBroadcastId' => $latestAnnouncement['id']
    ]);
    exit;
}

// =========================================================================
// AJAX: delete an announcement from THIS clinic's view only.
//   - Own-clinic announcement  -> hard DELETE
//   - Platform-wide (TenantID IS NULL) -> insert per-tenant row in
//     `announcement_dismissals` so only this clinic stops seeing it.
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (($_POST['action'] ?? '') === 'delete_announcement'
        || (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_announcement'))) {

    // Prevent stray output / PHP notices from polluting the JSON body
    @ini_set('display_errors', '0');
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $isAjaxDelete = (
        (isset($_GET['ajax']) && $_GET['ajax'] === 'delete_announcement')
        || (strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest')
        || (strpos(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json') !== false)
    );

    if ($isAjaxDelete) {
        header('Content-Type: application/json; charset=utf-8');
    }

    $send = function($payload) use ($isAjaxDelete) {
        if ($isAjaxDelete) {
            echo json_encode($payload);
            exit;
        }
        $ok = !empty($payload['success']);
        $msg = trim((string)($payload['message'] ?? ''));
        if ($ok) {
            header('Location: dashboard.php?ann_deleted=1');
        } else {
            header('Location: dashboard.php?ann_err=' . urlencode($msg !== '' ? $msg : 'Delete failed.'));
        }
        exit;
    };

    $annId = intval($_POST['announcement_id'] ?? 0);
    if ($annId <= 0 || empty($tenant_id)) {
        $send(['success' => false, 'message' => 'Invalid request.']);
    }

    try {
        // Ensure per-tenant dismissals table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_dismissals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            TenantID VARCHAR(64) NOT NULL,
            dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ann_tenant (announcement_id, TenantID),
            KEY idx_tenant (TenantID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $stmtCol = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME IN ('TenantID', 'tenant_id') LIMIT 1");
        $tCol = $stmtCol ? $stmtCol->fetchColumn() : null;
        if (!$tCol) { $send(['success' => false, 'message' => 'Schema not supported.']); }

        $stmtOwn = $pdo->prepare("SELECT {$tCol} AS owner_tenant FROM announcements WHERE id = ? LIMIT 1");
        $stmtOwn->execute([$annId]);
        $row = $stmtOwn->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $send(['success' => false, 'message' => 'Announcement not found.']); }
        $owner = $row['owner_tenant'];

        if ($owner === null || $owner === '') {
            // Platform-wide: per-clinic dismissal only
            $stmtDis = $pdo->prepare("INSERT IGNORE INTO announcement_dismissals (announcement_id, TenantID) VALUES (?, ?)");
            $stmtDis->execute([$annId, $tenant_id]);
            $send(['success' => true]);
        } elseif ((string)$owner === (string)$tenant_id) {
            // Hard delete (owned by this clinic)
            $stmtDel = $pdo->prepare("DELETE FROM announcements WHERE id = ? AND {$tCol} = ?");
            $stmtDel->execute([$annId, $tenant_id]);
            $send(['success' => $stmtDel->rowCount() > 0]);
        } else {
            $send(['success' => false, 'message' => 'You cannot delete this announcement.']);
        }
    } catch (PDOException $e) {
        $send(['success' => false, 'message' => 'Database error.']);
    } catch (Throwable $e) {
        $send(['success' => false, 'message' => 'Server error.']);
    }
}

$hasBroadcast = false; $latestBroadcast = ""; $latestBroadcastId = null; $announcementHistory = [];
try {
    $latestAnnouncement = $fetchLatestAnnouncement();
    $hasBroadcast = (bool)$latestAnnouncement['hasBroadcast'];
    $latestBroadcast = (string)$latestAnnouncement['message'];
    $latestBroadcastId = $latestAnnouncement['id'];

    // Make sure dismissals table exists for the history filter below
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcement_dismissals (
            id INT AUTO_INCREMENT PRIMARY KEY,
            announcement_id INT NOT NULL,
            TenantID VARCHAR(64) NOT NULL,
            dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_ann_tenant (announcement_id, TenantID),
            KEY idx_tenant (TenantID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (PDOException $e) {}

    $stmtAnnouncementCol = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'announcements' AND COLUMN_NAME IN ('TenantID', 'tenant_id') LIMIT 1");
    $announcementTenantColumn = $stmtAnnouncementCol ? $stmtAnnouncementCol->fetchColumn() : null;

    if ($announcementTenantColumn && !empty($tenant_id)) {
        $stmtHistory = $pdo->prepare("SELECT a.id, a.message, a.type, a.is_active, a.{$announcementTenantColumn} AS TenantID, a.sender, a.created_at
            FROM announcements a
            WHERE (a.{$announcementTenantColumn} = ? OR a.{$announcementTenantColumn} IS NULL)
              AND NOT EXISTS (SELECT 1 FROM announcement_dismissals d WHERE d.announcement_id = a.id AND d.TenantID = ?)
            ORDER BY a.id DESC LIMIT 20");
        $stmtHistory->execute([$tenant_id, $tenant_id]);
    } else {
        $stmtHistory = $pdo->query("SELECT id, message, type, is_active, NULL AS TenantID, sender, created_at FROM announcements ORDER BY id DESC LIMIT 20");
    }
    if ($stmtHistory) $announcementHistory = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
// Sidebar active style based on theme brightness (match tenantsettings)
$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
if (!$currentUserIsOwner && $tenant_id) {
    try {
        $stmtCurAccess = $pdo->prepare("SELECT cs.is_admin, cs.granted_features FROM clinic_staff cs INNER JOIN users u ON LOWER(TRIM(cs.email_address)) = LOWER(TRIM(u.email)) WHERE cs.TenantID = ? AND u.id = ? LIMIT 1");
        $stmtCurAccess->execute([$tenant_id, $userId]);
        $curAccess = $stmtCurAccess->fetch(PDO::FETCH_ASSOC);
        if ($curAccess) {
            $currentUserIsStaffAdmin = (bool)($curAccess['is_admin'] ?? false);
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

// --- LOAD PLATFORM TERMS AND CONDITIONS (mula sa Super Admin global settings) ---
$termsAndConditions = '';
$globalSettingsFile = __DIR__ . '/maternityhub_settings.json';
if (file_exists($globalSettingsFile)) {
    $globalSettings = json_decode(file_get_contents($globalSettingsFile), true);
    $termsAndConditions = $globalSettings['terms_and_conditions'] ?? '';
}
if (trim($termsAndConditions) === '') {
    $termsAndConditions = "MATERNITYHUB - TERMS AND CONDITIONS\nLast Updated: May 2026\n\nThe full Terms and Conditions has not yet been configured by the Platform Owner. Please contact MaternityHub support.";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Dashboard - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
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
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        .profile-hover-overlay { opacity: 0; transition: opacity 0.3s ease; }
        .group:hover .profile-hover-overlay { opacity: 1; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

<?php if(isset($_GET['msg']) && $_GET['msg'] == 'ProfileUpdated'): ?>
<div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-primary p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
    <span class="material-symbols-outlined text-primary">check_circle</span>
    <p class="text-xs font-black text-slate-800">Profile Picture Updated!</p>
</div>
<script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 3000);</script>
<?php endif; ?>

<?php if($msgError): ?>
<div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-red-500 p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
    <span class="material-symbols-outlined text-red-500">error</span>
    <p class="text-xs font-black text-slate-800 tracking-tight"><?= htmlspecialchars($msgError) ?></p>
</div>
<script>setTimeout(() => { document.getElementById('alertMsg')?.remove(); }, 5000);</script>
<?php endif; ?>

<div id="loggingOutScreen" class="fixed inset-0 z-[110] hidden bg-white flex-col items-center justify-center">
    <div class="size-12 border-4 border-slate-200 border-t-primary rounded-full animate-spin mb-4"></div>
    <p class="font-bold text-slate-800 animate-pulse text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl text-center border border-slate-100">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Are you sure you want to log out?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2 rounded-xl font-bold text-slate-400 hover:bg-slate-100 text-[11px]">Cancel</button>
            <button onclick="confirmLogout()" class="flex-1 py-2 rounded-xl font-bold bg-red-500 text-white hover:bg-red-600 text-[11px] shadow-lg shadow-red-100">Logout</button>
        </div>
    </div>
</div>

<!-- TERMS AND CONDITIONS MODAL -->
<div id="termsModal" class="fixed inset-0 z-[300] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-md" onclick="if(event.target===this) closeTermsModal()">
    <div class="bg-white rounded-[2rem] w-full max-w-3xl shadow-2xl border border-slate-200 relative overflow-hidden flex flex-col max-h-[90vh]">
        <div class="absolute top-0 left-0 w-full h-2 bg-primary"></div>
        <div class="flex items-center justify-between gap-4 px-8 pt-8 pb-5 border-b border-slate-100">
            <div class="flex items-center gap-3">
                <div class="size-12 rounded-2xl bg-primary/10 text-primary flex items-center justify-center border border-primary/20 shrink-0">
                    <span class="material-symbols-outlined text-2xl">gavel</span>
                </div>
                <div>
                    <h3 class="text-xl font-black text-slate-900 leading-tight">Terms and Conditions</h3>
                    <p class="text-[11px] text-slate-400 font-bold uppercase tracking-widest">MaternityHub Platform Agreement</p>
                </div>
            </div>
            <button type="button" onclick="closeTermsModal()" class="size-10 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition-colors shrink-0" aria-label="Close">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="overflow-y-auto px-8 py-6 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap">
<?= htmlspecialchars($termsAndConditions) ?>
        </div>
        <div class="px-8 py-5 border-t border-slate-100 bg-slate-50 flex justify-end">
            <button type="button" onclick="closeTermsModal()" class="px-6 py-2.5 bg-primary text-white rounded-full font-bold text-xs uppercase tracking-widest hover:opacity-90 transition-opacity shadow-md">
                I Understand
            </button>
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
                <p class="<?= $headerBadgeBg ?> px-2 py-0.5 rounded text-[10px] font-black tracking-widest flex items-center gap-1">CODE: <?= htmlspecialchars($clinicCode) ?></p>
            </div>
        </div>
    </div>

    <div class="flex items-center gap-4 ml-auto">
        <div class="hidden sm:flex flex-col text-right justify-center <?= $headerTextPrimary ?>">
            <p class="text-sm font-bold leading-none"><?= htmlspecialchars($displayName) ?></p>
            <p class="<?= $headerTextSecondary ?> text-[9px] italic opacity-80 mt-1 uppercase tracking-tighter"><?= htmlspecialchars($displayRole) ?></p>
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

            <a href="dashboard.php" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl icon-filled">dashboard</span> <span>Dashboard</span>
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
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help &amp; Support</span>
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

    <main class="flex-1 overflow-y-auto p-8 relative z-10">
        <div class="max-w-6xl mx-auto space-y-8">

            <div class="bg-white rounded-[32px] border border-slate-200 p-6 flex items-center justify-between shadow-sm">
                <div class="flex items-center gap-6">
                    <form method="POST" enctype="multipart/form-data" id="profileForm">
                        <input type="file" name="profile_pic" id="profileInput" class="hidden" onchange="this.form.submit();">
                        <div onclick="document.getElementById('profileInput').click();" class="size-20 rounded-full border-4 border-slate-50 bg-cover bg-center shadow-md relative group cursor-pointer overflow-hidden" style="background-image: url('<?= htmlspecialchars($profilePic) ?>');">
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition-all">
                                <span class="material-symbols-outlined text-white">photo_camera</span>
                            </div>
                        </div>
                    </form>
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1"><?= $activeRoleLabel ?></p>
                        <h4 class="text-xl font-black text-slate-800 tracking-tight leading-none"><?= $displayName ?></h4>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="size-2 rounded-full bg-primary animate-pulse"></span>
                            <p class="text-[10px] font-black text-primary uppercase tracking-widest"><?= $roleModeLabel ?></p>
                            <span class="ml-2 bg-primary/10 text-primary-dark px-3 py-1 rounded-md text-[11px] font-black uppercase tracking-widest border border-primary/20">
                                Clinic Code: <?= htmlspecialchars($clinicCode) ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-[13px] font-black text-slate-800 uppercase"><?= date('F j, Y') ?></p>
                    <p class="text-[10px] font-bold text-primary uppercase tracking-widest mt-1"><?= date('l') ?></p>
                    <button type="button" onclick="openTermsModal()" class="mt-2 inline-flex items-center gap-1 text-[10px] font-bold text-slate-500 hover:text-primary transition-colors underline-offset-2 hover:underline">
                        <span class="material-symbols-outlined text-[13px]">gavel</span>
                        Terms and Conditions
                    </button>
                </div>
            </div>

            <div id="broadcastBanner" data-broadcast-id="<?= $latestBroadcastId !== null ? (int)$latestBroadcastId : '' ?>" data-broadcast-message="<?= htmlspecialchars($latestBroadcast) ?>" class="bg-amber-50 border border-amber-200 rounded-[2rem] p-6 shadow-sm flex items-center gap-4 relative overflow-hidden hidden">
                <div class="size-12 rounded-2xl bg-amber-100 text-amber-600 flex items-center justify-center shrink-0 border border-amber-200">
                    <span class="material-symbols-outlined text-2xl animate-pulse">campaign</span>
                </div>
                <div class="flex-1">
                    <p class="text-[10px] font-black text-amber-500 uppercase tracking-widest mb-1">Platform Announcement</p>
                    <p id="broadcastMessage" class="text-sm font-bold text-slate-800"><?= htmlspecialchars($latestBroadcast) ?></p>
                </div>
                <button onclick="dismissBroadcast()" class="text-amber-400 hover:text-amber-600"><span class="material-symbols-outlined">close</span></button>
            </div>

            <section class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">Hello, <?= htmlspecialchars($displayName) ?>!</h2>
                    <p class="text-slate-500 font-medium">Here's your clinic status for today.</p>
                </div>

                <?php if (!$isStaffRole): ?>
                <div class="flex items-stretch gap-3 w-full md:w-auto">
                    <div class="bg-white px-4 py-3 rounded-2xl border border-slate-200 shadow-sm flex-1 md:flex-none">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1.5">Your Public Clinic Link</p>
                        <div class="flex items-center gap-2">
                            <input type="text" id="clinicLinkInput" readonly value="<?= htmlspecialchars($clinicPortalLink) ?>" class="<?= $isLinkValid ? 'bg-slate-50' : 'bg-red-50 text-red-400' ?> border border-slate-100 rounded-lg px-3 py-1.5 text-xs font-medium outline-none w-56 md:w-64 truncate">

                            <?php if ($isLinkValid): ?>
                                <button onclick="copyClinicLink()" class="bg-primary hover:bg-primary-dark text-white px-3 py-1.5 rounded-lg font-bold text-xs transition-all flex items-center gap-1 shrink-0" title="Copy Link">
                                    <span class="material-symbols-outlined text-sm">content_copy</span>
                                </button>
                                <a href="<?= htmlspecialchars($clinicPortalLink) ?>&preview=1" target="_blank" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-1.5 rounded-lg font-bold text-xs transition-all flex items-center gap-1 shrink-0" title="Visit Portal (Preview)">
                                    <span class="material-symbols-outlined text-sm">visibility</span> Preview
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-red-500 font-bold px-2">Setup Required</span>
                            <?php endif; ?>
                        </div>
                        <p id="copyAlert" class="text-[10px] font-bold text-emerald-600 mt-1.5 hidden animate-pulse">Link copied to clipboard!</p>
                    </div>

                    <button type="button" id="announcementTriggerBtn" data-latest-id="<?= !empty($announcementHistory) ? (int)$announcementHistory[0]['id'] : 0 ?>" onclick="openAnnouncementModal()" class="relative bg-white hover:bg-amber-50 border border-slate-200 hover:border-amber-200 rounded-2xl px-4 py-3 shadow-sm transition-all flex flex-col items-center justify-center gap-1 group shrink-0" title="View Announcement History">
                        <span class="material-symbols-outlined text-amber-500 text-2xl group-hover:scale-110 transition-transform">campaign</span>
                        <span class="text-[10px] font-black text-slate-500 group-hover:text-amber-600 uppercase tracking-widest leading-none">Announcements</span>
                        <span id="announcementBadge" class="hidden absolute -top-1.5 -right-1.5 size-5 rounded-full bg-amber-500 text-white text-[10px] font-black items-center justify-center border-2 border-white shadow"></span>
                    </button>
                </div>
                <?php endif; ?>
            </section>

            <?php
                // ===== Subscription status card (Plan + days until expiration) =====
                $planLabel = $clinicPlan ? ucwords(strtolower($clinicPlan)) : 'No Plan On File';
                $daysLeft  = $clinicDaysLeft;

                if ($daysLeft === null) {
                    $subAccent = 'slate';
                    $subHeadline = 'No Expiration Date';
                    $subSub = 'Subscription expiry not yet recorded.';
                    $subIcon = 'help';
                } elseif ($daysLeft < 0) {
                    $subAccent = 'rose';
                    $subHeadline = 'Expired ' . abs($daysLeft) . ' day' . (abs($daysLeft) === 1 ? '' : 's') . ' ago';
                    $subSub = 'Renew now to restore full clinic access.';
                    $subIcon = 'error';
                } elseif ($daysLeft === 0) {
                    $subAccent = 'rose';
                    $subHeadline = 'Expires Today';
                    $subSub = 'Renew before end of day to avoid service interruption.';
                    $subIcon = 'warning';
                } elseif ($daysLeft <= 7) {
                    $subAccent = 'amber';
                    $subHeadline = $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' remaining';
                    $subSub = 'Your subscription is about to expire. Consider renewing soon.';
                    $subIcon = 'schedule';
                } else {
                    $subAccent = 'emerald';
                    $subHeadline = $daysLeft . ' days remaining';
                    $subSub = 'Your subscription is active and in good standing.';
                    $subIcon = 'verified';
                }

                $accentMap = [
                    'emerald' => ['bg'=>'bg-emerald-50','border'=>'border-emerald-200','text'=>'text-emerald-700','iconBg'=>'bg-emerald-100','badge'=>'bg-emerald-100 text-emerald-700 border-emerald-200'],
                    'amber'   => ['bg'=>'bg-amber-50','border'=>'border-amber-200','text'=>'text-amber-700','iconBg'=>'bg-amber-100','badge'=>'bg-amber-100 text-amber-700 border-amber-200'],
                    'rose'    => ['bg'=>'bg-rose-50','border'=>'border-rose-200','text'=>'text-rose-700','iconBg'=>'bg-rose-100','badge'=>'bg-rose-100 text-rose-700 border-rose-200'],
                    'slate'   => ['bg'=>'bg-slate-50','border'=>'border-slate-200','text'=>'text-slate-700','iconBg'=>'bg-slate-100','badge'=>'bg-slate-100 text-slate-700 border-slate-200'],
                ];
                $A = $accentMap[$subAccent];
            ?>
            <div class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center gap-5">
                    <div class="size-14 rounded-2xl <?= $A['iconBg'] ?> <?= $A['text'] ?> flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-3xl icon-filled"><?= $subIcon ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2 mb-1 flex-wrap">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Subscription Status</p>
                            <span class="inline-block px-2 py-0.5 rounded-md text-[10px] font-black uppercase tracking-widest border <?= $A['badge'] ?>"><?= htmlspecialchars($planLabel) ?> Plan</span>
                        </div>
                        <h3 class="text-2xl font-black <?= $A['text'] ?> tracking-tight leading-tight"><?= htmlspecialchars($subHeadline) ?></h3>
                        <p class="text-[12px] text-slate-500 font-medium mt-1"><?= htmlspecialchars($subSub) ?></p>
                        <?php if ($clinicExpiresAt): ?>
                            <p class="text-[11px] text-slate-400 font-bold mt-1.5 uppercase tracking-widest">
                                Valid until <?= date('M d, Y - h:i A', strtotime($clinicExpiresAt)) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <?php if (!$isStaffRole && $daysLeft !== null && $daysLeft <= 7): ?>
                        <a href="expire.php" class="bg-primary hover:bg-primary-dark text-white px-5 py-3 rounded-2xl font-black text-xs uppercase tracking-widest shadow-sm transition-all flex items-center gap-2 shrink-0">
                            <span class="material-symbols-outlined text-base">autorenew</span> Renew Now
                        </a>
                    <?php endif; ?>
                    <?php
                        // Upgrade button — only Admins, only when current plan is not already Annual
                        $currentPlanKey = mh_plan_info($clinicPlan ?? '')['key'];
                        $canUpgrade = (!$isStaffRole) && ($currentPlanKey !== 'Annual');
                    ?>
                    <?php if ($canUpgrade): ?>
                        <button type="button" onclick="openUpgradeModal()" class="bg-amber-500 hover:bg-amber-600 text-white px-5 py-3 rounded-2xl font-black text-xs uppercase tracking-widest shadow-sm transition-all flex items-center gap-2 shrink-0">
                            <span class="material-symbols-outlined text-base">trending_up</span> Upgrade Plan
                        </button>
                    <?php endif; ?>
                </div>
                <?php if (!empty($upgradeFlash)): ?>
                    <div class="mt-4 p-3 rounded-xl border <?= $upgradeFlashType === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : 'bg-red-50 border-red-200 text-red-700' ?> flex items-start gap-2 text-sm font-semibold">
                        <span class="material-symbols-outlined text-base"><?= $upgradeFlashType === 'success' ? 'check_circle' : 'error' ?></span>
                        <span><?= htmlspecialchars($upgradeFlash) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($canUpgrade ?? false): ?>
            <!-- UPGRADE PLAN MODAL -->
            <div id="upgradePlanModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
                <div class="bg-white rounded-[2rem] p-8 max-w-2xl w-full shadow-2xl border border-slate-100 max-h-[90vh] overflow-y-auto">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1">Subscription Upgrade</p>
                            <h3 class="text-2xl font-black text-slate-900 tracking-tight">Choose a Higher-Tier Plan</h3>
                        </div>
                        <button type="button" onclick="closeUpgradeModal()" class="size-9 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    <p class="text-sm text-slate-500 mb-6">
                        You're currently on the <strong class="text-slate-700"><?= htmlspecialchars($currentPlanKey) ?></strong> plan.
                        Upgrading is permanent — you cannot downgrade later. Any remaining days from your current plan will be carried over and added to the new plan's duration.
                    </p>

                    <?php
                        $tierOptions = [
                            ['key' => 'Monthly',     'label' => 'Monthly',     'price' => 2499,  'days' => 30,  'suffix' => '/month',    'badge' => null],
                            ['key' => 'Semi-Annual', 'label' => 'Semi-Annual', 'price' => 13499, 'days' => 180, 'suffix' => '/6 months', 'badge' => 'Save more'],
                            ['key' => 'Annual',      'label' => 'Annual',      'price' => 24999, 'days' => 360, 'suffix' => '/year',     'badge' => 'Best Value'],
                        ];
                        $currentRank = mh_plan_rank($currentPlanKey);
                    ?>
                    <form method="POST" id="upgradePlanForm">
                        <input type="hidden" name="action" value="upgrade_plan">
                        <input type="hidden" name="target_plan" id="upgrade_target_plan" value="">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
                            <?php foreach ($tierOptions as $opt):
                                $optRank = mh_plan_rank($opt['key']);
                                $isLocked = ($optRank <= $currentRank);
                            ?>
                                <div class="relative p-4 rounded-xl border-2 transition-all <?= $isLocked ? 'border-slate-200 bg-slate-50 opacity-60 cursor-not-allowed' : 'border-slate-200 bg-white hover:border-amber-400 cursor-pointer upgrade-tier-option' ?>"
                                     <?= $isLocked ? '' : 'data-plan="' . htmlspecialchars($opt['key']) . '"' ?>>
                                    <?php if (!empty($opt['badge']) && !$isLocked): ?>
                                        <span class="absolute -top-2 right-3 bg-amber-400 text-slate-900 text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full shadow"><?= htmlspecialchars($opt['badge']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($isLocked): ?>
                                        <span class="absolute top-2 right-2 material-symbols-outlined text-slate-400 text-base">lock</span>
                                    <?php endif; ?>
                                    <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-1"><?= $opt['days'] ?> Days</p>
                                    <p class="text-base font-black text-slate-900 leading-tight"><?= htmlspecialchars($opt['label']) ?></p>
                                    <p class="text-lg font-black text-emerald-700 mt-1">₱<?= number_format($opt['price']) ?><span class="text-[10px] text-slate-500 font-bold"><?= $opt['suffix'] ?></span></p>
                                    <?php if ($opt['key'] === $currentPlanKey): ?>
                                        <p class="text-[10px] text-slate-500 font-bold mt-2 uppercase tracking-widest">Current Plan</p>
                                    <?php elseif ($isLocked): ?>
                                        <p class="text-[10px] text-slate-500 font-bold mt-2 uppercase tracking-widest">Lower Tier</p>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="flex gap-3">
                            <button type="button" onclick="closeUpgradeModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs uppercase tracking-widest">Cancel</button>
                            <button type="submit" id="upgradeSubmitBtn" disabled class="flex-1 py-3 rounded-xl font-black text-white bg-amber-500 hover:bg-amber-600 transition-all text-xs uppercase tracking-widest shadow-md shadow-amber-500/30 disabled:opacity-50 disabled:cursor-not-allowed">
                                Continue to Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
                function openUpgradeModal() {
                    const m = document.getElementById('upgradePlanModal');
                    m.classList.remove('hidden'); m.classList.add('flex');
                }
                function closeUpgradeModal() {
                    const m = document.getElementById('upgradePlanModal');
                    m.classList.remove('flex'); m.classList.add('hidden');
                    document.getElementById('upgrade_target_plan').value = '';
                    document.getElementById('upgradeSubmitBtn').disabled = true;
                    document.querySelectorAll('.upgrade-tier-option').forEach(el => {
                        el.classList.remove('border-amber-500', 'bg-amber-50');
                        el.classList.add('border-slate-200', 'bg-white');
                    });
                }
                document.querySelectorAll('.upgrade-tier-option').forEach(el => {
                    el.addEventListener('click', () => {
                        const plan = el.getAttribute('data-plan');
                        document.getElementById('upgrade_target_plan').value = plan;
                        document.querySelectorAll('.upgrade-tier-option').forEach(o => {
                            o.classList.remove('border-amber-500', 'bg-amber-50');
                            o.classList.add('border-slate-200', 'bg-white');
                        });
                        el.classList.remove('border-slate-200', 'bg-white');
                        el.classList.add('border-amber-500', 'bg-amber-50');
                        document.getElementById('upgradeSubmitBtn').disabled = false;
                    });
                });
                document.getElementById('upgradePlanModal').addEventListener('click', (e) => {
                    if (e.target.id === 'upgradePlanModal') closeUpgradeModal();
                });
            </script>
            <?php endif; ?>

            <div class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm">
                <div class="flex items-start justify-between gap-3 mb-4">
                    <div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Analytics</p>
                        <h3 class="text-lg font-black text-slate-800 tracking-tight">Patient Growth (Last 6 Months)</h3>
                        <p class="text-[11px] text-slate-500 font-medium">
                            <?= $userGrowthHasDateSource ? 'Monthly new patient records for your clinic.' : 'No patient date column found; chart is on standby until created_at is available.' ?>
                        </p>
                    </div>
                    <div class="shrink-0 px-3 py-1.5 rounded-xl bg-blue-50 border border-blue-100 text-blue-700 text-xs font-black">
                        Total Patients: <?= number_format($countPatients) ?>
                    </div>
                </div>
                <div class="h-[280px]">
                    <canvas id="userGrowthChart"></canvas>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div onclick="window.location.href='appointments.php'" class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm hover:border-primary transition-all cursor-pointer group">
                    <div class="size-12 rounded-2xl bg-primary/10 text-primary flex items-center justify-center mb-4"><span class="material-symbols-outlined icon-filled">calendar_today</span></div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Visits Today</p>
                    <h3 class="text-4xl font-black text-slate-800 tracking-tighter"><?= number_format($countToday) ?></h3>
                </div>
                <div onclick="window.location.href='patientrecords.php'" class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm hover:border-blue-500 transition-all cursor-pointer group">
                    <div class="size-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-4"><span class="material-symbols-outlined icon-filled">folder_shared</span></div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Total Patients</p>
                    <h3 class="text-4xl font-black text-slate-800 tracking-tighter"><?= number_format($countPatients) ?></h3>
                </div>
                <div onclick="window.location.href='patientrecords.php'" class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm hover:border-pink-500 transition-all cursor-pointer group">
                    <div class="size-12 rounded-2xl bg-pink-50 text-pink-600 flex items-center justify-center mb-4"><span class="material-symbols-outlined icon-filled">child_care</span></div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Total Infants</p>
                    <h3 class="text-4xl font-black text-slate-800 tracking-tighter"><?= number_format($countInfants) ?></h3>
                    <p class="mt-1 text-[10px] text-slate-500 font-bold uppercase tracking-widest flex items-center gap-2">
                        <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[12px] text-blue-500">male</span> Boys: <span class="text-slate-700"><?= number_format($countInfantsMale) ?></span></span>
                        <span class="text-slate-300">|</span>
                        <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[12px] text-pink-500">female</span> Girls: <span class="text-slate-700"><?= number_format($countInfantsFemale) ?></span></span>
                    </p>
                </div>
                <div onclick="window.location.href='admissions.php'" class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm hover:border-violet-500 transition-all cursor-pointer group">
                    <div class="size-12 rounded-2xl bg-violet-50 text-violet-600 flex items-center justify-center mb-4"><span class="material-symbols-outlined icon-filled">hotel</span></div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Current Admissions</p>
                    <h3 class="text-4xl font-black text-slate-800 tracking-tighter"><?= number_format($countAdmittedToday) ?></h3>
                    <p class="mt-1 text-[10px] text-slate-400 font-bold uppercase tracking-widest"></p>
                </div>
                <div onclick="window.location.href='staffmanagement.php'" class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm hover:border-amber-500 transition-all cursor-pointer group">
                    <div class="size-12 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-4"><span class="material-symbols-outlined icon-filled">badge</span></div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Accounts</p>
                    <h3 class="text-4xl font-black text-slate-800 tracking-tighter"><?= number_format($countStaff) ?></h3>
                </div>
                <div onclick="window.location.href='financials.php'" class="bg-white rounded-[32px] border border-slate-200 p-6 shadow-sm hover:border-emerald-500 transition-all cursor-pointer group">
                    <div class="size-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-4"><span class="material-symbols-outlined icon-filled">payments</span></div>
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Revenue This Month</p>
                    <h3 class="text-3xl font-black text-slate-800 tracking-tighter">&#8369; <?= number_format($revenueThisMonth, 2) ?></h3>
                    <p class="mt-1 text-[10px] text-slate-400 font-bold uppercase tracking-widest">Based on Paid payments</p>
                </div>
            </div>

            <!-- ===================== ANNOUNCEMENT HISTORY MODAL ===================== -->
            <div id="announcementModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/50 backdrop-blur-sm opacity-0 transition-opacity duration-200 ease-out" onclick="if(event.target===this)closeAnnouncementModal()">
                <div id="announcementModalPanel" class="bg-white rounded-[2rem] w-full max-w-2xl max-h-[85vh] shadow-2xl border border-slate-100 flex flex-col overflow-hidden transform opacity-0 scale-95 translate-y-2 transition-all duration-200 ease-out">
                    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 shrink-0">
                        <div class="flex items-center gap-3">
                            <div class="size-11 rounded-2xl bg-amber-50 text-amber-500 flex items-center justify-center border border-amber-100">
                                <span class="material-symbols-outlined">campaign</span>
                            </div>
                            <div>
                                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">From SuperAdmin</p>
                                <h3 class="text-base font-black text-slate-800 tracking-tight leading-none">Announcement History</h3>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="announcementRecordCount" class="bg-amber-50 text-amber-600 border border-amber-100 text-[10px] font-black uppercase tracking-widest px-3 py-1.5 rounded-xl">
                                <?= count($announcementHistory) ?> record<?= count($announcementHistory) !== 1 ? 's' : '' ?>
                            </span>
                            <button onclick="closeAnnouncementModal()" class="size-9 rounded-xl hover:bg-slate-100 text-slate-500 flex items-center justify-center transition-colors">
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>
                    </div>

                    <div id="announcementEmptyState" class="<?= empty($announcementHistory) ? 'flex' : 'hidden' ?> flex-col items-center justify-center text-center px-6" style="height: 520px;">
                        <div class="size-16 rounded-3xl bg-slate-50 text-slate-300 flex items-center justify-center mb-4 border border-slate-100">
                            <span class="material-symbols-outlined text-4xl">notifications_off</span>
                        </div>
                        <p class="text-slate-400 font-bold text-sm">No announcements yet</p>
                        <p class="text-slate-300 text-[11px] font-medium mt-1">Announcements from the platform will appear here.</p>
                    </div>
                    <div id="announcementList" class="<?= empty($announcementHistory) ? 'hidden' : '' ?> overflow-y-auto divide-y divide-slate-50" style="height: 520px;">
                        <?php foreach ($announcementHistory as $ann):
                            $isAllClinics = ($ann['TenantID'] === null || $ann['TenantID'] === '');
                            $annDate = !empty($ann['created_at']) ? date('M j, Y \a\t g:i A', strtotime($ann['created_at'])) : 'Unknown date';
                            $annSender = trim((string)($ann['sender'] ?? ''));
                            if ($annSender === '') $annSender = 'Super Admin';
                        ?>
                        <div class="flex items-start gap-4 px-6 py-4 hover:bg-slate-50/60 transition-colors" data-ann-row="<?= (int)$ann['id'] ?>">
                            <div class="shrink-0 mt-0.5">
                                <?php if ($isAllClinics): ?>
                                    <span class="inline-flex items-center gap-1 bg-violet-50 text-violet-600 border border-violet-100 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-lg">
                                        <span class="material-symbols-outlined text-[12px]">public</span> All Clinics
                                    </span>
                                <?php else: ?>
                                    <span class="inline-flex items-center gap-1 bg-primary/5 text-primary border border-primary/10 text-[10px] font-black uppercase tracking-widest px-2.5 py-1 rounded-lg">
                                        <span class="material-symbols-outlined text-[12px]">business</span> Your Clinic
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-slate-700 leading-snug">
                                    <?= htmlspecialchars($ann['message']) ?>
                                </p>
                                <div class="flex items-center gap-3 mt-1.5 flex-wrap">
                                    <span class="text-[10px] text-slate-500 font-bold flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[11px] text-amber-500">shield_person</span>
                                        <?= htmlspecialchars($annSender) ?>
                                    </span>
                                    <span class="text-slate-200 text-[10px]">|</span>
                                    <span class="text-[10px] text-slate-400 font-medium flex items-center gap-1">
                                        <span class="material-symbols-outlined text-[11px]">schedule</span>
                                        <?= htmlspecialchars($annDate) ?>
                                    </span>
                                </div>
                            </div>
                            <div class="shrink-0">
                                <button type="button"
                                        onclick="openDeleteAnnouncementModal(<?= (int)$ann['id'] ?>, <?= htmlspecialchars(json_encode($ann['message']), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg text-rose-500 hover:text-white hover:bg-rose-500 border border-rose-100 hover:border-rose-500 transition-colors"
                                        title="Delete announcement">
                                    <span class="material-symbols-outlined text-[18px]">delete</span>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <!-- ===================================================================== -->

        </div>
    </main>
</div>

<form id="deleteAnnouncementFallbackForm" method="POST" class="hidden" aria-hidden="true">
    <input type="hidden" name="action" value="delete_announcement">
    <input type="hidden" id="deleteAnnouncementFallbackId" name="announcement_id" value="">
</form>

<script>
    const userGrowthLabels = <?= json_encode($userGrowthLabels) ?>;
    const userGrowthValues = <?= json_encode($userGrowthValues) ?>;

    function copyClinicLink() {
        var copyText = document.getElementById("clinicLinkInput");
        if (copyText.value === "No Clinic Code Assigned") return;
        copyText.select();
        navigator.clipboard.writeText(copyText.value);

        var alertMsg = document.getElementById("copyAlert");
        alertMsg.classList.remove("hidden");
        setTimeout(() => { alertMsg.classList.add("hidden"); }, 2000);
    }

    // --- PERSISTENT BROADCAST DISMISSAL via localStorage ---
    // Stores the ID of the last banner the user manually dismissed.
    // Any incoming announcement with a HIGHER id is treated as new and will show.
    const MH_DISMISSED_BANNER_KEY = 'mh_dismissed_banner_id';

    function getLastDismissedBannerId() {
        return parseInt(localStorage.getItem(MH_DISMISSED_BANNER_KEY) || '0', 10) || 0;
    }

    let broadcastAutoHideTimer = null;

    function startBroadcastAutoHide() {
        if (broadcastAutoHideTimer) clearTimeout(broadcastAutoHideTimer);
        broadcastAutoHideTimer = setTimeout(() => { dismissBroadcast(); }, 600000); // 10 minutes
    }

    function dismissBroadcast() {
        const banner = document.getElementById('broadcastBanner');
        if (!banner) return;
        const currentId = parseInt(banner.dataset.broadcastId || '0', 10) || 0;
        if (currentId > 0) localStorage.setItem(MH_DISMISSED_BANNER_KEY, String(currentId));
        banner.classList.add('hidden');
        if (broadcastAutoHideTimer) { clearTimeout(broadcastAutoHideTimer); broadcastAutoHideTimer = null; }
    }

    function renderBroadcast(data) {
        const banner = document.getElementById('broadcastBanner');
        const message = document.getElementById('broadcastMessage');
        if (!banner || !message) return;

        if (!data || !data.hasBroadcast || !data.latestBroadcast) {
            banner.dataset.broadcastId = '';
            banner.classList.add('hidden');
            if (broadcastAutoHideTimer) { clearTimeout(broadcastAutoHideTimer); broadcastAutoHideTimer = null; }
            return;
        }

        const incomingId = parseInt(data.latestBroadcastId || '0', 10) || 0;
        const lastDismissedId = getLastDismissedBannerId();

        banner.dataset.broadcastId = incomingId ? String(incomingId) : '';
        message.textContent = data.latestBroadcast;

        // Update the announcement-history button badge
        refreshAnnouncementBadge(incomingId);

        // Show ONLY if this announcement is newer than the last one the user dismissed
        if (incomingId > 0 && incomingId > lastDismissedId) {
            if (banner.classList.contains('hidden')) {
                banner.classList.remove('hidden');
            }
            startBroadcastAutoHide();
        } else {
            banner.classList.add('hidden');
        }
    }

    async function pollLatestAnnouncement() {
        try {
            const response = await fetch('?ajax=latest_announcement', { cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            if (!data || data.success !== true) return;
            renderBroadcast(data);
        } catch (e) {}
    }

    function openLogoutModal() { document.getElementById('logoutModal').classList.replace('hidden', 'flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.replace('flex', 'hidden'); }

    function openTermsModal() {
        const m = document.getElementById('termsModal');
        if (m) { m.classList.remove('hidden'); m.classList.add('flex'); document.body.style.overflow = 'hidden'; }
    }
    function closeTermsModal() {
        const m = document.getElementById('termsModal');
        if (m) { m.classList.add('hidden'); m.classList.remove('flex'); document.body.style.overflow = ''; }
    }

    let announcementsExpanded = false;
    function toggleAnnouncementHistory() {
        const extras = document.querySelectorAll('.announcement-extra');
        const icon = document.getElementById('toggleAnnouncementsIcon');
        const label = document.getElementById('toggleAnnouncementsLabel');
        announcementsExpanded = !announcementsExpanded;
        extras.forEach(el => el.classList.toggle('hidden', !announcementsExpanded));
        icon.textContent = announcementsExpanded ? 'expand_less' : 'expand_more';
        label.textContent = announcementsExpanded ? 'Show Less' : 'Show <?= count($announcementHistory) - 5 ?> More';
    }

    function openAnnouncementModal() {
        const m = document.getElementById('announcementModal');
        const p = document.getElementById('announcementModalPanel');
        if (!m) return;
        m.classList.remove('hidden');
        m.classList.add('flex');
        // Force a reflow so the initial (opacity-0 / scale-95) state is committed
        // BEFORE we transition to the visible state — guarantees a smooth animation.
        void m.offsetWidth;
        requestAnimationFrame(() => {
            m.classList.remove('opacity-0');
            m.classList.add('opacity-100');
            if (p) {
                p.classList.remove('opacity-0', 'scale-95', 'translate-y-2');
                p.classList.add('opacity-100', 'scale-100', 'translate-y-0');
            }
        });
        document.body.style.overflow = 'hidden';
        markAnnouncementsSeen();
    }
    function closeAnnouncementModal() {
        const m = document.getElementById('announcementModal');
        const p = document.getElementById('announcementModalPanel');
        if (!m) return;
        m.classList.remove('opacity-100');
        m.classList.add('opacity-0');
        if (p) {
            p.classList.remove('opacity-100', 'scale-100', 'translate-y-0');
            p.classList.add('opacity-0', 'scale-95', 'translate-y-2');
        }
        document.body.style.overflow = '';
        setTimeout(() => {
            m.classList.add('hidden');
            m.classList.remove('flex');
        }, 200);
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeAnnouncementModal(); closeTermsModal(); closeDeleteAnnouncementModal(); }
    });

    // ===== Delete-announcement confirmation flow =====
    let _pendingDeleteAnnId = null;
    function openDeleteAnnouncementModal(annId, message) {
        _pendingDeleteAnnId = annId;
        const preview = document.getElementById('deleteAnnPreview');
        if (preview) preview.textContent = (message || '').toString();
        const errBox = document.getElementById('deleteAnnError');
        if (errBox) { errBox.classList.add('hidden'); errBox.textContent = ''; }
        const btn = document.getElementById('confirmDeleteAnnBtn');
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<span class="material-symbols-outlined text-[18px]">delete</span><span>Delete</span>';
        }
        const m = document.getElementById('deleteAnnouncementModal');
        if (m) { m.classList.remove('hidden'); m.classList.add('flex'); }
    }
    function closeDeleteAnnouncementModal() {
        const m = document.getElementById('deleteAnnouncementModal');
        if (m) { m.classList.remove('flex'); m.classList.add('hidden'); }
        _pendingDeleteAnnId = null;
    }
    function refreshAnnouncementHistoryUI() {
        const list = document.getElementById('announcementList');
        const empty = document.getElementById('announcementEmptyState');
        const countEl = document.getElementById('announcementRecordCount');
        const remaining = list ? list.querySelectorAll('[data-ann-row]').length : 0;
        if (countEl) {
            countEl.textContent = remaining + ' record' + (remaining !== 1 ? 's' : '');
        }
        if (list && empty) {
            if (remaining === 0) {
                list.classList.add('hidden');
                empty.classList.remove('hidden');
                empty.classList.add('flex');
            } else {
                list.classList.remove('hidden');
                empty.classList.add('hidden');
                empty.classList.remove('flex');
            }
        }
    }
    function submitDeleteAnnouncementFallback() {
        if (!_pendingDeleteAnnId) return;
        const f = document.getElementById('deleteAnnouncementFallbackForm');
        const i = document.getElementById('deleteAnnouncementFallbackId');
        if (!f || !i) return;
        i.value = String(_pendingDeleteAnnId);
        f.submit();
    }
    async function confirmDeleteAnnouncement() {
        if (!_pendingDeleteAnnId) return;
        const btn = document.getElementById('confirmDeleteAnnBtn');
        const errBox = document.getElementById('deleteAnnError');
        const idleHtml = '<span class="material-symbols-outlined text-[18px]">delete</span><span>Delete</span>';
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<span class="material-symbols-outlined text-[18px] animate-spin">progress_activity</span><span>Deleting…</span>';
        }
        try {
            const fd = new FormData();
            fd.append('action', 'delete_announcement');
            fd.append('announcement_id', String(_pendingDeleteAnnId));
            const res = await fetch(window.location.pathname + '?ajax=delete_announcement&_=' + Date.now(), {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
            });
            const txt = await res.text();
            let data = null;
            try { data = JSON.parse(txt); } catch (_) {}
            if (data && data.success) {
                const row = document.querySelector('[data-ann-row="' + _pendingDeleteAnnId + '"]');
                if (row) {
                    row.style.transition = 'opacity .2s ease, transform .2s ease';
                    row.style.opacity = '0';
                    row.style.transform = 'translateX(8px)';
                    setTimeout(() => {
                        row.remove();
                        refreshAnnouncementHistoryUI();
                    }, 200);
                } else {
                    refreshAnnouncementHistoryUI();
                }
                closeDeleteAnnouncementModal();
            } else {
                // Incognito fallback: if AJAX is blocked/altered, submit as normal POST.
                if (!data && (res.status === 0 || res.status >= 300 || !txt || txt.indexOf('<') !== -1)) {
                    submitDeleteAnnouncementFallback();
                    return;
                }
                if (errBox) {
                    errBox.textContent = (data && data.message)
                        ? data.message
                        : ('Failed (HTTP ' + res.status + '). ' + (txt || '').slice(0, 160));
                    errBox.classList.remove('hidden');
                }
                if (btn) { btn.disabled = false; btn.innerHTML = idleHtml; }
            }
        } catch (err) {
            // Network/CORS/session oddities in incognito: fallback to standard POST.
            submitDeleteAnnouncementFallback();
            return;
        }
    }

    // --- Announcement notification badge ---
    const MH_LAST_SEEN_KEY = 'mh_last_seen_announcement_id';

    function getLastSeenAnnouncementId() {
        return parseInt(localStorage.getItem(MH_LAST_SEEN_KEY) || '0', 10) || 0;
    }

    function markAnnouncementsSeen() {
        const btn = document.getElementById('announcementTriggerBtn');
        const latestId = parseInt((btn && btn.dataset.latestId) || '0', 10) || 0;
        if (latestId > 0) localStorage.setItem(MH_LAST_SEEN_KEY, String(latestId));
        const badge = document.getElementById('announcementBadge');
        if (badge) { badge.classList.add('hidden'); badge.classList.remove('flex'); }
    }

    function refreshAnnouncementBadge(latestIdFromServer) {
        const btn = document.getElementById('announcementTriggerBtn');
        const badge = document.getElementById('announcementBadge');
        if (!btn || !badge) return;
        const knownLatest = parseInt(btn.dataset.latestId || '0', 10) || 0;
        const newest = Math.max(knownLatest, parseInt(latestIdFromServer || 0, 10) || 0);
        if (newest > knownLatest) btn.dataset.latestId = String(newest);
        const lastSeen = getLastSeenAnnouncementId();
        if (newest > 0 && newest > lastSeen) {
            badge.textContent = '';
            badge.classList.remove('hidden');
            badge.classList.add('flex');
        } else {
            badge.classList.add('hidden');
            badge.classList.remove('flex');
        }
    }

    function confirmLogout() {
        closeLogoutModal();
        document.getElementById('loggingOutScreen').classList.replace('hidden', 'flex');

        setTimeout(() => { window.location.href = '?logout=1&c=<?= urlencode($clinicCode) ?>'; }, 1000);
    }

    window.addEventListener('DOMContentLoaded', () => {
        if (window.Chart && document.getElementById('userGrowthChart')) {
            const ctx = document.getElementById('userGrowthChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: userGrowthLabels,
                    datasets: [{
                        label: 'New Patients',
                        data: userGrowthValues,
                        borderColor: '<?= htmlspecialchars($themeColor) ?>',
                        backgroundColor: 'color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)',
                        fill: true,
                        tension: 0.35,
                        borderWidth: 3,
                        pointRadius: 4,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: '#64748b', font: { size: 11, weight: '700' } }
                        },
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0,
                                color: '#64748b',
                                font: { size: 11, weight: '700' }
                            },
                            grid: { color: '#e2e8f0' }
                        }
                    }
                }
            });
        }

        // On page load: JS decides whether to show the banner (never trust PHP-rendered visibility)
        const initBanner = document.getElementById('broadcastBanner');
        if (initBanner) {
            const initId = parseInt(initBanner.dataset.broadcastId || '0', 10) || 0;
            const initMsg = initBanner.dataset.broadcastMessage || '';
            if (initId > 0 && initMsg && initId > getLastDismissedBannerId()) {
                document.getElementById('broadcastMessage').textContent = initMsg;
                initBanner.classList.remove('hidden');
                startBroadcastAutoHide();
            }
        }

        // Initialize the announcement-history button badge based on what's saved
        refreshAnnouncementBadge(<?= $latestBroadcastId !== null ? (int)$latestBroadcastId : 0 ?>);

        pollLatestAnnouncement();
        setInterval(pollLatestAnnouncement, 10000);
    });
</script>

<!-- ===================== DELETE ANNOUNCEMENT CONFIRMATION MODAL ===================== -->
<div id="deleteAnnouncementModal" class="fixed inset-0 z-[120] hidden items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4" onclick="if(event.target===this) closeDeleteAnnouncementModal()">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-6 pt-6 pb-4 flex items-start gap-4">
            <div class="shrink-0 w-12 h-12 rounded-full bg-rose-100 text-rose-600 flex items-center justify-center">
                <span class="material-symbols-outlined text-[26px]">delete</span>
            </div>
            <div class="flex-1">
                <h3 class="text-lg font-black text-slate-800 leading-tight">Delete Announcement?</h3>
                <p class="text-sm text-slate-500 mt-1 leading-snug">This will remove the announcement from your clinic. This action <span class="font-bold text-rose-600">cannot be undone</span>.</p>
            </div>
        </div>
        <div class="px-6 pb-4">
            <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3">
                <div class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Message</div>
                <p id="deleteAnnPreview" class="text-sm text-slate-700 font-semibold leading-snug break-words line-clamp-4"></p>
            </div>
            <div id="deleteAnnError" class="hidden mt-3 text-xs font-bold text-rose-600 bg-rose-50 border border-rose-100 rounded-lg px-3 py-2"></div>
        </div>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex items-center justify-end gap-2">
            <button type="button" onclick="closeDeleteAnnouncementModal()" class="px-4 py-2 rounded-lg text-sm font-bold text-slate-600 hover:bg-slate-200 transition-colors">Cancel</button>
            <button type="button" id="confirmDeleteAnnBtn" onclick="confirmDeleteAnnouncement()" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-bold text-white bg-rose-500 hover:bg-rose-600 active:bg-rose-700 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                <span class="material-symbols-outlined text-[18px]">delete</span><span>Delete</span>
            </button>
        </div>
    </div>
</div>
<!-- =============================================================================== -->

</body>
</html>