<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE video_planning_stages MODIFY thinking_tokens INT UNSIGNED NULL DEFAULT NULL');
    }

    public function down(): void
    {
        DB::statement('UPDATE video_planning_stages SET thinking_tokens = 0 WHERE thinking_tokens IS NULL');
        DB::statement('ALTER TABLE video_planning_stages MODIFY thinking_tokens INT UNSIGNED NOT NULL DEFAULT 0');
    }
};
