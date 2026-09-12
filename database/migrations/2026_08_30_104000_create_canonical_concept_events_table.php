<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_concept_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('canonical_concept_revision_id');
            $table->string('event_type', 60);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->json('metadata')->nullable();
            $table->string('event_key', 160)->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->unique(
                ['canonical_concept_revision_id', 'event_key'],
                'canonical_event_revision_key_uq'
            );

            $table->index(
                ['canonical_concept_revision_id', 'occurred_at'],
                'canonical_event_revision_time_idx'
            );

            $table
                ->foreign('canonical_concept_revision_id', 'canonical_event_revision_fk')
                ->references('id')
                ->on('canonical_concept_revisions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_concept_events');
    }
};
