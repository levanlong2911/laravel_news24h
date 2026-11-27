<?php

$at = '2026-11-21 20:00:00';
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
  "format": "moto-film-edit-policy-v1",
  "schema": "MotoFilmEditPolicy@1.0.0",
  "edit_plan": "MotoFilmEditPlan@1.0.0",
  "origins": ["default"],
  "sequence": {
    "order": "approved_plan",
    "take": "approved_clip",
    "trim": "approved_used_interval",
    "transition": "approved_plan",
    "omissions": false
  },
  "audio_tracks": ["clip_audio"],
  "colour": ["none"],
  "text_tracks": ["script_cards"],
  "confirmation": "with_render_request"
}
JSON;

return [
    'schemas' => [
        $schema('5186e5cb-bb54-4d1e-beb4-2813b96828bf', 'MotoFilmEditPolicy', 'Moto Phase 11 — edit policy (moto-film-edit-policy-v1)', '1.0.0', 'MotoFilmEditPolicy-1.0.0.schema.json', 'What an edit plan of a film may decide, stored by phase-11-edit as its prompt_content and checked by PHP before an edit plan is taken; no model reads it. Only decisions this version carries out are accepted: the default plan code makes from the approved sources.', $at),
        $schema('80b603f6-6080-425d-b639-d94b4cc2236e', 'MotoFilmEditPlan', 'Moto Phase 11 — edit plan (moto-film-edit-plan-v1)', '1.0.0', 'MotoFilmEditPlan-1.0.0.schema.json', 'The decisions a film is cut from: its purpose, the segments in order with the take and the interval each keeps and the transition it comes in with, the shots it lacks with why, and its sound, colour and text tracks. Code writes the default one; who wrote and who confirmed a plan are recorded beside it by the server.', $at),
    ],
    'phases' => [
        [
            'id' => 'cfaefec5-5df6-4613-a9d9-9603886507a2',
            'category' => 'moto',
            'workflow_code' => 'harley_custom_v1',
            'phase_code' => 'phase-11-edit',
            'kind' => 'phase',
            'name' => 'Phase 11 — Edit plan policy (default plan made by code, no model)',
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
            'change_note' => 'moto-film-edit-policy-v1, read through action spec.film_edit with executor none: an edit plan is the default one code makes from the approved sources — the segments in the order of the approved shot plan, each from the clip approved for its shot, keeping the interval and the transition the shot plan names, no shot left out by decision; the sound of the clips only, no colour treatment, the cards of the pinned script word for word; the plan is confirmed, by its hash, in the request that cuts it. A plan from a person or a model is not taken by this revision. Code checks ids, hashes and intervals; it judges no wording.',
            'created_at' => $at,
            'published_at' => $at,
        ],
    ],
    'links' => [
        [
            'id' => '10fc3517-6f29-4eaa-9d50-bee94ebc643b',
            'phase_id' => 'cfaefec5-5df6-4613-a9d9-9603886507a2',
            'schema_id' => '5186e5cb-bb54-4d1e-beb4-2813b96828bf',
            'role' => 'input',
            'slot_key' => 'edit_policy',
            'data_pointer' => '',
            'validation_mode' => 'whole',
            'is_required' => 1,
            'created_at' => $at,
        ],
        [
            'id' => 'a9e2f145-7b52-41c4-a9e5-33cf5da6b26b',
            'phase_id' => 'cfaefec5-5df6-4613-a9d9-9603886507a2',
            'schema_id' => '80b603f6-6080-425d-b639-d94b4cc2236e',
            'role' => 'output',
            'slot_key' => 'edit_plan',
            'data_pointer' => '',
            'validation_mode' => 'whole',
            'is_required' => 1,
            'created_at' => $at,
        ],
    ],
];
