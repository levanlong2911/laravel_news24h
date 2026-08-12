<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('video_sessions')
            ->where('status', 'done')
            ->pluck('id')
            ->each(function (string $sessionId): void {
                $statuses = DB::table('video_shots')
                    ->where('session_id', $sessionId)
                    ->pluck('status');

                $awaitingAction = ['draft', 'approved', 'needs_revision', 'queued'];

                if ($statuses->intersect($awaitingAction)->isNotEmpty()) {
                    DB::table('video_sessions')->where('id', $sessionId)->update(['status' => 'reviewing']);
                }
            });
    }

    public function down(): void
    {
        // Statuses may have changed after deployment; reversing them would corrupt live state.
    }
};
