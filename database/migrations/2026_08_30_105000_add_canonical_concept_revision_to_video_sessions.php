<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_sessions', function (Blueprint $table): void {
            $table->uuid('canonical_concept_revision_id')->nullable()->after('project_id');
            $table
                ->foreign('canonical_concept_revision_id', 'video_sessions_canonical_revision_fk')
                ->references('id')
                ->on('canonical_concept_revisions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table): void {
            $table->dropForeign('video_sessions_canonical_revision_fk');
            $table->dropColumn('canonical_concept_revision_id');
        });
    }
};
