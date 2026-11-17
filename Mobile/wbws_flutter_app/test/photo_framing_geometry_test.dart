// Unit tests for the adaptive photo-capture framing geometry
// (lib/services/photo_framing_geometry.dart) — the coordinate contract
// behind FR-03 (preview matches the final crop) and the landmark-driven
// overlay (spec §9.4: detection-image → preview → frame mapping).
//
// Detection space is the upright camera image ML Kit ran on. The camera
// plugin's preview exposes the same sensor frame as the capture (the
// same assumption the static guide used), so the scale between
// detection pixels and preview pixels is preview.width / upright.width.
import 'package:flutter_test/flutter_test.dart';
import 'package:fkss_app/services/photo_framing_geometry.dart';

void main() {
  group('kPhotoFrameAspect', () {
    test('is exactly 3:4', () {
      expect(kPhotoFrameAspect, closeTo(0.75, 1e-12));
    });
  });

  group('uprightImageSize', () {
    test('swaps dimensions for 90° and 270° rotation', () {
      const raw = SizeF(640, 480);
      expect(uprightImageSize(raw, 90).width, 480);
      expect(uprightImageSize(raw, 90).height, 640);
      expect(uprightImageSize(raw, 270).width, 480);
      expect(uprightImageSize(raw, 270).height, 640);
    });

    test('keeps dimensions for 0° and 180° rotation', () {
      const raw = SizeF(640, 480);
      expect(uprightImageSize(raw, 0).width, 640);
      expect(uprightImageSize(raw, 0).height, 480);
      expect(uprightImageSize(raw, 180).width, 640);
      expect(uprightImageSize(raw, 180).height, 480);
    });
  });

  group('frameRectInPreview', () {
    test('preview matching the capture aspect: frame fills the preview', () {
      // The camera preview widget uses AspectRatio(controller.value
      // .aspectRatio), the capture aspect — for a 4:3 portrait sensor
      // that is exactly 3:4, so the crop keeps the whole preview.
      const preview = SizeF(750, 1000);
      final frame = frameRectInPreview(preview);
      expect(frame.left, 0);
      expect(frame.top, 0);
      expect(frame.width, 750);
      expect(frame.height, 1000);
    });

    test('narrower preview: frame is full-width, 3:4 tall, centered', () {
      const preview = SizeF(1000, 1600);
      final frame = frameRectInPreview(preview);
      expect(frame.left, 0);
      expect(frame.width, 1000);
      expect(frame.height, closeTo(1000 / kPhotoFrameAspect, 1e-9));
      expect(frame.top, closeTo((1600 - frame.height) / 2, 1e-9));
      expect(frame.bottom, closeTo(frame.top + frame.height, 1e-9));
    });

    test('wider preview: frame is full-height, letterboxed', () {
      const preview = SizeF(1600, 1000);
      final frame = frameRectInPreview(preview);
      expect(frame.height, 1000);
      expect(frame.width, closeTo(1000 * kPhotoFrameAspect, 1e-9));
      expect(frame.left, closeTo((1600 - frame.width) / 2, 1e-9));
      expect(frame.top, 0);
    });

    test('frame is always exactly 3:4 for arbitrary previews', () {
      const previews = [
        SizeF(400, 800),
        SizeF(1080, 1920),
        SizeF(720, 1280),
        SizeF(500, 620),
      ];
      for (final preview in previews) {
        final frame = frameRectInPreview(preview);
        expect(frame.width / frame.height, closeTo(0.75, 1e-9),
            reason: 'preview $preview');
      }
    });
  });

  group('detectionPointToFrame', () {
    test('maps the frame corners to (0,0) and (1,1)', () {
      // 4:3 sensor rotated to upright portrait 480x640 (3:4) and a
      // matching 3:4 preview: the frame covers the whole preview.
      const upright = SizeF(480, 640);
      const preview = SizeF(750, 1000);
      final frame = frameRectInPreview(preview);
      expect(frame.left, 0);
      expect(frame.top, 0);

      final topLeft = detectionPointToFrame(
        const OffsetF(0, 0),
        upright,
        frame,
        preview: preview,
      );
      expect(topLeft.x, closeTo(0, 1e-9));
      expect(topLeft.y, closeTo(0, 1e-9));

      final bottomRight = detectionPointToFrame(
        const OffsetF(480, 640),
        upright,
        frame,
        preview: preview,
      );
      expect(bottomRight.x, closeTo(1, 1e-9));
      expect(bottomRight.y, closeTo(1, 1e-9));
    });

    test('center of the detection image maps to frame center', () {
      const upright = SizeF(480, 640);
      const preview = SizeF(750, 1000);
      final frame = frameRectInPreview(preview);
      final center =
          detectionPointToFrame(const OffsetF(240, 320), upright, frame, preview: preview);
      expect(center.x, closeTo(0.5, 1e-9));
      expect(center.y, closeTo(0.5, 1e-9));
    });

    test('letterboxed preview: detection center still hits frame center',
        () {
      // Landscape detection image with a same-aspect preview: the 3:4
      // frame is a vertical slice of the middle.
      const upright = SizeF(1600, 1000);
      const preview = SizeF(1600, 1000);
      final frame = frameRectInPreview(preview);
      expect(frame.left, closeTo(425, 1e-9));
      final center =
          detectionPointToFrame(const OffsetF(800, 500), upright, frame, preview: preview);
      expect(center.x, closeTo(0.5, 1e-9));
      expect(center.y, closeTo(0.5, 1e-9));
    });

    test('points outside the 3:4 crop fall outside 0..1 (not clamped)', () {
      const upright = SizeF(1600, 1000);
      const preview = SizeF(1600, 1000);
      final frame = frameRectInPreview(preview);
      final outside =
          detectionPointToFrame(const OffsetF(0, 500), upright, frame, preview: preview);
      expect(outside.x, lessThan(0));
      // The frame top edge IS the image top edge here (full height).
      final insideTop =
          detectionPointToFrame(OffsetF(frame.left, 0), upright, frame, preview: preview);
      expect(insideTop.y, closeTo(0, 1e-9));
    });
  });

  group('detectionPointToPreview / framePointToPreview', () {
    const upright = SizeF(480, 640);
    const preview = SizeF(750, 1000);

    test('scales detection pixels to preview pixels 1:1 in value', () {
      final p = detectionPointToPreview(
        const OffsetF(240, 320),
        upright,
        preview,
      );
      expect(p.x, closeTo(375, 1e-9));
      expect(p.y, closeTo(500, 1e-9));
    });

    test('mirroring flips x only, keeps y', () {
      final unmirrored =
          detectionPointToPreview(const OffsetF(100, 200), upright, preview);
      final mirrored = detectionPointToPreview(
        const OffsetF(100, 200),
        upright,
        preview,
        mirror: true,
      );
      expect(mirrored.x, closeTo(preview.width - unmirrored.x, 1e-9));
      expect(mirrored.y, closeTo(unmirrored.y, 1e-9));
    });

    test('round trip: detection → frame → preview equals direct mapping',
        () {
      final frame = frameRectInPreview(preview);
      const p = OffsetF(120, 300);

      final viaFrame = framePointToPreview(
        detectionPointToFrame(p, upright, frame, preview: preview),
        frame,
      );
      final direct = detectionPointToPreview(p, upright, preview);
      expect(viaFrame.x, closeTo(direct.x, 1e-9));
      expect(viaFrame.y, closeTo(direct.y, 1e-9));
    });

    test('mirror round trip through frame space flips horizontally only',
        () {
      final frame = frameRectInPreview(preview);
      const p = OffsetF(120, 300);

      final viaFrameMirrored = framePointToPreview(
        detectionPointToFrame(p, upright, frame, preview: preview),
        frame,
        mirror: true,
      );
      final direct = detectionPointToPreview(p, upright, preview);
      // Frame checks run in unmirrored capture space; mirroring happens
      // only at draw time, so a mirrored draw of an unmirrored frame
      // point flips around the frame center.
      final expectedX = frame.right - (direct.x - frame.left);
      expect(viaFrameMirrored.x, closeTo(expectedX, 1e-9));
      expect(viaFrameMirrored.y, closeTo(direct.y, 1e-9));
    });
  });

  group('frameXToPreview', () {
    test('unmirrored: left edge of frame plus normalized offset', () {
      const frame = RectF(left: 100, top: 0, width: 800, height: 1000);
      expect(frameXToPreview(0, frame), 100);
      expect(frameXToPreview(0.5, frame), 500);
      expect(frameXToPreview(1, frame), 900);
    });

    test('mirrored: right edge minus normalized offset', () {
      const frame = RectF(left: 100, top: 0, width: 800, height: 1000);
      expect(frameXToPreview(0, frame, mirror: true), 900);
      expect(frameXToPreview(0.5, frame, mirror: true), 500);
      expect(frameXToPreview(1, frame, mirror: true), 100);
    });
  });

  group('RectF', () {
    test('edges, center, contains', () {
      const frame = RectF(left: 10, top: 20, width: 30, height: 40);
      expect(frame.right, 40);
      expect(frame.bottom, 60);
      expect(frame.center.x, 25);
      expect(frame.center.y, 40);
      expect(frame.contains(const OffsetF(25, 40)), isTrue);
      expect(frame.contains(const OffsetF(9, 40)), isFalse);
    });
  });
}
