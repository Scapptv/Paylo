/// Backend API-dan gələn xətaların tipli wrapper-ı.
///
/// Repository qatı Dio xətalarını tutub bu siniflərə çevirir,
/// presentation qatı yalnız bu sinifləri tanıyır.
sealed class ApiException implements Exception {
  const ApiException(this.message);
  final String message;

  @override
  String toString() => 'ApiException: $message';
}

class NetworkException extends ApiException {
  const NetworkException([super.message = 'İnternet bağlantısı yoxdur.']);
}

class TimeoutException extends ApiException {
  const TimeoutException([super.message = 'Server yavaş cavab verir.']);
}

class UnauthorizedException extends ApiException {
  const UnauthorizedException([super.message = 'Sessiyanız bitib.']);
}

class ForbiddenException extends ApiException {
  const ForbiddenException([super.message = 'Bu əməliyyata icazə yoxdur.']);
}

class NotFoundException extends ApiException {
  const NotFoundException([super.message = 'Tapılmadı.']);
}

class ValidationException extends ApiException {
  const ValidationException(super.message, this.errors);
  final Map<String, List<String>> errors;

  /// İlk xəta mesajını qaytar (UI-da göstərmək üçün)
  String? firstError([String? field]) {
    if (field != null) {
      return errors[field]?.first;
    }
    if (errors.isEmpty) return null;
    return errors.values.first.first;
  }
}

class RateLimitException extends ApiException {
  const RateLimitException([
    super.message = 'Çox cəhd etmisiniz. Bir az sonra cəhd edin.',
    this.retryAfterSeconds,
  ]);

  /// `Retry-After` header dəyəri (saniyə). UI countdown göstərmək üçün.
  final int? retryAfterSeconds;
}

/// Sprint 9 M-7: API cavabındakı `X-RateLimit-*` header-lərindən yığılan info.
/// Caller-lər (controller, repository) bu məlumata baxıb proaktiv backoff edə bilər.
class RateLimitInfo {
  const RateLimitInfo({
    required this.limit,
    required this.remaining,
    this.resetInSeconds,
    required this.observedAt,
  });

  final int limit;
  final int remaining;
  final int? resetInSeconds;
  final DateTime observedAt;

  /// `remaining / limit` < 0.1 olduqda app polling intervalını artırmalıdır.
  bool get isLow => limit > 0 && remaining / limit < 0.1;
}

class ServerException extends ApiException {
  const ServerException([super.message = 'Server xətası baş verdi.']);
}

class UnknownException extends ApiException {
  const UnknownException([super.message = 'Naməlum xəta.']);
}
