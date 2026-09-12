<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_locks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('video_project_id')->index();
            $table->uuid('canonical_concept_revision_id')->nullable()->index();
            $table->char('canonical_hash', 64);
            $table->uuid('approved_anchor_id')->index();
            $table->uuid('reference_pack_id')->index();
            $table->unsignedInteger('identity_lock_version');
            $table->json('manifest_json');
            $table->longText('hash_payload_json');
            $table->char('identity_lock_hash', 64)->unique();
            $table->string('status', 32)->index();
            $table->uuid('frozen_by')->nullable()->index();
            $table->timestamp('frozen_at')->nullable()->index();
            $table->timestamp('superseded_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['video_project_id', 'identity_lock_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_locks');
    }
};
