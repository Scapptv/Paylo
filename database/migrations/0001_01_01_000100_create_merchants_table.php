<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                 // m_412, m_209 ...
            $table->string('name');
            $table->string('legal_name');
            $table->string('tin')->unique();
            $table->unsignedSmallInteger('mcc');
            $table->string('category')->index();              // grocery, restaurant, fuel, ...
            $table->enum('tier', ['standard', 'premium', 'enterprise'])->default('standard');
            $table->enum('status', ['active', 'pending', 'paused', 'revoked'])->default('pending')->index();
            $table->string('region')->nullable();
            $table->string('settlement_iban')->nullable();
            $table->string('settlement_cycle')->default('T+3');
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('pos_terminal_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['merchant_id', 'code']);
        });

        // FK-i users.merchant_id üçün bu noktada əlavə edirik.
        // SQLite ALTER TABLE ADD CONSTRAINT FOREIGN KEY dəstəkləmir → testlər (sqlite :memory:)
        // üçün skip edirik. Logical relationship migration zamanı yaradılan index ilə qorunur.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('merchant_id')->references('id')->on('merchants')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['merchant_id']);
            });
        }
        Schema::dropIfExists('branches');
        Schema::dropIfExists('merchants');
    }
};
