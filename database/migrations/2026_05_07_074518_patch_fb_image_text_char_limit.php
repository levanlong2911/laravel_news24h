<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Apply to all active frameworks — FB_IMAGE_TEXT is a universal writing rule
        $frameworks = DB::table('prompt_frameworks')->where('is_active', true)->get();

        $old = 'FB_IMAGE_TEXT:
- 1 sentence 60-90 chars. Use a strong active verb (steal, flip, crash, betray, collapse).
- Technique: Hook + Tension — reveal half the story, keep the best part hidden.
- Name the threat or rivalry — let reader feel stakes without knowing outcome.
- No emoji. Write in plain text only.
- GOOD: \'Miami Could Steal A.J. Brown Before Patriots Get a Shot\'
-       \'Southwest just made Alaska Airlines nervous\'
-       \'One phone call is about to change everything for Patriots fans.\'
- BAD: Two separate fact statements. Literal numbers/dates. Restate the headline. Using emoji.
- Write in the same language as the article.';

        $new = 'FB_IMAGE_TEXT:
- 1 sentence 70-100 chars. Use a strong active verb (steal, flip, crash, betray, collapse).
- Technique: Hook + Tension — reveal half the story, keep the best part hidden.
- MUST include the person\'s full name or team name — never use "a player", "a back", "the team", or any anonymous reference.
- Name the specific threat, rivalry, or irony — let reader feel stakes without knowing outcome.
- No emoji. Write in plain text only.
- GOOD: \'Green Bay is still betting on MarShawn Lloyd — who played one NFL game in two years.\'
-       \'Miami Could Steal A.J. Brown Before Patriots Get a Shot\'
-       \'Southwest just made Alaska Airlines nervous\'
- BAD: "Green Bay is still banking on a back who played once." — anonymous, no proper name.
- BAD: Two separate fact statements. Literal numbers/dates. Restate the headline. Using emoji.
- Write in the same language as the article.';

        foreach ($frameworks as $fw) {
            $phase3 = str_replace($old, $new, $fw->phase3_generate);

            // Fallback: str_replace may miss frameworks with encoding differences (e.g. travel_mobility)
            // If section still has old char limit, replace the entire FB_IMAGE_TEXT block via position
            if (str_contains($phase3, '1 sentence 60-90') || !str_contains($phase3, 'MUST include')) {
                $start = strpos($phase3, 'FB_IMAGE_TEXT:');
                $next  = strpos($phase3, "\nFB_POST_CONTENT:", $start ?: 0);
                if ($start !== false && $next !== false) {
                    $phase3 = substr($phase3, 0, $start) . $new . "\n\n" . ltrim(substr($phase3, $next));
                }
            }

            $phase3 = preg_replace(
                '/\[ \] FB image text:[^\n]+/u',
                '[ ] FB image text: 70-100 chars, proper name required, Hook + Tension, no anonymous reference',
                $phase3
            );

            DB::table('prompt_frameworks')
                ->where('id', $fw->id)
                ->update(['phase3_generate' => $phase3]);
        }
    }

    public function down(): void {}
};
