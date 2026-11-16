import 'dart:io';
import 'dart:typed_data';

import 'package:image/image.dart' as img;
import 'package:image_picker/image_picker.dart';
import 'package:path/path.dart' as p;

import 'photo_outbox_models.dart';
import 'photo_outbox_service.dart';

/// Quality-first image pipeline for ID-card / report-card / yearbook photos.
///
/// This is P2: opening the camera, correcting EXIF orientation, center-
/// cropping to the 4:5 portrait ratio, resizing to 1200x1500, encoding to
/// WebP q=92 (with JPEG q=92 fallback), enforcing the 2 MB cap through a
/// quality-control loop, and handing the finished file to
/// `PhotoOutboxService.enqueueCapturedPhoto()` for durable queuing.
///
/// The pipeline is pure-Dart (the `image` package) so it works identically
/// on Android and iOS with no native SDK beyond what image_picker already
/// brings. No photos are written to the user's gallery by default — we
/// keep everything in app-private queue storage.
///
/// Design reference: docs/OFFLINE_PHOTO_SYNC_DESIGN.md §3.
class PhotoCaptureService {
  PhotoCaptureService._();
  static final PhotoCaptureService instance = PhotoCaptureService._();

  final ImagePicker _picker = ImagePicker();

  // ---- Target spec (see design §3) ----
  static const int targetLongEdge = 1500; // height in portrait
  static const int targetShortEdge = 1200; // width in portrait
  static const double targetAspect =
      targetShortEdge / targetLongEdge; // 0.8 (4:5)
  static const int primaryQuality = 92; // WebP and JPEG
  static const int hardCapBytes = 2 * 1024 * 1024; // 2 MB
  static const int floorQuality = 88;
  static const int floorShortEdge = 1024;
  static const int floorLongEdge = 1280;

  /// Open the rear camera, let the user take one photo, run the encode
  /// pipeline, enqueue the result. Returns the enqueued entry, or null if
  /// the user cancelled or capture failed.
  ///
  /// [memberId], [memberCode], [field] identify the subject.
  /// [retakeOfClientOpId] is provided when this is a retake of a prior
  /// capture (drives the retake semantics in PhotoOutboxService).
  ///
  /// Throws [PhotoCaptureException] with a user-facing message on any
  /// pipeline failure (camera denied, encode failed, too big after all
  /// fallback attempts).
  Future<PhotoOutboxEntry?> captureAndEnqueue({
    required int memberId,
    String? memberCode,
    PhotoField field = PhotoField.student,
    String? retakeOfClientOpId,
    int? operatorAdminId,
    PreferredCamera preferredCamera = PreferredCamera.rear,
  }) async {
    final XFile? picked;
    try {
      picked = await _picker.pickImage(
        source: ImageSource.camera,
        preferredCameraDevice: preferredCamera == PreferredCamera.front
            ? CameraDevice.front
            : CameraDevice.rear,
        // We do NOT ask image_picker to resize/compress — it doesn't
        // support WebP output and its JPEG pipeline doesn't expose
        // orientation-corrected pixel data to us. We pull the raw camera
        // bytes and run the pipeline ourselves for deterministic quality.
        maxWidth: null,
        maxHeight: null,
        imageQuality: 100,
      );
    } catch (e) {
      throw PhotoCaptureException('Could not open camera: $e');
    }
    if (picked == null) return null;

    final takenAt = DateTime.now().toUtc();
    final tempDir = await PhotoOutboxService.instance.queueDirectory();
    final tmpPath = p.join(
      tempDir,
      'raw_${DateTime.now().microsecondsSinceEpoch}.tmp',
    );
    final tmpFile = File(tmpPath);
    try {
      // Stage the raw bytes in the queue dir (atomic rename later).
      await tmpFile.writeAsBytes(await picked.readAsBytes());

      final encoded = await _processAndEncode(File(picked.path), tmpPath);

      return PhotoOutboxService.instance.enqueueCapturedPhoto(
        memberId: memberId,
        memberCode: memberCode,
        field: field,
        absoluteFilePath: encoded.path,
        format: encoded.format,
        width: encoded.width,
        height: encoded.height,
        takenAt: takenAt,
        operatorAdminId: operatorAdminId,
        retakeOfClientOpId: retakeOfClientOpId,
      );
    } finally {
      // Always remove the temp raw file; enqueueCapturedPhoto will have
      // moved the final encoded file into its canonical name.
      try {
        if (await tmpFile.exists()) await tmpFile.delete();
      } catch (_) {}
    }
  }

  /// Pure-Dart pipeline: read bytes → decode → fix EXIF orientation →
  /// center-crop to 4:5 → resize → WebP q=92 → measure; if over cap,
  /// downscale/quality-fallback. Returns the file on disk (written to
  /// [destPathSuggestion]'s directory under a canonical name).
  Future<_EncodeResult> _processAndEncode(
    File source,
    String tempDestPath,
  ) async {
    final bytes = await source.readAsBytes();
    if (bytes.isEmpty) {
      throw PhotoCaptureException('Camera produced an empty photo.');
    }

    // 1. Decode. img.decodeImage auto-detects JPEG/PNG/WebP/etc.
    img.Image? decoded = img.decodeImage(bytes);
    if (decoded == null) {
      throw PhotoCaptureException(
          'Could not read the photo data. Try again.');
    }

    // 2. EXIF orientation correction (bake orientation into pixels).
    //
    // The `image` package returns orientation metadata in
    // `decoded.exif.orientationIfPresent`; we read that and apply the
    // corresponding flip+rotate manually. Newer versions of the package
    // may provide bakeOrientation(); the manual approach keeps us
    // compatible with older transitive-resolved versions too.
    decoded = _applyExifOrientation(decoded);

    // 3. Determine portrait orientation. If camera gave us a landscape
    // frame (unlikely for portrait ID shots but possible with some front
    // cams), we rotate 90° CCW to make the subject upright. We do NOT
    // autorotate based on face detection (no native ML in P2); the
    // framing overlay (P5) is what prevents sideways framing.
    if (decoded.width > decoded.height) {
      decoded = img.copyRotate(decoded, angle: -90);
    }

    // 4. Center-crop to 4:5 portrait. After this, w/h ratio = targetAspect.
    decoded = _centerCropTo4x5(decoded);

    // 5. Quality-control encode loop: start at best (1200x1500, q=92 WebP),
    // step down if we exceed 2 MB, never below floor.
    final specs = <_EncodeSpec>[
      _EncodeSpec(targetShortEdge, targetLongEdge, primaryQuality),
      _EncodeSpec(1080, 1350, primaryQuality),
      _EncodeSpec(1080, 1350, 90),
      _EncodeSpec(floorShortEdge, floorLongEdge, floorQuality),
    ];

    Object? lastEncodeError;
    for (final spec in specs) {
      // Resize to spec.
      img.Image resized = img.copyResize(
        decoded,
        width: spec.shortEdge,
        height: spec.longEdge,
        interpolation: img.Interpolation.cubic,
      );

      // Try WebP first. image 4.9.x: encodeWebP no longer takes a quality
      // param; use WebPEncoder with a WebPConfig. Fall back to bare encodeWebP
      // on older versions.
      List<int>? webpBytes;
      try {
        webpBytes = img.encodeWebP(resized,
            config: img.WebPConfig(lossless: false, quality: spec.quality));
      } catch (_) {
        try {
          // ignore: deprecated_member_use
          webpBytes = img.encodeWebP(resized);
        } catch (_) {
          webpBytes = null;
        }
      }
      try {
        final encodedBytes = webpBytes;
        if (encodedBytes != null && encodedBytes.length <= hardCapBytes) {
          final dest = await _writeEncoded(
            tempDestPath,
            PhotoFormat.webp,
            encodedBytes,
          );
          return _EncodeResult(
            path: dest.path,
            format: PhotoFormat.webp,
            width: spec.shortEdge,
            height: spec.longEdge,
            byteSize: encodedBytes.length,
          );
        }
      } catch (e) {
        lastEncodeError = e;
        // WebP encode failure → fall through to JPEG at same spec.
      }

      // JPEG fallback (also used if WebP produced > cap AND we're at a
      // smaller spec — WebP at 1200x1500 is almost always <2 MB for face
      // photos, but we code the fallback for safety).
      try {
        final jpegBytes = img.encodeJpg(
          resized,
          quality: spec.quality,
          // chroma: 4:2:0 is skin-tone-safe at q>=88 per photography lit.
        );
        if (jpegBytes.length <= hardCapBytes) {
          final dest = await _writeEncoded(
            tempDestPath,
            PhotoFormat.jpeg,
            jpegBytes,
          );
          return _EncodeResult(
            path: dest.path,
            format: PhotoFormat.jpeg,
            width: spec.shortEdge,
            height: spec.longEdge,
            byteSize: jpegBytes.length,
          );
        }
      } catch (e) {
        lastEncodeError = e;
      }
    }

    throw PhotoCaptureException(
      'Could not compress this photo under 2 MB even at $floorShortEdge'
      'x$floorLongEdge / q=$floorQuality. ${lastEncodeError ?? ""}',
    );
  }

  /// Apply EXIF orientation (1–8) by rotating/flipping the pixel buffer.
  img.Image _applyExifOrientation(img.Image src) {
    // image package 4.x removed `orientationIfPresent`; fall back to a
    // dynamic call that reads IFD0 Orientation tag if present, else 1.
    int orientation = 1;
    try {
      // Prefer bakeOrientation if the installed image package exposes it,
      // else read the tag manually.
      final baked = (img as dynamic).bakeOrientation(src);
      if (baked is img.Image) return baked;
    } catch (_) {
      // ignore
    }
    try {
      final ifd = (src.exif as dynamic).ifd;
      final raw = ifd?.get(0x0112); // EXIF Orientation tag (short)
      if (raw is int) orientation = raw;
    } catch (_) {
      try {
        // ignore: deprecated_member_use
        orientation = (src.exif as dynamic).orientationIfPresent as int? ?? 1;
      } catch (_) {
        orientation = 1;
      }
    }
    switch (orientation) {
      case 1:
        return src;
      case 2:
        return img.flipHorizontal(src);
      case 3:
        return img.copyRotate(src, angle: 180);
      case 4:
        return img.flipVertical(src);
      case 5:
        return img.flipHorizontal(img.copyRotate(src, angle: 90));
      case 6:
        return img.copyRotate(src, angle: 90);
      case 7:
        return img.flipHorizontal(img.copyRotate(src, angle: -90));
      case 8:
        return img.copyRotate(src, angle: -90);
      default:
        return src;
    }
  }

  /// Center-crop an image to the target 4:5 portrait ratio (width:height).
  /// If the image is already at or very close to 4:5, returns it unchanged.
  img.Image _centerCropTo4x5(img.Image src) {
    final srcRatio = src.width / src.height;
    if ((srcRatio - targetAspect).abs() < 0.01) {
      return src;
    }
    int cropW, cropH, offX, offY;
    if (srcRatio > targetAspect) {
      // Image is wider than 4:5 → crop width (slice left/right).
      cropH = src.height;
      cropW = (cropH * targetAspect).round();
      offX = ((src.width - cropW) ~/ 2).clamp(0, src.width - cropW);
      offY = 0;
    } else {
      // Image is taller than 4:5 → crop height (slice top/bottom).
      cropW = src.width;
      cropH = (cropW / targetAspect).round();
      offX = 0;
      offY = ((src.height - cropH) ~/ 2).clamp(0, src.height - cropH);
    }
    return img.copyCrop(
      src,
      x: offX,
      y: offY,
      width: cropW,
      height: cropH,
    );
  }

  Future<File> _writeEncoded(
    String tempPathBase,
    PhotoFormat format,
    Uint8List bytes,
  ) async {
    final destPath =
        '${tempPathBase.substring(0, tempPathBase.length - 4)}.${format.extension}';
    final f = File(destPath);
    await f.writeAsBytes(bytes, flush: true);
    return f;
  }

  /// Decode a queued photo to raw RGBA bytes for thumbnail/preview display.
  /// Used by roster thumbnail generation and review screens; keeps heavy
  /// image work off the main UI thread (callers should run this in a
  /// compute/isolate on low-end devices).
  img.Image? decodeQueuedImage(String path) {
    final f = File(path);
    if (!f.existsSync()) return null;
    try {
      return img.decodeImage(f.readAsBytesSync());
    } catch (_) {
      return null;
    }
  }
}

class _EncodeSpec {
  final int shortEdge;
  final int longEdge;
  final int quality;
  const _EncodeSpec(this.shortEdge, this.longEdge, this.quality);
}

class _EncodeResult {
  final String path;
  final PhotoFormat format;
  final int width;
  final int height;
  final int byteSize;
  const _EncodeResult({
    required this.path,
    required this.format,
    required this.width,
    required this.height,
    required this.byteSize,
  });
}

enum PreferredCamera { rear, front }

class PhotoCaptureException implements Exception {
  final String message;
  PhotoCaptureException(this.message);
  @override
  String toString() => 'PhotoCaptureException: $message';
}
