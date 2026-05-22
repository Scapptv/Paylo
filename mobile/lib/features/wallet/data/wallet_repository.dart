import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
import 'package:paylo/features/wallet/domain/wallet_models.dart';

class _MerchantDto {
  static Merchant fromJson(Map<String, dynamic> json) => Merchant(
        id:       json['id'] as int,
        code:     json['code'] as String,
        name:     json['name'] as String,
        category: json['category'] as String? ?? '',
        tier:     json['tier'] as String?,
      );
}

class _BucketDto {
  static Bucket fromJson(Map<String, dynamic> json) => Bucket(
        id:             json['id'] as int,
        balance:        json['balance'] as int,
        earnedTotal:    json['earned_total'] as int,
        redeemedTotal:  json['redeemed_total'] as int,
        lastActivityAt: json['last_activity_at'] != null
            ? DateTime.tryParse(json['last_activity_at'] as String)
            : null,
        merchant: _MerchantDto.fromJson(json['merchant'] as Map<String, dynamic>),
      );
}

class _LedgerEntryDto {
  static LedgerEntry fromJson(Map<String, dynamic> json) => LedgerEntry(
        id:           json['id'] as int,
        uid:          json['uid'] as String,
        type:         LedgerType.fromString(json['type'] as String),
        amount:       json['amount'] as int,
        balanceAfter: json['balance_after'] as int,
        ref:          json['ref'] as String?,
        createdAt:    DateTime.parse(json['created_at'] as String),
        merchant: json['merchant'] is Map<String, dynamic>
            ? _MerchantDto.fromJson(json['merchant'] as Map<String, dynamic>)
            : null,
      );
}

class WalletRepository {
  WalletRepository(this._api);
  final ApiClient _api;

  Future<WalletSummary> getWallet() async {
    final res = await _api.get<Map<String, dynamic>>('/wallet');
    final data = res.data!;

    return WalletSummary(
      totalBalance:         data['total_balance'] as int,
      totalEarnedAllTime:   data['total_earned_all_time'] as int,
      totalRedeemedAllTime: data['total_redeemed_all_time'] as int,
      expiringSoon:         data['expiring_soon'] as int,
      bucketsCount:         data['buckets_count'] as int,
      currency:             (data['currency'] as String?) ?? 'AZN',
      buckets: (data['buckets'] as List)
          .map((e) => _BucketDto.fromJson(e as Map<String, dynamic>))
          .toList(),
      recentEntries: (data['recent_entries'] as List)
          .map((e) => _LedgerEntryDto.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }
}

final walletRepositoryProvider = Provider<WalletRepository>((ref) {
  return WalletRepository(ref.watch(apiClientProvider));
});

final walletProvider = FutureProvider.autoDispose<WalletSummary>((ref) {
  return ref.watch(walletRepositoryProvider).getWallet();
});
