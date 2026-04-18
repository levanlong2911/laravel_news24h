<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_articles', function (Blueprint $table) {
            $table->text('thumbnail')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('raw_articles', function (Blueprint $table) {
            $table->string('thumbnail', 500)->nullable()->change();
        });
    }
};
