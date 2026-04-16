<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Fix 4: thêm 'review' vào lifecycle status của article
    // pending → processing → review | published | failed
    // 'review' = PostGuard flagged hoặc soft-duplicate → cần duyệt thủ công

    public function up(): void
    {
        DB::statement("ALTER TABLE articles MODIFY COLUMN status
            ENUM('pending','processing','review','published','failed')
            NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        // Migrate 'review' rows → 'published' trước khi shrink enum
        DB::statement("UPDATE articles SET status = 'published' WHERE status = 'review'");
        DB::statement("ALTER TABLE articles MODIFY COLUMN status
            ENUM('pending','processing','published','failed')
            NOT NULL DEFAULT 'pending'");
    }
};
