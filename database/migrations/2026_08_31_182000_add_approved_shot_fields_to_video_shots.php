<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->uuid('approved_qa_report_id')->nullable()->index();
            $table->string('approved_artifact_hash', 64)->nullable();
            $table->uuid('approved_by_admin_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('video_shots', function (Blueprint $table) {
            $table->dropColumn([
                'approved_qa_report_id',
                'approved_artifact_hash',
                'approved_by_admin_id',
            ]);
        });
    }
};
