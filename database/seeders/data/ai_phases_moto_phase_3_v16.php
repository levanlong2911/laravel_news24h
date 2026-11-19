<?php

return (static function (): array {
    $at = '2026-11-19 18:00:00';
    $path = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v7/phase-3-rev16.md';
    $content = file_get_contents($path);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Moto Phase 3 revision 16: phase-3-rev16.md is missing or empty.');
    }

    $prompt = str_replace("\r\n", "\n", $content);

    return [
        'schemas' => [],
        'phases' => [
            [
                'category' => 'moto',
                'id' => 'a34305c2-c8c0-4af8-8494-6a16e7345651',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-3',
                'revision' => 16,
                'kind' => 'phase',
                'name' => 'Phase 3 — Custom design in seven stages, assembled (Harley-Davidson custom)',
                'sort_order' => 3,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => '8fb60aad-bb7a-4940-91dd-6e98fc9222fd',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 3 moto revision 16 (release moto-cinematic-3s-v7; format moto-design-v3, đầu vào moto-design-input-v2 và schema MotoDesign@3.0.0 giữ nguyên; nền là revision 15): khai assembly protocol moto-design-assembly-v1 — PHP điền các trường cố định từ nguồn đã ghim (vỏ ngoài, base, rider, id/tên/loại/visible_at_rest của feature, id departure, id và tên vai, id và loại giai đoạn, đơn vị cố định, hằng số toạ độ, camera đã ghim), AI chỉ trả phần thiết kế theo generation schema dẫn xuất từ schema Final cùng dna_coverage; thêm nhiệm vụ so sánh và chọn phương án (tóm tắt trong BS01), Design Bible, số đo DNA là số đo của thiết kế; thay "One idea everywhere" bằng "One coherent idea" (tôn trọng bề mặt DNA để trơn); thêm mục tự rà trước khi trả; mục Output theo generation schema.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => 'a34305c2-cbbe-45db-9bbb-7e97e41b2670',
                'phase_id' => 'a34305c2-c8c0-4af8-8494-6a16e7345651',
                'schema_id' => 'f6005287-dbc4-4408-9aa8-40e59da93c7d',
                'role' => 'output',
                'slot_key' => 'design',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
