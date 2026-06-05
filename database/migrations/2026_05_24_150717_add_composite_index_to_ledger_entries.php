<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit M-3: merchant dashboard və admin reports üzərində
 * `WHERE merchant_id = ? AND type = ? ORDER BY created_at` formalı sorğular
 * tez-tez işlədilir (məs. "bu merchant-da son N earn", "type-larına görə cəm").
 *
 * Mövcud index-lər:
 *  - (merchant_id, created_at)
 *  - (type, created_at)
 *
 * Composite (merchant_id, type, created_at) hər iki filtr birgə tətbiq olunduqda
 * leftmost-prefix qaydasına görə daha effektivdir. Pre-existing (merchant_id,
 * created_at) və (type, created_at) saxlanılır — onlar tək-tək filtr halları
 * üçün yenə də optimal qalır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->index(
                ['merchant_id', 'type', 'created_at'],
                'ledger_entries_merchant_type_created_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table): void {
            $table->dropIndex('ledger_entries_merchant_type_created_idx');
        });
    }
};
