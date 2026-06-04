<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-token HMAC secret for body signing (V2 hardening of POS API).
 *
 * Sızdırılmış bearer token məsələsinin ikinci müdafiə xətti:
 *  - Bearer token attacker-ə vesica giriş verir, lakin
 *  - HMAC secret olmadan request body-ni manipulyasiya edə bilməz.
 *
 * Sütun NULLABLE — geri uyğunluq üçün: köhnə token-lər heç bir signature
 * tələb etmir (default davranış). Yeni token-lər `--require-hmac` ilə
 * verilsə, secret doldurulur və VerifyHmacSignature middleware tələb edir.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('hmac_secret', 64)->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('hmac_secret');
        });
    }
};
