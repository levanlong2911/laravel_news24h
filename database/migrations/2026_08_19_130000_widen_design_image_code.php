<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE video_design_images CHANGE image_code image_code VARCHAR(100) NOT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE video_design_images CHANGE image_code image_code VARCHAR(60) NOT NULL');
    }
};
