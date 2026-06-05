<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Models\PushToken;
use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * POST   /api/v1/push/register   — token-i qeydiyyatdan keçir / yenilə
 * DELETE /api/v1/push/register   — token-i sil
 */
final class PushTokenController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'        => ['required', 'string', 'max:500'],
            'platform'     => ['required', 'string', 'in:ios,android'],
            'app_version'  => ['nullable', 'string', 'max:64'],
            'device_model' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();

        // Audit Api-4: əgər bu token başqa user-ə bağlıdırsa, onu **silmirik**.
        // Köhnə davranış (silmək) DoS vektoru idi: leak olmuş token ilə attacker
        // victim-in push channel-ini söndürə bilərdi. İndi əvəzində 403 + audit
        // log: lazımsız side-effect yox, incident görünür.
        $existingOnOtherUser = PushToken::where('token', $validated['token'])
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($existingOnOtherUser !== null) {
            $this->audit->log('api.push.register.cross_user_attempt', [
                'requester_user_id' => $user->id,
                'token_owner_id'    => $existingOnOtherUser->user_id,
                'token_hash'        => hash('sha256', $validated['token']),
            ], $request);

            return response()->json([
                'message' => 'Bu token başqa cihaza bağlıdır.',
            ], 403);
        }

        PushToken::updateOrCreate(
            ['user_id' => $user->id, 'token' => $validated['token']],
            [
                'platform'     => $validated['platform'],
                'app_version'  => $validated['app_version'] ?? null,
                'device_model' => $validated['device_model'] ?? null,
                'last_seen_at' => Carbon::now(),
            ],
        );

        return response()->json(['message' => 'Push token registered.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:500'],
        ]);

        PushToken::where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Push token removed.']);
    }
}
