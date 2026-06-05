import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:paylo/shared/widgets/secure_screen.dart';

/// 2026-06-04 dərin audit — MOB-2 refcount regression testi.
///
/// `MainShell` IndexedStack bütün tab-ları eyni anda mount edir, ona görə QR +
/// wallet + profil SecureScreen-ləri eyni anda aktiv olur. Refcount olmadan biri
/// dispose olanda native `setSecure(false)` çağırıb digərinin qorumasını söndürərdi.
/// Bu test sayğacın düzgün kompozisiyasını və native toggle-ın yalnız 0↔1
/// keçidlərində çağırıldığını yoxlayır.
void main() {
  const channel = MethodChannel('az.paylo/secure_window');

  testWidgets('MOB-2: multiple SecureScreens compose via refcount', (tester) async {
    final calls = <bool>[];
    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(channel, (call) async {
      if (call.method == 'setSecure') {
        calls.add(call.arguments['enabled'] as bool);
      }
      return null;
    });

    // İki SecureScreen eyni anda mount → native setSecure(true) yalnız BİR dəfə.
    await tester.pumpWidget(
      const MaterialApp(
        home: Column(
          children: [
            SecureScreen(child: SizedBox()),
            SecureScreen(child: SizedBox()),
          ],
        ),
      ),
    );
    await tester.pump();

    expect(SecureScreen.activeCount, 2);
    expect(calls.where((e) => e).length, 1);  // bir dəfə true
    expect(calls.where((e) => !e).length, 0); // hələ false yox

    // Birini sök → digəri hələ aktivdir, secure söndürülmür.
    await tester.pumpWidget(
      const MaterialApp(
        home: Column(
          children: [
            SecureScreen(child: SizedBox()),
            SizedBox(),
          ],
        ),
      ),
    );
    await tester.pump();

    expect(SecureScreen.activeCount, 1);
    expect(calls.where((e) => !e).length, 0); // hələ false yox

    // Hamısını sök → secure(false) yalnız indi, bir dəfə.
    await tester.pumpWidget(const MaterialApp(home: SizedBox()));
    await tester.pump();

    expect(SecureScreen.activeCount, 0);
    expect(calls.where((e) => !e).length, 1); // bir dəfə false

    tester.binding.defaultBinaryMessenger.setMockMethodCallHandler(channel, null);
  });
}
