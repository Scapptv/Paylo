import 'dart:async';

import 'package:barcode_widget/barcode_widget.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/qr/presentation/controllers/qr_controller.dart';

/// Barkod ekranı — QR əvəzinə Code128 barkod göstərir.
/// Hər 30 saniyədə yeni rotating token alır, müştəri ID-si ilə bağlıdır.
class QrScreen extends ConsumerStatefulWidget {
  const QrScreen({super.key});

  @override
  ConsumerState<QrScreen> createState() => _QrScreenState();
}

class _QrScreenState extends ConsumerState<QrScreen> {
  Timer? _tick;
  int _secondsLeft = 30;

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      final p = ref.read(qrControllerProvider).payload;
      if (p != null && mounted) {
        setState(() => _secondsLeft = p.secondsLeft);
      }
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final state    = ref.watch(qrControllerProvider);
    final authState = ref.watch(authControllerProvider);
    final user     = authState is AuthAuthenticated ? authState.session.user : null;
    final customerId = user?.customerQr ?? (user != null ? 'cust_${user.id}' : '—');

    return Scaffold(
      appBar: AppBar(
        title: const Text('Barkod'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, size: 20),
            onPressed: () => ref.read(qrControllerProvider.notifier).refresh(),
          ),
        ],
      ),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            children: [
              Text('BU BARKODU KASSİRƏ GÖSTƏR',
                  style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
              const SizedBox(height: 6),
              Text('Hər 30 saniyədə yenilənir',
                  style: AppTextStyles.body(13, color: AppColors.text2),),

              const Spacer(),

              _CustomerIdBadge(customerId: customerId),

              const SizedBox(height: 16),

              if (state.payload == null && state.loading)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 60),
                  child: CircularProgressIndicator(color: AppColors.accent),
                )
              else if (state.payload != null) ...[
                _BarcodeCard(
                  data: state.payload!.qrValue,
                  customerId: customerId,
                ),

                const SizedBox(height: 24),

                _CountdownBar(secondsLeft: _secondsLeft, totalSeconds: state.payload!.ttl),

                const SizedBox(height: 12),
                Text(
                  _secondsLeft > 0 ? '$_secondsLeft san sonra yenilənir' : 'yenilənir...',
                  style: AppTextStyles.mono(11, color: AppColors.muted, letterSpacing: 0.16),
                ),
              ],

              const Spacer(),

              if (state.error != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 16),
                  child: Text(state.error!.message, style: AppTextStyles.body(12, color: AppColors.danger)),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _CustomerIdBadge extends StatelessWidget {
  const _CustomerIdBadge({required this.customerId});
  final String customerId;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: () {
        Clipboard.setData(ClipboardData(text: customerId));
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            backgroundColor: AppColors.surface2,
            content: Text('Müştəri ID kopyalandı', style: AppTextStyles.body(12, color: AppColors.text)),
            duration: const Duration(seconds: 2),
          ),
        );
      },
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
        decoration: BoxDecoration(
          color: AppColors.surface,
          border: Border.all(color: AppColors.accent.withValues(alpha: 0.5)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.badge_outlined, color: AppColors.accent, size: 14),
            const SizedBox(width: 8),
            Text('ID: ',
                style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.2, weight: FontWeight.w700),),
            Text(customerId,
                style: AppTextStyles.mono(12, color: AppColors.accent, letterSpacing: 0.16, weight: FontWeight.w700),),
            const SizedBox(width: 8),
            const Icon(Icons.copy, color: AppColors.muted, size: 12),
          ],
        ),
      ),
    );
  }
}

class _BarcodeCard extends StatelessWidget {
  const _BarcodeCard({required this.data, required this.customerId});
  final String data;
  final String customerId;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 24, 20, 16),
      decoration: BoxDecoration(
        color: Colors.white,
        border: Border.all(color: AppColors.accent, width: 2),
        boxShadow: [
          BoxShadow(
            color: AppColors.accent.withValues(alpha: 0.18),
            blurRadius: 28,
            spreadRadius: -4,
            offset: const Offset(0, 8),
          ),
        ],
      ),
      child: Column(
        children: [
          SizedBox(
            width: 280,
            height: 110,
            child: BarcodeWidget(
              barcode: Barcode.code128(escapes: false),
              data: data,
              drawText: false,
              color: Colors.black,
              backgroundColor: Colors.white,
            ),
          ),
          const SizedBox(height: 10),
          Text(
            data,
            style: AppTextStyles.mono(10, color: Colors.black87, letterSpacing: 0.2, weight: FontWeight.w600),
            textAlign: TextAlign.center,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
          const SizedBox(height: 4),
          Text(
            'Müştəri: $customerId',
            style: AppTextStyles.mono(9, color: Colors.black54, letterSpacing: 0.16),
          ),
        ],
      ),
    );
  }
}

class _CountdownBar extends StatelessWidget {
  const _CountdownBar({required this.secondsLeft, required this.totalSeconds});
  final int secondsLeft;
  final int totalSeconds;

  @override
  Widget build(BuildContext context) {
    final progress = totalSeconds == 0 ? 0.0 : (secondsLeft / totalSeconds).clamp(0.0, 1.0);
    final color = secondsLeft < 5 ? AppColors.warning : AppColors.accent;

    return Container(
      width: 280, height: 4,
      color: AppColors.surface2,
      child: Align(
        alignment: Alignment.centerLeft,
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 500),
          width: 280 * progress,
          decoration: BoxDecoration(
            color: color,
            boxShadow: [BoxShadow(color: color.withValues(alpha: 0.6), blurRadius: 6)],
          ),
        ),
      ),
    );
  }
}
