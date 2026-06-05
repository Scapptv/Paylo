# MOB-1 — iOS app-switcher blur doğrulaması

**Audit:** 2026-06-04 MOB-1. `AppDelegate.swift` scene-əsaslı lifecycle-a (NotificationCenter
+ `SceneDelegate`) köçürüldü; əvvəlki `applicationWillResignActive` override scene app-də
çağırılmırdı və blur heç vaxt işə düşmürdü.

> **Niyə əl ilə:** bu davranış native + vizualdır (OS-un çəkdiyi background snapshot).
> Windows/Linux host-da iOS build/run mümkün deyil — yoxlama **macOS + Xcode**-da edilməlidir.

## Vacib gözlənti (iOS ≠ Android)
- iOS-da Android `FLAG_SECURE`-nin birbaşa ekvivalenti **yoxdur**. Bu blur yalnız
  **app-switcher / background preview** snapshot-ını qoruyur.
- Əl ilə çəkilən **screenshot** (Side + Volume Up) iOS-da bloklanmır — QR görünəcək.
  Bu **gözləniləndir**. Yoxlama məhz app-switcher snapshot-ı haqqındadır.
- **Fiziki cihaz tövsiyə olunur** — Simulator-un app-switcher-i background snapshot-ı
  həmişə eyni şəkildə render etmir.

## Addımlar (macOS + Xcode)

1. **Compile yoxlaması** (Swift-in qurulduğunu təsdiqlə):
   ```bash
   cd scapp-loyalty/mobile
   flutter pub get
   flutter build ios --debug --no-codesign
   ```
   Uğurlu build = `AppDelegate.swift` + `SceneDelegate.swift` problemsiz kompilyasiya olunur.

2. **Cihazda işə sal:**
   ```bash
   flutter run --dart-define=API_BASE_URL=https://api.paylo.az   # və ya dev URL
   ```
   (Fiziki iOS cihaz qoşulu olsun.)

3. **Login → "Barkod" (QR) tab-ına keç.** QR kodu ekranda göstərilməlidir.

4. **App-i background-a at:** Home jest (yuxarı sürüşdür) və ya App Switcher aç
   (yuxarı sürüşdür + saxla).

5. **App-switcher kartına bax:** QR/balans **BLUR** olmalıdır (tünd `systemMaterialDark`
   overlay) — QR kodu oxunmamalıdır. ✅ = MOB-1 keçdi.

6. **App-ə qayıt:** blur götürülməli, məzmun görünməlidir.

7. **MOB-2 birgə yoxlama:** addım 4–6-nı **Wallet** və **Profil** tab-larında təkrarla
   (balans + Müştəri ID də blur olmalıdır).

## Avtomatlaşdırma (quruldu)

### 1. CI compile-check — `.github/workflows/ios-verify.yml`
macOS runner-da (`macos-14`, Flutter 3.41.9) hər push/PR-da:
`flutter analyze` → `flutter test` (64 Dart test) → **`flutter build ios --no-codesign`**.
Sonuncu addım `AppDelegate.swift` + `SecureWindowGuard.swift`-in kompilyasiyasını
təsdiqləyir — Windows host-da edilə bilməyən yoxlama. (Workflow həm də RunnerTests
target varsa XCTest-i qaçırır.)

### 2. XCTest — `ios/RunnerTests/SecureWindowGuardTests.swift`
Blur məntiqi `SecureWindowGuard` sinfinə çıxarıldığı üçün Flutter engine olmadan
yoxlanır: secure rejimdə `willResignActive` key window-a blur əlavə edir,
`didBecomeActive` götürür, ikiqat install olmur. **5 test.**

#### RunnerTests target-i Xcode-da əlavə et (bir dəfəlik, ~1 dəq)
Flutter iOS layihəsində default test target yoxdur. Mac-da:
1. `mobile/ios/Runner.xcworkspace`-i Xcode-da aç.
2. **File → New → Target → Unit Testing Bundle**; ad: **`RunnerTests`**, *Target to be
   Tested:* **Runner**.
3. Avtomatik yaranan placeholder `RunnerTests.swift`-i sil; mövcud
   `RunnerTests/SecureWindowGuardTests.swift`-i RunnerTests target-inə əlavə et
   (File Inspector → Target Membership → RunnerTests).
4. **Product → Scheme → Edit Scheme → Test** bölməsinə RunnerTests-i əlavə et.
5. **⌘U** ilə qaçır (və ya CI avtomatik tutacaq).

## Geri dön (rollback) lazım olsa
`AppDelegate.swift`-də blur məntiqi `SecureWindowGuard`-vari ayrıdır; problem olsa
`installBlurOverlay`/`removeBlurOverlay` daxilini dəyişmək kifayətdir, lifecycle wiring
(NotificationCenter) toxunulmaz qalır.
