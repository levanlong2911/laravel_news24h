<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /**
         * 1️⃣ ENUM position → VARCHAR
         * (ENUM rất khó scale, đổi sớm là đúng)
         */
        DB::statement("
            ALTER TABLE advertisements
            MODIFY position VARCHAR(50) NOT NULL
        ");

        Schema::table('advertisements', function (Blueprint $table) {

            /**
             * 2️⃣ domain_id UUID NULLABLE (GIỮ NGUYÊN)
             * ❌ KHÔNG ÉP NOT NULL
             */
            if (!Schema::hasColumn('advertisements', 'domain_id')) {
                $table->uuid('domain_id')->nullable()->after('script');
            }

            /**
             * 3️⃣ active → is_active (NẾU CHƯA ĐỔI)
             */
            if (Schema::hasColumn('advertisements', 'active')) {
                $table->renameColumn('active', 'is_active');
            }

            /**
             * 4️⃣ index phục vụ query ads theo domain
             */
            $table->index(
                ['domain_id', 'position', 'is_active'],
                'ads_domain_position_active'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {

            $table->dropIndex('ads_domain_position_active');

            if (Schema::hasColumn('advertisements', 'is_active')) {
                $table->renameColumn('is_active', 'active');
            }
        });

        /**
         * rollback position về ENUM (nếu cần)
         */
        DB::statement("
            ALTER TABLE advertisements
            MODIFY position ENUM(
                'top',
                'middle',
                'bottom',
                'header',
                'in-post'
            )
        ");
    }
};
