<?php

return [
    /**
     * Bump thủ công khi RenderPlan schema/pipeline thay đổi đáng kể (không tự
     * động lấy git sha — CI/Docker/release zip có thể không có .git). Dùng để
     * đối chiếu 2 lần chạy `video:benchmark` có cùng phiên bản pipeline không.
     */
    'pipeline_version' => '2026.07.22',

    /**
     * Tran chi phi cho MOT cu goi Claude (Extractor/Producer/Director deu tinh
     * rieng) — KHONG phai gioi han tong ca session. Day la tham so VAN HANH:
     * doi tran khong nen phai sua code roi deploy lai, nen no o config chu
     * khong con la hang so trong VideoRenderPlanService.
     *
     * Gia tri nay duoc tiem vao CostCeilingGate, thay cho DenyByDefaultGate mac
     * dinh cua GatedLlmClient — tuc chinh no la "quyen tieu tien". Xem
     * docs/video/ARCHITECTURE.md §18.
     */
    'llm_cost_ceiling_usd' => (float) env('VIDEO_LLM_COST_CEILING_USD', 0.05),

    /**
     * Bắn tiến trình Python ở nền sau khi bấm nút (§18.25).
     *
     * `runner_dir` RỖNG = TẮT hẳn việc bắn — hệ thống quay về nếp cũ: session
     * vẫn tạo được, chỉ là phải chạy tay `python tools/session_runner.py`. Đây
     * là mặc định CÓ CHỦ ĐÍCH: máy chưa cấu hình thì không được im lặng thử
     * chạy một đường dẫn không tồn tại rồi báo lỗi khó hiểu.
     *
     * Đường dẫn để ở env chứ không hardcode vì hai repo nằm ở hai chỗ khác
     * nhau trên mỗi máy (repo Python KHÔNG nằm trong cây Laravel).
     */
    'runner' => [
        'python_bin' => env('VIDEO_PYTHON_BIN', 'python'),
        'runner_dir' => env('VIDEO_RUNNER_DIR', ''),

        /**
         * Thư mục log của tiến trình nền. Tiến trình nền KHÔNG có ai nhìn
         * stdout, nên không chuyển hướng ra file là mất sạch dấu vết khi hỏng.
         */
        'log_dir' => env('VIDEO_RUNNER_LOG_DIR', storage_path('logs/video-runner')),
    ],

    /**
     * EditorialPolicy (§12 Rule #1: data, không phải code) — knowledge world
     * thật, tiêm qua constructor EditorialInterpreter. Mặc định TRỐNG cho tới
     * khi có bằng chứng thật (§12 lịch sử: "policy thật thêm sau" — không
     * hardcode Feadship/domes vào code, chỉ vào data ở đây).
     *
     * Feadship/domes: bài Moonrise 2025 refit nói "integrated satellite
     * receivers instead of exposed radomes" — domes=true là suy luận (article
     * không nói thẳng), KHÔNG qua nổi Gatekeeper. Đây là editorial taste đã
     * biết trước (world-knowledge), không phải fact — đúng chỗ của Editorial,
     * không phải Truth. Xem docs/video/ARCHITECTURE.md §12.
     */
    'editorial_policies' => [
        [
            'match' => ['builder' => 'Feadship'],
            'prohibit_attribute' => 'domes',
            'prohibit_value' => true,
            'reason' => 'integrated satellite receivers instead of exposed radomes (2025 refit)',
        ],
    ],

    /**
     * Creation Arc v2 (§18.16 ARCHITECTURE.md — ĐỌC TRƯỚC KHI SỬA FILE NÀY).
     *
     * Scene BỊA CÓ CHỦ ĐÍCH (thiết kế → thi công → hoàn thiện → thành phẩm)
     * chèn TRƯỚC scene thật, CHỈ cho category có mặt trong `categories`. Quyết
     * định user 2026-07-24: LUÔN áp dụng khi category khớp, KHÔNG xét bài báo
     * có bằng chứng thi công hay không — ngoại lệ CÓ CHỦ ĐÍCH với "không bằng
     * chứng → không tồn tại", chỉ trong đúng phạm vi này.
     *
     * Quyết định user 2026-07-26: hình dáng/màu sắc ĐƯỢC PHÉP bịa, ràng buộc
     * duy nhất là BẤT BIẾN xuyên các pha. Vì thế chúng là lựa chọn EDITORIAL,
     * không phải Truth claim — và thứ khóa được chúng là ẢNH NEO (Flux), không
     * phải chữ (§18.16: 3 clip nhận cùng câu nhận dạng vẫn ra 3 con tàu khác
     * nhau — chữ không đủ độ phân giải để khóa silhouette).
     *
     * v1 → v2: viết lại theo 9 ẢNH TƯ LIỆU THẬT của một lượt đóng du thuyền.
     * v1 mô tả SAI ngành ở 2/3 pha — nặng nhất là gộp nhầm "hàn trên sườn thép
     * trần" (đầu quy trình) với "mài vỏ đã sơn" (cuối quy trình), hai giai đoạn
     * cách nhau cả năm. Đừng viết lại các câu dưới đây bằng cách ĐOÁN; sửa
     * chúng phải dựa trên tư liệu thật của đúng ngành đó.
     *
     * MỌI câu mô tả chuyển động phải theo Observable/Measurable Behavior
     * (§18.12): trả lời được "điều gì ĐO ĐƯỢC đã đổi khác giữa frame đầu và
     * frame cuối" (khoảng cách thu lại, vệt hoàn thiện dài thêm, khe hở khép
     * lại) — KHÔNG dùng động từ diễn giải ý định ("guides", "works",
     * "hand-finishes"). Bằng chứng render §18.15: câu tả tia lửa ("spray
     * growing denser") KHÔNG tạo tiến triển; câu tả kết quả còn lại ("a new
     * line segment appears") thì CÓ.
     *
     * render_strategy: IMAGE = cần ảnh neo (Flux/Kontext) rồi i2v; VIDEO =
     * text-to-video thẳng. Chưa có code đọc field này — consumer đã lên kế
     * hoạch là bước lưu ảnh neo cấp session (§18.16 mục "Thứ tự thi công" #4).
     * Python hiện phân biệt bằng scene.id.
     */
    'creation_arc' => [
        /**
         * category slug (bảng `categories` của CMS) → khoá trong `phase_sets`.
         * cars/moto CỐ TÌNH VẮNG: chưa có tư liệu thật cho hai ngành đó, viết
         * template bằng cách đoán là lặp lại đúng sai lầm của v1. Category
         * không có mặt ở đây thì Creation Arc không kích hoạt và RenderPlan
         * giữ nguyên — hành vi đã có sẵn, không cần code thêm.
         */
        'categories' => [
            'yacht' => 'vessel',
            'superyacht' => 'vessel',
            // 2026-07-31: cars/moto DÙNG CHUNG `restoration` (quyết định user).
            // Trước đó cố tình vắng vì thiếu tư liệu thật — nay đã có (§18.26).
            // Tháo lắp xe máy khác ô tô, nhưng CẤU TRÚC 7 pha thì chung; khác
            // biệt để trong `composition_note`, không tách phase_set.
            'cars' => 'restoration',
            'moto' => 'restoration',
        ],

        'phase_sets' => [
            /**
             * Đóng tàu — nguồn: 9 ảnh tư liệu thật (§18.16).
             *
             * Mỗi phase_set có 2 khoá:
             *   identity — nhận dạng thị giác cấp VIDEO, 2 trạng thái vòng đời
             *   phases   — các scene
             */
            'vessel' => [

                /**
                 * CREATIVE IDENTITY — đi thẳng vào RenderPlan.creative_identity
                 * (§18.17). Đây KHÔNG phải Truth: bài báo hầu như không bao giờ nói
                 * màu vỏ hay số tầng (bài "The Sixth Sense" chỉ cho vessel_type +
                 * length). Được phép bịa, ràng buộc duy nhất là BẤT BIẾN xuyên các
                 * scene — đó là lý do nó nằm ở MỘT chỗ duy nhất và không được viết
                 * lại ở bất kỳ composition_note nào.
                 *
                 * Bằng chứng phải có cơ chế này: 3 clip nhận đúng cùng câu nhận
                 * dạng từ Truth mà vẫn ra 3 con tàu khác nhau (§18.15) — chữ từ
                 * Truth quá nghèo để khóa hình.
                 *
                 * Vì sao DARK NAVY chứ không phải silver/pearl white (3 tài liệu đề
                 * xuất mỗi cái một màu): trong xưởng vỏ tàu VỐN ĐÃ là kim loại xám,
                 * nên thành phẩm màu bạc khiến trạng thái đầu và cuối trông như
                 * nhau — giết luôn cảm giác thời gian trôi. Navy cho tương phản tối
                 * đa với primer.
                 *
                 * Hai trạng thái là TIME CUE, không phải mâu thuẫn: cùng một con
                 * tàu, khác giai đoạn.
                 *
                 * KHÔNG NÊU CHIỀU DÀI ở đây — sửa 2026-07-31. Hai câu này từng
                 * ghi "the same 74-metre luxury motor yacht", con số của bài
                 * "The Sixth Sense", rồi áp cho MỌI bài yacht. Bài Matilde 7 dài
                 * 33,5m (Truth trích được `length_metres: 33.5`, tiêu đề ghi
                 * "Seamore 34") mà prompt vẫn nói 74m — SAI HƠN GẤP ĐÔI, ở cả
                 * scene 2 lẫn scene 5.
                 *
                 * Khác màu vỏ/số tầng: những thứ đó bài báo hầu như không bao
                 * giờ nói nên bịa là hợp lệ (§18.16). Chiều dài thì NGƯỢC LẠI —
                 * nó gần như luôn có trong bài, đã qua Gatekeeper, và là sự thật
                 * ĐO ĐƯỢC. Bịa đè lên một fact đã verify là phá đúng nguyên tắc
                 * mà cả Truth Layer dựng lên để bảo vệ.
                 *
                 * Muốn có kích thước trong prompt thì để compiler nối từ Truth
                 * (`entity_identity_facts`), không viết tay ở đây.
                 */
                'identity' => [
                    'construction' => [
                        'visual_identity' => 'the same luxury motor yacht, hull still bare grey steel with visible weld seams, no paint and no name markings, three decks, a raked plumb bow, window openings cut but not yet glazed',
                    ],
                    'final' => [
                        'visual_identity' => 'the same luxury motor yacht with a dark navy metallic hull, white superstructure, three decks, a raked plumb bow, long horizontal tinted glass bands along each deck, and a slender radar mast behind the wheelhouse',
                    ],
                ],

                'phases' => [
                    /**
                     * Ảnh tư liệu #6: bàn vẽ General Arrangement — nhiều mặt bằng
                     * boong trải ra, PROFILE VIEW dọc mép trên (đây là lý do chọn
                     * ảnh #6 thay vì #5 phối cảnh nội thất: profile view NHÌN THẤY
                     * được identity, nội thất thì không), mẫu da/vải bên cạnh.
                     * v1 sai: tả bút chì phác thân tàu trên giấy can — sai cả dụng
                     * cụ lẫn đối tượng vẽ.
                     *
                     * IMAGE: khung này dựng từ ảnh neo qua Kontext (ảnh thành phẩm
                     * → bản vẽ kỹ thuật). composition_note ở đây dùng để SINH ảnh;
                     * lúc i2v Python chỉ gửi câu chuyển động camera, không lặp lại
                     * mô tả đã nằm trong ảnh (§18.11).
                     */
                    'design' => [
                        'purpose' => 'ESTABLISH',
                        'render_strategy' => 'IMAGE',
                        /*
                     * objective = SCENE INTENT (§18.18) — "scene này đang làm gì
                     * cho video", KHÁC CẤP với `producer.visual_promise` ("video
                     * hứa gì với người xem"). Bắt buộc phải tự viết ở đây: promise
                     * nói về THÀNH PHẨM đang chạy trên biển, còn scene arc cố ý
                     * mô tả trạng thái vòng đời TRƯỚC đó. Copy promise xuống đây
                     * là mô tả vật chưa hoàn thiện bằng từ vựng vật đã hoàn thiện
                     * — đúng nguyên nhân render v2 thất bại 4/6 (§18.17).
                     */
                        'objective' => 'Establish that this vessel began as a drawing — the concept exists on paper before any steel is cut.',
                        // Chủ thể trong khung là TỜ BẢN VẼ, không phải con tàu.
                        'camera_target' => 'design_drawing',
                        'hero' => 'design_drawing',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'PUSH_IN', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => "A naval architect's hand holds a fine pen over a large General Arrangement drawing sheet spread across a wooden desk — several deck plan views laid out side by side, a side profile view of {hero_name} running along the top edge of the sheet; leather and fabric material swatches and a bound sample book rest beside the drawings.",
                        'micro_physics' => [
                            'The pen tip advances along a deck plan outline and a freshly drawn line remains behind it, the outlined section extending further across the sheet with every pass.',
                            "The architect's other hand slides a second drawing sheet a few centimetres to one side, uncovering more of the profile view beneath it.",
                        ],
                    ],

                    /**
                     * Ảnh tư liệu #1, #2, #4 — SHOT CHỦ LỰC của cả pha: 4-5 cần
                     * trục vàng cùng treo một khối thượng tầng nhiều tầng, hạ
                     * xuống thân tàu kim loại trần ở bến nước, trời xanh. Ảnh #2
                     * (góc thấp nhìn lên bụng khối, 1 thợ đứng mép làm thước tỉ lệ)
                     * là bố cục mạnh nhất.
                     *
                     * Đây cũng chính là `construction_method: modular construction`
                     * mà Truth Layer ĐÃ trích được từ bài Nixie nhưng v1 bỏ không
                     * dùng — v1 tả một cảnh hàn tổng quát TRÁI với dữ liệu có sẵn.
                     *
                     * VIDEO (không dùng ảnh neo): khối đi xuống rồi khớp vào thân
                     * là structural transformation — đúng thứ i2v khóa chết
                     * (§18.16). Dung sai identity ở giai đoạn kim loại trần cũng
                     * cao nhất, nên đây là chỗ ít cần neo nhất.
                     */
                    'construction_hull' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the vessel taking physical form for the first time — a multi-deck section lowered onto the bare metal hull.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'MAJESTIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],
                        // NƠI CHỐN nằm ở `world`, KHÔNG viết vào đây. Cần cẩu cũng
                        // đi theo `world`: cẩu di động là thiết bị của bãi ngoài
                        // trời — đổi sang nhà xưởng có mái thì nó phải thành cầu
                        // trục chạy ray. Nó BIẾN THIÊN THEO môi trường nên thuộc
                        // môi trường, không thuộc dàn cảnh.
                        'world' => ['environment' => 'open_shipyard'],
                        // SỐ NGƯỜI = ý đồ đạo diễn, đặt NGAY CẠNH prose để một
                        // người viết cả hai và thấy nhau khi sửa. Khớp đúng prose
                        // bên dưới: 1 thợ ở mép vỏ + 2 thợ trên boong.
                        'crowd' => ['worker' => 3],
                        'composition_note' => "A large multi-deck superstructure section hangs suspended directly above the bare metal hull of {hero_name}; its underside structural ribbing is fully exposed, while a lone worker in a hard hat stands at the hull's edge, tiny against its scale.",
                        'micro_physics' => [
                            'The suspended section descends steadily and the gap between it and the hull below closes continuously, shrinking from several metres to almost nothing.',
                            'The lifting cables stay taut and vertical throughout, the load swaying only slightly as it comes down.',
                            'Two workers on the hull deck below step back from the landing area as the section closes in above them.',
                        ],
                    ],

                    /**
                     * Ảnh tư liệu #3: động cơ diesel hàng hải trắng khổng lồ treo
                     * trên khung nâng của cần trục, hạ xuống boong; một quản đốc
                     * đội mũ bảo hộ đi trên boong bên dưới làm thước tỉ lệ; trời
                     * nhiều mây. Beat này user chốt 2026-07-26 là GỘP vào
                     * Construction (không thành pha riêng) nhưng tách làm scene 2.
                     */
                    'construction_engine' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the scale of what goes inside — the propulsion machinery lowered into a hull that is still an open shell.',
                        // Chủ thể là CỖ MÁY đang treo, không phải con tàu — shot này
                        // không nhìn thấy dáng tàu nên cũng không cần câu nhận dạng.
                        'camera_target' => 'marine_engine',
                        'hero' => 'marine_engine',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'CENTERED', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],
                        // Khung nâng của cẩu GIỮ LẠI: nó là đạo cụ chính của shot,
                        // còn tồn tại dù đổi sang cầu trục — không biến thiên theo
                        // môi trường nên không thuộc `world`.
                        'world' => ['environment' => 'open_shipyard'],
                        // MỘT người là CHỦ Ý: quản đốc làm thước tỉ lệ, 'dwarfed by
                        // the engine's bulk' — sự cô đơn chính là thứ cho thấy cỗ
                        // máy khổng lồ. Khai số ở đây còn để CHẶN model tự rắc thêm
                        // người vào cho 'sinh động', thứ prose không nói nổi.
                        'crowd' => ['supervisor' => 1],
                        'composition_note' => "A massive white marine diesel engine hangs from a heavy crane's rectangular lifting frame, chains taut at all four corners, suspended above the open deck plating of {hero_name}; a supervisor in a hard hat walks across the deck below, dwarfed by the engine's bulk.",
                        'micro_physics' => [
                            'The engine descends steadily toward the open engine-room hatch and the clearance beneath it shrinks continuously until the hatch opening is nearly filled.',
                            'The four lifting chains remain taut and evenly loaded, the engine holding level as it comes down.',
                            'The supervisor below walks clear of the landing zone, his path opening a widening distance between himself and the descending load.',
                        ],
                    ],

                    /**
                     * Ảnh tư liệu #8: thợ đứng trên XE NÂNG CẮT KÉO màu cam
                     * (scissor lift) áp vào mạn tàu ĐÃ SƠN TRẮNG, mài đường ghép,
                     * tia lửa bắn ra, trong nhà xưởng sáng.
                     *
                     * ĐÂY LÀ ĐIỂM v1 SAI NẶNG NHẤT: v1 tả "đánh bóng vỏ tàu" bằng
                     * tay đeo găng, và tả cảnh hàn ở pha Construction trên sườn
                     * thép trần. Thực tế fairing/mài diễn ra trên vỏ ĐÃ SƠN, cách
                     * giai đoạn khung thép trần cả năm. Không được gộp hai giai
                     * đoạn này lại.
                     */
                    'craftsmanship' => [
                        'purpose' => 'DETAIL',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the hand work that separates a hull from a finished surface — precision at a scale the eye can check.',
                        // Chủ thể là ĐƯỜNG GHÉP đang mài, cận cảnh — không đọc được
                        // dáng tàu ở khung này.
                        'camera_target' => 'hull_seam',
                        'hero' => 'hull_seam',
                        /*
                         * speed=MEDIUM, KHÔNG phải SLOW (2026-07-31). Cả 6 pha
                         * trước đó đều SLOW — video ra một nhịp duy nhất từ đầu
                         * đến cuối. Cảnh này là máy mài chạy dọc đường ghép, tia
                         * lửa bắn: nhịp của HÀNH ĐỘNG nhanh hơn hẳn khối thượng
                         * tầng đang hạ xuống. Cùng tốc độ cho cả hai là bỏ mất
                         * tương phản nhịp — thứ duy nhất tách 6 clip ra khỏi nhau
                         * khi ba shot liền đều STATIC.
                         *
                         * KHÔNG đổi `movement` (vẫn STATIC) — xem ghi chú ở pha
                         * construction_hull: chuyển động của VẬT đang kể chuyện,
                         * thêm chuyển động máy vào lúc chưa có bằng chứng render
                         * là đoán.
                         */
                        'camera' => ['framing' => 'CLOSE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'HARSH', 'light_grade' => 'COOL'],
                        'world' => ['environment' => 'finishing_hall'],
                        'crowd' => ['worker' => 1],
                        'composition_note' => 'A worker in protective gear and a face shield stands on the platform of an orange scissor lift raised against the towering white-painted hull of {hero_name}, working an angle grinder along a seam on the hull side.',
                        'micro_physics' => [
                            'The grinder advances along the seam and a smooth faired strip remains behind it, the finished stretch extending continuously while the rough section ahead grows shorter.',
                            'A tight fan of orange sparks streams from the contact point and travels with the grinder, always originating exactly where the disc meets the hull.',
                            'Fine dust drifts down the hull face below the contact point, settling on the platform rail.',
                        ],
                    ],

                    /**
                     * Ảnh tư liệu #9: thành phẩm lúc hoàng hôn — thân xanh than
                     * sẫm, thượng tầng trắng, rời khỏi nhà xưởng ra mặt nước,
                     * thủy thủ đứng dọc boong mũi, cầu vòm phía xa, trời hồng tím.
                     *
                     * Pha này TRƯỚC ĐÂY KHÔNG TỒN TẠI trong code — v1 chỉ giả định
                     * "scene thật đầu tiên = Experience", và render thật chứng minh
                     * giả định đó sai: scene thật của bài Nixie tả thân thép thô
                     * trong xưởng, tức Construction lần hai (§18.15).
                     *
                     * TÁCH 2 SCENE (quyết định user 2026-07-26): mong muốn ban đầu
                     * là một cú máy "3D từ ngoài vào trong". KHÔNG khả thi trong
                     * MỘT scene — i2v chỉ hoạt hoá cái đã có trong khung nguồn, nên
                     * không thể sinh ra rồi đi vào một không gian trên tàu không
                     * tồn tại trong ảnh neo; còn t2v tự do camera thì lại làm trôi
                     * identity (đúng mâu thuẫn đã gặp ở Construction). Tư liệu thật
                     * cũng CẮT CẢNH giữa ngoại thất (#9) và không gian trên tàu
                     * (#5/#7), không quay một mạch.
                     *
                     * IMAGE: i2v thẳng từ ảnh neo — đây là chỗ identity quan trọng
                     * nhất (khán giả đối chiếu thành phẩm với bản vẽ mở đầu).
                     * Màu thân/thượng tầng là bịa CÓ CHỦ ĐÍCH nhưng phải trùng với
                     * ảnh neo, vì ảnh neo sinh ra từ chính câu này.
                     */
                    'experience_exterior' => [
                        'purpose' => 'REVEAL',
                        'render_strategy' => 'IMAGE',
                        // Pha DUY NHẤT trong arc mà scene intent gần với video promise
                        // (cả hai đều nói về thành phẩm) — nhưng vẫn viết riêng, vì
                        // việc của scene này là ĐÓNG vòng với bản vẽ mở đầu, không
                        // phải nhắc lại lời hứa cấp video.
                        'objective' => 'Close the loop with the opening drawing — the finished vessel leaving the shed is the object that was on paper.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'TRACK', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'TRIUMPHANT', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'SOFT', 'light_grade' => 'GOLDEN'],
                        /*
                         * KHÔNG nêu màu vỏ ở đây. Màu nằm ở MỘT chỗ duy nhất:
                         * `identity.final.visual_identity` (§18.17) — compiler tự
                         * nối vào khi `identity_visible()` cho phép, và scene này
                         * (WIDE + target = hero) luôn được nối.
                         *
                         * Bug thật đã xảy ra: câu này từng ghi "a dark graphite
                         * hull" trong khi identity ghi "dark navy metallic hull"
                         * — hai màu khác nhau nằm cách nhau hai câu TRONG CÙNG
                         * MỘT prompt. Đúng thứ §18.17 cảnh báo: viết lại màu ở
                         * chỗ thứ hai là tự phá identity trong lúc đang lo
                         * identity.
                         */
                        /*
                         * BỎ "an arched steel bridge silhouetted in the distance"
                         * (2026-07-31). Cây cầu đó là tàn dư từ render bài
                         * "The Sixth Sense" — memory ghi rõ scene 9 ra cầu vì
                         * subject chỉ có 1 attribute. Nó bị chép vào đây rồi áp
                         * cho MỌI bài yacht. Matilde 7 hạ thuỷ ở La Spezia,
                         * không liên quan cầu nào.
                         *
                         * §18.16 cho phép bịa bối cảnh, nhưng một cây cầu cụ thể
                         * ở "phía xa" đang giả làm chi tiết RIÊNG của bài này —
                         * đó là bịa đội lốt sự thật, khác với bịa thừa nhận là
                         * bịa (màu vỏ). Chân trời trống để Veo tự lo, hoặc để
                         * Truth điền khi bài có landscape entity.
                         */
                        'composition_note' => '{hero_name}, now complete, moves out of the shipyard shed onto open water at dusk, crew members standing in a line along the foredeck, beneath a pink and violet sky.',
                        'micro_physics' => [
                            'The vessel moves steadily forward and the lit shed opening behind it grows narrower, more of the open water ahead entering the frame.',
                            'Water parts at the bow into a widening wake that lengthens continuously astern.',
                            'The reflection of the dusk sky slides along the dark hull side as the vessel advances.',
                        ],
                    ],

                    /**
                     * Nửa "vào trong" của Experience — CẮT CẢNH sang không gian
                     * trên tàu, không phải cú máy liên tục xuyên vỏ tàu.
                     *
                     * VIDEO (t2v): nội thất/boong KHÔNG kiểm chứng được identity —
                     * không khán giả nào đối chiếu chiếc ghế với bản vẽ mở đầu. Đó
                     * chính là chỗ đánh đổi được: thả camera tự do, không cần neo.
                     *
                     * Nội dung đây KHÔNG bịa tự do: bám đúng attribute Truth đã
                     * trích được từ bài Nixie — `outdoor_features` ("capacious
                     * alfresco seating areas and sunning/social spaces like the
                     * pool area") và `deck_material` ("teak"). Vì thế là boong
                     * sinh hoạt ngoài trời có hồ bơi, KHÔNG phải salon kín tự nghĩ
                     * ra (không có tư liệu nào cho nội thất kín).
                     */
                    'experience_onboard' => [
                        'purpose' => 'RESOLUTION',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Land the payoff — put the viewer aboard the space that all the earlier construction was building toward.',
                        // Camera đứng TRÊN tàu — chủ thể là không gian boong. Giữ
                        // target=hero + movement=TRACK sinh ra câu tự mâu thuẫn
                        // ("lướt tới trên sàn teak" + "đi song song bên cạnh Nixie").
                        // PUSH_IN mới đúng cú máy tiến vào không gian.
                        'camera_target' => 'upper_deck',
                        'hero' => 'upper_deck',
                        'camera' => ['framing' => 'MEDIUM', 'movement' => 'PUSH_IN', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'LEADING_LINES', 'light_intensity' => 'SOFT', 'light_grade' => 'GOLDEN'],
                        'composition_note' => 'On the upper deck of {hero_name} at golden hour, a wide teak-planked social space opens out: low cream lounge seating arranged along both sides, an alfresco dining table set under an overhang, and a rectangular swimming pool set into the deck at the far end, its water catching the low sun.',
                        'micro_physics' => [
                            'The camera glides forward along the teak planking and more of the pool deck enters the frame with every metre, the seating that filled the foreground passing out of shot behind it.',
                            'The pool surface ripples continuously, its band of reflected sunlight stretching further across the water as the angle changes.',
                            'A light breeze moves the edge of a folded towel and the hem of a seat cushion cover.',
                        ],
                    ],
                ],   // ket thuc 'phases'
            ],   // ket thuc 'vessel'

            /**
             * PHỤC CHẾ XE (cars + moto) — nguồn: một video AI THẬT 23 giây,
             * dựng lại một chiếc Mitsubishi Evo IX trong gara (§18.26).
             *
             * KHÁC HẲN `vessel` VỀ NGUYÊN TẮC, không phải chỉ khác nội dung:
             *
             *   vessel      = 6 scene, 5 BỐI CẢNH khác nhau, máy quay đổi kiểu
             *   restoration = 7 scene, MỘT bối cảnh, MỘT vị trí máy, đứng yên
             *
             * Vì sao: trong video tham chiếu, kệ lốp / hai màn hình TV / bàn
             * nguội ở nền GIỐNG HỆT NHAU suốt 23 giây. Máy không nhích một
             * milimet. Thứ thay đổi là chiếc xe, ngay trong khung.
             *
             * Đó là một CƠ CHẾ KHOÁ IDENTITY mạnh hơn mọi câu mô tả: người xem
             * tự thấy đó là cùng một chiếc xe vì nền là bằng chứng. So với
             * §18.15 ("3 clip nhận cùng câu nhận dạng vẫn ra 3 con tàu khác
             * nhau — chữ không đủ độ phân giải để khóa silhouette"), đây là lời
             * giải bằng CẤU TRÚC thay vì bằng chữ.
             *
             * ⚠️ CHƯA CHẠY ĐƯỢC ĐÚNG Ý ĐỒ. Khung cố định chỉ có tác dụng khi 7
             * clip DÙNG CHUNG nền — tức frame cuối clip trước làm ảnh gốc clip
             * sau (i2v chuỗi). Python hiện render 100% t2v độc lập
             * (render_queued_shots.py docstring dòng 18), nên 7 clip sẽ ra 7
             * cái gara khác nhau. Dữ liệu dưới đây ĐÚNG và dùng được ngay ở mức
             * nội dung; phần "khoá identity" phải chờ i2v. Xem §18.26.
             *
             * `set_dressing` là câu tả BỐI CẢNH dùng chung, lặp nguyên văn ở
             * mọi pha — cố ý. Khi chưa có i2v, lặp chữ là thứ duy nhất còn lại
             * để ghì nền lại gần nhau.
             */
            'restoration' => [

                /**
                 * Xe KHÔNG cần identity 2 trạng thái như tàu: bài báo về xe
                 * thường có sẵn hãng/model/màu, và Truth trích được. Ở đây chỉ
                 * neo hai mốc vòng đời để pha đầu và pha cuối tương phản rõ.
                 *
                 * KHÔNG nêu hãng/model — Truth điền. Bịa "Evo IX" cho mọi bài
                 * xe là lặp đúng lỗi `74-metre` vừa sửa.
                 */
                'identity' => [
                    'construction' => [
                        'visual_identity' => 'the same car, paint faded and scratched, front bumper cracked and held on with zip ties, mismatched wheels, no badges',
                    ],
                    'final' => [
                        'visual_identity' => 'the same car, now with deep glossy paint, a bare carbon-fibre bonnet, wide bolt-on fenders, and black split-spoke wheels',
                    ],
                ],

                'phases' => [
                    /**
                     * frame_00s — chiếc xe hỏng đứng MỘT MÌNH giữa gara. Không
                     * người. Đây là "trước" mà toàn bộ video sẽ đối chiếu về.
                     */
                    'arrival' => [
                        'purpose' => 'ESTABLISH',
                        'render_strategy' => 'IMAGE',
                        'objective' => 'Establish the before — the car as it arrived, alone in the workshop, before a single part comes off.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => '{hero_name} stands alone on the polished concrete floor of a workshop, nose angled toward the camera, no one else in the room; steel racks of stacked tyres line the left wall, two dark wall-mounted screens and a tool bench sit along the back wall, and long fluorescent tubes run across the open timber ceiling.',
                        'micro_physics' => [
                            'Dust drifts slowly through the shafts of light from the skylight, settling on the bonnet.',
                            'A loose cable-tie on the front bumper sways slightly and comes to rest.',
                        ],
                    ],

                    /**
                     * frame_01s–04s — ba thợ xúm vào tháo cản trước; một người
                     * bê cản rời khỏi xe. Chuyển động ĐO ĐƯỢC: cản rời khỏi
                     * thân, khoảng cách mở rộng.
                     */
                    'teardown' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the car coming apart — the first panels leaving the body and landing on the floor.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'CENTERED', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],
                        'composition_note' => 'Three mechanics in grey overalls work around the front of {hero_name} in the same workshop, one crouched at the wheel arch with a cordless impact driver, one lifting the bonnet, one drawing the front bumper away from the body; the same tyre racks, wall screens and tool bench stand behind them.',
                        'micro_physics' => [
                            'The front bumper separates from the body and the gap between them widens continuously until the bumper is clear.',
                            'The freed bumper is set down on the concrete and comes to rest, its lower lip flexing once.',
                            'The bonnet rises on its hinges and the dark engine bay opens into view beneath it.',
                        ],
                    ],

                    /**
                     * frame_05s–07s — trơ khung: bánh tháo hết, kê trên bốn
                     * đội, két làm mát lộ ra, vũng dầu dưới sàn.
                     */
                    'bare_shell' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Reach the low point — the car stripped to a shell on stands, further from finished than when it arrived.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'HARSH', 'light_grade' => 'COOL'],
                        'composition_note' => '{hero_name} sits high on four axle stands in the same workshop with every wheel removed, bare brake discs exposed, the whole front clip stripped back to the intercooler and radiator support, removed panels laid out on the floor around it; the same tyre racks and wall screens fill the background.',
                        'micro_physics' => [
                            'A dark patch of fluid spreads slowly outward across the concrete beneath the engine bay.',
                            'A brake disc turns a quarter rotation on its hub and stops.',
                        ],
                    ],

                    /**
                     * frame_08s–11s — mài, primer, và ốp hông composite màu be
                     * đang được ướm vào. Đây là pha "hình dáng đổi", không chỉ
                     * "màu đổi".
                     */
                    'bodywork' => [
                        'purpose' => 'DETAIL',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Show the shape changing, not just the surface — wider panels going on that were never part of the original car.',
                        // WIDE như 6 pha kia. Đổi sang MEDIUM là ĐỔI VỊ TRÍ MÁY —
                        // phá đúng cơ chế mà cả phase_set này dựa vào. Bản nháp
                        // đầu để MEDIUM và bị test bắt (2026-07-31).
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'DRAMATIC', 'composition' => 'LEADING_LINES', 'light_intensity' => 'HARSH', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => 'Two workers in respirators hold a pale unpainted composite fender panel against the flank of {hero_name}, whose body is now uniform grey primer; a matching panel is already fixed on the far side and a low front splitter rests under the nose, in the same workshop with the same racks behind.',
                        'micro_physics' => [
                            'The panel closes the last centimetres onto the flank and the gap along its edge disappears.',
                            'Fine sanding dust lifts off the primed surface and drifts down the body side.',
                        ],
                    ],

                    /**
                     * frame_12s — MÀU TRỞ LẠI. Khoảnh khắc mạnh nhất của cả
                     * video: xám primer → đỏ sâu + ca-pô carbon trần.
                     */
                    'paint' => [
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'Bring the colour back — the moment the shell stops being a project and starts being a car again.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'MAJESTIC', 'composition' => 'CENTERED', 'light_intensity' => 'SOFT', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => '{hero_name} stands on its stands in the same workshop with fresh deep colour on every panel and a bare carbon-fibre bonnet left unpainted, the wide fenders now blended into the body, still without wheels or headlights.',
                        'micro_physics' => [
                            'The overhead fluorescent tubes draw a long highlight down the wet flank, the reflection lengthening as the surface flashes off.',
                            'A last drift of overspray thins out and clears from in front of the body.',
                        ],
                    ],

                    /**
                     * frame_14s–18s — lắp đèn, lắp bánh, hạ khỏi đội, đèn SÁNG.
                     */
                    'reveal' => [
                        'purpose' => 'REVEAL',
                        'render_strategy' => 'IMAGE',
                        'objective' => 'Close the loop with the opening shot — the same spot on the same floor, the same car, finished.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'TRIUMPHANT', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'SOFT', 'light_grade' => 'GOLDEN'],
                        'composition_note' => '{hero_name} sits back down on its own wheels on the workshop floor, complete, in the same position and the same angle it held in the opening shot, with the same tyre racks and wall screens behind it.',
                        'micro_physics' => [
                            'The body settles the last few centimetres as the stands come away and the suspension takes the weight.',
                            'The headlights come on and their glow spreads across the concrete ahead of the car.',
                        ],
                    ],

                    /**
                     * frame_20s–22s — xe LĂN BÁNH ra khỏi khung, để lại gara
                     * TRỐNG và vệt lốp trên sàn.
                     *
                     * Nốt lặng đóng vòng với pha 1 mà không cần một lời nào —
                     * cùng khung hình, khác nội dung: lúc đầu có xe hỏng, lúc
                     * cuối không còn gì.
                     */
                    'drive_out' => [
                        'purpose' => 'RESOLUTION',
                        'render_strategy' => 'VIDEO',
                        'objective' => 'End on the empty room — the work is over and the only thing left of it is on the floor.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'MEDIUM'],
                        'aesthetic' => ['emotion' => 'CALM', 'composition' => 'NEGATIVE_SPACE', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'NEUTRAL'],
                        'composition_note' => '{hero_name} pulls forward out of the workshop from the same camera position, the same tyre racks, wall screens and tool bench standing empty behind it.',
                        'micro_physics' => [
                            'The car moves out of frame and the floor it stood on opens up empty behind it.',
                            'Dark curved tyre marks stay printed on the concrete where it pulled away.',
                        ],
                    ],
                ],   // ket thuc 'phases' cua restoration
            ],   // ket thuc 'restoration'
        ],
    ],
];
