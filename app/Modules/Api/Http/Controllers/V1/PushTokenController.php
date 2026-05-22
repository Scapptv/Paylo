<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Models\PushToken;
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
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token'        => ['required', 'string', 'max:500'],
            'platform'     => ['required', 'string', 'in:ios,android'],
            'app_version'  => ['nullable', 'string', 'max:64'],
            'device_model' => ['nullable', 'string', 'max:128'],
        ]);

        $user = $request->user();

        // Təhlükəsizlik: token başqa istifadəçiyə bağlıdırsa, onu silirik —
        // əks halda `updateOrCreate(['token' => ...])` qarşı tərəfin user_id-ni
        // override edib onun push channel-ini ələ keçirərdi (token bilməklə).
        PushToken::where('token', $validated['token'])
            ->where('user_id', '!=', $user->id)
            ->delete();

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
            'token' => ['required', 'string'],
        ]);

        PushToken::where('user_id', $request->user()->id)
            ->where('token', $validated['token'])
            ->delete();

        return response()->json(['message' => 'Push token removed.']);
    }
}
