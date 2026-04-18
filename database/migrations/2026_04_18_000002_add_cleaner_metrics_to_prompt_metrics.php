<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->float('cleaner_reduction_ratio')->nullable()->after('word_count');
            $table->boolean('used_haiku')->default(true)->after('cleaner_reduction_ratio');
        });
    }

    public function down(): void
    {
        Schema::table('prompt_metrics', function (Blueprint $table) {
            $table->dropColumn(['cleaner_reduction_ratio', 'used_haiku']);
        });
    }
};
