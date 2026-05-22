# Paylo — Mobile App

Flutter app — iOS + Android — for customers (loyalty wallet).

## Stack

| | |
|---|---|
| Framework | Flutter 3.19+ |
| Language | Dart 3.3+ |
| State management | Riverpod 2 |
| Networking | Dio + interceptors |
| Routing | go_router |
| Storage | flutter_secure_storage (Keychain/Keystore) |
| Push | firebase_messaging + flutter_local_notifications |
| QR | qr_flutter |
| Architecture | Feature-based clean architecture (data / domain / presentation) |

## Struktur

```
mobile/lib/
├── app.dart                  ← MaterialApp.router + auth listener
├── main.dart                 ← entry point
├── firebase_options.dart     ← placeholder (flutterfire configure ilə əvəz)
│
├── core/                     ← infrastructure
│   ├── api/api_client.dart   ← Dio + interceptors + ApiException mapping
│   ├── config/app_config.dart
│   ├── errors/api_exception.dart  ← tipli sealed class
│   ├── router/app_router.dart     ← go_router + auth guard
│   ├── storage/secure_token_storage.dart
│   ├── theme/app_theme.dart       ← dark palette, fonts
│   └── utils/formatters.dart      ← AZN, tarix
│
├── features/
│   ├── auth/                 ← login, register
│   │   ├── data/
│   │   ├── domain/
│   │   └── presentation/{controllers,screens,widgets}
│   ├── wallet/               ← balance + buckets
│   ├── history/              ← cursor pagination
│   ├── qr/                   ← rotating token
│   ├── profile/              ← edit, change password, delete
│   └── push/                 ← FCM token register
│
└── shared/widgets/           ← AppButton, BrandMark, MainShell, SplashScreen, OfflineBanner
```

## Quraşdırma

### 1. Flutter SDK
```bash
flutter --version    # >=3.19
flutter doctor
```

### 2. Asılılıqlar
```bash
cd mobile
flutter pub get
```

### 3. Native qovluqları yarat
İlk dəfə clone edildikdə `android/` və `ios/` qovluqlarını Flutter generate edir:
```bash
flutter create . --project-name paylo --org az.paylo
```

### 4. Firebase config (push notification üçün)
```bash
dart pub global activate flutterfire_cli
flutterfire configure --project=paylo
```
Bu komanda `firebase_options.dart`-ı avtomatik dolduracaq.

### 5. Backend URL

Default Android emulator üçün `10.0.2.2:8000`, iOS üçün `localhost:8000`. Override:

```bash
flutter run --dart-define=API_BASE_URL=http://192.168.1.10:8000
```

Production:
```bash
flutter build apk --release --dart-define=API_BASE_URL=https://api.paylo.az
flutter build ipa --release --dart-define=API_BASE_URL=https://api.paylo.az
```

## İşə salmaq

```bash
# Android emulator
flutter emulators --launch <id>
flutter run

# iOS simulator
open -a Simulator
flutter run

# Real device (USB)
flutter devices
flutter run -d <device_id>
```

## Build

```bash
# Android APK
flutter build apk --release

# Android App Bundle (Play Store üçün)
flutter build appbundle --release

# iOS (yalnız macOS-da)
flutter build ipa --release
```

## Test istifadəçilər

Backend seeder (`UserSeeder`) bu hesabları yaradır:
- `aysel@gmail.com` / `password` — customer
- Mobil app yalnız `role=customer` user-ləri qəbul edir

## Auth flow

```
1. App açılır
   ↓
2. SplashScreen
   ↓
3. AuthController.bootstrap() — secure storage-dan token oxu
   ↓
4. Token yoxdur və ya expired → /login
   Token var və valid → /wallet
   ↓
5. /login → POST /api/v1/auth/login
   Response → token + user
   secure storage-a yaz
   ↓
6. /wallet (MainShell — bottom nav: Wallet / History / QR / Profile)
   ↓
7. Logout → token unregister + DELETE /api/v1/auth/logout + storage.clear()
   ↓
   /login-ə qayıt
```

## Rotating QR

```
QR ekranı açılır → QrController.build() → /api/v1/qr çağırılır
   ↓
QR kodu göstərilir (30 san countdown)
   ↓
25 saniyədən sonra Timer.periodic yenidən /api/v1/qr çağırır
   ↓
Yeni token gəlir, QR yenilənir
   ↓
Ekran bağlandıqda Timer ləğv olunur
```

POS terminal scan etdikdə token-i backend-ə verir (verify endpoint). Token 30 san sonra etibarsız olur — screenshot ilə oğurlama riski yoxdur.

## Push notifications

1. `main.dart` → Firebase.initializeApp()
2. Login uğurlu olduqda → `PushService.init()` → permission + FCM token
3. Token backend-ə göndərilir → `POST /api/v1/push/register`
4. Backend istifadəçiyə push göndərmək üçün bu token-i istifadə edir
5. Logout → `POST /api/v1/push/register` (DELETE)

## Production checklist

- [ ] `flutterfire configure` icra edildi
- [ ] `--dart-define=API_BASE_URL=https://...` keçildi
- [ ] iOS bundle id və signing certificate qurulub
- [ ] Android keystore yaradılıb (`android/key.properties`)
- [ ] Firebase Console-da APNs sertifikatı yüklənib (iOS push üçün)
- [ ] App Store / Google Play meta data hazırdır
- [ ] Crashlytics integration (gələcəkdə)
- [ ] Sentry integration (gələcəkdə)
