import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shimmer/shimmer.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/core/utils/formatters.dart';
import 'package:paylo/features/wallet/data/wallet_repository.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';
import 'package:paylo/features/wallet/presentation/widgets/bucket_tile.dart';
import 'package:paylo/features/wallet/presentation/widgets/ledger_entry_tile.dart';
import 'package:paylo/shared/widgets/animated_count.dart';
import 'package:paylo/shared/widgets/glow.dart';
import 'package:paylo/shared/widgets/secure_screen.dart';

class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final walletAsync = ref.watch(walletProvider);

    // Audit 2026-06-04 MOB-2: wallet həssas data (balans/bucket/ledger) —
    // screenshot/recording bağlanır (QR ekranı ilə eyni qoruma).
    return SecureScreen(child: Scaffold(
      body: Stack(
        children: [
          const Positioned.fill(
            child: IgnorePointer(
              child: DecoratedBox(
                decoration: BoxDecoration(
                  gradient: RadialGradient(
                    center: Alignment(0.7, -1.1),
                    radius: 1.1,
                    colors: [Color(0x1FC8FF3D), AppColors.bg],
                  ),
                ),
              ),
            ),
          ),
          RefreshIndicator(
            color: AppColors.accent,
            backgroundColor: AppColors.surface,
            onRefresh: () async => ref.invalidate(walletProvider),
            child: walletAsync.when(
              loading: () => const _LoadingState(),
              error: (e, _) => _ErrorState(message: e.toString(), onRetry: () => ref.invalidate(walletProvider)),
              data: (wallet) => CustomScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                slivers: [
                  const SliverAppBar(
                    pinned: false,
                    expandedHeight: 80,
                    backgroundColor: Colors.transparent,
                    flexibleSpace: Padding(
                      padding: EdgeInsets.fromLTRB(20, 24, 20, 0),
                      child: Align(alignment: Alignment.bottomLeft, child: _Header()),
                    ),
                  ),

                  // Minimalist balans kartı — toxunduqda detallı modal açılır
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 24),
                    sliver: SliverToBoxAdapter(
                      child: _MinimalBalanceCard(
                        total: wallet.totalBalance,
                        bucketsCount: wallet.bucketsCount,
                        expiringSoon: wallet.expiringSoon,
                        onTap: () => _openDetailsSheet(context, wallet),
                      ),
                    ),
                  ),

                  // Delivery karusel
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(0, 0, 0, 24),
                    sliver: SliverToBoxAdapter(
                      child: _DeliverySection(),
                    ),
                  ),

                  const SliverPadding(padding: EdgeInsets.only(bottom: 80)),
                ],
              ),
            ),
          ),
        ],
      ),
    ),);
  }

  void _openDetailsSheet(BuildContext context, WalletSummary wallet) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      barrierColor: Colors.black.withValues(alpha: 0.6),
      builder: (_) => _WalletDetailsSheet(wallet: wallet),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Header
// ─────────────────────────────────────────────────────────────────────────────

class _Header extends StatelessWidget {
  const _Header();
  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Xoş gəldin', style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.2)),
        const SizedBox(height: 4),
        Text('Wallet', style: AppTextStyles.display(26, weight: FontWeight.w600)),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Minimalist Balance Card
// ─────────────────────────────────────────────────────────────────────────────

class _MinimalBalanceCard extends StatelessWidget {
  const _MinimalBalanceCard({
    required this.total,
    required this.bucketsCount,
    required this.expiringSoon,
    required this.onTap,
  });

  final int total;
  final int bucketsCount;
  final int expiringSoon;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.fromLTRB(22, 22, 22, 18),
        decoration: BoxDecoration(
          color: AppColors.surface,
          border: Border.all(color: AppColors.border2),
          boxShadow: [
            BoxShadow(
              color: AppColors.accent.withValues(alpha: 0.06),
              blurRadius: 24,
              spreadRadius: -6,
              offset: const Offset(0, 8),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Container(width: 6, height: 6, color: AppColors.accent),
                const SizedBox(width: 8),
                Text('CƏM BONUS',
                    style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
                const Spacer(),
                Text('detallar →',
                    style: AppTextStyles.mono(10, color: AppColors.accent, letterSpacing: 0.2),),
              ],
            ),
            const SizedBox(height: 14),
            AnimatedCount(
              value: total,
              formatter: (v) => AppFormat.azn(v.round()),
              style: AppTextStyles.display(40, weight: FontWeight.w300, color: AppColors.accent, letterSpacing: -0.03),
            ),
            const SizedBox(height: 10),
            Row(
              children: [
                Text('$bucketsCount merchant',
                    style: AppTextStyles.mono(11, color: AppColors.muted),),
                if (expiringSoon > 0) ...[
                  const SizedBox(width: 10),
                  Container(width: 3, height: 3, color: AppColors.muted),
                  const SizedBox(width: 10),
                  const Icon(Icons.schedule, color: AppColors.warning, size: 11),
                  const SizedBox(width: 4),
                  Text('${AppFormat.azn(expiringSoon)} bitir',
                      style: AppTextStyles.mono(11, color: AppColors.warning),),
                ],
              ],
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Wallet Details Sheet (full breakdown + recent entries)
// ─────────────────────────────────────────────────────────────────────────────

class _WalletDetailsSheet extends StatelessWidget {
  const _WalletDetailsSheet({required this.wallet});
  final WalletSummary wallet;

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.86,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      builder: (_, scrollController) => Container(
        decoration: const BoxDecoration(
          color: AppColors.bg,
          border: Border(top: BorderSide(color: AppColors.accent, width: 2)),
        ),
        child: Column(
          children: [
            // Drag handle
            Container(
              margin: const EdgeInsets.only(top: 8, bottom: 4),
              width: 40, height: 3,
              color: AppColors.muted,
            ),
            Expanded(
              child: CustomScrollView(
                controller: scrollController,
                slivers: [
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 16, 20, 16),
                    sliver: SliverToBoxAdapter(
                      child: _FullBalanceCard(
                        total: wallet.totalBalance,
                        bucketsCount: wallet.bucketsCount,
                        expiringSoon: wallet.expiringSoon,
                      ),
                    ),
                  ),

                  // Buckets header
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 8, 20, 8),
                    sliver: SliverToBoxAdapter(
                      child: Text('MERCHANT-LƏR ÜZRƏ',
                          style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
                    ),
                  ),
                  SliverPadding(
                    padding: const EdgeInsets.symmetric(horizontal: 20),
                    sliver: SliverList.separated(
                      itemCount: wallet.buckets.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (_, i) => BucketTile(bucket: wallet.buckets[i]),
                    ),
                  ),

                  // Recent entries header
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 24, 20, 12),
                    sliver: SliverToBoxAdapter(
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Text('SON HƏRƏKƏTLƏR',
                              style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
                          Text('HAMISI →',
                              style: AppTextStyles.mono(10, color: AppColors.accent, letterSpacing: 0.2),),
                        ],
                      ),
                    ),
                  ),
                  SliverPadding(
                    padding: const EdgeInsets.fromLTRB(20, 0, 20, 32),
                    sliver: SliverList.separated(
                      itemCount: wallet.recentEntries.length,
                      separatorBuilder: (_, __) => const SizedBox(height: 8),
                      itemBuilder: (_, i) => LedgerEntryTile(entry: wallet.recentEntries[i]),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

// Eyni "böyük" balans kartı modal içində
class _FullBalanceCard extends StatelessWidget {
  const _FullBalanceCard({
    required this.total,
    required this.bucketsCount,
    required this.expiringSoon,
  });

  final int total;
  final int bucketsCount;
  final int expiringSoon;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(24),
      decoration: BoxDecoration(
        color: AppColors.surface,
        border: Border.all(color: AppColors.border2),
        gradient: const RadialGradient(
          center: Alignment(1.1, -1),
          radius: 1.4,
          colors: [Color(0x33C8FF3D), AppColors.surface],
        ),
        boxShadow: [
          BoxShadow(
            color: AppColors.accent.withValues(alpha: 0.10),
            blurRadius: 28,
            spreadRadius: -4,
            offset: const Offset(0, 10),
          ),
        ],
      ),
      child: Stack(
        children: [
          Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(width: 6, height: 6, color: AppColors.accent),
                  const SizedBox(width: 8),
                  Text('CƏM BONUS',
                      style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
                ],
              ),
              const SizedBox(height: 12),
              AnimatedCount(
                value: total,
                formatter: (v) => AppFormat.azn(v.round()),
                style: AppTextStyles.display(42, weight: FontWeight.w300, color: AppColors.accent, letterSpacing: -0.03),
              ),
              const SizedBox(height: 14),
              Text('$bucketsCount fərqli merchant-da',
                  style: AppTextStyles.mono(11, color: AppColors.muted),),
              if (expiringSoon > 0) ...[
                const SizedBox(height: 14),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                    color: AppColors.warning.withValues(alpha: 0.10),
                    border: Border.all(color: AppColors.warning.withValues(alpha: 0.4)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.schedule, color: AppColors.warning, size: 12),
                      const SizedBox(width: 6),
                      Text(
                        '${AppFormat.azn(expiringSoon)} 30 gün ərzində bitir',
                        style: AppTextStyles.mono(10, color: AppColors.warning, letterSpacing: 0.1),
                      ),
                    ],
                  ),
                ),
              ],
            ],
          ),
          const Positioned(left: 0, right: 0, bottom: 0, child: ScanLine()),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Delivery Section — Glovo tərzində fırlanan karusel
// ─────────────────────────────────────────────────────────────────────────────

class _DeliverySection extends StatelessWidget {
  static const _items = <_DeliveryItem>[
    _DeliveryItem('Restoran',   Icons.restaurant,     Color(0xFFFF6B6B)),
    _DeliveryItem('Market',     Icons.shopping_cart,  Color(0xFF4ECDC4)),
    _DeliveryItem('E-ticarət',  Icons.storefront,     AppColors.accent),
    _DeliveryItem('Aptek',      Icons.local_pharmacy, Color(0xFFA78BFA)),
    _DeliveryItem('Taksi',      Icons.local_taxi,     Color(0xFFFFD166)),
  ];

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 0, 20, 14),
          child: Row(
            children: [
              Container(width: 6, height: 6, color: AppColors.accent),
              const SizedBox(width: 8),
              Text('DELIVERY',
                  style: AppTextStyles.mono(10, color: AppColors.muted, letterSpacing: 0.24, weight: FontWeight.w700),),
              const Spacer(),
              Text('hamısı →',
                  style: AppTextStyles.mono(10, color: AppColors.accent, letterSpacing: 0.2),),
            ],
          ),
        ),
        SizedBox(
          height: 160,
          child: _DeliveryCarousel(items: _items),
        ),
      ],
    );
  }
}

class _DeliveryItem {
  const _DeliveryItem(this.label, this.icon, this.color);
  final String label;
  final IconData icon;
  final Color color;
}

class _DeliveryCarousel extends StatefulWidget {
  const _DeliveryCarousel({required this.items});
  final List<_DeliveryItem> items;

  @override
  State<_DeliveryCarousel> createState() => _DeliveryCarouselState();
}

class _DeliveryCarouselState extends State<_DeliveryCarousel> {
  late final PageController _controller;
  late double _page;
  late int _initialPage;

  @override
  void initState() {
    super.initState();
    _initialPage = widget.items.length ~/ 2; // mərkəzdə E-ticarət (index 2)
    _page = _initialPage.toDouble();
    _controller = PageController(
      initialPage: _initialPage,
      viewportFraction: 0.34,
    );
    _controller.addListener(() {
      setState(() => _page = _controller.page ?? _initialPage.toDouble());
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return PageView.builder(
      controller: _controller,
      itemCount: widget.items.length,
      physics: const BouncingScrollPhysics(),
      itemBuilder: (_, i) {
        final delta = (i - _page).abs().clamp(0.0, 1.5);
        final scale = (1.0 - delta * 0.28).clamp(0.6, 1.0);
        final opacity = (1.0 - delta * 0.55).clamp(0.25, 1.0);
        final isCenter = delta < 0.5;
        final item = widget.items[i];

        return Center(
          child: Opacity(
            opacity: opacity,
            child: Transform.scale(
              scale: scale,
              child: GestureDetector(
                onTap: () => _controller.animateToPage(
                  i,
                  duration: const Duration(milliseconds: 350),
                  curve: Curves.easeOutCubic,
                ),
                child: _DeliveryBadge(item: item, active: isCenter),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _DeliveryBadge extends StatelessWidget {
  const _DeliveryBadge({required this.item, required this.active});
  final _DeliveryItem item;
  final bool active;

  @override
  Widget build(BuildContext context) {
    final ringColor = active ? item.color : AppColors.border2;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        AnimatedContainer(
          duration: const Duration(milliseconds: 220),
          curve: Curves.easeOut,
          width: 88, height: 88,
          alignment: Alignment.center,
          decoration: BoxDecoration(
            color: AppColors.surface,
            shape: BoxShape.circle,
            border: Border.all(color: ringColor, width: active ? 2 : 1),
            boxShadow: active
                ? [
                    BoxShadow(
                      color: item.color.withValues(alpha: 0.35),
                      blurRadius: 24,
                      spreadRadius: -2,
                    ),
                  ]
                : null,
          ),
          child: Icon(item.icon, color: active ? item.color : AppColors.text2, size: 36),
        ),
        const SizedBox(height: 10),
        AnimatedDefaultTextStyle(
          duration: const Duration(milliseconds: 220),
          style: AppTextStyles.mono(
            active ? 11 : 10,
            color: active ? AppColors.text : AppColors.muted,
            letterSpacing: 0.18,
            weight: active ? FontWeight.w700 : FontWeight.w500,
          ),
          child: Text(item.label),
        ),
      ],
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Loading / Error states
// ─────────────────────────────────────────────────────────────────────────────

class _LoadingState extends StatelessWidget {
  const _LoadingState();
  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Shimmer.fromColors(
          baseColor: AppColors.surface,
          highlightColor: AppColors.surface2,
          child: Column(
            children: List.generate(4, (i) => Container(
              margin: const EdgeInsets.only(bottom: 12),
              height: i == 0 ? 140 : 60,
              color: AppColors.surface,
            ),),
          ),
        ),
      ],
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.onRetry});
  final String message;
  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(Icons.error_outline, color: AppColors.danger, size: 40),
            const SizedBox(height: 16),
            Text(message, textAlign: TextAlign.center, style: AppTextStyles.body(13, color: AppColors.text2)),
            const SizedBox(height: 24),
            OutlinedButton(onPressed: onRetry, child: const Text('Yenidən cəhd et')),
          ],
        ),
      ),
    );
  }
}
