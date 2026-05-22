import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/router/app_router.dart';
import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/auth/presentation/controllers/auth_controller.dart';
import 'package:paylo/features/push/data/push_service.dart';
import 'package:paylo/shared/widgets/offline_banner.dart';

class PayloApp extends ConsumerStatefulWidget {
  const PayloApp({super.key});

  @override
  ConsumerState<PayloApp> createState() => _PayloAppState();
}

class _PayloAppState extends ConsumerState<PayloApp> {
  @override
  void initState() {
    super.initState();

    // Auth state-i dinlə → authenticated olduqda push register et,
    // unauthenticated olduqda token unregister et.
    ref.listenManual<AuthState>(authControllerProvider, (prev, next) async {
      if (prev is! AuthAuthenticated && next is AuthAuthenticated) {
        // Yeni login — push token register et
        try {
          await ref.read(pushServiceProvider).init();
        } catch (_) {
          // Firebase config olmadıqda silent
        }
      }
      if (prev is AuthAuthenticated && next is AuthUnauthenticated) {
        // Logout — push token unregister
        try {
          await ref.read(pushServiceProvider).unregisterCurrentToken();
        } catch (_) {}
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(appRouterProvider);

    SystemChrome.setSystemUIOverlayStyle(const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: AppColors.bg,
      systemNavigationBarIconBrightness: Brightness.light,
    ),);

    return MaterialApp.router(
      title: 'Paylo',
      theme: AppTheme.dark(),
      darkTheme: AppTheme.dark(),
      themeMode: ThemeMode.dark,
      debugShowCheckedModeBanner: false,
      routerConfig: router,
      builder: (context, child) => OfflineBanner(child: child ?? const SizedBox.shrink()),
      localizationsDelegates: const [
        GlobalMaterialLocalizations.delegate,
        GlobalWidgetsLocalizations.delegate,
        GlobalCupertinoLocalizations.delegate,
      ],
      supportedLocales: const [
        Locale('az'),
        Locale('en'),
        Locale('ru'),
      ],
    );
  }
}
