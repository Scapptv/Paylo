/**
 * Bütün rollarda istifadə olunan format funksiyaları.
 * Backend amount-ları integer (qəpik) qaytarır — frontend bölüb format edir.
 */
export function useFormat() {
    /** 5840 → "58.40 AZN" */
    const azn = (raw) => {
        if (raw === null || raw === undefined) return '— AZN';
        const value = Number(raw) / 100;
        return value.toLocaleString('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }) + ' AZN';
    };

    /** 1234567 → "1.2M", 4208 → "4.2k" */
    const compact = (n) => {
        if (n === null || n === undefined) return '0';
        n = Number(n);
        if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
        if (n >= 1_000)     return (n / 1_000).toFixed(1) + 'k';
        return n.toLocaleString('en-US');
    };

    const aznCompact = (raw) => {
        if (raw === null || raw === undefined) return '— AZN';
        return compact(Number(raw) / 100) + ' AZN';
    };

    const date = (iso) => {
        if (!iso) return '—';
        return new Date(iso).toLocaleString('az-AZ', {
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit',
        });
    };

    const relativeTime = (iso) => {
        if (!iso) return '—';
        const diff = (Date.now() - new Date(iso).getTime()) / 1000;
        if (diff < 60)      return Math.floor(diff) + ' san əvvəl';
        if (diff < 3600)    return Math.floor(diff / 60) + ' dəq əvvəl';
        if (diff < 86400)   return Math.floor(diff / 3600) + ' saat əvvəl';
        if (diff < 604800)  return Math.floor(diff / 86400) + ' gün əvvəl';
        return date(iso);
    };

    return { azn, compact, aznCompact, date, relativeTime };
}
