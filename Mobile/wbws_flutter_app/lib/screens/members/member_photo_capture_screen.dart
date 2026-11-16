import 'dart:io';

import 'package:flutter/material.dart';

import '../../services/photo_capture_service.dart';
import '../../services/photo_outbox_models.dart';
import '../../services/photo_outbox_service.dart';

/// FKSS brand colors used by photo-capture UI (matches admin dashboard).
/// Mobile theme.dart is app-wide blue; photo screens use the school
/// maroon+gold so they visually cohere with the printed ID product.
class _AppThemePhoto {
  static const Color maroon = Color(0xFF600000);
  static const Color gold = Color(0xFFF0C000);
}

// Shorthand alias for the capture-screen widgets below.
typedef AppTheme = _AppThemePhoto;

/// Single-member photo capture screen (P2 MVP — batch roster comes in P5/P6).
///
/// Shows a large instruction card with the member's name/code, opens the
/// camera via PhotoCaptureService on tap, shows the encoded photo for
/// review with ✓ Accept / ↺ Retake, and either returns the enqueued entry
/// or stays open for a retake.
///
/// Route args:
///   arguments = {'memberId': int, 'memberCode': String?, 'memberName': String,
///                'field': PhotoField?, 'retakeOfClientOpId': String?}
class MemberPhotoCaptureScreen extends StatefulWidget {
  const MemberPhotoCaptureScreen({super.key});

  @override
  State<MemberPhotoCaptureScreen> createState() =>
      _MemberPhotoCaptureScreenState();
}

class _MemberPhotoCaptureScreenState extends State<MemberPhotoCaptureScreen> {
  bool _capturing = false;
  PhotoOutboxEntry? _reviewEntry;
  String? _error;

  int get _memberId =>
      (ModalRoute.of(context)?.settings.arguments
          as Map<String, dynamic>?)?['memberId']
      as int;
  String? get _memberCode => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['memberCode'] as String?;
  String get _memberName =>
      ((ModalRoute.of(context)?.settings.arguments
          as Map<String, dynamic>?)?['memberName'] as String?) ??
      'Member';
  PhotoField get _field => ((ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['field'] as PhotoField?) ??
      PhotoField.student;
  String? get _retakeOf => (ModalRoute.of(context)?.settings.arguments
      as Map<String, dynamic>?)?['retakeOfClientOpId'] as String?;

  Future<void> _takePhoto() async {
    if (_capturing) return;
    setState(() {
      _capturing = true;
      _error = null;
    });
    try {
      final entry = await PhotoCaptureService.instance.captureAndEnqueue(
        memberId: _memberId,
        memberCode: _memberCode,
        field: _field,
        retakeOfClientOpId: _retakeOf,
      );
      if (!mounted) return;
      if (entry == null) {
        // User cancelled the camera.
        setState(() => _capturing = false);
        return;
      }
      setState(() {
        _reviewEntry = entry;
        _capturing = false;
      });
    } on PhotoCaptureException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _capturing = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = 'Unexpected error: $e';
        _capturing = false;
      });
    }
  }

  void _retake() {
    // Discard the just-captured entry, reopen the shutter.
    final entry = _reviewEntry;
    if (entry != null) {
      PhotoOutboxService.instance.discard(entry.clientOpId);
    }
    setState(() {
      _reviewEntry = null;
      _error = null;
    });
    _takePhoto();
  }

  void _accept() {
    final entry = _reviewEntry;
    if (entry == null) return;
    Navigator.of(context).pop(entry);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_field == PhotoField.student
            ? 'Capture ID Photo'
            : 'Capture Guardian Photo'),
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              _MemberCard(name: _memberName, code: _memberCode),
              const SizedBox(height: 16),
              Expanded(child: _buildBody()),
              const SizedBox(height: 16),
              _buildActions(),
            ],
          ),
        ),
      ),
    );
  }

  Widget _buildBody() {
    if (_error != null) {
      return _ErrorPanel(message: _error!, onRetry: _takePhoto);
    }
    if (_capturing) {
      return const Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 12),
            Text('Opening camera…'),
          ],
        ),
      );
    }
    final entry = _reviewEntry;
    if (entry != null) {
      return _ReviewPanel(entry: entry);
    }
    return const _IdlePanel();
  }

  Widget _buildActions() {
    if (_reviewEntry != null) {
      return Row(
        children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: _retake,
              icon: const Icon(Icons.refresh),
              label: const Text('Retake'),
              style: OutlinedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: FilledButton.icon(
              onPressed: _accept,
              icon: const Icon(Icons.check),
              label: const Text('Accept'),
              style: FilledButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 14),
                backgroundColor: AppTheme.maroon,
              ),
            ),
          ),
        ],
      );
    }
    return FilledButton.icon(
      onPressed: _capturing ? null : _takePhoto,
      icon: const Icon(Icons.camera_alt),
      label: Text(_capturing ? 'Opening…' : '📷 Take Photo'),
      style: FilledButton.styleFrom(
        padding: const EdgeInsets.symmetric(vertical: 16),
        backgroundColor: AppTheme.maroon,
        textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w600),
      ),
    );
  }
}

class _MemberCard extends StatelessWidget {
  final String name;
  final String? code;
  const _MemberCard({required this.name, required this.code});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: AppTheme.maroon.withOpacity(0.06),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppTheme.maroon.withOpacity(0.2)),
      ),
      child: Row(
        children: [
          CircleAvatar(
            backgroundColor: AppTheme.maroon,
            child: Text(
              name.isNotEmpty ? name[0].toUpperCase() : '?',
              style: const TextStyle(color: Colors.white),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(name,
                    style: const TextStyle(
                        fontSize: 18, fontWeight: FontWeight.w700)),
                if (code != null)
                  Text(code!,
                      style: TextStyle(
                          color: Colors.grey.shade700, fontSize: 13)),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _IdlePanel extends StatelessWidget {
  const _IdlePanel();

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(Icons.person_pin_circle_outlined,
              size: 96, color: Colors.grey.shade400),
          const SizedBox(height: 16),
          const Text('Frame the subject head-to-shoulders',
              style: TextStyle(fontSize: 16)),
          const SizedBox(height: 8),
          Text('Face centered · even lighting · no hat',
              style: TextStyle(color: Colors.grey.shade600)),
          const SizedBox(height: 24),
          Container(
            padding: const EdgeInsets.all(12),
            decoration: BoxDecoration(
              color: AppTheme.gold.withOpacity(0.15),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Text(
              'Photos are saved privately on your device. They will NOT '
              'upload until you enable sync in the Sync Panel. You can '
              'also Export ZIP to hand in photos via USB.',
              style: TextStyle(
                color: Colors.brown.shade900,
                fontSize: 12,
              ),
              textAlign: TextAlign.center,
            ),
          ),
        ],
      ),
    );
  }
}

class _ReviewPanel extends StatelessWidget {
  final PhotoOutboxEntry entry;
  const _ReviewPanel({required this.entry});

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Expanded(
          child: ClipRRect(
            borderRadius: BorderRadius.circular(12),
            child: Container(
              color: Colors.black,
              alignment: Alignment.center,
              child: Image.file(
                File(entry.localPath),
                fit: BoxFit.contain,
                errorBuilder: (_, __, ___) => const Center(
                  child: Text('Could not preview.',
                      style: TextStyle(color: Colors.white)),
                ),
              ),
            ),
          ),
        ),
        const SizedBox(height: 12),
        _InfoChip(
          icon: Icons.check_circle_outline,
          label:
              '${entry.format.extension.toUpperCase()} · ${entry.width}×${entry.height} · ${entry.humanSize}',
        ),
      ],
    );
  }
}

class _InfoChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const _InfoChip({required this.icon, required this.label});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.green.shade50,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: Colors.green.shade200),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: Colors.green.shade700),
          const SizedBox(width: 6),
          Text(label,
              style: TextStyle(
                  color: Colors.green.shade800,
                  fontSize: 12,
                  fontWeight: FontWeight.w500)),
        ],
      ),
    );
  }
}

class _ErrorPanel extends StatelessWidget {
  final String message;
  final VoidCallback onRetry;
  const _ErrorPanel({required this.message, required this.onRetry});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.error_outline, size: 64, color: Colors.redAccent),
          const SizedBox(height: 12),
          Text(message,
              textAlign: TextAlign.center,
              style: const TextStyle(color: Colors.redAccent)),
          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: onRetry,
            icon: const Icon(Icons.refresh),
            label: const Text('Try Again'),
          ),
        ],
      ),
    );
  }
}
