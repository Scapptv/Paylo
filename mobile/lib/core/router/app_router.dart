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
      redirect: (context, state) {
        final auth = ref.read(authControllerProvider);
        final location = state.matchedLocation;

        // Bootstrap zamanı splash-da qal
        if (auth is AuthInitial || auth is AuthLoading) {
          return location == '/splash' ? null : '/splash';
        }

        final isAuthenticated = auth is AuthAuthenticated;
        final isAuthRoute = location == '/login' || location == '/register';

        // Splash-dan çıxış: rola görə yönləndir
        if (location == '/splash') {
          return isAuthenticated ? '/wallet' : '/login';
        }

        // Authenticated user auth ekranındadırsa, wallet-ə qaytar
        if (isAuthenticated && isAuthRoute) {
          return '/wallet';
        }

        // Unauthenticated user qorunan ekrana girirsə, login-ə qaytar
        if (!isAuthenticated && !isAuthRoute) {
          return '/login';
        }

        return null;
      },
      routes: [
        GoRoute(path: '/splash',   builder: (_, __) => const SplashScreen()),
        GoRoute(path: '/login',    builder: (_, __) => const LoginScreen()),
        GoRoute(path: '/register', builder: (_, __) => const RegisterScreen()),
        GoRoute(path: '/wallet',   builder: (_, __) => const MainShell(initialIndex: 0)),
        GoRoute(path: '/history',  builder: (_, __) => const MainShell(initialIndex: 1)),
        GoRoute(path: '/qr',       builder: (_, __) => const MainShell(initialIndex: 2)),
        GoRoute(path: '/profile',  builder: (_, __) => const MainShell(initialIndex: 3)),
      ],
    );
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
