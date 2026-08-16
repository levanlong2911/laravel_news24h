<?php

namespace App\Video\RenderPlan;

use App\Video\Concept\CreativeConcept;
use App\Video\Story\CreationArcPlanner;

final class CreativeRenderPlanBuilder
{
    /** @param array<string, array<string, mixed>> $phases
     * @return array<string, mixed>
     */
    public function build(RenderPlanMeta $meta, CreativeConcept $concept, array $phases): array
    {
        $heroId = 'creative_subject';
        $plan = [
            'plan_version' => '1.0',
            'plan_id' => $meta->planId,
            'article_id' => $meta->articleId,
            'generated_at' => $meta->generatedAt,
            'story' => ['title' => $meta->title, 'language' => $meta->language, 'target_seconds' => 5],
            'world' => [
                'entities' => [[
                    'id' => $heroId,
                    'type' => 'vehicle',
                    'attributes' => (object) [],
                    'identity' => ['name' => 'original concept', 'visual_referent' => false],
                ]],
                'relations' => [],
                'events' => [],
            ],
            'facts' => [],
            'acts' => [],
            'scenes' => [],
            'timeline' => [],
            'assets' => [],
            'continuity' => ['invariants' => [], 'prohibitions' => []],
            'creative_concept' => $concept->toArray(),
        ];

        if ($meta->category !== '') {
            $plan['category'] = $meta->category;
        }

        return (new CreationArcPlanner($phases))->mergeInto($plan, $heroId, 'original concept');
    }
}
