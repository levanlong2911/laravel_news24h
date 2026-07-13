<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Narrative\QA\Rules;

use App\Services\AI\FilmOS\Narrative\QA\FindingCategory;
use App\Services\AI\FilmOS\Narrative\QA\FindingSeverity;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeAuditContext;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeFinding;
use App\Services\AI\FilmOS\Narrative\QA\NarrativeRule;

/**
 * A scene node's worldObjectRef points at a world object that does not exist.
 */
final class DanglingSceneWorldRefRule implements NarrativeRule
{
    public const CODE = 'D4.DANGLING_WORLD_REF';

    public function ruleId(): string
    {
        return 'scene.dangling_world_ref';
    }

    public function check(NarrativeAuditContext $context): array
    {
        $findings = [];

        foreach ($context->scene()->allNodes() as $nodeId => $node) {
            $ref = $node->worldObjectRef;

            if ($ref !== null && !$context->world()->hasObject($ref)) {
                // ERROR but not blocking: the node still renders from its own
                // label/type; only its world grounding is missing.
                $findings[] = new NarrativeFinding(
                    severity:  FindingSeverity::ERROR,
                    category:  FindingCategory::SCENE,
                    code:      self::CODE,
                    message:   "Scene node '{$nodeId}' references world object '{$ref}' which does not exist.",
                    ruleId:    $this->ruleId(),
                    blocking:  false,
                    subjectId: $nodeId,
                );
            }
        }

        return $findings;
    }
}
