<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. DROP ALL FK liên quan role_id
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'admins'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement("ALTER TABLE admins DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
        }

        // 2. SỬA CẤU TRÚC
        Schema::table('admins', function (Blueprint $table) {

            if (Schema::hasColumn('admins', 'domain')) {
                $table->dropColumn('domain');
            }

            if (!Schema::hasColumn('admins', 'domain_id')) {
                $table->uuid('domain_id')->nullable()->after('role_id');
            }

            $table->uuid('role_id')->change();
            $table->timestamp('email_verified_at')->nullable()->change();
            $table->string('remember_token', 100)->nullable()->change();
        });

        // 3. ADD FK LẠI
        Schema::table('admins', function (Blueprint $table) {
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('restrict');

            $table->foreign('domain_id')
                ->references('id')->on('domains')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        // DROP FK an toàn
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'admins'
              AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        foreach ($foreignKeys as $fk) {
            DB::statement("ALTER TABLE admins DROP FOREIGN KEY `{$fk->CONSTRAINT_NAME}`");
        }

        Schema::table('admins', function (Blueprint $table) {
            if (Schema::hasColumn('admins', 'domain_id')) {
                $table->dropColumn('domain_id');
            }
        });
    }
};
