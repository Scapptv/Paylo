<?php

use App\Core\Enums\LedgerEntryType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-merchant bucket — hər customer × merchant cütü üçün bir
        Schema::create('buckets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->integer('balance')->default(0);          // raw, qəpik
            $table->integer('earned_total')->default(0);
            $table->integer('redeemed_total')->default(0);
            $table->integer('expired_total')->default(0);

            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'merchant_id']);
            $table->index(['merchant_id', 'balance']);       // admin üçün böyük bucket sorğuları
        });

        // Immutable ledger
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 32)->unique();
            $table->foreignId('user_id')->constrained();
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->foreignId('cashier_id')->nullable()->constrained('users');

            $table->enum('type', array_map(fn($c) => $c->value, LedgerEntryType::cases()))->index();
            $table->integer('amount');
            $table->integer('balance_after');

            $table->string('ref')->nullable();                // receipt no, idempotency key
            $table->foreignId('reverses_id')->nullable()->constrained('ledger_entries');
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'merchant_id', 'created_at']);
            $table->index(['merchant_id', 'created_at']);
            $table->index(['type', 'created_at']);

            // Idempotency: eyni (merchant, ref, type) üçün iki dəfə yazılmasın
            // (ref null olarsa unique tətbiq olunmur, çünki SQL null != null)
            $table->unique(['merchant_id', 'ref', 'type'], 'ledger_entries_merchant_ref_type_unique');
        });

        // Transactions (POS satışları) — ledger entry-lərin source-u
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no');
            $table->foreignId('merchant_id')->constrained();
            $table->foreignId('branch_id')->nullable()->constrained();
            $table->foreignId('cashier_id')->nullable()->constrained('users');
            $table->foreignId('user_id')->constrained();     // customer

            $table->integer('sale_amount');                  // qəpik
            $table->integer('earned_amount')->default(0);
            $table->integer('redeemed_amount')->default(0);

            $table->enum('status', ['completed', 'refunded', 'reversed'])->default('completed')->index();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['merchant_id', 'occurred_at']);
            $table->index(['cashier_id', 'occurred_at']);

            // receipt_no yalnız merchant səviyyəsində unikaldır — iki fərqli mağaza
            // eyni qəbz nömrəsindən istifadə edə bilər.
            $table->unique(['merchant_id', 'receipt_no'], 'transactions_merchant_receipt_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('buckets');
    }
};
