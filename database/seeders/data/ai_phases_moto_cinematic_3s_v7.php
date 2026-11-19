<?php

/**
 * @return array{schemas: list<array<string, mixed>>, phases: list<array<string, mixed>>, links: list<array<string, mixed>>} the revisions written for release moto-cinematic-3s-v7: a design of seven stages whose physical stages pass through checkpoints (MotoDesign 3.0.0, moto-design-v3) and the revisions of the later phases that read and carry those checkpoints; which release takes which revision is the catalogue of each release in config/video.php, never this file
 */
$dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v7/';
$at = '2026-11-18 00:00:00';
$read = static function (string $file) use ($dir): string {
    $content = file_get_contents($dir.$file);

    if ($content === false || trim($content) === '') {
        throw new RuntimeException('Release moto-cinematic-3s-v7: '.$file.' is missing or empty.');
    }

    return str_replace("\r\n", "\n", $content);
};

$entries = [
    [
        'code' => 'phase-3',
        'revision' => 15,
        'phase_id' => '8fb60aad-bb7a-4940-91dd-6e98fc9222fd',
        'based_on_id' => '05108488-93bd-4ff9-9077-ed6856570130',
        'name' => 'Phase 3 — Custom design in seven stages (Harley-Davidson custom)',
        'sort_order' => 3,
        'prompt' => 'phase-3-rev15.md',
        'note' => 'Phase 3 moto revision 15 (release moto-cinematic-3s-v7, format moto-design-v3, đầu vào moto-design-input-v2, schema MotoDesign@3.0.0): đúng 7 build state BS01–BS07 — BS01 model (phác thảo, bản vẽ, mô hình), BS02 handover (duyệt, bàn giao), BS03–BS05 build, BS06–BS07 finished; mỗi bước vật lý có danh sách checkpoint, mỗi checkpoint là trạng thái đã xác lập tại mốc với surfaces, in_progress, exceptions, temporary, surface đầy đủ, không kế thừa ngầm; from_state/until_state của bộ phận, installed/operable của đèn, from_state của tính năng và trang trí trỏ checkpoint; quy tắc nhận diện trỏ BS01/BS02 hoặc checkpoint; refs giữ đủ, không ép trần; BS07 không có thiết bị xưởng; không viết shot, góc máy hay thời lượng.',
        'schema_id' => 'f6005287-dbc4-4408-9aa8-40e59da93c7d',
        'link_id' => 'a347dcdc-aa0e-4788-9790-9b3062e8bfab',
        'schema_key' => 'MotoDesign',
        'schema_version' => '3.0.0',
        'schema_file' => 'MotoDesign-3.0.0.schema.json',
        'schema_name' => 'Moto Phase 3 — custom design in seven stages with checkpoints (moto-design-v3)',
        'schema_note' => 'MotoDesign 3.0.0: format moto-design-v3; build_states đúng 7 vị trí cố định (BS01 model có model_medium, BS02 handover, BS03–BS05 build, BS06–BS07 finished); bước vật lý có checkpoints (ít nhất một, id <stage>-CPnn) giữ surfaces, in_progress, exceptions, temporary, surface; mọi mốc trạng thái trỏ checkpoint.',
        'slot' => 'design',
    ],
    [
        'code' => 'phase-4',
        'revision' => 18,
        'phase_id' => '52a23676-24cf-415a-b333-a4f922d4f9ec',
        'based_on_id' => '74a35d38-c0be-44ab-ad8c-6fc3f749630a',
        'name' => 'Phase 4 — Story and script over seven stages',
        'sort_order' => 4,
        'prompt' => 'phase-4-rev18.md',
        'note' => 'Phase 4 moto revision 18 (release moto-cinematic-3s-v7, format moto-script-v3, đầu vào moto-script-input-v5, schema MotoScript@3.0.0): đọc thiết kế 7 bước; film_facts.stages theo bước (kind, checkpoints, must_name chung của bước) và film_facts.states theo checkpoint; mỗi beat trên bước vật lý khai checkpoint_start và checkpoint_end của chính bước đó, beat không đổi trạng thái thì đầu và cuối trùng, null ở BS01–BS02; Idea là BS01–BS02, Fabrication là BS03–BS05; độ phủ vẫn theo build state.',
        'schema_id' => '10974c22-4a81-4079-b88b-070c7a1cea56',
        'link_id' => 'ec07273b-d53f-437d-8a99-833e60d38d5d',
        'schema_key' => 'MotoScript',
        'schema_version' => '3.0.0',
        'schema_file' => 'MotoScript-3.0.0.schema.json',
        'schema_name' => 'Moto Phase 4 — story and script (moto-script-v3)',
        'schema_note' => 'MotoScript 3.0.0: format moto-script-v3; state BS01–BS07; mỗi beat thêm checkpoint_start, checkpoint_end (checkpoint hoặc null).',
        'slot' => 'script',
    ],
    [
        'code' => 'phase-5',
        'revision' => 5,
        'phase_id' => '1dc551f8-1cce-48a1-9309-9d49defa1359',
        'based_on_id' => '490f0ef2-97ab-471d-ab17-7fb1d17155aa',
        'name' => 'Phase 5 — Locations and sets',
        'sort_order' => 5,
        'prompt' => 'phase-5-rev5.md',
        'note' => 'Phase 5 moto revision 5 (release moto-cinematic-3s-v7, format moto-locations-v2, đầu vào moto-locations-input-v3, schema MotoLocations@2.0.0): beat mang checkpoint đầu/cuối và thiết bị tạm của đúng các checkpoint đó; build_states là các bước thiết kế rồi từng checkpoint với stage và label; support của plate chỉ lấy thiết bị của beat, giá đỡ đoạn kiểm tra không xuất hiện khi chạy xe.',
        'schema_id' => '194164a6-3ad1-4944-a4d2-62fb6eaf2ba3',
        'link_id' => 'ac899c53-d780-43c7-a942-5b170f383707',
        'schema_key' => 'MotoLocations',
        'schema_version' => '2.0.0',
        'schema_file' => '../moto-cinematic-3s-v1/MotoLocations-2.0.0.schema.json',
        'schema_name' => 'Moto Phase 5 — locations and background plates (moto-locations-v2)',
        'schema_note' => 'MotoLocations 2.0.0: format moto-locations-v2; mỗi location thêm spatial_layers, subject_zone, entry_routes, camera_access, fixed_landmarks.',
        'slot' => 'locations',
    ],
    [
        'code' => 'phase-6',
        'revision' => 12,
        'phase_id' => '6e0cf7fe-a4fc-4bfd-a54d-cbf96727bf82',
        'based_on_id' => 'fdb14b6f-b144-4498-a45d-1902fcaf8992',
        'name' => 'Phase 6 — Scenes with tracked work over checkpoints',
        'sort_order' => 6,
        'prompt' => 'phase-6-rev12.md',
        'note' => 'Phase 6 moto revision 12 (release moto-cinematic-3s-v7, format moto-scenes-v3, đầu vào moto-scenes-input-v5, schema MotoScenes@3.0.1): như revision 11, đọc beat có checkpoint đầu/cuối và design_states theo checkpoint; trạng thái của beat là checkpoint nó mở đầu, kết quả là checkpoint cuối; scene giữ một build state, từng beat giữ checkpoint riêng, không làm phẳng.',
        'schema_id' => '10c845a5-3f62-4898-bca8-a82ad55fea6b',
        'link_id' => '16d9ae9b-4422-4acf-91a3-0b9e10266e5d',
        'schema_key' => 'MotoScenes',
        'schema_version' => '3.0.1',
        'schema_file' => 'MotoScenes-3.0.1.schema.json',
        'schema_name' => 'Moto Phase 6 — scenes, continuity and tracked work (moto-scenes-v3)',
        'schema_note' => 'MotoScenes 3.0.1: như 3.0.0, mô tả lights_on không còn ghi dải BS06–BS12.',
        'slot' => 'scenes',
    ],
    [
        'code' => 'phase-7',
        'revision' => 17,
        'phase_id' => 'eda5dc96-fa97-4526-8125-aba2db31a5b7',
        'based_on_id' => '684bce34-5e39-4878-ad79-2ee7bfb5b2f0',
        'name' => 'Phase 7 — How each scene is filmed, over checkpoints',
        'sort_order' => 7,
        'prompt' => 'phase-7-rev17.md',
        'note' => 'Phase 7 moto revision 17 (release moto-cinematic-3s-v7, chính sách full-coverage-budget-v1, format moto-shots-v3, đầu vào moto-shots-input-v10, schema MotoShots@3.0.0): như revision 16, mỗi shot ghim checkpoint khung đầu và khung cuối trong dải của các beat nó phủ; shot giữa một quá trình không lấy trạng thái cuối của quá trình; thiếu checkpoint cho trạng thái cần thể hiện thì khai xung đột chặn; part và support theo checkpoint của shot.',
        'schema_id' => '213e1afe-f1a3-4cb2-81e0-94f7d2e916e3',
        'link_id' => 'de34d1b5-4cb9-4127-b9a2-534076120221',
        'schema_key' => 'MotoShots',
        'schema_version' => '3.0.0',
        'schema_file' => 'MotoShots-3.0.0.schema.json',
        'schema_name' => 'Moto Phase 7 — full-coverage shot plan over checkpoints (moto-shots-v3)',
        'schema_note' => 'MotoShots 3.0.0: như 2.3.0, format moto-shots-v3; mỗi shot thêm checkpoint_start, checkpoint_end (checkpoint hoặc null).',
        'slot' => 'shots',
    ],
    [
        'code' => 'phase-10',
        'revision' => 20,
        'phase_id' => 'a07e92ea-c82a-4e36-a644-32ee6e20a29b',
        'based_on_id' => 'cc853d95-4399-4d5f-9b80-99e1082eaa96',
        'name' => 'Phase 10 — Video prompt writer',
        'sort_order' => 10,
        'prompt' => 'phase-10-rev20.md',
        'note' => 'Phase 10 moto revision 20 (release moto-cinematic-3s-v7, đầu vào moto-clip-prompt-input-v9; hai schema đầu ra không đổi): như revision 19, design_context có thêm build_state_end — checkpoint khung cuối, cạnh build_state là checkpoint khung đầu; clip chỉ tới trạng thái cuối khi timeline diễn công việc dẫn tới đó, không bao giờ ở 0 ms.',
        'schema_id' => '03a9ca35-5b38-46a0-a978-658bfd56a2da',
        'link_id' => '344dc555-547c-4e01-9178-b98d20593ba0',
        'schema_key' => 'MotoClipPlan',
        'schema_version' => '2.0.0',
        'schema_file' => '../moto-cinematic-3s-v1/MotoClipPlan-2.0.0.schema.json',
        'schema_name' => 'Moto clip plan (moto-clip-plan-v2)',
        'schema_note' => 'MotoClipPlan 2.0.0: format moto-clip-plan-v2; render.mode reference_to_video, render 3000 ms, used 0–3000 ms; input keyframe luôn bị chặn; giữ nhánh đọc 1.x cho dự án cũ.',
        'slot' => 'clip_plan',
        'links' => [
            ['id' => '29d26e34-4873-4c6a-ae55-a6f27ae148c1', 'schema_id' => 'cec087c0-78f7-446c-a980-cb1a784bfd40', 'slot' => 'clip_prompt'],
        ],
    ],
];

$data = ['schemas' => [], 'phases' => [], 'links' => []];

foreach ($entries as $entry) {
    $prompt = $read($entry['prompt']);
    $data['phases'][] = [
        'category' => 'moto',
        'id' => $entry['phase_id'],
        'workflow_code' => 'harley_custom_v1',
        'phase_code' => $entry['code'],
        'revision' => $entry['revision'],
        'kind' => 'phase',
        'name' => $entry['name'],
        'sort_order' => $entry['sort_order'],
        'prompt_content' => $prompt,
        'content_sha256' => hash('sha256', $prompt),
        'needs' => null,
        'parallel_with' => null,
        'stop' => null,
        'status' => 'published',
        'is_current' => ($entry['current'] ?? true) ? 1 : 0,
        'based_on_id' => $entry['based_on_id'],
        'source_file' => null,
        'source_section_key' => null,
        'change_note' => $entry['note'],
        'created_by' => 'db:seed AiPhaseSeeder',
        'created_at' => $at,
        'published_at' => $at,
    ];

    foreach ($entry['links'] ?? [] as $link) {
        $data['links'][] = ['id' => $link['id'], 'phase_id' => $entry['phase_id'], 'schema_id' => $link['schema_id'], 'role' => 'output', 'slot_key' => $link['slot'], 'data_pointer' => '', 'validation_mode' => 'whole', 'is_required' => 1, 'created_at' => $at];
    }

    $schema = $read($entry['schema_file']);
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);
    $data['schemas'][] = [
        'id' => $entry['schema_id'],
        'namespace' => 'moto',
        'schema_key' => $entry['schema_key'],
        'version' => $entry['schema_version'],
        'name' => $entry['schema_name'],
        'source_file' => 'resources/ai/moto/releases/'.ltrim(str_replace('../', '', str_starts_with($entry['schema_file'], '../') ? $entry['schema_file'] : 'moto-cinematic-3s-v7/'.$entry['schema_file']), '/'),
        'dependencies' => '[]',
        'status' => 'published',
        'created_by' => 'db:seed AiPhaseSeeder',
        'schema_content' => $schema,
        'schema_sha256' => hash('sha256', $schema),
        'created_at' => $at,
        'json_schema_id' => $decoded['$id'],
        'json_schema_dialect' => $decoded['$schema'],
        'change_note' => $entry['schema_note'],
        'published_at' => $at,
    ];
    $data['links'][] = ['id' => $entry['link_id'], 'phase_id' => $entry['phase_id'], 'schema_id' => $entry['schema_id'], 'role' => 'output', 'slot_key' => $entry['slot'], 'data_pointer' => '', 'validation_mode' => 'whole', 'is_required' => 1, 'created_at' => $at];
}

return $data;
