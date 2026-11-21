<?php

return (static function (): array {
    $at = '2026-11-20 12:00:00';
    $path = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v7/phase-7-rev18.md';
    $content = file_get_contents($path);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Moto Phase 7 revision 18: phase-7-rev18.md is missing or empty.');
    }

    $prompt = str_replace("\r\n", "\n", $content);

    return [
        'schemas' => [],
        'phases' => [
            [
                'category' => 'moto',
                'id' => '7282dc54-913b-4993-9983-1e59d8b68705',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-7',
                'revision' => 18,
                'kind' => 'phase',
                'name' => 'Phase 7 — How each scene is filmed, within the limits of a shot',
                'sort_order' => 7,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => 'eda5dc96-fa97-4526-8125-aba2db31a5b7',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 7 moto revision 18 (release moto-cinematic-3s-v7, chính sách full-coverage-budget-v1, format moto-shots-v3 và schema MotoShots@3.0.0 giữ nguyên, đầu vào moto-shots-input-v11; nền là revision 17): nhận film.shot_limits do code khai — một shot diễn tối đa số sự kiện khác nhau đã cho (tính cả sự kiện tiếp từ shot trước) và hiện tối đa số người khác nhau đã cho (tính cả người xuất hiện lần lượt); giới hạn là mức trần, tính theo sự kiện chứ không theo bước timeline; luật hai người từ lời khuyên thành giới hạn cứng; sự kiện nêu quá số người thì khai xung đột chặn, không bỏ người.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => '2e349867-acbb-4824-9a50-eca85f23ded1',
                'phase_id' => '7282dc54-913b-4993-9983-1e59d8b68705',
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
