import 'dart:async';
import 'dart:math' as math;

import 'package:barcode_widget/barcode_widget.dart';
import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:screen_brightness/screen_brightness.dart';
import 'package:wakelock_plus/wakelock_plus.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/qr/presentation/controllers/qr_controller.dart';
import 'package:paylo/shared/widgets/secure_screen.dart';

/// Barkod ekranı — Code128 göstərir, POS skanerlərinə uyğun professional dizayn.
///
/// Universal oxunaqlılıq üçün:
/// - Landscape orientation lock (mobil) → ekranın fiziki eni maksimum.
/// - Ekran parlaqlığı 100% (skanerin kontrast oxuması üçün).
/// - Wakelock aktiv → ekran sönmür, skannın orta hesabı 2-3 saniyəlik fokusu var.
/// - `drawText: false` + ayrıca monospace label → barkod yüksək kontrastı saxlayır.
/// - Bar height ≥ 18% of width (ISO/IEC 15417 tövsiyəsi 15%-dən yüksək).
/// - Quiet zone 10× module-width — BarcodeWidget-in default-i, əlavə `Padding` ilə təmin.
class QrScreen extends ConsumerStatefulWidget {
  const QrScreen({super.key});

  @override
  ConsumerState<QrScreen> createState() => _QrScreenState();
}

class _QrScreenState extends ConsumerState<QrScreen> with WidgetsBindingObserver {
  Timer? _tick;
  int _secondsLeft = 30;
  double? _originalBrightness;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);

    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      final p = ref.read(qrControllerProvider).payload;
      if (p != null && mounted) {
        setState(() => _secondsLeft = p.secondsLeft);
      }
    });

    // Platform-spesifik enchancement-lər — yalnız mobil-də.
    if (!kIsWeb) {
      // Landscape lock — barkodun fiziki eni böyüyür.
      SystemChrome.setPreferredOrientations([
        DeviceOrientation.landscapeLeft,
        DeviceOrientation.landscapeRight,
      ]);
      // Ekran sönməsin
      WakelockPlus.enable().catchError((_) {});
      // Parlaqlığı maksimuma qaldır (orijinalı yadda saxla)
      _boostBrightness();
    }
  }

  Future<void> _boostBrightness() async {
    try {
      _originalBrightness = await ScreenBrightness().current;
      await ScreenBrightness().setScreenBrightness(1.0);
    } catch (_) {
      // platform dəstəkləmir — sakitcə davam et
    }
  }

  Future<void> _restoreBrightness() async {
    try {
      if (_originalBrightness != null) {
        await ScreenBrightness().setScreenBrightness(_originalBrightness!);
      } else {
        await ScreenBrightness().resetScreenBrightness();
      }
    } catch (_) {}
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _tick?.cancel();

    if (!kIsWeb) {
      // Bütün orientasiyaları bərpa et.
      SystemChrome.setPreferredOrientations(DeviceOrientation.values);
      WakelockPlus.disable().catchError((_) {});
      _restoreBrightness();
    }

    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(qrControllerProvider);
    final authState = ref.watch(authControllerProvider);
    final user = authState is AuthAuthenticated ? authState.session.user : null;
    final customerCode = user?.customerQr ?? (user != null ? 'cust_${user.id}' : '—');

    // Sprint 9 M-1: QR ekranı həssas data — screenshot/recording bağlanır.
    return SecureScreen(
      child: Scaffold(
        backgroundColor: AppColors.bg,
        appBar: AppBar(
          title: const Text('Barkod'),
          centerTitle: false,
          actions: [
            IconButton(
              icon: const Icon(Icons.refresh_rounded, size: 22),
              tooltip: 'Yenilə',
              onPressed: () => ref.read(qrControllerProvider.notifier).refresh(),
            ),
            const SizedBox(width: 4),
          ],
        ),
        body: SafeArea(
          child: LayoutBuilder(
            builder: (context, constraints) {
              final isLandscape = constraints.maxWidth > constraints.maxHeight;
              return _Body(
                state: state,
                customerCode: customerCode,
                secondsLeft: _secondsLeft,
                isLandscape: isLandscape,
                onRefresh: () => ref.read(qrControllerProvider.notifier).refresh(),
              );
            },
          ),
        ),
      ),
    );
  }
}

class _Body extends StatelessWidget {
  const _Body({
    required this.state,
    required this.customerCode,
    required this.secondsLeft,
    required this.isLandscape,
    required this.onRefresh,
  });

  final QrState state;
  final String customerCode;
  final int secondsLeft;
  final bool isLandscape;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    if (state.payload == null && state.loading) {
      return const Center(
        child: CircularProgressIndicator(color: AppColors.accent),
      );
    }

    if (state.payload == null && state.error != null) {
      return _ErrorView(message: state.error!.message, onRetry: onRefresh);
    }

    if (state.payload == null) {
      return const SizedBox.shrink();
    }

    return Padding(
      padding: EdgeInsets.symmetric(
        horizontal: isLandscape ? 16 : 16,
        vertical: 12,
      ),
      child: isLandscape
          ? _LandscapeLayout(
              data: state.payload!.qrValue,
              customerCode: customerCode,
              secondsLeft: secondsLeft,
              totalSeconds: state.payload!.ttl,
              onRefresh: onRefresh,
            )
          : _PortraitLayout(
              data: state.payload!.qrValue,
              customerCode: customerCode,
              secondsLeft: secondsLeft,
              totalSeconds: state.payload!.ttl,
              onRefresh: onRefresh,
            ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Layout: landscape (tövsiyə olunan rejim — barkod maks. fiziki ən)
// ─────────────────────────────────────────────────────────────────────────────
class _LandscapeLayout extends StatelessWidget {
  const _LandscapeLayout({
    required this.data,
    required this.customerCode,
    required this.secondsLeft,
    required this.totalSeconds,
    required this.onRefresh,
  });

  final String data;
  final String customerCode;
  final int secondsLeft;
  final int totalSeconds;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        // Sol: barkod kartı — ekranın 75%-i
        Expanded(
          flex: 3,
          child: _BarcodeCard(data: data, customerCode: customerCode),
        ),
        const SizedBox(width: 16),
        // Sağ: meta panel — countdown + customer code + refresh
        Expanded(
          flex: 1,
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text(
                'KASSİRƏ GÖSTƏR',
                style: AppTextStyles.mono(10,
                    color: AppColors.muted,
                    letterSpacing: 0.32,
                    weight: FontWeight.w700),
                textAlign: TextAlign.center,
              ),
              const SizedBox(height: 14),
              _CountdownRing(secondsLeft: secondsLeft, totalSeconds: totalSeconds),
              const SizedBox(height: 14),
              _CustomerCodeBadge(code: customerCode),
              const SizedBox(height: 12),
              _RefreshButton(onPressed: onRefresh),
            ],
          ),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Layout: portrait (fallback — landscape-ə rotate olmayan veb/tablet)
// ─────────────────────────────────────────────────────────────────────────────
class _PortraitLayout extends StatelessWidget {
  const _PortraitLayout({
    required this.data,
    required this.customerCode,
    required this.secondsLeft,
    required this.totalSeconds,
    required this.onRefresh,
  });

  final String data;
  final String customerCode;
  final int secondsLeft;
  final int totalSeconds;
  final VoidCallback onRefresh;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const SizedBox(height: 8),
        Text(
          'BARKODU KASSİRƏ GÖSTƏRİN',
          style: AppTextStyles.mono(11,
              color: AppColors.muted,
              letterSpacing: 0.36,
              weight: FontWeight.w700),
          textAlign: TextAlign.center,
        ),
        const SizedBox(height: 4),
        Text(
          'Hər 30 saniyədə yenilənir',
          style: AppTextStyles.body(12, color: AppColors.text2),
          textAlign: TextAlign.center,
        ),
        const Spacer(),
        // Barkod kartı — bütün enliyi tutur
        _BarcodeCard(data: data, customerCode: customerCode),
        const SizedBox(height: 24),
        // Countdown + meta sətri
        Row(
          children: [
            _CountdownRing(secondsLeft: secondsLeft, totalSeconds: totalSeconds),
            const SizedBox(width: 14),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    secondsLeft > 0
                        ? '$secondsLeft san sonra yenilənir'
                        : 'yenilənir...',
                    style: AppTextStyles.body(13,
                        color: AppColors.text, weight: FontWeight.w600),
                  ),
                  const SizedBox(height: 2),
                  Text(
                    'Telefonu üfüqi tutsanız skaner daha asan oxuyar',
                    style: AppTextStyles.body(11, color: AppColors.muted),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 18),
        _CustomerCodeBadge(code: customerCode, expanded: true),
        const SizedBox(height: 12),
        _RefreshButton(onPressed: onRefresh),
        const SizedBox(height: 8),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Barkod kartı — universal oxunaqlılıq üçün ölçü qaydaları:
//  - Card padding: top/bottom 18, sides 24 (quiet zone üçün)
//  - Barkod ölçüsü: tam mövcud genişlik × max(width × 0.22, 100)
//    (ISO/IEC 15417 min 15% — bizdə 22% margin var)
//  - Pure black-on-white, drawText:false (kontrast pozulmasın)
//  - Alt sətir: müştəri kodu monospace, ayrıca - kontrastsızlaşdırmır
// ─────────────────────────────────────────────────────────────────────────────
class _BarcodeCard extends StatelessWidget {
  const _BarcodeCard({required this.data, required this.customerCode});

  final String data;
  final String customerCode;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        final cardWidth = constraints.maxWidth;
        // Barkod fiziki sahəsi — card daxili: padding 24 hər tərəfdə
        final barcodeWidth = math.max(cardWidth - 48, 200.0);
        // Aspect ratio: ən 22% (ISO min 15% + buffer)
        final barcodeHeight = (barcodeWidth * 0.22).clamp(100.0, 220.0);

        return Container(
          padding: const EdgeInsets.fromLTRB(24, 22, 24, 18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(20),
            border: Border.all(color: Colors.white, width: 0),
            boxShadow: [
              BoxShadow(
                color: AppColors.accent.withValues(alpha: 0.22),
                blurRadius: 32,
                spreadRadius: -6,
                offset: const Offset(0, 10),
              ),
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.08),
                blurRadius: 8,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                width: barcodeWidth,
                height: barcodeHeight,
                child: BarcodeWidget(
                  barcode: Barcode.code128(escapes: false),
                  data: data,
                  drawText: false,
                  color: Colors.black,
                  backgroundColor: Colors.white,
                  // Quiet zone — BarcodeWidget default-u 10× modul, padding-i təmin edir.
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 4),
                ),
              ),
              const SizedBox(height: 14),
              // Alt sətir: müştəri kodu — kassir əl ilə daxil edə bilsin
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
                decoration: BoxDecoration(
                  color: const Color(0xFFF4F6F9),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text('MÜŞTƏRİ KODU',
                        style: AppTextStyles.mono(9,
                            color: Colors.black54,
                            letterSpacing: 0.4,
                            weight: FontWeight.w700)),
                    const SizedBox(width: 10),
                    Text(customerCode,
                        style: AppTextStyles.mono(13,
                            color: Colors.black87,
                            letterSpacing: 0.3,
                            weight: FontWeight.w700)),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Countdown ring — dairəvi progress + qalan saniyə
// ─────────────────────────────────────────────────────────────────────────────
class _CountdownRing extends StatelessWidget {
  const _CountdownRing({required this.secondsLeft, required this.totalSeconds});

  final int secondsLeft;
  final int totalSeconds;

  @override
  Widget build(BuildContext context) {
    final progress = totalSeconds == 0
        ? 0.0
        : (secondsLeft / totalSeconds).clamp(0.0, 1.0);
    final color = secondsLeft < 5 ? AppColors.warning : AppColors.accent;

    return SizedBox(
      width: 64,
      height: 64,
      child: Stack(
        alignment: Alignment.center,
        children: [
          // Arxa fon — passive ring
          SizedBox.expand(
            child: CircularProgressIndicator(
              value: 1.0,
              strokeWidth: 5,
              color: AppColors.surface2,
            ),
          ),
          // Aktiv progress
          SizedBox.expand(
            child: TweenAnimationBuilder<double>(
              tween: Tween(begin: progress, end: progress),
              duration: const Duration(milliseconds: 700),
              builder: (_, value, __) => CircularProgressIndicator(
                value: value,
                strokeWidth: 5,
                color: color,
              ),
            ),
          ),
          Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              Text(
                '${secondsLeft.clamp(0, 99)}',
                style: AppTextStyles.mono(18,
                    color: color,
                    weight: FontWeight.w800,
                    letterSpacing: -0.5),
              ),
              Text(
                'san',
                style: AppTextStyles.mono(9,
                    color: AppColors.muted,
                    letterSpacing: 0.3,
                    weight: FontWeight.w600),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Müştəri kodu badge — tap-on-copy
// ─────────────────────────────────────────────────────────────────────────────
class _CustomerCodeBadge extends StatelessWidget {
  const _CustomerCodeBadge({required this.code, this.expanded = false});

  final String code;
  final bool expanded;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () {
        Clipboard.setData(ClipboardData(text: code));
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: AppColors.surface2,
            content: Text('Müştəri kodu kopyalandı',
                style: AppTextStyles.body(12, color: AppColors.text)),
            duration: const Duration(seconds: 2),
          ),
        );
      },
      child: Container(
        width: expanded ? double.infinity : null,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
        decoration: BoxDecoration(
          color: AppColors.surface,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(color: AppColors.accent.withValues(alpha: 0.45)),
        ),
        child: Row(
          mainAxisAlignment:
              expanded ? MainAxisAlignment.center : MainAxisAlignment.start,
          mainAxisSize: expanded ? MainAxisSize.max : MainAxisSize.min,
          children: [
            const Icon(Icons.badge_outlined, color: AppColors.accent, size: 14),
            const SizedBox(width: 8),
            Text('ID:',
                style: AppTextStyles.mono(10,
                    color: AppColors.muted,
                    letterSpacing: 0.24,
                    weight: FontWeight.w700)),
            const SizedBox(width: 6),
            Flexible(
              child: Text(code,
                  style: AppTextStyles.mono(12,
                      color: AppColors.accent,
                      letterSpacing: 0.16,
                      weight: FontWeight.w700),
                  overflow: TextOverflow.ellipsis),
            ),
            const SizedBox(width: 8),
            const Icon(Icons.copy_rounded, color: AppColors.muted, size: 12),
          ],
        ),
      ),
    );
  }
}

class _RefreshButton extends StatelessWidget {
  const _RefreshButton({required this.onPressed});

  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return SizedBox(
      width: double.infinity,
      child: OutlinedButton.icon(
        onPressed: onPressed,
        icon: const Icon(Icons.refresh_rounded, size: 16),
        label: Text('Yenilə',
            style: AppTextStyles.body(13,
                color: AppColors.accent, weight: FontWeight.w700)),
        style: OutlinedButton.styleFrom(
          foregroundColor: AppColors.accent,
          side: BorderSide(color: AppColors.accent.withValues(alpha: 0.55)),
          padding: const EdgeInsets.symmetric(vertical: 10),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
        ),
      ),
    );
  }
}

class _ErrorView extends StatelessWidget {
  const _ErrorView({required this.message, required this.onRetry});

  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline_rounded,
                color: AppColors.danger, size: 40),
            const SizedBox(height: 12),
            Text(message,
                style: AppTextStyles.body(14, color: AppColors.text),
                textAlign: TextAlign.center),
            const SizedBox(height: 16),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded, size: 16),
              label: const Text('Yenidən cəhd et'),
            ),
          ],
        ),
      ),
    );
  }
}
