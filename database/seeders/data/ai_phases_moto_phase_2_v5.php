<?php

return (static function (): array {
    $at = '2026-11-19 12:00:00';
    $path = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v5/phase-2-rev5.md';
    $content = file_get_contents($path);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Moto Phase 2 revision 5: phase-2-rev5.md is missing or empty.');
    }

    $prompt = str_replace("\r\n", "\n", $content);

    return [
        'schemas' => [],
        'phases' => [
            [
                'category' => 'moto',
                'id' => '43bec9e0-22aa-4b7b-ab75-b095c6bffef8',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-2',
                'revision' => 5,
                'kind' => 'phase',
                'name' => 'Phase 2 — Custom Motorcycle Design DNA',
                'sort_order' => 2,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => '7eb708cc-59bd-4563-8589-4d821da0100b',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 2 moto revision 5 (release cinematic v5, v7; format moto-dna-v2, đầu vào moto-dna-input-v2 và schema MotoDna@2.0.0 giữ nguyên; nền là revision 4): sửa các lỗi của lượt DNA thật đầu tiên theo revision 4 (dự án a3427fd0, 19/11) — nêu rõ chiếc xe cần giao: thấp, dài, khối đầy, bánh trước nổi bật, lốp sau rộng, chắn bùn trước ôm bánh, chắn bùn sau liền với yên và đuôi, kim loại nhấn nhìn rõ ở mâm, ốp máy và ống xả; departure không được bỏ bộ phận hay làm yếu dáng xe và phải ghi đúng phương pháp; must_avoid không cấm cỡ lốp, chắn bùn hay dáng custom chỉ vì nguồn dùng; chức năng đặc biệt chỉ khi phục vụ mục đích sử dụng; change_scope gồm mọi cụm DNA thiết kế lại; hình học đầu xe giữ hay đổi phải thống nhất; mâm tả như một kết cấu liền; ngoài brief chỉ dùng tên trung tính cho xe nền; thêm bước tự rà theo danh sách cụ thể và bổ sung vào đúng trường trước khi trả JSON.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => '2106afb2-5532-44fe-8fd7-207ef69117d8',
                'phase_id' => '43bec9e0-22aa-4b7b-ab75-b095c6bffef8',
                'schema_id' => 'c8514b0c-c340-4d94-bbd0-eaced2c9f259',
                'role' => 'output',
                'slot_key' => 'dna',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
