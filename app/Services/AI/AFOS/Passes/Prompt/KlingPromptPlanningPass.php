<?php

namespace App\Services\AI\AFOS\Passes\Prompt;

use App\Services\AI\AFOS\Creative\Intent;
use App\Services\AI\AFOS\Ir\CameraIR;
use App\Services\AI\AFOS\Ir\CompositionIR;
use App\Services\AI\AFOS\Ir\PromptIR;
use App\Services\AI\AFOS\Types\CameraHeight;
use App\Services\AI\AFOS\Types\CameraMovementType;
use App\Services\AI\AFOS\Types\CompositionRule;
use App\Services\AI\AFOS\Types\DOFLevel;
use App\Services\AI\AFOS\Types\Emotion;
use App\Services\AI\AFOS\Types\EyeFlowDirection;
use App\Services\AI\AFOS\Types\FramingType;
use App\Services\AI\AFOS\Types\NegativeSpaceDirection;

/**
 * KlingPromptPlanningPass — transforms typed IR into PromptIR for Kling's prompt syntax.
 *
 * Pipeline tier 3:
 *   CameraIR + CompositionIR + Intent → PromptIR
 *
 * This is the only class that knows Kling's vocabulary and clause structure.
 * Backend (KlingBackend) only receives PromptIR and serializes it — no logic.
 *
 * Swapping backends: implement a new PromptPlanningPass (VeoPromptPlanningPass,
 * RunwayPromptPlanningPass) and register it in AfosPassManager. Zero changes
 * to composition or camera passes.
 *
 * Vocabulary tables (entityToPhrase, framingToVerb, etc.) belong here, not in
 * the backend. The backend is a formatter, not a vocabulary holder.
 */
final class KlingPromptPlanningPass
{
    public function name(): string { return 'KlingPromptPlanningPass'; }

    public function run(CameraIR $camera, CompositionIR $composition, Intent $intent): PromptIR
    {
        return new PromptIR(
            shotId:            $composition->shotId,
            subjectClause:     $this->subjectClause($composition, $camera),
            atmosphereClause:  $this->atmosphereClause($intent->primaryEmotion, $composition),
            cameraClause:      $this->cameraChoreography($camera),
            compositionClause: $this->compositionLanguage($composition),
            emotionalClose:    $this->emotionalClose($intent->primaryEmotion),
            technicalSpec:     'Cinematic, hyperrealistic, no text overlays. ' . $this->technicalSpec($camera, $intent),
        );
    }

    // ── SUBJECT ───────────────────────────────────────────────────────────────

    private function subjectClause(CompositionIR $composition, CameraIR $camera): string
    {
        $entity = $this->entityToPhrase($composition->primarySubjectEntity);
        $scale  = $this->framingToVerb($camera->framing);

        $depth = '';
        if ($composition->foregroundEntity && $composition->backgroundEntity) {
            $fg    = $this->entityToPhrase($composition->foregroundEntity);
            $bg    = $this->entityToPhrase($composition->backgroundEntity);
            $depth = ", {$fg} in foreground pulling the eye, {$bg} receding behind";
        } elseif ($composition->foregroundEntity) {
            $fg    = $this->entityToPhrase($composition->foregroundEntity);
            $depth = ", {$fg} passing through foreground";
        } elseif ($composition->backgroundEntity) {
            $bg    = $this->entityToPhrase($composition->backgroundEntity);
            $depth = ", {$bg} dissolving into background";
        }

        return "The {$entity} {$scale}{$depth}.";
    }

    private function entityToPhrase(string $entityRef): string
    {
        static $map = [
            'pool_reflection'  => 'villa pool and its mirror-perfect reflection',
            'pool_surface'     => 'still pool surface',
            'reflection'       => 'glass-still reflection',
            'facade'           => 'stone facade',
            'villa_facade'     => "villa's travertine facade",
            'terrace'          => 'travertine terrace',
            'infinity_edge'    => 'infinity edge meeting the horizon',
            'infinity_pool'    => 'infinity pool',
            'courtyard'        => 'open courtyard',
            'interior'         => 'sun-lit interior',
            'view'             => 'panoramic view beyond',
            'horizon'          => 'horizon line',
            'sky'              => 'open sky',
            'mountain'         => 'mountain range',
            'architecture'     => 'architecture',
            'detail'           => 'architectural detail',
            'material'         => 'material surface',
            'stone'            => 'stone surface',
            'water'            => 'water surface',
            'glass'            => 'glass panel',
            'light'            => 'quality of light',
            'shadow'           => 'shadow pattern',
            'subject'          => 'subject',
            'hero_subject'     => 'subject',
            'vehicle'          => 'vehicle',
        ];

        $key = strtolower($entityRef);
        if (isset($map[$key])) {
            return $map[$key];
        }

        // EntityRef.displayName is not available here — fall back to
        // humanising the entity ID. Phase B: pass EntityRef through IR.
        return str_replace('_', ' ', $key);
    }

    private function framingToVerb(FramingType $framing): string
    {
        return match ($framing) {
            FramingType::EXTREME_CLOSE => 'fills the entire frame — every grain and texture visible',
            FramingType::CLOSE         => 'occupies the frame with commanding presence',
            FramingType::MEDIUM        => 'anchors the composition with clear weight',
            FramingType::WIDE          => 'sits within a wide field of negative space and context',
            FramingType::EXTREME_WIDE  => 'rests small against the vast scale of its environment',
        };
    }

    // ── ATMOSPHERE ────────────────────────────────────────────────────────────

    private function atmosphereClause(Emotion $emotion, CompositionIR $composition): string
    {
        $base = match ($emotion) {
            Emotion::SERENITY  => 'Warm golden-hour light moves imperceptibly across still water; the air holds a silence that feels chosen, not empty',
            Emotion::LUXURY    => 'Precise light describes every material with care — no surface is accidental, each catches light at a deliberate angle; the space breathes with the weight of considered craft',
            Emotion::WONDER    => 'Light arrives from an unexpected angle, making familiar forms suddenly strange; the environment withholds something just off-frame, drawing the eye further',
            Emotion::POWER     => 'Contrast defines every plane — light compressed against shadow with no mid-tone to soften the force; the environment holds tension like a coiled spring',
            Emotion::TRIUMPH   => 'Light floods the space from above and ahead; the environment opens outward as if the world has moved aside to acknowledge the moment',
            Emotion::CURIOSITY => 'A partial reveal teases what lies beyond; edges hold secrets, the frame is a question more than an answer',
            Emotion::TENSION   => 'Shadow and highlight compress the space — air feels charged, as if a threshold is about to be crossed',
            Emotion::ISOLATION => 'Vast, deliberate negative space surrounds the subject; silence is not absence but presence — solitude rendered as sovereignty',
        };

        if ($composition->negativeSpaceAmount >= 0.40) {
            $dir = match ($composition->negativeSpaceDirection) {
                NegativeSpaceDirection::RIGHT  => 'The right side of the frame opens into pure light and air',
                NegativeSpaceDirection::LEFT   => 'The left side breathes open space into the composition',
                NegativeSpaceDirection::TOP    => 'Above, the sky opens endlessly',
                NegativeSpaceDirection::BOTTOM => 'Below, ground and shadow anchor the weight of the frame',
            };
            return "{$base}. {$dir}.";
        }

        return "{$base}.";
    }

    // ── CAMERA CHOREOGRAPHY ───────────────────────────────────────────────────

    private function cameraChoreography(CameraIR $camera): string
    {
        $heightStart = $this->heightToPhrase($camera->startHeight);
        $heightEnd   = $this->heightToPhrase($camera->endHeight);
        $movement    = $this->movementToPhrase($camera->movementType, $heightStart, $heightEnd, $camera->velocityCurve);
        $lens        = "{$camera->focalLengthMm}mm telephoto";
        $dof         = match ($camera->dof) {
            DOFLevel::SHALLOW => 'shallow depth of field isolating the subject against background blur',
            DOFLevel::MEDIUM  => 'medium depth of field preserving environmental context',
            DOFLevel::DEEP    => 'deep focus holding the full spatial environment sharp',
        };

        return "The camera {$movement}, on a {$lens} with {$dof}.";
    }

    private function heightToPhrase(CameraHeight $height): string
    {
        return match ($height) {
            CameraHeight::GROUND      => 'at ground level',
            CameraHeight::ANKLE       => 'at ankle height, just above the surface',
            CameraHeight::KNEE        => 'at knee height',
            CameraHeight::HIP         => 'at hip height',
            CameraHeight::WAIST       => 'at waist height',
            CameraHeight::CHEST       => 'at chest height',
            CameraHeight::EYE         => 'at eye level',
            CameraHeight::ABOVE_HEAD  => 'above head height',
            CameraHeight::DRONE_LOW   => 'at low drone altitude, skimming just above the scene',
            CameraHeight::DRONE_HIGH  => 'at high altitude, commanding a full aerial view',
        };
    }

    private function movementToPhrase(
        CameraMovementType $type,
        string             $heightStart,
        string             $heightEnd,
        array              $curve,
    ): string {
        $isStaticStart = count($curve) > 0 && $curve[0] <= 0.05;

        return match ($type) {
            CameraMovementType::STATIC => "holds completely locked {$heightStart}, not a millimeter of drift — the stillness itself becomes the statement",

            CameraMovementType::PUSH_IN => $isStaticStart
                ? "begins completely still {$heightStart}, then breathes into a slow, deliberate push — gathering speed gradually, arriving at intimacy without announcing the move"
                : "pushes in steadily {$heightStart}, closing distance with quiet intention",

            CameraMovementType::PULL_OUT => "begins {$heightStart}, then pulls back in a slow controlled withdrawal — the subject shrinks as the world beyond it grows",

            CameraMovementType::CRANE_UP => "rises on a smooth crane from {$heightStart} to {$heightEnd} — ascending without interruption, the ground releasing into a broader field",

            CameraMovementType::CRANE_DOWN => "descends from {$heightStart} to {$heightEnd} — the frame compressing gently as it arrives at intimacy with the subject",

            CameraMovementType::ORBIT => "performs a slow orbital arc {$heightStart} — moving around the subject as if examining it from every angle simultaneously",

            CameraMovementType::TRACKING => "tracks alongside {$heightStart}, maintaining constant distance while the environment slides through the frame",

            CameraMovementType::DRONE_DESCEND => "descends from {$heightStart} to {$heightEnd} — the scene expanding in resolution as altitude drops",

            CameraMovementType::DRONE_ASCEND => "ascends from {$heightStart} to {$heightEnd} — individual details dissolving into a commanding aerial perspective",

            CameraMovementType::FPV => "moves through the space in an immersive first-person sweep {$heightStart}",

            CameraMovementType::HANDHELD => "carries a barely perceptible human tremor {$heightStart} — the imperfection that signals presence",
        };
    }

    // ── COMPOSITIONAL LANGUAGE ────────────────────────────────────────────────

    private function compositionLanguage(CompositionIR $composition): string
    {
        $rule = match ($composition->compositionRule) {
            CompositionRule::GOLDEN_RATIO   => 'Golden ratio composition — subject positioned at the phi point, proportions that feel inevitable rather than designed',
            CompositionRule::RULE_OF_THIRDS => 'Rule-of-thirds framing — subject anchored at the intersection, horizon dividing the frame with classical authority',
            CompositionRule::SYMMETRY       => 'Perfect bilateral symmetry — the frame folds on itself; left mirrors right with architectural precision',
            CompositionRule::LEAD_ROOM      => "Lead-room framing — open space ahead of the subject's implied direction, giving the composition room to breathe",
            CompositionRule::NEGATIVE_LEAD  => "Negative-space lead — the subject pressed toward the edge, the open frame pulling the eye deeper",
            CompositionRule::CENTER_WEIGHT  => "Central compositional weight — the subject at the frame's exact center; everything radiates outward from that anchor",
        };

        $flow = match ($composition->eyeFlowDirection) {
            EyeFlowDirection::LEFT_TO_RIGHT  => 'Eye flow traces naturally from left to right — the reading direction lending the composition a forward momentum',
            EyeFlowDirection::RIGHT_TO_LEFT  => 'Eye flow moves against convention, right to left — creating a subtle resistance the viewer must lean into',
            EyeFlowDirection::TOP_TO_BOTTOM  => 'The eye descends through the frame — falling from sky to earth, from broad to intimate',
            EyeFlowDirection::DIAGONAL_TL_BR => 'A diagonal tension pulls from upper-left to lower-right — the most natural eye path, here made conscious and deliberate',
            EyeFlowDirection::DIAGONAL_TR_BL => 'The diagonal runs counter-intuitively — a deliberate resistance to the expected reading path',
            EyeFlowDirection::CENTRIPETAL    => 'The eye is drawn inward — foreground elements functioning as a vignette that concentrates attention at center depth',
        };

        return "{$rule}. {$flow}.";
    }

    // ── EMOTIONAL CLOSE ───────────────────────────────────────────────────────

    private function emotionalClose(Emotion $emotion): string
    {
        return match ($emotion) {
            Emotion::SERENITY  => 'The entire image feels like a held breath — suspended, completely peaceful, inevitable. Time has stopped cooperating with urgency.',
            Emotion::LUXURY    => 'Every detail communicates care. Nothing in frame is accidental. The space earns the privilege of being watched.',
            Emotion::WONDER    => 'The shot radiates discovery — an invitation the viewer cannot refuse, a question that opens into another question.',
            Emotion::POWER     => 'The frame holds contained force — energy waiting for release, compressed into perfect cinematic stillness before the inevitable explosion.',
            Emotion::TRIUMPH   => 'The moment radiates earned achievement — real, grounded, impossible to mistake for performance.',
            Emotion::CURIOSITY => 'The shot creates a question the eye cannot stop trying to answer. Something just outside the frame matters enormously.',
            Emotion::TENSION   => 'The frame holds a threat just below the surface. Something is gathering. Something is about to break.',
            Emotion::ISOLATION => "The subject's solitude is not loneliness — it is sovereignty. The space around it is not emptiness but a declaration.",
        };
    }

    // ── TECHNICAL SPEC ────────────────────────────────────────────────────────

    private function technicalSpec(CameraIR $camera, Intent $intent): string
    {
        $lens = match (true) {
            $camera->focalLengthMm <= 24  => 'Ultra-wide perspective (≤24mm), full environmental context.',
            $camera->focalLengthMm <= 35  => 'Wide-angle perspective (35mm), natural environmental relationship.',
            $camera->focalLengthMm <= 50  => 'Standard lens (50mm), neutral perspective compression.',
            $camera->focalLengthMm <= 85  => 'Short telephoto (85mm), subject separated from background.',
            $camera->focalLengthMm <= 135 => 'Telephoto compression (135mm), background fully collapsed.',
            default                        => "Long telephoto ({$camera->focalLengthMm}mm), extreme background compression.",
        };

        $tempo = match ($intent->tempo->value) {
            'meditative' => 'Meditative pacing — single sustained move.',
            'measured'   => 'Measured pacing — slow and deliberate.',
            'building'   => 'Building rhythm — gaining momentum through the shot.',
            'urgent'     => 'Urgent rhythm — compressed timing, forward pressure.',
            'explosive'  => 'Explosive pacing — maximum energy, peak motion.',
            default      => 'Measured pacing.',
        };

        return "{$lens} f/{$camera->aperture}. {$tempo}";
    }
}
