<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../backend/ethiopian_date.php';
require_once __DIR__ . '/../backend/calendar_system.php';
// session is already started and role checked in dashboard.php

// ------------------------------------------------------------
// Auth / session context
// ------------------------------------------------------------
$userName = (string) ($_SESSION['admin_full_name'] ?? $_SESSION['admin_username'] ?? 'Information Department');
$userRole = (string) ($_SESSION['admin_role'] ?? 'Information Department');
$username = $_SESSION['admin_username'] ?? '';

$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$todayFormatted = ethio_date_format($today, 'F j, Y');

// Determine path prefix for AJAX calls
$isInDashboardSubdir = false;
if (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/dashboards/') !== false) {
    $isInDashboardSubdir = true;
}
$ajaxPrefix = $isInDashboardSubdir ? '../' : '';

// ------------------------------------------------------------
// Helper: safe query + fetch helpers
// ------------------------------------------------------------
function db_fetch_all_assoc(mysqli $conn, string $sql, array $params = []): array
{
    $rows = [];
    if (!$stmt = $conn->prepare($sql)) {
        return $rows;
    }
    if (!empty($params)) {
        $types = '';
        $bind  = [];
        foreach ($params as $p) {
            if (is_int($p)) {
                $types .= 'i';
            } elseif (is_float($p)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
            $bind[] = $p;
        }
        $stmt->bind_param($types, ...$bind);
    }
    if ($stmt->execute()) {
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    }
    $stmt->close();
    return $rows;
}

// ------------------------------------------------------------
// Live data: members, stats, recent
// ------------------------------------------------------------
$recentMembers = [];
$genderCounts = ['male' => 0, 'female' => 0];
$sectionCounts = [
    '7_13'     => 0,
    '14_17'    => 0,
    '18_plus'  => 0,
];
$statusCounts = [
    'active'   => 0,
    'warning'  => 0,
    'inactive' => 0,
    'archived' => 0,
];

$totalMembers = 0;
$memberTypeRegular = 0;
$memberTypeSpecial = 0; // member_type = special_regular (student + role)
$memberTypeWaiting = 0; // registration waiting (no ID yet)
$memberTypeHonor   = 0;

$atRiskStudents = 0; // placeholder: define logic later

if (isset($conn)) {
    // The full directory is fetched on demand by MemberDirectoryService. Do not
    // embed a second sensitive roster in the dashboard response.

    // Recent 10
    $recentMembers = db_fetch_all_assoc(
        $conn,
        "SELECT 
            student_name,
            father_name,
            grandfather_name,
            member_type,
            status,
            current_section,
            created_at
         FROM members
         WHERE status != 'archived'
         ORDER BY id DESC
         LIMIT 10"
    );

    // Aggregations - Total excludes archived, but we count archived separately
    $aggCounts = db_fetch_all_assoc(
        $conn,
        "SELECT 
            SUM(status != 'archived')           AS total_members,
            SUM(member_type='regular' AND status != 'archived')      AS regular_cnt,
            SUM(member_type='special_regular' AND status != 'archived') AS special_cnt,
            SUM(member_type='honorary' AND status != 'archived')     AS honor_cnt,
            SUM(registration_type='waiting' AND status != 'archived') AS waiting_cnt,
            SUM(gender='male' AND status != 'archived')              AS male_cnt,
            SUM(gender='female' AND status != 'archived')            AS female_cnt,
            SUM(status='active')            AS active_cnt,
            SUM(status='warning')           AS warning_cnt,
            SUM(status='inactive')          AS inactive_cnt,
            SUM(status='archived')          AS archived_cnt,
            SUM(age_group='7_13' AND status != 'archived')           AS ag_7_13_cnt,
            SUM(age_group='14_17' AND status != 'archived')          AS ag_14_17_cnt,
            SUM(age_group='18_plus' AND status != 'archived')        AS ag_18_plus_cnt
         FROM members"
    );

    if (!empty($aggCounts)) {
        $row = $aggCounts[0];
        $totalMembers      = (int) ($row['total_members'] ?? 0);
        $memberTypeRegular = (int) ($row['regular_cnt'] ?? 0);
        $memberTypeHonor   = (int) ($row['honor_cnt'] ?? 0);
        $memberTypeSpecial = (int) ($row['special_cnt'] ?? 0);
        $memberTypeWaiting = (int) ($row['waiting_cnt'] ?? 0);

        $genderCounts['male']   = (int) ($row['male_cnt'] ?? 0);
        $genderCounts['female'] = (int) ($row['female_cnt'] ?? 0);

        $statusCounts['active']   = (int) ($row['active_cnt'] ?? 0);
        $statusCounts['warning']  = (int) ($row['warning_cnt'] ?? 0);
        $statusCounts['inactive'] = (int) ($row['inactive_cnt'] ?? 0);
        $statusCounts['archived'] = (int) ($row['archived_cnt'] ?? 0);

        $sectionCounts['7_13']     = (int) ($row['ag_7_13_cnt'] ?? 0);
        $sectionCounts['14_17']    = (int) ($row['ag_14_17_cnt'] ?? 0);
        $sectionCounts['18_plus']  = (int) ($row['ag_18_plus_cnt'] ?? 0);
    }

    // Example "at risk" heuristic placeholder: warning + inactive (non-archived)
    $atRiskStudents = $statusCounts['warning'] + $statusCounts['inactive'];
}

// For display labels mapping — delegates to the ministry category model
// so the A/B/C letters and Amharic labels have one source of truth.
require_once __DIR__ . '/../backend/services/MemberCategory.php';

// Attendance alerting Phase 2: open warnings per department (server-side, cheap GROUP BY).
require_once __DIR__ . '/../backend/services/AttendanceWarningService.php';
require_once __DIR__ . '/../backend/services/AttendanceMonthRollupService.php';
// Attendance alerting Phase 4: current EC month roll-up (Education + Mezmur) for the "Monthly Attendance" card.
$attendanceMonthCard = \App\Services\AttendanceMonthRollupService::dashboardCard($conn, ['edu', 'mezmur']);
$attendanceWarningsAvailable = false;
$attendanceWarningCounts = ['edu' => ['total' => 0, 'critical' => 0], 'mezmur' => ['total' => 0, 'critical' => 0]];
try {
    $attendanceWarningsAvailable = \App\Services\AttendanceWarningService::available($conn);
    if ($attendanceWarningsAvailable) {
        $attendanceWarningCounts = \App\Services\AttendanceWarningService::openCounts($conn);
    }
} catch (\Throwable $e) {
    error_log('Dashboard attendance warning counts failed: ' . $e->getMessage());
}
function sectionLabelFromGroup(?string $ageGroup): string
{
    $letter = \App\Services\MemberCategory::letterFor($ageGroup);
    return $letter === null ? '' : \App\Services\MemberCategory::labelAm($letter)
        . ' (' . $letter . ')';
}

// ------------------------------------------------------------
// Recent members normalized for UI
// ------------------------------------------------------------
$recentMembers = array_map(function ($row) {
    $name = trim(($row['student_name'] ?? '') . ' ' . ($row['father_name'] ?? '') . ' ' . ($row['grandfather_name'] ?? ''));
    return [
        'name'   => $name ?: '—',
        'type'   => $row['member_type'] ?? 'መደበኛ',
        'status' => ucfirst($row['status'] ?? 'Active'),
        'section'=> $row['current_section'] ?? '',
        'date'   => !empty($row['created_at']) ? ethio_date_format(new DateTime($row['created_at'], new DateTimeZone('Africa/Addis_Ababa')), 'M j, Y') : '',
    ];
}, $recentMembers);

// ------------------------------------------------------------
// Helpers: next member code (simple illustrative logic)
// ------------------------------------------------------------
function generate_next_member_code(mysqli $conn): string
{
    $res = db_fetch_all_assoc($conn, "SELECT member_code FROM members WHERE member_code IS NOT NULL AND member_code <> '' ORDER BY id DESC LIMIT 1");
    $last = $res[0]['member_code'] ?? null;
    if (!$last) return '0001';
    $num = preg_replace('/\D/', '', $last);
    $next = str_pad((int)$num + 1, 4, '0', STR_PAD_LEFT);
    return $next;
}

$nextMemberCode = isset($conn) ? generate_next_member_code($conn) : '0001';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= DEPT_INFO_NAME_EN ?> - <?= SCHOOL_NAME_SHORT_AM ?></title>
    <?= wbws_calendar_scripts($conn) ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- CSRF Token for AJAX requests -->
    <meta name="csrf-token" content="<?= generateCsrfToken() ?>">
    <script>
        // Global CSRF token for fetch requests
        const CSRF_TOKEN = '<?= generateCsrfToken() ?>';
    </script>

    <link rel="icon"
          href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⛪</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/admin/js/chart.umd.min.js"></script>
    <script src="/admin/js/danger_confirm.js?v=20261022dc4"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" integrity="sha384-vtjasyidUo0kW94K5MXDXntzOJpQgBKXmE7e2Ga4LG0skTTLeBi97eFAXsqewJjw" crossorigin="anonymous"></script>
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" integrity="sha384-/o6I2CkkWC//PSjvWC/eYN7l3xM3tJm8ZzVkCOfp//W05QcE3mlGskpoHB6XqI+B" crossorigin="anonymous"/>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&family=Poppins:wght@300;400;600;700&display=swap');

        :root {
            --emerald-600: #059669;
            --emerald-700: #047857;
            --emerald-800: #065f46;
            --card-radius: 1.25rem;
        }

        body {
            font-family: 'Poppins', sans-serif;
            -webkit-font-smoothing: antialiased;
            background: #f5f7fb;
        }

        .amharic-text {
            font-family: 'Noto Serif Ethiopic', serif;
        }

        .sidebar-gradient {
            width: 260px;
            background: linear-gradient(180deg, #064e3b 0%, #047857 40%, #16a34a 100%);
            position: sticky;
            top: 0;
            align-self: flex-start;
            height: 100vh;
            overflow-y: auto;
            color: #ecfdf5;
            padding: 18px 16px 20px;
        }

        .stat-card {
            border-radius: 1.5rem;
            color: #ffffff;
            box-shadow: 0 12px 25px rgba(15, 118, 110, 0.35);
            transition: transform 0.18s ease-out, box-shadow 0.18s ease-out;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 16px 32px rgba(15, 118, 110, 0.45);
        }

        .panel {
            border-radius: 1rem;
            background: #ffffff;
            box-shadow: 0 8px 20px rgba(148, 163, 184, 0.35);
        }

        .content-section { display: none; }
        .content-section.active { display: block; }

        /* Mobile / app-style tweaks */
        @media (max-width: 768px) {
            body {
                background: #eef2f7;
                padding-bottom: 76px; /* space for bottom nav */
            }

            .mobile-touch-target {
                min-height: 48px;
                min-width: 48px;
            }

            .mobile-card {
                border-radius: 1.25rem;
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.10);
                background: rgba(255, 255, 255, 0.92);
                backdrop-filter: blur(6px);
            }

            .panel {
                border-radius: 1.25rem;
            }
        }

        /* Settings tabs */
        .stab{padding:7px 14px;border-radius:10px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#64748b;transition:all .15s;white-space:nowrap}
        .stab:hover{background:#f8fafc;border-color:#cbd5e1}
        .stab-on{background:#0f172a!important;color:#fff!important;border-color:#0f172a!important}
        .settings-pane{animation:fadeSlide .25s ease-out}
        @keyframes fadeSlide{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}
        .sys-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #f1f5f9}
        .sys-row:last-child{border-bottom:none}
        .sys-label{font-size:11px;color:#64748b}.sys-val{font-size:12px;font-weight:600;color:#1e293b}
        /* Attendance tabs */
        .atab{padding:7px 14px;border-radius:10px;font-size:11px;font-weight:500;cursor:pointer;border:1px solid #fed7aa;background:#fff;color:#9a3412;transition:all .15s;white-space:nowrap}
        .atab:hover{background:#fff7ed;border-color:#fb923c}
        .atab-on{background:#ea580c!important;color:#fff!important;border-color:#ea580c!important}
        .att-pane{animation:fadeSlide .25s ease-out}
        .att-badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:600}
        .att-present{background:#d1fae5;color:#065f46}.att-absent{background:#fee2e2;color:#991b1b}
        .att-late{background:#fef3c7;color:#92400e}.att-excused{background:#dbeafe;color:#1e40af}

        .bottom-nav-shadow {
            box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.25);
        }

        .nav-pill-active {
            background: rgba(255, 223, 0, 1);
        }

        /* Modal / sheet */
        .sheet {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: flex-end;
            justify-content: center;
            z-index: 80;
        }
        .sheet.open { display: flex; }
        .sheet .sheet-body {
            width: 100%;
            max-width: 100%;
            height: 100%;
            background: #f8fafc;
            border-radius: 0;
            box-shadow: 0 -10px 40px rgba(0,0,0,0.2);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            animation: sheet-slide-up 0.3s ease-out;
        }

        @keyframes sheet-slide-up {
            from { transform: translateY(100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        /* Desktop: Side panel style */
        @media (min-width: 768px) {
            .sheet {
                align-items: stretch;
                justify-content: flex-end;
                background: rgba(0, 0, 0, 0.4);
            }
            .sheet .sheet-body {
                max-width: 800px;
                height: 100%;
                border-radius: 0;
                border-left: 1px solid #e2e8f0;
                animation: sheet-slide-left 0.3s ease-out;
            }
        }

        @keyframes sheet-slide-left {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        /* Desktop: Make it cover the main content area (right side) */
        @media (min-width: 768px) {
            .sheet {
                left: 16rem; /* Offset by sidebar width (w-64 = 16rem) */
                width: calc(100% - 16rem);
                background: transparent; /* No overlay needed if it covers content */
            }
            .sheet .sheet-body {
                max-width: 100%;
                border-left: 1px solid #e2e8f0;
            }
        }

        /* Animations */
        .toast-enter {
            animation: toast-in 0.25s ease-out;
        }
        @keyframes toast-in {
            from { transform: translateY(-16px); opacity: 0; }
            to   { transform: translateY(0); opacity: 1; }
        }

        /* Status chips (P1: one recipe — same look as the JS list chips).
           .chip was never defined, so these rendered as unpadded squares. */
        .chip           { display: inline-block; padding: .125rem .5rem; border-radius: 999px; font-size: 10px; font-weight: 700; line-height: 1.4; }
        .badge-active   { background: #d1fae5; color: #047857; }
        .badge-warning  { background: #fef3c7; color: #b45309; }
        .badge-inactive { background: #f1f5f9; color: #475569; }
        .badge-archived { background: #e2e8f0; color: #475569; }

        /* Advanced search pills */
        .filter-pill {
            border: 1px solid #e2e8f0;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 11px;
            color: #475569;
            cursor: pointer;
        }
        .filter-pill.active {
            background: #d1fae5;
            border-color: #34d399;
            color: #065f46;
        }

        /* Editable row icons */
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 10px;
            border: 1px solid #e2e8f0;
            background: #fff;
            transition: all 0.12s ease;
        }
        .action-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15,23,42,0.12);
            border-color: #cbd5e1;
        }

        /* Sticky top bar mobile */
        .mobile-sticky-header {
            position: sticky;
            top: 0;
            z-index: 30;
            background: linear-gradient(180deg, #047857 0%, #0f766e 100%);
            color: #fff;
            padding: 12px 16px;
        }

        /* Hide scrollbar for bottom nav */
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }

        /* Active bottom nav button */
        .nav-bottom-active {
            background: rgba(255, 255, 255, 0.2);
            opacity: 1 !important;
        }

        /* Inline labels */
        .label-soft {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: #94a3b8;
            font-weight: 700;
        }
    </style>
<link rel="stylesheet" href="/admin/css/mobile.css?v=<?= SSMS_ASSET_VER ?>">
<?php include __DIR__ . "/../theme.php"; ?>
</head>
<body class="bg-slate-100">
<?php if (function_exists("ay_context_bar_html")) echo ay_context_bar_html($conn ?? null); ?>

<div class="min-h-screen flex flex-col md:flex-row">

    <!-- Desktop Sidebar -->
    <aside class="hidden md:flex sidebar-gradient school-sidebar text-white w-64 flex-col py-5 px-4">
        <div class="flex items-center mb-6">
            <div class="w-11 h-11 rounded-2xl bg-white/15 flex items-center justify-center shadow-md">
                <i class="fa-solid fa-id-card-clip text-xl"></i>
            </div>
            <div class="ml-3">
                <div class="text-sm font-bold amharic-text">Information Department</div>
                <div class="text-[11px] text-emerald-100 amharic-text"><?= SCHOOL_NAME_SHORT_AM ?> <?= DEPT_INFO_NAME ?></div>
            </div>
        </div>

        <nav class="flex-1 flex flex-col space-y-1 text-sm">
            <a href="#" data-section="dashboard"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gauge text-sm"></i>
                </span>
                <span class="font-semibold">Dashboard</span>
            </a>

            <button data-section="manage"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-pen-to-square text-sm"></i>
                </span>
                <span class="font-semibold">Manage Members</span>
            </button>

            <button data-section="archive"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-regular fa-folder-open text-sm"></i>
                </span>
                <span class="font-semibold">Old Members Archive</span>
            </button>

                

            




            <button data-section="attendance"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-clipboard-check text-sm"></i>
                </span>
                <span class="font-semibold">Attendance & Status</span>
            </button>

            <button data-section="analytics"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-cyan-500/30 flex items-center justify-center">
                    <i class="fa-solid fa-chart-column text-sm"></i>
                </span>
                <span class="font-semibold">Analytics Hub</span>
            </button>

            <button data-section="reports"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-file-lines text-sm"></i>
                </span>
                <span class="font-semibold">Exports & Reports</span>
            </button>

            <button data-section="settings"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-gear text-sm"></i>
                </span>
                <span class="font-semibold">Settings</span>
            </button>

            <button data-comm-open="inbox"
                    class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition">
                <span class="w-8 h-8 rounded-xl bg-teal-500/30 flex items-center justify-center">
                    <i class="fa-solid fa-comments text-sm"></i>
                </span>
                <span class="font-semibold">Communication</span>
            </button>

            <button onclick="navigateToSection('profile')"
               data-section="profile"
               class="mobile-touch-target flex items-center gap-3 px-3 py-2 rounded-xl bg-white/10 hover:bg-white/20 transition text-white text-left w-full border-none cursor-pointer">
                <span class="w-8 h-8 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-user-gear text-sm"></i>
                </span>
                <span class="font-semibold">My Profile</span>
            </button>

        </nav>

        <div class="mt-5 space-y-2">
            <?php
            require_once __DIR__ . '/../components/sidebar_profile_item.php';
            renderSidebarUserCard($userName, 'Info Dept', $todayFormatted ?? '', strtoupper(substr($userName, 0, 1)), 'linear-gradient(135deg,#059669,#10b981)');
            ?>
            <a href="/admin/logout.php"
               class="w-full flex items-center justify-center gap-2 px-3 py-2 rounded-xl bg-red-500/95 text-white text-xs font-semibold shadow hover:bg-red-600 transition">
                <span>Logout</span>
                <i class="fa-solid fa-power-off text-xs"></i>
            </a>
        </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col">
        <!-- Mobile top header -->
        <div class="mobile-sticky-header md:hidden flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-white/15 rounded-full flex items-center justify-center">
                    <i class="fa-solid fa-circle-info text-lg"></i>
                </div>
                <div>
                    <div class="text-sm font-bold">Information Department</div>
                    <div class="text-[11px] text-emerald-100 amharic-text">የመረጃ ቁጥጥር · መመዝገብ · ሪፖርት</div>
                </div>
            </div>
            <div class="text-right text-[11px]">
                <div class="font-semibold"><?= e($todayFormatted) ?></div>
                <div class="text-emerald-100"><?= e($userName) ?></div>
            </div>
        </div>

        <header class="hidden md:flex bg-emerald-700 text-white px-3 sm:px-6 py-3 sm:py-4 items-center justify-between shadow-md">
            <div class="flex items-center gap-3">
                <button class="md:hidden w-9 h-9 rounded-full bg-white/10 flex items-center justify-center">
                    <i class="fa-solid fa-bars text-sm"></i>
                </button>
                <div>
                    <div class="flex items-center gap-2">
                        <span class="w-8 h-8 rounded-full bg-white/15 flex items-center justify-center">
                            <i class="fa-solid fa-circle-info text-lg"></i>
                        </span>
                        <div>
                            <h1 class="text-base sm:text-lg font-bold amharic-text">
                                Information Department
                            </h1>
                            <p class="text-[11px] sm:text-xs text-emerald-100 amharic-text">
 የመረጃ ቁጥጥር · የአባላት መመዝገብ እና ሪፖርት
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <!-- Notification Bell -->
                <?php 
                include __DIR__ . '/../components/notification_center.php';
                echo renderNotificationCenter();
                ?>
                
                <div class="hidden sm:flex flex-col text-right text-xs">
                    <span class="font-semibold"><?= e($todayFormatted) ?></span>
                    <span class="text-emerald-100"><?= e($userName) ?></span>
                </div>
                <div class="w-9 h-9 rounded-full bg-white/95 flex items-center justify-center text-xs font-bold text-emerald-700 sm:hidden">
                    <?= strtoupper(substr($userName, 0, 1)) ?>
                </div>
            </div>
        </header>

        <main class="flex-1 p-3 sm:p-5 space-y-4 sm:space-y-5">

            <!-- DASHBOARD -->
            <section id="section-dashboard" class="content-section active">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 mb-2 sm:mb-3">
                    <div>
                        <div class="flex items-center gap-2 text-emerald-700">
                            <i class="fa-solid fa-gauge-high text-sm"></i>
                            <span class="uppercase tracking-wide text-[11px] font-semibold">Dashboard</span>
                        </div>
                        <h2 class="text-lg sm:text-xl font-bold text-slate-800">
                            Information Department Overview
                        </h2>
                    </div>
                    <div class="flex items-center gap-2 text-xs">
                        <span class="px-3 py-1.5 rounded-full border border-emerald-200 text-emerald-700 bg-white">
                            Live statistics
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 mb-4">
                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, <?= THEME_PRIMARY_DARK ?>, <?= THEME_PRIMARY ?>, <?= THEME_PRIMARY_LIGHT ?>)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Total Members</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-users text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">
                            <?= $totalMembers ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">
                            Active (non-archived) members
                        </div>
                    </div>

                    <div class="stat-card p-3 sm:p-4 flex flex-col justify-between" style="background:linear-gradient(135deg, <?= THEME_ACCENT ?>, <?= THEME_ACCENT_2 ?>)">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Member Type</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-tags text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>መደበኛ</span>
                                <span class="font-semibold"><?= $memberTypeRegular ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>ልዩ መደበኛ</span>
                                <span class="font-semibold"><?= $memberTypeSpecial ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>የክብር አባላት</span>
                                <span class="font-semibold"><?= $memberTypeHonor ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="stat-card bg-gradient-to-tr from-amber-500 via-orange-400 to-yellow-400 p-3 sm:p-4 flex flex-col justify-between">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">By Status</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-heart-pulse text-sm"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-[11px] sm:text-xs space-y-0.5">
                            <div class="flex justify-between">
                                <span>Active</span>
                                <span class="font-semibold"><?= $statusCounts['active'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Warning</span>
                                <span class="font-semibold"><?= $statusCounts['warning'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Inactive</span>
                                <span class="font-semibold"><?= $statusCounts['inactive'] ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span>Archived</span>
                                <span class="font-semibold"><?= $statusCounts['archived'] ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance alerting Phase 2: "Open Attendance Warnings" replaces the
                         members.status-based "At-risk" heuristic (requirements §4–§6, §9). -->
                    <a href="/admin/attendance_warnings.php" class="stat-card bg-gradient-to-tr from-red-500 via-rose-500 to-orange-400 p-3 sm:p-4 flex flex-col justify-between" id="aw-dashboard-card" title="Open the attendance warnings list" style="text-decoration:none;color:inherit">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Open Attendance Warnings</div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                            </div>
                        </div>
                        <?php if ($attendanceWarningsAvailable): ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold" data-aw-total>
                            <?= (int)$attendanceWarningCounts['edu']['total'] + (int)$attendanceWarningCounts['mezmur']['total'] ?>
                        </div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-90 flex gap-3 flex-wrap">
                            <span>Education <b data-aw-edu><?= (int)$attendanceWarningCounts['edu']['total'] ?></b><?php if ((int)$attendanceWarningCounts['edu']['critical'] > 0): ?> <span class="opacity-80">(<?= (int)$attendanceWarningCounts['edu']['critical'] ?> critical)</span><?php endif; ?></span>
                            <span>Mezmur <b data-aw-mezmur><?= (int)$attendanceWarningCounts['mezmur']['total'] ?></b><?php if ((int)$attendanceWarningCounts['mezmur']['critical'] > 0): ?> <span class="opacity-80">(<?= (int)$attendanceWarningCounts['mezmur']['critical'] ?> critical)</span><?php endif; ?></span>
                        </div>
                        <?php else: ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">—</div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">Not available yet (feature flag / sql/072)</div>
                        <?php endif; ?>
                    </a>
                    <!-- Attendance alerting Phase 4: Monthly Attendance (current Ethiopian month; both departments, read-only). -->
                    <a href="/admin/attendance_monthly.php" class="stat-card bg-gradient-to-tr from-teal-600 via-teal-500 to-emerald-400 p-3 sm:p-4 flex flex-col justify-between" id="am-dashboard-card" title="Monthly attendance report (Ethiopian months)" style="text-decoration:none;color:inherit">
                        <div class="flex items-center justify-between">
                            <div class="text-xs uppercase tracking-wide">Monthly Attendance · <?= htmlspecialchars($attendanceMonthCard['month_label_am'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="w-8 h-8 rounded-2xl bg-white/15 flex items-center justify-center">
                                <i class="fa-solid fa-calendar-days text-sm"></i>
                            </div>
                        </div>
                        <?php if ($attendanceMonthCard['available']): ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold" data-am-below><?= (int)$attendanceMonthCard['below'] ?></div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-90 flex gap-3 flex-wrap">
                            <span>below threshold</span>
                            <span>Education <b><?= (int)($attendanceMonthCard['per_dept']['edu']['below'] ?? 0) ?></b></span>
                            <span>Mezmur <b><?= (int)($attendanceMonthCard['per_dept']['mezmur']['below'] ?? 0) ?></b></span>
                            <?php if ($attendanceMonthCard['avg_rate'] !== null): ?><span>avg <b><?= $attendanceMonthCard['avg_rate'] ?>%</b></span><?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="mt-2 text-2xl sm:text-3xl font-bold">—</div>
                        <div class="mt-1 text-[11px] sm:text-xs opacity-80">Not available yet (feature flag / sql/074)</div>
                        <?php endif; ?>
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                        <i class="fa-solid fa-child"></i>
                                    </span>
                                    <span>Section Distribution (by current section)</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Live numbers from members table
                                </p>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-3 mt-3">
                            <div class="p-3 rounded-2xl bg-sky-50 border border-sky-100">
                                <div class="text-[11px] text-sky-700 amharic-text">ህጻናት (A)</div>
                                <div class="text-xl font-bold text-sky-900 mt-1"><?= $sectionCounts['7_13'] ?></div>
                                <div class="text-[11px] text-sky-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-amber-50 border border-amber-100">
                                <div class="text-[11px] text-amber-700 amharic-text">ማዕከላዊያን (B)</div>
                                <div class="text-xl font-bold text-amber-900 mt-1"><?= $sectionCounts['14_17'] ?></div>
                                <div class="text-[11px] text-amber-500">Section</div>
                            </div>
                            <div class="p-3 rounded-2xl bg-rose-50 border border-rose-100">
                                <div class="text-[11px] text-rose-700 amharic-text">ወጣቶች (C)</div>
                                <div class="text-xl font-bold text-rose-900 mt-1"><?= $sectionCounts['18_plus'] ?></div>
                                <div class="text-[11px] text-rose-500">Section</div>
                            </div>
                        </div>

                        <div class="mt-4 h-28">
                            <canvas id="sectionChart"></canvas>
                        </div>
                    </div>

                    <div class="panel p-4 mobile-card">
                        <div class="flex items-center justify-between mb-2">
                            <div>
                                <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                    <span class="w-7 h-7 rounded-xl bg-sky-100 flex items-center justify-center text-sky-600">
                                        <i class="fa-solid fa-venus-mars"></i>
                                    </span>
                                    <span>Gender Distribution</span>
                                </h3>
                                <p class="text-[11px] text-slate-500">
                                    Visual balance of male vs female members
                                </p>
                            </div>
                        </div>

                        <div class="mt-3 h-28">
                            <canvas id="genderChart"></canvas>
                        </div>

                        <div class="mt-3 grid grid-cols-2 gap-2 text-[11px]">
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-sky-500"></span>
                                <span>Male</span>
                                <span class="ml-auto font-semibold"><?= $genderCounts['male'] ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                <span>Female</span>
                                <span class="ml-auto font-semibold"><?= $genderCounts['female'] ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-emerald-100 flex items-center justify-center text-emerald-600">
                                    <i class="fa-solid fa-clock-rotate-left"></i>
                                </span>
                                <span>Recent Members</span>
                            </h3>
                            <p class="text-[11px] text-slate-500">
                                Latest registrations handled by the Information Department
                            </p>
                        </div>
                        <button class="hidden sm:inline-flex items-center gap-1 text-[11px] text-emerald-700 hover:text-emerald-900">
                            View all →
                        </button>
                    </div>

                    <div class="overflow-x-auto -mx-2 sm:mx-0">
                        <table class="min-w-full text-[11px] sm:text-xs">
                            <thead>
                            <tr class="text-left text-slate-500 border-b border-slate-200">
                                <th class="py-2 px-2 sm:px-3">Name</th>
                                <th class="py-2 px-2 sm:px-3">Type</th>
                                <th class="py-2 px-2 sm:px-3">Status</th>
                                <th class="py-2 px-2 sm:px-3">Section</th>
                                <th class="py-2 px-2 sm:px-3">Registered</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($recentMembers as $row): ?>
                                <tr class="hover:bg-slate-50">
                                    <td class="py-2 px-2 sm:px-3 amharic-text"><?= e($row['name']) ?></td>
                                    <td class="py-2 px-2 sm:px-3 amharic-text">
                                        <?= e($row['type']) ?>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3">
                                        <?php
                                        $statusChip = 'badge-active';
                                        if (stripos($row['status'], 'Warning') !== false) $statusChip = 'badge-warning';
                                        if (stripos($row['status'], 'Inactive') !== false) $statusChip = 'badge-inactive';
                                        ?>
                                        <span class="chip <?= $statusChip ?>"><?= e($row['status']) ?></span>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3 amharic-text">
                                        <?= esc($row['section'], '—') ?>
                                    </td>
                                    <td class="py-2 px-2 sm:px-3">
                                        <?= e($row['date'] ?? '') ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ALL MEMBERS -->

            <!-- MANAGE (edit, archive) -->
            <section id="section-manage" class="content-section">
                <div class="panel p-4 mobile-card">
                    <div class="flex items-center justify-between mb-3">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                                <span class="w-7 h-7 rounded-xl bg-indigo-100 flex items-center justify-center text-indigo-600">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </span>
                                <span>Manage Members</span>
                            </h3>
                            <p class="text-xs text-slate-500">
                                Search, Edit, and Manage all active members.
                            </p>
                        </div>
                    </div>

                    <!-- Search & Filter Bar (Duplicated from All Members for convenience) -->
                    <div class="bg-white border border-slate-200 rounded-xl p-3 mb-4 shadow-sm">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-3">
                            <div class="relative">
                                <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                                <input autocomplete="off" type="text" id="manageSearchInput" placeholder="Search anything: names, phone, address, guardian, profession, ID..."
                                       class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-xs focus:ring-indigo-200 focus:border-indigo-400">
                            </div>
                            <select id="manageFilterType" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Registration Types</option>
                                <option value="waiting">Waiting</option>
                                <option value="direct">Direct</option>
                                <option value="transfer">Transfer</option>
                            </select>
                            <select id="manageFilterStatus" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="warning">Warning</option>
                                <option value="inactive">Inactive</option>
                                <option value="archived">Archived</option>
                            </select>
                            <select id="manageFilterMemberType" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Member Types</option>
                                <option value="regular">Regular</option>
                                <option value="honorary">Honorary</option>
                                <option value="special_regular">Special Regular</option>
                            </select>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <select id="manageFilterGender" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Gender</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                            <select id="manageFilterCity" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Cities</option>
                                <option value="addis_ababa">Addis Ababa</option>
                                <option value="oromia">Oromia</option>
                            </select>
                            <select id="manageFilterAgeGroup" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Age Groups</option>
                                <?php foreach (\App\Services\MemberCategory::sections() as $infoCode => $infoSec): ?><option value="<?= $infoCode ?>"><?= e($infoSec['am']) ?> (<?= e($infoSec['letter']) ?>)</option><?php endforeach; ?>
                            </select>
                            <select id="manageFilterEducation" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-xs">
                                <option value="">All Education Levels</option>
                                <?php
                                // M2: options single-sourced from the canonical vocabulary —
                                // the old hardcoded set matched nothing the forms store.
                                require_once __DIR__ . '/../backend/services/EducationLabels.php';
                                foreach (\App\Services\EducationLabels::options() as $eduCode => $eduLabel): ?>
                                <option value="<?= e($eduCode) ?>"><?= e($eduLabel) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mt-3 flex flex-col sm:flex-row gap-2 justify-end">
                            <button type="button" onclick="resetManageFilters()" class="px-4 py-2 rounded-xl border border-slate-200 text-xs font-semibold text-slate-600 bg-white hover:bg-slate-50">Reset</button>
                            <button onclick="applyManageFilters()" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-xs font-bold hover:bg-indigo-700">Filter</button>
                        </div>
                    </div>

                    <!-- Members List Table -->
                    <div class="overflow-x-auto border rounded-xl bg-white shadow-sm">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-slate-50 text-[11px] text-slate-500 uppercase tracking-wider">
                                <tr>
                                    <th class="px-4 py-3 font-semibold">Member</th>
                                    <th class="px-4 py-3 font-semibold">Type</th>
                                    <th class="px-4 py-3 font-semibold">Status</th>
                                    <th class="px-4 py-3 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="manageMembersTableBody" class="divide-y divide-slate-100 text-xs text-slate-700">
                                <!-- Populated by JS -->
                                <tr><td colspan="4" class="p-4 text-center text-slate-400">Loading members...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="manageMembersPagination" class="mt-3 flex flex-col sm:flex-row items-center justify-between gap-3" aria-live="polite"></div>
                </div>
            </section>

<script src="/admin/js/paginated-list.js?v=20261022p"></script>
<script>window.WBWS_SECTIONS=<?= json_encode(array_map(static function($sec){return $sec['am'].' ('.$sec['letter'].')';}, \App\Services\MemberCategory::sections()), JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="/admin/js/manage-members.js" defer></script>
<script src="/frontend/js/member-picker.js?v=20261022p" defer></script>
<script src="/admin/js/archive-members.js" defer></script>

            <!-- ARCHIVE -->
            <section id="section-archive" class="content-section">
                <div class="panel p-4 mobile-card">
                    <!-- Header -->
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                        <div>
                            <h3 class="text-sm font-semibold text-slate-800 mb-1">
                                <i class="fa-solid fa-box-archive text-amber-500 mr-2"></i>Old Members Archive
                            </h3>
                            <p class="text-xs text-slate-500">
                                Archived / left members. You can restore them back to active if they return.
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="archivedCount" class="px-3 py-1.5 bg-amber-100 text-amber-700 rounded-full text-xs font-bold">
                                Loading...
                            </span>
                            <button onclick="loadArchivedMembers()" class="p-2 text-slate-500 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition">
                                <i class="fa-solid fa-sync"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Search -->
                    <div class="mb-4">
                        <div class="relative">
                            <i class="fa-solid fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                            <input autocomplete="off" type="text" id="archiveSearch" placeholder="Search archived members..." 
                                   oninput="filterArchivedMembers()"
                                   class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 transition">
                        </div>
                    </div>
                    
                    <!-- Archived Members List -->
                    <div id="archivedMembersList" class="space-y-3">
                        <div class="text-center py-8 text-slate-400">
                            <i class="fa-solid fa-spinner fa-spin text-2xl mb-2"></i>
                            <p class="text-sm">Loading archived members...</p>
                        </div>
                    </div>
                    <div id="archivedMembersPagination" class="mt-3 flex items-center justify-between gap-3" aria-live="polite"></div>
                </div>
            </section>



            <!-- GROUPS -->
            <!-- M20: placeholder section removed — Information Dept has no Groups module access; nav entry removed with it. -->

            <!-- ════════════════════════════════════════════════════════
                 ANALYTICS HUB — shared read-only analytics over the two
                 independent attendance sources: Education (classes) and
                 Mezmur (sections). HR attendance retired 2026-10-07; the hub
                 partial is shared with the HR dashboard. Never merges data.
            ═════════════════════════════════════════════════════════ -->
            <section id="section-analytics" class="content-section">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-9 h-9 rounded-2xl bg-cyan-100 flex items-center justify-center"><i class="fa-solid fa-chart-column text-cyan-600"></i></span>
                            Analytics Hub
                        </h2>
                        <p class="text-xs text-slate-500 mt-1">Shared with the HR department — both analyse the Education and Mezmur attendance; neither records it.</p>
                    </div>
                </div>
                <?php include __DIR__ . '/sections/attendance_analytics_hub.php'; ?>
            </section>

            <section id="section-attendance" class="content-section">

                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-9 h-9 rounded-2xl bg-orange-100 flex items-center justify-center"><i class="fa-solid fa-clipboard-check text-orange-600"></i></span>
                            Attendance & Status
                        </h2>
                        <p class="text-xs text-slate-500 amharic-text mt-1">የአባላት ቆጠራና ሁኔታ</p>
                    </div>
                </div>

                <!-- Sub-Tabs -->
                <div class="flex gap-2 mb-5 overflow-x-auto hide-scrollbar pb-1">
                    <button onclick="showAttTab(this,'attOverview')" class="atab atab-on"><i class="fa-solid fa-chart-pie mr-1"></i>Overview</button>
                    <button onclick="showAttTab(this,'attDaily')" class="atab"><i class="fa-solid fa-calendar-day mr-1"></i>Daily Report</button>
                    <button onclick="showAttTab(this,'attMember')" class="atab"><i class="fa-solid fa-user-clock mr-1"></i>Member Lookup</button>
                    <button onclick="showAttTab(this,'attRisk')" class="atab"><i class="fa-solid fa-triangle-exclamation mr-1"></i>At-Risk</button>
                    <button onclick="showAttTab(this,'attStatus')" class="atab"><i class="fa-solid fa-user-pen mr-1"></i>Status Mgmt</button>
                </div>

                <!-- ===== OVERVIEW ===== -->
                <div id="attOverview" class="att-pane">
                    <div id="attOvLoad" class="text-center py-12"><div class="inline-block w-5 h-5 border-2 border-slate-200 border-t-orange-500 rounded-full animate-spin"></div><p class="text-xs text-slate-400 mt-2">Loading...</p></div>
                    <div id="attOvContent" style="display:none">
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-4" id="attKpis"></div>
                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                            <div class="panel p-4"><h4 class="text-xs font-semibold text-slate-600 mb-3"><i class="fa-solid fa-chart-bar mr-1 text-orange-400"></i>Last 7 Days</h4><div style="height:200px"><canvas id="attWeekChart"></canvas></div></div>
                            <div class="panel p-4"><h4 class="text-xs font-semibold text-slate-600 mb-3"><i class="fa-solid fa-chart-line mr-1 text-blue-400"></i>Weekly Summary (4 Weeks)</h4><div style="height:200px"><canvas id="attMonthChart"></canvas></div></div>
                        </div>
                        <div class="panel p-4 mb-4">
                            <h4 class="text-xs font-semibold text-slate-600 mb-3"><i class="fa-solid fa-user-xmark mr-1 text-red-400"></i>Top Absentees (Last 30 Days)</h4>
                            <div id="attAbsentees" class="text-xs text-slate-400">Loading...</div>
                        </div>
                    </div>
                </div>

                <!-- ===== DAILY REPORT ===== -->
                <div id="attDaily" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <div class="flex flex-col sm:flex-row sm:items-center gap-3 mb-3">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Select Date</label>
                                <input type="date" id="attDailyDate" class="px-3 py-2 rounded-xl border border-slate-200 text-sm" onchange="loadDailyReport()">
                            </div>
                            <button onclick="loadDailyReport()" class="px-4 py-2 bg-orange-500 text-white rounded-xl text-xs font-semibold hover:bg-orange-600 mt-4 sm:mt-0"><i class="fa-solid fa-magnifying-glass mr-1"></i>Load</button>
                        </div>
                        <div id="attDailySummary" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3" style="display:none"></div>
                        <div id="attDailyTable" style="display:none;max-height:400px;overflow:auto">
                            <table class="w-full text-xs border-collapse">
                                <thead class="sticky top-0 bg-slate-50"><tr><th class="px-3 py-2 text-left font-semibold text-slate-500">#</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Name</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Code</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Gender</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Attendance</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Notes</th></tr></thead>
                                <tbody id="attDailyBody"></tbody>
                            </table>
                        </div>
                        <div id="attDailyEmpty" class="text-center py-8 text-slate-400" style="display:none"><i class="fa-solid fa-calendar-xmark text-3xl mb-2"></i><p class="text-sm">No attendance recorded for this date</p></div>
                    </div>
                </div>

                <!-- ===== MEMBER LOOKUP ===== -->
                <div id="attMember" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Search Member</label>
                        <div class="flex gap-2">
                            <input autocomplete="off" type="text" id="attMemSearch" class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-sm" placeholder="Name, code, or phone..." onkeyup="if(event.key==='Enter')searchMemberAtt()">
                            <button onclick="searchMemberAtt()" class="px-4 py-2 bg-orange-500 text-white rounded-xl text-xs font-semibold hover:bg-orange-600"><i class="fa-solid fa-search"></i></button>
                        </div>
                    </div>
                    <div id="attMemResults" style="display:none"></div>
                    <div id="attMemDetail" style="display:none"></div>
                </div>

                <!-- ===== AT-RISK MEMBERS ===== -->
                <div id="attRisk" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <div class="flex flex-col sm:flex-row sm:items-end gap-3 mb-3">
                            <!-- Attendance alerting Phase 4: at-risk = below the department policy threshold in an Ethiopian month
                                 (attendance_month_rollup — same numbers as the Monthly attendance report and the warnings engine). -->
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department</label>
                                <select id="attRiskDept" class="px-3 py-2 rounded-xl border border-slate-200 text-sm" onchange="loadAtRiskMonths().then(loadAtRisk)">
                                    <option value="edu" selected>Education</option>
                                    <option value="mezmur">Mezmur</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Month (EC)</label>
                                <select id="attRiskMonth" class="px-3 py-2 rounded-xl border border-slate-200 text-sm"><option value="">Current month</option></select>
                            </div>
                            <button onclick="loadAtRisk()" class="px-4 py-2 bg-red-500 text-white rounded-xl text-xs font-semibold hover:bg-red-600"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Find At-Risk</button>
                            <a id="attRiskReportLink" href="/admin/attendance_monthly.php?below=1" class="px-4 py-2 bg-slate-100 text-slate-700 rounded-xl text-xs font-semibold hover:bg-slate-200"><i class="fa-solid fa-calendar-days mr-1"></i>Full monthly report</a>
                        </div>
                        <div id="attRiskCount" class="text-xs text-slate-400 mb-2"></div>
                        <div id="attRiskList" style="max-height:450px;overflow:auto"></div>
                    </div>
                </div>

                <!-- ===== STATUS MANAGEMENT ===== -->
                <div id="attStatus" class="att-pane" style="display:none">
                    <div class="panel p-4 mb-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-1"><i class="fa-solid fa-user-pen mr-1 text-violet-500"></i>Update Member Status</h4>
                        <p class="text-[10px] text-slate-400 mb-3">Search for a member and update their status based on attendance patterns</p>
                        <div class="flex gap-2 mb-4">
                            <input autocomplete="off" type="text" id="attStatusSearch" class="flex-1 px-3 py-2 rounded-xl border border-slate-200 text-sm" placeholder="Search member name, code, or phone..." onkeyup="if(event.key==='Enter')searchForStatus()">
                            <button onclick="searchForStatus()" class="px-4 py-2 bg-violet-500 text-white rounded-xl text-xs font-semibold hover:bg-violet-600"><i class="fa-solid fa-search"></i></button>
                        </div>
                        <div id="attStatusResults"></div>
                    </div>
                    <div class="panel p-4 mb-4">
                        <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-users-gear mr-1 text-amber-500"></i>Quick Status Overview</h4>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3" id="attStatusKpis">
                            <div class="bg-emerald-50 p-3 rounded-xl text-center"><div class="text-lg font-bold text-emerald-700"><?= $statusCounts['active'] ?? 0 ?></div><div class="text-[10px] text-emerald-600">Active</div></div>
                            <div class="bg-amber-50 p-3 rounded-xl text-center"><div class="text-lg font-bold text-amber-700"><?= $statusCounts['warning'] ?? 0 ?></div><div class="text-[10px] text-amber-600">Warning</div></div>
                            <div class="bg-red-50 p-3 rounded-xl text-center"><div class="text-lg font-bold text-red-700"><?= $statusCounts['inactive'] ?? 0 ?></div><div class="text-[10px] text-red-600">Inactive</div></div>
                            <div class="bg-slate-100 p-3 rounded-xl text-center"><div class="text-lg font-bold text-slate-500"><?= $statusCounts['archived'] ?? 0 ?></div><div class="text-[10px] text-slate-500">Archived</div></div>
                        </div>
                    </div>
                </div>

            </section>

            <!-- REPORTS -->
            <section id="section-reports" class="content-section">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-800 flex items-center gap-2">
                            <span class="w-7 h-7 rounded-xl bg-blue-100 flex items-center justify-center text-blue-600"><i class="fa-solid fa-file-lines"></i></span>
                            <span>Exports & Reports</span>
                        </h3>
                        <p class="text-xs text-slate-500 amharic-text">ሪፖርቶችና ወጪዎች</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="/admin/reports_v2.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-teal-600 to-emerald-600 text-white text-xs font-semibold shadow hover:from-teal-700 hover:to-emerald-700 transition">
                            <i class="fa-solid fa-table-list"></i> New Reporting Centre
                            <span class="px-1.5 py-0.5 rounded-full bg-white/25 text-[9px] font-bold tracking-wide">BETA</span>
                        </a>
                        <a href="/admin/reports.php" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white text-xs font-semibold shadow hover:from-blue-700 hover:to-indigo-700 transition">
                            <i class="fa-solid fa-chart-line"></i> Open Advanced Analytics Center
                            <i class="fa-solid fa-arrow-up-right-from-square text-[10px] opacity-70"></i>
                        </a>
                    </div>
                </div>

                <!-- Quick notice -->
                <div class="panel p-3 mb-4" style="background:linear-gradient(135deg,#eff6ff,#eef2ff);border:1px solid #c7d2fe;">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-chart-pie text-blue-600"></i>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-blue-800">Advanced Analytics Available</div>
                            <div class="text-[10px] text-blue-600">Interactive charts, multi-filter data explorer, and export to CSV, PDF & Word — all in one place.</div>
                        </div>
                    </div>
                </div>

                <!-- ════════════════════════════════════════════════
                     ATTENDANCE PDF REPORTS (Phase D, read-only)
                     Generated over the three independent attendance
                     sources. The hub renders figures; it never edits.
                ═════════════════════════════════════════════════ -->
                <div class="panel p-5 mb-6">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <div>
                            <h4 class="text-sm font-semibold text-slate-700"><i class="fa-solid fa-file-pdf mr-1 text-red-500"></i> Attendance PDF Reports</h4>
                            <p class="text-[11px] text-slate-500 mt-0.5">Read-only reports over Education &amp; Mezmur attendance — rendered with Amharic-capable PDF fonts. Sources stay separate.</p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Report type</label>
                            <select id="pdfReportType" onchange="InfoReports.toggleFields()"
                                    class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="general">General (all departments)</option>
                                <option value="sections">Section-based (Mezmur)</option>
                                <option value="classes">Class-based (Education)</option>
                                <option value="member">Member-based (one member)</option>
                                <option value="full">Full analysis (combined)</option>
                            </select>
                        </div>
                        <div id="pdfSourceWrap" style="display:none">
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Source</label>
                            <select id="pdfReportSource"
                                    class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="mezmur">Mezmur (sections)</option>
                            </select>
                        </div>
                        <div id="pdfMemberWrap" style="display:none">
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Member</label>
                            <input autocomplete="off" type="search" inputmode="search" id="pdfMemberSearch"
                                   data-member-picker-target="pdfMemberId" data-member-picker-status="active"
                                   class="w-full px-3 py-2 mb-1 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500"
                                   placeholder="Search member…">
                            <select id="pdfMemberId" data-optional="true" class="w-full px-3 py-1.5 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                                <option value="">— pick member —</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">From</label>
                            <input type="date" id="pdfFrom" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                        <div>
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">To</label>
                            <input type="date" id="pdfTo" class="w-full px-3 py-2 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500">
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" onclick="InfoReports.download()"
                                class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-gradient-to-r from-red-500 to-rose-600 text-white text-xs font-semibold shadow hover:from-red-600 hover:to-rose-700 transition">
                            <i class="fa-solid fa-file-arrow-down"></i> Generate &amp; Download PDF
                        </button>
                        <span class="text-[11px] text-slate-400"><i class="fa-solid fa-eye mr-1"></i>Reports render only — they never change attendance data.</span>
                    </div>
                </div>

                <!-- Quick Export Buttons -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                    <a href="/admin/export_pdf.php?format=csv&filter=all" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-emerald-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-users text-emerald-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">All Members</div>
                                <div class="text-[10px] text-slate-500">Export full member list as CSV</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-emerald-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=active" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-user-check text-blue-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Active Members Only</div>
                                <div class="text-[10px] text-slate-500">Active status members</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-blue-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-file-pdf text-red-500 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">PDF Report</div>
                                <div class="text-[10px] text-slate-500">Print-ready member list</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-red-500 font-medium"><i class="fa-solid fa-print mr-1"></i>Open & Print</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=waiting" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-amber-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-hourglass-half text-amber-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Waiting Members</div>
                                <div class="text-[10px] text-slate-500">Registration type = waiting</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-amber-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=csv&filter=no_id" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-red-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-id-card text-red-500 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Members Without ID Card</div>
                                <div class="text-[10px] text-slate-500">ID card not generated yet</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-red-500 font-medium"><i class="fa-solid fa-download mr-1"></i>Download CSV</span></div>
                    </a>
                    <a href="/admin/export_pdf.php?format=docx&filter=all" class="panel p-4 hover:shadow-md transition block">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-indigo-100 rounded-xl flex items-center justify-center"><i class="fa-solid fa-file-word text-indigo-600 text-lg"></i></div>
                            <div>
                                <div class="font-semibold text-slate-800 text-sm">Word Document</div>
                                <div class="text-[10px] text-slate-500">All members as Word doc</div>
                            </div>
                        </div>
                        <div class="mt-3 text-right"><span class="text-xs text-indigo-600 font-medium"><i class="fa-solid fa-download mr-1"></i>Download DOC</span></div>
                    </a>
                </div>

                <!-- Summary Stats Report -->
                <div class="panel p-4">
                    <h4 class="text-sm font-semibold text-slate-700 mb-3"><i class="fa-solid fa-chart-bar mr-1 text-blue-500"></i> Quick Summary Stats</h4>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-center mb-4">
                        <div class="bg-emerald-50 p-3 rounded-xl"><div class="text-lg font-bold text-emerald-700"><?= $totalMembers ?></div><div class="text-[10px] text-emerald-600">Total Active</div></div>
                        <div class="bg-blue-50 p-3 rounded-xl"><div class="text-lg font-bold text-blue-700"><?= $genderCounts['male'] ?></div><div class="text-[10px] text-blue-600">Male</div></div>
                        <div class="bg-pink-50 p-3 rounded-xl"><div class="text-lg font-bold text-pink-700"><?= $genderCounts['female'] ?></div><div class="text-[10px] text-pink-600">Female</div></div>
                        <div class="bg-amber-50 p-3 rounded-xl"><div class="text-lg font-bold text-amber-700"><?= $memberTypeWaiting ?></div><div class="text-[10px] text-amber-600">Waiting</div></div>
                    </div>
                    <div class="grid grid-cols-3 gap-3 text-center">
                        <div class="bg-sky-50 p-3 rounded-xl"><div class="text-lg font-bold text-sky-700"><?= $sectionCounts['7_13'] ?></div><div class="text-[10px] text-sky-600 amharic-text">ህጻናት (A)</div></div>
                        <div class="bg-amber-50 p-3 rounded-xl"><div class="text-lg font-bold text-amber-700"><?= $sectionCounts['14_17'] ?></div><div class="text-[10px] text-amber-600 amharic-text">ማዕከላዊያን (B)</div></div>
                        <div class="bg-rose-50 p-3 rounded-xl"><div class="text-lg font-bold text-rose-700"><?= $sectionCounts['18_plus'] ?></div><div class="text-[10px] text-rose-600 amharic-text">ወጣቶች (C)</div></div>
                    </div>
                </div>
            </section>

            <!-- SETTINGS -->
            <section id="section-settings" class="content-section">

                <!-- Settings Header -->
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                    <div>
                        <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                            <span class="w-9 h-9 rounded-2xl bg-slate-100 flex items-center justify-center"><i class="fa-solid fa-gear text-slate-600"></i></span>
                            Settings
                        </h2>
                        <p class="text-xs text-slate-500 amharic-text mt-1">የክፍል ቅንብሮች</p>
                    </div>
                </div>

                <!-- Settings Sub-Tabs -->
                <div class="flex gap-2 mb-5 overflow-x-auto hide-scrollbar pb-1">
                    <button onclick="showSettingsTab(this,'stProfile')" class="stab stab-on"><i class="fa-solid fa-user mr-1"></i>My Profile</button>
                    <button onclick="showSettingsTab(this,'stDept')" class="stab"><i class="fa-solid fa-building mr-1"></i>Department</button>
                    <button onclick="showSettingsTab(this,'stPrefs')" class="stab"><i class="fa-solid fa-sliders mr-1"></i>Preferences</button>
                    <button onclick="showSettingsTab(this,'stSystem')" class="stab"><i class="fa-solid fa-server mr-1"></i>System</button>
                </div>

                <!-- ===== COMMON ACCOUNT PAGE ===== -->
                <div id="stProfile" class="settings-pane">
                    <div class="panel p-6 text-center">
                        <div class="w-16 h-16 rounded-full bg-gradient-to-br from-emerald-400 to-teal-600 flex items-center justify-center mx-auto mb-4 shadow-lg">
                            <i class="fa-solid fa-user-shield text-2xl text-white" aria-hidden="true"></i>
                        </div>
                        <h3 class="font-bold text-slate-800 text-lg mb-2">Profile &amp; account security</h3>
                        <p class="text-sm text-slate-500 max-w-xl mx-auto mb-5">Manage your name, email, username, private profile image, and password in the shared account center.</p>
                        <a href="<?= e(ssms_app_url('admin/account.php')) ?>" class="inline-flex items-center gap-2 px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-semibold hover:bg-emerald-700 transition focus:outline-none focus:ring-2 focus:ring-emerald-400 focus:ring-offset-2">
                            <i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> Open My Profile
                        </a>
                    </div>
                </div>

                <!-- ===== DEPARTMENT INFO ===== -->
                <div id="stDept" class="settings-pane" style="display:none">
                    <div class="panel p-5">
                        <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-building text-blue-500"></i> Department & Church Information</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Name (English)</label>
                                <input type="text" id="deptNameEn" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="Information Department">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Name (Amharic)</label>
                                <input type="text" id="deptNameAm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm amharic-text focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="ማብራሪያ ክፍል">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Church/School Name (English)</label>
                                <input type="text" id="churchNameEn" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="<?= SCHOOL_TRANSLATION_EN ?> <?= SCHOOL_TYPE ?>">
                            </div>
                            <div>
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Church/School Name (Amharic)</label>
                                <input type="text" id="churchNameAm" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm amharic-text focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="<?= SCHOOL_NAME_AMHARIC ?>">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Department Description</label>
                                <textarea id="deptDesc" rows="3" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-blue-200 focus:border-blue-400" placeholder="Brief description of the department..."></textarea>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button onclick="saveDeptSettings()" class="px-5 py-2.5 bg-blue-600 text-white rounded-xl text-xs font-semibold hover:bg-blue-700 transition flex items-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Department Info
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ===== SYSTEM PREFERENCES ===== -->
                <div id="stPrefs" class="settings-pane" style="display:none">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- Member Code Settings -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-barcode text-violet-500"></i> Member Code Format</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Code Prefix</label>
                                    <input type="text" id="codePrefix" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-violet-200 focus:border-violet-400" placeholder="e.g. WB (leave empty for no prefix)">
                                    <p class="text-[10px] text-slate-400 mt-1">Result: <span id="codePreview" class="font-mono font-bold text-violet-600">0001</span></p>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Number of Digits</label>
                                    <select id="codeDigits" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-violet-200 focus:border-violet-400" onchange="updateCodePreview()">
                                        <option value="3">3 digits (001)</option>
                                        <option value="4" selected>4 digits (0001)</option>
                                        <option value="5">5 digits (00001)</option>
                                        <option value="6">6 digits (000001)</option>
                                    </select>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="autoGenCode" class="sr-only peer" checked>
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-violet-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-violet-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Auto-generate member codes on registration</span>
                                </div>
                            </div>
                        </div>

                        <!-- Default Values -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-list-check text-teal-500"></i> Registration Defaults</h4>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Age Group</label>
                                    <select id="defAgeGroup" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="">None (manual selection)</option>
                                        <?php foreach (\App\Services\MemberCategory::sections() as $infoCode => $infoSec): ?><option value="<?= $infoCode ?>"><?= e($infoSec['am']) ?> (<?= e($infoSec['ages']) ?>)</option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Member Type</label>
                                    <select id="defMemType" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="regular">Regular</option>
                                        <option value="honorary">Honorary</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Default Registration Type</label>
                                    <select id="defRegType" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-teal-200 focus:border-teal-400">
                                        <option value="direct">Direct</option>
                                        <option value="transfer">Transfer</option>
                                        <option value="waiting">Waiting</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Validation Rules -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-shield-check text-rose-500"></i> Validation Rules</h4>
                            <div class="space-y-3">
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="phoneRequired" class="sr-only peer">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-rose-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Require phone number on registration</span>
                                </div>
                                <div class="flex items-center gap-3">
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" id="idAutoGen" class="sr-only peer">
                                        <div class="w-9 h-5 bg-slate-200 peer-focus:ring-2 peer-focus:ring-rose-200 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-rose-500"></div>
                                    </label>
                                    <span class="text-xs text-slate-600">Auto-generate ID cards on registration</span>
                                </div>
                                <div>
                                    <label class="block text-[10px] font-semibold text-slate-500 mb-1 uppercase">Guardian Required Under Age</label>
                                    <select id="guardianAge" class="w-full px-3 py-2.5 rounded-xl border border-slate-200 text-sm focus:ring-2 focus:ring-rose-200 focus:border-rose-400">
                                        <option value="0">Not required</option>
                                        <option value="10">Under 10</option>
                                        <option value="14">Under 14</option>
                                        <option value="16">Under 16</option>
                                        <option value="18">Under 18 (all minors)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Save Prefs Button -->
                        <div class="panel p-5 flex items-center justify-between">
                            <div>
                                <h4 class="font-semibold text-slate-700 flex items-center gap-2"><i class="fa-solid fa-floppy-disk text-emerald-500"></i> Save All Preferences</h4>
                                <p class="text-[10px] text-slate-400 mt-1">Click to save member code format, defaults, and validation rules</p>
                            </div>
                            <button onclick="savePreferences()" class="px-5 py-2.5 bg-emerald-600 text-white rounded-xl text-xs font-semibold hover:bg-emerald-700 transition flex items-center gap-2">
                                <i class="fa-solid fa-save"></i> Save Preferences
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ===== SYSTEM / DATA MANAGEMENT ===== -->
                <div id="stSystem" class="settings-pane" style="display:none">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <!-- System Stats -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-chart-simple text-indigo-500"></i> System Statistics</h4>
                            <div id="sysStats" class="space-y-3">
                                <div class="text-center py-4"><div class="inline-block w-5 h-5 border-2 border-slate-200 border-t-emerald-500 rounded-full animate-spin"></div><p class="text-xs text-slate-400 mt-2">Loading...</p></div>
                            </div>
                        </div>

                        <!-- Data Management -->
                        <div class="panel p-5">
                            <h4 class="font-semibold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-database text-cyan-500"></i> Data Management</h4>
                            <div class="space-y-3">
                                <a href="/admin/export_pdf.php?format=csv&filter=all" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-emerald-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-emerald-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-file-csv text-emerald-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Export All Members (CSV)</div><div class="text-[10px] text-slate-400">Download complete member database</div></div>
                                </a>
                                <a href="/admin/export_pdf.php?format=pdf&filter=all" target="_blank" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-red-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-red-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-file-pdf text-red-500"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Full PDF Report</div><div class="text-[10px] text-slate-400">Print-ready member list</div></div>
                                </a>
                                <div onclick="clearCache()" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-amber-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-amber-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-broom text-amber-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Clear Cache</div><div class="text-[10px] text-slate-400">Remove temporary files and rate limit data</div></div>
                                </div>
                                <a href="/admin/system_health.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-blue-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-blue-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-stethoscope text-blue-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">System Health Check</div><div class="text-[10px] text-slate-400">Database diagnostics & auto-fix</div></div>
                                </a>
                                <a href="/admin/reports.php" class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 hover:bg-violet-50 transition cursor-pointer">
                                    <div class="w-9 h-9 bg-violet-100 rounded-lg flex items-center justify-center"><i class="fa-solid fa-chart-line text-violet-600"></i></div>
                                    <div><div class="text-sm font-medium text-slate-700">Advanced Analytics</div><div class="text-[10px] text-slate-400">Charts, reports, filtered exports</div></div>
                                </a>
                            </div>
                        </div>

                        <!-- Recent Activity -->
                        <div class="panel p-5 lg:col-span-2">
                            <h4 class="font-semibold text-slate-700 mb-3 flex items-center gap-2"><i class="fa-solid fa-timeline text-orange-500"></i> Recent Activity Log</h4>
                            <div id="sysActivity" class="text-xs text-slate-400">Loading...</div>
                        </div>
                    </div>
                </div>

            </section>

            <!-- ═══ PROFILE TAB ═══ -->
            <?php
            require_once __DIR__ . '/../components/profile_tab_section.php';
            renderProfileTabSection('section-profile', 'content-section');
            ?>
        </main>

        <!-- Bottom Nav (mobile) -->
        <nav class="md:hidden fixed bottom-0 left-0 right-0 bg-emerald-700 text-white bottom-nav-shadow z-50">
            <div class="flex overflow-x-auto hide-scrollbar px-2 py-2 gap-1">
                <button data-section="dashboard"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target nav-bottom-active">
                    <i class="fa-solid fa-chart-pie text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Dashboard</span>
                </button>
                <button data-section="manage"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-pen-to-square text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Manage</span>
                </button>
                <button data-section="archive"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-box-archive text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Archive</span>
                </button>
                

                <button data-section="attendance"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-clipboard-check text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Attendance</span>
                </button>
                <button data-section="analytics"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-chart-column text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Analytics</span>
                </button>
                <button data-section="reports"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-file-lines text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Reports</span>
                </button>
                <button data-section="settings"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-gear text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Settings</span>
                </button>
                <button data-comm-open="inbox"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80">
                    <i class="fa-solid fa-comments text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Comms</span>
                </button>
                <button data-section="profile"
                        class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80 text-white border-none bg-transparent cursor-pointer">
                    <i class="fa-solid fa-user-gear text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Profile</span>
                </button>
                   class="flex flex-col items-center min-w-[64px] px-2 py-1.5 rounded-xl mobile-touch-target opacity-80 text-white text-decoration-none">
                    <i class="fa-solid fa-user-gear text-base mb-0.5"></i>
                    <span class="text-[10px] whitespace-nowrap">Profile</span>
                </a>
            </div>
        </nav>
    </div>
</div>

<!-- Loading overlay -->
<div id="formLoadingOverlay"
     class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-2xl shadow-lg px-6 py-4 flex items-center gap-3">
        <div class="animate-spin rounded-full h-6 w-6 border-2 border-emerald-500 border-t-transparent"></div>
        <div class="text-sm font-medium text-emerald-700">
            Saving member, please wait…
        </div>
    </div>
</div>

<!-- Manage sheet -->
<div id="manageSheet" class="sheet">
    <div class="sheet-body flex flex-col h-full bg-slate-50">
        <!-- Header -->
        <div class="flex items-center justify-between px-6 py-4 bg-white border-b border-slate-200 sticky top-0 z-10">
            <div>
                <div class="label-soft">Manage Member</div>
                <div class="text-lg font-bold text-slate-800" id="manageSheetTitle">Member Details</div>
            </div>
            <button class="w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 flex items-center justify-center text-slate-500 transition" onclick="closeManageSheet()">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
        </div>
        
        <!-- Content -->
        <div id="manageSheetContent" class="flex-1 overflow-y-auto p-6">
            Loading...
        </div>
    </div>
</div>

<script>
    const emerald = '#16a34a';
    const emeraldLight = '#22c55e';
    const gold = '#facc15';
    const sky = '#0ea5e9';
    const rose = '#f97373';

    // Simple section navigation (sidebar + bottom nav)
    function navigateToSection(name) {
        // Preserve old links while using the single server-paged directory.
        if (name === 'members') name = 'manage';
        closeManageSheet(); // Close any open modal
        // Retired/unknown sections (old bookmarks) fall back to the dashboard.
        if (!document.getElementById('section-' + name)) name = 'dashboard';
        document.querySelectorAll('.content-section').forEach(sec => sec.classList.remove('active'));
        const target = document.getElementById('section-' + name);
        if (target) target.classList.add('active');
        if (name === 'manage') {
            if (typeof loadManageMembers === 'function') loadManageMembers();
        }
        if (name === 'analytics') {
            if (window.AttendanceHub && typeof window.AttendanceHub.init === 'function') {
                window.AttendanceHub.init();
            }
        }
        if (name === 'profile' && typeof window.loadTabProfile === 'function') {
            window.loadTabProfile();
        }

        // Update URL so refresh stays on this section
        const url = new URL(window.location);
        url.searchParams.set('section', name);
        history.replaceState(null, '', url);

        // Highlight desktop pills
        document.querySelectorAll('aside [data-section]').forEach(btn => {
            btn.classList.remove('nav-pill-active', 'bg-white/20');
        });
        document.querySelectorAll('aside [data-section="' + name + '"]').forEach(btn => {
            btn.classList.add('nav-pill-active', 'bg-white/20');
        });

        // Highlight bottom nav
        document.querySelectorAll('nav [data-section]').forEach(btn => {
            btn.classList.remove('opacity-100');
            btn.classList.add('opacity-80');
        });
        document.querySelectorAll('nav [data-section="' + name + '"]').forEach(btn => {
            btn.classList.remove('opacity-80');
            btn.classList.add('opacity-100');
        });
    }

    window.navigateToSection = navigateToSection;
    window.switchTab = navigateToSection;
    window.nav = navigateToSection;
    window.switchSection = navigateToSection;
    window.showSection = navigateToSection;

    document.querySelectorAll('[data-section]').forEach(el => {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            const name = this.getAttribute('data-section');
            if (name) navigateToSection(name);
        });
    });

    // Initialize default section
    const urlParams = new URLSearchParams(window.location.search);
    const sectionParam = urlParams.get('section');
    if (sectionParam) {
        navigateToSection(sectionParam);
    } else {
        navigateToSection('dashboard');
    }
    const memberParam = parseInt(urlParams.get('member') || '0', 10);
    if (memberParam > 0 && typeof openManageSheet === 'function') {
        openManageSheet(memberParam);
        const cleanUrl = new URL(window.location); cleanUrl.searchParams.delete('member'); history.replaceState(null, '', cleanUrl);
    }

    // Charts
    document.addEventListener('DOMContentLoaded', () => {
        const sectionCtx = document.getElementById('sectionChart').getContext('2d');
        new Chart(sectionCtx, {
            type: 'bar',
            data: {
                labels: ['ህጻናት (A)', 'ማዕከላዊያን (B)', 'ወጣቶች (C)'],
                datasets: [{
                    data: [
                        <?= (int)$sectionCounts['7_13'] ?>,
                        <?= (int)$sectionCounts['14_17'] ?>,
                        <?= (int)$sectionCounts['18_plus'] ?>
                    ],
                    backgroundColor: [
                        'rgba(22,163,74,0.9)',
                        'rgba(34,197,94,0.9)',
                        'rgba(250,204,21,0.9)',
                        'rgba(248,113,113,0.9)'
                    ],
                    borderRadius: 12,
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {legend: {display: false}},
                scales: {
                    x: {
                        grid: {display: false},
                        ticks: {color: '#64748b', font: {size: 10}}
                    },
                    y: {
                        grid: {color: '#e2e8f0'},
                        ticks: {color: '#64748b', font: {size: 10}, precision: 0}
                    }
                }
            }
        });

        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'bar',
            data: {
                labels: ['Male', 'Female'],
                datasets: [{
                    data: [<?= (int)$genderCounts['male'] ?>, <?= (int)$genderCounts['female'] ?>],
                    backgroundColor: ['rgba(59,130,246,0.9)', 'rgba(244,63,94,0.9)'],
                    borderRadius: 12,
                    borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {legend: {display: false}},
                scales: {
                    x: {
                        grid: {color: '#e2e8f0'},
                        ticks: {color: '#64748b', font: {size: 10}, precision: 0}
                    },
                    y: {
                        grid: {display: false},
                        ticks: {color: '#64748b', font: {size: 10}}
                    }
                }
            }
        });
    });


    // Manage sheet (edit)
    function openManageSheet(id) {
        const sheet = document.getElementById('manageSheet');
        const content = document.getElementById('manageSheetContent');
        const title = document.getElementById('manageSheetTitle');
        if (!sheet || !content) return;

        sheet.classList.add('open');
        content.innerHTML = 'Loading...';

        const cacheBust = Date.now();
        fetch('/admin/info_manage_member.php?id=' + encodeURIComponent(id) + '&embed=1&v=' + cacheBust)
            .then(res => res.text())
            .then(html => {
                content.innerHTML = html;
                if (window.MemberNav) MemberNav.afterLoad(id);
                if (window.DeptStatus) DeptStatus.mount(content);
                if (title) title.textContent = 'Member #' + id;
                // Default to Preview tab
                switchManageTab('preview');
            })
            .catch(() => {
                content.innerHTML = '<div class="text-red-600 text-sm">Failed to load member.</div>';
            });
    }

    function closeManageSheet() {
        const sheet = document.getElementById('manageSheet');
        if (sheet) sheet.classList.remove('open');
        if (window.MemberNav) MemberNav.reset();
    }

    // Archive action - Show verification modal
    let archiveMemberId = null;
    let archiveMemberName = '';
    
    function archiveMember(id, name) {
        archiveMemberId = id;
        archiveMemberName = name || 'this member';
        
        // Update modal content
        document.getElementById('archiveMemberName').textContent = archiveMemberName;
        document.getElementById('archiveReason').value = '';
        document.getElementById('archiveNotes').value = '';
        document.getElementById('archiveConfirmText').value = '';
        document.getElementById('confirmArchiveBtn').disabled = true;
        
        // Show modal
        document.getElementById('archiveModal').classList.remove('hidden');
        document.getElementById('archiveModal').classList.add('flex');
    }
    
    function closeArchiveModal() {
        document.getElementById('archiveModal').classList.add('hidden');
        document.getElementById('archiveModal').classList.remove('flex');
        archiveMemberId = null;
    }
    
    function checkArchiveConfirmation() {
        const confirmText = document.getElementById('archiveConfirmText').value.trim();
        const reason = document.getElementById('archiveReason').value;
        const btn = document.getElementById('confirmArchiveBtn');
        
        // Must type "ARCHIVE" and select a reason
        if (confirmText === 'ARCHIVE' && reason !== '') {
            btn.disabled = false;
            btn.classList.remove('bg-slate-300', 'cursor-not-allowed');
            btn.classList.add('bg-red-600', 'hover:bg-red-700');
        } else {
            btn.disabled = true;
            btn.classList.add('bg-slate-300', 'cursor-not-allowed');
            btn.classList.remove('bg-red-600', 'hover:bg-red-700');
        }
    }
    
    // Danger confirmation dialog (admin/js/danger_confirm.js) — owner decisions 2026-10-21.
    function dcAsk(o) {
        if (window.DangerConfirm && window.DangerConfirm.ask) return window.DangerConfirm.ask(o);
        var m = 'The confirmation dialog did not load. Reload the page (Ctrl+F5) and try again.';
        if (typeof showToast === 'function') showToast(m, 'error'); else console.error(m);
        return Promise.resolve(false);
    }

    async function confirmArchive() {
        if (!archiveMemberId) return;
        
        const reason = document.getElementById('archiveReason').value;
        const notes = document.getElementById('archiveNotes').value;
        const btn = document.getElementById('confirmArchiveBtn');
        const rsel = document.getElementById('archiveReason');
        const rtxt = rsel && rsel.selectedIndex >= 0 ? rsel.options[rsel.selectedIndex].text : reason;
        const dc = await dcAsk({tier:'medium', title:'Archive ' + archiveMemberName + '?', object: archiveMemberName,
            summary:'The member is moved out of the active lists. Reason: ' + rtxt + '.', intent:'I want to archive this member',
            consequences:['The member disappears from active member lists, class rosters, attendance sheets and new reports.','Teachers and the mobile app no longer show this member.','Family links stay but the member is no longer counted as active.'],
            keeps:['All history (attendance, marks, documents) is kept.','You can restore the member from the Archive list.'],
            phrase:'ARCHIVE MEMBER', confirmLabel:'Archive member'});
        if (!dc) return;
        
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Archiving...';
        
        fetch('/admin/info_archive_member.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                id: archiveMemberId,
                reason: reason,
                notes: notes,
                csrf_token: CSRF_TOKEN
            })
        }).then(r=>r.json()).then(data=>{
            if (data.status === 'success') {
                closeArchiveModal();
                showToast('✓ ' + data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Archive failed.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-box-archive mr-2"></i> Archive Member';
            }
        }).catch(()=>{
            showToast('Network error. Please try again.', 'error');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-box-archive mr-2"></i> Archive Member';
        });
    }
    
    // Restore member from archive
    async function restoreMember(id, name) {
        if (!(await dcAsk({tier:'low', title:'Restore ' + name + ' to active members?', summary:'The member appears again in active lists, rosters and attendance.', confirmLabel:'Restore member'}))) return;
        
        fetch('/admin/info_restore_member.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({id: id, csrf_token: CSRF_TOKEN})
        }).then(r=>r.json()).then(data=>{
            if (data.status === 'success') {
                showToast('✓ ' + data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showToast(data.message || 'Restore failed.', 'error');
            }
        }).catch(()=>{
            showToast('Network error.', 'error');
        });
    }
    
    // Toast notification
    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'fixed top-4 right-4 z-[200] px-6 py-4 rounded-xl shadow-2xl text-white font-semibold text-sm transform transition-all duration-300 translate-x-full';
        toast.style.background = type === 'success' ? 'linear-gradient(135deg, #059669, #10b981)' : 'linear-gradient(135deg, #dc2626, #ef4444)';
        // H3: text only — server messages carry member names (stored XSS).
        toast.textContent = message;
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.remove('translate-x-full'), 100);
        setTimeout(() => {
            toast.classList.add('translate-x-full');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // ---------------------------------------------------------
    // Shared text-escaping helper used by attendance and management views.
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text ?? '');
        return div.innerHTML;
    }
    function escapeAttr(text) {
        return escapeHtml(text).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    // A value passed as a JS string argument inside onclick="...":
    // JSON-encode (valid JS literal), then HTML-attribute-encode.
    function jsArg(v) {
        return JSON.stringify(String(v ?? '')).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // MANAGE SHEET FUNCTIONS (Tabs, Preview, Edit)
    // ---------------------------------------------------------
    function switchManageTab(tab) {
        const previewBtn = document.getElementById('tab-preview');
        const editBtn = document.getElementById('tab-edit');
        const viewPreview = document.getElementById('view-preview');
        const editForm = document.getElementById('view-edit');

        if (!previewBtn || !editBtn || !viewPreview || !editForm) return;

        if (tab === 'preview') {
            previewBtn.classList.add('border-emerald-600', 'text-emerald-700');
            previewBtn.classList.remove('border-transparent', 'text-slate-500');
            editBtn.classList.remove('border-emerald-600', 'text-emerald-700');
            editBtn.classList.add('border-transparent', 'text-slate-500');
            
            viewPreview.classList.remove('hidden');
            viewPreview.style.display = 'block';
            editForm.classList.add('hidden');
            editForm.style.display = 'none';
        } else {
            editBtn.classList.add('border-emerald-600', 'text-emerald-700');
            editBtn.classList.remove('border-transparent', 'text-slate-500');
            previewBtn.classList.remove('border-emerald-600', 'text-emerald-700');
            previewBtn.classList.add('border-transparent', 'text-slate-500');
            
            editForm.classList.remove('hidden');
            editForm.style.display = 'block';
            viewPreview.classList.add('hidden');
            viewPreview.style.display = 'none';
        }
    }

    // In-page Image Viewer Logic
    let viewerScale = 1;
    let viewerRotation = 0;
    let viewerPx = 0, viewerPy = 0;
    let viewerIsDragging = false;
    let viewerStartX, viewerStartY;

    function openDocFullscreen(src) {
        const overlay = document.getElementById('imageViewerOverlay');
        const img = document.getElementById('viewer-img');
        if(!overlay || !img) return;

        // Reset state
        viewerScale = 1;
        viewerRotation = 0;
        viewerPx = 0;
        viewerPy = 0;
        
        // Resolve absolute path
        const fullUrl = new URL(src, window.location.href).href;
        img.src = fullUrl;
        updateViewerTransform();

        overlay.classList.remove('hidden');
        overlay.classList.add('flex');
    }

    function closeDocFullscreen() {
        const overlay = document.getElementById('imageViewerOverlay');
        if(overlay) {
            overlay.classList.add('hidden');
            overlay.classList.remove('flex');
        }
    }

    function updateViewerTransform() {
        const img = document.getElementById('viewer-img');
        if(img) {
            img.style.transform = `translate(${viewerPx}px, ${viewerPy}px) scale(${viewerScale}) rotate(${viewerRotation}deg)`;
        }
    }

    function adjustViewerZoom(delta) {
        viewerScale += delta;
        if(viewerScale < 0.1) viewerScale = 0.1;
        if(viewerScale > 5) viewerScale = 5;
        updateViewerTransform();
    }

    function resetViewerView() {
        viewerScale = 1;
        viewerRotation = 0;
        viewerPx = 0;
        viewerPy = 0;
        updateViewerTransform();
    }

    function rotateViewerImg() {
        viewerRotation += 90;
        updateViewerTransform();
    }

    // Setup viewer event listeners once
    document.addEventListener('DOMContentLoaded', () => {
        const container = document.getElementById('img-container');
        if(!container) return;

        // Wheel Zoom
        container.addEventListener('wheel', (e) => {
            e.preventDefault();
            adjustViewerZoom(e.deltaY > 0 ? -0.1 : 0.1);
        }, { passive: false });

        // Drag Panning
        container.addEventListener('mousedown', (e) => {
            viewerIsDragging = true;
            viewerStartX = e.clientX - viewerPx;
            viewerStartY = e.clientY - viewerPy;
            container.style.cursor = 'grabbing';
        });

        window.addEventListener('mousemove', (e) => {
            if(!viewerIsDragging) return;
            e.preventDefault();
            viewerPx = e.clientX - viewerStartX;
            viewerPy = e.clientY - viewerStartY;
            updateViewerTransform();
        });

        window.addEventListener('mouseup', () => {
            viewerIsDragging = false;
            if(container) container.style.cursor = 'grab';
        });
    });

    function submitEditForm(e) {
        e.preventDefault();
        const form = e.target;
        // M9: button-disable alone still allows re-entry (Enter key, second
        // sheet, impatient re-click after .finally re-enables mid-flight in
        // slow networks). One save per form until the round-trip settles.
        if (form.dataset.saving === '1') return;
        form.dataset.saving = '1';
        const formData = new FormData(form);
        formData.append('csrf_token', CSRF_TOKEN);
        const btn = form.querySelector('button[type="submit"]');
        
        if(btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }

        fetch('/admin/info_manage_member.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            // M22: mirror registration — a non-JSON reply (login page after
            // full session loss) means re-login, not 'Network error'.
            const ct = response.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                throw { _type: 'session_expired', message: 'Session expired. The page will reload.' };
            }
            return response.json();
        })
        .then(data => {
            if (data && (data.status === 'session_expired' || data.status === 'csrf_expired' || data.action === 'reload')) {
                alert(data.message || 'Session expired. The page will reload.');
                window.location.reload();
                return;
            }
            if(data.status === 'success') {
                closeManageSheet();
                // M21: no full-page reload — refresh the lists in place so
                // the officer keeps section, scroll position and filters.
                try { if (typeof loadManageMembers === 'function') loadManageMembers(); } catch (e) { console.warn(e); }
                try { if (typeof loadAllMembers === 'function') loadAllMembers(); } catch (e) { console.warn(e); }
                if (typeof showToast === 'function') showToast('Saved successfully!', 'success');
                else alert('Saved successfully!');
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(err => {
            if (err && err._type === 'session_expired') {
                alert(err.message); window.location.reload(); return;
            }
            if (err instanceof SyntaxError) {
                alert('Session may have expired. The page will reload.'); window.location.reload(); return;
            }
            alert('Network error');
            console.error(err);
        })
        .finally(() => {
            form.dataset.saving = '';
            if(btn) {
                btn.disabled = false;
                btn.textContent = 'Save Changes';
            }
        });
    }
</script>

<!-- Archive Verification Modal -->
<div id="archiveModal" class="fixed inset-0 z-[150] bg-slate-900/80 backdrop-blur-sm hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full overflow-hidden animate-in">
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-box-archive text-white text-xl"></i>
                </div>
                <div class="text-white">
                    <h3 class="font-bold text-lg">Archive Member</h3>
                    <p class="text-white/80 text-sm">Move to old members</p>
                </div>
            </div>
        </div>
        
        <!-- Body -->
        <div class="p-6">
            <!-- Warning -->
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 mb-5">
                <div class="flex gap-3">
                    <i class="fa-solid fa-triangle-exclamation text-amber-500 text-lg mt-0.5"></i>
                    <div>
                        <p class="text-amber-800 font-semibold text-sm mb-1">You are about to archive:</p>
                        <p id="archiveMemberName" class="text-amber-900 font-bold text-base">Member Name</p>
                    </div>
                </div>
            </div>
            
            <!-- Reason -->
            <div class="mb-4">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    <i class="fa-solid fa-clipboard-list mr-1 text-slate-400"></i> Reason for archiving *
                </label>
                <select id="archiveReason" onchange="checkArchiveConfirmation()" 
                        class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500">
                    <option value="">-- Select a reason --</option>
                    <option value="left_school">ከትምህርት ቤት ወጥቷል/ች (Left School)</option>
                    <option value="graduated">ተመርቋል/ች (Graduated)</option>
                    <option value="transferred">ወደ ሌላ ቦታ ተዛውሯል/ች (Transferred)</option>
                    <option value="inactive_long">ረጅም ጊዜ አልተገኘም/ች (Long Inactive)</option>
                    <option value="deceased">አርፏል/ች (Deceased)</option>
                    <option value="other">ሌላ (Other)</option>
                </select>
            </div>
            
            <!-- Notes -->
            <div class="mb-5">
                <label class="block text-sm font-semibold text-slate-700 mb-2">
                    <i class="fa-solid fa-note-sticky mr-1 text-slate-400"></i> Additional Notes (Optional)
                </label>
                <textarea id="archiveNotes" rows="2" placeholder="Any additional information..."
                          class="w-full px-4 py-3 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:border-amber-500 resize-none"></textarea>
            </div>
            
            <!-- Confirmation -->
            <div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-5">
                <label class="block text-sm font-semibold text-red-700 mb-2">
                    <i class="fa-solid fa-keyboard mr-1"></i> Type "ARCHIVE" to confirm
                </label>
                <input type="text" id="archiveConfirmText" oninput="checkArchiveConfirmation()" 
                       placeholder="Type ARCHIVE here..."
                       class="w-full px-4 py-3 border-2 border-red-200 rounded-xl text-sm font-mono text-center uppercase tracking-widest focus:ring-2 focus:ring-red-500 focus:border-red-500">
            </div>
        </div>
        
        <!-- Footer -->
        <div class="bg-slate-50 px-6 py-4 flex gap-3">
            <button onclick="closeArchiveModal()" 
                    class="flex-1 px-4 py-3 bg-white border border-slate-200 text-slate-600 font-semibold rounded-xl hover:bg-slate-100 transition">
                <i class="fa-solid fa-times mr-2"></i> Cancel
            </button>
            <button id="confirmArchiveBtn" onclick="confirmArchive()" disabled
                    class="flex-1 px-4 py-3 bg-slate-300 text-white font-semibold rounded-xl cursor-not-allowed transition">
                <i class="fa-solid fa-box-archive mr-2"></i> Archive Member
            </button>
        </div>
    </div>
</div>

<script>
// ============================================================
// ATTENDANCE & STATUS SECTION FUNCTIONS
// ============================================================
const _aa = '/admin/api_attendance_info.php?action=';
const hasChartJs = typeof Chart !== 'undefined';
let _attCharts = {};

function showAttTab(btn, id) {
    document.querySelectorAll('.att-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.atab').forEach(b => b.classList.remove('atab-on'));
    document.getElementById(id).style.display = 'block';
    btn.classList.add('atab-on');
    if (id === 'attOverview') loadAttOverview();
    if (id === 'attRisk') { loadAtRiskMonths().then(loadAtRisk); }
    if (id === 'attDaily' && !document.getElementById('attDailyDate').value) {
        document.getElementById('attDailyDate').value = new Date().toISOString().split('T')[0];
    }
}

function attToast(msg, ok) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;color:#fff;font-size:13px;z-index:var(--z-toast);background:' + (ok !== false ? '#ea580c' : '#dc2626');
    t.innerHTML = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

function attBadge(status) {
    const m = {present:'att-present',absent:'att-absent',late:'att-late',excused:'att-excused'};
    return '<span class="att-badge ' + (m[status]||'att-excused') + '">' + (status||'—') + '</span>';
}

function statusBadge(s) {
    const m = {active:'background:#d1fae5;color:#065f46',warning:'background:#fef3c7;color:#92400e',inactive:'background:#fee2e2;color:#991b1b'};
    return '<span style="display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:600;' + (m[s]||'') + '">' + (s||'—') + '</span>';
}

// --- Overview ---
let _attOvLoaded = false;
function loadAttOverview() {
    if (_attOvLoaded) return;
    fetch(_aa + 'overview', {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') { document.getElementById('attOvLoad').innerHTML = '<p class="text-red-400 text-xs">' + escapeHtml(d.message||'Error') + '</p>'; return; }
        _attOvLoaded = true;
        document.getElementById('attOvLoad').style.display = 'none';
        document.getElementById('attOvContent').style.display = 'block';
        const data = d.data, t = data.today, ms = data.member_status;
        const todayRate = data.total_active > 0 ? Math.round((parseInt(t.present_cnt)||0) / data.total_active * 100) : 0;
        document.getElementById('attKpis').innerHTML = [
            {l:"Today's Present",v:t.present_cnt||0,c:'#16a34a',b:'#d1fae5'},
            {l:"Today's Absent",v:t.absent_cnt||0,c:'#dc2626',b:'#fee2e2'},
            {l:"Today's Late",v:t.late_cnt||0,c:'#d97706',b:'#fef3c7'},
            {l:'Today Rate',v:todayRate+'%',c:'#2563eb',b:'#dbeafe'},
            {l:'Never Attended',v:data.never_attended||0,c:'#7c3aed',b:'#ede9fe'},
            {l:'At Warning',v:ms.warning_cnt||0,c:'#ea580c',b:'#ffedd5'}
        ].map(k => '<div style="background:'+k.b+';padding:12px;border-radius:14px;text-align:center"><div style="font-size:20px;font-weight:700;color:'+k.c+'">'+k.v+'</div><div style="font-size:9px;font-weight:500;color:'+k.c+'80">'+k.l+'</div></div>').join('');

        // Week chart
        if (hasChartJs && data.week_trend.length > 0) {
            const wt = data.week_trend;
            if (_attCharts.week) _attCharts.week.destroy();
            _attCharts.week = new Chart(document.getElementById('attWeekChart').getContext('2d'), {
                type:'bar', data:{labels:wt.map(w=>(window.WBWSCalendar && WBWSCalendar.formatDate) ? WBWSCalendar.formatDate(w.day,'short') : w.day.slice(5)), datasets:[
                    {label:'Present',data:wt.map(w=>w.present_cnt),backgroundColor:'#16a34a',borderRadius:4},
                    {label:'Absent',data:wt.map(w=>w.absent_cnt),backgroundColor:'#ef4444',borderRadius:4}
                ]}, options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:10,font:{size:10}}}},scales:{y:{beginAtZero:true,stacked:true},x:{stacked:true,ticks:{font:{size:9}}}}}
            });
        }
        // Monthly chart
        if (hasChartJs && data.monthly_weeks.length > 0) {
            const mw = data.monthly_weeks;
            if (_attCharts.month) _attCharts.month.destroy();
            _attCharts.month = new Chart(document.getElementById('attMonthChart').getContext('2d'), {
                type:'line', data:{labels:mw.map(w=>'Wk '+w.week_start.slice(5)), datasets:[
                    {label:'Present',data:mw.map(w=>w.present_cnt),borderColor:'#16a34a',backgroundColor:'rgba(22,163,74,.1)',fill:true,tension:.4,pointRadius:4},
                    {label:'Absent',data:mw.map(w=>w.absent_cnt),borderColor:'#ef4444',backgroundColor:'transparent',tension:.4,borderDash:[4,4],pointRadius:3}
                ]}, options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{boxWidth:10,font:{size:10}}}},scales:{y:{beginAtZero:true}}}
            });
        }
        // Top absentees
        const abs = data.top_absentees;
        document.getElementById('attAbsentees').innerHTML = abs.length === 0 ? '<p class="text-xs text-emerald-500"><i class="fa-solid fa-check-circle mr-1"></i>No frequent absentees found!</p>' :
            '<div style="max-height:250px;overflow:auto"><table class="w-full text-xs"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-2 py-1.5 text-left font-semibold text-slate-500">Name</th><th class="px-2 py-1.5 text-left font-semibold text-slate-500">Code</th><th class="px-2 py-1.5 text-center font-semibold text-slate-500">Absent</th><th class="px-2 py-1.5 text-center font-semibold text-slate-500">Rate</th><th class="px-2 py-1.5 text-center font-semibold text-slate-500">Status</th></tr></thead><tbody>' +
            abs.map(a => '<tr class="border-t border-slate-100 hover:bg-orange-50 cursor-pointer" onclick="viewMemberAtt('+a.member_id+')"><td class="px-2 py-1.5 font-medium">'+escapeHtml(a.student_name+' '+a.father_name)+'</td><td class="px-2 py-1.5"><code class="text-[10px] bg-slate-100 px-1 rounded">'+(a.member_code||'—')+'</code></td><td class="px-2 py-1.5 text-center text-red-600 font-bold">'+a.absent_days+'</td><td class="px-2 py-1.5 text-center"><span style="color:'+(a.rate<50?'#dc2626':a.rate<75?'#d97706':'#16a34a')+'">'+a.rate+'%</span></td><td class="px-2 py-1.5 text-center">'+statusBadge(a.member_status)+'</td></tr>').join('') +
            '</tbody></table></div>';
    }).catch(err => { document.getElementById('attOvLoad').innerHTML = '<p class="text-xs text-red-400">Error: '+escapeHtml(err.message)+'</p>'; });
}

// --- Daily Report ---
function loadDailyReport() {
    const date = document.getElementById('attDailyDate').value;
    if (!date) return;
    fetch(_aa + 'daily_report&date=' + date, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') { attToast(d.message||'Error', false); return; }
        const s = d.summary, recs = d.records;
        if (recs.length === 0) {
            document.getElementById('attDailySummary').style.display = 'none';
            document.getElementById('attDailyTable').style.display = 'none';
            document.getElementById('attDailyEmpty').style.display = 'block';
            return;
        }
        document.getElementById('attDailyEmpty').style.display = 'none';
        document.getElementById('attDailySummary').style.display = 'grid';
        document.getElementById('attDailySummary').innerHTML = [
            {l:'Total',v:s.total,c:'#1e293b',b:'#f1f5f9'},{l:'Present',v:s.present,c:'#16a34a',b:'#d1fae5'},
            {l:'Absent',v:s.absent,c:'#dc2626',b:'#fee2e2'},{l:'Late',v:s.late,c:'#d97706',b:'#fef3c7'}
        ].map(k => '<div style="background:'+k.b+';padding:10px;border-radius:12px;text-align:center"><div style="font-size:18px;font-weight:700;color:'+k.c+'">'+k.v+'</div><div style="font-size:9px;color:'+k.c+'80">'+k.l+'</div></div>').join('');
        document.getElementById('attDailyTable').style.display = 'block';
        document.getElementById('attDailyBody').innerHTML = recs.map((r,i) =>
            '<tr class="border-t border-slate-100 hover:bg-slate-50"><td class="px-3 py-2">'+(i+1)+'</td><td class="px-3 py-2 font-medium">'+escapeHtml((r.student_name||'')+' '+(r.father_name||''))+'</td><td class="px-3 py-2"><code class="text-[10px] bg-slate-100 px-1 rounded">'+(r.member_code||'—')+'</code></td><td class="px-3 py-2">'+(r.gender==='male'?'M':'F')+'</td><td class="px-3 py-2">'+attBadge(r.status)+'</td><td class="px-3 py-2 text-slate-400">'+(r.notes||'—')+'</td></tr>'
        ).join('');
    });
}

// --- Member Lookup ---
function searchMemberAtt() {
    const q = document.getElementById('attMemSearch').value.trim();
    if (!q) return;
    fetch(_aa + 'search_members&q=' + encodeURIComponent(q), {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.members.length === 0) { document.getElementById('attMemResults').style.display = 'block'; document.getElementById('attMemResults').innerHTML = '<div class="panel p-4 text-center text-slate-400 text-xs">No members found</div>'; return; }
        document.getElementById('attMemResults').style.display = 'block';
        document.getElementById('attMemDetail').style.display = 'none';
        document.getElementById('attMemResults').innerHTML = '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">' +
            d.members.map(m => '<div class="panel p-3 cursor-pointer hover:shadow-md transition" onclick="viewMemberAtt('+m.id+')"><div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold '+(m.gender==='male'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+'">'+escapeHtml((m.student_name||'?').charAt(0))+'</div><div><div class="text-xs font-semibold text-slate-700">'+escapeHtml(m.student_name+' '+m.father_name)+'</div><div class="text-[10px] text-slate-400">'+(m.member_code||'—')+' · '+statusBadge(m.status)+'</div></div></div></div>').join('') +
            '</div>';
    });
}

function viewMemberAtt(memberId) {
    fetch(_aa + 'member_attendance&member_id=' + memberId, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') { attToast(d.message, false); return; }
        const m = d.member, st = d.stats, recs = d.records;
        // Switch to member tab if not there
        document.querySelectorAll('.att-pane').forEach(p => p.style.display = 'none');
        document.getElementById('attMember').style.display = 'block';
        document.querySelectorAll('.atab').forEach(b => b.classList.remove('atab-on'));
        document.querySelectorAll('.atab')[2].classList.add('atab-on');
        document.getElementById('attMemResults').style.display = 'none';
        document.getElementById('attMemDetail').style.display = 'block';
        document.getElementById('attMemDetail').innerHTML =
            '<div class="panel p-4 mb-3"><div class="flex items-center gap-3 mb-3"><div class="w-12 h-12 rounded-full flex items-center justify-center text-lg font-bold '+(m.gender==='male'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+'">'+escapeHtml((m.student_name||'?').charAt(0))+'</div><div><h4 class="font-bold text-slate-800">'+escapeHtml(m.student_name+' '+m.father_name+(m.grandfather_name?' '+m.grandfather_name:''))+'</h4><div class="text-xs text-slate-400">'+(m.member_code||'—')+' · '+(m.age_group||'')+' · '+(m.phone_number||'No phone')+' · '+statusBadge(m.status)+'</div></div></div>' +
            '<div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-3">' +
            '<div style="background:#f0fdf4;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#16a34a">'+st.present+'</div><div style="font-size:9px;color:#16a34a">Present</div></div>' +
            '<div style="background:#fee2e2;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#dc2626">'+st.absent+'</div><div style="font-size:9px;color:#dc2626">Absent</div></div>' +
            '<div style="background:#fef3c7;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#d97706">'+st.late+'</div><div style="font-size:9px;color:#d97706">Late</div></div>' +
            '<div style="background:#f1f5f9;padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:#1e293b">'+st.total+'</div><div style="font-size:9px;color:#64748b">Total Days</div></div>' +
            '<div style="background:'+(st.rate>=75?'#d1fae5':st.rate>=50?'#fef3c7':'#fee2e2')+';padding:8px;border-radius:10px;text-align:center"><div style="font-size:16px;font-weight:700;color:'+(st.rate>=75?'#065f46':st.rate>=50?'#92400e':'#991b1b')+'">'+st.rate+'%</div><div style="font-size:9px">Rate</div></div></div>' +
            '<button onclick="document.getElementById(\'attMemDetail\').style.display=\'none\';document.getElementById(\'attMemResults\').style.display=\'block\'" class="text-xs text-orange-600 mb-3 hover:underline"><i class="fa-solid fa-arrow-left mr-1"></i>Back to results</button></div>' +
            (recs.length === 0 ? '<div class="panel p-4 text-center text-slate-400 text-xs">No attendance records in the last 90 days</div>' :
            '<div class="panel" style="max-height:350px;overflow:auto"><table class="w-full text-xs"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-3 py-2 text-left font-semibold text-slate-500">Date</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Status</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Check-in</th><th class="px-3 py-2 text-left font-semibold text-slate-500">Notes</th></tr></thead><tbody>' +
            recs.map(r => '<tr class="border-t border-slate-100"><td class="px-3 py-2">'+((typeof fmtDate === 'function') ? fmtDate(r.attendance_date) : r.attendance_date)+'</td><td class="px-3 py-2">'+attBadge(r.status)+'</td><td class="px-3 py-2 text-slate-400">'+(r.check_in_time||'—')+'</td><td class="px-3 py-2 text-slate-400">'+(r.notes||'—')+'</td></tr>').join('') +
            '</tbody></table></div>');
    });
}

// --- At-Risk ---
// Attendance alerting Phase 4: reads attendance_month_rollup through api_attendance_info.php
// (department + Ethiopian month; threshold / min sessions come from the attendance policy).
function loadAtRiskMonths() {
    const dept = (document.getElementById('attRiskDept') || {}).value || 'edu';
    const sel = document.getElementById('attRiskMonth');
    if (!sel) return Promise.resolve();
    return fetch('/admin/api_attendance_monthly.php?action=months&department=' + encodeURIComponent(dept), {credentials:'same-origin', headers:{'Accept':'application/json'}})
        .then(r => r.json()).then(d => {
            const cur = sel.value;
            const months = (d && d.status === 'success' && d.available && Array.isArray(d.months)) ? d.months : [];
            sel.innerHTML = months.length === 0 ? '<option value="">Current month</option>' :
                months.map(m => '<option value="' + m.ec_year + '-' + m.ec_month + '">' + escapeHtml(m.label) + ' · ' + escapeHtml(m.label_am) + (m.is_current ? ' (current)' : '') + '</option>').join('');
            if (cur && Array.from(sel.options).some(o => o.value === cur)) sel.value = cur;
        }).catch(() => {});
}
function loadAtRisk() {
    const dept = (document.getElementById('attRiskDept') || {}).value || 'edu';
    const mv = ((document.getElementById('attRiskMonth') || {}).value || '').split('-');
    const q = 'at_risk_members&department=' + encodeURIComponent(dept) + (mv.length === 2 ? '&ec_year=' + mv[0] + '&ec_month=' + mv[1] : '');
    fetch(_aa + q, {credentials:'same-origin'}).then(r => r.json()).then(d => {
        if (d.status !== 'success') return;
        const link = document.getElementById('attRiskReportLink');
        if (link && d.report_url) link.href = d.report_url;
        const countEl = document.getElementById('attRiskCount'), listEl = document.getElementById('attRiskList');
        if (!d.available) {
            countEl.innerHTML = 'Monthly attendance is not available yet (feature flag / sql/074).';
            listEl.innerHTML = '';
            return;
        }
        const ms = d.members || [];
        countEl.innerHTML = '<span class="font-semibold text-red-600">' + ms.length + '</span> ' + escapeHtml(d.department_label) + ' members below ' + d.threshold + '% in ' + escapeHtml(d.month_label_am) + ' (' + escapeHtml(d.month_label) + ') · at least ' + d.min_sessions + ' sessions · rate = (present + excused + 0.5 × late) ÷ sessions';
        listEl.innerHTML = ms.length === 0 ? '<div class="text-center py-8 text-emerald-500 text-xs"><i class="fa-solid fa-check-circle text-2xl mb-2"></i><p>No member is below the threshold this month.</p></div>' :
            '<table class="w-full text-xs"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-2 py-1.5 text-left">Name</th><th class="px-2 py-1.5">Code</th><th class="px-2 py-1.5">' + (dept === 'edu' ? 'Class' : 'Section') + '</th><th class="px-2 py-1.5">Sessions</th><th class="px-2 py-1.5">Present</th><th class="px-2 py-1.5">Absent</th><th class="px-2 py-1.5">Rate</th><th class="px-2 py-1.5">Warnings</th><th class="px-2 py-1.5">Status</th><th class="px-2 py-1.5">Action</th></tr></thead><tbody>' +
            ms.map(m => '<tr class="border-t border-slate-100 hover:bg-red-50"><td class="px-2 py-1.5 font-medium">'+escapeHtml(m.student_name+' '+m.father_name)+'</td><td class="px-2 py-1.5"><code class="text-[10px] bg-slate-100 px-1 rounded">'+(m.member_code||'—')+'</code></td><td class="px-2 py-1.5 text-center">'+escapeHtml(m.group||'—')+'</td><td class="px-2 py-1.5 text-center">'+(m.sessions||0)+'</td><td class="px-2 py-1.5 text-center text-emerald-600">'+(m.present_days||0)+'</td><td class="px-2 py-1.5 text-center text-red-600 font-bold">'+(m.absent_days||0)+'</td><td class="px-2 py-1.5 text-center" style="color:'+(m.band==='low'?'#dc2626':'#d97706')+'">'+(m.rate==null?'—':m.rate+'%')+'</td><td class="px-2 py-1.5 text-center">'+(m.open_warnings>0?'<a class="text-orange-600 hover:underline" href="/admin/attendance_warnings.php?department='+dept+'&member_id='+m.id+'">'+m.open_warnings+' open</a>':'—')+'</td><td class="px-2 py-1.5 text-center">'+statusBadge(m.status)+(m.department_status==='inactive'?' <span class="text-[10px] text-slate-400">(inactive in dept.)</span>':'')+'</td><td class="px-2 py-1.5 text-center"><button onclick="quickStatusChange('+parseInt(m.id,10)+','+jsArg(m.student_name)+','+jsArg(m.status)+')" class="text-[10px] px-2 py-1 bg-amber-100 text-amber-700 rounded-lg hover:bg-amber-200"><i class="fa-solid fa-pen"></i></button></td></tr>').join('') +
            '</tbody></table>';
    });
}

// --- Status Management ---
function searchForStatus() {
    const q = document.getElementById('attStatusSearch').value.trim();
    if (!q) return;
    fetch(_aa + 'search_members&q=' + encodeURIComponent(q), {credentials:'same-origin'}).then(r => r.json()).then(d => {
        document.getElementById('attStatusResults').innerHTML = d.members.length === 0 ? '<p class="text-xs text-slate-400 text-center py-4">No members found</p>' :
            '<div class="space-y-2">' + d.members.map(m =>
                '<div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 hover:bg-slate-100 transition">' +
                '<div class="flex items-center gap-2"><div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold '+(m.gender==='male'?'bg-blue-100 text-blue-600':'bg-pink-100 text-pink-600')+'">'+escapeHtml((m.student_name||'?').charAt(0))+'</div>' +
                '<div><div class="text-xs font-semibold text-slate-700">'+escapeHtml(m.student_name+' '+m.father_name)+'</div><div class="text-[10px] text-slate-400">'+(m.member_code||'—')+' · '+(m.age_group||'')+'</div></div></div>' +
                '<div class="flex items-center gap-2">'+statusBadge(m.status)+
                '<button onclick="quickStatusChange('+parseInt(m.id,10)+','+jsArg(m.student_name+' '+m.father_name)+','+jsArg(m.status)+')" class="px-3 py-1.5 bg-violet-100 text-violet-700 rounded-lg text-[10px] font-semibold hover:bg-violet-200"><i class="fa-solid fa-pen mr-1"></i>Change</button></div></div>'
            ).join('') + '</div>';
    });
}

async function quickStatusChange(memberId, name, currentStatus) {
    // Manual status changes: active <-> inactive only (HR / Information). The
    // server enforces the same rule; 'warning' is no longer set by hand.
    const opts = ['active','inactive'].filter(s => s !== currentStatus);
    let newStatus = opts.length === 1 ? opts[0] : null;
    const toInactive = newStatus === 'inactive';
    const dc = await dcAsk(newStatus === 'active'
        ? {tier:'low', title:'Make ' + name + ' active again?', summary:'Current status: ' + currentStatus + '. The member is counted as active in attendance and reports again.',
           reason:{label:'Reason for the change', required:false, maxLength:500}, confirmLabel:'Make active'}
        : {tier:'medium', title: toInactive ? 'Deactivate ' + name + '?' : 'Change the status of ' + name + '?', object: name,
           summary:'Current status: ' + currentStatus + '.', intent: toInactive ? 'I want to deactivate this member' : 'I want to change this status',
           consequences:['An INACTIVE member is left out of attendance warnings, active counts and class follow-up.','HR and Information see the reason in the member history.'],
           keeps:['The member record and history are kept.','You can make the member active again later.'],
           choice: toInactive ? null : {label:'New status', options:opts.map(o => ({value:o, label:o})), value:opts[0]},
           reason:{label: 'Reason (at least 3 letters)', required:true, minLength:3, maxLength:500},
           phrase: toInactive ? 'DEACTIVATE MEMBER' : 'CHANGE STATUS', confirmLabel: toInactive ? 'Deactivate member' : 'Change status'});
    if (!dc) return;
    if (!newStatus) newStatus = dc.choice;
    const reason = String(dc.reason || '').trim();
    if (!['active','inactive'].includes(newStatus)) { attToast('Invalid status', false); return; }
    if (newStatus === 'inactive' && reason.length < 3) { attToast('A reason is required to deactivate a member', false); return; }
    fetch('/admin/api_attendance_info.php?action=update_status', {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({member_id: memberId, new_status: newStatus, reason: reason, csrf_token: (typeof CSRF_TOKEN !== 'undefined' ? CSRF_TOKEN : '')})
    }).then(r => r.json()).then(d => {
        attToast(d.message, d.status === 'success');
        if (d.status === 'success') { searchForStatus(); _attOvLoaded = false; }
    }).catch(() => attToast('Network error', false));
}

// Auto-load overview when attendance section opens
(function() {
    var ab = document.querySelector('[data-section="attendance"]');
    if (ab) ab.addEventListener('click', function() { setTimeout(loadAttOverview, 200); });
})();

// ============================================================
// SETTINGS SECTION FUNCTIONS
// ============================================================
function showSettingsTab(btn, id) {
    document.querySelectorAll('.settings-pane').forEach(p => p.style.display = 'none');
    document.querySelectorAll('.stab').forEach(b => b.classList.remove('stab-on'));
    document.getElementById(id).style.display = 'block';
    btn.classList.add('stab-on');
    if (id === 'stDept' && !document.getElementById('deptNameEn').value) loadDeptSettings();
    if (id === 'stPrefs' && !document.getElementById('codeDigits')._loaded) loadPreferences();
    if (id === 'stSystem') loadSystemInfo();
}

function settingsToast(msg, ok) {
    const t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;padding:12px 20px;border-radius:12px;color:#fff;font-size:13px;z-index:var(--z-toast);background:' + (ok !== false ? '#16a34a' : '#dc2626');
    t.innerHTML = '<i class="fa-solid fa-' + (ok !== false ? 'check-circle' : 'exclamation-circle') + ' mr-2"></i>' + msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 3000);
}

function sApiPost(action, data) {
    return fetch('/admin/api_settings.php?action=' + action, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    }).then(r => r.json());
}
function sApiGet(action) {
    return fetch('/admin/api_settings.php?action=' + action, { credentials: 'same-origin' }).then(r => r.json());
}

// --- Department ---
function loadDeptSettings() {
    sApiGet('dept_get').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings;
        document.getElementById('deptNameEn').value = s.dept_name_en || '';
        document.getElementById('deptNameAm').value = s.dept_name_am || '';
        document.getElementById('churchNameEn').value = s.church_name_en || '';
        document.getElementById('churchNameAm').value = s.church_name_am || '';
        document.getElementById('deptDesc').value = s.dept_description || '';
    });
}

function saveDeptSettings() {
    sApiPost('dept_save', {
        dept_name_en: document.getElementById('deptNameEn').value, dept_name_am: document.getElementById('deptNameAm').value,
        church_name_en: document.getElementById('churchNameEn').value, church_name_am: document.getElementById('churchNameAm').value,
        dept_description: document.getElementById('deptDesc').value
    }).then(d => settingsToast(d.message, d.status === 'success')).catch(() => settingsToast('Network error', false));
}

// --- Preferences ---
function loadPreferences() {
    document.getElementById('codeDigits')._loaded = true;
    sApiGet('dept_get').then(d => {
        if (d.status !== 'success') return;
        const s = d.settings;
        document.getElementById('codePrefix').value = s.member_code_prefix || '';
        document.getElementById('codeDigits').value = s.member_code_digits || '4';
        document.getElementById('autoGenCode').checked = s.auto_generate_code === '1';
        document.getElementById('defAgeGroup').value = s.default_age_group || '';
        document.getElementById('defMemType').value = s.default_member_type || 'regular';
        document.getElementById('defRegType').value = s.default_registration_type || 'direct';
        document.getElementById('phoneRequired').checked = s.phone_required === '1';
        document.getElementById('idAutoGen').checked = s.id_card_auto_generate === '1';
        document.getElementById('guardianAge').value = s.guardian_required_under || '14';
        updateCodePreview();
    });
}

function updateCodePreview() {
    const prefix = document.getElementById('codePrefix') ? document.getElementById('codePrefix').value : '';
    const digits = parseInt(document.getElementById('codeDigits').value || '4');
    const preview = document.getElementById('codePreview');
    if (preview) preview.textContent = prefix + '1'.padStart(digits, '0');
}

function savePreferences() {
    sApiPost('dept_save', {
        member_code_prefix: document.getElementById('codePrefix').value, member_code_digits: document.getElementById('codeDigits').value,
        auto_generate_code: document.getElementById('autoGenCode').checked ? '1' : '0', default_age_group: document.getElementById('defAgeGroup').value,
        default_member_type: document.getElementById('defMemType').value, default_registration_type: document.getElementById('defRegType').value,
        phone_required: document.getElementById('phoneRequired').checked ? '1' : '0', id_card_auto_generate: document.getElementById('idAutoGen').checked ? '1' : '0',
        guardian_required_under: document.getElementById('guardianAge').value
    }).then(d => settingsToast(d.message, d.status === 'success')).catch(() => settingsToast('Network error', false));
}

// --- System Info ---
function loadSystemInfo() {
    sApiGet('system_info').then(d => {
        if (d.status !== 'success') return;
        const i = d.info, m = i.members, u = i.users, db = i.database, c = i.cache;
        document.getElementById('sysStats').innerHTML =
            '<div class="sys-row"><span class="sys-label">Total Members</span><span class="sys-val">' + (m.total || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Active Members</span><span class="sys-val" style="color:#16a34a">' + (m.active || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Archived</span><span class="sys-val" style="color:#94a3b8">' + (m.archived || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">User Accounts</span><span class="sys-val">' + (u.total || 0) + ' (' + (u.active || 0) + ' active)</span></div>' +
            '<div class="sys-row"><span class="sys-label">Database Size</span><span class="sys-val">' + (db.size_mb || '?') + ' MB</span></div>' +
            '<div class="sys-row"><span class="sys-label">Tables</span><span class="sys-val">' + (i.tables || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Total DB Rows</span><span class="sys-val">' + Number(db.total_rows || 0).toLocaleString() + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Photos</span><span class="sys-val">' + (i.photos || 0) + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Cache</span><span class="sys-val">' + (c.files || 0) + ' files (' + (c.size_kb || 0) + ' KB)</span></div>' +
            '<div class="sys-row"><span class="sys-label">PHP</span><span class="sys-val">' + (i.php_version || '?') + '</span></div>' +
            '<div class="sys-row"><span class="sys-label">Server</span><span class="sys-val" style="font-size:10px">' + escapeHtml(i.server || '?') + '</span></div>';
        const act = i.recent_activity || [];
        document.getElementById('sysActivity').innerHTML = act.length === 0 ? '<p class="text-xs text-slate-400">No recent activity</p>' :
            '<div class="space-y-2">' + act.map(a =>
                '<div class="flex items-center gap-3 p-2 rounded-lg bg-slate-50">' +
                '<div class="w-7 h-7 bg-blue-100 rounded-full flex items-center justify-center"><i class="fa-solid fa-clock-rotate-left text-blue-500 text-[10px]"></i></div>' +
                '<div class="flex-1"><span class="text-xs font-medium text-slate-700">' + escapeHtml(a.username || '') + '</span> <span class="text-[10px] text-slate-400">' + escapeHtml(a.action || '') + '</span></div>' +
                '<span class="text-[10px] text-slate-400">' + (a.created_at ? ((typeof WBWSCalendar!=='undefined'&&WBWSCalendar.formatDateTime)?WBWSCalendar.formatDateTime(String(a.created_at).replace(' ','T')):String(a.created_at)) : '') + '</span></div>'
            ).join('') + '</div>';
    }).catch(() => { document.getElementById('sysStats').innerHTML = '<p class="text-xs text-red-400">Failed to load</p>'; });
}

async function clearCache() {
    if (!(await dcAsk({tier:'low', title:'Clear all cache files?', summary:'Saved report and statistics caches are removed and rebuilt the next time they are needed. Pages may load slower for a moment.', confirmLabel:'Clear cache'}))) return;
    sApiPost('clear_cache', {}).then(d => { settingsToast(d.message, d.status === 'success'); if (d.status === 'success') loadSystemInfo(); }).catch(() => settingsToast('Error', false));
}

/* InfoHub (Analytics Hub) moved to /admin/js/attendance_analytics_hub.js
   (shared with the HR dashboard). window.InfoHub remains an alias of
   window.AttendanceHub for existing handlers. */

/* ════════════════════════════════════════════════════════════════
   InfoReports — read-only PDF report downloads (Phase D)
   Builds a query to the governed api_info_reports.php endpoint.
════════════════════════════════════════════════════════════════ */
const InfoReports = (function () {
    'use strict';

    function toggleFields() {
        const type = document.getElementById('pdfReportType').value;
        document.getElementById('pdfSourceWrap').style.display = (type === 'sections') ? '' : 'none';
        document.getElementById('pdfMemberWrap').style.display = (type === 'member') ? '' : 'none';
    }

    function download() {
        const type = document.getElementById('pdfReportType').value;
        const params = new URLSearchParams();
        params.set('type', type);
        if (type === 'sections') {
            params.set('source', document.getElementById('pdfReportSource').value);
        }
        if (type === 'member') {
            const mid = document.getElementById('pdfMemberId').value;
            if (!mid) { showToast('Pick a member first.', 'error'); return; }
            params.set('member_id', mid);
        }
        const from = document.getElementById('pdfFrom').value;
        const to = document.getElementById('pdfTo').value;
        if (from) params.set('from', from);
        if (to) params.set('to', to);
        // GET download — the endpoint writes nothing, only renders.
        window.open('<?= $ajaxPrefix ?>api_info_reports.php?' + params.toString(), '_blank');
    }

    return { toggleFields: toggleFields, download: download };
})();

window.InfoReports = InfoReports;

// Initialize InfoHub if analytics section is requested or already active
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const sec = urlParams.get('section');
    const anEl = document.getElementById('section-analytics');
    if (sec === 'analytics' || (anEl && anEl.classList.contains('active'))) {
        if (window.AttendanceHub) AttendanceHub.init();
    }
})();

document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    const sec = urlParams.get('section');
    const anEl = document.getElementById('section-analytics');
    if (sec === 'analytics' || (anEl && anEl.classList.contains('active'))) {
        if (window.AttendanceHub) AttendanceHub.init();
    }
});

// Keep the settings preference preview reactive.
(function() {
    if (document.getElementById('codePrefix')) document.getElementById('codePrefix').addEventListener('input', updateCodePreview);
    if (document.getElementById('codeDigits')) document.getElementById('codeDigits').addEventListener('change', updateCodePreview);
})();
</script>

<style>
@keyframes animate-in {
    from { opacity: 0; transform: scale(0.95) translateY(-10px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
.animate-in { animation: animate-in 0.2s ease-out; }
</style>

<!-- Image Viewer Overlay -->
<div id="imageViewerOverlay" class="fixed inset-0 z-[100] bg-slate-900/95 hidden items-center justify-center backdrop-blur-sm">
    <div id="img-container" class="w-full h-full flex items-center justify-center cursor-grab overflow-hidden relative">
        <img id="viewer-img" src="" class="max-w-[90%] max-h-[90%] shadow-2xl transition-transform duration-100 ease-out" draggable="false">
    </div>

    <div class="fixed bottom-8 left-1/2 -translate-x-1/2 bg-slate-800/90 backdrop-blur border border-white/10 rounded-full px-4 py-2 flex items-center gap-4 shadow-xl z-[101]">
        <button onclick="adjustViewerZoom(-0.2)" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Zoom Out">
            <i class="fa-solid fa-minus"></i>
        </button>
        <button onclick="resetViewerView()" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Reset">
            <i class="fa-solid fa-compress"></i>
        </button>
        <button onclick="adjustViewerZoom(0.2)" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Zoom In">
            <i class="fa-solid fa-plus"></i>
        </button>
        <div class="w-px h-6 bg-slate-600"></div>
        <button onclick="rotateViewerImg()" class="w-10 h-10 rounded-full bg-white/5 hover:bg-white/20 text-slate-200 flex items-center justify-center transition" title="Rotate">
            <i class="fa-solid fa-rotate-right"></i>
        </button>
        <button onclick="closeDocFullscreen()" class="w-10 h-10 rounded-full bg-red-500/20 hover:bg-red-500 text-red-200 hover:text-white flex items-center justify-center transition" title="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>
<?php include __DIR__ . '/../components/comm/comm_section.php'; ?>
<script src="/admin/js/member_nav.js?v=<?= SSMS_ASSET_VER ?>"></script>
<script src="/admin/js/department_status.js?v=<?= SSMS_ASSET_VER ?>-20261022r"></script>
<script>
// Explain a permission bounce (access_control.php → index.php → dashboard.php?error=access_denied).
(function () {
    try {
        const u = new URL(window.location.href);
        if (u.searchParams.get('error') === 'access_denied') {
            if (typeof showToast === 'function') showToast('You do not have permission to open that page.', 'error');
            u.searchParams.delete('error');
            history.replaceState(null, '', u.toString());
        }
    } catch (e) { /* decorative */ }
})();
</script>
</body>
</html>
