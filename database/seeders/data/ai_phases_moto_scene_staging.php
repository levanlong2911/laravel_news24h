<?php

return (static function (): array {
    $at = '2026-11-23 18:00:00';
    $dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v9/';
    $read = static function (string $file) use ($dir): string {
        $content = file_get_contents($dir.$file);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Moto scene staging release: '.$file.' is missing or empty.');
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
    $schema = $read('MotoScenes-3.1.0.schema.json');
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);

    return [
        'schemas' => [
            [
                'id' => 'd555d28b-ac43-4703-9f1b-0ac58b636fbc',
                'namespace' => 'moto',
                'schema_key' => 'MotoScenes',
                'version' => '3.1.0',
                'name' => 'Moto Phase 6 — staged scenes with pace and emotion, continuity and tracked work (moto-scenes-v3)',
                'source_file' => 'resources/ai/moto/releases/moto-cinematic-3s-v9/MotoScenes-3.1.0.schema.json',
                'dependencies' => '[]',
                'status' => 'published',
                'created_by' => 'db:seed AiPhaseSeeder',
                'schema_content' => $schema,
                'schema_sha256' => hash('sha256', $schema),
                'created_at' => $at,
                'json_schema_id' => $decoded['$id'],
                'json_schema_dialect' => $decoded['$schema'],
                'change_note' => 'MotoScenes 3.1.0: format moto-scenes-v3 giữ nguyên; mỗi scene bắt buộc thêm pace (slow, steady, brisk — nhịp hành động, không phải thời lượng) và emotion gồm start, end (cảm xúc khán giả lúc mở và kết scene, hai giá trị có thể giống nhau). Mô tả blocking nêu đủ sáu phần: mở cảnh, hướng và hướng nhìn, di chuyển, tương tác, vật đỡ, kết cảnh; vẫn là một chuỗi. Sửa mô tả conflict cho khớp prompt: một beat dùng nhiều địa điểm hay nhiều ảnh nền không tự là xung đột. Các trường còn lại như 3.0.1; 3.0.1 giữ làm lịch sử.',
                'published_at' => $at,
            ],
        ],
        'phases' => [
            $phase('bec663ef-c77c-4c7d-ac26-466da297c2a2', 'phase-6', 15, 'Phase 6 — Scenes staged in seven steps, with pace and emotion', 6, 'phase-6-rev15.md', '964ad837-596e-400c-8ea5-553b6db77c31',
                'Phase 6 moto revision 15 (release moto-cinematic-3s-v9; format moto-scenes-v3, schema MotoScenes@3.1.0, đầu vào moto-scenes-input-v8; nền là revision 14): viết lại thành bảy bước — đọc nguồn, chia scene, mục tiêu-nhịp-cảm xúc, dàn dựng, theo dõi trạng thái và work, nối cảnh, tự rà rồi xuất JSON. Bổ sung pace và emotion (start, end) cho mỗi scene, đọc từ mục đích beat và theme, spine, emotional_goal của kịch bản. Blocking vẫn là một đoạn văn, viết theo thứ tự mở cảnh → hướng và hướng nhìn → di chuyển → tương tác → vật đỡ → kết cảnh; không đặt người vào đầu cảnh khi họ vào sau, không đưa xe thật vào cảnh chỉ có bản vẽ hay mô hình, không dời vật cố định. Đầu vào thêm kích thước và hệ toạ độ của địa điểm, footprint từng vùng, bề rộng lối vào, footprint và hướng đặt của vật thể; giá trị null là chưa biết, không tự điền. Phần Work giữ nguyên văn revision 14. Các phần còn lại rút bằng cách bỏ câu trùng với mô tả trong schema; quy tắc chia scene, checkpoint, continuity và chuyển cảnh không đổi.'),
            $phase('66afaccc-7253-4ff0-ab0c-d26c30a09c2f', 'phase-7', 21, 'Phase 7 — How each scene is filmed, with its pace and emotion', 7, 'phase-7-rev21.md', '35a000dd-521c-45b2-99d7-95bcd5bcaacf',
                'Phase 7 moto revision 21 (release moto-cinematic-3s-v9; chính sách full-coverage-budget-v1, format moto-shots-v3 và schema MotoShots@3.0.0 giữ nguyên, đầu vào moto-shots-input-v14; nền là revision 20): mỗi scene nhận thêm pace và emotion (start, end) từ bản chia scene; dùng chúng để quyết định cách quay — tốc độ máy quay và hành động, lượng nội dung một shot giữ, điều hình ảnh nhấn mạnh; không đổi sự kiện, blocking, ngân sách shot, ba giây của shot hay số người một shot được có; không thể hiện được trong các giới hạn đó thì ghi vào conflicts, dẫn pace hoặc emotion của scene làm bằng chứng. Thêm một dòng tự rà tương ứng. Mọi phần khác như revision 20.'),
        ],
        'links' => [
            $link('38375724-f3b2-4986-a684-a70d0026c2c3', 'bec663ef-c77c-4c7d-ac26-466da297c2a2', 'd555d28b-ac43-4703-9f1b-0ac58b636fbc', 'scenes'),
            $link('08583000-fd0f-4108-90b0-0f4e6d54d111', '66afaccc-7253-4ff0-ab0c-d26c30a09c2f', '213e1afe-f1a3-4cb2-81e0-94f7d2e916e3', 'shots'),
        ],
    ];
})();
