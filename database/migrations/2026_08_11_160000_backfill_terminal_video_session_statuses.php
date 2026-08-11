<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('video_sessions')
            ->where('status', 'rendering')
            ->pluck('id')
            ->each(function (string $sessionId): void {
                $statuses = DB::table('video_shots')
                    ->where('session_id', $sessionId)
                    ->pluck('status');

                if ($statuses->contains('failed')) {
                    DB::table('video_sessions')->where('id', $sessionId)->update(['status' => 'failed']);

                    return;
                }

                if (! $statuses->contains('queued') && $statuses->contains('rendered')) {
                    DB::table('video_sessions')->where('id', $sessionId)->update(['status' => 'done']);
                }
            });
    }

    public function down(): void
    {
        // Statuses may have changed after deployment; reversing them would corrupt live state.
    }
};
