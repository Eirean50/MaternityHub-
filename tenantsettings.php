<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();

// --- LOGOUT HANDLER ---
if ((isset($_GET['action']) && $_GET['action'] === 'logout') || isset($_GET['logout'])) {
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

// SECURITY CHECK: Siguraduhin na Admin lang ang makakapunta rito
if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient' || strtolower(trim($_SESSION['role'])) === 'staff') {
    header("Location: dashboard.php");
    exit();
}

require_once 'db.php';

if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

// 🔥 AUTO-FIX: ADD COLUMNS FOR 'WHY CHOOSE US' SECTION 🔥
try {
    $pdo->query("SELECT why_choose_heading FROM tenants LIMIT 1");
} catch (PDOException $e) {
    try { 
        $pdo->exec("ALTER TABLE tenants 
                    ADD why_choose_img VARCHAR(255) NULL, 
                    ADD why_choose_heading VARCHAR(255) NULL, 
                    ADD why_choose_desc TEXT NULL, 
                    ADD feature_1 VARCHAR(150) NULL, 
                    ADD feature_2 VARCHAR(150) NULL, 
                    ADD feature_3 VARCHAR(150) NULL"); 
    } catch (PDOException $ex) {}
}

// 🔥 AUTO-FIX: ADD login_cover COLUMN FOR LOGIN PAGE COVER PHOTO 🔥
try {
    $pdo->query("SELECT login_cover FROM tenants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE tenants ADD login_cover VARCHAR(255) NULL"); } catch (PDOException $ex) {}
}

// 🔥 AUTO-FIX: ADD hero_img COLUMN FOR SEPARATE HERO IMAGE 🔥
try {
    $pdo->query("SELECT hero_img FROM tenants LIMIT 1");
} catch (PDOException $e) {
    try { $pdo->exec("ALTER TABLE tenants ADD hero_img VARCHAR(255) NULL"); } catch (PDOException $ex) {}
}

$displayName = $_SESSION['full_name'] ?? 'Admin';
$userRole    = $_SESSION['role'] ?? 'Clinic Administrator';
$normalizedRole = strtolower(trim((string)$userRole));
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);

$userId      = $_SESSION['user_id'];
$tenant_id   = $_SESSION['TenantID'] ?? null; 

$_ownerAlsoMidwife = false;
if ($currentUserIsOwner && $tenant_id) {
    try { $_stmtMw = $pdo->prepare("SELECT COALESCE(also_midwife, 0) FROM users WHERE id = ? AND TenantID = ? LIMIT 1"); $_stmtMw->execute([$userId, $tenant_id]); $_ownerAlsoMidwife = ((int)$_stmtMw->fetchColumn() === 1); } catch (PDOException $e) {}
}
$displayRole = $currentUserIsOwner ? ($_ownerAlsoMidwife ? 'Owner / Midwife' : 'Owner') : $userRole; 

// DEFINING $isStaffRole PROPERLY PARA SA SIDEBAR
$isStaffRole = ($normalizedRole === 'staff');

$msgError = null;
if (isset($_GET['error']) && $_GET['error'] === 'contact') {
    $msgError = 'Contact number must be exactly 11 digits and start with 09 (e.g. 09123456789).';
}

// --- HANDLE PORTAL CUSTOMIZATION (WHITE-LABELING) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] === 'customize_portal') {
    $newClinicName = trim($_POST['clinic_name']);
    $newClinicAddress = trim($_POST['clinic_address']); 
    $newClinicContact = trim($_POST['clinic_contact']);
    // Validate: must be exactly 11 digits and start with 09
    if (!preg_match('/^09\d{9}$/', $newClinicContact)) {
        header("Location: tenantsettings.php?error=contact");
        exit();
    }
    $newThemeColor = trim($_POST['theme_color']);
    $newHeroHeadline = trim($_POST['hero_headline']);
    $newHeroSubtitle = trim($_POST['hero_subtitle']);
    $newAboutText = trim($_POST['about_text']);
    $newOpeningTime = trim($_POST['opening_time']);
    $newClosingTime = trim($_POST['closing_time']);

    // Why Choose Us Data
    $newWhyHeading = trim($_POST['why_choose_heading']);
    $newWhyDesc = trim($_POST['why_choose_desc']);
    $newFeature1 = trim($_POST['feature_1']);
    $newFeature2 = trim($_POST['feature_2']);
    $newFeature3 = trim($_POST['feature_3']);

    $oldData = null;

    // =====================================================================================
    // 🔥 DIRECT PHP AUDIT LOGGING (Mas reliable kaysa Database Trigger) 🔥
    // =====================================================================================
    try {
        // 1. Kunin ang lumang data mula sa database bago mag-update
        $stmtOldData = $pdo->prepare("SELECT clinic_name, complete_address, clinic_contact, clinic_logo, theme_color, opening_time, closing_time, hero_headline, hero_subtitle, about_text FROM tenants WHERE TenantID = ?");
        $stmtOldData->execute([$tenant_id]);
        $oldData = $stmtOldData->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Silent fail para hindi ma-interrupt ang mismong pag-save ng clinic settings
    }
    // =====================================================================================

    // LOGO UPLOAD LOGIC
    $logo_name = null;
    if (isset($_FILES['clinic_logo']) && $_FILES['clinic_logo']['error'] == 0) {
        $logo_name = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['clinic_logo']['name']);
        if (!is_dir('uploads/logos/')) { mkdir('uploads/logos/', 0777, true); }
        move_uploaded_file($_FILES['clinic_logo']['tmp_name'], 'uploads/logos/' . $logo_name);
    }

    // WHY CHOOSE US IMAGE UPLOAD LOGIC
    $why_img_name = null;
    if (isset($_FILES['why_choose_img']) && $_FILES['why_choose_img']['error'] == 0) {
        $why_img_name = time() . '_why_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['why_choose_img']['name']);
        if (!is_dir('uploads/images/')) { mkdir('uploads/images/', 0777, true); }
        move_uploaded_file($_FILES['why_choose_img']['tmp_name'], 'uploads/images/' . $why_img_name);
    }

    // HERO IMAGE UPLOAD LOGIC
    $hero_img_name = null;
    if (isset($_FILES['hero_img']) && $_FILES['hero_img']['error'] == 0) {
        $hero_img_name = time() . '_hero_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['hero_img']['name']);
        if (!is_dir('uploads/images/')) { mkdir('uploads/images/', 0777, true); }
        move_uploaded_file($_FILES['hero_img']['tmp_name'], 'uploads/images/' . $hero_img_name);
    }

    // LOGIN COVER PHOTO UPLOAD LOGIC
    $login_cover_name = null;
    if (isset($_FILES['login_cover']) && $_FILES['login_cover']['error'] == 0) {
        $login_cover_name = time() . '_cover_' . preg_replace("/[^a-zA-Z0-9.]/", "", $_FILES['login_cover']['name']);
        if (!is_dir('uploads/images/')) { mkdir('uploads/images/', 0777, true); }
        move_uploaded_file($_FILES['login_cover']['tmp_name'], 'uploads/images/' . $login_cover_name);
    }

    try {
        $pdo->beginTransaction();

        $sql = "UPDATE tenants SET clinic_name = ?, complete_address = ?, clinic_contact = ?, theme_color = ?, hero_headline = ?, hero_subtitle = ?, about_text = ?, opening_time = ?, closing_time = ?, why_choose_heading = ?, why_choose_desc = ?, feature_1 = ?, feature_2 = ?, feature_3 = ?";
        $params = [$newClinicName, $newClinicAddress, $newClinicContact, $newThemeColor, $newHeroHeadline, $newHeroSubtitle, $newAboutText, $newOpeningTime, $newClosingTime, $newWhyHeading, $newWhyDesc, $newFeature1, $newFeature2, $newFeature3];

        if ($logo_name) {
            $sql .= ", clinic_logo = ?";
            $params[] = $logo_name;
        }
        if ($why_img_name) {
            $sql .= ", why_choose_img = ?";
            $params[] = $why_img_name;
        }
        if ($hero_img_name) {
            $sql .= ", hero_img = ?";
            $params[] = $hero_img_name;
        }
        if ($login_cover_name) {
            $sql .= ", login_cover = ?";
            $params[] = $login_cover_name;
        }

        $sql .= " WHERE TenantID = ?";
        $params[] = $tenant_id;

        $stmtCustomize = $pdo->prepare($sql);
        $success = $stmtCustomize->execute($params);

        if (!$success) {
            throw new Exception('Failed to update tenant settings.');
        }

        $stmtUsersClinicName = $pdo->prepare("UPDATE users SET clinic_name = ? WHERE TenantID = ?");
        $usersUpdated = $stmtUsersClinicName->execute([$newClinicName, $tenant_id]);
        if (!$usersUpdated) {
            throw new Exception('Failed to update clinic name in users table.');
        }

        // Log only successful changes so audit trail reflects actual saved values.
        if ($oldData) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $currentTime = date('Y-m-d H:i:s');
            $refClinicName = !empty($oldData['clinic_name']) ? $oldData['clinic_name'] : 'Clinic';
            $stmtLog = $pdo->prepare("INSERT INTO audit_logs (TenantID, user_name, role, action_type, details, ip_address, created_at) VALUES (?, ?, ?, 'Update', ?, ?, ?)");

            if ((string)$oldData['clinic_name'] !== (string)$newClinicName) {
                $details = "\"{$refClinicName}\" changed their name to \"{$newClinicName}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ((string)$oldData['complete_address'] !== (string)$newClinicAddress) {
                $details = "\"{$refClinicName}\" changed their address to \"{$newClinicAddress}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ((string)$oldData['clinic_contact'] !== (string)$newClinicContact) {
                $details = "\"{$refClinicName}\" changed their contact number to \"{$newClinicContact}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ((string)($oldData['theme_color'] ?? '') !== (string)$newThemeColor) {
                $details = "\"{$refClinicName}\" changed their theme color from \"{$oldData['theme_color']}\" to \"{$newThemeColor}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            $oldOpening = !empty($oldData['opening_time']) ? date('H:i', strtotime($oldData['opening_time'])) : '';
            $oldClosing = !empty($oldData['closing_time']) ? date('H:i', strtotime($oldData['closing_time'])) : '';
            if ($oldOpening !== (string)$newOpeningTime) {
                $details = "\"{$refClinicName}\" changed their opening time from \"{$oldOpening}\" to \"{$newOpeningTime}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }
            if ($oldClosing !== (string)$newClosingTime) {
                $details = "\"{$refClinicName}\" changed their closing time from \"{$oldClosing}\" to \"{$newClosingTime}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ((string)($oldData['hero_headline'] ?? '') !== (string)$newHeroHeadline) {
                $details = "\"{$refClinicName}\" changed their public portal headline to \"{$newHeroHeadline}\"";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ((string)($oldData['hero_subtitle'] ?? '') !== (string)$newHeroSubtitle) {
                $details = "\"{$refClinicName}\" changed their portal subtitle.";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ((string)($oldData['about_text'] ?? '') !== (string)$newAboutText) {
                $details = "\"{$refClinicName}\" updated their \"About Us\" description.";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ($logo_name && (string)($oldData['clinic_logo'] ?? '') !== (string)$logo_name) {
                $details = "\"{$refClinicName}\" updated their clinic logo.";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ($hero_img_name) {
                $details = "\"{$refClinicName}\" updated their hero section image.";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ($why_img_name) {
                $details = "\"{$refClinicName}\" updated their \"Why Choose Us\" section image.";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }

            if ($login_cover_name) {
                $details = "\"{$refClinicName}\" updated their login page cover photo.";
                $stmtLog->execute([$tenant_id, $displayName, $userRole, $details, $ip, $currentTime]);
            }
        }

        $pdo->commit();

        if ($success) {
            header("Location: tenantsettings.php?msg=PortalUpdated");
            exit();
        } else {
            $msgError = "Failed to update portal settings.";
        }
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msgError = "Database Error: " . $e->getMessage();
    }
}

// --- FETCH CLINIC NAME, CODE, LOGO & CUSTOMIZATIONS ---
$clinicName = "MaternityHub";
$clinicAddress = ""; // Default empty
$clinicContact = ""; // Default empty
$clinicCode = "N/A"; 
$clinicLogo = null;
$themeColor = "#15803d"; // Default Green
$heroHeadline = "Your Journey to Motherhood, Supported with Care.";
$heroSubtitle = "From the first flutter to the first breath, our expert team provides comprehensive, compassionate maternity care tailored to you.";
$aboutText = "We believe in family-centered care. Every parent deserves a supportive environment where their choices are respected and their health is prioritized.";
$openingTime = "08:00"; // Default
$closingTime = "17:00"; // Default

// Defaults for Why Choose Us
$whyHeading = "Trusted Care for Your Growing Family";
$whyDesc = "At our clinic, we believe that every pregnancy journey is unique. Our dedicated team of healthcare professionals is committed to providing personalized, high-quality care in a warm and homely environment.";
$feature1 = "Licensed & Experienced Staff";
$feature2 = "Clean & Modern Facilities";
$feature3 = "Affordable & Accessible Care";
$whyImg = null;
$heroImg = null;
$loginCover = null;

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare("SELECT * FROM tenants WHERE TenantID = ?");
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);
        
        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'];
            if (!empty($clinicData['complete_address'])) $clinicAddress = $clinicData['complete_address'];
            if (!empty($clinicData['clinic_contact'])) $clinicContact = $clinicData['clinic_contact'];

            if (!empty($clinicData['clinic_code'])) {
                $clinicCode = $clinicData['clinic_code'];
            }
            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }
            if (!empty($clinicData['theme_color'])) $themeColor = $clinicData['theme_color'];
            if (!empty($clinicData['hero_headline'])) $heroHeadline = $clinicData['hero_headline'];
            if (!empty($clinicData['hero_subtitle'])) $heroSubtitle = $clinicData['hero_subtitle'];
            if (!empty($clinicData['about_text'])) $aboutText = $clinicData['about_text'];
            
            if (!empty($clinicData['opening_time'])) $openingTime = date('H:i', strtotime($clinicData['opening_time']));
            if (!empty($clinicData['closing_time'])) $closingTime = date('H:i', strtotime($clinicData['closing_time']));

            if (!empty($clinicData['why_choose_heading'])) $whyHeading = $clinicData['why_choose_heading'];
            if (!empty($clinicData['why_choose_desc'])) $whyDesc = $clinicData['why_choose_desc'];
            if (!empty($clinicData['feature_1'])) $feature1 = $clinicData['feature_1'];
            if (!empty($clinicData['feature_2'])) $feature2 = $clinicData['feature_2'];
            if (!empty($clinicData['feature_3'])) $feature3 = $clinicData['feature_3'];
            
            if (!empty($clinicData['why_choose_img']) && file_exists(__DIR__ . '/uploads/images/' . $clinicData['why_choose_img'])) {
                $whyImg = 'uploads/images/' . $clinicData['why_choose_img'];
            }
            if (!empty($clinicData['hero_img']) && file_exists(__DIR__ . '/uploads/images/' . $clinicData['hero_img'])) {
                $heroImg = 'uploads/images/' . $clinicData['hero_img'];
            }
            if (!empty($clinicData['login_cover']) && file_exists(__DIR__ . '/uploads/images/' . $clinicData['login_cover'])) {
                $loginCover = 'uploads/images/' . $clinicData['login_cover'];
            }
        }
    } catch (PDOException $e) {}
}

// =========================================================
// 🔥 DYNAMIC CONTRAST CALCULATOR PARA SA HEADER & SIDEBAR 🔥
// =========================================================
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2)); $g = hexdec(substr($hex, 2, 2)); $b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150); 

// Dynamic Tailwind Classes based on luminance
$headerTextPrimary = $isLightTheme ? "text-slate-900" : "text-white";
$headerTextSecondary = $isLightTheme ? "text-slate-700" : "text-primary-light";
$headerTextMuted = $isLightTheme ? "text-slate-400" : "text-white/50";
$headerBadgeBg = $isLightTheme ? "bg-slate-200 text-slate-800" : "bg-black/20 text-white";
$headerIconBox = $isLightTheme ? "bg-white border-slate-200" : "bg-white/15 border-white/25";
$headerIconColor = $isLightTheme ? "text-slate-700" : "text-white/90";
$headerBtn = $isLightTheme ? "bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm" : "bg-white/15 hover:bg-white/25 text-white border-white/30";

// --- DYNAMIC SIDEBAR AT BUTTON CLASSES ---
$sidebarActive = $isLightTheme ? "bg-slate-800 text-white shadow-md" : "bg-primary/10 text-primary";
$mainBtn = $isLightTheme ? "bg-slate-800 hover:bg-slate-900 text-white" : "bg-primary hover:bg-primary-dark text-white";

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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/><meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Settings - <?= htmlspecialchars($clinicName) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght@100..700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"/>
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
        #iframeContainer { transition: max-width 0.4s cubic-bezier(0.4, 0, 0.2, 1); margin: 0 auto; }
        @view-transition { navigation: auto; }
        header { view-transition-name: header; }
        aside { view-transition-name: sidebar; }
        ::view-transition-old(sidebar), ::view-transition-new(sidebar),
        ::view-transition-old(header), ::view-transition-new(header) { animation: none; }
    </style>
</head>
<body class="bg-background-light text-slate-800 h-screen flex flex-col relative text-sm antialiased font-display">

<div id="previewModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 md:p-8 bg-slate-900/60 backdrop-blur-sm transition-opacity">
    <div class="bg-white rounded-[2rem] w-full max-w-6xl h-full md:h-[95vh] shadow-2xl flex flex-col overflow-hidden border border-slate-100 relative">
        <div class="p-4 md:px-6 border-b border-slate-100 flex justify-between items-center bg-slate-50 shrink-0">
            <div class="flex items-center gap-3">
                <div class="size-10 <?= $sidebarActive ?> rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined text-xl">public</span>
                </div>
                <div>
                    <h3 class="text-sm font-black text-slate-800 leading-none">Live Portal Preview</h3>
                    <p class="text-[10px] text-slate-500 font-medium mt-1 truncate max-w-xs md:max-w-md"><?= htmlspecialchars($clinicPortalLink) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="hidden sm:flex bg-slate-200 p-1 rounded-lg">
                    <button onclick="setPreviewMode('desktop')" id="btnDesktop" class="px-3 py-1.5 rounded bg-white shadow-sm text-slate-700 text-xs font-bold flex items-center gap-1 transition-all">
                        <span class="material-symbols-outlined text-[18px]">desktop_windows</span> Desktop
                    </button>
                    <button onclick="setPreviewMode('mobile')" id="btnMobile" class="px-3 py-1.5 rounded text-slate-500 hover:text-slate-700 text-xs font-bold flex items-center gap-1 transition-all">
                        <span class="material-symbols-outlined text-[18px]">smartphone</span> Mobile
                    </button>
                </div>
                <button onclick="closePreviewModal()" class="text-slate-400 hover:text-red-500 transition-colors size-10 flex items-center justify-center rounded-full hover:bg-red-50 ml-2">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        </div>
        <div class="flex-1 bg-slate-200 p-2 md:p-6 relative overflow-y-auto">
            <div id="iframeContainer" class="w-full max-w-full h-full bg-white rounded-xl md:rounded-2xl shadow-lg overflow-hidden border border-slate-300">
                <iframe id="portalIframe" src="" class="w-full h-full border-0" title="Clinic Portal Preview"></iframe>
            </div>
        </div>
    </div>
</div>

<?php if(isset($_GET['msg']) && $_GET['msg'] == 'PortalUpdated'): ?>
<div id="alertMsg" class="fixed top-24 left-1/2 -translate-x-1/2 z-[120] bg-white border-l-4 border-primary p-4 rounded-2xl shadow-2xl flex items-center gap-3 animate-bounce">
    <span class="material-symbols-outlined text-primary">check_circle</span>
    <p class="text-xs font-black text-slate-800">Clinic Settings Saved Successfully!</p>
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
    <p class="font-bold text-slate-800 animate-pulse tracking-tight text-xs">Logging out safely...</p>
</div>

<div id="logoutModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl text-center border border-slate-100">
        <div class="size-12 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mx-auto mb-4">
            <span class="material-symbols-outlined text-2xl">logout</span>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Logout Account?</h3>
        <p class="text-slate-500 text-[11px] mb-6">Sigurado ka bang gusto mong lumabas?</p>
        <div class="flex gap-2">
            <button onclick="closeLogoutModal()" class="flex-1 py-2.5 rounded-xl font-bold text-slate-400 hover:bg-slate-100 transition-all text-[11px]">Cancel</button>
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
            
            <a href="dashboard.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold traknsition-all">
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
                <a href="financials.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">payments</span> <span>Financials</span>
                </a>
                <a href="report.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">bar_chart</span> <span>Reports</span>
                </a>
                <a href="support.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">support_agent</span> <span>Help & Support</span>
                </a>
                <a href="feedback.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">feedback</span> <span>Feedback</span>
                </a>
                <a href="tenantauditlogs.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all hover:text-slate-800">
                    <span class="material-symbols-outlined text-2xl">history</span> <span>Audit Logs</span>
                </a>
                <a href="tenantsettings.php" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                    <span class="material-symbols-outlined text-2xl icon-filled">settings</span> <span>Settings</span>
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
        <div class="max-w-4xl mx-auto space-y-8 pb-20">
            <div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
                <div>
                    <h2 class="text-3xl font-black text-slate-800 tracking-tighter uppercase leading-tight">Clinic Settings</h2>
                    <p class="text-slate-500 font-medium">I-customize ang clinic operations at public landing page.</p>
                </div>
                <?php if ($isLinkValid): ?>
                <div>
                    <button onclick="openPreviewModal('<?= htmlspecialchars($clinicPortalLink) ?>&preview=1')" class="bg-slate-800 hover:bg-slate-900 text-white px-5 py-2.5 rounded-xl font-bold text-sm transition-all flex items-center gap-2 shadow-sm" title="Open Live Preview">
                        <span class="material-symbols-outlined text-base">visibility</span> Live Preview
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <div class="bg-white rounded-[32px] border border-slate-200 p-8 shadow-sm relative overflow-hidden">
                <div class="absolute -right-10 -top-10 size-40 bg-primary/5 rounded-full pointer-events-none"></div>

                <form method="POST" action="tenantsettings.php" enctype="multipart/form-data" class="space-y-10 relative z-10">
                    <input type="hidden" name="action" value="customize_portal">
                    
                    <div>
                        <div class="flex items-center gap-3 mb-6 relative z-10">
                            <div class="size-10 <?= $sidebarActive ?> rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-xl icon-filled">palette</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-slate-800 tracking-tight">Clinic Branding & Preferences</h3>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Setup your clinic's public profile and operational settings.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                            <div class="space-y-2 md:col-span-1">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Clinic Name</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">local_hospital</span>
                                    <input type="text" name="clinic_name" value="<?= htmlspecialchars($clinicName) ?>" required class="w-full pl-12 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                </div>
                            </div>

                            <div class="space-y-2 md:col-span-2">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Complete Address</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">location_on</span>
                                    <input type="text" name="clinic_address" value="<?= htmlspecialchars($clinicAddress) ?>" placeholder="e.g. 123 Main St, Brgy. San Jose, Manila" required class="w-full pl-12 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                </div>
                            </div>
                            
                            <div class="space-y-2 md:col-span-1">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Contact Number</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none">call</span>
                                    <input type="text" name="clinic_contact" id="clinic_contact"
                                        value="<?= htmlspecialchars($clinicContact ?: '09') ?>"
                                        placeholder="09XXXXXXXXX"
                                        required maxlength="11" pattern="^09\d{9}$"
                                        title="Must be 11 digits starting with 09"
                                        class="w-full pl-12 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all"
                                        oninput="
                                            var v = this.value.replace(/[^0-9]/g,'');
                                            if (!v.startsWith('09')) { v = '09' + v.replace(/^0*9?/, ''); }
                                            this.value = v.substring(0, 11);
                                        "
                                        onkeydown="if(this.selectionStart<=1 && (event.key==='Backspace'||event.key==='Delete')) event.preventDefault();"
                                        onfocus="if(!this.value.startsWith('09')) this.value='09';"
                                        onblur="if(!/^09\d{9}$/.test(this.value)){this.setCustomValidity('Must be 11 digits starting with 09');}else{this.setCustomValidity('');}">
                                </div>
                                <p class="text-[10px] text-slate-400 mt-1">11 digits, must start with 09</p>
                            </div>
                        </div>

                        <div class="border-t border-slate-100 pt-6 space-y-5">
                            <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Global Theme Color</label>

                            <!-- Custom Color Picker -->
                            <div class="flex items-center gap-3 bg-slate-50 border border-dashed border-slate-300 rounded-2xl px-5 py-3.5 cursor-pointer hover:border-primary/40 hover:bg-primary/5 transition-all group" onclick="document.getElementById('customColorToggle').checked = true; document.getElementById('theme_color_input').click();">
                                <span class="material-symbols-outlined text-lg text-slate-400 group-hover:text-primary transition-colors">add_circle</span>
                                <span class="text-sm font-bold text-slate-600 group-hover:text-primary transition-colors">✦ Create Custom Color</span>
                                <input type="color" id="theme_color_input" name="theme_color" value="<?= htmlspecialchars($themeColor) ?>" class="size-7 rounded-full cursor-pointer border-0 p-0 shadow-sm shrink-0 ml-auto" onchange="document.getElementById('customColorToggle').checked = true; selectCustomColor(this.value);">
                            </div>

                            <!-- Curated Presets -->
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-[0.15em]">Curated Themes</p>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1">
                                <?php
                                $presets = [
                                    ['name' => 'Rose Blush',     'color' => '#e11d48', 'accent' => '#fda4af', 'bg' => '#fff1f2'],
                                    ['name' => 'Soft Lavender',  'color' => '#7c3aed', 'accent' => '#c4b5fd', 'bg' => '#f5f3ff'],
                                    ['name' => 'Ocean Calm',     'color' => '#0284c7', 'accent' => '#38bdf8', 'bg' => '#f0f9ff'],
                                    ['name' => 'Sage Green',     'color' => '#15803d', 'accent' => '#86efac', 'bg' => '#f0fdf4'],
                                    ['name' => 'Warm Peach',     'color' => '#ea580c', 'accent' => '#fdba74', 'bg' => '#fff7ed'],
                                    ['name' => 'Teal Serenity',  'color' => '#0d9488', 'accent' => '#5eead4', 'bg' => '#f0fdfa'],
                                    ['name' => 'Dusty Mauve',    'color' => '#a21caf', 'accent' => '#e879f9', 'bg' => '#fdf4ff'],
                                    ['name' => 'Golden Hour',    'color' => '#b45309', 'accent' => '#fbbf24', 'bg' => '#fffbeb'],
                                    ['name' => 'Berry Wine',     'color' => '#9f1239', 'accent' => '#fb7185', 'bg' => '#fff1f2'],
                                    ['name' => 'Sky Blue',       'color' => '#2563eb', 'accent' => '#93c5fd', 'bg' => '#eff6ff'],
                                    ['name' => 'Coral Bloom',    'color' => '#db2777', 'accent' => '#f9a8d4', 'bg' => '#fdf2f8'],
                                    ['name' => 'Slate Minimal',  'color' => '#475569', 'accent' => '#94a3b8', 'bg' => '#f8fafc'],
                                ];
                                foreach ($presets as $p):
                                    $isActive = (strtolower($themeColor) === strtolower($p['color']));
                                ?>
                                <label class="flex items-center gap-3 px-3 py-2.5 rounded-xl cursor-pointer transition-all hover:bg-slate-50 <?= $isActive ? 'bg-slate-50 ring-1 ring-primary/30' : '' ?>">
                                    <input type="radio" name="theme_preset" value="<?= $p['color'] ?>" class="hidden preset-radio" <?= $isActive ? 'checked' : '' ?> onchange="applyPreset('<?= $p['color'] ?>')">
                                    <span class="flex items-center gap-1 shrink-0">
                                        <span class="size-5 rounded-full border border-white shadow-sm" style="background:<?= $p['color'] ?>"></span>
                                        <span class="size-5 rounded-full border border-white shadow-sm -ml-1.5" style="background:<?= $p['accent'] ?>"></span>
                                    </span>
                                    <span class="text-sm font-semibold text-slate-700 <?= $isActive ? 'text-primary' : '' ?>"><?= $p['name'] ?></span>
                                    <?php if ($isActive): ?>
                                        <span class="material-symbols-outlined text-primary text-sm ml-auto">check_circle</span>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>

                            <input type="hidden" id="customColorToggle" name="custom_color_used" value="">
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-4">
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Opening Time</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">schedule</span>
                                    <input type="time" name="opening_time" value="<?= htmlspecialchars($openingTime) ?>" required class="w-full pl-12 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                </div>
                            </div>
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Closing Time</label>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-4 top-1/2 -translate-y-1/2 text-slate-400">schedule</span>
                                    <input type="time" name="closing_time" value="<?= htmlspecialchars($closingTime) ?>" required class="w-full pl-12 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Public Portal Headline</label>
                            <input type="text" name="hero_headline" value="<?= htmlspecialchars($heroHeadline) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3.5 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                        </div>

                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Portal Subtitle</label>
                            <textarea name="hero_subtitle" required rows="2" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all resize-none"><?= htmlspecialchars($heroSubtitle) ?></textarea>
                        </div>

                        <div class="space-y-2 mt-4">
                            <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">"About Us" Description</label>
                            <textarea name="about_text" required rows="4" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all resize-none"><?= htmlspecialchars($aboutText) ?></textarea>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 pt-8 relative z-10">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="size-10 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-xl icon-filled">photo_library</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-slate-800 tracking-tight">Photo & Image Settings</h3>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Manage all images displayed across your portal and login page.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <!-- Clinic Logo -->
                            <div class="space-y-3 bg-slate-50 rounded-2xl p-5 border border-slate-200">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Clinic Logo</label>
                                <p class="text-[11px] text-slate-400 font-medium -mt-1">Displayed in the header of all pages and the clinic portal.</p>
                                <?php if ($clinicLogo): ?>
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="size-24 rounded-full overflow-hidden border-2 border-slate-200 shadow-sm bg-white flex items-center justify-center">
                                            <img src="<?= htmlspecialchars($clinicLogo) ?>" class="w-full h-full object-cover" alt="Clinic Logo">
                                        </div>
                                        <span class="text-[10px] text-green-600 font-bold flex items-center gap-1"><span class="material-symbols-outlined text-xs">check_circle</span> Current logo</span>
                                    </div>
                                <?php else: ?>
                                    <div class="flex flex-col items-center gap-2">
                                        <div class="size-24 rounded-full bg-slate-200 flex items-center justify-center">
                                            <span class="material-symbols-outlined text-3xl text-slate-400">image</span>
                                        </div>
                                        <span class="text-[10px] text-slate-400 italic">No logo uploaded</span>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="clinic_logo" accept=".jpg,.jpeg,.png" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-3 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-[10px] file:font-bold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer">
                            </div>

                            <!-- Hero Section Image -->
                            <div class="space-y-3 bg-slate-50 rounded-2xl p-5 border border-slate-200">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Hero Section Image</label>
                                <p class="text-[11px] text-slate-400 font-medium -mt-1">Displayed on the right side of the Hero banner on the Clinic Portal.</p>
                                <?php if ($heroImg): ?>
                                    <div class="rounded-xl overflow-hidden border border-slate-200 shadow-sm h-36 bg-white">
                                        <img src="<?= htmlspecialchars($heroImg) ?>" class="w-full h-full object-cover" alt="Hero Image">
                                    </div>
                                    <span class="text-[10px] text-green-600 font-bold flex items-center gap-1"><span class="material-symbols-outlined text-xs">check_circle</span> Current image</span>
                                <?php else: ?>
                                    <div class="rounded-xl bg-slate-200 h-36 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-3xl text-slate-400">landscape</span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 italic">No image uploaded — default stock photo will be used.</span>
                                <?php endif; ?>
                                <input type="file" name="hero_img" accept=".jpg,.jpeg,.png" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-3 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100 cursor-pointer">
                            </div>

                            <!-- Why Choose Us Image -->
                            <div class="space-y-3 bg-slate-50 rounded-2xl p-5 border border-slate-200">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Why Choose Us Image</label>
                                <p class="text-[11px] text-slate-400 font-medium -mt-1">Displayed in the "Why Choose Us" section of the Clinic Portal.</p>
                                <?php if ($whyImg): ?>
                                    <div class="rounded-xl overflow-hidden border border-slate-200 shadow-sm h-36 bg-white">
                                        <img src="<?= htmlspecialchars($whyImg) ?>" class="w-full h-full object-cover" alt="Why Choose Us Image">
                                    </div>
                                    <span class="text-[10px] text-green-600 font-bold flex items-center gap-1"><span class="material-symbols-outlined text-xs">check_circle</span> Current image</span>
                                <?php else: ?>
                                    <div class="rounded-xl bg-slate-200 h-36 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-3xl text-slate-400">landscape</span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 italic">No image uploaded — default stock photo will be used.</span>
                                <?php endif; ?>
                                <input type="file" name="why_choose_img" accept=".jpg,.jpeg,.png" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-3 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-[10px] file:font-bold file:bg-blue-50 file:text-blue-600 hover:file:bg-blue-100 cursor-pointer">
                            </div>

                            <!-- Login Cover Photo -->
                            <div class="space-y-3 bg-slate-50 rounded-2xl p-5 border border-slate-200">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Login Cover Photo</label>
                                <p class="text-[11px] text-slate-400 font-medium -mt-1">Displayed on the left side of the Staff Login page. Theme color will show if empty.</p>
                                <?php if ($loginCover): ?>
                                    <div class="rounded-xl overflow-hidden border border-slate-200 shadow-sm h-36 bg-white">
                                        <img src="<?= htmlspecialchars($loginCover) ?>" class="w-full h-full object-cover" alt="Login Cover">
                                    </div>
                                    <span class="text-[10px] text-green-600 font-bold flex items-center gap-1"><span class="material-symbols-outlined text-xs">check_circle</span> Current cover photo</span>
                                <?php else: ?>
                                    <div class="rounded-xl bg-slate-200 h-36 flex items-center justify-center">
                                        <span class="material-symbols-outlined text-3xl text-slate-400">door_front</span>
                                    </div>
                                    <span class="text-[10px] text-slate-400 italic">No cover uploaded — theme color gradient will be shown.</span>
                                <?php endif; ?>
                                <input type="file" name="login_cover" accept=".jpg,.jpeg,.png" class="w-full bg-white border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all file:mr-3 file:py-1 file:px-3 file:rounded-full file:border-0 file:text-[10px] file:font-bold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer">
                            </div>
                        </div>
                    </div>

                    <div class="border-t border-slate-200 pt-8 relative z-10">
                        <div class="flex items-center gap-3 mb-6">
                            <div class="size-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center">
                                <span class="material-symbols-outlined text-xl icon-filled">verified_user</span>
                            </div>
                            <div>
                                <h3 class="text-lg font-black text-slate-800 tracking-tight">"Why Choose Us" Section</h3>
                                <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Customize the text content for this homepage section.</p>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Heading</label>
                                <input type="text" name="why_choose_heading" value="<?= htmlspecialchars($whyHeading) ?>" required class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all">
                            </div>
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Description</label>
                                <textarea name="why_choose_desc" required rows="4" class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm text-slate-800 font-medium outline-none focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all resize-none"><?= htmlspecialchars($whyDesc) ?></textarea>
                            </div>
                        </div>
                                
                        <div class="space-y-3 mt-6">
                            <label class="text-xs font-bold text-slate-700 uppercase tracking-widest">Feature Bullets (Max 3)</label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">check_circle</span>
                                    <input type="text" name="feature_1" value="<?= htmlspecialchars($feature1) ?>" required class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                                </div>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">check_circle</span>
                                    <input type="text" name="feature_2" value="<?= htmlspecialchars($feature2) ?>" required class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                                </div>
                                <div class="relative">
                                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm">check_circle</span>
                                    <input type="text" name="feature_3" value="<?= htmlspecialchars($feature3) ?>" required class="w-full pl-9 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-primary focus:ring-2 focus:ring-primary/20">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="pt-8 border-t border-slate-100 flex justify-end mt-6">
                        <button type="submit" class="px-8 py-3 rounded-xl font-bold <?= $mainBtn ?> transition-all shadow-[0_8px_20px_-6px_var(--tw-shadow-color)] shadow-primary/40 active:scale-95 flex items-center gap-2">
                            <span class="material-symbols-outlined text-sm">save</span>
                            Save Settings
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </main>
</div>

<script>
    // Theme preset logic
    function applyPreset(color) {
        document.getElementById('theme_color_input').value = color;
        document.getElementById('customColorToggle').value = '';
        document.querySelectorAll('.preset-radio').forEach(r => {
            const label = r.closest('label');
            const nameSpan = label.querySelector('span.text-sm');
            if (r.value === color) {
                label.classList.add('bg-slate-50', 'ring-1', 'ring-primary/30');
                nameSpan.classList.add('text-primary');
                // Add checkmark if not present
                if (!label.querySelector('.check-icon')) {
                    const check = document.createElement('span');
                    check.className = 'material-symbols-outlined text-primary text-sm ml-auto check-icon';
                    check.textContent = 'check_circle';
                    label.appendChild(check);
                }
            } else {
                label.classList.remove('bg-slate-50', 'ring-1', 'ring-primary/30');
                nameSpan.classList.remove('text-primary');
                const existing = label.querySelector('.check-icon');
                if (existing) existing.remove();
            }
        });
    }

    function selectCustomColor(color) {
        document.getElementById('theme_color_input').value = color;
        document.getElementById('customColorToggle').value = '1';
        // Deselect all presets
        document.querySelectorAll('.preset-radio').forEach(r => {
            r.checked = false;
            const label = r.closest('label');
            label.classList.remove('bg-slate-50', 'ring-1', 'ring-primary/30');
            label.querySelector('span.text-sm').classList.remove('text-primary');
            const existing = label.querySelector('.check-icon');
            if (existing) existing.remove();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        const currentPage = window.location.pathname.split('/').pop().toLowerCase();

        document.querySelectorAll('aside a[href]').forEach((link) => {
            const href = (link.getAttribute('href') || '').split('?')[0].toLowerCase();
            if (href && href === currentPage) {
                link.addEventListener('click', (event) => {
                    event.preventDefault();
                });
            }
        });
    });

    function openPreviewModal(url) {
        document.getElementById('portalIframe').src = url;
        document.getElementById('previewModal').classList.replace('hidden', 'flex');
        setPreviewMode('desktop'); 
    }

    function closePreviewModal() {
        document.getElementById('previewModal').classList.replace('flex', 'hidden');
        setTimeout(() => { document.getElementById('portalIframe').src = ''; }, 300); 
    }

    function setPreviewMode(mode) {
        const container = document.getElementById('iframeContainer');
        const btnDesktop = document.getElementById('btnDesktop');
        const btnMobile = document.getElementById('btnMobile');

        btnDesktop.className = 'px-3 py-1.5 rounded text-slate-500 hover:text-slate-700 text-xs font-bold flex items-center gap-1 transition-all';
        btnMobile.className = 'px-3 py-1.5 rounded text-slate-500 hover:text-slate-700 text-xs font-bold flex items-center gap-1 transition-all';

        if (mode === 'desktop') {
            container.style.maxWidth = '100%';
            btnDesktop.className = 'px-3 py-1.5 rounded bg-white shadow-sm text-slate-700 text-xs font-bold flex items-center gap-1 transition-all';
        } else if (mode === 'mobile') {
            container.style.maxWidth = '375px'; 
            btnMobile.className = 'px-3 py-1.5 rounded bg-white shadow-sm text-slate-700 text-xs font-bold flex items-center gap-1 transition-all';
        }
    }

    function openLogoutModal() {
        document.getElementById('logoutModal').classList.replace('hidden', 'flex');
    }

    function closeLogoutModal() {
        document.getElementById('logoutModal').classList.replace('flex', 'hidden');
    }

    function confirmLogout() {
        document.getElementById('logoutModal').classList.replace('flex', 'hidden');
        document.getElementById('loggingOutScreen').classList.replace('hidden', 'flex');
        setTimeout(() => {
            window.location.href = '?action=logout&c=<?= urlencode($clinicCode) ?>';
        }, 1500);
    }
</script>
</body>
</html>