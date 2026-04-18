<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('raw_articles', function (Blueprint $table) {
            $table->unsignedSmallInteger('fb_score')->default(0)->after('viral_score');
        });
    }

    public function down(): void
    {
        Schema::table('raw_articles', function (Blueprint $table) {
            $table->dropColumn('fb_score');
        });
    }
};
