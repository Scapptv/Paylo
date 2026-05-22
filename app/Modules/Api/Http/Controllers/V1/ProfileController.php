<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers\V1;

use App\Core\Services\AuditLogger;
use App\Http\Controllers\Controller;
use App\Modules\Api\Http\Resources\V1\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * GET    /api/v1/me            — cari istifadəçi
 * PUT    /api/v1/me            — name/phone/locale yeniləməsi
 * PUT    /api/v1/me/password   — şifrə dəyişdirmə
 * DELETE /api/v1/me            — hesab silmə (password təsdiqi ilə, anonymise)
 */
final class ProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => (new UserResource($request->user()))->toArray($request),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'   => ['sometimes', 'string', 'max:255'],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:32'],
            'locale' => ['sometimes', 'string', 'in:az,en,ru'],
        ]);

        $user = $request->user();
        $user->fill(array_intersect_key($validated, array_flip(['name', 'phone'])));
        $user->save();

        // `locale` hələ user cədvəlində sütun deyil — keçici olaraq cache-ə yazıla bilər.
        // Sütun əlavə olunduqda burada da `locale` fill olunmalıdır.

        $this->audit->log('api.profile.update', [
            'user_id' => $user->id,
            'fields'  => array_keys($validated),
        ], $request);

        return response()->json([
            'user' => (new UserResource($user->refresh()))->toArray($request),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        // `current_password` Laravel-in built-in rule-udur: cari autentifikasiya
        // olunmuş istifadəçinin şifrəsi ilə uyğunluq yoxlayır. Sanctum guard-ı
        // dəqiq göstəririk ki, default web guard-a cəhd etməsin.
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'current_password:sanctum'],
            'password'         => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        $user->password = $validated['password']; // hashed cast avtomatik hash edir
        $user->save();

        // Cari token saxlanır, digər cihazlarda forced logout.
        $currentTokenId = $request->user()->currentAccessToken()?->getKey();

        $revoked = $user->tokens()
            ->when($currentTokenId, fn ($q) => $q->where('id', '!=', $currentTokenId))
            ->delete();

        $this->audit->log('api.profile.password_changed', [
            'user_id'         => $user->id,
            'revoked_tokens'  => $revoked,
            'current_kept_id' => $currentTokenId,
        ], $request);

        return response()->json(['message' => 'Şifrə yeniləndi.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
            'confirm'  => ['required', 'boolean', 'accepted'],
        ]);

        $user = $request->user();

        if (! Hash::check($validated['password'], $user->password)) {
            $this->audit->log('api.profile.delete.failed', [
                'user_id' => $user->id,
                'reason'  => 'invalid_password',
            ], $request);

            throw ValidationException::withMessages([
                'password' => 'Şifrə yanlışdır.',
            ]);
        }

        // GDPR/KVKK uyğun anonimləşdirmə — hard-delete etmirik ki, ledger yazıları
        // (immutable) və audit izi bütöv qalsın. PII sahələri təmizlənir,
        // hesab deaktiv olunur, bütün token və push cihaz qeydləri silinir.
        $userId       = $user->id;
        $originalEmail = $user->email;

        $user->update([
            'name'        => 'Silinmiş istifadəçi #' . $userId,
            'email'       => "deleted+{$userId}@paylo.deleted",
            'phone'       => null,
            'is_active'   => false,
            'customer_qr' => null,
        ]);

        $user->pushTokens()->delete();
        $user->tokens()->delete();

        $this->audit->log('api.profile.deleted', [
            'user_id'         => $userId,
            'original_email'  => $originalEmail,
        ], $request);

        return response()->json(['message' => 'Hesab silindi.']);
    }
}
