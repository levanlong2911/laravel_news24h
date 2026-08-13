<?php

namespace App\Video\Editorial;

use App\Video\Scene\ScenePurpose;
use App\Video\Scene\SemanticScene;
use App\Video\World\Entity;
use App\Video\World\EntityType;
use App\Video\World\VerifiedAttribute;
use App\Video\World\VerifiedWorldGraph;

/**
 * Editorial — nơi DUY NHẤT world-knowledge (§12) được phép vào Planning Layer.
 * Đúng MỘT abstraction generic + data (§12 Rule #1-3), 7 method là 7 QUYẾT ĐỊNH
 * khác nhau, không phải 7 trách nhiệm khác nhau:
 *
 *   - aestheticFor()      mù chủ đề — chỉ nhận ScenePurpose, không thấy World Graph
 *   - prohibitionsFor()   đọc World Graph + EditorialPolicy (data) — §12
 *   - candidatesFor()     Rule Engine sinh KHÔNG GIAN lựa chọn hành động (§18.4)
 *   - microPhysicsFor()   hệ quả vật lý CỦA hành động đã Director chọn
 *   - environmentFor()    chuẩn hoá fact môi trường (Landscape entity) sang enum
 *                         đóng — CẤP VIDEO, không phải cấp scene (Sprint 2, 2026-07-22)
 *   - cameraTargetFor()   sửa camera.target khi IntentPlanner chọn máy móc
 *                         trúng entity không quay được (2026-07-23, §12 vẫn
 *                         đúng vai: chỉ EntityType xác định, không "gu")
 *   - durationWeightFor() trọng số pacing theo ScenePurpose — mù World Graph
 *                         giống aestheticFor() (2026-07-23, TimelinePlanner
 *                         Phase 5 đã hẹn trước)
 *
 * Cả 7 đều: generic, deterministic, không AI, không state, read-only over
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
     * Trọng số thời lượng — CÙNG PATTERN với aestheticFor() (thuần theo
     * ScenePurpose, mù World Graph, deterministic). Trước đây `TimelinePlanner`
     * chia đều tuyệt đối, tự nhận "pacing là taste, thuộc Editorial (Phase 5)"
     * — đây chính là Phase 5 đó, thêm 2026-07-23. `TimelinePlanner` dùng trọng
     * số này để tính khoảng thời gian [start,end], KHÔNG tự quyết pacing.
     * Quy ước, không thiêng — 1.0 = trung bình; Action/Resolution cần đọc lâu
     * hơn Detail/Comparison (cận cảnh nhanh, so sánh gọn).
     */
    public function durationWeightFor(ScenePurpose $purpose): float
    {
        return match ($purpose) {
            ScenePurpose::Establish  => 1.0,
            ScenePurpose::Reveal     => 1.1,
            ScenePurpose::Detail     => 0.7,
            ScenePurpose::Action     => 1.4,
            ScenePurpose::Process    => 1.0,
            ScenePurpose::Comparison => 0.8,
            ScenePurpose::Resolution => 1.3,
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
    // Trần cho feature candidate — chặn prompt phình khi Extractor trả một
    // entity có rất nhiều thuộc tính, hoặc một thuộc tính có rất nhiều giá trị.
    private const MAX_FEATURE_CANDIDATES = 20;

    private const MAX_FEATURE_VALUES_PER_CANDIDATE = 20;

    private const MAX_FEATURE_VALUE_LENGTH = 300;

    private const MAX_FEATURE_ATTRIBUTE_LENGTH = 64;

    private const FEATURE_ATTRIBUTE_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

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
        // ALIAS DATA (2026-07-29), không phải mở rộng thuật toán: `performance`
        // là danh từ hoá bất quy tắc (-ance), tokenVariants() chỉ lo hình thái
        // ĐỀU ĐẶN (-s/-d/-ed/-ing). Event thật `surprise_performance` cần nó.
        // Nguyên nhân gốc nằm ở thượng nguồn: Extractor sinh cả
        // `performed_song` lẫn `surprise_performance` cho CÙNG một semantic —
        // sửa đúng là chuẩn hoá ở Extractor, đây chỉ là lớp tương thích.
        'performance' => ActionType::Perform,
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
     * @return array{hero_candidates: list<string>, action_candidates: list<ActionCandidate>, feature_candidates: list<FeatureCandidate>}
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

        return [
            'hero_candidates' => $heroCandidates,
            'action_candidates' => $actionCandidates,
            // CHỈ scene Detail — đó là đường đứt đã đo được (StoryPlanner:241
            // xếp hạng theo số thuộc tính, ScenePlanner:94 sinh hẳn scene
            // Detail cho entity đủ giàu, rồi Editorial không sinh gì cho nó).
            // Cấp cho mọi scene sẽ đưa lại cùng bộ thuộc tính nhiều lần và tái
            // tạo đúng cảnh lặp đang muốn sửa.
            'feature_candidates' => $scene->purpose === ScenePurpose::Detail
                ? $this->featureCandidatesFor($scene, $world)
                : [],
        ];
    }

    /**
     * Thuộc tính đã verify của CHÍNH subject trong scene — không lấy của entity
     * khác, để scene về con tàu không thấy thuộc tính của khách sạn.
     *
     * @return list<FeatureCandidate>
     */
    private function featureCandidatesFor(SemanticScene $scene, VerifiedWorldGraph $world): array
    {
        // Sắp xếp để cùng một world luôn cho cùng thứ tự candidate — index mà
        // Director trả về mới ổn định giữa hai lần chạy.
        $subjectIds = array_values(array_unique($scene->subjectIds));
        sort($subjectIds);

        $candidates = [];

        foreach ($subjectIds as $id) {
            $entity = $world->entity($id);
            if ($entity === null) {
                continue;
            }

            $attributes = $entity->attributes;
            ksort($attributes);

            foreach ($attributes as $name => $verified) {
                if (! $this->isUsableAttributeName($name)) {
                    continue;
                }

                $values = $this->featureValues($verified);
                if ($values === []) {
                    continue;
                }

                $candidates[] = new FeatureCandidate($id, $name, $values);
            }
        }

        return array_slice($candidates, 0, self::MAX_FEATURE_CANDIDATES);
    }

    private function isUsableAttributeName(mixed $name): bool
    {
        return is_string($name)
            && mb_strlen($name) <= self::MAX_FEATURE_ATTRIBUTE_LENGTH
            && preg_match(self::FEATURE_ATTRIBUTE_PATTERN, $name) === 1;
    }

    /**
     * Chỉ scalar biểu diễn được. Array/object KHÔNG ép chuỗi — `(string)` trên
     * mảng ra "Array", tức bịa một giá trị không có bằng chứng nào.
     *
     * @param  list<VerifiedAttribute>  $verified
     * @return list<string|int|float|bool>
     */
    private function featureValues(array $verified): array
    {
        $values = [];

        foreach ($verified as $attribute) {
            $value = $attribute->value;

            if (! is_string($value) && ! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                continue;
            }

            if (is_float($value) && ! is_finite($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '' || mb_strlen($value) > self::MAX_FEATURE_VALUE_LENGTH) {
                    continue;
                }
            }

            if (! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return array_slice($values, 0, self::MAX_FEATURE_VALUES_PER_CANDIDATE);
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

    /** EntityType không có hình dạng vật lý — camera không thể "quay" vào đây. */
    private const NON_VISUAL_TYPES = [EntityType::Event, EntityType::Effect];

    /**
     * `IntentPlanner` chọn `camera.target = scene.subjectIds[0]` HOÀN TOÀN máy
     * móc theo vị trí mảng — nó bị type system chặn không cho biết EntityType
     * (§1: "camera KHÔNG THỂ phụ thuộc chủ đề"). Method này SỬA lại khi lựa
     * chọn đó trúng 1 entity không có hình dạng vật lý (`event`/`effect`) VÀ
     * scene có subject khác thay thế được — Objective 100% (chỉ đọc
     * EntityType, không cần "gu"), đúng vai Editorial (§12).
     *
     * Bug thật (2026-07-23): scene subjects=['world_cup_final_match',
     * 'metlife_stadium'], IntentPlanner chọn subjectIds[0]='world_cup_final_match'
     * (type=event) làm camera.target — Kling nhận lệnh "zoom vào 1 sự kiện",
     * vô nghĩa. Không có subject thay thế hợp lệ thì GIỮ NGUYÊN — không đoán
     * bừa (Rule 0).
     */
    public function cameraTargetFor(SemanticScene $scene, string $originalTarget, VerifiedWorldGraph $world): string
    {
        $entity = $world->entity($originalTarget);

        if ($entity === null || ! in_array($entity->type, self::NON_VISUAL_TYPES, true)) {
            return $originalTarget;
        }

        foreach ($scene->subjectIds as $id) {
            if ($id === $originalTarget) {
                continue;
            }

            $candidate = $world->entity($id);
            if ($candidate !== null && ! in_array($candidate->type, self::NON_VISUAL_TYPES, true)) {
                return $id;
            }
        }

        return $originalTarget;
    }

    // ---- Khớp từ khoá: 3 tầng, KHÔNG dùng str_contains() (2026-07-29) ----
    //
    // BUG THẬT đã xảy ra trong production: `str_contains()` khớp MỌI vị trí
    // trong chuỗi, nên event thật `transfer_to_outfitting` của bài Nixie khớp
    // keyword `'fit'` -> ActionType::Align -> prompt "Nixie aligns  into
    // position". Bài báo nói con tàu ĐƯỢC CHUYỂN VÀO xưởng hoàn thiện, không
    // nói cái gì được căn thẳng. Cùng loại lỗi với `'face'` khớp trong
    // `'surface'` bên Python (đã sửa cùng ngày).
    //
    // Ba false positive khác cùng cơ chế: `refit_completed`->align,
    // `island_survey`->position ('land' trong 'island'), `wondered_about`->
    // triumph ('won' trong 'wondered').
    //
    // Vì sao KHÔNG dùng exact-token thuần: đã đo trên dữ liệu thật, nó làm MẤT
    // 6 mapping đang đúng — `anchored`/`moored` (đang có trong DB),
    // `docked_at`, `performed_song`, `surprise_performance`, `break_in`. World
    // Graph dùng động từ ĐÃ CHIA (mô tả việc đã xảy ra), không dùng nguyên mẫu.
    //
    // Ba tầng, thứ tự cố định:
    //   1. Khớp CẢ CHUỖI  — cho keyword nhiều token (`break_in`)
    //   2. Khớp TOKEN     — tách theo `_`, so nguyên văn (`won` trong `award_won`)
    //   3. Khớp DẠNG BIẾN THỂ của token — hình thái ĐỀU ĐẶN, xem MORPHOLOGY_SUFFIXES

    /**
     * Hậu tố hình thái ĐỀU ĐẶN được cắt để tìm dạng gốc.
     *
     * Đây LÀ một danh sách hữu hạn, không phải phép chuẩn hoá tổng quát — nói
     * thẳng để người sau không tưởng nó tự xử lý mọi biến thể. Nguyên tắc phân
     * chia: hình thái đều đặn thì thuật toán lo; BẤT QUY TẮC thì thêm vào bảng
     * keyword dưới dạng alias data (vd 'performance' => Perform), KHÔNG nhồi
     * thêm hậu tố vào đây.
     *
     * `'d'` đứng riêng ngoài `'ed'` là cần thiết: `secured` cắt `ed` ra `secur`
     * (không khớp keyword `secure`), cắt `d` mới ra `secure`.
     */
    private const MORPHOLOGY_SUFFIXES = ['s', 'd', 'ed', 'ing'];

    /**
     * Các dạng ứng viên của MỘT token, để tra thẳng vào bảng keyword.
     *
     * Sinh ứng viên thay vì "chuẩn hoá về một dạng gốc" — vì dạng gốc không
     * xác định được duy nhất (`secured` -> `secur` hay `secure`?). Sinh cả hai
     * rồi tra bảng thì không cần chọn.
     *
     * Có xử lý phụ âm đôi: `fitted` -> cắt `ed` -> `fitt` -> `fit`. Nhờ vậy
     * `outfitting` -> `outfitt` -> `outfit` — vẫn KHÁC `fit`, không khớp sai.
     *
     * @return list<string>
     */
    private function tokenVariants(string $token): array
    {
        $variants = [$token];

        foreach (self::MORPHOLOGY_SUFFIXES as $suffix) {
            if (! str_ends_with($token, $suffix) || strlen($token) <= strlen($suffix) + 1) {
                continue;
            }

            $stem = substr($token, 0, -strlen($suffix));
            $variants[] = $stem;

            // Phụ âm đôi: 'fitt' -> 'fit', 'stopp' -> 'stop'.
            $len = strlen($stem);
            if ($len >= 3 && $stem[$len - 1] === $stem[$len - 2]) {
                $variants[] = substr($stem, 0, -1);
            }
        }

        return $variants;
    }

    /**
     * Tra bảng keyword theo 3 tầng. Trả về giá trị đầu tiên khớp.
     *
     * CHÚ Ý — thứ tự bảng là LOAD-BEARING khi nhiều keyword cùng khớp (vd
     * `weather: "clear storm"` khớp cả `storm` lẫn `clear`): keyword khai
     * TRƯỚC thắng. Có test khoá hành vi này, đừng sắp lại bảng theo alphabet.
     *
     * @param  array<string, mixed>  $keywords
     */
    private function lookupKeyword(string $text, array $keywords): mixed
    {
        $normalized = strtolower(trim($text));

        // TẦNG 1 — cả chuỗi, cho keyword nhiều token ('break_in').
        if (isset($keywords[$normalized])) {
            return $keywords[$normalized];
        }

        // Tách theo cả `_` (relation/event type) lẫn khoảng trắng (giá trị
        // attribute tự do như "Hudson River", "golden hour").
        $tokens = preg_split('/[\s_]+/', $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        // TẦNG 2 + 3 — quét theo THỨ TỰ BẢNG (không theo thứ tự token) để
        // "keyword khai trước thắng" đúng như tài liệu.
        foreach ($keywords as $keyword => $value) {
            foreach ($tokens as $token) {
                // Tầng 2: token nguyên văn. Keyword nhiều token không bao giờ
                // khớp ở đây (token không chứa `_`), nên đã xử lý ở tầng 1.
                if ($token === $keyword) {
                    return $value;
                }

                // Tầng 3: dạng biến thể hình thái đều đặn.
                if (in_array($keyword, $this->tokenVariants($token), true)) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $keywords
     */
    private function keywordMatch(string $text, array $keywords): ?string
    {
        $matched = $this->lookupKeyword($text, $keywords);

        return is_string($matched) ? $matched : null;
    }

    /** Dùng cho cả Relation.type lẫn Event.type — cùng cơ chế khớp từ khoá. */
    private function actionTypeFor(string $typeString): ?ActionType
    {
        $matched = $this->lookupKeyword($typeString, self::ACTION_KEYWORDS);

        return $matched instanceof ActionType ? $matched : null;
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
