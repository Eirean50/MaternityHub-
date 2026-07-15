<?php
date_default_timezone_set('Asia/Manila');
ob_start();
session_start();

function resolveTenantIdFromSession($session) {
    $candidateKeys = ['TenantID', 'TenantD', 'TenandID', 'tenant_id', 'tenantId'];
    foreach ($candidateKeys as $key) {
        if (isset($session[$key])) {
            $value = trim((string)$session[$key]);
            $normalized = strtolower($value);
            $isPlaceholder = ($normalized === '' || $normalized === 'null' || $normalized === 'undefined' || $normalized === 'false' || $normalized === '0');

            if ($isPlaceholder) {
                continue;
            }

            if ($value !== '') {
                return $value;
            }
        }
    }
    return null;
}

function isValidTenantIdValue($value) {
    if ($value === null) return false;
    $value = trim((string)$value);
    if ($value === '') return false;
    $normalized = strtolower($value);
    if (in_array($normalized, ['0', 'null', 'undefined', 'false'], true)) return false;
    return true;
}

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
            $auditTenantCandidate = resolveTenantIdFromSession($_SESSION);
            $auditTenant = ($isSuperAdmin || !isValidTenantIdValue($auditTenantCandidate)) ? null : $auditTenantCandidate;
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
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();

    if (!empty($c) && $c !== 'N/A') {
        header('Location: tenant_login.php?c=' . urlencode($c));
    } else {
        header('Location: tenant_login.php');
    }
    exit();
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] === 'Patient') {
    header('Location: tenant_login.php');
    exit();
}

require_once 'db.php';
if (!isset($pdo) && isset($conn)) { $pdo = $conn; }

$displayName = $_SESSION['full_name'] ?? 'Admin';
$userRole = $_SESSION['role'] ?? 'Clinic Administrator';
$displayRole = $userRole;
$normalizedRole = strtolower(trim((string)$userRole));
$isStaffRole = ($normalizedRole === 'staff');
$userId = $_SESSION['user_id'];

// --- OWNER / STAFF ADMIN PERMISSION SYSTEM ---
$currentUserIsOwner = in_array($normalizedRole, ['admin', 'administrator', 'owner', 'owner/midwife'], true);
$currentUserIsStaffAdmin = false;
$currentUserGrantedFeatures = [];
$tenant_id = null;
$sessionTenantRaw = resolveTenantIdFromSession($_SESSION);

// 1) Authoritative source: users.TenantID
if (isset($pdo)) {
    try {
        $stmtTenantByUser = $pdo->prepare('SELECT TenantID FROM users WHERE id = ? LIMIT 1');
        $stmtTenantByUser->execute([$userId]);
        $tenantFromUser = $stmtTenantByUser->fetchColumn();
        if ($tenantFromUser !== false && isValidTenantIdValue($tenantFromUser)) {
            $tenant_id = trim((string)$tenantFromUser);
        }
    } catch (PDOException $e) {
        // Fallback to other sources below.
    }
}

// 2) Fallback: resolved session tenant value
if (!isValidTenantIdValue($tenant_id) && isValidTenantIdValue($sessionTenantRaw)) {
    $tenant_id = trim((string)$sessionTenantRaw);
}

// 3) If still unresolved and session looks like a clinic code, map via tenants.clinic_code
if (!isValidTenantIdValue($tenant_id) && isValidTenantIdValue($sessionTenantRaw) && isset($pdo)) {
    try {
        $stmtTenantByCode = $pdo->prepare('SELECT TenantID FROM tenants WHERE clinic_code = ? LIMIT 1');
        $stmtTenantByCode->execute([trim((string)$sessionTenantRaw)]);
        $tenantFromCode = $stmtTenantByCode->fetchColumn();
        if ($tenantFromCode !== false && isValidTenantIdValue($tenantFromCode)) {
            $tenant_id = trim((string)$tenantFromCode);
        }
    } catch (PDOException $e) {
        // Keep final fallback behavior.
    }
}

if (isValidTenantIdValue($tenant_id)) {
    $_SESSION['TenantID'] = (string)$tenant_id;
    $_SESSION['tenant_id'] = (string)$tenant_id;
}

$clinicName = 'MaternityHub';
$clinicCode = 'N/A';
$clinicLogo = null;
$themeColor = '#15803d';

if ($tenant_id) {
    try {
        $stmtClinic = $pdo->prepare('SELECT clinic_name, clinic_code, clinic_logo, theme_color FROM tenants WHERE TenantID = ?');
        $stmtClinic->execute([$tenant_id]);
        $clinicData = $stmtClinic->fetch(PDO::FETCH_ASSOC);

        if ($clinicData) {
            $clinicName = $clinicData['clinic_name'] ?? $clinicName;
            if (!empty($clinicData['clinic_code'])) $clinicCode = $clinicData['clinic_code'];
            if (!empty($clinicData['clinic_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $clinicData['clinic_logo'])) {
                $clinicLogo = 'uploads/logos/' . $clinicData['clinic_logo'];
            }
            if (!empty($clinicData['theme_color'])) $themeColor = $clinicData['theme_color'];
        }
    } catch (PDOException $e) {}
}

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
        else { $profilePic = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=' . ltrim($themeColor, '#') . '&color=fff'; }
    } else {
        $profilePic = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=' . ltrim($themeColor, '#') . '&color=fff';
    }
} catch (PDOException $e) {
    $profilePic = 'https://ui-avatars.com/api/?name=' . urlencode($displayName) . '&background=' . ltrim($themeColor, '#') . '&color=fff';
}

$hex = ltrim($themeColor, '#');
if (strlen($hex) == 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$luminance = ($r * 0.299) + ($g * 0.587) + ($b * 0.114);
$isLightTheme = ($luminance > 150);

$headerTextPrimary = $isLightTheme ? 'text-slate-900' : 'text-white';
$headerTextSecondary = $isLightTheme ? 'text-slate-700' : 'text-primary-light';
$headerTextMuted = $isLightTheme ? 'text-slate-400' : 'text-white/50';
$headerBadgeBg = $isLightTheme ? 'bg-slate-200 text-slate-800' : 'bg-black/20 text-white';
$headerIconBox = $isLightTheme ? 'bg-white border border-slate-200' : 'bg-white/15 border border-white/25';
$headerIconColor = $isLightTheme ? 'text-slate-700' : 'text-white/90';
$headerBtn = $isLightTheme ? 'bg-white hover:bg-slate-50 text-slate-800 border-slate-200 shadow-sm' : 'bg-white/15 hover:bg-white/25 text-white border-white/30';
$sidebarActive = $isLightTheme ? 'bg-slate-800 text-white shadow-md' : 'bg-primary/10 text-primary';

// Finish Owner/StaffAdmin detection after $pdo and $tenant_id are ready
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

$statusOptions = ['available', 'occupied', 'cleaning'];
$roomTypeOptions = ['delivery_room', 'labor_room', 'recovery_room', 'infant_ward'];
$recoverySubtypeOptions = ['regular', 'private', 'large_private'];
$recoverySubtypeLabels = [
    'regular' => 'Basic',
    'private' => 'Semi-Private',
    'large_private' => 'Private',
];
$roomError = '';
$roomSuccess = '';
$roomTable = 'clinic_rooms';
$bedTable = 'clinic_room_beds';

function getRoomDisplayConfig($beds) {
    $availableBeds = 0;
    foreach ($beds as $bed) {
        if (strtolower($bed['bed_status']) === 'available') {
            $availableBeds++;
        }
    }

    if ($availableBeds > 0) {
        return ['color' => 'bg-green-100 border-green-500', 'icon' => '', 'label' => 'Available'];
    } else {
        return ['color' => 'bg-red-100 border-red-500', 'icon' => '', 'label' => 'Full'];
    }
}

function getStatusConfig($status) {
    switch (strtolower($status)) {
        case 'available': return ['color' => 'bg-green-100 border-green-500 text-green-900', 'icon' => '', 'label' => 'Available'];
        case 'occupied': return ['color' => 'bg-red-100 border-red-500 text-red-900', 'icon' => '', 'label' => 'Occupied'];
        case 'cleaning': return ['color' => 'bg-yellow-100 border-yellow-500 text-yellow-900', 'icon' => '', 'label' => 'Cleaning'];
        default: return ['color' => 'bg-gray-100 border-gray-500 text-gray-900', 'icon' => '', 'label' => 'Unknown'];
    }
}

function getBedBadgeClasses($status) {
    switch (strtolower($status)) {
        case 'available': return 'bg-emerald-100 text-emerald-800 border border-emerald-200';
        case 'occupied': return 'bg-rose-100 text-rose-800 border border-rose-200';
        case 'cleaning': return 'bg-amber-100 text-amber-800 border border-amber-200';
        default: return 'bg-slate-100 text-slate-700 border border-slate-200';
    }
}

function getBedCardClasses($status) {
    switch (strtolower($status)) {
        case 'available': return 'bg-emerald-50 border-emerald-200';
        case 'occupied': return 'bg-rose-50 border-rose-200';
        case 'cleaning': return 'bg-amber-50 border-amber-200';
        default: return 'bg-slate-50 border-slate-200';
    }
}

function getRoomTypeLogoConfig($roomType) {
    switch (strtolower((string)$roomType)) {
        case 'labor_room':
            return ['icon' => 'local_hospital', 'label' => 'Labor Room', 'bg' => 'bg-purple-100', 'fg' => 'text-purple-700'];
        case 'recovery_room':
            return ['icon' => 'hotel', 'label' => 'Recovery Room', 'bg' => 'bg-amber-100', 'fg' => 'text-amber-700'];
        case 'infant_ward':
            return ['icon' => 'child_friendly', 'label' => 'Infant Ward', 'bg' => 'bg-cyan-100', 'fg' => 'text-cyan-700'];
        default:
            return ['icon' => 'pregnant_woman', 'label' => 'Delivery Room', 'bg' => 'bg-emerald-100', 'fg' => 'text-emerald-700'];
    }
}

$schemaReady = false;
if ($tenant_id) {
    try {
        try {
            $stmtTableCheck = $pdo->query("SHOW TABLES LIKE 'clinic_rooms'");
            if ($stmtTableCheck && $stmtTableCheck->fetchColumn()) {
                $roomTable = 'clinic_rooms';
            } else {
                $stmtTableCheck = $pdo->query("SHOW TABLES LIKE 'clinic_room'");
                if ($stmtTableCheck && $stmtTableCheck->fetchColumn()) {
                    $roomTable = 'clinic_room';
                }
            }
        } catch (PDOException $e) {
            $roomTable = 'clinic_rooms';
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$roomTable} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NOT NULL,
            room_name VARCHAR(150) NOT NULL,
            room_type VARCHAR(30) NOT NULL DEFAULT 'delivery_room',
            status VARCHAR(20) NOT NULL DEFAULT 'available',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_clinic_rooms_tenant (TenantID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE {$roomTable} MODIFY COLUMN TenantID VARCHAR(50) NOT NULL");
        } catch (PDOException $e) {
            // Ignore if already string
        }

        try {
            $pdo->exec("ALTER TABLE {$roomTable} ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'available'");
        } catch (PDOException $e) {
            // Column may already exist
        }

        try {
            $pdo->exec("ALTER TABLE {$roomTable} ADD COLUMN room_id INT NULL");
        } catch (PDOException $e) {
            // Column may already exist
        }

        try {
            $pdo->exec("UPDATE {$roomTable} SET room_id = id WHERE room_id IS NULL OR room_id = 0");
        } catch (PDOException $e) {
            // Non-blocking for compatibility
        }

        try {
            $pdo->exec("ALTER TABLE {$roomTable} ADD UNIQUE KEY uq_room_id (room_id)");
        } catch (PDOException $e) {
            // Unique index may already exist
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$bedTable} (
            id INT AUTO_INCREMENT PRIMARY KEY,
            TenantID VARCHAR(50) NOT NULL,
            room_id INT NOT NULL,
            bed_label VARCHAR(150) NOT NULL,
            bed_status VARCHAR(20) NOT NULL DEFAULT 'available',
            admission_id VARCHAR(100) DEFAULT NULL,
            patient_id VARCHAR(100) DEFAULT NULL,
            patient_name VARCHAR(150) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_tenant (TenantID),
            INDEX idx_room (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE {$bedTable} ADD COLUMN bed_status VARCHAR(20) NOT NULL DEFAULT 'available'");
        } catch (PDOException $e) {
            // Column may already exist
        }

        try {
            $pdo->exec("UPDATE {$bedTable} SET bed_status = status WHERE (bed_status IS NULL OR bed_status = '') AND status IS NOT NULL");
        } catch (PDOException $e) {
            // Ignore if legacy status column does not exist
        }

        try {
            $pdo->exec("ALTER TABLE {$bedTable} MODIFY COLUMN TenantID VARCHAR(50) NOT NULL");
        } catch (PDOException $e) {
            // Ignore if already string
        }

        // Ensure bed_id column exists and is always set to id in clinic_room_beds
        try {
            $pdo->query("SELECT bed_id FROM {$bedTable} LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE {$bedTable} ADD bed_id INT NULL AFTER id");
            } catch (PDOException $ex) {}
        }
        try {
            $pdo->exec("UPDATE {$bedTable} SET bed_id = id WHERE bed_id IS NULL OR bed_id = 0");
        } catch (PDOException $e) {}

        // Ensure room_type column exists in clinic_room_beds and is synced from clinic_rooms
        try {
            $pdo->query("SELECT room_type FROM {$bedTable} LIMIT 1");
        } catch (PDOException $e) {
            try {
                $pdo->exec("ALTER TABLE {$bedTable} ADD room_type VARCHAR(30) DEFAULT NULL AFTER room_id");
            } catch (PDOException $ex) {}
        }
        try {
            $pdo->exec("UPDATE {$bedTable} b INNER JOIN {$roomTable} r ON b.room_id = COALESCE(NULLIF(r.room_id, 0), r.id) AND b.TenantID = r.TenantID SET b.room_type = r.room_type WHERE b.room_type IS NULL OR b.room_type = ''");
        } catch (PDOException $e) {}

        // Recovery room subtype column (regular / private / large_private)
        try {
            $pdo->query("SELECT room_subtype FROM {$roomTable} LIMIT 1");
        } catch (PDOException $e) {
            try { $pdo->exec("ALTER TABLE {$roomTable} ADD COLUMN room_subtype VARCHAR(30) NULL AFTER room_type"); } catch (PDOException $ex) {}
        }
        // Default existing recovery rooms to 'regular' if subtype not set
        try {
            $pdo->exec("UPDATE {$roomTable} SET room_subtype = 'regular' WHERE TenantID = " . $pdo->quote($tenant_id) . " AND room_type = 'recovery_room' AND (room_subtype IS NULL OR room_subtype = '')");
        } catch (PDOException $e) {}

        // Default existing labor rooms to 'regular' (Basic) if subtype not set
        try {
            $pdo->exec("UPDATE {$roomTable} SET room_subtype = 'regular' WHERE TenantID = " . $pdo->quote($tenant_id) . " AND room_type = 'labor_room' AND (room_subtype IS NULL OR room_subtype = '')");
        } catch (PDOException $e) {}

        // Recovery room subtype prices table
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS clinic_room_subtype_prices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                TenantID VARCHAR(50) NOT NULL,
                room_type VARCHAR(30) NOT NULL,
                room_subtype VARCHAR(30) NOT NULL,
                price DECIMAL(10,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_tenant_subtype (TenantID, room_type, room_subtype)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) {}

        // Seed default rows (price 0) so the UI always has the 3 recovery subtypes
        try {
            $stmtSeedPrice = $pdo->prepare("INSERT IGNORE INTO clinic_room_subtype_prices (TenantID, room_type, room_subtype, price) VALUES (?, 'recovery_room', ?, 0)");
            foreach (['regular', 'private', 'large_private'] as $st) {
                $stmtSeedPrice->execute([$tenant_id, $st]);
            }
        } catch (PDOException $e) {}

        // Seed default rows for labor room subtypes. If a legacy single labor price exists
        // (room_subtype = 'default'), copy it as the initial value for all 3 subtypes
        // so existing pricing is preserved.
        try {
            $stmtExistingLaborDef = $pdo->prepare("SELECT price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'labor_room' AND room_subtype = 'default' LIMIT 1");
            $stmtExistingLaborDef->execute([$tenant_id]);
            $existingLaborDefPrice = (float)($stmtExistingLaborDef->fetchColumn() ?: 0);
            $stmtSeedLaborPrice = $pdo->prepare("INSERT IGNORE INTO clinic_room_subtype_prices (TenantID, room_type, room_subtype, price) VALUES (?, 'labor_room', ?, ?)");
            foreach (['regular', 'private', 'large_private'] as $st) {
                $stmtSeedLaborPrice->execute([$tenant_id, $st, $existingLaborDefPrice]);
            }
        } catch (PDOException $e) {}

        // Seed default (single) price rows for labor / delivery / infant ward room types.
        try {
            $stmtSeedDefaultPrice = $pdo->prepare("INSERT IGNORE INTO clinic_room_subtype_prices (TenantID, room_type, room_subtype, price) VALUES (?, ?, 'default', 0)");
            foreach (['labor_room', 'delivery_room', 'infant_ward'] as $rt) {
                $stmtSeedDefaultPrice->execute([$tenant_id, $rt]);
            }
        } catch (PDOException $e) {}

        $schemaReady = true;
    } catch (PDOException $e) {
        $roomError = 'Unable to initialize room storage. Please check database permissions.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $schemaReady && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        if ($action === 'add_room') {
            $roomName = trim($_POST['room_name'] ?? '');
            $roomType = strtolower(trim($_POST['room_type'] ?? ''));
            $roomSubtype = strtolower(trim($_POST['room_subtype'] ?? ''));
            if ($roomName === '') {
                $roomError = 'Room name is required.';
            } elseif (!in_array($roomType, $roomTypeOptions, true)) {
                $roomError = 'Please select a valid room type.';
            } elseif ($roomType === 'recovery_room' && !in_array($roomSubtype, $recoverySubtypeOptions, true)) {
                $roomError = 'Please select a valid Recovery Room type (Basic, Semi-Private, or Private).';
            } elseif ($roomType === 'labor_room' && !in_array($roomSubtype, $recoverySubtypeOptions, true)) {
                $roomError = 'Please select a valid Labor Room type (Basic, Semi-Private, or Private).';
            } else {
                $subtypeToSave = ($roomType === 'recovery_room' || $roomType === 'labor_room') ? $roomSubtype : null;
                $stmtAddRoom = $pdo->prepare("INSERT INTO {$roomTable} (TenantID, room_name, room_type, room_subtype) VALUES (?, ?, ?, ?)");
                $stmtAddRoom->execute([$tenant_id, $roomName, $roomType, $subtypeToSave]);

                $newRoomId = (int)$pdo->lastInsertId();
                if ($newRoomId > 0) {
                    $stmtSyncRoomId = $pdo->prepare("UPDATE {$roomTable} SET room_id = ? WHERE id = ? AND TenantID = ?");
                    $stmtSyncRoomId->execute([$newRoomId, $newRoomId, $tenant_id]);
                }

                if ($roomType === 'delivery_room') {
                    if ($newRoomId > 0) {
                        $stmtAutoBed = $pdo->prepare("INSERT INTO {$bedTable} (TenantID, room_id, bed_label, bed_status) VALUES (?, ?, 'Bed 01', 'available')");
                        $stmtAutoBed->execute([$tenant_id, $newRoomId]);
                    }
                }

                // Semi-Private and Private recovery rooms automatically have 1 bed only.
                if ($roomType === 'recovery_room' && in_array($subtypeToSave, ['private', 'large_private'], true)) {
                    if ($newRoomId > 0) {
                        $stmtAutoBed = $pdo->prepare("INSERT INTO {$bedTable} (TenantID, room_id, bed_label, bed_status, room_type) VALUES (?, ?, 'Bed 01', 'available', 'recovery_room')");
                        $stmtAutoBed->execute([$tenant_id, $newRoomId]);
                    }
                }

                // Semi-Private and Private labor rooms automatically have 1 bed only.
                if ($roomType === 'labor_room' && in_array($subtypeToSave, ['private', 'large_private'], true)) {
                    if ($newRoomId > 0) {
                        $stmtAutoBed = $pdo->prepare("INSERT INTO {$bedTable} (TenantID, room_id, bed_label, bed_status, room_type) VALUES (?, ?, 'Bed 01', 'available', 'labor_room')");
                        $stmtAutoBed->execute([$tenant_id, $newRoomId]);
                    }
                }

                header('Location: room.php?ok=room_added');
                exit();
            }
        }

        if ($action === 'add_bed') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            $bedLabel = trim($_POST['bed_label'] ?? '');

            if ($roomId <= 0 || $bedLabel === '') {
                $roomError = 'A valid room and bed name is required.';
            } else {
                $stmtRoomCheck = $pdo->prepare("SELECT COALESCE(NULLIF(room_id, 0), id) AS room_key, room_name, room_type FROM {$roomTable} WHERE (id = ? OR room_id = ?) AND TenantID = ? LIMIT 1");
                $stmtRoomCheck->execute([$roomId, $roomId, $tenant_id]);
                $roomData = $stmtRoomCheck->fetch(PDO::FETCH_ASSOC);

                if (!$roomData) {
                    $roomError = 'Invalid room selected.';
                } else {
                    if (strtolower((string)$roomData['room_type']) === 'delivery_room') {
                        $stmtDeliveryBeds = $pdo->prepare("SELECT COUNT(*) FROM {$bedTable} WHERE TenantID = ? AND room_id = ?");
                        $stmtDeliveryBeds->execute([$tenant_id, (int)$roomData['room_key']]);
                        $deliveryBedCount = (int)$stmtDeliveryBeds->fetchColumn();

                        if ($deliveryBedCount === 0) {
                            $stmtAutoBed = $pdo->prepare("INSERT INTO {$bedTable} (TenantID, room_id, bed_label, bed_status, room_type) VALUES (?, ?, 'Bed 01', 'available', ?)");
                            $stmtAutoBed->execute([$tenant_id, (int)$roomData['room_key'], strtolower((string)$roomData['room_type'])]);
                            header('Location: room.php?ok=bed_added');
                            exit();
                        } else {
                            $roomError = 'Delivery Room is automatically limited to 1 bed only.';
                        }
                    } else {
                        // Semi-Private / Private recovery rooms are limited to 1 bed only (auto-created).
                        $stmtSubLookup = $pdo->prepare("SELECT COALESCE(LOWER(room_subtype), '') FROM {$roomTable} WHERE (id = ? OR room_id = ?) AND TenantID = ? LIMIT 1");
                        $stmtSubLookup->execute([$roomId, $roomId, $tenant_id]);
                        $thisSubtype = (string)$stmtSubLookup->fetchColumn();

                        if (strtolower((string)$roomData['room_type']) === 'recovery_room' && in_array($thisSubtype, ['private', 'large_private'], true)) {
                            $roomError = ($thisSubtype === 'private' ? 'Semi-Private' : 'Private') . ' Recovery Room is automatically limited to 1 bed only.';
                        } elseif (strtolower((string)$roomData['room_type']) === 'labor_room' && in_array($thisSubtype, ['private', 'large_private'], true)) {
                            $roomError = ($thisSubtype === 'private' ? 'Semi-Private' : 'Private') . ' Labor Room is automatically limited to 1 bed only.';
                        } else {
                            $stmtAddBed = $pdo->prepare("INSERT INTO {$bedTable} (TenantID, room_id, bed_label, bed_status, room_type) VALUES (?, ?, ?, 'available', ?)");
                            $stmtAddBed->execute([$tenant_id, (int)$roomData['room_key'], $bedLabel, strtolower((string)$roomData['room_type'])]);
                            header('Location: room.php?ok=bed_added');
                            exit();
                        }
                    }
                }
            }
        }

        if ($action === 'rename_room') {
            $roomId = (int)($_POST['room_id'] ?? 0);
            $newRoomName = trim($_POST['new_room_name'] ?? '');

            if ($roomId <= 0 || $newRoomName === '') {
                $roomError = 'A valid room and new room name is required.';
            } else {
                $stmtOld = $pdo->prepare("SELECT room_name FROM {$roomTable} WHERE (id = ? OR room_id = ?) AND TenantID = ?");
                $stmtOld->execute([$roomId, $roomId, $tenant_id]);
                $oldRoomData = $stmtOld->fetch(PDO::FETCH_ASSOC);
                $oldRoomName = $oldRoomData['room_name'] ?? null;

                if ($oldRoomName) {
                    $stmtRenameRoom = $pdo->prepare("UPDATE {$roomTable} SET room_name = ? WHERE (id = ? OR room_id = ?) AND TenantID = ?");
                    $stmtRenameRoom->execute([$newRoomName, $roomId, $roomId, $tenant_id]);

                    header('Location: room.php?ok=room_renamed');
                    exit();
                }
            }
        }

        if ($action === 'rename_bed') {
            $bedId = (int)($_POST['bed_id'] ?? 0);
            $newBedLabel = trim($_POST['new_bed_label'] ?? '');

            if ($bedId <= 0 || $newBedLabel === '') {
                $roomError = 'A valid bed and new bed name is required.';
            } else {
                $stmtBedLock = $pdo->prepare("SELECT patient_id, patient_name FROM {$bedTable} WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtBedLock->execute([$bedId, $tenant_id]);
                $bedLockRow = $stmtBedLock->fetch(PDO::FETCH_ASSOC);
                $hasPatientInBed = !empty(trim((string)($bedLockRow['patient_id'] ?? ''))) || !empty(trim((string)($bedLockRow['patient_name'] ?? '')));

                if (!$hasPatientInBed) {
                    $stmtActiveAdmission = $pdo->prepare("SELECT id FROM admissions WHERE TenantID = ? AND assigned_bed_id = ? AND (status <> 'Discharged' AND stage <> 'Discharged') AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1");
                    $stmtActiveAdmission->execute([$tenant_id, $bedId]);
                    $hasPatientInBed = (bool)$stmtActiveAdmission->fetchColumn();
                }

                if ($hasPatientInBed) {
                    $roomError = 'Cannot rename a bed that has an assigned patient.';
                } else {
                $stmtRenameBed = $pdo->prepare("UPDATE {$bedTable} SET bed_label = ? WHERE id = ? AND TenantID = ?");
                $stmtRenameBed->execute([$newBedLabel, $bedId, $tenant_id]);
                header('Location: room.php?ok=bed_renamed');
                exit();
                }
            }
        }

        if ($action === 'delete_bed') {
            $bedId = (int)($_POST['bed_id'] ?? 0);
            if ($bedId <= 0) {
                $roomError = 'Invalid bed selected for deletion.';
            } else {
                $stmtBedLock = $pdo->prepare("SELECT patient_id, patient_name FROM {$bedTable} WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtBedLock->execute([$bedId, $tenant_id]);
                $bedLockRow = $stmtBedLock->fetch(PDO::FETCH_ASSOC);
                $hasPatientInBed = !empty(trim((string)($bedLockRow['patient_id'] ?? ''))) || !empty(trim((string)($bedLockRow['patient_name'] ?? '')));

                if (!$hasPatientInBed) {
                    $stmtActiveAdmission = $pdo->prepare("SELECT id FROM admissions WHERE TenantID = ? AND assigned_bed_id = ? AND (status <> 'Discharged' AND stage <> 'Discharged') AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1");
                    $stmtActiveAdmission->execute([$tenant_id, $bedId]);
                    $hasPatientInBed = (bool)$stmtActiveAdmission->fetchColumn();
                }

                if ($hasPatientInBed) {
                    $roomError = 'Cannot delete a bed that has an assigned patient.';
                } else {
                $stmtDeleteBed = $pdo->prepare("DELETE FROM {$bedTable} WHERE id = ? AND TenantID = ?");
                $stmtDeleteBed->execute([$bedId, $tenant_id]);
                header('Location: room.php?ok=bed_deleted');
                exit();
                }
            }
        }

        if ($action === 'update_bed_status') {
            $bedId = (int)($_POST['bed_id'] ?? 0);
            $bedStatus = strtolower(trim($_POST['bed_status'] ?? ''));
            if ($bedId > 0 && in_array($bedStatus, $statusOptions, true)) {
                $stmtBedLock = $pdo->prepare("SELECT patient_id, patient_name FROM {$bedTable} WHERE id = ? AND TenantID = ? LIMIT 1");
                $stmtBedLock->execute([$bedId, $tenant_id]);
                $bedLockRow = $stmtBedLock->fetch(PDO::FETCH_ASSOC);
                $hasPatientInBed = !empty(trim((string)($bedLockRow['patient_id'] ?? ''))) || !empty(trim((string)($bedLockRow['patient_name'] ?? '')));

                if (!$hasPatientInBed) {
                    $stmtActiveAdmission = $pdo->prepare("SELECT id FROM admissions WHERE TenantID = ? AND assigned_bed_id = ? AND (status <> 'Discharged' AND stage <> 'Discharged') AND (is_archived = 0 OR is_archived IS NULL) LIMIT 1");
                    $stmtActiveAdmission->execute([$tenant_id, $bedId]);
                    $hasPatientInBed = (bool)$stmtActiveAdmission->fetchColumn();
                }

                if ($hasPatientInBed) {
                    $roomError = 'Cannot change the status of a bed with an assigned patient.';
                } elseif ($bedStatus === 'available' || $bedStatus === 'cleaning') {
                    $stmtUpdateStatus = $pdo->prepare("UPDATE {$bedTable} SET bed_status = ?, patient_id = NULL, patient_name = NULL WHERE id = ? AND TenantID = ?");
                } else {
                    $stmtUpdateStatus = $pdo->prepare("UPDATE {$bedTable} SET bed_status = ? WHERE id = ? AND TenantID = ?");
                }
                if (!$hasPatientInBed) {
                    $stmtUpdateStatus->execute([$bedStatus, $bedId, $tenant_id]);
                    header('Location: room.php?ok=status_updated');
                    exit();
                }
            }
            if ($roomError === '') {
                $roomError = 'Invalid bed status update.';
            }
        }

        if ($action === 'clean_all_rooms') {
            $stmtClean = $pdo->prepare("UPDATE {$bedTable} SET bed_status = 'available', patient_id = NULL, patient_name = NULL WHERE TenantID = ? AND bed_status = 'cleaning'");
            $stmtClean->execute([$tenant_id]);
            header('Location: room.php?ok=all_cleaned');
            exit();
        }

        if ($action === 'update_room_default_price') {
            $targetType = strtolower(trim($_POST['room_type'] ?? ''));
            $rawPrice = isset($_POST['default_price']) ? trim((string)$_POST['default_price']) : '0';
            if ($rawPrice === '') { $rawPrice = '0'; }
            if (!in_array($targetType, ['labor_room', 'delivery_room', 'infant_ward'], true)) {
                $roomError = 'Invalid room type for price update.';
            } elseif (!is_numeric($rawPrice) || (float)$rawPrice < 0) {
                $roomError = 'Price must be a valid non-negative number.';
            } else {
                $stmtUpsertDefPrice = $pdo->prepare("INSERT INTO clinic_room_subtype_prices (TenantID, room_type, room_subtype, price) VALUES (?, ?, 'default', ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
                $stmtUpsertDefPrice->execute([$tenant_id, $targetType, (float)$rawPrice]);
                header('Location: room.php?ok=prices_updated');
                exit();
            }
        }

        if ($action === 'update_recovery_prices') {
            $prices = $_POST['recovery_price'] ?? [];
            if (!is_array($prices)) { $prices = []; }
            $stmtUpsertPrice = $pdo->prepare("INSERT INTO clinic_room_subtype_prices (TenantID, room_type, room_subtype, price) VALUES (?, 'recovery_room', ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
            foreach ($recoverySubtypeOptions as $subtypeKey) {
                $rawPrice = isset($prices[$subtypeKey]) ? trim((string)$prices[$subtypeKey]) : '0';
                if ($rawPrice === '') { $rawPrice = '0'; }
                if (!is_numeric($rawPrice) || (float)$rawPrice < 0) {
                    $roomError = 'Prices must be valid non-negative numbers.';
                    break;
                }
                $stmtUpsertPrice->execute([$tenant_id, $subtypeKey, (float)$rawPrice]);
            }
            if ($roomError === '') {
                header('Location: room.php?ok=prices_updated');
                exit();
            }
        }

        if ($action === 'update_labor_prices') {
            $prices = $_POST['labor_price'] ?? [];
            if (!is_array($prices)) { $prices = []; }
            $stmtUpsertPrice = $pdo->prepare("INSERT INTO clinic_room_subtype_prices (TenantID, room_type, room_subtype, price) VALUES (?, 'labor_room', ?, ?) ON DUPLICATE KEY UPDATE price = VALUES(price)");
            foreach ($recoverySubtypeOptions as $subtypeKey) {
                $rawPrice = isset($prices[$subtypeKey]) ? trim((string)$prices[$subtypeKey]) : '0';
                if ($rawPrice === '') { $rawPrice = '0'; }
                if (!is_numeric($rawPrice) || (float)$rawPrice < 0) {
                    $roomError = 'Prices must be valid non-negative numbers.';
                    break;
                }
                $stmtUpsertPrice->execute([$tenant_id, $subtypeKey, (float)$rawPrice]);
            }
            if ($roomError === '') {
                header('Location: room.php?ok=prices_updated');
                exit();
            }
        }

    } catch (PDOException $e) {
        $roomError = 'Failed to save room data. Please try again.';
    }
}

if (isset($_GET['ok'])) {
    if ($_GET['ok'] === 'room_added') $roomSuccess = 'Room added successfully.';
    if ($_GET['ok'] === 'bed_added') $roomSuccess = 'Bed added successfully.';
    if ($_GET['ok'] === 'room_renamed') $roomSuccess = 'Room renamed successfully.';
    if ($_GET['ok'] === 'bed_renamed') $roomSuccess = 'Bed renamed successfully.';
    if ($_GET['ok'] === 'bed_deleted') $roomSuccess = 'Bed deleted successfully.';
    if ($_GET['ok'] === 'status_updated') $roomSuccess = 'Bed status updated.';
    if ($_GET['ok'] === 'all_cleaned') $roomSuccess = 'All cleaning beds have been set to Available.';
    if ($_GET['ok'] === 'prices_updated') $roomSuccess = 'Recovery room prices updated.';
}

$cleaningBedCount = 0;
$rooms = [];
$deliveryRooms = [];
$laborRooms = [];
$recoveryRooms = [];
$infantWardRooms = [];
$recoveryPrices = ['regular' => 0.00, 'private' => 0.00, 'large_private' => 0.00];
$laborPrices = ['regular' => 0.00, 'private' => 0.00, 'large_private' => 0.00];
$defaultRoomPrices = ['labor_room' => 0.00, 'delivery_room' => 0.00, 'infant_ward' => 0.00];
if ($schemaReady) {
    try {
        $stmtPrices = $pdo->prepare("SELECT room_subtype, price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'recovery_room'");
        $stmtPrices->execute([$tenant_id]);
        foreach ($stmtPrices->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $key = strtolower((string)($pr['room_subtype'] ?? ''));
            if (isset($recoveryPrices[$key])) {
                $recoveryPrices[$key] = (float)($pr['price'] ?? 0);
            }
        }
    } catch (PDOException $e) {}

    try {
        $stmtLaborPrices = $pdo->prepare("SELECT room_subtype, price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_type = 'labor_room'");
        $stmtLaborPrices->execute([$tenant_id]);
        foreach ($stmtLaborPrices->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $key = strtolower((string)($pr['room_subtype'] ?? ''));
            if (isset($laborPrices[$key])) {
                $laborPrices[$key] = (float)($pr['price'] ?? 0);
            }
        }
    } catch (PDOException $e) {}

    try {
        $stmtDefPrices = $pdo->prepare("SELECT room_type, price FROM clinic_room_subtype_prices WHERE TenantID = ? AND room_subtype = 'default'");
        $stmtDefPrices->execute([$tenant_id]);
        foreach ($stmtDefPrices->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $rk = strtolower((string)($pr['room_type'] ?? ''));
            if (isset($defaultRoomPrices[$rk])) {
                $defaultRoomPrices[$rk] = (float)($pr['price'] ?? 0);
            }
        }
    } catch (PDOException $e) {}
    try {
        // Keep room status in DB aligned with real-time bed availability.
        $stmtSyncRoomStatusSql = $pdo->prepare("UPDATE {$roomTable} r LEFT JOIN ( SELECT b.room_id, COUNT(*) AS total_beds, SUM(CASE WHEN LOWER(COALESCE(b.bed_status, 'available')) = 'available' AND a.id IS NULL THEN 1 ELSE 0 END) AS available_beds, SUM(CASE WHEN LOWER(COALESCE(b.bed_status, 'available')) = 'cleaning' THEN 1 ELSE 0 END) AS cleaning_beds FROM {$bedTable} b LEFT JOIN admissions a ON a.TenantID = b.TenantID AND a.assigned_bed_id = b.id AND (a.status <> 'Discharged' AND a.stage <> 'Discharged') AND (a.is_archived = 0 OR a.is_archived IS NULL) WHERE b.TenantID = ? GROUP BY b.room_id ) s ON s.room_id = COALESCE(NULLIF(r.room_id, 0), r.id) SET r.status = CASE WHEN COALESCE(s.total_beds, 0) = 0 THEN 'available' WHEN COALESCE(s.available_beds, 0) = 0 THEN 'occupied' WHEN COALESCE(s.cleaning_beds, 0) > 0 THEN 'cleaning' ELSE 'available' END WHERE r.TenantID = ?");
        $stmtSyncRoomStatusSql->execute([$tenant_id, $tenant_id]);

        $stmtRooms = $pdo->prepare("SELECT COALESCE(NULLIF(room_id, 0), id) AS id, room_name, room_type, room_subtype, created_at FROM {$roomTable} WHERE TenantID = ? ORDER BY created_at ASC, COALESCE(NULLIF(room_id, 0), id) ASC");
        $stmtRooms->execute([$tenant_id]);
        $roomRows = $stmtRooms->fetchAll(PDO::FETCH_ASSOC);

        $stmtBeds = $pdo->prepare("SELECT id, room_id, bed_label, bed_status, patient_id, patient_name, room_type FROM {$bedTable} WHERE TenantID = ? ORDER BY room_id ASC, id ASC");
        $stmtBeds->execute([$tenant_id]);
        $bedRows = $stmtBeds->fetchAll(PDO::FETCH_ASSOC);

        $stmtCleanCount = $pdo->prepare("SELECT COUNT(*) FROM {$bedTable} WHERE TenantID = ? AND bed_status = 'cleaning'");
        $stmtCleanCount->execute([$tenant_id]);
        $cleaningBedCount = (int)$stmtCleanCount->fetchColumn();

        $stmtActiveBedPatients = $pdo->prepare("SELECT assigned_bed_id, patient_id, full_name, admission_date FROM admissions WHERE TenantID = ? AND assigned_bed_id IS NOT NULL AND assigned_bed_id > 0 AND (status <> 'Discharged' AND stage <> 'Discharged') AND (is_archived = 0 OR is_archived IS NULL)");
        $stmtActiveBedPatients->execute([$tenant_id]);
        $activePatientByBed = [];
        foreach ($stmtActiveBedPatients->fetchAll(PDO::FETCH_ASSOC) as $activeRow) {
            $activeBedId = (int)($activeRow['assigned_bed_id'] ?? 0);
            if ($activeBedId <= 0 || isset($activePatientByBed[$activeBedId])) {
                continue;
            }

            $admissionDateRaw = trim((string)($activeRow['admission_date'] ?? ''));
            $admissionDateDisplay = '';
            if ($admissionDateRaw !== '') {
                try {
                    $admissionDateDisplay = (new DateTime($admissionDateRaw))->format('M d, Y h:i A');
                } catch (Exception $e) {
                    $admissionDateDisplay = $admissionDateRaw;
                }
            }

            $activePatientByBed[$activeBedId] = [
                'patient_id' => trim((string)($activeRow['patient_id'] ?? '')),
                'patient_name' => trim((string)($activeRow['full_name'] ?? '')),
                'admission_datetime' => $admissionDateDisplay
            ];
        }

        $stmtRoomingInBabies = $pdo->prepare("SELECT i.linked_bed_id, i.infant_name FROM infants i INNER JOIN admissions a ON a.id = i.admission_id AND a.TenantID = i.TenantID WHERE i.TenantID = ? AND LOWER(TRIM(COALESCE(i.location_option, ''))) = 'rooming_in' AND i.linked_bed_id IS NOT NULL AND i.linked_bed_id > 0 AND COALESCE(TRIM(i.infant_name), '') <> '' AND (a.status <> 'Discharged' AND a.stage <> 'Discharged') AND (a.is_archived = 0 OR a.is_archived IS NULL) ORDER BY i.id ASC");
        $stmtRoomingInBabies->execute([$tenant_id]);
        $roomingInBabiesByBed = [];
        foreach ($stmtRoomingInBabies->fetchAll(PDO::FETCH_ASSOC) as $babyRow) {
            $bedId = (int)($babyRow['linked_bed_id'] ?? 0);
            $babyName = trim((string)($babyRow['infant_name'] ?? ''));
            if ($bedId <= 0 || $babyName === '') {
                continue;
            }
            if (!isset($roomingInBabiesByBed[$bedId])) {
                $roomingInBabiesByBed[$bedId] = [];
            }
            if (!in_array($babyName, $roomingInBabiesByBed[$bedId], true)) {
                $roomingInBabiesByBed[$bedId][] = $babyName;
            }
        }

        $bedsByRoom = [];
        foreach ($bedRows as $bedRow) {
            $rid = (int)($bedRow['room_id'] ?? 0);
            if ($rid <= 0) {
                continue;
            }
            if (!isset($bedsByRoom[$rid])) {
                $bedsByRoom[$rid] = [];
            }
            $bedId = (int)($bedRow['id'] ?? 0);
            $bedStatusValue = (string)($bedRow['bed_status'] ?? 'available');
            $patientIdValue = $bedRow['patient_id'] ?? null;
            $patientNameValue = $bedRow['patient_name'] ?? null;
            $admissionDateValue = null;
            $babyNamesValue = [];

            if (isset($activePatientByBed[$bedId])) {
                $bedStatusValue = 'occupied';
                $patientIdValue = $activePatientByBed[$bedId]['patient_id'];
                $patientNameValue = $activePatientByBed[$bedId]['patient_name'];
                $admissionDateValue = $activePatientByBed[$bedId]['admission_datetime'];
            }

            if (isset($roomingInBabiesByBed[$bedId])) {
                $babyNamesValue = $roomingInBabiesByBed[$bedId];
            }

            $bedsByRoom[$rid][] = [
                'id' => (int)($bedRow['id'] ?? 0),
                'bed_label' => (string)($bedRow['bed_label'] ?? ''),
                'bed_status' => $bedStatusValue,
                'room_type' => (string)($bedRow['room_type'] ?? ''),
                'patient_id' => $patientIdValue,
                'patient_name' => $patientNameValue,
                'admission_datetime' => $admissionDateValue,
                'baby_names' => $babyNamesValue
            ];
        }

        $groupedRooms = [];

        foreach ($roomRows as $row) {
            $roomId = (int)($row['id'] ?? 0);
            if ($roomId <= 0) {
                continue;
            }
            $rName = (string)($row['room_name'] ?? '');
            $roomTypeValue = in_array(strtolower((string)($row['room_type'] ?? 'delivery_room')), $roomTypeOptions, true)
                ? strtolower((string)$row['room_type'])
                : 'delivery_room';

            $groupedRooms[$roomId] = [
                'id' => $roomId,
                'name' => $rName,
                'room_type' => $roomTypeValue,
                'room_subtype' => strtolower((string)($row['room_subtype'] ?? '')),
                'status' => 'available',
                'beds' => $bedsByRoom[$roomId] ?? []
            ];
        }

        foreach ($groupedRooms as &$r) {
            if (!empty($r['beds'])) {
                $hasOccupied = false;
                $hasCleaning = false;
                $hasAvailable = false;
                foreach ($r['beds'] as $b) {
                    $s = strtolower($b['bed_status'] ?? 'available');
                    if ($s === 'occupied') $hasOccupied = true;
                    if ($s === 'cleaning') $hasCleaning = true;
                    if ($s === 'available') $hasAvailable = true;
                }
                if (!$hasAvailable) {
                    $r['status'] = 'occupied';
                } elseif ($hasOccupied) {
                    $r['status'] = 'occupied';
                } elseif ($hasCleaning) {
                    $r['status'] = 'cleaning';
                }
            }
        }
        unset($r);

        try {
            $stmtSyncRoomStatus = $pdo->prepare("UPDATE {$roomTable} SET status = ? WHERE (id = ? OR room_id = ?) AND TenantID = ?");
            foreach ($groupedRooms as $syncRoom) {
                $stmtSyncRoomStatus->execute([(string)($syncRoom['status'] ?? 'available'), (int)$syncRoom['id'], (int)$syncRoom['id'], $tenant_id]);
            }
        } catch (PDOException $e) {
            // Non-blocking: UI already has computed status.
        }

        $rooms = array_values($groupedRooms);

        foreach ($rooms as $roomItem) {
            if ($roomItem['room_type'] === 'infant_ward') {
                $infantWardRooms[] = $roomItem;
            } elseif ($roomItem['room_type'] === 'labor_room') {
                $laborRooms[] = $roomItem;
            } elseif ($roomItem['room_type'] === 'recovery_room') {
                $recoveryRooms[] = $roomItem;
            } else {
                $deliveryRooms[] = $roomItem;
            }
        }
    } catch (PDOException $e) {
        $roomError = 'Unable to load rooms. Please try again.';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Rooms - <?= htmlspecialchars($clinicName) ?></title>
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
    <p class="font-bold text-slate-800 animate-pulse text-xs">Logging out safely...</p>
</div>

<div id="cleanAllModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm">
    <div class="bg-white rounded-[2rem] p-6 max-w-xs w-full shadow-2xl text-center border border-slate-100">
        <div class="size-12 rounded-2xl bg-yellow-50 text-yellow-500 flex items-center justify-center mx-auto mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
            </svg>
        </div>
        <h3 class="text-base font-black text-slate-900 mb-1">Clean All Rooms?</h3>
        <p class="text-slate-500 text-[11px] mb-6">All <strong class="text-yellow-600"><?= $cleaningBedCount ?> cleaning bed<?= $cleaningBedCount > 1 ? 's' : '' ?></strong> will be set to Available. Occupied beds will not be affected.</p>
        <div class="flex gap-2">
            <button type="button" onclick="closeCleanAllModal()" class="flex-1 py-2 rounded-xl font-bold text-slate-400 hover:bg-slate-100 text-[11px]">Cancel</button>
            <form method="POST" class="flex-1">
                <input type="hidden" name="action" value="clean_all_rooms">
                <button type="submit" class="w-full py-2 rounded-xl font-bold bg-yellow-500 text-white hover:bg-yellow-600 text-[11px] shadow-lg shadow-yellow-100">Clean All</button>
            </form>
        </div>
    </div>
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

            <a href="dashboard.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">dashboard</span> <span>Dashboard</span>
            </a>
            <a href="appointments.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">calendar_today</span> <span>Appointments</span>
            </a>
            <a href="admissions.php" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] text-slate-500 hover:bg-slate-50 font-bold transition-all">
                <span class="material-symbols-outlined text-2xl">how_to_reg</span> <span>Admissions</span>
            </a>

            <a href="room.php" onclick="event.preventDefault(); return false;" aria-current="page" class="flex items-center gap-5 px-6 py-4 rounded-[2rem] <?= $sidebarActive ?> font-bold shadow-sm transition-all hover:scale-[1.02]">
                <span class="material-symbols-outlined text-2xl icon-filled">bed</span> <span>Rooms</span>
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
        <div class="max-w-7xl mx-auto">
            <?php if ($roomSuccess): ?>
                <div class="mb-4 rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-800 px-4 py-3 text-sm font-bold shadow-sm">
                    <?= htmlspecialchars($roomSuccess) ?>
                </div>
            <?php endif; ?>
            <?php if ($roomError): ?>
                <div class="mb-4 rounded-2xl border border-rose-200 bg-rose-50 text-rose-800 px-4 py-3 text-sm font-bold shadow-sm">
                    <?= htmlspecialchars($roomError) ?>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-[2rem] border border-slate-200 p-6 shadow-sm mb-6">
                <div class="flex flex-col gap-4">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                        <button type="button" class="room-category-btn rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700" data-room-category="labor_room">Labor Rooms</button>
                        <button type="button" class="room-category-btn rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700" data-room-category="delivery_room">Delivery Rooms</button>
                        <button type="button" class="room-category-btn rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700" data-room-category="recovery_room">Recovery Rooms</button>
                        <button type="button" class="room-category-btn rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-black text-slate-700" data-room-category="infant_ward">Infant Wards</button>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 text-[10px] font-bold text-slate-600 border-t border-slate-100 pt-3">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="text-slate-400 uppercase tracking-widest">Clinic Room Status</span>
                            <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-green-500 mr-1.5"></span>Available</span>
                            <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-red-500 mr-1.5"></span>Occupied</span>
                            <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-yellow-500 mr-1.5"></span>Cleaning</span>
                        </div>
                    </div>

                    <?php if ($cleaningBedCount > 0): ?>
                    <div class="border-t border-slate-100 pt-3">
                        <button type="button" onclick="openCleanAllModal()" class="group relative rounded-xl bg-gradient-to-b from-yellow-400 to-yellow-500 hover:from-yellow-500 hover:to-yellow-600 text-white pl-3.5 pr-4 py-2 text-[11px] font-black transition-all shadow-md hover:shadow-lg flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform group-hover:rotate-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/>
                            </svg>
                            Clean All Rooms
                        </button>
                    </div>
                    <?php endif; ?>

                    <div id="categoryPanelLabor" class="room-category-panel hidden" data-room-panel="labor_room">
                        <form method="POST" class="flex flex-col sm:flex-row gap-2 flex-wrap">
                            <input type="hidden" name="action" value="add_room">
                            <input type="hidden" name="room_type" value="labor_room">
                            <input type="text" name="room_name" required maxlength="150" placeholder="e.g. Labor Room A" class="w-full sm:w-72 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium focus:border-primary focus:ring-primary">
                            <select name="room_subtype" required class="w-full sm:w-56 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium focus:border-primary focus:ring-primary">
                                <option value="">-- Select Labor Type --</option>
                                <?php foreach ($recoverySubtypeOptions as $stOpt): ?>
                                    <option value="<?= $stOpt ?>"><?= htmlspecialchars($recoverySubtypeLabels[$stOpt]) ?> (₱<?= number_format($laborPrices[$stOpt], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="rounded-lg bg-primary hover:bg-primary-dark text-white px-4 py-2 text-xs font-bold transition-all shadow-sm">Add Labor Room</button>
                        </form>

                        <div class="mt-4 rounded-2xl border border-purple-200 bg-purple-50/60 p-4">
                            <form method="POST" class="flex flex-col gap-3">
                                <input type="hidden" name="action" value="update_labor_prices">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-purple-600">payments</span>
                                    <h4 class="text-sm font-black text-purple-800 tracking-tight">Labor Room Prices (per stay)</h4>
                                </div>
                                <p class="text-[11px] text-purple-700">Set the price for each Labor Room type. It will be automatically added to the patient's bill when assigned in Admissions.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <?php foreach ($recoverySubtypeOptions as $stOpt): ?>
                                    <label class="flex flex-col gap-1 bg-white rounded-xl border border-purple-200 p-3 shadow-sm">
                                        <span class="text-[10px] font-black uppercase tracking-widest text-purple-700"><?= htmlspecialchars($recoverySubtypeLabels[$stOpt]) ?></span>
                                        <div class="flex items-center gap-1">
                                            <span class="text-sm font-black text-slate-500">₱</span>
                                            <input type="number" min="0" step="0.01" name="labor_price[<?= $stOpt ?>]" value="<?= htmlspecialchars(number_format($laborPrices[$stOpt], 2, '.', '')) ?>" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-bold text-slate-700 focus:border-primary focus:ring-primary">
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div>
                                    <button type="submit" class="rounded-lg bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 text-xs font-black transition-all shadow-sm">Save Prices</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="categoryPanelDelivery" class="room-category-panel hidden" data-room-panel="delivery_room">
                        <form method="POST" class="flex flex-col sm:flex-row gap-2">
                            <input type="hidden" name="action" value="add_room">
                            <input type="hidden" name="room_type" value="delivery_room">
                            <input type="text" name="room_name" required maxlength="150" placeholder="e.g. Delivery Room A" class="w-full sm:w-80 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium focus:border-primary focus:ring-primary">
                            <button type="submit" class="rounded-lg bg-primary hover:bg-primary-dark text-white px-4 py-2 text-xs font-bold transition-all shadow-sm">Add Delivery Room</button>
                        </form>

                        <div class="mt-4 rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4">
                            <form method="POST" class="flex flex-col sm:flex-row sm:items-end gap-3">
                                <input type="hidden" name="action" value="update_room_default_price">
                                <input type="hidden" name="room_type" value="delivery_room">
                                <div class="flex flex-col gap-1 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-emerald-600">payments</span>
                                        <h4 class="text-sm font-black text-emerald-800 tracking-tight">Delivery Room Price (per stay)</h4>
                                    </div>
                                    <p class="text-[11px] text-emerald-700">It will be automatically added to the patient's bill when assigned to a Delivery Room.</p>
                                    <div class="flex items-center gap-1 mt-1">
                                        <span class="text-sm font-black text-slate-500">₱</span>
                                        <input type="number" min="0" step="0.01" name="default_price" value="<?= htmlspecialchars(number_format($defaultRoomPrices['delivery_room'], 2, '.', '')) ?>" class="w-full sm:w-48 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-bold text-slate-700 focus:border-primary focus:ring-primary">
                                    </div>
                                </div>
                                <button type="submit" class="rounded-lg bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 text-xs font-black transition-all shadow-sm">Save Price</button>
                            </form>
                        </div>
                    </div>

                    <div id="categoryPanelRecovery" class="room-category-panel hidden" data-room-panel="recovery_room">
                        <form method="POST" class="flex flex-col sm:flex-row gap-2 flex-wrap">
                            <input type="hidden" name="action" value="add_room">
                            <input type="hidden" name="room_type" value="recovery_room">
                            <input type="text" name="room_name" required maxlength="150" placeholder="e.g. Recovery Room A" class="w-full sm:w-72 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium focus:border-primary focus:ring-primary">
                            <select name="room_subtype" required class="w-full sm:w-56 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium focus:border-primary focus:ring-primary">
                                <option value="">-- Select Recovery Type --</option>
                                <?php foreach ($recoverySubtypeOptions as $stOpt): ?>
                                    <option value="<?= $stOpt ?>"><?= htmlspecialchars($recoverySubtypeLabels[$stOpt]) ?> (₱<?= number_format($recoveryPrices[$stOpt], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="rounded-lg bg-primary hover:bg-primary-dark text-white px-4 py-2 text-xs font-bold transition-all shadow-sm">Add Recovery Room</button>
                        </form>

                        <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50/60 p-4">
                            <form method="POST" class="flex flex-col gap-3">
                                <input type="hidden" name="action" value="update_recovery_prices">
                                <div class="flex items-center gap-2">
                                    <span class="material-symbols-outlined text-amber-600">payments</span>
                                    <h4 class="text-sm font-black text-amber-800 tracking-tight">Recovery Room Prices (per stay)</h4>
                                </div>
                                <p class="text-[11px] text-amber-700">Set the price for each Recovery Room type. It will be automatically added to the patient's bill when assigned in Admissions.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                                    <?php foreach ($recoverySubtypeOptions as $stOpt): ?>
                                    <label class="flex flex-col gap-1 bg-white rounded-xl border border-amber-200 p-3 shadow-sm">
                                        <span class="text-[10px] font-black uppercase tracking-widest text-amber-700"><?= htmlspecialchars($recoverySubtypeLabels[$stOpt]) ?></span>
                                        <div class="flex items-center gap-1">
                                            <span class="text-sm font-black text-slate-500">₱</span>
                                            <input type="number" min="0" step="0.01" name="recovery_price[<?= $stOpt ?>]" value="<?= htmlspecialchars(number_format($recoveryPrices[$stOpt], 2, '.', '')) ?>" class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-bold text-slate-700 focus:border-primary focus:ring-primary">
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div>
                                    <button type="submit" class="rounded-lg bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 text-xs font-black transition-all shadow-sm">Save Prices</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div id="categoryPanelInfant" class="room-category-panel hidden" data-room-panel="infant_ward">
                        <form method="POST" class="flex flex-col sm:flex-row gap-2">
                            <input type="hidden" name="action" value="add_room">
                            <input type="hidden" name="room_type" value="infant_ward">
                            <input type="text" name="room_name" required maxlength="150" placeholder="e.g. Infant Ward A" class="w-full sm:w-80 rounded-xl border border-slate-300 px-3 py-2 text-sm font-medium focus:border-primary focus:ring-primary">
                            <button type="submit" class="rounded-lg bg-primary hover:bg-primary-dark text-white px-4 py-2 text-xs font-bold transition-all shadow-sm">Add Infant Ward</button>
                        </form>

                        <div class="mt-4 rounded-2xl border border-cyan-200 bg-cyan-50/60 p-4">
                            <form method="POST" class="flex flex-col sm:flex-row sm:items-end gap-3">
                                <input type="hidden" name="action" value="update_room_default_price">
                                <input type="hidden" name="room_type" value="infant_ward">
                                <div class="flex flex-col gap-1 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="material-symbols-outlined text-cyan-600">payments</span>
                                        <h4 class="text-sm font-black text-cyan-800 tracking-tight">Infant Ward Price (per stay)</h4>
                                    </div>
                                    <p class="text-[11px] text-cyan-700">It will be automatically added to the mother's bill when the baby is assigned to the Infant Ward.</p>
                                    <div class="flex items-center gap-1 mt-1">
                                        <span class="text-sm font-black text-slate-500">₱</span>
                                        <input type="number" min="0" step="0.01" name="default_price" value="<?= htmlspecialchars(number_format($defaultRoomPrices['infant_ward'], 2, '.', '')) ?>" class="w-full sm:w-48 rounded-lg border border-slate-200 px-2 py-1.5 text-sm font-bold text-slate-700 focus:border-primary focus:ring-primary">
                                    </div>
                                </div>
                                <button type="submit" class="rounded-lg bg-cyan-500 hover:bg-cyan-600 text-white px-4 py-2 text-xs font-black transition-all shadow-sm">Save Price</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="space-y-8">
                <section class="room-category-panel hidden" data-room-panel="delivery_room">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-emerald-600">pregnant_woman</span>
                        <h3 class="text-lg font-black text-slate-800 tracking-tight">Delivery Rooms</h3>
                    </div>
                    <?php if (empty($deliveryRooms)): ?>
                    <div class="rounded-2xl border border-dashed border-emerald-200 bg-emerald-50/40 p-6 text-center text-xs font-bold text-emerald-700">
                        No rooms yet in the Delivery Rooms category.
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($deliveryRooms as $room): $config = getRoomDisplayConfig($room['beds']); $typeLogo = getRoomTypeLogoConfig($room['room_type']); ?>
                        <button type="button" onclick="openRoomModal(<?= (int)$room['id'] ?>)" class="text-left bg-white border-l-4 rounded-2xl shadow-sm p-5 hover:shadow-md hover:scale-[1.02] transition-all <?= $config['color'] ?>">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center justify-center size-8 rounded-xl <?= $typeLogo['bg'] ?> <?= $typeLogo['fg'] ?>">
                                    <span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($typeLogo['icon']) ?></span>
                                </span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500"><?= htmlspecialchars($typeLogo['label']) ?></span>
                            </div>
                            <div class="flex justify-between items-start mb-3 gap-2">
                                <h4 class="text-lg font-black text-slate-800 leading-tight"><?= htmlspecialchars($room['name']) ?></h4>
                                <span class="text-xl" title="<?= $config['label'] ?>"><?= $config['icon'] ?></span>
                            </div>
                            <div class="mb-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-800 border border-emerald-200">Delivery Room</span>
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-emerald-50 text-emerald-700 border border-emerald-200 ml-1">₱<?= number_format($defaultRoomPrices['delivery_room'] ?? 0, 2) ?></span>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-0.5">Status</p>
                                <p class="font-black text-sm"><?= $config['label'] ?></p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <p class="text-[10px] font-bold text-slate-500">Beds inside: <?= count($room['beds']) ?></p>
                                <span class="material-symbols-outlined text-slate-300 text-sm">open_in_new</span>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="room-category-panel hidden" data-room-panel="labor_room">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-purple-600">local_hospital</span>
                        <h3 class="text-lg font-black text-slate-800 tracking-tight">Labor Rooms</h3>
                    </div>
                    <?php if (empty($laborRooms)): ?>
                    <div class="rounded-2xl border border-dashed border-purple-200 bg-purple-50/40 p-6 text-center text-xs font-bold text-purple-700">
                        No rooms yet in the Labor Rooms category.
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($laborRooms as $room): $config = getRoomDisplayConfig($room['beds']); $typeLogo = getRoomTypeLogoConfig($room['room_type']); ?>
                        <button type="button" onclick="openRoomModal(<?= (int)$room['id'] ?>)" class="text-left bg-white border-l-4 rounded-2xl shadow-sm p-5 hover:shadow-md hover:scale-[1.02] transition-all <?= $config['color'] ?>">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center justify-center size-8 rounded-xl <?= $typeLogo['bg'] ?> <?= $typeLogo['fg'] ?>">
                                    <span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($typeLogo['icon']) ?></span>
                                </span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500"><?= htmlspecialchars($typeLogo['label']) ?></span>
                            </div>
                            <div class="flex justify-between items-start mb-3 gap-2">
                                <h4 class="text-lg font-black text-slate-800 leading-tight"><?= htmlspecialchars($room['name']) ?></h4>
                                <span class="text-xl" title="<?= $config['label'] ?>"><?= $config['icon'] ?></span>
                            </div>
                            <div class="mb-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-purple-100 text-purple-800 border border-purple-200">Labor Room</span>
                                <?php $lsKey = strtolower((string)($room['room_subtype'] ?? '')); if (isset($recoverySubtypeLabels[$lsKey])): ?>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-violet-100 text-violet-800 border border-violet-200 ml-1"><?= htmlspecialchars($recoverySubtypeLabels[$lsKey]) ?></span>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-purple-50 text-purple-700 border border-purple-200 ml-1">₱<?= number_format($laborPrices[$lsKey] ?? 0, 2) ?></span>
                                <?php else: ?>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-purple-50 text-purple-700 border border-purple-200 ml-1">₱<?= number_format($defaultRoomPrices['labor_room'] ?? 0, 2) ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-0.5">Status</p>
                                <p class="font-black text-sm"><?= $config['label'] ?></p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <p class="text-[10px] font-bold text-slate-500">Beds inside: <?= count($room['beds']) ?></p>
                                <span class="material-symbols-outlined text-slate-300 text-sm">open_in_new</span>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="room-category-panel hidden" data-room-panel="recovery_room">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-amber-600">hotel</span>
                        <h3 class="text-lg font-black text-slate-800 tracking-tight">Recovery Rooms</h3>
                    </div>
                    <?php if (empty($recoveryRooms)): ?>
                    <div class="rounded-2xl border border-dashed border-amber-200 bg-amber-50/40 p-6 text-center text-xs font-bold text-amber-700">
                        No rooms yet in the Recovery Rooms category.
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($recoveryRooms as $room): $config = getRoomDisplayConfig($room['beds']); $typeLogo = getRoomTypeLogoConfig($room['room_type']); ?>
                        <button type="button" onclick="openRoomModal(<?= (int)$room['id'] ?>)" class="text-left bg-white border-l-4 rounded-2xl shadow-sm p-5 hover:shadow-md hover:scale-[1.02] transition-all <?= $config['color'] ?>">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center justify-center size-8 rounded-xl <?= $typeLogo['bg'] ?> <?= $typeLogo['fg'] ?>">
                                    <span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($typeLogo['icon']) ?></span>
                                </span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500"><?= htmlspecialchars($typeLogo['label']) ?></span>
                            </div>
                            <div class="flex justify-between items-start mb-3 gap-2">
                                <h4 class="text-lg font-black text-slate-800 leading-tight"><?= htmlspecialchars($room['name']) ?></h4>
                                <span class="text-xl" title="<?= $config['label'] ?>"><?= $config['icon'] ?></span>
                            </div>
                            <div class="mb-2">
                                <?php $rsKey = strtolower((string)($room['room_subtype'] ?? '')); if (isset($recoverySubtypeLabels[$rsKey])): ?>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-violet-100 text-violet-800 border border-violet-200"><?= htmlspecialchars($recoverySubtypeLabels[$rsKey]) ?></span>
                                    <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-emerald-100 text-emerald-800 border border-emerald-200 ml-1">₱<?= number_format($recoveryPrices[$rsKey] ?? 0, 2) ?></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-0.5">Status</p>
                                <p class="font-black text-sm"><?= $config['label'] ?></p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <p class="text-[10px] font-bold text-slate-500">Beds inside: <?= count($room['beds']) ?></p>
                                <span class="material-symbols-outlined text-slate-300 text-sm">open_in_new</span>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>

                <section class="room-category-panel hidden" data-room-panel="infant_ward">
                    <div class="flex items-center gap-2 mb-3">
                        <span class="material-symbols-outlined text-cyan-600">child_friendly</span>
                        <h3 class="text-lg font-black text-slate-800 tracking-tight">Infant Ward</h3>
                    </div>
                    <?php if (empty($infantWardRooms)): ?>
                    <div class="rounded-2xl border border-dashed border-cyan-200 bg-cyan-50/40 p-6 text-center text-xs font-bold text-cyan-700">
                        No rooms yet in the Infant Wards category.
                    </div>
                    <?php else: ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                        <?php foreach ($infantWardRooms as $room): $config = getRoomDisplayConfig($room['beds']); $typeLogo = getRoomTypeLogoConfig($room['room_type']); ?>
                        <button type="button" onclick="openRoomModal(<?= (int)$room['id'] ?>)" class="text-left bg-white border-l-4 rounded-2xl shadow-sm p-5 hover:shadow-md hover:scale-[1.02] transition-all <?= $config['color'] ?>">
                            <div class="flex items-center gap-2 mb-3">
                                <span class="inline-flex items-center justify-center size-8 rounded-xl <?= $typeLogo['bg'] ?> <?= $typeLogo['fg'] ?>">
                                    <span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($typeLogo['icon']) ?></span>
                                </span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500"><?= htmlspecialchars($typeLogo['label']) ?></span>
                            </div>
                            <div class="flex justify-between items-start mb-3 gap-2">
                                <h4 class="text-lg font-black text-slate-800 leading-tight"><?= htmlspecialchars($room['name']) ?></h4>
                                <span class="text-xl" title="<?= $config['label'] ?>"><?= $config['icon'] ?></span>
                            </div>
                            <div class="mb-2">
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-cyan-100 text-cyan-800 border border-cyan-200">Infant Ward</span>
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-widest bg-cyan-50 text-cyan-700 border border-cyan-200 ml-1">₱<?= number_format($defaultRoomPrices['infant_ward'] ?? 0, 2) ?></span>
                            </div>
                            <div>
                                <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-0.5">Status</p>
                                <p class="font-black text-sm"><?= $config['label'] ?></p>
                            </div>
                            <div class="mt-4 pt-3 border-t border-slate-100 flex items-center justify-between">
                                <p class="text-[10px] font-bold text-slate-500">Beds inside: <?= count($room['beds']) ?></p>
                                <span class="material-symbols-outlined text-slate-300 text-sm">open_in_new</span>
                            </div>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </section>
            </div>

            <?php foreach ($rooms as $room): ?>
            <div id="roomModal<?= (int)$room['id'] ?>" class="fixed inset-0 z-[120] hidden items-center justify-center p-4 bg-slate-900/40 backdrop-blur-sm transition-opacity">
                <div class="bg-white w-full max-w-2xl rounded-[2rem] shadow-2xl border border-slate-100 max-h-[90vh] flex flex-col">

                    <div class="p-6 border-b border-slate-100 flex items-center justify-between shrink-0">
                        <div>
                            <?php $modalLogo = getRoomTypeLogoConfig($room['room_type']); ?>
                            <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1">Manage Room</p>
                            <div class="flex items-center gap-2 mb-2">
                                <span class="inline-flex items-center justify-center size-8 rounded-xl <?= $modalLogo['bg'] ?> <?= $modalLogo['fg'] ?>">
                                    <span class="material-symbols-outlined text-[18px]"><?= htmlspecialchars($modalLogo['icon']) ?></span>
                                </span>
                                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500"><?= htmlspecialchars($modalLogo['label']) ?></span>
                            </div>
                            <h3 class="text-2xl font-black text-slate-800 tracking-tight leading-none"><?= htmlspecialchars($room['name']) ?></h3>
                        </div>
                        <button type="button" onclick="closeRoomModal(<?= (int)$room['id'] ?>)" class="size-10 rounded-full hover:bg-slate-100 text-slate-400 hover:text-red-500 transition-colors inline-flex items-center justify-center">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>

                    <div class="p-6 overflow-y-auto flex-1 bg-slate-50/50">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                            <form method="POST" class="flex flex-col gap-2">
                                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Rename Room</label>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <input type="hidden" name="action" value="rename_room">
                                    <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
                                    <input type="text" name="new_room_name" required maxlength="150" value="<?= htmlspecialchars($room['name']) ?>" class="flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold text-slate-700 shadow-sm">
                                    <button type="submit" class="rounded-xl bg-slate-800 hover:bg-slate-900 text-white px-4 py-2 text-xs font-bold transition-colors">Save</button>
                                </div>
                            </form>

                            <?php $isDeliveryRoom = ($room['room_type'] === 'delivery_room'); ?>
                            <?php $isSingleBedRecovery = ($room['room_type'] === 'recovery_room' && in_array(strtolower((string)($room['room_subtype'] ?? '')), ['private', 'large_private'], true)); ?>
                            <?php $isSingleBedLabor = ($room['room_type'] === 'labor_room' && in_array(strtolower((string)($room['room_subtype'] ?? '')), ['private', 'large_private'], true)); ?>
                            <?php if (!$isDeliveryRoom && !$isSingleBedRecovery && !$isSingleBedLabor): ?>
                            <form method="POST" class="flex flex-col gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm">
                                <div class="flex items-center justify-between gap-2">
                                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Add New Bed</label>
                                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"><?= htmlspecialchars(str_replace('_', ' ', $room['room_type'])) ?></span>
                                </div>

                                <p class="text-[11px] text-slate-500">Use a clear bed label so it's easy to track in admissions.</p>
                                <div class="flex flex-col sm:flex-row gap-2">
                                    <input type="hidden" name="action" value="add_bed">
                                    <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
                                    <div class="relative flex-1">
                                        <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-[18px]">bed</span>
                                        <input type="text" name="bed_label" required maxlength="150" placeholder="e.g. Bed 01" class="w-full rounded-xl border border-slate-200 pl-10 pr-3 py-2 text-sm font-bold text-slate-700 shadow-sm focus:border-primary focus:ring-primary">
                                    </div>
                                    <button type="submit" class="rounded-xl bg-primary hover:bg-primary-dark text-white px-4 py-2 text-xs font-black transition-colors shadow-sm sm:min-w-[110px]">Add Bed</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>

                        <div class="space-y-4 border-t border-slate-200 pt-6">
                            <h4 class="text-sm font-black text-slate-800 mb-2">Beds</h4>

                            <?php if (empty($room['beds'])): ?>
                                <div class="rounded-2xl bg-white border border-dashed border-slate-300 p-6 text-center text-xs font-bold text-slate-500">
                                    No beds assigned to this room yet.
                                </div>
                            <?php else: ?>
                                <?php foreach ($room['beds'] as $bed): ?>
                                    <?php $isBedLockedByPatient = (trim((string)($bed['patient_name'] ?? '')) !== '' || trim((string)($bed['patient_id'] ?? '')) !== ''); ?>
                                    <div class="rounded-2xl border p-4 shadow-sm <?= getBedCardClasses($bed['bed_status']) ?>">

                                        <div class="flex items-start justify-between gap-2 mb-3">
                                            <div>
                                                <p class="text-sm font-black text-slate-800 truncate"><?= htmlspecialchars($bed['bed_label']) ?></p>

                                                <?php if (strtolower($bed['bed_status']) === 'occupied' && !empty($bed['patient_name'])): ?>
                                                    <p class="text-xs font-bold text-rose-700 mt-1 flex items-center gap-1 bg-rose-100 px-2 py-1 rounded-lg w-max border border-rose-200">
                                                        <span class="material-symbols-outlined text-[14px]">person</span>
                                                        <?= htmlspecialchars($bed['patient_name']) ?>
                                                    </p>
                                                    <?php if (!empty($bed['admission_datetime'])): ?>
                                                        <p class="text-[10px] font-bold text-slate-600 mt-1 flex items-center gap-1">
                                                            <span class="material-symbols-outlined text-[12px]">schedule</span>
                                                            Admitted: <?= htmlspecialchars((string)$bed['admission_datetime']) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if (($room['room_type'] ?? '') === 'recovery_room' && !empty($bed['baby_names'])): ?>
                                                        <p class="text-[10px] font-bold text-violet-700 mt-1 flex items-start gap-1">
                                                            <span class="material-symbols-outlined text-[12px] mt-[1px]">child_friendly</span>
                                                            Baby: <?= htmlspecialchars(implode(', ', (array)$bed['baby_names'])) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                            <span class="text-[10px] px-2.5 py-1 rounded-full font-black uppercase tracking-wider <?= getBedBadgeClasses($bed['bed_status']) ?>">
                                                <?= htmlspecialchars($bed['bed_status']) ?>
                                            </span>
                                        </div>

                                        <?php if (strtolower((string)($bed['bed_status'] ?? '')) !== 'occupied'): ?>
                                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-4 border-t border-slate-200/50 pt-4">

                                            <form method="POST" class="flex flex-col gap-2 justify-end">
                                                <input type="hidden" name="action" value="update_bed_status">
                                                <input type="hidden" name="bed_id" value="<?= (int)$bed['id'] ?>">
                                                <label class="text-[10px] font-black uppercase tracking-widest text-slate-400">Change Status</label>
                                                <div class="flex gap-2">
                                                    <select name="bed_status" class="flex-1 rounded-lg border border-slate-200 px-2 py-1.5 text-xs font-bold bg-white" <?= $isBedLockedByPatient ? 'disabled' : '' ?>>
                                                        <option value="available" <?= strtolower($bed['bed_status']) === 'available' ? 'selected' : '' ?>>Available (Ready)</option>
                                                        <option value="cleaning" <?= strtolower($bed['bed_status']) === 'cleaning' ? 'selected' : '' ?>>For Cleaning</option>
                                                    </select>
                                                    <button type="submit" class="rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 px-3 py-1.5 text-[10px] font-bold transition-colors <?= $isBedLockedByPatient ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= $isBedLockedByPatient ? 'disabled' : '' ?>>Update</button>
                                                </div>
                                                <?php if ($isBedLockedByPatient): ?>
                                                    <p class="text-[10px] font-bold text-rose-600">Occupied</p>
                                                <?php endif; ?>
                                            </form>
                                        </div>
                                        <?php endif; ?>

                                        <div class="mt-3 flex items-center justify-between border-t border-slate-200/50 pt-3 opacity-60 hover:opacity-100 transition-opacity">
                                            <form method="POST" class="flex gap-2 w-3/4">
                                                <input type="hidden" name="action" value="rename_bed">
                                                <input type="hidden" name="bed_id" value="<?= (int)$bed['id'] ?>">
                                                <input type="text" name="new_bed_label" required maxlength="150" value="<?= htmlspecialchars($bed['bed_label']) ?>" class="flex-1 rounded-md border border-slate-300 px-2 py-1 text-[10px] font-bold" <?= $isBedLockedByPatient ? 'readonly' : '' ?>>
                                                <button type="submit" class="rounded-md bg-slate-500 hover:bg-slate-700 text-white px-2 py-1 text-[10px] font-bold transition-colors <?= $isBedLockedByPatient ? 'opacity-50 cursor-not-allowed' : '' ?>" <?= $isBedLockedByPatient ? 'disabled' : '' ?>>Rename Bed</button>
                                            </form>

                                            <form method="POST" onsubmit="return confirm('Delete this bed?');">
                                                <input type="hidden" name="action" value="delete_bed">
                                                <input type="hidden" name="bed_id" value="<?= (int)$bed['id'] ?>">
                                                <button type="submit" title="Delete bed" aria-label="Delete bed" class="rounded-md bg-transparent hover:bg-rose-100 text-slate-400 hover:text-rose-600 p-1.5 inline-flex items-center justify-center transition-colors <?= $isBedLockedByPatient ? 'opacity-50 cursor-not-allowed hover:bg-transparent hover:text-slate-400' : '' ?>" <?= $isBedLockedByPatient ? 'disabled' : '' ?>>
                                                    <span class="material-symbols-outlined text-[16px]">delete</span>
                                                </button>
                                            </form>
                                        </div>

                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

        </div>
    </main>
</div>

<script>
    function openLogoutModal() { document.getElementById('logoutModal').classList.remove('hidden'); document.getElementById('logoutModal').classList.add('flex'); }
    function closeLogoutModal() { document.getElementById('logoutModal').classList.remove('flex'); document.getElementById('logoutModal').classList.add('hidden'); }
    function openCleanAllModal() { document.getElementById('cleanAllModal').classList.remove('hidden'); document.getElementById('cleanAllModal').classList.add('flex'); }
    function closeCleanAllModal() { document.getElementById('cleanAllModal').classList.remove('flex'); document.getElementById('cleanAllModal').classList.add('hidden'); }

    function setActiveRoomCategory(category) {
        const buttons = document.querySelectorAll('.room-category-btn');
        const panels = document.querySelectorAll('.room-category-panel');

        buttons.forEach((btn) => {
            const isActive = btn.getAttribute('data-room-category') === category;
            btn.classList.toggle('bg-primary', isActive);
            btn.classList.toggle('text-white', isActive);
            btn.classList.toggle('border-primary', isActive);
            btn.classList.toggle('bg-white', !isActive);
            btn.classList.toggle('text-slate-700', !isActive);
            btn.classList.toggle('border-slate-200', !isActive);
        });

        panels.forEach((panel) => {
            const match = panel.getAttribute('data-room-panel') === category;
            panel.classList.toggle('hidden', !match);
        });
    }

    function openRoomModal(roomId) {
        const modal = document.getElementById('roomModal' + roomId);
        if (!modal) return;
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeRoomModal(roomId) {
        const modal = document.getElementById('roomModal' + roomId);
        if (!modal) return;
        modal.classList.remove('flex');
        modal.classList.add('hidden');
    }

    function confirmLogout() {
        closeLogoutModal();
        document.getElementById('loggingOutScreen').classList.replace('hidden', 'flex');
        setTimeout(() => { window.location.href = '?logout=1&c=<?= urlencode($clinicCode) ?>'; }, 1000);
    }

    document.addEventListener('DOMContentLoaded', function () {
        setActiveRoomCategory('labor_room');

        const categoryButtons = document.querySelectorAll('.room-category-btn');
        categoryButtons.forEach((btn) => {
            btn.addEventListener('click', function () {
                setActiveRoomCategory(btn.getAttribute('data-room-category'));
            });
        });
    });
</script>

</body>
</html>