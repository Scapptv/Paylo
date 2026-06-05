import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:google_fonts/google_fonts.dart';

import 'package:paylo/features/wallet/data/wallet_repository.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';
import 'package:paylo/features/wallet/presentation/screens/wallet_screen.dart';

/// 2026-06-04 — WalletScreen widget testləri.
/// MOB-2-də WalletScreen `SecureScreen`-lə wrap edildi; bu testlər həm wrap-ın
/// render-i sındırmadığını, həm wallet state-lərinin (data/error) düzgün
/// göründüyünü yoxlayır (əvvəllər heç bir widget test yox idi).
void main() {
  setUpAll(() {
    // Şəbəkədən font çəkməsin — test mühitində fallback font istifadə olunsun.
    GoogleFonts.config.allowRuntimeFetching = false;
  });

  const wallet = WalletSummary(
    totalBalance: 2481,
    totalEarnedAllTime: 2481,
    totalRedeemedAllTime: 0,
    expiringSoon: 0,
    bucketsCount: 1,
    buckets: [],
    recentEntries: [],
  );

  testWidgets('renders balance card in data state (SecureScreen wrap intact)', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [walletProvider.overrideWith((ref) => wallet)],
        child: const MaterialApp(home: WalletScreen()),
      ),
    );
    await tester.pump();

    expect(find.text('CƏM BONUS'), findsOneWidget);
    expect(find.textContaining('merchant'), findsWidgets);
  });

  testWidgets('renders retry button in error state', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [walletProvider.overrideWith((ref) => throw Exception('boom'))],
        child: const MaterialApp(home: WalletScreen()),
      ),
    );
    await tester.pump();

    expect(find.text('Yenidən cəhd et'), findsOneWidget);
  });
}
