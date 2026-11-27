<?php

return (static function (): array {
    $at = '2026-11-25 12:00:00';
    $dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v9/';
    $read = static function (string $file) use ($dir): string {
        $content = file_get_contents($dir.$file);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Moto video prompt revision 23: '.$file.' is missing or empty.');
        }

        return str_replace("\r\n", "\n", $content);
    };
    $schema = static function (string $id, string $key, string $version, string $file, string $name, string $note) use ($read, $at): array {
        $content = $read($file);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return [
            'id' => $id,
            'namespace' => 'moto',
            'schema_key' => $key,
            'version' => $version,
            'name' => $name,
            'source_file' => 'resources/ai/moto/releases/moto-cinematic-3s-v9/'.$file,
            'dependencies' => '[]',
            'status' => 'published',
            'created_by' => 'db:seed AiPhaseSeeder',
            'schema_content' => $content,
            'schema_sha256' => hash('sha256', $content),
            'created_at' => $at,
            'json_schema_id' => $decoded['$id'],
            'json_schema_dialect' => $decoded['$schema'],
            'change_note' => $note,
            'published_at' => $at,
        ];
    };
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
    $phaseId = '11c1281c-da19-41ca-be10-76a0d35c5a9f';
    $planSchema = '8330ac00-5f19-4b69-8d61-d1fa22769c93';
    $promptSchema = '8e0da8b5-5e72-490d-b9db-5a2d68986fbe';
    $prompt = $read('phase-10-rev23.md');

    return [
        'schemas' => [
            $schema($planSchema, 'MotoClipPlan', '3.0.0', 'MotoClipPlan-3.0.0.schema.json', 'Moto Phase 10 — clip plan under an execution policy (moto-clip-plan-v3)',
                'MotoClipPlan 3.0.0: format moto-clip-plan-v3, ghim chính sách thực thi moto-clip-execution-v1; mỗi clip có motion_method (ai_video | dynamic_crop), input_mode (keyframe | references), content_duration_ms, render (cách gửi, transport, task, model hoặc bộ xử lý, adapter, tỷ lệ, resolution, duration_ms là thời lượng file nhỏ nhất model làm được mà phủ nội dung), used, keyframe (bộ ảnh, quyết định duyệt, master 16:9, delivery 9:16, crop), images (ảnh thực gửi theo thứ tự với send_as first_frame | reference | crop_source và hash), crop_plan, audio_policy (allow_silent | require_audio), media_policy v2, settings (phiên bản cấu trúc, revision) và danh sách lý do chặn mới (model, resolution, âm thanh, crop, chọn ảnh). 2.0.0 và 1.6.0 giữ làm lịch sử.'),
            $schema($promptSchema, 'MotoClipPrompt', '1.0.1', 'MotoClipPrompt-1.0.1.schema.json', 'Moto Phase 10 — video prompt (moto-clip-prompt-v1), schema 1.0.1',
                'MotoClipPrompt 1.0.1: cùng trường và luật với 1.0.0, chỉ sửa mô tả cho đúng cả hai cách làm clip — keyframe đã duyệt là khung đầu khi clip mở từ bộ keyframe, ảnh tham chiếu khi model dựng hình mở đầu; code kiểm thêm các dòng Camera/Action có mốc giờ. 1.0.0 giữ làm lịch sử.'),
        ],
        'phases' => [
            [
                'category' => 'moto',
                'id' => $phaseId,
                'workflow_code' => 'harley_custom_v1',
                'phase_code' => 'phase-10',
                'revision' => 23,
                'kind' => 'phase',
                'name' => 'Phase 10 — Video prompt writer, by keyframe or references, on the approved timeline',
                'sort_order' => 10,
                'prompt_content' => $prompt,
                'content_sha256' => hash('sha256', $prompt),
                'needs' => null,
                'parallel_with' => null,
                'stop' => null,
                'status' => 'published',
                'is_current' => 1,
                'based_on_id' => 'b9aa90cf-eac2-428d-8181-a248b03b3d7d',
                'source_file' => null,
                'source_section_key' => null,
                'change_note' => 'Phase 10 moto revision 23 (release moto-cinematic-3s-v9 ghim bằng catalogue; đầu vào moto-clip-prompt-input-v12, format moto-clip-prompt-v1 giữ nguyên, schema MotoClipPrompt@1.0.1 + MotoClipPlan@3.0.0; nền là revision 22, vẫn là bản ghim của release v8): viết gọn sáu phần — nhiệm vụ và trách nhiệm nguồn; cách dùng ảnh theo chế độ (keyframe: ảnh là khung mở đầu, tập trung chuyển động từ hình đó; references: nói rõ mỗi ảnh cho gì, không đưa grid/góc lặp vào video); hình mở đầu và trạng thái; camera và hành động theo timeline với hai dạng dòng Camera [a–b s]: và Action [a–b s]: mà code đối chiếu số dòng, thứ tự và mốc giờ; trạng thái cuối, nhận diện, mức lộ mặt và âm thanh; gaps, conflicts, flags và JSON trả về. Đầu vào thêm nhịp và cảm xúc của scene (chỉ để diễn đạt chuyển động quan sát được), nhóm execution (chế độ, thời lượng nội dung, thời lượng file nguồn, khoảng dùng, khung 9:16, vai trò first frame/reference của từng ảnh thực gửi); file nguồn dài hơn thì nội dung hoàn tất trong phần được dùng, phần dư giữ trạng thái, không thêm sự kiện. is_current = 1: mọi release của harley_custom_v1 ghim Phase 10 bằng catalogue (v1…v9) hoặc khoảng 1–11 (legacy), không release nào đọc bản hiện hành.',
                'created_by' => 'db:seed AiPhaseSeeder',
                'created_at' => $at,
                'published_at' => $at,
            ],
        ],
        'links' => [
            $link('f99eaff5-f824-4881-a87f-42d1fb20010a', $phaseId, $planSchema, 'clip_plan'),
            $link('c2a2e11e-545e-491d-a52a-c2df16ec14a9', $phaseId, $promptSchema, 'clip_prompt'),
        ],
    ];
})();
