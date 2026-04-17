<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            // 1-2 câu ngắn để paste lên ảnh bìa (Canva, tool làm ảnh)
            // Claude viết tự nhiên — câu hay nhất hoặc fact nổi bật nhất của bài
            $table->text('fb_image_text')->nullable()->after('thumbnail');

            // Câu trích dẫn trực tiếp nổi bật nhất nếu bài có quote đáng dùng
            $table->text('fb_quote')->nullable()->after('fb_image_text');

            // Caption sẵn sàng paste lên Facebook (không có link)
            // 2 dòng đầu hook trước "Xem thêm", cuối là CTA phù hợp content type
            $table->text('fb_post_content')->nullable()->after('fb_quote');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['fb_image_text', 'fb_quote', 'fb_post_content']);
        });
    }
};
