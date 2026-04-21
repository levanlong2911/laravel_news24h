<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('feed_source_id')->constrained('feed_sources')->cascadeOnDelete();
            $table->foreignUuid('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('title');
            $table->string('url', 2000);
            $table->char('url_hash', 32)->unique();            // md5(url) — dedup
            $table->string('thumbnail', 2000)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->longText('raw_content')->nullable();       // crawled content
            $table->enum('status', ['pending', 'processing', 'done', 'failed'])->default('pending');
            $table->string('error_message')->nullable();
            $table->foreignUuid('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->timestamps();

            $table->index(['category_id', 'status']);
            $table->index(['feed_source_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_items');
    }
};
