import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter/widgets.dart';

/// Sprint 9 M-1: ekranı screenshot/recording-dən qoruyur.
///
/// İstifadə nümunəsi:
/// ```
/// return SecureScreen(child: QrView(...));
/// ```
///
/// Platforma davranışı:
///  - **Android**: `FLAG_SECURE` window flag — screenshot, screen recording
///    və Recents preview-da ekran qara görünür. Çıxanda flag silinir.
///  - **iOS**: `applicationWillResignActive`-də blur overlay (AppDelegate).
///    Screenshot funksiyası sistem səviyyəsində bloklana bilmir, lakin
///    multitasking/control center açıq olduqda preview qorunur.
///  - **Web / desktop / test**: no-op.
///
/// State idarəsi: widget mount olanda secure on, dispose olanda off — başqa
/// ekrana keçəndə avtomatik söndürülür.
class SecureScreen extends StatefulWidget {
  const SecureScreen({super.key, required this.child});

  final Widget child;

  static const MethodChannel _channel = MethodChannel('az.paylo/secure_window');

  // Audit 2026-06-04 MOB-2: refcount. `MainShell` IndexedStack bütün tab-ları
  // eyni anda mount edir, ona görə birdən çox SecureScreen (QR + wallet + profil)
  // eyni anda aktiv ola bilər. Sayğac 0→1 olanda secure aktivləşir, 1→0 olanda
  // söndürülür — bir ekranın dispose-u digərinin qorumasını ləğv etməsin.
  static int _activeCount = 0;

  /// Test üçün görünən sayğac (debug introspection).
  @visibleForTesting
  static int get activeCount => _activeCount;

  static Future<void> _acquire() async {
    _activeCount++;
    if (_activeCount == 1) {
      await setSecure(true);
    }
  }

  static Future<void> _release() async {
    if (_activeCount > 0) {
      _activeCount--;
    }
    if (_activeCount == 0) {
      await setSecure(false);
    }
  }

  /// Aşağı səviyyəli native toggle. Adətən `SecureScreen` widget-i (refcount ilə)
  /// kifayət edir; birbaşa çağırış yalnız xüsusi hallar üçündür.
  static Future<void> setSecure(bool enabled) async {
    if (kIsWeb) return;
    // Hələ yalnız Android/iOS support var.
    try {
      await _channel.invokeMethod('setSecure', {'enabled': enabled});
    } on PlatformException {
      // Native channel mövcud deyil (məs test runtime) — sakit keç.
    } on MissingPluginException {
      // Plugin registered deyil — sakit keç.
    }
  }

  @override
  State<SecureScreen> createState() => _SecureScreenState();
}

class _SecureScreenState extends State<SecureScreen> {
  @override
  void initState() {
    super.initState();
    SecureScreen._acquire();
  }

  @override
  void dispose() {
    SecureScreen._release();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => widget.child;
}
