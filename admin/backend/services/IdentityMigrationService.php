<?php
/**
 * Format-v2 renumbering engine — shared by the CLI tool and the web
 * runner so the logic can never drift.
 *
 * Every active student is (re)issued a v2 code:
 *   * prefix recomputed from CURRENT positions (v2 composition) or the
 *     category letter when the student holds none,
 *   * tail is a fresh random unique 5-digit number,
 *   * old code preserved in legacy_student_code + student_code_migrations,
 *   * QR image regenerated.
 *
 * Students whose stored code already matches their computed v2 prefix
 * are skipped, which makes the runner idempotent and cheap to re-run.
 * Keyset pagination (id > last) keeps memory flat at any roster size.
 */
namespace App\Services;

require_once __DIR__ . '/IdentityCodeService.php';
require_once __DIR__ . '/StudentCategory.php';

final class IdentityMigrationService
{
    private const PAGE = 100;
    private const LOG_CAP = 200;

    /**
     * @return array{renumbered:int,qr:int,errors:list<string>,log:list<string>,skipped_pending:int}
     */
    public static function renumberAll(\mysqli $conn, bool $dryRun): array
    {
        $renumbered = 0;
        $qr = 0;
        $pending = 0;
        $errors = [];
        $log = [];
        $lastId = 0;

        while (true) {
            $stmt = $conn->prepare(
                "SELECT id, student_code,  student_name, father_name
                 FROM students
                 WHERE status != 'archived' AND id > ?
                 ORDER BY id ASC LIMIT " . self::PAGE
            );
            if (!$stmt) {
                $errors[] = 'Paged query prepare failed.';
                break;
            }
            $stmt->bind_param('i', $lastId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows === 0) {
                $stmt->close();
                break;
            }

            while ($row = $result->fetch_assoc()) {
                $studentId = (int)$row['id'];
                $lastId = $studentId;
                $old = (string)($row['student_code'] ?? '');
                $name = $row['student_name'] . ' ' . $row['father_name'];

                $segments = IdentityCodeService::resolveStaffSegments($conn, $studentId);
                if ($segments !== null && $segments !== []) {
                    $prefix = $segments[0];
                    $letter = null;
                } else {
                    $prefix = null;
                    $letter = null;
                }

                if ($prefix === null && $letter === null) {
                    $pending++;
                    if (count($log) < self::LOG_CAP) {
                        $log[] = "  {$name}: no positions & no resolvable category — stays pending";
                    }
                    continue;
                }
                $expected = $prefix ?? $letter;

                $parsed = IdentityCodeService::parse($old);
                if ($parsed !== null && $old !== '' &&
                    ($parsed['kind'] === 'staff' ? $parsed['prefix'] : $parsed['letter']) === $expected) {
                    continue; // already v2 with the correct prefix
                }

                if (count($log) < self::LOG_CAP) {
                    $log[] = "  {$name}: {$old} → {$expected}-" . ($dryRun ? '#####' : '(new tail)');
                }

                if ($dryRun) {
                    $renumbered++;
                    continue;
                }

                try {
                    $newCode = $letter !== null
                        ? IdentityCodeService::allocateStudent($conn, $letter)
                        : IdentityCodeService::allocateStaff($conn, [$prefix]);

                    $update = $conn->prepare(
                        'UPDATE students SET legacy_student_code = ?, student_code = ? WHERE id = ?'
                    );
                    $update->bind_param('ssi', $old, $newCode, $studentId);
                    if (!$update->execute()) {
                        $errors[] = "Update failed for student {$studentId}";
                        $update->close();
                        continue;
                    }
                    $update->close();

                    $logStmt = $conn->prepare(
                        'INSERT INTO student_code_migrations (student_id, old_code, new_code, reason)
                         VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE old_code = VALUES(old_code), new_code = VALUES(new_code), reason = VALUES(reason)'
                    );
                    $reason = 'format_v2_renumber';
                    $logStmt->bind_param('isss', $studentId, $old, $newCode, $reason);
                    $logStmt->execute();
                    $logStmt->close();

                    $renumbered++;
                    if (IdentityCodeService::regenerateQr($conn, $studentId)) {
                        $qr++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Student {$studentId}: " . $e->getMessage();
                }
            }
            $result->free();
            $stmt->close();
        }

        return [
            'renumbered' => $renumbered,
            'qr' => $qr,
            'errors' => $errors,
            'log' => $log,
            'skipped_pending' => $pending,
        ];
    }
}
