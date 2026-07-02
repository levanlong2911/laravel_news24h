<?php

namespace App\DTOs;

/**
 * Compact Cinematic DSL for one shot. Rule 1: NO provider names here.
 * ProviderResolver reads motion_level + realism + has_human to decide provider.
 */
final class ShotDTO
{
    public function __construct(
        public readonly int     $shotOrder,
        // Camera (compact enum codes — PromptCompiler expands)
        public readonly string  $cam,    // WIDE|MEDIUM|CLOSE|MACRO|ORBITAL|TRACKING|AERIAL|POV
        public readonly string  $lens,   // 24|35|50|85|135|200
        public readonly string  $light,  // W1|W2|G1|N1|N2|D1|S1|S2|C1|C2
        public readonly string  $move,   // STATIC|P1|P2|D1|D2|O1|O2|H1|T1|T2
        public readonly string  $emo,    // HOOK|CRAFT|AWE|TENSE|DRAMA|REVEAL|CALM|POWER|JOY|FEAR|EPIC
        public readonly float   $dur,
        // Visual intent — ProviderResolver uses these (Rule 1)
        public readonly string  $motionLevel,   // none|low|medium|high
        public readonly string  $realism,       // low|medium|high|photoreal
        public readonly bool    $hasHuman,
        public readonly string  $cameraGoal = '',
        // Subject
        public readonly string  $subActor  = '',
        public readonly string  $subAction = '',
        public readonly string  $subObj    = '',
        // Asset reference
        public readonly ?AssetRefDTO $assetRef = null,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            shotOrder:   (int) $data['shot_order'],
            cam:         $data['cam'],
            lens:        $data['lens'],
            light:       $data['light'],
            move:        $data['move'],
            emo:         $data['emo'],
            dur:         (float) $data['dur'],
            motionLevel: $data['motion_level'],
            realism:     $data['realism'],
            hasHuman:    (bool) $data['has_human'],
            cameraGoal:  $data['camera_goal'] ?? '',
            subActor:    $data['sub']['actor']  ?? '',
            subAction:   $data['sub']['action'] ?? '',
            subObj:      $data['sub']['obj']    ?? '',
            assetRef:    isset($data['asset_ref']) ? AssetRefDTO::fromArray($data['asset_ref']) : null,
        );
    }

    public function toArray(): array
    {
        $data = [
            'shot_order'   => $this->shotOrder,
            'cam'          => $this->cam,
            'lens'         => $this->lens,
            'light'        => $this->light,
            'move'         => $this->move,
            'emo'          => $this->emo,
            'dur'          => $this->dur,
            'motion_level' => $this->motionLevel,
            'realism'      => $this->realism,
            'has_human'    => $this->hasHuman,
            'camera_goal'  => $this->cameraGoal,
            'sub' => [
                'actor'  => $this->subActor,
                'action' => $this->subAction,
                'obj'    => $this->subObj,
            ],
        ];

        if ($this->assetRef !== null) {
            $data['asset_ref'] = $this->assetRef->toArray();
        }

        return $data;
    }
}
