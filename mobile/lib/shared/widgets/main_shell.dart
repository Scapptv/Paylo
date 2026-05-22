import 'package:flutter/material.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/cart/presentation/screens/cart_screen.dart';
import 'package:paylo/features/history/presentation/screens/history_screen.dart';
import 'package:paylo/features/profile/presentation/screens/profile_screen.dart';
import 'package:paylo/features/qr/presentation/screens/qr_screen.dart';
import 'package:paylo/features/wallet/presentation/screens/wallet_screen.dart';

/// 5 tab-li əsas shell.
/// Sıralama: Wallet · Tarixçə · [Barkod (mərkəz, qabarıq)] · Səbətim · Profil
class MainShell extends StatefulWidget {
  const MainShell({super.key, this.initialIndex = 0});
  final int initialIndex;

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  late int _currentIndex = widget.initialIndex;

  late final List<Widget> _screens = const [
    WalletScreen(),   // 0
    HistoryScreen(),  // 1
    QrScreen(),       // 2  Barkod
    CartScreen(),     // 3
    ProfileScreen(),  // 4
  ];

  // Side-tab-lar (mərkəzdən kənar 4 ədəd)
  static const _side = <_TabSpec>[
    _TabSpec(0, Icons.account_balance_wallet_outlined, Icons.account_balance_wallet, 'Wallet'),
    _TabSpec(1, Icons.receipt_long_outlined,           Icons.receipt_long,           'Tarixçə'),
    _TabSpec(3, Icons.shopping_bag_outlined,           Icons.shopping_bag,           'Səbətim'),
    _TabSpec(4, Icons.person_outline,                  Icons.person,                 'Profil'),
  ];

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      extendBody: true,
      body: IndexedStack(index: _currentIndex, children: _screens),
      bottomNavigationBar: _BottomBar(
        currentIndex: _currentIndex,
        sideTabs: _side,
        onTap: (i) => setState(() => _currentIndex = i),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Bottom bar
// ─────────────────────────────────────────────────────────────────────────────

class _BottomBar extends StatelessWidget {
  const _BottomBar({
    required this.currentIndex,
    required this.sideTabs,
    required this.onTap,
  });

  final int currentIndex;
  final List<_TabSpec> sideTabs;
  final ValueChanged<int> onTap;

  static const _barHeight = 68.0;
  static const _fabSize   = 60.0;
  static const _fabLift   = 24.0; // mərkəz tab nə qədər yuxarı qalxsın
  static const _notchPad  = 14.0; // mərkəz ətrafı boşluq

  @override
  Widget build(BuildContext context) {
    final bottomInset = MediaQuery.of(context).padding.bottom;
    final barkodActive = currentIndex == 2;

    return SizedBox(
      // bar + qaldırılmış FAB + bir az nəfəs alma boşluğu
      height: _barHeight + _fabLift + bottomInset + 6,
      child: Stack(
        clipBehavior: Clip.none,
        children: [
          // ── Alt bar (rəsmlə birlikdə kölgə)
          Positioned(
            left: 0, right: 0, bottom: 0,
            child: DecoratedBox(
              decoration: BoxDecoration(
                color: AppColors.surface,
                border: const Border(top: BorderSide(color: AppColors.border)),
                boxShadow: [
                  BoxShadow(
                    color: Colors.black.withValues(alpha: 0.25),
                    blurRadius: 18,
                    offset: const Offset(0, -4),
                  ),
                ],
              ),
              child: SafeArea(
                top: false,
                child: SizedBox(
                  height: _barHeight,
                  child: Row(
                    children: [
                      Expanded(child: _SideTab(spec: sideTabs[0], active: currentIndex == 0, onTap: () => onTap(0))),
                      Expanded(child: _SideTab(spec: sideTabs[1], active: currentIndex == 1, onTap: () => onTap(1))),
                      // mərkəz üçün boşluq (FAB altı)
                      const SizedBox(width: _fabSize + _notchPad * 2),
                      Expanded(child: _SideTab(spec: sideTabs[2], active: currentIndex == 3, onTap: () => onTap(3))),
                      Expanded(child: _SideTab(spec: sideTabs[3], active: currentIndex == 4, onTap: () => onTap(4))),
                    ],
                  ),
                ),
              ),
            ),
          ),

          // ── Mərkəz Barkod (qaldırılmış FAB)
          Positioned(
            top: 0,
            left: 0, right: 0,
            child: Center(child: _CenterBarkod(active: barkodActive, onTap: () => onTap(2))),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Side tab — pill background + accent indicator
// ─────────────────────────────────────────────────────────────────────────────

class _SideTab extends StatelessWidget {
  const _SideTab({required this.spec, required this.active, required this.onTap});
  final _TabSpec spec;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkResponse(
      onTap: onTap,
      highlightShape: BoxShape.rectangle,
      radius: 36,
      splashColor: AppColors.accent.withValues(alpha: 0.08),
      highlightColor: Colors.transparent,
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 8),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          mainAxisSize: MainAxisSize.max,
          children: [
            // Yuxarı accent indicator
            AnimatedContainer(
              duration: const Duration(milliseconds: 240),
              curve: Curves.easeOutCubic,
              height: 2,
              width: active ? 22 : 0,
              decoration: BoxDecoration(
                color: AppColors.accent,
                boxShadow: active
                    ? [BoxShadow(color: AppColors.accent.withValues(alpha: 0.6), blurRadius: 8)]
                    : null,
              ),
            ),
            const SizedBox(height: 8),
            // Icon
            AnimatedSwitcher(
              duration: const Duration(milliseconds: 200),
              transitionBuilder: (c, a) => ScaleTransition(
                scale: Tween<double>(begin: 0.85, end: 1).animate(a),
                child: FadeTransition(opacity: a, child: c),
              ),
              child: Icon(
                active ? spec.activeIcon : spec.icon,
                key: ValueKey('${spec.label}-$active'),
                size: 22,
                color: active ? AppColors.accent : AppColors.muted,
              ),
            ),
            const SizedBox(height: 6),
            // Label
            AnimatedDefaultTextStyle(
              duration: const Duration(milliseconds: 220),
              style: AppTextStyles.mono(
                10,
                color: active ? AppColors.accent : AppColors.muted,
                letterSpacing: 0.4,
                weight: active ? FontWeight.w700 : FontWeight.w500,
              ),
              child: Text(spec.label),
            ),
          ],
        ),
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Center Barkod (qabarıq)
// ─────────────────────────────────────────────────────────────────────────────

class _CenterBarkod extends StatelessWidget {
  const _CenterBarkod({required this.active, required this.onTap});
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    const fabSize = _BottomBar._fabSize;

    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: onTap,
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // Halo (yumşaq fon)
          Stack(
            alignment: Alignment.center,
            clipBehavior: Clip.none,
            children: [
              AnimatedContainer(
                duration: const Duration(milliseconds: 260),
                curve: Curves.easeOut,
                width: fabSize + 18, height: fabSize + 18,
                decoration: const BoxDecoration(
                  shape: BoxShape.circle,
                  color: AppColors.bg, // alt-barın üzərində səliqəli oturması üçün
                ),
              ),
              AnimatedContainer(
                duration: const Duration(milliseconds: 260),
                curve: Curves.easeOut,
                width: fabSize, height: fabSize,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: active ? AppColors.accent : AppColors.surface,
                  border: Border.all(
                    color: AppColors.accent,
                    width: active ? 2 : 1.5,
                  ),
                  boxShadow: [
                    BoxShadow(
                      color: AppColors.accent.withValues(alpha: active ? 0.55 : 0.28),
                      blurRadius: active ? 26 : 18,
                      spreadRadius: active ? 2 : 0,
                    ),
                  ],
                ),
                alignment: Alignment.center,
                child: AnimatedSwitcher(
                  duration: const Duration(milliseconds: 220),
                  transitionBuilder: (c, a) => ScaleTransition(scale: a, child: c),
                  child: Icon(
                    Icons.qr_code_2,
                    key: ValueKey(active),
                    size: 30,
                    color: active ? AppColors.bg : AppColors.accent,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          AnimatedDefaultTextStyle(
            duration: const Duration(milliseconds: 220),
            style: AppTextStyles.mono(
              10,
              color: active ? AppColors.accent : AppColors.muted,
              letterSpacing: 0.4,
              weight: active ? FontWeight.w700 : FontWeight.w500,
            ),
            child: const Text('Barkod'),
          ),
        ],
      ),
    );
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Spec
// ─────────────────────────────────────────────────────────────────────────────

class _TabSpec {
  const _TabSpec(this.index, this.icon, this.activeIcon, this.label);
  final int index;
  final IconData icon;
  final IconData activeIcon;
  final String label;
}
