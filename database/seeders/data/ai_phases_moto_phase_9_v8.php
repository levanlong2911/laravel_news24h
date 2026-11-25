<?php

return (static function (): array {
    $at = '2026-11-24 18:00:00';
    $file = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v9/phase-9-rev8.md';
    $content = file_get_contents($file);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Moto keyframe prompt revision 8: phase-9-rev8.md is missing or empty.');
    }

    $prompt = rtrim(str_replace("\r\n", "\n", $content), "\n");

    return [
        'schemas' => [],
        'phases' => [
            [
                'category' => 'moto',
                'id' => '45bce594-6272-45b5-a029-e3a8ca9b3f6a',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-9',
                'revision' => 8,
                'kind' => 'phase',
                'name' => 'Phase 9 — Keyframe image prompt, composed or edited, for a master and a delivery frame',
                'sort_order' => 9,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 0,
                'based_on_id' => '378787ff-2b5a-4b6f-9ead-38c5f3ecef37',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 9 moto revision 8 (release moto-cinematic-3s-v9 ghim bằng catalogue; schema MotoKeyframePrompt@1.0.2 và format moto-keyframe-prompt-v1 giữ nguyên; đầu vào moto-keyframe-prompt-input-v6; nền là revision 7, vẫn là bản hiện hành của các release không ghim Phase 9): đầu vào thêm operating_start của khung đầu, bản ghi ảnh nền đầy đủ như kế hoạch shot đã ghim, cảm xúc của scene làm ngữ cảnh, chính sách khung master/delivery và creation_mode; prompt viết gọn theo sáu bước, mỗi luật một lần, có hai nhánh compose (dựng khung mới từ ảnh tham chiếu) và edit (sửa đúng những gì người dùng nêu trên ảnh ứng viên, giữ nguyên phần còn lại).',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => '07275d0b-f74d-4311-9086-9bff004f6685',
                'phase_id' => '45bce594-6272-45b5-a029-e3a8ca9b3f6a',
                'schema_id' => 'd1c66641-1ff7-41ef-900d-7c08bf57b3a2',
                'role' => 'output',
                'slot_key' => 'keyframe_prompt',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
