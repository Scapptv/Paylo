<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Modules\Api\Services\RotatingQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/qr — müştəri üçün rotating QR token.
 *
 * Mobile hər TTL saniyədə bir yenidən çağırır; cashier scan endpoint-i isə
 * `RotatingQrService::verify()` ilə imzanı yoxlayır.
 */
final class QrController extends Controller
{
    private const TTL_SECONDS = 30;

    public function __construct(private readonly RotatingQrService $qr)
    {
    }

    /**
     * Audit Api-5: `static_qr` response-dan çıxarıldı. Mobile app static QR-i
     * yalnız bir dəfə (login / register cavabında `user.customer_qr`) götürüb
     * lokal saxlamalıdır — rotating endpoint hər çağırışda göndərməməlidir.
     * Bu, log/network leak vektorunu azaldır və rotating sisteminin niyyətini
     * gücləndirir (cashier əldə etdiyi token həmişə ephemeral olur).
     */
    public function generate(Request $request): JsonResponse
    {
        $user    = $request->user();
        $payload = $this->qr->generate($user, self::TTL_SECONDS);

        return response()->json([
            'qr_value'   => $payload['token'],
            'expires_at' => $payload['expires_at'],
            'ttl'        => self::TTL_SECONDS,
        ]);
    }
}
