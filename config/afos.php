<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AFOS — AI Filmmaking Operating System
    |--------------------------------------------------------------------------
    |
    | Activation gate:
    |   AFOS_ENABLED=true   → route Kling shots through the AFOS IR pipeline
    |                          (Phase A: SimpleCompositionBuilder → SimpleCameraBuilder → KlingBackend)
    |                          instead of the legacy AST pipeline (KlingSerializer).
    |
    | Observability gate (independent of enabled):
    |   AFOS_TRACE=true     → dump every IR artifact to storage/app/afos-traces/{shot_id}/
    |                          Numbered JSON files: 001_shot_goal_ir.json, 002_composition_ir.json, …
    |                          Use for fault isolation: "is the bug in Planning or Backend?"
    |
    | Learning gates (3-phase rollout — activate in order):
    |   AFOS_LEARNING=false       → log ExperienceRecords only, never update pass parameters
    |   AFOS_LEARNING=shadow      → Experience Engine proposes parameter updates, compares offline
    |                               Does NOT apply updates to live pipeline
    |   AFOS_LEARNING=active      → Experience Engine updates pass parameters automatically
    |                               Activate only after shadow mode shows stable improvement
    |
    | Recommended startup sequence:
    |   1. AFOS_ENABLED=true, AFOS_TRACE=true, AFOS_LEARNING=false
    |   2. Run 100–300 renders, collect IR traces + QA metrics. Lock baseline.
    |   3. AFOS_LEARNING=shadow — propose updates, compare offline.
    |   4. AFOS_LEARNING=active — only after shadow shows consistent improvement.
    |
    */

    'enabled'  => env('AFOS_ENABLED',  false),

    'trace'    => env('AFOS_TRACE',    false),

    'learning' => env('AFOS_LEARNING', 'false'),  // 'false' | 'shadow' | 'active'

    /*
    |--------------------------------------------------------------------------
    | Cost Model — utility = quality - λ_latency × latency_sec - λ_cost × cost_usd
    |--------------------------------------------------------------------------
    |
    | Tuned by Experience Engine after baseline is locked.
    | Default values heavily favour quality during the benchmark phase.
    |
    */

    'cost_model' => [
        'lambda_latency' => env('AFOS_LAMBDA_LATENCY', 0.02),  // penalty per second of latency
        'lambda_cost'    => env('AFOS_LAMBDA_COST',    0.15),  // penalty per USD spent
    ],
];
