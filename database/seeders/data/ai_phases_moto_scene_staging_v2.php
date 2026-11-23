<?php

return (static function (): array {
    $at = '2026-11-23 20:00:00';
    $dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v9/';
    $read = static function (string $file) use ($dir): string {
        $content = file_get_contents($dir.$file);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Moto scene staging v2: '.$file.' is missing or empty.');
        }

        return str_replace("\r\n", "\n", $content);
    };
    $schema = $read('MotoScenes-3.1.1.schema.json');
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);
    $prompt = $read('phase-6-rev16.md');

    return [
        'schemas' => [
            [
                'id' => '852a2f19-a7ed-46e6-85f0-b3d89b63c6bd',
                'namespace' => 'moto',
                'schema_key' => 'MotoScenes',
                'version' => '3.1.1',
                'name' => 'Moto Phase 6 — staged scenes with marked events, pace and emotion, continuity and tracked work (moto-scenes-v3)',
                'source_file' => 'resources/ai/moto/releases/moto-cinematic-3s-v9/MotoScenes-3.1.1.schema.json',
                'dependencies' => '[]',
                'status' => 'published',
                'created_by' => 'db:seed AiPhaseSeeder',
                'schema_content' => $schema,
                'schema_sha256' => hash('sha256', $schema),
                'created_at' => $at,
                'json_schema_id' => $decoded['$id'],
                'json_schema_dialect' => $decoded['$schema'],
                'change_note' => 'MotoScenes 3.1.1: cấu trúc giữ nguyên như 3.1.0; chỉ đổi tiêu đề và mô tả blocking — blocking kể theo thứ tự sự kiện của kịch bản, mỗi sự kiện của scene đánh dấu đúng một lần bằng mã trong ngoặc (EVxx) cạnh phần dàn cảnh của nó, kể cả sự kiện không có nhân vật; sáu nội dung (vị trí mở cảnh, hướng và hướng nhìn, di chuyển, tương tác, vật đỡ, vị trí kết) là những gì cần tả khi phù hợp, không phải sáu đoạn viết nối tiếp; người được gọi bằng mã, không dùng đại từ giới tính; hướng mặt trước của vật không phải hướng nhìn của người; không thêm hành động, người, vật hay di chuyển chỉ để nối câu. 3.1.0 giữ làm lịch sử.',
                'published_at' => $at,
            ],
        ],
        'phases' => [
            [
                'category' => 'moto',
                'id' => '64b44bf3-55d3-4e5e-b981-bb871d7c4133',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-6',
                'revision' => 16,
                'kind' => 'phase',
                'name' => 'Phase 6 — Scenes staged in seven steps, every event marked in its blocking',
                'sort_order' => 6,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => 'bec663ef-c77c-4c7d-ac26-466da297c2a2',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 6 moto revision 16 (release moto-cinematic-3s-v9, nâng cấp tại chỗ; hợp đồng dàn dựng moto-scene-staging-v2, schema MotoScenes@3.1.1, đầu vào moto-scenes-input-v8 giữ nguyên; nền là revision 15): sửa các chỉ dẫn gây lỗi ở lượt Scene thật đầu tiên. Bước 4: blocking kể theo thứ tự sự kiện của kịch bản; sáu nội dung là những gì cần tả khi phù hợp, không phải sáu đoạn viết nối tiếp; mỗi sự kiện của scene đánh dấu đúng một lần bằng (EVxx) cạnh phần dàn cảnh của nó, kể cả sự kiện không có nhân vật (tả vị trí hay trạng thái của chủ thể, không thêm người, không chỉ dẫn máy quay), không chép danh sách mã thay cho dấu; gọi người bằng mã, không suy giới tính; đặt người theo điểm thao tác và quay về phía đó, hướng mặt trước của vật không phải hướng nhìn của người; không thêm hành động, người, vật, di chuyển chỉ để nối câu. Trước Work: part ghi có mặt không tự chứng minh đã lắp trước sự kiện — ghi mọi thao tác sự kiện cho thấy theo luật Work, siết hay chỉnh part đã lắp không phải lần lắp mới. Bước 7 thêm ba dòng tự rà. Phần Work giữ nguyên văn revision 14; các bước khác như revision 15.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => 'd6d40cab-43cf-4ffe-93a4-58d5bef4679e',
                'phase_id' => '64b44bf3-55d3-4e5e-b981-bb871d7c4133',
                'schema_id' => '852a2f19-a7ed-46e6-85f0-b3d89b63c6bd',
                'role' => 'output',
                'slot_key' => 'scenes',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
