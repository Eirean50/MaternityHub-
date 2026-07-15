<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

// Force MySQL session to Philippine Time (UTC+8) so NOW() and stored timestamps match Asia/Manila
try { $pdo->exec("SET time_zone = '+08:00'"); } catch (PDOException $e) { /* silent */ }

// AUTO-FIX: ENSURE NEW COLUMNS EXIST IN `tenants`
try { $pdo->query("SELECT rejection_reason FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD rejection_reason TEXT NULL"); } catch (PDOException $ex) {} }
try { $pdo->query("SELECT suspension_reason FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD suspension_reason TEXT NULL"); } catch (PDOException $ex) {} }
try { $pdo->query("SELECT approved_at FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD approved_at DATETIME NULL"); } catch (PDOException $ex) {} }
try { $pdo->query("SELECT expires_at FROM tenants LIMIT 1"); }
catch (PDOException $e) { try { $pdo->exec("ALTER TABLE tenants ADD expires_at DATETIME NULL"); } catch (PDOException $ex) {} }

// AUTO-MIGRATE: widen status column so 'Expired' (and any future status) is not rejected by ENUM constraint
try { $pdo->exec("ALTER TABLE tenants MODIFY status VARCHAR(50) NOT NULL DEFAULT 'Pending Approval'"); } catch (PDOException $e) {}

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

// AUTO-EXPIRE: Active clinics past expires_at -> Expired (notifies each owner via email)
if (!function_exists('expire_due_clinics_and_notify')) {
    function expire_due_clinics_and_notify($pdo) {
        try {
            $stmt = $pdo->query("
                SELECT t.TenantID, t.clinic_name, t.clinic_code,
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
// SIMPLE EMAIL HELPER (PHP mail)
// ==============================================================
function send_tenant_email($to, $subject, $bodyHtml) {
    $sender_email = 'maternityhub@alwaysdata.net';
    $sender_name = 'MaternityHub System';
    $headers  = 'MIME-Version: 1.0' . "\r\n";
    $headers .= 'Content-type: text/html; charset=UTF-8' . "\r\n";
    $headers .= 'From: ' . $sender_name . ' <' . $sender_email . '>' . "\r\n";
    $headers .= 'Reply-To: ' . $sender_email . "\r\n";
    $headers .= 'X-Mailer: PHP/' . phpversion();
    @mail($to, $subject, $bodyHtml, $headers);
}

// ==============================================================
// AUDIT LOG HELPER FUNCTION
// ==============================================================
function log_audit($pdo, $user_name, $role, $action_type, $details) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $currentTime = date('Y-m-d H:i:s'); 
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_name, $role, $action_type, $details, $ip, $currentTime]);
    } catch (PDOException $e) {
        // Silent fail
    }
}
// ==============================================================

// ==============================================================
// --- LOGOUT HANDLER (WITH AUDIT TRACKING) ---
// ==============================================================
if (isset($_GET['logout'])) {
    // 1. I-save muna sa audit logs bago burahin ang session
    if (isset($_SESSION['full_name']) && isset($_SESSION['role'])) {
        $logoutName = $_SESSION['full_name'];
        $logoutRole = $_SESSION['role'];
        
        $isSuperAdmin = (strtolower(trim((string)$logoutRole)) === 'superadmin' || strpos(strtolower(trim((string)$logoutName)), 'eirean') !== false);
        $auditRole = $isSuperAdmin ? 'SuperAdmin' : $logoutRole;
        $auditDetails = $isSuperAdmin ? 'Super Admin safely logged out of the platform.' : 'User securely logged out of their clinic portal.';
        
        log_audit($pdo, $logoutName, $auditRole, 'Logout', $auditDetails);
    }
    
    // 2. Burahin ang session
    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    
    // 3. Redirect sa login page
    header("Location: registration.php");
    exit();
}
// ==============================================================

// --- SYSTEM SETTINGS (MAINTENANCE MODE CHECK) ---
$settingsFile = __DIR__ . '/maternityhub_settings.json';
$maintenanceMode = false;
$superThemeColor = '#10b981';
if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $maintenanceMode = $settings['maintenance_mode'] ?? false;
    $superThemeColor = $settings['super_theme_color'] ?? '#10b981';
}

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR
// ==============================================================
$hex = ltrim($superThemeColor, '#');
if (strlen($hex) == 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
}
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150);

$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerBgOp = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/10';
$headerBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';
$headerHoverOp = $isLightTheme ? 'hover:bg-slate-900/20' : 'hover:bg-white/20';

// --- SUPER ADMIN SECURITY CHECK ---
$isSuperAdmin = false;
if (isset($_SESSION['user_id'])) {
    $role = strtolower(trim($_SESSION['role'] ?? ''));
    $fullName = $_SESSION['full_name'] ?? '';
    
    if ($role === 'superadmin' || strpos(strtolower($fullName), 'eirean') !== false || $role === 'admin') {
        $isSuperAdmin = true; 
    }
}

if (!$isSuperAdmin) {
    header("Location: index.php");
    exit();
}

$displayName = $_SESSION['full_name'] ?? 'Super Admin';
$dbError = null; // Changed to dbError para ma-display properly sa UI

// Get Base URL for Copy Link feature
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$appBaseUrl = $protocol . "://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']);

// --- DETERMINE CURRENT VIEW (Active/Suspended/Unpaid vs Archived vs Appeals) ---
$currentView = $_GET['view'] ?? 'active';

// AUTO-MIGRATE: ensure suspension_appeals table exists (matches schema in suspended.php)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS suspension_appeals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        TenantID VARCHAR(64) NULL,
        clinic_code VARCHAR(64) NULL,
        clinic_name VARCHAR(255) NULL,
        appellant_name VARCHAR(255) NOT NULL,
        appellant_email VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Pending Review',
        ip_address VARCHAR(64) NULL,
        created_at DATETIME NOT NULL
    )");
} catch (PDOException $e) { /* silent */ }
try { $pdo->query("SELECT response_message FROM suspension_appeals LIMIT 1"); }
catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE suspension_appeals ADD response_message TEXT NULL");
        $pdo->exec("ALTER TABLE suspension_appeals ADD responded_at DATETIME NULL");
        $pdo->exec("ALTER TABLE suspension_appeals ADD responded_by VARCHAR(255) NULL");
    } catch (PDOException $ex) {}
}
// AUTO-MIGRATE: archived flag for appeals
try { $pdo->query("SELECT archived FROM suspension_appeals LIMIT 1"); }
catch (PDOException $e) {
    try {
        $pdo->exec("ALTER TABLE suspension_appeals ADD archived TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE suspension_appeals ADD archived_at DATETIME NULL");
        $pdo->exec("ALTER TABLE suspension_appeals ADD archived_by VARCHAR(255) NULL");
    } catch (PDOException $ex) {}
}

// --- HANDLE APPEAL ARCHIVE / UNARCHIVE / DELETE ---
if (isset($_GET['appeal_action']) && isset($_GET['appeal_id'])) {
    $appealAction = $_GET['appeal_action'];
    $aid = (int)$_GET['appeal_id'];
    if ($aid > 0) {
        try {
            $stmtAi = $pdo->prepare("SELECT clinic_name FROM suspension_appeals WHERE id = ? LIMIT 1");
            $stmtAi->execute([$aid]);
            $appealRow = $stmtAi->fetch(PDO::FETCH_ASSOC);
            $appealClinicName = $appealRow['clinic_name'] ?? 'Unknown';

            if ($appealAction === 'archive') {
                $u = $pdo->prepare("UPDATE suspension_appeals SET archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?");
                $u->execute([$displayName, $aid]);
                log_audit($pdo, $displayName, 'SuperAdmin', 'Appeal Archived', "Archived suspension appeal #$aid for: $appealClinicName.");
                header("Location: tenantmanagement.php?view=appeals&msg=appeal_archived");
                exit();
            } elseif ($appealAction === 'unarchive') {
                $u = $pdo->prepare("UPDATE suspension_appeals SET archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?");
                $u->execute([$aid]);
                log_audit($pdo, $displayName, 'SuperAdmin', 'Appeal Restored', "Restored suspension appeal #$aid for: $appealClinicName.");
                header("Location: tenantmanagement.php?view=appeals&appeal_filter=archived&msg=appeal_unarchived");
                exit();
            } elseif ($appealAction === 'delete') {
                $u = $pdo->prepare("DELETE FROM suspension_appeals WHERE id = ?");
                $u->execute([$aid]);
                log_audit($pdo, $displayName, 'SuperAdmin', 'Appeal Deleted', "Permanently deleted suspension appeal #$aid for: $appealClinicName.");
                header("Location: tenantmanagement.php?view=appeals&appeal_filter=archived&msg=appeal_deleted");
                exit();
            }
        } catch (PDOException $e) { /* silent */ }
    }
}

// --- HANDLE APPEAL RESPONSE (Super Admin replies to a suspension appeal) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['respond_appeal'])) {
    $appealId = (int)($_POST['appeal_id'] ?? 0);
    $responseMsg = trim($_POST['response_message'] ?? '');
    $newStatus = trim($_POST['appeal_status'] ?? 'Reviewed');
    if ($appealId > 0 && $responseMsg !== '') {
        try {
            $stmtA = $pdo->prepare("SELECT * FROM suspension_appeals WHERE id = ? LIMIT 1");
            $stmtA->execute([$appealId]);
            $appeal = $stmtA->fetch(PDO::FETCH_ASSOC);
            if ($appeal) {
                $upd = $pdo->prepare("UPDATE suspension_appeals SET response_message = ?, responded_at = NOW(), responded_by = ?, status = ? WHERE id = ?");
                $upd->execute([$responseMsg, $displayName, $newStatus, $appealId]);

                // Email the appellant with the response
                $sender = 'MaternityHub System <maternityhub@alwaysdata.net>';
                $headers = "MIME-Version: 1.0\r\nContent-type: text/html; charset=UTF-8\r\nFrom: $sender\r\nReply-To: maternityhub@alwaysdata.net\r\nX-Mailer: PHP/" . phpversion();
                $subject = "Response to Your Suspension Appeal — " . ($appeal['clinic_name'] ?? 'MaternityHub');
                $body = "<html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>"
                      . "<div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>"
                      . "<h2 style='color:#0f172a; margin:0 0 12px;'>Response to Your Appeal</h2>"
                      . "<p style='color:#334155;'>Hi <strong>" . htmlspecialchars($appeal['appellant_name']) . "</strong>,</p>"
                      . "<p style='color:#334155;'>Thank you for reaching out regarding the suspension of <strong>" . htmlspecialchars($appeal['clinic_name']) . "</strong>. The MaternityHub Super Admin team has reviewed your appeal.</p>"
                      . "<div style='background:#f1f5f9; border-left:4px solid #0f172a; padding:14px 18px; border-radius:6px; margin:18px 0;'>"
                      . "<p style='margin:0;color:#0f172a;font-size:13px;font-weight:bold;'>Response:</p>"
                      . "<p style='margin:6px 0 0;color:#334155;font-size:14px;line-height:1.6;'>" . nl2br(htmlspecialchars($responseMsg)) . "</p>"
                      . "</div>"
                      . "<p style='color:#334155;'>Status: <strong>" . htmlspecialchars($newStatus) . "</strong></p>"
                      . "<p style='color:#64748b; font-size:13px; margin-top:24px; line-height:1.5;'>Best regards,<br><strong style='color:#0f172a;'>MaternityHub Support</strong><br>" . htmlspecialchars($displayName) . "</p>"
                      . "</div></body></html>";
                @mail($appeal['appellant_email'], $subject, $body, $headers);

                log_audit($pdo, $displayName, 'SuperAdmin', 'Appeal Responded', "Responded to suspension appeal #{$appealId} for: " . ($appeal['clinic_name'] ?? 'Unknown') . ". New status: $newStatus.");
            }
        } catch (PDOException $e) { /* silent */ }
    }
    header("Location: tenantmanagement.php?view=appeals&msg=appeal_responded");
    exit();
}

// --- TENANT ACTIONS (Approve / Reject / Deactivate / Activate / Archive / Restore / Delete) ---
if (isset($_GET['action']) && isset($_GET['tenant_id'])) {
    $action = $_GET['action'];
    $targetTenant = $_GET['tenant_id'];

    try {
        $stmtClinicName = $pdo->prepare("SELECT t.clinic_name, t.clinic_code, t.plan, t.status, (SELECT email FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_email, (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) AS owner_fname FROM tenants t WHERE t.TenantID = ?");
        $stmtClinicName->execute([$targetTenant]);
        $clinicData = $stmtClinicName->fetch(PDO::FETCH_ASSOC);
        $clinicNameForLog = $clinicData ? $clinicData['clinic_name'] : "Unknown Clinic";
        $ownerEmail = $clinicData['owner_email'] ?? '';
        $ownerFname = $clinicData['owner_fname'] ?? '';
        $previousStatus = $clinicData['status'] ?? '';

        if ($action === 'activate' || $action === 'approve') {
            // NEW FLOW: If was Pending Approval (or unknown/empty initial state) → set Pending Payment + email payment link.
            $prevNorm = strtolower(trim((string)$previousStatus));
            $isApprovalState = ($prevNorm === 'pending approval' || $prevNorm === '' || !in_array($prevNorm, ['active','suspended','archived','rejected','pending payment']));
            if ($isApprovalState) {
                $stmt = $pdo->prepare("UPDATE tenants SET status = 'Pending Payment', approved_at = NOW(), rejection_reason = NULL WHERE TenantID = ?");
                $stmt->execute([$targetTenant]);
                // Keep user status as 'Pending' until payment completes (login handler redirects to PayMongo)

                if (!empty($ownerEmail)) {
                    $loginUrl = $appBaseUrl . '/registration.php';
                    $approveSubject = "Clinic Approved – Complete Your Subscription Payment";
                    $approveBody = "
                    <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                      <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                        <h2 style='color:#15803d; margin:0 0 12px;'>Your Clinic Has Been Approved! ✅</h2>
                        <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($ownerFname) . "</strong>,</p>
                        <p style='color:#334155;'>Great news! Your clinic <strong>" . htmlspecialchars($clinicNameForLog) . "</strong> has been approved by our Super Admin team.</p>
                        <p style='color:#334155;'>To activate your subscription, please <strong>log in below</strong> and you will be redirected to complete your payment securely.</p>
                        <div style='text-align:center; margin:24px 0;'>
                          <a href='" . htmlspecialchars($loginUrl) . "' style='background:#15803d; color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:bold;'>Log in &amp; Pay Now</a>
                        </div>
                        <p style='color:#64748b; font-size:13px;'>Thank you for choosing <strong>MaternityHub</strong>!</p>
                      </div>
                    </body></html>";
                    send_tenant_email($ownerEmail, $approveSubject, $approveBody);
                }

                log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Approved', "Approved registration for: $clinicNameForLog (ID: $targetTenant). Pending Payment.");
                header("Location: tenantmanagement.php?msg=tenant_approved");
                exit();
            }

            // FALLBACK (Suspended/Rejected reactivation) — original behavior
            $stmt = $pdo->prepare("UPDATE tenants SET status = 'Active', rejection_reason = NULL, suspension_reason = NULL, suspended_at = NULL WHERE TenantID = ?");
            $stmt->execute([$targetTenant]);
            $stmtUser = $pdo->prepare("UPDATE users SET status = 'Active' WHERE TenantID = ?");
            $stmtUser->execute([$targetTenant]);

            // Auto-resolve any pending/open suspension appeals for this clinic
            try {
                $autoResolveMsg = "Clinic has been reactivated by the Super Admin team. Access to your portal has been fully restored.";
                $updAppeals = $pdo->prepare("UPDATE suspension_appeals
                    SET status = 'Resolved',
                        response_message = COALESCE(NULLIF(response_message, ''), ?),
                        responded_by = COALESCE(responded_by, ?),
                        responded_at = COALESCE(responded_at, NOW())
                    WHERE TenantID = ? AND status IN ('Pending Review', 'Reviewed', 'Under Review')");
                $updAppeals->execute([$autoResolveMsg, $displayName, $targetTenant]);
            } catch (PDOException $e) { /* silent */ }

            // Send reactivation email if reactivating from Suspended
            if (strtolower(trim((string)$previousStatus)) === 'suspended' && !empty($ownerEmail)) {
                $loginUrl = $appBaseUrl . '/registration.php';
                $reactivateSubject = "Clinic Reactivated – Welcome Back to MaternityHub";
                $reactivateBody = "
                <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                  <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                    <h2 style='color:#10b981; margin:0 0 12px;'>Clinic Reactivated</h2>
                    <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($ownerFname) . "</strong>,</p>
                    <p style='color:#334155;'>Good news! Your clinic <strong>" . htmlspecialchars($clinicNameForLog) . "</strong> has been <strong style='color:#10b981;'>reactivated</strong> by the MaternityHub Super Admin team.</p>
                    <p style='color:#334155;'>You and your staff may now log back in and resume full access to your clinic portal.</p>
                    <div style='text-align:center; margin:24px 0;'>
                      <a href='" . htmlspecialchars($loginUrl) . "' style='background:#10b981;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;'>Log In to MaternityHub</a>
                    </div>
                    <p style='color:#64748b; font-size:13px; margin-top:24px;'>Thank you for being part of <strong>MaternityHub</strong>.</p>
                  </div>
                </body></html>";
                send_tenant_email($ownerEmail, $reactivateSubject, $reactivateBody);
            }

            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Reactivated', "Restored system access for: $clinicNameForLog (ID: $targetTenant).");

            $msg = ($action === 'approve') ? 'tenant_approved' : 'tenant_activated';
            header("Location: tenantmanagement.php?msg=$msg&name=" . urlencode($clinicNameForLog));
            exit();

        } elseif ($action === 'deactivate') {
            // Require a suspension reason (POST or GET)
            $suspensionReason = trim($_POST['suspension_reason'] ?? $_GET['suspension_reason'] ?? '');
            if ($suspensionReason === '') {
                header("Location: tenantmanagement.php?msg=suspend_no_reason");
                exit();
            }

            $stmt = $pdo->prepare("UPDATE tenants SET status = 'Suspended', suspension_reason = ?, suspended_at = NOW() WHERE TenantID = ?");
            $stmt->execute([$suspensionReason, $targetTenant]);
            $stmtUser = $pdo->prepare("UPDATE users SET status = 'Suspended' WHERE TenantID = ?");
            $stmtUser->execute([$targetTenant]);

            // Notify clinic owner via email with the reason
            if (!empty($ownerEmail)) {
                $suspendSubject = "Clinic Suspended – MaternityHub";
                $suspendBody = "
                <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                  <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                    <h2 style='color:#d97706; margin:0 0 12px;'>Clinic Suspended</h2>
                    <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($ownerFname) . "</strong>,</p>
                    <p style='color:#334155;'>We regret to inform you that your clinic <strong>" . htmlspecialchars($clinicNameForLog) . "</strong> has been <strong style='color:#d97706;'>suspended</strong> by the MaternityHub Super Admin team. You and your staff will not be able to access the portal until the issue is resolved.</p>
                    <div style='background:#fffbeb; border-left:4px solid #d97706; padding:14px 18px; border-radius:6px; margin:18px 0;'>
                      <p style='margin:0;color:#78350f;font-size:13px;'><strong>Reason for Suspension:</strong></p>
                      <p style='margin:6px 0 0;color:#92400e;font-size:14px;'>" . nl2br(htmlspecialchars($suspensionReason)) . "</p>
                    </div>
                    <p style='color:#334155;'>If you believe this is a mistake or wish to appeal, please reach out to us at <a href='mailto:maternityhub@alwaysdata.net'>maternityhub@alwaysdata.net</a>.</p>
                    <p style='color:#64748b; font-size:13px; margin-top:24px;'>— The MaternityHub Team</p>
                  </div>
                </body></html>";
                send_tenant_email($ownerEmail, $suspendSubject, $suspendBody);
            }

            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Suspended', "Suspended operations and revoked access for: $clinicNameForLog (ID: $targetTenant). Reason: $suspensionReason");

            header("Location: tenantmanagement.php?msg=tenant_deactivated");
            exit();

        } elseif ($action === 'reject') {
            // Accept rejection reason via POST or GET
            $rejectionReason = trim($_POST['rejection_reason'] ?? $_GET['rejection_reason'] ?? '');
            if ($rejectionReason === '') {
                header("Location: tenantmanagement.php?msg=reject_no_reason");
                exit();
            }

            $stmt = $pdo->prepare("UPDATE tenants SET status = 'Rejected', rejection_reason = ? WHERE TenantID = ?");
            $stmt->execute([$rejectionReason, $targetTenant]);
            $stmtUser = $pdo->prepare("UPDATE users SET status = 'Rejected' WHERE TenantID = ?");
            $stmtUser->execute([$targetTenant]);

            if (!empty($ownerEmail)) {
                $loginUrl = $appBaseUrl . '/registration.php';
                $rejectSubject = "Clinic Registration Rejected – MaternityHub";
                $rejectBody = "
                <html><body style='font-family: Arial, sans-serif; background:#f4f7f6; padding:30px; margin:0;'>
                  <div style='background:#fff; max-width:560px; margin:auto; border-radius:12px; padding:32px; border:1px solid #e2e8f0;'>
                    <h2 style='color:#dc2626; margin:0 0 12px;'>Registration Rejected</h2>
                    <p style='color:#334155;'>Hi <strong>" . htmlspecialchars($ownerFname) . "</strong>,</p>
                    <p style='color:#334155;'>We regret to inform you that your clinic registration for <strong>" . htmlspecialchars($clinicNameForLog) . "</strong> has been <strong style='color:#dc2626;'>rejected</strong> by our Super Admin team.</p>
                    <div style='background:#fef2f2; border-left:4px solid #dc2626; padding:14px 18px; border-radius:6px; margin:18px 0;'>
                      <p style='margin:0 0 6px; color:#7f1d1d; font-weight:bold; font-size:13px; text-transform:uppercase; letter-spacing:1px;'>Reason for Rejection</p>
                      <p style='margin:0; color:#334155;'>" . nl2br(htmlspecialchars($rejectionReason)) . "</p>
                    </div>
                    <p style='color:#334155;'>You may <strong>log in to your clinic</strong> and resubmit the photo of your clinic's <strong>DOH-LTO</strong> for re-approval.</p>
                    <div style='text-align:center; margin:24px 0;'>
                      <a href='" . htmlspecialchars($loginUrl) . "' style='background:#dc2626; color:#fff; text-decoration:none; padding:12px 28px; border-radius:8px; font-weight:bold;'>Log in &amp; Resubmit DOH-LTO</a>
                    </div>
                    <p style='color:#64748b; font-size:13px; margin-top:24px;'>Thank you for your interest in <strong>MaternityHub</strong>.</p>
                  </div>
                </body></html>";
                send_tenant_email($ownerEmail, $rejectSubject, $rejectBody);
            }

            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Rejected', "Rejected clinic registration for: $clinicNameForLog (ID: $targetTenant). Reason: $rejectionReason");

            header("Location: tenantmanagement.php?msg=tenant_rejected");
            exit();
            
        } elseif ($action === 'archive') { 
            $stmtUserArch = $pdo->prepare("UPDATE users SET status = 'Archived' WHERE TenantID = ?");
            $stmtUserArch->execute([$targetTenant]);
            
            $stmtTenantArch = $pdo->prepare("UPDATE tenants SET status = 'Archived' WHERE TenantID = ?");
            $stmtTenantArch->execute([$targetTenant]);
            
            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Archived', "Archived clinic and all its users: $clinicNameForLog (ID: $targetTenant).");
            
            header("Location: tenantmanagement.php?view=archived&msg=tenant_archived");
            exit();

        } elseif ($action === 'restore') { 
            // Ibalik mula sa Archive papuntang Suspended
            $stmtUserRest = $pdo->prepare("UPDATE users SET status = 'Suspended' WHERE TenantID = ?");
            $stmtUserRest->execute([$targetTenant]);
            
            $stmtTenantRest = $pdo->prepare("UPDATE tenants SET status = 'Suspended' WHERE TenantID = ?");
            $stmtTenantRest->execute([$targetTenant]);
            
            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Restored', "Restored clinic from archives: $clinicNameForLog (ID: $targetTenant). Status set to Suspended.");
            
            header("Location: tenantmanagement.php?view=archived&msg=tenant_restored");
            exit();

        } elseif ($action === 'expire') {
            // Force-expire an Active clinic
            $stmt = $pdo->prepare("UPDATE tenants SET status = 'Expired', expires_at = NOW() WHERE TenantID = ?");
            $stmt->execute([$targetTenant]);

            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Expired', "Force-expired clinic subscription: $clinicNameForLog (ID: $targetTenant). Owner must renew to regain access.");

            // Notify owner
            if (!empty($ownerEmail)) {
                $subj = "MaternityHub: Your Clinic Subscription Has Expired";
                $body = "<p>Hi <strong>" . htmlspecialchars($ownerFname) . "</strong>,</p>"
                      . "<p>Your <strong>MaternityHub</strong> subscription for <strong>" . htmlspecialchars($clinicNameForLog) . "</strong> has <strong>Expired</strong>.</p>"
                      . "<p>Please <a href='https://maternityhub.alwaysdata.net/registration.php'>log in</a> and renew your subscription to continue using the clinic portal.</p>"
                      . "<p>— MaternityHub Team</p>";
                @send_tenant_email($ownerEmail, $subj, $body);
            }

            header("Location: tenantmanagement.php?msg=tenant_expired");
            exit();

        } elseif ($action === 'unexpire') {
            // Restore from Expired -> Active with fresh plan-based expiry (admin override)
            $stmtPlanLk = $pdo->prepare("SELECT plan FROM tenants WHERE TenantID = ? LIMIT 1");
            $stmtPlanLk->execute([$targetTenant]);
            $unexInfo = mh_plan_info((string)$stmtPlanLk->fetchColumn());
            $unexDays = (int)$unexInfo['days'];
            $stmt = $pdo->prepare("UPDATE tenants SET status = 'Active', expires_at = DATE_ADD(NOW(), INTERVAL $unexDays DAY) WHERE TenantID = ?");
            $stmt->execute([$targetTenant]);
            $stmtUserAct = $pdo->prepare("UPDATE users SET status = 'Active' WHERE TenantID = ? AND status != 'Archived'");
            $stmtUserAct->execute([$targetTenant]);

            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Unexpired', "Manually un-expired clinic subscription: $clinicNameForLog (ID: $targetTenant). Subscription extended by $unexDays days.");

            if (!empty($ownerEmail)) {
                $subj = "MaternityHub: Your Clinic Subscription Has Been Restored";
                $body = "<p>Hi <strong>" . htmlspecialchars($ownerFname) . "</strong>,</p>"
                      . "<p>Good news! The Super Admin has restored your <strong>" . htmlspecialchars($clinicNameForLog) . "</strong> subscription. Your account is now <strong>Active</strong> again, valid for the next $unexDays days.</p>"
                      . "<p><a href='https://maternityhub.alwaysdata.net/registration.php'>Log in to your clinic portal</a>.</p>"
                      . "<p>— MaternityHub Team</p>";
                @send_tenant_email($ownerEmail, $subj, $body);
            }

            header("Location: tenantmanagement.php?msg=tenant_unexpired");
            exit();

        } elseif ($action === 'delete') {
            $stmtUserDel = $pdo->prepare("DELETE FROM users WHERE TenantID = ?");
            $stmtUserDel->execute([$targetTenant]);
            
            $stmtTenantDel = $pdo->prepare("DELETE FROM tenants WHERE TenantID = ?");
            $stmtTenantDel->execute([$targetTenant]);
            
            log_audit($pdo, $displayName, 'SuperAdmin', 'Tenant Deleted', "Permanently deleted clinic and all its users: $clinicNameForLog (ID: $targetTenant).");
            
            header("Location: tenantmanagement.php?view=" . urlencode($currentView) . "&msg=tenant_deleted");
            exit();
        }
    } catch (PDOException $e) {
        $dbError = "Database update error: " . $e->getMessage();
    }
}

// =========================================================================
// FETCH METRICS
// =========================================================================
$totalClinics = 0; $activeClinics = 0; $suspendedClinics = 0; $pendingClinics = 0; $totalUsers = 0;
try {
    $totalClinics = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status != 'Archived'")->fetchColumn();
    $activeClinics = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Active'")->fetchColumn();
    // Pinalitan yung Pending Approval ginawang Suspended Clinics sa metric
    $suspendedClinics = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status = 'Suspended'")->fetchColumn();
    $pendingClinics = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE status IN ('Pending Approval','Pending Payment')")->fetchColumn();
    $totalUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE LOWER(role) != 'superadmin' AND status != 'Archived'")->fetchColumn();
} catch (PDOException $e) {}

// --- COUNT PENDING APPEALS (for badge) ---
$pendingAppealsCount = 0;
try { $pendingAppealsCount = (int)$pdo->query("SELECT COUNT(*) FROM suspension_appeals WHERE status = 'Pending Review' AND (archived = 0 OR archived IS NULL)")->fetchColumn(); }
catch (PDOException $e) {}

// --- FETCH APPEALS (for appeals view) ---
$appealFilter = $_GET['appeal_filter'] ?? 'active'; // 'active' or 'archived'
$archivedAppealsCount = 0;
try { $archivedAppealsCount = (int)$pdo->query("SELECT COUNT(*) FROM suspension_appeals WHERE archived = 1")->fetchColumn(); }
catch (PDOException $e) {}

$appeals = [];
if ($currentView === 'appeals') {
    try {
        $archCond = ($appealFilter === 'archived') ? 'sa.archived = 1' : '(sa.archived = 0 OR sa.archived IS NULL)';
        $appeals = $pdo->query("SELECT sa.*, t.status AS tenant_status
            FROM suspension_appeals sa
            LEFT JOIN tenants t ON t.TenantID = sa.TenantID
            WHERE $archCond
            ORDER BY (sa.status = 'Pending Review') DESC, sa.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $appeals = []; }
}

// --- FETCH TENANTS DATA BASED ON CURRENT VIEW ---
try {
    if ($currentView === 'archived') {
        $query = "
            SELECT t.*, 
                   (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) as owner_fname,
                   (SELECT last_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) as owner_lname,
                   (SELECT email FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) as owner_email_users
            FROM tenants t 
            WHERE t.status = 'Archived'
            ORDER BY t.created_at DESC, t.TenantID DESC
        ";
    } else {
        $query = "
            SELECT t.*, 
                   (SELECT first_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) as owner_fname,
                   (SELECT last_name FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) as owner_lname,
                   (SELECT email FROM users WHERE TenantID = t.TenantID ORDER BY id ASC LIMIT 1) as owner_email_users
            FROM tenants t 
            WHERE t.status != 'Archived'
            ORDER BY t.created_at DESC, t.TenantID DESC
        ";
    }
    
    $stmtTenants = $pdo->query($query);
    $clinics = $stmtTenants->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $dbError = "Query failed: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Tenant Management - MaternityHub</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        "primary": "<?= htmlspecialchars($superThemeColor) ?>", "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 70%, black)", "primary-light": "color-mix(in srgb, <?= htmlspecialchars($superThemeColor) ?> 20%, white)",
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
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .scrollable-box { scroll-behavior: smooth; }
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

<div id="actionModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 transform scale-95 opacity-0 transition-all duration-300" id="actionModalBox">
        <div class="text-center">
            <div id="actionIconContainer" class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner">
                <span id="actionIcon" class="material-symbols-outlined text-3xl"></span>
            </div>
            <h3 id="actionTitle" class="text-2xl font-black text-slate-900 mb-2 tracking-tight"></h3>
            <p id="actionMessage" class="text-slate-500 text-sm mb-6 leading-relaxed"></p>
            
            <div class="bg-slate-50 rounded-xl p-4 mb-6 border border-slate-100 text-left flex items-center gap-3">
                <div class="size-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 shrink-0">
                    <span class="material-symbols-outlined text-lg">domain</span>
                </div>
                <div class="overflow-hidden">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Target Clinic</p>
                    <p id="actionTargetClinic" class="text-sm font-bold text-slate-800 truncate"></p>
                </div>
            </div>

            <div class="flex gap-3">
                <button onclick="closeActionModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <a id="actionConfirmBtn" href="#" class="flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center">Confirm</a>
            </div>
        </div>
    </div>
</div>

<header class="h-20 bg-primary border-b border-primary-dark flex items-center justify-between px-6 md:px-12 sticky top-0 z-50 shrink-0 shadow-soft transition-colors duration-300 <?= $headerText ?>">
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
            <p class="text-[9px] opacity-80 mt-1 uppercase tracking-widest">Platform Owner</p>
        </div>
        <button onclick="openLogoutModal()" class="flex items-center gap-2 <?= $headerBgOp ?> <?= $headerHoverOp ?> border <?= $headerBorderOp ?> px-4 py-2 rounded-xl text-xs font-bold transition-all shadow-sm">
            <span class="material-symbols-outlined text-sm">logout</span><span class="hidden md:inline">Logout</span>
        </button>
    </div>
</header>

<div class="flex-1 flex overflow-hidden">
    <aside class="w-72 bg-white border-r border-slate-200 hidden md:flex flex-col shrink-0 shadow-soft z-10">
        <nav class="flex-1 p-6 h-full flex flex-col gap-2">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest px-4 mb-2 mt-4">Platform Management</p>
            
            <a href="superadmin.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span class="text-base">Dashboard</span>
            </a>
            
            <a href="tenantmanagement.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] bg-primary <?= $headerText ?> font-bold shadow-md transition-all hover:scale-[1.02]">
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
            
            <a href="systemsettings.php" class="flex items-center gap-4 px-6 py-4 rounded-[1.5rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                <span class="material-symbols-outlined text-2xl">settings</span> <span class="text-base">System Settings</span>
            </a>
        </nav>
    </aside>

    <main class="flex-1 overflow-y-auto p-4 md:p-8 bg-slate-50">
        <div class="max-w-[1400px] mx-auto space-y-6">
            
            <?php if($dbError): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 bg-red-100 text-red-800 border border-red-200 shadow-sm">
                    <span class="material-symbols-outlined text-xl">error</span> <?= htmlspecialchars($dbError) ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_GET['msg'])): ?>
                <?php 
                    $msgType = 'success'; $msgText = ''; $msgIcon = 'check_circle';
                    $msgClinicName = isset($_GET['name']) ? trim((string)$_GET['name']) : '';
                    if($_GET['msg'] == 'tenant_approved') { $msgText = "Clinic registration approved! They can now access their portal."; }
                    elseif($_GET['msg'] == 'tenant_activated') {
                        $msgText = $msgClinicName !== ''
                            ? '"' . htmlspecialchars($msgClinicName) . '" has been successfully reactivated and is now Active.'
                            : "Clinic successfully reactivated and is now Active.";
                        $msgIcon = 'lock_open';
                    }
                    elseif($_GET['msg'] == 'tenant_deactivated') { $msgText = "Clinic operations suspended. Access revoked."; $msgType = 'warning'; $msgIcon = 'block'; }
                    elseif($_GET['msg'] == 'tenant_rejected') { $msgText = "Clinic registration rejected."; $msgType = 'error'; $msgIcon = 'cancel'; }
                    elseif($_GET['msg'] == 'tenant_archived') { $msgText = "Clinic successfully moved to archives."; $msgType = 'warning'; $msgIcon = 'archive'; }
                    elseif($_GET['msg'] == 'tenant_restored') { $msgText = "Clinic restored from archives (Suspended status)."; }
                    elseif($_GET['msg'] == 'tenant_deleted') { $msgText = "Clinic permanently deleted."; $msgType = 'error'; $msgIcon = 'delete_forever'; }
                    elseif($_GET['msg'] == 'reject_no_reason') { $msgText = "Rejection cancelled — a reason is required to reject a clinic."; $msgType = 'warning'; $msgIcon = 'info'; }
                    elseif($_GET['msg'] == 'suspend_no_reason') { $msgText = "Suspension cancelled — a reason is required to suspend a clinic."; $msgType = 'warning'; $msgIcon = 'info'; }
                    elseif($_GET['msg'] == 'tenant_expired') { $msgText = "Clinic subscription marked as Expired. Owner has been notified to renew."; $msgType = 'warning'; $msgIcon = 'schedule'; }
                    elseif($_GET['msg'] == 'tenant_unexpired') { $msgText = "Clinic subscription restored. Active for the next 30 days."; $msgType = 'success'; $msgIcon = 'restart_alt'; }
                    elseif($_GET['msg'] == 'appeal_responded') { $msgText = "Response sent to the clinic owner via email."; $msgType = 'success'; $msgIcon = 'mark_email_read'; }
                    elseif($_GET['msg'] == 'appeal_archived') { $msgText = "Suspension appeal moved to archives."; $msgType = 'warning'; $msgIcon = 'archive'; }
                    elseif($_GET['msg'] == 'appeal_unarchived') { $msgText = "Suspension appeal restored from archives."; $msgType = 'success'; $msgIcon = 'unarchive'; }
                    elseif($_GET['msg'] == 'appeal_deleted') { $msgText = "Suspension appeal permanently deleted."; $msgType = 'error'; $msgIcon = 'delete_forever'; }
                ?>
                <?php if($msgText): ?>
                <div class="p-4 rounded-xl text-sm font-bold flex items-center gap-3 <?= $msgType === 'success' ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : ($msgType === 'warning' ? 'bg-amber-100 text-amber-800 border border-amber-200' : 'bg-red-100 text-red-800 border border-red-200') ?> animate-in slide-in-from-top-2 shadow-sm">
                    <span class="material-symbols-outlined"><?= $msgIcon ?></span> <?= $msgText ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($currentView !== 'archived' && $currentView !== 'appeals'): ?>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-6 mb-6">
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Total Clinics</p>
                    <h3 class="text-3xl font-black text-slate-800 leading-none"><?= number_format($totalClinics) ?></h3>
                </div>
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Active Clinics</p>
                    <h3 class="text-3xl font-black text-emerald-500 leading-none"><?= number_format($activeClinics) ?></h3>
                </div>
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">For Approval</p>
                    <h3 class="text-3xl font-black text-blue-500 leading-none"><?= number_format($pendingClinics) ?></h3>
                </div>
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Suspended Clinics</p>
                    <h3 class="text-3xl font-black text-red-500 leading-none"><?= number_format($suspendedClinics) ?></h3>
                </div>
                <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-sm">
                    <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest mb-1">Total Users (Staff)</p>
                    <h3 class="text-3xl font-black text-blue-500 leading-none"><?= number_format($totalUsers) ?></h3>
                </div>
            </div>
            <?php endif; ?>

            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">
                        <?= $currentView === 'archived' ? 'Archived Clinics' : ($currentView === 'appeals' ? 'Suspension Appeals' : 'Tenant Management') ?>
                    </h2>
                    <p class="text-slate-500 text-sm font-medium tracking-tight">
                        <?= $currentView === 'archived' ? 'View and restore archived clinic records.' : ($currentView === 'appeals' ? 'Review and respond to suspension appeals from clinic owners.' : 'Review, approve, and manage registered maternity clinics.') ?>
                    </p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                    <?php if ($currentView === 'archived' || $currentView === 'appeals'): ?>
                        <a href="tenantmanagement.php" class="flex items-center justify-center gap-2 bg-emerald-500 text-white px-4 py-3 rounded-2xl hover:bg-emerald-600 transition-all font-bold text-sm shadow-sm whitespace-nowrap">
                            <span class="material-symbols-outlined text-lg">domain</span> Active Clinics
                        </a>
                    <?php endif; ?>
                    <?php if ($currentView !== 'appeals'): ?>
                        <a href="tenantmanagement.php?view=appeals" class="relative flex items-center justify-center gap-2 bg-amber-500 text-white px-4 py-3 rounded-2xl hover:bg-amber-600 transition-all font-bold text-sm shadow-sm whitespace-nowrap">
                            <span class="material-symbols-outlined text-lg">gavel</span> Appeals
                            <?php if ($pendingAppealsCount > 0): ?>
                                <span class="absolute -top-1.5 -right-1.5 min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-black flex items-center justify-center border-2 border-white shadow"><?= $pendingAppealsCount ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($currentView !== 'archived' && $currentView !== 'appeals'): ?>
                        <a href="tenantmanagement.php?view=archived" class="flex items-center justify-center gap-2 bg-slate-800 text-white px-4 py-3 rounded-2xl hover:bg-slate-900 transition-all font-bold text-sm shadow-sm whitespace-nowrap">
                            <span class="material-symbols-outlined text-lg">archive</span> View Archives
                        </a>
                    <?php endif; ?>
                    
                    <div class="relative w-full md:w-80">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-lg">search</span>
                        <input type="text" id="clinicSearch" onkeyup="applyFiltersAndSort()" placeholder="Search by Clinic Name..." class="pl-11 pr-4 py-3 rounded-2xl border border-slate-200 text-sm focus:ring-primary focus:border-primary shadow-sm w-full font-medium">
                    </div>
                    <div class="relative w-full sm:w-48">
                        <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-lg">swap_vert</span>
                        <select id="dateSort" onchange="applyFiltersAndSort()" class="pl-11 pr-10 py-3 rounded-2xl border border-slate-200 text-sm focus:ring-primary focus:border-primary shadow-sm w-full font-medium bg-white">
                            <option value="newest">Newest First</option>
                            <option value="oldest">Oldest First</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php if ($currentView === 'appeals'): ?>
            <!-- ============== APPEALS VIEW ============== -->
            <div class="flex items-center gap-2 -mt-2 mb-1 flex-wrap">
                <a href="tenantmanagement.php?view=appeals&appeal_filter=active" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all border <?= $appealFilter !== 'archived' ? 'bg-primary text-white border-primary shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
                    <span class="material-symbols-outlined text-base">inbox</span> Active Appeals
                </a>
                <a href="tenantmanagement.php?view=appeals&appeal_filter=archived" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-xs font-bold transition-all border <?= $appealFilter === 'archived' ? 'bg-slate-800 text-white border-slate-800 shadow-sm' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50' ?>">
                    <span class="material-symbols-outlined text-base">archive</span> Archived
                    <?php if ($archivedAppealsCount > 0): ?>
                        <span class="bg-white/20 px-2 rounded-full text-[10px] font-black"><?= $archivedAppealsCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="overflow-y-auto max-h-[650px] scrollable-box w-full divide-y divide-slate-100">
                    <?php if (empty($appeals)): ?>
                        <div class="p-16 text-center text-slate-400">
                            <span class="material-symbols-outlined text-6xl mb-2 opacity-40">gavel</span>
                            <p class="font-bold text-sm">No appeals submitted yet.</p>
                            <p class="text-xs mt-1">When a suspended clinic submits an appeal, it will appear here.</p>
                        </div>
                    <?php else: foreach ($appeals as $a): 
                        $aStatus = strtolower(trim((string)$a['status']));
                        $isPending = ($aStatus === 'pending review');
                        $statusClass = $isPending ? 'bg-amber-50 text-amber-700 border-amber-200' : ($aStatus === 'resolved' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-slate-100 text-slate-600 border-slate-200');
                    ?>
                        <div class="p-6 hover:bg-slate-50/60 transition">
                            <div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap mb-2">
                                        <span class="px-2.5 py-0.5 rounded-full text-[9px] font-black uppercase tracking-widest border <?= $statusClass ?>"><?= htmlspecialchars($a['status']) ?></span>
                                        <span class="text-[11px] text-slate-400 font-bold">Appeal #<?= (int)$a['id'] ?> · <?= htmlspecialchars(date('M d, Y g:i A', strtotime($a['created_at']))) ?></span>
                                    </div>
                                    <h3 class="text-base font-black text-slate-800 tracking-tight"><?= htmlspecialchars($a['clinic_name'] ?? 'Unknown Clinic') ?> <span class="text-slate-400 font-mono text-xs">(<?= htmlspecialchars($a['clinic_code'] ?? '') ?>)</span></h3>
                                    <p class="text-xs text-slate-500 mt-1">From <strong class="text-slate-700"><?= htmlspecialchars($a['appellant_name']) ?></strong> &middot; <a href="mailto:<?= htmlspecialchars($a['appellant_email']) ?>" class="text-primary hover:underline"><?= htmlspecialchars($a['appellant_email']) ?></a></p>
                                    <div class="mt-3 bg-slate-50 border-l-4 border-amber-400 p-3 rounded-r-lg">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Appeal Message</p>
                                        <p class="text-sm text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($a['message'])) ?></p>
                                    </div>
                                    <?php if (!empty($a['response_message'])): ?>
                                    <div class="mt-3 bg-emerald-50 border-l-4 border-emerald-500 p-3 rounded-r-lg">
                                        <p class="text-[10px] font-black text-emerald-700 uppercase tracking-widest mb-1">Your Response · <?= htmlspecialchars(date('M d, Y g:i A', strtotime($a['responded_at']))) ?> by <?= htmlspecialchars($a['responded_by']) ?></p>
                                        <p class="text-sm text-emerald-900 leading-relaxed"><?= nl2br(htmlspecialchars($a['response_message'])) ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="flex flex-col gap-2 shrink-0 md:w-44">
                                    <button onclick="openRespondModal(<?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes($a['clinic_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($a['appellant_name'])) ?>', '<?= htmlspecialchars(addslashes($a['appellant_email'])) ?>')" class="inline-flex items-center justify-center gap-2 h-10 px-4 bg-primary text-white rounded-xl hover:opacity-90 font-bold text-xs transition-all shadow-sm">
                                        <span class="material-symbols-outlined text-base">reply</span> <?= !empty($a['response_message']) ? 'Send Again' : 'Respond' ?>
                                    </button>
                                    <?php
                                        $tenantStatusNorm = strtolower(trim((string)($a['tenant_status'] ?? '')));
                                        $appealStatusNorm = strtolower(trim((string)$a['status']));
                                        $clinicAlreadyActive = ($tenantStatusNorm === 'active');
                                        $appealResolved = ($appealStatusNorm === 'resolved');
                                        $canReactivate = !empty($a['TenantID']) && !$clinicAlreadyActive && !$appealResolved && in_array($tenantStatusNorm, ['suspended', 'rejected'], true);
                                    ?>
                                    <?php if ($canReactivate): ?>
                                    <button type="button" onclick="openReactivateModal('<?= htmlspecialchars(addslashes((string)$a['TenantID'])) ?>', '<?= htmlspecialchars(addslashes((string)($a['clinic_name'] ?? 'this clinic'))) ?>')" class="inline-flex items-center justify-center gap-2 h-10 px-3 bg-emerald-50 text-emerald-700 rounded-xl hover:bg-emerald-500 hover:text-white font-bold text-xs transition-all shadow-sm border border-emerald-200">
                                        <span class="material-symbols-outlined text-base">lock_open</span> Reactivate
                                    </button>
                                    <?php elseif ($clinicAlreadyActive): ?>
                                    <span class="inline-flex items-center justify-center gap-2 h-10 px-3 bg-emerald-50 text-emerald-700 rounded-xl font-bold text-[11px] border border-emerald-200" title="Clinic is already Active">
                                        <span class="material-symbols-outlined text-base">verified</span> Reactivated
                                    </span>
                                    <?php endif; ?>
                                    <?php if (!empty($a['archived']) && (int)$a['archived'] === 1): ?>
                                        <button type="button" onclick="openAppealArchiveModal('unarchive', <?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes((string)($a['clinic_name'] ?? 'this appeal'))) ?>')" class="inline-flex items-center justify-center gap-2 h-10 px-3 bg-emerald-50 text-emerald-700 rounded-xl hover:bg-emerald-500 hover:text-white font-bold text-xs transition-all shadow-sm border border-emerald-200">
                                            <span class="material-symbols-outlined text-base">unarchive</span> Restore
                                        </button>
                                        <button type="button" onclick="openAppealArchiveModal('delete', <?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes((string)($a['clinic_name'] ?? 'this appeal'))) ?>')" class="inline-flex items-center justify-center size-10 bg-red-50 text-red-600 rounded-xl hover:bg-red-600 hover:text-white transition-all shadow-sm border border-red-200" title="Permanently Delete">
                                            <span class="material-symbols-outlined text-lg">delete_forever</span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="openAppealArchiveModal('archive', <?= (int)$a['id'] ?>, '<?= htmlspecialchars(addslashes((string)($a['clinic_name'] ?? 'this appeal'))) ?>')" class="inline-flex items-center justify-center gap-2 h-10 px-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-700 hover:text-white font-bold text-xs transition-all shadow-sm border border-slate-200" title="Move appeal to archives">
                                            <span class="material-symbols-outlined text-base">archive</span> Archive
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden flex flex-col">
                <div class="overflow-x-auto overflow-y-auto max-h-[550px] scrollable-box w-full">
                    <table class="w-full text-left border-collapse relative" id="clinicsTable">
                        <thead class="sticky top-0 z-20 bg-slate-50 shadow-sm border-b border-slate-200">
                            <tr class="text-slate-400 text-[10px] uppercase tracking-widest">
                                <th class="p-5 font-black whitespace-nowrap bg-slate-50">Tenant ID</th>
                                <th class="p-5 font-black whitespace-nowrap bg-slate-50">Clinic Profile</th>
                                <th class="p-5 font-black whitespace-nowrap bg-slate-50">Clinic Owner</th>
                                <th class="p-5 font-black whitespace-nowrap bg-slate-50">Contact Info</th>

                                <th class="p-5 font-black whitespace-nowrap bg-slate-50">Status</th>
                                <th class="p-5 font-black text-right whitespace-nowrap bg-slate-50">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm font-medium text-slate-600 divide-y divide-slate-100">
                            <?php if (empty($clinics)): ?>
                                <tr><td colspan="7" class="p-10 text-center text-slate-400 italic">No clinics found in this view.</td></tr>
                            <?php else: ?>
                                <?php foreach ($clinics as $c): ?>
                                    <?php 
                                        $oFname = !empty($c['owner_fname']) ? $c['owner_fname'] : ($c['first_name'] ?? '');
                                        $oLname = !empty($c['owner_lname']) ? $c['owner_lname'] : ($c['last_name'] ?? '');
                                        $oEmail = !empty($c['owner_email_users']) ? $c['owner_email_users'] : ($c['email'] ?? '');
                                        $hasOwner = (!empty($oFname) || !empty($oEmail));
                                        
                                        // Buuin yung Clinic Link (ClinicHomepage.php ang target)
                                        $clinicLink = $appBaseUrl . '/ClinicHomepage.php?c=' . htmlspecialchars($c['clinic_code']);
                                    ?>
                                    <tr class="clinic-row hover:bg-slate-50/80 transition-colors bg-white" data-created-at="<?= strtotime($c['created_at']) ?>">
                                        <td class="p-5 font-black text-slate-500 align-top pt-6">
                                            <?= htmlspecialchars($c['TenantID']) ?><br>
                                            <span class="text-[9px] font-bold uppercase tracking-widest text-slate-400 mt-1 block">Joined <?= date('M d, Y', strtotime($c['created_at'])) ?></span>
                                        </td>
                                        
                                        <td class="p-5">
                                            <div class="flex items-start gap-4">
                                                <div class="size-14 rounded-2xl bg-slate-100 border border-slate-200 overflow-hidden shrink-0 flex items-center justify-center">
                                                    <?php if(!empty($c['clinic_logo'])): ?>
                                                        <img src="uploads/logos/<?= htmlspecialchars($c['clinic_logo']) ?>" class="size-full object-cover opacity-<?= $currentView === 'archived' ? '50' : '100' ?>">
                                                    <?php else: ?>
                                                        <span class="material-symbols-outlined text-slate-300 text-3xl">local_hospital</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div>
                                                    <p class="font-black text-slate-800 leading-tight text-base tracking-tight clinic-name"><?= htmlspecialchars($c['clinic_name']) ?></p>
                                                    
                                                    <?php if ($currentView !== 'archived'): ?>
                                                    <div class="flex items-center gap-1.5 mt-2">
                                                        <div class="bg-primary/10 text-primary px-2 py-0.5 rounded text-[10px] font-mono font-bold border border-primary/20 flex items-center gap-1 w-max">
                                                            <span class="material-symbols-outlined text-[12px]">link</span>
                                                            <?= htmlspecialchars($c['clinic_code']) ?>
                                                        </div>
                                                        <button onclick="copyLink('<?= $clinicLink ?>', this)" class="text-[10px] font-bold text-slate-500 hover:text-primary transition-colors flex items-center gap-1 bg-slate-100 hover:bg-slate-200 px-2 py-0.5 rounded border border-slate-200">
                                                            <span class="material-symbols-outlined text-[12px] copy-icon">content_copy</span> <span class="copy-text">Copy Link</span>
                                                        </button>
                                                        <?php
                                                            $dohFile = $c['doh_lto_no'] ?? '';
                                                            $dohPath = '';
                                                            if (!empty($dohFile)) {
                                                                if (str_starts_with($dohFile, 'uploads/')) { $dohPath = $dohFile; }
                                                                elseif (file_exists(__DIR__ . '/uploads/doh_lto/' . $dohFile)) { $dohPath = 'uploads/doh_lto/' . $dohFile; }
                                                            }
                                                        ?>
                                                        <?php if (!empty($dohPath)): ?>
                                                        <button onclick="viewDohLto('<?= htmlspecialchars($dohPath) ?>')" class="text-[10px] font-bold text-slate-500 hover:text-emerald-600 transition-colors flex items-center gap-1 bg-emerald-50 hover:bg-emerald-100 px-2 py-0.5 rounded border border-emerald-200">
                                                            <span class="material-symbols-outlined text-[12px]">description</span> View DOH LTO
                                                        </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>

                                        <td class="p-5 align-top pt-6">
                                            <?php if($hasOwner): ?>
                                                <p class="font-bold text-slate-800 leading-tight text-sm"><?= htmlspecialchars($oFname . ' ' . $oLname) ?></p>
                                                <p class="text-xs text-slate-500 mt-0.5"><?= htmlspecialchars($oEmail) ?></p>
                                            <?php else: ?>
                                                <p class="text-xs text-slate-400 italic">No admin assigned yet</p>
                                            <?php endif; ?>
                                        </td>

                                        <td class="p-5 align-top pt-6">
                                            <p class="text-sm font-bold text-slate-700 leading-tight flex items-center gap-1.5 mb-1"><span class="material-symbols-outlined text-[16px] text-slate-400">mail</span> <?= htmlspecialchars($c['clinic_email'] ?? $oEmail) ?></p>
                                            <p class="text-xs text-slate-500 flex items-center gap-1.5"><span class="material-symbols-outlined text-[16px] text-slate-400">call</span> <?= htmlspecialchars($c['clinic_contact'] ?? 'N/A') ?></p>
                                        </td>


                                        <td class="p-5 align-top pt-6">
                                            <?php $statusNorm = strtolower(trim((string)($c['status'] ?? ''))); ?>
                                            <?php if($statusNorm === 'archived'): ?>
                                                <span class="bg-slate-100 text-slate-600 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-slate-300 flex items-center gap-1.5 w-max"><span class="material-symbols-outlined text-[12px]">archive</span> ARCHIVED</span>
                                            <?php elseif($statusNorm === 'active'): ?>
                                                <span class="bg-emerald-50 text-emerald-600 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-emerald-100 flex items-center gap-1.5 w-max"><span class="size-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.8)]"></span> ACTIVE</span>
                                            <?php elseif($statusNorm === 'suspended'): ?>
                                                <span class="bg-red-50 text-red-600 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-red-100 flex items-center gap-1.5 w-max"><span class="size-2 rounded-full bg-red-500"></span> SUSPENDED</span>
                                            <?php elseif($statusNorm === 'rejected'): ?>
                                                <span class="bg-slate-100 text-slate-500 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-slate-200 flex items-center gap-1.5 w-max"><span class="size-2 rounded-full bg-slate-400"></span> REJECTED</span>
                                            <?php elseif($statusNorm === 'pending payment'): ?>
                                                <span class="bg-amber-50 text-amber-600 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-amber-100 flex items-center gap-1.5 w-max"><span class="size-2 rounded-full bg-amber-500 animate-pulse shadow-[0_0_8px_rgba(245,158,11,0.8)]"></span> PENDING PAYMENT</span>
                                            <?php elseif($statusNorm === 'expired'): ?>
                                                <span class="bg-rose-50 text-rose-600 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-rose-200 flex items-center gap-1.5 w-max" title="Subscription expired<?php if(!empty($c['expires_at'])): ?> on <?= htmlspecialchars(date('M d, Y', strtotime($c['expires_at']))) ?><?php endif; ?>"><span class="material-symbols-outlined text-[12px]">hourglass_disabled</span> EXPIRED</span>
                                            <?php else: /* Pending Approval, empty, or any unrecognized state defaults to FOR APPROVAL */ ?>
                                                <span class="bg-amber-50 text-amber-600 px-3 py-1.5 rounded-full text-[9px] font-black uppercase tracking-widest border border-amber-100 flex items-center gap-1.5 w-max"><span class="size-2 rounded-full bg-amber-500 animate-pulse shadow-[0_0_8px_rgba(245,158,11,0.8)]"></span> FOR APPROVAL</span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="p-5 text-right align-top pt-5">
                                            <div class="flex items-center justify-end gap-2">
                                                
                                                <?php if($c['status'] === 'Archived'): ?>
                                                    <button onclick="openActionModal('restore', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center h-10 px-4 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-500 hover:text-white font-bold text-xs transition-all shadow-sm gap-1.5" title="Restore Clinic">
                                                        <span class="material-symbols-outlined text-base">restore_from_trash</span> Restore
                                                    </button>
                                                    <button onclick="openActionModal('delete', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center size-10 bg-red-50 text-red-600 rounded-xl hover:bg-red-600 hover:text-white transition-all shadow-sm" title="Permanent Delete">
                                                        <span class="material-symbols-outlined text-lg">delete_forever</span>
                                                    </button>
                                                
                                                <?php else: ?>
                                                    <?php $actionStatus = strtolower(trim((string)($c['status'] ?? ''))); ?>
                                                    <?php if($actionStatus === 'active'): ?>
                                                        <button onclick="openActionModal('deactivate', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center h-10 px-4 bg-amber-50 text-amber-600 rounded-xl hover:bg-amber-500 hover:text-white font-bold text-xs transition-all shadow-sm gap-1.5" title="Deactivate/Suspend">
                                                            <span class="material-symbols-outlined text-base">block</span> Deactivate
                                                        </button>
                                                        <button onclick="openActionModal('expire', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center size-10 bg-rose-50 text-rose-600 rounded-xl hover:bg-rose-500 hover:text-white transition-all shadow-sm" title="Expire Subscription">
                                                            <span class="material-symbols-outlined text-lg">schedule</span>
                                                        </button>
                                                        <button onclick="openActionModal('archive', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center size-10 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-600 hover:text-white transition-all shadow-sm" title="Archive Clinic">
                                                            <span class="material-symbols-outlined text-lg">archive</span>
                                                        </button>
                                                    
                                                    <?php elseif($actionStatus === 'expired'): ?>
                                                        <button onclick="openActionModal('unexpire', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center h-10 px-4 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-500 hover:text-white font-bold text-xs transition-all shadow-sm gap-1.5" title="Un-expire Clinic (extend 30 days)">
                                                            <span class="material-symbols-outlined text-base">restart_alt</span> Unexpire
                                                        </button>
                                                        <button onclick="openActionModal('archive', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center size-10 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-600 hover:text-white transition-all shadow-sm" title="Archive Clinic">
                                                            <span class="material-symbols-outlined text-lg">archive</span>
                                                        </button>
                                                    
                                                    <?php elseif($actionStatus === 'suspended' || $actionStatus === 'rejected'): ?>
                                                        <button onclick="openActionModal('activate', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center h-10 px-4 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-500 hover:text-white font-bold text-xs transition-all shadow-sm gap-1.5" title="Reactivate Clinic">
                                                            <span class="material-symbols-outlined text-base">lock_open</span> Reactivate
                                                        </button>
                                                        <button onclick="openActionModal('archive', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center size-10 bg-slate-100 text-slate-600 rounded-xl hover:bg-slate-600 hover:text-white transition-all shadow-sm" title="Archive Clinic">
                                                            <span class="material-symbols-outlined text-lg">archive</span>
                                                        </button>

                                                    <?php else: /* pending approval, pending payment, empty/unknown -> show Approve/Reject only */ ?>
                                                        <button onclick="openActionModal('approve', '<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center h-10 px-4 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-500 hover:text-white font-bold text-xs transition-all shadow-sm gap-1.5" title="Approve Registration">
                                                            <span class="material-symbols-outlined text-base">check_circle</span> Approve
                                                        </button>
                                                        <button onclick="openRejectModal('<?= $c['TenantID'] ?>', '<?= htmlspecialchars(addslashes($c['clinic_name'])) ?>')" class="inline-flex items-center justify-center size-10 bg-slate-100 text-slate-500 rounded-xl hover:bg-slate-500 hover:text-white transition-all shadow-sm" title="Reject Registration">
                                                            <span class="material-symbols-outlined text-lg">cancel</span>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
    </main>
</div>

<!-- RESPOND TO APPEAL MODAL -->
<div id="respondAppealModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-8 max-w-md w-full shadow-2xl border border-slate-100">
        <div class="text-center mb-6">
            <div class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-4 bg-emerald-50 text-emerald-500 shadow-inner">
                <span class="material-symbols-outlined text-3xl">mark_email_read</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 mb-1 tracking-tight">Respond to Appeal</h3>
            <p class="text-slate-500 text-sm">Your message will be emailed to the clinic owner.</p>
        </div>

        <div class="bg-slate-50 rounded-xl p-4 mb-5 border border-slate-100">
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Replying To</p>
            <p id="respondTargetClinic" class="text-sm font-bold text-slate-800"></p>
            <p id="respondTargetEmail" class="text-xs text-slate-500 mt-0.5"></p>
        </div>

        <form method="POST" id="respondAppealForm" action="tenantmanagement.php?view=appeals">
            <input type="hidden" name="respond_appeal" value="1">
            <input type="hidden" name="appeal_id" id="respondAppealId" value="">

            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Status</label>
            <select name="appeal_status" class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-emerald-500 focus:border-emerald-500 outline-none mb-4 bg-white">
                <option value="Reviewed">Reviewed</option>
                <option value="Resolved">Resolved</option>
                <option value="Denied">Denied</option>
                <option value="Pending Review">Keep as Pending</option>
            </select>

            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Your Response <span class="text-red-500">*</span></label>
            <textarea name="response_message" id="respondMessage" required minlength="10" rows="6" placeholder="Type your response to the clinic owner. Be clear and professional." class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-emerald-500 focus:border-emerald-500 outline-none resize-none"></textarea>
            <p class="text-[10px] text-slate-400 mt-1">This message will be delivered via email immediately.</p>

            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeRespondModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl font-bold text-white bg-emerald-500 hover:bg-emerald-600 transition-all text-xs shadow-md shadow-emerald-500/30 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-base">send</span> Send Response
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ARCHIVE / DELETE APPEAL MODAL -->
<div id="appealArchiveModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="appealArchiveBox" class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 transform scale-95 opacity-0 transition-all duration-300">
        <div class="text-center">
            <div id="appealArchiveIconWrap" class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner">
                <span id="appealArchiveIcon" class="material-symbols-outlined text-3xl"></span>
            </div>
            <h3 id="appealArchiveTitle" class="text-2xl font-black text-slate-900 mb-2 tracking-tight"></h3>
            <p id="appealArchiveMessage" class="text-slate-500 text-sm mb-6 leading-relaxed"></p>

            <div class="bg-slate-50 rounded-xl p-4 mb-6 border border-slate-100 text-left flex items-center gap-3">
                <div class="size-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 shrink-0">
                    <span class="material-symbols-outlined">gavel</span>
                </div>
                <div class="overflow-hidden">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Appeal</p>
                    <p id="appealArchiveTarget" class="text-sm font-bold text-slate-800 truncate"></p>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeAppealArchiveModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <a id="appealArchiveConfirmBtn" href="#" class="flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center"></a>
            </div>
        </div>
    </div>
</div>

<!-- REACTIVATE FROM APPEAL MODAL -->
<div id="reactivateAppealModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div id="reactivateAppealBox" class="bg-white rounded-[2rem] p-8 max-w-sm w-full shadow-2xl border border-slate-100 transform scale-95 opacity-0 transition-all duration-300">
        <div class="text-center">
            <div class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-emerald-50 text-emerald-500">
                <span class="material-symbols-outlined text-3xl">lock_open</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 mb-2 tracking-tight">Reactivate Clinic?</h3>
            <p class="text-slate-500 text-sm mb-6 leading-relaxed">This will restore full access for the clinic. The appeal will be marked as <strong class="text-emerald-600">Resolved</strong> automatically.</p>

            <div class="bg-slate-50 rounded-xl p-4 mb-6 border border-slate-100 text-left flex items-center gap-3">
                <div class="size-10 rounded-full bg-emerald-100 flex items-center justify-center text-emerald-500 shrink-0">
                    <span class="material-symbols-outlined">local_hospital</span>
                </div>
                <div class="overflow-hidden">
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Clinic</p>
                    <p id="reactivateTargetClinic" class="text-sm font-bold text-slate-800 truncate"></p>
                </div>
            </div>

            <div class="flex gap-3">
                <button type="button" onclick="closeReactivateModal()" id="reactivateCancelBtn" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <button type="button" onclick="confirmReactivateAppeal()" id="reactivateConfirmBtn" class="flex-1 py-3 rounded-xl font-bold text-white bg-emerald-500 hover:bg-emerald-600 transition-all text-xs shadow-md shadow-emerald-500/30 flex items-center justify-center gap-2">
                    <span class="material-symbols-outlined text-base">lock_open</span>
                    <span id="reactivateBtnLabel">Yes, Reactivate</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SUSPEND WITH REASON MODAL -->
<div id="suspendReasonModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-8 max-w-md w-full shadow-2xl border border-slate-100">
        <div class="text-center mb-6">
            <div class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-4 bg-amber-50 text-amber-500 shadow-inner">
                <span class="material-symbols-outlined text-3xl">block</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 mb-1 tracking-tight">Suspend Clinic</h3>
            <p class="text-slate-500 text-sm">State the reason for suspension. The clinic owner will receive this via email and will be locked out until reactivated.</p>
        </div>

        <div class="bg-slate-50 rounded-xl p-4 mb-5 border border-slate-100 flex items-center gap-3">
            <div class="size-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 shrink-0">
                <span class="material-symbols-outlined text-lg">domain</span>
            </div>
            <div class="overflow-hidden">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Target Clinic</p>
                <p id="suspendTargetClinic" class="text-sm font-bold text-slate-800 truncate"></p>
            </div>
        </div>

        <form method="POST" id="suspendReasonForm" action="">
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Reason for Suspension <span class="text-red-500">*</span></label>
            <textarea name="suspension_reason" id="suspendReasonText" required minlength="10" rows="5" placeholder="e.g. Repeated violation of platform policies, unpaid invoices, or pending compliance review." class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-amber-500 focus:border-amber-500 outline-none resize-none"></textarea>
            <p class="text-[10px] text-slate-400 mt-1">Minimum 10 characters.</p>

            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeSuspendModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl font-bold text-white bg-amber-500 hover:bg-amber-600 transition-all text-xs shadow-md shadow-amber-500/30">Suspend Clinic</button>
            </div>
        </form>
    </div>
</div>

<!-- REJECT WITH REASON MODAL -->
<div id="rejectReasonModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-8 max-w-md w-full shadow-2xl border border-slate-100">
        <div class="text-center mb-6">
            <div class="size-16 rounded-3xl flex items-center justify-center mx-auto mb-4 bg-red-50 text-red-500 shadow-inner">
                <span class="material-symbols-outlined text-3xl">cancel</span>
            </div>
            <h3 class="text-2xl font-black text-slate-900 mb-1 tracking-tight">Reject Registration</h3>
            <p class="text-slate-500 text-sm">Provide a clear reason. The clinic owner will receive this via email.</p>
        </div>

        <div class="bg-slate-50 rounded-xl p-4 mb-5 border border-slate-100 flex items-center gap-3">
            <div class="size-10 rounded-full bg-slate-200 flex items-center justify-center text-slate-500 shrink-0">
                <span class="material-symbols-outlined text-lg">domain</span>
            </div>
            <div class="overflow-hidden">
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-0.5">Target Clinic</p>
                <p id="rejectTargetClinic" class="text-sm font-bold text-slate-800 truncate"></p>
            </div>
        </div>

        <form method="POST" id="rejectReasonForm" action="">
            <input type="hidden" name="_method" value="reject">
            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Reason for Rejection <span class="text-red-500">*</span></label>
            <textarea name="rejection_reason" id="rejectReasonText" required minlength="10" rows="5" placeholder="e.g. The submitted DOH-LTO document is unreadable or expired. Please re-upload a clear, valid copy." class="w-full rounded-xl border border-slate-200 p-3 text-sm focus:ring-red-500 focus:border-red-500 outline-none resize-none"></textarea>
            <p class="text-[10px] text-slate-400 mt-1">Minimum 10 characters.</p>

            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeRejectModal()" class="flex-1 py-3 rounded-xl font-bold text-slate-500 bg-slate-100 hover:bg-slate-200 transition-all text-xs">Cancel</button>
                <button type="submit" class="flex-1 py-3 rounded-xl font-bold text-white bg-red-500 hover:bg-red-600 transition-all text-xs shadow-md shadow-red-500/30">Send Rejection</button>
            </div>
        </form>
    </div>
</div>

<!-- DOH LTO Viewer Modal -->
<div id="dohLtoModal" class="fixed inset-0 z-[100] flex items-center justify-center p-4" style="opacity:0;pointer-events:none;transition:all 0.3s ease;">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeDohLtoModal()"></div>
    <div class="bg-white w-full max-w-2xl rounded-3xl shadow-2xl relative z-10 overflow-hidden border border-slate-100">
        <div class="p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50">
            <div class="flex items-center gap-3">
                <div class="size-10 rounded-xl bg-emerald-100 flex items-center justify-center">
                    <span class="material-symbols-outlined text-emerald-600">description</span>
                </div>
                <div>
                    <h3 class="text-base font-black text-slate-800 tracking-tight">DOH License to Operate</h3>
                    <p class="text-[10px] text-slate-400 font-bold uppercase tracking-widest">Submitted LTO Document</p>
                </div>
            </div>
            <button onclick="closeDohLtoModal()" class="size-9 flex items-center justify-center rounded-full hover:bg-red-50 text-red-400 hover:text-red-500 transition-all">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 flex items-center justify-center bg-slate-50/50 max-h-[70vh] overflow-auto" id="dohLtoContent"></div>
    </div>
</div>

<script>
    function viewDohLto(path) {
        var modal = document.getElementById('dohLtoModal');
        var content = document.getElementById('dohLtoContent');
        var ext = path.split('.').pop().toLowerCase();
        if (ext === 'pdf') {
            content.innerHTML = '<iframe src="' + path + '" class="w-full" style="height:65vh;border:none;border-radius:12px;"></iframe>';
        } else {
            content.innerHTML = '<img src="' + path + '" alt="DOH LTO" class="max-w-full max-h-[65vh] rounded-2xl shadow-sm border border-slate-200 object-contain">';
        }
        modal.style.opacity = '1';
        modal.style.pointerEvents = 'auto';
    }
    function closeDohLtoModal() {
        var modal = document.getElementById('dohLtoModal');
        modal.style.opacity = '0';
        modal.style.pointerEvents = 'none';
        setTimeout(function(){ document.getElementById('dohLtoContent').innerHTML = ''; }, 300);
    }

    // --- COPY CLINIC LINK LOGIC ---
    function copyLink(link, btnElement) {
        navigator.clipboard.writeText(link).then(() => {
            const icon = btnElement.querySelector('.copy-icon');
            const text = btnElement.querySelector('.copy-text');
            
            icon.textContent = 'check';
            icon.classList.add('text-emerald-500');
            text.textContent = 'Copied!';
            text.classList.add('text-emerald-500');
            
            setTimeout(() => {
                icon.textContent = 'content_copy';
                icon.classList.remove('text-emerald-500');
                text.textContent = 'Copy Link';
                text.classList.remove('text-emerald-500');
            }, 2000);
        }).catch(err => {
            console.error('Error copying text: ', err);
            alert('Failed to copy link.');
        });
    }

    // --- ACTION DIALOGUE MODAL LOGIC ---
    function openActionModal(action, tenantId, clinicName) {
        // Re-route reason-required actions to their dedicated modals
        if (action === 'deactivate') { openSuspendModal(tenantId, clinicName); return; }
        if (action === 'reject')     { openRejectModal(tenantId, clinicName); return; }
        const modal = document.getElementById('actionModal');
        const box = document.getElementById('actionModalBox');
        const iconContainer = document.getElementById('actionIconContainer');
        const icon = document.getElementById('actionIcon');
        const title = document.getElementById('actionTitle');
        const message = document.getElementById('actionMessage');
        const targetClinic = document.getElementById('actionTargetClinic');
        const confirmBtn = document.getElementById('actionConfirmBtn');

        targetClinic.textContent = clinicName + ' (' + tenantId + ')';
        // Panatilihin ang current view parameters para hindi mawala sa archive page pagka-save
        confirmBtn.href = `tenantmanagement.php?view=<?= $currentView ?>&action=${action}&tenant_id=${tenantId}`;

        if (action === 'approve') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-emerald-50 text-emerald-500';
            icon.textContent = 'check_circle';
            title.textContent = 'Approve Clinic?';
            message.innerHTML = 'This clinic will be verified and their admin account will be activated immediately.';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/30';
            confirmBtn.textContent = 'Approve Clinic';
        } else if (action === 'reject') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-slate-100 text-slate-500';
            icon.textContent = 'cancel';
            title.textContent = 'Reject Registration?';
            message.innerHTML = 'Are you sure you want to reject this clinic? They will not be able to access the platform.';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-slate-500 hover:bg-slate-600 shadow-slate-500/30';
            confirmBtn.textContent = 'Yes, Reject';
        } else if (action === 'deactivate') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-amber-50 text-amber-500';
            icon.textContent = 'block';
            title.textContent = 'Deactivate Clinic?';
            message.innerHTML = 'Are you sure you want to suspend this clinic?<br>All staff and admins will lose access until reactivated.';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-amber-500 hover:bg-amber-600 shadow-amber-500/30';
            confirmBtn.textContent = 'Deactivate';
        } else if (action === 'activate') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-emerald-50 text-emerald-500';
            icon.textContent = 'lock_open';
            title.textContent = 'Reactivate Clinic?';
            message.innerHTML = 'Are you sure you want to restore access to this clinic?';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/30';
            confirmBtn.textContent = 'Reactivate';
        } else if (action === 'archive') { 
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-slate-100 text-slate-600';
            icon.textContent = 'archive';
            title.textContent = 'Archive Clinic?';
            message.innerHTML = 'Are you sure you want to archive this clinic?<br><span class="text-slate-500 font-bold mt-2 block">It will be moved to the archives and can be restored later.</span>';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-slate-600 hover:bg-slate-700 shadow-slate-600/30';
            confirmBtn.textContent = 'Yes, Archive';
        } else if (action === 'restore') { 
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-emerald-50 text-emerald-600';
            icon.textContent = 'restore_from_trash';
            title.textContent = 'Restore Clinic?';
            message.innerHTML = 'This clinic will be restored to "Suspended" status. You can manually reactivate it afterwards.';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/30';
            confirmBtn.textContent = 'Restore';
        } else if (action === 'expire') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-rose-50 text-rose-600';
            icon.textContent = 'schedule';
            title.textContent = 'Expire Subscription?';
            message.innerHTML = 'This will mark the clinic\'s subscription as <strong>Expired</strong>. The owner will be locked out of the dashboard until they renew payment.<br><span class="text-rose-500 font-bold mt-2 block">An email notification will be sent.</span>';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-rose-600 hover:bg-rose-700 shadow-rose-600/30';
            confirmBtn.textContent = 'Yes, Expire';
        } else if (action === 'unexpire') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-emerald-50 text-emerald-600';
            icon.textContent = 'restart_alt';
            title.textContent = 'Un-expire Clinic?';
            message.innerHTML = 'This will reactivate the clinic and extend the subscription by <strong>30 days</strong> from now.<br><span class="text-emerald-600 font-bold mt-2 block">No payment will be charged — admin override.</span>';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-emerald-600 hover:bg-emerald-700 shadow-emerald-600/30';
            confirmBtn.textContent = 'Yes, Unexpire';
        } else if (action === 'delete') {
            iconContainer.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-red-50 text-red-600';
            icon.textContent = 'delete_forever';
            title.textContent = 'Permanently Delete?';
            message.innerHTML = 'Are you absolutely sure you want to delete this clinic?<br><span class="text-red-500 font-bold mt-2 block">This action cannot be undone and all data will be lost.</span>';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-red-600 hover:bg-red-700 shadow-red-600/30';
            confirmBtn.textContent = 'Yes, Delete';
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => {
            box.classList.remove('scale-95', 'opacity-0');
            box.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeActionModal() {
        const modal = document.getElementById('actionModal');
        const box = document.getElementById('actionModalBox');
        
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        
        setTimeout(() => {
            modal.classList.remove('flex');
            modal.classList.add('hidden');
        }, 300);
    }

    function applyFiltersAndSort() {
        const input = document.getElementById("clinicSearch").value.toLowerCase();
        const sortOrder = document.getElementById("dateSort")?.value || "newest";
        const tbody = document.querySelector("#clinicsTable tbody");
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll(".clinic-row"));

        rows.sort((a, b) => {
            const aCreated = Number(a.dataset.createdAt || 0);
            const bCreated = Number(b.dataset.createdAt || 0);
            return sortOrder === "oldest" ? aCreated - bCreated : bCreated - aCreated;
        });

        rows.forEach(row => {
            const clinicName = row.querySelector(".clinic-name").innerText.toLowerCase();
            const isMatch = clinicName.includes(input);
            row.style.display = isMatch ? "" : "none";
            tbody.appendChild(row);
        });
    }

    function filterClinics() {
        applyFiltersAndSort();
    }

    document.addEventListener('DOMContentLoaded', applyFiltersAndSort);

    // -- LOGOUT LOGIC --
    function openLogoutModal() {
        document.getElementById('logoutModal').classList.remove('hidden');
        document.getElementById('logoutModal').classList.add('flex');
    }

    // -- SUSPEND WITH REASON MODAL --
    function openSuspendModal(tenantId, clinicName) {
        const modal = document.getElementById('suspendReasonModal');
        document.getElementById('suspendTargetClinic').textContent = clinicName + ' (' + tenantId + ')';
        document.getElementById('suspendReasonText').value = '';
        const form = document.getElementById('suspendReasonForm');
        form.action = `tenantmanagement.php?view=<?= $currentView ?>&action=deactivate&tenant_id=${encodeURIComponent(tenantId)}`;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => document.getElementById('suspendReasonText').focus(), 100);
    }
    function closeSuspendModal() {
        const modal = document.getElementById('suspendReasonModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    // -- REJECT WITH REASON MODAL --
    function openRejectModal(tenantId, clinicName) {
        const modal = document.getElementById('rejectReasonModal');
        document.getElementById('rejectTargetClinic').textContent = clinicName + ' (' + tenantId + ')';
        document.getElementById('rejectReasonText').value = '';
        const form = document.getElementById('rejectReasonForm');
        form.action = `tenantmanagement.php?view=<?= $currentView ?>&action=reject&tenant_id=${encodeURIComponent(tenantId)}`;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => document.getElementById('rejectReasonText').focus(), 100);
    }
    function closeRejectModal() {
        const modal = document.getElementById('rejectReasonModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    // -- REACTIVATE FROM APPEAL MODAL --
    let _reactivateTenantId = null;
    function openReactivateModal(tenantId, clinicName) {
        _reactivateTenantId = tenantId;
        document.getElementById('reactivateTargetClinic').textContent = clinicName + ' (' + tenantId + ')';
        const modal = document.getElementById('reactivateAppealModal');
        const box = document.getElementById('reactivateAppealBox');
        document.getElementById('reactivateBtnLabel').textContent = 'Yes, Reactivate';
        const btn = document.getElementById('reactivateConfirmBtn');
        btn.disabled = false;
        btn.classList.remove('opacity-70', 'cursor-not-allowed');
        document.getElementById('reactivateCancelBtn').disabled = false;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => { box.classList.remove('scale-95', 'opacity-0'); box.classList.add('scale-100', 'opacity-100'); }, 10);
    }
    function closeReactivateModal() {
        const modal = document.getElementById('reactivateAppealModal');
        const box = document.getElementById('reactivateAppealBox');
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        setTimeout(() => { modal.classList.remove('flex'); modal.classList.add('hidden'); }, 250);
    }
    function confirmReactivateAppeal() {
        if (!_reactivateTenantId) return;
        const btn = document.getElementById('reactivateConfirmBtn');
        const cancelBtn = document.getElementById('reactivateCancelBtn');
        const label = document.getElementById('reactivateBtnLabel');
        btn.disabled = true;
        cancelBtn.disabled = true;
        btn.classList.add('opacity-70', 'cursor-not-allowed');
        label.innerHTML = 'Reactivating <span class="inline-block size-3 border-2 border-white/40 border-t-white rounded-full animate-spin align-middle ml-1"></span>';
        setTimeout(() => {
            window.location.href = 'tenantmanagement.php?view=appeals&action=activate&tenant_id=' + encodeURIComponent(_reactivateTenantId);
        }, 350);
    }

    // -- ARCHIVE / DELETE APPEAL MODAL --
    function openAppealArchiveModal(action, appealId, clinicName) {
        const modal = document.getElementById('appealArchiveModal');
        const box = document.getElementById('appealArchiveBox');
        const iconWrap = document.getElementById('appealArchiveIconWrap');
        const icon = document.getElementById('appealArchiveIcon');
        const title = document.getElementById('appealArchiveTitle');
        const message = document.getElementById('appealArchiveMessage');
        const target = document.getElementById('appealArchiveTarget');
        const confirmBtn = document.getElementById('appealArchiveConfirmBtn');

        target.textContent = clinicName + ' (Appeal #' + appealId + ')';
        confirmBtn.href = 'tenantmanagement.php?view=appeals&appeal_action=' + encodeURIComponent(action) + '&appeal_id=' + appealId;

        if (action === 'archive') {
            iconWrap.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-slate-100 text-slate-600';
            icon.textContent = 'archive';
            title.textContent = 'Archive Appeal?';
            message.innerHTML = 'This appeal will be moved to the archives. You can restore it later if needed.';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-slate-700 hover:bg-slate-800 shadow-slate-700/30';
            confirmBtn.textContent = 'Yes, Archive';
        } else if (action === 'unarchive') {
            iconWrap.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-emerald-50 text-emerald-600';
            icon.textContent = 'unarchive';
            title.textContent = 'Restore Appeal?';
            message.innerHTML = 'This appeal will be moved back to the active list.';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-emerald-500 hover:bg-emerald-600 shadow-emerald-500/30';
            confirmBtn.textContent = 'Yes, Restore';
        } else if (action === 'delete') {
            iconWrap.className = 'size-16 rounded-3xl flex items-center justify-center mx-auto mb-5 shadow-inner bg-red-50 text-red-600';
            icon.textContent = 'delete_forever';
            title.textContent = 'Delete Appeal?';
            message.innerHTML = 'This appeal will be permanently deleted.<br><span class="text-red-500 font-bold mt-2 block">This action cannot be undone.</span>';
            confirmBtn.className = 'flex-1 py-3 rounded-xl font-bold text-white transition-all text-xs shadow-md text-center bg-red-600 hover:bg-red-700 shadow-red-600/30';
            confirmBtn.textContent = 'Yes, Delete';
        }

        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => { box.classList.remove('scale-95', 'opacity-0'); box.classList.add('scale-100', 'opacity-100'); }, 10);
    }
    function closeAppealArchiveModal() {
        const modal = document.getElementById('appealArchiveModal');
        const box = document.getElementById('appealArchiveBox');
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        setTimeout(() => { modal.classList.remove('flex'); modal.classList.add('hidden'); }, 250);
    }

    // -- RESPOND TO APPEAL MODAL --
    function openRespondModal(appealId, clinicName, name, email) {
        const modal = document.getElementById('respondAppealModal');
        document.getElementById('respondAppealId').value = appealId;
        document.getElementById('respondTargetClinic').textContent = clinicName + ' — ' + name;
        document.getElementById('respondTargetEmail').textContent = email;
        document.getElementById('respondMessage').value = '';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        setTimeout(() => document.getElementById('respondMessage').focus(), 100);
    }
    function closeRespondModal() {
        const modal = document.getElementById('respondAppealModal');
        modal.classList.remove('flex');
        modal.classList.add('hidden');
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