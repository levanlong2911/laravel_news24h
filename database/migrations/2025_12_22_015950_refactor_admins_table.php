<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 1. DROP FK an toàn
         */
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

        /**
         * 2. DROP các cột cần sửa
         */
        Schema::table('admins', function (Blueprint $table) {
            if (Schema::hasColumn('admins', 'domain')) {
                $table->dropColumn('domain');
            }

            if (Schema::hasColumn('admins', 'email_verified_at')) {
                $table->dropColumn('email_verified_at');
            }

            if (Schema::hasColumn('admins', 'remember_token')) {
                $table->dropColumn('remember_token');
            }
        });

        /**
         * 3. ADD lại cột đúng chuẩn
         */
        Schema::table('admins', function (Blueprint $table) {

            if (!Schema::hasColumn('admins', 'domain_id')) {
                $table->uuid('domain_id')->nullable()->after('role_id');
            }

            // role_id chuẩn UUID
            $table->uuid('role_id')->change(); // 👈 CHỈ GIỮ LẠI nếu role_id đang là string/int
            // nếu vẫn lỗi → drop + add giống dưới

            $table->timestamp('email_verified_at')->nullable()->after('email');
            $table->string('remember_token', 100)->nullable()->after('password');
        });

        /**
         * 4. ADD FK lại
         */
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
