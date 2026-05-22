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
      _startTimer();
    });

    return const QrState();
  }

  void _startTimer() {
    _timer?.cancel();
    _timer = Timer.periodic(AppConfig.qrRotationInterval, (_) => _fetch());
  }

  Future<void> refresh() async {
    await _fetch();
    _startTimer();
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
