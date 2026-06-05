import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/features/qr/data/qr_repository.dart';

void main() {
  group('QrPayload', () {
    test('isExpired true when expiresAt in past', () {
      final p = QrPayload(
        qrValue: 'qr1.cust.123.hmac',
        expiresAt: DateTime.now().subtract(const Duration(seconds: 1)),
        ttl: 30,
        staticQr: null,
      );
      expect(p.isExpired, isTrue);
    });

    test('isExpired false when expiresAt in future', () {
      final p = QrPayload(
        qrValue: 'qr1.cust.123.hmac',
        expiresAt: DateTime.now().add(const Duration(seconds: 25)),
        ttl: 30,
        staticQr: null,
      );
      expect(p.isExpired, isFalse);
    });

    test('secondsLeft is non-negative even when expired', () {
      final p = QrPayload(
        qrValue: 'qr1.cust.123.hmac',
        expiresAt: DateTime.now().subtract(const Duration(seconds: 10)),
        ttl: 30,
        staticQr: null,
      );
      expect(p.secondsLeft, 0);
    });

    test('secondsLeft approximates remaining', () {
      final p = QrPayload(
        qrValue: 'qr1.cust.123.hmac',
        expiresAt: DateTime.now().add(const Duration(seconds: 20)),
        ttl: 30,
        staticQr: null,
      );
      expect(p.secondsLeft, inInclusiveRange(19, 20));
    });
  });
}
