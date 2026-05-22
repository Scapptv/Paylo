<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Enums\UserRole;
use App\Core\Models\User;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Requests\V1\RegisterRequest;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Mobile-dan yeni müştəri qeydiyyatı. Yalnız `customer` rolu yaradır —
 * digər rollar (admin, merchant_*, cashier, pos_terminal) admin paneli
 * üzərindən təyin olunur.
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
     */
    public function store(RegisterRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = User::create([
            'name'        => $request->string('name'),
            'email'       => $request->string('email'),
            'phone'       => $request->string('phone'),
            'password'    => $request->string('password'),
            'role'        => UserRole::Customer,
            'is_active'   => true,
            // customer_qr User::saving boot listener-i tərəfindən avtomatik
            // generate olunur (R7 non-null kontraktı). Burada explicit ötürmürük
            // ki, bütün create yolları üçün tək bir mənbə qalsın.
        ]);

        // Email verification mail-ini Laravel-in standart listener-i göndərsin
        // (User MustVerifyEmail implement edirsə avtomatik tetiklenir).
        event(new Registered($user));

        $this->audit->log('api.auth.register', [
            'user_id'     => $user->id,
            'email'       => $user->email,
            'customer_qr' => $user->customer_qr,
            'device_name' => $request->string('device_name')->toString(),
        ], $request);

        $response = $this->login->issueToken($user, $request->string('device_name')->toString());

        return $response->setStatusCode(Response::HTTP_CREATED);
    }
}
