<?php

namespace App\Video\Analysis;

/**
 * Đọc RenderPlan đã lắp xong → danh sách cảnh báo cho NGƯỜI DUYỆT, trước khi
 * tiêu tiền render.
 *
 * ĐÂY KHÔNG PHẢI TẦNG MỚI. Cổng duyệt đã tồn tại từ trước (`draft → composing →
 * approved | needs_revision → queued → rendered`, không đường render nào bỏ qua
 * duyệt). Cái thiếu là người duyệt đang bấm approve mà KHÔNG BIẾT plan đó nghèo
 * dữ liệu tới mức nào. Class này chỉ tính ra mấy con số đó — không chặn, không
 * đổi trạng thái, không quyết định thay ai.
 *
 * Vì sao dừng ở "cảnh báo", không chặn: pipeline CỐ Ý tách Truth và Creative.
 * Bài báo nghèo chi tiết thị giác là chuyện BÌNH THƯỜNG (tin di chuyển tàu, tin
 * tài chính, tin thể thao đều vậy) và `creative_identity` tồn tại đúng để lấp
 * chỗ đó. Chặn theo độ giàu thị giác nghĩa là nói "bài báo phải giàu visual mới
 * được render" — trái với chính kiến trúc.
 *
 * 100% deterministic: không LLM, không I/O, không `now()`, không random. Cùng
 * plan luôn ra cùng báo cáo.
 *
 * ĐỌC RENDERPLAN (mảng), không đọc VerifiedWorldGraph — cố ý:
 *   - `renderplan_json` đọc lại từ DB cũng dùng được, không cần chạy lại pipeline
 *   - RenderPlan là post-verification nên không có evidence/quote nào lọt vào đây
 *
 * Ngưỡng ở dưới TIÊM QUA CONSTRUCTOR và default là PHỎNG ĐOÁN từ đúng MỘT plan
 * thật (2026-07-30). Chưa hiệu chuẩn. Đừng đọc chúng như hằng số đã kiểm chứng.
 */
final class RenderPlanQualityReport
{
    /**
     * @param  int  $minDescriptiveAttributeNames  Số TÊN attribute có giá trị chuỗi khác nhau,
     *                                             dưới mức này thì cảnh báo. Bằng chứng N=1: plan thật có đúng 1
     *                                             (`vessel_type`) — mọi attribute còn lại là số đo.
     */
    public function __construct(
        private readonly int $minDescriptiveAttributeNames = 3,
    ) {}

    /**
     * @param  array<string, mixed>  $renderPlan
     * @return array{
     *     warnings: list<array{code: string, message: string, detail: array<string, mixed>}>,
     *     metrics: array<string, mixed>
     * }
     */
    public function analyze(array $renderPlan): array
    {
        $entities = $renderPlan['world']['entities'] ?? [];
        $scenes = $renderPlan['scenes'] ?? [];

        $warnings = [];
        $metrics = [];

        // ---- 1. Hero có XÁC ĐỊNH ĐƯỢC không (không phải "hero có đúng không") ----
        //
        // Câu hỏi cố ý yếu hơn: "pipeline có tín hiệu nào để biết bài này nói về
        // entity nào?" chứ KHÔNG phải "entity đã chọn có đúng không". Câu mạnh
        // hơn không tính được — bằng chứng thật (bài a25f63f3, 2026-07-29): tiêu
        // đề là "$90M superyacht owned by Carnival Cruise Line billionaire headed
        // for midcoast", KHÔNG chứa tên con tàu nào. Mọi đường nối tiêu đề →
        // entity đều qua dữ liệu hiện bị vứt (`owner`) hoặc không hề trích (giá).
        //
        // Nên báo ĐỘ BẤT ĐỊNH, không khẳng định một đáp án sai.
        $namedInTitle = $this->entitiesNamedInTitle($entities, (string) ($renderPlan['story']['title'] ?? ''));
        $metrics['entities_named_in_title'] = $namedInTitle;

        if ($namedInTitle === []) {
            $warnings[] = [
                'code' => 'HERO_NOT_DETERMINABLE',
                'message' => 'Tiêu đề không gọi tên entity nào — chủ thể chính đang chọn bằng suy đoán "entity đầu tiên", không có tín hiệu nào xác nhận',
                'detail' => ['entity_names' => $this->entityNames($entities)],
            ];
        }

        // ---- 2. Độ giàu chi tiết MÔ TẢ ----
        //
        // Lọc theo KIỂU DỮ LIỆU, không theo tên attribute — bắt buộc, vì lọc theo
        // tên (`hull_color`, `material`…) là nhồi kiến thức ngành vào code, đúng
        // thứ §1 cấm. Giá trị chuỗi ≈ mô tả ("motor yacht", "grey"); giá trị số ≈
        // số đo (242, 1992). Đây là PROXY, không phải phép đo chính xác: một
        // `vessel_type` chuỗi cũng chỉ là phân loại, chưa phải chi tiết thị giác.
        // Dùng SỐ TÊN KHÁC NHAU (không phải tổng số giá trị) vì cùng một
        // `vessel_type` lặp ở 3 entity không giàu hơn 1 entity.
        $descriptive = $this->descriptiveAttributeNames($entities);
        $metrics['descriptive_attribute_names'] = $descriptive;
        $metrics['attribute_values_total'] = $this->countAttributeValues($entities);

        if (count($descriptive) < $this->minDescriptiveAttributeNames) {
            $warnings[] = [
                'code' => 'LOW_DESCRIPTIVE_RICHNESS',
                'message' => sprintf(
                    'Bài báo chỉ cho %d loại thuộc tính mô tả — chi tiết hình ảnh sẽ do Editorial bịa gần như toàn bộ, không lấy từ bài',
                    count($descriptive),
                ),
                'detail' => ['attribute_names' => $descriptive, 'threshold' => $this->minDescriptiveAttributeNames],
            ];
        }

        // ---- 3. Môi trường cấp video ----
        //
        // Tính LẠI lý do từ mảng, KHÔNG gọi được EditorialInterpreter::
        // environmentDiagnosisFor() vì hàm đó nhận VerifiedWorldGraph còn ở đây
        // chỉ có RenderPlan. Trùng lặp có chủ ý, ~4 dòng, hai input khác nhau.
        $landscapeIds = $this->landscapeIds($entities);
        $environment = $renderPlan['world_environment'] ?? [];
        $metrics['landscape_entity_ids'] = $landscapeIds;

        if ($environment === []) {
            $warnings[] = [
                'code' => 'NO_ENVIRONMENT',
                'message' => count($landscapeIds) >= 2
                    ? 'Nhiều hơn một entity bối cảnh — không biết cảnh nào ứng với cái nào nên bỏ trống hết (không đoán); thời tiết/thời điểm sẽ do provider tự quyết'
                    : 'Không có fact môi trường nào — thời tiết/thời điểm/ánh sáng sẽ do provider tự quyết, mỗi lần render một kiểu',
                'detail' => ['landscape_entity_ids' => $landscapeIds],
            ];
        }

        // ---- 4. Tham chiếu treo — CHỈ ở field BẮT BUỘC phải phân giải được ----
        //
        // `subjects`/`act_id`/`asset_refs` treo là lỗi thật: Editorial tra
        // subject không thấy sẽ âm thầm không sinh candidate nào.
        //
        // CỐ Ý KHÔNG xét `camera.target` và `director_notes.hero`: chúng ĐƯỢC
        // PHÉP trỏ tới vật thể bịa không phải entity. Bằng chứng thật: 4 scene
        // Creation Arc trỏ tới `design_drawing`/`marine_engine`/`hull_seam`/
        // `upper_deck` — cố ý, và schema định nghĩa `camera.target` là `slug`
        // chứ không ràng buộc phải là entity. Cảnh báo ở đó = 4 báo động giả mỗi
        // video, dạy người duyệt bỏ qua cảnh báo. Xem `metrics` để tra tay.
        $dangling = $this->danglingReferences($renderPlan, $entities, $scenes);
        $metrics['non_entity_camera_targets'] = $this->nonEntityCameraTargets($entities, $scenes);

        if ($dangling !== []) {
            $warnings[] = [
                'code' => 'DANGLING_REFERENCE',
                'message' => 'Có scene trỏ tới id không tồn tại trong plan — tầng sau sẽ bỏ qua âm thầm',
                'detail' => ['references' => $dangling],
            ];
        }

        // ---- 5. Scene thiếu `objective` ----
        //
        // `objective` chỉ có MỘT nguồn: `producer.visual_promise` (§17/§18).
        // Scene thiếu nó thì tầng compile phía sau mất một tín hiệu khi dựng câu
        // lệnh render — điểm tin cậy của scene đó thấp hơn các scene khác.
        // Bằng chứng thật: 6/15 scene thiếu, đúng 6 scene Creation Arc (chèn SAU
        // khi Producer đã chạy nên không cái nào có objective).
        $missingObjective = $this->scenesMissingObjective($scenes);
        $metrics['scenes_total'] = count($scenes);
        $metrics['scenes_missing_objective'] = $missingObjective;

        if ($missingObjective !== []) {
            $warnings[] = [
                'code' => 'SCENES_MISSING_OBJECTIVE',
                'message' => sprintf(
                    '%d/%d scene không có objective — các scene đó thiếu một tín hiệu khi dựng lệnh render',
                    count($missingObjective),
                    count($scenes),
                ),
                'detail' => ['scene_ids' => $missingObjective],
            ];
        }

        return ['warnings' => $warnings, 'metrics' => $metrics];
    }

    /**
     * Khớp theo BIÊN TỪ, không `str_contains()` — cùng lý do đã sửa
     * `EditorialInterpreter` (2026-07-29): tên ngắn nằm lọt trong từ khác sẽ khớp
     * sai. Tên rỗng/không có thì bỏ qua, entity vô danh là hợp lệ.
     *
     * @param  list<array<string, mixed>>  $entities
     * @return list<string> id các entity được gọi tên trong tiêu đề
     */
    private function entitiesNamedInTitle(array $entities, string $title): array
    {
        if (trim($title) === '') {
            return [];
        }

        $found = [];

        foreach ($entities as $entity) {
            $name = trim((string) ($entity['identity']['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            if (preg_match('/\b'.preg_quote($name, '/').'\b/iu', $title) === 1) {
                $found[] = (string) $entity['id'];
            }
        }

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @return list<string>
     */
    private function entityNames(array $entities): array
    {
        $names = [];

        foreach ($entities as $entity) {
            $name = trim((string) ($entity['identity']['name'] ?? ''));

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Tên attribute có ÍT NHẤT MỘT giá trị dạng chuỗi. Trả về đã sắp để báo cáo
     * ổn định (cùng plan → cùng thứ tự).
     *
     * @param  list<array<string, mixed>>  $entities
     * @return list<string>
     */
    private function descriptiveAttributeNames(array $entities): array
    {
        $names = [];

        foreach ($entities as $entity) {
            foreach ($entity['attributes'] ?? [] as $name => $value) {
                foreach ((array) $value as $single) {
                    if (is_string($single)) {
                        $names[(string) $name] = true;
                    }
                }
            }
        }

        $sorted = array_keys($names);
        sort($sorted);

        return $sorted;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     */
    private function countAttributeValues(array $entities): int
    {
        $total = 0;

        foreach ($entities as $entity) {
            foreach ($entity['attributes'] ?? [] as $value) {
                $total += count((array) $value);
            }
        }

        return $total;
    }

    /**
     * @param  list<array<string, mixed>>  $entities
     * @return list<string>
     */
    private function landscapeIds(array $entities): array
    {
        return array_values(array_map(
            fn (array $e) => (string) $e['id'],
            array_filter($entities, fn (array $e) => ($e['type'] ?? null) === 'landscape'),
        ));
    }

    /**
     * @param  array<string, mixed>  $renderPlan
     * @param  list<array<string, mixed>>  $entities
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array{scene_id: string, field: string, missing_id: string}>
     */
    private function danglingReferences(array $renderPlan, array $entities, array $scenes): array
    {
        $entityIds = array_column($entities, 'id');
        $actIds = array_column($renderPlan['acts'] ?? [], 'id');
        $assetIds = array_column($renderPlan['assets'] ?? [], 'id');

        $dangling = [];

        foreach ($scenes as $scene) {
            $sceneId = (string) ($scene['id'] ?? '?');

            foreach ($scene['subjects'] ?? [] as $subject) {
                if (! in_array($subject, $entityIds, true)) {
                    $dangling[] = ['scene_id' => $sceneId, 'field' => 'subjects', 'missing_id' => (string) $subject];
                }
            }

            $actId = $scene['act_id'] ?? null;
            if ($actId !== null && ! in_array($actId, $actIds, true)) {
                $dangling[] = ['scene_id' => $sceneId, 'field' => 'act_id', 'missing_id' => (string) $actId];
            }

            foreach ($scene['asset_refs'] ?? [] as $assetRef) {
                if (! in_array($assetRef, $assetIds, true)) {
                    $dangling[] = ['scene_id' => $sceneId, 'field' => 'asset_refs', 'missing_id' => (string) $assetRef];
                }
            }
        }

        return $dangling;
    }

    /**
     * Quan sát, KHÔNG phải cảnh báo — xem lý do ở khối 4. Để người duyệt soi
     * mắt phát hiện một cái sai chính tả lẫn giữa các id bịa hợp lệ.
     *
     * @param  list<array<string, mixed>>  $entities
     * @param  list<array<string, mixed>>  $scenes
     * @return list<array{scene_id: string, target: string}>
     */
    private function nonEntityCameraTargets(array $entities, array $scenes): array
    {
        $entityIds = array_column($entities, 'id');
        $targets = [];

        foreach ($scenes as $scene) {
            $target = $scene['camera']['target'] ?? null;

            if ($target !== null && ! in_array($target, $entityIds, true)) {
                $targets[] = ['scene_id' => (string) ($scene['id'] ?? '?'), 'target' => (string) $target];
            }
        }

        return $targets;
    }

    /**
     * @param  list<array<string, mixed>>  $scenes
     * @return list<string>
     */
    private function scenesMissingObjective(array $scenes): array
    {
        $missing = [];

        foreach ($scenes as $scene) {
            if (trim((string) ($scene['objective'] ?? '')) === '') {
                $missing[] = (string) ($scene['id'] ?? '?');
            }
        }

        return $missing;
    }
}
