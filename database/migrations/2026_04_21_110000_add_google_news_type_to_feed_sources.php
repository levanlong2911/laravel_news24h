<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL ENUM requires full column redefinition to add a value
        DB::statement("ALTER TABLE feed_sources MODIFY COLUMN fetch_type ENUM('rss','crawl','google_news') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE feed_sources MODIFY COLUMN fetch_type ENUM('rss','crawl') NOT NULL");
    }
};
