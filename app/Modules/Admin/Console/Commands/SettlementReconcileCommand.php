<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Core\Enums\LedgerEntryType;
use App\Core\Models\Bucket;
use App\Core\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settlement reconciliation — gündəlik bucket vs ledger müqayisəsi.
 *
 * Məqsəd: Bucket cədvəlində saxlanan denormalized counter-lərin (balance,
 * earned_total, redeemed_total, expired_total) ledger_entries cədvəlindəki
 * immutable əsas həqiqətdən sürüşməsini aşkar etmək. Bucket counter-ləri
 * `LedgerService` tərəfindən eyni DB transaction-da yenilənir, lakin manual
 * SQL edit, racy migrasiya, və ya gələcək feature buglarından qaynaqlanan
 * sürüşmələrə qarşı **passiv qoruma** kimi gündəlik yoxlama lazımdır.
 *
 * Davranış:
 *  - Read-only: heç bir cədvəl dəyişdirilmir; yalnız audit log yazılır
 *    (mismatch və summary). Hər mismatch struktur-loq event-i olur,
 *    sonradan email/Slack alert-ə bağlana bilər (logging channel routing).
 *  - Çıxış kodu: 0 = mismatch yoxdur, 1 = mismatch tapıldı (cron alerting üçün).
 *
 * Düstur (audit invariant):
 *   expected.earned_total    = SUM(amount) WHERE type='earn'
 *   expected.redeemed_total  = SUM(amount) WHERE type='redeem'
 *   expected.expired_total   = SUM(amount) WHERE type='expire'
 *   expected.balance         = SUM(credits) - SUM(debits)
 *     credits = Earn, Adjustment, Transfer (LedgerEntryType::isCredit)
 *     debits  = Redeem, Refund, Reversal, Expire (LedgerEntryType::isDebit)
 *
 * Scope (--for):
 *  - all                 : bütün bucket-ləri yoxla (manual/recovery üçün).
 *  - today / yesterday / YYYY-MM-DD: yalnız o tarixdə `last_activity_at`
 *    olan bucket-ləri yoxla — gündəlik cron üçün məhdud iş yükü.
 *  Reconciliation HƏMİŞƏ cumulative-dir (bütün vaxt). --for yalnız "hansı
 *  bucket-ləri yoxlayaq" filtridir, hesablanma metoduna təsir etmir.
 */
class SettlementReconcileCommand extends Command
{
    protected $signature = 'loyalty:settlement-reconcile
                            {--for=yesterday : Reconcile scope-u (today|yesterday|YYYY-MM-DD|all)}
                            {--merchant= : Yalnız bir merchant ID-si üçün reconcile et}
                            {--dry-run : Audit log yazma, yalnız konsolda hesabat ver}';

    protected $description = 'Per-bucket counter-lər ilə ledger toplamı arasında uyğunluğu yoxlayır (read-only).';

    public function __construct(private readonly AuditLogger $audit)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // DB miqrasiya olunmayıb (məs. ilkin install): boş işlə bitir — cron-u
        // sıxlaşdırmamaq üçün xəta yox.
        if (! Schema::hasTable('buckets') || ! Schema::hasTable('ledger_entries')) {
            $this->warn('Settlement reconcile: `buckets` və ya `ledger_entries` cədvəli yoxdur, skip.');

            return self::SUCCESS;
        }

        $scope      = $this->resolveScope((string) $this->option('for'));
        $merchantId = $this->option('merchant') !== null ? (int) $this->option('merchant') : null;
        $dryRun     = (bool) $this->option('dry-run');

        $this->info(sprintf(
            'Settlement reconcile başladı (scope: %s%s%s)',
            $scope['label'],
            $merchantId !== null ? ", merchant_id={$merchantId}" : '',
            $dryRun ? ', dry-run' : '',
        ));

        $buckets = $this->collectBuckets($scope['date'], $merchantId);

        if ($buckets->isEmpty()) {
            $this->info('Settlement reconcile: yoxlanılacaq bucket yoxdur (scope boşdur).');

            if (! $dryRun) {
                $this->audit->log('loyalty.settlement_reconcile.completed', [
                    'scope'           => $scope['label'],
                    'merchant_id'     => $merchantId,
                    'buckets_checked' => 0,
                    'mismatches'      => 0,
                ]);
            }

            return self::SUCCESS;
        }

        $report = $this->reconcile($buckets);
        $this->renderReport($report);

        if (! $dryRun) {
            $this->audit->log('loyalty.settlement_reconcile.completed', [
                'scope'           => $scope['label'],
                'merchant_id'     => $merchantId,
                'buckets_checked' => $report['checked'],
                'mismatches'      => count($report['mismatches']),
            ]);

            foreach ($report['mismatches'] as $m) {
                $this->audit->log('loyalty.settlement_reconcile.mismatch', $m);
            }
        }

        return $report['mismatches'] === [] ? self::SUCCESS : 1;
    }

    /**
     * --for input-unu strukturlaşdırılmış scope-a çevir.
     *
     * @return array{label: string, date: ?CarbonImmutable}
     */
    private function resolveScope(string $for): array
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

        // YYYY-MM-DD — fail-fast: yanlış format ilə cron alarm-a düşür və
        // əməliyyat investigate olunur. Carbon `createFromFormat`-da uyğunsuz
        // input üçün özünün InvalidArgumentException-ını atır; biz onu öz
        // mesajımıza wrap edirik ki, operator nədən səhv olduğunu görsün.
        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $for);
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(
                "--for üçün etibarsız tarix formatı: '{$for}'. Gözlənilən: today|yesterday|all|YYYY-MM-DD.",
                previous: $e,
            );
        }
        if ($parsed === false) {
            throw new \InvalidArgumentException(
                "--for üçün etibarsız tarix formatı: '{$for}'. Gözlənilən: today|yesterday|all|YYYY-MM-DD."
            );
        }

        return ['label' => $parsed->toDateString(), 'date' => $parsed->startOfDay()];
    }

    /**
     * Scope üzrə bucket-ləri yığ.
     *
     * `--for=all` → bütün bucket-lər.
     * Tarix verilibsə → yalnız o gün `last_activity_at` olan bucket-lər.
     * `last_activity_at` null olan bucket-lər tarix scope-undan kənardır
     * (heç bir aktivlik olmayıb, mismatch baş verə bilməz).
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
     *
     * Performance: bütün relevant (user_id, merchant_id, type) cütlərini bir
     * GROUP BY sorğusunda yığırıq → bucket sayı qədər N+1 sorğu YOXDUR.
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
                // Naməlum enum case — bilərəkdən yox saymırıq, debit kimi say
                // (mühafizəkar: balansa təsirsiz aşağı dəyər verir).
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

    /**
     * Konsola insan-oxunaqlı hesabat ver. Cron-da output yalnız log faylına
     * gedir; lakin manual `php artisan` çağırışında operator üçün lazımdır.
     *
     * @param array{checked: int, mismatches: array<int, array<string, mixed>>} $report
     */
    private function renderReport(array $report): void
    {
        $mismatchCount = count($report['mismatches']);

        if ($mismatchCount === 0) {
            $this->info(sprintf(
                'Settlement reconcile uğurlu: %d bucket yoxlanıldı, mismatch yoxdur.',
                $report['checked'],
            ));

            return;
        }

        $this->error(sprintf(
            'Settlement reconcile MİSMATCH: %d / %d bucket-də fərq aşkarlandı.',
            $mismatchCount,
            $report['checked'],
        ));

        $rows = [];
        foreach ($report['mismatches'] as $m) {
            foreach ($m['diffs'] as $field => $d) {
                $rows[] = [
                    $m['bucket_id'],
                    $m['user_id'],
                    $m['merchant_id'],
                    $field,
                    $d['actual'],
                    $d['expected'],
                    $d['delta'],
                ];
            }
        }

        $this->table(
            ['bucket', 'user', 'merchant', 'field', 'actual', 'expected', 'delta'],
            $rows,
        );
    }
}
