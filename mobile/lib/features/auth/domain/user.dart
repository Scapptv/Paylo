/// Domain layer — UI və data qatlarının istinad etdiyi pure model.
///
/// JSON-dan asılı deyil. Data layer-in `UserDto` faktoru bunu yaradır.
class User {
  const User({
    required this.id,
    required this.name,
    required this.email,
    required this.phone,
    required this.role,
    required this.customerQr,
    required this.emailVerified,
  });

  final int id;
  final String name;
  final String email;
  final String? phone;
  final String role;
  final String? customerQr;
  final bool emailVerified;

  String get initials {
    final parts = name.trim().split(RegExp(r'\s+'));
    if (parts.isEmpty) return '?';
    if (parts.length == 1) return parts[0][0].toUpperCase();
    return (parts[0][0] + parts.last[0]).toUpperCase();
  }
}
