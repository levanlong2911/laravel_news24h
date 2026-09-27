<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('video_visual_identities')
            ->whereNull('subject_key')
            ->update(['subject_key' => 'master_vessel']);

        Schema::table('video_visual_identities', function (Blueprint $table): void {
            $table->string('subject_key', 96)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('video_visual_identities', function (Blueprint $table): void {
            $table->string('subject_key', 96)->nullable()->change();
        });
    }
};
