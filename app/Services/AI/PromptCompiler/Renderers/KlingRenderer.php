<?php

namespace App\Services\AI\PromptCompiler\Renderers;

use App\Services\AI\PromptCompiler\PromptDocument\PromptDocument;
use App\Services\AI\PromptCompiler\RenderProfile;

/**
 * Renders PromptDocument → Kling image-to-video storyboard prompt.
 *
 * Format: labeled sections Kling 2.x parses as structured intent.
 *   SCENE       — 1-line visual context (location + light + mood)
 *   CAMERA      — what the camera does and how
 *   ACTION      — temporal choreography with [0–Xs] timestamp segments
 *   ENVIRONMENT — full scene + physics layer (what naturally moves)
 *   STYLE       — quality tier + perceptual lens effect
 *   CONTINUITY  — anchor injection (shots 2+ only)
 */
final class KlingRenderer
{
    /** Camera code → brief establishing action (phase 1 of choreography) */
    private const CAM_TIMING = [
        'AERIAL'   => 'Drone descends from high altitude, framing subject from above',
        'TRACKING' => 'Camera locks on subject, ready for lateral tracking',
        'ORBITAL'  => 'Camera begins arc sweep around subject',
        'WIDE'     => 'Wide frame reveals full scene context',
        'MEDIUM'   => 'Camera settles at conversational distance from subject',
        'CLOSE'    => 'Camera closes in toward face or key detail',
        'MACRO'    => 'Camera moves into extreme fine texture',
        'POV'      => 'POV perspective establishes subject sightline',
    ];

    /** Camera code → full action verb phrase (for CAMERA section) */
    private const CAM_ACTION = [
        'AERIAL'   => 'Drone camera descends from high altitude',
        'TRACKING' => 'Camera tracks alongside the moving subject',
        'ORBITAL'  => 'Camera sweeps in a slow orbital arc',
        'WIDE'     => 'Camera holds a wide establishing perspective',
        'MEDIUM'   => 'Camera frames the subject at conversational distance',
        'CLOSE'    => 'Camera moves into an intimate close-up',
        'MACRO'    => 'Camera reveals extreme fine detail in macro',
        'POV'      => 'Camera shows the subject\'s direct point of view',
    ];

    /**
     * Move code → camera movement phrase, keyed by motion_level.
     * Kling reads "slowly" and "gently" literally — use speed-appropriate language.
     */
    private const MOVE_ACTION = [
        'low' => [
            'STATIC' => '',
            'P1'     => 'gently pushing in toward the subject',
            'P2'     => 'slowly pulling back to reveal the surroundings',
            'D1'     => 'gently dollying right across the frame',
            'D2'     => 'gently dollying left across the frame',
            'O1'     => 'slowly sweeping clockwise around the subject',
            'O2'     => 'slowly sweeping counterclockwise around the subject',
            'H1'     => 'with soft subtle handheld sway',
            'T1'     => 'gently tilting upward to reveal the scene above',
            'T2'     => 'gently tilting downward to descend on the subject',
        ],
        'medium' => [
            'STATIC' => '',
            'P1'     => 'steadily pushing in toward the subject',
            'P2'     => 'steadily pulling back to reveal the surroundings',
            'D1'     => 'steadily dollying right across the frame',
            'D2'     => 'steadily dollying left across the frame',
            'O1'     => 'sweeping clockwise around the subject',
            'O2'     => 'sweeping counterclockwise around the subject',
            'H1'     => 'with organic handheld energy',
            'T1'     => 'tilting upward to reveal the scene above',
            'T2'     => 'tilting downward to descend on the subject',
        ],
        'high' => [
            'STATIC' => '',
            'P1'     => 'aggressively pushing in, rapidly closing distance to the subject',
            'P2'     => 'fast pulling back, dramatically revealing the full scale of the scene',
            'D1'     => 'fast lateral sweep right, cutting across the frame with energy',
            'D2'     => 'fast lateral sweep left, cutting across the frame with energy',
            'O1'     => 'swift aggressive clockwise sweep around the subject',
            'O2'     => 'swift aggressive counterclockwise sweep around the subject',
            'H1'     => 'with intense urgent handheld shake and motion',
            'T1'     => 'rapid upward tilt revealing scene with urgency',
            'T2'     => 'fast downward drive, descending hard onto the subject',
        ],
    ];

    /** Move code → temporal phase 1 start description, by motion_level */
    private const MOVE_TIMING = [
        'low' => [
            'P1'     => 'begins gentle push toward subject',
            'P2'     => 'begins gentle pull back',
            'D1'     => 'starts slow lateral dolly right',
            'D2'     => 'starts slow lateral dolly left',
            'O1'     => 'begins slow clockwise arc sweep',
            'O2'     => 'begins slow counterclockwise arc sweep',
            'H1'     => 'introduces soft handheld sway',
            'T1'     => 'tilts slowly upward',
            'T2'     => 'tilts slowly downward',
            'STATIC' => 'locked static frame establishes',
        ],
        'medium' => [
            'P1'     => 'begins steady push toward subject',
            'P2'     => 'begins steady pull back to reveal surroundings',
            'D1'     => 'starts lateral dolly right',
            'D2'     => 'starts lateral dolly left',
            'O1'     => 'begins clockwise arc sweep',
            'O2'     => 'begins counterclockwise arc sweep',
            'H1'     => 'introduces organic handheld sway',
            'T1'     => 'tilts upward to reveal overhead',
            'T2'     => 'tilts downward onto subject',
            'STATIC' => 'locked static frame establishes',
        ],
        'high' => [
            'P1'     => 'drops fast, aggressive push-in begins immediately',
            'P2'     => 'pulls back fast, dramatically opening the frame',
            'D1'     => 'cuts fast right with speed and purpose',
            'D2'     => 'cuts fast left with speed and purpose',
            'O1'     => 'rips into fast clockwise sweep',
            'O2'     => 'rips into fast counterclockwise sweep',
            'H1'     => 'hits subject with urgent shaky handheld energy',
            'T1'     => 'snaps upward fast, revealing the scene',
            'T2'     => 'drives downward hard onto subject',
            'STATIC' => 'locked frame holds tension',
        ],
    ];

    /**
     * Velocity DSL token → Kling-specific camera motion phrase.
     *
     * Tokens are model-agnostic (emitted by CameraEnergyPlanner).
     * This table is the ONLY place that knows Kling's preferred vocabulary.
     * Swap table entries when targeting a different provider.
     *
     * Kling responds better to concrete motion verbs than to adverbs
     * ("rapid drone dive" > "explosive velocity burst").
     */
    private const VELOCITY_PHRASES = [
        'burst'  => 'Rapid drone dive —',
        'rush'   => 'Fast aggressive push —',
        'push'   => 'Steady accelerated motion —',
        'brake'  => 'Camera decelerates abruptly, holds —',
        'hover'  => 'Near-static hold —',
        'static' => 'Locked frame —',
        'natural'=> '',
    ];

    /** Lens code → perceptual effect (not technical spec) */
    private const LENS_EFFECT = [
        '24'  => 'Ultra-wide perspective with environmental context',
        '35'  => 'Natural wide perspective',
        '50'  => 'Natural eye-level perspective',
        '85'  => 'Telephoto compression with shallow depth of field',
        '135' => 'Strong telephoto compression isolating the subject',
        '200' => 'Extreme telephoto, subject separated from background',
    ];

    /** Light code → brief 1-phrase descriptor for SCENE section */
    private const LIGHT_BRIEF = [
        'W1' => 'warm amber stadium floodlights',
        'W2' => 'warm golden sunset light',
        'G1' => 'golden hour side lighting',
        'N1' => 'neon-lit urban night',
        'N2' => 'cool moonlit night',
        'D1' => 'dramatic high-contrast rim light',
        'S1' => 'soft indoor window light',
        'S2' => 'soft diffused ambient light',
        'C1' => 'clinical neutral light',
        'C2' => 'cool industrial light with steam',
    ];

    /**
     * Light code → physics layer: what physically moves in this environment.
     * Listed in the ENVIRONMENT section so Kling renders secondary motion.
     */
    private const PHYSICS_LAYER = [
        'W1' => 'Cold breath visible in freezing air. Snowflakes drift through amber stadium light. Jersey fabric ripples with body momentum.',
        'W2' => 'Warm air shimmers near bright surfaces. Loose fabric catches golden light and lifts slightly. Sweat reflects stadium glow.',
        'G1' => 'Golden light catches dust particles hanging in air. Grass sways in gentle breeze. Long shadows sweep the frame.',
        'N1' => 'Rain streaks cut through neon light. Puddles shimmer and ripple underfoot. Wet surfaces gleam and reflect color.',
        'N2' => 'Moonlight shifts through passing clouds. Light ripples across wet surfaces. Ground-level mist drifts slowly.',
        'D1' => 'Atmospheric haze drifts through dramatic backlight. Deep shadows contain subtle hidden movement.',
        'S1' => 'Sheer curtains sway gently in background. Dust particles float slowly in shafts of soft light.',
        'S2' => 'Fine particles drift in soft diffused light. Surfaces glow with even ambient warmth.',
        'C1' => 'Clinical stillness. Minimal environmental motion. Every surface controlled and precise.',
        'C2' => 'Steam wisps rise and dissipate near machinery. Steel surfaces gleam under cool industrial light.',
    ];

    /** Emotion code → crowd / scene reaction for temporal phase 3 */
    private const SECONDARY_MOTION = [
        'POWER'  => 'Crowd erupts from seats; flags wave; stadium energy surges through the frame',
        'JOY'    => 'Crowd celebrates; wide smiles and raised arms fill the background',
        'EPIC'   => 'Camera reveals full scale; crowd becomes a sea of color and motion',
        'TENSE'  => 'Crowd leans forward in silence; coiled anticipation holds',
        'AWE'    => 'Collective gasp; crowd turns to track the trajectory skyward',
        'DRAMA'  => 'Players react; coaches gesture; crowd murmur rises into noise',
        'REVEAL' => 'Scene opens gradually; context and scale become clear',
        'CALM'   => 'Peaceful resolution; atmosphere settles and breathes',
        'HOOK'   => 'Immediate crowd reaction; energy floods the frame from the first second',
        'CRAFT'  => 'Precise resolution; technique fully executed; environment holds still',
        'FEAR'   => 'Silence builds; only subtle environmental movement; tension unresolved',
    ];

    /** Emotion code → 1-word mood for SCENE section */
    private const MOOD_BRIEF = [
        'POWER'  => 'powerful athletic intensity',
        'JOY'    => 'celebratory energy',
        'EPIC'   => 'cinematic grandeur',
        'TENSE'  => 'coiled anticipation',
        'AWE'    => 'overwhelming scale',
        'DRAMA'  => 'high dramatic tension',
        'REVEAL' => 'slow unveiling',
        'CALM'   => 'peaceful stillness',
        'HOOK'   => 'immediate visual impact',
        'CRAFT'  => 'focused precision',
        'FEAR'   => 'unsettling near-stillness',
    ];

    public static function render(PromptDocument $doc, array $dsl = [], ?RenderProfile $profile = null): string
    {
        $dur         = (float) ($dsl['dur']         ?? 2.0);
        $camCode     = $dsl['cam']                ?? 'MEDIUM';
        $moveCode    = $dsl['move']               ?? 'STATIC';
        $lensCode    = $dsl['lens']               ?? '50';
        $lightCode   = $dsl['light']              ?? '';
        $emoCode     = $dsl['emo']                ?? 'CRAFT';
        $motionLevel = $dsl['motion_level']       ?? 'medium';
        $sceneTitle  = $dsl['scene_title']        ?? '';

        // ScenePlanner-enriched data (Sprint 2+)
        $timeline    = $dsl['timeline']    ?? [];
        $physics     = $dsl['physics']     ?? [];
        $director    = $dsl['director']    ?? [];
        $composition = $dsl['composition'] ?? [];

        $klingDur = $dur <= 5.0 ? 5.0 : 10.0;
        $sections = [];

        // ── SCENE ───────────────────────────────────────────────────────────
        $storyPhase = $dsl['semantic_intent']['story_phase'] ?? '';
        $sections[] = "SCENE\n" . self::buildSceneDesc($sceneTitle, $lightCode, $emoCode, $storyPhase);

        // ── CAMERA ──────────────────────────────────────────────────────────
        $sections[] = "CAMERA\n" . self::buildCameraBlock($camCode, $moveCode, $lensCode, $motionLevel, $director, $composition);

        // ── ACTION (temporal choreography) ──────────────────────────────────
        if ($timeline !== []) {
            // ScenePlanner generated explicit per-second timeline — use it directly.
            $sections[] = "ACTION\n" . self::buildTimelineBlock($timeline);
        } else {
            // Fallback: rule-based choreography when ScenePlanner hasn't run.
            $subjectAction   = $doc->subject->enrichedSentence !== ''
                ? ucfirst($doc->subject->enrichedSentence) . '.'
                : '';
            $secondaryMotion = self::SECONDARY_MOTION[$emoCode] ?? '';
            $sections[] = "ACTION\n" . self::buildTemporalChoreography(
                $klingDur, $camCode, $moveCode, $motionLevel, $subjectAction, $secondaryMotion
            );
        }

        // ── ENVIRONMENT ─────────────────────────────────────────────────────
        $envBlock = self::buildEnvironmentBlock($doc, $lightCode, $physics);
        if ($envBlock !== '') {
            $sections[] = "ENVIRONMENT\n{$envBlock}";
        }

        // ── STYLE ───────────────────────────────────────────────────────────
        $sections[] = "STYLE\n" . self::buildStyleBlock($doc, $lensCode, $profile, $director);

        // ── CONTINUITY (shots 2+ only) ──────────────────────────────────────
        $continuityPlan = $dsl['continuity_plan'] ?? [];
        $shotOrder      = (int) ($dsl['shot_order'] ?? 0);

        if ($continuityPlan !== [] && $shotOrder > 1) {
            $continuityText = self::buildRichContinuity($continuityPlan);
            if ($continuityText !== '') {
                $sections[] = "CONTINUITY\n{$continuityText}";
            }
        } elseif ($doc->continuity !== null && $doc->continuity->anchor !== '') {
            $sections[] = "CONTINUITY\nMaintain identical appearance — {$doc->continuity->anchor}.";
        }

        return implode("\n\n", $sections);
    }

    // ── Private builders ────────────────────────────────────────────────────

    private static function buildSceneDesc(
        string $sceneTitle,
        string $lightCode,
        string $emoCode,
        string $storyPhase = '',
    ): string {
        $parts = [];
        if ($sceneTitle !== '') {
            $parts[] = $sceneTitle;
        }
        $light = self::LIGHT_BRIEF[$lightCode] ?? '';
        if ($light !== '') {
            $parts[] = $light;
        }
        $mood = self::MOOD_BRIEF[$emoCode] ?? '';
        if ($mood !== '') {
            $parts[] = $mood;
        }

        $base = implode(', ', $parts) . '.';

        // Prepend story phase label for setup and climax — gives Kling tonal context.
        $phaseLabel = match ($storyPhase) {
            'setup'  => 'Opening shot. ',
            'climax' => 'Climactic moment. ',
            'resolve'=> 'Resolution. ',
            default  => '',
        };

        return $phaseLabel . $base;
    }

    /**
     * Render timeline[] from ScenePlanner into ACTION section segments.
     *
     * Each segment: "[0–1s] Camera action. Subject action. Environment. Secondary."
     */
    private static function buildTimelineBlock(array $timeline): string
    {
        $lines = [];
        foreach ($timeline as $seg) {
            $start = self::fmt((float) ($seg['start'] ?? 0));
            $end   = self::fmt((float) ($seg['end']   ?? 1));

            $parts = [];

            // Velocity DSL token → Kling-specific motion phrase prepended to camera directive.
            $token        = $seg['velocity_token'] ?? '';
            $velPhrase    = self::VELOCITY_PHRASES[$token] ?? '';
            $cameraText   = $seg['camera'] ?? '';

            if ($cameraText !== '') {
                $parts[] = $velPhrase !== ''
                    ? $velPhrase . ' ' . lcfirst(rtrim($cameraText, '.')) . '.'
                    : rtrim($cameraText, '.') . '.';
            } elseif ($velPhrase !== '') {
                $parts[] = rtrim($velPhrase, ' —') . '.';
            }

            if (($seg['subject']     ?? '') !== '') {
                $parts[] = ucfirst(rtrim($seg['subject'], '.')) . '.';
            }
            if (($seg['environment'] ?? '') !== '') {
                $parts[] = ucfirst(rtrim($seg['environment'], '.')) . '.';
            }
            if (($seg['secondary']   ?? '') !== '') {
                $parts[] = ucfirst(rtrim($seg['secondary'], '.')) . '.';
            }

            if ($parts !== []) {
                $lines[] = "[{$start}–{$end}s] " . implode(' ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    private static function buildCameraBlock(
        string $camCode,
        string $moveCode,
        string $lensCode,
        string $motionLevel,
        array  $director = [],
        array  $composition = [],
    ): string {
        $camAction  = self::CAM_ACTION[$camCode]                        ?? 'Camera frames the subject';
        $levelTable = self::MOVE_ACTION[$motionLevel] ?? self::MOVE_ACTION['medium'];
        $moveAction = $levelTable[$moveCode]          ?? '';
        $lensEffect = self::LENS_EFFECT[$lensCode]                      ?? '';

        $parts = [$camAction];
        if ($moveAction !== '') {
            $parts[] = $moveAction;
        }
        if ($lensEffect !== '') {
            $parts[] = lcfirst($lensEffect);
        }

        $base = implode(', ', $parts) . '.';

        if ($motionLevel === 'none') {
            $base .= ' Locked off — no camera drift.';
        }

        // Director: handheld stabilization is a distinctive camera feel — name it explicitly.
        $stabilization = $director['stabilization'] ?? '';
        if ($stabilization === 'handheld') {
            $base .= ' Handheld energy throughout.';
        }

        // Director: non-default height that isn't already implied by camCode.
        $height = $director['camera_height'] ?? '';
        $heightImpliedByCam = in_array($camCode, ['AERIAL', 'MACRO'], true);
        if ($height !== '' && $height !== 'eye-level' && !$heightImpliedByCam) {
            $base .= ' ' . ucfirst(str_replace('-', ' ', $height)) . ' perspective.';
        }

        // Composition: subject framing and leading lines from CompositionPlanner.
        $position     = $composition['subject_position'] ?? '';
        $leadingLines = $composition['leading_lines']    ?? '';
        if ($position !== '' && $position !== 'center') {
            $readablePos = str_replace('_', ' ', $position);
            $base .= " {$readablePos} framing.";
        }
        if ($leadingLines !== '') {
            $base .= ' ' . ucfirst($leadingLines) . '.';
        }

        return $base;
    }

    private static function buildTemporalChoreography(
        float $dur,
        string $camCode,
        string $moveCode,
        string $motionLevel,
        string $subjectAction,
        string $secondaryMotion,
    ): string {
        $p1End = self::fmt($dur * 0.20); // first 20%: camera establishes
        $p2End = self::fmt($dur * 0.80); // next  60%: subject acts
        $total = self::fmt($dur);

        $camTiming   = self::CAM_TIMING[$camCode]                           ?? 'Camera establishes';
        $timingTable = self::MOVE_TIMING[$motionLevel] ?? self::MOVE_TIMING['medium'];
        $moveTiming  = $timingTable[$moveCode]         ?? 'locked static frame establishes';

        $segments = [];
        $segments[] = "[0–{$p1End}s] {$camTiming}; {$moveTiming}.";

        if ($subjectAction !== '') {
            $segments[] = "[{$p1End}–{$p2End}s] {$subjectAction}";
        }

        if ($secondaryMotion !== '') {
            $segments[] = "[{$p2End}–{$total}s] {$secondaryMotion}; camera motion resolves.";
        } else {
            $segments[] = "[{$p2End}–{$total}s] Scene settles; environment reacts; camera completes motion.";
        }

        return implode("\n", $segments);
    }

    private static function buildEnvironmentBlock(PromptDocument $doc, string $lightCode, array $physics = []): string
    {
        $parts = [];

        // Only use environment description if it's a real semantic expansion, not a light-code fallback.
        if (!$doc->environment->isFallback && $doc->environment->description !== '') {
            $parts[] = rtrim($doc->environment->description, '.');
        }

        if ($physics !== []) {
            // Sprint 3 physics structure: {atmosphere, interaction, background, micro_motion}
            // Sprint 2 fallback: {weather, character, environment, particles}
            // Detect which version and flatten accordingly.
            $isSprint3 = array_key_exists('atmosphere', $physics);
            $layers = $isSprint3
                ? ['atmosphere', 'micro_motion', 'interaction', 'background']
                : ['weather', 'character', 'environment', 'particles'];

            foreach ($layers as $layer) {
                foreach ($physics[$layer] ?? [] as $phrase) {
                    if ($phrase !== '') {
                        $parts[] = ucfirst(rtrim((string) $phrase, '.')) . '.';
                    }
                }
            }
        } else {
            // Fallback: rule-based PHYSICS_LAYER from light code.
            $legacy = self::PHYSICS_LAYER[$lightCode] ?? '';
            if ($legacy !== '') {
                $parts[] = $legacy;
            }
        }

        return $parts !== [] ? implode(' ', $parts) : '';
    }

    private static function buildStyleBlock(
        PromptDocument $doc,
        string $lensCode,
        ?RenderProfile $profile,
        array $director = [],
    ): string {
        $camStyle = $director['camera_style'] ?? '';

        $quality = match ($doc->quality->tier) {
            'photoreal' => 'Hyperrealistic, photographic quality, professional cinema camera',
            'high'      => 'Highly detailed, cinematic quality, sharp focus',
            'medium'    => 'Stylized realistic, balanced cinematic detail',
            default     => 'Cinematic quality',
        };

        // Override quality tier label with director camera_style when available
        if ($camStyle !== '' && $camStyle !== 'cinematic') {
            $quality .= ", {$camStyle} style";
        }

        $lensEffect = self::LENS_EFFECT[$lensCode] ?? '';
        $parts = [$quality];
        if ($lensEffect !== '') {
            $parts[] = $lensEffect;
        }

        $motionBlur = $director['motion_blur'] ?? '';
        if ($motionBlur !== '' && $motionBlur !== 'natural') {
            $parts[] = ucfirst($motionBlur) . ' motion blur';
        }

        if ($profile !== null && $profile->styleAdditions !== []) {
            array_push($parts, ...$profile->styleAdditions);
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Render the rich continuity plan into a concise Kling CONTINUITY paragraph.
     *
     * Kling needs these in one dense block — not a bullet list.
     * Priority: identity > previous dynamic state > environment > camera.
     */
    private static function buildRichContinuity(array $plan): string
    {
        $sentences = [];

        // Character identity: who this person is (must not change)
        $identity = $plan['character']['identity'] ?? [];
        $role     = $identity['role'] ?? '';
        $idParts  = array_filter([
            $identity['jersey'] ?? '',
            $identity['helmet'] ?? '',
            $identity['number'] !== '' ? '#' . $identity['number'] : '',
            $identity['gender'] ?? '',
        ]);
        if ($role !== '') {
            $idStr = $idParts !== []
                ? "Same {$role} — " . implode(', ', $idParts) . '.'
                : "Same {$role}.";
            $sentences[] = $idStr;
        }

        // Previous shot dynamic state: where the subject WAS at the end of last shot
        $prevState = $plan['previous_state'] ?? null;
        if ($prevState !== null && ($prevState['action_phase'] ?? '') !== '') {
            $sentences[] = 'Continuing from: ' . rtrim($prevState['action_phase'], '.') . '.';
            if (($prevState['object_in_hand'] ?? '') !== '') {
                $sentences[] = ucfirst($prevState['object_in_hand']) . ' still in hand.';
            }
        }

        // Environment consistency
        $env     = $plan['environment'] ?? [];
        $envDesc = array_filter([
            $env['weather']    ?? '',
            $env['time']       ?? '',
            $env['palette']    ?? '',
        ]);
        if ($envDesc !== []) {
            $sentences[] = 'Scene: ' . implode(', ', $envDesc) . '.';
        }

        // Camera consistency
        $cam     = $plan['camera'] ?? [];
        $camDesc = array_filter([
            ($cam['lens']    ?? '') !== '' ? $cam['lens'] . ' lens' : '',
            $cam['height']   ?? '',
            $cam['camera_style'] ?? '',
        ]);
        if ($camDesc !== []) {
            $sentences[] = 'Maintain camera: ' . implode(', ', $camDesc) . '.';
        }

        return implode(' ', $sentences);
    }

    /**
     * Compact renderer for text-to-video (Kling 5-second clips).
     *
     * Single dense paragraph — no block headers, no timestamps.
     * Each beat starts with a fast-motion verb the model actually executes.
     * Target: 500–700 chars.
     *
     * Text-to-video reads this as a VISUAL DESCRIPTION, not a director script.
     * "Fast zoom to eyes" → model executes zoom.
     * "[0–0.5s] snap zoom locks..." → model ignores the timestamp, misses the verb.
     */
    public static function renderCompact(PromptDocument $doc, array $dsl = []): string
    {
        $lensCode    = $dsl['lens']         ?? '50';
        $lightCode   = $dsl['light']        ?? '';
        $emoCode     = $dsl['emo']          ?? 'CRAFT';
        $sceneTitle  = $dsl['scene_title']  ?? '';
        $timeline    = $dsl['timeline']     ?? [];
        $physics     = $dsl['physics']      ?? [];

        $parts = [];

        // ── Scene (1 tight line) ──────────────────────────────────────────
        $light = self::LIGHT_BRIEF[$lightCode] ?? '';
        $mood  = self::MOOD_BRIEF[$emoCode]    ?? '';
        $scene = implode(', ', array_filter([$sceneTitle, $light, $mood]));
        $parts[] = $scene . '.';

        // ── Beats (motion-verb first, each ≤90 chars) ────────────────────
        foreach ($timeline as $seg) {
            $token   = $seg['velocity_token'] ?? 'natural';
            $prefix  = self::COMPACT_VELOCITY[$token] ?? '';
            $camera  = trim(rtrim($seg['camera']  ?? '', '. '));
            $subject = trim(rtrim($seg['subject'] ?? '', '. '));

            // Truncate camera sentence to first clause (at dash or comma)
            $camera = self::firstClause($camera, 70);

            $beat = $prefix !== ''
                ? $prefix . lcfirst($camera)
                : ucfirst($camera);

            if ($subject !== '') {
                $shortSub = self::firstClause($subject, 60);
                $beat    .= '. ' . ucfirst($shortSub);
            }

            if ($beat !== '') {
                $parts[] = rtrim($beat, '. ') . '.';
            }
        }

        // ── Physics micro-detail (most cinematic layer only, 1 phrase) ───
        $microLayer = $physics['micro_motion'] ?? $physics['atmosphere'] ?? [];
        if (!empty($microLayer[0])) {
            $parts[] = ucfirst(rtrim((string) $microLayer[0], '. ')) . '.';
        }

        // ── Style (compressed) ───────────────────────────────────────────
        $lens  = self::LENS_EFFECT[$lensCode] ?? '';
        $style = 'Cinematic, hyperrealistic, no text overlays';
        if ($lens !== '') {
            $style .= '. ' . $lens;
        }
        $parts[] = $style . '.';

        return implode(' ', $parts);
    }

    /**
     * Velocity token → compact motion-verb prefix for text-to-video.
     * Short, imperative, visual. Model treats these as "do this now".
     */
    private const COMPACT_VELOCITY = [
        'burst'  => 'Fast zoom — ',
        'rush'   => 'Rapid push-in — ',
        'push'   => 'Steady push — ',
        'brake'  => 'Camera snaps still — ',
        'hover'  => 'Hold — ',
        'static' => '',
        'natural'=> '',
    ];

    /**
     * Return text up to the first em-dash, period, or comma, capped at $max chars.
     * Keeps the most important clause and drops elaboration.
     */
    private static function firstClause(string $text, int $max): string
    {
        // Cut at first em-dash (elaboration separator in BeatFusionEngine output)
        $dashPos = mb_strpos($text, ' — ');
        if ($dashPos !== false && $dashPos < $max) {
            $text = mb_substr($text, 0, $dashPos);
        }

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        // Cut at last word boundary before $max
        $cut       = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');
        return $lastSpace ? mb_substr($cut, 0, $lastSpace) : $cut;
    }

    // ── Cinematic narrative renderer ────────────────────────────────────────

    /**
     * Physical body-motion sentences per action type and beat.
     *
     * 'hook'       — Subject state before the action begins (declarative, still).
     * 'escalation' — Used after "As": the loading/approach phase (no period — joins camera).
     * 'reveal'     — Peak action sentence (full, ends with period in buildCinematic).
     * 'payoff'     — null: payoff is camera + environment only.
     *
     * %actor% is replaced at render time with $dsl['sub']['actor'].
     */
    /**
     * Body motion sentences + synchronized camera choreography per action type and beat.
     *
     * Body fields  (hook / escalation / reveal):
     *   hook       — Subject state before action (declarative, still).
     *   escalation — Gerund form used after "As ": the loading phase.
     *   reveal     — Peak action sentence (full).
     *
     * Camera choreography fields (cam_*):
     *   cam_hook       — Starting position + framing height: where the camera IS.
     *   cam_escalation — Movement arc: start position → movement verb → end position + body reference.
     *   cam_reveal     — Camera at the decisive moment: exact position + what fills frame + instant.
     *   cam_payoff     — Resolution arc: how the camera exits the moment.
     *
     * cam_* replaces the generic ScenePlanner camera sentence for that beat.
     * Omit any cam_* key to fall back to ScenePlanner sentence + velocity prefix.
     */
    /**
     * Body motion sentences + synchronized camera choreography + progressive environment.
     *
     * Body fields  (hook / escalation / reveal):
     *   hook       — Subject state before action (declarative, still).
     *   escalation — Gerund form used after "As ": the loading phase.
     *   reveal     — Peak action sentence (full, complete chain).
     *
     * Camera choreography (cam_*) — each sentence encodes start position, velocity curve, end position, and lens:
     *   cam_hook       — LOCKED position: height, framing, lens, DOF.
     *   cam_escalation — velocity curve: near-still → accelerates → peak, referenced to body state.
     *   cam_reveal     — peak velocity moment + subject handoff (e.g. ball becomes primary subject).
     *   cam_payoff     — deceleration arc + subject exit; what the camera settles on.
     *
     * Progressive environment (env_*) — what the environment does at each specific beat:
     *   env_hook       — atmospheric opening state: what is still, what barely moves.
     *   env_escalation — environment begins responding: particles lift, fabric moves.
     *   env_reveal     — environment peak reaction: debris, displacement, pressure.
     *   (payoff handled by PAYOFF_REACTION table — not duplicated here)
     *
     * cam_* and env_* override generic ScenePlanner sentences when present.
     */
    private const BODY_MOTION_BEAT = [
        'throw' => [
            // Body motion: describe OUTCOME and weight transfer, not joint angles.
            // Avoid: arm extension, elbow angle, fingertip detail — model renders those poorly.
            // Prefer: hip rotation, body weight, ball trajectory — model renders these well.
            'hook'           => 'The %actor% stands poised behind center — weight balanced, body compact and still, reading the field with controlled intensity',
            'escalation'     => 'his weight drives forward from the ground up — explosive hip rotation leading the throw, torso coiling through as bodyweight transfers from back foot to front foot in a single committed motion',
            'reveal'         => 'The throw releases — the ball spirals cleanly downfield, launched by full body rotation and hip drive rather than arm force alone; his body follows through naturally, momentum carrying him forward, both feet briefly leaving the ground as the throw completes',
            // Camera: hold still during complex body motion, track ball after release.
            'cam_hook'       => 'A locked-off 85mm telephoto holds completely still at waist height — telephoto compression isolating the quarterback against the frozen field, shallow depth of field keeping the defensive line in soft background blur',
            'cam_escalation' => 'the camera holds near-static through the loading phase, then makes one controlled upward arc as the weight commits — arriving at a wide lateral frame at chest height just before release, keeping the full body readable in frame',
            'cam_reveal'     => 'The camera holds the wide lateral frame as the ball releases — quarterback body and ball visible together for one beat — then pivots smoothly to track the ball\'s spiral trajectory upward against the stadium sky, the quarterback receding naturally into the background',
            'cam_payoff'     => 'the camera decelerates into a slow wide orbital arc — ball visible tracing its spiral against the stadium sky — quarterback a small balanced figure below as the full stadium scale opens',
            // Environment: avoid hand references.
            'env_hook'       => 'frozen breath vapor drifts in slow controlled wisps; snow crystals hang suspended in warm amber floodlight; crowd completely silent in held anticipation',
            'env_escalation' => 'turf compresses under the plant foot; jersey fabric shifts with the body rotation; crowd begins leaning forward as one',
            'env_reveal'     => 'snow and turf debris kick up from the plant zone; the ball cuts a clean arc through the frozen air, spiral visible against the floodlit sky',
        ],
        'dunk' => [
            'hook'           => 'The %actor% gathers speed toward the basket, body low and coiled for the explosion',
            'escalation'     => 'he plants hard and explodes upward, knees driving high, rising above the rim',
            'reveal'         => 'He reaches full vertical extension — hand grips and slams the ball through the rim with full force, arm following through below the net',
            'cam_hook'       => 'A locked low-angle shot holds below knee height, wide enough to show the court stretching and the basket in the far distance — zero movement, compressed telephoto perspective',
            'cam_escalation' => 'the camera begins stationary at floor level, then accelerates upward tracking the rise — speed increasing with vertical momentum — arriving at rim height at full velocity as the player crests above the basket',
            'cam_reveal'     => 'The camera holds locked at rim height at the exact instant of contact — hand, ball, and rim fill the frame at peak sharpness, background crowd in shallow-DOF blur',
            'cam_payoff'     => 'the camera decelerates and drops in a sweeping arc below the net, pulling back to reveal the full court as the crowd erupts above',
            'env_hook'       => 'sneaker squeak echoes on hardwood; crowd noise compressed into low murmur; court lights reflect off polished floor',
            'env_escalation' => 'crowd noise rises as the player lifts; jerseys and shorts ripple with the acceleration upward',
            'env_reveal'     => 'net snaps violently downward; backboard trembles from the force; crowd sound peaks instantly',
        ],
        'kick' => [
            'hook'           => 'The %actor% stands twelve yards back, breath controlled, body coiled and completely still',
            'escalation'     => 'he strides forward with measured pace, plant foot locking hard into the turf on the final step',
            'reveal'         => 'His striking leg swings through with maximum force — the ball launches off his boot in a clean arc',
            'cam_hook'       => 'A wide locked static frame shows the full kicker and both goalposts — telephoto compression collapsing the distance, zero camera movement, crowd a soft blur behind',
            'cam_escalation' => 'the camera rushes low along the ground from behind the kicker — starting near-still then accelerating hard, closing the distance rapidly as the plant foot drops and the leg cocks back',
            'cam_reveal'     => 'The camera snaps to a locked lateral position exactly level with the plant foot at the moment of contact — boot and ball filling the frame, the turf exploding in a small burst beneath the impact',
            'cam_payoff'     => 'the camera deceleration-tilts upward to track the ball in flight against the sky, uprights visible as the target, crowd rising below',
            'env_hook'       => 'stadium breath-hold; distant crowd murmur settles to near-silence; wind just perceptible in the flags',
            'env_escalation' => 'turf grass bends slightly with the approaching stride; ambient crowd tension rises audibly',
            'env_reveal'     => 'turf explodes in a small burst at contact point; ball launches with a dull thud through still air',
        ],
        'cruise' => [
            'hook'           => 'The %actor% cuts through open ocean, hull cleaving the water cleanly ahead of a long white wake',
            'escalation'     => 'it accelerates into open water, wake foam churning white and wide behind the stern as speed builds',
            'reveal'         => 'It emerges from the sun\'s glint at full speed — bow spray flying wide off the hull in long explosive arcs',
            'cam_hook'       => 'A locked high drone shot holds wide and completely still — full hull length visible against open ocean horizon, telephoto compression flattening the perspective, wake a clean white line behind',
            'cam_escalation' => 'the drone descends and accelerates forward alongside the hull — starting slow at altitude, building speed as it drops — matching vessel velocity at hull level as bow spray begins to build',
            'cam_reveal'     => 'The camera drops to water level at the bow at maximum speed — spray and foam explode across the full frame, lens catching the full force of the bow wave at its peak',
            'cam_payoff'     => 'the drone decelerates sharply and climbs in a banking arc — vessel receding to a small white shape against the vast ocean, wake the only evidence of its passage',
            'env_hook'       => 'ocean surface glassy far from the hull; wake a clean steady line; sky wide and open',
            'env_escalation' => 'wake broadens and whitens; bow spray begins lifting; surface chop increases with speed',
            'env_reveal'     => 'bow spray erupts in full force, arcing wide in long white sheets; hull vibration visible in the water surface on both sides',
        ],
        'default' => [
            'hook'           => 'The %actor% holds position, energy compressed and ready to release',
            'escalation'     => 'the action builds and accelerates toward the decisive moment',
            'reveal'         => 'The decisive moment arrives at full force — nothing held back',
            'cam_hook'       => 'A medium locked shot holds steady, telephoto compression framing the subject with shallow DOF separating it from the scene behind',
            'cam_escalation' => 'the camera begins near-still, then accelerates toward the subject — building speed as the action builds — arriving at a tighter frame at full velocity',
            'cam_reveal'     => 'The camera holds at maximum velocity as the action peaks — subject filling the frame, background in complete DOF blur',
            'cam_payoff'     => 'the camera decelerates into a wide pull-back arc, the full scene asserting itself in the aftermath',
            'env_hook'       => 'environment holds in opening stillness; only subtle ambient movement',
            'env_escalation' => 'environment begins responding as energy builds; secondary motion accumulates',
            'env_reveal'     => 'environment reacts at peak force — maximum secondary motion, particles, displacement',
        ],
    ];

    /** How the environment reacts at the payoff beat, keyed by emotion code. */
    private const PAYOFF_REACTION = [
        'POWER'  => 'the stadium detonates — crowd surges from seats in a wave, thick breath clouds burst upward in frozen air, turf debris scatters from the force, flags snap violently, amber floodlights flood the erupting chaos',
        'JOY'    => 'the crowd bursts to life — energy flooding the frame from every direction',
        'EPIC'   => 'the full scale of the environment asserts itself — subject suddenly small against the vastness',
        'TENSE'  => 'the stadium holds its breath, tension suspended in the air unresolved',
        'AWE'    => 'the ocean stretches to the horizon — vast, indifferent, impossibly wide',
        'DRAMA'  => 'players and crowd react simultaneously, stadium sound rising from silence to roar',
        'CALM'   => 'the environment settles into stillness, the action complete and at rest',
        'CRAFT'  => 'the technique reveals itself — precise, complete, nothing wasted',
        'HOOK'   => 'energy saturates every corner of the frame from the first second',
        'FEAR'   => 'the silence deepens, the environment holding tension unresolved',
        'REVEAL' => 'the context clarifies — scale and meaning becoming apparent',
    ];

    /**
     * Velocity token → camera action prefix for escalation/reveal beats in cinematic prose.
     * Injected before the ScenePlanner camera sentence to force aggressive camera language.
     * Empty string = pass through unchanged.
     */
    private const CINEMATIC_CAM_RUSH = [
        'burst'   => 'the camera explodes forward — ',
        'rush'    => 'the camera rushes in hard — ',
        'push'    => 'the camera surges steadily — ',
        'brake'   => 'the camera cuts hard and holds — ',
        'hover'   => '',
        'static'  => '',
        'natural' => '',
    ];

    /** One-sentence feeling statement appended at the end, keyed by emotion. */
    private const CLOSING_STATEMENT = [
        'POWER'  => 'The entire action feels explosive, physically powerful, and cinematically inevitable.',
        'JOY'    => 'The moment radiates pure elation — contagious and absolutely real.',
        'EPIC'   => 'The shot feels large — larger than any single moment could contain.',
        'TENSE'  => 'The tension is unresolved, suspended like a held breath.',
        'AWE'    => 'The scale overwhelms — the subject dwarfed by the world it moves through.',
        'CRAFT'  => 'The craft is fully visible — nothing hidden, nothing wasted.',
        'HOOK'   => 'From the first frame, the viewer cannot look away.',
    ];

    /**
     * Cinematic narrative renderer for text-to-video.
     *
     * Output: continuous prose screenplay — action verbs, temporal connectors,
     * and environment reactions. Written so the model executes motion, not tags.
     *
     *   hook:       Subject state. Atmosphere physics. Camera snap-in (full sentence).
     *   escalation: As [body motion], [camera sentence].
     *   reveal:     [Body motion]. [Camera/rack focus]. [Physics interaction].
     *   payoff:     Without cutting, [camera] while [environment erupts].
     *   closing:    One-sentence feeling statement.
     *   style:      Cinematic suffix.
     */
    /**
     * Cinematic narrative renderer for text-to-video.
     *
     * Produces one continuous screenplay paragraph — no paragraph breaks between beats.
     * Each camera sentence has a start position, movement verb, and end position
     * referenced to the body's current state (choreography, not label replacement).
     *
     * Beat access is indexed (not sequential foreach) so each beat can reference
     * what comes next or before it — enabling proper temporal connectors.
     *
     * Priority: action-specific cam_* choreography > velocity-augmented ScenePlanner sentence.
     */
    public static function renderCinematic(PromptDocument $doc, array $dsl = []): string
    {
        $action   = $dsl['sub']['action'] ?? '';
        $actor    = $dsl['sub']['actor']  ?? 'the subject';
        $lensCode = $dsl['lens']          ?? '85';
        $emoCode  = $dsl['emo']           ?? 'POWER';
        $timeline = $dsl['timeline']      ?? [];
        $physics  = $dsl['physics']       ?? [];
        $reveal   = $dsl['reveal']        ?? [];

        if ($timeline === []) {
            return self::renderCompact($doc, $dsl);
        }

        // Index beats by name for look-ahead access — avoids blind sequential iteration.
        $beats = [];
        foreach ($timeline as $seg) {
            $b = $seg['beat'] ?? '';
            if ($b !== '') {
                $beats[$b] = $seg;
            }
        }

        $motionSeq   = self::BODY_MOTION_BEAT[$action] ?? self::BODY_MOTION_BEAT['default'];
        $micro       = rtrim(($physics['micro_motion'] ?? [])[0] ?? '', '. ');
        $atmosphere  = rtrim(($physics['atmosphere']   ?? [])[0] ?? '', '. ');
        $interaction = rtrim(($physics['interaction']  ?? [])[0] ?? '', '. ');

        // Action-specific per-beat environment overrides generic ScenePlanner physics when present.
        $envHook       = isset($motionSeq['env_hook'])       ? rtrim($motionSeq['env_hook'],       '. ') : null;
        $envEscalation = isset($motionSeq['env_escalation']) ? rtrim($motionSeq['env_escalation'], '. ') : null;
        $envReveal     = isset($motionSeq['env_reveal'])     ? rtrim($motionSeq['env_reveal'],     '. ') : null;

        $parts = [];

        // Front-load anatomy constraints — these must survive the 2500-char Kling truncation limit.
        $parts[] = 'Hyperrealistic, anatomically correct human body, accurate limb proportions, natural joint mechanics.';

        // ── Hook: opening body state + environment + camera establishes locked position ──
        $hookBody    = rtrim(str_replace('%actor%', $actor, $motionSeq['hook'] ?? ''), '.');
        $hookEnvText = $envHook ?? ($micro ?: $atmosphere);  // env_hook > micro_motion > atmosphere
        $hookSeg     = $beats['hook'] ?? [];
        // Action-specific camera choreography takes priority over ScenePlanner sentence
        $hookCamText = $motionSeq['cam_hook']
            ?? rtrim($hookSeg['camera'] ?? '', '. ');

        if ($hookBody !== '') {
            $parts[] = $hookBody . '.';
        }
        if ($hookEnvText !== '') {
            $parts[] = ucfirst($hookEnvText) . '.';
        }
        if ($hookCamText !== '') {
            $parts[] = ucfirst($hookCamText) . '.';
        }

        // ── Escalation: loading body motion + environment builds + camera accelerates ──
        $escalSeg  = $beats['escalation'] ?? [];
        $escalBody = $motionSeq['escalation'] ?? '';
        $escalVel  = $escalSeg['velocity_token'] ?? '';

        if (isset($motionSeq['cam_escalation'])) {
            // Action-specific: camera choreography already references body state
            $escalCamText = $motionSeq['cam_escalation'];
        } else {
            // Generic fallback: velocity prefix + ScenePlanner sentence
            $camRush      = self::CINEMATIC_CAM_RUSH[$escalVel] ?? '';
            $rawCam       = rtrim($escalSeg['camera'] ?? '', '. ');
            $escalCamText = $camRush !== '' ? $camRush . lcfirst($rawCam) : ucfirst($rawCam);
        }

        if ($escalBody !== '' && $escalCamText !== '') {
            $parts[] = 'As ' . $escalBody . ', ' . lcfirst(rtrim($escalCamText, '.')) . '.';
        } elseif ($escalCamText !== '') {
            $parts[] = ucfirst($escalCamText) . '.';
        }
        // Per-beat environment for escalation injected as its own sentence after the camera move
        if ($envEscalation !== null && $envEscalation !== '') {
            $parts[] = ucfirst($envEscalation) . '.';
        }

        // ── Reveal: peak action body + camera at maximum velocity + subject handoff ──
        $revealSeg  = $beats['reveal'] ?? [];
        $revealBody = rtrim($motionSeq['reveal'] ?? '', '. —');
        $revealVel  = $revealSeg['velocity_token'] ?? '';

        if (isset($motionSeq['cam_reveal'])) {
            $revealCamText = $motionSeq['cam_reveal'];
        } else {
            $revealInstr   = rtrim($reveal['camera_instruction'] ?? '', '. —');
            $rawRevCam     = rtrim($revealInstr ?: ($revealSeg['camera'] ?? ''), '. —');
            $camRush       = self::CINEMATIC_CAM_RUSH[$revealVel] ?? '';
            $revealCamText = $camRush !== ''
                ? ucfirst(rtrim($camRush, ' — ')) . ': ' . lcfirst($rawRevCam)
                : ucfirst($rawRevCam);
        }

        // env_reveal > physics interaction — environment peak reaction at moment of release
        $revealEnvText = $envReveal ?? ($interaction !== '' ? $interaction : null);

        if ($revealBody !== '') {
            $parts[] = $revealBody . '.';
        }
        if ($revealCamText !== '') {
            $parts[] = $revealCamText . '.';
        }
        if ($revealEnvText !== null && $revealEnvText !== '') {
            $parts[] = ucfirst($revealEnvText) . '.';
        }

        // ── Payoff: environment erupts + camera resolves the motion arc ───────
        $payoffSeg = $beats['payoff'] ?? [];
        $envReact  = self::PAYOFF_REACTION[$emoCode] ?? 'the environment reacts to the moment';

        $camPayoff = $motionSeq['cam_payoff']
            ?? self::firstClause(rtrim($payoffSeg['camera'] ?? '', '. '), 80);

        if ($camPayoff !== '') {
            $parts[] = 'Without cutting, ' . lcfirst($camPayoff) . ' while ' . $envReact . '.';
        } else {
            $parts[] = 'Without cutting, ' . $envReact . '.';
        }

        // ── Closing feeling statement + style ─────────────────────────────────
        $closing = self::CLOSING_STATEMENT[$emoCode] ?? '';
        if ($closing !== '') {
            $parts[] = $closing;
        }

        $lens  = self::LENS_EFFECT[$lensCode] ?? '';
        $style = 'Cinematic, no text overlays';
        if ($lens !== '') {
            $style .= '. ' . $lens;
        }
        $parts[] = $style . '.';

        return implode(' ', array_filter($parts));
    }

    // ── renderLayered() — Priority-layer renderer (v2) ─────────────────────────

    /**
     * Outcome-focused visual descriptions per action type.
     *
     * Describes WHAT THE VIEWER SEES, not biomechanical sequences.
     * The model interpolates heuristics from its training data — give it the
     * target visual state, not the physics chain that produces it.
     *
     * 'subject' — concise visual identity of the subject
     * 'action'  — the primary visual outcome at the peak moment
     * 'payoff'  — what the viewer sees after the peak (ball in air, crowd, etc.)
     */
    /**
     * Semantic scene descriptions per action type — narrative style, not labeled blocks.
     *
     * Philosophy (v3):
     * - Natural flowing sentences, the way a human would describe the scene.
     * - No joint vocabulary (elbow, wrist, shoulder, knee, hip rotation, torso coiling).
     * - No "explosive" (triggers AI deformation artifacts) → "controlled", "fluid", "natural".
     * - No "visual center" (model doesn't understand visual hierarchy) → "stays clearly in frame".
     * - Anatomy constraints are positive anchors, not negatives.
     *
     * 'subject' — one narrative sentence: WHO + WHERE + ATMOSPHERE
     * 'action'  — what the viewer sees happen: controlled action + what stays in frame
     * 'payoff'  — the final memorable image: ball, crowd, environment
     */
    private const VISUAL_OUTCOME = [
        'throw' => [
            // %actor% is replaced with the real article subject (e.g. "Patrick Mahomes")
            'subject' => '%actor% stands alone beneath warm stadium lights, in full uniform and helmet, ready to throw.',
            'action'  => 'A smooth, controlled throw. The football rises into a perfect spiral and stays clearly in frame. The motion feels natural and powerful.',
            'payoff'  => 'The ball spirals cleanly against the stadium sky.',
        ],
        'dunk' => [
            'subject' => '%actor% moves toward the basket with controlled speed, in full uniform.',
            'action'  => 'One fluid leap, driving the ball cleanly through the rim. The movement feels smooth and powerful.',
            'payoff'  => 'The net snaps downward. The player holds for a moment at peak height.',
        ],
        'kick' => [
            'subject' => '%actor% stands set in full uniform, behind the ball.',
            'action'  => 'A clean, fluid kick approach and strike. The ball lifts cleanly and stays clearly visible in frame.',
            'payoff'  => 'The ball rises in a smooth arc toward the uprights.',
        ],
        'cruise' => [
            'subject' => '%actor% moves at full speed across open ocean in clear daylight.',
            'action'  => 'The hull cuts cleanly through the water. Bow spray arcs wide off both sides in long white sheets.',
            'payoff'  => 'The vessel recedes toward the horizon. A long white wake marks its path.',
        ],
        'default' => [
            'subject' => '%actor% stands in position beneath bright lights, focused and still.',
            'action'  => 'One controlled, fluid movement at full intensity. The motion feels natural and powerful.',
            'payoff'  => 'The action completes cleanly. The environment reacts naturally.',
        ],
    ];

    /**
     * Camera — max 2 short sentences per action.
     * Rule: one locked position + one smooth movement only.
     * No chains, no accelerations, no orbit arcs.
     */
    private const CAMERA_LAYERED = [
        'throw'   => 'Side angle. The camera gently follows the ball into flight.',
        'dunk'    => 'Low angle from the floor. The camera rises smoothly with the leap.',
        'kick'    => 'Wide frame, side angle. The camera tilts up to follow the ball.',
        'cruise'  => 'Drone, wide. Descends gently to hull level.',
        'default' => 'Locked medium frame, side angle.',
    ];

    /**
     * Budget-aware priority-layer renderer for Kling text-to-video (v2).
     *
     * Architecture:
     *   Layer 0 (fixed prefix):  Anatomy/quality anchor — always first, never trimmed.
     *   Layer 1 (MUST):          Subject identity, primary action outcome, camera behavior.
     *   Layer 2 (SHOULD):        Scene/environment, action payoff visual.
     *   Layer 3 (MAY):           Physics micro-detail. Trimmed first if over budget.
     *   Layer 0 (fixed suffix):  Style close — always last, never trimmed.
     *
     * Budget enforcement: MUST layers are included even if over budget (non-negotiable).
     * SHOULD and MAY layers are included only if space remains. MAY is dropped before SHOULD.
     *
     * @param int $maxTokens Target token ceiling (1 token ≈ 4 chars). Default 300.
     */
    public static function renderLayered(PromptDocument $doc, array $dsl = [], int $maxTokens = 300): string
    {
        $maxChars  = $maxTokens * 4;
        $action    = $dsl['sub']['action'] ?? '';
        $actor     = $dsl['sub']['actor']  ?? 'the subject';
        $lensCode  = $dsl['lens']          ?? '85';
        $lightCode = $dsl['light']         ?? '';
        $emoCode   = $dsl['emo']           ?? 'POWER';
        $physics   = $dsl['physics']       ?? [];

        $outcome = self::VISUAL_OUTCOME[$action] ?? self::VISUAL_OUTCOME['default'];
        $camText = self::CAMERA_LAYERED[$action]  ?? self::CAMERA_LAYERED['default'];
        $subject = ucfirst(str_replace('%actor%', $actor, $outcome['subject']));

        // Fixed bookends — never trimmed regardless of budget.
        // Anatomy prefix: positive count constraints guide the model before anything else.
        // "single athlete, two arms, two legs" forces the model away from extra-limb artifacts.
        $prefix = $dsl['anatomy_prefix']
            ?? 'Hyperrealistic. Single athlete. Two arms, two legs. Natural anatomy, realistic hands.';
        $lensEffect = self::LENS_EFFECT[$lensCode] ?? '';
        $suffix = 'Cinematic, no text overlays' . ($lensEffect !== '' ? '. ' . $lensEffect : '') . '.';

        // Normalize text: strip trailing whitespace/dots, then add exactly one period.
        // This prevents double periods when constants already end with '.'.
        $norm = static function (string $s): string {
            return rtrim($s, '. ') . '.';
        };

        // Priority layers: [priority, text]
        // priority 1 = MUST, 2 = SHOULD, 3 = MAY
        $layers = [
            [1, $norm($subject)],
            [1, $norm($outcome['action'])],
            [1, $norm($camText)],
            [2, $norm(self::buildLayeredScene($lightCode, $emoCode))],
            [2, $norm($outcome['payoff'])],
            [3, $norm(self::buildLayeredPhysics($lightCode, $physics))],
        ];

        $budget = $maxChars - strlen($prefix) - strlen($suffix) - 10; // 10 = separator overhead

        $body = [];
        foreach ($layers as [$priority, $text]) {
            if ($text === '.') { // empty after normalization
                continue;
            }
            $cost = strlen($text) + 1;
            if ($priority === 1 || $budget >= $cost) {
                $body[]  = $text;
                $budget -= $cost;
            }
        }

        return implode(' ', array_filter([$prefix, ...$body, $suffix]));
    }

    private static function buildLayeredScene(string $lightCode, string $emoCode): string
    {
        $light = self::LIGHT_BRIEF[$lightCode] ?? '';
        $mood  = self::MOOD_BRIEF[$emoCode]    ?? '';
        $parts = array_filter([$light, $mood]);
        return $parts !== [] ? ucfirst(implode(', ', $parts)) . '.' : '';
    }

    private static function buildLayeredPhysics(string $lightCode, array $physics): string
    {
        // Sprint 3 physics: prefer micro_motion or atmosphere over legacy PHYSICS_LAYER.
        if ($physics !== []) {
            $phrase = ($physics['micro_motion'] ?? $physics['atmosphere'] ?? [])[0] ?? '';
            if ($phrase !== '') {
                return ucfirst(rtrim((string) $phrase, '. ')) . '.';
            }
        }
        return self::PHYSICS_LAYER[$lightCode] ?? '';
    }

    // ── Utilities ───────────────────────────────────────────────────────────────

    /** Format a float duration: drop .0 if whole, keep 1 decimal otherwise. */
    private static function fmt(float $v): string
    {
        return $v === floor($v) ? (string)(int)$v : number_format($v, 1);
    }
}
