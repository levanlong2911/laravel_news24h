<?php

// Thu muc dung chung cho moi bang chung va bang gia cua provider anh.
$providerEvidenceDir = resource_path('ai/providers');

$designContracts = [
    'vessel_design_v4' => [
        'prompt_dir' => resource_path('ai/screenplay/design'),
        'schema_path' => resource_path('ai/screenplay/schemas/vessel_design_v4.json'),
        'prompt_version' => env('SCREENPLAY_DESIGN_PROMPT_VERSION', 'vessel-design-v4-r2'),
    ],
];
$designContract = (string) env('SCREENPLAY_DESIGN_CONTRACT', 'vessel_design_v4');

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

            'model_sheet' => [
                [
                    'id' => 'openai:gpt-image-2.5-flare',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2.5-flare',
                    'label' => 'GPT Image 2.5 Flare',
                    'default' => true,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'controls' => [
                        'sizes' => [
                            '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152',
                            '3840x2160', '720x1280', '1152x2048', '2160x3840',
                        ],
                        'default_size' => '1536x1024',
                        'default_quality' => 'high',
                    ],
                ],
                [
                    'id' => 'openai:gpt-image-2.5-sunburst',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2.5-sunburst',
                    'label' => 'GPT Image 2.5 Sunburst',
                    'default' => false,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'controls' => [
                        'sizes' => [
                            '1024x1024', '1536x1024', '1024x1536', '2048x2048', '2048x1152',
                            '3840x2160', '720x1280', '1152x2048', '2160x3840',
                        ],
                        'default_size' => '1536x1024',
                        'default_quality' => 'high',
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
                    'id' => 'gemini:gemini-omni-1.1-flash',
                    'provider' => 'gemini',
                    'model' => 'gemini-omni-1.1-flash',
                    'label' => 'Omni 1.1 Flash · 3 s',
                    'default' => true,
                    'api_version' => 'v1beta',
                    'mode' => 'sync',
                    'method' => 'interactions',
                    'controls' => [
                        'durations' => [3],
                        'default_duration' => 3,
                        'aspect_ratios' => ['9:16'],
                        'default_aspect_ratio' => '9:16',
                        'resolutions' => ['720p', '1080p'],
                        'default_resolution' => '720p',
                        'task' => 'image_to_video',
                        'max_seconds' => 10,
                        'duration_control' => 'prompt_only',
                        'max_reference_images' => 10,
                        'last_frame' => false,
                    ],
                    'evidence' => [
                        'models' => 'gemini_models_2026_09_15.json',
                        'contract' => 'omni_video_contract_2026_10_14.json',
                    ],
                ],
                [
                    'id' => 'gemini:veo-3.1-lite-generate-preview',
                    'provider' => 'gemini',
                    'model' => 'veo-3.1-lite-generate-preview',
                    'label' => 'Veo 3.1 Lite',
                    'default' => false,
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

        'prompt_version' => env('STORYBOARD_PROMPT_VERSION', 'storyboard-v4-r3'),

        'model' => env('SCENE_PLAN_MODEL', 'gpt-6.1-sol'),

        'effort' => env('SCENE_PLAN_EFFORT', 'high'),

        'shot_clip' => [
            'model_id' => 'gemini:gemini-omni-1.1-flash',
            'duration_ms' => 3000,
        ],

        'unblocked_findings' => ['duration_conflict', 'heuristic'],

        /*
         * Gioi han van hanh, KHONG phai ngan sach da do. Nen 261 token/scene o
         * ban v1; v2 them bon truong moi va reasoning nam trong cung ngan sach.
         */
        'max_tokens' => (int) env('SCENE_PLAN_MAX_TOKENS', 64000),

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
            'contract_version' => $designContract,

            'contracts' => $designContracts,

            'profile_contract_version' => 'superyacht_design_v1',

            'prompt_dir' => $designContracts[$designContract]['prompt_dir'] ?? null,

            'schema_path' => $designContracts[$designContract]['schema_path'] ?? null,

            'prompt_version' => $designContracts[$designContract]['prompt_version'] ?? null,

            'model' => env('SCREENPLAY_DESIGN_MODEL', 'gpt-6.1-sol'),

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

            'model' => env('SCREENPLAY_LOCATIONS_MODEL', 'gpt-6.1-sol'),

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

            'prompt_version' => env('SCREENPLAY_SCENES_PROMPT_VERSION', 'scene-expansion-v6-r5'),

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

    /*
    | Spec superyacht v6.3: 8 JSON Schema va 22 rang buoc cheo truong (port tu
    | superyacht-pipeline-starter). Chi dung de kiem tai lieu; pipeline v6.3 chua chay.
    */
    'superyacht' => [
        'variables' => [
            'languages' => ['en'],
        ],

        'phase3_requires_judges' => (bool) env('SUPERYACHT_PHASE3_REQUIRES_JUDGES', false),

        'judge' => [
            'model' => env('SUPERYACHT_JUDGE_MODEL', 'gpt-6.1-sol'),
            'effort' => env('SUPERYACHT_JUDGE_EFFORT', 'high'),
            'max_tokens' => (int) env('SUPERYACHT_JUDGE_MAX_TOKENS', 16000),
            'timeout_seconds' => (int) env('SUPERYACHT_JUDGE_TIMEOUT_SECONDS', 600),
            'stream' => false,
            'retry_times' => 0,
        ],

        'design' => [
            'model' => env('SUPERYACHT_DESIGN_MODEL', 'gpt-6.1-sol'),
            'effort' => env('SUPERYACHT_DESIGN_EFFORT', 'medium'),
            'max_tokens' => (int) env('SUPERYACHT_DESIGN_MAX_TOKENS', 48000),
            'timeout_seconds' => (int) env('SUPERYACHT_DESIGN_TIMEOUT_SECONDS', 1800),
            'stream' => true,
            'retry_times' => 0,
        ],

        'design_check' => [
            'profile_margin_m' => 0.2,
            'near_x' => 0.005,
            'near_z_m' => 0.3,
        ],

        'model_sheet' => [
            'suffix' => 'neutral light-grey studio background, soft even light, no text',
            'views' => [
                ['id' => 'MS1', 'view' => 'port side profile, exact side view'],
                ['id' => 'MS2', 'view' => '3/4 bow from port, eye level'],
                ['id' => 'MS3', 'view' => '3/4 stern from starboard, eye level'],
                ['id' => 'MS4', 'view' => 'bow head-on'],
                ['id' => 'MS5', 'view' => 'stern head-on'],
                ['id' => 'MS6', 'view' => 'top-down plan view'],
                ['id' => 'MS7', 'view' => 'aerial 3/4 from high port bow'],
                ['id' => 'MS8', 'view' => 'close-up of innovation N1'],
            ],
            'default_size' => '1536x1024',
            'cameras' => [
                'MS1' => 'Orthographic exact side elevation at 90 degrees to the centreline, no perspective.',
                'MS2' => 'Camera about 35 degrees off the bow axis on the port side, at eye level, lens equivalent to about 85 mm.',
                'MS3' => 'Camera about 35 degrees off the stern axis on the starboard side, at eye level, lens equivalent to about 85 mm.',
                'MS4' => 'Orthographic exact front elevation on the centreline, no perspective.',
                'MS5' => 'Orthographic exact rear elevation on the centreline, no perspective.',
                'MS6' => 'Orthographic view straight down from above, no perspective.',
                'MS7' => 'Camera about 35 degrees off the bow axis on the port side, looking down 25 to 35 degrees, lens equivalent to about 85 mm.',
                'MS8' => 'Close-up camera framed on innovation N1, lens equivalent to about 85 mm.',
            ],
            'framing' => [
                'MS1' => 'Show the whole yacht in frame; do not crop the bow or the stern.',
            ],
            'frames' => [
                'MS1' => ['projection' => 'orthographic', 'azimuth' => 90, 'elevation' => 0, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
                'MS2' => ['projection' => 'perspective', 'azimuth' => 35, 'eye_height' => 'G7', 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
                'MS3' => ['projection' => 'perspective', 'azimuth' => 215, 'eye_height' => 'G7', 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
                'MS4' => ['projection' => 'orthographic', 'azimuth' => 0, 'elevation' => 0, 'width' => 1024, 'height' => 1536, 'target' => 'yacht'],
                'MS5' => ['projection' => 'orthographic', 'azimuth' => 180, 'elevation' => 0, 'width' => 1024, 'height' => 1536, 'target' => 'yacht'],
                'MS6' => ['projection' => 'orthographic', 'azimuth' => 0, 'elevation' => 90, 'width' => 1024, 'height' => 1536, 'target' => 'yacht'],
                'MS7' => ['projection' => 'perspective', 'azimuth' => 35, 'elevation' => 30, 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'yacht'],
                'MS8' => ['projection' => 'perspective', 'azimuth' => 'G9.0', 'elevation' => 'G9.1', 'focal_mm' => 85, 'width' => 1536, 'height' => 1024, 'target' => 'n1'],
            ],
            'occlusion' => 'Keep every part at its stated shape, size and position. Where this camera cannot see a part, leave it hidden; do not move, duplicate, enlarge or make anything transparent to reveal it.',
            'reference_caption' => 'The attached image is the approved MS1 exterior of this yacht. Keep this exterior design, its silhouette and proportions exactly; change only the camera.',
            'approved' => [
                'sha256:370d7ce9e4434eedbfce91d3c8fc6548f9cc7f9f1a898cdd9d3b76bdf05418dd' => [
                    'state_features' => ['canyon_heliostat_active', 'lee_edge_fins_deployed'],
                    'state' => 'Heliostat mirrors are parked in their defocused stow position with optical shutters closed; no light beam is shown. Porous terrace-edge fins are retracted flush.',
                    'emphasis' => 'Keep the canyon on the vessel centreline beneath its slope-following sealed cap; do not reinterpret it as a glazed shaft on the port or starboard side. Keep the aft terraces recessed within the concave lee pocket. Preserve separated vertical and oblique window groups instead of long horizontal glazing strips. Preserve the specified change in rib direction around the crest and aft scoop.',
                    'avoid_interior' => [
                        'Interior palettes use cool greys, blue-green tones, pale ash and selected violet textiles; no brown-leather or desert-decoration scheme is used.',
                        'Cinema, observation lounge and performance space share the canyon room; no piano or separate screen room is included.',
                        'The owner occupies an offset crest-level leeward suite, not the forward main deck.',
                        'Guest accommodation spans multiple decks and includes canyon-fed daylight below.',
                    ],
                    'proxy' => [
                        ['part' => 'n1_cap', 'type' => 'slot', 'from' => 'landmarks.canyon_cap_forward', 'to' => 'landmarks.canyon_cap_aft', 'width' => 'design_parameters.canyon_width', 'recess' => 0.15, 'radius' => 'G12'],
                        ['part' => 'n1_mirror', 'type' => 'disc', 'at' => ['landmarks.heliostat_port', 'landmarks.heliostat_starboard'], 'diameter' => 'design_parameters.mirror_unit_diameter', 'tilt_deg' => 'G5.0', 'pedestal' => 'G5.1'],
                        ['part' => 'n2_ribs', 'type' => 'ribs', 'from_x' => 0.18, 'turn_x' => 'landmarks.ripple_rotation_port.x', 'turn_y' => 'landmarks.ripple_rotation_port.y', 'to_x' => 0.83, 'pitch' => 'design_parameters.ripple_pitch', 'depth' => 'design_parameters.ripple_depth', 'half_turn' => 'G4'],
                        ['part' => 'terrace', 'type' => 'pocket', 'from' => 'open_terraces', 'wall' => 'G8'],
                        ['part' => 'pool', 'type' => 'basin', 'at' => 'landmarks.lee_pool_center', 'length' => 'design_parameters.pool_length', 'width' => 'design_parameters.pool_width', 'depth' => 'design_parameters.pool_water_depth'],
                        ['part' => 'steps', 'type' => 'steps', 'at' => ['landmarks.stern_steps_port', 'landmarks.stern_steps_starboard'], 'width' => 'G6.0', 'tread' => 0.3],
                        ['part' => 'slipway', 'type' => 'channel', 'from' => 'landmarks.slipway_head', 'to' => 'landmarks.stern_toe', 'width' => 'G6.1'],
                        ['id' => 'W1', 'part' => 'windows', 'type' => 'window', 'label' => 'Cửa phòng chủ', 'source' => 'owner_window · INT01 "deep-set oblique side window"', 'at' => 'landmarks.owner_window', 'facing' => 'side', 'sides' => ['port'], 'shape' => 'oblique', 'tilt_deg' => 20, 'width' => 3.0, 'height' => 1.8, 'glass' => 'smoke'],
                        ['id' => 'W2', 'part' => 'windows', 'type' => 'window', 'label' => 'Kính trước cầu lái', 'source' => 'bridge_forward_window · INT10 "separated window facets"', 'at' => 'landmarks.bridge_forward_window', 'facing' => 'forward', 'facets' => 5, 'spread' => 7.0, 'shape' => 'rect', 'width' => 1.4, 'height' => 1.1, 'glass' => 'clear'],
                        ['id' => 'W3', 'part' => 'windows', 'type' => 'window', 'label' => 'Kính mạn cầu lái', 'source' => 'bridge_port_window · INT10 "forward and side bridge windows"', 'at' => 'landmarks.bridge_port_window', 'facing' => 'side', 'sides' => ['port', 'starboard'], 'facets' => 3, 'facet_step' => 1.5, 'shape' => 'rect', 'width' => 1.2, 'height' => 1.0, 'glass' => 'clear'],
                        ['id' => 'W4', 'part' => 'windows', 'type' => 'window', 'label' => 'Cửa oval VIP mũi', 'source' => 'INT02 Main "forward shoulders" · "separated oval side windows above the waterline"', 'deck' => 'Main', 'facing' => 'side', 'sides' => ['port', 'starboard'], 'from_x' => 0.15, 'to_x' => 0.39, 'spacing' => 3.0, 'shape' => 'oval', 'width' => 1.2, 'height' => 0.8, 'glass' => 'smoke'],
                        ['id' => 'W5', 'part' => 'windows', 'type' => 'window', 'label' => 'Cửa đứng salon', 'source' => 'INT03 Main "midships, port of the canyon" · "separated tall side apertures"', 'deck' => 'Main', 'facing' => 'side', 'sides' => ['port'], 'from_x' => 0.39, 'to_x' => 0.63, 'spacing' => 3.0, 'shape' => 'rect', 'width' => 1.0, 'height' => 2.4, 'glass' => 'smoke'],
                        ['id' => 'W6', 'part' => 'windows', 'type' => 'window', 'label' => 'Cửa so le phòng ăn', 'source' => 'INT04 Main "midships, starboard of the canyon" · "staggered vertical side windows"', 'deck' => 'Main', 'facing' => 'side', 'sides' => ['starboard'], 'from_x' => 0.39, 'to_x' => 0.63, 'spacing' => 2.0, 'shape' => 'rect', 'width' => 0.8, 'height' => 2.0, 'stagger' => 0.3, 'glass' => 'smoke'],
                        ['id' => 'W7', 'part' => 'windows', 'type' => 'window', 'label' => 'Cửa mạn sea hall', 'source' => 'INT09 Main "aft, inside the fixed stern access boundary" · "sealed side windows"', 'deck' => 'Main', 'facing' => 'side', 'sides' => ['port', 'starboard'], 'from_x' => 0.63, 'to_x' => 0.87, 'spacing' => 3.0, 'shape' => 'rect', 'width' => 1.0, 'height' => 1.2, 'glass' => 'smoke'],
                        ['id' => 'W8', 'part' => 'windows', 'type' => 'window', 'label' => 'Cửa xiên cabin Guest', 'source' => 'INT12 Guest "forward of the canyon, outboard" · "separated oblique side window" · guest_deck_cabins', 'deck' => 'Guest', 'facing' => 'side', 'sides' => ['port', 'starboard'], 'from_x' => 0.27, 'to_x' => 0.60, 'count' => 'design_parameters.guest_deck_cabins', 'shape' => 'oblique', 'tilt_deg' => 20, 'width' => 2.0, 'height' => 1.4, 'glass' => 'smoke'],
                        ['id' => 'E1', 'part' => 'bulwark', 'type' => 'bulwark', 'label' => 'Thành chắn boong mũi', 'source' => 'EXT01 "Iron Blue bulwarks enclose a Chalk Grey walking surface"', 'from_x' => 0, 'to_x' => 'landmarks.slope_start.x', 'height' => 1.1, 'thickness' => 0.15],
                        ['id' => 'E2', 'part' => 'n1_coaming', 'type' => 'coaming', 'label' => 'Gờ khe canyon', 'source' => 'EXT05 "rests within rounded Chalk Grey coamings"', 'of' => 'n1_cap', 'rim' => 0.3, 'height' => 0.2],
                        ['id' => 'E3', 'part' => 'accent_edge', 'type' => 'edge', 'label' => 'Viền vây xốp mép terrace', 'source' => 'EXT06 "Oxidized Copper porous fins retract along the edges"', 'of' => 'terrace', 'width' => 0.15],
                        ['id' => 'E4', 'part' => 'slipway_rail', 'type' => 'rails', 'label' => 'Ray và viền rãnh trượt', 'source' => 'EXT08 "captive cradle follows recessed rails" · "Oxidized Copper edge markers"', 'of' => 'slipway', 'spacing' => 1.2, 'rail' => 0.12, 'depth' => 'G14', 'marker' => 0.15],
                    ],
                ],
            ],
            'design' => [
                'dimensions' => 'data.canonical.dimensions',
                'dimension_labels' => [
                    'loa' => 'LOA',
                    'lwl' => 'LWL',
                    'beam' => 'beam',
                    'draft' => 'draft',
                    'crest_height' => 'crest height',
                    'bow_freeboard' => 'bow freeboard',
                ],
                'decks' => 'data.yacht_bible.specs.decks',
                'coordinate_x' => 'data.canonical.coordinate_system.x',
                'landmarks' => 'data.canonical.landmarks',
                'crest' => 'crest',
                'deck_levels' => 'data.canonical.decks',
                'geometry' => [
                    'data.yacht_bible.exterior.silhouette',
                    'data.yacht_bible.exterior.bow',
                    'data.yacht_bible.exterior.hull',
                    'data.yacht_bible.exterior.superstructure',
                    'data.yacht_bible.exterior.glazing',
                ],
                'innovations' => 'data.yacht_bible.innovations',
                'innovation_ids' => ['N1', 'N2', 'N3'],
                'innovation_fields' => ['name', 'position', 'geometry', 'visible'],
                'materials' => [
                    'data.canonical.surfaces.finished',
                    'data.canonical.surfaces.glazing',
                    'data.canonical.surfaces.metal_finishes',
                ],
                'colors' => 'data.yacht_bible.colors',
                'color_roles' => ['hull', 'superstructure', 'accent'],
                'requirements' => [
                    'data.yacht_bible.exterior.mast_and_radar',
                    'data.yacht_bible.exterior.helideck',
                    'data.yacht_bible.exterior.stern',
                    'data.yacht_bible.exterior.water_access',
                ],
                'avoid' => 'data.yacht_bible.must_avoid_resolution',
                'operational_limits' => 'data.canonical.operational_limits',
                'view_details' => ['MS8' => 'data.canonical.model_sheet.views'],
            ],
        ],

        'proxy' => [
            'builder' => 'yacht-proxy-v1',
            'canonical_keys' => ['dimensions', 'coordinate_system', 'profile_curve', 'plan_outline', 'decks', 'landmarks', 'open_terraces', 'design_parameters', 'surfaces'],
            'fill' => 0.9,
            'assumptions' => [
                'G1' => ['label' => 'Đường bo mái từ mép boong cao nhất lên đỉnh trên trục tâm (siêu elip)', 'value' => 2.5, 'unit' => 'số mũ'],
                'G2' => ['label' => 'Thân vỏ dưới thẳng đứng tới cao độ', 'value' => 6, 'unit' => 'm'],
                'G3' => ['label' => 'Phần dưới nước chữ U (siêu elip số mũ 4); mũi chạm đường nước tại x; ky phẳng từ x đến x', 'value' => [0.047, 0.10, 0.90], 'unit' => 'phần LOA'],
                'G4' => ['label' => 'Gân N2 đổi hướng dọc đường chéo đi qua mốc ripple_rotation (dọc phía trước, ngang phía sau); G4 × LOA ÷ 2 là bề rộng đoạn bo chỗ đổi hướng; vùng kiểm dọc/ngang cách turn_x ± (G4 + 0.04)', 'value' => 0.05, 'unit' => 'phần LOA'],
                'G5' => ['label' => 'Gương ở tư thế cất: góc nghiêng đĩa; đường kính trụ đỡ', 'value' => [15, 0.6], 'unit' => '° ; m'],
                'G6' => ['label' => 'Bề rộng bậc thang đuôi; bề rộng rãnh trượt tender', 'value' => [2.5, 3.5], 'unit' => 'm'],
                'G7' => ['label' => 'Cao độ camera "eye level"', 'value' => 10, 'unit' => 'm'],
                'G8' => ['label' => 'Thành hốc terrace mỗi bên', 'value' => 1, 'unit' => 'm'],
                'G9' => ['label' => 'Camera MS8: phương vị (góc sau bên trái); góc nhìn xuống', 'value' => [135, 30], 'unit' => '°'],
                'G10' => ['label' => 'Độ lõm tối đa của cửa có mốc so với vỏ proxy (sâu hơn coi là mâu thuẫn, không dựng)', 'value' => 1.5, 'unit' => 'm'],
                'M1' => ['label' => 'Sơn vỏ: độ nhám; lớp phủ bóng', 'value' => [0.35, 0.8], 'unit' => '0–1'],
                'M2' => ['label' => 'Nhôm thân trên phủ mờ: độ nhám; độ kim loại', 'value' => [0.6, 0.2], 'unit' => '0–1'],
                'G11' => ['label' => 'Mũi loe (flared bow): bề rộng đường nước so với mép boong tại mũi; x nơi đạt đủ bề rộng và mặt cắt chuyển từ V sang U', 'value' => [0.55, 0.25], 'unit' => 'tỉ lệ ; phần LOA'],
                'G12' => ['label' => 'Bán kính bo góc khe canyon (thiết kế ghi "radiused corners", không cho số)', 'value' => 0.5, 'unit' => 'm'],
                'G13' => ['label' => 'Độ lõm kính cửa hàng W4–W8 sau mặt vỏ (thiết kế không cho số; W1–W3 lõm theo mốc)', 'value' => 0.2, 'unit' => 'm'],
                'G14' => ['label' => 'Độ sâu rãnh ray lõm của rãnh trượt tender (EXT08 ghi "recessed rails", không cho số)', 'value' => 0.08, 'unit' => 'm'],
                'M3' => ['label' => 'Sàn terrace (thiết kế không nêu vật liệu): màu đá sáng trung tính', 'value' => '#D9D6CF', 'unit' => ''],
                'M4' => ['label' => 'Phần dưới nước (thiết kế không nêu sơn chống hà): dùng màu vỏ', 'value' => 'hull', 'unit' => ''],
                'M5' => ['label' => 'Mã màu cho "matte grey running surfaces" của rãnh trượt (EXT08 chỉ ghi tên màu)', 'value' => '#9A9EA2', 'unit' => ''],
            ],
            'tolerances' => [
                'profile_m' => 0.05,
                'plan_m' => 0.05,
                'crest_x' => 0.005,
                'crest_z_m' => 0.05,
                'ratio' => 0.02,
                'landmark_m' => 0.01,
                'rib_longitudinal_deg' => 10,
                'rib_transverse_deg' => 80,
                'rib_spacing_factor' => 2.5,
                'detail_m' => 0.1,
                'detail_dim_m' => 0.01,
                'window_stretch' => 0.1,
                'pocket_coverage' => 0.95,
                'deck_m' => 0.3,
            ],
            'max_png_bytes' => 8388608,
            'max_glb_bytes' => 33554432,
        ],

        'build_state_text' => [
            'parts' => [
                'keel_blocks' => 'keel blocks',
                'hull_shell' => 'hull shell',
                'main_deck' => 'main deck',
                'superstructure' => 'superstructure',
                'glazing' => 'glazing',
                'hull_doors' => 'hull doors',
                'observation_blister' => 'observation blister',
                'helideck' => 'helideck',
                'mast' => 'mast',
                'stern_platform' => 'stern platform',
                'terraces' => 'terraces',
                'scaffolding_on_hull' => 'scaffolding on the hull',
            ],
            'surfaces' => [
                'scale_model_white' => 'white scale-model finish',
                'bare_steel' => 'bare steel',
                'steel_primer' => 'red-oxide primer',
                'raw_aluminium' => 'raw unpainted aluminium',
                'protective_film' => 'white protective film',
                'filler_primer' => 'grey filler and primer',
                'final_paint' => 'final paint',
                'final_glass' => 'final glass',
                'teak' => 'teak',
                'other_final' => 'final finish',
            ],
        ],

        'spec_version' => '6.3.0',

        'workflow_code' => 'superyacht_v6_3',
        'schema_namespace' => 'superyacht',
        'spec_path' => resource_path('ai/superyacht/spec_v6_3_en.md'),
        'contract_fixture_dir' => resource_path('ai/superyacht/contracts/fixtures'),

        'contracts' => [
            'categories' => [
                'yacht' => 'superyacht_v6_3',
            ],
            'workflows' => [
                'superyacht_v6_3' => [
                    'actions' => [
                        'spec.design' => [
                            'phase' => 'phase-3',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '3',
                            'stages' => [
                                'draft' => ['canonical'],
                                'lock' => ['canonical', 'constraints'],
                            ],
                            'outputs' => [
                                'canonical' => ['namespace' => 'superyacht', 'schema' => 'CanonicalDesign', 'version' => '6.3.0', 'pointer' => '/data/canonical', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 0 Step 6 data contract: canonical (Phase 3); Phase 3 Step 11 example'],
                                'constraints' => ['namespace' => 'superyacht', 'schema' => 'Constraint', 'version' => '6.3.0', 'pointer' => '/data/constraints', 'mode' => 'each_item', 'required' => true, 'source' => 'Phase 0 Step 6 data contract: constraints[] (Phase 3); Phase 3 Step 11 example'],
                            ],
                            'records' => [
                                'lock/design' => ['namespace' => 'superyacht', 'schema' => 'LockRecord', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'match' => ['/lock_type' => ['design']], 'source' => 'Pipeline map: DESIGN LOCK (lock record); Phase 3 Step 11 design lock; LockRecord.lock_type design'],
                                'review/decision' => ['namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'match' => ['/item_type' => ['canonical', 'model_sheet']], 'source' => 'ReviewDecision.item_type canonical, model_sheet; reason DESIGN_LOCK'],
                            ],
                        ],
                        'spec.shot_list' => [
                            'phase' => 'phase-6',
                            'instructions' => ['system-prompt', 'common-standards'],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'outputs' => [
                                'shots' => ['namespace' => 'superyacht', 'schema' => 'ShotSpec', 'version' => '6.3.0', 'pointer' => '/data/shots', 'mode' => 'each_item', 'required' => true, 'source' => 'Phase 0 Step 6 data contract: shots[] (Phase 6); schema 4 ShotSpec is one row of the Phase 6 shot list'],
                            ],
                        ],
                        'spec.prompt_compile' => [
                            'phase' => 'phase-6c',
                            'instructions' => ['system-prompt', 'common-standards'],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '2',
                            'outputs' => [
                                'compiled_shots' => ['namespace' => 'superyacht', 'schema' => 'CompiledShot', 'version' => '6.3.0', 'pointer' => '/data/compiled_shots', 'mode' => 'each_item', 'required' => true, 'items' => ['count' => 1, 'key' => 'shot_id'], 'source' => 'Phase 0 Step 6 data contract: compiled_shots[] (Phase 6C); Phase 6C Output is one item (schema 5); one shot per task'],
                            ],
                            'records' => [
                                'lock/spec' => ['namespace' => 'superyacht', 'schema' => 'LockRecord', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'match' => ['/lock_type' => ['spec']], 'source' => 'Phase 6C Step 7: the compiled spec is written with a lock record; Pipeline map: SPEC FREEZE'],
                                'review/decision' => ['namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'match' => ['/item_type' => ['compiled_shot']], 'source' => 'ReviewDecision.item_type compiled_shot; SPEC FREEZE: UNCERTAIN goes to a human'],
                            ],
                        ],
                    ],
                    'links' => [
                        ['phase' => 'phase-4m', 'role' => 'lock', 'slot' => 'timing', 'namespace' => 'superyacht', 'schema' => 'LockRecord', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Pipeline map: TIMING LOCK; LockRecord.lock_type timing'],
                        ['phase' => 'phase-7a', 'role' => 'lock', 'slot' => 'pacing', 'namespace' => 'superyacht', 'schema' => 'LockRecord', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Pipeline map: PACING LOCK; LockRecord.lock_type pacing'],
                        ['phase' => 'phase-9q', 'role' => 'lock', 'slot' => 'publish', 'namespace' => 'superyacht', 'schema' => 'LockRecord', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Pipeline map: BEFORE PUBLISH; LockRecord.lock_type publish'],
                        ['phase' => 'phase-7', 'role' => 'review', 'slot' => 'decision', 'namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'source' => 'ReviewDecision.item_type frame; Phase 7 UNCERTAIN frames go to the human queue'],
                        ['phase' => 'phase-7a', 'role' => 'review', 'slot' => 'decision', 'namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'source' => 'ReviewDecision.item_type animatic; reason ANIMATIC_LOW'],
                        ['phase' => 'phase-8', 'role' => 'review', 'slot' => 'decision', 'namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'source' => 'ReviewDecision.item_type clip; Phase 8 UNCERTAIN clips go to the human queue'],
                        ['phase' => 'phase-9q', 'role' => 'review', 'slot' => 'decision', 'namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'source' => 'ReviewDecision.item_type export; reason PUBLISH'],
                        ['phase' => 'phase-7', 'role' => 'input', 'slot' => 'compiled_shot', 'namespace' => 'superyacht', 'schema' => 'CompiledShot', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 7 Input (v6.3): frozen CompiledShots (Phase 6C)'],
                        ['phase' => 'phase-7', 'role' => 'input', 'slot' => 'render_request', 'namespace' => 'superyacht', 'schema' => 'RenderRequest', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 7 Input (v6.3): a frozen RenderRequest built from them for each call'],
                        ['phase' => 'phase-8', 'role' => 'input', 'slot' => 'compiled_shot', 'namespace' => 'superyacht', 'schema' => 'CompiledShot', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 8 v6: motion prompts, references and tool parameters come from the frozen compiled spec (Phase 6C)'],
                        ['phase' => 'phase-8', 'role' => 'input', 'slot' => 'render_request', 'namespace' => 'superyacht', 'schema' => 'RenderRequest', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Schema 8 RenderRequest: frozen right before each provider call, kind video / video_extend / video_edit'],
                    ],
                ],
            ],
        ],

        // Thu muc 8 file JSON Schema va base URI dung trong $id cua chung.
        'schema_dir' => resource_path('ai/superyacht/schemas'),
        'schema_base_uri' => 'https://superyacht.local/schemas/',
        'schemas' => [
            'CanonicalDesign', 'BuildState', 'Constraint', 'ShotSpec',
            'CompiledShot', 'LockRecord', 'ReviewDecision', 'RenderRequest',
        ],

        // FULL_AUTO | REVIEW_KEY | REVIEW_ALL
        'default_run_mode' => 'REVIEW_KEY',

        // Thu tu build state cho moi phep so sanh (BS01-BS04 la trang thai thiet ke).
        'states' => ['BS01', 'BS02', 'BS03', 'BS04', 'BS05', 'BS06', 'BS07', 'BS08', 'BS09', 'BS10', 'BS11', 'BS12'],
        'hero_beat' => 'B16',

        // Phan duoc phep bien mat giua hai build state lien tiep (INV-BS-03).
        'removable_parts' => ['scaffolding_on_hull', 'keel_blocks'],

        // 'phrase' cho bo bien dich STATE GEOMETRY (INV-BS-04); 'keywords' de do nhac toi duong tinh (INV-CS-03).
        'parts' => [
            'keel_blocks' => ['phrase' => 'welded keel and bottom blocks', 'keywords' => ['keel block', 'bottom block']],
            'hull_shell' => ['phrase' => 'a complete hull shell', 'keywords' => ['hull shell', 'complete hull']],
            'main_deck' => ['phrase' => 'an open main deck', 'keywords' => ['main deck']],
            'superstructure' => ['phrase' => 'the superstructure', 'keywords' => ['superstructure']],
            'glazing' => ['phrase' => 'fitted glazing', 'keywords' => ['glazing', 'glass panel', 'window glass']],
            'hull_doors' => ['phrase' => 'twin hull door openings at midships', 'keywords' => ['hull door']],
            'observation_blister' => ['phrase' => 'an observation blister below the bow waterline', 'keywords' => ['blister']],
            'helideck' => ['phrase' => 'a flush helideck on the foredeck', 'keywords' => ['helideck']],
            'mast' => ['phrase' => 'a mast', 'keywords' => ['mast']],
            'stern_platform' => ['phrase' => 'a full-width stern water platform', 'keywords' => ['stern platform', 'water platform']],
            'terraces' => ['phrase' => 'stepped terraces', 'keywords' => ['terrace']],
            'scaffolding_on_hull' => ['phrase' => 'scaffolding along both sides', 'keywords' => ['scaffolding']],
        ],

        // Tu vung be mat cho nua SURFACE STATE cua compiled_text.
        'surfaces' => [
            'scale_model_white' => 'white scale-model finish',
            'bare_steel' => 'bare welded steel',
            'steel_primer' => 'red-oxide primer',
            'raw_aluminium' => 'raw unpainted aluminium',
            'protective_film' => 'white protective film',
            'filler_primer' => 'grey filler and primer',
            'final_paint' => 'final paint',
            'final_glass' => 'final tinted glass',
            'teak' => 'teak',
            'other_final' => 'final finish',
        ],

        // operational_limits[].feature -> phan phai co tren tau hoan thien (INV-CD-03).
        'features' => [
            'hull_doors_open' => 'hull_doors',
            'helideck_landing' => 'helideck',
            'blister_viewing' => 'observation_blister',
            'terraces_deployed' => 'terraces',
            'stern_platform_down' => 'stern_platform',
        ],

        // Quyet dinh duyet hop le theo loai muc (INV-RD-01).
        'review_decisions' => [
            'canonical' => ['approve', 'reject', 'revise_design'],
            'model_sheet' => ['approve', 'reject', 'rerender', 'revise_design'],
            'compiled_shot' => ['approve', 'reject', 'swap_coverage'],
            'frame' => ['approve', 'reject', 'rerender', 'swap_variant', 'swap_coverage'],
            'clip' => ['approve', 'reject', 'rerender', 'swap_variant', 'swap_coverage'],
            'animatic' => ['approve', 'reject'],
            'export' => ['approve', 'reject'],
        ],
        'human_only_review_reasons' => ['DESIGN_LOCK', 'PUBLISH'],

        // Model cua nguoi duyet DESIGN_LOCK / PUBLISH ("user:<id>"). Du an dung Admin.
        'user_model' => 'App\\Models\\Admin',

        // Sai so khi kiem moc thoi gian nam tren cut grid (giay).
        'cut_grid_tolerance' => 0.001,
    ],
];
