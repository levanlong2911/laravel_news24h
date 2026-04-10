<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain')->unique();            // espn.com
            $table->string('name')->nullable();            // ESPN
            $table->enum('type', ['trusted', 'blocked']); // trusted / blocked
            $table->string('category')->nullable();        // Sports, News, Tech...
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_sources');
    }
};
