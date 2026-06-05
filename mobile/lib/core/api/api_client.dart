import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pretty_dio_logger/pretty_dio_logger.dart';

import 'package:paylo/core/config/app_config.dart';
import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/storage/secure_token_storage.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';

/// Bütün API çağırışları üçün vahid client.
///
/// Mexanizmlər:
///   - AuthInterceptor: storage-dan token götürüb Bearer header əlavə edir
///   - ErrorInterceptor: DioException → tipli ApiException
///   - 401 dinləyicisi: token-i silir və app-i login ekranına qaytarır
///   - Sprint 9 M-7: RateLimitInterceptor — `X-RateLimit-*` header-lərini state-ə yansıdır
class ApiClient {
  ApiClient({
    required this.dio,
    required this.storage,
    required this.onUnauthorized,
  });

  final Dio dio;
  final SecureTokenStorage storage;
  final void Function() onUnauthorized;

  static ApiClient create({
    required SecureTokenStorage storage,
    required void Function() onUnauthorized,
    void Function(RateLimitInfo info)? onRateLimitUpdate,
  }) {
    final headers = <String, dynamic>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
    };
    if (AppConfig.apiHostHeader.isNotEmpty) {
      headers['Host'] = AppConfig.apiHostHeader;
    }

    final dio = Dio(BaseOptions(
      baseUrl: AppConfig.apiUrl,
      connectTimeout: AppConfig.networkTimeout,
      receiveTimeout: AppConfig.networkTimeout,
      sendTimeout: AppConfig.networkTimeout,
      headers: headers,
      validateStatus: (status) => status != null && status >= 200 && status < 500,
    ),);

    final client = ApiClient(dio: dio, storage: storage, onUnauthorized: onUnauthorized);

    dio.interceptors.add(_AuthInterceptor(storage));
    dio.interceptors.add(_ErrorInterceptor(onUnauthorized: onUnauthorized));
    // Sprint 9 M-7: hər API cavabında `X-RateLimit-*` header-lərini parse et.
    if (onRateLimitUpdate != null) {
      dio.interceptors.add(_RateLimitInterceptor(onUpdate: onRateLimitUpdate));
    }

    if (kDebugMode) {
      dio.interceptors.add(PrettyDioLogger(
        requestHeader: false,
        requestBody: true,
        responseBody: true,
        responseHeader: false,
        compact: true,
      ),);
    }

    return client;
  }

  // Convenience HTTP methods --------------------------

  Future<Response<T>> get<T>(String path, {Map<String, dynamic>? query}) async {
    try {
      return await dio.get<T>(path, queryParameters: query);
    } on DioException catch (e) {
      throw _mapDioError(e);
    }
  }

  Future<Response<T>> post<T>(String path, {Object? body}) async {
    try {
      return await dio.post<T>(path, data: body);
    } on DioException catch (e) {
      throw _mapDioError(e);
    }
  }

  Future<Response<T>> put<T>(String path, {Object? body}) async {
    try {
      return await dio.put<T>(path, data: body);
    } on DioException catch (e) {
      throw _mapDioError(e);
    }
  }

  Future<Response<T>> delete<T>(String path, {Object? body}) async {
    try {
      return await dio.delete<T>(path, data: body);
    } on DioException catch (e) {
      throw _mapDioError(e);
    }
  }
}

class _AuthInterceptor extends Interceptor {
  _AuthInterceptor(this.storage);
  final SecureTokenStorage storage;

  @override
  Future<void> onRequest(RequestOptions options, RequestInterceptorHandler handler) async {
    final token = await storage.readToken();
    if (token != null && token.isNotEmpty) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }
}

class _ErrorInterceptor extends Interceptor {
  _ErrorInterceptor({required this.onUnauthorized});
  final void Function() onUnauthorized;

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    // validateStatus 500-dən aşağı hər kodu OK sayır, ona görə manuel yoxlayırıq
    final status = response.statusCode ?? 0;

    if (status == 401) {
      onUnauthorized();
      handler.reject(DioException(
        requestOptions: response.requestOptions,
        response: response,
        type: DioExceptionType.badResponse,
        error: const UnauthorizedException(),
      ),);
      return;
    }
    if (status >= 400) {
      handler.reject(DioException(
        requestOptions: response.requestOptions,
        response: response,
        type: DioExceptionType.badResponse,
      ),);
      return;
    }

    handler.next(response);
  }
}

/// Sprint 9 M-7: `X-RateLimit-Limit/Remaining/Reset` header-lərini parse edib
/// caller-ə təqdim edir.
class _RateLimitInterceptor extends Interceptor {
  _RateLimitInterceptor({required this.onUpdate});
  final void Function(RateLimitInfo info) onUpdate;

  @override
  void onResponse(Response response, ResponseInterceptorHandler handler) {
    final info = _parse(response);
    if (info != null) onUpdate(info);
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final response = err.response;
    if (response != null) {
      final info = _parse(response);
      if (info != null) onUpdate(info);
    }
    handler.next(err);
  }

  RateLimitInfo? _parse(Response response) {
    final headers = response.headers;
    final limitRaw     = headers.value('x-ratelimit-limit');
    final remainingRaw = headers.value('x-ratelimit-remaining');
    final resetRaw     = headers.value('x-ratelimit-reset');

    if (limitRaw == null || remainingRaw == null) return null;
    final limit     = int.tryParse(limitRaw);
    final remaining = int.tryParse(remainingRaw);
    if (limit == null || remaining == null) return null;

    return RateLimitInfo(
      limit:          limit,
      remaining:      remaining,
      resetInSeconds: resetRaw != null ? int.tryParse(resetRaw) : null,
      observedAt:     DateTime.now(),
    );
  }
}

ApiException _mapDioError(DioException e) {
  final response = e.response;
  final status = response?.statusCode ?? 0;
  final data = response?.data;

  String message = _extractMessage(data) ?? 'Naməlum xəta';

  switch (e.type) {
    case DioExceptionType.connectionTimeout:
    case DioExceptionType.sendTimeout:
    case DioExceptionType.receiveTimeout:
      return const TimeoutException();
    case DioExceptionType.connectionError:
      return const NetworkException();
    case DioExceptionType.cancel:
      return const UnknownException('Sorğu ləğv edildi.');
    case DioExceptionType.badCertificate:
      return const NetworkException('Təhlükəsizlik sertifikatı problemi.');
    case DioExceptionType.unknown:
    case DioExceptionType.badResponse:
      break;
  }

  // Server cavabı var amma uğursuz
  switch (status) {
    case 401:
      return UnauthorizedException(message);
    case 403:
      return ForbiddenException(message);
    case 404:
      return NotFoundException(message);
    case 422:
      final errors = _extractValidationErrors(data);
      return ValidationException(message, errors);
    case 429:
      // Sprint 9 M-7: `Retry-After` header dəyəri exception-a daxil edilir.
      final retryAfter = int.tryParse(response?.headers.value('retry-after') ?? '');
      return RateLimitException(message, retryAfter);
    case >= 500:
      return ServerException(message);
    default:
      return UnknownException(message);
  }
}

String? _extractMessage(dynamic data) {
  if (data is Map<String, dynamic>) {
    final m = data['message'];
    if (m is String && m.isNotEmpty) return m;
  }
  return null;
}

Map<String, List<String>> _extractValidationErrors(dynamic data) {
  if (data is! Map<String, dynamic>) return {};
  final errors = data['errors'];
  if (errors is! Map) return {};

  final result = <String, List<String>>{};
  errors.forEach((key, value) {
    if (value is List) {
      result[key.toString()] = value.map((e) => e.toString()).toList();
    }
  });
  return result;
}

// --- Riverpod provider-lər ---

/// Sprint 9 M-7: ən son `RateLimitInfo`. UI proaktiv backoff göstərə bilər.
final rateLimitInfoProvider = StateProvider<RateLimitInfo?>((_) => null);

final apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.watch(secureStorageProvider);
  return ApiClient.create(
    storage: storage,
    onUnauthorized: () {
      // Audit 2026-06-04 MOB-5: 401-də yalnız storage təmizləmək kifayət deyildi —
      // auth state dəyişmirdi, UI authenticated qalıb hər sorğuda 401 alırdı. İndi
      // AuthController-i xəbərdar edirik: storage təmizlənir VƏ state
      // Unauthenticated-ə keçir → router login ekranına yönləndirir.
      ref.read(authControllerProvider.notifier).handleUnauthorized();
    },
    onRateLimitUpdate: (info) {
      ref.read(rateLimitInfoProvider.notifier).state = info;
    },
  );
});
