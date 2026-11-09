<?php

$at = '2026-11-07 12:00:00';
$revised = '2026-11-07 13:00:00';
$read = '2026-11-08 09:00:00';
$pinned = '2026-11-08 14:00:00';
$schema = static function (string $id, string $key, string $name, string $version, string $file, string $note, string $created): array {
    $path = 'resources/ai/moto/schemas/'.$file;
    $content = (string) file_get_contents(dirname(__DIR__, 3).'/'.$path);

    return [
        'id' => $id,
        'namespace' => 'moto',
        'schema_key' => $key,
        'version' => $version,
        'name' => $name,
        'schema_content' => $content,
        'schema_sha256' => hash('sha256', $content),
        'source_file' => $path,
        'json_schema_id' => 'https://superyacht.local/schemas/'.$key,
        'json_schema_dialect' => 'https://json-schema.org/draft/2020-12/schema',
        'dependencies' => '[]',
        'status' => 'published',
        'change_note' => $note,
        'created_by' => 'db:seed AiPhaseSeeder',
        'created_at' => $created,
        'published_at' => $created,
    ];
};
$checked = 'Checked by PHP on a plan code compiles; no model writes it.';
$first = <<<'TEXT'
The supplied image is the first frame.
Camera: {the camera move and its poses}.
Work: {each work item with a part that changes place, and only those parts}.
Action: {the action of the shot}.
Result: {the result of the shot}.
Timing: {when each result is visible, in seconds of the rendered clip}.
Ends on: {the end frame of the shot, when it has one}.
Positions at the end: {the positions the shot ends in}.
Parts at the end: {where the work leaves each part that changes place}.
Keep: {what the next shot starts from}.
Audio: natural ambient sound only, no music, no speech.
No face of any person, no readable text, no logo or brand, no morphing of the motorcycle, no object, tool or person the shot does not name, no change to a part the shot does not change, no change of build stage.
TEXT;
$second = <<<'TEXT'
The supplied image is the first frame.
Camera: {the camera move and its poses}.
Work: {each work item, with where its parts go, or where they stay when none changes place}.
Action: {the action of the shot}.
Result: {the result of the shot}.
Timing: {when each result is visible, in seconds of the rendered clip}.
Ends on: {the end frame of the shot, when it has one}.
Positions at the end: {the positions the shot ends in}.
Parts at the end: {where the work leaves each part that changes place}.
Keep: {what the next shot starts from}.
Audio: natural ambient sound only, no music, no speech.
No face of any person, no readable text, no logo or brand, no morphing of the motorcycle, no object, tool or person the shot does not name, no change to a part the shot does not change, no change of build stage.
TEXT;
$third = <<<'TEXT'
The supplied image is the first frame.
Create a {duration_seconds}-second clip.
Camera: {camera}.
Work: {work}.
Action: {action}.
Result: {result}.
Timing: {timing}.
Ends on: {end_frame}.
Positions at the end: {end_configuration}.
Parts at the end: {work_end}.
State at the end: {end_state}.
Audio: natural ambient sound only, no music, no speech.
No face of any person, no readable text, no logo or brand, no morphing of the motorcycle, no object, tool or person the shot does not name, no change to a part the shot does not change, no change of build stage.
TEXT;
$fourth = <<<'TEXT'
The supplied image is the first frame.
Create a {duration_seconds}-second clip.
At the start: {start_state}.
Camera: {camera}.
Work: {work}.
Action: {action}.
Result: {result}.
Timing: {timing}.
Ends on: {end_frame}.
Positions at the end: {end_configuration}.
Parts at the end: {work_end}.
State at the end: {end_state}.
Audio: natural ambient sound only, no music, no speech.
No face of any person, no readable text, no logo or brand, no morphing of the motorcycle, no object, tool or person the shot does not name, no change to a part the shot does not change, no change of build stage.
TEXT;
$phase = [
    'category' => 'moto',
    'workflow_code' => 'harley_custom_v1',
    'phase_code' => 'phase-10',
    'kind' => 'phase',
    'name' => 'Phase 10 — Clip prompt (compiled by PHP, no model)',
    'sort_order' => 10,
    'needs' => null,
    'parallel_with' => null,
    'stop' => null,
    'status' => 'published',
    'source_file' => null,
    'source_section_key' => null,
    'created_by' => 'db:seed AiPhaseSeeder',
];
$link = [
    'role' => 'output',
    'slot_key' => 'clip_plan',
    'data_pointer' => '',
    'validation_mode' => 'whole',
    'is_required' => 1,
];

return [
    'schemas' => [
        $schema('5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e710', 'MotoClipPlan', 'Moto Phase 10 — clip plan (moto-clip-plan-v1)', '1.0.0', 'MotoClipPlan.schema.json', $checked, $at),
        $schema('8d31f6a0-27c4-4e95-a1b8-6c04e9f3d211', 'MotoFilmPlan', 'Moto Phase 11 — film plan (moto-film-plan-v1)', '1.0.0', 'MotoFilmPlan.schema.json', $checked, $at),
        $schema('5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e711', 'MotoClipPlan', 'Moto Phase 10 — clip plan (moto-clip-plan-v1)', '1.1.0', 'MotoClipPlan-1.1.0.schema.json', $checked.' A plan now carries moto-clip-prompt-v2; a plan compiled before keeps version 1.0.0 and its own prompt.', $revised),
        $schema('5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e712', 'MotoClipPlan', 'Moto Phase 10 — clip plan (moto-clip-plan-v1)', '1.2.0', 'MotoClipPlan-1.2.0.schema.json', $checked.' A clip belongs to a shot and no longer to a render scene; the plan names the template it was compiled from, a prompt may be missing for a blocked shot, and a clip carries the hash of what the screen shows.', $read),
        $schema('5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e713', 'MotoClipPlan', 'Moto Phase 10 — clip plan (moto-clip-plan-v1)', '1.3.0', 'MotoClipPlan-1.3.0.schema.json', $checked.' A clip pins the file checks it will be judged by and the contract the sound policy, so the page hash covers both; every hash has the pattern of its format; a missing resolution or file check must say why it is blocked; a reason is one of a fixed list; scene_id is the scene code.', $pinned),
    ],
    'phases' => [
        $phase + [
            'id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c10',
            'revision' => 1,
            'prompt_content' => $first,
            'content_sha256' => hash('sha256', $first),
            'is_current' => 0,
            'based_on_id' => null,
            'change_note' => 'moto-clip-prompt-v1. A record of the template MotoShotPrompt::clip compiled from config; no model reads it.',
            'created_at' => $at,
            'published_at' => $at,
        ],
        $phase + [
            'id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c20',
            'revision' => 2,
            'prompt_content' => $second,
            'content_sha256' => hash('sha256', $second),
            'is_current' => 0,
            'based_on_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c10',
            'change_note' => 'moto-clip-prompt-v2. Every work item keeps its work: an item that moves no part, such as align_check, now says where its parts stay instead of dropping out. A record of the template MotoShotPrompt::clip compiled from config; no model reads it.',
            'created_at' => $revised,
            'published_at' => $revised,
        ],
        $phase + [
            'id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c30',
            'revision' => 3,
            'prompt_content' => $third,
            'content_sha256' => hash('sha256', $third),
            'is_current' => 0,
            'based_on_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c20',
            'change_note' => 'The template PHP reads through the resolver: spec.clips, executor none, no model. Named fields PHP fills from the approved shot; a line drops out when an optional field is empty. Adds the length of the clip, since Omni takes its length from the prompt alone, and says what the shot ends in instead of Keep. A change of words here needs no change of code.',
            'created_at' => $read,
            'published_at' => $read,
        ],
        $phase + [
            'id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c40',
            'revision' => 4,
            'prompt_content' => $fourth,
            'content_sha256' => hash('sha256', $fourth),
            'is_current' => 1,
            'based_on_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c30',
            'change_note' => 'Says what the shot starts from, as the shot plan gives it in continuity_start: a speed or a lean the first frame cannot show, such as 55 km/h with a 6 degree lean. The line drops out when the shot plan has none. Linked to MotoClipPlan 1.3.0.',
            'created_at' => $pinned,
            'published_at' => $pinned,
        ],
    ],
    'links' => [
        $link + [
            'id' => 'e24c9b17-6a05-4d38-8f12-b7a6d0c35f10',
            'phase_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c10',
            'schema_id' => '5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e710',
            'created_at' => $at,
        ],
        $link + [
            'id' => 'e24c9b17-6a05-4d38-8f12-b7a6d0c35f20',
            'phase_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c20',
            'schema_id' => '5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e711',
            'created_at' => $revised,
        ],
        $link + [
            'id' => 'e24c9b17-6a05-4d38-8f12-b7a6d0c35f30',
            'phase_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c30',
            'schema_id' => '5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e712',
            'created_at' => $read,
        ],
        $link + [
            'id' => 'e24c9b17-6a05-4d38-8f12-b7a6d0c35f40',
            'phase_id' => 'c10a7d52-3e41-4b6f-9a58-2f6d1e7b4c40',
            'schema_id' => '5b7e2c94-81d3-4f0a-b6e7-0a93c1d2e713',
            'created_at' => $pinned,
        ],
    ],
];
