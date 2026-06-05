<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Requests\V1\LoginRequest;
use App\Modules\Api\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mobile login / logout / logout-all.
 *
 * Web session-based LoginController-dən tamamilə ayrıdır — Sanctum personal
 * access token istifadə edir, cookie/session toxunmur.
 */
final class LoginController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * Audit Api-1: timing-equalisation üçün real bcrypt hash. Email DB-də
     * tapılmadıqda bcrypt çağırılmasa, response 10-50ms-də qayıdır; tapılarsa
     * Hash::check 100-200ms çəkir. Bu fərq email enumeration imkanı verir.
     * Mövcud olmayan user üçün də dummy hash-ə qarşı yoxlama edirik — eyni
     * compute-time, secret-leak yoxdur.
     *
     * Aşağıdakı `$2y$12$…` faktiki bcrypt cost=12 outputudur (Laravel docs
     * nümunəsi). Heç bir real user üçün uyğun gəlmir; Hash::check qaytaracağı
     * bool dəyəri bizə lazım deyil — yalnız müddəti tutmaq üçün çağırılır.
     */
    private const TIMING_DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    /**
     * POST /api/v1/auth/login
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $this->ensureIsNotRateLimited($request);

        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        // Audit Api-1, Api-2: hər uğursuz hal üçün eyni mesaj, eyni timing.
        // Üç fərqli uğursuzluq səbəbi (user yox / qeyri-customer rol / deaktiv)
        // istifadəçi enumeration-a yol verirdi — indi yalnız audit log-da
        // ayırd edilir; HTTP response identikdir.
        $reason = $this->resolveLoginFailureReason($user, $request->string('password')->toString());

        if ($reason !== null) {
            // Audit Api-11: iki qatlı throttle — composite (email+IP) + email-only.
            RateLimiter::hit($this->throttleKey($request), self::COMPOSITE_DECAY_SECONDS);
            RateLimiter::hit($this->emailThrottleKey($request), self::EMAIL_DECAY_SECONDS);
            $this->audit->log('api.auth.login.failed', [
                'email'   => (string) $request->input('email'),
                'user_id' => $user?->id,
                'role'    => $user?->role?->value,
                'reason'  => $reason,
            ], $request);

            throw ValidationException::withMessages([
                'email' => 'Yanlış e-poçt və ya şifrə.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));
        RateLimiter::clear($this->emailThrottleKey($request));

        /** @var User $user — reason null olduğu üçün burada non-null və validdir */
        $response = $this->issueToken($user, $request->string('device_name')->toString());

        $this->audit->log('api.auth.login', [
            'user_id'     => $user->id,
            'email'       => $user->email,
            'device_name' => $request->string('device_name')->toString(),
        ], $request);

        return $response;
    }

    /**
     * Audit Api-1 + Api-2: uğursuzluq səbəbini qaytarır (audit üçün), valid login
     * üçün `null`. Hər branch-da bcrypt çağrılır — timing eyniləşdirilib.
     *
     * Səbəblər:
     *  - 'user_not_found'      — email DB-də yoxdur
     *  - 'invalid_password'    — email var, parol uyğunsuz
     *  - 'role_not_allowed'    — email + parol düz, lakin customer rolunda deyil
     *  - 'inactive'            — email + parol düz, customer, lakin deaktiv
     */
    private function resolveLoginFailureReason(?User $user, string $password): ?string
    {
        if ($user === null) {
            // Timing-equalisation: dummy bcrypt hash-ə qarşı yoxlama → real
            // hash check ilə eyni CPU müddəti. Nəticə həmişə false, lakin
            // attacker bunu request müddətindən anlaya bilmir.
            Hash::check($password, self::TIMING_DUMMY_HASH);

            return 'user_not_found';
        }

        if (! Hash::check($password, $user->password)) {
            return 'invalid_password';
        }

        if ($user->role !== UserRole::Customer) {
            return 'role_not_allowed';
        }

        if (! $user->is_active) {
            return 'inactive';
        }

        return null;
    }

    /**
     * POST /api/v1/auth/logout — yalnız hazırkı token-i ləğv et.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user  = $request->user();
        $token = $user->currentAccessToken();
        $token?->delete();

        $this->audit->log('api.auth.logout', [
            'user_id'    => $user->id,
            'token_name' => $token?->name,
        ], $request);

        return response()->json(['message' => 'Logged out.']);
    }

    /**
     * POST /api/v1/auth/logout-all — istifadəçinin bütün token-lərini ləğv et.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $user    = $request->user();
        $deleted = $user->tokens()->delete();

        $this->audit->log('api.auth.logout_all', [
            'user_id'        => $user->id,
            'tokens_deleted' => $deleted,
        ], $request);

        return response()->json(['message' => 'All sessions terminated.']);
    }

    /**
     * Auth uğurlu olduqdan sonra standart JSON cavabı qurur.
     *
     * Token sabit `['customer']` ability ilə verilir — ability:customer
     * middleware mobile endpoint-lərinə yalnız bu token-ləri buraxır.
     * TTL: 30 gün (config/sanctum.expiration override edə bilər).
     */
    public function issueToken(User $user, string $deviceName): JsonResponse
    {
        // Defense-in-depth: bu metod həm login, həm register flow-undan çağırılır.
        // Hər iki path-dan keçən vahid yoxlama nöqtəsi — gələcəkdə hər hansı
        // observer/migration `is_active` default-unu dəyişsə belə deaktiv user
        // token ala bilməz.
        if (! $user->is_active) {
            $this->audit->log('api.auth.issue_token.blocked', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'reason'  => 'inactive',
            ], request());

            throw ValidationException::withMessages([
                'email' => 'Hesabınız deaktiv edilib. Adminə müraciət edin.',
            ]);
        }

        // Eyni device_name üçün köhnə token varsa təmizlə — bir cihaz = bir token (re-login)
        $user->tokens()->where('name', $deviceName)->delete();

        $expirationMinutes = (int) (config('sanctum.expiration') ?? 60 * 24 * 30);
        $expiresAt         = Carbon::now()->addMinutes($expirationMinutes);
        $plainToken        = $user->createToken($deviceName, ['customer'], $expiresAt)->plainTextToken;

        return response()->json([
            'token'      => $plainToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'user'       => (new UserResource($user))->toArray(request()),
        ]);
    }

    /**
     * Audit Api-11: iki qatlı throttle:
     *  1. Composite (email+IP) — adi user üçün 5 cəhd / dəq.
     *  2. Email-only — eyni emailə qarşı bütün IP-lərdən cəm 10 cəhd / 5 dəq
     *     (attacker IP rotation ilə composite-i ötüb spesifik hesabı bombalaya
     *     bilməsin).
     */
    private function ensureIsNotRateLimited(Request $request): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey($request), self::COMPOSITE_MAX_ATTEMPTS)) {
            $this->throwRateLimited($request, $this->throttleKey($request));
        }

        if (RateLimiter::tooManyAttempts($this->emailThrottleKey($request), self::EMAIL_MAX_ATTEMPTS)) {
            $this->throwRateLimited($request, $this->emailThrottleKey($request));
        }
    }

    private function throwRateLimited(Request $request, string $key): never
    {
        $seconds = RateLimiter::availableIn($key);

        $this->audit->log('api.auth.login.rate_limited', [
            'email'   => (string) $request->input('email'),
            'key'     => $key,
            'seconds' => $seconds,
        ], $request);

        throw ValidationException::withMessages([
            'email' => "Çox cəhd. {$seconds} saniyə sonra yenidən cəhd edin.",
        ]);
    }

    private function throttleKey(Request $request): string
    {
        return 'api-login:' . Str::transliterate(Str::lower((string) $request->input('email')) . '|' . $request->ip());
    }

    private function emailThrottleKey(Request $request): string
    {
        return 'api-login-email:' . Str::transliterate(Str::lower((string) $request->input('email')));
    }

    // Composite (email+IP) layer — normal user üçün adi qoruma.
    private const COMPOSITE_MAX_ATTEMPTS = 5;
    private const COMPOSITE_DECAY_SECONDS = 60;

    // Email-only layer — IP rotation hücumlarına qarşı, daha geniş pəncərə.
    private const EMAIL_MAX_ATTEMPTS = 10;
    private const EMAIL_DECAY_SECONDS = 300;
}
