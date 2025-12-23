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
        Schema::table('advertisements', function (Blueprint $table) {
            // 1. add domain_id
            if (!Schema::hasColumn('advertisements', 'domain_id')) {
                $table->uuid('domain_id')->nullable()->after('script');
            }

            // 2. update enum position
            $table->enum('position', [
                'top',
                'middle',
                'bottom',
                'header',
                'in-post',
            ])->change();
        });

        // 3. add foreign key
        Schema::table('advertisements', function (Blueprint $table) {
            $table->foreign('domain_id')
                ->references('id')
                ->on('domains')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('advertisements', function (Blueprint $table) {
            $table->dropForeign(['domain_id']);
            $table->dropColumn('domain_id');

            // rollback enum
            $table->enum('position', [
                'top',
                'middle',
                'bottom',
            ])->change();
        });
    }
};
