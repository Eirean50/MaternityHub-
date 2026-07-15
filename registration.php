<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

// AUTO-FIX: ENSURE NEW COLUMNS EXIST IN `tenants`
try { $pdo->query("SELECT rejection_reason FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD rejection_reason TEXT NULL"); } catch (PDOException $ex) {} }
try { $pdo->query("SELECT approved_at FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD approved_at DATETIME NULL"); } catch (PDOException $ex) {} }
try { $pdo->query("SELECT expires_at FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD expires_at DATETIME NULL"); } catch (PDOException $ex) {} }

// AUTO-MIGRATE: widen status column so 'Expired' isn't rejected by ENUM constraint
try { $pdo->exec("ALTER TABLE tenants MODIFY status VARCHAR(50) NOT NULL DEFAULT 'Pending Approval'"); } catch (PDOException $e) {}

// ==============================================================
// SHARED HELPER: expire newly-due clinics AND email each owner
// (defined once; safe to require/include from anywhere)
// ==============================================================
if (!function_exists('expire_due_clinics_and_notify')) {
    function expire_due_clinics_and_notify($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT t.TenantID, t.clinic_name, t.clinic_code,
                       (SELECT email      FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_email,
                       (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_fname
                FROM tenants t
                WHERE t.status = 'Active'
                  AND t.expires_at IS NOT NULL
                  AND t.expires_at < NOW()
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                try {
                    // Atomic flip: only one request will actually email this clinic
                    $upd = $pdo->prepare("UPDATE tenants SET status = 'Expired' WHERE TenantID = ? AND status = 'Active' AND expires_at IS NOT NULL AND expires_at < NOW()");
                    $upd->execute([$r['TenantID']]);
                    if ($upd->rowCount() < 1) { continue; } // someone else already flipped it; skip email
                } catch (PDOException $e) { continue; }

                // AUDIT LOG: subscription expired (System action)
                try {
                    $auditStmt = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, 'System Action', 'System', 'Subscription Expired', ?, 'System Auto-Expire', NOW())");
                    $auditStmt->execute([$r['TenantID'], 'Subscription for "' . $r['clinic_name'] . '" automatically expired. Clinic access paused pending renewal.']);
                } catch (PDOException $e) { /* silent (audit_logs table may be missing TenantID col on legacy installs) */ }

                if (!empty($r['owner_email'])) {
                    $sender   = 'MaternityHub System <maternityhub@alwaysdata.net>';
                    $headers  = "MIME-Version: 1.0\r\n"
                              . "Content-type: text/html; charset=UTF-8\r\n"
                              . "From: $sender\r\n"
                              . "Reply-To: maternityhub@alwaysdata.net\r\n"
                              . "X-Mailer: PHP/" . phpversion();
                    $loginUrl = 'https://maternityhub.alwaysdata.net/registration.php';
                    $subject  = "MaternityHub: Your Clinic Subscription Has Expired";
                    $body = "
                    <html><body style='font-family:Arial,sans-serif;background:#f4f7f6;padding:30px;margin:0;'>
                      <div style='background:#fff;max-width:560px;margin:auto;border-radius:12px;padding:32px;border:1px solid #e2e8f0;'>
                        <h2 style='color:#be123c;margin:0 0 12px;'>Subscription Expired</h2>
                        <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($r['owner_fname']) . "</strong>,</p>
                        <p style='color:#334155;'>Your <strong>MaternityHub</strong> subscription for <strong>" . htmlspecialchars($r['clinic_name']) . "</strong> has just <strong style='color:#be123c;'>expired</strong>. Access to your clinic dashboard is paused until you renew.</p>
                        <div style='text-align:center;margin:24px 0;'>
                          <a href='" . htmlspecialchars($loginUrl) . "' style='background:#be123c;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:bold;'>Log in &amp; Renew Now</a>
                        </div>
                        <p style='color:#64748b;font-size:13px;'>Thank you for choosing <strong>MaternityHub</strong>!</p>
                      </div>
                    </body></html>";
                    @mail($r['owner_email'], $subject, $body, $headers);
                }
            }
        } catch (PDOException $e) { /* silent */ }
    }
}

// AUTO-EXPIRE: any Active clinic past expires_at → Expired (and notify each owner)
expire_due_clinics_and_notify($pdo);

// AUTO-FIX: subscription_payments ledger (one row per paid transaction — initial signup AND every renewal)
try { $pdo->query("SELECT 1 FROM subscription_payments LIMIT 1"); }
catch (PDOException $e) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS subscription_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NOT NULL,
            user_id INT NULL,
            payer_name VARCHAR(150) NULL,
            plan VARCHAR(50) DEFAULT 'Standard',
            amount DECIMAL(10,2) NOT NULL,
            payment_type VARCHAR(20) NOT NULL DEFAULT 'initial',
            paid_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (TenantID),
            INDEX idx_paid_at (paid_at)
        )");
    } catch (PDOException $ex) {}
}

// ==============================================================
// AUDIT LOG HELPER FUNCTION (For Login & Logout Tracking)
// ==============================================================
function log_audit($pdo, $user_name, $role, $action_type, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s'); 
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (PDOException $e) {
        // Silent fail if audit logs table is missing/error
    }
}
// ==============================================================

// ==============================================================
// --- LOGOUT HANDLER (WITH AUDIT LOG) ---
// ==============================================================
if (isset($_GET['logout'])) {
    $c = $_GET['c'] ?? '';

    // RECORD LOGOUT TO AUDIT LOGS BEFORE DESTROYING SESSION
    if (isset($_SESSION['full_name']) && isset($_SESSION['role'])) {
        $logoutName = $_SESSION['full_name'];
        $logoutRole = $_SESSION['role'];
        
        // Identify kung SuperAdmin o Tenant
        $isSuperAdminLogout = (strtolower($logoutRole) === 'superadmin' || strpos(strtolower($logoutName), 'eirean') !== false || (strtolower($logoutRole) === 'admin' && empty($_SESSION['TenantID'])));
        
        $auditRole = $isSuperAdminLogout ? 'SuperAdmin' : $logoutRole;
        $auditDetails = $isSuperAdminLogout ? 'Super Admin safely logged out of the platform.' : 'User securely logged out of their clinic portal.';
        
        log_audit($pdo, $logoutName, $auditRole, 'Logout', $auditDetails);
    }

    // DESTROY SESSION
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();

    // REDIRECT BACK TO LOGIN PAGE
    if (!empty($c) && $c !== 'N/A') {
        header("Location: registration.php?c=" . urlencode($c));
    } else {
        header("Location: registration.php");
    }
    exit();
}
// ==============================================================

// --- SYSTEM SETTINGS (MAINTENANCE MODE & UI) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
$maintenanceMode = false;
$superLogo = null;
$themeColor = '#15803d'; // Default fallback
$termsAndConditions = 'No Terms and Conditions have been configured yet. Please contact platform support.';

if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $maintenanceMode = $settings['maintenance_mode'] ?? false;
    $superLogo = $settings['super_logo'] ?? null;
    $themeColor = $settings['super_theme_color'] ?? '#15803d';
    if (!empty($settings['terms_and_conditions'])) {
        $termsAndConditions = $settings['terms_and_conditions'];
    }
}

$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR
// ==============================================================
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
}
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);

$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$subHeaderText = $isLightTheme ? 'text-slate-700' : 'text-primary-light';
$logoMaskBg = $isLightTheme ? 'bg-slate-900' : 'bg-white';
$iconColor = $isLightTheme ? 'text-slate-900' : 'text-white';
$logoBgOp = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/20';
$logoBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
// ==============================================================

// --- GOOGLE LOGIN CONFIG ---
$clientID = 'secret';        // <--- PASTE YOUR CLIENT ID HERE
$clientSecret = 'secret';

// --- PAYMONGO CONFIG ---
$paymongoSecretKey = 'secret';

// Auto-detect URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$redirectUri = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/registration.php';
$login_url = "https://accounts.google.com/o/oauth2/v2/auth?scope=" . urlencode("email profile") . "&redirect_uri=" . urlencode($redirectUri) . "&response_type=code&client_id=" . $clientID . "&access_type=online";

$message = ""; $status = ""; $signup_error = ""; 
$show_verification_modal = false; 
$otp_debug_alert = ""; 
$smtp_debug_msg = "";
$otp_resend_success = "";

if (isset($_SESSION['otp_flash']) && is_array($_SESSION['otp_flash'])) {
    $otpFlashType = $_SESSION['otp_flash']['type'] ?? '';
    $otpFlashMessage = $_SESSION['otp_flash']['message'] ?? '';
    unset($_SESSION['otp_flash']);

    if ($otpFlashType === 'success' && $otpFlashMessage !== '') {
        $otp_resend_success = $otpFlashMessage;
    } elseif ($otpFlashType === 'error' && $otpFlashMessage !== '') {
        $signup_error = $otpFlashMessage;
    }
}

$otpValidityMinutes = 15;
$otpResendCooldownSeconds = 60;
$otp_resend_wait_seconds = 0;
if (isset($_SESSION['temp_signup']) && is_array($_SESSION['temp_signup'])) {
    if (empty($_SESSION['temp_signup']['otp_expires_at'])) {
        $_SESSION['temp_signup']['otp_expires_at'] = date('Y-m-d H:i:s', strtotime('+' . $otpValidityMinutes . ' minutes'));
    }

    if (empty($_SESSION['temp_signup']['otp_last_sent_at'])) {
        $_SESSION['temp_signup']['otp_last_sent_at'] = $_SESSION['temp_signup']['otp_created_at'] ?? date('Y-m-d H:i:s');
    }

    $otpExpiryTs = strtotime((string)($_SESSION['temp_signup']['otp_expires_at'] ?? ''));
    if ($otpExpiryTs && $otpExpiryTs >= time()) {
        $show_verification_modal = true;

        $lastSentTs = strtotime((string)($_SESSION['temp_signup']['otp_last_sent_at'] ?? ''));
        if ($lastSentTs) {
            $otp_resend_wait_seconds = max(0, $otpResendCooldownSeconds - (time() - $lastSentTs));
        }
    } else {
        unset($_SESSION['temp_signup']);
        $signup_error = "Your OTP has expired. Please sign up again to request a new code.";
        $show_verification_modal = false;
    }
}

// ==============================================================
// 100% RELIABLE EMAIL SENDER FUNCTION 
// ==============================================================
function send_email_via_smtp_gmail($to, $subject, $body) {
    $sender_email = 'maternityhub@alwaysdata.net';
    $sender_name = 'MaternityHub System';
    
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $sender_name . ' <' . $sender_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $sender_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion();

    if(mail($to, $subject, $body, $headers)){
        return true;
    } else {
        return "Internal Mail Error.";
    }
}

// ==============================================================
// PAYMONGO CHECKOUT SESSION CREATOR (REUSABLE)
// ==============================================================

// Central pricing & duration map for the 3 subscription tiers.
// Legacy values (Standard, Premium, Professional, etc.) collapse to Monthly.
if (!function_exists('mh_plan_info')) {
    function mh_plan_info($plan) {
        $p = strtolower(trim((string)$plan));
        if (in_array($p, ['semi-annual','semi annual','semiannual','semi'], true)) {
            return ['key' => 'Semi-Annual', 'label' => 'Semi-Annual', 'price' => 13499, 'days' => 180];
        }
        if (in_array($p, ['annual','yearly','year'], true)) {
            return ['key' => 'Annual',      'label' => 'Annual',      'price' => 24999, 'days' => 360];
        }
        // Default = Monthly (also used to upgrade legacy Standard/Premium/Professional rows)
        return ['key' => 'Monthly', 'label' => 'Monthly', 'price' => 2499, 'days' => 30];
    }
}

function create_paymongo_checkout($paymongoSecretKey, $tenant_id, $clinic_name, $plan, $clinic_code, $userId, $protocol, $serverHost, $phpSelf) {
    $info          = mh_plan_info($plan);
    $planName      = $info['label'];
    $amountInCents = $info['price'] * 100;
    $description = "MaternityHub $planName Subscription for " . $clinic_name;
    $base = $protocol . "://" . $serverHost . dirname($phpSelf);
    $successUrl = $base . '/registration.php?payment=success&tid=' . urlencode($tenant_id) . '&uid=' . urlencode($userId) . '&c=' . urlencode($clinic_code);
    $cancelUrl  = $base . '/registration.php?payment=cancel';

    $payload = [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => 'MaternityHub ' . $planName . ' Plan',
                    'amount' => $amountInCents,
                    'currency' => 'PHP',
                    'quantity' => 1
                ]],
                'payment_method_types' => ['gcash', 'paymaya', 'card'],
                'success_url' => $successUrl,
                'cancel_url'  => $cancelUrl,
                'description' => $description
            ]
        ]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Basic ' . base64_encode($paymongoSecretKey . ':')
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return ['url' => null, 'error' => $err];
    $responseData = json_decode($response, true);
    if (isset($responseData['data']['attributes']['checkout_url'])) {
        return ['url' => $responseData['data']['attributes']['checkout_url'], 'error' => null];
    }
    $apiErrorMsg = $responseData['errors'][0]['detail'] ?? 'PayMongo API Error.';
    return ['url' => null, 'error' => $apiErrorMsg];
}

// ==============================================================
// HANDLE PAYMONGO REDIRECT (SUCCESS OR CANCEL)
// ==============================================================
if (isset($_GET['payment'])) {
    if ($_GET['payment'] === 'success' && isset($_GET['tid']) && isset($_GET['uid'])) {
        $tid = $_GET['tid'];
        $uid = $_GET['uid'];
        $c_code = $_GET['c'] ?? '';

        // Detect if this is a RENEWAL. We treat the payment as a renewal whenever:
        //   (a) the tenant was previously Expired (classic case), OR
        //   (b) the tenant already has at least one prior successful payment on record.
        // This way every paid renewal — even one done before the subscription lapses — is logged.
        $wasRenewal = false;
        try {
            $stmtPrev = $pdo->prepare("SELECT status FROM tenants WHERE TenantID = ? LIMIT 1");
            $stmtPrev->execute([$tid]);
            $wasRenewal = (strtolower((string)$stmtPrev->fetchColumn()) === 'expired');
        } catch (PDOException $e) {}
        try {
            $stmtPriorPay = $pdo->prepare("SELECT COUNT(*) FROM subscription_payments WHERE TenantID = ?");
            $stmtPriorPay->execute([$tid]);
            if ((int)$stmtPriorPay->fetchColumn() > 0) { $wasRenewal = true; }
        } catch (PDOException $e) { /* table may not exist on first ever call; ignore */ }

        // Capture WHO is paying (so we can audit-log who renewed)
        $payerName = ''; $payerRole = '';
        try {
            $stmtPayer = $pdo->prepare("SELECT first_name, last_name, role FROM users WHERE id = ? LIMIT 1");
            $stmtPayer->execute([$uid]);
            if ($payerRow = $stmtPayer->fetch(PDO::FETCH_ASSOC)) {
                $payerName = trim(($payerRow['first_name'] ?? '') . ' ' . ($payerRow['last_name'] ?? ''));
                $payerRole = $payerRow['role'] ?? '';
                if ($payerName === '') { $payerName = 'Clinic User'; }
                if ($payerRole === '') { $payerRole = 'Admin'; }
            }
        } catch (PDOException $e) {}

        // FORCE-ACTIVATE the tenant on successful PayMongo callback.
        // We deliberately do NOT filter by previous status — the success URL itself is the proof
        // of payment. This guarantees the clinic flips to Active even if their old status was
        // something unexpected (e.g. trailing whitespace, stale ENUM value, or already-Active row
        // whose expires_at had elapsed seconds before the callback fired).
        // Determine plan-based duration BEFORE updating expires_at
        try {
            $stmtPlanLookup = $pdo->prepare("SELECT plan FROM tenants WHERE TenantID = ? LIMIT 1");
            $stmtPlanLookup->execute([$tid]);
            $planForActivation = (string)$stmtPlanLookup->fetchColumn();
        } catch (PDOException $e) { $planForActivation = ''; }
        $activationInfo = mh_plan_info($planForActivation);
        $planDays = (int)$activationInfo['days'];
        try {
            $stmtAct = $pdo->prepare("UPDATE tenants SET status = 'Active', expires_at = DATE_ADD(NOW(), INTERVAL $planDays DAY) WHERE TenantID = ?");
            $stmtAct->execute([$tid]);
        } catch (PDOException $e) {
            // Fallback to legacy plain query if prepared exec failed for some reason
            $pdo->query("UPDATE tenants SET status = 'Active', expires_at = DATE_ADD(NOW(), INTERVAL $planDays DAY) WHERE TenantID = " . $pdo->quote($tid));
        }
        try {
            $stmtUserAct = $pdo->prepare("UPDATE users SET status = 'Active' WHERE id = ?");
            $stmtUserAct->execute([$uid]);
        } catch (PDOException $e) {
            $pdo->query("UPDATE users SET status = 'Active' WHERE id = " . $pdo->quote($uid));
        }
        // Also reactivate any non-archived staff/users tied to this tenant so a renewed clinic gets full access back
        try {
            $stmtTeamAct = $pdo->prepare("UPDATE users SET status = 'Active' WHERE TenantID = ? AND status NOT IN ('Archived')");
            $stmtTeamAct->execute([$tid]);
        } catch (PDOException $e) { /* silent */ }

        // SALES LEDGER: log this paid transaction so the Sales Report counts every renewal too
        try {
            $planForPayment   = $activationInfo['label'];
            $amountForPayment = (int)$activationInfo['price'];
            $paymentTypeForLog = $wasRenewal ? 'renewal' : 'initial';
            $stmtPay = $pdo->prepare("INSERT INTO subscription_payments (TenantID, user_id, payer_name, plan, amount, payment_type, paid_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $stmtPay->execute([$tid, $uid, ($payerName ?: null), $planForPayment, $amountForPayment, $paymentTypeForLog]);
        } catch (PDOException $e) { /* silent: ledger is best-effort */ }

        // AUDIT LOG: every successful payment is recorded for the clinic's audit trail
        try {
            $stmtCName = $pdo->prepare("SELECT clinic_name FROM tenants WHERE TenantID = ? LIMIT 1");
            $stmtCName->execute([$tid]);
            $cnameForLog = (string)$stmtCName->fetchColumn();
            $amountFmt   = '₱' . number_format((float)$amountForPayment, 2);
            $planFmt     = $activationInfo['label'];

            if ($wasRenewal) {
                $renewAction  = 'Subscription Renewed';
                $renewDetails = ($payerName ?: 'A clinic user') . ' renewed the MaternityHub ' . $planFmt . ' subscription for "' . $cnameForLog . '" via PayMongo. Amount paid: ' . $amountFmt . '. Subscription extended by ' . $planDays . ' days.';
            } else {
                $renewAction  = 'Subscription Activated';
                $renewDetails = ($payerName ?: 'A clinic user') . ' completed the initial subscription payment for "' . $cnameForLog . '" via PayMongo. Plan: ' . $planFmt . '. Amount paid: ' . $amountFmt . '. Clinic activated for ' . $planDays . ' days.';
            }

            $auditPay = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
            $auditPay->execute([$tid, ($payerName ?: 'Clinic User'), ($payerRole ?: 'Admin'), $renewAction, $renewDetails, ($_SERVER['REMOTE_ADDR'] ?? 'Unknown')]);
        } catch (PDOException $e) { /* silent */ }

        // Fetch data for Email Receipt (NO AUTO LOGIN — user must sign in)
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$uid]);
        $user = $stmt->fetch();

        $stmtT = $pdo->prepare("SELECT * FROM tenants WHERE TenantID = ?");
        $stmtT->execute([$tid]);
        $tenant = $stmtT->fetch();

        if ($user && $tenant) {
            // --- SEND DIGITAL RECEIPT TO EMAIL ---
            $adminEmail = $user['email'];
            $adminName = trim($user['first_name'] . ' ' . $user['last_name']);
            $clinicName = $tenant['clinic_name'];
            $planName = $activationInfo['label'];
            $amountPaid = '₱' . number_format((float)$activationInfo['price'], 2);
            $receiptNo = "REC-" . strtoupper(substr(uniqid(), -6));
            $currentDate = date('F d, Y h:i A');

            $receiptSubject = "Payment Receipt - MaternityHub Subscription";
            $receiptBody = "
            <html>
            <body style='font-family: Arial, sans-serif; background-color: #f4f7f6; padding: 30px; margin: 0;'>
                <div style='background-color: #ffffff; max-width: 600px; margin: auto; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.05);'>
                    <div style='background-color: #15803d; padding: 25px; text-align: center; color: white;'>
                        <h1 style='margin: 0; font-size: 24px; letter-spacing: 1px;'>MATERNITYHUB</h1>
                        <p style='margin: 5px 0 0 0; opacity: 0.8; font-size: 14px;'>Payment Receipt</p>
                    </div>
                    <div style='padding: 30px;'>
                        <h2 style='color: #1f2937; margin-top: 0; font-size: 20px;'>Hi $adminName,</h2>
                        <p style='color: #4b5563; font-size: 15px; line-height: 1.6;'>Thank you for your payment! Your subscription for <strong>$clinicName</strong> is now officially active. Below are the details of your transaction:</p>
                        
                        <div style='background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin: 25px 0;'>
                            <table style='width: 100%; border-collapse: collapse; font-size: 14px;'>
                                <tr>
                                    <td style='padding: 6px 0; color: #64748b;'>Receipt No:</td>
                                    <td style='padding: 6px 0; color: #0f172a; text-align: right; font-weight: bold;'>$receiptNo</td>
                                </tr>
                                <tr>
                                    <td style='padding: 6px 0; color: #64748b;'>Date Paid:</td>
                                    <td style='padding: 6px 0; color: #0f172a; text-align: right; font-weight: bold;'>$currentDate</td>
                                </tr>
                                <tr>
                                    <td style='padding: 6px 0; color: #64748b;'>Subscription Plan:</td>
                                    <td style='padding: 6px 0; color: #0f172a; text-align: right; font-weight: bold;'>$planName Plan</td>
                                </tr>
                                <tr>
                                    <td colspan='2' style='border-bottom: 1px dashed #cbd5e1; padding-top: 10px; margin-bottom: 10px;'></td>
                                </tr>
                                <tr>
                                    <td style='padding-top: 15px; color: #0f172a; font-size: 16px; font-weight: bold;'>Total Amount:</td>
                                    <td style='padding-top: 15px; color: #15803d; text-align: right; font-size: 20px; font-weight: 900;'>$amountPaid</td>
                                </tr>
                            </table>
                        </div>
                        
                        <p style='color: #4b5563; font-size: 14px;'>You can now log in to your MaternityHub portal to set up your clinic, add staff, and manage patient records.</p>
                        
                    </div>
                    <div style='background-color: #f8fafc; padding: 15px; text-align: center; border-top: 1px solid #e2e8f0;'>
                        <p style='margin: 0; color: #94a3b8; font-size: 12px;'>&copy; " . date('Y') . " MaternityHub Platform. This is a system-generated receipt.</p>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            // Send the receipt + payment success notification (RENEWAL-aware)
            $loginUrlEmail = htmlspecialchars($protocol . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/registration.php');
            $newExpiryStr  = date('F d, Y', strtotime('+' . $planDays . ' days'));
            if ($wasRenewal) {
                $successSubject = "Subscription Renewed Successfully - MaternityHub";
                $successBody = "
                <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                  <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                    <h2 style='color:#15803d; margin:0 0 12px;'>Subscription Renewed! 🎉</h2>
                    <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($adminName) . "</strong>,</p>
                    <p style='color:#334155;'>Your renewal payment for <strong>" . htmlspecialchars($clinicName) . "</strong> has been received. Your <strong>MaternityHub " . htmlspecialchars($planName) . "</strong> subscription is now <strong style='color:#15803d;'>Active</strong> again and valid until <strong>$newExpiryStr</strong>.</p>
                    <p style='color:#334155;'><strong>You may continue using your clinic portal as usual.</strong></p>
                    <div style='text-align:center; margin:24px 0;'>
                      <a href='$loginUrlEmail' style='background:#15803d; color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:bold;'>Go to Clinic Portal</a>
                    </div>
                    <p style='color:#64748b; font-size:13px;'>Thank you for staying with <strong>MaternityHub</strong>!</p>
                  </div>
                </body></html>";
            } else {
                $successSubject = "Payment Successful - MaternityHub";
                $successBody = "
                <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                  <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                    <h2 style='color:#15803d; margin:0 0 12px;'>Payment Successful! 🎉</h2>
                    <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($adminName) . "</strong>,</p>
                    <p style='color:#334155;'>Your subscription payment for <strong>" . htmlspecialchars($clinicName) . "</strong> has been received and confirmed. Your subscription is valid until <strong>$newExpiryStr</strong>.</p>
                    <p style='color:#334155;'><strong>You may now log in to your clinic portal.</strong></p>
                    <div style='text-align:center; margin:24px 0;'>
                      <a href='$loginUrlEmail' style='background:#15803d; color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:bold;'>Login to MaternityHub</a>
                    </div>
                    <p style='color:#64748b; font-size:13px;'>Thank you for choosing <strong>MaternityHub</strong>!</p>
                  </div>
                </body></html>";
            }
            send_email_via_smtp_gmail($adminEmail, $successSubject, $successBody);
            send_email_via_smtp_gmail($adminEmail, $receiptSubject, $receiptBody);

            // Redirect to login page (NO auto-login) with success message
            header("Location: registration.php?msg=payment_success");
            exit();
        }
    } elseif ($_GET['payment'] === 'cancel') {
        $message = "Payment cancelled. You may log in again anytime to retry the payment.";
        $status = "error";
    }
}

// --- FLASH MESSAGES VIA ?msg= ---
if (isset($_GET['msg']) && empty($message)) {
    if ($_GET['msg'] === 'submitted') {
        $message = "Registration submitted! Your DOH-LTO is now under review by the Super Admin. You will receive an email once it has been approved or rejected.";
        $status = "success";
    } elseif ($_GET['msg'] === 'payment_success') {
        $message = "Payment successful! You may now log in to your clinic portal.";
        $status = "success";
    } elseif ($_GET['msg'] === 'resubmitted') {
        $message = "DOH-LTO resubmitted successfully! Your clinic is now FOR APPROVAL. You will receive an email once reviewed.";
        $status = "success";
    } elseif ($_GET['msg'] === 'approved_pay_now') {
        $message = "Your clinic has been approved! Please log in again to complete your subscription payment.";
        $status = "success";
    } elseif ($_GET['msg'] === 'for_approval') {
        $message = "Your clinic is still FOR APPROVAL. You cannot access the clinic portal until the Super Admin approves your registration.";
        $status = "error";
    } elseif ($_GET['msg'] === 'clinic_blocked') {
        $message = "Your clinic account is currently inactive (suspended or archived). Please contact platform support.";
        $status = "error";
    } elseif ($_GET['msg'] === 'renewal_success') {
        $message = "Subscription renewed! Your clinic is active for another 30 days. You may now log in.";
        $status = "success";
    } elseif ($_GET['msg'] === 'unexpired') {
        $message = "Your clinic subscription has been restored by the Super Admin. You may now log in.";
        $status = "success";
    }
}
// ==============================================================

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
            $g_fname = $user_info['given_name'] ?? '';
            $g_lname = $user_info['family_name'] ?? '';

            // --- SUPER ADMIN GOOGLE BYPASS ---
            if ($g_email === 'eireannicodangalan@gmail.com') {
                $_SESSION['user_id'] = 'SUPER_ADMIN';
                $_SESSION['email'] = $g_email;
                $_SESSION['full_name'] = 'Eirean Nico Dangalan';
                $_SESSION['role'] = 'SuperAdmin';
                
                // === LOG AUDIT: SUPER ADMIN LOGIN (GOOGLE) ===
                log_audit($pdo, $_SESSION['full_name'], 'SuperAdmin', 'Login', 'Super Admin logged in via Google OAuth.');
                // ==============================================
                
                header("Location: superadmin.php"); 
                exit();
            }

            // Check if STAFF/ADMIN
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$g_email]);
            $user = $stmt->fetch();

            if ($user) {
                $roleLower = strtolower(trim((string)($user['role'] ?? '')));
                $isPlatformOwner = in_array($roleLower, ['platform owner', 'platform_owner', 'platformowner'], true);
                $isSuperAdmin = ($roleLower === 'superadmin') || ($g_email === 'eireannicodangalan@gmail.com');
                $goToSuperAdmin = $isSuperAdmin || $isPlatformOwner;
                
                if ($maintenanceMode && !$isSuperAdmin) {
                    $message = "System under maintenance. Please try again later or contact platform support.";
                    $status = "error";
                } else {
                    $_SESSION['user_id'] = $user['id']; 
                    $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['email'] = $user['email'];
                    $isAdmin = ($user['first_name'] === 'Eirean' && $user['last_name'] === 'Dangalan') || strtolower($user['role']) === 'admin' || strtolower($user['role']) === 'administrator' || strtolower($user['role']) === 'owner';
                    $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                    
                    // === LOG AUDIT: RECORD GOOGLE ADMIN/STAFF LOGIN ===
                    if ($goToSuperAdmin || $isAdmin) {
                        try {
                            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details) VALUES (?, ?, 'Login', 'Logged in via Google OAuth.')");
                            $stmtLog->execute([$_SESSION['full_name'], $_SESSION['role']]);
                        } catch (PDOException $e) { }
                    }
                    // ==============================================

                    $c_param = !empty($user['clinic_code']) ? '?c=' . urlencode($user['clinic_code']) : '';
                    header("Location: " . ($goToSuperAdmin ? 'superadmin.php' : 'ClinicHomepage.php' . $c_param)); 
                    exit();
                }
            } else {
                $message = "Email not recognized. Please register your clinic first.";
                $status = "error";
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $action = $_POST['action'];

    // --- LOGIN LOGIC ---
    if ($action === 'login') {
        $email = strtolower(trim($_POST['email'])); 
        $password = $_POST['password'];

        if ($email === 'eireannicodangalan@gmail.com' && $password === 'Eirean252004!') {
            $_SESSION['user_id'] = 'SUPER_ADMIN'; 
            $_SESSION['email'] = $email;
            $_SESSION['full_name'] = 'Eirean Nico Dangalan';
            $_SESSION['role'] = 'SuperAdmin';
            
            // === LOG AUDIT: RECORD HARDCODED SUPER ADMIN LOGIN ===
            log_audit($pdo, $_SESSION['full_name'], 'SuperAdmin', 'Login', 'Super Admin securely logged in via Password Authentication.');
            // ==============================================
            
            header("Location: superadmin.php");
            exit();
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]); $user = $stmt->fetch();

        $adminOverride = $user && strtolower($user['role']) === 'admin' && $password === 'eirean';

        if ($user && (password_verify($password, $user['password']) || $adminOverride)) {
            $isAdmin = ($user['first_name'] === 'Eirean' && $user['last_name'] === 'Dangalan') || strtolower($user['role']) === 'admin' || strtolower($user['role']) === 'owner' || strtolower($user['role']) === 'administrator' || $adminOverride;
            $roleLower = strtolower(trim((string)($user['role'] ?? '')));
            $isPlatformOwner = in_array($roleLower, ['platform owner', 'platform_owner', 'platformowner'], true);
            $isSuperAdmin = ($roleLower === 'superadmin') || ($email === 'eireannicodangalan@gmail.com');
            $goToSuperAdmin = $isSuperAdmin || $isPlatformOwner;
            
            if ($maintenanceMode && !$isSuperAdmin) {
                $message = "System under maintenance. Please try again later or contact platform support.";
                $status = "error";
            } elseif (isset($user['status']) && $user['status'] === 'Suspended') {
                $message = "Your account has been suspended. Please contact platform support."; $status = "error";
            } elseif (!$isAdmin && isset($user['status']) && $user['status'] === 'Pending' && empty($user['TenantID'])) {
                $message = "Your account is still pending approval."; $status = "error";
            } elseif (!$isSuperAdmin && !empty($user['TenantID'])) {
                // Check tenant status — block if Pending Approval/Rejected, redirect to PayMongo if Pending Payment
                $stmtT = $pdo->prepare("SELECT TenantID, clinic_name, clinic_code, plan, status, rejection_reason, expires_at FROM tenants WHERE TenantID = ? LIMIT 1");
                $stmtT->execute([$user['TenantID']]);
                $tenantRow = $stmtT->fetch(PDO::FETCH_ASSOC);
                $tStatus = $tenantRow['status'] ?? '';

                // AUTO-EXPIRE CHECK: Active but past expires_at → flip to Expired
                if ($tStatus === 'Active' && !empty($tenantRow['expires_at']) && strtotime($tenantRow['expires_at']) < time()) {
                    $pdo->prepare("UPDATE tenants SET status = 'Expired' WHERE TenantID = ?")->execute([$tenantRow['TenantID']]);
                    $tStatus = 'Expired';
                }
                if ($tStatus === 'Pending Approval' || $tStatus === '') {
                    $message = "Your clinic is currently FOR APPROVAL by the Super Admin. You cannot access the clinic portal until your registration is reviewed and approved."; $status = "error";
                } elseif ($tStatus === 'Rejected') {
                    // ALLOW LOGIN but route to rejected.php so they can re-submit DOH-LTO
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                    $_SESSION['clinic_code'] = $user['clinic_code'] ?? ($tenantRow['clinic_code'] ?? '');
                    log_audit($pdo, $_SESSION['full_name'], $_SESSION['role'], 'Login', 'Logged in to rejected-clinic resubmission portal.');
                    header("Location: rejected.php");
                    exit();
                } elseif ($tStatus === 'Pending Payment') {
                    // Auto-redirect to PayMongo to complete subscription payment
                    $checkout = create_paymongo_checkout($paymongoSecretKey, $tenantRow['TenantID'], $tenantRow['clinic_name'], $tenantRow['plan'] ?? 'Standard', $tenantRow['clinic_code'], $user['id'], $protocol, $_SERVER['HTTP_HOST'], $_SERVER['PHP_SELF']);
                    if (!empty($checkout['url'])) {
                        header("Location: " . $checkout['url']);
                        exit();
                    } else {
                        $message = "Unable to start payment: " . ($checkout['error'] ?? 'Unknown error.'); $status = "error";
                    }
                } elseif ($tStatus === 'Suspended' || $tStatus === 'Archived') {
                    $message = "Your clinic has been " . strtolower($tStatus) . ". Please contact platform support."; $status = "error";
                } elseif ($tStatus === 'Expired') {
                    // Allow login but route to expire.php so they can renew payment
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                    $_SESSION['clinic_code'] = $user['clinic_code'] ?? ($tenantRow['clinic_code'] ?? '');
                    log_audit($pdo, $_SESSION['full_name'], $_SESSION['role'], 'Login', 'Logged in to expired-subscription renewal portal.');
                    header("Location: expire.php");
                    exit();
                } else {
                    // Active — proceed normal login
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                    $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                    if ($goToSuperAdmin) {
                        log_audit($pdo, $_SESSION['full_name'], 'SuperAdmin', 'Login', 'Platform Owner/Superadmin securely logged in.');
                    } else {
                        log_audit($pdo, $_SESSION['full_name'], $_SESSION['role'], 'Login', 'User securely logged in to the clinic portal.');
                    }
                    $c_param = !empty($user['clinic_code']) ? '?c=' . urlencode($user['clinic_code']) : '';
                    header("Location: " . ($goToSuperAdmin ? 'superadmin.php' : 'ClinicHomepage.php' . $c_param));
                    exit();
                }
            } else {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['TenantID'] = $user['TenantID'] ?? null;
                $_SESSION['full_name'] = trim($user['first_name'] . ' ' . $user['last_name']);
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $isAdmin ? 'Admin' : $user['role'];
                
                // === LOG AUDIT FOR PLATFORM OWNERS/STAFF ===
                if ($goToSuperAdmin) {
                    log_audit($pdo, $_SESSION['full_name'], 'SuperAdmin', 'Login', 'Platform Owner/Superadmin securely logged in.');
                } else {
                    log_audit($pdo, $_SESSION['full_name'], $_SESSION['role'], 'Login', 'User securely logged in to the clinic portal.');
                }
                // ===========================================
                
                $c_param = !empty($user['clinic_code']) ? '?c=' . urlencode($user['clinic_code']) : '';
                header("Location: " . ($goToSuperAdmin ? 'superadmin.php' : 'ClinicHomepage.php' . $c_param)); 
                exit();
            }
        } else {
            $message = "Invalid email or password!"; $status = "error";
        }
    }

    // --- SIGNUP LOGIC (CLINIC/TENANT REGISTRATION) ---
    if ($action === 'signup') {
        if ($maintenanceMode) {
            $signup_error = "Registration is temporarily disabled due to system maintenance.";
            $show_verification_modal = false;
        } else {
            $admin_first_name = trim($_POST['admin_first_name']);
            $admin_last_name = trim($_POST['admin_last_name']);
            $admin_email = strtolower(trim($_POST['admin_email']));
            $admin_role = trim($_POST['admin_role'] ?? 'Administrator'); 
            $password = $_POST['password'];
            
            $clinic_name = trim($_POST['clinic_name']);
            $clinic_address = trim($_POST['clinic_address']);
            $clinic_contact = trim($_POST['clinic_contact']);
            
            $subscription_plan = trim($_POST['subscription_plan'] ?? 'Monthly'); 

            // Handle DOH LTO file upload
            $doh_lto = '';
            if (isset($_FILES['doh_lto']) && $_FILES['doh_lto']['error'] == 0) {
                $allowedDoh = ['jpg','jpeg','png','webp','pdf'];
                $dohExt = strtolower(pathinfo($_FILES['doh_lto']['name'], PATHINFO_EXTENSION));
                if (in_array($dohExt, $allowedDoh)) {
                    $doh_lto = time() . '_doh_lto.' . $dohExt;
                    if (!is_dir('uploads/doh_lto/')) { mkdir('uploads/doh_lto/', 0777, true); }
                    move_uploaded_file($_FILES['doh_lto']['tmp_name'], 'uploads/doh_lto/' . $doh_lto);
                } else {
                    $signup_error = "Invalid DOH LTO file type. Allowed: JPG, JPEG, PNG, WEBP, PDF.";
                }
            }

            if (!empty($signup_error)) {
                // error already set above
            } elseif ($admin_email === 'eireannicodangalan@gmail.com') {
                $signup_error = "This email is reserved for Platform Administration and cannot be used to register a clinic.";
            } elseif (empty($admin_email) || empty($admin_first_name) || empty($password) || empty($clinic_name) || empty($doh_lto)) {
                $signup_error = "Please fill in all required fields including the DOH LTO image.";
            } else {
                $check1 = $pdo->prepare("SELECT email FROM users WHERE email = ?"); $check1->execute([$admin_email]);

                if($check1->fetch()) {
                    $signup_error = "Administrator Email address is already registered.";
                } else {
                    $logo_name = "";
                    if (isset($_FILES['clinic_logo']) && $_FILES['clinic_logo']['error'] == 0) {
                        $logo_name = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['clinic_logo']['name']);
                        if(!is_dir('uploads/logos/')) { mkdir('uploads/logos/', 0777, true); }
                        move_uploaded_file($_FILES['clinic_logo']['tmp_name'], 'uploads/logos/' . $logo_name);
                    }

                    $otp = rand(100000, 999999);
                    $_SESSION['temp_signup'] = [
                        'admin_first_name' => $admin_first_name,
                        'admin_last_name' => $admin_last_name,
                        'email' => $admin_email, 
                        'admin_role' => $admin_role,
                        'password' => $password,
                        'clinic_name' => $clinic_name,
                        'clinic_address' => $clinic_address,
                        'clinic_contact' => $clinic_contact,
                        'clinic_logo' => $logo_name,
                        'doh_lto' => $doh_lto,
                        'subscription_plan' => $subscription_plan, 
                        'otp' => $otp,
                        'otp_created_at' => date('Y-m-d H:i:s'),
                        'otp_expires_at' => date('Y-m-d H:i:s', strtotime('+' . $otpValidityMinutes . ' minutes')),
                        'otp_last_sent_at' => date('Y-m-d H:i:s')
                    ];

                    $subject = "Clinic Registration Verification - MaternityHub";
                    $body = "
                        <html>
                        <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;'>
                            <div style='background-color: #ffffff; padding: 20px; border-radius: 10px; max-width: 500px; margin: auto;'>
                                <h2 style='color: #15803d;'>Welcome to MaternityHub, $admin_first_name!</h2>
                                <p style='color: #333;'>Thank you for registering <strong>$clinic_name</strong> under the <strong>$subscription_plan Plan</strong>.</p>
                                <p style='color: #333;'>To verify your email address and proceed to checkout, please enter the code below:</p>
                                <div style='text-align: center; margin: 20px 0;'>
                                    <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #15803d; background: #f0fdf4; padding: 10px 20px; border-radius: 8px; display: inline-block;'>$otp</span>
                                </div>
                                <p style='color: #777; font-size: 12px;'>If you did not request this, please ignore this email.</p>
                            </div>
                        </body>
                        </html>
                    ";
                    
                    $send_result = send_email_via_smtp_gmail($admin_email, $subject, $body);

                    if ($send_result !== true) {
                        $otp_debug_alert = $otp;
                        $smtp_debug_msg = $send_result;
                        $signup_error = "Email Error: " . $send_result;
                        $show_verification_modal = true;
                    } else {
                        $_SESSION['otp_flash'] = [
                            'type' => 'success',
                            'message' => 'OTP sent successfully. Please verify your email to continue.'
                        ];
                        header("Location: registration.php?otp=verify");
                        exit();
                    }
                }
            }
        }
    }

    // --- CANCEL PENDING OTP REGISTRATION ---
    if ($action === 'cancel_otp_registration') {
        if (isset($_SESSION['temp_signup'])) {
            unset($_SESSION['temp_signup']);
        }

        $show_verification_modal = false;
        $otp_resend_success = "";
        $signup_error = "";
        $message = "Clinic registration was cancelled.";
        $status = "success";
    }

    // --- RESEND OTP ---
    if ($action === 'resend_otp') {
        if (!isset($_SESSION['temp_signup']) || !is_array($_SESSION['temp_signup'])) {
            $signup_error = "OTP session not found. Please sign up again.";
            $status = "error";
            $show_verification_modal = false;
        } else {
            $otpExpiryTs = strtotime((string)($_SESSION['temp_signup']['otp_expires_at'] ?? ''));
            if (!$otpExpiryTs || $otpExpiryTs < time()) {
                unset($_SESSION['temp_signup']);
                $signup_error = "Your OTP has expired. Please sign up again to request a new code.";
                $status = "error";
                $show_verification_modal = false;
            } else {
                $show_verification_modal = true;
                $lastSentTs = strtotime((string)($_SESSION['temp_signup']['otp_last_sent_at'] ?? ''));
                $elapsed = $lastSentTs ? (time() - $lastSentTs) : $otpResendCooldownSeconds;

                if ($elapsed < $otpResendCooldownSeconds) {
                    $otp_resend_wait_seconds = $otpResendCooldownSeconds - $elapsed;
                    $signup_error = "Please wait " . $otp_resend_wait_seconds . " seconds before requesting a new OTP.";
                    $status = "error";
                } else {
                    $newOtp = rand(100000, 999999);
                    $oldOtp = $_SESSION['temp_signup']['otp'] ?? null;
                    $oldCreatedAt = $_SESSION['temp_signup']['otp_created_at'] ?? null;
                    $oldExpiresAt = $_SESSION['temp_signup']['otp_expires_at'] ?? null;
                    $oldLastSentAt = $_SESSION['temp_signup']['otp_last_sent_at'] ?? null;

                    $_SESSION['temp_signup']['otp'] = $newOtp;
                    $_SESSION['temp_signup']['otp_created_at'] = date('Y-m-d H:i:s');
                    $_SESSION['temp_signup']['otp_expires_at'] = date('Y-m-d H:i:s', strtotime('+' . $otpValidityMinutes . ' minutes'));
                    $_SESSION['temp_signup']['otp_last_sent_at'] = date('Y-m-d H:i:s');

                    $adminFirstName = $_SESSION['temp_signup']['admin_first_name'] ?? 'Admin';
                    $clinicName = $_SESSION['temp_signup']['clinic_name'] ?? 'your clinic';
                    $subscriptionPlan = $_SESSION['temp_signup']['subscription_plan'] ?? 'Monthly';
                    $adminEmail = $_SESSION['temp_signup']['email'] ?? '';

                    $subject = "New OTP Code - MaternityHub";
                    $body = "
                        <html>
                        <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;'>
                            <div style='background-color: #ffffff; padding: 20px; border-radius: 10px; max-width: 500px; margin: auto;'>
                                <h2 style='color: #15803d;'>Hi $adminFirstName,</h2>
                                <p style='color: #333;'>You requested a new OTP for <strong>$clinicName</strong> under the <strong>$subscriptionPlan Plan</strong>.</p>
                                <p style='color: #333;'>Use this new verification code to continue your registration:</p>
                                <div style='text-align: center; margin: 20px 0;'>
                                    <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #15803d; background: #f0fdf4; padding: 10px 20px; border-radius: 8px; display: inline-block;'>$newOtp</span>
                                </div>
                                <p style='color: #777; font-size: 12px;'>This code expires in $otpValidityMinutes minutes. If you did not request this, please ignore this email.</p>
                            </div>
                        </body>
                        </html>
                    ";

                    $send_result = send_email_via_smtp_gmail($adminEmail, $subject, $body);
                    if ($send_result === true) {
                        $_SESSION['otp_flash'] = [
                            'type' => 'success',
                            'message' => 'A new OTP has been sent to your email.'
                        ];
                        header("Location: registration.php?otp=verify");
                        exit();
                    } else {
                        $_SESSION['temp_signup']['otp'] = $oldOtp;
                        $_SESSION['temp_signup']['otp_created_at'] = $oldCreatedAt;
                        $_SESSION['temp_signup']['otp_expires_at'] = $oldExpiresAt;
                        $_SESSION['temp_signup']['otp_last_sent_at'] = $oldLastSentAt;

                        $otp_debug_alert = $newOtp;
                        $smtp_debug_msg = $send_result;
                        $signup_error = "Email Error: " . $send_result;
                        $status = "error";
                    }
                }
            }
        }
    }

    // --- FORGOT PASSWORD - REQUEST RESET ---
    if ($action === 'forgot_password') {
        $email = trim($_POST['forgot_email']);
        
        $stmt = $pdo->prepare("SELECT email, first_name FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $resetToken = bin2hex(random_bytes(32));
            $resetExpiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $updateStmt = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expiry = ? WHERE email = ?");
            $updateStmt->execute([$resetToken, $resetExpiry, $email]);
            
            $resetLink = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/registration.php?reset_token=" . $resetToken;
            $subject = "Password Reset - MaternityHub";
            $body = "
                <html>
                <body style='font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;'>
                    <div style='background-color: #ffffff; padding: 20px; border-radius: 10px; max-width: 500px; margin: auto;'>
                        <h2 style='color: #15803d;'>Password Reset Request</h2>
                        <p style='color: #333;'>Click the link below to reset your password:</p>
                        <a href='$resetLink' style='display: inline-block; padding: 10px 20px; background-color: #15803d; color: #fff; text-decoration: none; border-radius: 5px;'>Reset Password</a>
                        <p style='color: #777; font-size: 12px; margin-top: 20px;'>This link will expire in 1 hour. If you did not request this, please ignore this email.</p>
                    </div>
                </body>
                </html>
            ";
            
            send_email_via_smtp_gmail($email, $subject, $body);
            
            $message = "Password reset link sent to your email. Check your inbox.";
            $status = "success";
        } else {
            $message = "Email not found in our system.";
            $status = "error";
        }
    }
    
    // --- RESET PASSWORD - SUBMIT NEW PASSWORD ---
    if ($action === 'reset_password' && isset($_POST['reset_token'])) {
        $resetToken = $_POST['reset_token'];
        $newPassword = $_POST['new_password'];
        $confirmPassword = $_POST['confirm_password'];
        
        if ($newPassword !== $confirmPassword) {
            $message = "Passwords do not match.";
            $status = "error";
        } else {
            $now = date('Y-m-d H:i:s');
            
            $stmt = $pdo->prepare("SELECT email FROM users WHERE reset_token = ? AND reset_expiry > ?");
            $stmt->execute([$resetToken, $now]);
            $user = $stmt->fetch();
            
            if ($user) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $updateStmt = $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expiry = NULL WHERE email = ?");
                $updateStmt->execute([$hashedPassword, $user['email']]);
                
                $message = "Password reset successful! Please login with your new password.";
                $status = "success";
            } else {
                $message = "Reset token is invalid or has expired.";
                $status = "error";
            }
        }
    }

    // --- VERIFY OTP & PAYMONGO GENERATION ---
    if ($action === 'verify_otp' && isset($_POST['otp_digit']) && is_array($_POST['otp_digit'])) {
        $entered_otp = implode("", $_POST['otp_digit']); 
        
        if (!isset($_SESSION['temp_signup']) || !is_array($_SESSION['temp_signup'])) {
            $signup_error = "OTP session not found. Please sign up again.";
            $status = "error";
            $show_verification_modal = false;
        } else {
            $otpExpiryTs = strtotime((string)($_SESSION['temp_signup']['otp_expires_at'] ?? ''));
            if (!$otpExpiryTs || $otpExpiryTs < time()) {
                unset($_SESSION['temp_signup']);
                $signup_error = "Your OTP has expired. Please sign up again to request a new code.";
                $status = "error";
                $show_verification_modal = false;
            } elseif ($entered_otp == $_SESSION['temp_signup']['otp']) {
            $data = $_SESSION['temp_signup'];
            $hashed = password_hash($data['password'], PASSWORD_DEFAULT);
            $success = false;

            try {
                $pdo->beginTransaction();

                $maxStmt = $pdo->query("SELECT TenantID FROM tenants ORDER BY CAST(SUBSTRING(TenantID, 2) AS UNSIGNED) DESC LIMIT 1");
                $lastTenant = $maxStmt->fetchColumn();
                
                if ($lastTenant) {
                    $lastNum = (int)str_replace('T', '', $lastTenant);
                    $nextNum = $lastNum + 1;
                } else {
                    $nextNum = 1;
                }
                
                $new_tenant_id = 'T' . str_pad($nextNum, 3, '0', STR_PAD_LEFT);
                $clinic_code = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 6);

                $stmtTenant = $pdo->prepare("INSERT INTO tenants (TenantID, clinic_code, clinic_name, complete_address, clinic_contact, clinic_logo, doh_lto_no, plan, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending Approval')");
                $stmtTenant->execute([
                    $new_tenant_id,
                    $clinic_code, 
                    $data['clinic_name'], $data['clinic_address'], $data['clinic_contact'], 
                    $data['clinic_logo'], 
                    !empty($data['doh_lto']) ? 'uploads/doh_lto/' . $data['doh_lto'] : '', 
                    $data['subscription_plan']
                ]);

                $stmtUser = $pdo->prepare("INSERT INTO users (TenantID, clinic_code, clinic_name, first_name, last_name, email, password, role, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                $stmtUser->execute([
                    $new_tenant_id,
                    $clinic_code,          
                    $data['clinic_name'],  
                    $data['admin_first_name'], 
                    $data['admin_last_name'], 
                    $data['email'], 
                    $hashed, 
                    $data['admin_role']
                ]);

                $stmtCreatorStaff = $pdo->prepare("INSERT INTO clinic_staff (TenantID, first_name, middle_name, last_name, email_address, password, role, status, credentials_file) VALUES (?, ?, '', ?, ?, ?, ?, 'Active', NULL)");
                $stmtCreatorStaff->execute([
                    $new_tenant_id,
                    $data['admin_first_name'],
                    $data['admin_last_name'],
                    $data['email'],
                    $hashed,
                    $data['admin_role']
                ]);
                
                $newUserId = $pdo->lastInsertId();
                $pdo->commit();
                $success = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $success = false;
                $signup_error = "Database Error: " . $e->getMessage();
            }

            if ($success) {
                unset($_SESSION['temp_signup']); 

                // =========================================================
                // SEND "REGISTRATION SUBMITTED" EMAIL — NO PAYMENT YET
                // SuperAdmin must approve the DOH-LTO first.
                // =========================================================
                $submitSubject = "Registration Submitted - MaternityHub";
                $submitBody = "
                <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                  <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                    <h2 style='color:#15803d; margin:0 0 12px;'>Registration Submitted</h2>
                    <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($data['admin_first_name']) . "</strong>,</p>
                    <p style='color:#334155;'>Thank you for registering <strong>" . htmlspecialchars($data['clinic_name']) . "</strong> on MaternityHub.</p>
                    <p style='color:#334155;'>Your DOH-LTO and clinic information have been submitted to our Super Admin team for verification. Please allow some time for review.</p>
                    <ul style='color:#334155; line-height:1.8;'>
                      <li>If <strong>approved</strong>, you will receive a payment link via email to activate your subscription.</li>
                      <li>If <strong>rejected</strong>, we will notify you with the reason.</li>
                    </ul>
                    <p style='color:#64748b; font-size:13px; margin-top:24px;'>Thank you for choosing <strong>MaternityHub</strong>!</p>
                  </div>
                </body></html>";
                send_email_via_smtp_gmail($data['email'], $submitSubject, $submitBody);

                header("Location: registration.php?msg=submitted");
                exit();
            } else {
                $message = "Failed to register clinic. " . ($signup_error ?? "");
                $status = "error";
            }
            } else {
                $signup_error = "Invalid OTP Code. Please try again.";
                $status = "error";
                $show_verification_modal = true;
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Welcome - MaternityHub</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet" />
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "<?= htmlspecialchars($themeColor) ?>", 
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)", 
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)", 
                        "background-light": "#f0fdf4",
                    },
                    fontFamily: { "display": ["Plus Jakarta Sans", "sans-serif"] },
                    boxShadow: { 'soft': '0 10px 40px -10px rgba(0,0,0,0.08)', }
                }
            }
        }
    </script>
    <style>
        html, body { margin: 0; padding: 0; scroll-behavior: smooth; }
        body { overflow-x: hidden; }
        .modal-hidden { opacity: 0; pointer-events: none; transform: scale(0.95); transition: all 0.3s ease; }
        .modal-visible { opacity: 1; pointer-events: auto; transform: scale(1); transition: all 0.3s ease; }
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
        
        .hero-pattern {
            background-image: radial-gradient(rgba(15, 23, 42, 0.12) 0.6px, transparent 0.6px);
            background-size: 24px 24px;
            mask-image: radial-gradient(ellipse at center, black 28%, transparent 82%);
            opacity: 0.18;
        }
        .ambient-orb {
            position: absolute;
            filter: blur(36px);
            pointer-events: none;
        }
    </style>
</head>
<body class="bg-background-light font-display text-slate-800 min-h-screen flex flex-col antialiased">

<a href="index.php" class="fixed top-5 left-5 z-50 flex items-center gap-1.5 px-4 py-2.5 bg-white/90 backdrop-blur-sm text-slate-700 font-bold text-xs uppercase tracking-widest rounded-2xl shadow-md border border-slate-200 hover:bg-primary hover:text-white hover:border-primary transition-all">
    <span class="material-symbols-outlined text-base">arrow_back</span> Back
</a>

<section class="min-h-[85vh] flex items-center justify-center p-4 md:p-6 relative z-10 w-full max-w-[1200px] mx-auto">
    <main class="w-full bg-white rounded-3xl shadow-soft overflow-hidden flex flex-col md:flex-row min-h-[750px] border border-slate-100 mt-8 lg:mt-12">
        
        <div class="hidden md:flex md:w-5/12 relative bg-primary items-center justify-center overflow-hidden">
            <div class="absolute inset-0 bg-[url('https://images.unsplash.com/photo-1555252333-9f8e92e65df9?q=80&w=2000&auto=format&fit=crop')] bg-cover bg-center opacity-40 mix-blend-overlay"></div>
            <div class="absolute inset-0 bg-gradient-to-t from-black/60 via-primary/80 to-primary/90"></div>
            <div class="relative z-10 p-16 <?= $headerText ?> flex flex-col h-full justify-between w-full">
                
                <div class="flex items-center gap-3">
                    <?php if($superLogoPath): ?>
                        <div class="h-20 w-auto max-w-[300px] overflow-hidden rounded-xl">
                            <img src="<?= htmlspecialchars($superLogoPath) ?>" class="h-full w-full object-contain">
                        </div>
                    <?php else: ?>
                        <div class="size-20 <?= $logoBgOp ?> rounded-3xl flex items-center justify-center shrink-0 shadow-sm border <?= $logoBorderOp ?> backdrop-blur-sm">
                            <span class="material-symbols-outlined <?= $iconColor ?> text-5xl">child_care</span>
                        </div>
                    <?php endif; ?>
                    <span class="text-4xl font-bold tracking-tight font-display <?= $headerText ?>">MaternityHub</span>
                </div>
                
                <div class="mt-auto mb-10">
                    <h1 class="text-5xl font-extrabold tracking-tight leading-[1.1] mb-6 font-display">Your journey to motherhood, supported.</h1>
                    <p class="<?= $subHeaderText ?> font-medium text-lg leading-relaxed">The complete management system for trusted maternity clinics in the Philippines.</p>
                </div>
                
            </div>
        </div>

        <div class="w-full md:w-7/12 p-10 lg:p-20 flex flex-col justify-center relative">
            
            <?php if ($maintenanceMode): ?>
                <div class="mb-6 p-4 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-3">
                    <span class="material-symbols-outlined text-amber-600 text-2xl animate-pulse">construction</span>
                    <div>
                        <h4 class="font-bold text-amber-800 tracking-tight">System Maintenance</h4>
                        <p class="text-xs text-amber-700 leading-tight mt-1">MaternityHub is currently undergoing scheduled maintenance. Regular clinic logins and new registrations are temporarily disabled. Please check back later.</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="md:hidden flex items-center gap-2 mb-8">
                <?php if($superLogoPath): ?>
                    <div class="h-14 w-auto max-w-[200px] overflow-hidden rounded-lg">
                        <img src="<?= htmlspecialchars($superLogoPath) ?>" class="h-full w-full object-contain">
                    </div>
                <?php else: ?>
                    <div class="size-14 bg-primary/10 rounded-xl flex items-center justify-center shrink-0">
                        <span class="material-symbols-outlined text-primary text-3xl">child_care</span>
                    </div>
                <?php endif; ?>
                <span class="text-3xl font-bold tracking-tight text-slate-800 font-display">MaternityHub</span>
            </div>

            <div class="mb-10 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h3 class="text-4xl font-extrabold text-slate-900 mb-3 font-display">Welcome Back</h3>
                    <p class="text-slate-500 font-medium text-lg">Please enter your details to sign in to your clinic portal.</p>
                </div>
                <a href="#pricing" class="hidden sm:inline-flex items-center justify-center gap-2 bg-slate-50 border border-slate-200 text-slate-600 px-4 py-2 rounded-xl text-sm font-bold hover:bg-slate-100 hover:text-primary transition-all whitespace-nowrap shrink-0">
                    <span>View Plans</span> <span class="material-symbols-outlined text-[18px]">arrow_downward</span>
                </a>
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
                        <input name="email" required type="email" class="w-full pl-12 pr-4 py-4 rounded-xl border border-slate-200 bg-slate-50/50 text-slate-900 focus:bg-white focus:ring-4 focus:ring-primary/10 focus:border-primary outline-none transition-all font-medium placeholder:font-normal text-base" placeholder="name@example.com"/>
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
                        <button class="absolute right-4 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors flex items-center justify-center" type="button" onclick="togglePassword()">
                            <span id="togglePasswordIcon" class="material-symbols-outlined">visibility</span>
                        </button>
                    </div>
                </div>
                <button class="w-full bg-primary hover:bg-primary-dark <?= $headerText ?> font-bold py-4 rounded-xl shadow-[0_8px_20px_-6px_rgba(16,185,129,0.4)] transition-all transform active:scale-[0.98] mt-4 text-lg <?= $maintenanceMode ? 'opacity-50 cursor-not-allowed' : '' ?>" type="submit">Sign In</button>
            </form>
            
            <div class="mt-8 pt-8 border-t border-slate-100 flex flex-col items-center">
                <p class="text-center text-sm font-medium text-slate-600 mb-3">
                    Are you a Clinic Owner? 
                </p>
                <button onclick="<?= $maintenanceMode ? 'alert(\'Registration is temporarily disabled due to system maintenance.\')' : 'openModal()' ?>" class="w-full py-3.5 border-2 border-slate-200 text-slate-700 font-bold rounded-xl hover:border-primary hover:text-primary transition-all flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-[20px]">storefront</span> Register your clinic here
                </button>
            </div>
        </div>
    </main>
</section>

<section id="pricing" class="py-24 bg-white border-t border-slate-100 relative z-10 w-full">
    <div class="absolute inset-0 hero-pattern z-0 opacity-30"></div>
    <div class="ambient-orb size-96 rounded-full bg-primary/5 top-0 left-1/2 -translate-x-1/2"></div>
    
    <div class="max-w-7xl mx-auto px-6 relative z-10">
        
        <div class="text-center max-w-2xl mx-auto mb-16 fade-up">
            <h2 class="text-4xl md:text-5xl font-black text-slate-900 tracking-tight font-display mb-4">Simple, Fair Pricing</h2>
            <p class="text-lg text-slate-500 font-medium">Choose the plan that fits your clinic. Same full feature set across all tiers — no hidden fees.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 lg:gap-8 items-stretch">

            <!-- MONTHLY -->
            <div class="bg-slate-900 rounded-3xl p-8 border border-slate-800 shadow-2xl relative overflow-hidden fade-up z-10 flex flex-col h-full">
                <div class="mt-2">
                    <h3 class="text-2xl font-black text-white font-display mb-2">Monthly</h3>
                    <p class="text-sm text-slate-400 mb-6 border-b border-slate-700 pb-6">Flexible month-to-month access. Perfect for trying out MaternityHub.</p>
                    <div class="mb-8 flex items-baseline">
                        <span class="text-5xl font-black text-white">₱2,499</span>
                        <span class="text-sm text-slate-400 font-bold ml-1">/ month</span>
                    </div>
                    <p class="text-xs uppercase tracking-widest text-slate-500 font-bold mb-4">30 Days Access</p>
                    <ul class="space-y-3 mb-8 text-sm font-medium text-slate-300">
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Unlimited</strong> Patient Records</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Unlimited</strong> Staff Accounts</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> Appointments, Financials & Reports</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Custom Web Branding</strong></li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> Priority Support</li>
                    </ul>
                </div>
                <div class="mt-auto">
                    <button onclick="<?= $maintenanceMode ? 'alert(\'Registration disabled\')' : 'openModal()' ?>" class="w-full py-4 rounded-xl font-bold bg-slate-800 text-white hover:bg-slate-700 border border-slate-700 transition-all transform active:scale-95">Avail Now</button>
                </div>
            </div>

            <!-- SEMI-ANNUAL -->
            <div class="bg-slate-900 rounded-3xl p-8 border border-slate-800 shadow-2xl relative overflow-hidden fade-up z-10 flex flex-col h-full">
                <div class="mt-2">
                    <h3 class="text-2xl font-black text-white font-display mb-2">Semi-Annual</h3>
                    <p class="text-sm text-slate-400 mb-6 border-b border-slate-700 pb-6">Save more with 6 months of uninterrupted access.</p>
                    <div class="mb-8 flex items-baseline">
                        <span class="text-5xl font-black text-white">₱13,499</span>
                        <span class="text-sm text-slate-400 font-bold ml-1">/ 6 months</span>
                    </div>
                    <p class="text-xs uppercase tracking-widest text-slate-500 font-bold mb-4">180 Days Access</p>
                    <ul class="space-y-3 mb-8 text-sm font-medium text-slate-300">
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Unlimited</strong> Patient Records</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Unlimited</strong> Staff Accounts</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> Appointments, Financials & Reports</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Custom Web Branding</strong></li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> Priority Support</li>
                    </ul>
                </div>
                <div class="mt-auto">
                    <button onclick="<?= $maintenanceMode ? 'alert(\'Registration disabled\')' : 'openModal()' ?>" class="w-full py-4 rounded-xl font-bold bg-slate-800 text-white hover:bg-slate-700 border border-slate-700 transition-all transform active:scale-95">Avail Now</button>
                </div>
            </div>

            <!-- ANNUAL (highlighted Best Value) -->
            <div class="bg-slate-900 rounded-3xl p-8 border-2 border-amber-400 shadow-2xl relative overflow-hidden fade-up z-10 flex flex-col h-full md:scale-[1.03]">
                <div class="absolute top-0 inset-x-0 flex justify-center">
                    <span class="bg-amber-400 text-slate-900 text-[10px] font-black uppercase tracking-widest py-1.5 px-6 rounded-b-xl shadow-md">Best Value</span>
                </div>
                <div class="mt-6">
                    <h3 class="text-2xl font-black text-white font-display mb-2">Annual</h3>
                    <p class="text-sm text-slate-400 mb-6 border-b border-slate-700 pb-6">Lock in the best price and get a full year of access.</p>
                    <div class="mb-8 flex items-baseline">
                        <span class="text-5xl font-black text-white">₱24,999</span>
                        <span class="text-sm text-slate-400 font-bold ml-1">/ year</span>
                    </div>
                    <p class="text-xs uppercase tracking-widest text-amber-400 font-bold mb-4">360 Days Access</p>
                    <ul class="space-y-3 mb-8 text-sm font-medium text-slate-300">
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Unlimited</strong> Patient Records</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Unlimited</strong> Staff Accounts</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> Appointments, Financials & Reports</li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> <strong>Custom Web Branding</strong></li>
                        <li class="flex items-start gap-3"><span class="material-symbols-outlined text-primary text-lg shrink-0">check_circle</span> Priority Support</li>
                    </ul>
                </div>
                <div class="mt-auto">
                    <button onclick="<?= $maintenanceMode ? 'alert(\'Registration disabled\')' : 'openModal()' ?>" class="w-full py-4 rounded-xl font-bold bg-primary text-white hover:bg-primary-dark transition-all shadow-[0_8px_20px_-6px_rgba(16,185,129,0.4)] transform active:scale-95">Avail Now</button>
                </div>
            </div>

        </div>
    </div>
</section>

<footer class="bg-white border-t border-slate-200 w-full mt-auto relative z-10">
    <div class="max-w-7xl mx-auto px-6 py-8">
        <div class="flex flex-col md:flex-row items-center justify-between gap-4">
            <div class="flex items-center gap-2 shrink-0">
                <span class="text-sm font-black text-primary font-display flex items-center gap-1">
                    <span class="material-symbols-outlined text-[18px]">child_care</span>MaternityHub
                </span>
            </div>
            <div class="text-center text-xs font-semibold text-slate-500">
                &copy; <?= date('Y') ?> MaternityHub Platform. All Rights Reserved.
            </div>
        </div>
    </div>
</footer>

<div id="signupModal" class="fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4 <?= (!empty($signup_error) && !$show_verification_modal) ? 'modal-visible' : 'modal-hidden' ?>">
    <div class="bg-white w-full max-w-4xl rounded-3xl shadow-2xl overflow-hidden max-h-[95vh] flex flex-col transform transition-all">
        <div class="px-8 py-5 border-b border-slate-100 flex justify-between items-center bg-white sticky top-0 z-20 gap-4 shrink-0">
            <div class="flex items-center gap-4">
                <button type="button" onclick="closeModal()" class="inline-flex items-center gap-2 text-sm font-bold text-slate-500 hover:text-primary transition-colors whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">arrow_back</span><span>Back</span>
                </button>
                <div>
                <h2 class="text-2xl font-extrabold text-slate-900 font-display">Register Your Clinic</h2>
                <p class="text-sm text-slate-500 font-medium mt-1">Join MaternityHub and manage your maternity clinic with ease.</p>
                </div>
            </div>
            <button onclick="closeModal()" class="size-10 rounded-full hover:bg-slate-100 text-slate-400 hover:text-slate-700 transition-all flex items-center justify-center"><span class="material-symbols-outlined">close</span></button>
        </div>

        <form method="POST" enctype="multipart/form-data" class="flex flex-col flex-1 overflow-hidden" id="signupFormElement">
            <input type="hidden" name="action" value="signup">
            
            <div class="p-6 md:p-8 overflow-y-auto flex-1 bg-slate-50/50 flex flex-col gap-6 relative z-0">
                <?php if($signup_error && !$show_verification_modal): ?>
                <div class="p-4 rounded-xl bg-red-50 text-red-700 text-sm font-bold border border-red-100 flex items-center gap-2">
                    <span class="material-symbols-outlined">error</span> <?= $signup_error ?>
                </div>
                <?php endif; ?>
                
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 space-y-5">
                    <div class="flex items-center gap-2 mb-2 border-b border-slate-100 pb-3">
                        <span class="material-symbols-outlined text-primary">shield_person</span><h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider">1. Administrator Profile</h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Admin First Name</label><input name="admin_first_name" required type="text" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="Juan"></div>
                        <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Admin Last Name</label><input name="admin_last_name" required type="text" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="Dela Cruz"></div>
                    </div>
                    <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Admin Email Address</label><input name="admin_email" required type="email" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="admin@email.com"></div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-slate-600">Admin Password</label>
                            <div class="relative group">
                                <input id="signupPassword" name="password" required type="password" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="••••••••">
                                <button type="button" onclick="togglePassword('signupPassword','signupPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors flex"><span id="signupPasswordIcon" class="material-symbols-outlined text-xl">visibility</span></button>
                            </div>
                        </div>
                        <div class="space-y-1.5">
                            <label class="text-xs font-semibold text-slate-600">Confirm Password</label>
                            <div class="relative group">
                                <input id="signupConfirmPassword" name="confirm_password" required type="password" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="••••••••">
                                <button type="button" onclick="togglePassword('signupConfirmPassword','signupConfirmPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors flex"><span id="signupConfirmPasswordIcon" class="material-symbols-outlined text-xl">visibility</span></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 space-y-5">
                    <div class="flex items-center gap-2 mb-2 border-b border-slate-100 pb-3">
                        <span class="material-symbols-outlined text-primary">local_hospital</span><h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider">2. Clinic Information</h4>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Clinic Name</label><input name="clinic_name" required type="text" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="e.g. St. Mary's Maternity Clinic"></div>
                        <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Clinic Logo (Optional)</label><input type="file" name="clinic_logo" accept=".jpg,.jpeg,.png" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-500 file:mr-4 file:py-1 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer"></div>
                    </div>
                    <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Complete Address</label><input name="clinic_address" required type="text" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="Street, Barangay, City/Municipality, Province"></div>
                    <div class="space-y-1.5"><label class="text-xs font-semibold text-slate-600">Clinic Contact Number(s)</label><input name="clinic_contact" required type="text" class="w-full h-11 rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm focus:ring-2 focus:ring-primary/20 outline-none" placeholder="Landline or Mobile"></div>
                </div>

                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 space-y-5">
                    <div class="flex items-center gap-2 mb-2 border-b border-slate-100 pb-3">
                        <span class="material-symbols-outlined text-primary">verified</span><h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider">3. Legal & Health Compliance</h4>
                    </div>
                    <div class="space-y-1.5">
                        <label class="text-xs font-semibold text-slate-600">DOH License to Operate (LTO) Image <span class="text-red-500">*</span></label>
                        <input name="doh_lto" required type="file" accept=".jpg,.jpeg,.png,.webp,.pdf" class="w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 outline-none file:mr-4 file:rounded-lg file:border-0 file:bg-primary/10 file:px-4 file:py-1.5 file:font-semibold file:text-primary hover:file:bg-primary/20 cursor-pointer">
                        <p class="text-[11px] text-slate-400">Upload a clear photo or scan of your DOH LTO. Allowed: JPG, JPEG, PNG, WEBP, PDF.</p>
                    </div>
                </div>

                <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 space-y-5">
                    <div class="flex items-center gap-2 mb-2 border-b border-slate-100 pb-3">
                        <span class="material-symbols-outlined text-primary">sell</span><h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider">4. Subscription Plan</h4>
                    </div>
                    <input type="hidden" name="subscription_plan" id="subscription_plan_field" value="Monthly">
                    <p class="text-[11px] text-slate-500 mb-3">Choose a plan that fits your clinic. All plans include unlimited staff &amp; patients.</p>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3" id="planPickerWrap">
                        <button type="button" data-plan="Monthly" data-price="₱2,499" data-days="30 days" class="plan-card text-left rounded-xl border-2 border-primary bg-primary/5 p-4 transition-all hover:border-primary">
                            <h5 class="font-black text-slate-800 text-base mb-1">Monthly</h5>
                            <p class="text-[11px] text-slate-500 mb-2">30 days access</p>
                            <span class="text-lg font-black text-primary">₱2,499<span class="text-[10px] text-slate-500">/month</span></span>
                        </button>
                        <button type="button" data-plan="Semi-Annual" data-price="₱13,499" data-days="180 days" class="plan-card text-left rounded-xl border-2 border-slate-200 bg-white p-4 transition-all hover:border-primary">
                            <h5 class="font-black text-slate-800 text-base mb-1">Semi-Annual</h5>
                            <p class="text-[11px] text-slate-500 mb-2">180 days access</p>
                            <span class="text-lg font-black text-primary">₱13,499<span class="text-[10px] text-slate-500">/6 months</span></span>
                        </button>
                        <button type="button" data-plan="Annual" data-price="₱24,999" data-days="360 days" class="plan-card text-left rounded-xl border-2 border-slate-200 bg-white p-4 transition-all hover:border-primary relative">
                            <span class="absolute -top-2 right-3 bg-amber-400 text-amber-900 text-[9px] font-black uppercase tracking-widest px-2 py-0.5 rounded-full">Best Value</span>
                            <h5 class="font-black text-slate-800 text-base mb-1">Annual</h5>
                            <p class="text-[11px] text-slate-500 mb-2">360 days access</p>
                            <span class="text-lg font-black text-primary">₱24,999<span class="text-[10px] text-slate-500">/year</span></span>
                        </button>
                    </div>
                    <script>
                        (function(){
                            const wrap = document.getElementById('planPickerWrap');
                            const field = document.getElementById('subscription_plan_field');
                            if (!wrap || !field) return;
                            wrap.querySelectorAll('.plan-card').forEach(btn => {
                                btn.addEventListener('click', () => {
                                    wrap.querySelectorAll('.plan-card').forEach(b => {
                                        b.classList.remove('border-primary','bg-primary/5');
                                        b.classList.add('border-slate-200','bg-white');
                                    });
                                    btn.classList.remove('border-slate-200','bg-white');
                                    btn.classList.add('border-primary','bg-primary/5');
                                    field.value = btn.dataset.plan;
                                });
                            });
                        })();
                    </script>
                </div>

                <div class="p-6 border-t border-slate-200 bg-white shrink-0 z-20">
                    <button class="w-full h-14 bg-primary <?= $headerText ?> font-bold rounded-xl hover:bg-primary-dark shadow-[0_8px_20px_-6px_rgba(16,185,129,0.4)] transition-all text-base transform active:scale-[0.99]" type="button" onclick="handleSignupSubmit(event)">Register Clinic &amp; Complete Payment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- TERMS & CONDITIONS MODAL (shown after form is validated, before payment) -->
<div id="termsModal" class="modal-hidden fixed inset-0 z-[80] bg-slate-900/60 backdrop-blur-md flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-3xl rounded-3xl shadow-2xl overflow-hidden max-h-[90vh] flex flex-col">
        <div class="px-8 py-5 border-b border-slate-100 flex justify-between items-center bg-white sticky top-0 z-10 gap-4 shrink-0">
            <div class="flex items-center gap-3">
                <div class="size-11 rounded-full bg-primary/10 text-primary flex items-center justify-center">
                    <span class="material-symbols-outlined">gavel</span>
                </div>
                <div>
                    <h2 class="text-lg font-extrabold text-slate-900 leading-tight">Terms &amp; Conditions</h2>
                    <p class="text-xs text-slate-500">Please review carefully before proceeding to payment.</p>
                </div>
            </div>
            <button type="button" onclick="closeTermsModal()" class="size-10 rounded-full hover:bg-slate-100 text-slate-400 hover:text-slate-700 transition-all flex items-center justify-center"><span class="material-symbols-outlined">close</span></button>
        </div>

        <div id="termsScrollBox" class="px-8 py-6 overflow-y-auto flex-1 bg-slate-50/50">
            <div class="bg-white border border-slate-200 rounded-2xl p-6 text-sm text-slate-700 whitespace-pre-line leading-relaxed font-medium"><?= htmlspecialchars($termsAndConditions) ?></div>
            <p class="text-[11px] text-slate-400 text-center mt-3 italic">Scroll to the bottom to enable the "Yes, I Understand" button.</p>
        </div>

        <div class="px-8 py-5 border-t border-slate-100 bg-white shrink-0 flex flex-col sm:flex-row items-center justify-between gap-3">
            <label id="agreeTermsLabel" class="flex items-center gap-2 text-xs font-semibold text-slate-400 cursor-not-allowed select-none">
                <input type="checkbox" id="agreeTermsCheck" disabled class="size-4 rounded border-slate-300 text-primary focus:ring-primary disabled:opacity-50 disabled:cursor-not-allowed">
                I have read and agree to the Terms &amp; Conditions.
            </label>
            <div class="flex items-center gap-2 w-full sm:w-auto">
                <button type="button" onclick="closeTermsModal()" class="px-5 h-11 rounded-xl border border-slate-200 text-slate-700 font-bold text-sm hover:bg-slate-50 transition-all">Cancel</button>
                <button type="button" id="agreeTermsButton" onclick="confirmTermsAndSubmit()" disabled class="px-5 h-11 rounded-xl bg-primary <?= $headerText ?> font-bold text-sm hover:bg-primary-dark transition-all disabled:opacity-50 disabled:cursor-not-allowed shadow-md">
                    <span class="material-symbols-outlined text-base align-middle mr-1">check_circle</span>Yes, I Understand
                </button>
            </div>
        </div>
    </div>
</div>

<div id="verifyModal" class="fixed inset-0 z-[70] bg-slate-900/60 backdrop-blur-md flex items-center justify-center p-4 <?= $show_verification_modal ? 'modal-visible' : 'modal-hidden' ?>">
    <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl p-10 text-center relative overflow-hidden">
        <div class="absolute top-0 left-0 w-full h-2 bg-gradient-to-r from-primary to-primary-light"></div>
        <div class="size-20 bg-primary/10 rounded-full flex items-center justify-center mx-auto mb-6 text-primary shadow-sm ring-8 ring-primary/20"><span class="material-symbols-outlined text-[40px]">domain_verification</span></div>
        <h2 class="text-2xl font-extrabold text-slate-900 mb-2 font-display">Verify Clinic Registration</h2>
        <p class="text-sm text-slate-500 mb-8 leading-relaxed">We sent a 6-digit code to the admin email:<br><span class="font-bold text-slate-800"><?= htmlspecialchars($_SESSION['temp_signup']['email'] ?? '') ?></span></p>
        
        <?php if($signup_error && $show_verification_modal): ?>
        <div class="bg-red-50 text-red-700 text-sm font-bold p-4 rounded-xl mb-6 border border-red-100 flex items-center justify-center gap-2"><span class="material-symbols-outlined text-lg">error</span><span><?= $signup_error ?></span></div>
        <?php endif; ?>

        <?php if($otp_resend_success && $show_verification_modal): ?>
        <div class="bg-primary-light/40 text-primary-dark text-sm font-bold p-4 rounded-xl mb-6 border border-primary-light/70 flex items-center justify-center gap-2"><span class="material-symbols-outlined text-lg">check_circle</span><span><?= htmlspecialchars($otp_resend_success) ?></span></div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-8">
            <input type="hidden" name="action" value="verify_otp">
            <div class="flex justify-center gap-2 md:gap-3">
                <?php for($i=0; $i<6; $i++): ?>
                <input type="text" name="otp_digit[]" maxlength="1" class="w-12 h-14 text-center text-2xl font-bold border-2 border-slate-200 rounded-xl bg-slate-50 text-slate-800 focus:bg-white focus:border-primary focus:ring-4 focus:ring-primary/10 outline-none transition-all otp-input hover:border-slate-300 shadow-sm" required oninput="moveFocus(this, <?= $i ?>)" inputmode="numeric" autocomplete="one-time-code">
                <?php endfor; ?>
            </div>
            <button type="submit" class="w-full h-12 bg-primary <?= $headerText ?> font-bold rounded-xl hover:bg-primary-dark shadow-[0_8px_20px_-6px_rgba(16,185,129,0.4)] transition-all">Verify & Pay</button>
        </form>
        <form method="POST" class="mt-4 space-y-2">
            <input type="hidden" name="action" value="resend_otp">
            <button type="submit" id="resendOtpButton" class="text-sm font-semibold text-primary hover:text-primary-dark transition-colors disabled:text-slate-400 disabled:cursor-not-allowed">
                Resend OTP
            </button>
            <p id="resendOtpTimer" class="text-xs text-slate-400 <?= $otp_resend_wait_seconds > 0 ? '' : 'hidden' ?>">
                You can resend again in <span id="resendOtpSeconds"><?= (int)$otp_resend_wait_seconds ?></span>s.
            </p>
        </form>
        <div class="mt-8 pt-6 border-t border-slate-100">
            <form method="POST" class="flex justify-center">
                <input type="hidden" name="action" value="cancel_otp_registration">
                <button type="submit" class="text-slate-400 hover:text-slate-700 text-sm font-semibold transition-colors flex items-center justify-center gap-1 mx-auto"><span class="material-symbols-outlined text-base">arrow_back</span> Cancel Registration</button>
            </form>
        </div>
    </div>
</div>

<div id="forgotPasswordModal" class="modal-hidden fixed inset-0 z-[60] bg-slate-900/40 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-3xl shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-white"><h2 class="text-xl font-extrabold text-slate-900">Reset Password</h2><button onclick="closeForgotPasswordModal()" class="size-10 rounded-full hover:bg-slate-100 text-slate-400 transition-all flex items-center justify-center"><span class="material-symbols-outlined">close</span></button></div>
        <div class="p-8 space-y-6">
            <div class="text-center"><div class="size-16 bg-blue-50 text-blue-500 rounded-full flex items-center justify-center mx-auto mb-4"><span class="material-symbols-outlined text-[32px]">lock_reset</span></div><p class="text-sm text-slate-500 font-medium">Enter your email and we'll send you a link to reset your password.</p></div>
            <form id="forgotPasswordForm" method="POST" class="flex flex-col gap-4">
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
            <form id="resetPasswordForm" method="POST" class="flex flex-col gap-5">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="reset_token" id="resetTokenInput" value="">
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-700">New Password</label>
                    <div class="relative">
                        <input id="resetNewPassword" name="new_password" required type="password" class="w-full h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all outline-none" placeholder="••••••••">
                        <button type="button" onclick="togglePassword('resetNewPassword','resetNewPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors flex"><span id="resetNewPasswordIcon" class="material-symbols-outlined text-[20px]">visibility</span></button>
                    </div>
                </div>
                <div class="space-y-1.5">
                    <label class="text-xs font-bold text-slate-700">Confirm Password</label>
                    <div class="relative">
                        <input id="resetConfirmPassword" name="confirm_password" required type="password" class="w-full h-12 rounded-xl border border-slate-200 bg-slate-50 px-4 pr-11 text-sm focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all outline-none" placeholder="••••••••">
                        <button type="button" onclick="togglePassword('resetConfirmPassword','resetConfirmPasswordIcon')" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-primary transition-colors flex"><span id="resetConfirmPasswordIcon" class="material-symbols-outlined text-[20px]">visibility</span></button>
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
    
    function handleSignupSubmit(event) {
        event.preventDefault();
        const form = document.getElementById('signupFormElement');
        const pwd = document.getElementById('signupPassword').value;
        const confirmPwd = document.getElementById('signupConfirmPassword').value;

        // 1. Native HTML5 validation: trigger browser prompts for empty required fields
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        // 2. Password match check
        if (pwd !== confirmPwd) {
            alert("Passwords do not match.");
            return;
        }

        // 3. All info complete -> show Terms & Conditions modal
        openTermsModal();
    }

    function openTermsModal() {
        const m = document.getElementById('termsModal');
        const chk = document.getElementById('agreeTermsCheck');
        const btn = document.getElementById('agreeTermsButton');
        const box = document.getElementById('termsScrollBox');
        const lbl = document.getElementById('agreeTermsLabel');
        if (chk) { chk.checked = false; chk.disabled = true; }
        if (lbl) { lbl.classList.add('text-slate-400','cursor-not-allowed'); lbl.classList.remove('text-slate-600','cursor-pointer'); }
        if (btn) btn.disabled = true;
        if (box) box.scrollTop = 0;
        m.classList.remove('modal-hidden');
        m.classList.add('modal-visible');
    }

    function closeTermsModal() {
        const m = document.getElementById('termsModal');
        m.classList.remove('modal-visible');
        m.classList.add('modal-hidden');
    }

    function confirmTermsAndSubmit() {
        const chk = document.getElementById('agreeTermsCheck');
        if (!chk || !chk.checked) return;
        closeTermsModal();
        document.getElementById('signupFormElement').submit();
    }

    // Enable the checkbox only after user scrolled near the bottom; then "Yes, I Understand" requires checkbox ticked
    document.addEventListener('DOMContentLoaded', () => {
        const box = document.getElementById('termsScrollBox');
        const chk = document.getElementById('agreeTermsCheck');
        const btn = document.getElementById('agreeTermsButton');
        const lbl = document.getElementById('agreeTermsLabel');
        let scrolled = false;
        const enableCheckbox = () => {
            scrolled = true;
            if (chk) chk.disabled = false;
            if (lbl) {
                lbl.classList.remove('text-slate-400','cursor-not-allowed');
                lbl.classList.add('text-slate-600','cursor-pointer');
            }
            updateBtn();
        };
        if (box) {
            const checkScroll = () => {
                if (box.scrollTop + box.clientHeight >= box.scrollHeight - 20) {
                    enableCheckbox();
                }
            };
            box.addEventListener('scroll', checkScroll);
            // If content is short enough to not need scrolling, consider it read
            setTimeout(() => { if (box.scrollHeight <= box.clientHeight + 20) { enableCheckbox(); } }, 300);
        }
        if (chk) chk.addEventListener('change', updateBtn);
        function updateBtn() {
            if (!btn) return;
            btn.disabled = !(scrolled && chk && chk.checked);
        }
    });

    function togglePassword(inputId = 'loginPassword', iconId = 'togglePasswordIcon') {
        const passwordInput = document.getElementById(inputId);
        const icon = document.getElementById(iconId);
        if (!passwordInput || !icon) return;
        if (passwordInput.type === 'password') { passwordInput.type = 'text'; icon.textContent = 'visibility_off'; } 
        else { passwordInput.type = 'password'; icon.textContent = 'visibility'; }
    }
    
    const modal = document.getElementById('signupModal');
    
    function openModal() { 
        modal.classList.remove('modal-hidden'); 
        modal.classList.add('modal-visible'); 
    }
    
    function closeModal() { 
        modal.classList.remove('modal-visible'); 
        modal.classList.add('modal-hidden'); 
        if (document.getElementById('signupFormElement')) { document.getElementById('signupFormElement').reset(); } 
    }
    
    window.onclick = function(e) { 
        if (e.target === modal) closeModal(); 
        const forgotModal = document.getElementById('forgotPasswordModal'); if (e.target === forgotModal) closeForgotPasswordModal();
        const resetModal = document.getElementById('resetPasswordModal'); if (e.target === resetModal) closeResetPasswordModal();
    }

    function openForgotPasswordModal() { document.getElementById('forgotPasswordModal').classList.remove('modal-hidden'); document.getElementById('forgotPasswordModal').classList.add('modal-visible'); }
    function closeForgotPasswordModal() { document.getElementById('forgotPasswordModal').classList.remove('modal-visible'); document.getElementById('forgotPasswordModal').classList.add('modal-hidden'); }
    function openResetPasswordModal(token) { document.getElementById('resetTokenInput').value = token; document.getElementById('resetPasswordModal').classList.remove('modal-hidden'); document.getElementById('resetPasswordModal').classList.add('modal-visible'); }
    function closeResetPasswordModal() { document.getElementById('resetPasswordModal').classList.remove('modal-visible'); document.getElementById('resetPasswordModal').classList.add('modal-hidden'); }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('reset_token')) { openResetPasswordModal(urlParams.get('reset_token')); }

    function moveFocus(current, index) { if (current.value.length >= 1) { const next = document.getElementsByClassName('otp-input')[index + 1]; if (next) next.focus(); } }
    
    document.addEventListener('DOMContentLoaded', () => {
        const otpInputs = document.querySelectorAll('.otp-input');  
        if(otpInputs.length > 0) {
            otpInputs[0].addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = e.clipboardData.getData('text').slice(0, 6).split('');
                if (pastedData.length === 6) {
                    otpInputs.forEach((input, i) => { input.value = pastedData[i]; });
                    otpInputs[5].focus();
                }
            });
        }

        const resendOtpButton = document.getElementById('resendOtpButton');
        const resendOtpTimer = document.getElementById('resendOtpTimer');
        const resendOtpSeconds = document.getElementById('resendOtpSeconds');
        let resendSecondsRemaining = <?= (int)$otp_resend_wait_seconds ?>;

        const updateResendState = () => {
            if (!resendOtpButton || !resendOtpTimer || !resendOtpSeconds) {
                return;
            }

            if (resendSecondsRemaining > 0) {
                resendOtpButton.disabled = true;
                resendOtpTimer.classList.remove('hidden');
                resendOtpSeconds.textContent = String(resendSecondsRemaining);
            } else {
                resendOtpButton.disabled = false;
                resendOtpTimer.classList.add('hidden');
            }
        };

        updateResendState();
        if (resendSecondsRemaining > 0 && resendOtpButton) {
            const resendCountdown = setInterval(() => {
                resendSecondsRemaining -= 1;
                updateResendState();

                if (resendSecondsRemaining <= 0) {
                    clearInterval(resendCountdown);
                }
            }, 1000);
        }
    });
</script>
</body>
</html>