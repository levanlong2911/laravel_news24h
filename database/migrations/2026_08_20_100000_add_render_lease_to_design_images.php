<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->string('worker_id', 100)->nullable()->after('status');
            $table->char('claim_token', 36)->nullable()->after('worker_id');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
            $table->timestamp('lease_expires_at')->nullable()->after('claimed_at');
            $table->timestamp('queued_at')->nullable()->after('lease_expires_at');
            $table->text('render_error')->nullable()->after('queued_at');
            $table->index(['status', 'lease_expires_at'], 'video_design_images_status_lease_index');
        });
    }

    public function down(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->dropIndex('video_design_images_status_lease_index');
            $table->dropColumn([
                'worker_id', 'claim_token', 'claimed_at',
                'lease_expires_at', 'queued_at', 'render_error',
            ]);
        });
    }
};
