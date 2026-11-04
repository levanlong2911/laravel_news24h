<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->longText('render_queue')->charset('utf8mb4')->collation('utf8mb4_unicode_ci')->nullable()->after('render_error');
        });
    }

    public function down(): void
    {
        Schema::table('video_design_images', function (Blueprint $table) {
            $table->dropColumn('render_queue');
        });
    }
};
