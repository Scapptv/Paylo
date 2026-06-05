import UIKit

/// Audit 2026-06-04 MOB-1: screenshot / app-switcher blur məntiqi — AppDelegate-dən
/// AYRI, beləliklə Flutter engine olmadan XCTest ilə yoxlana bilir.
///
/// `secureMode` flag-ini saxlayır (Dart `SecureScreen` method channel-ı təyin edir)
/// və verilən `UIWindow`-da `UIVisualEffectView` blur overlay-i qurub götürür.
/// AppDelegate yalnız lifecycle (NotificationCenter) və channel-ı bu sinfə bağlayır.
final class SecureWindowGuard {
    /// App-wide singleton (AppDelegate istifadə edir).
    static let shared = SecureWindowGuard()

    /// Test üçün təmiz instance yaratmaq olar.
    init() {}

    private(set) var secureMode: Bool = false
    private(set) var blurOverlay: UIVisualEffectView?

    /// Dart `setSecure(enabled)` çağırışı. Söndürüləndə mövcud overlay dərhal götürülür.
    func setSecure(_ enabled: Bool) {
        secureMode = enabled
        if !enabled {
            removeBlur()
        }
    }

    /// App inactive olur (app switcher / Control Center) — secure rejimdədirsə blur qoy.
    func handleWillResignActive(window: UIWindow?) {
        guard secureMode else { return }
        installBlur(on: window)
    }

    /// App yenidən aktiv olur — blur götür.
    func handleDidBecomeActive() {
        removeBlur()
    }

    func installBlur(on window: UIWindow?) {
        guard blurOverlay == nil, let window = window else { return }
        let blur = UIVisualEffectView(effect: UIBlurEffect(style: .systemMaterialDark))
        blur.frame = window.bounds
        blur.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        window.addSubview(blur)
        blurOverlay = blur
    }

    func removeBlur() {
        blurOverlay?.removeFromSuperview()
        blurOverlay = nil
    }
}
