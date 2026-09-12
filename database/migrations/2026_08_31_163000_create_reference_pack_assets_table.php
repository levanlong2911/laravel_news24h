<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_pack_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('reference_pack_id')->index();
            $table->string('view_id', 120);
            $table->string('role', 80);
            $table->unsignedInteger('order');
            $table->boolean('required')->default(true);
            $table->json('camera_json');
            $table->json('additional_required_paths')->nullable();
            $table->string('status', 32)->index();
            $table->uuid('render_id')->nullable()->index();
            $table->string('artifact_id', 120)->nullable();
            $table->char('artifact_hash', 64)->nullable();
            $table->string('artifact_storage_key', 1024)->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->uuid('qa_run_id')->nullable()->index();
            $table->char('qa_report_hash', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(['reference_pack_id', 'view_id']);
            $table->unique(['reference_pack_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reference_pack_assets');
    }
};
