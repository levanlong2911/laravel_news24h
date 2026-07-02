<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_outputs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('project_id')->constrained('video_projects')->cascadeOnDelete();
            $table->string('video_path', 600)->nullable();
            $table->string('thumbnail_path', 600)->nullable();
            $table->string('youtube_video_id', 30)->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->string('status', 30)->default('pending'); // pending | rendering | complete | failed
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_outputs');
    }
};
