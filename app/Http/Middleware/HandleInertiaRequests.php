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
        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        'id'          => $request->user()->id,
                        'name'        => $request->user()->name,
                        'email'       => $request->user()->email,
                        'role'        => $request->user()->role->value,
                        'role_label'  => $request->user()->role->label(),
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
