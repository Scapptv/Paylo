import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/shared/widgets/brand_mark.dart';
import 'package:paylo/shared/widgets/glow.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl =
      AnimationController(vsync: this, duration: const Duration(milliseconds: 900))..forward();
  late final Animation<double> _fade = CurvedAnimation(parent: _ctrl, curve: Curves.easeOut);
  late final Animation<double> _scale = Tween(begin: 0.92, end: 1.0).animate(
      CurvedAnimation(parent: _ctrl, curve: Curves.easeOutBack),);

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          const Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: RadialGradient(
                  center: Alignment(0, -0.3),
                  radius: 0.9,
                  colors: [Color(0x33C8FF3D), AppColors.bg],
                ),
              ),
            ),
          ),
          Center(
            child: FadeTransition(
              opacity: _fade,
              child: ScaleTransition(
                scale: _scale,
                child: const Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    PulseGlow(child: BrandMark(size: 22)),
                    SizedBox(height: 48),
                    SizedBox(
                      width: 22, height: 22,
                      child: CircularProgressIndicator(strokeWidth: 1.6, color: AppColors.accent),
                    ),
                  ],
                ),
              ),
            ),
          ),
          Positioned(
            left: 0, right: 0, bottom: 32,
            child: FadeTransition(
              opacity: _fade,
              child: Text(
                'LOYALTY · RE-IMAGINED',
                textAlign: TextAlign.center,
                style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.4),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
