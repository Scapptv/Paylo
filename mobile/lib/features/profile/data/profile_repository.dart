import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
import 'package:paylo/features/auth/data/user_dto.dart';
import 'package:paylo/features/auth/domain/user.dart';

class ProfileRepository {
  ProfileRepository(this._api);
  final ApiClient _api;

  Future<User> me() async {
    final res = await _api.get<Map<String, dynamic>>('/me');
    return UserDto.fromJson(res.data!['user'] as Map<String, dynamic>);
  }

  Future<User> update({String? name, String? phone, String? locale}) async {
    final res = await _api.put<Map<String, dynamic>>('/me', body: {
      if (name != null) 'name': name,
      if (phone != null) 'phone': phone,
      if (locale != null) 'locale': locale,
    },);
    return UserDto.fromJson(res.data!['user'] as Map<String, dynamic>);
  }

  Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    await _api.put('/me/password', body: {
      'current_password': currentPassword,
      'password': newPassword,
      'password_confirmation': newPassword,
    },);
  }

  Future<void> deleteAccount({required String password}) async {
    await _api.delete('/me', body: {
      'password': password,
      'confirm': true,
    },);
  }
}

final profileRepositoryProvider = Provider<ProfileRepository>((ref) {
  return ProfileRepository(ref.watch(apiClientProvider));
});

final profileProvider = FutureProvider.autoDispose<User>((ref) {
  return ref.watch(profileRepositoryProvider).me();
});
