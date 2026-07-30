<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Áp lại 3 migration batch 12 đã no-op, và mở rộng DOMAIN LAWS ra đủ 8 framework.
 *
 * Cùng nguyên nhân với 2026_07_30_000001: cả ba chạy khi prompt_frameworks còn
 * rỗng nên không đụng được bản ghi nào, nhưng vẫn ghi là đã apply:
 *
 *   073835_patch_nfl_framework_phase3        → luật câu kết + chuỗi nhân quả (nfl_sports)
 *   085105_differentiate_framework_phase3    → DOMAIN LAWS (6/8 framework)
 *   062627_patch_fact_integrity_add_name_rule → luật không đổi tên riêng (mọi framework)
 *
 * Bổ sung so với bản gốc: 085105 bỏ sót nfl_sports và travel_mobility. Hai bộ luật
 * đó viết mới ở đây theo đúng khuôn 6 domain sẵn có, để cả 8 mảng đều có giọng
 * riêng thay vì dùng chung một giọng.
 *
 * Idempotent: mỗi bước tự bỏ qua nếu nội dung đã có mặt.
 */
return new class extends Migration
{
    private const SEP = '══════════════════════════════════════════';

    private const NAME_ANCHOR = '  - locations or event names';

    private const NAME_RULE = "  - proper names: never expand, alter, or substitute a name not in the source\n"
        . "    (e.g. source says 'Wilson' → write 'Wilson', not 'Russell Wilson' or any other Wilson)\n"
        . "    (e.g. source says 'Payton Wilson' → never change to 'Russell Wilson' or any other person)";

    private const NFL_CLOSING_OLD = "• Final sentence: forward-looking fact or open consequence\n  Never philosophical. Never preachy.";

    private const NFL_CLOSING_NEW = "• Final sentence: must state a concrete next fact (date, action, roster status, or outcome).\n"
        . "  Speculative closings are banned: \"whether [X] will\", \"remains to be seen\", \"remains the only question\", \"only [X] can answer\".\n"
        . "  Never philosophical. Never preachy.";

    private const NFL_CAUSAL_OLD = '• Every sentence must earn its place — if it only restates what the previous said, cut it.';

    private const NFL_CAUSAL_NEW = '• Every sentence must earn its place — if it only restates what the previous said, cut it.' . "\n"
        . '• Causal chain: when the source explicitly states A triggered B, connect them in direct sequence.' . "\n"
        . '  Bad: "Wilson was released. Lloyd was signed." Good: "Wilson\'s release opened the roster spot Lloyd now fills."';

    private const DOMAINS = [
        'nfl_sports' => [
            'label' => 'NFL',
            'laws'  => [
                '• Cap and contract figures are the story, not decoration: give the number, the term, and the guaranteed portion when the source has them.',
                '• Position decides significance: 70 rushing yards means different things for a rookie back and a franchise back — say which.',
                '• Seeding and playoff maths belong in the stakes as an actual number, never as "huge implications".',
                '• Fantasy and betting angles are real reader value — include them only where the source supports a concrete read.',
            ],
            'forbidden' => '"must-win" without naming what is lost, "war in the trenches", "wants it more", "gutsy performance", "statement win"',
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
        'travel_mobility' => [
            'label' => 'TRAVEL & MOBILITY',
            'laws'  => [
                '• Reader value is operational: route, date, cabin, price, availability — a story missing all of these is not a travel story.',
                '• Name the aircraft, the airport code, the square footage, the build cost. Vague scale is useless to someone planning.',
                '• Compare against the realistic alternative the reader already has, not against a theoretical best.',
                '• Disruption and safety reporting stays factual and calm — passengers read these while stranded.',
            ],
            'forbidden' => '"hidden gem", "game-changer for travellers", "dream home", "paradise", "you can now finally"',
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
    ];

    public function up(): void
    {
        $nameRule = 0;
        $nfl      = 0;
        $laws     = [];

        foreach (DB::table('prompt_frameworks')->get() as $fw) {
            $phase3 = $fw->phase3_generate;

            // ── 1. Luật tên riêng (062627) — mọi framework ───────────────────
            if (str_contains($phase3, self::NAME_ANCHOR) && !str_contains($phase3, 'proper names: never expand')) {
                $phase3 = str_replace(self::NAME_ANCHOR, self::NAME_ANCHOR . "\n" . self::NAME_RULE, $phase3);
                $nameRule++;
            }

            // ── 2. Luật riêng nfl_sports (073835) ────────────────────────────
            if ($fw->name === 'nfl_sports') {
                if (str_contains($phase3, self::NFL_CLOSING_OLD)) {
                    $phase3 = str_replace(self::NFL_CLOSING_OLD, self::NFL_CLOSING_NEW, $phase3);
                    $nfl++;
                }
                if (!str_contains($phase3, '• Causal chain:')) {
                    $phase3 = str_replace(self::NFL_CAUSAL_OLD, self::NFL_CAUSAL_NEW, $phase3);
                    $nfl++;
                }
            }

            // ── 3. DOMAIN LAWS (085105, mở rộng đủ 8) ────────────────────────
            if (isset(self::DOMAINS[$fw->name]) && !str_contains($phase3, 'DOMAIN LAWS')) {
                $inserted = $this->insertDomainLaws($phase3, self::DOMAINS[$fw->name]);
                if ($inserted !== null) {
                    $phase3 = $inserted;
                    $laws[] = $fw->name;
                }
            }

            if ($phase3 !== $fw->phase3_generate) {
                DB::table('prompt_frameworks')->where('id', $fw->id)->update(['phase3_generate' => $phase3]);
            }
        }

        echo "  luật tên riêng : {$nameRule} framework\n";
        echo "  luật nfl_sports: {$nfl} thay thế\n";
        echo '  DOMAIN LAWS    : ' . count($laws) . ' framework (' . implode(', ', $laws) . ")\n";
    }

    public function down(): void
    {
        foreach (DB::table('prompt_frameworks')->get() as $fw) {
            $phase3 = $fw->phase3_generate;

            if (isset(self::DOMAINS[$fw->name])) {
                $phase3 = str_replace($this->buildBlock(self::DOMAINS[$fw->name]) . "\n\n", '', $phase3);
            }

            if ($fw->name === 'nfl_sports') {
                $phase3 = str_replace(self::NFL_CLOSING_NEW, self::NFL_CLOSING_OLD, $phase3);
                $phase3 = str_replace(self::NFL_CAUSAL_NEW, self::NFL_CAUSAL_OLD, $phase3);
            }

            $phase3 = str_replace("\n" . self::NAME_RULE, '', $phase3);

            if ($phase3 !== $fw->phase3_generate) {
                DB::table('prompt_frameworks')->where('id', $fw->id)->update(['phase3_generate' => $phase3]);
            }
        }
    }

    /**
     * Chèn khối DOMAIN LAWS ngay trước đường kẻ mở đầu mục FACT INTEGRITY.
     * Trả về null nếu không tìm được mốc — để caller biết mà bỏ qua thay vì
     * cắt dán nhầm chỗ.
     */
    private function insertDomainLaws(string $phase3, array $domain): ?string
    {
        $factPos = strpos($phase3, 'FACT INTEGRITY');
        if ($factPos === false) {
            return null;
        }

        $sepStart = strrpos(substr($phase3, 0, $factPos), self::SEP);
        if ($sepStart === false) {
            return null;
        }

        return substr($phase3, 0, $sepStart)
            . $this->buildBlock($domain) . "\n\n"
            . substr($phase3, $sepStart);
    }

    private function buildBlock(array $domain): string
    {
        return "\n\nDOMAIN LAWS — {$domain['label']}\n"
            . self::SEP . "\n"
            . implode("\n", $domain['laws']) . "\n\n"
            . "FORBIDDEN ({$domain['label']} — add to global list):\n"
            . $domain['forbidden'];
    }
};
