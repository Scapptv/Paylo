// GENERATED FILE — `flutterfire configure` ilə əvəz olunmalıdır.
//
// Bu fayl placeholder-dır. Real layihədə:
//   1. Firebase Console-da iOS + Android app yaradın
//   2. `dart pub global activate flutterfire_cli`
//   3. `flutterfire configure --project=paylo`
//
// Bu komanda `firebase_options.dart` faylını avtomatik generate edir.
// Hələlik push notification olmadan da app işləyəcək — Firebase init xətanı silent tutub
// app davam edir.

import 'package:firebase_core/firebase_core.dart';
import 'package:flutter/foundation.dart' show TargetPlatform, defaultTargetPlatform;

class DefaultFirebaseOptions {
  static FirebaseOptions get currentPlatform {
    return switch (defaultTargetPlatform) {
      TargetPlatform.android => const FirebaseOptions(
          apiKey: 'placeholder',
          appId: 'placeholder',
          messagingSenderId: 'placeholder',
          projectId: 'paylo',
        ),
      TargetPlatform.iOS => const FirebaseOptions(
          apiKey: 'placeholder',
          appId: 'placeholder',
          messagingSenderId: 'placeholder',
          projectId: 'paylo',
          iosBundleId: 'az.paylo.app',
        ),
      _ => throw UnsupportedError('Bu platform dəstəklənmir'),
    };
  }
}
