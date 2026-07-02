<?php

namespace App\Services\AI\PromptAST\Serializers;

use App\Services\AI\PromptAST\Blocks\CameraBlock;
use App\Services\AI\PromptAST\Blocks\CinematicBlock;
use App\Services\AI\PromptAST\Blocks\ContinuityBlock;
use App\Services\AI\PromptAST\Blocks\EnvironmentBlock;
use App\Services\AI\PromptAST\Blocks\SceneBlock;
use App\Services\AI\PromptAST\Blocks\StyleBlock;
use App\Services\AI\PromptAST\Blocks\TimelineBlock;
use App\Services\AI\PromptAST\PromptAST;
use App\Services\AI\SceneGraph\Enums\CameraType;
use App\Services\AI\SceneGraph\Nodes\PhaseNode;

/**
 * Serializes PromptAST → Kling 2.x structured prompt string.
 *
 * Kling format: labeled sections the model parses as structured intent.
 *   SCENE       — 1-line visual context (location + light + mood)
 *   CAMERA      — what the camera does and how
 *   ACTION      — temporal choreography with [0–Xs] timestamp segments
 *   ENVIRONMENT — full scene + physics layer (what naturally moves)
 *   STYLE       — quality tier + perceptual lens effect
 *   CONTINUITY  — anchor injection (shots 2+ only)
 *
 * All lookup tables are keyed by enum->value (string) — NOT by enum instances,
 * which are objects and cannot be used as PHP array keys at runtime.
 * Access pattern: self::TABLE[$enum->value] ?? 'fallback'
 */
final class KlingSerializer implements PromptSerializer
{
    // ── Lookup tables — Kling-specific language ──────────────────────────────
    // Keys are enum->value strings. Emotion/CameraType/CameraMove values are
    // uppercase ('POWER', 'AERIAL', 'P1'). MotionLevel values are lowercase
    // ('high', 'medium', 'low', 'none').

    /** Emotion->value → 1-phrase mood label for SCENE section */
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

    /** DSL light code → brief 1-phrase descriptor for SCENE section */
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

    /** CameraType->value → full action verb phrase for CAMERA section */
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

    /** CameraType->value → brief establishing phrase for ACTION phase 1 */
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

    /**
     * MotionLevel->value → CameraMove->value → camera movement phrase for CAMERA section.
     * Kling reads "slowly" and "gently" literally — speed-appropriate language is required.
     *
     * Outer key: MotionLevel->value ('high', 'medium', 'low', 'none')
     * Inner key: CameraMove->value ('STATIC', 'P1', 'P2', ...)
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
        'none' => [
            'STATIC' => '',
            'P1'     => 'with minimal push-in',
            'P2'     => 'with minimal pull-back',
            'D1'     => 'with minimal rightward drift',
            'D2'     => 'with minimal leftward drift',
            'O1'     => 'with barely perceptible clockwise movement',
            'O2'     => 'with barely perceptible counterclockwise movement',
            'H1'     => 'with barely perceptible handheld breath',
            'T1'     => 'with barely perceptible upward tilt',
            'T2'     => 'with barely perceptible downward tilt',
        ],
    ];

    /** MotionLevel->value → CameraMove->value → temporal phase 1 start description */
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
        'none' => [
            'STATIC' => 'perfectly locked static frame',
            'P1'     => 'almost imperceptible push begins',
            'P2'     => 'almost imperceptible pull begins',
            'D1'     => 'minimal rightward drift',
            'D2'     => 'minimal leftward drift',
            'O1'     => 'barely perceptible clockwise arc',
            'O2'     => 'barely perceptible counterclockwise arc',
            'H1'     => 'breath-level handheld weight',
            'T1'     => 'imperceptible upward float',
            'T2'     => 'imperceptible downward settle',
        ],
    ];

    /** Lens code → perceptual effect label for CAMERA and STYLE sections */
    private const LENS_EFFECT = [
        '16'  => 'Extreme ultra-wide — environmental immersion',
        '24'  => 'Ultra-wide perspective with environmental context',
        '35'  => 'Natural wide perspective',
        '50'  => 'Natural eye-level perspective',
        '85'  => 'Telephoto compression with shallow depth of field',
        '135' => 'Strong telephoto compression isolating the subject',
        '200' => 'Extreme telephoto, subject separated from background',
    ];

    /** DSL light code → fallback physics layer when PhysicsPlanner has no output */
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

    /** Emotion->value → crowd/scene reaction for fallback choreography final segment */
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

    // ── Public entry point ───────────────────────────────────────────────────

    public function serialize(PromptAST $ast): string
    {
        $sections = [];

        $sections[] = 'SCENE' . "\n" . $this->serializeScene($ast->scene);
        $sections[] = 'CAMERA' . "\n" . $this->serializeCamera($ast->camera);

        $actionText = $ast->timeline->isEmpty()
            ? $this->serializeFallbackChoreography($ast->timeline, $ast->camera, $ast->cinematic)
            : $this->serializeTimeline($ast->timeline);

        if ($actionText !== '') {
            $sections[] = 'ACTION' . "\n" . $actionText;
        }

        $envText = $this->serializeEnvironment($ast->environment, $ast->scene->lightCode);
        if ($envText !== '') {
            $sections[] = 'ENVIRONMENT' . "\n" . $envText;
        }

        $sections[] = 'STYLE' . "\n" . $this->serializeStyle($ast->style, $ast->camera->lensCode);

        if ($ast->continuity !== null) {
            $contText = $this->serializeContinuity($ast->continuity);
            if ($contText !== '') {
                $sections[] = 'CONTINUITY' . "\n" . $contText;
            }
        }

        return implode("\n\n", $sections);
    }

    // ── Section serializers ──────────────────────────────────────────────────

    private function serializeScene(SceneBlock $scene): string
    {
        $parts = [];
        if ($scene->sceneTitle !== '') {
            $parts[] = $scene->sceneTitle;
        }
        $light = self::LIGHT_BRIEF[$scene->lightCode] ?? '';
        if ($light !== '') {
            $parts[] = $light;
        }
        $mood = self::MOOD_BRIEF[$scene->emotion->value] ?? '';
        if ($mood !== '') {
            $parts[] = $mood;
        }

        $base = implode(', ', $parts) . '.';

        $phaseLabel = match ($scene->storyPhase->value) {
            'setup'   => 'Opening shot. ',
            'climax'  => 'Climactic moment. ',
            'resolve' => 'Resolution. ',
            default   => '',
        };

        return $phaseLabel . $base;
    }

    private function serializeCamera(CameraBlock $cam): string
    {
        $camAction  = self::CAM_ACTION[$cam->camType->value]      ?? 'Camera frames the subject';
        $moveTable  = self::MOVE_ACTION[$cam->motionLevel->value] ?? self::MOVE_ACTION['medium'];
        $moveAction = $moveTable[$cam->move->value]               ?? '';
        $lensEffect = self::LENS_EFFECT[$cam->lensCode]           ?? '';

        $parts = [$camAction];
        if ($moveAction !== '') {
            $parts[] = $moveAction;
        }
        if ($lensEffect !== '') {
            $parts[] = lcfirst($lensEffect);
        }

        $base = implode(', ', $parts) . '.';

        if ($cam->motionLevel->value === 'none') {
            $base .= ' Locked off — no camera drift.';
        }

        if ($cam->stabilization === 'handheld') {
            $base .= ' Handheld energy throughout.';
        }

        // Height already implied by AERIAL and MACRO — don't repeat it
        $heightImpliedByCam = in_array($cam->camType, [CameraType::AERIAL, CameraType::MACRO], true);
        if ($cam->height !== '' && $cam->height !== 'eye-level' && !$heightImpliedByCam) {
            $base .= ' ' . ucfirst(str_replace('-', ' ', $cam->height)) . ' perspective.';
        }

        if ($cam->subjectPosition !== '' && $cam->subjectPosition !== 'center') {
            $base .= ' ' . str_replace('_', ' ', $cam->subjectPosition) . ' framing.';
        }

        if ($cam->leadingLines !== '') {
            $base .= ' ' . ucfirst($cam->leadingLines) . '.';
        }

        return $base;
    }

    /**
     * Serialize PhaseNode[] → ACTION timestamp segments.
     * Each segment: "[0–Xs] Camera. Subject. Environment. Secondary."
     */
    private function serializeTimeline(TimelineBlock $tl): string
    {
        $lines = [];
        foreach ($tl->phases as $phase) {
            /** @var PhaseNode $phase */
            $start = $this->fmt($phase->start);
            $end   = $this->fmt($phase->end);

            $parts = [];
            if ($phase->camera !== '') {
                $parts[] = rtrim($phase->camera, '.') . '.';
            }
            if ($phase->subject !== '') {
                $parts[] = ucfirst(rtrim($phase->subject, '.')) . '.';
            }
            if ($phase->environment !== '') {
                $parts[] = ucfirst(rtrim($phase->environment, '.')) . '.';
            }
            if ($phase->secondary !== '') {
                $parts[] = ucfirst(rtrim($phase->secondary, '.')) . '.';
            }

            if ($parts !== []) {
                $lines[] = "[{$start}–{$end}s] " . implode(' ', $parts);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Rule-based fallback when no explicit timeline phases exist.
     * Three-segment structure: [establish] → [action/goal] → [reaction].
     */
    private function serializeFallbackChoreography(
        TimelineBlock  $tl,
        CameraBlock    $cam,
        CinematicBlock $cinematic,
    ): string {
        $dur   = $tl->shotDuration;
        $p1End = $this->fmt($dur * 0.20);
        $p2End = $this->fmt($dur * 0.80);
        $total = $this->fmt($dur);

        $camTiming  = self::CAM_TIMING[$cam->camType->value]       ?? 'Camera establishes';
        $moveTable  = self::MOVE_TIMING[$cam->motionLevel->value]  ?? self::MOVE_TIMING['medium'];
        $moveTiming = $moveTable[$cam->move->value]                ?? 'locked static frame establishes';
        $secondary  = self::SECONDARY_MOTION[$cinematic->emotion->value] ?? '';

        $segments   = [];
        $segments[] = "[0–{$p1End}s] {$camTiming}; {$moveTiming}.";
        if ($cinematic->goal !== '') {
            $segments[] = "[{$p1End}–{$p2End}s] " . ucfirst(rtrim($cinematic->goal, '.')) . '.';
        }

        if ($secondary !== '') {
            $segments[] = "[{$p2End}–{$total}s] {$secondary}; camera motion resolves.";
        } else {
            $segments[] = "[{$p2End}–{$total}s] Scene settles; environment reacts; camera completes motion.";
        }

        return implode("\n", $segments);
    }

    private function serializeEnvironment(EnvironmentBlock $env, string $lightCode): string
    {
        $parts = [];

        if ($env->hasPhysics()) {
            foreach (['atmosphere', 'microMotion', 'interaction', 'background'] as $layer) {
                foreach ($env->$layer as $phrase) {
                    if ($phrase !== '') {
                        $parts[] = ucfirst(rtrim((string) $phrase, '.')) . '.';
                    }
                }
            }
        } else {
            $fallback = self::PHYSICS_LAYER[$lightCode] ?? '';
            if ($fallback !== '') {
                $parts[] = $fallback;
            }
        }

        return $parts !== [] ? implode(' ', $parts) : '';
    }

    private function serializeStyle(StyleBlock $style, string $lensCode): string
    {
        $quality = match ($style->qualityTier) {
            'photoreal' => 'Hyperrealistic, photographic quality, professional cinema camera',
            'high'      => 'Highly detailed, cinematic quality, sharp focus',
            'medium'    => 'Stylized realistic, balanced cinematic detail',
            default     => 'Cinematic quality',
        };

        $lensEffect = self::LENS_EFFECT[$lensCode] ?? '';
        $parts      = [$quality];
        if ($lensEffect !== '') {
            $parts[] = $lensEffect;
        }

        if ($style->motionBlur >= 0.6) {
            $parts[] = 'High motion blur';
        } elseif ($style->motionBlur <= 0.15) {
            $parts[] = 'Minimal motion blur';
        }

        return implode('. ', $parts) . '.';
    }

    /**
     * Serialize ContinuityBlock → Kling CONTINUITY paragraph (dense, not bullet list).
     * Priority: identity → previous state → environment → camera.
     */
    private function serializeContinuity(ContinuityBlock $cont): string
    {
        $sentences = [];

        $id   = $cont->identity;
        $role = $id->role;
        if ($role !== '') {
            $idParts = array_filter([
                $id->jersey,
                $id->helmet,
                $id->number !== '' ? '#' . $id->number : '',
                $id->gender,
            ]);
            $sentences[] = $idParts !== []
                ? "Same {$role} — " . implode(', ', $idParts) . '.'
                : "Same {$role}.";
        }

        $prev = $cont->previousState;
        if ($prev !== null && $prev->actionPhase !== '') {
            $sentences[] = 'Continuing from: ' . rtrim($prev->actionPhase, '.') . '.';
            if ($prev->objectInHand !== '') {
                $sentences[] = ucfirst($prev->objectInHand) . ' still in hand.';
            }
        }

        $env = $cont->environment;
        $envParts = array_values(array_unique(array_filter([$env->weather, $env->time, $env->palette])));
        if ($envParts !== []) {
            $sentences[] = 'Scene: ' . implode(', ', $envParts) . '.';
        }

        $cam = $cont->camera;
        $camParts = array_filter([
            $cam->lens    !== '' ? $cam->lens . ' lens' : '',
            $cam->height,
            $cam->cameraStyle,
        ]);
        if ($camParts !== []) {
            $sentences[] = 'Maintain camera: ' . implode(', ', $camParts) . '.';
        }

        return implode(' ', $sentences);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function fmt(float $v): string
    {
        return $v === floor($v) ? (string)(int)$v : number_format($v, 1);
    }
}
