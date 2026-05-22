import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/core/utils/formatters.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';
import 'package:paylo/shared/widgets/glow.dart';

class LedgerEntryTile extends StatelessWidget {
  const LedgerEntryTile({super.key, required this.entry});
  final LedgerEntry entry;

  Color _typeColor() => switch (entry.type) {
        LedgerType.earn || LedgerType.adjustment => AppColors.success,
        LedgerType.redeem => AppColors.warning,
        LedgerType.refund || LedgerType.reversal => AppColors.danger,
        _ => AppColors.muted,
      };

  @override
  Widget build(BuildContext context) {
    final color = _typeColor();
    final sign = entry.isCredit ? '+' : '−';

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
              Container(width: 3, color: color.withValues(alpha: 0.55)),
              Expanded(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                        decoration: BoxDecoration(
                          color: color.withValues(alpha: 0.10),
                          border: Border.all(color: color.withValues(alpha: 0.4)),
                        ),
                        child: Row(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Container(
                              width: 6, height: 6,
                              decoration: BoxDecoration(
                                color: color,
                                boxShadow: [BoxShadow(color: color.withValues(alpha: 0.6), blurRadius: 6)],
                              ),
                            ),
                            const SizedBox(width: 6),
                            Text(entry.type.name.toUpperCase(),
                                style: AppTextStyles.mono(9, color: color, letterSpacing: 0.16),),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(entry.merchant?.name ?? '—',
                                style: AppTextStyles.body(13), overflow: TextOverflow.ellipsis,),
                            const SizedBox(height: 2),
                            Text(AppFormat.relative(entry.createdAt),
                                style: AppTextStyles.mono(10, color: AppColors.muted),),
                          ],
                        ),
                      ),
                      Text('$sign${AppFormat.azn(entry.amount)}',
                          style: AppTextStyles.mono(13, weight: FontWeight.w600, color: color, letterSpacing: 0),),
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
