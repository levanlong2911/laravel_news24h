<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('url')->nullable();                  // homepage URL (cho crawl)
            $table->string('rss_url', 500)->nullable();        // RSS / RSSHub URL
            $table->enum('fetch_type', ['rss', 'crawl']);
            $table->string('crawl_selector')->nullable();       // XPath / CSS cho crawl
            $table->unsignedSmallInteger('fetch_interval_minutes')->default(60);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_fetched_at')->nullable();
            $table->unsignedInteger('total_fetched')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_sources');
    }
};
