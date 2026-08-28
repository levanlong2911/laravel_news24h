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

    // Shared secret used only by the Python composer/renderer API.
    'api_token' => env('VIDEO_API_TOKEN'),

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

        // Mỗi lần bắn tiến trình tạo MỘT file mới, không bao giờ tự xoá —
        // video:prune-runner-logs dọn theo hạn này (xem lệnh đó).
        'log_retention_days' => env('VIDEO_RUNNER_LOG_RETENTION_DAYS', 21),
    ],

    /*
     * Bam nut la render NGAY, dung doi tai cho (DesignImageRenderer).
     *
     * TAT o phpunit.xml: mot test lo goi duong nay se spawn worker THAT va
     * tieu tien that. Hom 2026-08-20 chuyen do da xay ra — chi thoat vi
     * DatabaseTransactions khien worker khong nhin thay o chua commit. Do la
     * MAY, khong phai thiet ke, nen cho no mot cong tac tuong minh.
     *
     * Test nao muon thu duong nay phai bat lai VA thay PythonRunner bang gia.
     */
    'sync_render' => (bool) env('VIDEO_SYNC_RENDER', true),

    /*
     * `queue`  — enqueue roi goi worker; worker claim, giu lease, bao ve qua
     *            outbox ben. Duong cho production.
     * `direct` — Laravel goi Python, doc stdout, tu ghi so cai. Ngan va de soi,
     *            nhung MAT outbox: tien trinh chet sau khi provider tinh tien
     *            thi khong co gi phat lai.
     */
    'render_mode' => env('VIDEO_RENDER_MODE', 'direct'),

    /*
     * Cau dao tong cho MOI lan sinh tien trinh Python. TAT trong phpunit.xml:
     * Python la thu goi provider, nen mot test lo cham vao duong nay se tra
     * tien that. Test nao muon thu phai bat lai TUONG MINH va thay PythonRunner
     * bang gia.
     */
    'python_runner_enabled' => (bool) env('VIDEO_PYTHON_RUNNER', true),

    'planning_queue' => [
        'connection' => env('VIDEO_PLANNING_QUEUE_CONNECTION', 'video'),
        'name' => env('VIDEO_PLANNING_QUEUE', 'video-planning'),

        // Chạy chặng NGAY trong request thay vì đẩy vào queue — chỉ để gỡ lỗi.
        // Haiku + Sonnet có thể vượt max_execution_time=120s.
        'sync' => (bool) env('VIDEO_PLANNING_SYNC', false),
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
     * Hồ sơ phân tích cảm hứng sáng tạo. Engine trong app/Video/Inspiration
     * không biết domain nào tồn tại; toàn bộ vocabulary theo category nằm ở
     * đây và được tiêm qua CategoryCreativeProfile.
     *
     * 12 aspect là hướng dẫn quan sát MỀM, không phải checklist bắt buộc điền.
     * Analyst chỉ xuất dữ liệu bài thật sự có; Laravel tự tính phần còn thiếu
     * để tầng thiết kế sau được quyền sáng tạo.
     */
    'creative_concept' => [
        'mode' => env('VIDEO_CREATIVE_CONCEPT_MODE', 'disabled'),
    ],

    'tests' => [
        'known_good_geometry_prompt_sha256' => '2092f18636fa1cc798d6c6708c1fc09968333a35f1d2abb86315b28c8f716a4b',
        'known_good_geometry_render_sha256' => 'b2d52e0e6384b01d1457aff320a6d6bea0cb3846786042c7ca140d518eb3f116',
    ],

    'creative_profiles' => [
        'categories' => [
            'yacht' => 'luxury_vessel',
            'superyacht' => 'luxury_vessel',
        ],

        'profiles' => [
            'luxury_vessel' => [
                'mission' => 'Prepare a concise source-inspiration brief containing information from this article that could help a separate creative designer invent a completely new superyacht.',

                // Mission cua Inspiration bao model SOAN BRIEF; Concept Designer
                // phai duoc bao THIET KE, va bao ro cai gi nhin thay tu ngoai.
                'concept_mission' => 'Design a superyacht that has never existed. Its proportions and silhouette must be readable from outside the vessel.',

                // Trình tự tiến của arc, theo hạng mục — Sonnet không được lùi
                // ngược trong danh sách này. Phần tử ĐẦU và CUỐI cũng là ràng
                // buộc: scene đầu phải là `design`, scene cuối phải là `operation`.
                // Hạng mục khác (phục chế xe, xây nhà) khai trình tự của riêng nó.
                /*
                 * Hinh dang da THAT BAI, khong phai so thich. Moi muc doi duoc
                 * mot lan render da tra tien; dung them muc nao chua co bang chung.
                 *
                 * Danh sach nay di vao instruction cua Sonnet. Danh sach ben
                 * `yacht.json` (visual_antipatterns) di vao prompt anh, va hai
                 * cai KHONG phai ban sao cua nhau: mot cai noi voi nguoi thiet ke,
                 * mot cai noi voi nguoi ve.
                 */
                'identity_cross_checks' => [
                    [
                        'kind' => 'ratio',
                        'numerator' => 'design_length_m',
                        'denominator' => 'design_beam_m',
                        'equals' => 'length_to_beam_ratio',
                        'tolerance' => 0.08,
                    ],
                ],

                'design_spec_export' => [
                    'schema_version' => '1.0',

                    'invariants' => [
                        'length_to_beam_ratio',
                        'bow_geometry',
                        'stern_geometry',
                        'continuous_sheer',
                        'superstructure_envelope',
                        'enclosed_deck_level_count',
                        'opening_layout',
                    ],

                    'export_aliases' => [
                        'bow.forefoot' => ['continuous_convex' => 'continuous_convex_transition'],
                        'bow.chine' => ['hard_to_midships' => 'hard_chine_to_midships'],
                        'stern.transom' => ['plumb_full_beam' => 'plumb_full_beam_transom'],
                        'stern.platform' => ['recessed_waterline' => 'integrated_recessed_waterline_platform'],
                        'superstructure.envelope' => ['one_continuous_shell' => 'one_continuous_primary_shell'],
                        'openings.distribution' => ['horizontal_ribbon' => 'horizontal_flush_ribbon_apertures'],
                        'openings.hull_openings' => ['sparse_service' => 'sparse_service_openings'],
                    ],

                    'export_key_names' => [
                        'stern.transom' => 'type',
                        'openings.aperture_bands' => 'superstructure_bands',
                        'openings.distribution' => 'language',
                        'openings.surface_relationship' => 'configuration',
                    ],
                ],

                'concept_forbidden_terms' => [
                    'cantilever',
                    'cantilevered',
                    'cantilevering',
                    'wedding cake',
                    'stacked slabs',
                    'apartment block',
                    'cruise ship',
                    'bulbous',
                    'overhanging',
                    'floating slab',
                    'fin',
                    'fins',
                ],

                'concept_antipatterns' => [
                    'independent horizontal slabs stacked like a wedding cake',
                    'apartment-block or cruise-ship massing',
                    'decorative mast, radar domes, antennas unless required by source evidence',
                    'bulbous bow, anchor pockets, or unexplained hull-side apertures unless required by source evidence',
                ],

                'arc_stages' => ['design', 'construction', 'finishing', 'completion', 'operation'],

                // Được phép lặp và được phép bỏ qua `finishing`; bốn cái này thì không.
                'arc_required_stages' => ['design', 'construction', 'completion', 'operation'],
                'article_patterns' => [
                    'design_profile',
                    'new_build_launch_or_delivery',
                    'construction_progress',
                    'refit_or_conversion',
                    'sale_charter_or_market',
                    'owner_or_celebrity_news',
                    'performance_or_sea_trial',
                    'incident_or_legal',
                    'mixed',
                ],
                'inspection_aspects' => [
                    'size_and_dimensions',
                    'form_and_proportions',
                    'spatial_layout',
                    'deck_organization',
                    'materials_and_finishes',
                    'amenities',
                    'windows_and_glazing',
                    'wellness_and_relaxation',
                    'transformable_spaces',
                    'capacity',
                    'construction_new_build_and_refit',
                ],

                /**
                 * Khe danh tính của NGÀNH này. Code không hiểu nghĩa từng khe —
                 * nó chỉ kiểm đủ khe, đúng kiểu, trong ngưỡng. Profile khác
                 * (automotive…) khai bộ khe riêng mà không cần nhánh code.
                 */
                'identity_slots' => [
                    // 100 m la SAN BIEN TAP cua ho so nay, khong phai dinh nghia
                    // ky thuat cua "superyacht". San tung la 75 m; nang len 100 m
                    // 2026-08-24 theo quyet dinh bien tap.
                    //
                    // San CHAN duoc cai sai nhung khong DAY duoc cai dung: bon lan
                    // Sonnet tra duoi san (74/72/72 tu bai nguon 70 m, roi
                    // 2026-08-23 tu bai 120-foot ~ 36,6 m) vi khong dong nao bao no
                    // phai lam gi khi nguon nho hon. Comment PHP nay khong den duoc
                    // model — chi `guidance` di vao instruction.
                    'design_length_m' => [
                        'type' => 'number',
                        'min' => 100.0,
                        'max' => 180.0,
                        'guidance' => 'Editorial floor: if the source vessel is smaller, '
                            .'scale the new design up to at least 100 m.',
                    ],
                    'design_beam_m' => [
                        'type' => 'number',
                        'min' => 10.0,
                        'max' => 35.0,
                        'guidance' => 'Beam must be consistent with design_length_m and length_to_beam_ratio.',
                    ],
                    'length_to_beam_ratio' => ['type' => 'number', 'min' => 5.5, 'max' => 7.5],
                    'design_draft_m' => ['type' => 'number', 'min' => 2.0, 'max' => 6.0],
                    'visible_freeboard_at_midships_m' => ['type' => 'number', 'min' => 1.5, 'max' => 6.0],
                    'typical_deck_to_deck_height_m' => ['type' => 'number', 'min' => 2.6, 'max' => 3.5],
                    'visible_deck_tiers' => ['type' => 'integer', 'min' => 3, 'max' => 6,
                        'guidance' => 'A verification count, not a design motif. State it here and nowhere else.'],
                    'bow' => ['type' => 'object', 'fields' => [
                        'stem' => ['type' => 'enum', 'values' => ['plumb', 'near_plumb']],
                        'rake_degrees' => ['type' => 'number', 'min' => 0.0, 'max' => 25.0],
                        'waterline_entry' => ['type' => 'enum', 'values' => ['fine', 'moderate', 'full']],
                        'forefoot' => ['type' => 'enum', 'values' => ['continuous_convex', 'hard_knuckle', 'rounded']],
                        'chine' => ['type' => 'enum', 'values' => ['hard_to_midships', 'soft', 'none']],
                    ]],
                    'hull' => ['type' => 'object', 'fields' => [
                        'sheer' => ['type' => 'enum', 'values' => ['continuous_gentle_rise_toward_bow', 'continuous_level']],
                        'midbody' => ['type' => 'enum', 'values' => ['full_displacement', 'slender_displacement']],
                        'keel' => ['type' => 'enum', 'values' => ['continuous_central_baseline']],
                    ]],
                    'stern' => ['type' => 'object', 'fields' => [
                        'transom' => ['type' => 'enum', 'values' => ['plumb_full_beam']],
                        'platform' => ['type' => 'enum', 'values' => ['recessed_waterline']],
                        'overhang' => [
                            'type' => 'boolean',
                            'guidance' => 'true only when the stern projects aft beyond the transom base.',
                        ],
                        'transom_face' => ['type' => 'text', 'max_length' => 90],
                    ]],
                    'superstructure' => ['type' => 'object', 'fields' => [
                        'envelope' => ['type' => 'enum', 'values' => ['one_continuous_shell', 'faceted_continuous_shell', 'swept_integrated_shell']],
                        'massing_position' => ['type' => 'enum', 'values' => ['central', 'central_aft', 'aft']],
                        'external_read' => ['type' => 'enum', 'values' => ['single_integrated_mass', 'continuous_low_volume', 'compressed_wedge_mass']],
                        'long_foredeck' => [
                            'type' => 'boolean',
                            'guidance' => 'true when the foredeck runs clear for roughly a fifth of the length or more.',
                        ],
                        'tier_rule' => ['type' => 'enum', 'values' => [
                            'verifiable_within_one_continuous_mass',
                            'verifiable_within_two_connected_masses',
                        ]],
                        'profile_note' => ['type' => 'text', 'max_length' => 90],
                    ]],
                    'openings' => ['type' => 'object', 'fields' => [
                        'aperture_bands' => [
                            'type' => 'integer',
                            'min' => 1,
                            'max' => 8,
                            'guidance' => 'Count aperture bands, not deck levels; align with visible_deck_tiers when they correspond.',
                        ],
                        'distribution' => ['type' => 'enum', 'values' => ['horizontal_ribbon']],
                        'vertical_extent' => ['type' => 'enum', 'values' => ['partial_height', 'mixed_by_zone']],
                        'surface_relationship' => ['type' => 'enum', 'values' => ['flush_recessed', 'flush']],
                        'hull_openings' => ['type' => 'enum', 'values' => ['sparse_service', 'minimal_service', 'none']],
                    ]],
                    'glazing_type' => [
                        'type' => 'text',
                        'max_length' => 60,
                        'guidance' => 'The finished glazing only; at fabrication stage every aperture is still empty.',
                    ],
                    'hull_material' => ['type' => 'text', 'max_length' => 60],
                    'superstructure_material' => ['type' => 'text', 'max_length' => 60],
                    'hull_colour' => ['type' => 'text', 'max_length' => 60],
                    'boot_stripe_colour' => [
                        'type' => 'text',
                        'max_length' => 90,
                        'guidance' => 'State the boot stripe colour and its placement along the lower hull.',
                    ],
                    'superstructure_colour' => ['type' => 'text', 'max_length' => 60],
                ],

                'viewpoint_guidance' => [
                    'front_three_quarter' => 'Low camera near the design waterline, off the bow. Bow face and hull-side features read well; flat deck surfaces are strongly foreshortened and may be unreadable.',
                    'side' => 'Low camera near the design waterline, square to the centreline. Sheer line, freeboard, tier count and hull-side features read well; deck surfaces are edge-on.',
                    'rear_three_quarter' => 'Low camera near the design waterline, off the quarter. Transom and aft hull-side features read well; flat deck surfaces are strongly foreshortened.',
                ],

                'excluded_context_types' => [
                    'owner',
                    'celebrity',
                    'sale_price',
                    'builder',
                    'designer',
                    'brand',
                    'product_name',
                    'legal_controversy',
                ],
            ],
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
     * cách nhau cả năm.
     *
     * TƯ LIỆU LÀ THÔNG TIN, KHÔNG PHẢI LUẬT. Nó giúp hiểu ngành đang diễn ra thế
     * nào; nó KHÔNG khoá được các câu dưới đây. Thứ có quyền phủ quyết là ẢNH/
     * CLIP RENDER THẬT: prompt đúng tư liệu mà model vẫn vẽ sai thì prompt phải
     * đổi, không phải chờ tìm được tấm ảnh tư liệu khác. Cùng lý do, các mục
     * §18.x trong ARCHITECTURE.md là ghi chép thứ đã đo được, không phải điều
     * cấm — đọc để khỏi lặp lại sai lầm cũ, rồi vẫn cứ thử khi có lý do.
     *
     * Bằng chứng cho chính luật này (2026-08-04): identity `construction` viết
     * bám tư liệu mà Flux vẫn cho ra du thuyền hoàn thiện; sửa được là nhờ nhìn
     * ảnh render, không nhờ tra lại tư liệu.
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
                    /**
                     * PERMANENT — con tàu LÀ GÌ, bất kể đang dựng tới đâu.
                     *
                     * Tách khỏi `construction` vì hai người tiêu thụ cần hai thứ
                     * khác nhau, và một trường không gánh nổi cả hai:
                     *
                     *   ẢNH NEO   cần identity + TRẠNG THÁI. Nó là MỘT ảnh, không
                     *             có mắt nào mang trạng thái hộ — nên `construction`
                     *             phải kể luôn "chưa có thượng tầng, chưa lắp kính".
                     *   CHUỖI     chỉ cần identity. Trạng thái do từng mắt mang
                     *             (`existing` + `adds`), và nhét thêm vào đây thì
                     *             prompt tự mâu thuẫn: identity nói "mép boong đã
                     *             mở" trong khi mắt `keel` nói "No deck."
                     *
                     * Đo được 2026-08-07 trên prompt production của
                     * `chain_superyacht_keel`: dùng `construction` cho chuỗi tạo ra
                     * đúng mâu thuẫn đó trong một đoạn văn.
                     *
                     * KHÔNG có câu phủ định nào ở đây. Chuỗi dựng dần lo trạng thái
                     * bằng CƠ CHẾ — nóc hở vì boong chưa tới lượt, không phải vì có
                     * ai cấm boong (8 lượt Flux + 3 lượt Kontext chứng minh cấm
                     * không có tác dụng).
                     *
                     * Câu này ĐÃ RENDER THẬT: 3 ảnh 2026-08-06, $0.045,
                     * gpt-image-2 + /edit. Khoá bằng sha256 trong
                     * `tests/test_construction_chain.py` bên repo Python.
                     */
                    'permanent' => [
                        'visual_identity' => 'This is the construction of a 90-metre steel superyacht — a slender superyacht about six times longer than it is wide, with a knife-like vertical plumb bow and a wide flat stern.',
                    ],

                    /**
                     * Bằng chứng render 2026-08-04 (anchor Flux dev, $0.025): bản
                     * cũ ghi "…three decks, a raked plumb bow, window openings cut
                     * but not yet glazed" và Flux cho ra một chiếc du thuyền ĐÃ
                     * HOÀN THIỆN — vỏ sơn bóng, kính đã lắp, thượng tầng dựng
                     * xong, có cả radome.
                     *
                     * Nguyên nhân: "three decks" mô tả HÌNH DẠNG THÀNH PHẨM. Nó là
                     * khái niệm mạnh, kéo model về con tàu xong rồi mấy chữ "chưa
                     * lắp kính" bị nuốt. Nêu cái ĐANG CÓ mà quên phủ định cái CHƯA
                     * CÓ thì model tự điền nốt phần còn thiếu.
                     *
                     * SỬA QUAN TRỌNG NHẤT là TỪ MỞ ĐẦU, không phải các câu phủ
                     * định. Bản cũ mở bằng "luxury motor yacht" — trong dữ liệu
                     * huấn luyện gần như MỌI ảnh gắn nhãn đó đều là tàu đã bàn
                     * giao, nên model chốt khái niệm "du thuyền hoàn thiện" ngay
                     * từ token đầu; "bare grey steel", "no paint" phía sau chỉ còn
                     * là modifier, và model hoà giải hai nhóm token bằng cách vẽ
                     * đúng thứ ta nhận được: du thuyền xong đậu trong xưởng.
                     *
                     * Giờ mở bằng "steel hull under construction" — khái niệm gốc
                     * là VẬT ĐANG ĐÓNG. "Future luxury motor yacht" đẩy xuống sau
                     * để giữ thông tin nó sẽ thành gì mà không dẫn dắt hình ảnh.
                     *
                     * Rồi mới liệt kê thứ CHƯA TỒN TẠI, đặt trong prompt DƯƠNG
                     * (negative không đủ cho trục trạng thái). Bỏ "three decks" vì
                     * giai đoạn này chưa có tầng nào — chính nó tả hình dạng THÀNH
                     * PHẨM.
                     *
                     * KHÔNG nêu số mét ở đây (xem ghi chú dài phía trên): chiều dài
                     * là Truth của từng bài, compiler nối từ entity_identity_facts.
                     */
                    'construction' => [
                        'visual_identity' => 'the same large steel superyacht hull under construction, a future luxury motor yacht, raw mill-scale steel plate in patchy grey and red-brown primer, rough matte surface with rust streaks, visible horizontal weld seams and white chalk survey marks, a raked plumb bow, no superstructure on it yet — the top is an open flat deck edge with nothing above it, no windows fitted, only rectangular cut-outs in the plating, no railings, no mast, no radar, no name',
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

                        /**
                         * `requires_state` — ẢNH NGUỒN của shot chuyển động phải
                         * CHỨNG MINH trạng thái nào. Từ vựng đóng, khai ở
                         * `construction_chains/superyacht.json` → `states`.
                         *
                         * Nó KHÔNG phân loại chủ đề cảnh; nó nêu điều kiện cần.
                         * `craftsmanship` là thợ mài mối hàn TRÊN VỎ, nên cần
                         * `hull_shell` — không phải `interior_fitout` dù chữ
                         * "craftsmanship" nghe như chuyện nội thất, và cũng không
                         * phải `complete_hull` vì shell đã đủ chứng minh điều kiện.
                         * Chọn state NHỎ NHẤT mà đủ, không chọn state nghe đầy đủ hơn.
                         *
                         * Không tìm được artifact khớp → resolver trả
                         * UNSATISFIABLE và shot KHÔNG được render. Tuyệt đối không
                         * lặng lẽ lấy ảnh neo hay ảnh gần nhất: hôm nay production
                         * đang dùng MỘT ảnh neo cho cả sáu clip, tức năm clip khởi
                         * từ ảnh sai mà không ai biết.
                         *
                         * MỘT state, không phải danh sách. `experience_onboard`
                         * thật ra cần cả ngoại thất lẫn nội thất, nhưng hợp đồng
                         * nhiều-state là thiết kế khác — mở rộng có chủ đích khi
                         * gặp ca thật, không lén nhét vào đây.
                         */
                        'requires_state' => 'technical_drawing',
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
                        'requires_state' => 'hull_shell',   // xem ghi chu o phase 'design'
                        'purpose' => 'PROCESS',
                        'render_strategy' => 'VIDEO',
                        /**
                         * VIẾT LẠI 2026-08-07. Bản cũ: "a multi-deck section
                         * lowered onto the bare metal hull", với prose tả khối
                         * thượng tầng đang hạ xuống và thợ đứng TRÊN BOONG.
                         *
                         * Ảnh nguồn của cảnh này là `hull_shell` — vỏ thép thô hở
                         * nóc. Không có boong để đứng, không có khối nào treo trên
                         * cao, không có thượng tầng. Câu chữ đang tả một giai đoạn
                         * muộn hơn hẳn ảnh mà nó sẽ được hoạt hoá từ đó.
                         *
                         * Và nó hỏng theo kiểu rất khó đọc: bằng chứng Veo ($0.36
                         * ở engine v1/v2) nói prior của ẢNH thắng câu chữ — clip
                         * sẽ không ra thứ prompt tả, cũng không báo lỗi. Chỉ là
                         * một đoạn video sai.
                         *
                         * Bản mới chỉ tả chuyển động của thứ ĐÃ CÓ trong khung:
                         * cầu trục chạy ray, thợ đứng giữa các sườn, hồ quang hàn.
                         */
                        'objective' => 'Show the vessel taking physical form — the bare steel hull being welded up from the inside.',
                        'camera' => ['framing' => 'WIDE', 'movement' => 'STATIC', 'speed' => 'SLOW'],
                        'aesthetic' => ['emotion' => 'MAJESTIC', 'composition' => 'RULE_OF_THIRDS', 'light_intensity' => 'NEUTRAL', 'light_grade' => 'COOL'],
                        // NƠI CHỐN nằm ở `world`, KHÔNG viết vào đây. Cần cẩu cũng
                        // đi theo `world`: cẩu di động là thiết bị của bãi ngoài
                        // trời — đổi sang nhà xưởng có mái thì nó phải thành cầu
                        // trục chạy ray. Nó BIẾN THIÊN THEO môi trường nên thuộc
                        // môi trường, không thuộc dàn cảnh.
                        'setting' => ['environment' => 'covered_shipbuilding_hall'],
                        // SỐ NGƯỜI = ý đồ đạo diễn, đặt NGAY CẠNH prose để một
                        // người viết cả hai và thấy nhau khi sửa. Khớp đúng prose
                        // bên dưới: 2 thợ trong lòng vỏ + 1 thợ trên sàn xưởng.
                        'crowd' => ['worker' => 3],
                        'composition_note' => 'Two welders in hard hats stand inside the open steel hull of {hero_name}, between its upright transverse frames, tiny against its length; the overhead gantry crane runs on its rails above them with its hook hanging empty.',
                        'micro_physics' => [
                            // Ở cỡ này thợ chỉ cao vài chục pixel, nên tả chuyển
                            // động CẢ NGƯỜI — bài học đã trả tiền ở clip engine:
                            // cờ-lê lực và thước lá không đọc được ở cỡ chủ thể
                            // đó, và bắt Veo hoạt hoá thứ không nhìn thấy là mời
                            // nó bịa ra một vật thứ hai.
                            'The nearer welder straightens up, steps across two frame bays toward the bow, and lowers himself again at the next joint.',
                            'His welding arc flares and dies in short bursts, and the raw steel around him brightens and dims with it.',
                            'Thin welding smoke rises out of the open hull and drifts slowly up toward the roof trusses.',
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
                        'requires_state' => 'machinery_installation',   // xem ghi chu o phase 'design'
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
                        'setting' => ['environment' => 'covered_shipbuilding_hall'],
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
                        /**
                         * SỬA 2026-08-07: `hull_shell` -> `complete_hull`.
                         *
                         * Tôi gán `hull_shell` theo TÊN PHA ("craftsmanship nghe
                         * như hoàn thiện bề mặt"), không theo prose. Resolver báo
                         * SẴN SÀNG vì hai chuỗi enum bằng nhau, và $0.18 suýt
                         * được tiêu để hoạt hoá một tấm ảnh thép thô bằng câu chữ
                         * tả vỏ đã sơn trắng.
                         *
                         * Prose ở dưới ĐÚNG về nghiệp vụ — ghi chú ngay trên đầu
                         * pha này đã chốt: fairing/mài diễn ra trên vỏ ĐÃ SƠN,
                         * cách giai đoạn khung thép trần cả năm, và không được gộp
                         * hai giai đoạn lại. Nên sửa `requires_state`, KHÔNG sửa
                         * prose.
                         *
                         * Hệ quả: chuỗi hiện mới dựng tới `hull_shell` nên cảnh
                         * này UNSATISFIABLE. Đó là câu trả lời ĐÚNG — chưa có ảnh
                         * nào chứng minh được vỏ đã sơn, và render bằng ảnh gần
                         * đúng chính là thứ cả thiết kế này dựng lên để chặn.
                         */
                        'requires_state' => 'complete_hull',
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
                        'setting' => ['environment' => 'finishing_hall'],
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
                        'requires_state' => 'finished_vessel',   // xem ghi chu o phase 'design'
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
                        'requires_state' => 'finished_vessel',   // xem ghi chu o phase 'design'
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
