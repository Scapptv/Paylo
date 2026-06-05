import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:paylo/core/api/api_client.dart';
import 'package:paylo/core/errors/api_exception.dart';
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
    return parse(res.data);
  }

  /// Audit 2026-06-04 MOB-4: qorunmuş + test-edilə bilən parse. Səhv/əskik sahə
  /// TypeError yox, tipli `ServerException` atır (UI daimi error state göstərir,
  /// crash yox). Boş və ya `total_balance`-siz body etibarsız sayılır — server
  /// xətasını "0 AZN" kimi yanıldıcı balansla gizlətməmək üçün açıq xəta veririk.
  static WalletSummary parse(Map<String, dynamic>? data) {
    if (data == null || data['total_balance'] == null) {
      throw const ServerException('Wallet məlumatı oxuna bilmədi (gözlənilməz format).');
    }
    try {
      return WalletSummary(
        totalBalance:         _int(data['total_balance']),
        totalEarnedAllTime:   _int(data['total_earned_all_time']),
        totalRedeemedAllTime: _int(data['total_redeemed_all_time']),
        expiringSoon:         _int(data['expiring_soon']),
        bucketsCount:         _int(data['buckets_count']),
        currency:             (data['currency'] as String?) ?? 'AZN',
        buckets: ((data['buckets'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(_BucketDto.fromJson)
            .toList(),
        recentEntries: ((data['recent_entries'] as List?) ?? const [])
            .whereType<Map<String, dynamic>>()
            .map(_LedgerEntryDto.fromJson)
            .toList(),
      );
    } on ApiException {
      rethrow;
    } catch (_) {
      throw const ServerException('Wallet məlumatı oxuna bilmədi (gözlənilməz format).');
    }
  }

  static int _int(dynamic v) => (v as num?)?.toInt() ?? 0;
}

final walletRepositoryProvider = Provider<WalletRepository>((ref) {
  return WalletRepository(ref.watch(apiClientProvider));
});

final walletProvider = FutureProvider.autoDispose<WalletSummary>((ref) {
  return ref.watch(walletRepositoryProvider).getWallet();
});
