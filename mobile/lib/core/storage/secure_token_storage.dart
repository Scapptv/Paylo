import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/config/app_config.dart';

/// Encrypted secure storage.
///
/// iOS:     Keychain (kSecAttrAccessibleAfterFirstUnlock)
/// Android: EncryptedSharedPreferences (Keystore-backed)
class SecureTokenStorage {
  SecureTokenStorage(this._storage);

  final FlutterSecureStorage _storage;

  static const _iosOptions = IOSOptions(
    accessibility: KeychainAccessibility.first_unlock,
  );

  static const _androidOptions = AndroidOptions(
    encryptedSharedPreferences: true,
  );

  Future<String?> readToken() => _storage.read(
        key: AppConfig.storageKeyToken,
        iOptions: _iosOptions,
        aOptions: _androidOptions,
      );

  Future<void> writeToken(String token) => _storage.write(
        key: AppConfig.storageKeyToken,
        value: token,
        iOptions: _iosOptions,
        aOptions: _androidOptions,
      );

  Future<DateTime?> readExpiresAt() async {
    final raw = await _storage.read(key: AppConfig.storageKeyExpiresAt, iOptions: _iosOptions, aOptions: _androidOptions);
    return raw == null ? null : DateTime.tryParse(raw);
  }

  Future<void> writeExpiresAt(DateTime expiresAt) => _storage.write(
        key: AppConfig.storageKeyExpiresAt,
        value: expiresAt.toIso8601String(),
        iOptions: _iosOptions,
        aOptions: _androidOptions,
      );

  Future<void> clear() async {
    await _storage.delete(key: AppConfig.storageKeyToken, iOptions: _iosOptions, aOptions: _androidOptions);
    await _storage.delete(key: AppConfig.storageKeyExpiresAt, iOptions: _iosOptions, aOptions: _androidOptions);
    await _storage.delete(key: AppConfig.storageKeyUser, iOptions: _iosOptions, aOptions: _androidOptions);
  }

  Future<bool> hasToken() async {
    final token = await readToken();
    return token != null && token.isNotEmpty;
  }
}

final secureStorageProvider = Provider<SecureTokenStorage>((ref) {
  return SecureTokenStorage(const FlutterSecureStorage());
});
