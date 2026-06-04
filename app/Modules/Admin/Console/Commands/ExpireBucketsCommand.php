<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Core\Enums\LedgerEntryType;
use App\Core\Models\Bucket;
use App\Core\Models\LedgerEntry;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Core\Services\LedgerService;
use App\Core\ValueObjects\BonusValue;
use App\Modules\Api\Services\WebhookSender;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Bucket expiration job — vaxtı keçmiş bonus balansları silir.
 *
 * Qayda: Əgər bucket-in son `last_activity_at` dəyəri `expire_after_days`
 * gündən köhnədirsə, mövcud `balance` Expire tipli ledger entry kimi yazılır
 * (debit) və bucket balansı sıfıra düşür. Bucket sətri saxlanır (counter-lər
 * `expired_total`-da toplanır).
 *
 * Atomic: hər bucket öz mini-transaction-undadır. Bir bucket xəta verərsə,
 * digərləri davam edir; ümumi hesabatda failed_count göstərilir.
 *
 * Schedule: `routes/console.php`-də hər gecə 03:00. Manual run-da `--dry-run`
 * yalnız hesabat verir, ledger toxunmur.
 */
class ExpireBucketsCommand extends Command
{
    protected $signature = 'loyalty:expire-buckets
                            {--dry-run : Yalnız hesabat ver, ledger yazma}
                            {--merchant= : Yalnız bir merchant ID-si üçün işlət}
                            {--days= : `expire_after_days` config dəyərini override et}';

    protected $description = 'Vaxtı keçmiş bucket balansını Expire entry kimi yazır və sıfırlayır.';

    public function __construct(
        private readonly LedgerService $ledger,
        private readonly AuditLogger $audit,
        private readonly WebhookSender $webhooks,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('buckets') || ! Schema::hasTable('ledger_entries')) {
            $this->warn('Expire-buckets: zəruri cədvəllər yoxdur, skip.');
            return self::SUCCESS;
        }

        $dryRun     = (bool) $this->option('dry-run');
        $merchantId = $this->option('merchant') !== null ? (int) $this->option('merchant') : null;
        $days       = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('loyalty.expire_after_days', 365);

        if ($days <= 0) {
            $this->error("--days müsbət tam ədəd olmalıdır, alındı: {$days}.");
            return self::FAILURE;
        }

        $threshold = Carbon::now()->subDays($days);

        $this->info(sprintf(
            'Expire-buckets başladı: threshold=%s, days=%d%s%s',
            $threshold->toIso8601String(),
            $days,
            $merchantId !== null ? ", merchant_id={$merchantId}" : '',
            $dryRun ? ' (DRY-RUN)' : '',
        ));

        $query = Bucket::query()
            ->where('balance', '>', 0)
            ->whereNotNull('last_activity_at')
            ->where('last_activity_at', '<', $threshold);

        if ($merchantId !== null) {
            $query->where('merchant_id', $merchantId);
        }

        $buckets = $query->orderBy('id')->get();

        if ($buckets->isEmpty()) {
            $this->info('Expire-buckets: vaxtı keçən bucket tapılmadı.');

            if (! $dryRun) {
                $this->audit->log('loyalty.expire_buckets.completed', [
                    'threshold' => $threshold->toIso8601String(),
                    'expired'   => 0,
                    'failed'    => 0,
                ]);
            }

            return self::SUCCESS;
        }

        $expired  = 0;
        $failed   = 0;
        $totalAmt = 0;
        $rows     = [];

        foreach ($buckets as $bucket) {
            $amount = (int) $bucket->balance;

            if ($dryRun) {
                $expired++;
                $totalAmt += $amount;
                $rows[] = [
                    $bucket->id,
                    $bucket->user_id,
                    $bucket->merchant_id,
                    $amount,
                    optional($bucket->last_activity_at)->toDateString(),
                ];
                continue;
            }

            try {
                $this->expireOne($bucket, $amount);
                $expired++;
                $totalAmt += $amount;
                $rows[] = [
                    $bucket->id,
                    $bucket->user_id,
                    $bucket->merchant_id,
                    $amount,
                    optional($bucket->last_activity_at)->toDateString(),
                ];

                // POSNET-ə bildir: customer-in cari bucket-i sıfırlandı.
                // Webhook uğursuzluğu cron-u sındırmamalıdır — try/catch ilə qoru.
                try {
                    $this->webhooks->emit(
                        (int) $bucket->merchant_id,
                        'bucket_expire',
                        [
                            'bucket_id'     => $bucket->id,
                            'merchant_id'   => $bucket->merchant_id,
                            'customer_id'   => $bucket->user_id,
                            'amount_expired_cents' => $amount,
                            'new_balance'   => 0,
                            'threshold'     => $threshold->toIso8601String(),
                            'expired_at'    => now()->toIso8601String(),
                        ],
                    );
                } catch (\Throwable $w) {
                    Log::warning('loyalty.expire_buckets.webhook_failed', [
                        'bucket_id' => $bucket->id,
                        'error'     => $w->getMessage(),
                    ]);
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error('loyalty.expire_buckets.bucket_failed', [
                    'bucket_id'   => $bucket->id,
                    'user_id'     => $bucket->user_id,
                    'merchant_id' => $bucket->merchant_id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $this->renderReport($rows, $expired, $failed, $totalAmt, $dryRun);

        if (! $dryRun) {
            $this->audit->log('loyalty.expire_buckets.completed', [
                'threshold'    => $threshold->toIso8601String(),
                'expired'      => $expired,
                'failed'       => $failed,
                'total_amount' => $totalAmt,
                'merchant_id'  => $merchantId,
            ]);
        }

        return $failed === 0 ? self::SUCCESS : 1;
    }

    /**
     * Bir bucket üçün atomic expire — Expire entry yazır, balansı sıfırlayır,
     * counter-i artırır.
     */
    private function expireOne(Bucket $bucket, int $amount): void
    {
        DB::transaction(function () use ($bucket, $amount): void {
            $customer = User::findOrFail($bucket->user_id);
            $merchant = Merchant::findOrFail($bucket->merchant_id);

            // LedgerService daxilində writeEntry hash chain yazır + bucket-lər
            // yenilənir. Lakin Expire metodumuz yoxdur — manual writeEntry
            // çağırışına ehtiyac var. Audit invariant: Expire DEBIT-dir, balansı
            // azaldır, expired_total artırır.
            //
            // Servisdə yeni metod əlavə etməkdənsə, public expire() method-u
            // yaratmaq daha təmiz dizayndır.
            $this->ledger->expire(
                customer: $customer,
                merchant: $merchant,
                amount:   new BonusValue($amount),
                reason:   'auto_expire_after_inactivity',
            );
        });
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    private function renderReport(array $rows, int $expired, int $failed, int $totalAmt, bool $dryRun): void
    {
        $this->info(sprintf(
            '%s%d bucket %s, cəm məbləğ: %d qəpik, uğursuz: %d.',
            $dryRun ? '[DRY-RUN] ' : '',
            $expired,
            $dryRun ? 'expire ediləcək' : 'expire edildi',
            $totalAmt,
            $failed,
        ));

        if ($rows !== []) {
            $this->table(
                ['bucket', 'user', 'merchant', 'amount', 'last_activity'],
                array_slice($rows, 0, 20),
            );
            if (count($rows) > 20) {
                $this->info('... və ' . (count($rows) - 20) . ' bucket də.');
            }
        }
    }
}
