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
        'POWER'  => 'explosive athletic intensity',
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
            if (($seg['camera']      ?? '') !== '') {
                $parts[] = rtrim($seg['camera'], '.') . '.';
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

    /** Format a float duration: drop .0 if whole, keep 1 decimal otherwise. */
    private static function fmt(float $v): string
    {
        return $v === floor($v) ? (string)(int)$v : number_format($v, 1);
    }
}
