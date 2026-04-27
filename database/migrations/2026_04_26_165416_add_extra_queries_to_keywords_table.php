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
        Schema::table('keywords', function (Blueprint $table) {
            $table->json('extra_queries')->nullable()->after('search_keyword');
        });
    }

    public function down(): void
    {
        Schema::table('keywords', function (Blueprint $table) {
            $table->dropColumn('extra_queries');
        });
    }
};
