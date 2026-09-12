<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('render_attempts', function (Blueprint $table) {
            $table->string('provider_job_id', 160)->nullable()->index();
            $table->json('provider_submit_response_json')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('render_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'provider_job_id',
                'provider_submit_response_json',
            ]);
        });
    }
};
