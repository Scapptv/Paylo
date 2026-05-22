<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger immutability — DB səviyyəsində enforcement.
 *
 * Eloquent `booted()` blokları proses daxilində qoruyur, lakin raw query
 * (`DB::table('ledger_entries')->update(...)`) bypass edə bilər.
 *
 * Bu migration:
 *   1. `prev_hash` və `entry_hash` sütunları əlavə edir (hash chain).
 *   2. UPDATE və DELETE üçün DB trigger-ləri qurur (MySQL / PostgreSQL / SQLite).
 *
 * Hash chain formula (LedgerService::computeHash):
 *   sha256( prev_hash | uid | user_id | merchant_id | type | amount | ref | created_at )
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->string('prev_hash', 64)->nullable()->after('meta');
            $table->string('entry_hash', 64)->nullable()->after('prev_hash');
        });

        match (DB::getDriverName()) {
            'sqlite' => $this->createSqliteTriggers(),
            'mysql'  => $this->createMysqlTriggers(),
            'pgsql'  => $this->createPgsqlTriggers(),
            default  => null,
        };
    }

    public function down(): void
    {
        match (DB::getDriverName()) {
            'sqlite' => $this->dropSqliteTriggers(),
            'mysql'  => $this->dropMysqlTriggers(),
            'pgsql'  => $this->dropPgsqlTriggers(),
            default  => null,
        };

        Schema::table('ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'entry_hash']);
        });
    }

    private function createSqliteTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            BEGIN
                SELECT RAISE(FAIL, 'ledger_entries: UPDATE blocked — immutable ledger');
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            BEGIN
                SELECT RAISE(FAIL, 'ledger_entries: DELETE blocked — immutable ledger');
            END;
        SQL);
    }

    private function dropSqliteTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ledger_entries: UPDATE blocked — immutable ledger';
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'ledger_entries: DELETE blocked — immutable ledger';
            END;
        SQL);
    }

    private function dropMysqlTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete');
    }

    private function createPgsqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ledger_entries_block_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'ledger_entries: % blocked — immutable ledger', TG_OP;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_update
            BEFORE UPDATE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_block_mutation();
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER ledger_entries_no_delete
            BEFORE DELETE ON ledger_entries
            FOR EACH ROW EXECUTE FUNCTION ledger_entries_block_mutation();
        SQL);
    }

    private function dropPgsqlTriggers(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_update ON ledger_entries');
        DB::unprepared('DROP TRIGGER IF EXISTS ledger_entries_no_delete ON ledger_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS ledger_entries_block_mutation()');
    }
};
