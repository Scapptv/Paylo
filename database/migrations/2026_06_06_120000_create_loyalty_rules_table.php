<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-əsaslı loyalty qaydaları (roadmap Phase 4.2 — Rules).
 *
 * `config/loyalty.php` earn faizlərinin (basis points), tier multiplier-lərinin,
 * redemption və expiration ayarlarının DEFAULT mənbəyi olaraq qalır. Bu cədvəl
 * admin-redaktə olunan OVERRIDE-ları saxlayır: `key` = config-in alt-yolu
 * (məs. `earn_rates_bp.grocery`), `value` = integer dəyər.
 *
 * `AppServiceProvider::boot()` bu override-ları runtime-da config-ə tətbiq edir;
 * `EarnCalculator` (dəyişməz) config oxumağa davam edir — kanonik integer
 * hesablama (intdiv) toxunulmur, yalnız rate-in MƏNBƏYİ genişlənir.
 *
 * Override yoxdursa config faylı default-u qüvvədə qalır (backward-compatible).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80)->unique()->comment('config/loyalty.php alt-yolu, məs. earn_rates_bp.grocery');
            $table->bigInteger('value')->comment('integer dəyər (bp / qəpik / faiz / gün)');
            $table->unsignedBigInteger('updated_by')->nullable()->comment('son dəyişən admin (audit)');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rules');
    }
};
