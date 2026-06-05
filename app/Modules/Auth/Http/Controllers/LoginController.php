<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Services\RoleRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sistemin yeganə login giriş nöqtəsi.
 * Eyni form-dan bütün rollar daxil olur — backend rola görə yönləndirir.
 */
class LoginController extends Controller
{
    public function __construct(private readonly RoleRouter $router)
    {
    }

    /** Login formu.
     *
     * Audit Sprint 2: Password reset MVP-də mövcud deyil (`future` etiketi
     * README-də). UI link gizlədilir ki, "Şifrəni unutdum" 404-ə getməsin.
     */
    public function show(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => false,
        ]);
    }

    /**
     * POST /login
     *
     * Audit A-5: deaktiv hesab üçün ayrıca "Hesabınız deaktiv edilib" mesajı
     * email enumeration imkanı verirdi (attacker email + parol uyğunluğunu
     * deaktiv brand-ından təsdiq edə bilərdi). İndi həm yanlış cred, həm
     * deaktiv hesab eyni generic "Yanlış e-poçt və ya şifrə" mesajını alır.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();
        $request->session()->regenerate();

        $user = Auth::user();

        if (! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => 'Yanlış e-poçt və ya şifrə.',
            ]);
        }

        return redirect()->intended($this->router->homeUrlFor($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
