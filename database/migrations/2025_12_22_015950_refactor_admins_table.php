<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {

            if (Schema::hasColumn('admins', 'domain')) {
                $table->dropColumn('domain');
            }

            if (!Schema::hasColumn('admins', 'domain_id')) {
                $table->uuid('domain_id')->nullable()->after('role_id');
            }

            // role_id về UUID
            $table->uuid('role_id')->change();

            $table->timestamp('email_verified_at')->nullable()->change();
            $table->string('remember_token', 100)->nullable()->change();
        });

        // DROP FK TRƯỚC (QUAN TRỌNG)
        Schema::table('admins', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
        });

        // ADD FK LẠI
        Schema::table('admins', function (Blueprint $table) {
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('restrict');

            $table->foreign('domain_id')
                ->references('id')->on('domains')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');
        });
    }
};
