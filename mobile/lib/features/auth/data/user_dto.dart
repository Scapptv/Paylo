import 'package:paylo/features/auth/domain/user.dart';

/// Data layer DTO — sırf JSON ↔ domain Map conversion.
/// Domain `User` sinifi bu DTO-dan asılı deyil.
class UserDto {
  static User fromJson(Map<String, dynamic> json) => User(
        id:            json['id'] as int,
        name:          json['name'] as String,
        email:         json['email'] as String,
        phone:         json['phone'] as String?,
        role:          json['role'] as String,
        customerQr:    json['customer_qr'] as String?,
        emailVerified: json['email_verified'] as bool? ?? false,
      );
}
