<?php

namespace App\Video\Scene;

use App\Video\Story\Act;
use App\Video\Story\ActSource;
use App\Video\Story\NarrativeRole;
use App\Video\Story\StoryGraph;
use App\Video\World\VerifiedWorldGraph;

/**
 * StoryGraph → SceneGraph. Decompose mỗi act thành một hoặc nhiều scene.
 *
 * Đọc VerifiedWorldGraph để phân giải act.sourceId → subject entities. KHÔNG
 * chạm Evidence/quote/provenance (Architecture Test canh, như Story Planner).
 *
 * Deterministic, không AI, không gu đạo diễn. Số scene của một act do ĐỘ GIÀU
 * của graph quyết định (một entity nhiều thuộc tính đáng một cảnh cận sau cảnh
 * toàn), KHÔNG do chủ đề. Đây vẫn là ontology-first: cùng luật này áp cho yacht,
 * sư tử hay nhà máy.
 */
final class ScenePlanner
{
    /**
     * @param int $detailThreshold Số thuộc tính tối thiểu để một entity được kể
     *        thành 2 cảnh (toàn + cận) thay vì 1. Quy ước, không thiêng.
     */
    public function __construct(
        private readonly int $detailThreshold = 4,
    ) {
    }

    public function plan(StoryGraph $story, VerifiedWorldGraph $graph): SceneGraph
    {
        $eventEntity   = $this->indexEventEntities($graph);
        $relationEnds  = $this->indexRelationEndpoints($graph);

        $scenes  = [];
        $ordinal = 1;

        foreach ($story->acts as $act) {
            $subjects = $this->subjectsOf($act, $eventEntity, $relationEnds);

            if ($subjects === []) {
                continue; // act trỏ tới thứ không phân giải được — bỏ, không đoán
            }

            foreach ($this->purposesFor($act, $subjects, $graph) as $purpose) {
                $scenes[] = new SemanticScene(
                    'scene_' . $ordinal,
                    $act->id,
                    $ordinal,
                    $purpose,
                    $subjects,
                );
                $ordinal++;
            }
        }

        return new SceneGraph($scenes);
    }

    /**
     * @param array<string, string>        $eventEntity
     * @param array<string, array{0:string,1:string}> $relationEnds
     * @return list<string>
     */
    private function subjectsOf(Act $act, array $eventEntity, array $relationEnds): array
    {
        return match ($act->source) {
            ActSource::Entity   => [$act->sourceId],
            ActSource::Event    => isset($eventEntity[$act->sourceId]) ? [$eventEntity[$act->sourceId]] : [],
            ActSource::Relation => $relationEnds[$act->sourceId] ?? [],
        };
    }

    /**
     * Chức năng tự sự suy CƠ HỌC từ (role, source). Không gu, không domain.
     * Một entity giàu thuộc tính được kể thành hai cảnh: toàn cảnh giới thiệu
     * rồi cận cảnh chi tiết — quyết định này dựa trên ĐỘ GIÀU graph, không phải
     * chủ đề.
     *
     * @param list<string> $subjects
     * @return list<ScenePurpose>
     */
    private function purposesFor(Act $act, array $subjects, VerifiedWorldGraph $graph): array
    {
        if ($act->role === NarrativeRole::Resolve) {
            return [ScenePurpose::Resolution];
        }

        if ($act->role === NarrativeRole::Introduce) {
            $entity = $graph->entity($subjects[0]);
            $rich   = $entity !== null && count($entity->attributes) >= $this->detailThreshold;

            return $rich
                ? [ScenePurpose::Establish, ScenePurpose::Detail]
                : [ScenePurpose::Establish];
        }

        // EXPLAIN — chức năng theo loại nguồn.
        //
        // Relation → REVEAL, KHÔNG phải COMPARISON. Đây là điểm ontology sắc:
        // không phải quan hệ nào cũng là so sánh. `support_vessel_for`,
        // `original_owner`, `built` là liên kết, không so sánh gì; chỉ
        // `successor_of` mới thực sự comparison. Muốn phân biệt thì planner phải
        // hiểu NGHĨA từng loại quan hệ — đó là domain knowledge, đúng chỗ ontology
        // chết. Nên Scene chỉ nói trung tính "bộc lộ một mối liên hệ"; việc nâng
        // thành compare/support/lineage là của Editorial, nơi được phép biết
        // nghĩa quan hệ (§12). Contract enum chưa có value ASSOCIATION trung tính
        // — nếu sau này cần thì mới đổi contract (§6), chưa trả rent lúc này.
        return [match ($act->source) {
            ActSource::Entity   => ScenePurpose::Detail,
            ActSource::Event    => ScenePurpose::Action,
            ActSource::Relation => ScenePurpose::Reveal,
        }];
    }

    /**
     * @return array<string, string> event id → entity id
     */
    private function indexEventEntities(VerifiedWorldGraph $graph): array
    {
        $map = [];

        foreach ($graph->events as $event) {
            $map[$event->id] = $event->entityId;
        }

        return $map;
    }

    /**
     * @return array<string, array{0:string,1:string}> relation id → [from, to]
     */
    private function indexRelationEndpoints(VerifiedWorldGraph $graph): array
    {
        $map = [];

        foreach ($graph->relations as $relation) {
            $map[$relation->id] = [$relation->from, $relation->to];
        }

        return $map;
    }
}
