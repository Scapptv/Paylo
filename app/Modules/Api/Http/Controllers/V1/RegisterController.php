<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Requests\V1\RegisterRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Mobile-dan yeni müştəri qeydiyyatı. Yalnız `customer` rolu yaradır —
 * digər rollar (admin, merchant_*, cashier, pos_terminal) admin paneli
 * üzərindən təyin olunur.
 *
 * Audit Api-3 + Api-6 (Sprint 1 PR #3) qərarları:
 *  - Email enumeration qarşısı üçün hər iki halda (yeni / mövcud email)
 *    eyni generic 200 cavab qaytarılır. Mövcud email üçün noop log yazılır,
 *    real account yaradılmır, token verilmir.
 *  - Email verification feature silindi — User MustVerifyEmail implement
 *    etmir, Registered event listener mövcud deyildi (silent noop idi).
 *    Future: lazımdırsa, ayrıca verify endpoint + queued mail əlavə olunar.
 */
final class RegisterController extends Controller
{
    public function __construct(
        private readonly LoginController $login,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * POST /api/v1/auth/register
     *
     * Davranış (timing-equalisation üçün hər iki hal eyni response formatı):
     *  - Yeni email   → user create + token issue + 201 + generic payload
     *  - Mövcud email → 200 + generic payload (`registered: false`), yeni token yox
     *
     * Mobile app-in UX axını: response-da `token` field-i `null` olarsa
     * "yenidən login edin və ya şifrəni unutdunuz?" axınına yönləndir.
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        $email      = $request->string('email')->toString();
        $deviceName = $request->string('device_name')->toString();

        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            // Mövcud user — silent log, real action yox. Future: kifayət qədər
            // güclü identifier varsa (telefon match və s.) email re-send və ya
            // recovery hint göndərə bilərik. MVP-də sadəcə audit.
            $this->audit->log('api.auth.register.duplicate_email', [
                'user_id' => $existing->id,
                'email'   => $email,
                'reason'  => 'silent_noop_to_prevent_enumeration',
            ], $request);

            return response()->json([
                'message'    => 'Qeydiyyat sorğunuz qəbul edildi.',
                'registered' => false,
                'token'      => null,
                'user'       => null,
            ]);
        }

        /** @var User $user */
        $user = User::create([
            'name'      => $request->string('name'),
            'email'     => $email,
            'phone'     => $request->string('phone'),
            'password'  => $request->string('password'),
            'role'      => UserRole::Customer,
            'is_active' => true,
            // customer_qr User::saving boot listener-i tərəfindən avtomatik
            // generate olunur (R7 non-null kontraktı).
        ]);

        $this->audit->log('api.auth.register', [
            'user_id'     => $user->id,
            'email'       => $user->email,
            'customer_qr' => $user->customer_qr,
            'device_name' => $deviceName,
        ], $request);

        $tokenResponse = $this->login->issueToken($user, $deviceName);
        $tokenPayload  = $tokenResponse->getData(true);

        return response()->json([
            'message'    => 'Qeydiyyat sorğunuz qəbul edildi.',
            'registered' => true,
            'token'      => $tokenPayload['token'] ?? null,
            'expires_at' => $tokenPayload['expires_at'] ?? null,
            'user'       => $tokenPayload['user'] ?? null,
        ], Response::HTTP_CREATED);
    }
}
