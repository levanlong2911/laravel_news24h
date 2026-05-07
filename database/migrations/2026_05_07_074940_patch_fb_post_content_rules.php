<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD = 'FB_POST_CONTENT:
• 60-150 chars MAX. No bullet points. No lists. Plain paragraphs only.
• Structure: Hook → Tension → Hidden fact
• Reveal MAX 2 facts — keep the most specific or surprising fact hidden.
• Never use conclusion words: obvious, clear, certain, confirmed.
• "Changes everything" is BANNED — say WHAT changes instead.
• Name the threat or rivalry in the first line — never bury the conflict.
• No emoji. No URL. No hashtags. Same language as article.
• No CTA. Do NOT end with "Find out", "Read more", "Click", "Discover", or any call-to-action.';

    private const NEW = 'FB_POST_CONTENT:
• 150-250 chars. Plain text only. No bullet points, emoji, URL, hashtags. Same language as article.
• Formula: [Named person/team] + [Specific stake] + [Pressure/tension] + [Withheld outcome]
  Step 1 — Identify exactly who is affected and what they stand to lose or gain.
  Step 2 — Introduce pressure: deadline, injuries, rival move, contract risk, or expectations.
  Step 3 — Soft CTA: end with a sentence that makes the reader feel they need the outcome.
  No explicit instructions ("Find out", "Read more", "Click", "Discover"). Choose one technique:
    Deadline        → "The window closes Thursday."
    Withheld ID     → "One team nobody expected is already in contact."
    Stakes fork     → "Sign him now — or lose him to a rival for nothing."
    Teased fact     → "Sirmans answered one question. His answer reframes the entire picture."
    Question        → "Which team made the call nobody expected?"
    Incomplete fact → "One number explains why Green Bay kept Lloyd over Wilson."
    Insider signal  → "What Sirmans said off-script tells you everything about his 2026 ceiling."
    Stakes gap      → "The roster math tells a very different story."
• Lean into uncertainty, upside, pressure, or controversy naturally.
• Be specific. Avoid generic suspense ("everything could change", "a decision to make").
• Avoid generic phrasing: "major update", "huge news", "shocking development", "fans stunned".
• GOOD: "Smith demanded a trade. His team has 48 hours and $8M to respond — or lose him for nothing."
• GOOD: "Lloyd is on the roster. Wilson is in Seattle. Training camp answers the only question left."
• BAD: "Big changes coming soon." — no name, no stake, no tension.
• BAD: "Find out what happens next." — explicit command, zero information.
• Do NOT use explicit CTAs: "Find out", "Read more", "Click", "Discover", or any direct instruction.';

    public function up(): void
    {
        $frameworks = DB::table('prompt_frameworks')->where('is_active', true)->get();

        foreach ($frameworks as $fw) {
            $phase3 = str_replace(self::OLD, self::NEW, $fw->phase3_generate);

            // Fallback: str_replace may miss frameworks with encoding differences (e.g. travel_mobility)
            // If section still has old char limit, replace entire FB_POST_CONTENT block via position
            if (str_contains($phase3, '60-150 chars MAX') || !str_contains($phase3, 'Soft CTA')) {
                $start = strpos($phase3, 'FB_POST_CONTENT:');
                $next  = strpos($phase3, 'QUALITY GATE', $start ?: 0);
                if ($start !== false && $next !== false) {
                    $sep    = strrpos(substr($phase3, 0, $next), "\n");
                    $phase3 = substr($phase3, 0, $start) . self::NEW . "\n\n" . ltrim(substr($phase3, $sep));
                }
            }

            $phase3 = preg_replace(
                '/\[ \] FB post:[^\n]+/u',
                '[ ] FB post: 150-250 chars, named + specific stake + pressure + withheld outcome, no generic phrases, no CTA',
                $phase3
            );

            DB::table('prompt_frameworks')
                ->where('id', $fw->id)
                ->update(['phase3_generate' => $phase3]);
        }
    }

    public function down(): void {}
};
