import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
import 'package:paylo/core/errors/api_exception.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';

class HistoryPage {
  const HistoryPage({
    required this.entries,
    required this.nextCursor,
    required this.hasMore,
  });

  final List<LedgerEntry> entries;
  final String? nextCursor;
  final bool hasMore;
}

class HistoryRepository {
  HistoryRepository(this._api);
  final ApiClient _api;

  Future<HistoryPage> fetch({String? cursor, String? type, int? merchantId}) async {
    final res = await _api.get<Map<String, dynamic>>('/history', query: {
      if (cursor != null) 'cursor': cursor,
      if (type != null) 'type': type,
      if (merchantId != null) 'merchant_id': merchantId,
      'limit': 20,
    },);
    return parse(res.data);
  }

  /// Audit 2026-06-04 MOB-4: qorunmuş + test-edilə bilən parse. Səhv formatlı
  /// cavab TypeError yox, `ServerException` atır. Boş `data` list isə etibarlı
  /// (boş tarixçə) sayılır.
  static HistoryPage parse(Map<String, dynamic>? data) {
    try {
      final list = (data?['data'] as List?) ?? const [];
      final entries = list
          .whereType<Map<String, dynamic>>()
          .map(_ledgerFromJson)
          .toList();

      return HistoryPage(
        entries: entries,
        nextCursor: data?['next_cursor'] as String?,
        hasMore: data?['has_more'] as bool? ?? false,
      );
    } on ApiException {
      rethrow;
    } catch (_) {
      throw const ServerException('Tarixçə oxuna bilmədi (gözlənilməz format).');
    }
  }

  static LedgerEntry _ledgerFromJson(Map<String, dynamic> json) => LedgerEntry(
        id:           json['id'] as int,
        uid:          json['uid'] as String,
        type:         LedgerType.fromString(json['type'] as String),
        amount:       json['amount'] as int,
        balanceAfter: json['balance_after'] as int,
        ref:          json['ref'] as String?,
        createdAt:    DateTime.parse(json['created_at'] as String),
        merchant: json['merchant'] is Map<String, dynamic>
            ? Merchant(
                id:       json['merchant']['id'] as int,
                code:     json['merchant']['code'] as String,
                name:     json['merchant']['name'] as String,
                category: json['merchant']['category'] as String? ?? '',
              )
            : null,
      );
}

final historyRepositoryProvider = Provider<HistoryRepository>((ref) {
  return HistoryRepository(ref.watch(apiClientProvider));
});
