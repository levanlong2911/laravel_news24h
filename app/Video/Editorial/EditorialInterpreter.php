<?php

namespace App\Video\Editorial;

use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\VerifiedWorldGraph;

/**
 * Editorial — nơi DUY NHẤT world-knowledge (§12) được phép vào Planning Layer.
 * Đúng MỘT abstraction generic + data (§12 Rule #1-3), 5 method là 5 QUYẾT ĐỊNH
 * khác nhau, không phải 5 trách nhiệm khác nhau:
 *
 *   - aestheticFor()      mù chủ đề — chỉ nhận ScenePurpose, không thấy World Graph
 *   - prohibitionsFor()   đọc World Graph + EditorialPolicy (data) — §12
 *   - candidatesFor()     Rule Engine sinh KHÔNG GIAN lựa chọn hành động (§18.4)
 *   - microPhysicsFor()   hệ quả vật lý CỦA hành động đã Director chọn
 *   - environmentFor()    chuẩn hoá fact môi trường (Landscape entity) sang enum
 *                         đóng — CẤP VIDEO, không phải cấp scene (Sprint 2, 2026-07-22)
 *
 * Cả 4 đều: generic, deterministic, không AI, không state, read-only over
 * VerifiedWorldGraph. Xem ARCHITECTURE.md §12, §18.4, §18.7.
 */
final class EditorialInterpreter
{
    /**
     * @param list<EditorialPolicy> $policies §12 Rule #1: du lieu, tiem qua
     *        constructor — Interpreter khong hardcode Feadship/Ferrari/Moonrise.
     */
    public function __construct(
        private readonly array $policies = [],
    ) {
    }

    public function aestheticFor(ScenePurpose $purpose): SceneAesthetic
    {
        return match ($purpose) {
            ScenePurpose::Establish => new SceneAesthetic(
                Emotion::Calm, Composition::Centered, LightIntensity::Soft, LightGrade::Neutral,
            ),
            ScenePurpose::Reveal => new SceneAesthetic(
                Emotion::Calm, Composition::RuleOfThirds, LightIntensity::Soft, LightGrade::Warm,
            ),
            ScenePurpose::Detail => new SceneAesthetic(
                Emotion::Calm, Composition::Centered, LightIntensity::Soft, LightGrade::Neutral,
            ),
            ScenePurpose::Action => new SceneAesthetic(
                Emotion::Tense, Composition::RuleOfThirds, LightIntensity::Harsh, LightGrade::Cool,
            ),
            ScenePurpose::Process => new SceneAesthetic(
                Emotion::Dramatic, Composition::LeadingLines, LightIntensity::Neutral, LightGrade::Neutral,
            ),
            ScenePurpose::Comparison => new SceneAesthetic(
                Emotion::Calm, Composition::Symmetrical, LightIntensity::Neutral, LightGrade::Neutral,
            ),
            ScenePurpose::Resolution => new SceneAesthetic(
                Emotion::Majestic, Composition::Centered, LightIntensity::Soft, LightGrade::Golden,
            ),
        };
    }

    /**
     * §12 Rule #3: read-only over VerifiedWorldGraph — chi sinh prohibitions,
     * KHONG BAO GIO sua entity.type/attributes/builder. §12 Rule #2: generic —
     * ham nay khong biet Feadship/Ferrari/Moonrise ton tai, chi khop $policies.
     *
     * @return list<array{entity_id: string, attribute: string, value: mixed, reason: string}>
     */
    public function prohibitionsFor(VerifiedWorldGraph $world): array
    {
        $prohibitions = [];

        foreach ($world->entities() as $entity) {
            foreach ($this->policies as $policy) {
                if (! $this->matches($entity, $policy->match)) {
                    continue;
                }

                $prohibitions[] = [
                    'entity_id' => $entity->id,
                    'attribute' => $policy->prohibitAttribute,
                    'value'     => $policy->prohibitValue,
                    'reason'    => $policy->reason,
                ];
            }
        }

        return $prohibitions;
    }

    /**
     * @param array<string, mixed> $match
     */
    private function matches(Entity $entity, array $match): bool
    {
        foreach ($match as $attribute => $expected) {
            if ($entity->value($attribute) !== $expected) {
                return false;
            }
        }

        return true;
    }

    // ---- Phase 3: candidatesFor()/microPhysicsFor() — cùng pattern generic
    // code + world-knowledge như aestheticFor()/prohibitionsFor(), không tách
    // class riêng (§12: "đúng một abstraction"). Xem ARCHITECTURE.md §18.4. ----

    // Dùng chung cho CẢ Relation.type (2 entity) lẫn Event.type (1 entity) — cùng
    // cơ chế khớp từ khoá, khác nhau ở target rỗng/không rỗng. Tên đổi từ
    // RELATION_KEYWORDS -> ACTION_KEYWORDS 2026-07-22 khi thêm Event làm nguồn thứ 2.
    private const ACTION_KEYWORDS = [
        'lift'    => ActionType::Lift,
        'lower'   => ActionType::Lower,
        'align'   => ActionType::Align,
        'install' => ActionType::Align,
        'fit'     => ActionType::Align,
        'guide'   => ActionType::Guide,
        'steady'  => ActionType::Guide,
        'brace'   => ActionType::Guide,
        'secure'  => ActionType::Secure,
        'fasten'  => ActionType::Secure,
        'bolt'    => ActionType::Secure,
        'inspect' => ActionType::Inspect,
        'check'   => ActionType::Inspect,
        'signal'  => ActionType::Signal,
        'release' => ActionType::Release,
        // Position — thêm 2026-07-22, bằng chứng thật: relation "docked_at"
        // (bài viết yacht/sự kiện, không phải công nghiệp) không khớp 8 verb
        // trên. Generic đa domain: thuyền đậu bến, xe đậu bãi, máy bay hạ cánh.
        'dock'    => ActionType::Position,
        'moor'    => ActionType::Position,
        'anchor'  => ActionType::Position,
        'park'    => ActionType::Position,
        'arrive'  => ActionType::Position,
        'land'    => ActionType::Position,
        // Perform — thêm 2026-07-22, bằng chứng thật: world.events
        // "surprise_performance"/"performed_song" (entity=nas), KHÔNG entity thứ
        // 2 — action tự thân. Generic đa domain: nhạc sĩ biểu diễn, VĐV thi đấu,
        // diễn giả phát biểu — không riêng "ca sĩ hát".
        'perform' => ActionType::Perform,
        // Triumph/Confront — thêm 2026-07-22, bằng chứng thật qua video:benchmark
        // (10 bài Claude thật): "race_victory"×5, "award_won"×2, "protest_clash",
        // "break_in" không khớp keyword nào trước đó — xem ActionType.php.
        'victory'  => ActionType::Triumph,
        'award'    => ActionType::Triumph,
        'won'      => ActionType::Triumph,
        'clash'    => ActionType::Confront,
        'break_in' => ActionType::Confront,
    ];

    /**
     * Rule Engine sinh KHÔNG GIAN lựa chọn hợp lệ — KHÔNG quyết cái nào đáng kể
     * (đó là Subjective, việc của Director). §12 Rule #2: generic, không biết
     * "yacht"/"stern block" — chỉ khớp EntityType/Relation.type/Attribute.
     *
     * @return array{hero_candidates: list<string>, action_candidates: list<ActionCandidate>}
     */
    public function candidatesFor(SemanticScene $scene, VerifiedWorldGraph $world): array
    {
        $heroCandidates = [];
        foreach ($scene->subjectIds as $id) {
            $entity = $world->entity($id);
            if ($entity !== null && ! $entity->isAnchorOnly()) {
                $heroCandidates[] = $id;
            }
        }

        $actionCandidates = [];
        foreach ($world->relations as $relation) {
            $touchesScene = in_array($relation->from, $scene->subjectIds, true)
                || in_array($relation->to, $scene->subjectIds, true);
            if (! $touchesScene) {
                continue;
            }

            $actionType = $this->actionTypeFor($relation->type);
            if ($actionType === null) {
                continue; // không khớp verb generic nào — KHÔNG ép, bỏ qua (Rule 0)
            }

            $actionCandidates[] = new ActionCandidate(
                $actionType,
                $relation->from,
                $relation->to,
                $this->modifiersFor($relation->to, $world),
            );
        }

        // Event = hành động 1 entity (khác Relation luôn có 2) — vd "nas performs"
        // không có đối tượng tác động. Bổ sung 2026-07-22, bằng chứng thật: bỏ sót
        // hoàn toàn trước đó khiến scene chỉ có Event (không Relation nào chạm
        // tới) rơi vào "không có candidate" dù bài báo THẬT SỰ có nói tới hành động.
        foreach ($world->events as $event) {
            if (! in_array($event->entityId, $scene->subjectIds, true)) {
                continue;
            }

            $actionType = $this->actionTypeFor($event->type);
            if ($actionType === null) {
                continue;
            }

            $actionCandidates[] = new ActionCandidate(
                $actionType,
                $event->entityId,
                '', // Event không có entity thứ 2 — target rỗng, KHÔNG bịa
                $this->modifiersFor($event->entityId, $world),
            );
        }

        return ['hero_candidates' => $heroCandidates, 'action_candidates' => $actionCandidates];
    }

    /**
     * Hệ quả VẬT LÝ của hành động ĐÃ CHỌN — Objective (weight > ngưỡng → cable
     * tension là fact vật lý, không phải "gu" kể chuyện), nên tính SAU khi
     * Director chọn xong, không nằm trong candidates cho Director chọn.
     *
     * @return list<string>
     */
    public function microPhysicsFor(ActionCandidate $chosen): array
    {
        if (in_array('heavy_object', $chosen->modifiers, true)) {
            // Bằng chứng: install_hull_block_v1.json (đã validate render) —
            // "the crane cable eases as tension transfers to the keel blocks".
            return ['the lifting cable holds under visible tension'];
        }

        return [];
    }

    // ---- environmentFor(): Sprint 2 (2026-07-22) — chuẩn hoá fact môi trường
    // sang vocabulary đóng của schema. CẤP VIDEO, không phải cấp scene: Landscape
    // entity không có relation nối tới subject nào (chưa có bằng chứng thật cần
    // `located_in`, Rule 0) nên KHÔNG BAO GIỜ lọt vào scene.subjectIds — scope
    // theo scene sẽ luôn rỗng. Xem RenderPlanAssembler::assemble() (world_environment). ----

    /** Bump khi đổi *_KEYWORDS (thêm/bớt mapping) — benchmark ghi cột riêng để
     * so sánh 2 lần chạy có cùng bảng ánh xạ enum không. */
    public const ENVIRONMENT_MAPPING_VERSION = 'environment-v1';

    private const WEATHER_KEYWORDS = [
        'storm'    => 'STORM',
        'rain'     => 'RAIN',
        'drizzle'  => 'RAIN',
        'snow'     => 'SNOW',
        'fog'      => 'FOG',
        'mist'     => 'FOG',
        'overcast' => 'CLOUDY',
        'cloud'    => 'CLOUDY',
        'indoor'   => 'INDOOR',
        'clear'    => 'CLEAR',
        'sunny'    => 'CLEAR',
    ];

    private const TIME_OF_DAY_KEYWORDS = [
        'dawn'        => 'DAWN',
        'sunrise'     => 'DAWN',
        'morning'     => 'MORNING',
        'midday'      => 'MIDDAY',
        'noon'        => 'MIDDAY',
        'golden hour' => 'GOLDEN_HOUR',
        'sunset'      => 'GOLDEN_HOUR',
        'dusk'        => 'DUSK',
        'twilight'    => 'DUSK',
        'night'       => 'NIGHT',
    ];

    private const MEDIUM_KEYWORDS = [
        'water'   => 'WATER',
        'sea'     => 'WATER',
        'ocean'   => 'WATER',
        'harbor'  => 'WATER',
        'harbour' => 'WATER',
        // river — thêm 2026-07-22, bằng chứng thật: video:benchmark bài Tequila
        // yacht, landscape "Hudson River" có claim medium="river" (qua Gatekeeper)
        // nhưng bị bỏ sót, environment_reason=NO_MATCHING_ATTRIBUTES sai lý do.
        'river'   => 'WATER',
        'air'     => 'AIR',
        'sky'     => 'AIR',
        'ground'  => 'GROUND',
        'land'    => 'GROUND',
        'space'   => 'SPACE',
    ];

    private const LIGHT_SOURCE_KEYWORDS = [
        'natural'    => 'NATURAL',
        'sunlight'   => 'NATURAL',
        'daylight'   => 'NATURAL',
        'artificial' => 'ARTIFICIAL',
        'floodlight' => 'ARTIFICIAL',
        'studio'     => 'ARTIFICIAL',
        'mixed'      => 'MIXED',
    ];

    /**
     * Chỉ áp dụng khi ĐÚNG MỘT Landscape entity tồn tại — 0 thì không có gì để
     * nói; ≥2 thì không biết cái nào khớp scene nào, KHÔNG đoán (Rule 0). Trả về
     * vocabulary đóng của `$defs/environment`, chỉ set key nào khớp được keyword
     * — key nào Truth có nhưng không khớp từ khoá nào thì bỏ qua, không ép.
     *
     * @return array<string, string>
     */
    public function environmentFor(VerifiedWorldGraph $world): array
    {
        $landscapes = $this->landscapeEntitiesIn($world);

        if (count($landscapes) !== 1) {
            return [];
        }

        $landscape   = $landscapes[0];
        $environment = [];

        foreach ([
            'weather'      => self::WEATHER_KEYWORDS,
            'time_of_day'  => self::TIME_OF_DAY_KEYWORDS,
            'medium'       => self::MEDIUM_KEYWORDS,
            'light_source' => self::LIGHT_SOURCE_KEYWORDS,
        ] as $attribute => $keywords) {
            if (! $landscape->has($attribute)) {
                continue;
            }

            $mapped = $this->keywordMatch((string) $landscape->value($attribute), $keywords);
            if ($mapped !== null) {
                $environment[$attribute] = $mapped;
            }
        }

        // location là string tự do trong schema (không enum) — giữ nguyên văn.
        if ($landscape->has('location')) {
            $environment['location'] = (string) $landscape->value('location');
        }

        return $environment;
    }

    /**
     * Chẩn đoán CHỈ dùng cho benchmark/quan sát (`video:benchmark`) — KHÔNG
     * dùng trong RenderPlanAssembler. `environmentFor()` giữ nguyên contract
     * (mảng rỗng cho cả 3 tình huống); method này tách lý do ra để đo lường,
     * không phá contract sản xuất hiện có.
     *
     * @return 'NO_LANDSCAPE_ENTITY'|'MULTIPLE_LANDSCAPES'|'NO_MATCHING_ATTRIBUTES'|'SUCCESS'
     */
    public function environmentDiagnosisFor(VerifiedWorldGraph $world): string
    {
        $landscapes = $this->landscapeEntitiesIn($world);

        if (count($landscapes) === 0) {
            return 'NO_LANDSCAPE_ENTITY';
        }

        if (count($landscapes) >= 2) {
            return 'MULTIPLE_LANDSCAPES';
        }

        return $this->environmentFor($world) !== [] ? 'SUCCESS' : 'NO_MATCHING_ATTRIBUTES';
    }

    /**
     * @return list<Entity>
     */
    private function landscapeEntitiesIn(VerifiedWorldGraph $world): array
    {
        return array_values(array_filter(
            $world->entities(),
            fn (Entity $e) => $e->type === EntityType::Landscape,
        ));
    }

    /**
     * @param array<string, string> $keywords
     */
    private function keywordMatch(string $text, array $keywords): ?string
    {
        $lower = strtolower($text);

        foreach ($keywords as $keyword => $enumValue) {
            if (str_contains($lower, $keyword)) {
                return $enumValue;
            }
        }

        return null;
    }

    /** Dùng cho cả Relation.type lẫn Event.type — cùng cơ chế khớp từ khoá. */
    private function actionTypeFor(string $typeString): ?ActionType
    {
        $type = strtolower($typeString);
        foreach (self::ACTION_KEYWORDS as $keyword => $actionType) {
            if (str_contains($type, $keyword)) {
                return $actionType;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function modifiersFor(string $entityId, VerifiedWorldGraph $world): array
    {
        $entity = $world->entity($entityId);
        if ($entity === null) {
            return [];
        }

        foreach (array_keys($entity->attributes) as $name) {
            if (str_contains(strtolower($name), 'weight') && (float) $entity->value($name) > 1000) {
                return ['heavy_object'];
            }
        }

        return [];
    }
}
