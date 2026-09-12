<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_decisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('canonical_concept_revision_id');
            $table->string('target_path', 255);
            $table->string('decision_type', 60);
            $table->json('value');
            $table->string('provenance_origin', 30)->nullable();
            $table->json('source_aspects')->nullable();
            $table->json('invariant_ids')->nullable();
            $table->json('relationship_ids')->nullable();
            $table->string('origin', 30);
            $table->timestampsTz();

            $table->unique(
                ['canonical_concept_revision_id', 'target_path'],
                'canonical_decision_revision_path_uq'
            );
            $table->index(
                ['canonical_concept_revision_id', 'decision_type'],
                'canonical_decision_revision_type_idx'
            );

            $table
                ->foreign('canonical_concept_revision_id', 'canonical_decision_revision_fk')
                ->references('id')
                ->on('canonical_concept_revisions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_decisions');
    }
};
