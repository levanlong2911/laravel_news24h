<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->default(DB::raw('(UUID())'))->primary(); // UUID thay vì ID tự tăng
            $table->string('title');  // Tiêu đề bài viết
            $table->text('content');  // Nội dung bài viết
            $table->string('slug')->unique();  // Slug (URL thân thiện)
            $table->string('thumbnail')->nullable(); // Ảnh đại diện (thumbnail)
            $table->uuid('category_id');
            $table->foreign('category_id')->references('id')->on('categories')->onDelete('cascade');  // Mã danh mục ->onDelete('cascade')
            $table->uuid('author_id');
            $table->foreign('author_id')->references('id')->on('admins');  // Tác giả bài viết
            $table->boolean('is_active')->default(true);  // Trạng thái bài viết
            $table->timestamp('created_at')->default(DB::raw('CURRENT_TIMESTAMP')); // Thời gian tạo
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP')); // Thời gian cập nhật
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
