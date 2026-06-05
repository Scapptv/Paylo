# Paylo Mobile — Firebase Setup

Sprint 9 M-2: `lib/firebase_options.dart` hazırda **placeholder dəyərlərlə** doludur. Production-da push notification işləməsi üçün real Firebase project DSN-ləri lazımdır. Bu sənəd addım-addım quraşdırmanı izah edir.

---

## 1. Firebase project yarat

1. https://console.firebase.google.com/ → "Add project"
2. Project adı: **Paylo** (və ya `paylo-production` / `paylo-staging` ayrı project-lər)
3. Google Analytics: opsional — analytics tələb olunmursa bağla
4. Project yaradıldıqdan sonra **Project Settings → General**-də Project ID-ni qeyd et

---

## 2. iOS app əlavə et

1. Project Settings → **Add app → iOS**
2. Bundle ID: `az.paylo.app` (mövcud `Info.plist`-də qeyd olunub)
3. App nickname: `Paylo iOS`
4. **GoogleService-Info.plist** faylını yüklə
5. Faylı **`mobile/ios/Runner/`** qovluğuna kopyala
6. Xcode-da `Runner` target-ə əlavə et (Add Files to Runner → drag-drop)
7. APNs sertifikatları:
   - Apple Developer Portal-da APNs Auth Key yarat (.p8 faylı)
   - Firebase Console → Cloud Messaging → iOS app → APNs Auth Key yüklə
   - Team ID və Key ID daxil et

---

## 3. Android app əlavə et

1. Project Settings → **Add app → Android**
2. Package name: `az.paylo.app` (mövcud `build.gradle.kts`-də qeyd olunub)
3. App nickname: `Paylo Android`
4. SHA-1 cert fingerprint (release):
   ```bash
   keytool -list -v -keystore android/app/upload-keystore.jks -alias upload
   ```
   SHA1 dəyərini Firebase-ə əlavə et (Phone Auth / Dynamic Links üçün məcburidir; FCM üçün opsional).
5. **google-services.json** faylını yüklə
6. Faylı **`mobile/android/app/`** qovluğuna kopyala (`.gitignore`-da artıq mövcuddur — commit OLMUR)

### Android build.gradle-də Google Services plugin

`mobile/android/build.gradle.kts` (root):
```kotlin
plugins {
    // ...
    id("com.google.gms.google-services") version "4.4.2" apply false
}
```

`mobile/android/app/build.gradle.kts` (app):
```kotlin
plugins {
    // ... mövcudlar
    id("com.google.gms.google-services")
}
```

---

## 4. firebase_options.dart-ı generate et

`flutterfire` CLI Firebase project-dən platform üçün düzgün konfiqurasiya yaradır:

```bash
# CLI quraşdır (bir dəfə)
dart pub global activate flutterfire_cli

# Project root-da icra et
cd mobile
flutterfire configure --project=paylo-production
```

Bu komand:
- `lib/firebase_options.dart` faylını real DSN-lərlə üzərinə yazır
- iOS/Android app-ləri Firebase project-də avtomatik konfiqurasiya edir
- `ios/Runner/GoogleService-Info.plist` və `android/app/google-services.json` faylları yerləşdirilibsə avtomatik istifadə edir

**Mövcud placeholder fayl səhv yazılacaq — bu normaldır.** Real konfiq DSN-lər indi içində olur.

---

## 5. Yoxlama

```bash
flutter build apk --debug
# və ya
flutter run -d <device_id>
```

Console-da görmək lazım olan:
```
[FCM] token: dGhpc2lzYXRva2Vu...
```

Push test:
```bash
# Backend artisan endpoint-i ilə test push göndər (gələcəkdə implement)
php artisan paylo:push-test --user=42 --message="Test bonus qazandın"
```

Və ya Firebase Console → Cloud Messaging → New Notification → Token target.

---

## 6. CI / Multi-environment

Production və staging üçün ayrı Firebase project-lər istifadə etmək tövsiyə olunur:

```bash
# Production build
flutterfire configure --project=paylo-production --out=lib/firebase_options_prod.dart
flutter build appbundle --release --dart-define=FLAVOR=production

# Staging build
flutterfire configure --project=paylo-staging --out=lib/firebase_options_staging.dart
flutter build apk --release --dart-define=FLAVOR=staging
```

Sonra `main.dart`-da:
```dart
const flavor = String.fromEnvironment('FLAVOR', defaultValue: 'production');
await Firebase.initializeApp(
  options: flavor == 'staging' ? StagingFirebaseOptions.currentPlatform : ProdFirebaseOptions.currentPlatform,
);
```

---

## 7. Təhlükəsizlik qeydləri

- **`google-services.json` və `GoogleService-Info.plist` `.gitignore`-da olmalıdır.** Bunlar API key ehtiva edir, lakin Firebase təhlükəsizliyi App Check + Security Rules ilə təmin olunur, key özü sirli deyil.
- **APNs Auth Key (.p8)** real sirli — yalnız Firebase Console-da saxlanmalı, repository-yə qoyma.
- **FCM Server Key** backend-də yalnız bir yerdə saxlanmalı (env var). Server-side push request-ləri üçün lazımdır.
- **App Check** (Firebase): production-da FCM API-yə yalnız real cihazlardan çağırış edilməsi üçün konfiqurə et.

---

## 8. Troubleshooting

**iOS-da push gəlmir:**
- APNs Auth Key Firebase Console-a yüklənib?
- `ios/Runner/Info.plist`-də `UIBackgroundModes` daxilində `remote-notification` var?
- App Settings → Notifications icazəsi verilib?

**Android-da push gəlmir:**
- `google-services.json` `app/` qovluğundadır?
- `google-services` Gradle plugin aktivdir?
- Cihazda Google Play Services mövcuddur?

**Debug log:**
```dart
debugPrint(await FirebaseMessaging.instance.getToken());
```
Bu sətr token qaytarmırsa, init prosesi xəta verir — `Firebase.initializeApp` call stack-i yoxla.
