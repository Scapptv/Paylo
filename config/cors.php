<?php

/*
|--------------------------------------------------------------------------
| CORS allowed origins resolver
|--------------------------------------------------------------------------
|
| Təhlükəsizlik prinsipi: default `*` DEYİL. `CORS_ALLOWED_ORIGINS` env-i
| boşdursa, Sanctum-un stateful domain siyahısından origin törədirik
| (həm `http`, həm `https` variantı ilə). Bu, brauzerdə təsadüfi
| wildcard + `credentials: true` kombinasiyasının qarşısını alır
| (brauzer onsuz da bunu rədd edir, amma config səviyyəsində qadağa
| daha aydın siqnaldır).
|
| `CORS_ALLOWED_ORIGINS=*` açıq şəkildə yazılarsa wildcard işləyəcək
| (yalnız local dev üçün tövsiyə olunur — `supports_credentials` ilə
| birlikdə brauzer onu rədd edəcək).
|
*/

$rawOrigins = env('CORS_ALLOWED_ORIGINS');

if ($rawOrigins === null || trim((string) $rawOrigins) === '') {
    // Sanctum stateful domain-ləri (host[:port], scheme-siz) → tam origin-lərə çevir.
    $statefulDomains = array_filter(array_map(
        'trim',
        explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', ''))
    ));

    $allowedOrigins = [];
    foreach ($statefulDomains as $host) {
        $allowedOrigins[] = 'http://' . $host;
        $allowedOrigins[] = 'https://' . $host;
    }
    $allowedOrigins = array_values(array_unique($allowedOrigins));
} else {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', (string) $rawOrigins))));
}

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Mobile (Flutter Dio) brauzer CORS-a tabe deyil — bu konfiqurasiya
    | əsasən admin paneli / web app üçün vacibdir. Buna baxmayaraq, Sanctum
    | SPA cookie auth-u dəstəkləmək üçün `supports_credentials = true`.
    |
    | Production-da `CORS_ALLOWED_ORIGINS` env-ində konkret domain(lər)
    | göstərin — wildcard `*` + credentials brauzer tərəfindən bloklanır.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
