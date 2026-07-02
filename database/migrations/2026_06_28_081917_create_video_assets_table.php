<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('video_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('shot_id')->constrained('video_shots')->cascadeOnDelete();
            $table->string('asset_type', 30)->default('image'); // image | video_clip
            $table->string('provider', 50)->nullable();         // fal_flux | kling | stock
            $table->text('remote_url')->nullable();
            $table->string('local_path', 600)->nullable();
            $table->string('status', 30)->default('pending');   // pending | generating | ready | failed
            $table->json('meta')->nullable();                   // cost, width, height, duration
            $table->timestamps();

            $table->index(['shot_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_assets');
    }
};
