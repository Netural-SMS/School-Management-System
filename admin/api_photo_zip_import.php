<?php
/**
 * Z2-Z5 — ZIP airlock import.
 *
 * Accepts a ZIP bundle exported from the mobile app, verifies the
 * manifest structure, validates the HMAC signature (warn, don't block,
 * if mismatched — same as the spec says the admin is shown a warning),
 * extracts to a temp dir, and feeds every photo through
 * PhotoUploadService::ingestFromFile() so they land through the same
 * receipt+candidate pipeline as online syncs.
 *
 * After commit, the ZIP is moved into admin/uploads/members/photo_imports/
 * for audit; on failure the temp dir is cleaned up.
 *
 * Access: super_admin, school_admin, info_dept, hr_dept, photo_importer.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/backend/services/PhotoUploadService.php';
if (session_status() === PHP_SESSION_NONE) @session_start();
ensureAdminAccess();

use App\Services\PhotoUploadService;

header('Content-Type: application/json; charset=utf-8');

$role = (string)($_SESSION['admin_role'] ?? '');
$allowed = ['super_admin','school_admin','info_dept','hr_dept','photo_importer'];
if (!in_array($role, $allowed, true)) {
    http_response_code(403);
    echo json_encode(['status'=>'error','message'=>'Access denied.']);
    exit;
}
$operatorId = (int)($_SESSION['admin_id'] ?? 0);
if ($operatorId <= 0) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'You must be logged in.']);
    exit;
}

$action = $_GET['action'] ?? 'upload';

if ($action === 'upload') {
    handleUpload($operatorId);
} elseif ($action === 'recent') {
    handleRecent($operatorId);
} else {
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Unknown action.']);
}

function handleUpload(int $operatorId): void {
    global $conn;
    if (!isset($_FILES['zip']) || !is_array($_FILES['zip'])) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'No ZIP file uploaded.']); return;
    }
    $f = $_FILES['zip'];
    if ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Upload error: '.($f['error']??'unknown')]); return;
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    $size = @filesize($tmp);
    if ($tmp === '' || !is_uploaded_file($tmp) || $size === false || $size <= 0) {
        http_response_code(400);
        echo json_encode(['status'=>'error','message'=>'Upload could not be verified.']); return;
    }
    if ($size > 200 * 1024 * 1024) { // 200 MB cap (≈100 photos × 2 MB)
        http_response_code(413);
        echo json_encode(['status'=>'error','message'=>'ZIP exceeds 200 MB cap.']); return;
    }

    // ZipArchive availability check.
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Server is missing the ZipArchive extension (ext-zip). Enable it to import.']); return;
    }

    // Extract to temp dir.
    $tmpDir = sys_get_temp_dir() . '/fkss_zip_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpDir, 0700, true)) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Could not create temp extract directory.']); return;
    }
    try {
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Could not open ZIP (corrupt?).');
        }
        if (!$zip->extractTo($tmpDir)) {
            $zip->close();
            throw new RuntimeException('Could not extract ZIP.');
        }
        $zip->close();

        $manifestPath = $tmpDir . '/manifest.json';
        $sigPath = $tmpDir . '/manifest.sig';
        if (!is_file($manifestPath)) {
            throw new RuntimeException('manifest.json missing — this is not a valid FKSS photo bundle.');
        }
        $manifestRaw = file_get_contents($manifestPath);
        if ($manifestRaw === false) throw new RuntimeException('Could not read manifest.json.');
        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || ($manifest['manifest_version'] ?? '') !== 'fkss-photo-airlock-v1') {
            throw new RuntimeException('Unsupported manifest version.');
        }
        $photos = $manifest['photos'] ?? [];
        if (!is_array($photos) || !$photos) {
            throw new RuntimeException('Manifest has no photos listed.');
        }

        // HMAC verification (we don't have server-issued pairing keys yet
        // so we accept with a warning — v1 behavior matches spec).
        $sigValid = false; $sigWarning = 'Per-install key only (server pairing pending).';
        if (is_file($sigPath)) {
            $sigHex = trim((string)file_get_contents($sigPath));
            $deviceId = (string)($manifest['device_id'] ?? '');
            $key = lookupDeviceHmacKey($conn, $deviceId);
            if ($key !== null) {
                $expected = hash_hmac('sha256', $manifestRaw, $key);
                if (hash_equals($expected, $sigHex)) {
                    $sigValid = true;
                    $sigWarning = null;
                } else {
                    $sigWarning = 'HMAC signature does not match the paired device key.';
                }
            }
        }

        // Begin batch transaction (master row + per-photo ingest inside its own tx).
        $conn->begin_transaction();
        try {
            $batchUuid = (string)($manifest['bundle_id'] ?? '');
            if (!preg_match('/^[a-f0-9\-]{32,64}$/D', $batchUuid)) $batchUuid = bin2hex(random_bytes(16));
            $deviceId = (string)($manifest['device_id'] ?? '');
            $stmt = $conn->prepare(
                'INSERT INTO photo_import_batches
                   (batch_uuid, operator_admin_id, device_id, source,
                    manifest_sig_valid, total_photos, status,
                    manifest_json, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $totalCount = count($photos);
            $source = 'zip';
            $sigInt = $sigValid ? 1 : 0;
            $status = 'importing';
            $manifestJson = json_encode($manifest);
            // Column types: uuid(s), operator(i), device(s), source(s),
            // sig(i), total(i), status(s), manifest(s) → s i s s i i s s.
            $stmt->bind_param('sissiiss',
                $batchUuid, $operatorId, $deviceId, $source,
                $sigInt, $totalCount, $status, $manifestJson);
            if (!$stmt->execute()) throw new RuntimeException('batch_insert_failed');
            if (!$stmt->execute()) throw new RuntimeException('batch_insert_failed');
            $batchId = (int)$conn->insert_id;
            $stmt->close();
            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        // Per-photo ingestion (each in its own transaction inside ingestFromFile).
        $results = [];
        $committed = 0;
        $conflicts = 0;
        $replays = 0;
        $errors = [];
        $seq = 0;
        foreach ($photos as $p) {
            $seq++;
            $arcPath = (string)($p['arc_path'] ?? '');
            $memberCode = (string)($p['member_code'] ?? '');
            $memberId = (int)($p['member_id'] ?? 0);
            $field = (string)($p['field'] ?? 'student_photo');
            if (!in_array($field, ['student_photo','guardian_photo'], true)) $field = 'student_photo';
            $sha = strtolower((string)($p['sha256'] ?? ''));
            $clientOpId = (string)($p['client_op_id'] ?? '');
            $takenAt = (string)($p['taken_at'] ?? '');
            $byteSize = (int)($p['byte_size'] ?? 0);

            // Create the import item row first so ingestFromFile can back-link.
            $conn->query('INSERT INTO photo_import_items
                (batch_id, seq, member_id, field, sha256, decision, created_at)
                VALUES ('.$batchId.', '.$seq.', '.($memberId>0?$memberId:'NULL').',
                "'.$conn->real_escape_string($field).'", "'.$conn->real_escape_string($sha).'",
                "pending", NOW())');
            $itemId = (int)$conn->insert_id;

            $srcPath = $tmpDir . '/' . $arcPath;
            // Resolve member_id by code if not trusted (defense: trust client member_id
            // but cross-check; resolve via member_code when possible).
            if ($memberCode !== '') {
                $resolved = resolveMemberIdByCode($conn, $memberCode);
                if ($resolved !== null) $memberId = $resolved;
            }

            if ($memberId <= 0 || !is_file($srcPath)) {
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'skipped',
                    'reason'=>$memberId<=0?'member_not_found':'file_missing'];
                $errors[] = "Seq $seq ($memberCode): ".($memberId<=0?'member not found':'file missing in ZIP');
                $conn->query("UPDATE photo_import_items SET decision='failed' WHERE id=$itemId");
                continue;
            }

            $res = PhotoUploadService::ingestFromFile($conn, [
                'member_id' => $memberId,
                'operator_admin_id' => $operatorId,
                'device_id' => $deviceId,
                'client_op_id' => $clientOpId,
                'import_item_id' => $itemId,
                'field' => $field,
                'sha256' => $sha,
                'taken_at' => $takenAt,
                'width' => (int)($p['width'] ?? 0),
                'height' => (int)($p['height'] ?? 0),
                'source_path' => $srcPath,
            ]);
            if (!empty($res['ok'])) {
                if (!empty($res['replayed'])) $replays++; else $committed++;
                if (!empty($res['conflict'])) $conflicts++;
                $decision = !empty($res['conflict']) ? 'conflict' : 'accepted';
                $receiptId = (int)($res['receipt_id'] ?? 0);
                $conn->query("UPDATE photo_import_items SET decision='$decision',
                    resolved_member_id=$memberId, receipt_id=$receiptId WHERE id=$itemId");
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>$decision,
                    'receipt_id'=>$receiptId, 'replayed'=>!empty($res['replayed'])];
            } else {
                $errors[] = "Seq $seq ($memberCode): ".($res['error']??'unknown');
                $conn->query("UPDATE photo_import_items SET decision='failed' WHERE id=$itemId");
                $results[] = ['seq'=>$seq,'code'=>$memberCode,'status'=>'failed',
                    'reason'=>$res['error']??'unknown'];
            }
        }

        // Finalize batch.
        $conn->query("UPDATE photo_import_batches SET
            status='done', committed_photos=$committed, committed_at=NOW()
            WHERE id=$batchId");

        // Archive the ZIP.
        $archiveDir = __DIR__ . '/uploads/members/photo_imports';
        if (!is_dir($archiveDir)) @mkdir($archiveDir, 0755, true);
        $archivePath = $archiveDir . '/' . $batchUuid . '.zip';
        @move_uploaded_file($tmp, $archivePath); // source may already be gone; fall back to copy
        if (!is_file($archivePath)) @copy($tmp, $archivePath);

        echo json_encode([
            'status' => 'success',
            'batch_id' => $batchId,
            'batch_uuid' => $batchUuid,
            'total' => $totalCount,
            'committed' => $committed,
            'replays' => $replays,
            'conflicts' => $conflicts,
            'failures' => count($errors),
            'sig_valid' => $sigValid,
            'sig_warning' => $sigWarning,
            'errors' => array_slice($errors, 0, 20),
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
    } finally {
        // Clean up temp extraction.
        if (is_dir($tmpDir)) @rmdir_recursive($tmpDir);
    }
}

function handleRecent(int $operatorId): void {
    global $conn;
    $limit = 20;
    $res = $conn->query("SELECT id, batch_uuid, device_id, total_photos, committed_photos,
        status, manifest_sig_valid, created_at, committed_at
        FROM photo_import_batches
        ORDER BY id DESC LIMIT $limit");
    $rows = [];
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    echo json_encode(['status'=>'success','batches'=>$rows]);
}

function resolveMemberIdByCode(mysqli $conn, string $code): ?int {
    $stmt = $conn->prepare('SELECT id FROM members WHERE member_code = ? LIMIT 1');
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function lookupDeviceHmacKey(mysqli $conn, string $deviceId): ?string {
    // Placeholder: when pairing lands (P7) this reads a stored key from
    // photo_device_identities. For now we return null so callers treat
    // it as unverified-but-accepted.
    return null;
}

function rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $it) {
        if ($it->isDir()) @rmdir($it->getPathname()); else @unlink($it->getPathname());
    }
    @rmdir($dir);
}
