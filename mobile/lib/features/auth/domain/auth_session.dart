import 'package:paylo/features/auth/domain/user.dart';

class AuthSession {
  const AuthSession({
    required this.token,
    required this.expiresAt,
    required this.user,
  });

  final String token;
  final DateTime expiresAt;
  final User user;

  bool get isExpired => DateTime.now().isAfter(expiresAt);

  /// 24 saatdan az qalıbsa, refresh məsləhət görülür
  bool get needsRefreshSoon =>
      expiresAt.difference(DateTime.now()).inHours < 24;
}
