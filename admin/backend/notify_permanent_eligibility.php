<?php
/**
 * CLI cron: notify HR when temporary students reach the 6-month eligibility
 * for promotion to permanent. Idempotent — uses permanent_eligibility_notified_at.
 *
 * Usage (cron, daily):
 *   php /path/to/admin/backend/notify_permanent_eligibility.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../../config.php';
// Cron has no browser session; attribute actions to a system actor.
$_SESSION['admin_role'] = 'system';
$_SESSION['admin_id'] = 0;
require_once __DIR__ . '/workflow.php';

if (!($conn instanceof mysqli) || $conn->connect_error) {
    fwrite(STDERR, "DB connection unavailable.\n");
    exit(1);
}

$conn->set_charset('utf8mb4');

// Find eligible, not-yet-notified temporary students.
$sql = "SELECT id, student_name, father_name, student_code
        FROM students
        WHERE membership_tier = 'temporary'
          AND status != 'archived'
          AND registered_at <= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
          AND permanent_eligibility_notified_at IS NULL";
$res = $conn->query($sql);
if (!$res) {
    fwrite(STDERR, "Query failed: " . $conn->error . "\n");
    exit(1);
}

$count = 0;
while ($row = $res->fetch_assoc()) {
    $studentId = (int)$row['id'];
    $name = trim($row['student_name'] . ' ' . $row['father_name']);

    // Create a department task for HR (fires task_assigned notification to hr_dept).
    if (function_exists('createDepartmentTask')) {
        createDepartmentTask($conn, "Promote eligible student to permanent: $name", [
            'description' => "Student $name has completed 6 months as a temporary student and is eligible for promotion to permanent. Please review and promote.",
            'task_type' => 'action',
            'priority' => 'normal',
            'to_dept' => 'hr_dept',
            'related_student_id' => $studentId,
        ]);
    }

    // Fire the dedicated eligibility notification (hr_dept + super_admin + school_admin).
    if (function_exists('sendNotification')) {
        sendNotification($conn, 'student_eligible_permanent',
            "Student Eligible for Permanent Promotion",
            "$name has completed 6 months and is eligible for promotion to permanent.",
            [
                'data' => ['student_id' => $studentId, 'student_code' => $row['student_code']],
            ]
        );
    }

    // Mark notified (dedup).
    $stmt = $conn->prepare("UPDATE students SET permanent_eligibility_notified_at = NOW() WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $stmt->close();
    }

    $count++;
    fwrite(STDOUT, "Notified student #$studentId ($name)\n");
}

fwrite(STDOUT, "Done. $count student(s) notified.\n");
