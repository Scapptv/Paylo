import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/storage/secure_token_storage.dart';
import 'package:paylo/features/auth/data/auth_repository.dart';
import 'package:paylo/features/auth/domain/auth_session.dart';

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
  late final SecureTokenStorage _storage;

  @override
  AuthState build() {
    _repo    = ref.watch(authRepositoryProvider);
    _storage = ref.watch(secureStorageProvider);
    _bootstrap();
    return const AuthInitial();
  }

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

    // Token var və expire olmayıb. /me çağırıb user-i çək.
    // Hələlik minimal — full bootstrap üçün /me-dan user-i təzələmək lazımdır.
    // Bu MVP üçün state-i Authenticated-ə qoyub user-i null saxlamaq əvəzinə
    // login ekranına qaytaraq ki, fresh data alaq. Sadə və təhlükəsizdir.
    // (Production-da: ProfileRepository.me() çağır, AuthSession bərpa et.)
    state = const AuthUnauthenticated();
  }

  Future<void> login({required String email, required String password}) async {
    state = const AuthLoading();
    try {
      final session = await _repo.login(email: email, password: password);
      state = AuthAuthenticated(session);
    } on ApiException catch (e) {
      state = AuthError(e);
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
    }
  }

  Future<void> logout() async {
    await _repo.logout();
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
