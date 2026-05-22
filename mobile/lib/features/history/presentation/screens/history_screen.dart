import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/theme/app_theme.dart';
import 'package:paylo/features/history/presentation/controllers/history_controller.dart';
import 'package:paylo/features/wallet/presentation/widgets/ledger_entry_tile.dart';

class HistoryScreen extends ConsumerStatefulWidget {
  const HistoryScreen({super.key});

  @override
  ConsumerState<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends ConsumerState<HistoryScreen> {
  final _scrollController = ScrollController();

  @override
  void initState() {
    super.initState();
    _scrollController.addListener(_onScroll);
  }

  @override
  void dispose() {
    _scrollController.removeListener(_onScroll);
    _scrollController.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scrollController.position.pixels >=
        _scrollController.position.maxScrollExtent - 200) {
      ref.read(historyControllerProvider.notifier).loadMore();
    }
  }

  @override
  Widget build(BuildContext context) {
    final state = ref.watch(historyControllerProvider);

    return Scaffold(
      appBar: AppBar(title: const Text('Tarixçə')),
      body: Column(
        children: [
          // Filter chips
          SizedBox(
            height: 48,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 8),
              children: [
                _FilterChip(label: 'Hamısı', active: state.filterType == null,
                    onTap: () => ref.read(historyControllerProvider.notifier).refresh(),),
                const SizedBox(width: 6),
                _FilterChip(label: 'Qazanma', active: state.filterType == 'earn',
                    onTap: () => ref.read(historyControllerProvider.notifier).refresh(type: 'earn'),),
                const SizedBox(width: 6),
                _FilterChip(label: 'Xərcləmə', active: state.filterType == 'redeem',
                    onTap: () => ref.read(historyControllerProvider.notifier).refresh(type: 'redeem'),),
                const SizedBox(width: 6),
                _FilterChip(label: 'Refund', active: state.filterType == 'refund',
                    onTap: () => ref.read(historyControllerProvider.notifier).refresh(type: 'refund'),),
              ],
            ),
          ),

          Expanded(child: _buildBody(state)),
        ],
      ),
    );
  }

  Widget _buildBody(HistoryState state) {
    if (state.loading) {
      return const Center(child: CircularProgressIndicator(color: AppColors.accent));
    }

    if (state.error != null && state.entries.isEmpty) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, color: AppColors.danger, size: 40),
              const SizedBox(height: 16),
              Text(state.error!.message, style: AppTextStyles.body(13, color: AppColors.text2), textAlign: TextAlign.center),
              const SizedBox(height: 16),
              OutlinedButton(
                onPressed: () => ref.read(historyControllerProvider.notifier).refresh(),
                child: const Text('Yenidən cəhd et'),
              ),
            ],
          ),
        ),
      );
    }

    if (state.entries.isEmpty) {
      return Center(
        child: Text('Hələ heç bir əməliyyat yoxdur',
            style: AppTextStyles.mono(11, color: AppColors.muted, letterSpacing: 0.16),),
      );
    }

    return RefreshIndicator(
      color: AppColors.accent,
      backgroundColor: AppColors.surface,
      onRefresh: () => ref.read(historyControllerProvider.notifier).refresh(type: state.filterType),
      child: ListView.separated(
        controller: _scrollController,
        padding: const EdgeInsets.fromLTRB(20, 0, 20, 100),
        itemCount: state.entries.length + (state.hasMore ? 1 : 0),
        separatorBuilder: (_, __) => const SizedBox(height: 8),
        itemBuilder: (_, i) {
          if (i >= state.entries.length) {
            return Padding(
              padding: const EdgeInsets.all(16),
              child: Center(
                child: state.loadingMore
                    ? const CircularProgressIndicator(color: AppColors.accent, strokeWidth: 2)
                    : Text('Daha çox yüklə...', style: AppTextStyles.mono(11, color: AppColors.muted)),
              ),
            );
          }
          return LedgerEntryTile(entry: state.entries[i]);
        },
      ),
    );
  }
}

class _FilterChip extends StatelessWidget {
  const _FilterChip({required this.label, required this.active, required this.onTap});
  final String label;
  final bool active;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      onTap: onTap,
      behavior: HitTestBehavior.opaque,
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 220),
        curve: Curves.easeOut,
        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
        decoration: BoxDecoration(
          color: active ? AppColors.accent : AppColors.surface,
          border: Border.all(color: active ? AppColors.accent : AppColors.border),
          boxShadow: active
              ? [BoxShadow(color: AppColors.accent.withValues(alpha: 0.35), blurRadius: 14, spreadRadius: -2)]
              : null,
        ),
        child: AnimatedDefaultTextStyle(
          duration: const Duration(milliseconds: 220),
          style: AppTextStyles.mono(
            10,
            color: active ? AppColors.bg : AppColors.text2,
            letterSpacing: 0.16,
            weight: active ? FontWeight.w700 : FontWeight.w500,
          ),
          child: Text(label.toUpperCase()),
        ),
      ),
    );
  }
}
