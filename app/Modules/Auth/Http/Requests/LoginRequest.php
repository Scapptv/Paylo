<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['boolean'],
        ];
    }

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            // Hər iki throttle layer-i artırılır — uğurlu login ikisini də sıfırlayır.
            RateLimiter::hit($this->throttleKey(), self::COMPOSITE_DECAY_SECONDS);
            RateLimiter::hit($this->emailThrottleKey(), self::EMAIL_DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => 'Yanlış e-poçt və ya şifrə.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        RateLimiter::clear($this->emailThrottleKey());
    }

    /**
     * İki qatlı throttle:
     *  1. Composite (email+IP) — adi user üçün 5 cəhd / dəq.
     *  2. Email-only — eyni emaila qarşı bütün IP-lərdən cəm 10 cəhd / 5 dəq
     *     (audit A-2: attacker IP rotation ilə composite-i ötüb yenə də spesifik
     *     hesabı bombalaya bilməsin).
     *
     * Hər iki limit ayrı-ayrı yoxlanılır; hansı tetiklendisə Lockout və 429.
     */
    private function ensureIsNotRateLimited(): void
    {
        if (RateLimiter::tooManyAttempts($this->throttleKey(), self::COMPOSITE_MAX_ATTEMPTS)) {
            $this->throwLockout($this->throttleKey());
        }

        if (RateLimiter::tooManyAttempts($this->emailThrottleKey(), self::EMAIL_MAX_ATTEMPTS)) {
            $this->throwLockout($this->emailThrottleKey());
        }
    }

    private function throwLockout(string $key): never
    {
        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'email' => "Çox cəhd. {$seconds} saniyə sonra yenidən cəhd edin.",
        ]);
    }

    private function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')) . '|' . $this->ip());
    }

    private function emailThrottleKey(): string
    {
        return 'login_email:' . Str::transliterate(Str::lower($this->string('email')));
    }

    // Composite (email+IP) layer — normal user üçün adi qoruma.
    private const COMPOSITE_MAX_ATTEMPTS = 5;
    private const COMPOSITE_DECAY_SECONDS = 60;

    // Email-only layer — IP rotation hücumlarına qarşı, daha geniş pəncərə.
    private const EMAIL_MAX_ATTEMPTS = 10;
    private const EMAIL_DECAY_SECONDS = 300;
}
