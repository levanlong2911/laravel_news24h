<?php

$at = '2026-11-08 18:00:00';
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
// The policy PHP reads from the DB; no model reads it. Its text is frozen here: a change is a new revision.
$policy = <<<'JSON'
{
  "format": "moto-film-policy-v1",
  "schema": "MotoFilmPolicy@1.0.0",
  "output_profile": "moto-portrait-1080p30-v1",
  "timeline": {
    "interval": "half_open",
    "quantization": "exact"
  },
  "picture": {
    "order": "approved_plan",
    "trim": "approved_used_interval",
    "retime": false,
    "crop": false
  },
  "transitions": {
    "allowed": ["cut", "match_cut", "time_jump", "dip"],
    "dip": {
      "colour": "black",
      "each_side_ms": 200,
      "overlap": false
    }
  },
  "audio": {
    "policy": "clip_only",
    "silent_clip": "silence",
    "boundary_treatment": "cut",
    "separate_tracks": false
  },
  "cards": {
    "text": "verbatim",
    "style_profile": "moto-card-style-v1",
    "reading_speed": {
      "unit": "words_per_second",
      "warning_above": 3
    }
  }
}
JSON;

return [
    'schemas' => [
        $schema('1c40933f-f464-45de-bff5-76718918d8dc', 'MotoFilmPolicy', 'Moto Phase 11 — film policy (moto-film-policy-v1)', '1.0.0', 'MotoFilmPolicy-1.0.0.schema.json', 'The cutting policy phase-11 stores as its prompt_content, checked by PHP before a film plan is compiled; no model reads it. Only values the first version cuts are accepted.', $at),
        $schema('19d24db5-a63a-4765-8d59-b7057f2bd616', 'MotoFilmPlan', 'Moto Phase 11 — film plan (moto-film-plan-v1)', '1.1.0', 'MotoFilmPlan-1.1.0.schema.json', 'Checked by PHP on a plan code compiles; no model writes it. One frame timeline [start, end) for the preview, the manifest, FFmpeg and the check; the plan keeps the policy, the output profile and the card style it was built with, the Phase 10 manifest by its hash and every card by its place in the pinned script. 1.0.0 stays as history.', $at),
    ],
    'phases' => [
        [
            'id' => 'c7f0db6c-0304-4eb1-aba4-ba092b2cd5bc',
            'category' => 'moto',
            'workflow_code' => 'harley_custom_v1',
            'phase_code' => 'phase-11',
            'kind' => 'phase',
            'name' => 'Phase 11 — Film policy (cut by PHP and FFmpeg, no model)',
            'sort_order' => 11,
            'needs' => null,
            'parallel_with' => null,
            'stop' => null,
            'status' => 'published',
            'source_file' => null,
            'source_section_key' => null,
            'created_by' => 'db:seed AiPhaseSeeder',
            'revision' => 1,
            'prompt_content' => $policy,
            'content_sha256' => hash('sha256', $policy),
            'is_current' => 1,
            'based_on_id' => null,
            'change_note' => 'moto-film-policy-v1, read through action spec.film with executor none: the clips in the order of the approved shot plan, each trimmed to the interval it keeps, no speed change and no crop; cut, match cut and time jump cut straight, a dip fades 200 ms to black and back inside the two clips; only the sound of the clips, silence for a silent clip; the cards of the script word for word in moto-card-style-v1, warning above 3 words a second; output moto-portrait-1080p30-v1. Code checks the data, the frames, the text layout, the files and what FFmpeg can do; it judges no wording.',
            'created_at' => $at,
            'published_at' => $at,
        ],
    ],
    'links' => [
        [
            'id' => 'e7c361eb-1897-45e7-b8d9-45db99e65917',
            'phase_id' => 'c7f0db6c-0304-4eb1-aba4-ba092b2cd5bc',
            'schema_id' => '1c40933f-f464-45de-bff5-76718918d8dc',
            'role' => 'input',
            'slot_key' => 'film_policy',
            'data_pointer' => '',
            'validation_mode' => 'whole',
            'is_required' => 1,
            'created_at' => $at,
        ],
        [
            'id' => 'b2bcf699-699d-4e59-9a93-029395e30947',
            'phase_id' => 'c7f0db6c-0304-4eb1-aba4-ba092b2cd5bc',
            'schema_id' => '19d24db5-a63a-4765-8d59-b7057f2bd616',
            'role' => 'output',
            'slot_key' => 'film_plan',
            'data_pointer' => '',
            'validation_mode' => 'whole',
            'is_required' => 1,
            'created_at' => $at,
        ],
    ],
];
