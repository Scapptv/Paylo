import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';

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
    final data = res.data!;

    return QrPayload(
      qrValue:   data['qr_value'] as String,
      expiresAt: DateTime.parse(data['expires_at'] as String),
      ttl:       data['ttl'] as int? ?? 30,
      staticQr:  data['static_qr'] as String?,
    );
  }
}

final qrRepositoryProvider = Provider<QrRepository>((ref) {
  return QrRepository(ref.watch(apiClientProvider));
});
