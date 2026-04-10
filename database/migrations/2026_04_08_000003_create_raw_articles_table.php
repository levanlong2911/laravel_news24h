<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->uuid('keyword_id');
            $table->foreign('keyword_id')->references('id')->on('keywords')->cascadeOnDelete();

            // Dữ liệu gốc từ Google News / SerpAPI
            $table->string('title', 500);
            $table->text('url');
            $table->char('url_hash', 32)->unique(); // MD5(url) — dedup
            $table->text('snippet')->nullable();
            $table->string('source', 200)->nullable();
            $table->string('source_icon', 500)->nullable();
            $table->string('thumbnail', 500)->nullable();

            // Scoring signals
            $table->unsignedSmallInteger('viral_score')->default(0);
            $table->unsignedTinyInteger('position')->default(99);
            $table->string('published_date', 100)->nullable(); // "2 hours ago", "Apr 8"
            $table->unsignedTinyInteger('stories_count')->default(0);
            $table->boolean('top_story')->default(false);

            // Link tới bài AI đã viết (khi status=done)
            $table->uuid('article_id')->nullable();
            $table->foreign('article_id')->references('id')->on('articles')->nullOnDelete();

            // Trạng thái pipeline
            $table->enum('status', ['pending', 'generating', 'done', 'failed'])->default('pending');

            // TTL 24h — tự xóa bởi model:prune
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->index('keyword_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index(['viral_score', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_articles');
    }
};
