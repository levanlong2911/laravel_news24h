<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /**
         * 1. DROP enum position cũ
         */
        Schema::table('advertisements', function (Blueprint $table) {
            if (Schema::hasColumn('advertisements', 'position')) {
                $table->dropColumn('position');
            }
        });

        /**
         * 2. ADD position enum mới + domain_id
         */
        Schema::table('advertisements', function (Blueprint $table) {

            if (!Schema::hasColumn('advertisements', 'domain_id')) {
                $table->uuid('domain_id')->nullable()->after('script');
            }

            $table->enum('position', [
                'top',
                'middle',
                'bottom',
                'header',
                'in-post',
            ])->after('script');
        });

        /**
         * 3. ADD FK
         */
        Schema::table('advertisements', function (Blueprint $table) {
            $table->foreign('domain_id')
                ->references('id')
                ->on('domains')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        /**
         * DROP FK + domain_id
         */
        Schema::table('advertisements', function (Blueprint $table) {
            if (Schema::hasColumn('advertisements', 'domain_id')) {
                $table->dropForeign(['domain_id']);
                $table->dropColumn('domain_id');
            }
        });

        /**
         * ROLLBACK enum position
         */
        Schema::table('advertisements', function (Blueprint $table) {
            if (Schema::hasColumn('advertisements', 'position')) {
                $table->dropColumn('position');
            }

            $table->enum('position', [
                'top',
                'middle',
                'bottom',
            ]);
        });
    }
};
