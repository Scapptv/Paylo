import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/storage/secure_token_storage.dart';
import 'package:paylo/features/auth/data/auth_repository.dart';
import 'package:paylo/features/auth/domain/auth_session.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/features/push/data/push_service.dart';

/// AuthState — app açıldıqda nə görəcəyimizi müəyyən edir.
sealed class AuthState {
  const AuthState();
}

class AuthInitial         extends AuthState { const AuthInitial(); }
class AuthLoading         extends AuthState { const AuthLoading(); }
class AuthUnauthenticated extends AuthState { const AuthUnauthenticated(); }
class AuthAuthenticated   extends AuthState {
  const AuthAuthenticated(this.session);
  final AuthSession session;
}
class AuthError extends AuthState {
  const AuthError(this.exception);
  final ApiException exception;
}

class AuthController extends Notifier<AuthState> {
  late final AuthRepository _repo;
  late final ProfileRepository _profile;
  late final SecureTokenStorage _storage;

  @override
  AuthState build() {
    _repo    = ref.watch(authRepositoryProvider);
    _profile = ref.watch(profileRepositoryProvider);
    _storage = ref.watch(secureStorageProvider);
    _bootstrap();
    return const AuthInitial();
  }

  /// Sprint 9 M-5: app yenidən açıldıqda saxlanılan token-i serverlə validate edir
  /// və fresh user data ilə `AuthSession` bərpa edir.
  ///
  /// Davranış matrisası:
  ///   - Token yox                          → Unauthenticated
  ///   - Token var, expire olub             → storage təmizlə + Unauthenticated
  ///   - Token var, expire olmayıb, /me 200 → Authenticated (fresh user)
  ///   - /me 401 (server tokeni revoke edib, məs başqa cihazda re-login)
  ///                                        → storage təmizlə + Unauthenticated
  ///   - /me network error / 5xx            → Unauthenticated (təkrar login təklif olunur);
  ///                                          token storage-da qalır, lakin app login ekranında
  ///                                          istifadəçi parolu yenidən daxil edir.
  Future<void> _bootstrap() async {
    final hasToken = await _storage.hasToken();
    if (!hasToken) {
      state = const AuthUnauthenticated();
      return;
    }

    final expiresAt = await _storage.readExpiresAt();
    if (expiresAt == null || DateTime.now().isAfter(expiresAt)) {
      await _storage.clear();
      state = const AuthUnauthenticated();
      return;
    }

    final token = await _storage.readToken();
    if (token == null || token.isEmpty) {
      state = const AuthUnauthenticated();
      return;
    }

    state = const AuthLoading();
    try {
      final user = await _profile.me();
      state = AuthAuthenticated(AuthSession(
        token:     token,
        expiresAt: expiresAt,
        user:      user,
      ),);
    } on UnauthorizedException {
      // Server tokeni qəbul etmir (başqa cihazda re-login, və ya admin
      // revoke etdi) → təmizlə və login-ə yönləndir.
      await _storage.clear();
      state = const AuthUnauthenticated();
    } on ApiException {
      // Şəbəkə / 5xx — token saxlanır, lakin user-i fresh ala bilmirik.
      // İstifadəçi login ekranında manual yenidən daxil olur.
      state = const AuthUnauthenticated();
    } catch (_) {
      // Audit 2026-06-04 MOB-3: /me cavabı gözlənilməz formatda olsa cold-start-da
      // ağ ekran yaratmasın — login ekranına düş.
      state = const AuthUnauthenticated();
    }
  }

  Future<void> login({required String email, required String password}) async {
    state = const AuthLoading();
    try {
      final session = await _repo.login(email: email, password: password);
      state = AuthAuthenticated(session);
    } on ApiException catch (e) {
      state = AuthError(e);
    } catch (_) {
      // Audit 2026-06-04 MOB-3: gözlənilməz parse/runtime xətası (məs. server
      // cavabı səhv formatda) login düyməsini sonsuz AuthLoading-də saxlamasın.
      state = const AuthError(ServerException('Gözlənilməz xəta baş verdi.'));
    }
  }

  Future<void> register({
    required String name,
    required String email,
    required String phone,
    required String password,
  }) async {
    state = const AuthLoading();
    try {
      final session = await _repo.register(
        name: name, email: email, phone: phone, password: password,
      );
      state = AuthAuthenticated(session);
    } on ApiException catch (e) {
      state = AuthError(e);
    } catch (_) {
      // Audit 2026-06-04 MOB-3: register cavabı səhv formatda olsa belə spinner
      // ilişməsin — tipli xətaya çevir.
      state = const AuthError(ServerException('Gözlənilməz xəta baş verdi.'));
    }
  }

  /// Sprint 9 M-8: logout-dan əvvəl FCM token-i serverdə deregister et.
  /// Əks halda silinmiş hesaba bağlı cihaz hələ də push qəbul edə bilər.
  /// Şəbəkə xətası logout-u bloklamır — local clear hər halda baş verir.
  Future<void> logout() async {
    try {
      await ref.read(pushServiceProvider).unregisterCurrentToken();
    } catch (_) {
      // best-effort: serverlə əlaqə yoxdursa logout-u dayandırmırıq
    }
    await _repo.logout();
    state = const AuthUnauthenticated();
  }

  /// Audit 2026-06-04 MOB-5: API interceptor sessiya ortasında 401 görəndə
  /// (server token-i revoke edib — başqa cihazda re-login və ya parol dəyişikliyi)
  /// çağırır. Əvvəllər `onUnauthorized` yalnız storage təmizləyirdi, auth state
  /// dəyişmirdi — UI authenticated qalıb hər sorğuda 401 alırdı, yalnız app
  /// restart-da düzəlirdi. İndi state Unauthenticated-ə keçir → router login-ə atır.
  Future<void> handleUnauthorized() async {
    if (state is AuthUnauthenticated) return; // artıq çıxış edilib, təkrar etmə
    await _storage.clear();
    state = const AuthUnauthenticated();
  }

  /// Login ekranında xəta göstərildikdən sonra state-i təmizlə
  void clearError() {
    if (state is AuthError) {
      state = const AuthUnauthenticated();
    }
  }
}

final authControllerProvider = NotifierProvider<AuthController, AuthState>(
  AuthController.new,
);
