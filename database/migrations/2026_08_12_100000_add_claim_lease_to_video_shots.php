<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->string('worker_id', 100)->nullable()->after('status');
            // Mot token cho CA BATCH claim, khong phai moi dong — nhieu shot
            // cung mot request claim se mang chung mot claim_token. Vi vay
            // KHONG unique: chi index thuong de tra cuu nhanh theo token.
            $table->uuid('claim_token')->nullable()->after('worker_id');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');

            $table->index(['status', 'session_id', 'created_at']);
            $table->index('claim_token');
            $table->index(['status', 'lease_expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->dropIndex(['status', 'session_id', 'created_at']);
            $table->dropIndex(['claim_token']);
            $table->dropIndex(['status', 'lease_expires_at']);
            $table->dropColumn(['worker_id', 'claim_token', 'claimed_at', 'lease_expires_at']);
        });
    }
};
