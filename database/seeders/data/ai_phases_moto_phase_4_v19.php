<?php

return (static function (): array {
    $at = '2026-11-20 12:00:00';
    $dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v7/';
    $read = static function (string $file) use ($dir): string {
        $content = file_get_contents($dir.$file);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Moto Phase 4 revision 19: '.$file.' is missing or empty.');
        }

        return str_replace("\r\n", "\n", $content);
    };
    $prompt = $read('phase-4-rev19.md');
    $schema = $read('MotoScript-3.1.0.schema.json');
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);

    return [
        'schemas' => [
            [
                'id' => '90f0c0e8-c75a-4177-9dff-560c74799beb',
                'namespace' => 'moto',
                'schema_key' => 'MotoScript',
                'version' => '3.1.0',
                'name' => 'Moto Phase 4 — story and script within the film limits (moto-script-v3)',
                'source_file' => 'resources/ai/moto/releases/moto-cinematic-3s-v7/MotoScript-3.1.0.schema.json',
                'dependencies' => '[]',
                'status' => 'published',
                'created_by' => 'db:seed AiPhaseSeeder',
                'schema_content' => $schema,
                'schema_sha256' => hash('sha256', $schema),
                'created_at' => $at,
                'json_schema_id' => $decoded['$id'],
                'json_schema_dialect' => $decoded['$schema'],
                'change_note' => 'MotoScript 3.1.0: như 3.0.0, format moto-script-v3 giữ nguyên; story thêm theme, message, audience, emotional_goal, client (character, want, reason, change, want_beat, change_beat) và motorcycle (from, to, shape_beat); obstacles tối đa một. 3.0.0 giữ làm lịch sử.',
                'published_at' => $at,
            ],
        ],
        'phases' => [
            [
                'category' => 'moto',
                'id' => 'bc9cd138-32a6-4df1-be68-2db1324c5cbc',
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-4',
                'revision' => 19,
                'kind' => 'phase',
                'name' => 'Phase 4 — Story and script within the film limits',
                'sort_order' => 4,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => '52a23676-24cf-415a-b333-a4f922d4f9ec',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 4 moto revision 19 (release moto-cinematic-3s-v7; format moto-script-v3 giữ nguyên, đầu vào moto-script-input-v6, schema MotoScript@3.1.0; nền là revision 18): nhận variables.film (phần chính sau edit, số shot nguồn, trần sự kiện của kịch bản, trần sự kiện và số người của một shot) do code khai, là mức trần chứ không phải mục tiêu; story thêm chủ đề, thông điệp, khán giả, cảm xúc, tuyến khách hàng và tuyến chiếc xe; trở ngại không bắt buộc, tối đa một; hook do khâu dựng chọn từ nguyên liệu, không phải beat; build state là nguồn — giữ ID, thứ tự, vai, kết quả, AI kể bằng hành động tiêu biểu và bước nhảy thời gian giữa các beat; mỗi sự kiện không quá số người một shot hiện được; thêm mục tự rà trước khi trả.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            [
                'id' => 'dba69a16-378a-4e8e-8caa-a30e034925a0',
                'phase_id' => 'bc9cd138-32a6-4df1-be68-2db1324c5cbc',
                'schema_id' => '90f0c0e8-c75a-4177-9dff-560c74799beb',
                'role' => 'output',
                'slot_key' => 'script',
                'data_pointer' => '',
                'validation_mode' => 'whole',
                'is_required' => 1,
                'created_at' => $at,
            ],
        ],
    ];
})();
