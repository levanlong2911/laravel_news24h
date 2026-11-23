<?php

return (static function (): array {
    $at = '2026-11-21 16:00:00';
    $path = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v7/phase-5-rev7.md';
    $content = file_get_contents($path);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Moto Phase 5 revision 7: phase-5-rev7.md is missing or empty.');
    }

    $prompt = str_replace("\r\n", "\n", $content);

    return [
        'schemas' => [],
        'phases' => [
            [
                'category' => 'moto',
                'id' => '1e1c0b05-5000-4e70-b12f-0481bea68f8a',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-5',
                'revision' => 7,
                'kind' => 'phase',
                'name' => 'Phase 5 — Location dossier and plates',
                'sort_order' => 5,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => '75aae423-39e6-4c29-bf70-1745e710de66',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 5 moto revision 7 (release moto-cinematic-3s-v7; format moto-locations-v3, đầu vào moto-locations-input-v4 và schema MotoLocations@3.0.0 giữ nguyên; nền là revision 6): chỉ đổi hai câu sau lượt thật đầu tiên thiếu khoá bắt buộc ground ở ba ảnh nền — ground luôn được ghi, dry hoặc wet khi beat nêu mặt đường, còn lại là null; mọi khoá schema bắt buộc đều được ghi kể cả khi giá trị là null hoặc danh sách rỗng. Cách lập địa điểm và ảnh nền không đổi.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => '06f67114-afe2-494b-903e-9916a398bb1b',
                'phase_id' => '1e1c0b05-5000-4e70-b12f-0481bea68f8a',
                'schema_id' => '3f4d096e-ef01-4de7-b233-1e0a09fc11b6',
                'role' => 'output',
                'slot_key' => 'locations',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
