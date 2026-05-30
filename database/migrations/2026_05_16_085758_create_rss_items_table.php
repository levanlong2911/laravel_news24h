<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rss_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('news_web_id');
            $table->uuid('article_id')->nullable();
            $table->string('title');
            $table->string('url');
            $table->string('url_hash', 32)->unique();
            $table->string('image')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->enum('status', ['pending', 'done'])->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('news_web_id')->references('id')->on('news_webs')->onDelete('cascade');
            $table->foreign('article_id')->references('id')->on('articles')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rss_items');
    }
};
