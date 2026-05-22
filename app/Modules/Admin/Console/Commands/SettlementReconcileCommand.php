<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Settlement reconciliation — skeleton.
 *
 * Məqsəd: gündəlik per-merchant ledger cəminin (sum of entries) və
 * Bucket aggregate snapshot-larının tutarlı olduğunu yoxlamaq, fərqlərə
 * görə alert yaratmaq.
 *
 * Hələ implement edilməyib — schedule-a qoşulub ki, prod-da boş qalmasın.
 */
class SettlementReconcileCommand extends Command
{
    protected $signature = 'loyalty:settlement-reconcile
                            {--for=yesterday : Reconcile etmək üçün tarix (today|yesterday|YYYY-MM-DD)}';

    protected $description = 'Per-merchant ledger toplamı və bucket snapshot-ları arasındakı uyğunluğu yoxlayır.';

    public function handle(): int
    {
        $for = (string) $this->option('for');

        Log::info('loyalty:settlement-reconcile invoked', [
            'for'       => $for,
            'timestamp' => now()->toIso8601String(),
            'note'      => 'no-op skeleton; biznes məntiqi hələ tamamlanmayıb',
        ]);

        $this->info('Settlement reconcile: skeleton işlədi (no-op).');

        return self::SUCCESS;
    }
}
