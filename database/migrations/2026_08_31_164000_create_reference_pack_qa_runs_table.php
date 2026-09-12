<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_pack_qa_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reference_pack_id')->index();
            $table->string('status', 32)->index();
            $table->string('decision', 32)->nullable()->index();
            $table->char('request_hash', 64)->nullable();
            $table->char('report_hash', 64)->nullable();
            $table->json('request_json')->nullable();
            $table->json('report_json')->nullable();
            $table->unsignedInteger('hard_fail_count')->default(0);
            $table->unsignedInteger('soft_fail_count')->default(0);
            $table->unsignedInteger('uncertain_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_pack_qa_runs');
    }
};
