<?php

namespace App\Video\Story;

use App\Video\World\Entity;
use App\Video\World\VerifiedWorldGraph;

/**
 * VerifiedWorldGraph → StoryGraph. Trạm đầu của Planning Layer.
 *
 * BẤT BIẾN (có Architecture Test canh): Planner chỉ đọc VerifiedWorldGraph. Nó
 * KHÔNG được chạm Evidence, quote, offset, EvidenceIndex, hay bài báo gốc. Nếu
 * nó bắt đầu `if (str_contains($quote, 'award'))` thì Truth Layer vừa xây thành
 * vô nghĩa — Truth và Planning dính lại làm một. Xem docs/video/ARCHITECTURE.md §1.
 *
 * Deterministic, không AI. "Đáng kể" đo bằng CẤU TRÚC GRAPH (centrality), không
 * bằng chủ đề. Đây là lý do cùng một Planner xử lý được yacht, sư tử, nhà máy:
 * moonrise_2020 lên đầu vì 7 quan hệ chạm vào nó + 24 thuộc tính, không vì ai
 * gõ 'yacht' vào code.
 */
final class StoryPlanner
{
    /** Event/Relation bị giảm so với entity chúng chạm tới. Quy ước, không thiêng. */
    private const CONNECTIVE_DAMPING = 0.5;

    /**
     * Stage của quá trình TẠO RA một vật thể — Objective (xác định từ chuỗi
     * relation/event.type, không cần "gu"), cùng pattern với ACTION_KEYWORDS
     * (EditorialInterpreter). Thêm 2026-07-23, bằng chứng thật: bài yacht có
     * designed_by/built_at/delivered nhưng StoryPlanner xếp theo centrality
     * thuần khiến "thiết kế→thi công→hoàn thiện" bị xáo trộn. CHỈ áp dụng khi
     * relation/event khớp — domain không có process tạo-vật-thể (NFL, tin tức
     * hành chính...) không khớp gì, thứ tự giữ NGUYÊN như trước (graceful
     * degrade). Chưa mở rộng thêm arc khác (CONFLICT/REVEAL...) — chưa có bằng
     * chứng cần, xem project_film_crew_role_mapping.md (memory).
     */
    private const STAGE_KEYWORDS = [
        'designed'    => 1,
        'conceived'   => 1,
        'founded'     => 1,
        'built'       => 2,
        'constructed' => 2,
        'manufactured' => 2,
        'delivered'   => 3,
        'launched'    => 3,
        'completed'   => 3,
        'sold'        => 4,
        'purchased'   => 4,
    ];

    public function __construct(
        private readonly int $maxActs = 8,
    ) {
    }

    public function plan(VerifiedWorldGraph $graph): StoryGraph
    {
        $ranked = $this->rankByImportance($graph);

        if ($ranked === []) {
            return new StoryGraph();
        }

        $ranked = array_slice($ranked, 0, $this->maxActs);
        $ranked = $this->orderByStage($ranked);

        $acts   = [];
        $last   = count($ranked) - 1;

        foreach (array_values($ranked) as $i => $candidate) {
            $acts[] = new Act(
                'act_' . ($i + 1),
                $i + 1,
                $candidate['source'],
                $candidate['source_id'],
                $this->roleFor($i, $last),
                $candidate['importance'],
            );
        }

        return new StoryGraph($acts);
    }

    /**
     * Act = node|edge của World Graph — cả ba loại đều có thể thành act, đúng
     * như contract (§3). Entity, Event, Relation cùng cạnh tranh trên MỘT thang
     * importance; ranking hiện tại khiến entity thường dẫn đầu, nhưng "sale of
     * Moonrise" (event trên node trung tâm) hoàn toàn có thể vượt "Feadship"
     * (builder nhắc thoáng qua) — và đó là đúng về mặt tự sự.
     *
     * @return list<array{source: ActSource, source_id: string, importance: float}>
     */
    private function rankByImportance(VerifiedWorldGraph $graph): array
    {
        $degree = $this->degreeOf($graph);

        /** @var array<string, float> $entityImportance */
        $entityImportance = [];
        $ranked = [];

        foreach ($graph->entities() as $entity) {
            $importance = $this->importanceOf($entity, $degree[$entity->id] ?? 0);
            $entityImportance[$entity->id] = $importance;

            if ($importance <= 0.0) {
                continue; // không thuộc tính, không quan hệ, không sự kiện — không có gì để kể
            }

            // Entity là VẬT, không phải quá trình — không có stage (ActSource::Entity
            // luôn null, chỉ Relation/Event mới mang nghĩa "giai đoạn").
            $ranked[] = ['source' => ActSource::Entity, 'source_id' => $entity->id, 'importance' => $importance, 'stage' => null];
        }

        // Event và Relation là act "kết nối": chúng đáng kể theo các entity mà
        // chúng chạm tới, nhưng bị GIẢM so với chính entity đó — bản thân sự vật
        // dễ kể hơn một sự kiện của nó hay một mối nối giữa hai sự vật. Damping
        // là nguyên tắc tự sự, KHÔNG phải domain knowledge; tests assert thứ tự
        // chứ không assert giá trị tuyệt đối.
        foreach ($graph->events as $event) {
            $base = $entityImportance[$event->entityId] ?? 0.0;

            if ($base > 0.0) {
                $ranked[] = [
                    'source' => ActSource::Event, 'source_id' => $event->id,
                    'importance' => $base * self::CONNECTIVE_DAMPING,
                    'stage' => $this->stageFor($event->type),
                ];
            }
        }

        foreach ($graph->relations as $relation) {
            $endpoints = ($entityImportance[$relation->from] ?? 0.0) + ($entityImportance[$relation->to] ?? 0.0);

            if ($endpoints > 0.0) {
                $ranked[] = [
                    'source' => ActSource::Relation, 'source_id' => $relation->id,
                    'importance' => $endpoints / 2 * self::CONNECTIVE_DAMPING,
                    'stage' => $this->stageFor($relation->type),
                ];
            }
        }

        // Giảm dần theo importance. Hoà thì theo source_id để deterministic —
        // KHÔNG theo thứ tự xuất hiện, vì thứ tự đó do LLM quyết, không ổn định.
        usort($ranked, function (array $a, array $b): int {
            return [$b['importance'], $a['source_id']] <=> [$a['importance'], $b['source_id']];
        });

        return $ranked;
    }

    private function stageFor(string $typeString): ?int
    {
        $type = strtolower($typeString);

        foreach (self::STAGE_KEYWORDS as $keyword => $stage) {
            if (str_contains($type, $keyword)) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Sắp lại act ĐÃ CHỌN (không đổi tiêu chí chọn ai vào top maxActs — chỉ đổi
     * THỨ TỰ hiển thị) theo stage tăng dần khi xác định được; act không khớp
     * stage nào giữ nguyên thứ tự importance tương đối giữa chúng (stable sort
     * qua $originalIndex — không phụ thuộc PHP version có usort ổn định hay
     * không). Domain không có act nào khớp STAGE_KEYWORDS → mọi stage đều null
     * → thứ tự y hệt trước khi thêm tính năng này (graceful degrade).
     *
     * CHỈ áp dụng khi có ÍT NHẤT 2 stage KHÁC NHAU xuất hiện — 1 relation
     * "built" lẻ loi (không đi kèm designed/delivered) không đủ để coi là một
     * mạch tự sự "tạo ra vật thể" thật, KHÔNG nên để nó nhảy lên trước những
     * act quan trọng hơn hẳn theo centrality. Bug thật bắt được qua
     * StoryPlannerTest (fixture Moonrise có "built" nhưng đang kể chuyện BÁN,
     * không phải kể chuyện ĐÓNG) — 2026-07-23.
     *
     * @param list<array{source: ActSource, source_id: string, importance: float, stage: ?int}> $ranked
     * @return list<array{source: ActSource, source_id: string, importance: float, stage: ?int}>
     */
    private function orderByStage(array $ranked): array
    {
        $distinctStages = array_unique(array_filter(array_column($ranked, 'stage'), fn ($s) => $s !== null));
        if (count($distinctStages) < 2) {
            return $ranked;
        }

        $indexed = [];
        foreach (array_values($ranked) as $i => $item) {
            $item['_i'] = $i; // giữ thứ tự importance gốc làm tiebreak — bảo đảm stable
            $indexed[] = $item;
        }

        usort($indexed, function (array $a, array $b): int {
            $stageA = $a['stage'] ?? PHP_INT_MAX;
            $stageB = $b['stage'] ?? PHP_INT_MAX;

            return [$stageA, $a['_i']] <=> [$stageB, $b['_i']];
        });

        foreach ($indexed as &$item) {
            unset($item['_i']);
        }
        unset($item);

        return $indexed;
    }

    /**
     * Bậc của mỗi entity: bao nhiêu quan hệ và sự kiện chạm vào nó.
     *
     * @return array<string, int>
     */
    private function degreeOf(VerifiedWorldGraph $graph): array
    {
        $degree = [];

        foreach ($graph->relations as $relation) {
            $degree[$relation->from] = ($degree[$relation->from] ?? 0) + 1;
            $degree[$relation->to]   = ($degree[$relation->to] ?? 0) + 1;
        }

        foreach ($graph->events as $event) {
            $degree[$event->entityId] = ($degree[$event->entityId] ?? 0) + 1;
        }

        return $degree;
    }

    /**
     * Centrality thô: mỗi kết nối (relation/event) nặng hơn mỗi thuộc tính, vì
     * một node được cả câu chuyện tham chiếu tới thì đáng kể hơn một node lắm
     * chi tiết nhưng đứng lẻ. Hệ số 3/1 là quy ước, không phải hằng số thiêng —
     * nó chỉ cần cho ra thứ tự ổn định, và mọi test đều assert thứ tự, không
     * assert giá trị tuyệt đối.
     */
    private function importanceOf(Entity $entity, int $degree): float
    {
        return $degree * 3.0 + count($entity->attributes) * 1.0;
    }

    private function roleFor(int $index, int $last): NarrativeRole
    {
        return match (true) {
            $index === 0     => NarrativeRole::Introduce,
            $index === $last => NarrativeRole::Resolve,
            default          => NarrativeRole::Explain,
        };
    }
}
