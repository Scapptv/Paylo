import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';

class CartScreen extends StatelessWidget {
  const CartScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: Stack(
        children: [
          const Positioned.fill(
            child: IgnorePointer(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment(-0.7, -1.1),
                    radius: 1.1,
                    colors: [Color(0x1FC8FF3D), AppColors.bg],
                  ),
                ),
              ),
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 24, 20, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Səbətim',
                      style: AppTextStyles.display(26, weight: FontWeight.w600),),
                  const SizedBox(height: 4),
                  Text('SİFARİŞLƏRİN',
                      style: AppTextStyles.mono(10,
                          color: AppColors.muted,
                          letterSpacing: 0.24,
                          weight: FontWeight.w700,),),
                  const SizedBox(height: 32),
                  Expanded(
                    child: Center(
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Container(
                            width: 80, height: 80,
                            decoration: BoxDecoration(
                              color: AppColors.surface,
                              border: Border.all(color: AppColors.border2),
                            ),
                            alignment: Alignment.center,
                            child: const Icon(Icons.shopping_bag_outlined,
                                color: AppColors.muted, size: 38,),
                          ),
                          const SizedBox(height: 18),
                          Text('Səbətin boşdur',
                              style: AppTextStyles.body(14, color: AppColors.text2),),
                          const SizedBox(height: 6),
                          Text('Delivery-dən sifariş ver',
                              style: AppTextStyles.mono(11, color: AppColors.muted),),
                        ],
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
