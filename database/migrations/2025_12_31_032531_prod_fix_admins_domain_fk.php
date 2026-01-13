<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        /**
         * 1️⃣ DROP FK domain_id nếu tồn tại (AN TOÀN)
         */
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'admins'
              AND COLUMN_NAME = 'domain_id'
              AND CONSTRAINT_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement(
                "ALTER TABLE admins DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}"
            );
        }

        /**
         * 2️⃣ ADD lại FK domain_id (KHÔNG TRÙNG)
         */
        Schema::table('admins', function (Blueprint $table) {
            $table->foreign('domain_id')
                  ->references('id')
                  ->on('domains')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_NAME = 'admins'
              AND COLUMN_NAME = 'domain_id'
              AND CONSTRAINT_SCHEMA = DATABASE()
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement(
                "ALTER TABLE admins DROP FOREIGN KEY {$fk->CONSTRAINT_NAME}"
            );
        }
    }
};
