<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $framework = DB::table('prompt_frameworks')->where('name', 'nfl_sports')->first();

        if (!$framework) {
            return;
        }

        $phase3 = $framework->phase3_generate;

        // Fix 1 (Issue #2): Strengthen final sentence rule — ban speculative "Whether X" closings
        $phase3 = str_replace(
            '• Final sentence: forward-looking fact or open consequence' . "\n" . '  Never philosophical. Never preachy.',
            '• Final sentence: must state a concrete next fact (date, action, roster status, or outcome).' . "\n"
            . '  Speculative closings are banned: "whether [X] will", "remains to be seen", "remains the only question", "only [X] can answer".' . "\n"
            . '  Never philosophical. Never preachy.',
            $phase3
        );

        // Fix 2 (Issue #3): Add explicit causal chain instruction after "earn its place" rule
        $phase3 = str_replace(
            '• Every sentence must earn its place — if it only restates what the previous said, cut it.',
            '• Every sentence must earn its place — if it only restates what the previous said, cut it.' . "\n"
            . '• Causal chain: when the source explicitly states A triggered B, connect them in direct sequence.' . "\n"
            . '  Bad: "Wilson was released. Lloyd was signed." Good: "Wilson\'s release opened the roster spot Lloyd now fills."',
            $phase3
        );

        DB::table('prompt_frameworks')
            ->where('name', 'nfl_sports')
            ->update(['phase3_generate' => $phase3]);
    }

    public function down(): void
    {
        // Content patches are not safely reversible
    }
};
