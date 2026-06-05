import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/features/auth/data/auth_repository.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/features/push/data/push_service.dart';
import 'package:paylo/core/storage/secure_token_storage.dart';

import '../support/fakes.dart';

/// 2026-06-04 — AuthController lifecycle integration testləri (fake-lərlə, şəbəkəsiz).
/// Audit boşluğu: əvvəllər bütün mobil testlər pure-unit idi; MOB-3 (parse crash)
/// və MOB-5 (401 naviqasiya) kimi lifecycle problemləri yalnız bu səviyyədə tutulur.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late FakeAuthRepository auth;
  late FakeProfileRepository profile;
  late FakeSecureTokenStorage storage;
  late FakePushService push;

  setUp(() {
    auth = FakeAuthRepository();
    profile = FakeProfileRepository();
    storage = FakeSecureTokenStorage();
    push = FakePushService();
  });

  ProviderContainer makeContainer() {
    final c = ProviderContainer(
      overrides: [
        authRepositoryProvider.overrideWithValue(auth),
        profileRepositoryProvider.overrideWithValue(profile),
        secureStorageProvider.overrideWithValue(storage),
        pushServiceProvider.overrideWithValue(push),
      ],
    );
    addTearDown(c.dispose);
    return c;
  }

  group('bootstrap', () {
    test('no token → Unauthenticated', () async {
      final c = makeContainer();
      c.read(authControllerProvider);
      await pumpEventQueue();
      expect(c.read(authControllerProvider), isA<AuthUnauthenticated>());
    });

    test('valid token + /me success → Authenticated (fresh user)', () async {
      storage.seed(token: 'tok', expiresAt: DateTime.now().add(const Duration(days: 1)));
      profile.meResult = buildUser(name: 'Lalə');
      final c = makeContainer();
      c.read(authControllerProvider);
      await pumpEventQueue();

      final state = c.read(authControllerProvider);
      expect(state, isA<AuthAuthenticated>());
      expect((state as AuthAuthenticated).session.user.name, 'Lalə');
    });

    test('token but /me 401 → Unauthenticated + storage cleared', () async {
      storage.seed(token: 'tok', expiresAt: DateTime.now().add(const Duration(days: 1)));
      profile.meError = const UnauthorizedException();
      final c = makeContainer();
      c.read(authControllerProvider);
      await pumpEventQueue();

      expect(c.read(authControllerProvider), isA<AuthUnauthenticated>());
      expect(storage.clearCount, greaterThan(0));
      expect(await storage.readToken(), isNull);
    });

    test('token but /me network error → Unauthenticated (token KEPT)', () async {
      storage.seed(token: 'tok', expiresAt: DateTime.now().add(const Duration(days: 1)));
      profile.meError = const NetworkException();
      final c = makeContainer();
      c.read(authControllerProvider);
      await pumpEventQueue();

      expect(c.read(authControllerProvider), isA<AuthUnauthenticated>());
      expect(await storage.readToken(), 'tok'); // şəbəkə xətasında token saxlanır
    });

    test('expired token → Unauthenticated, /me NOT called', () async {
      storage.seed(token: 'tok', expiresAt: DateTime.now().subtract(const Duration(days: 1)));
      final c = makeContainer();
      c.read(authControllerProvider);
      await pumpEventQueue();

      expect(c.read(authControllerProvider), isA<AuthUnauthenticated>());
      expect(profile.meCallCount, 0);
    });
  });

  group('login / logout / 401 (MOB-3, MOB-5)', () {
    Future<AuthController> settled(ProviderContainer c) async {
      final n = c.read(authControllerProvider.notifier);
      await pumpEventQueue(); // bootstrap (token yox) → Unauthenticated
      return n;
    }

    test('login success → Authenticated', () async {
      final c = makeContainer();
      final n = await settled(c);
      auth.loginResult = buildSession();

      await n.login(email: 'a@b.az', password: 'pw');
      expect(c.read(authControllerProvider), isA<AuthAuthenticated>());
    });

    test('login ApiException → AuthError', () async {
      final c = makeContainer();
      final n = await settled(c);
      auth.loginError = const ValidationException('Yanlış e-poçt və ya şifrə.', {});

      await n.login(email: 'a@b.az', password: 'pw');
      expect(c.read(authControllerProvider), isA<AuthError>());
    });

    test('MOB-3: login unexpected (non-ApiException) error → AuthError, not stuck loading', () async {
      final c = makeContainer();
      final n = await settled(c);
      auth.loginError = Exception('parse crash'); // TypeError tipli gözlənilməz xəta

      await n.login(email: 'a@b.az', password: 'pw');
      // Köhnə kodda yalnız `on ApiException` tutulurdu → AuthLoading-də ilişirdi.
      expect(c.read(authControllerProvider), isA<AuthError>());
    });

    test('MOB-5: handleUnauthorized after login → Unauthenticated + cleared', () async {
      final c = makeContainer();
      final n = await settled(c);
      auth.loginResult = buildSession();
      await n.login(email: 'a@b.az', password: 'pw');
      expect(c.read(authControllerProvider), isA<AuthAuthenticated>());

      await n.handleUnauthorized(); // interceptor sessiya ortasında 401 gördü
      expect(c.read(authControllerProvider), isA<AuthUnauthenticated>());
      expect(storage.clearCount, greaterThan(0));
    });

    test('logout → Unauthenticated + push unregistered + repo.logout', () async {
      final c = makeContainer();
      final n = await settled(c);
      auth.loginResult = buildSession();
      await n.login(email: 'a@b.az', password: 'pw');

      await n.logout();
      expect(c.read(authControllerProvider), isA<AuthUnauthenticated>());
      expect(push.unregisterCount, greaterThan(0));
      expect(auth.logoutCalled, isTrue);
    });
  });
}
