<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\LedgerEntryType;
use App\Core\Enums\MerchantStatus;
use App\Core\Enums\UserRole;
use App\Core\Models\Bucket;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Analytics — admin paneli üçün dərin metrikləri IMMUTABLE LEDGER-dən KANONİK
 * hesablayan servis (roadmap Phase 4.1).
 *
 * KANONİK QAYDALAR (LedgerEntryType, dəyişməz):
 *   credit (isCredit) = Earn, Adjustment, Transfer
 *   debit  (isDebit)  = Redeem, Refund, Reversal, Expire
 *   liability (outstanding bonus) = Σcredits − Σdebits      [kumulativ]
 *   amount = integer qəpik (HEÇ VAXT float); AZN = qəpik / 100 yalnız UI-da
 *   ledger_entries = həqiqətin tək mənbəyi (bucket counter-ləri ondan törəyir)
 *
 * Bütün hesablamalar bu düsturdan çıxır. Tip təsnifatı PHP-də
 * `LedgerEntryType::isCredit()` ilə edilir (DB-spesifik CASE yox) —
 * `SettlementReconciler` ilə eyni kanonik yanaşma. Servis yalnız xam integer
 * qəpik qaytarır; faiz/delta/format frontend-də hesablanır.
 *
 * Kanonik invariant (testlə sübut olunur): `liabilityTrend`-in son dəyəri
 * cari `Σ bucket.balance`-a bərabərdir — ledger-dən rekonstruksiya = denormalized
 * balans. Bu, analitikanın uydurma deyil, ledger-əsaslı olduğunun zəmanətidir.
 */
class AnalyticsService
{
    /**
     * Tam analitika dəsti: KPI-lar + günlük axın + liability trendi + tip bölgüsü
     * + top merchant-lər. `$days` pəncərəsi (1..365, default 30).
     *
     * @return array<string, mixed>
     */
    public function overview(int $days = 30): array
    {
        $days      = max(1, min($days, 365));
        $now       = CarbonImmutable::now();
        $start     = $now->subDays($days - 1)->startOfDay();
        $prevStart = $start->subDays($days);

        return [
            'days'           => $days,
            'kpis'           => $this->kpis($start, $prevStart),
            'dailyFlow'      => $this->dailyFlow($start, $now),
            'liabilityTrend' => $this->liabilityTrend($start, $now),
            'typeBreakdown'  => $this->typeBreakdown($start),
            'topMerchants'   => $this->topMerchants($start),
        ];
    }

    /**
     * Başlıq KPI-ları — hamısı xam integer qəpik / say. Faiz və delta frontend-də.
     *
     * @return array<string, int>
     */
    private function kpis(CarbonImmutable $start, CarbonImmutable $prevStart): array
    {
        return [
            // Cari outstanding liability — kanonik balans (Σcredits−Σdebits), bucket-lərdə saxlanılır.
            'liability'         => (int) Bucket::sum('balance'),

            // Kumulativ (bütün vaxt) earn / redeem.
            'earnedAll'         => $this->sumByType(LedgerEntryType::Earn),
            'redeemedAll'       => $this->sumByType(LedgerEntryType::Redeem),

            // Cari period (start..now) — delta üçün.
            'earnedPeriod'      => $this->sumByType(LedgerEntryType::Earn, $start),
            'redeemedPeriod'    => $this->sumByType(LedgerEntryType::Redeem, $start),

            // Əvvəlki period (prevStart..start) — period-over-period müqayisə.
            'earnedPrev'        => $this->sumByType(LedgerEntryType::Earn, $prevStart, $start),
            'redeemedPrev'      => $this->sumByType(LedgerEntryType::Redeem, $prevStart, $start),

            // Kontekst sayları.
            'activeCustomers'   => (int) User::where('role', UserRole::Customer)
                ->whereHas('buckets', fn ($q) => $q->where('balance', '>', 0))
                ->count(),
            'activeMerchants'   => (int) Merchant::where('status', MerchantStatus::Active)->count(),
            'ledgerEntries'     => (int) DB::table('ledger_entries')->where('created_at', '>=', $start)->count(),
        ];
    }

    /**
     * Bir tipin amount cəmi (integer qəpik). İstəyə görə [from, to) zaman pəncərəsi.
     */
    private function sumByType(LedgerEntryType $type, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        $q = DB::table('ledger_entries')->where('type', $type->value);

        if ($from !== null) {
            $q->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $q->where('created_at', '<', $to);
        }

        return (int) $q->sum('amount');
    }

    /**
     * Günlük earn vs redeem axını (xətt qrafiki üçün) — sıfır-dolu, start..now.
     *
     * @return array<int, array{date: string, earned: int, redeemed: int}>
     */
    private function dailyFlow(CarbonImmutable $start, CarbonImmutable $now): array
    {
        $rows = DB::table('ledger_entries')
            ->selectRaw('DATE(created_at) as d, type, SUM(amount) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('d', 'type')
            ->get();

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->d][(string) $r->type] = (int) $r->total;
        }

        $out    = [];
        $cursor = $start->startOfDay();
        $end    = $now->startOfDay();
        while ($cursor <= $end) {
            $key = $cursor->toDateString();
            $out[] = [
                'date'     => $key,
                'earned'   => $map[$key][LedgerEntryType::Earn->value] ?? 0,
                'redeemed' => $map[$key][LedgerEntryType::Redeem->value] ?? 0,
            ];
            $cursor = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Kumulativ outstanding liability trendi — KANONİK: hər günə qədər Σcredits−Σdebits.
     *
     * Pəncərədən əvvəlki bütün ledger-dən baseline götürülür, sonra hər gün net
     * dəyişim əlavə olunur. Son dəyər cari `Σ bucket.balance`-a bərabər olmalıdır.
     *
     * @return array<int, array{date: string, liability: int}>
     */
    private function liabilityTrend(CarbonImmutable $start, CarbonImmutable $now): array
    {
        // Baseline: pəncərə başlamazdan əvvəlki kumulativ liability.
        $liability = 0;
        $baseRows  = DB::table('ledger_entries')
            ->selectRaw('type, SUM(amount) as total')
            ->where('created_at', '<', $start)
            ->groupBy('type')
            ->get();
        foreach ($baseRows as $r) {
            $liability += $this->signedAmount((string) $r->type, (int) $r->total);
        }

        // Pəncərə daxili günlük net dəyişim.
        $rows = DB::table('ledger_entries')
            ->selectRaw('DATE(created_at) as d, type, SUM(amount) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('d', 'type')
            ->get();
        $delta = [];
        foreach ($rows as $r) {
            $key         = (string) $r->d;
            $delta[$key] = ($delta[$key] ?? 0) + $this->signedAmount((string) $r->type, (int) $r->total);
        }

        $out    = [];
        $cursor = $start->startOfDay();
        $end    = $now->startOfDay();
        while ($cursor <= $end) {
            $key        = $cursor->toDateString();
            $liability += $delta[$key] ?? 0;
            $out[]      = ['date' => $key, 'liability' => $liability];
            $cursor     = $cursor->addDay();
        }

        return $out;
    }

    /**
     * Tip üzrə bölgü (period) — hər LedgerEntryType üçün cəm + say + credit/debit axını.
     *
     * @return array<int, array{type: string, label: string, total: int, count: int, flow: string}>
     */
    private function typeBreakdown(CarbonImmutable $start): array
    {
        $rows = DB::table('ledger_entries')
            ->selectRaw('type, SUM(amount) as total, COUNT(*) as cnt')
            ->where('created_at', '>=', $start)
            ->groupBy('type')
            ->get();

        $out = [];
        foreach (LedgerEntryType::cases() as $type) {
            $row   = $rows->firstWhere('type', $type->value);
            $out[] = [
                'type'  => $type->value,
                'label' => $type->label(),
                'total' => $row !== null ? (int) $row->total : 0,
                'count' => $row !== null ? (int) $row->cnt : 0,
                'flow'  => $type->isCredit() ? 'credit' : 'debit',
            ];
        }

        return $out;
    }

    /**
     * Top merchant-lər — cari liability (kanonik bucket balansı) üzrə sıralı,
     * period earn/redeem həcmi ilə.
     *
     * @return array<int, array{id: int, code: string, name: string, category: string|null, liability: int, earned: int, redeemed: int}>
     */
    private function topMerchants(CarbonImmutable $start, int $limit = 6): array
    {
        $liab = Bucket::query()
            ->selectRaw('merchant_id, SUM(balance) as liability')
            ->groupBy('merchant_id')
            ->pluck('liability', 'merchant_id');

        $vol = DB::table('ledger_entries')
            ->selectRaw('merchant_id, type, SUM(amount) as total')
            ->where('created_at', '>=', $start)
            ->groupBy('merchant_id', 'type')
            ->get();
        $earnVol = [];
        $redVol  = [];
        foreach ($vol as $r) {
            if ((string) $r->type === LedgerEntryType::Earn->value) {
                $earnVol[(int) $r->merchant_id] = (int) $r->total;
            }
            if ((string) $r->type === LedgerEntryType::Redeem->value) {
                $redVol[(int) $r->merchant_id] = (int) $r->total;
            }
        }

        $out = Merchant::query()->get(['id', 'code', 'name', 'category'])
            ->map(fn (Merchant $m) => [
                'id'        => (int) $m->id,
                'code'      => $m->code,
                'name'      => $m->name,
                'category'  => $m->category,
                'liability' => (int) ($liab[$m->id] ?? 0),
                'earned'    => $earnVol[$m->id] ?? 0,
                'redeemed'  => $redVol[$m->id] ?? 0,
            ])
            ->sortByDesc('liability')
            ->take($limit)
            ->values()
            ->all();

        return $out;
    }

    /**
     * Bir tipin işarələnmiş kanonik töhfəsi: credit → +amount, debit → −amount.
     * Naməlum enum case mühafizəkar olaraq debit sayılır (balansa təsirsiz aşağı).
     */
    private function signedAmount(string $typeValue, int $amount): int
    {
        $type = LedgerEntryType::tryFrom($typeValue);

        if ($type === null) {
            return -$amount;
        }

        return $type->isCredit() ? $amount : -$amount;
    }
}
