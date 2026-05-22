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
     * POST /api/v1/auth/login
     */
    public function store(LoginRequest $request): JsonResponse
    {
        $this->ensureIsNotRateLimited($request);

        /** @var User|null $user */
        $user = User::where('email', $request->string('email'))->first();

        if (! $user || ! Hash::check($request->string('password')->toString(), $user->password)) {
            RateLimiter::hit($this->throttleKey($request));
            $this->audit->log('api.auth.login.failed', [
                'email'  => (string) $request->input('email'),
                'reason' => 'invalid_credentials',
            ], $request);

            throw ValidationException::withMessages([
                'email' => 'Yanlış e-poçt və ya şifrə.',
            ]);
        }

        // Mobile app yalnız customer rolu üçündür — kassir/admin web panel istifadə edir.
        if ($user->role !== UserRole::Customer) {
            RateLimiter::hit($this->throttleKey($request));
            $this->audit->log('api.auth.login.failed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'role'    => $user->role?->value,
                'reason'  => 'role_not_allowed',
            ], $request);

            throw ValidationException::withMessages([
                'email' => 'Bu hesabla mobil tətbiqə giriş icazəsi yoxdur.',
            ]);
        }

        if (! $user->is_active) {
            RateLimiter::hit($this->throttleKey($request));
            $this->audit->log('api.auth.login.failed', [
                'user_id' => $user->id,
                'email'   => $user->email,
                'reason'  => 'inactive',
            ], $request);

            throw ValidationException::withMessages([
                'email' => 'Hesabınız deaktiv edilib. Adminə müraciət edin.',
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));

        $response = $this->issueToken($user, $request->string('device_name')->toString());

        $this->audit->log('api.auth.login', [
            'user_id'     => $user->id,
            'email'       => $user->email,
            'device_name' => $request->string('device_name')->toString(),
        ], $request);

        return $response;
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

    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        $this->audit->log('api.auth.login.rate_limited', [
            'email'   => (string) $request->input('email'),
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
}
