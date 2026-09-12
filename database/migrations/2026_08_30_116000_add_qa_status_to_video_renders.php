<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_renders', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_renders', 'qa_status')) {
                $table->string('qa_status', 40)->nullable()->index();
            }

            if (! Schema::hasColumn('video_renders', 'latest_qa_run_id')) {
                $table->uuid('latest_qa_run_id')->nullable();
            }

            if (! Schema::hasColumn('video_renders', 'qa_approved')) {
                $table->boolean('qa_approved')->default(false);
            }
        });
    }

    public function down(): void
    {
        Schema::table('video_renders', function (Blueprint $table): void {
            foreach (['qa_status', 'latest_qa_run_id', 'qa_approved'] as $column) {
                if (Schema::hasColumn('video_renders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
