<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('articles');

        Schema::create('articles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Keyword liên kết
            $table->uuid('keyword_id');
            $table->foreign('keyword_id')
                ->references('id')
                ->on('keywords')
                ->cascadeOnDelete();

            // Nguồn gốc (Google News)
            $table->text('source_url');
            $table->char('source_url_hash', 32)->unique(); // MD5(source_url) — dedup
            $table->string('source_title', 500);
            $table->string('source_name', 100)->nullable();

            // Nội dung đã AI xử lý
            $table->string('title', 500);
            $table->string('slug')->unique();
            $table->string('meta_description', 255)->nullable();
            $table->longText('content');
            $table->text('summary')->nullable();

            // Scoring
            $table->unsignedSmallInteger('viral_score')->default(0);

            // Trạng thái pipeline
            $table->enum('status', ['pending', 'processing', 'published', 'failed'])
                ->default('pending');

            // TTL — tự xóa sau 48h
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('keyword_id');
            $table->index('status');
            $table->index('expires_at');
            $table->index(['viral_score', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
