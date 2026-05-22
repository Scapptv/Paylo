/// Mühit konfiqurasiyası.
///
/// flutter build apk --dart-define=API_BASE_URL=https://api.paylo.az
/// flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000 (Android emulator)
/// flutter run --dart-define=API_BASE_URL=http://localhost:8000 (iOS simulator)
class AppConfig {
  const AppConfig._();

  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000',
  );

  /// Optional override for the HTTP `Host` header.
  ///
  /// Useful when the backend is served by a virtual host (e.g. Laravel Herd's
  /// `scapp-loyalty.test`) but the device must connect via an IP such as
  /// `10.0.2.2` (Android emulator) or the LAN address. Pass it via
  /// `--dart-define=API_HOST_HEADER=scapp-loyalty.test`.
  static const String apiHostHeader = String.fromEnvironment('API_HOST_HEADER');

  static const String apiVersion = 'v1';

  /// `${apiBaseUrl}/api/v1`
  static String get apiUrl => '$apiBaseUrl/api/$apiVersion';

  static const Duration networkTimeout = Duration(seconds: 20);

  /// QR rotation interval (backend ilə uyğun olmalıdır)
  static const Duration qrRotationInterval = Duration(seconds: 25);

  /// Secure storage key-ləri
  static const String storageKeyToken = 'auth_token';
  static const String storageKeyExpiresAt = 'auth_expires_at';
  static const String storageKeyUser = 'auth_user';
  static const String storageKeyLocale = 'app_locale';
}
