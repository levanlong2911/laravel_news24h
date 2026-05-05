<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('claude_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->string('admin_id');
            $table->string('title')->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->string('action')->default('send_to_claude'); // send_to_claude | synthesize
            $table->timestamps();

            $table->foreign('admin_id')->references('id')->on('admins')->onDelete('cascade');
            $table->index(['admin_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claude_usage_logs');
    }
};
