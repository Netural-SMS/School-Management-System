# FKSS Offline Photo MVP — P1–P5 status (as of commit 8a9b93f2)

## What's built and shipped

| Phase | Status | Commit | Summary |
|---|---|---|---|
| Design docs | ✅ done | `80c8ead4` | `docs/OFFLINE_PHOTO_SYNC_DESIGN.md` + `docs/OFFLINE_PHOTO_ZIP_IMPORT_DESIGN.md` with the 3 user refinements (WebP/q92/1200×1500/1–2 MB, sync-OFF-by-default, multi-photographer conflict + retake semantics) |
| **P1 Foundation** | ✅ done | `02d8a0df` | Schema v43 (`photo_outbox`, `photo_export_batches`, `photo_device_identity`, `photo_sync_policy`); `PhotoOutboxService` with enqueue/retake/undo-retake/claim/markDone/markRetry/markFailed/discard/counters/due-pick/policy CRUD; per-install device UUID; safe defaults (sync OFF, Wi-Fi only); app v1.8.0+43; `image`/`uuid`/`archive` added as explicit deps |
| **P2 Capture** | ✅ done | `f9ae54b7` | `PhotoCaptureService` — camera open, EXIF orientation bake (cases 1–8), center-crop to 4:5, resize 1200×1500 cubic, WebP q=92 primary / JPEG q=92 fallback, 2 MB cap quality loop (1200×1500/q92 → 1080×1350/q92 → 1080×1350/q90 → 1024×1280/q88), decode-verify; `MemberPhotoCaptureScreen` MVP with member card, idle guidance, full-size review, Accept/Retake, error panel, size/format chip, maroon+gold brand colors |
| **P3 Backend endpoint** | ✅ done | `af0ec44a` | SQL migration 096 (`photo_upload_receipts`, `member_photo_candidates`, `photo_import_batches/items`); `PhotoUploadService::ingest` with Idempotency-Key + SHA-256 verification, MIME (WebP/JPEG), 2 MB cap, WebP GD decode-check; 3-way idempotency (op-id, operator+sha256, content replay); multi-photographer conflict detection (same-operator retake auto-wins, cross-operator conflict:true for admin review); updates `members.student_photo_path` in a single transaction; `POST /api/v1/members/{id}/photo` route; CORS headers extended with `X-Photo-*` |
| **P4 Sync worker** | ✅ done | `38505900` | `ApiService.uploadMemberPhoto()` multipart with all `X-Photo-*` headers, 90s timeout, 401 refresh retry; `PhotoSyncWorker` ChangeNotifier+Stream with FIFO concurrency=2, policy gates, claim-dispatch loop using atomic `claimForUpload`, full-jitter exponential backoff via shared `outbox_policy.dart`, `replayed`/`conflict` handling, pause/resume/cancel, byte counters, ETA, event stream |
| **P5 UI foundation** | ✅ done | `8a9b93f2` | `PhotoSyncPanelScreen` with dynamic network banner, sync/cellular toggles with MB-cost confirmation, queue counters, Start/Pause/Resume/Cancel, Export-ZIP stub, live progress bar with ETA, recent activity log, privacy footer; maroon (#600000) + gold (#F0C000) brand colors |

## What remains for MVP end-to-end (next sessions)

1. **Wire the screens into app routing** — add a Members-tab entry button to open the Capture screen and Sync Panel, and register both routes in the app router.
2. **Z1 ZIP export** — `archive` packaging, manifest.json, HMAC signing, share sheet (photo_outbox_service.dart already has the exports directory + policy + batch table, so plumbing is ready).
3. **P5 polishing** — roster badge (`N unsynced · sync OFF`), Members-tab entry for "Photo Capture Day", thumbnail rendering from queued files.
4. **P6 batch-capture UX** — roster screen with per-member status dots, "Start from first missing" auto-advance, storage/battery warnings.
5. **Web-side conflict review UI** — Photo Review page in admin dashboard listing pending candidates with side-by-side comparison (candidates table already exists; UI needs a page that lists `decision_pending=1` candidates per member).
6. **Z2-Z6 web importer** — ZIP upload, review grid, commit, feedback to device.
7. **P7 hardening** — orphan-file reconciliation on startup, LRU eviction of synced files >30 days, iOS iCloud exclusion flag, Android foreground notification, flaky-network soak tests.

## Files added/modified this session

- `docs/OFFLINE_PHOTO_SYNC_DESIGN.md` (updated with WebP/sync-off/conflict rules)
- `docs/OFFLINE_PHOTO_ZIP_IMPORT_DESIGN.md` (added multi-photographer conflict grid, WebP validation, 800 MB budget)
- `Mobile/wbws_flutter_app/lib/services/local_schema_v43.dart` (NEW — v43 DDL)
- `Mobile/wbws_flutter_app/lib/services/local_schema_v34.dart` (version bump 42→43)
- `Mobile/wbws_flutter_app/lib/services/local_db.dart` (_migrateToV43 in onCreate+onUpgrade)
- `Mobile/wbws_flutter_app/lib/services/photo_outbox_models.dart` (NEW — enums + entry/counters/policy/batch models)
- `Mobile/wbws_flutter_app/lib/services/photo_outbox_service.dart` (NEW — CRUD/retake/claim/state transitions/policy)
- `Mobile/wbws_flutter_app/lib/services/photo_capture_service.dart` (NEW — EXIF/crop/resize/WebP/JPEG encode loop)
- `Mobile/wbws_flutter_app/lib/services/photo_sync_worker.dart` (NEW — FIFO upload drain)
- `Mobile/wbws_flutter_app/lib/screens/members/member_photo_capture_screen.dart` (NEW)
- `Mobile/wbws_flutter_app/lib/screens/members/photo_sync_panel.dart` (NEW)
- `Mobile/wbws_flutter_app/lib/services/api_service.dart` (`uploadMemberPhoto()` method + `PhotoUploadPayload`)
- `Mobile/wbws_flutter_app/pubspec.yaml` (image/uuid/archive direct deps, v1.8.0+43)
- `Mobile/wbws_flutter_app/test/photo_outbox_p1_foundation_test.dart` (schema/enum/policy/counters unit tests)
- `sql/096_mobile_photo_uploads.sql` (NEW — receipt/candidates/import tables)
- `admin/backend/services/PhotoUploadService.php` (NEW — multipart ingestion)
- `api/v1/routes/members.php` (POST /members/{id}/photo route)
- `api/v1/core/middleware.php` (CORS Allow-Headers extended)
- `admin/uploads/members/photos/` directory created

## Test status

- Existing PHP security-well-formedness tests: **8 passed / 379 subtests** (still green after every commit).
- New Dart unit tests added in `photo_outbox_p1_foundation_test.dart`; full Flutter test run blocked on Flutter SDK not being available in this sandbox (same reason as iOS build — will run on dev machine / CI).
