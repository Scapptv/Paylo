import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';

class BrandMark extends StatelessWidget {
  const BrandMark({super.key, this.size = 14});
  final double size;

  @override
  Widget build(BuildContext context) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Transform.rotate(
          angle: 0.785, // 45°
          child: Container(
            width: size, height: size,
            decoration: BoxDecoration(
              color: AppColors.accent,
              boxShadow: [
                BoxShadow(color: AppColors.accent.withValues(alpha: 0.5), blurRadius: size, spreadRadius: 1),
              ],
            ),
          ),
        ),
        SizedBox(width: size * 0.8),
        Text('Paylo', style: AppTextStyles.display(size + 6, weight: FontWeight.w800)),
      ],
    );
  }
}
