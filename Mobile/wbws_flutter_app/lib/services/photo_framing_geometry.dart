/// Pure geometry for the adaptive 3:4 photo-capture guide.
///
/// Everything in this file is unit-testable Dart with no Flutter or
/// plugin dependencies. It answers the two questions that must never
/// drift apart (FR-03):
///
/// 1. Where is the 3:4 frame on the camera preview?
/// 2. Given a detection result from ML Kit, where does that landmark
///    sit inside the frame (and on the preview)?
///
/// Coordinate spaces used here:
///
/// * **Detection space** — the upright camera image that ML Kit ran on
///   (sensor frame rotated to portrait). Shares aspect ratio and field
///   of view with the captured photo (INFERENCE: the Flutter `camera`
///   plugin's picture and preview expose the same sensor frame; this is
///   the same assumption the previous static guide used and must be
///   confirmed per-device in Stage D acceptance).
/// * **Preview space** — pixels of the on-screen `CameraPreview` widget.
/// * **Frame space** — normalized (0..1, 0..1) coordinates inside the
///   final 3:4 crop. Target regions (spec §7.3) are defined here.
library;

/// Calibration switch for ML Kit coordinate output.
///
/// ML Kit face/pose detection applies the supplied rotation when
/// detecting and returns coordinates relative to the rotated (upright)
/// image. If on-device Stage D calibration shows coordinates arriving in
/// the raw sensor frame instead, flip this flag and re-run the geometry
/// tests (they cover both conventions).
const bool kMlKitReturnsUprightCoordinates = true;

/// True final-output aspect ratio (width / height).
/// Mirrors [PhotoImageProcessor.targetAspect]; keep in sync.
const double kPhotoFrameAspect = 3 / 4;

/// The largest centered 3:4 rectangle inside the preview — exactly the
/// region [PhotoImageProcessor] keeps after its 3:4 center crop.
RectF frameRectInPreview(SizeF preview) {
  final double width = preview.width < preview.height * kPhotoFrameAspect
      ? preview.width
      : preview.height * kPhotoFrameAspect;
  final double height = width / kPhotoFrameAspect;
  return RectF(
    left: (preview.width - width) / 2,
    top: (preview.height - height) / 2,
    width: width,
    height: height,
  );
}

/// Size of the upright detection image given the raw camera buffer and
/// the rotation ML Kit was told to apply.
SizeF uprightImageSize(SizeF raw, int rotationDegrees) {
  final bool swap = rotationDegrees == 90 || rotationDegrees == 270;
  return swap ? SizeF(raw.height, raw.width) : SizeF(raw.width, raw.height);
}

/// Uniform scale factor from detection pixels to preview pixels.
double detectionToPreviewScale(SizeF uprightImage, SizeF preview) =>
    preview.width / uprightImage.width;

/// Detection-space point → preview-space point, with front-camera
/// mirroring applied at draw time only.
OffsetF detectionPointToPreview(
  OffsetF p,
  SizeF uprightImage,
  SizeF preview, {
  bool mirror = false,
}) {
  final double scale = detectionToPreviewScale(uprightImage, preview);
  final double x = mirror ? preview.width - p.x * scale : p.x * scale;
  return OffsetF(x, p.y * scale);
}

/// Detection-space point → frame-normalized point (unmirrored capture
/// space — this is what the framing checks use).
///
/// [preview] is the on-screen preview size the frame sits inside; the
/// detection→preview scale is uniform over the WHOLE preview (the
/// preview shows the full upright image), so it must not be derived
/// from the frame alone — the frame is narrower than the preview
/// whenever the capture aspect is wider than 3:4.
///
/// Points may fall outside 0..1 when the subject is outside the crop;
/// callers clamp only where a bounded value is required.
OffsetF detectionPointToFrame(
  OffsetF p,
  SizeF uprightImage,
  RectF frame, {
  required SizeF preview,
}) {
  final double scale = detectionToPreviewScale(uprightImage, preview);
  final double x = (p.x * scale - frame.left) / frame.width;
  final double y = (p.y * scale - frame.top) / frame.height;
  return OffsetF(x, y);
}

/// Frame-normalized point → preview-space point (for drawing).
OffsetF framePointToPreview(
  OffsetF n,
  RectF frame, {
  bool mirror = false,
}) {
  return OffsetF(
    frameXToPreview(n.x, frame, mirror: mirror),
    frame.top + n.y * frame.height,
  );
}

/// Frame-normalized x → preview-space x (mirroring aware).
double frameXToPreview(double nx, RectF frame, {bool mirror = false}) =>
    mirror ? frame.right - nx * frame.width : frame.left + nx * frame.width;

/// Minimal rectangle primitives (unit-testable without dart:ui).
class SizeF {
  final double width;
  final double height;
  const SizeF(this.width, this.height);
}

class OffsetF {
  final double x;
  final double y;
  const OffsetF(this.x, this.y);
}

class RectF {
  final double left;
  final double top;
  final double width;
  final double height;
  const RectF({
    required this.left,
    required this.top,
    required this.width,
    required this.height,
  });

  double get right => left + width;
  double get bottom => top + height;
  SizeF get size => SizeF(width, height);

  OffsetF get center => OffsetF(left + width / 2, top + height / 2);

  bool contains(OffsetF p) =>
      p.x >= left && p.x <= right && p.y >= top && p.y <= bottom;
}
