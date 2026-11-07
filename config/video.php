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

    'async' => [
        'enabled' => (bool) env('VIDEO_ASYNC_ENABLED', false),
        'execution_margin_seconds' => (int) env('VIDEO_QUEUE_EXECUTION_MARGIN_SECONDS', 120),
        'state_directory' => storage_path('framework/cache/video-queue'),
        'supervisor' => [
            'tick_seconds' => 1,
            'ram_reserve_mb' => (int) env('VIDEO_QUEUE_RAM_RESERVE_MB', 1536),
            'completion_reserve_mb' => (int) env('VIDEO_QUEUE_COMPLETION_RESERVE_MB', 600),
            'spawn_per_tick' => (int) env('VIDEO_QUEUE_SPAWN_PER_TICK', 4),
            'db_connections_per_worker' => 1,
            'process_estimate_mb' => ['api' => 80, 'image' => (int) env('VIDEO_QUEUE_IMAGE_ESTIMATE_MB', 400), 'poll' => 60, 'download' => 120, 'ffmpeg' => 300],
            'startup_seconds' => 10,
            'cpu_reserve' => 1,
            'max_processes' => (int) env('VIDEO_QUEUE_MAX_PROCESSES', 0),
            'db_connection_ceiling' => 0.8,
            'stop_wait_seconds' => 30,
            'recovery_every_seconds' => 60,
            'recovery_batch' => 50,
            'pending_grace_seconds' => 600,
            'heartbeat_stale_seconds' => 30,
        ],
        'rate_limit' => [
            'default_retry_seconds' => 60,
            'max_delay_seconds' => 600,
            'max_wait_seconds' => 1800,
            'cooldown_lock_seconds' => 5,
            'client_retry' => true,
            'client_wait_seconds' => (int) env('VIDEO_IMAGE_CLIENT_WAIT_SECONDS', 10),
        ],
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

            'location_reference' => [
                [
                    'id' => 'openai:gpt-image-2.5-flare',
                    'provider' => 'openai',
                    'model' => 'gpt-image-2.5-flare',
                    'label' => 'GPT Image 2.5 Flare — tạo và sửa ảnh',
                    'default' => true,
                    'pricing' => 'estimated',
                    'max_variations' => 2,
                    'capabilities' => ['generate', 'edit_sources'],
                    'max_sources' => 4,
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
                    'capabilities' => ['generate', 'edit_sources'],
                    'max_sources' => 4,
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
                    'capabilities' => ['generate'],
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
                    'capabilities' => ['generate'],
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
                    'capabilities' => ['generate'],
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
                    'capabilities' => ['generate'],
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
    'claude_stream' => [
        'idle_timeout_seconds' => (int) env('CLAUDE_STREAM_IDLE_TIMEOUT_SECONDS', 180),
        'progress_every_seconds' => 5,
        'thinking' => ['type' => 'adaptive', 'display' => 'summarized'],
    ],

    'script' => [
        'workflows' => [
            'superyacht_v6_3' => [
                'action' => 'spec.script',
                'adapter' => \App\Video\Superyacht\Script\SuperyachtScript::class,
            ],
            'harley_custom_v1' => [
                'action' => 'spec.script',
                'adapter' => \App\Video\Moto\MotoScript::class,
            ],
        ],
    ],

    'locations' => [
        'workflows' => [
            'superyacht_v6_3' => [
                'action' => 'spec.locations',
                'adapter' => \App\Video\Superyacht\Locations\SuperyachtLocations::class,
            ],
            'harley_custom_v1' => [
                'action' => 'spec.locations',
                'adapter' => \App\Video\Moto\MotoLocations::class,
            ],
        ],
    ],

    'scenes' => [
        'workflows' => [
            'harley_custom_v1' => [
                'action' => 'spec.scenes',
                'adapter' => \App\Video\Moto\MotoScenes::class,
            ],
        ],
    ],

    'shots' => [
        'workflows' => [
            'harley_custom_v1' => [
                'action' => 'spec.shots',
                'adapter' => \App\Video\Moto\MotoShots::class,
            ],
        ],
    ],

    'references' => [
        'workflows' => [
            'harley_custom_v1' => [
                'action' => 'spec.reference',
                'adapter' => \App\Video\Moto\MotoReference::class,
            ],
        ],
    ],

    'moto' => [
        'brief' => [
            'platform' => null,
            'keep' => null,
            'change_scope' => null,
            'rider' => null,
            'purpose' => null,
            'limits' => null,
        ],

        'concept_history_limit' => 10,

        'design' => [
            'effort' => env('MOTO_DESIGN_EFFORT', 'medium'),
        ],

        'script' => [
            'execution' => \App\Video\Moto\MotoContract::SCRIPT_EXECUTION,
            'model' => env('MOTO_SCRIPT_MODEL', 'sonnet55'),
            'effort' => env('MOTO_SCRIPT_EFFORT', 'high'),
            'max_tokens' => (int) env('MOTO_SCRIPT_MAX_TOKENS', 64000),
            'timeout_seconds' => (int) env('MOTO_SCRIPT_TIMEOUT_SECONDS', 900),
            'schema_heading' => 'Output JSON Schema',
            'base_card' => true,
            'brief' => [
                'video_length' => 30,
                'aspect_ratio' => '9:16',
                'platform' => 'YouTube Shorts, Facebook Reels',
                'tone_notes' => '',
            ],
            'brief_options' => [
                'video_length' => [15, 120],
                'aspect_ratios' => ['9:16', '16:9', '1:1', '4:5'],
                'platform_max' => 120,
                'tone_notes_max' => 500,
            ],
            'history' => ['enabled' => false],
            'check' => [
                'cover_build_states' => true,
                'length_tolerance_sec' => 0.1,
                'card_seconds_per_card' => 10,
                'warn' => [
                    'beat_min_sec' => 0.8,
                    'hero_min_sec' => 2,
                ],
            ],
        ],

        'locations' => [
            'execution' => \App\Video\Moto\MotoContract::LOCATIONS_EXECUTION,
            'model' => env('MOTO_LOCATIONS_MODEL', 'sonnet55'),
            'effort' => env('MOTO_LOCATIONS_EFFORT', 'medium'),
            'efforts' => ['medium', 'high'],
            'max_tokens' => (int) env('MOTO_LOCATIONS_MAX_TOKENS', 32000),
            'timeout_seconds' => (int) env('MOTO_LOCATIONS_TIMEOUT_SECONDS', 900),
            'schema_heading' => 'Output JSON Schema',
            'semantic_review' => 'manual',
            'reference' => [
                'task' => 'location_reference',
                'labels' => [
                    'space' => 'Space:',
                    'dimensions' => 'Size:',
                    'surfaces' => 'Surfaces:',
                    'equipment' => 'Fixed equipment:',
                    'palette' => 'Colour palette:',
                    'support' => 'Temporary work support, standing empty:',
                    'ground' => 'Ground surface:',
                    'props' => 'Props:',
                    'composition' => 'Composition:',
                    'light' => 'Light:',
                    'must_show' => 'Must show:',
                    'avoid' => 'Avoid:',
                ],
                'style' => [
                    'location' => 'Photorealistic documentary photograph of a working place where custom motorcycles are designed, built or finished: true perspective, real equipment and materials at full scale, one still frame',
                    'open_ground' => 'Photorealistic location photograph of an open outdoor place: true horizon and natural perspective, real ground, light and terrain at full scale, one still frame',
                ],
                'suffix' => [
                    'location' => 'No motorcycle, no motorcycle parts, no people, no text, no signage, no logos',
                ],
            ],
        ],

        'scenes' => [
            'execution' => \App\Video\Moto\MotoContract::SCENES_EXECUTION,
            'model' => env('MOTO_SCENES_MODEL', 'sonnet55'),
            'effort' => env('MOTO_SCENES_EFFORT', 'high'),
            'max_tokens' => (int) env('MOTO_SCENES_MAX_TOKENS', 64000),
            'timeout_seconds' => (int) env('MOTO_SCENES_TIMEOUT_SECONDS', 900),
            'schema_heading' => 'Output JSON Schema',
        ],

        'shots' => [
            'execution' => \App\Video\Moto\MotoContract::SHOTS_EXECUTION,
            'model' => env('MOTO_SHOTS_MODEL', 'sonnet55'),
            'effort' => env('MOTO_SHOTS_EFFORT', 'high'),
            'max_tokens' => (int) env('MOTO_SHOTS_MAX_TOKENS', 64000),
            'timeout_seconds' => (int) env('MOTO_SHOTS_TIMEOUT_SECONDS', 1200),
            'schema_heading' => 'Output JSON Schema',
            'clip_task' => 'scene_clip',
            'keyframe_reference_limit' => 4,
            'check' => [
                'cover_design_refs' => true,
            ],
            'prompt' => [
                'image' => [
                    'style' => 'Photorealistic film still of one moment, true perspective and real materials at full scale',
                    'labels' => [
                        'frame' => 'Frame:',
                        'camera' => 'Camera:',
                        'first_frame' => 'First frame:',
                        'motorcycle' => 'Motorcycle:',
                        'stage' => 'Build stage:',
                        'part' => 'Part in frame:',
                        'off_frame' => 'Out of frame, only its effect shows:',
                        'work' => 'Work at the first frame:',
                        'absent' => 'Not on the motorcycle at this stage:',
                        'people' => 'People:',
                        'place' => 'Place:',
                        'set' => 'Set:',
                        'light' => 'Light:',
                        'changes' => 'Changes on the set for this shot:',
                        'continuity' => 'Continuity:',
                    ],
                    'suffix' => 'No face of any person, no readable text, no signage, no logos',
                ],
                'video' => [
                    'lead' => 'The supplied image is the first frame.',
                    'labels' => [
                        'work' => 'Work:',
                        'action' => 'Action:',
                        'result' => 'Result:',
                        'timing' => 'Timing:',
                        'camera' => 'Camera:',
                        'end_frame' => 'Ends on:',
                        'end_configuration' => 'Positions at the end:',
                        'work_end' => 'Parts at the end:',
                        'continuity' => 'Keep:',
                    ],
                    'suffix' => 'No face of any person, no readable text, no morphing of the motorcycle',
                ],
                'presentations' => [
                    'installed' => 'installed',
                    'being_made' => 'being made',
                    'awaiting_install' => 'finished, waiting to be fitted',
                    'removed' => 'removed from the motorcycle',
                    'drawing' => 'as a drawing',
                    'model' => 'as a model',
                    'digital_model' => 'as a digital model',
                ],
                'people_views' => [
                    'face_hidden' => 'Visible as the beat shows them, with the face hidden; not a dark silhouette.',
                ],
                'work' => [
                    'operations' => [
                        'make' => 'making',
                        'finish' => 'finishing',
                        'test_fit' => 'test-fitting',
                        'assemble' => 'assembling',
                        'paint' => 'painting',
                        'remove' => 'removing',
                        'install' => 'installing',
                        'reinstall' => 'refitting',
                        'align_check' => 'holding up for alignment without fitting',
                    ],
                    'locations' => [
                        'on_motorcycle' => 'on the motorcycle',
                        'off_motorcycle' => 'off the motorcycle',
                    ],
                ],
            ],
        ],

        'reference' => [
            'execution' => \App\Video\Moto\MotoContract::REFERENCE_EXECUTION,
            'model' => env('MOTO_REFERENCE_MODEL', 'sonnet55'),
            'effort' => env('MOTO_REFERENCE_EFFORT', 'medium'),
            'max_tokens' => (int) env('MOTO_REFERENCE_MAX_TOKENS', 32000),
            'timeout_seconds' => (int) env('MOTO_REFERENCE_TIMEOUT_SECONDS', 600),
            'schema_heading' => 'Output JSON Schema',
            'estimate_output_tokens_per_cell' => 1500,
            'share_angle_deg' => 15,
            'layouts' => [
                ['cells' => 1, 'size' => '2048x1152', 'rows' => 1, 'columns' => 1, 'cell' => ['width' => 2048, 'height' => 1152]],
                ['cells' => 4, 'size' => '2048x1152', 'rows' => 2, 'columns' => 2, 'cell' => ['width' => 1024, 'height' => 576]],
                ['cells' => 6, 'size' => '3840x2160', 'rows' => 2, 'columns' => 3, 'cell' => ['width' => 1280, 'height' => 1080]],
                ['cells' => 8, 'size' => '3840x2160', 'rows' => 2, 'columns' => 4, 'cell' => ['width' => 960, 'height' => 1080]],
                ['cells' => 10, 'size' => '3840x2160', 'rows' => 2, 'columns' => 5, 'cell' => ['width' => 768, 'height' => 1080]],
            ],
            'part_sheet' => [
                'grid' => ['min' => 2, 'max' => 10],
                'default' => ['rows' => 3, 'columns' => 6, 'size' => '3840x2160', 'quality' => 'high'],
                'canvas' => 'One image of :width x :height pixels divided into a grid of :rows rows and :columns columns, :cells cells of :cell_width x :cell_height pixels each; every cell is one separate picture, and nothing crosses a cell edge.',
                'source' => 'The attached image is the approved model sheet of the finished motorcycle, :rows rows by :columns columns of panels. Use it to recognise each part, its proportions and how it is built. The text of each cell decides shape, count and surface.',
                'lines' => 'Separate the cells with thin, straight, continuous white lines that stand out clearly from the grey background: one line across the whole image between every two rows, and one line down each whole row between every two columns. Every row holds all its columns, and a cell left empty keeps its place.',
                'rules' => [
                    'Each cell shows one part, finished as the design states, with every element its text lists, alone: never the whole motorcycle and never another part.',
                    'A "Do not draw" line names parts that only meet this part where it is mounted: none of them appears in the cell, whatever else the text of the cell mentions.',
                    'A smaller part with its own entry in the design stays out of the cell unless the text of the cell lists it; do not strip the part down, and do not invent an inside or a hidden face the text does not describe.',
                    'The part sits wholly inside its cell with plain background on every side, and never touches or crosses a line; no part appears in two cells.',
                    'Where the attached sheet and the text of a cell differ on shape, count or surface, the text wins.',
                    'Neutral light-grey studio background and the same soft even studio light in every cell; a light a cell names belongs to that part and is not the studio light.',
                    'No people, no hands, no tools, no text, labels, numbers or logos.',
                ],
                'labels' => [
                    'shape' => 'Shape:',
                    'elements' => 'Elements:',
                    'faces' => 'Faces:',
                    'size' => 'Size in the design pose:',
                    'surfaces' => 'Surfaces:',
                    'configuration' => 'Configuration:',
                    'lights' => 'Its own light fitting:',
                    'feature' => 'Feature:',
                    'must_keep' => 'Must keep:',
                    'must_not' => 'Must not:',
                    'not_draw' => 'Do not draw:',
                    'panels' => 'On the attached sheet it shows in:',
                    'no_panel' => 'No panel of the attached sheet shows this part clearly: take its shape from this text; the sheet gives only its context.',
                    'view' => 'View:',
                    'top_view' => 'from straight above (elevation :elevation°), the end that faces the front of the motorcycle toward the top of the cell',
                ],
            ],
            'surface_groups' => [
                'frame' => 'frame',
                'swingarm' => 'frame',
                'fuel_tank' => 'bodywork',
                'front_fender' => 'bodywork',
                'rear_fender' => 'bodywork',
                'side_panel' => 'bodywork',
                'fairing' => 'bodywork',
                'engine' => 'engine',
                'front_wheel' => 'wheels',
                'rear_wheel' => 'wheels',
            ],
            'finish_labels' => [
                'raw_metal' => 'raw metal, not yet finished',
                'being_made' => 'unfinished, still being made',
                'primer' => 'in primer',
                'digital' => 'digital model surface, not a physical material',
                'physical_model' => 'surface of the physical model not established',
                'unknown' => 'finish at this point not established',
                'named_finish' => ':finish, not yet finished',
            ],
            'prompt' => [
                'styles' => [
                    'physical' => 'Technical reference sheet for one custom motorcycle build: photorealistic studio photographs of real parts, true perspective, at full scale',
                    'digital' => 'Technical reference sheet for one digital model of a custom motorcycle: clean renders of the 3D model itself with plain model shading and no photographic materials, true perspective; show the model directly, never a screen or monitor showing it',
                ],
                'canvas' => 'One image of :width x :height pixels divided into a grid of :rows rows and :columns columns, :cells cells of :cell_width x :cell_height pixels each; every cell is one separate picture that fills its own cell, and nothing crosses a cell edge.',
                'canvas_single' => 'One image of :width x :height pixels: a single picture that fills the whole image, with no grid and no panels. Below, the cell means this picture.',
                'sources' => [
                    'grey' => 'The attached image is the approved model sheet of the finished motorcycle (panels MS1–MS8), converted to greyscale. Use it only for shape, proportion, construction, handedness and where each part sits. Its tones are never evidence of colour or finish: take every surface from the text of each cell.',
                    'colour' => 'The attached image is the approved model sheet of the finished motorcycle (panels MS1–MS8). Use it for shape, proportion, construction, handedness and where each part sits, and for colour and finish only where the text of a cell names that finish.',
                ],
                'rules' => [
                    'Show in each cell only the parts that cell lists; a context part appears only as far as it places the subject.',
                    'Keep every part\'s shape, handedness and asymmetry as on the model sheet; never mirror a part.',
                    'The model sheet shows the finished motorcycle: where a cell says a part is unfinished, off the motorcycle or not present, the cell wins over the model sheet.',
                    'Add no detail, fastener, internal part, marking or wear that the cell does not name.',
                    'Neutral light-grey studio background, soft even light; no people, no hands, no tools, no text, labels, numbers or logos.',
                ],
                'empty' => 'Cells left empty show only the plain neutral background.',
                'view' => [
                    'sides' => [
                        0 => 'from the front',
                        45 => 'from the front, toward the rider\'s left',
                        90 => 'from the rider\'s left side',
                        135 => 'from the rear, toward the rider\'s left',
                        180 => 'from the rear',
                        225 => 'from the rear, toward the rider\'s right',
                        270 => 'from the rider\'s right side',
                        315 => 'from the front, toward the rider\'s right',
                    ],
                    'no_side' => 'the part is off the motorcycle, so no side of the motorcycle applies',
                    'own_angle' => 'the angle is described in the framing line below',
                    'level' => 'camera level with the subject',
                    'slightly_above' => 'camera slightly above, looking a little down',
                    'above' => 'camera above, looking down',
                    'overhead' => 'camera almost straight overhead',
                    'below' => 'camera below, looking up',
                    'scales' => [
                        'macro' => 'macro: one small detail fills the cell',
                        'close' => 'close: the listed parts fill the cell from edge to edge and nothing else of the motorcycle fits in it',
                        'medium' => 'medium: the listed parts with a little room around them',
                        'wide' => 'wide: the whole subject with space around it',
                    ],
                ],
                'labels' => [
                    'cell' => 'Cell',
                    'position' => 'row :row, column :column',
                    'parts' => 'Parts:',
                    'stage' => 'Build stage:',
                    'done' => 'Work already done:',
                    'pending' => 'Work not yet done, so nothing of it shows:',
                    'first_frame' => 'At the first frame of the shot:',
                    'pose' => 'In the shot the camera is:',
                    'decided' => 'Decided:',
                    'subject' => 'Subject:',
                    'context' => 'Context, only to place the subject:',
                    'faces' => 'Faces in view:',
                    'size' => 'Size:',
                    'elements' => 'Elements:',
                    'surfaces' => 'Surfaces at this point:',
                    'relation' => 'Relation:',
                    'mount' => 'Mounting:',
                    'view' => 'View:',
                    'must_show' => 'Must show:',
                    'absent' => 'Not present at this point:',
                ],
            ],
        ],

        'model_sheet' => [
            'suffix' => 'neutral light-grey studio background, soft even light, no text',
            'views' => [
                ['id' => 'MS1', 'view' => '3/4 front view from the rider\'s left, eye level'],
                ['id' => 'MS2', 'view' => 'left side profile, exact side view'],
                ['id' => 'MS3', 'view' => 'right side profile, exact side view'],
                ['id' => 'MS4', 'view' => 'front head-on'],
                ['id' => 'MS5', 'view' => 'rear head-on'],
                ['id' => 'MS6', 'view' => 'top-down plan view'],
                ['id' => 'MS7', 'view' => '3/4 rear view from the rider\'s right, eye level'],
                ['id' => 'MS8', 'view' => 'close-up chosen from the design'],
            ],
            'cameras' => [
                'MS1' => ['azimuth_deg' => 45, 'elevation_deg' => 8, 'projection' => 'perspective', 'lens_mm' => 85],
                'MS2' => ['azimuth_deg' => 90, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS3' => ['azimuth_deg' => 270, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS4' => ['azimuth_deg' => 0, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS5' => ['azimuth_deg' => 180, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS6' => ['azimuth_deg' => null, 'elevation_deg' => 90, 'projection' => 'orthographic', 'front' => 'toward the top of the panel'],
                'MS7' => ['azimuth_deg' => 225, 'elevation_deg' => 8, 'projection' => 'perspective', 'lens_mm' => 85],
                'MS8' => ['azimuth_deg' => null, 'elevation_deg' => null, 'projection' => 'perspective', 'lens_mm' => 85, 'target' => 'a close-up chosen from the design: a signature feature or the detail that best shows how the build is made'],
            ],
            'camera_convention' => 'Azimuth is measured around the motorcycle from its front: 0 = front, 90 = the rider\'s left side, 180 = rear, 270 = the rider\'s right side; left and right are the rider\'s, seated facing forward. Elevation 0 = eye level, 90 = straight down.',
            'occlusion' => 'Keep every part at its stated shape, size and position. Where this camera cannot see a part, leave it hidden; do not move, duplicate, enlarge or make anything transparent to reveal it.',
            'master' => [
                'execution' => 'moto-model-sheet-v1',
                'size' => '3840x2160',
                'variations' => 1,
                'columns' => 4,
                'rows' => 2,
                'cell' => ['width' => 960, 'height' => 1080],
                'order' => ['MS1', 'MS2', 'MS3', 'MS4', 'MS5', 'MS6', 'MS7', 'MS8'],
                'detail_views' => ['MS8'],
                'layout_guide' => false,
                'prompt' => [
                    'layout' => 'ONE image of {width}x{height} pixels: a model sheet of this one motorcycle in {rows} rows by {columns} columns of equal panels, each panel {cell_width}x{cell_height} pixels, covering the whole image edge to edge with no title strip and no caption area.',
                    'panel' => 'Row {row}, column {column} ({x}-{x_end} px across, {y}-{y_end} px down)',
                    'framing' => 'In every whole-motorcycle panel the complete motorcycle required by that camera, from the front tyre to the rear tyre and from the ground to the highest point, is inside the panel with a margin of plain studio background on every side; it never touches or crosses into another panel.',
                    'detail_framing' => 'The close-up panel is a detail view and may crop close on its subject.',
                    'rules' => [
                        'All eight panels show the same motorcycle with the same design, proportions, colours, materials and finish, in the same displayed configuration, under the same studio background and the same lighting.',
                        'Keep the rider\'s left and right exactly as each camera states; do not mirror a panel.',
                        'Do not add, remove, move or reshape any part between panels.',
                        'No rider, no people, no text, letters, numbers, labels, titles, logos, badges, panel borders or dividing lines anywhere in the image.',
                        'A scale described on a part is kept as plain graduation marks, with no lettering or numbers.',
                    ],
                    'presentation' => 'Studio presentation: the complete finished motorcycle stands upright on a level studio floor, both tyres on the ground. When the side stand is up, a plain low studio stand under the frame holds it upright; the stand is not part of the motorcycle. Nothing is cut open or made transparent.',
                    'guide_caption' => 'The attached image is only a layout guide showing the position of the eight panels; it contains no motorcycle. Place each view inside its panel and do not draw the guide lines.',
                    'max_chars' => 27000,
                ],
            ],
        ],
    ],

    'superyacht' => [
        'variables' => [
            'languages' => ['en'],
        ],

        'dna' => [
            'model' => env('SUPERYACHT_DNA_MODEL', 'opus55'),
            'effort' => env('SUPERYACHT_DNA_EFFORT', 'high'),
            'max_tokens' => (int) env('SUPERYACHT_DNA_MAX_TOKENS', 32000),
            'timeout_seconds' => (int) env('SUPERYACHT_DNA_TIMEOUT_SECONDS', 1200),
            'stream' => true,
            'retry_times' => 0,
        ],

        'design' => [
            'schema_heading' => 'Output JSON Schema',
            'model' => env('SUPERYACHT_DESIGN_MODEL', 'sonnet55'),
            'effort' => env('SUPERYACHT_DESIGN_EFFORT', 'high'),
            'max_tokens' => (int) env('SUPERYACHT_DESIGN_MAX_TOKENS', 128000),
            'timeout_seconds' => (int) env('SUPERYACHT_DESIGN_TIMEOUT_SECONDS', 3600),
            'stream' => true,
            'retry_times' => 0,
            'adapters' => [
                'superyacht_v6_3' => \App\Video\YachtDesign\SuperyachtDesign::class,
                'harley_custom_v1' => \App\Video\Moto\MotoDesign::class,
            ],
        ],

        'design_check' => [
            'profile_margin_m' => 0.2,
            'near_x' => 0.005,
            'near_z_m' => 0.3,
        ],

        'script' => [
            'execution' => 'db-phase-4-script-v3',
            'model' => env('SUPERYACHT_SCRIPT_MODEL', 'sonnet55'),
            'effort' => env('SUPERYACHT_SCRIPT_EFFORT', 'high'),
            'max_tokens' => (int) env('SUPERYACHT_SCRIPT_MAX_TOKENS', 64000),
            'timeout_seconds' => (int) env('SUPERYACHT_SCRIPT_TIMEOUT_SECONDS', 900),
            'brief' => [
                'video_length' => 30,
                'aspect_ratio' => '9:16',
                'platform' => 'YouTube Shorts, Facebook Reels',
                'tone_notes' => '',
            ],
            'brief_options' => [
                'video_length' => [30, 30],
                'aspect_ratios' => ['9:16', '16:9', '1:1', '4:5'],
                'platform_max' => 120,
                'tone_notes_max' => 500,
            ],
            'history' => ['enabled' => false],
            'check' => [
                'final_cut_sec' => 30,
                'length_tolerance_sec' => 0.05,
                'tour' => ['min_sec' => 8, 'max_sec' => 10, 'location_max_sec' => 1.5],
                'loop_min_distance' => 0.2,
                'progress_max_share' => 0.15,
                'card_seconds_per_card' => 10,
                'card_max_words' => 6,
                'name_card_from' => 'B5',
                'warn' => [
                    'beat_min_sec' => 0.8,
                    'hero_min_sec' => 2,
                    'hero_card_share' => 0.5,
                    'construction_run' => 2,
                    'money_shots_outside' => 2,
                ],
            ],
        ],

        'locations' => [
            'execution' => 'db-phase-5-locations-v4',
            'schema_heading' => 'Output JSON Schema',
            'model' => env('SUPERYACHT_LOCATIONS_MODEL', 'sonnet55'),
            'effort' => env('SUPERYACHT_LOCATIONS_EFFORT', 'high'),
            'efforts' => ['high', 'medium'],
            'max_tokens' => (int) env('SUPERYACHT_LOCATIONS_MAX_TOKENS', 64000),
            'timeout_seconds' => (int) env('SUPERYACHT_LOCATIONS_TIMEOUT_SECONDS', 900),
            'semantic_review' => 'manual',
            'check' => [
                'clearance_m' => [
                    'full_yacht' => ['length' => 20, 'width' => 10, 'height' => 8],
                    'component' => ['length' => 0, 'width' => 0, 'height' => 0],
                    'scale_model' => ['length' => 0, 'width' => 0, 'height' => 0],
                ],
                'enclosed_kinds' => ['studio', 'visualization_room', 'test_tank', 'construction_hall', 'paint_hall'],
                'open_water_kinds' => ['sea', 'anchorage'],
                'short_beat_sec' => 2,
                'short_beat_max_must_show' => 1,
                'details_only_reveals' => ['H1'],
                'unsourced_luxury' => ['marble', 'gold', 'gilded', 'brass', 'crystal', 'chandelier', 'onyx', 'velvet', 'chrome', 'mirror-polished', 'lacquer'],
                'night_words' => ['night', 'dusk', 'blue hour', 'twilight', 'dawn', 'pre-dawn', 'evening', 'after dark', 'sunset'],
                'anchor_ignore_words' => ['combines', 'crowns', 'enters', 'follows', 'occupy', 'receives', 'supplies', 'contains', 'meets', 'punctures', 'sits'],
                'token_min_length' => 5,
                'generic_words' => [
                    'floor', 'floors', 'walls', 'ceiling', 'ceilings', 'panel', 'panels', 'upholstery', 'joinery', 'hardware', 'fittings', 'screens',
                    'table', 'chairs', 'rails', 'seats', 'seating', 'lining', 'linings', 'treads', 'inserts', 'benches', 'cushions', 'frames', 'consoles',
                    'controls', 'drains', 'pads', 'water', 'light', 'above', 'below', 'beside', 'between', 'through', 'under', 'along', 'beyond', 'their',
                    'there', 'which', 'while', 'without', 'within', 'where', 'across', 'around', 'separate', 'single', 'small', 'large', 'narrow',
                ],
            ],
            'reference' => [
                'task' => 'location_reference',
                'labels' => [
                    'space' => 'Space:',
                    'dimensions' => 'Size:',
                    'clear_height' => 'Clear height under the lowest overhead structure:',
                    'deck' => 'Deck:',
                    'state' => 'State:',
                    'surface' => 'Surface:',
                    'materials' => 'Materials on their surfaces:',
                    'window' => 'Windows and openings:',
                    'signature' => 'Signature detail:',
                    'feature' => 'Design feature:',
                    'elements' => 'Visible elements:',
                    'effect' => 'Design effect:',
                    'keep' => 'Must keep:',
                    'built_ins' => 'Built-in pieces:',
                    'furniture' => 'Furniture:',
                    'room_layout' => 'Room layout:',
                    'lighting' => 'Fixed lights:',
                    'lighting_signature' => 'Designed lighting:',
                    'detail' => 'State detail:',
                    'layout' => 'Arranged in the space:',
                    'condition' => 'Condition:',
                    'surfaces' => 'Surfaces:',
                    'equipment' => 'Fixed equipment:',
                    'props' => 'Props:',
                    'palette' => 'Colour palette:',
                    'composition' => 'Composition:',
                    'light' => 'Light:',
                    'environment' => 'Seen beyond the space:',
                    'must_show' => 'Must show:',
                    'avoid' => 'Avoid:',
                ],
                'style' => [
                    'location' => 'Photorealistic documentary photograph of a working place where superyachts are designed, built or tested: true perspective, real equipment and materials at full scale, one still frame',
                    'open_water' => 'Photorealistic marine landscape photograph: true horizon and natural perspective, real water, light and terrain at full scale, one still frame',
                    'interior' => 'Photorealistic interior photograph of a superyacht: true architectural perspective with straight verticals, every material rendered with its real texture, grain and finish, precise joints, reveals and shadow lines, one still frame',
                    'exterior' => 'Photorealistic exterior photograph of this area of a superyacht: the exact form and proportions of the design, every surface rendered with its real texture and finish, one still frame',
                ],
                'suffix' => [
                    'location' => 'No yacht, no people, no text, no signage, no logos',
                    'interior' => 'No people, no text, no signage, no logos',
                    'exterior' => 'No people, no text, no signage, no logos',
                ],
            ],
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
                ['id' => 'MS8', 'view' => 'close-up chosen from the design'],
            ],
            // Thong so camera gui Sonnet; Sonnet viet cau camera vao final_prompt va khong doi MS1-MS7.
            'cameras' => [
                'MS1' => ['azimuth_deg' => 90, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS2' => ['azimuth_deg' => 45, 'elevation_deg' => 3, 'projection' => 'perspective', 'lens_mm' => 85],
                'MS3' => ['azimuth_deg' => 225, 'elevation_deg' => 3, 'projection' => 'perspective', 'lens_mm' => 85],
                'MS4' => ['azimuth_deg' => 0, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS5' => ['azimuth_deg' => 180, 'elevation_deg' => 0, 'projection' => 'orthographic'],
                'MS6' => ['azimuth_deg' => null, 'elevation_deg' => 90, 'projection' => 'orthographic', 'bow' => 'toward the top of the panel'],
                'MS7' => ['azimuth_deg' => 45, 'elevation_deg' => 35, 'projection' => 'perspective', 'lens_mm' => 85],
                'MS8' => ['azimuth_deg' => null, 'elevation_deg' => null, 'projection' => 'perspective', 'lens_mm' => 85, 'target' => 'a close-up chosen from the design: its own MS8 guidance first, including notes; never a default innovation'],
            ],
            'camera_convention' => 'Azimuth is measured around the yacht from the bow: 0 = bow, 90 = port, 180 = stern, 270 = starboard. Elevation 0 = eye level, 90 = straight down.',
            /*
             * Model sheet master: MOT request AI tao MOT anh 3840x2160, luoi 2 hang x 4 cot,
             * roi may chu cat tam o 960x1080 theo toa do co dinh. Luot render dung prompt QA da dat. Nhan va duong phan cach do giao dien
             * ve de len; anh nguon giu sach chu.
             *
             * Lech spec v6.3 Phase 3 Step 10 (spec render MS1 truoc roi MS2-MS8 theo MS1): o day
             * tam goc sinh cung mot luot.
             */
            'master' => [
                // v3: Sonnet viet toan bo prompt (final_prompt); he thong luu, hien va gui nguyen van.
                'execution' => 'model-sheet-master-v3',
                // Bo da duyet o cac execution nay van la model sheet hop le.
                'supported_executions' => ['model-sheet-master-v1', 'model-sheet-master-v2', 'model-sheet-master-v3'],
                'size' => '3840x2160',
                'variations' => 1,
                'columns' => 4,
                'rows' => 2,
                'cell' => ['width' => 960, 'height' => 1080],
                // Thu tu o theo hang, trai sang phai: hang tren MS1-MS4, hang duoi MS5-MS8.
                'order' => ['MS1', 'MS2', 'MS3', 'MS4', 'MS5', 'MS6', 'MS7', 'MS8'],
                // Goc chi tiet: duoc phep cat can canh, khong doi chua tron tau.
                'detail_views' => ['MS8'],
                // Tuy chon thu nghiem: gui kem anh mau luoi trong (khong co hinh hoc tau) de
                // huong dan vi tri tam o. Chua co bang chung tot hon prompt thuan chu.
                'layout_guide' => (bool) env('MODEL_SHEET_LAYOUT_GUIDE', false),
                'guide_background' => '#D9D9D9',
                'guide_line' => '#BDBDBD',
                // Quy tac co dinh (fixed_rules) gui Sonnet; Sonnet dua vao final_prompt dung mot lan, code khong chen lai.
                'prompt' => [
                    'layout' => 'ONE image of {width}x{height} pixels: a model sheet of this one yacht in {rows} rows by {columns} columns of equal panels, each panel {cell_width}x{cell_height} pixels, covering the whole image edge to edge with no title strip and no caption area.',
                    'panel' => 'Row {row}, column {column} ({x}-{x_end} px across, {y}-{y_end} px down): {id}, {view}.',
                    'framing' => 'In every whole-yacht panel the complete yacht required by that camera is inside the panel with a margin of plain studio background on every side; it never touches or crosses into another panel.',
                    'detail_framing' => 'Panel {id} is a detail view and may crop close on its subject.',
                    'rules' => [
                        'All eight panels show the same yacht with the same design, proportions, number of decks, colors, materials and finish state, under the same studio background and the same lighting.',
                        'Keep port and starboard exactly as each camera states; do not mirror a panel.',
                        'Do not add, remove, move or reshape any part between panels.',
                        // Khong cam "frames": khung guong, khung cua la mot phan cua thiet ke.
                        'No text, letters, numbers, labels, titles, logos, panel borders or dividing lines anywhere in the image.',
                    ],
                    'presentation' => 'Studio presentation: the complete finished yacht stands clear of the water, so its whole outer hull down to the keel is visible; nothing is cut open or made transparent.',
                    'guide_caption' => 'The attached image is only a layout guide showing the position of the eight panels; it contains no yacht. Place each view inside its panel and do not draw the guide lines.',
                    // Do dai toi da Sonnet phai giu cho final_prompt.
                    'max_chars' => 20000,
                ],
            ],

            /*
             * Prompt model sheet: Sonnet 5.5 doc ban thiet ke day du mot lan, ban nhap, chi muc,
             * camera va quy tac co dinh, viet toan bo final_prompt va tu kiem (schema trong
             * ModelSheetQaSchema). Code chi kiem ky thuat va luu nguyen van. Prompt QA doc tu DB:
             * phase `phase_code` cua workflow theo category.
             */
            'qa' => [
                'execution' => 'model-sheet-qa-v2',
                'phase_code' => 'phase-3-model-sheet',
                'model' => env('MODEL_SHEET_QA_MODEL', 'claude-sonnet-5-5'),
                'effort' => env('MODEL_SHEET_QA_EFFORT', 'high'),
                // Tinh ca thinking: luot that dau tien dung ~24k thinking + ~12k JSON, vuot tran 32k.
                'max_tokens' => (int) env('MODEL_SHEET_QA_MAX_TOKENS', 64000),
                // Uoc tinh hien tren nut QA, theo luot do that (50k vao, ~36k ra gom thinking).
                'estimate_tokens' => ['input' => 50000, 'output' => 36000],
                'timeout_seconds' => (int) env('MODEL_SHEET_QA_TIMEOUT_SECONDS', 900),
                'stream' => true,
                // false: schema dau ra nam trong prompt, may chu kiem cau truc (ModelSheetQaSchema).
                'enforce_schema' => (bool) env('MODEL_SHEET_QA_ENFORCE_SCHEMA', false),
                // Gia theo ClaudeWriterService::MODEL_CATALOG['sonnet55'].
                'pricing' => ['input_usd_per_mtok' => 2.00, 'output_usd_per_mtok' => 10.00, 'version' => 'anthropic-sonnet55-2026-10'],
            ],

            'occlusion' => 'Keep every part at its stated shape, size and position. Where this camera cannot see a part, leave it hidden; do not move, duplicate, enlarge or make anything transparent to reveal it.',
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
                'moto' => 'harley_custom_v1',
            ],
            'workflows' => [
                'harley_custom_v1' => [
                    'actions' => [
                        'spec.inspiration' => [
                            'phase' => 'phase-1',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'outputs' => [
                                'evidence' => ['namespace' => 'moto', 'schema' => 'MotoEvidence', 'version' => '1.0.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 1 Output (format moto-evidence-v1): the source motorcycle with its platform, the base apart from the build, styling, chassis, powertrain, technology, specs, intent, visuals, claims, issues and the eight self-checks; release database/releases/moto-phase-1-inspiration-v1'],
                            ],
                        ],
                        'spec.dna' => [
                            'phase' => 'phase-2',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'outputs' => [
                                'dna' => ['namespace' => 'moto', 'schema' => 'MotoDna', 'version' => '1.1.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 2 Output (format moto-dna-v1): the resolved brief, theme, emotion, departures, kept base features, 1-3 signature features, silhouette, must-avoid list and hero moment of a muscle-bobber concept; release database/releases/moto-phase-2-dna-v2'],
                            ],
                        ],
                        'spec.design' => [
                            'phase' => 'phase-3',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['design'],
                            ],
                            'outputs' => [
                                'design' => ['namespace' => 'moto', 'schema' => 'MotoDesign', 'version' => '1.0.1', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 3 Output (format moto-design-v1): one object-based Harley-Davidson custom design record — motorcycle, parts, systems, rider interface, connections, features, operational limits, build states, identity rules, source issues and the model sheet with its eight views and final prompt; release database/releases/moto-phase-3-design-v8'],
                            ],
                        ],
                        'spec.script' => [
                            'phase' => 'phase-4',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['script'],
                            ],
                            'outputs' => [
                                'script' => ['namespace' => 'moto', 'schema' => 'MotoScript', 'version' => '1.0.2', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 4 Output (format moto-script-v1): the story, the builder and rider, the flexible beats B1…Bn with build state, time jump, design ids, configurations and situation, the text cards with their sources (the DISCLAIMER card carries the fixed text of the schema) and the music brief; release database/releases/moto-phase-4-script-v8'],
                            ],
                        ],
                        'spec.locations' => [
                            'phase' => 'phase-5',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['locations'],
                            ],
                            'outputs' => [
                                'locations' => ['namespace' => 'moto', 'schema' => 'MotoLocations', 'version' => '1.0.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 5 Output (format moto-locations-v1): the places LOCxx the approved script uses and the background plates BGxx rendered once and shared by its beats, with their support, ground, light, composition and shot changes; unplated beats, conflicts with their evidence and blocking, and continuity; the motorcycle, its parts, mockups, drawings and people are added at the shot step; release database/releases/moto-phase-5-locations-v1'],
                            ],
                        ],
                        'spec.scenes' => [
                            'phase' => 'phase-6',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['scenes'],
                            ],
                            'outputs' => [
                                'scenes' => ['namespace' => 'moto', 'schema' => 'MotoScenes', 'version' => '1.1.2','pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 6 Output (format moto-scenes-v1): the approved beats grouped into scenes SCxx of consecutive beats in one place, continuous story time and one established build state, each with its purpose, set brief, starting and ending continuity (placement, detached parts, lights on, surface condition, protection per character, weather), continuity link, incoming transition with its evidence and the work its beats show on parts (operation, parts worked with it, place on or off the motorcycle at the start and end, evidence from the beat); conflicts with their evidence and blocking; timing, environment assignments, characters, configurations and states stay with the beats and are derived by code; release database/releases/moto-phase-6-scenes-v3'],
                            ],
                        ],
                        'spec.shots' => [
                            'phase' => 'phase-7',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['shots'],
                            ],
                            'outputs' => [
                                'shots' => ['namespace' => 'moto', 'schema' => 'MotoShots', 'version' => '1.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 7 Output (format moto-shots-v1): the shots SCxx_SHyy of every approved scene as data — beat coverage with screen time and result time, purpose, parts and their presentation from the scene plan\'s work or the build state, parts a beat keeps out of frame with their effect, the work items it shows by id, people as hands, back, silhouette or with the face hidden, a numeric camera, first frame, action, result, end frame for first_last, continuity, environment (background, unplated or a requested background), reference needs, render length, mode and kept interval, links and conflicts; code derives timing, sources, reference candidates, readiness, absent parts, limits, cards and the image and video prompts; release database/releases/moto-phase-7-shots-v5'],
                            ],
                        ],
                        'spec.reference' => [
                            'phase' => 'phase-8',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['reference'],
                            ],
                            'outputs' => [
                                'reference' => ['namespace' => 'moto', 'schema' => 'MotoReferencePrompt', 'version' => '1.2.1', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Moto Phase 8 Output (format moto-reference-prompt-v3): for each reference cell code planned, whether its draft lines stay as code wrote them or are revised, the revised lines with the section of the cell they belong to, the draft lines they replace and the facts that support them, what was left out of the facts that do not hold at this moment and why, and the facts the cell needs but the sources do not give; code keeps the cell ids, layout, parts, state, checkpoint and angles, checks every line against the facts and assembles the master prompt section by section; seeder data database/seeders/data/ai_phases_moto_phase_8_v4.php'],
                            ],
                        ],
                    ],
                ],
                'superyacht_v6_3' => [
                    'actions' => [
                        'spec.inspiration' => [
                            'phase' => 'phase-1',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'outputs' => [
                                'evidence' => ['namespace' => 'superyacht', 'schema' => 'Phase1Evidence', 'version' => '2.0.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 1 Output (format phase1-evidence-v2): the subject of the article, its identity, exterior, interior, technology, scale, intent, captions and claims, each value with verbatim evidence and its source tag, the open source issues and the eight self-checks; release database/releases/phase-1-inspiration-v3'],
                            ],
                        ],
                        'spec.dna' => [
                            'phase' => 'phase-2',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'outputs' => [
                                'dna' => ['namespace' => 'superyacht', 'schema' => 'Phase2Dna', 'version' => '2.0.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 2 Output (format phase2-dna-v2): the design DNA — theme, departures from Phase 1 ids, exactly three innovations, silhouette, must-avoid list, hero moment and emotion — and the ten self-checks the writer ran and repaired before answering; release database/releases/phase-2-design-dna-v4'],
                            ],
                        ],
                        'spec.design' => [
                            'phase' => 'phase-3',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '11',
                            'stages' => [
                                'draft' => ['design'],
                            ],
                            'outputs' => [
                                'design' => ['namespace' => 'superyacht', 'schema' => 'Phase3Design', 'version' => '3.2.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 3 Output (format phase3-design-v3): the whole answer in one schema — the envelope with its status and flags, and one object-based design record: vessel, parts with their configurations and lights, decks, spaces, connections, routes, innovations with their modes, operational limits, build states from studio to launch (BS01–BS05 design development, BS06–BS12 construction and completion), identity rules, source issues and the model sheet with its eight views and final prompt; design prose sized to its object, without word limits; release database/releases/phase-3-design-v9'],
                            ],
                            'records' => [
                                'lock/design' => ['namespace' => 'superyacht', 'schema' => 'LockRecord', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'match' => ['/lock_type' => ['design']], 'source' => 'Pipeline map: DESIGN LOCK (lock record); Phase 3 Step 11 design lock; LockRecord.lock_type design'],
                                'review/decision' => ['namespace' => 'superyacht', 'schema' => 'ReviewDecision', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => false, 'match' => ['/item_type' => ['canonical', 'model_sheet']], 'source' => 'ReviewDecision.item_type canonical, model_sheet; reason DESIGN_LOCK'],
                            ],
                        ],
                        'spec.script' => [
                            'phase' => 'phase-4',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '2',
                            'stages' => [
                                'draft' => ['script'],
                            ],
                            'outputs' => [
                                'script' => ['namespace' => 'superyacht', 'schema' => 'Phase4Script', 'version' => '2.1.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 4 Output (format phase4-script-v2): the whole envelope with data.story (spine), data.anchors.character within the x-word-limits of the schema and data.script (17 beats, tour); release database/releases/phase-4-script-v3'],
                            ],
                        ],
                        'spec.locations' => [
                            'phase' => 'phase-5',
                            'instructions' => [],
                            'executor' => 'none',
                            'model_profile' => null,
                            'contract_version' => '1',
                            'stages' => [
                                'draft' => ['locations'],
                            ],
                            'outputs' => [
                                'locations' => ['namespace' => 'superyacht', 'schema' => 'Phase5Locations', 'version' => '4.0.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 5 Output (format phase5-locations-v4): in one answer the places outside the yacht with their fixed description and fit, and the shared background plates the approved script needs, each from a design id, a design id mapped with evidence, or a location of the same answer, with the design features (by the relation the design gives the space) and night lighting they show; unplated beats, unresolved mappings, conflicts and continuity; release database/releases/phase-5-locations-v12'],
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
                                'compiled_shots' => ['namespace' => 'superyacht', 'schema' => 'CompiledShot', 'version' => '6.3.1', 'pointer' => '/data/compiled_shots', 'mode' => 'each_item', 'required' => true, 'items' => ['count' => 1, 'key' => 'shot_id'], 'source' => 'Phase 0 Step 6 data contract: compiled_shots[] (Phase 6C); Phase 6C Output is one item (schema 5); one shot per task'],
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
                        ['phase' => 'phase-7', 'role' => 'input', 'slot' => 'compiled_shot', 'namespace' => 'superyacht', 'schema' => 'CompiledShot', 'version' => '6.3.1', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 7 Input (v6.3): frozen CompiledShots (Phase 6C)'],
                        ['phase' => 'phase-7', 'role' => 'input', 'slot' => 'render_request', 'namespace' => 'superyacht', 'schema' => 'RenderRequest', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 7 Input (v6.3): a frozen RenderRequest built from them for each call'],
                        ['phase' => 'phase-8', 'role' => 'input', 'slot' => 'compiled_shot', 'namespace' => 'superyacht', 'schema' => 'CompiledShot', 'version' => '6.3.1', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Phase 8 v6: motion prompts, references and tool parameters come from the frozen compiled spec (Phase 6C)'],
                        ['phase' => 'phase-8', 'role' => 'input', 'slot' => 'render_request', 'namespace' => 'superyacht', 'schema' => 'RenderRequest', 'version' => '6.3.0', 'pointer' => '', 'mode' => 'whole', 'required' => true, 'source' => 'Schema 8 RenderRequest: frozen right before each provider call, kind video / video_extend / video_edit'],
                    ],
                ],
            ],
        ],

        // Thu muc 8 file JSON Schema va base URI dung trong $id cua chung.
        'schema_dir' => resource_path('ai/superyacht/schemas'),
        'schema_base_uri' => 'https://superyacht.local/schemas/',
        'schema_versions' => ['CompiledShot' => '6.3.1'],
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
