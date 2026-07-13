<?php

return [
    'enabled' => env('FILMOS_ENABLED', false),

    'learning' => [
        // Normalization ceiling for cost efficiency score.
        // Provider cost (USD/video) above this → costEfficiency = 0.0
        // Adjust when average provider pricing changes significantly.
        'max_cost'    => (float) env('FILMOS_LEARNING_MAX_COST', 0.50),

        // Normalization ceiling for latency score (wall-clock render seconds).
        // Latency above this → latencyScore = 0.0
        'max_latency' => (float) env('FILMOS_LEARNING_MAX_LATENCY', 300.0),
    ],
];
