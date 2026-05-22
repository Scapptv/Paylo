<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger hash-chain tail pointer — perf optimization.
 *
 * Problem: `LedgerService::writeEntry` əvvəllər hər insertdə
 *   LedgerEntry::orderByDesc('id')->lockForUpdate()->first()
 * çağırırdı. InnoDB-də bu, `ledger_entries` cədvəlində supremum gap-lock yaradır
 * və bütün paralel ledger yazılarını qlobal serialize edir.
 *
 * Həll: tək sətirli `ledger_chain_tail` cədvəli (id=1). Yazma zamanı yalnız bu
 * named row-da X-lock alırıq. Hash chain korrektliyi eynidir (linear ordering
 * yenə də qorunur), amma kilid kiçik və izolyasiya olunmuş row-dadır.
 *
 * Qeyd: cədvəl həmişə dəqiq 1 sətir saxlayır (id=1). Mövcud ledger varsa,
 * `up()` o sətri sonuncu entry-dən doldurur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_chain_tail', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary(); // həmişə = 1
            $table->unsignedBigInteger('last_entry_id')->nullable();
            $table->string('last_hash', 64)->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        // Mövcud ledger-dən tail-i seed et (idempotent — yenidən run olunsa eyni nəticə).
        $last = DB::table('ledger_entries')->orderByDesc('id')->first();

        DB::table('ledger_chain_tail')->insert([
            'id'            => 1,
            'last_entry_id' => $last->id        ?? null,
            'last_hash'     => $last->entry_hash ?? null,
            'updated_at'    => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_chain_tail');
    }
};
