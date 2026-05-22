<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Loyalty bucket expiration job — skeleton.
 *
 * Məqsəd: hər bucket üçün, müəyyən müddət (məs: 365 gün) hərəkətsiz qalan
 * `balance`-i `Expire` tipli ledger entry kimi yazıb sıfırlamaq.
 *
 * Hələ biznes qaydası (TTL, qrejim, partial expire) layihə fazasında olduğu
 * üçün bu komand TƏHLÜKƏSİZ no-op-dur: yalnız log yazır və zəro rows
 * affected qaytarır. Schedule-a bağlıdır ki, infrastructure-i sınansın.
 */
class ExpireBucketsCommand extends Command
{
    protected $signature = 'loyalty:expire-buckets
                            {--dry-run : Heç nəyi dəyişmə, yalnız hesabat ver}';

    protected $description = 'Mövcud bucket-lərdə vaxtı keçmiş balansları (Expire) ledger entry kimi yazır.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        Log::info('loyalty:expire-buckets invoked', [
            'dry_run'   => $dryRun,
            'timestamp' => now()->toIso8601String(),
            'note'      => 'no-op skeleton; biznes məntiqi hələ tamamlanmayıb',
        ]);

        $this->info('Expire-buckets: skeleton işlədi (no-op). Heç bir bucket dəyişmədi.');

        return self::SUCCESS;
    }
}
