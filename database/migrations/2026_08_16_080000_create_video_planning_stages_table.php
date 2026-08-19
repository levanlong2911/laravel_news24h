<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_planning_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->foreign('session_id')->references('id')->on('video_sessions')->cascadeOnDelete();

            $table->unsignedInteger('planning_revision')->default(0);
            $table->string('stage', 24);
            $table->unique(['session_id', 'planning_revision', 'stage']);

            $table->string('status', 12)->default('pending');

            $table->longText('input_json')->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->longText('raw_response')->nullable();
            $table->longText('output_json')->nullable();
            $table->char('output_hash', 64)->nullable();

            $table->uuid('claim_token')->nullable();
            $table->timestamp('claimed_at')->nullable();

            $table->string('model', 40)->nullable();
            $table->string('instruction_version', 40)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);

            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('video_sessions', function (Blueprint $table) {
            $table->unsignedInteger('planning_revision')->default(0)->after('plan_revision');
        });
    }

    public function down(): void
    {
        Schema::table('video_sessions', function (Blueprint $table) {
            $table->dropColumn('planning_revision');
        });

        Schema::dropIfExists('video_planning_stages');
    }
};
