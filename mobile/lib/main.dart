import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'package:paylo/app.dart';
import 'package:paylo/features/push/data/push_service.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Date formatters üçün locale data
  await initializeDateFormatting('az_AZ', null);

  // Firebase (push notifications) — opsional
  try {
    await Firebase.initializeApp();
    FirebaseMessaging.onBackgroundMessage(firebaseBackgroundHandler);
  } catch (e) {
    if (kDebugMode) {
      debugPrint('[main] Firebase init skipped: $e');
    }
    // Firebase yoxdursa, app yenə də işləyir — yalnız push olmaz.
  }

  runApp(
    const ProviderScope(child: PayloApp()),
  );
}
