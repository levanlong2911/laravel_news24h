<?php

// Thu muc dung chung cho moi bang chung va bang gia cua provider anh.
$providerEvidenceDir = resource_path('ai/providers');

$designPromptVersion = env('SCREENPLAY_DESIGN_PROMPT_VERSION', 'vessel-design-v2-r3');

$designPromptDirs = [
    'vessel-design-v2-r3' => resource_path('ai/screenplay/design'),
    'vessel-design-v2-r4' => resource_path('ai/screenplay/design/r4'),
];

return [
    'provider_evidence_dir' => $providerEvidenceDir,

    'render_worker_token' => env('RENDER_WORKER_TOKEN'),

    'runner' => [
        'log_dir' => env('VIDEO_RUNNER_LOG_DIR', storage_path('logs/video-runner')),

        'log_retention_days' => env('VIDEO_RUNNER_LOG_RETENTION_DAYS', 21),
    ],

    'openai_image' => [
        'disk' => env('VIDEO_IMAGE_DISK', 'video_artifacts'),

        'memory_limit' => env('VIDEO_IMAGE_MEMORY_LIMIT', '1024M'),

        'timeout' => (int) env('VIDEO_IMAGE_TIMEOUT', 300),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),

        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com'),

        'versions' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('GEMINI_API_VERSIONS', 'v1beta,v1')),
        ))),

        'timeout' => (int) env('GEMINI_HTTP_TIMEOUT', 60),

        'evidence_dir' => $providerEvidenceDir,

        'disk' => env('VIDEO_IMAGE_DISK', 'video_artifacts'),

        'image_timeout' => (int) env('GEMINI_IMAGE_TIMEOUT', 300),
    ],

    'media_models' => [
        'image' => [
            'environment_plate' => [
                [
                    'id' => 'openai:gpt-image-2.5-flare',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2.5-flare',
                    'label' => 'GPT Image 2.5 Flare — tạo ảnh nhanh',
                    'default' => true,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'controls' => [
                        'sizes' => [
                            '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152',
                            '3840x2160', '720x1280', '1152x2048', '2160x3840',
                        ],
                        'default_size' => '1152x2048',
                        'default_quality' => 'low',
                    ],
                ],
                [
                    'id' => 'openai:gpt-image-2.5-sunburst',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2.5-sunburst',
                    'label' => 'GPT Image 2.5 Sunburst — sửa ảnh chính xác',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'controls' => [
                        'sizes' => [
                            '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152',
                            '3840x2160', '720x1280', '1152x2048', '2160x3840',
                        ],
                        'default_size' => '1152x2048',
                        'default_quality' => 'low',
                    ],
                ],
                [
                    'id' => 'openai:gpt-image-2',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2',
                    'label' => 'GPT Image 2',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'controls' => [
                        'sizes' => [
                            '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152',
                            '3840x2160', '720x1280', '1152x2048', '2160x3840',
                        ],
                        'default_size' => '1152x2048',
                        'default_quality' => 'low',
                    ],
                ],
                [
                    'id' => 'gemini:gemini-3.1-flash-lite-image',
                    'provider' => 'gemini',
                    'model' => 'gemini-3.1-flash-lite-image',
                    'label' => 'Nano Banana 2 Lite',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 1,
                    'api_version' => 'v1',
                    'shape' => 'image_config',
                    'controls' => [
                        'aspect_ratios' => [
                            '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4',
                            '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1',
                        ],
                        'default_aspect_ratio' => '9:16',
                        'image_sizes' => ['1K'],
                        'default_image_size' => '1K',
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_12.json',
                        'image_config' => 'gemini_image_config_2026_09_13.json',
                        'capabilities' => 'gemini_image_capabilities.json',
                        'pricing' => 'gemini_image_pricing_2026_09_14.json',
                    ],
                ],
                [
                    'id' => 'gemini:gemini-3.1-flash-image',
                    'provider' => 'gemini',
                    'model' => 'gemini-3.1-flash-image',
                    'label' => 'Nano Banana 2',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 1,
                    'api_version' => 'v1',
                    'shape' => 'image_config',
                    'controls' => [
                        'aspect_ratios' => [
                            '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4',
                            '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1',
                        ],
                        'default_aspect_ratio' => '9:16',
                        'image_sizes' => ['0.5K', '1K', '2K', '4K'],
                        'default_image_size' => '1K',
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_12.json',
                        'image_config' => 'gemini_image_config_2026_09_13.json',
                        'capabilities' => 'gemini_image_capabilities.json',
                        'pricing' => 'gemini_image_pricing_2026_09_14.json',
                    ],
                ],
                [
                    'id' => 'gemini:gemini-3-pro-image',
                    'provider' => 'gemini',
                    'model' => 'gemini-3-pro-image',
                    'label' => 'Nano Banana Pro',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 1,
                    'api_version' => 'v1',
                    'shape' => 'image_config',
                    'controls' => [
                        'aspect_ratios' => [
                            '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9',
                        ],
                        'default_aspect_ratio' => '9:16',
                        'image_sizes' => ['1K', '2K', '4K'],
                        'default_image_size' => '1K',
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_12.json',
                        'image_config' => 'gemini_image_config_2026_09_13.json',
                        'capabilities' => 'gemini_image_capabilities.json',
                        'pricing' => 'gemini_image_pricing_2026_09_14.json',
                    ],
                ],
            ],

            /*
             * O NEO DANH TINH. Ten task trung `DesignImageStore::ANCHOR_TYPE` de khong
             * sinh ra mot he ten thu hai canh `image_type`.
             *
             * Evidence file dung chung voi `environment_plate`: chung chung minh MODEL
             * co that, nhan `imageConfig`, va gia bao nhieu — khong cai nao noi ve task.
             * Chep lai ten file o day chu khong tro sang task kia: mot ngay nao do hai
             * task co the dung hai ban evidence khac nhau, va luc do khong ai phai go
             * mot tham chieu cheo ra truoc.
             */
            'identity_anchor' => [
                [
                    'id' => 'openai:gpt-image-2',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2',
                    'label' => 'GPT Image 2',
                    // MAC DINH giu nguyen: Nano Banana la lua chon them, khong phai
                    // thay the. KHONG so gia o day — anchor cho chon ca ba muc chat
                    // luong, nen mot con so don le se sai o hai muc con lai.
                    'default' => true,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'controls' => [
                        'sizes' => [
                            '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152',
                            '3840x2160', '720x1280', '1152x2048', '2160x3840',
                        ],
                        'default_size' => '1152x2048',
                        'default_quality' => 'low',
                    ],
                ],
                [
                    'id' => 'gemini:gemini-3.1-flash-lite-image',
                    'provider' => 'gemini',
                    'model' => 'gemini-3.1-flash-lite-image',
                    'label' => 'Nano Banana 2 Lite',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 1,
                    'api_version' => 'v1',
                    'shape' => 'image_config',
                    'controls' => [
                        'aspect_ratios' => [
                            '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4',
                            '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1',
                        ],
                        'default_aspect_ratio' => '9:16',
                        'image_sizes' => ['1K'],
                        'default_image_size' => '1K',
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_12.json',
                        'image_config' => 'gemini_image_config_2026_09_13.json',
                        'capabilities' => 'gemini_image_capabilities.json',
                        'pricing' => 'gemini_image_pricing_2026_09_14.json',
                    ],
                ],
                [
                    'id' => 'gemini:gemini-3.1-flash-image',
                    'provider' => 'gemini',
                    'model' => 'gemini-3.1-flash-image',
                    'label' => 'Nano Banana 2',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 1,
                    'api_version' => 'v1',
                    'shape' => 'image_config',
                    'controls' => [
                        'aspect_ratios' => [
                            '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4',
                            '9:16', '16:9', '21:9', '1:4', '4:1', '1:8', '8:1',
                        ],
                        'default_aspect_ratio' => '9:16',
                        'image_sizes' => ['0.5K', '1K', '2K', '4K'],
                        'default_image_size' => '1K',
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_12.json',
                        'image_config' => 'gemini_image_config_2026_09_13.json',
                        'capabilities' => 'gemini_image_capabilities.json',
                        'pricing' => 'gemini_image_pricing_2026_09_14.json',
                    ],
                ],
                [
                    'id' => 'gemini:gemini-3-pro-image',
                    'provider' => 'gemini',
                    'model' => 'gemini-3-pro-image',
                    'label' => 'Nano Banana Pro',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 1,
                    'api_version' => 'v1',
                    'shape' => 'image_config',
                    'controls' => [
                        'aspect_ratios' => [
                            '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9',
                        ],
                        'default_aspect_ratio' => '9:16',
                        'image_sizes' => ['1K', '2K', '4K'],
                        'default_image_size' => '1K',
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_12.json',
                        'image_config' => 'gemini_image_config_2026_09_13.json',
                        'capabilities' => 'gemini_image_capabilities.json',
                        'pricing' => 'gemini_image_pricing_2026_09_14.json',
                    ],
                ],
            ],
        ],

        // Ten model va `predictLongRunning` DA duoc chung minh bang
        // gemini_models_2026_09_15.json (key nay nhin thay, v1beta).
        //
        // `controls` lay tu tai lieu — veo_video_contract_2026_09_15.json — chu chua
        // co canary nao di qua. Rang buoc "1080p/4k doi 8 giay" duoc kiem o
        // SceneClipDispatchService, khong phai o day.
        'video' => [
            'scene_clip' => [
                [
                    'id' => 'gemini:veo-3.1-lite-generate-preview',
                    'provider' => 'gemini',
                    'model' => 'veo-3.1-lite-generate-preview',
                    'label' => 'Veo 3.1 Lite',
                    'default' => true,
                    'api_version' => 'v1beta',
                    'mode' => 'async',
                    'method' => 'predictLongRunning',
                    'controls' => [
                        'durations' => [4, 6, 8],
                        'default_duration' => 8,
                        'aspect_ratios' => ['9:16', '16:9'],
                        'default_aspect_ratio' => '9:16',
                        'resolutions' => ['720p', '1080p'],
                        'default_resolution' => '720p',
                        // Tai lieu: 1080p va 4k chi chay o 8 giay. De luat nay o DAY chu
                        // khong cam trong code, vi man hinh va server phai doc cung mot
                        // nguon — hai ban sao bang hai ngon ngu thi som muon cung lech.
                        'long_resolutions' => ['1080p'],
                        'long_resolution_duration' => 8,

                        // Canary that ngay 2026-09-15 (render 8d7e3a5b) da chung minh endpoint
                        // nay NHAN `lastFrame` o hinh dang `bytesBase64Encoded`. Tai lieu minh
                        // hoa `inlineData`, nhung hinh dang do CHUA duoc thu — nen day khong
                        // phai bang chung rang tai lieu sai.
                        //
                        // Khung cuoi clip HOI TU ve anh cuoi (PSNR 28.98 dB) chu khong ve anh
                        // dau (16.68 dB). Do la hoi tu DO DUOC, chua phai nhan qua da chung
                        // minh: xem veo_last_frame_canary_2026_09_15.json.
                        'last_frame' => true,
                    ],
                    'evidence' => ['models' => 'gemini_models_2026_09_15.json'],
                ],
                [
                    'id' => 'gemini:veo-3.1-fast-generate-preview',
                    'provider' => 'gemini',
                    'model' => 'veo-3.1-fast-generate-preview',
                    'label' => 'Veo 3.1 Fast',
                    'default' => false,
                    'api_version' => 'v1beta',
                    'mode' => 'async',
                    'method' => 'predictLongRunning',
                    'controls' => [
                        'durations' => [4, 6, 8],
                        'default_duration' => 8,
                        'aspect_ratios' => ['9:16', '16:9'],
                        'default_aspect_ratio' => '9:16',
                        'resolutions' => ['720p', '1080p', '4k'],
                        'default_resolution' => '720p',
                        'long_resolutions' => ['1080p', '4k'],
                        'long_resolution_duration' => 8,

                        // Canary moi chay tren Lite. Cung mot endpoint KHONG phai la da thu —
                        // bat co nay truoc khi co canary rieng cho model nay la doan, khong phai
                        // bang chung. Chay `video:canary-last-frame --model=` voi model nay truoc.
                        'last_frame' => false,
                    ],
                    'evidence' => ['models' => 'gemini_models_2026_09_15.json'],
                ],
                [
                    'id' => 'gemini:veo-3.1-generate-preview',
                    'provider' => 'gemini',
                    'model' => 'veo-3.1-generate-preview',
                    'label' => 'Veo 3.1',
                    'default' => false,
                    'api_version' => 'v1beta',
                    'mode' => 'async',
                    'method' => 'predictLongRunning',
                    'controls' => [
                        'durations' => [4, 6, 8],
                        'default_duration' => 8,
                        'aspect_ratios' => ['9:16', '16:9'],
                        'default_aspect_ratio' => '9:16',
                        'resolutions' => ['720p', '1080p', '4k'],
                        'default_resolution' => '720p',
                        'long_resolutions' => ['1080p', '4k'],
                        'long_resolution_duration' => 8,

                        // Canary moi chay tren Lite. Cung mot endpoint KHONG phai la da thu —
                        // bat co nay truoc khi co canary rieng cho model nay la doan, khong phai
                        // bang chung. Chay `video:canary-last-frame --model=` voi model nay truoc.
                        'last_frame' => false,
                    ],
                    'evidence' => ['models' => 'gemini_models_2026_09_15.json'],
                ],
            ],
            'film_segment' => [
                [
                    'id' => 'gemini:gemini-omni-1.1-flash',
                    'provider' => 'gemini',
                    'model' => 'gemini-omni-1.1-flash',
                    'label' => 'Omni 1.1 Flash',
                    'default' => true,
                    'api_version' => 'v1beta',
                    'mode' => 'sync',
                    'method' => 'interactions',
                    'controls' => [
                        'aspect_ratios' => ['9:16'],
                        'default_aspect_ratio' => '9:16',
                        'resolutions' => ['1080p'],
                        'default_resolution' => '1080p',
                        'task' => 'reference_to_video',
                        'max_seconds' => 10,
                        'duration_control' => 'unverified',
                        'max_reference_images' => 10,
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_15.json',
                        'contract' => 'omni_video_contract_2026_10_14.json',
                    ],
                ],
            ],
        ],
    ],

    'omni' => [
        'timeout' => (int) env('VIDEO_OMNI_HTTP_TIMEOUT', 900),
        'poll_timeout' => (int) env('VIDEO_OMNI_POLL_TIMEOUT', 30),
        'max_reference_bytes' => (int) env('VIDEO_OMNI_MAX_REFERENCE_BYTES', 20971520),
        'reference' => [
            'max_long_edge' => (int) env('VIDEO_OMNI_REFERENCE_LONG_EDGE', 2048),
            'max_bytes' => (int) env('VIDEO_OMNI_REFERENCE_MAX_BYTES', 2097152),
            'jpeg_quality' => (int) env('VIDEO_OMNI_REFERENCE_JPEG_QUALITY', 92),
            'processor' => 'gd-resize-jpeg-v1',
        ],
        'output_sizes' => [
            '9:16' => ['720p' => [720, 1280], '1080p' => [1080, 1920]],
            '16:9' => ['720p' => [1280, 720], '1080p' => [1920, 1080]],
        ],
        'canary_path' => 'omni-canary',
        'evidence_prefix' => 'omni_video_canary_',
        'target' => [
            'duration_ms' => 10000,
            'duration_tolerance_ms' => (int) env('VIDEO_OMNI_DURATION_TOLERANCE_MS', 500),
            'width' => 1080,
            'height' => 1920,
            'video_streams' => 1,
            'audio_streams' => 1,
            'av_drift_tolerance_ms' => (int) env('VIDEO_OMNI_AV_DRIFT_TOLERANCE_MS', 100),
            'extension_min_ms' => 3000,
            'extension_max_ms' => 10000,
        ],
    ],

    'veo' => [
        'disk' => env('VIDEO_VIDEO_DISK', 'video_artifacts'),
        'source_disks' => ['video_artifacts'],
        'timeout' => (int) env('VIDEO_VEO_HTTP_TIMEOUT', 120),
        'max_bytes' => (int) env('VIDEO_VEO_MAX_BYTES', 209715200),
        'max_source_bytes' => (int) env('VIDEO_VEO_MAX_SOURCE_BYTES', 33554432),
        'download_hosts' => ['generativelanguage.googleapis.com'],
        'ffprobe_bin' => env('VIDEO_FFPROBE_BIN', 'ffprobe'),

        // De TRONG thi suy ra tu `ffprobe_bin` — hai binary luon ship cung nhau, va
        // hai bien moi truong la hai co hoi lech nhau. Xem FfmpegBinary::resolve().
        'ffmpeg_bin' => env('VIDEO_FFMPEG_BIN'),
        'ffmpeg_timeout' => (int) env('VIDEO_FFMPEG_TIMEOUT', 600),

        // Do that: ghep 2/5/10/20 clip deu troi DUNG 21ms — HANG SO, khong cong don.
        // 21,33ms la mot khung AAC (1024 mau o 48kHz): duoi audio dai hon video mot
        // khung, va chi co MOT cai duoi cho ca chuoi.
        //
        // Nguong nay CHI kiem troi do dai. No KHONG phat hien duoc mat khung hinh:
        // `format.duration` bam theo AUDIO (do that: video 20.000000s / audio
        // 20.021333s / format 20.021333s), nen bo mot khung video khong doi duration.
        // Viec do thuoc ve VideoFrameCounter.
        'concat_duration_tolerance_ms' => (int) env('VIDEO_FFMPEG_CONCAT_TOLERANCE_MS', 250),
        'preview_dir' => storage_path('app/video-compose-previews'),
        'compose_tmp_dir' => storage_path('app/video-compose-tmp'),
        'ffprobe_timeout' => (int) env('VIDEO_FFPROBE_TIMEOUT', 20),

        // Ghep ban final (muc 2): chua noi vao DB, chua noi vao man hinh.
        'compose_final_dir' => storage_path('app/video-compose-final'),
        'compose_input_root' => base_path(),
        // Ngan sach MONG MUON. `AppServiceProvider` con chan no duoi
        // `max_execution_time` cua PHP: dat 900 trong khi PHP giet request o 120
        // khong cho them thoi gian nao, chi khien ta khong biet minh da bi giet.
        'compose_budget_seconds' => (int) env('VIDEO_COMPOSE_BUDGET_SECONDS', 900),
        // So ban final giu FILE lai cho moi session. Hang trong lich su van con,
        // chi file bi xoa — man hinh da biet hien "khong co file".
        'compose_keep_finals' => (int) env('VIDEO_COMPOSE_KEEP_FINALS', 5),
        'compose_max_input_bytes' => (int) env('VIDEO_COMPOSE_MAX_INPUT_BYTES', 2_000_000_000),
        // Dung sai tieng: MAC DINH 0.
        //
        // AAC ma hoa theo khoi 1024 mau, nen ky vong duoc don len boi cua 1024 ngay
        // trong verifier. Sau phep do — 1 clip, cat thang, crossfade chia chan 1024,
        // va ba ca le — deu khop CHINH XAC cong thuc do. Khong co gi de noi ra.
        'compose_audio_tolerance_samples' => (int) env('VIDEO_COMPOSE_AUDIO_TOLERANCE_SAMPLES', 0),
        // Dung sai thoi gian cho moc dau va do dai tung luong cua output.
        // Do that tren sau ban ghep: hinh khop timeline TUYET DOI ca sau lan,
        // tieng lech nhieu nhat 0,333 ms. 5 ms la ~15 lan so do lon nhat, va van
        // chat hon diem mu 21,3 ms cua phep dem mau.
        'compose_timing_tolerance_ms' => (int) env('VIDEO_COMPOSE_TIMING_TOLERANCE_MS', 5),
        'duration_tolerance_ms' => (int) env('VIDEO_VEO_DURATION_TOLERANCE_MS', 1500),
    ],

    'creative_profiles' => [
        'categories' => [
            'yacht' => 'luxury_vessel',
        ],

        'profiles' => [
            'luxury_vessel' => [
                'mission' => 'Prepare a concise source-inspiration brief containing information from this article that could help a separate creative designer invent a completely new superyacht.',

                'concept_mission' => 'Design a superyacht that has never existed. Its proportions and silhouette must be readable from outside the superyacht.',

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
                    'the category default: a three-tier stepped superstructure set aft on a long foredeck, a raked stem, a reverse transom with full-beam swim platform, and continuous horizontal glazing bands',
                    'independent horizontal slabs stacked like a wedding cake',
                    'apartment-block or cruise-ship massing',
                    'decorative mast, radar domes, antennas unless required by source evidence',
                    'bulbous bow, anchor pockets, or unexplained hull-side apertures unless required by source evidence',
                ],

                'arc_stages' => ['design', 'construction', 'finishing', 'completion', 'operation'],

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

    'environment' => [
        'profiles' => [
            'yacht' => 'vessel_v2',
        ],

        'fitted_from_stage' => [
            'yacht' => 'completion',
        ],
    ],

    'scene_plan' => [
        'prompt_path' => resource_path('ai/prompts/storyboard.txt'),

        'prompt_version' => env('STORYBOARD_PROMPT_VERSION', 'storyboard-v2-r1'),

        'model' => env('SCENE_PLAN_MODEL'),

        /*
         * Gioi han van hanh, KHONG phai ngan sach da do. Nen 261 token/scene o
         * ban v1; v2 them bon truong moi va reasoning nam trong cung ngan sach.
         */
        'max_tokens' => (int) env('SCENE_PLAN_MAX_TOKENS', 32000),

        'client' => [
            'stream' => (bool) env('SCENE_PLAN_STREAM', true),
            'timeout_seconds' => (int) env('SCENE_PLAN_TIMEOUT_SECONDS', 900),
            /** Attempt count, not retries: 1 means one HTTP request. */
            'retry_times' => (int) env('SCENE_PLAN_RETRY_TIMES', 1),
        ],

        'profile_dir' => resource_path('ai/profiles/scene_planning'),

        'profiles' => [
            'yacht' => 'vessel_v2',
        ],
    ],

    'film_cut' => [
        'preset' => [
            'total_ms' => 30000,
            'segment_ms' => 10000,
            'segment_count' => 3,
            'max_scenes' => 20,
            'aspect_ratio' => '9:16',
            'resolution' => '1080p',
            'model_task' => 'film_segment',
            'audio' => 'ambient_only',
        ],
        'segment_reference_views' => 3,
    ],

    'screenplay' => [
        'profile_dir' => resource_path('ai/profiles/screenplay'),

        'profiles' => [
            'yacht' => 'yacht_v1',
        ],

        /** @var array{length_m: array{min: int, max: int}} */
        'dimension_bounds' => [
            'length_m' => ['min' => 100, 'max' => 180],
        ],

        'workflow' => env('VIDEO_WORKFLOW', 'design_first_v1'),

        'design' => [
            'contract_version' => 'vessel_design_v2',

            'profile_contract_version' => 'superyacht_design_v1',

            'prompt_dirs' => $designPromptDirs,

            'prompt_dir' => $designPromptDirs[$designPromptVersion] ?? '',

            'schema_path' => resource_path('ai/screenplay/schemas/vessel_design_v2.json'),

            'prompt_version' => $designPromptVersion,

            'model' => env('SCREENPLAY_DESIGN_MODEL', 'gpt-5.6-sol'),

            'max_tokens' => (int) env('SCREENPLAY_DESIGN_MAX_TOKENS', 48000),

            'stream' => (bool) env('SCREENPLAY_DESIGN_STREAM', true),

            'effort' => env('SCREENPLAY_DESIGN_EFFORT', 'high'),

            'timeout_seconds' => (int) env('SCREENPLAY_DESIGN_TIMEOUT_SECONDS', 1800),

            'repair' => [
                'enabled' => (bool) env('SCREENPLAY_DESIGN_REPAIR_ENABLED', true),

                'max_openings' => (int) env('SCREENPLAY_DESIGN_REPAIR_MAX_OPENINGS', 8),

                'max_tokens' => (int) env('SCREENPLAY_DESIGN_REPAIR_MAX_TOKENS', 8000),

                'effort' => env('SCREENPLAY_DESIGN_REPAIR_EFFORT', 'medium'),

                'timeout_seconds' => (int) env('SCREENPLAY_DESIGN_REPAIR_TIMEOUT_SECONDS', 300),

                'lease_margin_seconds' => 60,
            ],

            /** Attempt count, not retries: 1 means one HTTP request. */
            'retry_times' => (int) env('SCREENPLAY_DESIGN_RETRY_TIMES', 1),
        ],

        'story' => [
            'contract_version' => 'screenplay_foundation_story_v1',

            'prompt_dir' => resource_path('ai/screenplay/foundation'),

            'schema_path' => resource_path('ai/screenplay/schemas/screenplay_foundation_story_v1.json'),

            'prompt_version' => env('SCREENPLAY_STORY_PROMPT_VERSION', 'foundation-story-v1-r10'),

            'model' => env('SCREENPLAY_STORY_MODEL', 'claude-sonnet-5-5'),

            'effort' => env('SCREENPLAY_STORY_EFFORT', 'medium'),

            'max_tokens' => (int) env('SCREENPLAY_STORY_MAX_TOKENS', 48000),

            'stream' => (bool) env('SCREENPLAY_STORY_STREAM', true),

            'timeout_seconds' => (int) env('SCREENPLAY_STORY_TIMEOUT_SECONDS', 900),

            /** Attempt count, not retries: 1 means one HTTP request. */
            'retry_times' => (int) env('SCREENPLAY_STORY_RETRY_TIMES', 1),
        ],

        'locations' => [
            'contract_version' => 'screenplay_locations_v5',

            'prompt_dir' => resource_path('ai/screenplay/locations'),

            'schema_path' => resource_path('ai/screenplay/schemas/screenplay_locations_v5.json'),

            'prompt_version' => env('SCREENPLAY_LOCATIONS_PROMPT_VERSION', 'locations-v5-r4'),

            'model' => env('SCREENPLAY_LOCATIONS_MODEL', 'gpt-5.6-sol'),

            'max_tokens' => (int) env('SCREENPLAY_LOCATIONS_MAX_TOKENS', 24000),

            'stream' => (bool) env('SCREENPLAY_LOCATIONS_STREAM', true),

            'effort' => env('SCREENPLAY_LOCATIONS_EFFORT', 'high'),

            'timeout_seconds' => (int) env('SCREENPLAY_LOCATIONS_TIMEOUT_SECONDS', 900),

            /** Attempt count, not retries: 1 means one HTTP request. */
            'retry_times' => (int) env('SCREENPLAY_LOCATIONS_RETRY_TIMES', 1),
        ],

        'scenes' => [
            'contract_version' => 'screenplay_scene_expansion_v6',

            'assembled_version' => 'screenplay_v8',

            /** Assembled contracts a stored screenplay may carry and still be approved or used in production. */
            'production_versions' => ['screenplay_v4', 'screenplay_v5', 'screenplay_v6', 'screenplay_v7', 'screenplay_v8'],

            /** Foundation contracts the cast and scene steps may build on. */
            'foundation_versions' => ['screenplay_foundation_v2', 'screenplay_foundation_v3', 'screenplay_foundation_v4'],

            'prompt_dir' => resource_path('ai/screenplay/scene_expansion'),

            'schema_path' => resource_path('ai/screenplay/schemas/screenplay_scene_expansion_v6.json'),

            'prompt_version' => env('SCREENPLAY_SCENES_PROMPT_VERSION', 'scene-expansion-v6-r2'),

            'model' => env('SCREENPLAY_SCENES_MODEL', 'claude-sonnet-5-5'),

            'max_tokens' => (int) env('SCREENPLAY_SCENES_MAX_TOKENS', 64000),

            'stream' => (bool) env('SCREENPLAY_SCENES_STREAM', true),

            'effort' => env('SCREENPLAY_SCENES_EFFORT', 'medium'),

            'timeout_seconds' => (int) env('SCREENPLAY_SCENES_TIMEOUT_SECONDS', 900),

            /** Attempt count, not retries: 1 means one HTTP request. */
            'retry_times' => (int) env('SCREENPLAY_SCENES_RETRY_TIMES', 1),
        ],

        'aspect_ratio' => env('SCREENPLAY_ASPECT_RATIO', '9:16'),
    ],
];
