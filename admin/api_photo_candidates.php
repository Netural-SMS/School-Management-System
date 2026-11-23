<?php
/**
 * Admin API for photo candidate conflict resolution.
 *
 * GET  ?action=list-pending   → list candidates pending admin decision
 * POST ?action=decide         → {candidate_id, decision: 'accept'|'reject'}
 *                                Sets the candidate as current (accept) or
 *                                archives it (reject). Both actions are
 *                                transactional and update the denormalized
 *                                students.student_photo_path.
 *
 * Access: super_admin, school_admin, info_dept, hr_dept, photo_importer.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/backend/services/StudentFileService.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
// access_control.php auto-runs auth + role gating on include.

header('Content-Type: application/json; charset=utf-8');

$action = (string)($_REQUEST['action'] ?? 'list-pending');
$canDecide = in_array(
    (string)($_SESSION['admin_role'] ?? ''),
    ['super_admin', 'school_admin', 'info_dept', 'hr_dept', 'photo_importer'],
    true
);
if (!$canDecide) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Information, HR, Admin, or Photo Importer staff can resolve photo conflicts.']);
    exit;
}

if ($action === 'list-pending') {
    try {
        $list = pendingCandidates($conn);
    } catch (Throwable $e) {
        http_response_code(500);
        $out = json_encode(['status' => 'error',
            'message' => 'Could not load pending reviews: ' . $e->getMessage()],
            JSON_UNESCAPED_UNICODE);
        echo $out === false
            ? '{"status":"error","message":"Could not load pending reviews."}'
            : $out;
        exit;
    }
    echo json_encode(['status' => 'success', 'items' => $list]);
    exit;
}

if ($action === 'decide' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON body.']);
        exit;
    }
    $candidateId = (int)($body['candidate_id'] ?? 0);
    $decision = (string)($body['decision'] ?? '');
    if ($candidateId <= 0 || !in_array($decision, ['accept', 'reject'], true)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'candidate_id and decision (accept|reject) are required.']);
        exit;
    }
    $adminId = (int)($_SESSION['admin_id'] ?? 0);
    $result = decideCandidate($conn, $candidateId, $decision, $adminId);
    if (!$result['ok']) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
exit;

// ------------------------------------------------------------------
function pendingCandidates(mysqli $conn): array {
    $sql = "SELECT c.id,
                   c.student_id,
                   c.field,
                   c.stored_path,
                   c.mime,
                   c.byte_size,
                   c.width,
                   c.height,
                   c.taken_at,
                   c.operator_admin_id,
                   u.username AS operator_username,
                   u.full_name AS operator_name,
                   c.device_id,
                   m.student_name,
                   m.student_code,
                   m.father_name,
                   cur.id AS current_id,
                   cur.stored_path AS current_path,
                   cur.taken_at AS current_taken_at,
                   cur.operator_admin_id AS current_operator_id
              FROM student_photo_candidates c
              JOIN students m ON m.id = c.student_id
              LEFT JOIN users u ON u.id = c.operator_admin_id
              LEFT JOIN student_photo_candidates cur
                ON cur.student_id = c.student_id AND cur.field = c.field
               AND cur.is_current = 1 AND cur.archived = 0
             WHERE c.decision_pending = 1 AND c.archived = 0
             ORDER BY c.created_at DESC
             LIMIT 200";
    $res = $conn->query($sql);
    $items = [];
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = [
            'id' => (int)$row['id'],
            'student_id' => (int)$row['student_id'],
            'student_name' => $row['student_name'] ?? '',
            'student_code' => $row['student_code'] ?? '',
            'father_name' => $row['father_name'] ?? '',
            'field' => $row['field'],
            'path' => '/' . ltrim((string)$row['stored_path'], '/'),
            'mime' => $row['mime'],
            'byte_size' => (int)$row['byte_size'],
            'width' => (int)$row['width'],
            'height' => (int)$row['height'],
            'taken_at' => $row['taken_at'],
            'operator_admin_id' => (int)$row['operator_admin_id'],
            'operator_name' => $row['operator_name'] ?: $row['operator_username'] ?: ('Admin #' . (int)$row['operator_admin_id']),
            'current_path' => $row['current_path'] ? '/' . ltrim((string)$row['current_path'], '/') : null,
            'current_taken_at' => $row['current_taken_at'],
            'has_current' => !empty($row['current_id']),
        ];
    }
    return $items;
}

function decideCandidate(mysqli $conn, int $candidateId, string $decision, int $adminId): array {
    $conn->begin_transaction();
    try {
        $sel = $conn->prepare(
            "SELECT id, student_id, field, stored_path FROM student_photo_candidates
              WHERE id = ? AND archived = 0 LIMIT 1"
        );
        $sel->bind_param('i', $candidateId);
        $sel->execute();
        $cand = $sel->get_result()->fetch_assoc();
        $sel->close();
        if (!$cand) {
            $conn->rollback();
            return ['status' => 'error', 'message' => 'Candidate not found.'];
        }
        $studentId = (int)$cand['student_id'];
        $field = (string)$cand['field'];
        $newPath = (string)$cand['stored_path'];

        if ($decision === 'accept') {
            // De-throne existing current.
            $conn->query(
                "UPDATE student_photo_candidates
                    SET is_current = 0
                  WHERE student_id = $studentId AND field = '" .
                $conn->real_escape_string($field) .
                "' AND is_current = 1 AND id <> $candidateId"
            );
            $u = $conn->prepare(
                "UPDATE student_photo_candidates
                    SET is_current = 1,
                        decision_pending = 0,
                        archived = 0,
                        decided_at = NOW(),
                        decided_by_admin_id = ?
                  WHERE id = ?"
            );
            $u->bind_param('ii', $adminId, $candidateId);
            $u->execute();
            $u->close();

            $col = $field === 'guardian_photo' ? 'guardian_photo_path' : 'student_photo_path';
            $u2 = $conn->prepare("UPDATE students SET $col = ? WHERE id = ?");
            $u2->bind_param('si', $newPath, $studentId);
            $u2->execute();
            $u2->close();
        } else {
            // Reject: archive this candidate (keep for audit), do not
            // change current photo.
            $u = $conn->prepare(
                "UPDATE student_photo_candidates
                    SET is_current = 0,
                        decision_pending = 0,
                        archived = 1,
                        decided_at = NOW(),
                        decided_by_admin_id = ?
                  WHERE id = ?"
            );
            $u->bind_param('ii', $adminId, $candidateId);
            $u->execute();
            $u->close();
        }

        $conn->commit();
        return ['status' => 'success', 'decision' => $decision];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['status' => 'error', 'message' => 'Could not save decision: ' . $e->getMessage()];
    }
}
