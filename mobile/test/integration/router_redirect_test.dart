import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/core/router/app_router.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';

import '../support/fakes.dart';

/// 2026-06-04 — router gating integration testləri.
/// `AppRouter.redirectLogic` pure funksiyaya çıxarıldı (MOB-7 refactoru ilə yanaşı)
/// ki, auth flip → login yönləndirməsi (MOB-5) testlə qorunsun.
void main() {
  final authed = AuthAuthenticated(buildSession());
  const unauth = AuthUnauthenticated();
  const initial = AuthInitial();
  const loading = AuthLoading();

  group('bootstrap mərhələsi', () {
    test('AuthInitial qorunan ekranda → /splash', () {
      expect(AppRouter.redirectLogic(initial, '/wallet'), '/splash');
    });

    test('AuthInitial /splash-də → null (qal)', () {
      expect(AppRouter.redirectLogic(initial, '/splash'), isNull);
    });

    test('AuthLoading /login-də → /splash', () {
      expect(AppRouter.redirectLogic(loading, '/login'), '/splash');
    });
  });

  group('splash-dan çıxış', () {
    test('Unauthenticated /splash → /login', () {
      expect(AppRouter.redirectLogic(unauth, '/splash'), '/login');
    });

    test('Authenticated /splash → /wallet', () {
      expect(AppRouter.redirectLogic(authed, '/splash'), '/wallet');
    });
  });

  group('gating', () {
    test('Authenticated /login-də → /wallet (geri at)', () {
      expect(AppRouter.redirectLogic(authed, '/login'), '/wallet');
    });

    test('Authenticated /wallet → null (icazə)', () {
      expect(AppRouter.redirectLogic(authed, '/wallet'), isNull);
    });

    test('Unauthenticated /login → null (icazə)', () {
      expect(AppRouter.redirectLogic(unauth, '/login'), isNull);
    });

    test('Unauthenticated qorunan /wallet → /login', () {
      expect(AppRouter.redirectLogic(unauth, '/wallet'), '/login');
    });

    test('MOB-5: sessiya ortası 401 flip — Unauthenticated /profile → /login', () {
      // AuthController.handleUnauthorized() state-i Unauthenticated edir →
      // refreshListenable router-i yeniləyir → bu qayda login-ə atır.
      expect(AppRouter.redirectLogic(unauth, '/profile'), '/login');
    });
  });
}
