<?php

declare(strict_types=1);

namespace App\Modules\Api\Console\Commands;

use App\Core\Enums\UserRole;
use App\Core\Models\Merchant;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * POSNET (və ya digər M2M client) üçün Sanctum personal access token verir.
 *
 * Hər merchant üçün bir "POS integration" user yaradılır (yoxdursa) — sonradan
 * bu user-ə bir neçə adlandırılmış token əlavə edilə bilər (məs. hər fiziki
 * terminal üçün ayrı token). Token ability sabitdir: `pos:write`.
 *
 * Çıxış: plain-text token YALNIZ BİR DƏFƏ konsola yazılır; sahibi onu təhlükəsiz
 * yerdə saxlamalıdır. Sonra rotate üçün eyni `--name` ilə komanda təkrar
 * verilir — köhnə token avtomatik silinmir, manual `php artisan pos:revoke-token`
 * lazımdır (hələ implement edilməyib — yol xəritəsi).
 *
 * Misal:
 *   php artisan pos:issue-token --merchant=m_412 --name=bravo-pos-01
 *   php artisan pos:issue-token --merchant=m_412 --name=bravo-pos-02 --expires-days=365
 */
final class IssuePosTokenCommand extends Command
{
    protected $signature = 'pos:issue-token
                            {--merchant= : Merchant kodu (məs. m_412)}
                            {--name= : Token adı (məs. terminal-id və ya inteqrasiya server)}
                            {--expires-days=90 : Token-in keçərliliyi (gün). Default 90 — bank M2M norm}
                            {--include-reverse : reverse endpoint üçün pos:reverse ability də ver (default: yox)}
                            {--require-hmac : HMAC body signing tələb et (X-Paylo-Signature). V2 hardening.}';

    protected $description = 'POSNET və digər M2M client-lər üçün Sanctum token verir.';

    public function handle(AuditLogger $audit): int
    {
        $merchantCode   = (string) $this->option('merchant');
        $tokenName      = (string) $this->option('name');
        $expiresDays    = (int) $this->option('expires-days');
        $includeReverse = (bool) $this->option('include-reverse');
        $requireHmac    = (bool) $this->option('require-hmac');

        if ($merchantCode === '' || $tokenName === '') {
            $this->error('--merchant və --name məcburidir.');

            return self::INVALID;
        }

        if ($expiresDays <= 0 || $expiresDays > 3650) {
            $this->error('--expires-days 1..3650 aralığında olmalıdır.');

            return self::INVALID;
        }

        $merchant = Merchant::where('code', $merchantCode)->first();
        if ($merchant === null) {
            $this->error("Merchant '{$merchantCode}' tapılmadı.");

            return self::FAILURE;
        }

        // Per-merchant POS integration user — `pos@<code>.api` namespace-i merchant-a
        // bağlı, real email deyil, yalnız sahib identifikasiyası üçündür. is_active=true.
        $email = "pos@{$merchant->code}.api";
        /** @var User $user */
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name'        => "POS Integration ({$merchant->name})",
                'password'    => Hash::make(Str::random(64)),  // login üçün istifadə edilmir
                'role'        => UserRole::PosTerminal,
                'merchant_id' => $merchant->id,
                'is_active'   => true,
            ],
        );

        // Defensive: əgər köhnə user merchant-a aid deyilsə (məs. mağaza
        // birləşməsi tarixçəsi) — fail-fast əvəzinə müdaxilə.
        if ($user->merchant_id !== $merchant->id) {
            $this->error("Mövcud user (id={$user->id}) başqa merchant-a aiddir. Email namespace pozulub.");

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error("POS integration user passive vəziyyətdədir. Əvvəlcə is_active=true edin.");

            return self::FAILURE;
        }

        // Eyni ad altında köhnə token varsa onu sil — bir ad = bir aktiv token.
        $revoked = $user->tokens()->where('name', $tokenName)->delete();
        if ($revoked > 0) {
            $this->warn("Eyni adla {$revoked} köhnə token tapıldı və silindi (rotation).");
        }

        $expiresAt    = Carbon::now()->addDays($expiresDays);
        $abilities    = $includeReverse ? ['pos:write', 'pos:reverse'] : ['pos:write'];
        $hmacSecret   = $requireHmac ? bin2hex(random_bytes(32)) : null;  // 64 hex chars
        $newToken     = $user->createToken($tokenName, $abilities, $expiresAt);
        $plainToken   = $newToken->plainTextToken;

        if ($hmacSecret !== null) {
            $newToken->accessToken->forceFill(['hmac_secret' => $hmacSecret])->save();
        }

        $audit->log('api.pos.token.issued', [
            'merchant_id'   => $merchant->id,
            'merchant_code' => $merchant->code,
            'token_name'    => $tokenName,
            'expires_at'    => $expiresAt->toIso8601String(),
            'abilities'     => $abilities,
            'hmac_required' => $hmacSecret !== null,
            'rotated'       => $revoked > 0,
        ]);

        $this->newLine();
        $this->line('====================================================================');
        $this->line('POS API TOKEN — Bu çıxış BİR DƏFƏ göstərilir. İndi saxlayın.');
        $this->line('====================================================================');
        $this->newLine();
        $this->line("Merchant      : {$merchant->code} ({$merchant->name})");
        $this->line("Token adı     : {$tokenName}");
        $this->line("Keçərlilik    : {$expiresAt->toIso8601String()} (~{$expiresDays} gün)");
        $this->line("Ability       : " . implode(', ', $abilities));
        if ($hmacSecret !== null) {
            $this->line("HMAC tələbi   : MƏCBURİ (X-Paylo-Signature header)");
        }
        $this->newLine();
        $this->line("Authorization : Bearer {$plainToken}");
        if ($hmacSecret !== null) {
            $this->line("HMAC secret   : {$hmacSecret}");
            $this->newLine();
            $this->line('Hər POST sorğusunda iki header əlavə edin:');
            $this->line('  X-Paylo-Timestamp: <unix-timestamp>');
            $this->line('  X-Paylo-Signature: sha256=<hash_hmac("sha256", timestamp.".".body, secret)>');
            $this->line('Timestamp ±5 dəq pəncərəsində olmalıdır (replay protection).');
        }
        $this->newLine();
        $this->line('İstifadə nümunəsi:');
        $this->line("  curl -H 'Authorization: Bearer {$plainToken}' \\");
        $this->line("       -H 'Idempotency-Key: <ulid>' \\");
        $this->line('       -H \'Accept: application/json\' \\');
        $this->line('       -H \'Content-Type: application/json\' \\');
        $this->line("       http://localhost:8000/api/v1/pos/sale -d '{...}'");
        $this->newLine();

        return self::SUCCESS;
    }
}
