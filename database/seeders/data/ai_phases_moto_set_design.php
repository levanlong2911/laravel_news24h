<?php

return (static function (): array {
    $at = '2026-11-21 18:00:00';
    $dir = dirname(__DIR__, 3).'/resources/ai/moto/releases/moto-cinematic-3s-v8/';
    $read = static function (string $file) use ($dir): string {
        $content = file_get_contents($dir.$file);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException('Moto set design release: '.$file.' is missing or empty.');
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
    $schema = $read('MotoLocations-4.0.0.schema.json');
    $decoded = json_decode($schema, true, 512, JSON_THROW_ON_ERROR);

    return [
        'schemas' => [
            [
                'id' => '0449bd1b-6f0e-4f64-8d36-bb85950df795',
                'namespace' => 'moto',
                'schema_key' => 'MotoLocations',
                'version' => '4.0.0',
                'name' => 'Moto Phase 5 — set design dossier and background plates (moto-locations-v4)',
                'source_file' => 'resources/ai/moto/releases/moto-cinematic-3s-v8/MotoLocations-4.0.0.schema.json',
                'dependencies' => '[]',
                'status' => 'published',
                'created_by' => 'db:seed AiPhaseSeeder',
                'schema_content' => $schema,
                'schema_sha256' => hash('sha256', $schema),
                'created_at' => $at,
                'json_schema_id' => $decoded['$id'],
                'json_schema_dialect' => $decoded['$schema'],
                'change_note' => 'MotoLocations 4.0.0: format moto-locations-v4; mỗi địa điểm có set_design là nguồn duy nhất cho kiến trúc (AR01…, vật liệu, màu riêng, lớp hoàn thiện, độ cũ, độ sạch, bề mặt, mốc) và vật thể (OB01…, công dụng, vai trò, cố định hay không, vùng, vị trí, hướng, footprint, chiều cao, trạng thái chuẩn), thay cho surfaces, fixed_equipment, palette, spatial_layers và fixed_landmarks dạng chữ; mỗi ảnh nền có camera (vùng, vị trí, điểm, chiều cao, hướng nhìn, tiêu cự, khung), lighting_plan (nguồn sáng LS01… với vai trò, hướng, mềm/cứng, cường độ tương đối, tông màu, nhiệt độ màu khi có nguồn, trong hay ngoài khung; vùng sáng, vùng tối, độ sáng của chủ thể), visible và set_state (chỉ phần khác trạng thái chuẩn); continuity khai same_layout, same_camera và may_change. 3.0.0 và 2.0.0 giữ làm lịch sử.',
                'published_at' => $at,
            ],
        ],
        'phases' => [
            $phase('089ad916-c207-4b5d-bd7e-e22a3e5a501d', 'phase-5', 8, 'Phase 5 — Set design dossier and plates', 5, 'phase-5-rev8.md', '1e1c0b05-5000-4e70-b12f-0481bea68f8a',
                'Phase 5 moto revision 8 (release moto-cinematic-3s-v8; format moto-locations-v4, đầu vào moto-locations-input-v5, schema MotoLocations@4.0.0; nền là revision 7): giữ bảy bước, bổ sung nhiệm vụ thiết kế bối cảnh còn thiếu — set_design là nguồn duy nhất cho kiến trúc, vật liệu, độ cũ, độ sạch, bề mặt và vật thể có vị trí, hướng, trạng thái chuẩn; mỗi ảnh nền khai camera, lighting_plan (nguồn sáng, vùng sáng/tối, độ sáng chủ thể), visible và set_state chỉ gồm phần khác trạng thái chuẩn; continuity khai rõ giữ bố trí, giữ camera và những gì được phép đổi; mọi dữ kiện một nguồn, code ghép mô tả từ trường cấu trúc; nguồn sáng ngoài khung không thành đèn trong ảnh, màu vật liệu không đổi theo ánh sáng. Phân loại thông tin nguồn: source_flags không tự thành conflict — conflict chỉ dành cho hai yêu cầu nguồn không thể cùng đáp ứng hoặc dữ kiện thiếu ảnh hưởng tới bố trí, hành động; chuyển thời điểm có chủ ý, có nguồn giải thích thì khai ở điều kiện từng ảnh nền và continuity (may_change light, time_of_day, basis script kèm câu trích), không dùng may_change để hợp thức hoá thay đổi nguồn không cho phép; ở beat representation, hình vẽ, bản in hay mô hình số của một bộ phận không chiếm chỗ theo kích thước thật và thiếu kích thước thật không phải missing_information, còn vật thật mang hình (màn hình, bảng vẽ, bàn, bề mặt đặt bản in) và mô hình vật lý vẫn được bố trí; bộ phận thiết kế đã bỏ chỉ là phương án gạch bỏ trên bản vẽ, đầu vào không liệt kê nó làm chủ thể ở beat đó. Trong continuity, objects của may_change là toàn bộ trạng thái của set (hiện diện, vị trí, hướng, tình trạng của vật thể và tình trạng của thành phần kiến trúc); same_layout chỉ nói về chỗ đứng của vật thể — đổi tình trạng thì liệt kê objects mà vẫn giữ được bố trí, còn đã khai same_layout thì vật thể không đổi chỗ, đổi hướng hay thêm bớt. Camera không được liệt kê thì các ảnh nền khai cùng dữ liệu camera. Giữa hai khung của một địa điểm, ánh sáng giống nhau khi các nguồn giống nhau (loại, nơi phát, chất, cường độ, tông, nhiệt độ màu); vai trò, hướng rơi theo khung, đèn có trong khung hay không, vùng sáng/tối và độ sáng chủ thể đi theo camera nên không cần liệt kê light; giá trị giữ nguyên thì viết y như nhau ở mọi ảnh nền.'),
            $phase('964ad837-596e-400c-8ea5-553b6db77c31', 'phase-6', 14, 'Phase 6 — Scenes with tracked work, read from the set design', 6, 'phase-6-rev14.md', '163220d9-f275-4cd8-954e-38d2fb9b5a39',
                'Phase 6 moto revision 14 (release moto-cinematic-3s-v8; format moto-scenes-v3 và schema MotoScenes@3.0.1 giữ nguyên, đầu vào moto-scenes-input-v7; nền là revision 13): chỉ đổi phần mô tả dữ liệu địa điểm nhận được — địa điểm có mốc và vật thể của set theo mã (vai trò, cố định, vùng, vị trí, hiện diện ở trạng thái chuẩn), ảnh nền có khí quyển và trạng thái set, continuity khai giữ bố trí hay giữ camera; người làm việc tại vật thể ở đúng chỗ của trạng thái đó, không tự di chuyển hay thêm vật. Làm rõ cách chia scene khi thời gian trôi qua: đổi camera hay ánh sáng trong cùng một thời điểm không buộc tách scene; khi kịch bản cho biết thời gian đã trôi qua (ví dụ ban ngày sang blue hour) thì tách scene dù cùng địa điểm, cùng build state và time_jump none; scene mở sau khoảng thời gian đó có continuity_from null, không dựng lại hành động hay thêm việc để lấp thời gian đã lược; transition time_jump chỉ dùng cho beat có time_jump khác none, trường hợp còn lại dùng cut kèm mô tả và dẫn nguồn; time_jump của kịch bản giữ nguyên.'),
            $phase('35a000dd-521c-45b2-99d7-95bcd5bcaacf', 'phase-7', 20, 'Phase 7 — How each scene is filmed, staged in the set design', 7, 'phase-7-rev20.md', '307c1f0e-ad9b-4565-9318-1601d40bb368',
                'Phase 7 moto revision 20 (release moto-cinematic-3s-v8; chính sách full-coverage-budget-v1, format moto-shots-v3 và schema MotoShots@3.0.0 giữ nguyên, đầu vào moto-shots-input-v13; nền là revision 19): chỉ đổi phần mô tả dữ liệu địa điểm nhận được — địa điểm có set_design (kiến trúc, vật liệu, bề mặt, mốc, vật thể theo mã với vùng, vị trí, hướng, footprint, hiện diện), ảnh nền có camera, lighting_plan, visible, trạng thái set và điều kiện sử dụng, kèm set_continuity; shot mở trên khung của ảnh nền, giữ vật thể đúng chỗ của trạng thái đó, máy quay không đi qua vật thể của set. Cách chia shot và giới hạn của shot không đổi; nói rõ thêm một điều đã có sẵn trong luật nối beat: các beat ở hai bên một khoảng thời gian đã trôi qua (hai scene, hoặc ảnh nền ở hai thời điểm) không bao giờ là một shot liên tục. Danh sách khoá đầu vào nêu đủ set_continuity; đường dẫn bằng chứng chỉ bắt đầu từ các khoá schema MotoShots@3.0.0 cho phép — connections và set_continuity chỉ để đọc, khác biệt giữa hai ảnh nền trích qua backgrounds.<id>.'),
            $phase('b9aa90cf-eac2-428d-8181-a248b03b3d7d', 'phase-10', 22, 'Phase 10 — Video prompt writer, with the light and the set state', 10, 'phase-10-rev22.md', '5e45158a-f9dc-4cd7-abdf-97f84f4d1d49',
                'Phase 10 moto revision 22 (release moto-cinematic-3s-v8; đầu vào moto-clip-prompt-input-v11, hai schema đầu ra không đổi; nền là revision 21): nhóm environment thêm light (nguồn sáng với vai trò, hướng, mềm/cứng, cường độ, tông màu, trong hay ngoài khung; vùng sáng, vùng tối, độ sáng chủ thể) và set_state (tên trạng thái, phần khác trạng thái chuẩn) của ảnh nền trong hình; viết đúng ánh sáng đó, không thêm nguồn, chỉ nêu đèn khi khung có; giữ trạng thái set, không tự di chuyển hay thêm vật. Cách viết prompt video không đổi; nói rõ thêm: một clip ở đúng một thời điểm của ảnh nền nó dùng, không viết thời điểm hay ánh sáng chuyển sang thời điểm khác trong clip.'),
        ],
        'links' => [
            $link('fa49bf33-1667-4c9d-aaf5-350b3725ae9a', '089ad916-c207-4b5d-bd7e-e22a3e5a501d', '0449bd1b-6f0e-4f64-8d36-bb85950df795', 'locations'),
            $link('0d5a5714-b689-4243-bb86-12bc36000ecb', '964ad837-596e-400c-8ea5-553b6db77c31', '10c845a5-3f62-4898-bca8-a82ad55fea6b', 'scenes'),
            $link('99338e2a-3f3a-4d22-9c4b-fdcf73f4b365', '35a000dd-521c-45b2-99d7-95bcd5bcaacf', '213e1afe-f1a3-4cb2-81e0-94f7d2e916e3', 'shots'),
            $link('86b8a42e-acb3-4261-8859-f4c8c5c71476', 'b9aa90cf-eac2-428d-8181-a248b03b3d7d', '03a9ca35-5b38-46a0-a978-658bfd56a2da', 'clip_plan'),
            $link('9d2ac0e6-c358-4747-b9fd-63f1f09203c6', 'b9aa90cf-eac2-428d-8181-a248b03b3d7d', 'cec087c0-78f7-446c-a980-cb1a784bfd40', 'clip_prompt'),
        ],
    ];
})();
