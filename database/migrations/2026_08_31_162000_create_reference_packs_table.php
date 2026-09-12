<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_packs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_project_id')->index();
            $table->uuid('approved_anchor_id')->index();
            $table->uuid('canonical_concept_revision_id')->nullable()->index();
            $table->char('canonical_hash', 64);
            $table->string('pack_version', 80);
            $table->json('pack_json');
            $table->char('pack_hash', 64)->unique();
            $table->string('status', 32)->index();
            $table->uuid('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('latest_qa_run_id')->nullable()->index();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_packs');
    }
};
