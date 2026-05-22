import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/features/push/data/push_repository.dart';

/// Push notification lifecycle manager.
///
/// Workflow:
///   1. App start → init() → permission request → token al → backend-ə register
///   2. Foreground message → local notification göstər
///   3. Background message → OS özü göstərir
///   4. Notification tap → URL handler (deep link)
///   5. Logout → unregister
@pragma('vm:entry-point')
Future<void> firebaseBackgroundHandler(RemoteMessage message) async {
  // Top-level function olmalıdır (Flutter requirement).
  // Background-da minimal iş — backend zaten data göndərmişdir.
  if (kDebugMode) {
    debugPrint('[FCM Background] ${message.messageId}');
  }
}

class PushService {
  PushService(this._repo);
  final PushRepository _repo;

  final _localNotifications = FlutterLocalNotificationsPlugin();
  final _messaging = FirebaseMessaging.instance;
  String? _currentToken;

  Future<void> init() async {
    // 1. Permission
    final settings = await _messaging.requestPermission(alert: true, badge: true, sound: true);
    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      return;
    }

    // 2. Local notifications (foreground üçün)
    await _localNotifications.initialize(
      const InitializationSettings(
        android: AndroidInitializationSettings('@mipmap/ic_launcher'),
        iOS: DarwinInitializationSettings(),
      ),
    );

    if (Platform.isAndroid) {
      await _localNotifications
          .resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(const AndroidNotificationChannel(
        'paylo_default',
        'Paylo Bildirişlər',
        description: 'Bonus qazanma, xərcləmə və hesab bildirişləri',
        importance: Importance.high,
      ),);
    }

    // 3. FCM token al və backend-ə göndər
    final token = await _messaging.getToken();
    if (token != null) {
      _currentToken = token;
      try {
        await _repo.register(token);
      } catch (e) {
        if (kDebugMode) debugPrint('[Push] register failed: $e');
      }
    }

    // 4. Token refresh dinləyicisi
    _messaging.onTokenRefresh.listen((newToken) async {
      _currentToken = newToken;
      try {
        await _repo.register(newToken);
      } catch (_) {}
    });

    // 5. Foreground messages → local notification
    FirebaseMessaging.onMessage.listen(_onForegroundMessage);

    // 6. Notification tap (app background-dan açılır)
    FirebaseMessaging.onMessageOpenedApp.listen(_onMessageTapped);
  }

  Future<void> _onForegroundMessage(RemoteMessage message) async {
    final notification = message.notification;
    if (notification == null) return;

    await _localNotifications.show(
      notification.hashCode,
      notification.title,
      notification.body,
      const NotificationDetails(
        android: AndroidNotificationDetails(
          'paylo_default',
          'Paylo Bildirişlər',
          importance: Importance.high,
          priority: Priority.high,
        ),
        iOS: DarwinNotificationDetails(presentAlert: true, presentBadge: true, presentSound: true),
      ),
    );
  }

  void _onMessageTapped(RemoteMessage message) {
    // Deep link / navigation
    // Real impl-də router.go(path) çağırılır.
    if (kDebugMode) debugPrint('[Push] tapped: ${message.data}');
  }

  Future<void> unregisterCurrentToken() async {
    if (_currentToken != null) {
      try {
        await _repo.unregister(_currentToken!);
      } catch (_) {}
    }
  }
}

final pushServiceProvider = Provider<PushService>((ref) {
  return PushService(ref.watch(pushRepositoryProvider));
});
