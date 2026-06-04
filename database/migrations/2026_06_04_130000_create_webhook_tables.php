<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhook infrastructure (Paylo → POSNET istiqaməti).
 *
 * Sxem qısaca:
 *  - webhook_endpoints  → Per-merchant URL + HMAC secret + active flag + event filter
 *  - webhook_deliveries → Hər emit olunmuş event üçün bir sətr, status izlənir
 *
 * Niyə iki cədvəl:
 *  - endpoint URL-i və secret-i bir yerdə (per-merchant config)
 *  - delivery hər emit olunmuş event üçün ayrı sətr — retry üçün, audit üçün, debug üçün
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80)->comment('Operator etiketi, məs. "posnet-prod"');
            $table->string('url', 500);
            $table->string('hmac_secret', 64)->comment('HMAC-SHA256 üçün 32-byte hex');
            $table->json('events')->comment('["admin_reverse","bucket_expire"]');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['merchant_id', 'active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 26)->unique()->comment('ULID — POSNET-də idempotency açarı');
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event_type', 32);
            $table->json('payload');
            $table->string('status', 16)->default('pending')->comment('pending|delivered|failed');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_response_status')->nullable();
            $table->text('last_response_body')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['endpoint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
