<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_json_schemas', function (Blueprint $table) {
            $table->string('name')->after('version');
        });

        Schema::table('ai_phase_schemas', function (Blueprint $table) {
            $table->timestamp('created_at')->nullable()->after('is_required');
        });
    }

    public function down(): void
    {
        Schema::table('ai_phase_schemas', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });

        Schema::table('ai_json_schemas', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
