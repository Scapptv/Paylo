<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Enums\LedgerEntryType;
use App\Core\Models\Bucket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Settlement reconciliation nüvəsi — per-bucket counter-lər (balance, earned_total,
 * redeemed_total, expired_total) ilə `ledger_entries` immutable toplamı arasında
 * uyğunluğu hesablayan SAF (side-effect-siz) servis.
 *
 * Bu məntiq əvvəllər yalnız `SettlementReconcileCommand` içində idi. Roadmap Phase 2.4-də
 * admin paneldən HTTP ilə də çağırıldığı üçün ortaq servisə çıxarıldı: həm CLI cron,
 * həm admin "İndi işlət" düyməsi EYNİ mənbədən hesablayır. Məntiqi dublikat etmək
 * drift riski yaradardı — ironik olaraq reconcile-ın özü məhz drift-i tutmaq üçündür.
 *
 * Düstur (audit invariant):
 *   expected.earned_total    = SUM(amount) WHERE type='earn'
 *   expected.redeemed_total  = SUM(amount) WHERE type='redeem'
 *   expected.expired_total   = SUM(amount) WHERE type='expire'
 *   expected.balance         = SUM(credits) - SUM(debits)
 *     credits = Earn, Adjustment, Transfer (LedgerEntryType::isCredit)
 *     debits  = Redeem, Refund, Reversal, Expire (LedgerEntryType::isDebit)
 *
 * Reconciliation HƏMİŞƏ cumulative-dir (bütün vaxt). Scope (--for / UI seçimi) yalnız
 * "hansı bucket-ləri yoxlayaq" filtridir, hesablanma metoduna təsir etmir.
 */
class SettlementReconciler
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Reconcile-ı icra et və strukturlaşdırılmış hesabat qaytar (side-effect yox —
     * audit yazılmır; onun üçün ayrıca `logCompletion`).
     *
     * @return array{
     *   tables_missing: bool,
     *   scope: string,
     *   merchant_id: int|null,
     *   checked: int,
     *   mismatches: array<int, array<string, mixed>>
     * }
     */
    public function run(string $for = 'yesterday', ?int $merchantId = null): array
    {
        // DB miqrasiya olunmayıb (məs. ilkin install): boş hesabatla bitir — xəta yox.
        if (! Schema::hasTable('buckets') || ! Schema::hasTable('ledger_entries')) {
            return [
                'tables_missing' => true,
                'scope'          => $for,
                'merchant_id'    => $merchantId,
                'checked'        => 0,
                'mismatches'     => [],
            ];
        }

        $scope   = $this->resolveScope($for);
        $buckets = $this->collectBuckets($scope['date'], $merchantId);

        if ($buckets->isEmpty()) {
            return [
                'tables_missing' => false,
                'scope'          => $scope['label'],
                'merchant_id'    => $merchantId,
                'checked'        => 0,
                'mismatches'     => [],
            ];
        }

        $result = $this->reconcile($buckets);

        return [
            'tables_missing' => false,
            'scope'          => $scope['label'],
            'merchant_id'    => $merchantId,
            'checked'        => $result['checked'],
            'mismatches'     => $result['mismatches'],
        ];
    }

    /**
     * Tamamlanmış hesabat üçün audit log yaz — CLI non-dry-run və HTTP "İndi işlət"
     * eyni izi buraxsın. Read-only baxış (UI index) bunu çağırMAMALIDIR.
     *
     * $request verilibsə (HTTP "İndi işlət"), audit aktor + IP-ni qeyd edir; CLI cron
     * üçün null qalır (aktor = Sistem — düzgün).
     *
     * @param array{scope: string, merchant_id: int|null, checked: int, mismatches: array<int, array<string, mixed>>} $report
     */
    public function logCompletion(array $report, ?Request $request = null): void
    {
        $this->audit->log('loyalty.settlement_reconcile.completed', [
            'scope'           => $report['scope'],
            'merchant_id'     => $report['merchant_id'],
            'buckets_checked' => $report['checked'],
            'mismatches'      => count($report['mismatches']),
        ], $request);

        foreach ($report['mismatches'] as $m) {
            $this->audit->log('loyalty.settlement_reconcile.mismatch', $m, $request);
        }
    }

    /**
     * --for input-unu strukturlaşdırılmış scope-a çevir.
     *
     * @return array{label: string, date: CarbonImmutable|null}
     */
    public function resolveScope(string $for): array
    {
        $for = strtolower(trim($for));

        if ($for === 'all') {
            return ['label' => 'all', 'date' => null];
        }
        if ($for === 'today') {
            return ['label' => 'today', 'date' => CarbonImmutable::now()->startOfDay()];
        }
        if ($for === 'yesterday' || $for === '') {
            return ['label' => 'yesterday', 'date' => CarbonImmutable::yesterday()];
        }

        // YYYY-MM-DD — fail-fast: yanlış format ilə cron alarm-a düşür və əməliyyat
        // investigate olunur.
        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $for);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidArgumentException(
                "--for üçün etibarsız tarix formatı: '{$for}'. Gözlənilən: today|yesterday|all|YYYY-MM-DD.",
                previous: $e,
            );
        }
        if ($parsed === false) {
            throw new InvalidArgumentException(
                "--for üçün etibarsız tarix formatı: '{$for}'. Gözlənilən: today|yesterday|all|YYYY-MM-DD."
            );
        }

        return ['label' => $parsed->toDateString(), 'date' => $parsed->startOfDay()];
    }

    /**
     * Scope üzrə bucket-ləri yığ. `--for=all` → bütün bucket-lər; tarix verilibsə →
     * yalnız o gün `last_activity_at` olan bucket-lər (null aktivlik scope-dan kənardır).
     *
     * @return Collection<int, Bucket>
     */
    private function collectBuckets(?CarbonImmutable $date, ?int $merchantId): Collection
    {
        $query = Bucket::query();

        if ($date !== null) {
            $query->whereDate('last_activity_at', $date->toDateString());
        }
        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Hər bucket üçün ledger-dən gözlənilən dəyəri hesabla, fərqi qeyd et.
     * Performance: bütün (user_id, merchant_id, type) cütlərini bir GROUP BY
     * sorğusunda yığırıq → N+1 yoxdur.
     *
     * @param Collection<int, Bucket> $buckets
     * @return array{checked: int, mismatches: array<int, array<string, mixed>>}
     */
    private function reconcile(Collection $buckets): array
    {
        $userIds     = $buckets->pluck('user_id')->unique()->values()->all();
        $merchantIds = $buckets->pluck('merchant_id')->unique()->values()->all();

        $aggregates = DB::table('ledger_entries')
            ->selectRaw('user_id, merchant_id, type, SUM(amount) as total')
            ->whereIn('user_id', $userIds)
            ->whereIn('merchant_id', $merchantIds)
            ->groupBy('user_id', 'merchant_id', 'type')
            ->get();

        // map[user_id][merchant_id][type_value] = total
        $map = [];
        foreach ($aggregates as $row) {
            $map[(int) $row->user_id][(int) $row->merchant_id][(string) $row->type] = (int) $row->total;
        }

        $mismatches = [];
        foreach ($buckets as $bucket) {
            $byType = $map[$bucket->user_id][$bucket->merchant_id] ?? [];

            $expected = $this->computeExpected($byType);

            $diffs = [];
            foreach (['balance', 'earned_total', 'redeemed_total', 'expired_total'] as $field) {
                $actualValue = (int) $bucket->$field;
                if ($actualValue !== $expected[$field]) {
                    $diffs[$field] = [
                        'actual'   => $actualValue,
                        'expected' => $expected[$field],
                        'delta'    => $actualValue - $expected[$field],
                    ];
                }
            }

            if ($diffs !== []) {
                $mismatches[] = [
                    'bucket_id'   => (int) $bucket->id,
                    'user_id'     => (int) $bucket->user_id,
                    'merchant_id' => (int) $bucket->merchant_id,
                    'diffs'       => $diffs,
                ];
            }
        }

        return ['checked' => $buckets->count(), 'mismatches' => $mismatches];
    }

    /**
     * Bir bucket üçün ledger-dən gözlənilən counter-ləri hesabla.
     *
     * @param array<string, int> $byType  type_value => SUM(amount)
     * @return array{balance: int, earned_total: int, redeemed_total: int, expired_total: int}
     */
    private function computeExpected(array $byType): array
    {
        $earned   = $byType[LedgerEntryType::Earn->value] ?? 0;
        $redeemed = $byType[LedgerEntryType::Redeem->value] ?? 0;
        $expired  = $byType[LedgerEntryType::Expire->value] ?? 0;

        $credits = 0;
        $debits  = 0;
        foreach ($byType as $typeValue => $sum) {
            $type = LedgerEntryType::tryFrom($typeValue);
            if ($type === null) {
                // Naməlum enum case — mühafizəkar: debit kimi say.
                $debits += $sum;

                continue;
            }

            if ($type->isCredit()) {
                $credits += $sum;
            } else {
                $debits += $sum;
            }
        }

        return [
            'balance'        => $credits - $debits,
            'earned_total'   => $earned,
            'redeemed_total' => $redeemed,
            'expired_total'  => $expired,
        ];
    }
}
