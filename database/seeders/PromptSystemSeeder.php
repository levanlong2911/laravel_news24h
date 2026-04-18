<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\CategoryContext;
use App\Models\FrameworkContentType;
use App\Models\PromptFramework;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * PromptSystemSeeder — seeds all prompt infrastructure.
 *
 * Creates:
 *   8 prompt_frameworks   (one per content domain group)
 *   48 framework_content_types  (6 per framework × 8)
 *   15 categories         (updateOrCreate by slug)
 *   15 category_contexts  (one per category, linked to correct framework)
 *
 * Safe to re-run (updateOrCreate on all records).
 */
class PromptSystemSeeder extends Seeder
{
    public function run(): void
    {
        $frameworks = $this->seedFrameworks();
        $this->seedCategoryContexts($frameworks);
    }

    // ── Phase 1: Frameworks + Content Types ───────────────────────────────────

    private function seedFrameworks(): array
    {
        $data = $this->frameworkDefinitions();
        $created = [];

        foreach ($data as $def) {
            $framework = PromptFramework::updateOrCreate(
                ['name' => $def['name']],
                [
                    'group_description' => $def['group_description'],
                    'system_prompt'     => $def['system_prompt'],
                    'phase1_analyze'    => $def['phase1_analyze'],
                    'phase2_diagnose'   => $def['phase2_diagnose'],
                    'phase3_generate'   => $def['phase3_generate'],
                    'version'           => 1,
                    'is_active'         => true,
                ]
            );

            foreach ($def['content_types'] as $i => $ct) {
                FrameworkContentType::updateOrCreate(
                    ['framework_id' => $framework->id, 'type_code' => $ct['type_code']],
                    [
                        'type_name'           => $ct['type_name'],
                        'trigger_keywords'    => $ct['trigger_keywords'],
                        'tone_profile'        => $ct['tone_profile'],
                        'structure_template'  => $ct['structure_template'],
                        'sort_order'          => $i + 1,
                        'is_active'           => $ct['is_active'] ?? true,
                        'applicability_score' => $ct['applicability_score'] ?? 1.0,
                    ]
                );
            }

            $created[$def['name']] = $framework->id;
            $this->command->info("Framework: {$def['name']} ({$framework->id})");
        }

        return $created;
    }

    // ── Phase 2: Categories + Contexts ────────────────────────────────────────

    private function seedCategoryContexts(array $frameworkIds): void
    {
        foreach ($this->categoryContextDefinitions($frameworkIds) as $def) {
            $category = Category::updateOrCreate(
                ['name' => $def['name']],
                ['slug' => $def['slug']]
            );

            CategoryContext::updateOrCreate(
                ['category_id' => $category->id],
                [
                    'framework_id'        => $def['framework_id'],
                    'domain'              => $def['domain'],
                    'audience'            => $def['audience'],
                    'terminology'         => $def['terminology'],
                    'tone_notes'          => $def['tone_notes'],
                    'hook_style'          => $def['hook_style'],
                    'custom_type_triggers'=> $def['custom_type_triggers'] ?? null,
                    'performance_score'   => 0,
                    'sample_size'         => 0,
                    'is_active'           => true,
                ]
            );

            $this->command->info("Context: {$def['name']} → {$def['domain']}");
        }
    }

    // ═══════════════════════════════════════════════════════════════════════════
    // Framework Definitions
    // ═══════════════════════════════════════════════════════════════════════════

    private function frameworkDefinitions(): array
    {
        $phase1 = $this->sharedPhase1();
        $phase2 = $this->sharedPhase2();

        return [

            // ── 1. NFL Sports ──────────────────────────────────────────────────
            [
                'name'              => 'nfl_sports',
                'group_description' => 'National Football League — America\'s most-watched sport',
                'system_prompt'     => 'You are an elite NFL journalist with deep knowledge of football strategy, salary cap mechanics, and fantasy sports implications. Write with authority, passion, and precision. Every article must have a clear narrative arc and actionable insight for fans, bettors, and fantasy players.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'victory',
                        'type_name'          => 'Victory & Triumph',
                        'trigger_keywords'   => ['win', 'wins', 'won', 'victory', 'champion', 'championship', 'beat', 'defeated', 'clinched', 'playoffs', 'super bowl', 'title'],
                        'tone_profile'       => ['cinematic', 'triumphant', 'earned'],
                        'structure_template' => "① HOOK — Open with the decisive moment or final score\n② CONTEXT — What was at stake; recent history between the teams\n③ PERFORMANCE — Key stats, standout plays, turning points\n④ REACTION — Quotes from QB, coach, key players\n⑤ SIGNIFICANCE — Playoff implications, records broken, what's next",
                        'sort_order'         => 1,
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'defeat',
                        'type_name'          => 'Defeat & Collapse',
                        'trigger_keywords'   => ['loss', 'loses', 'lost', 'defeated', 'eliminated', 'blown lead', 'collapsed', 'fall', 'fell'],
                        'tone_profile'       => ['analytical', 'honest', 'forward-looking'],
                        'structure_template' => "① HOOK — The moment the game turned or the final blow\n② CONTEXT — Expectations going in; what went wrong early\n③ BREAKDOWN — Key mistakes, stats, missed opportunities\n④ REACTION — Player/coach accountability\n⑤ PATH FORWARD — What must change, next matchup implications",
                        'sort_order'         => 2,
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'injury',
                        'type_name'          => 'Injury Report',
                        'trigger_keywords'   => ['injured', 'injury', 'out', 'IR', 'placed on', 'knee', 'shoulder', 'hamstring', 'concussion', 'surgery', 'week-to-week'],
                        'tone_profile'       => ['urgent', 'factual', 'empathetic'],
                        'structure_template' => "① HOOK — Who, what injury, confirmed timeline\n② IMPACT — Depth chart disruption, replacement options\n③ HISTORY — Player's injury record, return track record\n④ TEAM RESPONSE — Coach/GM quotes, roster moves\n⑤ OUTLOOK — Playoff implications, fantasy advice",
                        'sort_order'         => 3,
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'trade',
                        'type_name'          => 'Trade & Signing',
                        'trigger_keywords'   => ['signed', 'trade', 'traded', 'released', 'free agent', 'contract', 'deal', 'acquisition', 'extension', 'cut'],
                        'tone_profile'       => ['analytical', 'strategic', 'insider'],
                        'structure_template' => "① HOOK — Player, teams, deal details in one punchy line\n② WHY — Both sides' motivation and what they gain\n③ FIT — How player fits new system; historical comps\n④ REACTION — Analyst takes, insider quotes, fan pulse\n⑤ IMPACT — Playoff picture, salary cap, fantasy value",
                        'sort_order'         => 4,
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'drama',
                        'type_name'          => 'Drama & Controversy',
                        'trigger_keywords'   => ['drama', 'controversy', 'holdout', 'fined', 'suspended', 'benched', 'conflict', 'feud', 'dispute', 'arrested'],
                        'tone_profile'       => ['measured', 'balanced', 'factual'],
                        'structure_template' => "① HOOK — The controversy/conflict in one line\n② BACKGROUND — What led to this moment\n③ POSITIONS — All sides of the story with evidence\n④ FALLOUT — Immediate consequences, suspensions, fines\n⑤ RESOLUTION — Current status, what comes next",
                        'sort_order'         => 5,
                        'applicability_score'=> 0.8,
                    ],
                    [
                        'type_code'          => 'spotlight',
                        'type_name'          => 'Player Spotlight',
                        'trigger_keywords'   => ['record', 'milestone', 'stats', 'season', 'career', 'all-time', 'historic', 'best ever', 'performance', 'mvp'],
                        'tone_profile'       => ['celebratory', 'analytical', 'storytelling'],
                        'structure_template' => "① HOOK — The milestone, record, or stat achievement\n② CAREER CONTEXT — Journey to this moment\n③ THE NUMBERS — Key stats broken down simply\n④ COMPARISONS — Peers, historical benchmarks\n⑤ WHAT'S NEXT — Next milestone in reach, season targets",
                        'sort_order'         => 6,
                        'applicability_score'=> 0.9,
                    ],
                ],
            ],

            // ── 2. Individual Sports ───────────────────────────────────────────
            [
                'name'              => 'individual_sports',
                'group_description' => 'Individual athletic competitions — Tennis, Boxing, MMA, Golf, Athletics',
                'system_prompt'     => 'You are a world-class sports journalist covering individual athletic competitions. You understand the mental and physical demands of solo competition, ranking systems, and the storylines that captivate global audiences. Write with narrative depth, spotlighting the human behind the athlete.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'victory',
                        'type_name'          => 'Match Victory',
                        'trigger_keywords'   => ['wins', 'won', 'victory', 'champion', 'title', 'gold', 'defeated', 'beat', 'knockout', 'KO', 'submission', 'birdie'],
                        'tone_profile'       => ['triumphant', 'personal', 'dramatic'],
                        'structure_template' => "① HOOK — The winning moment; final score/result\n② MATCH STORY — Key turning points, momentum shifts\n③ PERFORMANCE — Stats, technique highlights, mental fortitude\n④ QUOTES — Winner's reflection, opponent's acknowledgment\n⑤ RANKING IMPACT — Standings change, what's next in the draw/season",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'defeat',
                        'type_name'          => 'Upset & Defeat',
                        'trigger_keywords'   => ['loses', 'lost', 'defeated', 'eliminated', 'exit', 'upset', 'shock', 'knocked out', 'falls'],
                        'tone_profile'       => ['honest', 'empathetic', 'analytical'],
                        'structure_template' => "① HOOK — The shock of the result; scoreline\n② WHAT HAPPENED — Key moments, tactical errors\n③ ATHLETE REACTION — Post-match quotes, body language\n④ CONTEXT — Career at this stage; opponent's quality\n⑤ WHAT'S NEXT — Ranking impact, upcoming schedule",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'injury',
                        'type_name'          => 'Injury & Withdrawal',
                        'trigger_keywords'   => ['injured', 'withdrawal', 'retired hurt', 'medical', 'pulled out', 'scratch', 'muscle', 'wrist', 'back', 'ankle'],
                        'tone_profile'       => ['sympathetic', 'factual', 'forward-looking'],
                        'structure_template' => "① HOOK — Athlete, injury, immediate impact on competition\n② THE MOMENT — How the injury occurred or was revealed\n③ MEDICAL STATUS — Official timeline, severity assessment\n④ CAREER CONTEXT — Previous injuries, resilience track record\n⑤ IMPACT — Tournament draw changes, season implications",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'comeback',
                        'type_name'          => 'Comeback Story',
                        'trigger_keywords'   => ['comeback', 'return', 'returns', 'back from', 'recovery', 'redemption', 'retired', 'unretired', 'comeback trail'],
                        'tone_profile'       => ['inspiring', 'emotional', 'narrative-driven'],
                        'structure_template' => "① HOOK — The return moment and why it matters\n② THE FALL — What forced them away; the low point\n③ THE JOURNEY — Recovery, training, mental transformation\n④ THE RETURN — Performance data; early signs\n⑤ THE STAKES — Can they reclaim former glory? Expert views",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'rivalry',
                        'type_name'          => 'Rivalry & Clash',
                        'trigger_keywords'   => ['rivalry', 'rival', 'face off', 'clash', 'showdown', 'vs', 'versus', 'rematch', 'grudge match', 'head to head'],
                        'tone_profile'       => ['electric', 'historical', 'anticipatory'],
                        'structure_template' => "① HOOK — This rivalry in one defining sentence\n② HISTORY — H2H record, key past encounters\n③ CURRENT FORM — Both athletes right now\n④ WHAT'S AT STAKE — Ranking, title, legacy\n⑤ PREDICTION — Expert takes, statistical edge",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'spotlight',
                        'type_name'          => 'Athlete Spotlight',
                        'trigger_keywords'   => ['record', 'milestone', 'ranked', 'ranking', 'career best', 'personal best', 'world number', 'legend', 'greatest'],
                        'tone_profile'       => ['celebratory', 'in-depth', 'personal'],
                        'structure_template' => "① HOOK — The achievement that defines this moment\n② ORIGIN — Where they came from; early career\n③ THE PEAK — Career highlights, defining wins\n④ THE NUMBERS — Stats, rankings, records\n⑤ LEGACY — Where they stand historically",
                        'applicability_score'=> 0.85,
                    ],
                ],
            ],

            // ── 3. Motorsport ──────────────────────────────────────────────────
            [
                'name'              => 'motorsport',
                'group_description' => 'High-speed racing — Formula 1, NASCAR, IndyCar, MotoGP',
                'system_prompt'     => 'You are a motorsport journalist with expert knowledge of race strategy, aerodynamics, and the politics of the paddock. Write with the energy of a race broadcast — fast-paced, technically accurate, yet accessible to casual fans. Every article must convey speed, drama, and the high-stakes nature of motorsport.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'victory',
                        'type_name'          => 'Race Victory',
                        'trigger_keywords'   => ['wins', 'won', 'victory', 'podium', 'checkered flag', 'first place', 'champion', 'pole to win', 'dominant'],
                        'tone_profile'       => ['fast-paced', 'triumphant', 'technical'],
                        'structure_template' => "① HOOK — Winner, track, margin of victory\n② RACE STORY — Lap-by-lap turning points\n③ STRATEGY — Pit stop calls, tire choices that won it\n④ REACTION — Winner quote, team principal response\n⑤ CHAMPIONSHIP — Points table impact, title fight status",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'crash',
                        'type_name'          => 'Crash & Retirement',
                        'trigger_keywords'   => ['crash', 'accident', 'DNF', 'retirement', 'collision', 'wall', 'out', 'barrier', 'safety car', 'red flag', 'shunt'],
                        'tone_profile'       => ['urgent', 'factual', 'safety-conscious'],
                        'structure_template' => "① HOOK — Incident, lap number, drivers involved\n② THE INCIDENT — What happened on track\n③ CAUSE — Technical failure, driver error, or racing incident\n④ DRIVER STATUS — Medical update if relevant\n⑤ CHAMPIONSHIP IMPACT — Points lost, title implications",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'strategy',
                        'type_name'          => 'Race Strategy',
                        'trigger_keywords'   => ['strategy', 'pit stop', 'undercut', 'overcut', 'tires', 'tyres', 'soft', 'medium', 'hard', 'pit lane', 'virtual safety car'],
                        'tone_profile'       => ['analytical', 'technical', 'insider'],
                        'structure_template' => "① HOOK — The strategy call that changed the race\n② THE SITUATION — Track position, tire life, gap to leader\n③ THE DECISION — What was chosen and why\n④ OUTCOME — Did it work? Key moments after\n⑤ EXPERT ANALYSIS — Was it the right call? Alternatives",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'championship',
                        'type_name'          => 'Championship Battle',
                        'trigger_keywords'   => ['championship', 'standings', 'points', 'title', 'gap', 'leaders', 'world champion', 'constructors'],
                        'tone_profile'       => ['dramatic', 'analytical', 'anticipatory'],
                        'structure_template' => "① HOOK — Current standings and the key number\n② THE BATTLE — Top contenders and their trajectories\n③ REMAINING RACES — Points available, circuits ahead\n④ SCENARIOS — What each driver needs\n⑤ PREDICTION — Expert consensus and statistical edge",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'drama',
                        'type_name'          => 'Controversy & Drama',
                        'trigger_keywords'   => ['penalty', 'protest', 'controversy', 'stewards', 'DQ', 'disqualified', 'radio message', 'team orders', 'dispute'],
                        'tone_profile'       => ['balanced', 'investigative', 'fair'],
                        'structure_template' => "① HOOK — The incident and immediate reaction\n② WHAT HAPPENED — Factual account with timing\n③ RULES — Relevant regulation explained clearly\n④ THE VERDICT — Official decision and rationale\n⑤ FALLOUT — Impact on standings, team relationships",
                        'applicability_score'=> 0.8,
                    ],
                    [
                        'type_code'          => 'spotlight',
                        'type_name'          => 'Driver/Team Spotlight',
                        'trigger_keywords'   => ['qualifying', 'pole position', 'fastest lap', 'lap record', 'debut', 'milestone', 'historic', 'first ever'],
                        'tone_profile'       => ['celebratory', 'narrative', 'technical'],
                        'structure_template' => "① HOOK — The achievement and why it stands out\n② CONTEXT — Career trajectory to this moment\n③ THE PERFORMANCE — Key data, lap times, comparisons\n④ REACTION — Driver, team, rival quotes\n⑤ LEGACY — What this means for their place in history",
                        'applicability_score'=> 0.85,
                    ],
                ],
            ],

            // ── 4. Luxury Assets ──────────────────────────────────────────────
            [
                'name'              => 'luxury_assets',
                'group_description' => 'Premium vehicles and yachts — Supercars & Superyachts',
                'system_prompt'     => 'You are a luxury lifestyle journalist covering the world\'s most exclusive vehicles and yachts. Write for high-net-worth readers who demand both technical precision and aspirational storytelling. Emphasize craftsmanship, exclusivity, performance specifications, and the lifestyle that comes with ownership.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'reveal',
                        'type_name'          => 'New Model Reveal',
                        'trigger_keywords'   => ['unveiled', 'reveal', 'debut', 'new model', 'launched', 'introduces', 'world premiere', 'new', 'next generation'],
                        'tone_profile'       => ['excited', 'authoritative', 'aspirational'],
                        'structure_template' => "① HOOK — The reveal moment; what makes it significant\n② DESIGN — Exterior and interior highlights\n③ SPECS — Power, performance figures, key technology\n④ EXCLUSIVITY — Price, production numbers, availability\n⑤ VERDICT — How it compares to rivals; who should want it",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'specs',
                        'type_name'          => 'Performance & Specs Deep Dive',
                        'trigger_keywords'   => ['horsepower', 'hp', 'top speed', '0-60', 'acceleration', 'engine', 'torque', 'length', 'beam', 'displacement', 'bhp'],
                        'tone_profile'       => ['technical', 'precise', 'enthusiast'],
                        'structure_template' => "① HOOK — The headline spec that defines this vehicle\n② POWERTRAIN — Engine, output, drivetrain details\n③ PERFORMANCE — 0–60, top speed, handling metrics\n④ TECH — Notable engineering innovations\n⑤ COMPARISON — How it stacks up against class rivals",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'acquisition',
                        'type_name'          => 'Sale & Ownership',
                        'trigger_keywords'   => ['sold', 'sale', 'buyer', 'purchased', 'owned by', 'collection', 'auction', 'record price', 'charter'],
                        'tone_profile'       => ['exclusive', 'factual', 'aspirational'],
                        'structure_template' => "① HOOK — The transaction and the headline figure\n② THE ASSET — Key specs and provenance\n③ THE STORY — Why it was sold; notable buyer/seller\n④ VALUATION — Market context; why this price\n⑤ LIFESTYLE — What ownership of this means",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'comparison',
                        'type_name'          => 'Head-to-Head Comparison',
                        'trigger_keywords'   => ['vs', 'versus', 'compared', 'rivals', 'better than', 'against', 'competitor', 'comparison'],
                        'tone_profile'       => ['analytical', 'balanced', 'decisive'],
                        'structure_template' => "① HOOK — The matchup and why it matters\n② DESIGN BATTLE — Visual and interior comparison\n③ PERFORMANCE — Side-by-side specs and track times\n④ EXCLUSIVITY — Price, rarity, prestige\n⑤ VERDICT — Clear winner and who each suits",
                        'applicability_score'=> 0.8,
                    ],
                    [
                        'type_code'          => 'lifestyle',
                        'type_name'          => 'Lifestyle & Celebrity',
                        'trigger_keywords'   => ['celebrity', 'billionaire', 'millionaire', 'owner', 'spotted', 'garage', 'fleet', 'superyacht trip', 'lifestyle'],
                        'tone_profile'       => ['aspirational', 'voyeuristic', 'glamorous'],
                        'structure_template' => "① HOOK — The person and their notable asset\n② THE STORY — Context behind this purchase/sighting\n③ THE ASSET — What makes this vehicle/yacht special\n④ THE LIFESTYLE — What this ownership signals\n⑤ MARKET CONTEXT — Trend this represents",
                        'applicability_score'=> 0.85,
                    ],
                    [
                        'type_code'          => 'review',
                        'type_name'          => 'First Drive / Sea Trial Review',
                        'trigger_keywords'   => ['review', 'first drive', 'test drive', 'sea trial', 'hands on', 'behind the wheel', 'we drove', 'first look'],
                        'tone_profile'       => ['experiential', 'authoritative', 'sensory'],
                        'structure_template' => "① HOOK — First impression in one visceral sentence\n② DESIGN IN PERSON — How it looks and feels up close\n③ THE EXPERIENCE — Driving/sailing sensations, standout moments\n④ THE NUMBERS — Does performance match the promise?\n⑤ VERDICT — Worth the price? Who it's really for",
                        'applicability_score'=> 0.9,
                    ],
                ],
            ],

            // ── 5. Travel & Mobility ──────────────────────────────────────────
            [
                'name'              => 'travel_mobility',
                'group_description' => 'Commercial aviation and alternative living — Airlines & Tiny Homes',
                'system_prompt'     => 'You are a travel and lifestyle journalist covering modern mobility — from premium aviation experiences to the minimalist tiny home movement. Your audience seeks both practical travel intelligence and inspiring alternative lifestyle content. Write with a sense of discovery, practicality, and authentic lifestyle insight.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'route',
                        'type_name'          => 'New Route & Destination',
                        'trigger_keywords'   => ['new route', 'new flight', 'launches', 'announces', 'direct flight', 'destination', 'service begins', 'expanded'],
                        'tone_profile'       => ['informative', 'travel-savvy', 'excited'],
                        'structure_template' => "① HOOK — Route, airline, launch date in one line\n② THE DESTINATION — What makes this route valuable\n③ SERVICE DETAILS — Frequency, aircraft, pricing tier\n④ COMPETITION — Other ways to get there, rival airlines\n⑤ TRAVELLER IMPACT — Who benefits most; booking tips",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'service',
                        'type_name'          => 'Premium Service & Product',
                        'trigger_keywords'   => ['first class', 'business class', 'lounge', 'suite', 'upgrade', 'product', 'seat', 'amenity', 'tiny home feature', 'design'],
                        'tone_profile'       => ['aspirational', 'detailed', 'experiential'],
                        'structure_template' => "① HOOK — The service/product and the experience promise\n② THE DETAILS — Specs, features, what's included\n③ EXPERIENCE — What it actually feels like\n④ COMPARISON — How it ranks against alternatives\n⑤ VERDICT — Worth it? Who should book/buy",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'deal',
                        'type_name'          => 'Travel Deal & Pricing',
                        'trigger_keywords'   => ['deal', 'discount', 'sale', 'fare', 'price', 'cheap', 'affordable', 'offer', 'promotion', 'flash sale'],
                        'tone_profile'       => ['urgent', 'practical', 'helpful'],
                        'structure_template' => "① HOOK — The deal, the saving, the deadline\n② THE DETAILS — Routes, dates, conditions\n③ HOW TO BOOK — Step-by-step guide\n④ FINE PRINT — Restrictions, blackout dates, fees\n⑤ VERDICT — Is it genuinely good value?",
                        'applicability_score'=> 0.7,
                    ],
                    [
                        'type_code'          => 'review',
                        'type_name'          => 'Experience Review',
                        'trigger_keywords'   => ['review', 'rated', 'best', 'ranked', 'award', 'tested', 'I tried', 'we stayed', 'flight review', 'tiny home tour'],
                        'tone_profile'       => ['honest', 'experiential', 'balanced'],
                        'structure_template' => "① HOOK — The experience and the headline verdict\n② ARRIVAL — First impressions\n③ THE EXPERIENCE — Moment-by-moment highlights\n④ THE LOWLIGHTS — What didn't work\n⑤ FINAL VERDICT — Score, who it's for, book/skip",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'incident',
                        'type_name'          => 'Incident & Disruption',
                        'trigger_keywords'   => ['cancelled', 'delay', 'grounded', 'disruption', 'incident', 'emergency', 'diverted', 'turbulence', 'problem'],
                        'tone_profile'       => ['factual', 'calm', 'passenger-focused'],
                        'structure_template' => "① HOOK — What happened, where, who was affected\n② THE FACTS — Verified account of the incident\n③ CAUSE — Official statement vs known facts\n④ PASSENGER IMPACT — Refunds, rebooking, stranded\n⑤ AIRLINE/COMPANY RESPONSE — Official action taken",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'trend',
                        'type_name'          => 'Lifestyle Trend',
                        'trigger_keywords'   => ['trend', 'growing', 'surge', 'popular', 'minimalist', 'off-grid', 'sustainable', 'movement', 'generation'],
                        'tone_profile'       => ['cultural', 'forward-looking', 'human'],
                        'structure_template' => "① HOOK — The trend and why it's happening now\n② THE DATA — Numbers showing the growth\n③ THE PEOPLE — Real stories from people living this\n④ WHY NOW — Economic, social, cultural drivers\n⑤ WHERE IT'S GOING — Expert forecast, challenges ahead",
                        'applicability_score'=> 0.9,
                    ],
                ],
            ],

            // ── 6. Lifestyle Living ───────────────────────────────────────────
            [
                'name'              => 'lifestyle_living',
                'group_description' => 'Motorcycle culture and community identity — Harley Davidson & touring',
                'system_prompt'     => 'You are a motorcycle culture journalist who understands the brotherhood of the road. Write for passionate riders who live the lifestyle — not just enthusiasts who read about it. Capture the spirit of freedom, community, craftsmanship, and the open road. Technical accuracy matters; so does soul.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'new_model',
                        'type_name'          => 'New Bike Launch',
                        'trigger_keywords'   => ['new model', 'new bike', 'launch', 'release', 'unveiled', '2024', '2025', '2026', 'lineup', 'introduces'],
                        'tone_profile'       => ['enthusiastic', 'technical', 'community-aware'],
                        'structure_template' => "① HOOK — The new model and the promise it makes\n② DESIGN — Styling, color options, heritage nods\n③ ENGINE & SPECS — Displacement, power, torque, technology\n④ RIDING FEEL — What early impressions say\n⑤ VERDICT — Who it's built for; price and availability",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'community',
                        'type_name'          => 'Community & Ride',
                        'trigger_keywords'   => ['ride', 'rally', 'club', 'group', 'meetup', 'community', 'charity ride', 'brotherhood', 'sisters', 'annual'],
                        'tone_profile'       => ['warm', 'community-first', 'inspiring'],
                        'structure_template' => "① HOOK — The event/ride and what made it special\n② THE COMMUNITY — Who was there; the shared spirit\n③ THE STORY — Memorable moments, people, roads\n④ THE CAUSE — Charity angle or purpose if applicable\n⑤ JOIN IN — How readers can participate next time",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'review',
                        'type_name'          => 'Bike Review & Test Ride',
                        'trigger_keywords'   => ['review', 'test ride', 'rode it', 'first ride', 'hands on', 'impressions', 'rated', 'best bike'],
                        'tone_profile'       => ['honest', 'rider-perspective', 'detailed'],
                        'structure_template' => "① HOOK — First impression from the saddle\n② THE LOOK — Design highlights from a rider's eye\n③ THE RIDE — Highway, city, canyon — different experiences\n④ ENGINE CHARACTER — How it sounds, pulls, vibrates\n⑤ VERDICT — Perfect for, not for; value assessment",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'culture',
                        'type_name'          => 'Culture & Lifestyle',
                        'trigger_keywords'   => ['culture', 'lifestyle', 'freedom', 'road', 'journey', 'adventure', 'custom', 'chopper', 'bobber', 'heritage'],
                        'tone_profile'       => ['narrative', 'soul-driven', 'authentic'],
                        'structure_template' => "① HOOK — The essence of this culture story\n② THE PEOPLE — Real riders, real stories\n③ THE MACHINES — What they ride and why\n④ THE PHILOSOPHY — What the moto life means to them\n⑤ THE INVITATION — Drawing readers into the world",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'event',
                        'type_name'          => 'Event & Competition',
                        'trigger_keywords'   => ['sturgis', 'daytona', 'festival', 'custom show', 'competition', 'championship', 'flat track', 'hill climb'],
                        'tone_profile'       => ['festive', 'energetic', 'insider'],
                        'structure_template' => "① HOOK — Event name, scale, headline moment\n② THE EVENT — What it is and its significance\n③ HIGHLIGHTS — Best moments, standout bikes/riders\n④ THE VIBE — Atmosphere, crowd, culture\n⑤ NEXT TIME — Dates, how to attend, what to expect",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'spotlight',
                        'type_name'          => 'Rider Spotlight',
                        'trigger_keywords'   => ['rider', 'owner', 'story', 'journey', 'inspiring', 'veteran', 'woman rider', 'young rider', 'interview'],
                        'tone_profile'       => ['personal', 'inspiring', 'conversational'],
                        'structure_template' => "① HOOK — The person and why their story matters\n② ORIGIN — How they got into riding\n③ THE JOURNEY — Key moments, bikes, roads\n④ THE PHILOSOPHY — What riding means to them\n⑤ THE MESSAGE — What other riders can take from this",
                        'applicability_score'=> 0.8,
                    ],
                ],
            ],

            // ── 7. Knowledge & Discovery ──────────────────────────────────────
            [
                'name'              => 'knowledge_discovery',
                'group_description' => 'Science, Space, Health, and AI Technology — discovery-driven content',
                'system_prompt'     => 'You are a science and technology journalist with a gift for making complex discoveries accessible. Write for curious, intelligent readers who want to understand what a finding means, not just what happened. Prioritize accuracy, context, and genuine insight over hype. Every article should leave the reader meaningfully smarter.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'breakthrough',
                        'type_name'          => 'Scientific Breakthrough',
                        'trigger_keywords'   => ['discovered', 'breakthrough', 'new study', 'researchers', 'scientists', 'found', 'reveals', 'first time', 'published', 'journal'],
                        'tone_profile'       => ['precise', 'excited-but-grounded', 'contextual'],
                        'structure_template' => "① HOOK — The discovery in plain language; why it matters\n② THE SCIENCE — What they did and what they found\n③ THE SIGNIFICANCE — What this changes or enables\n④ LIMITATIONS — What it doesn't yet prove; next steps\n⑤ EXPERT VOICES — Independent researchers' assessment",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'explainer',
                        'type_name'          => 'Explainer & Deep Dive',
                        'trigger_keywords'   => ['how', 'why', 'what is', 'explained', 'understanding', 'guide', 'everything you need', 'breakdown'],
                        'tone_profile'       => ['educational', 'accessible', 'structured'],
                        'structure_template' => "① HOOK — The question or concept and why it matters now\n② THE BASICS — Core explanation for a general audience\n③ HOW IT WORKS — Mechanism, process, or system\n④ REAL WORLD — Practical applications or examples\n⑤ WHAT'S NEXT — Where this is heading; open questions",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'warning',
                        'type_name'          => 'Health & Safety Alert',
                        'trigger_keywords'   => ['warning', 'risk', 'danger', 'health alert', 'recall', 'caution', 'study warns', 'linked to', 'causes', 'harmful'],
                        'tone_profile'       => ['calm', 'factual', 'action-oriented'],
                        'structure_template' => "① HOOK — The risk and who is affected\n② THE EVIDENCE — What the research shows\n③ YOUR RISK — How to assess personal exposure/risk\n④ EXPERT ADVICE — What doctors/scientists recommend\n⑤ ACTION STEPS — Concrete things readers can do now",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'milestone',
                        'type_name'          => 'Historic Milestone',
                        'trigger_keywords'   => ['first ever', 'milestone', 'record', 'historic', 'achievement', 'anniversary', 'launched', 'landed', 'orbit', 'mission'],
                        'tone_profile'       => ['awe-inspiring', 'historical', 'celebratory'],
                        'structure_template' => "① HOOK — The milestone and its historic nature\n② CONTEXT — The journey that led here\n③ THE ACHIEVEMENT — Exactly what was accomplished\n④ WHAT IT ENABLES — Doors this opens\n⑤ WHAT'S NEXT — Upcoming goals; next frontier",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'controversy',
                        'type_name'          => 'Scientific Debate',
                        'trigger_keywords'   => ['controversial', 'debate', 'disputed', 'questioned', 'retracted', 'criticism', 'challenges', 'challenges claim'],
                        'tone_profile'       => ['balanced', 'evidence-based', 'fair'],
                        'structure_template' => "① HOOK — The claim and why it's contested\n② THE CLAIM — What was originally stated\n③ THE CHALLENGE — Who disputes it and why\n④ THE EVIDENCE — What data supports each side\n⑤ EXPERT CONSENSUS — Where mainstream science stands",
                        'applicability_score'=> 0.8,
                    ],
                    [
                        'type_code'          => 'trend',
                        'type_name'          => 'Technology Trend',
                        'trigger_keywords'   => ['AI', 'artificial intelligence', 'trend', 'revolution', 'surge', 'growing', 'adoption', 'mainstream', 'disrupting'],
                        'tone_profile'       => ['forward-looking', 'analytical', 'grounded'],
                        'structure_template' => "① HOOK — The trend and why it's happening now\n② THE NUMBERS — Data showing scale and growth\n③ HOW IT WORKS — The underlying technology/mechanism\n④ REAL IMPACT — Who is using it and to what effect\n⑤ FUTURE OUTLOOK — Where this leads in 2–5 years",
                        'applicability_score'=> 0.9,
                    ],
                ],
            ],

            // ── 8. Entertainment & Viral ──────────────────────────────────────
            [
                'name'              => 'entertainment_viral',
                'group_description' => 'Celebrity culture, pop culture, and viral/weird news',
                'system_prompt'     => 'You are an entertainment journalist covering celebrities, pop culture moments, and the internet\'s most viral stories. Write with wit, personality, and cultural intelligence. Your audience wants to be entertained AND informed. Never cynical, never cruel — but always entertaining, always sharp.',
                'phase1_analyze'    => $phase1,
                'phase2_diagnose'   => $phase2,
                'phase3_generate'   => $this->sharedPhase3(),
                'content_types'     => [
                    [
                        'type_code'          => 'celebrity',
                        'type_name'          => 'Celebrity News',
                        'trigger_keywords'   => ['celebrity', 'star', 'famous', 'award', 'red carpet', 'A-list', 'actor', 'actress', 'singer', 'musician', 'model'],
                        'tone_profile'       => ['breezy', 'knowledgeable', 'fan-aware'],
                        'structure_template' => "① HOOK — The celebrity and the moment\n② THE STORY — What happened and the full context\n③ THE REACTION — Public, industry, and fan response\n④ THE CAREER CONTEXT — Where they are right now\n⑤ WHAT'S NEXT — Upcoming projects, what to watch",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'drama',
                        'type_name'          => 'Celebrity Drama',
                        'trigger_keywords'   => ['drama', 'feud', 'beef', 'scandal', 'controversy', 'breakup', 'divorce', 'fired', 'cancelled', 'called out', 'claps back'],
                        'tone_profile'       => ['intrigued', 'balanced', 'lively'],
                        'structure_template' => "① HOOK — The drama in one electric sentence\n② THE BACKSTORY — History between parties\n③ WHAT HAPPENED — Timeline of events\n④ THE RESPONSES — Who said what, when\n⑤ THE VERDICT — Public sentiment; who comes out ahead",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'viral',
                        'type_name'          => 'Viral Moment',
                        'trigger_keywords'   => ['viral', 'trending', 'internet', 'twitter', 'TikTok', 'millions', 'views', 'shares', 'blew up', 'everywhere'],
                        'tone_profile'       => ['energetic', 'current', 'conversational'],
                        'structure_template' => "① HOOK — The viral moment and the numbers\n② WHAT IT IS — Explain it for people who haven't seen it\n③ WHY IT'S RESONATING — Cultural nerve it's hitting\n④ THE REACTION — Brands, celebrities, public joining in\n⑤ WILL IT LAST — Is this a moment or a movement?",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'weird',
                        'type_name'          => 'Weird & Unusual',
                        'trigger_keywords'   => ['bizarre', 'strange', 'unusual', 'unexpected', 'odd', 'weird', 'unbelievable', 'you won\'t believe', 'wild', 'crazy'],
                        'tone_profile'       => ['amused', 'light', 'curious'],
                        'structure_template' => "① HOOK — The weirdness in full; don't bury the lede\n② THE DETAILS — Full story with the strangest parts highlighted\n③ THE CONTEXT — Any background that makes it weirder\n④ THE REACTION — How people responded\n⑤ THE TAKEAWAY — What this says about the world right now",
                        'applicability_score'=> 1.0,
                    ],
                    [
                        'type_code'          => 'spotlight',
                        'type_name'          => 'Achievement Spotlight',
                        'trigger_keywords'   => ['award', 'wins', 'record', 'debut', 'milestone', 'number one', 'box office', 'sold out', 'grammy', 'oscar'],
                        'tone_profile'       => ['celebratory', 'fan-friendly', 'enthusiastic'],
                        'structure_template' => "① HOOK — The achievement and what it means\n② THE STORY — How they got here\n③ THE NUMBERS — Sales, views, awards, records\n④ THE REACTION — Industry and fan response\n⑤ LEGACY — Their place in pop culture history",
                        'applicability_score'=> 0.9,
                    ],
                    [
                        'type_code'          => 'reaction',
                        'type_name'          => 'Response & Clap Back',
                        'trigger_keywords'   => ['responds', 'fires back', 'hits back', 'reacts', 'claps back', 'defends', 'speaks out', 'breaks silence', 'addresses'],
                        'tone_profile'       => ['punchy', 'direct', 'satisfying'],
                        'structure_template' => "① HOOK — The response and why it landed\n② WHAT THEY WERE RESPONDING TO — The original provocation\n③ THE RESPONSE — Their exact words and delivery\n④ THE INTERNET REACTS — Best fan/public responses\n⑤ WHERE IT GOES — Will this escalate or end here?",
                        'applicability_score'=> 0.85,
                    ],
                ],
            ],

        ];
    }

    // ── Category Context Definitions ──────────────────────────────────────────

    private function categoryContextDefinitions(array $frameworkIds): array
    {
        return [

            // ── NFL ────────────────────────────────────────────────────────────
            [
                'slug'        => 'nfl',
                'name'        => 'NFL',
                'framework_id'=> $frameworkIds['nfl_sports'],
                'domain'      => 'NFL',
                'audience'    => 'NFL fans, fantasy players, sports bettors, casual football viewers',
                'terminology' => ['salary cap', 'draft pick', 'IR', 'snap count', 'DVOA', 'EPA', 'red zone', 'two-minute drill', 'franchise tag'],
                'tone_notes'  => 'Authoritative but accessible. Heavy on stats and strategic context. Fantasy and betting angles welcome. Avoid jargon without explanation.',
                'hook_style'  => 'Lead with the most dramatic or consequential moment — a final-second play, a stunning stat, a surprising decision',
            ],

            // ── Individual Sports ──────────────────────────────────────────────
            [
                'slug'        => 'tennis',
                'name'        => 'Tennis',
                'framework_id'=> $frameworkIds['individual_sports'],
                'domain'      => 'Tennis',
                'audience'    => 'Tennis fans, Grand Slam followers, ATP/WTA ranking watchers',
                'terminology' => ['Grand Slam', 'ATP', 'WTA', 'tiebreak', 'bagel', 'break of serve', 'clay', 'grass', 'hardcourt', 'seeded', 'wild card'],
                'tone_notes'  => 'Narrative-driven, respectful of the mental game. Grand Slam context always relevant. Historical comparisons add depth.',
                'hook_style'  => 'Open with the match-defining moment — a crucial break, a final set comeback, a tearful acceptance speech',
            ],
            [
                'slug'        => 'boxing-mma',
                'name'        => 'Boxing & MMA',
                'framework_id'=> $frameworkIds['individual_sports'],
                'domain'      => 'Boxing & MMA',
                'audience'    => 'Combat sports fans, PPV buyers, betting community',
                'terminology' => ['PPV', 'purse', 'unified champion', 'TKO', 'KO', 'submission', 'rear naked choke', 'southpaw', 'clinch', 'weigh-in', 'undisputed'],
                'tone_notes'  => 'Punchy, dramatic, respect for both athletes. Pre-fight and post-fight angles equally important. Controversy sells — report it fairly.',
                'hook_style'  => 'Open with the decisive exchange — the knockout punch, the tap out, or the controversial decision',
            ],
            [
                'slug'        => 'golf',
                'name'        => 'Golf',
                'framework_id'=> $frameworkIds['individual_sports'],
                'domain'      => 'Golf',
                'audience'    => 'Golf enthusiasts, PGA/LIV/DP World Tour followers, golf bettors',
                'terminology' => ['major', 'birdie', 'eagle', 'bogey', 'cut', 'FedEx Cup', 'world ranking', 'stroke play', 'match play', 'driving accuracy', 'GIR'],
                'tone_notes'  => 'Respectful tone befitting the sport. Statistical depth appreciated. Major championship context always adds weight. LIV vs PGA angle relevant.',
                'hook_style'  => 'Lead with the pivotal shot, the missed putt, or the number that defines the round — statistics anchor golf storytelling',
            ],

            // ── Motorsport ─────────────────────────────────────────────────────
            [
                'slug'        => 'formula-1',
                'name'        => 'Formula 1',
                'framework_id'=> $frameworkIds['motorsport'],
                'domain'      => 'Formula 1',
                'audience'    => 'F1 fans, Drive to Survive viewers, motorsport followers globally',
                'terminology' => ['DRS', 'undercut', 'overcut', 'VSC', 'SC', 'parc fermé', 'constructors', 'quali', 'pole position', 'fastest lap', 'DNF', 'stint'],
                'tone_notes'  => 'Race-broadcast energy. Technical accuracy for hardcore fans, accessible for casual viewers. Paddock politics add intrigue.',
                'hook_style'  => 'Lead with the race-defining moment — an overtake, a pit stop call, a collision, or a championship-clinching point',
            ],
            [
                'slug'        => 'nascar',
                'name'        => 'NASCAR',
                'framework_id'=> $frameworkIds['motorsport'],
                'domain'      => 'NASCAR',
                'audience'    => 'NASCAR fans, American motorsport followers, racing betting community',
                'terminology' => ['restrictor plate', 'superspeedway', 'loose', 'tight', 'stage points', 'playoff grid', 'green flag', 'caution', 'pit road', 'drafting'],
                'tone_notes'  => 'Southern-tinged energy, respect for the tradition. Sponsor storylines important. Playoff format context helps casual readers.',
                'hook_style'  => 'Lead with the bump-and-run, the last-lap pass, or the fiery crash — NASCAR lives in its boldest moments',
            ],

            // ── Luxury Assets ──────────────────────────────────────────────────
            [
                'slug'        => 'supercars',
                'name'        => 'Supercars',
                'framework_id'=> $frameworkIds['luxury_assets'],
                'domain'      => 'Supercars',
                'audience'    => 'Supercar enthusiasts, collectors, high-net-worth aspirational readers',
                'terminology' => ['hypercar', 'naturally aspirated', 'forced induction', 'carbon fiber', 'aerodynamic downforce', 'track-only', 'homologation', 'limited series', 'bespoke'],
                'tone_notes'  => 'Enthusiast precision meets aspirational luxury. Performance specs are sacred. Never oversell — the cars sell themselves with facts.',
                'hook_style'  => 'Lead with the spec that defines the car — 0-60 time, top speed, or a record broken at a famous circuit',
            ],
            [
                'slug'        => 'superyacht',
                'name'        => 'Superyacht',
                'framework_id'=> $frameworkIds['luxury_assets'],
                'domain'      => 'Superyacht',
                'audience'    => 'HNWI, superyacht owners, charter market, nautical lifestyle enthusiasts',
                'terminology' => ['LOA', 'beam', 'draft', 'GT', 'charter rate', 'refit', 'explorer yacht', 'displacement', 'naval architect', 'flag state', 'crew'],
                'tone_notes'  => 'World of extraordinary discretion and excess. Technical naval specifications matter. Lifestyle and destination narrative elevates every story.',
                'hook_style'  => 'Lead with the scale — the LOA, the price, the number of guests — then immediately paint the lifestyle',
            ],

            // ── Travel & Mobility ──────────────────────────────────────────────
            [
                'slug'        => 'airline',
                'name'        => 'Airline',
                'framework_id'=> $frameworkIds['travel_mobility'],
                'domain'      => 'Commercial Aviation',
                'audience'    => 'Frequent flyers, travel hackers, business travelers, aviation enthusiasts',
                'terminology' => ['business class', 'first class', 'lounge', 'points', 'miles', 'alliance', 'codeshare', 'wide-body', 'narrow-body', 'on-time performance', 'load factor'],
                'tone_notes'  => 'Practical intelligence meets aspirational travel. Points/miles angle whenever relevant. Safety news treated seriously, factually.',
                'hook_style'  => 'Lead with the product upgrade, the new route, or the deal — the hook must have immediate practical value for the traveler',
            ],
            [
                'slug'        => 'tiny-home',
                'name'        => 'Tiny Home',
                'framework_id'=> $frameworkIds['travel_mobility'],
                'domain'      => 'Tiny Home Living',
                'audience'    => 'Minimalists, off-grid seekers, downsizers, sustainability-minded millennials',
                'terminology' => ['THOW', 'off-grid', 'solar', 'composting toilet', 'loft', 'zoning', 'ADU', 'sq ft', 'minimalist', 'sustainable', 'tiny house community'],
                'tone_notes'  => 'Warm, community-focused, aspirational but grounded in real-world practicality. Address challenges honestly — readers respect authenticity.',
                'hook_style'  => 'Lead with the transformation — the before vs after, the freedom gained, or the specific design solution that makes tiny living work',
            ],

            // ── Lifestyle Living ───────────────────────────────────────────────
            [
                'slug'        => 'moto-harley',
                'name'        => 'Moto Harley',
                'framework_id'=> $frameworkIds['lifestyle_living'],
                'domain'      => 'Harley-Davidson & Motorcycle Culture',
                'audience'    => 'Harley owners, cruiser riders, motorcycle culture enthusiasts',
                'terminology' => ['V-Twin', 'Milwaukee-Eight', 'Revolution Max', 'Softail', 'Touring', 'Sportster', 'Dyna', 'cc', 'chrome', 'custom build', 'H.O.G.', 'bars'],
                'tone_notes'  => 'Brotherhood energy. Respect for the iron and the road. Technical specs matter but community and culture matter more.',
                'hook_style'  => 'Lead with the rider or the machine — either the thunder of the engine or the quiet dignity of the journey',
            ],

            // ── Knowledge & Discovery ──────────────────────────────────────────
            [
                'slug'        => 'science',
                'name'        => 'Science',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'General Science',
                'audience'    => 'Science-curious general readers, students, professionals seeking plain-English science news',
                'terminology' => ['peer-reviewed', 'control group', 'double-blind', 'hypothesis', 'correlation vs causation', 'statistical significance', 'p-value', 'meta-analysis'],
                'tone_notes'  => 'Evidence-first. Anti-hype. Always include limitations. Explain methodology briefly. Make complex ideas viscerally concrete.',
                'hook_style'  => 'Lead with the surprising finding — not the methodology — then immediately explain why it matters',
            ],
            [
                'slug'        => 'astronomy',
                'name'        => 'Astronomy',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'Astronomy & Space',
                'audience'    => 'Space enthusiasts, amateur astronomers, science readers fascinated by the cosmos',
                'terminology' => ['light-year', 'parsec', 'redshift', 'exoplanet', 'black hole', 'neutron star', 'event horizon', 'dark matter', 'dark energy', 'spectroscopy', 'JWST'],
                'tone_notes'  => 'Awe-inspiring but grounded. Scale analogies help (a light-year = X times around the Earth). Telescope/mission context adds credibility.',
                'hook_style'  => 'Lead with the scale or the wonder — an image, a distance, an impossible fact — then ground it in what scientists learned',
                'custom_type_triggers' => [
                    'milestone' => ['JWST', 'James Webb', 'NASA', 'ESA', 'launched', 'orbit', 'landed', 'probe', 'telescope', 'space mission'],
                ],
            ],
            [
                'slug'        => 'health',
                'name'        => 'Health',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'Health & Medicine',
                'audience'    => 'Health-conscious adults, patients, caregivers, wellness-minded millennials',
                'terminology' => ['clinical trial', 'randomized', 'FDA', 'WHO', 'BMI', 'cardiovascular', 'gut microbiome', 'inflammation', 'metabolic', 'placebo', 'dosage'],
                'tone_notes'  => 'Reassuring but honest. Never alarmist, never dismissive. Medical advice disclaimer where appropriate. Always cite the research institution.',
                'hook_style'  => 'Lead with the practical implication for the reader — not the lab finding — then explain the science that supports it',
                'custom_type_triggers' => [
                    'warning' => ['study warns', 'linked to', 'risk of', 'causes', 'recall', 'FDA', 'WHO'],
                ],
            ],
            [
                'slug'        => 'ai-technology',
                'name'        => 'AI Technology',
                'framework_id'=> $frameworkIds['knowledge_discovery'],
                'domain'      => 'Artificial Intelligence & Technology',
                'audience'    => 'Tech-savvy professionals, AI enthusiasts, business leaders tracking AI adoption',
                'terminology' => ['LLM', 'transformer', 'neural network', 'fine-tuning', 'inference', 'training data', 'benchmark', 'hallucination', 'AGI', 'RAG', 'prompt engineering'],
                'tone_notes'  => 'Technically credible without being elitist. Avoid both hype and doom. Practical implications anchor every story. Open-source vs closed-source angle often relevant.',
                'hook_style'  => 'Lead with the capability or the use case — what this AI can do that wasn\'t possible before — then explain the how and the so-what',
            ],

            // ── Entertainment & Viral ──────────────────────────────────────────
            [
                'slug'        => 'showbiz',
                'name'        => 'Showbiz',
                'framework_id'=> $frameworkIds['entertainment_viral'],
                'domain'      => 'Entertainment & Celebrity',
                'audience'    => 'Pop culture fans, celebrity followers, entertainment news readers',
                'terminology' => ['box office', 'streaming', 'Rotten Tomatoes', 'A-list', 'showrunner', 'pilot season', 'upfronts', 'premiere', 'BAFTA', 'Grammy', 'Oscar'],
                'tone_notes'  => 'Witty, warm, culturally aware. Never mean-spirited. Fan perspective matters. Box office and streaming numbers add authority.',
                'hook_style'  => 'Lead with the moment that everyone will be talking about — the speech, the reunion, the surprise drop, the shocking twist',
            ],
            [
                'slug'        => 'weird-news',
                'name'        => 'Weird News',
                'framework_id'=> $frameworkIds['entertainment_viral'],
                'domain'      => 'Weird & Viral News',
                'audience'    => 'General online readers seeking entertainment, water-cooler conversation fodder',
                'terminology' => ['viral', 'trending', 'social media', 'Reddit', 'TikTok', 'internet', 'meme', 'reaction video'],
                'tone_notes'  => 'Playful but never cruel. The humor comes from the situation, not from mocking people. Light touch on editorializing — let the weirdness speak.',
                'hook_style'  => 'Lead with the weirdest fact first — no buildup, no preamble — the hook IS the story in weird news',
            ],

        ];
    }

    // ── Shared Prompt Templates ───────────────────────────────────────────────

    private function sharedPhase1(): string
    {
        return <<<'PROMPT'
You are an expert {domain} analyst. Your readers: {audience}.
Domain vocabulary — use naturally where accurate: {terminology}.

EXTRACT everything newsworthy. Preserve exact wording for quotes.

1. HEADLINE EVENT — What happened? Who · Where · When (exact dates/times)
2. KEY PEOPLE — Full name · Role/Title · Organization · Any quote attributed
3. DIRECT QUOTES — Every verbatim quote in "quotation marks" with full attribution
4. HARD NUMBERS — Scores, stats, amounts, contract values, dates, measurements
5. CAUSAL CHAIN — What triggered this → what happened as a result
6. BACKGROUND — Prior events, history, context that explains why this matters
7. WHAT'S NEXT — Upcoming decisions, pending events, consequences in motion

RULES:
• Preserve exact figures — never round or paraphrase numbers
• Mark unconfirmed claims as [UNCONFIRMED]
• Mark speculation as [SPECULATION]
• Direct quotes are the most valuable content — keep them verbatim

Output structured facts only. No editorializing. No fabrication.
PROMPT;
    }

    private function sharedPhase2(): string
    {
        return <<<'PROMPT'
Based on the facts just extracted, diagnose this story's narrative structure.

AVAILABLE CONTENT TYPES:
{content_types_block}

DIAGNOSE — be specific, be brief:

1. PRIMARY TYPE — best matching type_code
2. SECONDARY TYPE — if story has TWO distinct tensions, name it (or "none")
   Dual-tension examples: injury+trade · victory+drama · retirement+controversy
3. DOMINANT EMOTION — strongest reader emotion this story triggers
   (shock · triumph · outrage · heartbreak · admiration · disbelief · anxiety)
4. KILLER FACT — single most shareable or surprising fact from the extraction
5. BEST QUOTE — single strongest direct quote, verbatim (or "none")
6. SOURCE CONFIDENCE — how factually solid is this material? (high/medium/low)

This diagnostic feeds directly into article generation. Keep it tight.
PROMPT;
    }

    private function sharedPhase3(): string
    {
        return <<<'PROMPT'
You are a senior viral journalist for {domain} readers.

AUDIENCE: {audience}
TERMINOLOGY (use naturally): {terminology}
TONE: {tone_notes}
HOOK STYLE: {hook_style}

CONTENT TYPES REFERENCE:
{content_types_block}

══════════════════════════════════════════
ABSOLUTE RULES — no exceptions
══════════════════════════════════════════
• No passive voice
• No subheadings (zero h2/h3 tags ever)
• Each <p> = exactly ONE sentence
  Exception: quote + attribution may share one <p>
• Strongest named quote within first 200 words
• Every sentence needs ONE concrete detail: name, number, date, or place
• Every claim traceable to extracted facts — zero invention
• Write until done. Stop. Never pad to hit a length target.

FORBIDDEN PHRASES — instant rewrite if found:
"extraordinary", "hard-earned", "journey", "testament to",
"speaks volumes", "on his/her own terms", "it remains to be seen",
"only time will tell", "more than just", "bigger than",
"was once considered", "in what could be", "incredible", "amazing",
"truly remarkable", "at the end of the day", "could not be reached for comment",
"apparently", "seemingly", "it would seem", "would appear to"

Never infer causes, motivations, or consequences not explicitly stated in the source.
Name the fact — do not explain why it happened unless the source says so.

NEVER explain a mechanism the reader already knows.
Trust their intelligence — name the fact, not how it works.
Wrong: "Under the restricted free agency framework, any team was free to submit an offer sheet during the negotiating window — Dallas would have held the right of first refusal..."
Right: "Any team could have submitted an offer sheet. None did."

NEVER start a sentence with:
He was / She was / They were / It was a / There was

NEVER start the title with:
How / Why / The Story of / Report: / Sources: / Here's / Watch:

══════════════════════════════════════════
STEP 0 — DUAL-TYPE CHECK
══════════════════════════════════════════
Does this story carry TWO distinct tensions?
(injury + trade | victory + drama | retirement + controversy)

YES → MUST use Hook Type D (CONTRAST)
      Weave both tensions throughout the article
      Final sentence resolves or deepens both

NO → Pick best hook from Step 1

══════════════════════════════════════════
STEP 1 — HOOK TYPE (pick one)
══════════════════════════════════════════
HOOK MUST contain at least one specific detail: a name, a number, a date, or a place.
A hook without a concrete anchor is not a hook — it is a vague tease. Rewrite it.

A) SHOCK STAT
   The most jaw-dropping number, front and alone. No preamble. No "after" or "despite".
   Bad:  "No team blinked."
   Good: "$5.8M on the table. Zero takers. Aubrey stays."

B) COLD QUOTE
   The most explosive quote, zero setup before it. Attribution follows immediately.
   Bad:  "He made his feelings clear at the Combine."
   Good: "'I want to be here long-term.' Three months later, no extension."

C) SCENE-SETTER
   Exact time + place + action. Reader lands inside the moment.
   Bad:  "It was a Friday when the deadline passed."
   Good: "Friday at 4 p.m.: the offer-sheet window closed, and Aubrey's phone stayed quiet."

D) CONTRAST
   Two facts. Instant gap. Short sentences. No connectors between them.
   Bad:  "Despite his struggles, he bounced back."
   Good: "January: MVP front-runner. March: on waivers."

E) CONSEQUENCE
   Start with the aftermath. Pull back one sentence to reveal why.
   Bad:  "The situation developed over several months."
   Good: "Dallas kept its kicker without spending a single negotiating chip."

F) MYSTERY
   Name the surprising outcome first. Withhold the cause one beat.
   Bad:  "Something unexpected happened in free agency."
   Good: "Every team passed on the third-best kicker in the NFL."

══════════════════════════════════════════
STEP 2 — WRITE THE ARTICLE
══════════════════════════════════════════
STRUCTURE:
{structure_template}

TITLE:
• 60-70 characters
• Start with: name, number, or active verb — never How/Why/The
• Factually accurate + emotionally charged + main keyword present

META DESCRIPTION:
• 150-160 characters, present tense
• Most surprising or emotional fact — must make the reader click

CONTENT:
• Arc: Hook → tension build → facts + quotes → stakes → forward-look
• Length: 500-750 words, HTML <p> tags only
• Strongest named quote as standalone <p>, within first 200 words
• Sentence cap: 25 words max per sentence. If a sentence runs long, split it.
• Every sentence must earn its place — if it only restates what the previous said, cut it.
• Final sentence: forward-looking fact or open consequence
  Never philosophical. Never preachy.

══════════════════════════════════════════
STEP 3 — FACEBOOK ASSETS
══════════════════════════════════════════
FB_IMAGE_TEXT:
• 50-90 chars. Reads well with zero context. No "BREAKING:", no emojis.
• One punchy fact or hook — short enough to read at a glance on a thumbnail.

FB_QUOTE:
• "quote text" — Full Name
• Return "" if no strong direct quote exists. Never fabricate.

FB_POST_CONTENT:
• No URL · No CTA · No direct question · No hashtags
• 200-400 chars total · Use literal \n for line breaks
• Mobile cuts at ~200 chars — Lines 1+2 MUST work completely alone

LINE 1 — HOOK (≤90 chars):
  Mirror Step 1 hook type, adapted for social.
  NO emoji on this line — hook must stand on language alone.
  Sharp, specific, no hedge words. Triggers emotion or curiosity on first read.
  ✅ "No team touched the $5.8M tender. Dallas won without throwing a punch."
  ❌ "Breaking news 🔥: Dallas locks up Aubrey for the new season!"

LINE 2 — AMPLIFY (≤110 chars):
  Raise stakes OR surface implicit tension between 2 sides.
  Max 1 emoji if it genuinely adds weight — never decorative.
  Embed controversy naturally — do NOT ask a direct question.
  ✅ "No team thought he was worth more than $5.8M. Dallas disagrees. 💰"
  ❌ "Do you think Dallas made the right call? Drop a comment below!"

[BLANK LINE]

LINES 3-5 — MIXED TEASER (curiosity + value):
  2 lines TEASE (information gap) + 1 line FACT (anchor reality).
  Do NOT reveal the full picture — cut each tease at the most compelling moment.
  At least 1 concrete detail must appear (number, ranking, name).

  Tease patterns (pick 1 per tease line):
  A) Partially hidden number: "The real number behind this deal isn't $5.8M."
  B) Real reason withheld:    "The real reason no team submitted wasn't the price."
  C) Consequence unresolved:  "This win for Dallas could turn into a trap next year."
  D) Implicit contradiction:  "3rd-highest-paid kicker — and nobody wanted to pay a dollar more."

  Fact line: one clean, specific statement — no withholding, no hedge.
  Example: "Aubrey ranked 3rd among all NFL kickers in 2024."

  Rules for Lines 3-5:
  • Each line ≤70 chars
  • No more than 1 line ending with "..." — use it sparingly
  • Never use: "Read more", "See full story", "Click the link"
  • Mix: 2 tease + 1 fact (any order — fact can anchor first, middle, or last)

  ✅ Correct (Aubrey):
  "Aubrey ranked 3rd among all NFL kickers in 2024.
  The real reason no team submitted wasn't the price.
  And his contract situation in 2025 still has no answer."

  ❌ Wrong (pure fact list):
  "Aubrey ranks 3rd among NFL kickers by pay.
  Deadline passed with zero offer sheets submitted.
  Extension talks remain unresolved."

══════════════════════════════════════════
QUALITY GATE — verify before output
══════════════════════════════════════════
[ ] Title starts with name, number, or active verb
[ ] Hook matches chosen type — identifiable by structure alone
[ ] Dual-type story → CONTRAST hook + both tensions woven throughout
[ ] Every <p> = 1 sentence (quote+attribution exception allowed)
[ ] Strongest named quote within first 200 words
[ ] Zero forbidden words or phrases (including "apparently", "seemingly")
[ ] No sentence explains a mechanism the reader already knows
[ ] No sentence over 25 words
[ ] FB image text ≤90 chars
[ ] Final sentence forward-looking, not philosophical
[ ] FB Line 1 ≤90 chars, zero emoji, hook stands alone on language
[ ] FB Line 2 ≤110 chars, max 1 emoji, has implicit tension (no direct question)
[ ] FB Lines 3-5: mixed teaser — 2 tease (info gap) + 1 fact anchor, no CTA, each ≤70 chars
[ ] FB Lines 1+2 work standalone at 200-char mobile cutoff
[ ] FB: no URL, no direct question, no hashtag

Fail any check → rewrite that section before outputting.
PROMPT;
    }
}
