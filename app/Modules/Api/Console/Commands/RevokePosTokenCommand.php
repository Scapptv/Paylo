<?php

declare(strict_types=1);

namespace App\Modules\Api\Console\Commands;

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * POS API token-i revoke edir — incident response zamanı tez bir əmr ilə token-i
 * etibarsızlaşdırma. Növbəti API çağırışı 401 qaytarır.
 *
 * İki istifadə formatı:
 *   1. ID ilə (dəqiq):       php artisan pos:revoke-token --id=42
 *   2. Merchant+ad ilə:      php artisan pos:revoke-token --merchant=m_412 --name=bravo-pos-01
 *
 * `--force` interaktiv təsdiq sorğusunu atlayır (avtomatlaşma üçün).
 * Audit event: `api.pos.token.revoked` — operator id, token id, səbəb (varsa).
 */
final class RevokePosTokenCommand extends Command
{
    protected $signature = 'pos:revoke-token
                            {--id= : Token ID-si (pos:list-tokens çıxışından)}
                            {--merchant= : Merchant kodu (--name ilə birlikdə)}
                            {--name= : Token adı (--merchant ilə birlikdə)}
                            {--reason= : Səbəb (audit log üçün)}
                            {--force : Təsdiq sorğusunu atla}';

    protected $description = 'POS API token-ini revoke edir (DB-dən silir, dərhal 401 verir).';

    public function handle(AuditLogger $audit): int
    {
        $id           = $this->option('id');
        $merchantCode = (string) ($this->option('merchant') ?? '');
        $name         = (string) ($this->option('name') ?? '');
        $reason       = $this->option('reason');
        $force        = (bool) $this->option('force');

        $token = $this->resolveToken($id, $merchantCode, $name);
        if ($token === null) {
            return self::FAILURE;
        }

        $ownerId    = (int) $token->tokenable_id;
        $owner      = User::find($ownerId);
        $merchantId = $owner?->merchant_id;

        $summary = sprintf(
            'Token ID=%d, ad="%s", merchant=%d, abilities=[%s], son istifadə=%s',
            $token->id,
            $token->name,
            $merchantId ?? 0,
            implode(',', (array) $token->abilities),
            $token->last_used_at?->toDateTimeString() ?? 'never',
        );

        $this->warn($summary);

        if (! $force && ! $this->confirm('Bu token-i revoke etmək istədiyinizə əminsiniz?', false)) {
            $this->line('Revoke ləğv edildi.');

            return self::SUCCESS;
        }

        $tokenId   = (int) $token->id;
        $tokenName = (string) $token->name;
        $abilities = (array) $token->abilities;

        $token->delete();

        $audit->log('api.pos.token.revoked', [
            'token_id'    => $tokenId,
            'token_name'  => $tokenName,
            'merchant_id' => $merchantId,
            'abilities'   => $abilities,
            'reason'      => $reason,
        ]);

        $this->info("Token #{$tokenId} ('{$tokenName}') silindi. Növbəti API çağırışı 401 qaytaracaq.");

        return self::SUCCESS;
    }

    /**
     * Token-i ya id, ya da merchant+ad ilə tapır. Fail-fast: heç biri verilmirsə,
     * və ya hər ikisi səhvdirsə açıq error qaytarır (silent no-op tükənmiş zamanda
     * yanlış əminlik yarada bilərdi).
     */
    private function resolveToken(mixed $id, string $merchantCode, string $name): ?PersonalAccessToken
    {
        if ($id !== null && $id !== '') {
            $token = PersonalAccessToken::find((int) $id);
            if ($token === null) {
                $this->error("Token ID={$id} tapılmadı.");
            }

            return $token;
        }

        if ($merchantCode === '' || $name === '') {
            $this->error('--id və ya (--merchant + --name) məcburidir.');

            return null;
        }

        $merchant = Merchant::where('code', $merchantCode)->first();
        if ($merchant === null) {
            $this->error("Merchant '{$merchantCode}' tapılmadı.");

            return null;
        }

        $posUser = User::where('role', UserRole::PosTerminal)
            ->where('merchant_id', $merchant->id)
            ->where('email', "pos@{$merchant->code}.api")
            ->first();

        if ($posUser === null) {
            $this->error("Bu merchant üçün POS integration user mövcud deyil.");

            return null;
        }

        $tokens = PersonalAccessToken::where('tokenable_id', $posUser->id)
            ->where('tokenable_type', User::class)
            ->where('name', $name)
            ->get();

        if ($tokens->isEmpty()) {
            $this->error("'{$name}' adı ilə token tapılmadı (merchant '{$merchantCode}').");

            return null;
        }

        if ($tokens->count() > 1) {
            // Bu vəziyyət istənməz — IssuePosTokenCommand eyni adla əvvəlki
            // token-i silir. Tarixi data və ya manual SQL ilə yaranan hal.
            $this->error("'{$name}' adı ilə " . $tokens->count() . " token var. --id ilə dəqiq seç.");
            foreach ($tokens as $t) {
                $this->line("  ID={$t->id} expires_at=" . ($t->expires_at?->toDateTimeString() ?? 'no-expiry'));
            }

            return null;
        }

        return $tokens->first();
    }
}
