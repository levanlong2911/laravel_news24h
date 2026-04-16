<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_contexts', function (Blueprint $table) {
            $table->unsignedInteger('sample_size')->default(0)->after('performance_score');
        });
    }

    public function down(): void
    {
        Schema::table('category_contexts', function (Blueprint $table) {
            $table->dropColumn('sample_size');
        });
    }
};
