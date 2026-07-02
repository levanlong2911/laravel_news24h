<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('media_jobs', function (Blueprint $table) {
            // Output artifacts produced by Python MediaFactoryWorker.
            // Supports multi-output: video.mp4, poster.jpg, thumbnail.webp, preview.gif, etc.
            // Stored as an array of {type, url, size_bytes?} objects.
            $table->json('outputs')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('media_jobs', function (Blueprint $table) {
            $table->dropColumn('outputs');
        });
    }
};
