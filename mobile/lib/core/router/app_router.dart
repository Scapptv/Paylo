import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/auth/presentation/screens/login_screen.dart';
import 'package:paylo/features/auth/presentation/screens/register_screen.dart';
import 'package:paylo/shared/widgets/main_shell.dart';
import 'package:paylo/shared/widgets/splash_screen.dart';

/// Routing — `go_router` ilə.
///
/// Auth guard:
///   - AuthInitial / AuthLoading → splash
///   - AuthUnauthenticated → /login (kənarsa)
///   - AuthAuthenticated → /wallet (login/register-də qalmırsa)
class AppRouter {
  static GoRouter create(Ref ref) {
    return GoRouter(
      initialLocation: '/splash',
      refreshListenable: _AuthStateListenable(ref),
      // Audit 2026-06-04: gating məntiqi `redirectLogic`-ə çıxarıldı ki, pure
      // integration test edilə bilsin (xüsusən MOB-5: auth flip → login).
      redirect: (context, state) =>
          redirectLogic(ref.read(authControllerProvider), state.matchedLocation),
      routes: [
        GoRoute(path: '/splash',   builder: (_, __) => const SplashScreen()),
        GoRoute(path: '/login',    builder: (_, __) => const LoginScreen()),
        GoRoute(path: '/register', builder: (_, __) => const RegisterScreen()),
        GoRoute(path: '/wallet',   builder: (_, __) => const MainShell(initialIndex: 0)),
        GoRoute(path: '/history',  builder: (_, __) => const MainShell(initialIndex: 1)),
        GoRoute(path: '/qr',       builder: (_, __) => const MainShell(initialIndex: 2)),
        // Audit 2026-06-04 MOB-7: main_shell.dart-da index 3 = Cart, 4 = Profile.
        // Əvvəllər /profile səhvən index 3 (Cart) açırdı (off-by-one).
        GoRoute(path: '/cart',     builder: (_, __) => const MainShell(initialIndex: 3)),
        GoRoute(path: '/profile',  builder: (_, __) => const MainShell(initialIndex: 4)),
      ],
    );
  }

  /// Auth state + cari location-a görə yönləndirmə qərarı (pure, test-edilə bilən).
  /// `null` = yönləndirmə yoxdur, cari location-da qal.
  ///
  ///   - AuthInitial / AuthLoading → splash (bootstrap davam edir)
  ///   - Unauthenticated → /login (qorunan ekrandadırsa)
  ///   - Authenticated → /wallet (splash və ya login/register-dədirsə)
  static String? redirectLogic(AuthState auth, String location) {
    if (auth is AuthInitial || auth is AuthLoading) {
      return location == '/splash' ? null : '/splash';
    }

    final isAuthenticated = auth is AuthAuthenticated;
    final isAuthRoute = location == '/login' || location == '/register';

    if (location == '/splash') {
      return isAuthenticated ? '/wallet' : '/login';
    }
    if (isAuthenticated && isAuthRoute) {
      return '/wallet';
    }
    if (!isAuthenticated && !isAuthRoute) {
      return '/login';
    }
    return null;
  }
}

/// Auth state-i listenable olaraq go_router-ə bind edir.
/// State dəyişdikdə router refresh olur və redirect yenidən icra olunur.
class _AuthStateListenable extends ChangeNotifier {
  _AuthStateListenable(this.ref) {
    ref.listen<AuthState>(authControllerProvider, (_, __) {
      notifyListeners();
    });
  }
  final Ref ref;
}

final appRouterProvider = Provider<GoRouter>((ref) {
  return AppRouter.create(ref);
});
