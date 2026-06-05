<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Bütün Inertia səhifələrində avtomatik mövcud olan global props.
     * Frontend bu props-u `usePage().props.auth.user` kimi alır.
     */
    public function share(Request $request): array
    {
        // Audit H-2 / P-7: `merchant` relation-ı yalnız o user-lər üçün eager-load
        // et ki, onların `merchant_id`-si NULL deyil (Customer rolu üçün
        // mənasızdır). `loadMissing` artıq yüklənibsə yenidən sorğu vurmur —
        // POS controller-lərində $request->user()->merchant ikinci sorğuya gəlmir.
        if ($request->user() !== null && $request->user()->merchant_id !== null) {
            $request->user()->loadMissing('merchant');
        }

        return array_merge(parent::share($request), [
            'auth' => [
                // Audit H-3: `role?->value` null-safe — DB-də NULL rol mövcuddursa
                // (legacy data, korrupsiyalı seed) Inertia shared props 500-ə düşməsin.
                // Adi axında role mütləq mövcud olmalıdır; null cavabı UI sönmüş
                // statusla görsədir, server crash etmir.
                'user' => fn () => $request->user()
                    ? [
                        'id'          => $request->user()->id,
                        'name'        => $request->user()->name,
                        'email'       => $request->user()->email,
                        'role'        => $request->user()->role?->value,
                        'role_label'  => $request->user()->role?->label(),
                        'merchant_id' => $request->user()->merchant_id,
                        'merchant'    => $request->user()->merchant?->only(['id', 'code', 'name', 'category', 'tier']),
                        'customer_qr' => $request->user()->customer_qr,
                    ]
                    : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error'   => fn () => $request->session()->get('error'),
            ],
            'app' => [
                'name' => config('app.name'),
                'env'  => config('app.env'),
            ],
        ]);
    }
}
