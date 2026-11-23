<?php

return (static function (): array {
    $at = '2026-11-21 12:00:00';
    $dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v7/';
    $read = static function (string $file) use ($dir): string {
        $content = file_get_contents($dir.$file);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Moto location dossier release: '.$file.' is missing or empty.');
        }

        return str_replace("\r\n", "\n", $content);
    };
    $phase = static fn (string $id, string $code, int $revision, string $name, int $order, string $file, string $basedOn, string $note): array => [
        'category' => 'moto',
        'id' => $id,
        'workflow_code' => 'harley_custom_v1',
        'phase_code' => $code,
        'revision' => $revision,
        'kind' => 'phase',
        'name' => $name,
        'sort_order' => $order,
        'prompt_content' => $read($file),
        'content_sha256' => hash('sha256', $read($file)),
        'needs' => null,
        'parallel_with' => null,
        'stop' => null,
        'status' => 'published',
        'is_current' => 1,
        'based_on_id' => $basedOn,
        'source_file' => null,
        'source_section_key' => null,
        'change_note' => $note,
        'created_by' => 'db:seed AiPhaseSeeder',
        'created_at' => $at,
        'published_at' => $at,
    ];
    $link = static fn (string $id, string $phaseId, string $schemaId, string $slot): array => [
        'id' => $id,
        'phase_id' => $phaseId,
        'schema_id' => $schemaId,
        'role' => 'output',
        'slot_key' => $slot,
        'data_pointer' => '',
        'validation_mode' => 'whole',
        'is_required' => 1,
        'created_at' => $at,
    ];
    $schema = $read('MotoLocations-3.0.0.schema.json');
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);

    return [
        'schemas' => [
            [
                'id' => '3f4d096e-ef01-4de7-b233-1e0a09fc11b6',
                'namespace' => 'moto',
                'schema_key' => 'MotoLocations',
                'version' => '3.0.0',
                'name' => 'Moto Phase 5 — location dossier and background plates (moto-locations-v3)',
                'source_file' => 'resources/ai/moto/releases/moto-cinematic-3s-v7/MotoLocations-3.0.0.schema.json',
                'dependencies' => '[]',
                'status' => 'published',
                'created_by' => 'db:seed AiPhaseSeeder',
                'schema_content' => $schema,
                'schema_sha256' => hash('sha256', $schema),
                'created_at' => $at,
                'json_schema_id' => $decoded['$id'],
                'json_schema_dialect' => $decoded['$schema'],
                'change_note' => 'MotoLocations 3.0.0: format moto-locations-v3; mỗi địa điểm thêm function, source_beats, setting, provenance, coordinate_system, zones (kèm footprint và trục đặt chủ thể), access_routes (thay subject_zone và entry_routes dạng chữ); thêm nhóm connections; mỗi ảnh nền thêm subject_zone, landmarks, time_of_day, weather, atmosphere, ambient_sound và hướng, tính chất ánh sáng; số đo có ba nguồn gốc source, production_assumption, unknown; đường dẫn bằng chứng tới sự kiện, checkpoint, kích thước xe và flags của kịch bản. 2.0.0 giữ làm lịch sử.',
                'published_at' => $at,
            ],
        ],
        'phases' => [
            $phase('75aae423-39e6-4c29-bf70-1745e710de66', 'phase-5', 6, 'Phase 5 — Location dossier and plates', 5, 'phase-5-rev6.md', '1dc551f8-1cce-48a1-9309-9d49defa1359',
                'Phase 5 moto revision 6 (release moto-cinematic-3s-v7; format moto-locations-v3, đầu vào moto-locations-input-v4, schema MotoLocations@3.0.0; nền là revision 5): bảy bước làm việc — đọc nguồn, lập danh mục địa điểm (một nơi vật lý một mã), nối các địa điểm (connections), thiết kế không gian (zones, access_routes, coordinate_system, footprint), lập ảnh nền theo điều kiện sử dụng (buổi, ánh sáng, thời tiết, khí quyển, mặt đất, âm thanh nền), đối chiếu nguồn và độ vừa, tự rà; phân định thiết kế, kịch bản và môi trường; mọi lựa chọn riêng là giả định sản xuất; flags của kịch bản ảnh hưởng địa điểm giữ thành xung đột; điều kiện làm đổi hình ảnh thì cùng địa điểm có ảnh nền riêng.'),
            $phase('163220d9-f275-4cd8-954e-38d2fb9b5a39', 'phase-6', 13, 'Phase 6 — Scenes with tracked work, read from the location dossier', 6, 'phase-6-rev13.md', '6e0cf7fe-a4fc-4bfd-a54d-cbf96727bf82',
                'Phase 6 moto revision 13 (release moto-cinematic-3s-v7; format moto-scenes-v3 và schema MotoScenes@3.0.1 giữ nguyên, đầu vào moto-scenes-input-v6; nền là revision 12): chỉ đổi phần mô tả dữ liệu địa điểm nhận được — mỗi địa điểm có setting, function, zones và access_routes theo mã, connections giữa các địa điểm, mỗi ảnh nền có subject_zone, buổi và thời tiết; người vào theo lối và vùng có mã; xung đột địa điểm chặn giữ beat lại. Cách chia scene không đổi.'),
            $phase('307c1f0e-ad9b-4565-9318-1601d40bb368', 'phase-7', 19, 'Phase 7 — How each scene is filmed, staged in the zones of the location dossier', 7, 'phase-7-rev19.md', '7282dc54-913b-4993-9983-1e59d8b68705',
                'Phase 7 moto revision 19 (release moto-cinematic-3s-v7; chính sách full-coverage-budget-v1, format moto-shots-v3 và schema MotoShots@3.0.0 giữ nguyên, đầu vào moto-shots-input-v12; nền là revision 18): chỉ đổi phần mô tả dữ liệu địa điểm nhận được — địa điểm có setting, coordinate_system, zones (vị trí, footprint, trục đặt chủ thể), access_routes (bề rộng, ai dùng), connections; ảnh nền có subject_zone, landmarks và điều kiện sử dụng (buổi, hướng và tính chất ánh sáng, thời tiết, khí quyển, mặt đất); chủ thể đứng ở vùng chủ thể của ảnh nền, máy quay không đi qua vùng chặn. Cách chia shot và giới hạn của shot không đổi.'),
            $phase('5e45158a-f9dc-4cd7-abdf-97f84f4d1d49', 'phase-10', 21, 'Phase 10 — Video prompt writer, with the conditions of the place', 10, 'phase-10-rev21.md', 'a07e92ea-c82a-4e36-a644-32ee6e20a29b',
                'Phase 10 moto revision 21 (release moto-cinematic-3s-v7; đầu vào moto-clip-prompt-input-v10, hai schema đầu ra không đổi; nền là revision 20): chỉ thêm nhóm environment — điều kiện của ảnh nền shot đứng trên theo bộ hồ sơ địa điểm (time_of_day, weather, atmosphere, ambient_sound); buổi, thời tiết, khí quyển là dữ kiện của hình; ambient_sound chỉ vào dòng âm thanh, đứng trước tiếng của các sự kiện, không tả thành hình. Cách viết prompt video không đổi.'),
        ],
        'links' => [
            $link('02722e05-97dd-4631-a796-4b508fe4090c', '5e45158a-f9dc-4cd7-abdf-97f84f4d1d49', '03a9ca35-5b38-46a0-a978-658bfd56a2da', 'clip_plan'),
            $link('a686420c-5454-43e9-ad2f-62b387e7fdc1', '5e45158a-f9dc-4cd7-abdf-97f84f4d1d49', 'cec087c0-78f7-446c-a980-cb1a784bfd40', 'clip_prompt'),
            $link('f0d2aa62-ecbf-4165-9798-7a1777d37bc1', '75aae423-39e6-4c29-bf70-1745e710de66', '3f4d096e-ef01-4de7-b233-1e0a09fc11b6', 'locations'),
            $link('a76ab39c-861d-470d-832d-606e92eae807', '163220d9-f275-4cd8-954e-38d2fb9b5a39', '10c845a5-3f62-4898-bca8-a82ad55fea6b', 'scenes'),
            $link('789f4613-c3a4-454b-bb92-5220bbe6813c', '307c1f0e-ad9b-4565-9318-1601d40bb368', '213e1afe-f1a3-4cb2-81e0-94f7d2e916e3', 'shots'),
        ],
    ];
})();
