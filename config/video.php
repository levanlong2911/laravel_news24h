<?php

return [
    // Phase 1 series length -- equivalent of the Python-side plan's PARTS_PER_TOPIC.
    'parts_per_topic' => env('VIDEO_PARTS_PER_TOPIC', 3),

    // How many articles the video:process-articles command processes per run --
    // bounded deliberately so one run can't balloon into an unbounded batch.
    'batch_size' => env('VIDEO_BATCH_SIZE', 20),

    // Used when a category's CategoryContext.art_style isn't configured yet --
    // keeps the pipeline runnable (and consistent within a single run) instead
    // of leaving image_prompt with no style direction at all.
    'default_art_style' => env('VIDEO_DEFAULT_ART_STYLE', 'cinematic photorealistic, high detail, dramatic lighting'),
];
