<?php
// 1. SETTINGS & SESSION
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

ob_start();
session_start();
require_once 'db.php';

// Kunin ang Global Settings para sa Logo at Theme Color (Fallback)
$settingsFile = __DIR__ . '/maternityhub_settings.json';
$superLogo = null;
$themeColor = '#15803d'; // Default fallback green kung walang laman
$clinicName = 'MaternityHub';
$clinicAddress = ''; // Fallback address
$clinicContact = ''; // Fallback contact
$maintenanceMode = false;

if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $superLogo = $settings['super_logo'] ?? null;
    $themeColor = $settings['super_theme_color'] ?? '#15803d';
    $maintenanceMode = $settings['maintenance_mode'] ?? false;
}

$displayLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;
$displayThemeColor = $themeColor;
$displayClinicName = $clinicName;
$displayClinicAddress = $clinicAddress;
$displayClinicContact = $clinicContact;

// Default Texts kung walang custom
$displayHeroHeadline = "Welcome to " . $displayClinicName;
$displayHeroSubtitle = "The complete, secure, and DOH-compliant electronic health record (EHR) and management system built specifically for lying-in clinics in the Philippines.";
$clinicCodeParam = ''; 
$clinicCode = ''; // Variable for Clinic Code

// 🔥 DEFAULTS PARA SA 'WHY CHOOSE US' SECTION 🔥
$displayWhyHeading = "Trusted Care for Your Growing Family";
$displayWhyDesc = "At our clinic, we believe that every pregnancy journey is unique. Our dedicated team of healthcare professionals is committed to providing personalized, high-quality care in a warm and homely environment.";
$displayFeature1 = "Licensed & Experienced Staff";
$displayFeature2 = "Clean & Modern Facilities";
$displayFeature3 = "Affordable & Accessible Care";
$displayWhyImg = "https://images.unsplash.com/photo-1584515933487-779824d29309?q=80&w=800&auto=format&fit=crop"; // Default stock image
$displayHeroImg = "https://images.unsplash.com/photo-1584515933487-779824d29309?q=80&w=800&auto=format&fit=crop"; // Default hero image

// ==============================================================
// TENANT DATA OVERRIDE LOGIC
// ==============================================================
if (isset($_GET['c']) && !empty($_GET['c'])) {
    $clinicCode = trim($_GET['c']);
    $clinicCodeParam = "?c=" . urlencode($clinicCode);
    
    try {
        // 🔥 IN-UPDATE NA QUERY PARA KUNIN ANG WHY CHOOSE US DATA 🔥
        $stmt = $pdo->prepare("SELECT clinic_name, clinic_code, clinic_logo, theme_color, complete_address, clinic_contact, hero_headline, hero_subtitle, why_choose_heading, why_choose_desc, feature_1, feature_2, feature_3, why_choose_img, hero_img, status, suspension_reason FROM tenants WHERE clinic_code = ?");
        $stmt->execute([$clinicCode]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tenant) {
            $displayClinicName = $tenant['clinic_name'];
            $displayClinicAddress = $tenant['complete_address'] ?? '';
            $displayClinicContact = $tenant['clinic_contact'] ?? '';
            $clinicStatus = $tenant['status'] ?? '';
            $clinicSuspensionReason = $tenant['suspension_reason'] ?? '';
            $isClinicSuspended = (strtolower(trim((string)$clinicStatus)) === 'suspended');
            
            // I-update ang default headline base sa pangalan ng clinic
            $displayHeroHeadline = "Welcome to " . $displayClinicName;

            if (!empty($tenant['theme_color'])) {
                $displayThemeColor = $tenant['theme_color'];
            }
            if (!empty($tenant['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $tenant['clinic_logo'])) {
                $displayLogoPath = 'uploads/logos/' . $tenant['clinic_logo'];
            }
            
            if (!empty($tenant['hero_headline'])) $displayHeroHeadline = $tenant['hero_headline'];
            if (!empty($tenant['hero_subtitle'])) $displayHeroSubtitle = $tenant['hero_subtitle'];
            
            // 🔥 OVERRIDE WHY CHOOSE US DATA KUNG MAY SINET ANG CLINIC ADMIN 🔥
            if (!empty($tenant['why_choose_heading'])) $displayWhyHeading = $tenant['why_choose_heading'];
            if (!empty($tenant['why_choose_desc'])) $displayWhyDesc = $tenant['why_choose_desc'];
            if (!empty($tenant['feature_1'])) $displayFeature1 = $tenant['feature_1'];
            if (!empty($tenant['feature_2'])) $displayFeature2 = $tenant['feature_2'];
            if (!empty($tenant['feature_3'])) $displayFeature3 = $tenant['feature_3'];
            
            if (!empty($tenant['why_choose_img']) && file_exists(__DIR__ . '/uploads/images/' . $tenant['why_choose_img'])) {
                $displayWhyImg = 'uploads/images/' . $tenant['why_choose_img'];
            }
            if (!empty($tenant['hero_img']) && file_exists(__DIR__ . '/uploads/images/' . $tenant['hero_img'])) {
                $displayHeroImg = 'uploads/images/' . $tenant['hero_img'];
            }
            
        } else {
             header("Location: registration.php?error=invalid_clinic");
             exit();
        }
    } catch (PDOException $e) {
        // Silent fail
    }
}
// ==============================================================

// ==============================================================
// FETCH CLINIC SERVICES (PARA SA MODAL DISPLAY)
// ==============================================================
$clinicServices = [];
try {
    if (!empty($clinicCode)) {
        // Kunin muna ang TenantID gamit ang clinic code
        $stmtTenant = $pdo->prepare("SELECT TenantID FROM tenants WHERE clinic_code = ?");
        $stmtTenant->execute([$clinicCode]);
        $tenantIdRow = $stmtTenant->fetch(PDO::FETCH_ASSOC);
        
        if ($tenantIdRow) {
            $tenant_id = $tenantIdRow['TenantID'];
            // Kunin ang services
            $stmtSrv = $pdo->prepare("
                SELECT * FROM clinic_services 
                WHERE TenantID = ? 
                ORDER BY 
                    CASE service_name 
                        WHEN 'Prenatal Checkup' THEN 1 
                        WHEN 'Postnatal Checkup' THEN 2 
                        WHEN 'Normal Delivery' THEN 3 
                        WHEN 'Cesarean Delivery' THEN 4 
                        ELSE 5 
                    END, 
                    service_name ASC
            ");
            $stmtSrv->execute([$tenant_id]);
            $clinicServices = $stmtSrv->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    // Silent fail
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
$logoMaskBg = $isLightTheme ? 'bg-slate-900' : 'bg-white';
$iconColor = $isLightTheme ? 'text-slate-900' : 'text-white';
$logoBoxBg = $isLightTheme ? 'bg-slate-900/10' : 'bg-white/20';
$logoBorderOp = $isLightTheme ? 'border-slate-900/20' : 'border-white/20';

// DYNAMIC BADGE COLORS PARA SA CLINIC CODE
$codeBadgeBg = $isLightTheme ? 'bg-slate-900/10 border-slate-900/20 text-slate-800' : 'bg-white/20 border-white/30 text-white';
$codeHighlightBg = $isLightTheme ? 'bg-slate-900 text-white' : 'bg-white text-primary-dark';
// ==============================================================
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title><?= htmlspecialchars($displayClinicName) ?> | Maternity Clinic Management</title>
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
                        "accent": "#fb7185",
                        "ink": "#0f172a",
                    }, 
                    fontFamily: {
                        "display": ["Sora", "sans-serif"],
                        "body": ["Plus Jakarta Sans", "sans-serif"]
                    },
                    boxShadow: {
                        'soft': '0 20px 40px -15px rgba(0,0,0,0.07)',
                        'float': '0 24px 60px -24px rgba(0,0,0,0.15)'
                    }
                } 
            } 
        }
    </script>
    <style>
        :root {
            --bg-1: #f4fbf8;
            --bg-2: #eef6ff;
            --bg-3: #fff6f8;
        }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background:
                radial-gradient(circle at 15% 12%, color-mix(in srgb, <?= htmlspecialchars($displayThemeColor) ?> 16%, transparent), transparent 36%),
                radial-gradient(circle at 88% 18%, rgba(59, 130, 246, 0.12), transparent 35%),
                radial-gradient(circle at 72% 82%, rgba(251, 113, 133, 0.10), transparent 34%),
                linear-gradient(120deg, var(--bg-1), var(--bg-2) 52%, var(--bg-3));
        }
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
        .fade-up {
            opacity: 0;
            transform: translateY(14px);
            animation: fadeUp 700ms ease forwards;
        }
        .fade-up-delay-1 { animation-delay: 120ms; }
        .fade-up-delay-2 { animation-delay: 220ms; }
        .fade-up-delay-3 { animation-delay: 320ms; }
        @keyframes fadeUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        /* Modal Scrollbar */
        .scrollable-box::-webkit-scrollbar { width: 6px; }
        .scrollable-box::-webkit-scrollbar-track { background: transparent; }
        .scrollable-box::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .scrollable-box::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
</head>
<body class="text-slate-800 antialiased selection:bg-primary-light selection:text-primary-dark font-body min-h-screen flex flex-col">

    <nav class="fixed w-full z-50 bg-primary/95 backdrop-blur-xl border-b border-primary-dark/20 transition-all shadow-[0_8px_30px_rgba(15,23,42,0.06)] <?= $headerText ?>">
        <div class="max-w-7xl mx-auto px-6 py-3 min-h-[80px] flex items-center justify-between">
            
            <div class="flex items-center gap-3 md:gap-4 cursor-default">
                <div class="size-12 rounded-full <?= $logoBoxBg ?> <?= $logoBorderOp ?> overflow-hidden flex items-center justify-center shrink-0 border backdrop-blur-sm">
                    <?php if($displayLogoPath): ?>
                        <img src="<?= htmlspecialchars($displayLogoPath) ?>" alt="<?= htmlspecialchars($displayClinicName) ?> Logo" class="size-full object-cover">
                    <?php else: ?>
                        <span class="material-symbols-outlined text-4xl <?= $iconColor ?>">child_care</span>
                    <?php endif; ?>
                </div>
                
                <div class="flex flex-col justify-center py-1">
                    <span class="text-xl md:text-2xl font-extrabold tracking-tight font-display leading-none"><?= htmlspecialchars($displayClinicName) ?></span>
                </div>
            </div>

            <div class="flex items-center gap-6">
                <button onclick="openServicesModal()" class="flex items-center gap-1.5 font-bold text-sm px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 border border-transparent hover:border-white/30 transform hover:-translate-y-0.5 hover:shadow-lg active:scale-95 transition-all duration-300 ease-out">
                    <span class="material-symbols-outlined text-[20px]">medical_services</span> Services
                </button>

                <div class="hidden sm:flex items-center gap-1.5 opacity-80 border-l border-current pl-6">
                    <span class="text-xs font-medium">Powered by</span>
                    <span class="text-sm font-black font-display flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">child_care</span>MaternityHub
                    </span>
                </div>
            </div>
            
        </div>
    </nav>

    <div id="servicesModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/60 backdrop-blur-sm transition-opacity">
        <div class="bg-white rounded-[2rem] shadow-2xl border border-slate-100 w-full max-w-4xl flex flex-col max-h-[85vh] overflow-hidden transform scale-95 opacity-0 transition-all duration-300" id="servicesModalBox">
            
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/80 shrink-0">
                <div class="flex items-center gap-3">
                    <div class="size-10 rounded-xl bg-primary/10 text-primary flex items-center justify-center shadow-sm">
                        <span class="material-symbols-outlined icon-filled">medical_services</span>
                    </div>
                    <div>
                        <h3 class="text-xl font-black text-slate-800 tracking-tight leading-none font-display">Our Medical Services</h3>
                        <p class="text-xs text-slate-500 mt-1 font-medium">Comprehensive care tailored for you.</p>
                    </div>
                </div>
                <button onclick="closeServicesModal()" class="size-8 rounded-full bg-white border border-slate-200 hover:bg-slate-100 text-slate-400 hover:text-slate-700 flex items-center justify-center active:scale-90 transition-all duration-200 shadow-sm">
                    <span class="material-symbols-outlined text-xl">close</span>
                </button>
            </div>
            
            <div class="flex-1 overflow-y-auto p-6 scrollable-box bg-slate-50/50">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php if(empty($clinicServices)): ?>
                        <div class="col-span-full text-center py-16 px-6 rounded-3xl bg-white border border-dashed border-slate-300">
                            <span class="material-symbols-outlined text-5xl text-slate-300 mb-3">medical_information</span>
                            <p class="text-slate-500 font-bold text-lg">Services will be updated soon.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach($clinicServices as $srv): ?>
                        <?php 
                            $icon = 'medical_services';
                            $sName = strtolower($srv['service_name']);
                            if (strpos($sName, 'prenatal') !== false || strpos($sName, 'postnatal') !== false) $icon = 'pregnant_woman';
                            elseif (strpos($sName, 'ultrasound') !== false) $icon = 'monitor_heart';
                            elseif (strpos($sName, 'delivery') !== false) $icon = 'child_care';
                            elseif (strpos($sName, 'vaccine') !== false || strpos($sName, 'immunization') !== false) $icon = 'vaccines';
                        ?>
                        <div class="p-5 rounded-2xl bg-white border border-slate-200 hover:border-primary/50 hover:shadow-soft hover:-translate-y-1 transition-all duration-300 ease-out flex items-center gap-4 group cursor-default">
                            <div class="size-12 rounded-xl bg-primary/10 text-primary flex items-center justify-center shrink-0 group-hover:scale-110 group-hover:bg-primary group-hover:text-white transition-all duration-300 shadow-sm">
                                <span class="material-symbols-outlined text-[24px] icon-filled"><?= $icon ?></span>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-sm font-bold text-slate-800 font-display leading-tight"><?= htmlspecialchars($srv['service_name']) ?></h3>
                            </div>
                            <div class="text-right shrink-0 border-l border-slate-100 pl-4">
                                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest block mb-0.5">Rate</span>
                                <span class="text-base font-black text-primary-dark tracking-tight">₱<?= number_format($srv['price'], 2) ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-slate-100 bg-white shrink-0 text-center">
                <p class="text-xs text-slate-500 font-medium">For inquiries or custom packages, please contact the clinic directly.</p>
            </div>
        </div>
    </div>

    <main class="flex-1 flex flex-col">
        
        <section class="relative min-h-screen flex items-center pt-20 pb-12 overflow-hidden border-b border-slate-200/50">
            <div class="ambient-orb size-64 rounded-full bg-primary/20 -top-12 -left-10"></div>
            <div class="ambient-orb size-72 rounded-full bg-sky-200/55 top-10 -right-12"></div>
            <div class="absolute inset-0 hero-pattern z-0"></div>
            
            <div class="w-full max-w-7xl mx-auto px-6 relative z-10 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                
                <div class="space-y-6 text-center lg:text-left fade-up">
                    
                    <div class="flex flex-wrap justify-center lg:justify-start items-center gap-3">
                        <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/85 border border-primary/25 text-primary-dark font-bold text-xs uppercase tracking-widest shadow-sm">
                            <span class="size-2 rounded-full bg-primary animate-pulse"></span> Multi-Tenant Platform Ready
                        </div>

                        <?php if(!empty($clinicCode)): ?>
                            <button onclick="copyClinicCode('<?= htmlspecialchars($clinicCode) ?>', this)" title="Copy Code" class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-slate-50 border border-slate-200 text-slate-500 font-bold text-xs uppercase tracking-widest shadow-sm hover:bg-white hover:shadow-md transition-all active:scale-95 group">
                                <span class="material-symbols-outlined text-[16px]">qr_code</span> Clinic Code: <span class="text-slate-800 font-black"><?= htmlspecialchars($clinicCode) ?></span>
                                <span class="material-symbols-outlined text-[14px] text-primary/70 group-hover:text-primary transition-colors copy-icon">content_copy</span>
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <h1 class="text-5xl lg:text-7xl font-black text-slate-900 tracking-tighter leading-[1.08] font-display text-primary mt-4">
                        <?= htmlspecialchars($displayHeroHeadline) ?>
                    </h1>
                    
                    <p class="text-lg text-slate-500 font-medium leading-relaxed max-w-xl mx-auto lg:mx-0">
                        <?= htmlspecialchars($displayHeroSubtitle) ?>
                    </p>
                    
                    <div class="flex flex-col items-center lg:items-start fade-up fade-up-delay-1 mt-4">
                        <div class="flex flex-col sm:flex-row gap-4 items-center justify-center lg:justify-start w-full sm:w-auto">
                            <a href="https://maternityhub.alwaysdata.net/Downloads/Maternity Hub.apk" download="Maternity Hub.apk" class="w-full sm:w-auto px-8 py-4 bg-primary text-white text-center rounded-full font-bold shadow-float hover:shadow-xl hover:shadow-primary/40 hover:-translate-y-1 hover:bg-primary-dark active:scale-95 transition-all duration-300 ease-out flex items-center justify-center gap-2 group">
                                <span class="material-symbols-outlined text-xl group-hover:animate-bounce">download</span>
                                Download App
                            </a>
                        </div>
                        <p class="text-xs text-slate-400 font-semibold mt-3 text-center lg:text-left max-w-xs">
                            Download the MaternityHub app to book appointments, access your records, and leave feedback.
                        </p>
                    </div>
                    
                </div>
                
                <div class="relative hidden lg:block fade-up fade-up-delay-1">
                    <div class="absolute inset-0 bg-primary/15 rounded-[2.5rem] transform rotate-3 scale-105 -z-10 transition-transform duration-500 hover:rotate-6"></div>
                    <div class="absolute inset-0 bg-accent/10 rounded-[2.5rem] transform -rotate-3 scale-105 -z-10"></div>
                    
                    <div class="w-full max-w-[480px] aspect-square rounded-[2.5rem] shadow-2xl border-[12px] border-white relative overflow-hidden bg-slate-50 mx-auto lg:mx-0 hover:scale-[1.02] hover:shadow-primary/20 transition-all duration-500 ease-out group">
                        
                        <div class="relative z-10 w-full h-full">
                            <img src="<?= htmlspecialchars($displayHeroImg) ?>" alt="<?= htmlspecialchars($displayWhyHeading) ?>" class="w-full h-full object-cover transition-transform duration-700 group-hover:scale-110">
                        </div>
                        
                    </div>
                </div>
            </div>

            <div class="absolute bottom-8 left-1/2 -translate-x-1/2 z-20 flex flex-col items-center animate-bounce cursor-pointer opacity-70 hover:opacity-100 transition-opacity" onclick="document.getElementById('why-choose-us').scrollIntoView({behavior: 'smooth'})">
                <span class="material-symbols-outlined text-slate-500 text-4xl hover:text-primary transition-colors drop-shadow-sm">keyboard_arrow_down</span>
            </div>

        </section>

        <section id="why-choose-us" class="py-24 bg-white relative z-10 border-b border-slate-100">
            <div class="max-w-7xl mx-auto px-6 grid grid-cols-1 lg:grid-cols-2 gap-12 lg:gap-20 items-center">
                
                <div class="fade-up relative group cursor-pointer w-full max-w-md mx-auto lg:max-w-none">
                    <div class="absolute inset-0 bg-primary/15 transform rotate-3 rounded-[2rem] -z-10 group-hover:rotate-6 group-hover:scale-105 transition-all duration-500 ease-out"></div>
                    <div class="absolute inset-0 bg-accent/10 transform -rotate-3 rounded-[2rem] -z-10 group-hover:-rotate-6 group-hover:scale-105 transition-all duration-500 ease-out delay-75"></div>
                    
                    <div class="overflow-hidden rounded-[2rem] shadow-xl border-4 border-white relative z-10">
                        <img src="<?= htmlspecialchars($displayWhyImg) ?>" 
                             alt="Why Choose Us" 
                             class="w-full h-auto object-cover aspect-square sm:aspect-[4/3] lg:aspect-square transform group-hover:scale-110 transition-transform duration-700 ease-in-out">
                    </div>
                </div>

                <div class="fade-up fade-up-delay-1 text-center lg:text-left">
                    <p class="text-xs font-black text-primary uppercase tracking-widest mb-3">Why Choose Us</p>
                    <h2 class="text-4xl lg:text-5xl font-black text-slate-900 font-display leading-tight mb-6"><?= htmlspecialchars($displayWhyHeading) ?></h2>
                    
                    <p class="text-slate-500 text-base md:text-lg leading-relaxed mb-8 max-w-xl mx-auto lg:mx-0">
                        <?= htmlspecialchars($displayWhyDesc) ?>
                    </p>

                    <ul class="space-y-4 mb-10 max-w-sm mx-auto lg:mx-0">
                        <?php if(!empty($displayFeature1)): ?>
                        <li class="flex items-center gap-4 bg-slate-50 p-3 rounded-xl border border-slate-100 shadow-sm transition-transform hover:-translate-y-1 hover:shadow-md cursor-default duration-300">
                            <span class="material-symbols-outlined text-primary text-2xl">check_circle</span>
                            <span class="text-slate-700 font-bold text-sm md:text-base"><?= htmlspecialchars($displayFeature1) ?></span>
                        </li>
                        <?php endif; ?>
                        
                        <?php if(!empty($displayFeature2)): ?>
                        <li class="flex items-center gap-4 bg-slate-50 p-3 rounded-xl border border-slate-100 shadow-sm transition-transform hover:-translate-y-1 hover:shadow-md cursor-default duration-300">
                            <span class="material-symbols-outlined text-primary text-2xl">check_circle</span>
                            <span class="text-slate-700 font-bold text-sm md:text-base"><?= htmlspecialchars($displayFeature2) ?></span>
                        </li>
                        <?php endif; ?>
                        
                        <?php if(!empty($displayFeature3)): ?>
                        <li class="flex items-center gap-4 bg-slate-50 p-3 rounded-xl border border-slate-100 shadow-sm transition-transform hover:-translate-y-1 hover:shadow-md cursor-default duration-300">
                            <span class="material-symbols-outlined text-primary text-2xl">check_circle</span>
                            <span class="text-slate-700 font-bold text-sm md:text-base"><?= htmlspecialchars($displayFeature3) ?></span>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </section>

        <section class="py-16 bg-slate-50/50 relative z-10">
            <div class="max-w-7xl mx-auto px-6">
                <div class="p-8 md:p-12 rounded-[2.5rem] bg-ink text-white relative overflow-hidden shadow-2xl fade-up flex flex-col lg:flex-row items-center justify-between gap-10 hover:shadow-primary-dark/30 transition-shadow duration-500">
                    <div class="absolute inset-0 opacity-20 bg-[radial-gradient(circle_at_top_right,_var(--tw-gradient-stops))] from-primary via-transparent to-transparent"></div>
                    <div class="absolute -right-20 -bottom-20 opacity-10">
                        <span class="material-symbols-outlined text-[300px]">local_hospital</span>
                    </div>

                    <div class="relative z-10 text-center lg:text-left">
                        <h3 class="text-3xl font-black font-display mb-6 tracking-tight">Visit <?= htmlspecialchars($displayClinicName) ?></h3>
                        
                        <div class="space-y-5">
                            <div class="flex items-start justify-center lg:justify-start gap-4 group cursor-default">
                                <div class="size-10 rounded-full bg-white/10 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:scale-110 transition-all duration-300">
                                    <span class="material-symbols-outlined text-primary-light group-hover:text-white transition-colors">location_on</span>
                                </div>
                                <div class="text-left">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Clinic Address</p>
                                    <p class="text-slate-200 font-medium text-sm md:text-base leading-relaxed max-w-md group-hover:text-white transition-colors">
                                        <?= !empty($displayClinicAddress) ? htmlspecialchars($displayClinicAddress) : '<span class="italic opacity-50">Address not provided by clinic administrator.</span>' ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-center lg:justify-start gap-4 group cursor-default">
                                <div class="size-10 rounded-full bg-white/10 flex items-center justify-center shrink-0 group-hover:bg-primary group-hover:scale-110 transition-all duration-300">
                                    <span class="material-symbols-outlined text-primary-light group-hover:text-white transition-colors">call</span>
                                </div>
                                <div class="text-left">
                                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-1">Contact Number</p>
                                    <p class="text-slate-200 font-medium text-sm md:text-base group-hover:text-white transition-colors">
                                        <?= !empty($displayClinicContact) ? htmlspecialchars($displayClinicContact) : '<span class="italic opacity-50">Contact not provided.</span>' ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="relative z-10 shrink-0 w-full lg:w-auto mt-6 lg:mt-0 flex flex-col items-center">
                        <?php if (!empty($isClinicSuspended)): ?>
                            <a href="suspended.php<?= $clinicCodeParam ?>" class="w-full lg:w-auto px-8 py-4 bg-amber-500 text-white text-center rounded-2xl font-bold shadow-lg shadow-amber-500/30 hover:bg-amber-600 hover:-translate-y-1 hover:shadow-xl active:scale-95 transition-all duration-300 ease-out flex items-center justify-center gap-2 group">
                                Login Clinic <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">block</span>
                            </a>
                            <p class="text-[11px] text-amber-200 font-medium mt-3 text-center">This clinic is currently suspended.</p>
                        <?php else: ?>
                            <a href="tenant_login.php<?= $clinicCodeParam ?>" class="w-full lg:w-auto px-8 py-4 bg-primary text-white text-center rounded-2xl font-bold shadow-lg shadow-primary/30 hover:bg-primary-dark hover:-translate-y-1 hover:shadow-xl hover:shadow-primary/40 active:scale-95 transition-all duration-300 ease-out flex items-center justify-center gap-2 group">
                                Login Clinic <span class="material-symbols-outlined text-xl group-hover:translate-x-1 transition-transform">login</span>
                            </a>
                            <p class="text-[11px] text-white/50 font-medium mt-3 text-center">Only authorized staff can access this portal.</p>
                        <?php endif; ?>
                    </div>

                </div>

            </div>
        </section>
    </main>

    <footer class="bg-white border-t border-slate-200 mt-auto relative z-10">
        <div class="max-w-7xl mx-auto px-6 py-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-4">
                
                <div class="flex flex-col items-center md:items-start text-center md:text-left">
                    <p class="text-sm font-bold text-slate-700 font-display flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-primary text-[18px]">local_hospital</span>
                        <?= htmlspecialchars($displayClinicName) ?>
                    </p>
                    <p class="text-xs text-slate-400 mt-1">© <?= date('Y') ?> MaternityHub Platform. All rights reserved.</p>
                </div>

                <div class="flex items-center gap-2 shrink-0 bg-slate-50 px-4 py-2 rounded-full border border-slate-100 hover:bg-slate-100 transition-colors duration-300 cursor-default">
                    <span class="text-xs font-semibold text-slate-400">Powered by</span>
                    <span class="text-sm font-black text-primary font-display flex items-center gap-1">
                        <span class="material-symbols-outlined text-[16px]">child_care</span>MaternityHub
                    </span>
                </div>

            </div>
        </div>
    </footer>

    <script>
        function openServicesModal() { 
            document.getElementById('servicesModal').classList.replace('hidden', 'flex'); 
            setTimeout(() => { document.getElementById('servicesModalBox').classList.remove('scale-95', 'opacity-0'); }, 10);
        }
        function closeServicesModal() { 
            document.getElementById('servicesModalBox').classList.add('scale-95', 'opacity-0');
            setTimeout(() => { document.getElementById('servicesModal').classList.replace('flex', 'hidden'); }, 300);
        }

        function copyClinicCode(code, btn) {
            navigator.clipboard.writeText(code).then(() => {
                const icon = btn.querySelector('.copy-icon');
                if (icon) {
                    const orig = icon.innerText;
                    icon.innerText = 'check';
                    setTimeout(() => { icon.innerText = orig; }, 2000);
                }
            }).catch(err => {
                console.error('Failed to copy: ', err);
            });
        }
    </script>
</body>
</html>