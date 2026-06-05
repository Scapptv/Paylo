import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
import 'package:paylo/core/errors/api_exception.dart';

class QrPayload {
  const QrPayload({
    required this.qrValue,
    required this.expiresAt,
    required this.ttl,
    required this.staticQr,
  });

  final String qrValue;
  final DateTime expiresAt;
  final int ttl;
  final String? staticQr;

  bool get isExpired => DateTime.now().isAfter(expiresAt);

  int get secondsLeft {
    final s = expiresAt.difference(DateTime.now()).inSeconds;
    return s < 0 ? 0 : s;
  }
}

class QrRepository {
  QrRepository(this._api);
  final ApiClient _api;

  Future<QrPayload> generate() async {
    final res = await _api.get<Map<String, dynamic>>('/qr');
    return parse(res.data);
  }

  /// Audit 2026-06-04 MOB-4: qorunmuş + test-edilə bilən parse. `qr_value` /
  /// `expires_at` əskik və ya səhv tipli olsa TypeError yox, `ServerException`
  /// atır — `QrController` `on ApiException` tutub error state göstərsin
  /// (sonsuz loading-də ilişməsin).
  static QrPayload parse(Map<String, dynamic>? data) {
    final qrValue    = data?['qr_value'];
    final expiresRaw = data?['expires_at'];

    if (qrValue is! String || qrValue.isEmpty || expiresRaw is! String) {
      throw const ServerException('QR cavabı gözlənilməz formatdadır.');
    }

    final expiresAt = DateTime.tryParse(expiresRaw);
    if (expiresAt == null) {
      throw const ServerException('QR cavabı gözlənilməz formatdadır.');
    }

    return QrPayload(
      qrValue:   qrValue,
      expiresAt: expiresAt,
      ttl:       (data?['ttl'] as num?)?.toInt() ?? 30,
      staticQr:  data?['static_qr'] as String?,
    );
  }
}

final qrRepositoryProvider = Provider<QrRepository>((ref) {
  return QrRepository(ref.watch(apiClientProvider));
});
