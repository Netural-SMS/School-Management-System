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
              LayoutBuilder(
                builder: (context, constraints) => GestureDetector(
                  behavior: HitTestBehavior.opaque,
                  onTapDown: (details) => _focusAt(
                    details,
                    Size(constraints.maxWidth, constraints.maxHeight),
                  ),
                  child: Center(child: CameraPreview(controller)),
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
              const Positioned.fill(
                child: IgnorePointer(
                  child: CustomPaint(painter: _PortraitGuidePainter()),
                ),
              ),
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
                  'Center the face in the guide · even light · plain background',
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

class _PortraitGuidePainter extends CustomPainter {
  const _PortraitGuidePainter();

  @override
  void paint(Canvas canvas, Size size) {
    final guideWidth = math.min(size.width * 0.72, size.height * 0.62);
    final guideHeight = guideWidth * 1.24;
    final guide = Rect.fromCenter(
      center: Offset(size.width / 2, size.height * 0.49),
      width: guideWidth,
      height: guideHeight,
    );
    final shade = Paint()..color = Colors.black.withValues(alpha: 0.28);
    final overlay = Path()
      ..fillType = PathFillType.evenOdd
      ..addRect(Offset.zero & size)
      ..addOval(guide);
    canvas.drawPath(overlay, shade);

    final border = Paint()
      ..color = Colors.white.withValues(alpha: 0.92)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 2.2;
    canvas.drawOval(guide, border);
    final eyeGuide = Paint()
      ..color = const Color(0xFFF0C000).withValues(alpha: 0.85)
      ..strokeWidth = 1
      ..style = PaintingStyle.stroke;
    final eyeY = guide.top + guide.height * 0.38;
    canvas.drawLine(
      Offset(guide.left + 12, eyeY),
      Offset(guide.right - 12, eyeY),
      eyeGuide,
    );
  }

  @override
  bool shouldRepaint(covariant _PortraitGuidePainter oldDelegate) => false;
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
