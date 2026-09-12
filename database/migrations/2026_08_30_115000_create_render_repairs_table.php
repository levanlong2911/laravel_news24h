<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('render_repairs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('qa_run_id')->index();
            $table->uuid('parent_render_id')->index();
            $table->uuid('repair_render_id')->nullable()->index();
            $table->unsignedInteger('repair_generation');
            $table->string('status', 40);
            $table->string('repair_scope_id', 100);
            $table->char('source_qa_report_hash', 64);
            $table->char('parent_request_hash', 64);
            $table->char('parent_artifact_hash', 64);
            $table->json('repair_plan_json');
            $table->char('repair_request_hash', 64)->nullable();
            $table->timestampsTz();

            $table->unique(['qa_run_id', 'repair_scope_id'], 'qa_repair_scope_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('render_repairs');
    }
};
