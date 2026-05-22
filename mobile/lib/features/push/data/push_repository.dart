import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:device_info_plus/device_info_plus.dart';

import 'package:paylo/core/api/api_client.dart';

class PushRepository {
  PushRepository(this._api);
  final ApiClient _api;

  Future<void> register(String token) async {
    final info = await PackageInfo.fromPlatform();
    final device = await _deviceModel();

    await _api.post('/push/register', body: {
      'token': token,
      'platform': defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android',
      'app_version': '${info.version}+${info.buildNumber}',
      'device_model': device,
    },);
  }

  Future<void> unregister(String token) async {
    await _api.delete('/push/register', body: {'token': token});
  }

  Future<String> _deviceModel() async {
    try {
      final info = DeviceInfoPlugin();
      if (defaultTargetPlatform == TargetPlatform.iOS) {
        final ios = await info.iosInfo;
        return ios.utsname.machine;
      }
      if (defaultTargetPlatform == TargetPlatform.android) {
        final and = await info.androidInfo;
        return '${and.brand} ${and.model}';
      }
    } catch (_) {}
    return 'unknown';
  }
}

final pushRepositoryProvider = Provider<PushRepository>((ref) {
  return PushRepository(ref.watch(apiClientProvider));
});
