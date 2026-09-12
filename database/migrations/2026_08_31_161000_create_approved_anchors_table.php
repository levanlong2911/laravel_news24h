<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approved_anchors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_project_id')->index();
            $table->uuid('canonical_concept_revision_id')->nullable()->index();
            $table->char('canonical_hash', 64);
            $table->char('projection_hash', 64);
            $table->char('constraint_set_hash', 64);
            $table->char('prompt_spec_hash', 64);
            $table->uuid('render_id')->unique();
            $table->char('render_request_hash', 64);
            $table->string('artifact_id', 120);
            $table->char('artifact_hash', 64);
            $table->string('artifact_storage_key', 1024);
            $table->string('mime_type', 80);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->uuid('qa_run_id')->index();
            $table->char('qa_report_hash', 64);
            $table->string('status', 32)->index();
            $table->uuid('approved_by')->index();
            $table->timestamp('approved_at')->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approved_anchors');
    }
};
