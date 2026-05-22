import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pretty_dio_logger/pretty_dio_logger.dart';

import 'package:paylo/core/config/app_config.dart';
import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/core/storage/secure_token_storage.dart';

/// Bütün API çağırışları üçün vahid client.
///
/// Mexanizmlər:
///   - AuthInterceptor: storage-dan token götürüb Bearer header əlavə edir
///   - ErrorInterceptor: DioException → tipli ApiException
///   - 401 dinləyicisi: token-i silir və app-i login ekranına qaytarır
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
      return RateLimitException(message);
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

// --- Riverpod provider ---

final apiClientProvider = Provider<ApiClient>((ref) {
  final storage = ref.watch(secureStorageProvider);
  return ApiClient.create(
    storage: storage,
    onUnauthorized: () {
      // 401 alındıqda token-i sil → auth state yenilənəcək
      storage.clear();
    },
  );
});
