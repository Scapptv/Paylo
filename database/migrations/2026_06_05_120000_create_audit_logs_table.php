<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-əsaslı audit jurnalı (roadmap Phase 3.1).
 *
 * `AuditLogger` indiyə qədər yalnız log channel-a (fayl) yazırdı. Admin panelində
 * sorğulanabilir/filtrlənən audit görünüşü üçün hadisələr əlavə olaraq bu cədvələ
 * də yazılır (dual-write). Cədvəl APPEND-ONLY-dir — model səviyyəsində update/delete
 * qadağandır (ledger fəlsəfəsi: audit izi heç vaxt dəyişdirilməməlidir).
 *
 * actor_id qəsdən FK constraint-siz saxlanılır: audit yazıları istifadəçi
 * silinsə/anonimləşsə belə qalmalıdır (cascade-delete audit izini pozardı).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('event', 100)->comment('məs. admin.user.deactivated');
            $table->unsignedBigInteger('actor_id')->nullable()->comment('Əməliyyatı edən istifadəçi (request varsa)');
            $table->json('context')->nullable()->comment('Event-ə aid struktur data');
            $table->string('ip', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['event', 'created_at']);
            $table->index(['actor_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
