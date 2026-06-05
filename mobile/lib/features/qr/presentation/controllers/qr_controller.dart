import 'dart:async';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/config/app_config.dart';
import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/features/qr/data/qr_repository.dart';

class QrState {
  const QrState({this.payload, this.loading = true, this.error});

  final QrPayload? payload;
  final bool loading;
  final ApiException? error;

  QrState copyWith({QrPayload? payload, bool? loading, ApiException? error, bool clearError = false}) =>
      QrState(
        payload: payload ?? this.payload,
        loading: loading ?? this.loading,
        error: clearError ? null : (error ?? this.error),
      );
}

/// QR ekran açıldıqda timer başlayır → hər N saniyədə yeni token alır.
/// Screen dispose olduqda timer ləğv edilir.
class QrController extends AutoDisposeNotifier<QrState> {
  Timer? _timer;
  late final QrRepository _repo;

  @override
  QrState build() {
    _repo = ref.watch(qrRepositoryProvider);

    ref.onDispose(() {
      _timer?.cancel();
    });

    Future.microtask(() async {
      await _fetch();
      _scheduleNext();
    });

    return const QrState();
  }

  /// Sprint 9 M-6: timer-i payload-un `expiresAt`-ından dinamik hesablayır.
  /// Şəbəkə latensiyası üçün `qrRefreshBuffer` (3s) ayrılır — server-də expire
  /// olmuş token kassirə çatmasın. Payload yoxsa fallback `qrRotationInterval` (30s).
  void _scheduleNext() {
    _timer?.cancel();

    final p = state.payload;
    Duration delay;
    if (p == null) {
      delay = AppConfig.qrRotationInterval;
    } else {
      final remaining = p.expiresAt.difference(DateTime.now()) - AppConfig.qrRefreshBuffer;
      delay = remaining < AppConfig.qrMinRefreshInterval
          ? AppConfig.qrMinRefreshInterval
          : remaining;
    }

    _timer = Timer(delay, () async {
      await _fetch();
      _scheduleNext();
    });
  }

  Future<void> refresh() async {
    await _fetch();
    _scheduleNext();
  }

  Future<void> _fetch() async {
    try {
      final p = await _repo.generate();
      state = state.copyWith(payload: p, loading: false, clearError: true);
    } on ApiException catch (e) {
      state = state.copyWith(error: e, loading: false);
    }
  }
}

final qrControllerProvider =
    AutoDisposeNotifierProvider<QrController, QrState>(QrController.new);
