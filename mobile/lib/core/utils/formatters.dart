import 'package:intl/intl.dart';

/// Backend amount-ları raw integer (qəpik) qaytarır. UI formatlayır.
abstract final class AppFormat {
  /// 5840 → "58.40 AZN"
  static String azn(int? raw) {
    if (raw == null) return '— AZN';
    final value = raw / 100;
    final formatter = NumberFormat('#,##0.00', 'en_US');
    return '${formatter.format(value)} AZN';
  }

  /// 1234567 (qəpik) → "12.3k AZN"
  static String aznCompact(int? raw) {
    if (raw == null) return '— AZN';
    final value = raw / 100;
    if (value >= 1_000_000) return '${(value / 1_000_000).toStringAsFixed(1)}M AZN';
    if (value >= 1_000)     return '${(value / 1_000).toStringAsFixed(1)}k AZN';
    return azn(raw);
  }

  /// Raw integer → "5.00" (AZN olmadan, input field-lərdə)
  static String rawToAznInput(int raw) => (raw / 100).toStringAsFixed(2);

  /// 1234 → "1,234"
  static String compact(int? n) {
    if (n == null) return '0';
    if (n >= 1_000_000) return '${(n / 1_000_000).toStringAsFixed(1)}M';
    if (n >= 1_000)     return '${(n / 1_000).toStringAsFixed(1)}k';
    return NumberFormat('#,##0', 'en_US').format(n);
  }

  /// "2026-05-15T18:22:00Z" → "5 dəq əvvəl" və ya tarix
  static String relative(DateTime? dt) {
    if (dt == null) return '—';
    final diff = DateTime.now().difference(dt);
    if (diff.inSeconds < 60) return '${diff.inSeconds} san əvvəl';
    if (diff.inMinutes < 60) return '${diff.inMinutes} dəq əvvəl';
    if (diff.inHours   < 24) return '${diff.inHours} saat əvvəl';
    if (diff.inDays    <  7) return '${diff.inDays} gün əvvəl';
    return date(dt);
  }

  /// "15 may 2026, 18:22"
  static String date(DateTime? dt) {
    if (dt == null) return '—';
    return DateFormat('dd MMM yyyy, HH:mm', 'az_AZ').format(dt.toLocal());
  }
}
