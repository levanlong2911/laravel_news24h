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
        Schema::create('news_webs', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // 🔗 Liên kết category
            $table->uuid('category_id');

            // 🌐 Domain chính
            $table->string('domain');

            // 🔗 URL cụ thể (optional, ví dụ: heavy.com/patriots)
            $table->string('base_url')->nullable();

            // ⭐ flags
            $table->boolean('is_active')->default(true);
            $table->boolean('is_trusted')->default(false);
            $table->boolean('is_blocked')->default(false);

            $table->timestamps();

            // 🔐 FK
            $table->foreign('category_id')
                ->references('id')
                ->on('categories')
                ->onDelete('cascade');

            // 🚫 tránh trùng domain trong 1 category
            $table->unique(['category_id', 'domain']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_webs');
    }
};
