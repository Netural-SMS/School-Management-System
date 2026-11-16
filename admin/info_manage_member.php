<?php
/**
 * Manage Member - View/Edit Member Details
 * Works with parent page's JavaScript functions
 * IDs must match: tab-preview, tab-edit, view-preview, view-edit
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';
require_once __DIR__ . '/backend/services/MemberFileService.php';
require_once __DIR__ . '/backend/services/MemberImportService.php';

// Helper functions
if (!function_exists('sel')) {
    function sel($current, $value) { return ((string)$current === (string)$value) ? 'selected' : ''; }
}
if (!function_exists('chk')) {
    function chk($val) { return (!empty($val) && (int)$val === 1) ? 'checked' : ''; }
}
if (!function_exists('fixPath')) {
    function fixPath($path) {
        if (empty($path)) return '';
        if (strpos($path, 'http') === 0) return $path;
        $path = ltrim($path, '/');
        if (strpos($path, 'uploads/') === 0) return '/admin/' . $path;
        if (strpos($path, 'admin/') === 0) return '/' . $path;
        return '/' . $path;
    }
}
if (!function_exists('isImage')) {
    function isImage($path) { 
        if (empty($path)) return false; 
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION)); 
        return in_array($ext, ['jpg','jpeg','png','gif','webp','jfif','bmp']); 
    }
}
if (!function_exists('isPDF')) {
    function isPDF($path) { 
        if (empty($path)) return false; 
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf'; 
    }
}
if (!function_exists('memberFileUrl')) {
    function memberFileUrl($memberId, $field) {
        return '/admin/member_file.php?member_id=' . (int)$memberId . '&field=' . rawurlencode($field);
    }
}
if (!function_exists('field')) { 
    function field($n, $d = '') { return isset($_POST[$n]) ? trim($_POST[$n]) : $d; } 
}


// ============================================================
// HANDLE POST REQUEST (Update Member)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Global error handler for this request
    set_error_handler(function($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    
    $newUploadedPaths = [];
    $uploadsCommitted = false;
    try {
        // Validate CSRF token
        if (!validateCsrf()) {
            echo json_encode(['status' => 'error', 'message' => 'Security token expired. Please refresh and try again.']);
            exit;
        }
        if (function_exists('isRateLimited') && isRateLimited('info_manage', 60, 300)) {
            echo json_encode(['status' => 'error', 'message' => 'Too many requests. Please try again later.']);
            exit;
        }
        if (function_exists('recordAttempt')) recordAttempt('info_manage');
        
        if (!isset($conn) || $conn->connect_error) { 
            echo json_encode(['status' => 'error', 'message' => 'Database connection not available']); 
            exit; 
        }

        // Role gating: only roles permitted by access_control may edit members.
        // Info dept is a data-updater (viewer+edit); registration/promotion stay HR-only.
        $allowedEditRoles = ['super_admin', 'school_admin', 'hr_dept', 'info_dept'];
        if (!hasRole($allowedEditRoles)) {
            echo json_encode(['status' => 'error', 'message' => 'You do not have permission to edit members.']);
            http_response_code(403);
            exit;
        }
        
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0; 
        if ($id <= 0) { 
            echo json_encode(['status' => 'error', 'message' => 'Invalid member ID']); 
            exit; 
        }
        
        $stmt = $conn->prepare('SELECT * FROM members WHERE id = ?'); 
        $stmt->bind_param('i', $id); 
        $stmt->execute(); 
        $cur = $stmt->get_result()->fetch_assoc(); 
        $stmt->close(); 
        
        if (!$cur) { 
            echo json_encode(['status' => 'error', 'message' => 'Member not found']); 
            exit; 
        }
        
        // Migration 012 guarantees the education compatibility columns.

    // Get form values
    $registration_type = field('registration_type', $cur['registration_type'] ?? 'waiting');
    $member_type       = field('member_type', $cur['member_type'] ?? 'regular');
    // Member status (members.status) is a manual HR / Information decision
    // (Attendance alerting requirements §4): only those roles may change it,
    // only between active and inactive, and archived members are left alone.
    // Every other role keeps the stored value regardless of what was posted.
    $status            = (string)($cur['status'] ?? 'active');
    $statusChanged     = false;
    if (hasRole(['hr_dept', 'info_dept']) && $status !== 'archived') {
        $postedStatus = field('status', $status);
        if (in_array($postedStatus, ['active', 'inactive'], true) && $postedStatus !== $status) {
            $statusChanged = true;
            $status = $postedStatus;
        }
    }
    $student_name      = field('student_name', $cur['student_name'] ?? '');
    $baptismal_name    = field('baptismal_name', $cur['baptismal_name'] ?? '');
    $father_name       = field('father_name', $cur['father_name'] ?? '');
    $grandfather_name  = field('grandfather_name', $cur['grandfather_name'] ?? '');
    $gender            = field('gender', $cur['gender'] ?? 'male');
    $dob_day           = (int) field('dob_day', $cur['dob_ec_day'] ?? 0);
    $dob_month         = (int) field('dob_month', $cur['dob_ec_month'] ?? 0);
    $dob_year          = (int) field('dob_year', $cur['dob_ec_year'] ?? 0);
    
    // Age (optional) — Auto-age sectioning DISABLED by design
    $age = null;
    if ($dob_year > 0) { 
        $now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa')); 
        $y = (int)ethio_date_format($now, 'Y'); 
        $age = max(0, $y - $dob_year);
    }
    // Keep the Gregorian date_of_birth (used for exact age everywhere) in
    // step with the Ethiopian fields. Only a complete, valid EC date updates
    // it; otherwise the stored value is left unchanged (COALESCE below).
    $dob_gregorian = null;
    if ($dob_day > 0 && $dob_month > 0 && $dob_year > 0) {
        $dobParsed = \App\Services\MemberImportService::parseEcDate(sprintf('%04d-%02d-%02d', $dob_year, $dob_month, $dob_day));
        if (!empty($dobParsed['ok'])) {
            $dob_gregorian = (string)$dobParsed['gregorian'];
            $exactAge = \App\Services\MemberImportService::ageFromGregorian($dob_gregorian);
            if ($exactAge !== null) {
                $age = $exactAge;
            }
        }
    }
    // Respect explicitly provided values; never auto-override
    $age_group       = field('age_group', $cur['age_group'] ?? null) ?: null;
    $current_section = field('current_section', $cur['current_section'] ?? null) ?: null;
    
    $education_level  = field('education_level', $cur['education_level'] ?? '');
    $spiritual_education = field('spiritual_education', $cur['spiritual_education'] ?? '');
    $work_profession  = field('work_profession', $cur['work_profession'] ?? '');
    $city             = field('city', $cur['city'] ?? '');
    $sub_city         = field('sub_city', $cur['sub_city'] ?? '');
    $woreda           = field('woreda', $cur['woreda'] ?? '');
    $mender           = field('mender', $cur['mender'] ?? '');
    $block_number     = field('block_number', $cur['block_number'] ?? '');
    $house_number     = field('house_number', $cur['house_number'] ?? '');
    $phone_number     = field('phone_number', $cur['phone_number'] ?? '');
    $alt_phone_number = field('alt_phone_number', $cur['alt_phone_number'] ?? '');
    $guardian_name    = field('guardian_name', $cur['guardian_name'] ?? '');
    $guardian_phone1  = field('guardian_phone1', $cur['guardian_phone1'] ?? '');
    $guardian_phone2  = field('guardian_phone2', $cur['guardian_phone2'] ?? '');

    // Input length caps (consistent with MemberRegistrationPolicy limits).
    $caps = [
        'student_name' => 100, 'father_name' => 100, 'grandfather_name' => 100,
        'baptismal_name' => 100, 'work_profession' => 100, 'city' => 100,
        'sub_city' => 100, 'woreda' => 100, 'mender' => 100, 'current_section' => 100,
        'guardian_name' => 100, 'education_level' => 50, 'spiritual_education' => 50,
        'phone_number' => 30, 'alt_phone_number' => 30, 'guardian_phone1' => 30,
        'guardian_phone2' => 30, 'block_number' => 20, 'house_number' => 20,
    ];
    foreach ($caps as $fname => $max) {
        $val = ${$fname} ?? '';
        if (is_string($val) && function_exists('mb_strlen') && mb_strlen($val, 'UTF-8') > $max) {
            echo json_encode(['status' => 'error', 'message' => ucfirst(str_replace('_', ' ', $fname)) . ' is too long (max ' . $max . ').']);
            exit;
        }
    }
    

    // File validation/storage is isolated from the UI and database update.
    $storeUpload = static function (string $field) use (&$newUploadedPaths): ?string {
        $result = \App\Services\MemberFileService::storeRequestUpload($field);
        if ($result['error'] !== null) {
            throw new InvalidArgumentException($result['error']);
        }
        if ($result['path'] !== null) {
            $newUploadedPaths[] = $result['path'];
        }
        return $result['path'];
    };
    $student_photo_path      = $storeUpload('student_photo') ?? $cur['student_photo_path'];
    $guardian_photo_path     = $storeUpload('guardian_photo') ?? $cur['guardian_photo_path'];
    $doc_school_records_path = $storeUpload('doc_school_records') ?? $cur['doc_school_records_path'];
    $doc_spiritual_path      = $storeUpload('doc_spiritual') ?? $cur['doc_spiritual_path'];
    $doc_signed_form_path    = $storeUpload('doc_signed_form') ?? $cur['doc_signed_form_path'];

    $full_name_am = trim($student_name . ' ' . $father_name . ' ' . $grandfather_name);

    // Update the migration-backed member schema.
        // Legacy role flags (is_teacher, …) are no longer written here:
        // they are DERIVED from the member's positions right after the
        // update (PositionSyncService), keeping every old consumer in sync.
        $sql = "UPDATE members SET 
            registration_type=?, member_type=?, status=?,
            student_name=?, baptismal_name=?, father_name=?, grandfather_name=?,
            full_name_am=?, gender=?, 
            dob_ec_day=?, dob_ec_month=?, dob_ec_year=?, date_of_birth=COALESCE(?, date_of_birth), age=?, age_group=?, current_section=?,
            education_level=?, spiritual_education=?, work_profession=?,
            city=?, sub_city=?, woreda=?, mender=?, block_number=?, house_number=?,
            phone_number=?, phone_primary=?, alt_phone_number=?,
            guardian_name=?, guardian_phone1=?, guardian_phone2=?, phone_guardian=?,
            student_photo_path=?, guardian_photo_path=?,
            doc_school_records_path=?, doc_spiritual_path=?, doc_signed_form_path=?
            WHERE id=?";
        
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Member update prepare failed: ' . $conn->error);
        }
        $ageVal = $age !== null ? $age : 0;
        $bindValues = [
            $registration_type, $member_type, $status,
            $student_name, $baptismal_name, $father_name, $grandfather_name,
            $full_name_am, $gender,
            $dob_day, $dob_month, $dob_year, $dob_gregorian, $ageVal, $age_group, $current_section,
            $education_level, $spiritual_education, $work_profession,
            $city, $sub_city, $woreda, $mender, $block_number, $house_number,
            $phone_number, $phone_number, $alt_phone_number,
            $guardian_name, $guardian_phone1, $guardian_phone2, $guardian_phone1,
            $student_photo_path, $guardian_photo_path,
            $doc_school_records_path, $doc_spiritual_path, $doc_signed_form_path,
            $id
        ];
        $bindTypes = '';
        foreach ($bindValues as $bv) {
            $bindTypes .= is_int($bv) ? 'i' : 's';
        }
        $stmt->bind_param($bindTypes, ...$bindValues);
    
    $conn->begin_transaction();
    if ($stmt->execute()) { 
        $uploadsCommitted = true;
        // Family Link reverse-sync: if this member has a primary linked
        // guardian and the contact fields were edited here, push the new
        // values into the guardian record so every sibling follows
        // (the guardian row is the single source of truth).
        try {
            $glChanged = trim((string)$guardian_name) !== trim((string)($cur['guardian_name'] ?? ''))
                || trim((string)$guardian_phone1) !== trim((string)($cur['guardian_phone1'] ?? ''))
                || trim((string)$guardian_phone2) !== trim((string)($cur['guardian_phone2'] ?? ''));
            if ($glChanged && trim((string)$guardian_name) !== '') {
                require_once __DIR__ . '/backend/services/GuardianService.php';
                $glStmt = $conn->prepare('SELECT guardian_id FROM member_guardians WHERE member_id = ? AND is_primary = 1 LIMIT 1');
                $glGid = 0;
                if ($glStmt) {
                    $glStmt->bind_param('i', $id);
                    $glStmt->execute();
                    $glRow = $glStmt->get_result()->fetch_assoc();
                    $glStmt->close();
                    $glGid = $glRow ? (int)$glRow['guardian_id'] : 0;
                }
                if ($glGid > 0) {
                    \App\Services\GuardianService::update($conn, $glGid, [
                        'display_name' => $guardian_name,
                        'phone_primary' => $guardian_phone1,
                        'phone_alt' => $guardian_phone2,
                    ]);
                } else {
                    // Guardian details entered for the first time (or member
                    // never linked): match-or-create exactly like registration.
                    \App\Services\GuardianService::attachFromLegacy($conn, (int)$id, 0, 'hr');
                }
            }
        } catch (\Throwable $glError) {
            error_log('Family Link reverse-sync failed for member ' . $id . ': ' . $glError->getMessage());
        }
        foreach ([
            'student_photo_path' => $student_photo_path,
            'guardian_photo_path' => $guardian_photo_path,
            'doc_school_records_path' => $doc_school_records_path,
            'doc_spiritual_path' => $doc_spiritual_path,
            'doc_signed_form_path' => $doc_signed_form_path,
        ] as $column => $newPath) {
            $oldPath = $cur[$column] ?? null;
            if ($newPath && $oldPath && $newPath !== $oldPath) {
                \App\Services\MemberFileService::discard($oldPath);
            }
        }
        // Audit the member update (enterprise accountability).
        if (file_exists(__DIR__ . '/backend/services/SecurityAuditService.php')) {
            require_once __DIR__ . '/backend/services/SecurityAuditService.php';
            \App\Services\SecurityAuditService::record(
                $conn,
                'Member Updated',
                ['profile' => 'full'],
                'member',
                $id
            );
            if ($statusChanged) {
                \App\Services\SecurityAuditService::record(
                    $conn,
                    'Member Status Changed',
                    ['member_id' => (int)$id, 'from' => (string)($cur['status'] ?? ''), 'to' => $status, 'via' => 'edit_form'],
                    'member',
                    $id
                );
            }
        }
        $conn->commit();
        // Identity v2: apply submitted positions, re-code if needed and
        // derive legacy flags without changing the manual member type.
        require_once __DIR__ . '/backend/services/PositionSyncService.php';
        $editPositionIds = [];
        if (isset($_POST['position_ids']) && is_array($_POST['position_ids'])) {
            foreach ($_POST['position_ids'] as $pid) {
                $pid = filter_var($pid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($pid) { $editPositionIds[] = (int)$pid; }
            }
            $editPositionIds = array_values(array_unique($editPositionIds));
            $posRes = \App\Services\PositionSyncService::applyPositions(
                $conn, $id, $editPositionIds, (int)($_SESSION['admin_id'] ?? 0)
            );
            if (($posRes['status'] ?? '') !== 'success') {
                error_log('Member edit position sync failed for #' . $id);
            }
        }
        echo json_encode(['status' => 'success', 'message' => 'Member updated successfully']); 
    } else { 
        $conn->rollback();
        foreach ($newUploadedPaths as $uploadedPath) \App\Services\MemberFileService::discard($uploadedPath);
        reportInternalError('Member update execution failed', $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Unable to update the member.']);
    }
    $stmt->close();
    
    } catch (InvalidArgumentException $e) {
        try { $conn->rollback(); } catch (Throwable $r) {}
        foreach ($newUploadedPaths as $uploadedPath) \App\Services\MemberFileService::discard($uploadedPath);
        $uploadMessage = $e->getMessage();
        echo json_encode(['status' => 'error', 'message' => 'Upload error: ' . $uploadMessage]);
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $r) {}
        if (!$uploadsCommitted) {
            foreach ($newUploadedPaths as $uploadedPath) \App\Services\MemberFileService::discard($uploadedPath);
        }
        reportInternalError('Member update failed', $e);
        echo json_encode(['status' => 'error', 'message' => 'Unable to update the member.']);
    }
    exit;
}

// ============================================================
// GET REQUEST - Display Member Details
// ============================================================
$memberId = isset($_GET['id']) ? (int)$_GET['id'] : 0; 
if ($memberId <= 0) die('<div style="padding:20px;color:#dc2626;">Invalid member ID</div>');

$stmt = $conn->prepare('SELECT * FROM members WHERE id = ?'); 
$stmt->bind_param('i', $memberId); 
$stmt->execute(); 
$m = $stmt->get_result()->fetch_assoc(); 
$stmt->close(); 

if (!$m) die('<div style="padding:20px;color:#dc2626;">Member not found</div>');

// Ensure optional columns have safe defaults (in case column doesn't exist in DB yet)
$m['spiritual_education'] = $m['spiritual_education'] ?? '';
$m['age_group'] = $m['age_group'] ?? '';

// JSON mode for AJAX consumers (e.g. upgrade modal prefill)
if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'member' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}

// Ethiopian month names
$ethMonths = [1=>'መስከረም',2=>'ጥቅምት',3=>'ኅዳር',4=>'ታኅሣሥ',5=>'ጥር',6=>'የካቲት',7=>'መጋቢት',8=>'ሚያዝያ',9=>'ግንቦት',10=>'ሰኔ',11=>'ሐምሌ',12=>'ነሐሴ',13=>'ጳጉሜ'];
$dobDisplay = '';
if ($m['dob_ec_day'] && $m['dob_ec_month'] && $m['dob_ec_year']) {
    $dobDisplay = $m['dob_ec_day'] . ' ' . ($ethMonths[(int)$m['dob_ec_month']] ?? '') . ' ' . $m['dob_ec_year'] . ' ዓ.ም';
}

$now = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
$printDate = ethio_date_format($now, 'F j, Y');
$fullName = trim($m['student_name'] . ' ' . $m['father_name'] . ' ' . $m['grandfather_name']);
?>

<style>
/* ============================================================
   FKSS Member Profile — modern card-based layout
   IDs preserved for JS compatibility: tab-preview, tab-edit,
   view-preview, view-edit, editMemberForm, mmBackBtn.
   ============================================================ */
.mm-wrap {
    font-family: 'Segoe UI', system-ui, -apple-system, 'Noto Sans Ethiopic', sans-serif;
    background: #f5f7fb;
    color: #0f172a;
    line-height: 1.5;
    font-size: 14px;
    min-height: 100%;
}
* { box-sizing: border-box; }

/* ── HERO HEADER ─────────────────────────────────────────── */
.mm-hero{
    position:relative;
    background:
      radial-gradient(1200px 200px at 10% -20%, rgba(52,211,153,.35), transparent 60%),
      radial-gradient(900px 260px at 90% -30%, rgba(96,165,250,.25), transparent 60%),
      linear-gradient(135deg,#064e3b 0%,#065f46 35%,#047857 70%,#059669 100%);
    color:#fff;
    padding:22px 22px 18px;
    border-radius:0;
}
.mm-hero::after{
    content:'';position:absolute;left:0;right:0;bottom:-1px;height:6px;
    background:linear-gradient(90deg,#34d399 0%,#fbbf24 50%,#f472b6 100%);
}
.mm-hero-row{display:flex;gap:18px;align-items:flex-start;flex-wrap:wrap;}
.mm-photo-stack{display:flex;gap:10px;flex-shrink:0;}
.mm-photo{
    width:96px;height:120px;border-radius:12px;background:rgba(255,255,255,.12);
    border:3px solid rgba(255,255,255,.35);overflow:hidden;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:transform .2s, box-shadow .2s; position:relative;
}
.mm-photo.guard{width:64px;height:80px;align-self:flex-end;border-radius:10px;}
.mm-photo:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,0,0,.25);border-color:#fff;}
.mm-photo img{width:100%;height:100%;object-fit:cover;}
.mm-photo i{font-size:34px;color:rgba(255,255,255,.5);}
.mm-photo-label{
    position:absolute;bottom:6px;left:6px;font-size:9px;font-weight:700;
    padding:2px 7px;border-radius:999px;background:rgba(0,0,0,.45);color:#fff;
    letter-spacing:.06em;text-transform:uppercase;backdrop-filter:blur(4px);
}
.mm-hero-info{flex:1;min-width:240px;}
.mm-back-btn{
    display:inline-flex;align-items:center;gap:6px;
    background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.28);
    border-radius:999px;padding:4px 11px;font-size:11px;font-weight:600;cursor:pointer;
    margin-bottom:8px;transition:background .15s;
}
.mm-back-btn:hover{background:rgba(255,255,255,.25);}
.mm-name{font-size:22px;font-weight:800;letter-spacing:-.01em;line-height:1.25;margin-bottom:6px;}
.mm-sub{
    display:flex;flex-wrap:wrap;gap:6px 14px;align-items:center;
    font-size:12.5px;opacity:.92;margin-bottom:10px;
}
.mm-sub i{width:12px;text-align:center;opacity:.8;margin-right:4px;}
.mm-pills{display:flex;flex-wrap:wrap;gap:6px;}
.mm-pill{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 11px;border-radius:999px;font-size:11px;font-weight:700;
    background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);
    letter-spacing:.02em;
}
.mm-pill.dot::before{
    content:'';width:7px;height:7px;border-radius:50%;background:currentColor;
}
.mm-pill.status-active{background:#ecfdf5;color:#065f46;border-color:#6ee7b7;}
.mm-pill.status-warning{background:#fef3c7;color:#92400e;border-color:#fcd34d;}
.mm-pill.status-inactive{background:#fee2e2;color:#991b1b;border-color:#fca5a5;}
.mm-pill.status-archived{background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
.mm-pill.tier-permanent{background:#dcfce7;color:#14532d;border-color:#86efac;}
.mm-pill.tier-temporary{background:#ffedd5;color:#9a3412;border-color:#fdba74;}
.mm-hero-actions{
    display:flex;gap:8px;flex-wrap:wrap;margin-top:14px;
}
.mm-ha{
    display:inline-flex;align-items:center;gap:6px;
    padding:8px 13px;border-radius:10px;font-size:12px;font-weight:700;
    background:rgba(255,255,255,.14);color:#fff;border:1px solid rgba(255,255,255,.28);
    cursor:pointer;transition:all .15s;text-decoration:none;
}
.mm-ha:hover{background:#fff;color:#047857;border-color:#fff;transform:translateY(-1px);}
.mm-ha.primary{background:#fff;color:#047857;border-color:#fff;}
.mm-ha.primary:hover{background:#ecfdf5;}
.mm-ha.danger:hover{background:#fee2e2;color:#991b1b;border-color:#fecaca;}

/* ── Quick facts strip ───────────────────────────────────── */
.mm-facts{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1px;
    background:#e2e8f0;margin:0 0 0;
}
.mm-fact{
    background:#fff;padding:14px 16px;display:flex;flex-direction:column;gap:2px;
}
.mm-fact-label{font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;}
.mm-fact-value{font-size:14px;font-weight:700;color:#0f172a;}
.mm-fact-value.muted{color:#94a3b8;font-weight:500;}

/* ── Standalone bar ──────────────────────────────────────── */
.mm-standalone-bar{display:flex;gap:14px;align-items:center;background:#fffbeb;border-bottom:1px solid #fde68a;color:#92400e;font-size:12px;padding:8px 14px;}
.mm-standalone-bar a{color:#92400e;font-weight:700;text-decoration:underline;}

/* ── Tabs ────────────────────────────────────────────────── */
.mm-tabs{
    position:sticky;top:0;z-index:30;
    display:flex;background:#fff;border-bottom:1px solid #e2e8f0;
    box-shadow:0 1px 3px rgba(15,23,42,.05);
}
.mm-tab{
    flex:0 0 auto;padding:13px 20px;display:inline-flex;align-items:center;gap:8px;
    font-size:13.5px;font-weight:600;background:transparent;border:none;cursor:pointer;
    border-bottom:3px solid transparent;color:#64748b;transition:all .15s;
}
.mm-tab:hover{background:#f8fafc;color:#334155;}
.mm-tab.active{border-bottom-color:#059669;color:#047857;background:linear-gradient(to bottom,#ecfdf5,#fff);}
.mm-tab i{font-size:14px;opacity:.85;}

/* ── Content container ───────────────────────────────────── */
.mm-content{padding:18px 18px 120px;}
.mm-sheet{display:grid;gap:16px;}
@media(min-width:980px){
    .mm-sheet{grid-template-columns:minmax(0,1fr) 360px;}
    .mm-sheet .col-main{min-width:0;}
    .mm-sheet .col-side{min-width:0;display:flex;flex-direction:column;gap:16px;}
}

/* ── Cards ───────────────────────────────────────────────── */
.mm-card{
    background:#fff;border-radius:14px;overflow:hidden;
    border:1px solid #e2e8f0;box-shadow:0 1px 2px rgba(15,23,42,.04);
}
.mm-card-hdr{
    display:flex;align-items:center;gap:10px;padding:13px 16px;
    background:linear-gradient(180deg,#fbfdff,#f8fafc);border-bottom:1px solid #f1f5f9;
}
.mm-card-hdr .hdr-actions{margin-left:auto;display:flex;gap:6px;}
.mm-card-icon{
    width:34px;height:34px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;
}
.mm-icon-green{background:#d1fae5;color:#059669;}
.mm-icon-blue{background:#dbeafe;color:#2563eb;}
.mm-icon-purple{background:#ede9fe;color:#7c3aed;}
.mm-icon-red{background:#fee2e2;color:#dc2626;}
.mm-icon-amber{background:#fef3c7;color:#d97706;}
.mm-icon-slate{background:#e2e8f0;color:#475569;}
.mm-icon-pink{background:#fce7f3;color:#be185d;}
.mm-card-title{font-size:14px;font-weight:700;color:#0f172a;letter-spacing:-.01em;}
.mm-card-subtitle{font-size:11px;color:#94a3b8;margin-top:1px;}
.mm-card-body{padding:14px 16px;}

/* ── Info rows (key / value) ─────────────────────────────── */
.mm-grid2{display:grid;grid-template-columns:1fr 1fr;gap:0 24px;}
.mm-grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:0 24px;}
.mm-info{
    display:flex;flex-direction:column;gap:2px;padding:10px 0;
    border-bottom:1px dashed #eef2f7;
}
.mm-info:last-child{border-bottom:none;}
.mm-grid2 .mm-info:nth-last-child(-n+2),
.mm-grid3 .mm-info:nth-last-child(-n+3){border-bottom:none;}
.mm-info-label{font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
.mm-info-value{font-size:13.5px;color:#0f172a;font-weight:600;word-break:break-word;}
.mm-info-value.muted{color:#94a3b8;font-weight:500;font-style:italic;}
.mm-info a.mm-info-value{color:#047857;text-decoration:none;}
.mm-info a.mm-info-value:hover{text-decoration:underline;}

/* Inline chip list */
.mm-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;}
.mm-chip{
    display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:999px;
    font-size:11px;font-weight:700;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;
}
.mm-chip.green{background:#ecfdf5;color:#047857;border-color:#a7f3d0;}
.mm-chip.blue{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;}
.mm-chip.amber{background:#fffbeb;color:#92400e;border-color:#fde68a;}
.mm-chip.purple{background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe;}
.mm-chip.slate{background:#f8fafc;color:#334155;border-color:#e2e8f0;}

/* ── Photos grid ─────────────────────────────────────────── */
.mm-photos{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:14px;}
.mm-photo-wrap{text-align:center;}
.mm-photo-wrap .ph{
    width:120px;height:150px;margin:0 auto;border-radius:12px;background:linear-gradient(135deg,#f8fafc,#eef2f7);
    border:2px dashed #cbd5e1;overflow:hidden;cursor:pointer;
    display:flex;align-items:center;justify-content:center;position:relative;transition:all .2s;
}
.mm-photo-wrap .ph:hover{border-color:#10b981;box-shadow:0 8px 20px rgba(16,185,129,.18);transform:translateY(-2px);}
.mm-photo-wrap .ph img{width:100%;height:100%;object-fit:cover;}
.mm-photo-wrap .ph .ph-empty{display:flex;flex-direction:column;align-items:center;color:#94a3b8;}
.mm-photo-wrap .ph .ph-empty i{font-size:30px;margin-bottom:4px;}
.mm-photo-wrap .ph .ph-empty span{font-size:11px;}
.mm-photo-wrap .ph .zoom{
    position:absolute;inset:auto 6px 6px auto;background:rgba(0,0,0,.5);color:#fff;
    width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;
    font-size:12px;opacity:0;transition:opacity .2s;
}
.mm-photo-wrap .ph:hover .zoom{opacity:1;}
.mm-photo-wrap .lbl{font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.06em;margin-top:8px;}

/* ── Documents ───────────────────────────────────────────── */
.mm-docs{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;}
.mm-doc{
    padding:14px 10px;background:#f8fafc;border-radius:12px;text-align:center;
    cursor:pointer;border:2px solid transparent;transition:all .15s;text-decoration:none;color:inherit;display:block;
}
.mm-doc:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(15,23,42,.08);background:#fff;}
.mm-doc.has{background:linear-gradient(135deg,#ecfdf5,#d1fae5);border-color:#a7f3d0;}
.mm-doc .ic{
    width:46px;height:46px;border-radius:12px;background:#fff;margin:0 auto 8px;
    display:flex;align-items:center;justify-content:center;font-size:20px;color:#64748b;
}
.mm-doc.has .ic{background:#059669;color:#fff;}
.mm-doc .nm{font-size:11.5px;font-weight:700;color:#334155;margin-bottom:3px;}
.mm-doc .st{font-size:10.5px;color:#64748b;font-weight:600;}
.mm-doc.has .st{color:#059669;}

/* ── Department status ───────────────────────────────────── */
.mm-dept{min-height:46px;display:flex;align-items:center;}

/* ── Roles ───────────────────────────────────────────────── */
.mm-roles{display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:8px;}
.mm-role{
    text-align:center;padding:12px 6px;border-radius:12px;background:#f8fafc;
    border:1px solid #eef2f7;transition:all .15s;
}
.mm-role.on{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-color:#6ee7b7;}
.mm-role i{font-size:18px;color:#94a3b8;margin-bottom:4px;display:block;}
.mm-role.on i{color:#059669;}
.mm-role span{font-size:11px;font-weight:700;color:#64748b;}
.mm-role.on span{color:#047857;}

/* ── Family ──────────────────────────────────────────────── */
.mm-fam-g{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;}
.mm-fam-g:last-child{border-bottom:none;}
.mm-fam-g .av{
    width:38px;height:38px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,#059669,#0ea5e9);color:#fff;font-weight:800;
    display:flex;align-items:center;justify-content:center;font-size:14px;
}
.mm-fam-g .who{min-width:0;flex:1;}
.mm-fam-g .nm{font-weight:700;color:#0f172a;font-size:13px;}
.mm-fam-g .meta{font-size:11px;color:#64748b;display:flex;flex-wrap:wrap;gap:4px 8px;align-items:center;margin-top:2px;}
.mm-fam-tag{font-size:9.5px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;
    color:#047857;background:#d1fae5;border-radius:999px;padding:1px 7px;}
.mm-fam-tag.soft{color:#475569;background:#f1f5f9;}
.mm-fam-btn{
    display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;
    color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;
    padding:5px 10px;cursor:pointer;white-space:nowrap;text-decoration:none;
}
.mm-fam-btn:hover{background:#d1fae5;}
.mm-fam-empty{
    font-size:12px;color:#64748b;background:#f8fafc;border:1px dashed #e2e8f0;
    border-radius:10px;padding:12px;
}
.mm-sibs{display:flex;gap:8px;overflow-x:auto;padding-bottom:4px;margin:6px -4px 0;padding:4px;}
.mm-sib{
    flex:0 0 auto;display:flex;align-items:center;gap:8px;padding:6px 10px 6px 6px;
    background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;text-decoration:none;color:inherit;
    transition:all .15s;
}
.mm-sib:hover{background:#ecfdf5;border-color:#a7f3d0;}
.mm-sib .av{
    width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,#059669,#0ea5e9);
    color:#fff;font-weight:800;font-size:12px;display:flex;align-items:center;justify-content:center;
}
.mm-sib .nm{font-size:12px;font-weight:700;color:#0f172a;max-width:140px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.mm-sib .meta{font-size:10.5px;color:#64748b;}

/* ── EDIT TAB ────────────────────────────────────────────── */
#view-edit{display:none;}
.mm-edit-grid{display:grid;gap:16px;}
@media(min-width:900px){
    .mm-edit-grid{grid-template-columns:minmax(0,1fr) 340px;align-items:start;}
    .mm-edit-grid .edit-main{min-width:0;display:flex;flex-direction:column;gap:16px;}
    .mm-edit-grid .edit-side{min-width:0;display:flex;flex-direction:column;gap:16px;position:sticky;top:50px;}
}
.mm-form-group{margin-bottom:14px;}
.mm-form-group:last-child{margin-bottom:0;}
.mm-label{
    display:block;font-size:11px;font-weight:700;color:#475569;margin-bottom:6px;
    text-transform:uppercase;letter-spacing:.05em;
}
.mm-label .req{color:#dc2626;margin-left:3px;}
.mm-label .hint{text-transform:none;font-weight:500;color:#94a3b8;margin-left:6px;letter-spacing:0;}
.mm-input{
    width:100%;padding:10px 12px;border:1.5px solid #e2e8f0;border-radius:10px;
    font-size:13.5px;background:#fbfdff;transition:all .15s;color:#0f172a;
    font-family:inherit;
}
.mm-input:focus{outline:none;border-color:#10b981;background:#fff;box-shadow:0 0 0 3px rgba(16,185,129,.12);}
.mm-input::placeholder{color:#cbd5e1;}
.mm-help{font-size:11px;color:#94a3b8;margin-top:5px;line-height:1.45;}
.mm-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.mm-row-3{display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:10px;align-items:end;}
.mm-row-3 .mm-form-group{margin-bottom:0;}
.mm-checks{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;}
.mm-check{
    display:flex;align-items:flex-start;gap:10px;padding:10px 12px;
    background:#f8fafc;border-radius:10px;cursor:pointer;border:1.5px solid transparent;transition:all .15s;
}
.mm-check:hover{background:#f1f5f9;}
.mm-check input{width:18px;height:18px;accent-color:#10b981;margin-top:2px;flex-shrink:0;}
.mm-check input:checked ~ span{color:#047857;font-weight:600;}
.mm-check:has(input:checked){background:#ecfdf5;border-color:#a7f3d0;}
.mm-check span{font-size:12.5px;color:#334155;line-height:1.4;}
.mm-file-ok{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:#059669;font-weight:600;margin-bottom:6px;background:#ecfdf5;padding:3px 9px;border-radius:999px;}
.mm-file-row{
    display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #f1f5f9;
}
.mm-file-row:last-child{border-bottom:none;}
.mm-file-row .fl{
    width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;
    font-size:15px;flex-shrink:0;
}
.mm-file-row .fn{flex:1;min-width:0;}
.mm-file-row .fn .t{font-size:12.5px;font-weight:700;color:#0f172a;}
.mm-file-row .fn .s{font-size:11px;color:#64748b;margin-top:2px;}
.mm-file-row .fu{margin-left:auto;}
.mm-file-row .fu input{font-size:11px;}

/* Sticky save bar (anchored to sheet, not viewport) */
.mm-savebar{
    position:sticky;bottom:0;left:0;right:0;
    margin:16px -18px -120px;padding:12px 18px;
    background:linear-gradient(to top,#fff 60%,rgba(255,255,255,.85));
    backdrop-filter:blur(6px);
    border-top:1px solid #e2e8f0;
    display:flex;gap:10px;align-items:center;
    z-index:20;
}
.mm-save{
    flex:1;padding:13px 18px;background:linear-gradient(135deg,#059669,#10b981);
    color:#fff;font-size:14px;font-weight:700;border:none;border-radius:11px;
    cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:9px;transition:all .15s;
    box-shadow:0 4px 14px rgba(16,185,129,.3);
}
.mm-save:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(5,150,105,.38);}
.mm-save:disabled{opacity:.65;cursor:not-allowed;transform:none;}
.mm-savebar .sec{font-size:11px;color:#64748b;}

/* Empty state */
.mm-empty{padding:18px;text-align:center;color:#94a3b8;font-size:12.5px;}
.mm-empty i{font-size:28px;display:block;margin-bottom:8px;color:#cbd5e1;}

/* Toast/alert area */
.mm-alert{
    padding:10px 14px;border-radius:10px;font-size:12.5px;font-weight:600;
    display:none;align-items:center;gap:8px;margin-bottom:12px;
}
.mm-alert.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;display:flex;}
.mm-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;display:flex;}

/* Copy button micro-interaction */
.mm-copy{
    display:inline-flex;align-items:center;gap:4px;background:transparent;border:none;
    color:inherit;cursor:pointer;font:inherit;padding:2px 4px;border-radius:4px;
}
.mm-copy:hover{background:rgba(255,255,255,.18);}

/* ── Responsive ──────────────────────────────────────────── */
@media(max-width:768px){
    .mm-hero{padding:16px;}
    .mm-hero-row{gap:12px;}
    .mm-photo{width:78px;height:98px;}
    .mm-photo.guard{width:56px;height:70px;}
    .mm-name{font-size:18px;}
    .mm-content{padding:14px 12px 110px;}
    .mm-grid2,.mm-grid3{grid-template-columns:1fr;}
    .mm-grid2 .mm-info,.mm-grid3 .mm-info{border-bottom:1px dashed #eef2f7;}
    .mm-grid2 .mm-info:last-child,.mm-grid3 .mm-info:last-child{border-bottom:none;}
    .mm-row,.mm-row-3{grid-template-columns:1fr;}
    .mm-facts{grid-template-columns:1fr 1fr;}
    .mm-savebar{margin:12px -12px -110px;padding:10px 12px;}
    .mm-hero-actions .mm-ha{font-size:11.5px;padding:7px 10px;flex:1;justify-content:center;}
    .mm-tab{padding:11px 14px;font-size:12.5px;}
}
</style>

<div class="mm-wrap">

    <!-- HERO -->
    <div class="mm-hero">
        <div class="mm-hero-row">
            <div class="mm-photo-stack">
                <div class="mm-photo" title="Student photo" onclick="openDocFullscreen('<?= fixPath($m['student_photo_path']) ?>')">
                    <?php if (!empty($m['student_photo_path'])): ?>
                        <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                    <?php else: ?>
                        <i class="fas fa-user"></i>
                    <?php endif; ?>
                    <span class="mm-photo-label">Student</span>
                </div>
                <?php if (!empty($m['guardian_photo_path']) || !empty($m['guardian_name'])): ?>
                <div class="mm-photo guard" title="Guardian photo" onclick="openDocFullscreen('<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>')">
                    <?php if (!empty($m['guardian_photo_path'])): ?>
                        <img src="<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>" alt="Guardian">
                    <?php else: ?>
                        <i class="fas fa-user-group" style="font-size:22px;"></i>
                    <?php endif; ?>
                    <span class="mm-photo-label" style="font-size:8px;">Guardian</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="mm-hero-info">
                <button type="button" id="mmBackBtn" class="mm-back-btn" style="display:none" onclick="return window.MemberNav ? MemberNav.back() : false;"><i class="fas fa-arrow-left"></i> <span data-label>Back to previous member</span></button>
                <div class="mm-name"><?= e($fullName) ?></div>
                <div class="mm-sub">
                    <?php if (!empty($m['member_code'])): ?>
                        <span><i class="fas fa-id-card"></i> <button class="mm-copy" type="button" onclick="navigator.clipboard && navigator.clipboard.writeText(<?= json_encode($m['member_code']) ?>);this.firstElementChild.className='fas fa-check'"><?= e($m['member_code']) ?> <i class="far fa-copy"></i></button></span>
                    <?php else: ?>
                        <span><i class="fas fa-hourglass-half"></i> Waiting for ID</span>
                    <?php endif; ?>
                    <?php if (!empty($m['gender'])): ?><span><i class="fas fa-<?= $m['gender']==='female' ? 'venus' : 'mars' ?>"></i> <?= $m['gender']==='female' ? 'ሴት' : 'ወንድ' ?></span><?php endif; ?>
                    <?php if ($dobDisplay): ?><span><i class="fas fa-cake-candles"></i> <?= e($dobDisplay) ?><?php if (!empty($m['age'])) echo ' · '.(int)$m['age'].' ዓመት'; ?></span><?php endif; ?>
                    <?php if (!empty($m['current_section'])): ?><span><i class="fas fa-layer-group"></i> <?= e($m['current_section']) ?></span><?php elseif (!empty($m['age_group'])): ?><span><i class="fas fa-layer-group"></i> <?= e($m['age_group']) ?></span><?php endif; ?>
                    <?php if (!empty($m['phone_number'])): ?><span><i class="fas fa-phone"></i> <a href="tel:<?= e($m['phone_number']) ?>" style="color:inherit;text-decoration:none"><?= e($m['phone_number']) ?></a></span><?php endif; ?>
                </div>
                <div class="mm-pills">
                    <?php $st = (string)($m['status'] ?? 'active'); ?>
                    <span class="mm-pill dot status-<?= e($st) ?>"><?= e(ucfirst($st)) ?></span>
                    <span class="mm-pill"><?= e(ucfirst(str_replace('_', ' ', (string)($m['registration_type'] ?? 'waiting')))) ?> registration</span>
                    <span class="mm-pill"><?= e(ucfirst(str_replace('_', ' ', (string)($m['member_type'] ?? 'regular')))) ?></span>
                    <span class="mm-pill tier-<?= e($m['membership_tier'] ?? 'temporary') ?>"><?= e(ucfirst((string)($m['membership_tier'] ?? 'temporary'))) ?> tier</span>
                </div>

                <div class="mm-hero-actions">
                    <button class="mm-ha primary" type="button" onclick="switchManageTab('edit')"><i class="fas fa-pen-to-square"></i> Edit member</button>
                    <a class="mm-ha" target="_blank" href="/admin/print_member.php?id=<?= (int)$m['id'] ?>"><i class="fas fa-file-pdf"></i> Export PDF</a>
                    <button class="mm-ha" type="button" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                    <?php if (!empty($m['phone_number'])): ?>
                    <a class="mm-ha" href="tel:<?= e($m['phone_number']) ?>"><i class="fas fa-phone"></i> Call</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($_GET['embed'])): ?>
    <div class="mm-standalone-bar">
        <span><i class="fas fa-circle-info"></i> Opening this member inside the dashboard…</span>
        <a href="/admin/dashboard.php?member=<?= (int)$m['id'] ?>"><i class="fas fa-arrow-right"></i> Continue</a>
        <a href="javascript:history.back()"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
    <script>try { window.location.replace('/admin/dashboard.php?member=<?= (int)$m['id'] ?>'); } catch (e) {}</script>
    <?php endif; ?>

    <!-- QUICK FACTS STRIP -->
    <div class="mm-facts">
        <div class="mm-fact">
            <span class="mm-fact-label">Code</span>
            <span class="mm-fact-value<?= empty($m['member_code']) ? ' muted' : '' ?>"><?= !empty($m['member_code']) ? e($m['member_code']) : '—' ?></span>
        </div>
        <div class="mm-fact">
            <span class="mm-fact-label">Gender</span>
            <span class="mm-fact-value"><?= ($m['gender'] ?? '') === 'female' ? 'ሴት (Female)' : 'ወንድ (Male)' ?></span>
        </div>
        <div class="mm-fact">
            <span class="mm-fact-label">Age</span>
            <span class="mm-fact-value<?= empty($m['age']) ? ' muted' : '' ?>"><?= !empty($m['age']) ? (int)$m['age'].' years' : '—' ?></span>
        </div>
        <div class="mm-fact">
            <span class="mm-fact-label">Section</span>
            <span class="mm-fact-value<?= empty($m['current_section']) ? ' muted' : '' ?>"><?= !empty($m['current_section']) ? e($m['current_section']) : (!empty($m['age_group']) ? e($m['age_group']) : '—') ?></span>
        </div>
        <div class="mm-fact">
            <span class="mm-fact-label">Phone</span>
            <span class="mm-fact-value<?= empty($m['phone_number']) ? ' muted' : '' ?>"><?= !empty($m['phone_number']) ? e($m['phone_number']) : '—' ?></span>
        </div>
        <div class="mm-fact">
            <span class="mm-fact-label">Guardian</span>
            <span class="mm-fact-value<?= empty($m['guardian_name']) ? ' muted' : '' ?>"><?= !empty($m['guardian_name']) ? e($m['guardian_name']) : '—' ?></span>
        </div>
    </div>

    <!-- TABS -->
    <div class="mm-tabs">
        <button id="tab-preview" class="mm-tab active" onclick="switchManageTab('preview')" type="button">
            <i class="fas fa-eye"></i> <span>Preview</span>
        </button>
        <button id="tab-edit" class="mm-tab" onclick="switchManageTab('edit')" type="button">
            <i class="fas fa-pen-to-square"></i> <span>Edit</span>
        </button>
    </div>

    <div class="mm-content">
        <div id="mm-alert" class="mm-alert" role="alert"></div>

        <!-- ============== PREVIEW ============== -->
        <div id="view-preview">
            <?php
            // ── Load family data ────────────────────────────────
            $famGuardians = []; $famSiblings = [];
            try {
                require_once __DIR__ . '/backend/services/GuardianService.php';
                $famGuardians = \App\Services\GuardianService::familyOfMember($conn, (int)$m['id']);
                $famSiblings  = \App\Services\GuardianService::siblingsOf($conn, (int)$m['id']);
            } catch (\Throwable $famError) { $famGuardians = []; $famSiblings = []; }
            $famCanManage = in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin','school_admin','hr_dept'], true);
            $famRelLabel = static function(string $r): string {
                return $r === 'father' ? 'Father' : ($r === 'mother' ? 'Mother' : 'Guardian');
            };
            $initials = static function(string $s): string {
                $s = trim($s);
                if ($s === '') return '?';
                return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8');
            };
            $eduMap = [
                'kg'=>'KG','elementary'=>'Elementary (1-8)','high_school'=>'High School (9-12)',
                'high'=>'High School (9-12)','certificate'=>'Certificate','diploma'=>'Diploma',
                'degree'=>'Degree','masters'=>'Masters','phd'=>'PhD','illiterate'=>'Illiterate',''=>'—',
            ];
            $spEduMap = [
                'grade_1'=>'1ኛ ክፍል (Grade 1)','grade_2'=>'2ኛ ክፍል (Grade 2)','grade_3'=>'3ኛ ክፍል (Grade 3)',
                'grade_4'=>'4ኛ ክፍል (Grade 4)','grade_5'=>'5ኛ ክፍል (Grade 5)','grade_6'=>'6ኛ ክፍል (Grade 6)',
                'grade_7'=>'7ኛ ክፍል (Grade 7)','grade_8'=>'8ኛ ክፍል (Grade 8)','grade_9'=>'9ኛ ክፍል (Grade 9)',
                'grade_10'=>'10ኛ ክፍል (Grade 10)','grade_11'=>'11ኛ ክፍል (Grade 11)','grade_12'=>'12ኛ ክፍል (Grade 12)',
                'diploma'=>'ዲፕሎማ (Diploma)','degree'=>'ዲግሪ (Degree)',''=>'—',
            ];
            $regTypeMap = ['waiting'=>'Waiting','direct'=>'Direct','transfer'=>'Transfer'];
            $memTypeMap = ['regular'=>'Regular','special_regular'=>'Special Regular','honorary'=>'Honorary'];
            $cityMap = ['addis_ababa'=>'Addis Ababa','oromia'=>'Oromia',''=>'—'];
            $genderLabel = ($m['gender'] ?? '') === 'female' ? 'ሴት (Female)' : 'ወንድ (Male)';

            $phone = trim((string)($m['phone_number'] ?? ''));
            $altPhone = trim((string)($m['alt_phone_number'] ?? ''));
            $guardianPhone = trim((string)($m['guardian_phone1'] ?? ''));
            ?>
            <div class="mm-sheet">
                <div class="col-main">

                    <!-- Department status -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-amber"><i class="fas fa-user-clock"></i></div>
                            <div>
                                <div class="mm-card-title">Department Status</div>
                                <div class="mm-card-subtitle">Attendance &amp; class enrollment</div>
                            </div>
                        </div>
                        <div class="mm-card-body">
                            <div class="mm-dept" data-dept-status="<?= (int)$m['id'] ?>">
                                <span style="font-size:12px;color:#94a3b8"><i class="fas fa-spinner fa-spin"></i> Loading department status…</span>
                            </div>
                        </div>
                    </div>

                    <!-- Personal Information -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-green"><i class="fas fa-user"></i></div>
                            <div>
                                <div class="mm-card-title">Personal Information</div>
                                <div class="mm-card-subtitle">Identity &amp; demographics</div>
                            </div>
                        </div>
                        <div class="mm-card-body">
                            <div class="mm-grid2">
                                <div class="mm-info"><span class="mm-info-label">Full Name</span><span class="mm-info-value"><?= e(trim(($m['student_name']??'').' '.($m['father_name']??'').' '.($m['grandfather_name']??''))) ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Christian Name</span><span class="mm-info-value"><?= !empty($m['baptismal_name']) ? e($m['baptismal_name']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Gender</span><span class="mm-info-value"><?= $genderLabel ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Date of Birth (EC)</span><span class="mm-info-value"><?= $dobDisplay !== '' ? e($dobDisplay) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Age</span><span class="mm-info-value"><?= !empty($m['age']) ? (int)$m['age'].' years old' : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Section</span><span class="mm-info-value"><?= !empty($m['current_section']) ? e($m['current_section']) : (!empty($m['age_group']) ? e($m['age_group']) : '<span class="mm-info-value muted">—</span>') ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Education</span><span class="mm-info-value"><?= e($eduMap[$m['education_level'] ?? ''] ?? ucwords(str_replace('_',' ',(string)($m['education_level'] ?? '')))) ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Spiritual Education</span><span class="mm-info-value"><?= e($spEduMap[$m['spiritual_education'] ?? ''] ?? ucwords(str_replace('_',' ',(string)($m['spiritual_education'] ?? '')))) ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Profession</span><span class="mm-info-value"><?= !empty($m['work_profession']) ? e($m['work_profession']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-blue"><i class="fas fa-phone"></i></div>
                            <div>
                                <div class="mm-card-title">Contact</div>
                                <div class="mm-card-subtitle">Phones &amp; guardian</div>
                            </div>
                        </div>
                        <div class="mm-card-body">
                            <div class="mm-grid2">
                                <div class="mm-info"><span class="mm-info-label">Primary Phone</span>
                                    <span class="mm-info-value"><?= $phone !== '' ? '<a href="tel:'.e($phone).'">'.e($phone).' <i class="fas fa-phone-volume" style="color:#94a3b8;font-size:11px;margin-left:4px"></i></a>' : '<span class="mm-info-value muted">—</span>' ?></span>
                                </div>
                                <div class="mm-info"><span class="mm-info-label">Alternate Phone</span>
                                    <span class="mm-info-value"><?= $altPhone !== '' ? '<a href="tel:'.e($altPhone).'">'.e($altPhone).'</a>' : '<span class="mm-info-value muted">—</span>' ?></span>
                                </div>
                                <div class="mm-info"><span class="mm-info-label">Guardian Name</span><span class="mm-info-value"><?= !empty($m['guardian_name']) ? e($m['guardian_name']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Guardian Phone</span>
                                    <span class="mm-info-value"><?= $guardianPhone !== '' ? '<a href="tel:'.e($guardianPhone).'">'.e($guardianPhone).'</a>' : '<span class="mm-info-value muted">—</span>' ?></span>
                                </div>
                                <?php if (!empty($m['guardian_phone2'])): ?>
                                <div class="mm-info"><span class="mm-info-label">Guardian Phone 2</span>
                                    <span class="mm-info-value"><a href="tel:<?= e($m['guardian_phone2']) ?>"><?= e($m['guardian_phone2']) ?></a></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Address -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-red"><i class="fas fa-map-marker-alt"></i></div>
                            <div>
                                <div class="mm-card-title">Address</div>
                                <div class="mm-card-subtitle">Residence details</div>
                            </div>
                        </div>
                        <div class="mm-card-body">
                            <div class="mm-grid3">
                                <div class="mm-info"><span class="mm-info-label">City</span><span class="mm-info-value"><?= !empty($m['city']) ? e($cityMap[$m['city']] ?? $m['city']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Sub City</span><span class="mm-info-value"><?= !empty($m['sub_city']) ? e($m['sub_city']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Woreda</span><span class="mm-info-value"><?= !empty($m['woreda']) ? e($m['woreda']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Mender</span><span class="mm-info-value"><?= !empty($m['mender']) ? e($m['mender']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">Block No.</span><span class="mm-info-value"><?= !empty($m['block_number']) ? e($m['block_number']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                                <div class="mm-info"><span class="mm-info-label">House No.</span><span class="mm-info-value"><?= !empty($m['house_number']) ? e($m['house_number']) : '<span class="mm-info-value muted">—</span>' ?></span></div>
                            </div>
                            <?php
                                $addrParts = array_filter([$m['house_number'] ?? '', $m['mender'] ?? '', $m['woreda'] ?? '', $m['sub_city'] ?? '', $cityMap[$m['city'] ?? ''] ?? ($m['city'] ?? '')]);
                                if ($addrParts):
                            ?>
                            <div style="margin-top:10px;padding:10px 12px;background:#f8fafc;border-radius:10px;font-size:12px;color:#475569;">
                                <i class="fas fa-location-dot" style="color:#dc2626;margin-right:6px"></i>
                                <?= e(implode(', ', $addrParts)) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Family -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-green"><i class="fas fa-people-roof"></i></div>
                            <div>
                                <div class="mm-card-title">Family</div>
                                <div class="mm-card-subtitle">Guardians &amp; siblings</div>
                            </div>
                            <?php if ($famCanManage && $famGuardians): ?>
                            <div class="hdr-actions">
                                <a class="mm-fam-btn" href="/admin/dashboard.php?section=family" onclick="if(window.FamilyLink&&window.navigateToSection){if(typeof closeManageSheet==='function')closeManageSheet();navigateToSection('family');return false;}"><i class="fas fa-link"></i> Family Link</a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="mm-card-body">
                            <?php if (!$famGuardians): ?>
                                <div class="mm-fam-empty">
                                    <?php if (trim((string)($m['guardian_name'] ?? '')) !== ''): ?>
                                        <b><?= e($m['guardian_name']) ?></b> is typed on this record but has no guardian record yet, so siblings cannot be detected.
                                        <?php if ($famCanManage): ?><div style="margin-top:8px"><a class="mm-fam-btn" href="/admin/dashboard.php?section=family" onclick="if(window.FamilyLink&&window.navigateToSection){if(typeof closeManageSheet==='function')closeManageSheet();navigateToSection('family');if(FamilyLink&&FamilyLink.go)FamilyLink.go('unlinked');return false;}"><i class="fas fa-link"></i> Link in Family Link → Not linked yet</a></div><?php endif; ?>
                                    <?php else: ?>
                                        No guardian details on this record. Add the guardian's name and phone in <b>Edit</b> — a guardian record is created and siblings are detected automatically.
                                    <?php endif; ?>
                                </div>
                            <?php else: foreach ($famGuardians as $famG): ?>
                                <div class="mm-fam-g">
                                    <div class="av"><?= e($initials((string)($famG['display_name'] ?? 'G'))) ?></div>
                                    <div class="who">
                                        <div class="nm"><?= e((string)$famG['display_name']) ?></div>
                                        <div class="meta">
                                            <span class="mm-fam-tag<?= (int)($famG['is_primary'] ?? 0) === 1 ? '' : ' soft' ?>"><?= e($famRelLabel((string)$famG['relation'])) ?><?= (int)($famG['is_primary'] ?? 0) === 1 ? ' · primary' : '' ?></span>
                                            <?php if (trim((string)($famG['phone_primary'] ?? '')) !== ''): ?>
                                                <a href="tel:<?= e((string)$famG['phone_primary']) ?>" style="color:inherit;text-decoration:none"><i class="fas fa-phone" style="margin-right:3px"></i> <?= e((string)$famG['phone_primary']) ?></a>
                                            <?php else: ?>
                                                <span style="color:#d97706"><i class="fas fa-triangle-exclamation"></i> no phone</span>
                                            <?php endif; ?>
                                            <?php if (trim((string)($famG['phone_alt'] ?? '')) !== ''): ?> · <a href="tel:<?= e((string)$famG['phone_alt']) ?>" style="color:inherit;text-decoration:none"><?= e((string)$famG['phone_alt']) ?></a><?php endif; ?>
                                            <?php if (!empty($famG['aliases'])): ?><span>aka <?= e(implode(', ', (array)$famG['aliases'])) ?></span><?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($famCanManage): ?>
                                        <a class="mm-fam-btn" href="/admin/dashboard.php?section=family&guardian=<?= (int)$famG['id'] ?>" title="Open in Family Link" onclick="return window.MemberNav ? MemberNav.manageFamily(<?= (int)$famG['id'] ?>) : true;"><i class="fas fa-people-roof"></i> Manage</a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>

                            <div style="margin-top:14px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:flex;align-items:center;gap:6px;">
                                <i class="fas fa-people-roof"></i> Siblings<?= $famSiblings ? ' <span style="background:#f1f5f9;color:#475569;padding:1px 8px;border-radius:999px;font-weight:700;">'.count($famSiblings).'</span>' : '' ?>
                            </div>
                            <?php if (!$famSiblings): ?>
                                <div style="font-size:12px;color:#94a3b8;margin-top:4px"><?= $famGuardians ? 'No other member is linked to this guardian yet.' : '—' ?></div>
                            <?php else: ?>
                                <div class="mm-sibs">
                                    <?php foreach ($famSiblings as $famS):
                                        $famSName = (string)($famS['student_name'] ?: ($famS['full_name_am'] ?? ''));
                                    ?>
                                    <a class="mm-sib" href="/admin/dashboard.php?member=<?= (int)$famS['id'] ?>" onclick="return window.MemberNav ? MemberNav.go(<?= (int)$m['id'] ?>, <?= (int)$famS['id'] ?>) : true;" title="Open <?= e($famSName) ?>">
                                        <div class="av"><?= e($initials($famSName)) ?></div>
                                        <div>
                                            <div class="nm"><?= e($famSName) ?></div>
                                            <div class="meta"><?= e((string)($famS['member_code'] ?: 'no code')) ?><?= trim((string)($famS['current_section'] ?? '')) !== '' ? ' · '.e((string)$famS['current_section']) : '' ?></div>
                                        </div>
                                    </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /col-main -->

                <div class="col-side">

                    <!-- Photos -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-green"><i class="fas fa-camera"></i></div>
                            <div><div class="mm-card-title">Photos</div></div>
                        </div>
                        <div class="mm-card-body">
                            <div class="mm-photos">
                                <div class="mm-photo-wrap">
                                    <div class="ph" onclick="openDocFullscreen('<?= fixPath($m['student_photo_path']) ?>')">
                                        <?php if (!empty($m['student_photo_path'])): ?>
                                            <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                                            <span class="zoom"><i class="fas fa-magnifying-glass-plus"></i></span>
                                        <?php else: ?>
                                            <div class="ph-empty"><i class="fas fa-user"></i><span>No Photo</span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lbl">Student</div>
                                </div>
                                <div class="mm-photo-wrap">
                                    <div class="ph" onclick="openDocFullscreen('<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>')">
                                        <?php if (!empty($m['guardian_photo_path'])): ?>
                                            <img src="<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>" alt="Guardian">
                                            <span class="zoom"><i class="fas fa-magnifying-glass-plus"></i></span>
                                        <?php else: ?>
                                            <div class="ph-empty"><i class="fas fa-user-group" style="font-size:26px"></i><span>No Photo</span></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lbl">Guardian</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-blue"><i class="fas fa-folder-open"></i></div>
                            <div><div class="mm-card-title">Documents</div></div>
                        </div>
                        <div class="mm-card-body">
                            <?php
                            $docs = [
                                'doc_school_records_path' => ['School Records', 'fa-graduation-cap', '#2563eb'],
                                'doc_spiritual_path'      => ['Spiritual Records', 'fa-church', '#7c3aed'],
                                'doc_signed_form_path'    => ['Signed Form', 'fa-file-signature', '#059669'],
                            ];
                            foreach ($docs as $key => $info):
                                $path = (string)($m[$key] ?? ''); $has = $path !== '';
                                $fileUrl = memberFileUrl($m['id'], $key);
                                $action = $has ? (isPDF($path) ? "window.open('".$fileUrl."','_blank')" : "openDocFullscreen('".$fileUrl."')") : '';
                            ?>
                            <div class="mm-file-row">
                                <div class="fl" style="background:<?= $has ? '#ecfdf5' : '#f1f5f9' ?>;color:<?= $has ? '#059669' : $info[2] ?>;"><i class="fas <?= $info[1] ?>"></i></div>
                                <div class="fn">
                                    <div class="t"><?= $info[0] ?></div>
                                    <div class="s"><?= $has ? '<span style="color:#059669"><i class="fas fa-check-circle"></i> Uploaded · click to view</span>' : '<span style="color:#94a3b8">Not uploaded</span>' ?></div>
                                </div>
                                <?php if ($has): ?>
                                <button type="button" class="mm-fam-btn" onclick="<?= $action ?>" style="margin-left:auto"><i class="fas fa-eye"></i> View</button>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Status & Roles -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-purple"><i class="fas fa-id-badge"></i></div>
                            <div>
                                <div class="mm-card-title">Membership</div>
                                <div class="mm-card-subtitle">Type, tier &amp; roles</div>
                            </div>
                        </div>
                        <div class="mm-card-body">
                            <div class="mm-chips">
                                <span class="mm-chip slate"><?= e($regTypeMap[$m['registration_type'] ?? 'waiting'] ?? ucfirst((string)($m['registration_type'] ?? 'waiting'))) ?></span>
                                <span class="mm-chip green"><?= e($memTypeMap[$m['member_type'] ?? 'regular'] ?? ucfirst((string)($m['member_type'] ?? 'regular'))) ?></span>
                                <span class="mm-chip blue"><?= e($m['current_section'] ?: ($m['age_group'] ?: 'Unassigned')) ?></span>
                                <span class="mm-chip amber"><?= e(ucfirst((string)($m['membership_tier'] ?? 'temporary'))) ?></span>
                            </div>
                            <div style="height:12px"></div>
                            <div style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">Roles</div>
                            <div class="mm-roles">
                                <?php
                                $roles = [
                                    ['is_teacher','Teacher','chalkboard-user'],
                                    ['is_staff','Staff','user-tie'],
                                    ['is_committee','Committee','users'],
                                    ['is_volunteer','Volunteer','hand-holding-heart'],
                                ];
                                foreach ($roles as $r): $active = !empty($m[$r[0]]); ?>
                                <div class="mm-role<?= $active?' on':'' ?>">
                                    <i class="fas fa-<?= $r[2] ?>"></i>
                                    <span><?= $r[1] ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Quick links -->
                    <div class="mm-card">
                        <div class="mm-card-hdr">
                            <div class="mm-card-icon mm-icon-slate"><i class="fas fa-bolt"></i></div>
                            <div><div class="mm-card-title">Quick Actions</div></div>
                        </div>
                        <div class="mm-card-body" style="display:flex;flex-direction:column;gap:6px;">
                            <a class="mm-fam-btn" style="justify-content:center;padding:9px;" target="_blank" href="/admin/print_member.php?id=<?= (int)$m['id'] ?>"><i class="fas fa-file-pdf"></i> Print / ID Card</a>
                            <?php if ($famCanManage && $famGuardians): $g0 = $famGuardians[0]; ?>
                            <a class="mm-fam-btn" style="justify-content:center;padding:9px;color:#6d28d9;background:#f5f3ff;border-color:#ddd6fe;" href="/admin/dashboard.php?section=family&guardian=<?= (int)$g0['id'] ?>" onclick="return window.MemberNav ? MemberNav.manageFamily(<?= (int)$g0['id'] ?>) : true;"><i class="fas fa-people-roof"></i> Manage Family</a>
                            <?php endif; ?>
                            <?php if (in_array((string)($_SESSION['admin_role'] ?? ''), ['super_admin','school_admin','hr_dept','info_dept'], true)): ?>
                            <button type="button" class="mm-fam-btn" style="justify-content:center;padding:9px;color:#991b1b;background:#fee2e2;border-color:#fecaca;" onclick="if(confirm('Archive this member? This cannot be undone from here.')){archiveMember(<?= (int)$m['id'] ?>, <?= json_encode($fullName) ?>);}"><i class="fas fa-box-archive"></i> Archive Member</button>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /col-side -->
            </div><!-- /mm-sheet -->
        </div><!-- /view-preview -->

        <!-- ============== EDIT ============== -->
        <div id="view-edit" class="hidden">
            <form id="editMemberForm" onsubmit="return submitEditForm(event)" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)(generateCsrfToken() ?? '')) ?>">
                <?php $canEditStatus = hasRole(['hr_dept','info_dept']) && ($m['status'] ?? '') !== 'archived'; ?>

                <div class="mm-edit-grid">
                    <div class="edit-main">

                        <!-- Personal Information -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-green"><i class="fas fa-user"></i></div>
                                <div><div class="mm-card-title">Personal Information</div><div class="mm-card-subtitle">Identity &amp; demographics</div></div>
                            </div>
                            <div class="mm-card-body">
                                <div class="mm-form-group">
                                    <label class="mm-label">Student Name <span class="req">*</span></label>
                                    <input type="text" name="student_name" value="<?= e($m['student_name']) ?>" required class="mm-input" autocomplete="off">
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Father Name <span class="req">*</span></label>
                                        <input type="text" name="father_name" value="<?= e($m['father_name']) ?>" required class="mm-input" autocomplete="off">
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Grandfather Name</label>
                                        <input type="text" name="grandfather_name" value="<?= e($m['grandfather_name'] ?? '') ?>" class="mm-input" autocomplete="off">
                                    </div>
                                </div>
                                <div class="mm-form-group">
                                    <label class="mm-label">Christian Name <span class="hint">የክርስትና ስም</span></label>
                                    <input type="text" name="baptismal_name" value="<?= e($m['baptismal_name'] ?? '') ?>" class="mm-input" autocomplete="off" placeholder="e.g. Girma, Selamawit…">
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Gender</label>
                                        <select name="gender" class="mm-input">
                                            <option value="male" <?= sel($m['gender']??'','male') ?>>ወንድ (Male)</option>
                                            <option value="female" <?= sel($m['gender']??'','female') ?>>ሴት (Female)</option>
                                        </select>
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Date of Birth <span class="hint">(Ethiopian calendar)</span></label>
                                        <div class="mm-row-3">
                                            <input type="number" name="dob_day" value="<?= (int)($m['dob_ec_day'] ?? 0) ?>" placeholder="ቀን / Day" min="1" max="30" class="mm-input">
                                            <input type="number" name="dob_month" value="<?= (int)($m['dob_ec_month'] ?? 0) ?>" placeholder="ወር / Month" min="1" max="13" class="mm-input">
                                            <input type="number" name="dob_year" value="<?= (int)($m['dob_ec_year'] ?? 0) ?>" placeholder="ዓመት / Year" class="mm-input">
                                        </div>
                                    </div>
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Current Section <span class="hint">Age group / class</span></label>
                                        <input type="text" name="current_section" value="<?= e($m['current_section'] ?? '') ?>" class="mm-input" placeholder="e.g. Grade 5, Youth…" autocomplete="off">
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Age Group <span class="hint">(if different from section)</span></label>
                                        <select name="age_group" class="mm-input">
                                            <option value="">— Same as section —</option>
                                            <?php foreach (\App\Services\MemberCategory::sections() as $hrCode => $hrSec): ?>
                                            <option value="<?= e($hrCode) ?>" <?= sel($m['age_group'] ?? '', $hrCode) ?>><?= e($hrSec['am']) ?> (<?= e($hrSec['ages']) ?>)</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Education & Work -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-purple"><i class="fas fa-graduation-cap"></i></div>
                                <div><div class="mm-card-title">Education &amp; Work</div><div class="mm-card-subtitle">Academic &amp; spiritual education</div></div>
                            </div>
                            <div class="mm-card-body">
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Secular Education</label>
                                        <select name="education_level" class="mm-input">
                                            <option value="" <?= sel($m['education_level']??'','') ?>>— Select —</option>
                                            <option value="kg" <?= sel($m['education_level']??'','kg') ?>>KG</option>
                                            <option value="elementary" <?= sel($m['education_level']??'','elementary') ?>>Elementary (1-8)</option>
                                            <option value="high_school" <?= sel($m['education_level']??'','high_school') ?>>High School (9-12)</option>
                                            <option value="certificate" <?= sel($m['education_level']??'','certificate') ?>>Certificate</option>
                                            <option value="diploma" <?= sel($m['education_level']??'','diploma') ?>>Diploma</option>
                                            <option value="degree" <?= sel($m['education_level']??'','degree') ?>>Degree</option>
                                            <option value="masters" <?= sel($m['education_level']??'','masters') ?>>Masters</option>
                                            <option value="phd" <?= sel($m['education_level']??'','phd') ?>>PhD</option>
                                            <option value="illiterate" <?= sel($m['education_level']??'','illiterate') ?>>Illiterate</option>
                                        </select>
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label" style="color:#059669">✝ Spiritual Education</label>
                                        <select name="spiritual_education" class="mm-input" style="border-color:#10b981;background:#ecfdf5">
                                            <option value="" <?= sel($m['spiritual_education']??'','') ?>>— Select —</option>
                                            <?php foreach ($spEduMap as $v=>$lbl): if ($v === '') continue; ?>
                                            <option value="<?= e($v) ?>" <?= sel($m['spiritual_education']??'',$v) ?>><?= e($lbl) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="mm-form-group" style="margin-bottom:0">
                                    <label class="mm-label">Profession / Occupation</label>
                                    <input type="text" name="work_profession" value="<?= e($m['work_profession'] ?? '') ?>" class="mm-input" placeholder="e.g. Student, Teacher, Nurse…" autocomplete="off">
                                </div>
                            </div>
                        </div>

                        <!-- Contact -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-blue"><i class="fas fa-phone"></i></div>
                                <div><div class="mm-card-title">Contact</div><div class="mm-card-subtitle">Phones &amp; guardian</div></div>
                            </div>
                            <div class="mm-card-body">
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Primary Phone</label>
                                        <input type="tel" name="phone_number" value="<?= e($m['phone_number'] ?? '') ?>" class="mm-input" placeholder="09xxxxxxxx" autocomplete="tel">
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Alternate Phone</label>
                                        <input type="tel" name="alt_phone_number" value="<?= e($m['alt_phone_number'] ?? '') ?>" class="mm-input" placeholder="Optional" autocomplete="tel">
                                    </div>
                                </div>
                                <div class="mm-form-group">
                                    <label class="mm-label">Guardian Name</label>
                                    <input type="text" name="guardian_name" value="<?= e($m['guardian_name'] ?? '') ?>" class="mm-input" placeholder="Parent/legal guardian full name" autocomplete="off">
                                    <div class="mm-help">A guardian record is auto-created and siblings are detected when this is filled.</div>
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Guardian Phone 1</label>
                                        <input type="tel" name="guardian_phone1" value="<?= e($m['guardian_phone1'] ?? '') ?>" class="mm-input" placeholder="Primary guardian phone" autocomplete="tel">
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Guardian Phone 2</label>
                                        <input type="tel" name="guardian_phone2" value="<?= e($m['guardian_phone2'] ?? '') ?>" class="mm-input" placeholder="Optional" autocomplete="tel">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Address -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-red"><i class="fas fa-map-marker-alt"></i></div>
                                <div><div class="mm-card-title">Address</div><div class="mm-card-subtitle">Residence details</div></div>
                            </div>
                            <div class="mm-card-body">
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">City</label>
                                        <select name="city" class="mm-input">
                                            <option value="" <?= sel($m['city']??'','') ?>>— Select —</option>
                                            <option value="addis_ababa" <?= sel($m['city']??'','addis_ababa') ?>>Addis Ababa</option>
                                            <option value="oromia" <?= sel($m['city']??'','oromia') ?>>Oromia</option>
                                        </select>
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Sub City</label>
                                        <input type="text" name="sub_city" value="<?= e($m['sub_city'] ?? '') ?>" class="mm-input" placeholder="e.g. Bole" autocomplete="off">
                                    </div>
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Woreda</label>
                                        <input type="text" name="woreda" value="<?= e($m['woreda'] ?? '') ?>" class="mm-input" autocomplete="off">
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Mender</label>
                                        <input type="text" name="mender" value="<?= e($m['mender'] ?? '') ?>" class="mm-input" autocomplete="off">
                                    </div>
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Block No.</label>
                                        <input type="text" name="block_number" value="<?= e($m['block_number'] ?? '') ?>" class="mm-input" autocomplete="off">
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">House No.</label>
                                        <input type="text" name="house_number" value="<?= e($m['house_number'] ?? '') ?>" class="mm-input" autocomplete="off">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Roles / Positions -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-purple"><i class="fas fa-user-tag"></i></div>
                                <div><div class="mm-card-title">Positions &amp; Roles</div><div class="mm-card-subtitle">Departments and free roles (Synced with Identity &amp; Codes)</div></div>
                            </div>
                            <div class="mm-card-body">
                                <?php
                                require_once __DIR__ . '/backend/services/PositionSyncService.php';
                                $mmCat = \App\Services\PositionSyncService::catalogue($conn);
                                $mmHeld = \App\Services\PositionSyncService::currentPositionIds($conn, (int)$m['id']);
                                ?>
                                <?php if (empty($mmCat['departments']) && empty($mmCat['free'])): ?>
                                    <div class="mm-empty"><i class="fas fa-folder-open"></i> No positions defined yet. Configure them in Super Admin → Identity &amp; Codes.</div>
                                <?php else: ?>
                                <div class="mm-checks">
                                    <?php foreach (($mmCat['free'] ?? []) as $pos): ?>
                                    <label class="mm-check">
                                        <input type="checkbox" name="position_ids[]" value="<?= (int)$pos['id'] ?>" <?= in_array((int)$pos['id'], $mmHeld, true) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($pos['title_am']) ?> <span style="color:#94a3b8">[<?= htmlspecialchars($pos['role_code']) ?>]</span></span>
                                    </label>
                                    <?php endforeach; ?>
                                    <?php foreach (($mmCat['departments'] ?? []) as $pos): ?>
                                    <label class="mm-check">
                                        <input type="checkbox" name="position_ids[]" value="<?= (int)$pos['id'] ?>" <?= in_array((int)$pos['id'], $mmHeld, true) ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($pos['dept_code']) ?> · <?= htmlspecialchars($pos['title_am']) ?> <span style="color:#94a3b8">[<?= htmlspecialchars($pos['role_code']) ?>]</span></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div><!-- /edit-main -->

                    <div class="edit-side">

                        <!-- Photos upload -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-green"><i class="fas fa-camera"></i></div>
                                <div><div class="mm-card-title">Photos</div></div>
                            </div>
                            <div class="mm-card-body">
                                <div class="mm-photos">
                                    <div class="mm-photo-wrap">
                                        <div class="ph" style="cursor:default">
                                            <?php if (!empty($m['student_photo_path'])): ?>
                                                <img src="<?= fixPath($m['student_photo_path']) ?>" alt="Student">
                                            <?php else: ?>
                                                <div class="ph-empty"><i class="fas fa-user"></i><span>No Photo</span></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="lbl">Student</div>
                                        <input type="file" name="student_photo" accept="image/*" class="mm-input" style="margin-top:10px;padding:8px;font-size:12px">
                                    </div>
                                    <div class="mm-photo-wrap">
                                        <div class="ph" style="cursor:default">
                                            <?php if (!empty($m['guardian_photo_path'])): ?>
                                                <img src="<?= memberFileUrl($m['id'], 'guardian_photo_path') ?>" alt="Guardian">
                                            <?php else: ?>
                                                <div class="ph-empty"><i class="fas fa-user-group" style="font-size:26px"></i><span>No Photo</span></div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="lbl">Guardian</div>
                                        <input type="file" name="guardian_photo" accept="image/*" class="mm-input" style="margin-top:10px;padding:8px;font-size:12px">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Documents upload -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-blue"><i class="fas fa-folder-open"></i></div>
                                <div><div class="mm-card-title">Documents</div></div>
                            </div>
                            <div class="mm-card-body">
                                <?php foreach ($docs as $key=>$info):
                                    $has = !empty($m[$key]);
                                    $docLabels = ['doc_school_records_path'=>'School Records','doc_spiritual_path'=>'Spiritual Records','doc_signed_form_path'=>'Signed Form'];
                                ?>
                                <div class="mm-form-group" style="margin-bottom:10px">
                                    <label class="mm-label"><?= $docLabels[$key] ?? $info[0] ?></label>
                                    <?php if ($has): ?><span class="mm-file-ok"><i class="fas fa-check-circle"></i> Uploaded</span><?php endif; ?>
                                    <input type="file" name="<?= e($key) ?>" accept="image/*,.pdf" class="mm-input" style="padding:8px;font-size:12px">
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Status & Type -->
                        <div class="mm-card">
                            <div class="mm-card-hdr">
                                <div class="mm-card-icon mm-icon-amber"><i class="fas fa-cog"></i></div>
                                <div><div class="mm-card-title">Status &amp; Type</div></div>
                            </div>
                            <div class="mm-card-body">
                                <div class="mm-form-group">
                                    <label class="mm-label">Status</label>
                                    <?php if ($canEditStatus): ?>
                                    <select name="status" class="mm-input">
                                        <option value="active" <?= sel($m['status']??'','active') ?>>Active</option>
                                        <option value="inactive" <?= sel($m['status']??'','inactive') ?>>Inactive</option>
                                        <?php if (!in_array($m['status']??'', ['active','inactive'], true)): ?>
                                        <option value="<?= htmlspecialchars((string)$m['status']) ?>" selected><?= htmlspecialchars(ucfirst((string)$m['status'])) ?> (current)</option>
                                        <?php endif; ?>
                                    </select>
                                    <div class="mm-help">Manual status is HR / Information decision only. Attendance warnings never change it automatically.</div>
                                    <?php else: ?>
                                    <input type="text" class="mm-input" value="<?= htmlspecialchars(ucfirst((string)($m['status'] ?? 'active'))) ?>" readonly disabled>
                                    <div class="mm-help">Only HR or Information can change status.</div>
                                    <?php endif; ?>
                                </div>
                                <div class="mm-row">
                                    <div class="mm-form-group">
                                        <label class="mm-label">Registration</label>
                                        <select name="registration_type" class="mm-input">
                                            <option value="waiting" <?= sel($m['registration_type']??'','waiting') ?>>Waiting</option>
                                            <option value="transfer" <?= sel($m['registration_type']??'','transfer') ?>>Transfer</option>
                                            <option value="direct" <?= sel($m['registration_type']??'','direct') ?>>Direct</option>
                                        </select>
                                    </div>
                                    <div class="mm-form-group">
                                        <label class="mm-label">Member Type</label>
                                        <select name="member_type" class="mm-input">
                                            <option value="regular" <?= sel($m['member_type']??'','regular') ?>>Regular</option>
                                            <option value="special_regular" <?= sel($m['member_type']??'','special_regular') ?>>Special Regular</option>
                                            <option value="honorary" <?= sel($m['member_type']??'','honorary') ?>>Honorary</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div><!-- /edit-side -->
                </div><!-- /mm-edit-grid -->

                <!-- Save bar -->
                <div class="mm-savebar">
                    <button type="button" class="mm-fam-btn" onclick="switchManageTab('preview')" style="padding:10px 14px"><i class="fas fa-xmark"></i> Cancel</button>
                    <div class="sec">Changes are saved to the database immediately.</div>
                    <button type="submit" class="mm-save"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div><!-- /view-edit -->

    </div><!-- /mm-content -->
</div>

<script>
/* ── Tiny shim: define helpers only if the host page hasn't
      already (info_manage_member is injected via innerHTML into
      the dashboard, which already defines switchManageTab /
      submitEditForm / openDocFullscreen / archiveMember). When
      opened standalone (direct URL), these shims provide a
      working minimal experience before the redirect fires. ── */
(function(){
    if (typeof window.switchManageTab !== 'function') {
        window.switchManageTab = function(which){
            var prev = document.getElementById('view-preview');
            var edit = document.getElementById('view-edit');
            var tPrev = document.getElementById('tab-preview');
            var tEdit = document.getElementById('tab-edit');
            if (!prev || !edit) return;
            var showPrev = (which === 'preview');
            prev.style.display = showPrev ? 'block' : 'none';
            edit.style.display = showPrev ? 'none' : 'block';
            edit.classList.toggle('hidden', showPrev);
            if (tPrev) tPrev.classList.toggle('active', showPrev);
            if (tEdit) tEdit.classList.toggle('active', !showPrev);
        };
    }
    if (typeof window.openDocFullscreen !== 'function') {
        window.openDocFullscreen = function(url){ if (url) window.open(url,'_blank','noopener'); };
    }
    if (typeof window.archiveMember !== 'function') {
        window.archiveMember = function(){ alert('Archive action is available inside the dashboard.'); };
    }
    // Default to preview on first load
    window.switchManageTab('preview');
})();
</script>
