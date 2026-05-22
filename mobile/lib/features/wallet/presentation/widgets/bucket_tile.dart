import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/core/utils/formatters.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';
import 'package:paylo/shared/widgets/glow.dart';

class BucketTile extends StatelessWidget {
  const BucketTile({super.key, required this.bucket});
  final Bucket bucket;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      onTap: () {},
      child: Container(
        decoration: BoxDecoration(
          color: AppColors.surface,
          border: Border.all(color: AppColors.border),
        ),
        child: IntrinsicHeight(
          child: Row(
            children: [
              // Sol accent stripe
              Container(width: 3, color: AppColors.accent.withValues(alpha: 0.45)),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    children: [
                      Container(
                        width: 42, height: 42,
                        alignment: Alignment.center,
                        decoration: BoxDecoration(
                          gradient: LinearGradient(
                            begin: Alignment.topLeft,
                            end: Alignment.bottomRight,
                            colors: [
                              AppColors.accent.withValues(alpha: 0.18),
                              AppColors.surface3,
                            ],
                          ),
                          border: Border.all(color: AppColors.border2),
                        ),
                        child: Text(
                          bucket.merchant.name.isNotEmpty ? bucket.merchant.name[0].toUpperCase() : '?',
                          style: AppTextStyles.display(18, weight: FontWeight.w700, color: AppColors.accent),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(bucket.merchant.name,
                                style: AppTextStyles.body(14, weight: FontWeight.w500),
                                overflow: TextOverflow.ellipsis,),
                            const SizedBox(height: 2),
                            Text(bucket.merchant.category,
                                style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.1),),
                          ],
                        ),
                      ),
                      Text(AppFormat.azn(bucket.balance),
                          style: AppTextStyles.mono(13, weight: FontWeight.w600, color: AppColors.accent, letterSpacing: 0),),
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
