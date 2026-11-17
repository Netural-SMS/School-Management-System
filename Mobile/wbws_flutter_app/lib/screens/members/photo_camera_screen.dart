import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

import '../../services/device_tier_service.dart';
import '../../services/photo_capture_service.dart';

class PhotoCameraScreen extends StatefulWidget {
  final String memberName;

  const PhotoCameraScreen({super.key, required this.memberName});

  @override
  State<PhotoCameraScreen> createState() => _PhotoCameraScreenState();
}

class _PhotoCameraScreenState extends State<PhotoCameraScreen>
    with WidgetsBindingObserver {
  CameraController? _controller;
  List<CameraDescription> _cameras = const [];
  CameraDescription? _selectedCamera;
  bool _initializing = false;
  bool _takingPicture = false;
  bool _appResumed = true;
  String? _error;
  String? _controlsNotice;
  FlashMode _flashMode = FlashMode.off;
  double _minExposure = 0;
  double _maxExposure = 0;
  double _exposure = 0;
  double _minZoom = 1;
  double _maxZoom = 1;
  double _zoom = 1;

  ResolutionPreset get _resolutionPreset =>
      switch (DeviceTierService.instance.tier) {
        DeviceTier.low => ResolutionPreset.high,
        DeviceTier.mid => ResolutionPreset.veryHigh,
        DeviceTier.high => ResolutionPreset.max,
      };

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    unawaited(_initializeCamera());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.hidden ||
        state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused) {
      _appResumed = false;
      unawaited(_releaseCamera());
    } else if (state == AppLifecycleState.resumed) {
      _appResumed = true;
      if (_takingPicture) return;
      unawaited(_initializeCamera());
    }
  }

  Future<void> _initializeCamera({CameraDescription? preferred}) async {
    if (_initializing || !mounted) return;
    _initializing = true;
    setState(() {
      _error = null;
      _controlsNotice = null;
    });

    try {
      if (_cameras.isEmpty) {
        _cameras = await availableCameras();
      }
      if (_cameras.isEmpty) {
        throw StateError('No camera is available on this device.');
      }
      final selected = preferred ??
          _selectedCamera ??
          _cameras.firstWhere(
            (camera) => camera.lensDirection == CameraLensDirection.back,
            orElse: () => _cameras.first,
          );
      final controller = CameraController(
        selected,
        _resolutionPreset,
        enableAudio: false,
      );
      _controller = controller;
      await controller.initialize();
      if (!mounted || !_appResumed) {
        if (identical(_controller, controller)) _controller = null;
        await controller.dispose();
        return;
      }
      _selectedCamera = selected;
      await _loadCameraControls(controller);
      if (mounted) setState(() {});
    } on CameraException catch (e) {
      final failedController = _controller;
      _controller = null;
      await failedController?.dispose();
      if (mounted) {
        setState(() => _error = 'Camera unavailable: ${e.description}');
      }
    } catch (e) {
      final failedController = _controller;
      _controller = null;
      await failedController?.dispose();
      if (mounted) setState(() => _error = 'Could not open the camera: $e');
    } finally {
      _initializing = false;
      if (mounted) setState(() {});
    }
  }

  Future<void> _loadCameraControls(CameraController controller) async {
    try {
      await controller.setFlashMode(FlashMode.off);
      _flashMode = FlashMode.off;
    } on CameraException catch (e) {
      _controlsNotice = 'Flash control unavailable: ${e.description}';
    }
    try {
      _minExposure = await controller.getMinExposureOffset();
      _maxExposure = await controller.getMaxExposureOffset();
      _exposure = 0.clamp(_minExposure, _maxExposure).toDouble();
    } on CameraException catch (e) {
      _controlsNotice = 'Exposure adjustment unavailable: ${e.description}';
      _minExposure = 0;
      _maxExposure = 0;
    }
    try {
      _minZoom = await controller.getMinZoomLevel();
      _maxZoom = await controller.getMaxZoomLevel();
      _zoom = _minZoom;
    } on CameraException catch (e) {
      _controlsNotice ??= 'Zoom control unavailable: ${e.description}';
      _minZoom = 1;
      _maxZoom = 1;
    }
  }

  Future<void> _releaseCamera() async {
    final controller = _controller;
    _controller = null;
    if (mounted) setState(() {});
    try {
      await controller?.dispose();
    } on CameraException catch (e) {
      if (mounted) {
        setState(() => _error = 'Could not release camera: ${e.description}');
      }
    }
  }

  Future<void> _switchCamera() async {
    final current = _selectedCamera;
    if (current == null || _cameras.length < 2 || _takingPicture) return;
    final next = _cameras.firstWhere(
      (camera) => camera.lensDirection != current.lensDirection,
      orElse: () => _cameras.first,
    );
    final previous = _controller;
    _controller = null;
    await previous?.dispose();
    if (!mounted) return;
    await _initializeCamera(preferred: next);
  }

  Future<void> _cycleFlash() async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    final next = switch (_flashMode) {
      FlashMode.off => FlashMode.auto,
      FlashMode.auto => FlashMode.torch,
      FlashMode.torch || FlashMode.always => FlashMode.off,
    };
    try {
      await controller.setFlashMode(next);
      if (mounted) setState(() => _flashMode = next);
    } on CameraException catch (e) {
      if (mounted) {
        setState(() =>
            _controlsNotice = 'Flash control unavailable: ${e.description}');
      }
    }
  }

  Future<void> _setExposure(double value) async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    try {
      final applied = await controller.setExposureOffset(value);
      if (mounted) setState(() => _exposure = applied);
    } on CameraException catch (e) {
      if (mounted) {
        setState(() =>
            _controlsNotice = 'Could not adjust exposure: ${e.description}');
      }
    }
  }

  Future<void> _setZoom(double value) async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    try {
      await controller.setZoomLevel(value);
      if (mounted) setState(() => _zoom = value);
    } on CameraException catch (e) {
      if (mounted) {
        setState(
            () => _controlsNotice = 'Could not adjust zoom: ${e.description}');
      }
    }
  }

  Future<void> _focusAt(TapDownDetails details, Size size) async {
    final controller = _controller;
    if (controller == null || _takingPicture) return;
    final point = Offset(
      (details.localPosition.dx / size.width).clamp(0, 1),
      (details.localPosition.dy / size.height).clamp(0, 1),
    );
    try {
      await controller.setFocusPoint(point);
      await controller.setExposurePoint(point);
    } on CameraException catch (e) {
      if (mounted) {
        setState(() =>
            _controlsNotice = 'Tap-to-focus unavailable: ${e.description}');
      }
    }
  }

  Future<void> _takePicture() async {
    final controller = _controller;
    if (controller == null ||
        _takingPicture ||
        !_appResumed ||
        !controller.value.isInitialized) {
      return;
    }
    setState(() {
      _takingPicture = true;
      _error = null;
    });
    try {
      final image = await controller.takePicture();
      if (!mounted) {
        try {
          await File(image.path).delete();
        } on FileSystemException catch (e) {
          debugPrint('Could not remove abandoned camera image: $e');
        }
        return;
      }
      Navigator.of(context).pop(
        CapturedPhoto(path: image.path, takenAt: DateTime.now().toUtc()),
      );
    } on CameraException catch (e) {
      if (mounted) {
        setState(() {
          _takingPicture = false;
          _error = 'Could not take the photo: ${e.description}';
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _takingPicture = false;
          _error = 'Could not take the photo: $e';
        });
      }
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_controller?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;
    final ready = controller != null && controller.value.isInitialized;
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: Stack(
          fit: StackFit.expand,
          children: [
            if (ready)
              Center(
                child: AspectRatio(
                  aspectRatio: controller.value.aspectRatio,
                  child: LayoutBuilder(
                    builder: (context, constraints) {
                      final previewSize = Size(
                        constraints.maxWidth,
                        constraints.maxHeight,
                      );
                      return GestureDetector(
                        behavior: HitTestBehavior.opaque,
                        onTapDown: (details) => _focusAt(details, previewSize),
                        child: Stack(
                          fit: StackFit.expand,
                          children: [
                            CameraPreview(controller),
                            // The guide is painted in preview
                            // coordinates so the framed region is the
                            // exact region that survives the 3:4
                            // center crop in PhotoImageProcessor.
                            const IgnorePointer(
                              child:
                                  CustomPaint(painter: _PassportGuidePainter()),
                            ),
                          ],
                        ),
                      );
                    },
                  ),
                ),
              )
            else
              Center(
                child: _error == null
                    ? const CircularProgressIndicator(color: Colors.white)
                    : _CameraError(
                        message: _error!,
                        onRetry: () => _initializeCamera(
                          preferred: _selectedCamera,
                        ),
                      ),
              ),
            if (ready) ...[
              Positioned(
                left: 16,
                right: 16,
                top: 12,
                child: _cameraHeader(context),
              ),
              const Positioned(
                left: 24,
                right: 24,
                top: 76,
                child: Text(
                  'Eyes on the upper line · shoulders on the lower line '
                  '· even light · plain background',
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 13,
                    shadows: [Shadow(blurRadius: 5, color: Colors.black)],
                  ),
                ),
              ),
              Positioned(
                left: 18,
                right: 18,
                bottom: 20,
                child: _cameraControls(),
              ),
            ],
            if (ready && _error != null)
              Positioned(
                left: 16,
                right: 16,
                top: 112,
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    color: Colors.red.shade900.withValues(alpha: 0.9),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Text(
                      _error!,
                      textAlign: TextAlign.center,
                      style: const TextStyle(color: Colors.white),
                    ),
                  ),
                ),
              ),
            if (!ready && _error == null)
              Positioned(
                left: 12,
                top: 12,
                child: IconButton.filledTonal(
                  tooltip: 'Close camera',
                  onPressed: () => Navigator.of(context).pop(),
                  icon: const Icon(Icons.close),
                ),
              ),
          ],
        ),
      ),
    );
  }

  Widget _cameraHeader(BuildContext context) => Row(
        children: [
          IconButton.filledTonal(
            tooltip: 'Close camera',
            onPressed: () => Navigator.of(context).pop(),
            icon: const Icon(Icons.close),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              widget.memberName,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 17,
                fontWeight: FontWeight.w600,
                shadows: [Shadow(blurRadius: 6, color: Colors.black)],
              ),
            ),
          ),
          IconButton.filledTonal(
            tooltip: 'Flash: ${_flashMode.name}',
            onPressed: _cycleFlash,
            icon: Icon(switch (_flashMode) {
              FlashMode.off => Icons.flash_off,
              FlashMode.auto => Icons.flash_auto,
              FlashMode.torch || FlashMode.always => Icons.flash_on,
            }),
          ),
          if (_cameras.length > 1)
            IconButton.filledTonal(
              tooltip: 'Switch camera',
              onPressed: _switchCamera,
              icon: const Icon(Icons.cameraswitch),
            ),
        ],
      );

  Widget _cameraControls() {
    final hasExposure = _maxExposure > _minExposure;
    final hasZoom = _maxZoom > _minZoom;
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        if (hasExposure)
          _CameraSlider(
            label: 'Brightness',
            value: _exposure.clamp(_minExposure, _maxExposure),
            min: _minExposure,
            max: _maxExposure,
            icon: Icons.brightness_6_outlined,
            onChanged: (value) => setState(() => _exposure = value),
            onChangeEnd: _setExposure,
          ),
        if (hasZoom)
          _CameraSlider(
            label: 'Zoom',
            value: _zoom.clamp(_minZoom, _maxZoom),
            min: _minZoom,
            max: _maxZoom,
            icon: Icons.zoom_in,
            onChanged: (value) => setState(() => _zoom = value),
            onChangeEnd: _setZoom,
          ),
        if (_controlsNotice != null)
          Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              _controlsNotice!,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.orangeAccent, fontSize: 12),
            ),
          ),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            const SizedBox(width: 56),
            GestureDetector(
              onTap: _takePicture,
              child: Container(
                width: 76,
                height: 76,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: _takingPicture ? Colors.grey : Colors.white,
                  border: Border.all(color: Colors.white70, width: 5),
                ),
                child: _takingPicture
                    ? const Padding(
                        padding: EdgeInsets.all(20),
                        child: CircularProgressIndicator(strokeWidth: 3),
                      )
                    : const Icon(Icons.camera_alt, color: Color(0xFF600000)),
              ),
            ),
            const SizedBox(width: 20),
            IconButton.filledTonal(
              tooltip: 'Reset zoom',
              onPressed: hasZoom ? () => _setZoom(_minZoom) : null,
              icon: const Icon(Icons.center_focus_strong),
            ),
          ],
        ),
      ],
    );
  }
}

class _CameraSlider extends StatelessWidget {
  final String label;
  final double value;
  final double min;
  final double max;
  final IconData icon;
  final ValueChanged<double> onChanged;
  final ValueChanged<double> onChangeEnd;

  const _CameraSlider({
    required this.label,
    required this.value,
    required this.min,
    required this.max,
    required this.icon,
    required this.onChanged,
    required this.onChangeEnd,
  });

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Icon(icon, color: Colors.white, size: 18),
          const SizedBox(width: 8),
          SizedBox(
            width: 78,
            child: Text(label, style: const TextStyle(color: Colors.white)),
          ),
          Expanded(
            child: Slider(
              value: value.clamp(min, max),
              min: min,
              max: max,
              onChanged: onChanged,
              onChangeEnd: onChangeEnd,
            ),
          ),
        ],
      );
}

/// Passport framing overlay for the 3:4 ID-photo canvas.
///
/// Draws a green-bordered 3:4 frame — the largest 3:4 rectangle centered
/// in the camera preview, which is exactly the region
/// [PhotoImageProcessor] keeps after its 3:4 center crop — plus
/// composition marks (head room, eye level, shoulder level) tuned for a
/// 3x4 passport portrait. The area outside the frame is dimmed so the
/// operator can see what will and will not be in the final photo.
class _PassportGuidePainter extends CustomPainter {
  const _PassportGuidePainter();

  /// Final output canvas aspect (width / height) — matches
  /// [PhotoImageProcessor.targetAspect].
  static const double _aspect = 3 / 4;

  static const Color _green = Color(0xFF22C55E);

  /// Composition marks as fractions of the frame height. For a 3x4
  /// passport portrait the top of the head sits near 12%, the eyes near
  /// 42% and the shoulders around 70% of the frame.
  static const double _headTopFrac = 0.12;
  static const double _eyeFrac = 0.42;
  static const double _shoulderFrac = 0.70;

  @override
  void paint(Canvas canvas, Size size) {
    final frameWidth = math.min(size.width, size.height * _aspect);
    final frameHeight = frameWidth / _aspect;
    final frame = Rect.fromCenter(
      center: Offset(size.width / 2, size.height / 2),
      width: frameWidth,
      height: frameHeight,
    );

    _drawScrim(canvas, size, frame);
    _drawFrame(canvas, frame);
    _drawMarks(canvas, frame);
  }

  void _drawScrim(Canvas canvas, Size size, Rect frame) {
    final scrim = Paint()..color = Colors.black.withValues(alpha: 0.50);
    final path = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addRect(frame);
    canvas.drawPath(path, scrim);
  }

  void _drawFrame(Canvas canvas, Rect frame) {
    // Soft green glow under a crisp rectangular border — the border is
    // sharp because the final canvas is a sharp rectangle.
    canvas.drawRect(
      frame.inflate(3),
      Paint()
        ..color = _green.withValues(alpha: 0.22)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 9
        ..maskFilter = const MaskFilter.blur(BlurStyle.normal, 6),
    );
    canvas.drawRect(
      frame,
      Paint()
        ..color = _green.withValues(alpha: 0.95)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2,
    );

    // Viewfinder-style corner brackets, slightly brighter than the
    // border for a modern, instrument-like feel.
    final bracket = Paint()
      ..color = Colors.white.withValues(alpha: 0.95)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 4.5
      ..strokeCap = StrokeCap.round;
    final len = math.min(frame.width, frame.height) * 0.09;
    _drawBracket(canvas, frame.topLeft, 1, 1, len, bracket);
    _drawBracket(canvas, frame.topRight, -1, 1, len, bracket);
    _drawBracket(canvas, frame.bottomLeft, 1, -1, len, bracket);
    _drawBracket(canvas, frame.bottomRight, -1, -1, len, bracket);
  }

  void _drawBracket(
      Canvas canvas, Offset corner, int sx, int sy, double len, Paint paint) {
    canvas.drawLine(corner, corner + Offset(sx * len, 0), paint);
    canvas.drawLine(corner, corner + Offset(0, sy * len), paint);
  }

  void _drawMarks(Canvas canvas, Rect frame) {
    final labelStyle = TextStyle(
      color: Colors.white.withValues(alpha: 0.95),
      fontSize: (frame.height * 0.026).clamp(9.0, 12.0),
      fontWeight: FontWeight.w700,
      letterSpacing: 1.5,
      shadows: const [Shadow(blurRadius: 4, color: Colors.black)],
    );

    // Vertical centerline — subtle, for checking face centering.
    _dashedLine(
      canvas,
      Offset(frame.center.dx, frame.top + frame.height * 0.06),
      Offset(frame.center.dx, frame.bottom - frame.height * 0.06),
      Paint()
        ..color = Colors.white.withValues(alpha: 0.30)
        ..strokeWidth = 1,
    );

    // Head-room tick: where the top of the head should reach.
    final headY = frame.top + frame.height * _headTopFrac;
    canvas.drawLine(
      Offset(frame.center.dx - frame.width * 0.14, headY),
      Offset(frame.center.dx + frame.width * 0.14, headY),
      Paint()
        ..color = Colors.white.withValues(alpha: 0.65)
        ..strokeWidth = 1.5,
    );
    _label(canvas, 'HEAD TOP', Offset(frame.center.dx, headY), labelStyle,
        center: true);

    // Eye-level line — the primary alignment target.
    final eyeY = frame.top + frame.height * _eyeFrac;
    _dashedLine(
      canvas,
      Offset(frame.left, eyeY),
      Offset(frame.right, eyeY),
      Paint()
        ..color = _green
        ..strokeWidth = 2,
    );
    _label(canvas, 'EYE LEVEL', Offset(frame.right - 10, eyeY), labelStyle,
        alignRight: true);

    // Shoulder-level line.
    final shoulderY = frame.top + frame.height * _shoulderFrac;
    _dashedLine(
      canvas,
      Offset(frame.left, shoulderY),
      Offset(frame.right, shoulderY),
      Paint()
        ..color = _green.withValues(alpha: 0.55)
        ..strokeWidth = 1.5,
    );
    _label(canvas, 'SHOULDERS', Offset(frame.right - 10, shoulderY),
        labelStyle, alignRight: true, above: false);
  }

  void _dashedLine(Canvas canvas, Offset a, Offset b, Paint paint,
      {double dash = 10, double gap = 7}) {
    final dx = b.dx - a.dx;
    final dy = b.dy - a.dy;
    final total = math.sqrt(dx * dx + dy * dy);
    if (total <= 0) return;
    final ux = dx / total;
    final uy = dy / total;
    var travelled = 0.0;
    while (travelled < total) {
      final end = math.min(travelled + dash, total);
      canvas.drawLine(
        a + Offset(ux * travelled, uy * travelled),
        a + Offset(ux * end, uy * end),
        paint,
      );
      travelled = end + gap;
    }
  }

  void _label(
    Canvas canvas,
    String text,
    Offset anchor,
    TextStyle style, {
    bool alignRight = false,
    bool center = false,
    bool above = true,
  }) {
    final tp = TextPainter(
      text: TextSpan(text: text, style: style),
      textDirection: TextDirection.ltr,
    )..layout();
    var dx = anchor.dx;
    if (center) dx -= tp.width / 2;
    if (alignRight) dx -= tp.width;
    var dy = anchor.dy;
    if (above) dy -= tp.height + 4;
    tp.paint(canvas, Offset(dx, dy));
  }

  @override
  bool shouldRepaint(covariant _PassportGuidePainter oldDelegate) => false;
}

class _CameraError extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;

  const _CameraError({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.camera_alt_outlined,
                color: Colors.white70, size: 48),
            const SizedBox(height: 12),
            Text(message,
                textAlign: TextAlign.center,
                style: const TextStyle(color: Colors.white)),
            const SizedBox(height: 16),
            FilledButton(onPressed: onRetry, child: const Text('Try again')),
            TextButton(
              onPressed: () => Navigator.of(context).pop(),
              child: const Text('Close camera'),
            ),
          ],
        ),
      );
}
