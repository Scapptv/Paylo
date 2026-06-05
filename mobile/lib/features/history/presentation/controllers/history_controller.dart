import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/features/history/data/history_repository.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';

class HistoryState {
  const HistoryState({
    this.entries = const [],
    this.loading = false,
    this.loadingMore = false,
    this.error,
    this.hasMore = true,
    this.cursor,
    this.filterType,
  });

  final List<LedgerEntry> entries;
  final bool loading;
  final bool loadingMore;
  final ApiException? error;
  final bool hasMore;
  final String? cursor;
  final String? filterType;

  HistoryState copyWith({
    List<LedgerEntry>? entries,
    bool? loading,
    bool? loadingMore,
    ApiException? error,
    bool? hasMore,
    String? cursor,
    String? filterType,
    bool clearError = false,
    bool clearFilter = false,
  }) =>
      HistoryState(
        entries:     entries ?? this.entries,
        loading:     loading ?? this.loading,
        loadingMore: loadingMore ?? this.loadingMore,
        error:       clearError ? null : (error ?? this.error),
        hasMore:     hasMore ?? this.hasMore,
        cursor:      cursor ?? this.cursor,
        // Audit 2026-06-04 MOB-6: `filterType: null` ötürmək filtri təmizləməlidir.
        // `?? this.filterType` null-ı köhnə dəyərlə əvəz edirdi, ona görə "Hamısı"
        // çipi aktiv filtri heç vaxt sıfırlaya bilmirdi (loadMore köhnə filtri
        // tətbiq edirdi, çip yanlış aktiv görünürdü).
        filterType:  clearFilter ? null : (filterType ?? this.filterType),
      );
}

class HistoryController extends AutoDisposeNotifier<HistoryState> {
  late final HistoryRepository _repo;

  @override
  HistoryState build() {
    _repo = ref.watch(historyRepositoryProvider);
    Future.microtask(refresh);
    return const HistoryState(loading: true);
  }

  Future<void> refresh({String? type}) async {
    state = state.copyWith(
      loading: true,
      entries: const [],
      cursor: null,
      hasMore: true,
      filterType: type,
      clearFilter: type == null, // "Hamısı" seçimi filtri tam təmizləsin (MOB-6)
      clearError: true,
    );

    try {
      final page = await _repo.fetch(type: type);
      state = state.copyWith(
        entries: page.entries,
        cursor: page.nextCursor,
        hasMore: page.hasMore,
        loading: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(error: e, loading: false);
    }
  }

  Future<void> loadMore() async {
    if (state.loadingMore || !state.hasMore || state.cursor == null) return;
    state = state.copyWith(loadingMore: true);

    try {
      final page = await _repo.fetch(cursor: state.cursor, type: state.filterType);
      state = state.copyWith(
        entries: [...state.entries, ...page.entries],
        cursor: page.nextCursor,
        hasMore: page.hasMore,
        loadingMore: false,
      );
    } on ApiException catch (e) {
      state = state.copyWith(error: e, loadingMore: false);
    }
  }
}

final historyControllerProvider =
    AutoDisposeNotifierProvider<HistoryController, HistoryState>(HistoryController.new);
