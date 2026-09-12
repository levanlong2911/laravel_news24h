<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_renders', function (Blueprint $table) {
            $table->string('provider_job_id', 160)->nullable()->index();
            $table->unsignedInteger('provider_poll_count')->default(0);
            $table->timestamp('provider_last_polled_at')->nullable();
            $table->timestamp('provider_next_poll_at')->nullable()->index();
            $table->json('provider_submit_response_json')->nullable();
            $table->json('provider_last_poll_response_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('video_renders', function (Blueprint $table) {
            $table->dropColumn([
                'provider_job_id',
                'provider_poll_count',
                'provider_last_polled_at',
                'provider_next_poll_at',
                'provider_submit_response_json',
                'provider_last_poll_response_json',
            ]);
        });
    }
};
