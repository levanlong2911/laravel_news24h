<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const SEP = "══════════════════════════════════════════"; // U+2550 × 42

    private const DOMAINS = [
        'motorsport' => [
            'label' => 'MOTORSPORT',
            'laws'  => [
                '• Race-broadcast cadence: short sentences, high tempo. No lingering on background.',
                '• Technical vocabulary needs no definition: "DRS", "pit window", "sector time", "understeer", "tyre deg" — use without explanation.',
                '• Championship context: when a result shifts standings, state the gap in points.',
                '• Sequence over narrative: lead with the race moment, not the post-race summary.',
            ],
            'forbidden' => '"dominant victory", "crashed out" (say "retired" or name the incident), "made history" without naming the record, "motorsport fans will"',
        ],
        'luxury_assets' => [
            'label' => 'LUXURY & ASSETS',
            'laws'  => [
                '• Write peer-to-peer, never aspirational. Reader is a collector or buyer, not a spectator.',
                '• Specs speak: price, provenance, limited run, materials — no superlatives needed.',
                '• Understatement is credibility. "2.8M. Three ever made." beats "an eye-watering price tag."',
                '• Attribution always: maker, auction house, designer, year — specific, every time.',
            ],
            'forbidden' => '"shocking price", "incredible", "affordable" (never for luxury), "rich" (say "collector" or "buyer"), "jaw-dropping"',
        ],
        'lifestyle_living' => [
            'label' => 'LIFESTYLE & LIVING',
            'laws'  => [
                '• Write from inside the culture, not as a reporter observing it.',
                '• Specific beats generic: a named road, a real dish, a place with a reputation — not "the perfect destination."',
                '• Let the reader project in: details that place them there, not facts that describe the scene.',
                '• Community undercurrent: readers share a sensibility — write as one of them, not about them.',
            ],
            'forbidden' => '"lifestyle trend", "more and more people", "you need to try", "life-changing experience", "bucket list"',
        ],
        'knowledge_discovery' => [
            'label' => 'KNOWLEDGE & DISCOVERY',
            'laws'  => [
                '• Reader implication first: what this means for them, before what was found.',
                '• Scale analogies make abstract numbers real: "equivalent to 40 minutes of sunlight" beats "2.3 × 10⁻⁴ joules."',
                '• Precision over certainty: "the study found", "in trial conditions" — never "this proves" or "scientists say."',
                '• Name the mechanism only if the source explains it — never invent causality.',
            ],
            'forbidden' => '"breakthrough", "miracle", "cure", "scientists discover" (name what they found), "could revolutionize", "game-changer"',
        ],
        'entertainment_viral' => [
            'label' => 'ENTERTAINMENT & VIRAL',
            'laws'  => [
                '• Wit over enthusiasm: one precise observation beats three exclamation points.',
                '• Cultural context, not reaction count: what makes this moment matter — not how many people reacted.',
                '• Specificity kills vagueness: name the clip, the line, the moment — never "the scene everyone is talking about."',
                '• Never punch down. Irony is welcome; mockery is not.',
            ],
            'forbidden' => '"the internet reacted", "fans went wild", "broke the internet", "everyone is talking about", "you won\'t believe"',
        ],
        'individual_sports' => [
            'label' => 'INDIVIDUAL SPORTS',
            'laws'  => [
                '• Mental game is physical: form streak, pressure, and head-to-head record belong beside the score.',
                '• Ranking and tournament context always: a result means nothing without draw position or ranking gap.',
                '• Head-to-head record is a fact — cite it when it exists, not only when it favors the narrative.',
                '• Write the career moment inside the match moment: what was at stake in this specific game.',
            ],
            'forbidden' => '"choke" (name the errors), "fairytale run", "destiny", "all-or-nothing" as a generic phrase, "dream final"',
        ],
    ];

    public function up(): void
    {
        foreach (self::DOMAINS as $name => $domain) {
            $fw = DB::table('prompt_frameworks')->where('name', $name)->first();
            if (!$fw) continue;

            $phase3 = $fw->phase3_generate;
            if (str_contains($phase3, 'DOMAIN LAWS')) continue;

            $laws  = implode("\n", $domain['laws']);
            $label = $domain['label'];
            $block = "\n\nDOMAIN LAWS — {$label}\n" . self::SEP . "\n{$laws}\n\n"
                   . "FORBIDDEN ({$label} — add to global list):\n{$domain['forbidden']}";

            // Insert block before the ══ separator that immediately precedes FACT INTEGRITY
            $factPos = strpos($phase3, 'FACT INTEGRITY');
            if ($factPos === false) continue;

            $sepStart = strrpos(substr($phase3, 0, $factPos), self::SEP);
            if ($sepStart === false) continue;

            $phase3 = substr($phase3, 0, $sepStart) . $block . "\n\n" . substr($phase3, $sepStart);

            DB::table('prompt_frameworks')
                ->where('name', $name)
                ->update(['phase3_generate' => $phase3]);
        }
    }

    public function down(): void {}
};
