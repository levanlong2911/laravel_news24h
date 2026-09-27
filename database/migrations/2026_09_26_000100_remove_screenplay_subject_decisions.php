<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('video_review_decisions')
            ->where('entity_type', 'screenplay_subject')
            ->delete();
    }

    public function down(): void
    {
        // Deleted review decisions cannot be reconstructed safely.
    }
};
