<?php

namespace App\Video\RenderPlan;

use App\Video\Editorial\EditorialInterpreter;
use App\Video\Story\StoryGraph;
use App\Video\Timeline\TimedScene;
use App\Video\Timeline\TimedSceneGraph;
use App\Video\World\Entity;
use App\Video\World\VerifiedWorldGraph;

/**
 * Ráp Truth + Story + Scene + Intent + Timeline + Editorial → RenderPlan (mảng).
 *
 * Đây là PROJECTION + assemble, KHÔNG phải planner — mọi quyết định đã được các
 * tầng trước đưa ra. Assembler chỉ gom lại thành một document đúng schema. Giống
 * JSON serialization hơn là suy nghĩ.
 *
 * §13 tại đây: scene.world CHỈ emit khi Truth có (landscape entity / environment
 * fact). Hiện World Graph chưa bắt location (recall gap) → world VẮNG trên mọi
 * scene, và đó là ĐÚNG: provider tự điền lúc render. KHÔNG bịa medium=WATER.
 */
final class RenderPlanAssembler
{
    public function __construct(
        private readonly EditorialInterpreter $editorial = new EditorialInterpreter(),
    ) {
    }

    /**
     * @return array<string, mixed> RenderPlan sẵn sàng json_encode + validate schema
     */
    public function assemble(
        VerifiedWorldGraph $world,
        StoryGraph $story,
        TimedSceneGraph $timed,
        RenderPlanMeta $meta,
    ): array {
        $sceneAppearances = $this->countSceneAppearances($timed);

        return [
            'plan_version' => '1.0',
            'plan_id'      => $meta->planId,
            'article_id'   => $meta->articleId,
            'generated_at' => $meta->generatedAt,

            'story' => [
                'title'          => $meta->title,
                'language'       => $meta->language,
                'target_seconds' => (int) round($timed->targetSeconds),
            ],

            'world' => [
                'entities'  => array_map([$this, 'entityDoc'], $world->entities()),
                'relations' => array_map(fn ($r) => ['id' => $r->id, 'from' => $r->from, 'to' => $r->to, 'type' => $r->type], $world->relations),
                'events'    => array_map(fn ($e) => ['id' => $e->id, 'type' => $e->type, 'entity_id' => $e->entityId], $world->events),
            ],

            // facts[] có visual_hint đến từ trích xuất — pipeline hiện chưa sinh
            // hint riêng (chi tiết hình ảnh nằm trong attributes). Để rỗng, hợp lệ.
            'facts' => [],

            'acts' => array_map([$this, 'actDoc'], $story->acts),

            'scenes'   => array_map(fn (TimedScene $t) => $this->sceneDoc($t), $timed->scenes),
            'timeline' => array_map(fn (TimedScene $t) => [
                'scene_id'  => $t->intent->scene->id,
                'start_sec' => $t->time->start,
                'end_sec'   => $t->time->end,
            ], $timed->scenes),

            'assets' => $this->assetDocs($timed, $world),

            'continuity' => [
                // Invariant từ TRUTH: thuộc tính đơn-giá-trị của entity xuất hiện
                // ≥2 scene phải nhất quán xuyên các cú cắt.
                'invariants' => $this->invariants($world, $sceneAppearances),
                // Prohibition từ EDITORIAL POLICY (world-knowledge, §12) — CHƯA
                // xây engine policy; để rỗng. Đây là chỗ "no domes" sẽ thuộc về.
                'prohibitions' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entityDoc(Entity $entity): array
    {
        $attributes = $entity->renderableAttributes();

        $doc = [
            'id'   => $entity->id,
            'type' => $entity->type->value,
            // Entity anchor-only có attributes rỗng. PHP mảng rỗng encode thành
            // JSON [] còn schema đòi object {} — ép thành object cho đúng.
            'attributes' => $attributes === [] ? new \stdClass() : $attributes,
        ];

        if ($entity->identity !== null) {
            $id = ['name' => $entity->identity->name, 'visual_referent' => $entity->identity->visualReferent];

            $semantic = array_map(fn ($a) => $a->value, $entity->identity->semantic);
            if ($semantic !== []) {
                $id['semantic'] = $semantic;
            }

            $doc['identity'] = $id;
        }

        return $doc;
    }

    /**
     * @return array<string, mixed>
     */
    private function actDoc(\App\Video\Story\Act $act): array
    {
        $ref = match ($act->source) {
            \App\Video\Story\ActSource::Entity   => ['entity_ref' => $act->sourceId],
            \App\Video\Story\ActSource::Event    => ['event_ref' => $act->sourceId],
            \App\Video\Story\ActSource::Relation => ['relation_ref' => $act->sourceId],
        };

        return ['id' => $act->id, 'ordinal' => $act->ordinal, 'source' => $act->source->value] + $ref;
    }

    /**
     * @return array<string, mixed>
     */
    private function sceneDoc(TimedScene $t): array
    {
        $scene     = $t->intent->scene;
        $aesthetic = $this->editorial->aestheticFor($scene->purpose);

        return [
            'id'            => $scene->id,
            'ordinal'       => $scene->ordinal,
            'act_id'        => $scene->actId,
            'purpose'       => $scene->purpose->value,
            'subjects'      => $scene->subjectIds,
            'motion_intent' => $t->intent->motion->value,
            'camera' => [
                'framing'  => $t->intent->camera->framing->value,
                'movement' => $t->intent->camera->movement->value,
                'speed'    => $t->intent->camera->speed->value,
                'target'   => $t->intent->camera->target,
            ],
            'aesthetic' => [
                'emotion'         => $aesthetic->emotion->value,
                'composition'     => $aesthetic->composition->value,
                'light_intensity' => $aesthetic->lightIntensity->value,
                'light_grade'     => $aesthetic->lightGrade->value,
            ],
            // scene.world CỐ TÌNH vắng: Truth chưa có environment fact. §13.
            'asset_refs' => array_map(fn (string $id) => 'as_' . $id, $scene->subjectIds),
        ];
    }

    /**
     * assets[] = projection của các subject duy nhất. KHÔNG phải planner —
     * dedup/cache là việc của Python. required=true vì subject phải render được.
     *
     * @return list<array<string, mixed>>
     */
    private function assetDocs(TimedSceneGraph $timed, VerifiedWorldGraph $world): array
    {
        $subjectIds = [];
        foreach ($timed->scenes as $t) {
            foreach ($t->intent->scene->subjectIds as $id) {
                $subjectIds[$id] = true;
            }
        }

        $assets = [];
        foreach (array_keys($subjectIds) as $id) {
            $entity = $world->entity($id);

            $assets[] = [
                'id'        => 'as_' . $id,
                'kind'      => $entity?->type->value ?? 'physical_object',
                'entity_id' => $id,
                'required'  => true,
            ];
        }

        return $assets;
    }

    /**
     * @param array<string, int> $appearances
     * @return list<array<string, mixed>>
     */
    private function invariants(VerifiedWorldGraph $world, array $appearances): array
    {
        $invariants = [];

        foreach ($world->entities() as $entity) {
            if (($appearances[$entity->id] ?? 0) < 2) {
                continue; // chỉ xuất hiện một lần → không có cú cắt nào để giữ nhất quán
            }

            foreach ($entity->attributes as $name => $verified) {
                // Chỉ thuộc tính đơn-giá-trị: một tập nhiều giá trị (tiện nghi)
                // không phải "phải luôn bằng đúng cái này" theo nghĩa hình ảnh.
                if (count($verified) !== 1) {
                    continue;
                }

                $invariants[] = [
                    'entity_id' => $entity->id,
                    'attribute' => $name,
                    'value'     => $verified[0]->value,
                    'scope'     => 'always',
                ];
            }
        }

        return $invariants;
    }

    /**
     * @return array<string, int> entity id → số scene nó là subject
     */
    private function countSceneAppearances(TimedSceneGraph $timed): array
    {
        $count = [];
        foreach ($timed->scenes as $t) {
            foreach ($t->intent->scene->subjectIds as $id) {
                $count[$id] = ($count[$id] ?? 0) + 1;
            }
        }

        return $count;
    }
}
