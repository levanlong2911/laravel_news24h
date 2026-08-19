<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đuôi vòng đời — bốn chặng cuối HOÀN TOÀN chưa có chỗ chứa.
 *
 * Đo trước khi viết: `grep voice|tts|audio|music` trong app/ ra 0 kết quả liên
 * quan; `youtube_video_id` / `facebook_post_id` có sẵn 4 cột trong video_finals
 * nhưng KHÔNG dòng code nào ghi vào chúng.
 *
 * Bốn cột cứng đó không mở rộng được: thêm một nền tảng là một ALTER TABLE. Ở đây
 * mỗi nền tảng là MỘT HÀNG trong video_publications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_audio_tracks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('video_sessions')->cascadeOnDelete();
            $table->uuid('artifact_id')->nullable();
            $table->foreign('artifact_id')->references('id')->on('video_artifacts')->nullOnDelete();
            $table->string('track_type', 20);                         // voiceover|music|sfx|ambient
            $table->string('scene_id', 60)->nullable();               // null = trải cả video
            $table->string('source_type', 20)->default('ai_generated'); // upload|ai_generated|library
            $table->string('provider', 40)->nullable();
            $table->string('voice_id', 80)->nullable();
            $table->text('script_text')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->decimal('volume', 5, 2)->nullable();
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('status', 20)->default('draft');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'track_type']);
        });

        Schema::create('video_subtitles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('final_id');
            $table->foreign('final_id')->references('id')->on('video_finals')->cascadeOnDelete();
            $table->uuid('artifact_id')->nullable();
            $table->foreign('artifact_id')->references('id')->on('video_artifacts')->nullOnDelete();
            $table->string('language_code', 10);
            $table->string('type', 20)->default('auto');              // auto|manual|ai_generated
            $table->string('format', 10)->default('srt');             // srt|vtt|ass
            $table->timestamps();

            $table->unique(['final_id', 'language_code', 'format']);
        });

        // Khoá vào `final_id` chứ KHÔNG vào final_render: đăng là đăng CẢ video,
        // không đăng một clip trong timeline.
        Schema::create('video_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('final_id');
            $table->foreign('final_id')->references('id')->on('video_finals')->cascadeOnDelete();
            $table->string('platform', 30);                           // youtube|tiktok|facebook|instagram|website
            $table->string('platform_item_id', 255)->nullable();
            $table->string('external_url', 500)->nullable();
            $table->string('title', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('scheduled');       // scheduled|uploading|published|failed|archived
            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['final_id', 'platform']);
        });

        Schema::create('video_publication_metrics', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('publication_id');
            $table->foreign('publication_id')->references('id')->on('video_publications')->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('watch_time_sec')->default(0);
            $table->decimal('retention_rate', 6, 4)->nullable();
            $table->decimal('ctr', 6, 4)->nullable();
            $table->decimal('revenue', 12, 4)->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            // Một hàng cho một ngày — chạy lại job thu số liệu không sinh bản trùng.
            $table->unique(['publication_id', 'metric_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_publication_metrics');
        Schema::dropIfExists('video_publications');
        Schema::dropIfExists('video_subtitles');
        Schema::dropIfExists('video_audio_tracks');
    }
};
