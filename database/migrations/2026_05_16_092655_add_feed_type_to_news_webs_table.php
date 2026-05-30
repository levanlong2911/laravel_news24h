<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_webs', function (Blueprint $table) {
            $table->enum('feed_type', ['rss', 'sitemap', 'none'])
                  ->default('none')
                  ->after('rss_url');
        });
    }

    public function down(): void
    {
        Schema::table('news_webs', function (Blueprint $table) {
            $table->dropColumn('feed_type');
        });
    }
};
