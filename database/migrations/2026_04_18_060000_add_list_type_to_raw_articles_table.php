<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_articles', function (Blueprint $table) {
            $table->enum('list_type', ['top', 'recent'])->default('top')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('raw_articles', function (Blueprint $table) {
            $table->dropColumn('list_type');
        });
    }
};
