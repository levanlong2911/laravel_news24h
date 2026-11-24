<?php

return (static function (): array {
    $at = '2026-11-24 09:00:00';
    $file = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v9/phase-7-rev22.md';
    $content = file_get_contents($file);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Moto shot plan revision 22: phase-7-rev22.md is missing or empty.');
    }

    $prompt = str_replace("\r\n", "\n", $content);

    return [
        'schemas' => [],
        'phases' => [
            [
                'category' => 'moto',
                'id' => '1ba3290f-6b0a-4ccd-b42c-e9d0313c0097',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-7',
                'revision' => 22,
                'kind' => 'phase',
                'name' => 'Phase 7 — Shot plan in seven steps, each rule stated once',
                'sort_order' => 7,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => '66afaccc-7253-4ff0-ab0c-d26c30a09c2f',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 7 moto revision 22 (release moto-cinematic-3s-v9, nâng cấp tại chỗ; chính sách full-coverage-budget-v1, format moto-shots-v3, schema MotoShots@3.0.0 và đầu vào moto-shots-input-v14 giữ nguyên; nền là revision 21): viết lại prompt thành bảy bước theo trình tự làm việc — theo nguồn đã duyệt, chia ngân sách cả phim, xác lập chủ thể và trạng thái, dàn hành động và hai khung đầu cuối, thiết kế máy quay và bố cục, nối ảnh nền - tham chiếu - card, tự rà rồi trả về. Mỗi quy định chỉ nêu một lần; bỏ phần tả lại cấu trúc đầu vào và các định nghĩa trường đã có trong schema; bỏ danh sách tự rà lặp lại luật. Viết rõ ba điểm: tham chiếu continuity chỉ trỏ tới shot trước cùng scene hoặc scene liền trước được continuity_from nêu, cùng địa điểm sau khi thời gian trôi qua không phải hành động liên tục; gọi người bằng mã, không suy giới tính; số đo máy quay, cỡ cảnh và lời tả phải khớp nhau. Chính sách quay (máy chuyển động suốt shot, mở từ khung của ảnh nền, shot 3000 ms, ngân sách và giới hạn shot) không đổi.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => '60c88d20-9942-49eb-9a17-3a2022c6ffd0',
                'phase_id' => '1ba3290f-6b0a-4ccd-b42c-e9d0313c0097',
                'schema_id' => '213e1afe-f1a3-4cb2-81e0-94f7d2e916e3',
                'role' => 'output',
                'slot_key' => 'shots',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
