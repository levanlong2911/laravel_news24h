<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // liên kết post gốc
            $table->uuid('post_id');

            // nội dung đã AI xử lý
            $table->string('title');
            $table->text('content');

            // SEO
            $table->string('slug')->unique();
            $table->string('meta_description', 255)->nullable();
            $table->json('keywords')->nullable();

            // optional nâng cao
            $table->text('summary')->nullable();
            $table->json('faq')->nullable();

            $table->boolean('is_published')->default(false);

            $table->timestamps();

            // FK
            $table->foreign('post_id')
                ->references('id')
                ->on('posts')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
