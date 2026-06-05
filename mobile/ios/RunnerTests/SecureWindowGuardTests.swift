import XCTest
@testable import Runner

/// Audit 2026-06-04 MOB-1 — blur məntiqinin XCTest doğrulaması (cihazsız).
///
/// Vizual app-switcher snapshot-ı XCTest-də görünmür, lakin əsas məntiqi yoxlaya
/// bilərik: secure rejimdə `willResignActive` key window-a `UIVisualEffectView`
/// blur overlay-i əlavə edir, `didBecomeActive` onu götürür, `setSecure(false)`
/// mövcud overlay-i dərhal təmizləyir.
final class SecureWindowGuardTests: XCTestCase {

    private func makeWindow() -> UIWindow {
        UIWindow(frame: CGRect(x: 0, y: 0, width: 320, height: 640))
    }

    private func hasBlur(_ window: UIWindow) -> Bool {
        window.subviews.contains { $0 is UIVisualEffectView }
    }

    func test_installsBlurOnResignActive_whenSecure() {
        let g = SecureWindowGuard()
        let window = makeWindow()

        g.setSecure(true)
        g.handleWillResignActive(window: window)

        XCTAssertTrue(hasBlur(window))
        XCTAssertNotNil(g.blurOverlay)
    }

    func test_doesNotInstallBlur_whenNotSecure() {
        let g = SecureWindowGuard()
        let window = makeWindow()

        g.setSecure(false)
        g.handleWillResignActive(window: window)

        XCTAssertFalse(hasBlur(window))
        XCTAssertNil(g.blurOverlay)
    }

    func test_removesBlurOnBecomeActive() {
        let g = SecureWindowGuard()
        let window = makeWindow()

        g.setSecure(true)
        g.handleWillResignActive(window: window)
        XCTAssertNotNil(g.blurOverlay)

        g.handleDidBecomeActive()

        XCTAssertNil(g.blurOverlay)
        XCTAssertFalse(hasBlur(window))
    }

    func test_setSecureFalse_removesExistingBlur() {
        let g = SecureWindowGuard()
        let window = makeWindow()

        g.setSecure(true)
        g.handleWillResignActive(window: window)
        XCTAssertNotNil(g.blurOverlay)

        g.setSecure(false)

        XCTAssertNil(g.blurOverlay)
        XCTAssertFalse(hasBlur(window))
    }

    func test_doesNotDoubleInstallBlur() {
        let g = SecureWindowGuard()
        let window = makeWindow()

        g.setSecure(true)
        g.handleWillResignActive(window: window)
        g.handleWillResignActive(window: window) // ikinci dəfə — yeni overlay yox

        let blurCount = window.subviews.filter { $0 is UIVisualEffectView }.count
        XCTAssertEqual(blurCount, 1)
    }
}
