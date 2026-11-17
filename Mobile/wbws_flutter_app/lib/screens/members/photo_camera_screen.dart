import 'dart:async';
import 'dart:io';
import 'dart:math' as math;

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../services/device_tier_service.dart';
import '../../services/photo_capture_service.dart';
import '../../services/photo_framing_detector.dart';
import '../../services/photo_framing_engine.dart';
import '../../services/photo_framing_geometry.dart';
import '../../services/photo_gallery_import.dart';

/// FKSS adaptive portrait capture (spec: Adaptive Human-Guided Photo
/// Capture v1.0).
///
/// Layout (spec §4): header with logo + offline-readiness indicator and
/// settings; instruction panel above the preview; the live preview with
/// the exact 3:4 save frame and the adaptive landmark-driven guide;
/// three status indicators (face centered / eyes aligned / shoulders
/// visible, green-amber-neutral); bottom controls with gallery import,
/// shutter and camera switching.
///
/// Detection is on-device (ML Kit, bundled models — no internet) and is
/// advisory only: the shutter is never disabled by detection (FR-09).
/// When detection is unavailable the screen falls back to the static
/// manual guide (FR-18).
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

  // Adaptive framing (spec §5, §8).
  final PhotoFramingDetector _detector = PhotoFramingDetector();
  final PhotoFramingEngine _engine = PhotoFramingEngine();
  final ValueNotifier<FramingOverlayData?> _overlay =
      ValueNotifier<FramingOverlayData?>(null);
  Timer? _detectionTimer;
  FramingVerdict? _verdict;
  RawDetection? _lastProcessed;
  bool _hapticsReady = true; // vibrate once when framing becomes ready

  ResolutionPreset get _resolutionPreset =>
      switch (DeviceTierService.instance.tier) {
        DeviceTier.low => ResolutionPreset.high,
        DeviceTier.mid => ResolutionPreset.veryHigh,
        DeviceTier.high => ResolutionPreset.max,
      };

  bool get _isFrontCamera =>
      _selectedCamera?.lensDirection == CameraLensDirection.front;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _loadHaptics();
    unawaited(_initializeCamera());
  }

  Future<void> _loadHaptics() async {
    final prefs = await SharedPreferences.getInstance();
    if (mounted) setState(() => _hapticsReady = prefs.getBool('photo_capture_haptics') ?? true);
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.hidden ||
        state == AppLifecycleState.inactive ||
        state == AppLifecycleState.paused) {
      _appResumed = false;
      _detectionTimer?.cancel();
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
      // Adaptive guide: consume frames from the live controller. The
      // detector degrades to no-ops if the stream cannot start.
      _engine.reset();
      _lastProcessed = null;
      await _detector.attach(controller);
      if (mounted) setState(() {});
      _detectionTimer?.cancel();
      _detectionTimer = Timer.periodic(const Duration(milliseconds: 66), (_) {
        if (!mounted || !_appResumed) return;
        _processLatestDetection();
      });
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

  /// Maps the detector's latest raw result into 3:4 frame space, feeds
  /// the feedback engine and refreshes the overlay + instruction panel.
  void _processLatestDetection() {
    final raw = _detector.latest;
    if (raw == null || identical(raw, _lastProcessed)) return;
    _lastProcessed = raw;

    final controller = _controller;
    if (controller == null || !controller.value.isInitialized) return;

    // The painter paints across the whole preview widget, whose aspect
    // matches the detection image (see photo_framing_geometry.dart for
    // the coordinate contract).
    final preview = SizeF(1000, 1000 / controller.value.aspectRatio);
    final frame = frameRectInPreview(preview);
    final mirror = _isFrontCamera;

    final faces = raw.faces;
    RectF? faceBoxFrame;
    OffsetF? leftEyeFrame;
    OffsetF? rightEyeFrame;
    if (faces.isNotEmpty) {
      final f = faces.first;
      final tl = detectionPointToFrame(
        OffsetF(f.box.left, f.box.top), raw.uprightImageSize, frame,
        preview: preview);
      final br = detectionPointToFrame(
        OffsetF(f.box.right, f.box.bottom), raw.uprightImageSize, frame,
        preview: preview);
      faceBoxFrame = RectF(
        left: tl.x,
        top: tl.y,
        width: (br.x - tl.x).abs(),
        height: (br.y - tl.y).abs(),
      );
      leftEyeFrame = f.leftEye == null
          ? null
          : detectionPointToFrame(f.leftEye!, raw.uprightImageSize, frame,
              preview: preview);
      rightEyeFrame = f.rightEye == null
          ? null
          : detectionPointToFrame(f.rightEye!, raw.uprightImageSize, frame,
              preview: preview);
    }

    PoseObservation? poseObs;
    if (raw.pose != null) {
      final p = raw.pose!;
      poseObs = PoseObservation(
        leftShoulder: p.leftShoulder == null
            ? null
            : detectionPointToFrame(p.leftShoulder!, raw.uprightImageSize,
                frame, preview: preview),
        rightShoulder: p.rightShoulder == null
            ? null
            : detectionPointToFrame(p.rightShoulder!, raw.uprightImageSize,
                frame, preview: preview),
        leftConfidence: p.leftConfidence,
        rightConfidence: p.rightConfidence,
      );
    }

    final verdict = _engine.evaluate(
      FrameObservation(
        faceCount: faces.length,
        faceBox: faceBoxFrame,
        leftEye: leftEyeFrame,
        rightEye: rightEyeFrame,
        headTiltDegrees: faces.isNotEmpty ? faces.first.headTiltDegrees : null,
        pose: poseObs,
      ),
      DateTime.now(),
    );

    _overlay.value = FramingOverlayData(
      faceBox: faceBoxFrame,
      leftEye: leftEyeFrame,
      rightEye: rightEyeFrame,
      pose: poseObs,
      mirror: mirror,
    );

    final changed = _verdict == null ||
        _verdict!.message != verdict.message ||
        _verdict!.ready != verdict.ready ||
        _verdict!.faceCentered != verdict.faceCentered ||
        _verdict!.eyesAligned != verdict.eyesAligned ||
        _verdict!.shouldersVisible != verdict.shouldersVisible;
    if (changed && mounted) setState(() => _verdict = verdict);

    if (verdict.ready && _hapticsReady) {
      _hapticsReady = false;
      HapticFeedback.mediumImpact();
    } else if (!verdict.ready) {
      _hapticsReady = true;
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
    _detectionTimer?.cancel();
    _overlay.value = null;
    _engine.reset();
    _lastProcessed = null;
    await _detector.detach();
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

  Future<void> _importFromGallery() async {
    if (_takingPicture) return;
    try {
      final capture = await PhotoGalleryImport.pickAndNormalize();
      if (!mounted) return;
      if (capture == null) return; // operator cancelled the picker
      if (!await File(capture.path).exists()) {
        if (mounted) {
          setState(
              () => _controlsNotice = 'Could not read the selected photo.');
        }
        return;
      }
      if (mounted) Navigator.of(context).pop(capture);
    } catch (e) {
      if (mounted) {
        setState(() => _controlsNotice = 'Import failed: $e');
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
    _detectionTimer?.cancel();
    unawaited(_detector.dispose());
    _overlay.dispose();
    unawaited(_controller?.dispose());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final controller = _controller;
    final ready = controller != null && controller.value.isInitialized;
    final verdict = _verdict;
    return Scaffold(
      backgroundColor: const Color(0xFF0b0d12),
      body: SafeArea(
        child: Column(
          children: [
            _header(context, ready),
            if (ready) ...[
              _instructionPanel(verdict),
              Expanded(
                child: _previewArea(controller),
              ),
              _statusChips(verdict),
              _sliders(),
              _bottomControls(),
            ] else
              Expanded(
                child: Center(
                  child: _error == null
                      ? const CircularProgressIndicator(
                          color: Color(0xFF22C55E))
                      : _CameraError(
                          message: _error!,
                          onRetry: () => _initializeCamera(
                            preferred: _selectedCamera,
                          ),
                        ),
                ),
              ),
          ],
        ),
      ),
    );
  }

  // --- Header (spec §4.1) ----------------------------------------------

  Widget _header(BuildContext context, bool ready) => Padding(
        padding: const EdgeInsets.fromLTRB(12, 6, 12, 6),
        child: Row(
          children: [
            ClipRRect(
              borderRadius: BorderRadius.circular(8),
              child: Image.asset(
                'assets/logo_school.png',
                height: 30,
                width: 30,
                fit: BoxFit.cover,
                errorBuilder: (_, __, ___) => Container(
                  height: 30,
                  width: 30,
                  decoration: const BoxDecoration(
                    color: Color(0xFF600000),
                    shape: BoxShape.circle,
                  ),
                  alignment: Alignment.center,
                  child: const Text(
                    'F',
                    style: TextStyle(
                        color: Color(0xFFF0C000),
                        fontWeight: FontWeight.w800),
                  ),
                ),
              ),
            ),
            const SizedBox(width: 10),
            const Expanded(
              child: Text(
                'Photo Capture',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: Colors.white,
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.2,
                ),
              ),
            ),
            _OfflineChip(
              state: _offlineState(ready),
            ),
            const SizedBox(width: 6),
            IconButton.filledTonal(
              tooltip: 'Capture settings',
              onPressed: _openSettings,
              icon: const Icon(Icons.settings_outlined, size: 20),
              style: IconButton.styleFrom(
                backgroundColor: Colors.white12,
                foregroundColor: Colors.white,
              ),
            ),
            IconButton.filledTonal(
              tooltip: 'Close camera',
              onPressed: () => Navigator.of(context).pop(),
              icon: const Icon(Icons.close),
              style: IconButton.styleFrom(
                backgroundColor: Colors.white12,
                foregroundColor: Colors.white,
              ),
            ),
          ],
        ),
      );

  /// "Offline Ready" reflects on-device capture readiness — detection
  /// running with no errors — not network state (spec §4.1).
  _ChipState _offlineState(bool ready) {
    if (_detector.isReady && _detector.lastError == null) {
      return _ChipState.good;
    }
    if (_detector.isReady && _detector.lastError != null) {
      return _ChipState.warn;
    }
    return _ChipState.neutral;
  }

  void _openSettings() {
    showModalBottomSheet<void>(
      context: context,
      backgroundColor: const Color(0xFF161a22),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => SafeArea(
        child: StatefulBuilder(
          builder: (ctx, setSheet) => Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Capture settings',
                    style: TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.w700)),
                const SizedBox(height: 12),
                SwitchListTile(
                  value: _hapticsReady == false ? false : true,
                  onChanged: (v) async {
                    final prefs = await SharedPreferences.getInstance();
                    await prefs.setBool('photo_capture_haptics', v);
                    setSheet(() {});
                    if (mounted) setState(() {});
                  },
                  title: const Text('Vibrate when framing is ready',
                      style: TextStyle(color: Colors.white, fontSize: 14)),
                  subtitle: const Text('Off does not affect capture',
                      style: TextStyle(color: Colors.white54, fontSize: 12)),
                  activeThumbColor: const Color(0xFF22C55E),
                ),
                const Divider(color: Colors.white12),
                const Text('Flash',
                    style: TextStyle(color: Colors.white70, fontSize: 13)),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final mode in [FlashMode.off, FlashMode.auto, FlashMode.torch])
                      ChoiceChip(
                        label: Text(switch (mode) {
                          FlashMode.off => 'Off',
                          FlashMode.auto => 'Auto',
                          FlashMode.torch || FlashMode.always => 'Torch',
                        }),
                        selected: _flashMode == mode,
                        onSelected: (_) async {
                          await _cycleFlashTo(mode);
                          setSheet(() {});
                        },
                        selectedColor: const Color(0xFF22C55E),
                      ),
                  ],
                ),
                const Divider(color: Colors.white12),
                TextButton.icon(
                  onPressed: () async {
                    final controller = _controller;
                    if (controller != null && _maxZoom > _minZoom) {
                      await _setZoom(_minZoom);
                    }
                    if (_maxExposure > _minExposure) {
                      await _setExposure(
                          0.clamp(_minExposure, _maxExposure).toDouble());
                    }
                    if (ctx.mounted) Navigator.pop(ctx);
                  },
                  icon: const Icon(Icons.restart_alt, size: 18),
                  label: const Text('Reset zoom & brightness'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _cycleFlashTo(FlashMode next) async {
    final controller = _controller;
    if (controller == null) return;
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

  // --- Instruction panel (spec §4.2) -----------------------------------

  Widget _instructionPanel(FramingVerdict? verdict) {
    final ready = verdict?.ready ?? false;
    final message = ready
        ? 'Good framing — ready to capture'
        : (verdict?.message ?? 'Position the student inside the frame');
    final color = ready
        ? const Color(0xFF22C55E)
        : (verdict == null ? Colors.white70 : const Color(0xFFF0C000));
    return Container(
      margin: const EdgeInsets.fromLTRB(16, 2, 16, 6),
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: Colors.white12),
      ),
      child: Row(
        children: [
          Icon(
            ready
                ? Icons.check_circle
                : (verdict == null
                    ? Icons.center_focus_weak
                    : Icons.adjust),
            color: color,
            size: 18,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Text(
              message,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: color,
                fontSize: 13,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
          if (widget.memberName.isNotEmpty) ...[
            const SizedBox(width: 8),
            Flexible(
              child: Text(
                widget.memberName,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: const TextStyle(color: Colors.white38, fontSize: 11),
              ),
            ),
          ],
        ],
      ),
    );
  }

  // --- Preview (spec §4.3) ----------------------------------------------

  Widget _previewArea(CameraController controller) {
    return Center(
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
                  // The overlay is painted in exact preview coordinates;
                  // its 3:4 frame is the region that survives the
                  // processor's 3:4 center crop (FR-03).
                  CustomPaint(
                    painter: _AdaptiveGuidePainter(_overlay),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  // --- Status chips (spec §4.4) ------------------------------------------

  Widget _statusChips(FramingVerdict? verdict) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 4, 16, 0),
      child: Row(
        children: [
          Expanded(
            child: _StatusChip(
              label: 'Face centered',
              state: verdict == null
                  ? _ChipState.neutral
                  : _map(verdict.faceCentered),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _StatusChip(
              label: 'Eyes aligned',
              state: verdict == null
                  ? _ChipState.neutral
                  : _map(verdict.eyesAligned),
            ),
          ),
          const SizedBox(width: 8),
          Expanded(
            child: _StatusChip(
              label: 'Shoulders visible',
              state: verdict == null
                  ? _ChipState.neutral
                  : _map(verdict.shouldersVisible),
            ),
          ),
        ],
      ),
    );
  }

  _ChipState _map(CheckState state) => switch (state) {
        CheckState.pass => _ChipState.good,
        CheckState.warn => _ChipState.warn,
        CheckState.neutral => _ChipState.neutral,
      };

  // --- Sliders + bottom controls (spec §4.5) -----------------------------

  Widget _sliders() {
    final hasExposure = _maxExposure > _minExposure;
    final hasZoom = _maxZoom > _minZoom;
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 12),
      child: Column(
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
              padding: const EdgeInsets.only(top: 4),
              child: Text(
                _controlsNotice!,
                textAlign: TextAlign.center,
                style:
                    const TextStyle(color: Colors.orangeAccent, fontSize: 12),
              ),
            ),
        ],
      ),
    );
  }

  Widget _bottomControls() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(16, 6, 16, 14),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          // Gallery import (spec §4.5)
          IconButton.filledTonal(
            tooltip: 'Import from gallery',
            onPressed: _takingPicture ? null : _importFromGallery,
            icon: const Icon(Icons.photo_library_outlined),
            style: IconButton.styleFrom(
              backgroundColor: Colors.white12,
              foregroundColor: Colors.white,
            ),
          ),
          GestureDetector(
            onTap: _takePicture,
            child: Container(
              width: 76,
              height: 76,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: _takingPicture ? Colors.grey : Colors.white,
                border: Border.all(
                  color: const Color(0xFF22C55E),
                  width: 5,
                ),
              ),
              child: _takingPicture
                  ? const Padding(
                      padding: EdgeInsets.all(20),
                      child: CircularProgressIndicator(strokeWidth: 3),
                    )
                  : const Icon(Icons.camera_alt, color: Color(0xFF600000)),
            ),
          ),
          if (_cameras.length > 1)
            IconButton.filledTonal(
              tooltip: 'Switch camera',
              onPressed: _switchCamera,
              icon: const Icon(Icons.cameraswitch),
              style: IconButton.styleFrom(
                backgroundColor: Colors.white12,
                foregroundColor: Colors.white,
              ),
            )
          else
            const SizedBox(width: 48),
        ],
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// Overlay data + painter (spec §4.3, §5)
// ---------------------------------------------------------------------------

/// Landmarks mapped into 3:4 frame space, plus the front-camera mirror
/// flag used only when drawing (checks run in capture space).
class FramingOverlayData {
  final RectF? faceBox;
  final OffsetF? leftEye;
  final OffsetF? rightEye;
  final PoseObservation? pose;
  final bool mirror;

  const FramingOverlayData({
    required this.faceBox,
    required this.leftEye,
    required this.rightEye,
    required this.pose,
    required this.mirror,
  });
}

/// Adaptive guide painter. Static layer: dimmed surroundings, exact 3:4
/// green frame with viewfinder brackets, centerline and level lines.
/// Dynamic layer (when landmarks are available): a face outline derived
/// from the detected bounding box, detected eye markers and a detected
/// shoulder line — the outline adapts to the person instead of forcing
/// one silhouette (spec §5.3). Without landmarks only the static manual
/// guide is drawn.
class _AdaptiveGuidePainter extends CustomPainter {
  _AdaptiveGuidePainter(this._data);

  final ValueNotifier<FramingOverlayData?> _data;

  static const Color _green = Color(0xFF22C55E);

  @override
  void paint(Canvas canvas, Size size) {
    final preview = SizeF(size.width, size.height);
    final frame = frameRectInPreview(preview);
    final fRect = Rect.fromLTWH(
        frame.left, frame.top, frame.width, frame.height);

    _drawScrim(canvas, size, fRect);
    _drawFrame(canvas, fRect);
    _drawStaticMarks(canvas, fRect);
    _drawDynamic(canvas, fRect);
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

  void _drawStaticMarks(Canvas canvas, Rect frame) {
    final labelStyle = TextStyle(
      color: Colors.white.withValues(alpha: 0.85),
      fontSize: (frame.height * 0.026).clamp(9.0, 12.0),
      fontWeight: FontWeight.w700,
      letterSpacing: 1.5,
      shadows: const [Shadow(blurRadius: 4, color: Colors.black)],
    );

    _dashedLine(
      canvas,
      Offset(frame.center.dx, frame.top + frame.height * 0.06),
      Offset(frame.center.dx, frame.bottom - frame.height * 0.06),
      Paint()
        ..color = Colors.white.withValues(alpha: 0.30)
        ..strokeWidth = 1,
    );

    final eyeY = frame.top + frame.height * 0.42;
    _dashedLine(
      canvas,
      Offset(frame.left, eyeY),
      Offset(frame.right, eyeY),
      Paint()
        ..color = _green.withValues(alpha: 0.75)
        ..strokeWidth = 1.5,
    );
    _label(canvas, 'EYE LEVEL', Offset(frame.right - 10, eyeY), labelStyle,
        alignRight: true);

    final shoulderY = frame.top + frame.height * 0.70;
    _dashedLine(
      canvas,
      Offset(frame.left, shoulderY),
      Offset(frame.right, shoulderY),
      Paint()
        ..color = _green.withValues(alpha: 0.45)
        ..strokeWidth = 1.2,
    );
    _label(canvas, 'SHOULDERS', Offset(frame.right - 10, shoulderY),
        labelStyle, alignRight: true, above: false);
  }

  void _drawDynamic(Canvas canvas, Rect frame) {
    final data = _data.value;
    if (data == null) return;
    final faceBox = data.faceBox;
    if (faceBox == null) return;

    // Face outline: a soft-cornered box traced from the DETECTED
    // bounding box — adapts to head size, hairstyle and head coverings
    // without becoming an opaque silhouette.
    final frameF = RectF(
      left: frame.left,
      top: frame.top,
      width: frame.width,
      height: frame.height,
    );
    final box = Rect.fromLTRB(
      frameXToPreview(faceBox.left, frameF, mirror: data.mirror),
      frame.top + faceBox.top * frame.height,
      frameXToPreview(faceBox.right, frameF, mirror: data.mirror),
      frame.top + faceBox.bottom * frame.height,
    );
    final outline = Paint()
      ..color = Colors.white.withValues(alpha: 0.55)
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6;
    final radius = Radius.circular(math.min(box.width, box.height) * 0.30);
    canvas.drawRRect(
      RRect.fromRectAndRadius(box, radius),
      outline,
    );

    // Detected eye markers + eye line through the actual eyes.
    final eyes = <Offset?>[
      if (data.leftEye != null)
        _toPreview(data.leftEye!, frame, data.mirror),
      if (data.rightEye != null)
        _toPreview(data.rightEye!, frame, data.mirror),
    ];
    if (eyes.isNotEmpty) {
      final eyePaint = Paint()
        ..color = _green
        ..style = PaintingStyle.fill;
      for (final e in eyes.whereType<Offset>()) {
        canvas.drawCircle(e, 4, eyePaint);
        canvas.drawCircle(
          e,
          4,
          Paint()
            ..color = Colors.black54
            ..style = PaintingStyle.stroke
            ..strokeWidth = 1,
        );
      }
      if (eyes.length == 2) {
        final a = eyes[0]!;
        final b = eyes[1]!;
        canvas.drawLine(
          a,
          b,
          Paint()
            ..color = _green.withValues(alpha: 0.9)
            ..strokeWidth = 1.4,
        );
      }
    }

    // Detected shoulder line (pose landmarks, spec §5.2). Neutral manual
    // shoulder guide remains visible underneath when pose is missing.
    final pose = data.pose;
    if (pose != null &&
        pose.leftShoulder != null &&
        pose.rightShoulder != null) {
      final l = _toPreview(pose.leftShoulder!, frame, data.mirror);
      final r = _toPreview(pose.rightShoulder!, frame, data.mirror);
      final shoulderPaint = Paint()
        ..color = const Color(0xFFF0C000).withValues(alpha: 0.9)
        ..strokeWidth = 2.2
        ..strokeCap = StrokeCap.round;
      canvas.drawLine(l, r, shoulderPaint);
      final dot = Paint()..color = const Color(0xFFF0C000);
      canvas.drawCircle(l, 4, dot);
      canvas.drawCircle(r, 4, dot);
    }
  }

  Offset _toPreview(OffsetF n, Rect frame, bool mirror) {
    final p = framePointToPreview(n, frameF(frame), mirror: mirror);
    return Offset(p.x, p.y);
  }

  static RectF frameF(Rect frame) => RectF(
        left: frame.left,
        top: frame.top,
        width: frame.width,
        height: frame.height,
      );

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
  bool shouldRepaint(covariant _AdaptiveGuidePainter oldDelegate) => false;
}

// ---------------------------------------------------------------------------
// Shared UI pieces
// ---------------------------------------------------------------------------

enum _ChipState { good, warn, neutral }

/// Status / offline chip — icon + text so meaning never relies on color
/// alone (spec §13).
class _StatusChip extends StatelessWidget {
  final String label;
  final _ChipState state;

  const _StatusChip({required this.label, required this.state});

  @override
  Widget build(BuildContext context) {
    final (color, icon, tooltip) = switch (state) {
      _ChipState.good => (
          const Color(0xFF22C55E),
          Icons.check_circle,
          'OK',
        ),
      _ChipState.warn => (
          const Color(0xFFF0C000),
          Icons.error_outline,
          'Adjust',
        ),
      _ChipState.neutral => (
          Colors.white38,
          Icons.help_outline,
          'Not detected',
        ),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 6),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Row(
        children: [
          Icon(icon, color: color, size: 14),
          const SizedBox(width: 5),
          Expanded(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              semanticsLabel: '$label: $tooltip',
              style: TextStyle(
                color: color,
                fontSize: 11,
                fontWeight: FontWeight.w600,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _OfflineChip extends StatelessWidget {
  final _ChipState state;
  const _OfflineChip({required this.state});

  @override
  Widget build(BuildContext context) {
    final (color, icon, text) = switch (state) {
      _ChipState.good => (
          const Color(0xFF22C55E),
          Icons.cloud_off,
          'Offline Ready',
        ),
      _ChipState.warn => (
          const Color(0xFFF0C000),
          Icons.warning_amber_rounded,
          'Detection limited',
        ),
      _ChipState.neutral => (
          Colors.white38,
          Icons.hourglass_empty,
          'Starting…',
        ),
    };
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.14),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.4)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, color: color, size: 13),
          const SizedBox(width: 4),
          Text(
            text,
            style: TextStyle(
              color: color,
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
            ),
          ),
        ],
      ),
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
