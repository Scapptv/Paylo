import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/storage/secure_token_storage.dart';
import 'package:paylo/features/auth/data/user_dto.dart';
import 'package:paylo/features/auth/domain/auth_session.dart';

/// Backend auth endpoint-ləri ilə işləyən repository.
class AuthRepository {
  AuthRepository(this._api, this._storage);

  final ApiClient _api;
  final SecureTokenStorage _storage;

  Future<AuthSession> login({required String email, required String password}) async {
    final deviceName = await _deviceName();

    final res = await _api.post<Map<String, dynamic>>('/auth/login', body: {
      'email': email,
      'password': password,
      'device_name': deviceName,
    },);

    return _handleAuthResponse(res.data);
  }

  Future<AuthSession> register({
    required String name,
    required String email,
    required String phone,
    required String password,
  }) async {
    final deviceName = await _deviceName();

    final res = await _api.post<Map<String, dynamic>>('/auth/register', body: {
      'name': name,
      'email': email,
      'phone': phone,
      'password': password,
      'password_confirmation': password,
      'device_name': deviceName,
    },);

    return _handleAuthResponse(res.data);
  }

  Future<void> logout() async {
    try {
      await _api.post('/auth/logout');
    } catch (_) {
      // Backend xətası olsa belə, local-da çıxış etməliyik
    } finally {
      await _storage.clear();
    }
  }

  Future<void> logoutAll() async {
    try {
      await _api.post('/auth/logout-all');
    } finally {
      await _storage.clear();
    }
  }

  // --- Helpers ---

  /// Audit 2026-06-04 MOB-3: qorunmuş + test-edilə bilən parse (storage-suz, pure).
  /// Boş və ya səhv formatlı 2xx body (`validateStatus` 500-dən aşağı hər şeyi
  /// qəbul edir) əvvəllər TypeError atırdı — bu `ApiException` deyil, ona görə
  /// controller-in `on ApiException` bloku tutmurdu və login sonsuz `AuthLoading`-də
  /// ilişirdi. İndi tipli `ServerException` atırıq ki, controller onu AuthError-a
  /// çevirsin.
  static AuthSession parseSession(Map<String, dynamic>? data) {
    final token      = data?['token'];
    final expiresRaw = data?['expires_at'];
    final userJson   = data?['user'];

    if (token is! String || token.isEmpty ||
        expiresRaw is! String ||
        userJson is! Map<String, dynamic>) {
      throw const ServerException('Server cavabı gözlənilməz formatdadır.');
    }

    final expiresAt = DateTime.tryParse(expiresRaw);
    if (expiresAt == null) {
      throw const ServerException('Server cavabı gözlənilməz formatdadır.');
    }

    try {
      return AuthSession(token: token, expiresAt: expiresAt, user: UserDto.fromJson(userJson));
    } on ServerException {
      rethrow;
    } catch (_) {
      // UserDto sahələri səhv tipdə olsa (məs. id String) TypeError-i tipli
      // ServerException-a çevir.
      throw const ServerException('Server cavabı gözlənilməz formatdadır.');
    }
  }

  Future<AuthSession> _handleAuthResponse(Map<String, dynamic>? data) async {
    final session = parseSession(data);
    await _storage.writeToken(session.token);
    await _storage.writeExpiresAt(session.expiresAt);

    return session;
  }

  Future<String> _deviceName() async {
    try {
      final info = DeviceInfoPlugin();
      if (defaultTargetPlatform == TargetPlatform.iOS) {
        final ios = await info.iosInfo;
        return '${ios.name} (iOS ${ios.systemVersion})';
      }
      if (defaultTargetPlatform == TargetPlatform.android) {
        final and = await info.androidInfo;
        return '${and.brand} ${and.model} (Android ${and.version.release})';
      }
    } catch (_) {/* fallback */}
    return 'Paylo Mobile';
  }
}

final authRepositoryProvider = Provider<AuthRepository>((ref) {
  return AuthRepository(
    ref.watch(apiClientProvider),
    ref.watch(secureStorageProvider),
  );
});
