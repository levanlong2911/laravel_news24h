<?php

namespace App\Video\Story;

/**
 * Sinh các scene BỊA CÓ CHỦ ĐÍCH (thiết kế → thi công → hoàn thiện → thành
 * phẩm), chèn TRƯỚC scene thật, cho category có mặt trong
 * config('video.creation_arc.categories'). Xem docs/video/ARCHITECTURE.md
 * §18.16-18.17.
 *
 * SỐ SCENE do phase_set quyết định, KHÔNG cố định — đừng đếm số ở đây (docblock
 * cũ ghi "3 scene" đã sai sau khi lên v2, và cả tên category cũng sai: cars/moto
 * CỐ TÌNH chưa có phase_set vì chưa có tư liệu thật).
 *
 * KHÔNG đi qua StoryPlanner/ScenePlanner/Director/EditorialInterpreter —
 * những class đó evidence-gated (đọc VerifiedWorldGraph thật), sẽ luôn rỗng
 * với nội dung không có bằng chứng. Class này độc lập hoàn toàn, sinh thẳng
 * ra mảng đúng shape RenderPlan v1.0 (scene/act/timeline_slot) — KHÔNG đi qua
 * RenderPlanAssembler::sceneDoc() vì hàm đó bắt buộc tính aesthetic qua
 * EditorialInterpreter::aestheticFor(purpose), không nhận override từ config.
 *
 * Nhận `$heroId`/`$heroName` dạng chuỗi thuần (không phải Entity object) — vì
 * điểm gọi thật (VideoRenderPlanService::build()) chỉ có world.entities
 * ĐÃ SERIALIZE (mảng JSON từ VideoPlanningPipeline::plan(), không phải domain
 * object) — không cần dựng lại Entity chỉ để lấy 2 giá trị. Vẫn neo 1 phần
 * THẬT: `$heroId` phải trỏ tới entity ĐÃ qua Gatekeeper xác minh (không tự
 * bịa id) — chỉ 3 GIAI ĐOẠN QUÁ TRÌNH quanh nó là bịa, không phải toàn bộ.
 *
 * Tính NHẤT QUÁN thị giác xuyên 4 pha (Design/Construction/Craftsmanship/
 * Experience) KHÔNG xử lý ở đây — cố tình. world.entities[hero].attributes
 * thật (hull_color, v.v.) được compiler Python đọc và nhắc lại CHO MỌI SCENE
 * (không chỉ 3 scene bịa) tại motion.py::entity_identity_facts(), vì đó là
 * class duy nhất thấy TẤT CẢ scene (thật + bịa) cùng lúc — làm ở đây sẽ chỉ
 * phủ 3 scene bịa, để lọt đúng ranh giới Craftsmanship→Experience quan trọng
 * nhất. Xem docs/video/ARCHITECTURE.md §18.15 (Identity Consistency).
 */
final class CreationArcPlanner
{
    /**
     * Cả hai tham số đến từ `config/video.php` và được TIÊM QUA CONSTRUCTOR —
     * class KHÔNG tự gọi `config()`, đúng pattern EditorialInterpreter/
     * EditorialPolicy: nó không cần biết mình đang chạy trong Laravel hay trong
     * test PHPUnit thuần (Rule 2).
     *
     * `$phases` — `creation_arc.phase_sets.<set>.phases`. Mỗi pha:
     *   - `purpose`, `camera`, `aesthetic`, `composition_note`  BẮT BUỘC
     *   - `objective`      OPTIONAL — SCENE INTENT của pha, xem §18.18. Vắng thì
     *                      scene không có key `objective` và
     *                      RenderPlanQualityReport sẽ báo.
     *   - `micro_physics`  OPTIONAL — hành vi ĐO ĐƯỢC phụ (khói bốc lên, bụi
     *                      bay, bề mặt bóng dần…), nguyên tắc Observable/
     *                      Measurable Behavior §18.12.
     *   - `camera_target`/`hero`  OPTIONAL — ghi đè khi khung không nhìn vào
     *                      chính chủ thể; xem comment trong `plan()`.
     *
     * `$identity` — `creation_arc.phase_sets.<set>.identity`. Nhận dạng thị giác
     * cấp VIDEO, 2 trạng thái vòng đời (`construction`/`final`). Đi THẲNG vào
     * `RenderPlan.creative_identity`, KHÔNG bị nhào nặn ở đây: planner chỉ
     * chuyển tiếp, compiler Python mới quyết định scene nào dùng trạng thái nào
     * và có nhắc lại hay không (§18.17).
     *
     * @param  array<string, array<string, mixed>>  $phases
     * @param  array<string, array{visual_identity: string}>  $identity
     */
    public function __construct(
        private readonly array $phases = [],
        private readonly array $identity = [],
    ) {}

    /**
     * @param  string  $heroId  ID entity THẬT trong world.entities (đã qua Gatekeeper) — dùng làm subject/entity_ref.
     * @param  string|null  $heroName  Tên hiển thị (identity.name) nếu có; null thì fallback id đã prettify.
     * @return array{acts: list<array>, scenes: list<array>, timeline: list<array>}
     */
    public function plan(string $heroId, ?string $heroName = null, float $secondsPerScene = 5.0): array
    {
        $heroName ??= str_replace('_', ' ', $heroId);

        $acts = [];
        $scenes = [];
        $timeline = [];
        $ordinal = 1;
        $start = 0.0;

        foreach ($this->phases as $key => $tpl) {
            $sceneId = "creation_{$key}";
            $actId = "creation_{$key}_act";

            $acts[] = ['id' => $actId, 'ordinal' => $ordinal, 'source' => 'ENTITY', 'entity_ref' => $heroId];

            $scenes[] = [
                'id' => $sceneId,
                'ordinal' => $ordinal,
                'act_id' => $actId,
                'purpose' => $tpl['purpose'],
                'subjects' => [$heroId],
                'motion_intent' => 'LOW',
                // camera_target ghi đè (2026-07-26): KHÔNG phải scene nào cũng
                // đang nhìn vào chính con tàu. Shot bản vẽ trên bàn, shot động
                // cơ treo cần trục, shot đứng TRÊN boong — chủ thể trong khung
                // là tờ giấy / cỗ máy / không gian boong, không phải con tàu.
                // Để nguyên target=hero thì thư viện câu camera (xoay quanh
                // {target}) sinh ra câu tự mâu thuẫn: "camera lướt tới trên sàn
                // teak" + "tracks laterally alongside Nixie, keeping Nixie
                // centered". Slug ghi đè KHÔNG cần có trong world.entities —
                // entity_display_name() tự fallback prettify, và
                // entity_identity_facts() trả rỗng, nên câu nhận dạng con tàu
                // cũng tự động không lọt vào những shot không nhìn thấy nó.
                'camera' => $tpl['camera'] + ['target' => $tpl['camera_target'] ?? $heroId],
                'aesthetic' => $tpl['aesthetic'],
                'asset_refs' => ['as_'.$heroId],
                // world = NGỮ CẢNH THẾ GIỚI của pha, dạng khoá ngữ nghĩa thuần
                // (vd environment=open_shipyard). KHÔNG có chữ tiếng Anh nào:
                // Laravel sở hữu SỰ THẬT ("cảnh này diễn ra ở loại nơi nào"),
                // Python sở hữu CÁCH NÓI ("at an open waterside shipyard beneath
                // the open sky, tall yellow mobile cranes...").
                //
                // Trước đây nơi chốn nằm lẫn trong `composition_note` — cùng một
                // sự thật bị viết lại ở 3 pha + `anchor_setting` bên Python, nên
                // sửa nghiệp vụ (du thuyền đóng trong nhà hay ngoài trời) phải
                // sờ vào 4 chỗ và rất dễ lệch nhau. Giờ đổi ĐÚNG MỘT khoá.
                //
                // Pha nào không diễn ra ở một cơ sở sản xuất (bàn vẽ, trên boong,
                // ngoài biển) thì KHÔNG khai `world` — không bịa nơi chốn. Key
                // được thêm SAU, ngay dưới đây, để scene không mang `world: null`
                // (schema đòi object khi có mặt).
                'director_notes' => [
                    // hero ghi đè (2026-07-26): cùng lý do với camera_target —
                    // hero sinh ra câu "All attention is drawn to {hero}" đứng
                    // ĐẦU prompt. Với shot bản vẽ / cỗ máy / boong tàu thì chỉ
                    // vào con tàu là chỉ sai chỗ, và nguy hiểm nhất ở shot đứng
                    // trên boong: có thể khiến model kéo camera ra xa lấy trọn
                    // con tàu, hỏng đúng ý đồ. Mặc định vẫn là hero thật.
                    'hero' => $tpl['hero'] ?? $heroId,
                    'composition_note' => str_replace('{hero_name}', $heroName, $tpl['composition_note']),
                    'micro_physics' => array_map(
                        fn (string $s) => str_replace('{hero_name}', $heroName, $s),
                        $tpl['micro_physics'] ?? [],
                    ),
                    // crowd = SỐ NGƯỜI TRONG KHUNG, quyết định của đạo diễn (đông
                    // để thấy đại công trường, hay một người để thấy quy mô cỗ
                    // máy). Nằm trong director_notes vì nó là BLOCKING, cùng nhóm
                    // với hero/camera/micro_physics — không phải tri thức ngành.
                    //
                    // Nó cũng là thứ DUY NHẤT chặn được model tự thêm người vào
                    // khung: prose tả "một quản đốc" không ngăn Veo rắc thêm vài
                    // công nhân cho "sinh động", và thế là mất luôn ý đồ cô đơn.
                    'crowd' => $tpl['crowd'] ?? [],
                ],
            ];

            if (isset($tpl['world'])) {
                $scenes[count($scenes) - 1]['world'] = $tpl['world'];
            }

            // objective = SCENE INTENT tự viết cho từng pha, KHÔNG phải
            // `producer.visual_promise` copy xuống (§18.18).
            //
            // Bản sửa đầu (2026-07-30) đúng là copy promise — và dữ liệu
            // production bác ngay: promise thật nói "gleaming 242-foot vessel
            // MOVING THROUGH THE WATERS", trong khi scene arc đang chiếu tờ bản
            // vẽ và khối thép trần. Gán promise cho chúng là mô tả vật chưa hoàn
            // thiện bằng từ vựng vật đã hoàn thiện — đúng nguyên nhân render v2
            // thất bại 4/6 (§18.17). Thiếu tín hiệu tốt hơn tín hiệu mâu thuẫn.
            //
            // Optional theo schema: pha không khai objective thì VẮNG HẲN key,
            // không emit chuỗi rỗng — RenderPlanQualityReport sẽ báo, đúng như
            // nó đã báo lần đầu.
            $objective = trim((string) ($tpl['objective'] ?? ''));
            if ($objective !== '') {
                $scenes[array_key_last($scenes)]['objective'] = $objective;
            }

            $end = $start + $secondsPerScene;
            $timeline[] = ['scene_id' => $sceneId, 'start_sec' => $start, 'end_sec' => $end];
            $start = $end;

            $ordinal++;
        }

        return ['acts' => $acts, 'scenes' => $scenes, 'timeline' => $timeline];
    }

    /**
     * THAY THẾ toàn bộ scene/act/timeline của RenderPlan bằng scene Creation Arc
     * (§18.22, quyết định người dùng 2026-07-30).
     *
     * ĐẢO quyết định 2026-07-24 ("chèn TRƯỚC scene thật, video DÀI THÊM"). Bằng
     * chứng từ lần compile thật đầu tiên chạy trọn: bài "The Sixth Sense" ra 14
     * shot / $2.52, trong đó 8 scene thật có confidence 0.1-0.3 và nội dung nói
     * về những con tàu KHÁC (bài báo là tin di chuyển tàu, không phải bài kể về
     * một con tàu). Ghép chúng sau 6 scene đóng tàu không tạo thành một câu
     * chuyện — nó tạo ra hai video dán vào nhau.
     *
     * Hệ quả tốt: bài toán khó nhất của §18.15 BIẾN MẤT. Không còn scene thật
     * đứng sau ⇒ không còn ranh giới `Craftsmanship → Experience` giữa nội dung
     * bịa và nội dung thật ⇒ không còn rủi ro "hai con tàu khác nhau" ở đúng chỗ
     * nguy hiểm nhất. Cơ chế identity (§18.20) vẫn cần, nhưng giờ chỉ còn lo
     * nhất quán GIỮA 6 scene arc với nhau.
     *
     * GIỮ NGUYÊN `world`, `assets`, `continuity`, `producer`, `facts`: chúng là
     * dữ liệu cấp VIDEO, không thuộc scene nào. `world` đặc biệt quan trọng —
     * `camera.target` và `entity_identity_facts()` phía compiler đọc từ đó.
     *
     * Hàm thuần (không đọc DB/config) để test được không cần Laravel — gating
     * theo category là việc của VideoRenderPlanService.
     *
     * @param  array<string, mixed>  $renderPlan
     * @return array<string, mixed>
     */
    public function mergeInto(array $renderPlan, string $heroId, ?string $heroName = null, float $secondsPerScene = 5.0): array
    {
        $arc = $this->plan($heroId, $heroName, $secondsPerScene);
        if ($arc['scenes'] === []) {
            return $renderPlan;
        }

        $renderPlan['acts'] = $arc['acts'];
        $renderPlan['scenes'] = $arc['scenes'];
        $renderPlan['timeline'] = $arc['timeline'];
        $renderPlan['story']['target_seconds'] = (int) round(end($arc['timeline'])['end_sec']);

        // Scene arc tham chiếu `as_<hero>`; asset đó do Assembler sinh từ scene
        // THẬT, mà scene thật vừa bị thay đi. Thiếu nó là dangling ref —
        // RenderPlanQualityReport sẽ báo, và tầng sau sẽ tra không thấy.
        $renderPlan['assets'] = $this->ensureHeroAsset($renderPlan['assets'] ?? [], $heroId);

        // creative_identity là fact cấp VIDEO (sibling của world_environment).
        // Optional: vắng hẳn key khi chưa khai báo, không emit object rỗng.
        if ($this->identity !== []) {
            $renderPlan['creative_identity'] = $this->identity;
        }

        return $renderPlan;
    }

    /**
     * Bảo đảm `as_<hero>` có mặt trong `assets`. Giữ nguyên asset sẵn có (thừa
     * thì vô hại — schema không đòi mọi asset phải được scene tham chiếu), chỉ
     * thêm khi thiếu.
     *
     * @param  list<array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    private function ensureHeroAsset(array $assets, string $heroId): array
    {
        $assetId = 'as_'.$heroId;

        foreach ($assets as $asset) {
            if (($asset['id'] ?? null) === $assetId) {
                return $assets;
            }
        }

        $assets[] = ['id' => $assetId, 'kind' => 'structure', 'entity_id' => $heroId, 'required' => true];

        return $assets;
    }
}
