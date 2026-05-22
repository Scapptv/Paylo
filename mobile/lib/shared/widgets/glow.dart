import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';

/// Yumşaq pulsing glow (splash, brand mark, avatar üçün).
class PulseGlow extends StatefulWidget {
  const PulseGlow({
    super.key,
    required this.child,
    this.color = AppColors.accent,
    this.minBlur = 12,
    this.maxBlur = 32,
    this.minOpacity = 0.25,
    this.maxOpacity = 0.55,
    this.duration = const Duration(milliseconds: 1800),
  });

  final Widget child;
  final Color color;
  final double minBlur;
  final double maxBlur;
  final double minOpacity;
  final double maxOpacity;
  final Duration duration;

  @override
  State<PulseGlow> createState() => _PulseGlowState();
}

class _PulseGlowState extends State<PulseGlow> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl =
      AnimationController(vsync: this, duration: widget.duration)..repeat(reverse: true);

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _ctrl,
      builder: (_, child) {
        final t = Curves.easeInOut.transform(_ctrl.value);
        final blur = widget.minBlur + (widget.maxBlur - widget.minBlur) * t;
        final op   = widget.minOpacity + (widget.maxOpacity - widget.minOpacity) * t;
        return DecoratedBox(
          decoration: BoxDecoration(
            boxShadow: [
              BoxShadow(
                color: widget.color.withValues(alpha: op),
                blurRadius: blur,
                spreadRadius: blur * 0.18,
              ),
            ],
          ),
          child: child,
        );
      },
      child: widget.child,
    );
  }
}

/// Press-də subtle scale (98%) — bütün interactive container-lər üçün.
class PressScale extends StatefulWidget {
  const PressScale({super.key, required this.child, required this.onTap, this.scale = 0.97});
  final Widget child;
  final VoidCallback onTap;
  final double scale;

  @override
  State<PressScale> createState() => _PressScaleState();
}

class _PressScaleState extends State<PressScale> {
  bool _down = false;
  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown:   (_) => setState(() => _down = true),
      onTapCancel: ()  => setState(() => _down = false),
      onTapUp:     (_) => setState(() => _down = false),
      onTap: widget.onTap,
      child: AnimatedScale(
        scale: _down ? widget.scale : 1.0,
        duration: const Duration(milliseconds: 110),
        curve: Curves.easeOut,
        child: widget.child,
      ),
    );
  }
}

/// Üfüqi accent xəttinin soldan-sağa yavaş hərəkəti — premium hiss üçün.
class ScanLine extends StatefulWidget {
  const ScanLine({super.key, this.color = AppColors.accent, this.height = 1.5, this.duration = const Duration(seconds: 6)});
  final Color color;
  final double height;
  final Duration duration;

  @override
  State<ScanLine> createState() => _ScanLineState();
}

class _ScanLineState extends State<ScanLine> with SingleTickerProviderStateMixin {
  late final AnimationController _ctrl =
      AnimationController(vsync: this, duration: widget.duration)..repeat();

  @override
  void dispose() {
    _ctrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return ClipRect(
      child: AnimatedBuilder(
        animation: _ctrl,
        builder: (_, __) {
          final t = _ctrl.value;
          return Align(
            alignment: Alignment(-1 + 2 * t, 0),
            child: FractionallySizedBox(
              widthFactor: 0.35,
              child: Container(
                height: widget.height,
                decoration: BoxDecoration(
                  gradient: LinearGradient(
                    colors: [
                      widget.color.withValues(alpha: 0),
                      widget.color.withValues(alpha: 0.75),
                      widget.color.withValues(alpha: 0),
                    ],
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }
}
