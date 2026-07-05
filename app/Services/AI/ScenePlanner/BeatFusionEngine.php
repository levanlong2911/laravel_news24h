<?php

namespace App\Services\AI\ScenePlanner;

use App\Services\AI\ScenePlanner\Plans\CameraMotivationPlan;
use App\Services\AI\ScenePlanner\Plans\CompositionEvolutionPlan;
use App\Services\AI\ScenePlanner\Plans\EyeGuidancePlan;
use App\Services\AI\ScenePlanner\Plans\RevealPlan;
use App\Services\AI\ScenePlanner\Plans\VisualContrastPlan;

/**
 * BeatFusionEngine V2 — merges per-beat layer data into unified cinematic sentences.
 *
 * V1 Problem — stacking creates list-like directives that models read as separate
 * instructions rather than a coherent scene:
 *   "Dark amber tone. Snap zoom. Viewer eye locked. Depth: foreground [...]."
 *
 * V2 Solution — three innovations over V1:
 *
 * 1. ACTIVE ATMOSPHERE — light/atmosphere becomes an agent, not a descriptor.
 *    V1: "in dark amber stadium light"
 *    V2: "dark amber stadium light swallowing every surrounding distraction"
 *
 * 2. IMPLICIT EYE — viewer attention is expressed as inevitability, not directive.
 *    V1: "viewer eye snaps to ball release"
 *    V2: "the release point becomes impossible to ignore"
 *
 * 3. CAMERA MOTIVATION — camera moves have purpose, not just direction.
 *    V1: "camera pushes in"
 *    V2: "camera pushes in to compress the world to a single point of will"
 *
 * Per-beat sentence structure:
 *   hook:       [camera_core] [motivation], [atmosphere_active] — [eye_implicit].
 *   escalation: [camera_core] through [light], [fg_phrase].
 *   reveal:     [reveal_core] in [light] — [camera_core] as [eye_implicit].
 *   payoff:     [camera_core] in [light] — [eye_implicit].
 *   resolution: [camera_core] in [light] — [eye_implicit].
 */
final class BeatFusionEngine
{
    /**
     * Active atmospheric descriptions — what the light/atmosphere DOES at each beat.
     * Light is not a setting; it is an agent that acts on the scene.
     */
    private const ATMOSPHERE_ACTIVE = [
        'aerial_vehicle' => [
            'hook'       => 'dark cool cloud light dissolving all structure and identity below',
            'escalation' => 'warming atmosphere yielding to the vessel emerging through the haze',
            'reveal'     => 'bright warm golden afternoon light glorifying the vessel in full declaration',
            'payoff'     => 'rich golden hour light holding everything in warm and suspended stillness',
            'resolution' => 'softening warm light releasing the scene back to the ocean',
        ],
        'athletic_action' => [
            'hook'       => 'dark amber stadium light swallowing every surrounding distraction',
            'escalation' => 'bright warm floodlights flooding the loading body at full power',
            'reveal'     => 'cold clinical light bleaching the moment to its pure irreducible essence',
            'payoff'     => 'warm celebration light bathing stadium and moment together in resolution',
        ],
        'landscape_nature' => [
            'hook'       => 'cool neutral geological light stripping all warmth and temporal context',
            'escalation' => 'crisp high-altitude light clarifying structure as the geological scale assembles',
            'reveal'     => 'flooding golden warmth declaring the natural world in full illumination at last',
            'payoff'     => 'warm golden light holding geological time visible in a single suspended frame',
        ],
        'product_craft' => [
            'hook'       => 'dark controlled light isolating surface texture from form and function',
            'escalation' => 'clean neutral studio light clarifying the object without bias or distraction',
            'reveal'     => 'bright warm hero light elevating craftsmanship and precision to their peak',
            'payoff'     => 'bright neutral presentation light holding the object in its ideal state',
        ],
        'generic' => [
            'hook'       => 'low ambient light narrowing the entire world to this single point of presence',
            'escalation' => 'intensifying light building pressure as action approaches its decisive moment',
            'reveal'     => 'full illumination declaring the moment of commitment without ambiguity',
            'payoff'     => 'warm wide light holding subject and world together in resolution',
        ],
    ];

    /**
     * Implicit eye guidance — what commands the viewer's attention, stated as inevitability.
     * Replaces "viewer eye snaps to X" with "X becomes impossible to ignore."
     */
    private const EYE_IMPLICIT = [
        'aerial_vehicle' => [
            'hook'       => 'only velocity and scale are given — identity is withheld',
            'escalation' => 'the silhouette edge takes over the entire frame',
            'reveal'     => 'the hull waterline dominates — nothing else competes',
            'payoff'     => 'the horizon line draws attention to the full scale of things',
            'resolution' => 'the vanishing point holds until the scene closes',
        ],
        'athletic_action' => [
            'hook'       => 'only the face and its locked resolve exist in the frame',
            'escalation' => 'the grip and the loading action command the entire foreground',
            'reveal'     => 'the release point becomes impossible to look away from',
            'payoff'     => 'the stadium scale asserts itself beyond the athlete and the throw',
        ],
        'landscape_nature' => [
            'hook'       => 'only surface and geological texture are available — scale is withheld',
            'escalation' => 'the structural edge defines the depth of the world assembling',
            'reveal'     => 'the horizon declares natural scale that cannot be denied',
            'payoff'     => 'the geological world fills every depth plane of the frame',
        ],
        'product_craft' => [
            'hook'       => 'only material surface is available — form and function are withheld',
            'escalation' => 'the object edge begins to claim and define the frame',
            'reveal'     => 'the defining detail becomes the only thing that matters in the frame',
            'payoff'     => 'the complete object holds its own against every surrounding',
        ],
        'generic' => [
            'hook'       => 'only presence registers — identity and context are withheld',
            'escalation' => 'the action point rises to command the foreground and mid-depth',
            'reveal'     => 'the decisive detail becomes the only legible thing in the frame',
            'payoff'     => 'the full context asserts the scale and weight of the action',
        ],
    ];

    /**
     * Fuse all visual-direction layers into the beat timeline.
     *
     * V2 changes: EyeGuidancePlan is replaced by EYE_IMPLICIT constants; VisualContrastPlan
     * light_phrase is used but the tone string is no longer prepended as a sentence;
     * CameraMotivationPlan provides purpose clauses for non-reveal beats.
     *
     * @param  array                    $baseTimeline         Beat timeline with rhythm + curiosity applied
     * @param  string                   $category             CinematicBeatPlan::$category
     * @param  RevealPlan               $reveal               Reveal mechanism (may be empty)
     * @param  EyeGuidancePlan          $eyeGuidance          Kept for ScenePlanningResult; V2 uses EYE_IMPLICIT
     * @param  VisualContrastPlan       $visualContrast       Per-beat lighting phrase (light_phrase used)
     * @param  CompositionEvolutionPlan $compositionEvolution Per-beat depth layers
     * @param  CameraMotivationPlan     $motivation           Per-beat camera WHY (NEW in V2)
     * @return array                    Fused beat timeline
     */
    public function fuse(
        array $baseTimeline,
        string $category,
        RevealPlan $reveal,
        EyeGuidancePlan $_eyeGuidance,
        VisualContrastPlan $visualContrast,
        CompositionEvolutionPlan $compositionEvolution,
        CameraMotivationPlan $motivation,
    ): array {
        foreach ($baseTimeline as &$seg) {
            $beatName = $seg['beat'] ?? '';
            if ($beatName === '') {
                continue;
            }

            $camera      = $seg['camera']  ?? '';
            $subject     = $seg['subject'] ?? '';
            $lightPhrase = $visualContrast->lightPhraseFor($beatName);
            $comp        = $compositionEvolution->compositionFor($beatName);
            $motivPhrase = $motivation->motivationFor($beatName);

            $seg['camera']  = $this->fuseCamera($beatName, $category, $camera, $lightPhrase, $comp, $reveal, $motivPhrase);
            $seg['subject'] = $this->fuseSubject($beatName, $subject, $comp);
        }
        unset($seg);

        return $baseTimeline;
    }

    // ── Private helpers ─────────────────────────────────────────────────────

    private function fuseCamera(
        string $beatName,
        string $category,
        string $camera,
        string $lightPhrase,
        array $comp,
        RevealPlan $reveal,
        string $motivPhrase,
    ): string {
        $cameraCore = $this->clause($camera);
        $fgShort    = $this->clause($comp['foreground'] ?? '');
        $atmoActive = self::ATMOSPHERE_ACTIVE[$category][$beatName]
            ?? self::ATMOSPHERE_ACTIVE['generic'][$beatName]
            ?? '';
        $eyeImpl    = self::EYE_IMPLICIT[$category][$beatName]
            ?? self::EYE_IMPLICIT['generic'][$beatName]
            ?? '';

        // Reveal beat: reveal mechanism integrates light + camera hold + eye implicit
        if ($beatName === 'reveal' && !$reveal->isEmpty()) {
            $revealCore = $this->clause($reveal->cameraInstruction);
            return ucfirst("{$revealCore} in {$lightPhrase} — {$cameraCore} as {$eyeImpl}.");
        }

        // Hook: camera + motivation (purpose clause) + active atmosphere + eye implicit
        if ($beatName === 'hook') {
            $motivPart  = $motivPhrase !== '' ? " {$motivPhrase}" : '';
            return ucfirst("{$cameraCore}{$motivPart}, {$atmoActive} — {$eyeImpl}.");
        }

        // Escalation: camera through light + foreground depth (atmosphere embedded in "through")
        if ($beatName === 'escalation') {
            $depthPart = ($fgShort !== '' && $fgShort !== 'none') ? ", {$fgShort}" : '';
            return ucfirst("{$cameraCore} through {$lightPhrase}{$depthPart}.");
        }

        // Payoff / resolution: camera in light + eye implicit (subject handles depth)
        if ($beatName === 'payoff' || $beatName === 'resolution') {
            return ucfirst("{$cameraCore} in {$lightPhrase} — {$eyeImpl}.");
        }

        // Generic fallback
        return ucfirst("{$cameraCore} in {$lightPhrase}, {$eyeImpl}.");
    }

    private function fuseSubject(string $beatName, string $subject, array $comp): string
    {
        $subjectCore = $this->clause($subject);

        // Hook: curiosity text already captures close-up intent; depth is implicit
        if ($beatName === 'hook') {
            return ucfirst($subjectCore) . '.';
        }

        // Other beats: add one relevant depth layer to the subject description
        $depthText = match ($beatName) {
            'escalation'       => $comp['midground']  ?? '',
            'reveal'           => $comp['midground']  ?? '',
            'payoff', 'resolution' => $comp['background'] ?? '',
            default            => $comp['midground']  ?? '',
        };
        $depthShort = $this->clause($depthText);

        if ($depthShort !== '' && $depthShort !== 'none') {
            return ucfirst("{$subjectCore} — {$depthShort}.");
        }

        return ucfirst($subjectCore) . '.';
    }

    /**
     * Extract the first clause: text before the first "—" or ".", max 12 words, lowercased.
     */
    private function clause(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $parts = preg_split('/\s*[—.]\s*/u', $text, 2);
        $core  = trim($parts[0] ?? '');
        if ($core === '') {
            return '';
        }
        $words = explode(' ', $core);
        if (count($words) > 12) {
            $core = implode(' ', array_slice($words, 0, 12));
        }
        return lcfirst($core);
    }
}
