import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
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
    final data = res.data!;

    final entries = (data['data'] as List)
        .map((e) => _ledgerFromJson(e as Map<String, dynamic>))
        .toList();

    return HistoryPage(
      entries: entries,
      nextCursor: data['next_cursor'] as String?,
      hasMore: data['has_more'] as bool? ?? false,
    );
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
