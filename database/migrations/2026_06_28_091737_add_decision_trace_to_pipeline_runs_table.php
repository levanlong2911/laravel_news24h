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
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->json('decision_trace')->nullable()->after('output_json');
            $table->json('validation_errors')->nullable()->after('decision_trace');
        });
    }

    public function down(): void
    {
        Schema::table('pipeline_runs', function (Blueprint $table) {
            $table->dropColumn(['decision_trace', 'validation_errors']);
        });
    }
};
