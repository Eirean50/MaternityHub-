<?php
// Kunin ang Global Settings para sa Logo at Theme Color
$settingsFile = __DIR__ . '/maternityhub_settings.json';
$superLogo = null;
$themeColor = '#15803d'; // Default fallback green kung walang laman
$termsAndConditions = '';

if (file_exists($settingsFile)) {
    $settings = json_decode(file_get_contents($settingsFile), true);
    $superLogo = $settings['super_logo'] ?? null;
    $themeColor = $settings['super_theme_color'] ?? '#15803d'; // Kunin ang kulay dito
    $termsAndConditions = $settings['terms_and_conditions'] ?? '';
}

// Fallback default T&C kung walang naka-save pa
if (trim($termsAndConditions) === '') {
    $termsAndConditions = "MATERNITYHUB - TERMS AND CONDITIONS\nLast Updated: May 2026\n\nThe full Terms and Conditions has not yet been configured by the Platform Owner. Please contact MaternityHub support.";
}

$superLogoPath = ($superLogo && file_exists(__DIR__ . '/uploads/logos/' . $superLogo)) ? 'uploads/logos/' . $superLogo : null;

// ==============================================================
// DYNAMIC TEXT CONTRAST CALCULATOR (Para sa Header Text)
// ==============================================================
$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
}
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);

$isLightTheme = ($luminance > 150); // Kung lampas 150 ang brightness, LIGHT color siya.

// Dynamic Tailwind Class para sa Header Text
$headerText = $isLightTheme ? 'text-slate-900' : 'text-white';
// ==============================================================
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>MaternityHub | The Ultimate Maternity Clinic Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,1,0" rel="stylesheet" />
    <script>
        tailwind.config = { 
            theme: { 
                extend: { 
                    colors: {
                        // Dito papasok yung dynamic na kulay mula sa settings
                        "primary": "<?= htmlspecialchars($themeColor) ?>",
                        "primary-dark": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 70%, black)",
                        "primary-light": "color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 20%, white)",
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
                radial-gradient(circle at 15% 12%, color-mix(in srgb, <?= htmlspecialchars($themeColor) ?> 16%, transparent), transparent 36%),
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
    </style>
</head>
<body class="text-slate-800 antialiased selection:bg-primary-light selection:text-primary-dark font-body">

    <nav class="fixed w-full z-50 bg-primary/95 backdrop-blur-xl border-b border-primary-dark/20 transition-all shadow-[0_8px_30px_rgba(15,23,42,0.06)] <?= $headerText ?>">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3 cursor-pointer">
                <div class="size-14 rounded-full flex items-center justify-center overflow-hidden bg-[#f8f5eb] shadow-sm shrink-0">
                    <?php if($superLogoPath): ?>
                        <img src="<?= htmlspecialchars($superLogoPath) ?>" alt="Logo" class="size-full object-contain p-1.5">
                    <?php else: ?>
                        <div class="size-full bg-primary-dark text-white flex items-center justify-center shadow-inner">
                            <span class="material-symbols-outlined text-3xl">child_care</span>
                        </div>
                    <?php endif; ?>
                </div>
                <span class="text-2xl font-extrabold tracking-tight font-display">MaternityHub</span>
            </div>
        </div>
    </nav>

    <section class="relative pt-32 pb-20 lg:pt-48 lg:pb-32 overflow-hidden">
        <div class="ambient-orb size-64 rounded-full bg-primary/20 -top-12 -left-10"></div>
        <div class="ambient-orb size-72 rounded-full bg-sky-200/55 top-10 -right-12"></div>
        <div class="absolute inset-0 hero-pattern z-0"></div>
        <div class="max-w-7xl mx-auto px-6 relative z-10 grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
            
            <div class="space-y-8 text-center lg:text-left fade-up">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-white/85 border border-primary/25 text-primary-dark font-bold text-xs uppercase tracking-widest shadow-sm">
                    <span class="size-2 rounded-full bg-primary animate-pulse"></span> Multi-Tenant Platform Ready
                </div>
                <h1 class="text-5xl lg:text-7xl font-black text-slate-900 tracking-tighter leading-[1.08] font-display">
                    Modernize your <span class="text-primary">Maternity Clinic</span> today.
                </h1>
                <p class="text-lg text-slate-500 font-medium leading-relaxed max-w-xl mx-auto lg:mx-0">
                    The complete, secure, and DOH-compliant electronic health record (EHR) and management system built specifically for lying-in clinics in the Philippines.
                </p>
                
                <div class="flex flex-col sm:flex-row gap-3 items-center justify-center lg:justify-start fade-up fade-up-delay-1">
                    
                    <a href="registration.php" class="w-full sm:w-auto px-8 py-4 bg-primary text-white text-center rounded-full font-bold shadow-float hover:bg-primary-dark transition-all">
                        Login Clinic
                    </a>

                </div>
                
                <p class="text-xs text-slate-400 font-semibold fade-up fade-up-delay-2">No credit card required. DOH LTO required upon registration.</p>
            </div>
            
            <div class="relative hidden lg:block fade-up fade-up-delay-1">
                <div class="absolute inset-0 bg-primary/10 rounded-full transform rotate-3 scale-105 -z-10"></div>
                
                <div class="size-[500px] rounded-full shadow-2xl border-8 border-[#f8f5eb] relative overflow-hidden flex items-center justify-center bg-[#f8f5eb] mx-auto lg:mx-0">
                    
                    <div class="relative z-10 w-full h-full flex items-center justify-center p-12">
                        <?php if($superLogoPath): ?>
                            <img src="<?= htmlspecialchars($superLogoPath) ?>" alt="MaternityHub Full Logo" class="w-full h-full object-contain">
                        <?php else: ?>
                            <div class="size-full bg-primary-light/30 text-primary flex items-center justify-center rounded-full">
                                <span class="material-symbols-outlined text-[12rem]">child_care</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
        </div>
    </section>

    <footer class="pb-8">
        <div class="max-w-7xl mx-auto px-6">
            <div class="text-center text-xs font-semibold text-slate-400 border-t border-slate-200 pt-5 flex flex-col sm:flex-row items-center justify-center gap-2 sm:gap-4">
                <span>&copy; <?= date('Y') ?> MaternityHub. All Rights Reserved.</span>
                <span class="hidden sm:inline text-slate-300">|</span>
                <button type="button" onclick="openTermsModal()" class="text-primary-dark hover:text-primary font-bold underline-offset-4 hover:underline transition-colors inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-[14px]">gavel</span>
                    Terms and Conditions
                </button>
            </div>
        </div>
    </footer>

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
                        <h3 class="text-xl font-black text-slate-900 leading-tight font-display">Terms and Conditions</h3>
                        <p class="text-[11px] text-slate-400 font-bold uppercase tracking-widest">MaternityHub Platform Agreement</p>
                    </div>
                </div>
                <button type="button" onclick="closeTermsModal()" class="size-10 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-600 flex items-center justify-center transition-colors shrink-0" aria-label="Close">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            <div class="overflow-y-auto px-8 py-6 text-sm text-slate-700 leading-relaxed whitespace-pre-wrap font-body">
<?= htmlspecialchars($termsAndConditions) ?>
            </div>
            <div class="px-8 py-5 border-t border-slate-100 bg-slate-50 flex justify-end">
                <button type="button" onclick="closeTermsModal()" class="px-6 py-2.5 bg-primary text-white rounded-full font-bold text-xs uppercase tracking-widest hover:bg-primary-dark transition-colors shadow-md">
                    I Understand
                </button>
            </div>
        </div>
    </div>

    <script>
        function openTermsModal() {
            const m = document.getElementById('termsModal');
            m.classList.remove('hidden');
            m.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        function closeTermsModal() {
            const m = document.getElementById('termsModal');
            m.classList.add('hidden');
            m.classList.remove('flex');
            document.body.style.overflow = '';
        }
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTermsModal();
        });
    </script>

</body>
</html>