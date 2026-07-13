<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA\Rules;

use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeRule;

/**
 * A camera targets a focus node that is not present in the scene.
 *
 * WARNING (not ERROR): the shot is still compilable — the camera setup is
 * valid prompt language — but the focus intent silently points at nothing,
 * which usually means a planner bug or a removed node.
 */
final class CameraFocusNodeExistsRule implements NarrativeRule
{
    public const CODE = 'D4.FOCUS_NODE_MISSING';

    public function ruleId(): string
    {
        return 'camera.focus_node_missing';
    }

    public function check(NarrativeAuditContext $context): array
    {
        $findings = [];

        foreach ($context->scene()->allCameras() as $ordinal => $camera) {
            $focus = $camera->focusNodeId;

            if ($focus !== null && !$context->scene()->hasNode($focus)) {
                $findings[] = new NarrativeFinding(
                    severity:  FindingSeverity::WARNING,
                    category:  FindingCategory::CAMERA,
                    code:      self::CODE,
                    message:   "Camera at shot {$ordinal} focuses on scene node '{$focus}' which is not in the scene.",
                    ruleId:    $this->ruleId(),
                    blocking:  false,
                    subjectId: $focus,
                    ordinal:   $ordinal,
                );
            }
        }

        return $findings;
    }
}
