<?php
/**
 * Cross-department student role synchronization.
 *
 * Membership type (regular / special_regular / honorary) is manually selected
 * by an authorized editor. Roles, positions, teacher accounts and teaching
 * assignments must never promote or demote it. Role flags and identity codes
 * continue to synchronize independently.
 */

/**
 * Read-only compatibility snapshot for legacy synchronization callers.
 *
 * @deprecated Student types are manual; this function never changes them.
 * @param array $overrides Legacy role overrides, intentionally ignored.
 * @return array ['changed' => bool, 'old_type' => string, 'new_type' => string]
 */
function syncMemberType(mysqli $conn, int $studentId, array $overrides = []): array {
    if ($studentId <= 0) return ['changed' => false, 'old_type' => '', 'new_type' => ''];

    $stmt = $conn->prepare("SELECT student_type FROM students WHERE id = ?");
    if (!$stmt) return ['changed' => false, 'old_type' => '', 'new_type' => ''];
    $stmt->bind_param("i", $studentId);
    $stmt->execute();
    $student = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$student) return ['changed' => false, 'old_type' => '', 'new_type' => ''];
    $type = 'regular';
    return ['changed' => false, 'old_type' => $type, 'new_type' => $type];
}

/**
 * Sync is_teacher flag on a student when a teacher account is created/deleted.
 * Student type is manual and is never changed by this operation.
 *
 * @param mysqli $conn
 * @param int    $studentId
 * @param bool   $isNowTeacher  true if teacher account was created, false if removed
 * @return array sync result
 */
function syncMemberTeacherFlag(mysqli $conn, int $studentId, bool $isNowTeacher): array {
    if ($studentId <= 0) return ['changed' => false];
    
    $flag = $isNowTeacher ? 1 : 0;
    
    // If removing teacher flag, check if there are OTHER teacher accounts pointing to this student
    if (!$isNowTeacher) {
        $stmt = $conn->prepare("SELECT COUNT(*) as c FROM users WHERE student_id = ? AND role = 'teacher' AND is_active = 1");
        if ($stmt) {
            $stmt->bind_param("i", $studentId);
            $stmt->execute();
            $count = (int)$stmt->get_result()->fetch_assoc()['c'];
            $stmt->close();
            if ($count > 0) {
                // Still has active teacher accounts — keep the flag
                $flag = 1;
            }
        }
    }
    
    // Update the flag
    $stmt = $conn->prepare("UPDATE students SET is_teacher = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $flag, $studentId);
        $stmt->execute();
        $stmt->close();
    }

    // Identity v2 convergence: grant/remove the position mapped to
    // is_teacher (when the school defined one) and re-code the student so
    // flags, positions and codes never diverge. Best-effort.
    try {
        require_once __DIR__ . '/services/PositionSyncService.php';
        \App\Services\PositionSyncService::syncPositionFromFlag($conn, $studentId, 'is_teacher', (bool)$flag);
    } catch (\Throwable $e) {
        error_log('teacher position convergence skipped: ' . $e->getMessage());
    }

    // Preserve the legacy result shape without reclassifying the student.
    return syncMemberType($conn, $studentId, ['is_teacher' => $flag]);
}

/**
 * Retired automatic classification entry point. Kept as a no-op for legacy
 * callers; manual student types must never be repaired from role evidence.
 *
 * @deprecated Student types are edited explicitly, not synchronized.
 * @return array ['checked' => int, 'fixed' => int, 'details' => array]
 */
function batchSyncMemberTypes(mysqli $conn): array {
    return ['checked' => 0, 'fixed' => 0, 'details' => []];
}

/**
 * Get student role summary for display.
 * Returns a human-readable array of active roles.
 *
 * @param array $student  Student row from database
 * @return array ['roles' => ['Teacher', 'Staff'], 'is_special' => bool]
 */
function getMemberRoleSummary(array $student): array {
    $roles = [];
    if (!empty($student['is_teacher']))   $roles[] = 'Teacher';
    if (!empty($student['is_staff']))     $roles[] = 'Staff';
    if (!empty($student['is_committee'])) $roles[] = 'Committee';
    if (!empty($student['is_volunteer'])) $roles[] = 'Volunteer';
    
    return [
        'roles' => $roles,
        'is_special' => false,
        'type_label' => 'Regular'
    ];
}
