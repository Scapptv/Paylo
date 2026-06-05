import Flutter
import UIKit

/// Sprint 9 M-1 + Audit 2026-06-04 MOB-1: Screenshot / app-switcher qoruması.
///
/// Dart `SecureScreen` widget-i `setSecure(true/false)` method channel-ı çağırır.
/// App inactive olanda (app switcher, Control Center) həssas ekranların üzərinə
/// blur overlay qoyulur ki, OS-un çəkdiyi snapshot-da məlumat görünməsin.
///
/// Blur məntiqi `SecureWindowGuard`-a çıxarılıb (XCTest ilə yoxlanır). Bu sinif
/// yalnız WIRING edir:
///   1. Lifecycle — `UIApplication.willResignActive/didBecomeActive` NOTIFICATION-ları
///      (scene-əsaslı app-də etibarlı; köhnə `applicationWillResignActive` override
///      scene app-də çağırılmırdı — buna görə blur ölü idi).
///   2. Method channel — implicit Flutter engine hazır olanda qurulur (scene app-də
///      `didFinishLaunchingWithOptions` anında window/FVC hələ olmaya bilərdi).
///   3. Blur aktiv window-scene-in key window-una tətbiq olunur.
///
/// ⚠️ Vizual doğrulama: `ios/MOB1_BLUR_VERIFICATION.md` (Xcode/cihaz tələb olunur).
@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {

  private let secureChannel = "az.paylo/secure_window"

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    NotificationCenter.default.addObserver(
      self,
      selector: #selector(handleWillResignActive),
      name: UIApplication.willResignActiveNotification,
      object: nil
    )
    NotificationCenter.default.addObserver(
      self,
      selector: #selector(handleDidBecomeActive),
      name: UIApplication.didBecomeActiveNotification,
      object: nil
    )
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)

    if let registrar = engineBridge.pluginRegistry.registrar(forPlugin: "PayloSecureWindow") {
      let channel = FlutterMethodChannel(name: secureChannel, binaryMessenger: registrar.messenger())
      channel.setMethodCallHandler { call, result in
        if call.method == "setSecure" {
          if let args = call.arguments as? [String: Any], let enabled = args["enabled"] as? Bool {
            SecureWindowGuard.shared.setSecure(enabled)
            result(nil)
          } else {
            result(FlutterError(code: "bad_args", message: "enabled bool tələb olunur", details: nil))
          }
        } else {
          result(FlutterMethodNotImplemented)
        }
      }
    }
  }

  @objc private func handleWillResignActive() {
    SecureWindowGuard.shared.handleWillResignActive(window: activeKeyWindow())
  }

  @objc private func handleDidBecomeActive() {
    SecureWindowGuard.shared.handleDidBecomeActive()
  }

  /// Aktiv window-scene-in key window-u (iOS 13+ scene API).
  private func activeKeyWindow() -> UIWindow? {
    return UIApplication.shared.connectedScenes
      .compactMap { $0 as? UIWindowScene }
      .flatMap { $0.windows }
      .first { $0.isKeyWindow }
  }
}
