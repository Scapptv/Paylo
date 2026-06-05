import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/core/errors/api_exception.dart';

void main() {
  group('ValidationException', () {
    test('firstError returns first message of first field', () {
      const e = ValidationException('validation', {
        'email': ['Email yoxdur', 'format yanlış'],
        'phone': ['regex uyğun deyil'],
      });
      expect(e.firstError(), 'Email yoxdur');
    });

    test('firstError(field) returns that field first message', () {
      const e = ValidationException('validation', {
        'email': ['Email yoxdur'],
        'phone': ['regex uyğun deyil'],
      });
      expect(e.firstError('phone'), 'regex uyğun deyil');
    });

    test('firstError returns null when errors empty', () {
      const e = ValidationException('validation', {});
      expect(e.firstError(), isNull);
    });
  });

  group('RateLimitException', () {
    test('default retryAfter is null', () {
      const e = RateLimitException();
      expect(e.retryAfterSeconds, isNull);
    });

    test('explicit retryAfter is preserved', () {
      const e = RateLimitException('throttled', 42);
      expect(e.retryAfterSeconds, 42);
    });
  });

  group('RateLimitInfo', () {
    test('isLow true when remaining/limit < 10%', () {
      final info = RateLimitInfo(
        limit: 60,
        remaining: 5, // 8.3%
        observedAt: DateTime.now(),
      );
      expect(info.isLow, isTrue);
    });

    test('isLow false at exactly 10%', () {
      final info = RateLimitInfo(
        limit: 60,
        remaining: 6, // 10%
        observedAt: DateTime.now(),
      );
      expect(info.isLow, isFalse);
    });

    test('isLow false when remaining high', () {
      final info = RateLimitInfo(
        limit: 60,
        remaining: 50,
        observedAt: DateTime.now(),
      );
      expect(info.isLow, isFalse);
    });

    test('isLow false when limit is zero (defensive)', () {
      final info = RateLimitInfo(
        limit: 0,
        remaining: 0,
        observedAt: DateTime.now(),
      );
      expect(info.isLow, isFalse);
    });
  });
}
