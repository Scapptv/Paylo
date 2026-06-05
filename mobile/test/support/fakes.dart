import 'package:paylo/core/storage/secure_token_storage.dart';
import 'package:paylo/features/auth/data/auth_repository.dart';
import 'package:paylo/features/auth/domain/auth_session.dart';
import 'package:paylo/features/auth/domain/user.dart';
import 'package:paylo/features/profile/data/profile_repository.dart';
import 'package:paylo/features/push/data/push_service.dart';

/// 2026-06-04 widget/integration test suite üçün fake-lər (şəbəkə/native-siz).

User buildUser({int id = 8, String name = 'Aysel Hüseynova', String email = 'aysel@gmail.com'}) =>
    User(
      id: id,
      name: name,
      email: email,
      phone: '+994501234567',
      role: 'customer',
      customerQr: 'qr_test',
      emailVerified: false,
    );

AuthSession buildSession({User? user, DateTime? expiresAt}) => AuthSession(
      token: 'tok_test',
      expiresAt: expiresAt ?? DateTime.now().add(const Duration(days: 30)),
      user: user ?? buildUser(),
    );

/// In-memory secure storage — Keychain/Keystore əvəzinə.
class FakeSecureTokenStorage implements SecureTokenStorage {
  String? _token;
  DateTime? _expiresAt;
  int clearCount = 0;

  void seed({String? token, DateTime? expiresAt}) {
    _token = token;
    _expiresAt = expiresAt;
  }

  @override
  Future<String?> readToken() async => _token;

  @override
  Future<void> writeToken(String token) async => _token = token;

  @override
  Future<DateTime?> readExpiresAt() async => _expiresAt;

  @override
  Future<void> writeExpiresAt(DateTime expiresAt) async => _expiresAt = expiresAt;

  @override
  Future<void> clear() async {
    _token = null;
    _expiresAt = null;
    clearCount++;
  }

  @override
  Future<bool> hasToken() async => _token != null && _token!.isNotEmpty;
}

class FakeAuthRepository implements AuthRepository {
  AuthSession? loginResult;
  Object? loginError;
  bool logoutCalled = false;
  bool logoutAllCalled = false;

  @override
  Future<AuthSession> login({required String email, required String password}) async {
    if (loginError != null) throw loginError!;
    return loginResult!;
  }

  @override
  Future<AuthSession> register({
    required String name,
    required String email,
    required String phone,
    required String password,
  }) async {
    if (loginError != null) throw loginError!;
    return loginResult!;
  }

  @override
  Future<void> logout() async => logoutCalled = true;

  @override
  Future<void> logoutAll() async => logoutAllCalled = true;
}

class FakeProfileRepository implements ProfileRepository {
  User? meResult;
  Object? meError;
  int meCallCount = 0;

  @override
  Future<User> me() async {
    meCallCount++;
    if (meError != null) throw meError!;
    return meResult ?? buildUser();
  }

  @override
  Future<User> update({String? name, String? phone, String? locale}) async => meResult ?? buildUser();

  @override
  Future<void> changePassword({required String currentPassword, required String newPassword}) async {}

  @override
  Future<void> deleteAccount({required String password}) async {}
}

class FakePushService implements PushService {
  int initCount = 0;
  int unregisterCount = 0;

  @override
  Future<void> init() async => initCount++;

  @override
  Future<void> unregisterCurrentToken() async => unregisterCount++;
}
