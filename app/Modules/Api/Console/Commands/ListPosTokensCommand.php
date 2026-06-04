<?php

declare(strict_types=1);

namespace App\Modules\Api\Console\Commands;

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * POS API token-lərini sıralayır — operator yığılmış token-ləri görə bilsin,
 * istifadə olunmayanları aşkar etsin, vaxtı keçənləri təmizləsin.
 *
 * Plain-text token DEYİL — yalnız id, ad, ability, expires_at, last_used_at.
 *
 * Misal:
 *   php artisan pos:list-tokens
 *   php artisan pos:list-tokens --merchant=m_412
 *   php artisan pos:list-tokens --expired
 */
final class ListPosTokensCommand extends Command
{
    protected $signature = 'pos:list-tokens
                            {--merchant= : Yalnız konkret merchant-ın token-ləri}
                            {--expired : Yalnız vaxtı keçmiş token-lər}
                            {--unused-days= : Son N günü istifadə olunmamışlar}';

    protected $description = 'POS API token-lərinin siyahısı (plain-text yox, audit metadata).';

    public function handle(): int
    {
        $merchantCode = (string) ($this->option('merchant') ?? '');
        $expiredOnly  = (bool) $this->option('expired');
        $unusedDays   = $this->option('unused-days');

        // POS terminal user-lərini tap (`pos@<code>.api` schema-sı).
        $usersQuery = User::where('role', UserRole::PosTerminal)
            ->where('email', 'like', 'pos@%.api');

        if ($merchantCode !== '') {
            $merchant = Merchant::where('code', $merchantCode)->first();
            if ($merchant === null) {
                $this->error("Merchant '{$merchantCode}' tapılmadı.");

                return self::FAILURE;
            }
            $usersQuery->where('merchant_id', $merchant->id);
        }

        $users = $usersQuery->with('merchant:id,code,name')->get();

        if ($users->isEmpty()) {
            $this->line('Heç bir POS integration user mövcud deyil.');

            return self::SUCCESS;
        }

        $rows = [];
        $now  = Carbon::now();

        foreach ($users as $user) {
            $tokensQuery = $user->tokens();
            $tokens      = $tokensQuery->orderByDesc('id')->get();

            foreach ($tokens as $token) {
                $expiresAt = $token->expires_at;
                $isExpired = $expiresAt !== null && $expiresAt->lt($now);

                if ($expiredOnly && ! $isExpired) {
                    continue;
                }

                if ($unusedDays !== null) {
                    $threshold = $now->copy()->subDays((int) $unusedDays);
                    if ($token->last_used_at !== null && $token->last_used_at->gt($threshold)) {
                        continue;
                    }
                }

                $rows[] = [
                    'id'           => $token->id,
                    'merchant'     => $user->merchant?->code ?? '-',
                    'name'         => $token->name,
                    'abilities'    => implode(',', (array) $token->abilities),
                    'last_used'    => $token->last_used_at?->toDateTimeString() ?? 'never',
                    'expires_at'   => $expiresAt?->toDateTimeString() ?? 'no-expiry',
                    'status'       => $isExpired ? 'EXPIRED' : 'active',
                ];
            }
        }

        if ($rows === []) {
            $this->line('Filter şərtlərinə uyğun token tapılmadı.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Merchant', 'Ad', 'Abilities', 'Son istifadə', 'Bitir', 'Status'],
            $rows,
        );

        $this->line('Cəmi: ' . count($rows) . ' token. Plain-text token YALNIZ issuance zamanı göstərilir.');

        return self::SUCCESS;
    }
}
