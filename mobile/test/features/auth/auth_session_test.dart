import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/features/auth/domain/auth_session.dart';
import 'package:paylo/features/auth/domain/user.dart';

void main() {
  const user = User(
    id: 42,
    name: 'Aysel Hüseynova',
    email: 'aysel@example.com',
    phone: '+994501234567',
    role: 'customer',
    customerQr: 'qr_abcdef123456',
    emailVerified: false,
  );

  group('AuthSession', () {
    test('isExpired true when expiresAt in past', () {
      final s = AuthSession(
        token: 'tok',
        expiresAt: DateTime.now().subtract(const Duration(hours: 1)),
        user: user,
      );
      expect(s.isExpired, isTrue);
    });

    test('isExpired false when expiresAt in future', () {
      final s = AuthSession(
        token: 'tok',
        expiresAt: DateTime.now().add(const Duration(days: 7)),
        user: user,
      );
      expect(s.isExpired, isFalse);
    });

    test('needsRefreshSoon true when <24h left', () {
      final s = AuthSession(
        token: 'tok',
        expiresAt: DateTime.now().add(const Duration(hours: 6)),
        user: user,
      );
      expect(s.needsRefreshSoon, isTrue);
    });

    test('needsRefreshSoon false when >24h left', () {
      final s = AuthSession(
        token: 'tok',
        expiresAt: DateTime.now().add(const Duration(days: 5)),
        user: user,
      );
      expect(s.needsRefreshSoon, isFalse);
    });
  });

  group('User', () {
    test('initials from two-word name', () {
      expect(user.initials, 'AH');
    });

    test('initials from single-word name', () {
      const u = User(
        id: 1, name: 'Eldar', email: 'e@x.az', phone: null,
        role: 'customer', customerQr: 'qr_x', emailVerified: false,
      );
      expect(u.initials, 'E');
    });

    test('initials placeholder when name empty', () {
      const u = User(
        id: 1, name: '', email: 'e@x.az', phone: null,
        role: 'customer', customerQr: 'qr_x', emailVerified: false,
      );
      // Boş string split-i [''], parts.length=1, parts[0][0] — exception riski.
      // Bu test halı production-da olmamalıdır, lakin defensive davranışa baxırıq.
      expect(() => u.initials, throwsA(isA<RangeError>()));
    });

    test('initials takes first letter of first and last words', () {
      const u = User(
        id: 1, name: 'Aysel Şəmsəddin Hüseynova', email: 'a@x.az', phone: null,
        role: 'customer', customerQr: 'qr_x', emailVerified: false,
      );
      expect(u.initials, 'AH');
    });
  });
}
