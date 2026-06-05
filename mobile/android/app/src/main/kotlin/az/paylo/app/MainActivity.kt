package az.paylo.app

import android.os.Bundle
import android.view.WindowManager
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * Sprint 9 M-1: Screenshot protection.
 *
 * Dart `SecureScreen` widget-i bu method channel-ı çağırır — Android-də
 * `FLAG_SECURE` window flag-i screenshot, screen recording və Recents preview-da
 * boş ekran göstərir. Per-screen idarə olunur: QR/wallet ekranlarına girəndə
 * `setSecure(true)`, çıxanda `setSecure(false)`.
 */
class MainActivity : FlutterActivity() {

    private val secureChannel = "az.paylo/secure_window"

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)

        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, secureChannel)
            .setMethodCallHandler { call, result ->
                when (call.method) {
                    "setSecure" -> {
                        val enabled = call.argument<Boolean>("enabled") ?: false
                        runOnUiThread {
                            if (enabled) {
                                window.setFlags(
                                    WindowManager.LayoutParams.FLAG_SECURE,
                                    WindowManager.LayoutParams.FLAG_SECURE
                                )
                            } else {
                                window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                            }
                            result.success(null)
                        }
                    }
                    else -> result.notImplemented()
                }
            }
    }
}
