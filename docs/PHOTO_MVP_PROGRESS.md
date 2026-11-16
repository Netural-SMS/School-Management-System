# FKSS Offline Photo Capture — Production Status (updated 2026-11-14)

> NOT MVP — production-grade. Acceptance: WebP q92 @ 1200×1500 / JPEG fallback, 2 MB cap;
> sync OFF by default (3-gate opt-in); multi-photographer conflict picker; ZIP airlock with
> HMAC; SQLite outbox; idempotent upload; batch roster; progress/ETA; orphan reconciliation;
> free-space guards; capability negotiation; least-privilege roles; on-brand maroon/gold UI.

## What's shipped to `main`

| Phase | Status | Commit | Summary |
|---|---|---|---|
| Design | ✅ | `80c8ead4` | `OFFLINE_PHOTO_SYNC_DESIGN.md` + `OFFLINE_PHOTO_ZIP_IMPORT_DESIGN.md` |
| **P1 Foundation** | ✅ | `02d8a0df` | Schema v43 (4 tables), `PhotoOutboxService` CRUD/policy/counters; per-install UUID; sync OFF / Wi-Fi only defaults; image/uuid/archive deps |
| **P2 Capture** | ✅ | `f9ae54b7` | `PhotoCaptureService` — EXIF bake, 4:5 crop, resize 1200×1500, WebP q92 primary / JPEG q92 fallback, 4-step QC loop, 2 MB hard cap; maroon+gold capture screen |
| **P3 Backend** | ✅ | `af0ec44a` | SQL 096 (4 tables); `PhotoUploadService::ingest` with Idempotency-Key, SHA-256, MIME, idempotency tripwire, conflict detection; `POST /api/v1/members/{id}/photo`; CORS `X-Photo-*` headers |
| **P4 Sync Worker** | ✅ | `38505900` | `ApiService.uploadMemberPhoto`; `PhotoSyncWorker` FIFO drain, backoff, pause/resume/cancel, ETA, byte counters, event stream |
| **P5 Sync Panel UI** | ✅ | `8a9b93f2` | Maroon+gold Sync Panel: 3-gate opt-in (toggle → Start → Wi-Fi unless cellular), MB-cost dialog, progress/ETA, event log |
| **Production hardening** | ✅ | `d9769d34` | Orphan reconciliation on startup, 4 GB storage budget, free-space gate on enqueue, Members-tab unsynced-count badge, server `/app/config` media capability probe (WebP vs JPEG fallback) |
| **Admin conflict review** | ✅ | `d9769d34` + `89ed28f2` | `admin/photo_review.php` maroon/gold side-by-side picker; `api_photo_candidates.php` list+decide API (transactional accept/reject with audit trail); `photo_importer` least-privilege role in `access_control.php` |
| **P6 Batch capture** | ✅ | `9a0f1539` | `photo_batch_capture_screen.dart` — roster walk, auto-advance to first missing, gold/grey/maroon status dots, skip/view-details, done dialog; Take-Photo + Batch buttons on member detail; Batch button on member list |
| **Z1 ZIP export** | ✅ | `9a0f1539` | `PhotoZipExportService` — store-only ZIP, canonical manifest.json (sorted keys), per-file SHA-256, HMAC-SHA256 manifest.sig (per-install key; server-paired upgrade in P7), share_plus share sheet; Sync Panel Export button now live; app v1.8.1+44 |
| **Z2-Z5 Server import** | ✅ | `9c941216` | `api_photo_zip_import.php` — upload → extract → manifest parse → per-file validate → `PhotoUploadService::ingestFromFile` (reuses receipt/candidate/conflict pipeline); archive ZIP for audit; `photo_import.php` drag-and-drop admin page with recent-batches table |

## File map (committed)

### Mobile (Flutter)
- `lib/services/local_schema_v43.dart` — DDL for 4 outbox tables
- `lib/services/photo_outbox_models.dart` — enums/entry/counters/policy models
- `lib/services/photo_outbox_service.dart` — SQLite CRUD + retake/claim/policy + orphan reconciliation + free-space guard + `memberIdsWithPhoto()` roster helper
- `lib/services/photo_capture_service.dart` — camera + EXIF/crop/resize/WebP/JPEG QC loop
- `lib/services/photo_sync_worker.dart` — FIFO ChangeNotifier drain
- `lib/services/photo_zip_export_service.dart` — Z1 signed ZIP packaging + share
- `lib/services/api_service.dart` — `uploadMemberPhoto()` multipart
- `lib/screens/members/member_photo_capture_screen.dart` — single capture
- `lib/screens/members/photo_batch_capture_screen.dart` — P6 roster walk
- `lib/screens/members/photo_sync_panel.dart` — P5 control surface (Z1 button live)
- `lib/screens/members/member_detail_screen.dart` — Take-photo + Batch buttons
- `lib/screens/members/member_list_screen.dart` — unsynced-count badge + Batch button
- `test/photo_outbox_p1_foundation_test.dart`
- `pubspec.yaml` — image/uuid/archive/share_plus deps, v1.8.1+44

### Server / Admin
- `sql/096_mobile_photo_uploads.sql` — receipts + candidates + import batches/items
- `admin/backend/services/PhotoUploadService.php` — `ingest` (multipart) + `ingestFromFile` (ZIP use), shared conflict/promote logic
- `api/v1/routes/members.php` — `POST /api/v1/members/{id}/photo` (30/min rate limit)
- `api/v1/routes/app.php` — `/app/config` now advertises `media` capability block (max bytes, MIME list, idempotency flag)
- `api/v1/core/middleware.php` — CORS `X-Photo-*` headers
- `admin/api_photo_candidates.php` — pending-list + accept/reject transactional API
- `admin/photo_review.php` — maroon/gold side-by-side conflict picker
- `admin/api_photo_zip_import.php` — Z2-Z5 ZIP upload + ingest
- `admin/photo_import.php` — drag-and-drop import page
- `admin/access_control.php` — `photo_importer` least-privilege role wired to photo pages
- `admin/uploads/members/photos/` — canonical WebP/JPEG storage
- `admin/uploads/members/photo_imports/` — audit archive of imported ZIPs (created on demand)

## Remaining (P7 hardening — production polish)

1. **iOS iCloud backup exclusion** — method channel to mark queue/exports directories as `NSURLIsExcludedFromBackupKey` (currently stubbed via import of `flutter/services.dart`; the actual MethodChannel call is a TODO).
2. **Android foreground service notification** — wrap the sync worker in a foreground service so Android doesn't kill long uploads when the app is backgrounded.
3. **LRU eviction** — delete synced files older than 30 days from the queue dir to bound storage.
4. **Per-device HMAC pairing** — on first successful login, the server generates a random HMAC key for the device_id and returns it via `/app/config`; client stores in `photo_device_identity.photo_hmac_key`; Z1 export signs with it; Z5 verifies it (replaces the current "warn don't block" behavior).
5. **Web `photo_importer` role management UI** — role exists and ACL is wired, but admin user-management UI doesn't yet expose it in the role picker; add a "Photo Importer" option when creating/editing admin users.
6. **Flaky-network soak tests** — harness that drops connections mid-upload, restarts the app mid-enqueue, etc.
7. **Local Flutter test pass** — run `flutter pub get && flutter analyze && flutter test` on a machine with the Flutter SDK (sandbox here has no SDK); PHP well-formedness tests remain green (8 / 383 subtests).

## Test status

- **PHP well-formedness (security suite):** 8 passed / 383 subtests (green after every commit).
- **Dart/Flutter:** SDK not present in sandbox — run locally / CI.
