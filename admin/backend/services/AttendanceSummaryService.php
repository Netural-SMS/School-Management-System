<?php
/**
 * Single source of truth for monthly attendance summaries.
 *
 * WHY THIS SERVICE EXISTS
 * -----------------------
 * Two separate writers used to maintain `attendance_summary`:
 *   - workflow.php keyed rows on TODAY'S Gregorian month and computed
 *     rate = present / total,
 *   - api_attendance.php keyed rows on the RECORDED date's month and
 *     computed rate = (present + 0.5*late) / total.
 * Whichever endpoint saved last overwrote the same (student, year, month)
 * row with different semantics — silently corrupting attendance rates.
 *
 * DESIGN (industry-standard derived-cache pattern)
 * ------------------------------------------------
 * The `attendance` table is the only source of truth. Summary rows are a
 * DERIVED cache: every save recomputes the affected student+month FROM the
 * source rows (idempotent, self-healing, replay-safe) instead of applying
 * incremental deltas that can drift. One formula, one key, one writer:
 *
 *   key     = (student_id, academic_year_id, GC month, GC year) — derived
 *             from the RECORDED attendance_date, never from "today".
 *             The key has no class, so aggregation is per-student across
 *             classes (a transferred student's month stays whole).
 *   formula = (present + 0.5*late) / total — late counts half, matching
 *             the read-side summary UIs.
 *
 * Scale: recomputation touches only the student's own rows through the
 * uq_att_student_class_date (student_id, class_id, attendance_date) prefix
 * index — O(days recorded) regardless of roster size.
 */

namespace App\Services;

final class AttendanceSummaryService
{
    /** Monthly rate below which a low-attendance alert is raised. */
    public const LOW_ATTENDANCE_THRESHOLD = 70.0;

    /** Alerts for the same student are suppressed for this many days. */
    public const ALERT_SUPPRESSION_DAYS = 7;

    /**
     * Recompute and upsert the summary row for one student for the month
     * containing $attendanceDate. Returns the computed stats, or null when
     * the persistence layer is unavailable.
     *
     * @return array{month:int,year:int,total:int,present:int,absent:int,late:int,excused:int,rate:?float}|null
     */
    public static function recompute(
        \mysqli $conn,
        int $studentId,
        string $attendanceDate,
        ?int $academicYearId
    ): ?array {
        if ($studentId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate)) {
            return null;
        }
        $timestamp = strtotime($attendanceDate);
        if ($timestamp === false) {
            return null;
        }
        $gcMonth = (int)date('n', $timestamp);
        $gcYear = (int)date('Y', $timestamp);
        $startOfMonth = date('Y-m-01', $timestamp);
        $endOfMonth = date('Y-m-t', $timestamp);

        // Student-level aggregation (summary key carries no class), bounded by
        // the student/date index prefix.
        $statement = $conn->prepare(
            "SELECT
                COUNT(*) AS total_days,
                SUM(status = 'present') AS present_days,
                SUM(status = 'absent')  AS absent_days,
                SUM(status = 'late')    AS late_days,
                SUM(status = 'excused') AS excused_days
             FROM attendance
             WHERE student_id = ?
               AND attendance_date BETWEEN ? AND ?"
        );
        if (!$statement) {
            return null;
        }
        $statement->bind_param('iss', $studentId, $startOfMonth, $endOfMonth);
        if (!$statement->execute()) {
            $statement->close();
            return null;
        }
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        if (!is_array($row)) {
            return null;
        }

        $totalDays = (int)$row['total_days'];
        $presentDays = (int)$row['present_days'];
        $absentDays = (int)$row['absent_days'];
        $lateDays = (int)$row['late_days'];
        $excusedDays = (int)$row['excused_days'];
        $rate = $totalDays > 0
            ? round((($presentDays + $lateDays * 0.5) / $totalDays) * 100, 2)
            : null;

        try {
            if ($academicYearId === null) {
                // NULL never participates in a UNIQUE match, so an
                // ON DUPLICATE KEY upsert would keep inserting fresh rows.
                // Replace the no-year row deterministically instead.
                $delete = $conn->prepare(
                    'DELETE FROM attendance_summary
                     WHERE student_id = ? AND academic_year_id IS NULL AND month = ? AND year = ?'
                );
                if ($delete) {
                    $delete->bind_param('iii', $studentId, $gcMonth, $gcYear);
                    $delete->execute();
                    $delete->close();
                }
                $insert = $conn->prepare(
                    'INSERT INTO attendance_summary
                        (student_id, academic_year_id, month, year,
                         total_days, present_days, absent_days, late_days, excused_days,
                         attendance_rate)
                     VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                if ($insert) {
                    $insert->bind_param(
                        'iiiiiiiid',
                        $studentId,
                        $gcMonth,
                        $gcYear,
                        $totalDays,
                        $presentDays,
                        $absentDays,
                        $lateDays,
                        $excusedDays,
                        $rate
                    );
                    $insert->execute();
                    $insert->close();
                }
            } else {
                $upsert = $conn->prepare(
                    "INSERT INTO attendance_summary
                        (student_id, academic_year_id, month, year,
                         total_days, present_days, absent_days, late_days, excused_days,
                         attendance_rate)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        total_days = VALUES(total_days),
                        present_days = VALUES(present_days),
                        absent_days = VALUES(absent_days),
                        late_days = VALUES(late_days),
                        excused_days = VALUES(excused_days),
                        attendance_rate = VALUES(attendance_rate)"
                );
                if ($upsert) {
                    $yearValue = (int)$academicYearId;
                    $upsert->bind_param(
                        'iiiiiiiiid',
                        $studentId,
                        $yearValue,
                        $gcMonth,
                        $gcYear,
                        $totalDays,
                        $presentDays,
                        $absentDays,
                        $lateDays,
                        $excusedDays,
                        $rate
                    );
                    $upsert->execute();
                    $upsert->close();
                }
            }
        } catch (\Throwable $error) {
            // A summary failure must never fail the attendance save itself.
            error_log('Attendance summary persistence failed: ' . $error->getMessage());
        }

        return [
            'month' => $gcMonth,
            'year' => $gcYear,
            'total' => $totalDays,
            'present' => $presentDays,
            'absent' => $absentDays,
            'late' => $lateDays,
            'excused' => $excusedDays,
            'rate' => $rate,
        ];
    }

    /**
     * Refresh the student-level rollup (average of monthly rates). The
     * compatibility columns come from PHP migration 002; a deployment
     * without them simply skips this step.
     */
    public static function refreshMemberTotals(\mysqli $conn, int $studentId): void
    {
        if ($studentId <= 0) {
            return;
        }
        try {
            $statement = $conn->prepare(
                'SELECT AVG(attendance_rate) AS avg_rate
                 FROM attendance_summary
                 WHERE student_id = ? AND attendance_rate IS NOT NULL'
            );
            if (!$statement) {
                return;
            }
            $statement->bind_param('i', $studentId);
            $statement->execute();
            $average = $statement->get_result()->fetch_assoc();
            $statement->close();

            if ($average && $average['avg_rate'] !== null) {
                // Audit 2026-10-02, finding E: this used CURDATE(), so ANY
                // save — backfilling last term's register, correcting a row
                // from six months ago — stamped the student as having attended
                // TODAY. last_attendance_date must describe the attendance
                // data, not the moment the row happened to be written.
                //
                // Derived from the same source that feeds total_attendance_rate
                // (the `attendance` table; HR/Mezmur keep their own datasets by
                // product rule and are never merged here). Index-backed:
                // idx_att_student_date (student_id, attendance_date) from
                // sql/028 — and uq_att_student_class_date before it — are both
                // student_id-prefixed, so MAX() on an equality-bound student is
                // a bounded index probe, not a scan.
                //
                // COALESCE keeps any existing value when the student has no
                // attendance rows left (e.g. the last row was deleted), so the
                // fix can never blank a populated column.
                $update = $conn->prepare(
                    'UPDATE students
                     SET total_attendance_rate = ?,
                         last_attendance_date = COALESCE(
                             (SELECT MAX(a.attendance_date)
                                FROM attendance a
                               WHERE a.student_id = ?),
                             last_attendance_date
                         )
                     WHERE id = ?'
                );
                if ($update) {
                    $avgRate = round((float)$average['avg_rate'], 2);
                    $update->bind_param('dii', $avgRate, $studentId, $studentId);
                    $update->execute();
                    $update->close();
                }
            }
        } catch (\Throwable $error) {
            // Compatibility columns may be absent; never fail the save path.
            error_log('Attendance student totals refresh skipped: ' . $error->getMessage());
        }
    }

    /**
     * Legacy `attendance_issue` bell REMOVED (attendance alerting Phase 2,
     * 2026-10-19). Low-attendance and consecutive-absence warnings are now
     * raised exclusively by App\Services\AttendanceWarningService from the
     * per-department policy thresholds (requirements §4–§6, §8). Kept as a
     * no-op so any external caller keeps working.
     */
    public static function maybeAlertLowAttendance(\mysqli $conn, int $studentId, ?float $rate): void
    {
        // intentionally empty — see AttendanceWarningService::evaluate()
    }

    /**
     * One-call convenience used by attendance writers: recompute the month
     * of the saved record, refresh the student rollup, then alert if needed.
     * Derived-cache semantics make this safe to call repeatedly.
     *
     * @return array{month:int,year:int,total:int,present:int,absent:int,late:int,excused:int,rate:?float}|null
     */
    public static function recordSaved(
        \mysqli $conn,
        int $studentId,
        string $attendanceDate,
        ?int $academicYearId
    ): ?array {
        $stats = self::recompute($conn, $studentId, $attendanceDate, $academicYearId);
        if ($stats === null) {
            return null;
        }
        self::refreshMemberTotals($conn, $studentId);
        return $stats;
    }
}
