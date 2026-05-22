class Merchant {
  const Merchant({
    required this.id,
    required this.code,
    required this.name,
    required this.category,
    this.tier,
  });

  final int id;
  final String code;
  final String name;
  final String category;
  final String? tier;
}

class Bucket {
  const Bucket({
    required this.id,
    required this.balance,
    required this.earnedTotal,
    required this.redeemedTotal,
    required this.lastActivityAt,
    required this.merchant,
  });

  final int id;
  final int balance;
  final int earnedTotal;
  final int redeemedTotal;
  final DateTime? lastActivityAt;
  final Merchant merchant;
}

enum LedgerType {
  earn, redeem, refund, reversal, expire, adjustment, transfer, unknown;

  static LedgerType fromString(String s) => switch (s) {
        'earn'       => LedgerType.earn,
        'redeem'     => LedgerType.redeem,
        'refund'     => LedgerType.refund,
        'reversal'   => LedgerType.reversal,
        'expire'     => LedgerType.expire,
        'adjustment' => LedgerType.adjustment,
        'transfer'   => LedgerType.transfer,
        _            => LedgerType.unknown,
      };

  bool get isCredit => switch (this) {
        LedgerType.earn || LedgerType.adjustment || LedgerType.transfer => true,
        _ => false,
      };

  String get label => switch (this) {
        LedgerType.earn       => 'Qazanma',
        LedgerType.redeem     => 'Xərcləmə',
        LedgerType.refund     => 'Refund',
        LedgerType.reversal   => 'Reversal',
        LedgerType.expire     => 'Müddəti bitib',
        LedgerType.adjustment => 'Düzəliş',
        LedgerType.transfer   => 'Transfer',
        LedgerType.unknown    => '—',
      };
}

class LedgerEntry {
  const LedgerEntry({
    required this.id,
    required this.uid,
    required this.type,
    required this.amount,
    required this.balanceAfter,
    required this.ref,
    required this.createdAt,
    required this.merchant,
  });

  final int id;
  final String uid;
  final LedgerType type;
  final int amount;
  final int balanceAfter;
  final String? ref;
  final DateTime createdAt;
  final Merchant? merchant;

  bool get isCredit => type.isCredit;
}

class WalletSummary {
  const WalletSummary({
    required this.totalBalance,
    required this.totalEarnedAllTime,
    required this.totalRedeemedAllTime,
    required this.expiringSoon,
    required this.bucketsCount,
    required this.buckets,
    required this.recentEntries,
    this.currency = 'AZN',
  });

  final int totalBalance;
  final int totalEarnedAllTime;
  final int totalRedeemedAllTime;
  final int expiringSoon;
  final int bucketsCount;
  final List<Bucket> buckets;
  final List<LedgerEntry> recentEntries;
  final String currency;
}
