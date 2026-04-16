<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // Exact duplicate detection (SHA-256 of normalized content)
            // UNIQUE index → MySQL tự reject INSERT thứ 2 khi race condition
            $table->char('content_hash', 64)->nullable()->unique()->after('content');

            // Near-duplicate detection (32-bit SimHash, Hamming distance check)
            // Index để load batch nhanh, so sánh trong PHP
            $table->unsignedInteger('content_simhash')->nullable()->index()->after('content_hash');

            // POST Guard flag: bài cần human review (hallucination confidence < threshold)
            $table->boolean('human_review')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropUnique(['content_hash']);
            $table->dropIndex(['content_simhash']);
            $table->dropColumn(['content_hash', 'content_simhash', 'human_review']);
        });
    }
};
