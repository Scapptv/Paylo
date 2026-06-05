import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/features/auth/data/auth_repository.dart';
import 'package:paylo/features/history/data/history_repository.dart';
import 'package:paylo/features/history/presentation/controllers/history_controller.dart';
import 'package:paylo/features/qr/data/qr_repository.dart';
import 'package:paylo/features/wallet/data/wallet_repository.dart';

/// 2026-06-04 dərin audit — MOB-3 / MOB-4 parse hardening regression testləri.
///
/// Əvvəllər repository-lər `data['x'] as String/int` kimi qorunmasız cast edirdi.
/// Səhv/əskik formatlı 2xx body `TypeError` atırdı — bu `ApiException` deyil, ona
/// görə `on ApiException` handler-ləri tutmurdu (login sonsuz spinner, QR tab hang).
/// İndi parse statik metodları səhv formatı tipli `ServerException`-a çevirir.
void main() {
  group('MOB-3: AuthRepository.parseSession', () {
    test('parses a valid auth body', () {
      final session = AuthRepository.parseSession({
        'token': 'abc123',
        'expires_at': '2030-01-01T00:00:00+04:00',
        'user': {
          'id': 8, 'name': 'Aysel', 'email': 'a@b.az', 'role': 'customer',
          'customer_qr': 'qr_x', 'email_verified': false,
        },
      });

      expect(session.token, 'abc123');
      expect(session.user.id, 8);
      expect(session.user.name, 'Aysel');
    });

    test('throws ServerException (not TypeError) on null/empty body', () {
      expect(() => AuthRepository.parseSession(null), throwsA(isA<ServerException>()));
      expect(() => AuthRepository.parseSession({}), throwsA(isA<ServerException>()));
    });

    test('throws ServerException when token missing', () {
      expect(
        () => AuthRepository.parseSession({
          'expires_at': '2030-01-01T00:00:00Z',
          'user': {'id': 1, 'name': 'A', 'email': 'a@b.c', 'role': 'customer'},
        }),
        throwsA(isA<ServerException>()),
      );
    });

    test('throws ServerException when expires_at is not a date', () {
      expect(
        () => AuthRepository.parseSession({
          'token': 'abc', 'expires_at': 'not-a-date',
          'user': {'id': 1, 'name': 'A', 'email': 'a@b.c', 'role': 'customer'},
        }),
        throwsA(isA<ServerException>()),
      );
    });

    test('throws ServerException when user map is malformed (id missing)', () {
      expect(
        () => AuthRepository.parseSession({
          'token': 'abc', 'expires_at': '2030-01-01T00:00:00Z', 'user': <String, dynamic>{},
        }),
        throwsA(isA<ServerException>()),
      );
    });
  });

  group('MOB-4: WalletRepository.parse', () {
    test('parses a valid wallet body', () {
      final w = WalletRepository.parse({
        'total_balance': 2481, 'total_earned_all_time': 2481,
        'total_redeemed_all_time': 0, 'expiring_soon': 0, 'buckets_count': 1,
        'currency': 'AZN', 'buckets': const [], 'recent_entries': const [],
      });

      expect(w.totalBalance, 2481);
      expect(w.bucketsCount, 1);
      expect(w.currency, 'AZN');
    });

    test('throws ServerException on null body (no misleading 0 AZN)', () {
      expect(() => WalletRepository.parse(null), throwsA(isA<ServerException>()));
    });

    test('throws ServerException when total_balance key absent', () {
      expect(() => WalletRepository.parse({'currency': 'AZN'}), throwsA(isA<ServerException>()));
    });

    test('throws ServerException when buckets is not a list', () {
      expect(
        () => WalletRepository.parse({'total_balance': 5, 'buckets': 'oops'}),
        throwsA(isA<ServerException>()),
      );
    });
  });

  group('MOB-4: QrRepository.parse', () {
    test('parses a valid qr body', () {
      final p = QrRepository.parse({
        'qr_value': 'qr1.qr_x.1778998704.40801dc44cecc2cd',
        'expires_at': '2030-01-01T00:00:00Z',
        'ttl': 30,
      });

      expect(p.qrValue, startsWith('qr1.'));
      expect(p.ttl, 30);
    });

    test('throws ServerException (not TypeError) when qr_value missing', () {
      expect(() => QrRepository.parse({}), throwsA(isA<ServerException>()));
      expect(() => QrRepository.parse(null), throwsA(isA<ServerException>()));
    });

    test('throws ServerException when expires_at missing or invalid', () {
      expect(() => QrRepository.parse({'qr_value': 'qr1.x'}), throwsA(isA<ServerException>()));
      expect(
        () => QrRepository.parse({'qr_value': 'qr1.x', 'expires_at': 'nope'}),
        throwsA(isA<ServerException>()),
      );
    });

    test('defaults ttl to 30 when absent', () {
      final p = QrRepository.parse({
        'qr_value': 'qr1.x', 'expires_at': '2030-01-01T00:00:00Z',
      });
      expect(p.ttl, 30);
    });
  });

  group('MOB-4: HistoryRepository.parse', () {
    test('parses a valid (empty) history page', () {
      final page = HistoryRepository.parse({'data': const [], 'has_more': false});
      expect(page.entries, isEmpty);
      expect(page.hasMore, isFalse);
    });

    test('treats absent data list as empty page (not error)', () {
      final page = HistoryRepository.parse({});
      expect(page.entries, isEmpty);
    });

    test('throws ServerException when data is not a list', () {
      expect(() => HistoryRepository.parse({'data': 'oops'}), throwsA(isA<ServerException>()));
    });
  });

  group('MOB-6: HistoryState.copyWith filter clearing', () {
    test('clearFilter resets filterType to null ("Hamısı" chip)', () {
      const s = HistoryState(filterType: 'earn');
      expect(s.copyWith(clearFilter: true).filterType, isNull);
    });

    test('explicit filterType is applied', () {
      const s = HistoryState(filterType: 'earn');
      expect(s.copyWith(filterType: 'redeem').filterType, 'redeem');
    });

    test('absent filterType is preserved (loadMore keeps active filter)', () {
      const s = HistoryState(filterType: 'earn');
      expect(s.copyWith(loadingMore: true).filterType, 'earn');
    });
  });
}
