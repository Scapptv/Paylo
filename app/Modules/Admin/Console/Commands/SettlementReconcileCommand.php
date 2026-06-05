<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Core\Services\SettlementReconciler;
use Illuminate\Console\Command;

/**
 * Settlement reconciliation — gündəlik bucket vs ledger müqayisəsi (CLI cron giriş nöqtəsi).
 *
 * Hesablama məntiqi `App\Core\Services\SettlementReconciler`-dədir (roadmap Phase 2.4-də
 * admin panel HTTP wrapper-i ilə paylaşıla bilsin deyə çıxarıldı). Bu command yalnız:
 *  - CLI option-larını (--for / --merchant / --dry-run) parse edir,
 *  - servisi çağırır,
 *  - nəticəni konsola (operator üçün) render edir,
 *  - non-dry-run halında audit log yazdırır (servis `logCompletion`).
 *
 * Davranış:
 *  - Read-only: heç bir cədvəl dəyişdirilmir; yalnız audit log yazılır.
 *  - Çıxış kodu: 0 = mismatch yoxdur, 1 = mismatch tapıldı (cron alerting üçün).
 *  - --dry-run: audit yazmaz, lakin exit kodu yenə mismatch əsasında qaytarılır.
 */
class SettlementReconcileCommand extends Command
{
    protected $signature = 'loyalty:settlement-reconcile
                            {--for=yesterday : Reconcile scope-u (today|yesterday|YYYY-MM-DD|all)}
                            {--merchant= : Yalnız bir merchant ID-si üçün reconcile et}
                            {--dry-run : Audit log yazma, yalnız konsolda hesabat ver}';

    protected $description = 'Per-bucket counter-lər ilə ledger toplamı arasında uyğunluğu yoxlayır (read-only).';

    public function handle(SettlementReconciler $reconciler): int
    {
        $for        = (string) $this->option('for');
        $merchantId = $this->option('merchant') !== null ? (int) $this->option('merchant') : null;
        $dryRun     = (bool) $this->option('dry-run');

        // Hesablama servisdə. Etibarsız --for tarixi burada throw edir (cron alarm).
        $report = $reconciler->run($for, $merchantId);

        if ($report['tables_missing']) {
            $this->warn('Settlement reconcile: `buckets` və ya `ledger_entries` cədvəli yoxdur, skip.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Settlement reconcile başladı (scope: %s%s%s)',
            $report['scope'],
            $merchantId !== null ? ", merchant_id={$merchantId}" : '',
            $dryRun ? ', dry-run' : '',
        ));

        if ($report['checked'] === 0) {
            $this->info('Settlement reconcile: yoxlanılacaq bucket yoxdur (scope boşdur).');

            if (! $dryRun) {
                $reconciler->logCompletion($report);
            }

            return self::SUCCESS;
        }

        $this->renderReport($report);

        if (! $dryRun) {
            $reconciler->logCompletion($report);
        }

        return $report['mismatches'] === [] ? self::SUCCESS : 1;
    }

    /**
     * Konsola insan-oxunaqlı hesabat ver. Cron-da output yalnız log faylına gedir;
     * lakin manual `php artisan` çağırışında operator üçün lazımdır.
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
