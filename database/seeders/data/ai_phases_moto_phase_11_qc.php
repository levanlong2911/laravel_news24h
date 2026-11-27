<?php

$at = '2026-11-21 22:00:00';
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
  "format": "moto-film-qc-policy-v1",
  "schema": "MotoFilmQCPolicy@1.0.0",
  "report": "MotoFilmQCReport@1.0.0",
  "technical": {
    "black": {
      "min_duration_ms": 100,
      "pixel_threshold": 0.1,
      "picture_ratio": 0.98
    },
    "freeze": {
      "min_duration_ms": 1000,
      "noise_db": -60
    },
    "silence": {
      "min_duration_ms": 1000,
      "noise_db": -60
    },
    "loudness": {
      "method": "ebur128",
      "judge": false
    },
    "suspect_severity": "minor"
  },
  "content": {
    "reviewers": ["person"],
    "areas": ["story_pacing", "continuity", "sound_fits_action", "text_readability", "intent_or_fault"],
    "required": ["story_pacing", "continuity", "sound_fits_action", "text_readability"]
  },
  "findings": {
    "severities": ["info", "minor", "major", "critical"],
    "blocking": ["major", "critical"]
  }
}
JSON;

return [
    'schemas' => [
        $schema('36edc675-bcd3-4977-bc5d-9f7e39af2e78', 'MotoFilmQCPolicy', 'Moto Phase 11 — QC policy (moto-film-qc-policy-v1)', '1.0.0', 'MotoFilmQCPolicy-1.0.0.schema.json', 'How a cut film is checked before it may be approved, stored by phase-11-qc as its prompt_content and read by PHP; no model reads it. What code measures on the file and with which thresholds, the areas a person reviews and which must pass, the severities that hold an approval back.', $at),
        $schema('c6cbfad4-0919-48cc-b396-62e10a85f6dc', 'MotoFilmQCReport', 'Moto Phase 11 — QC report (moto-film-qc-report-v1)', '1.0.0', 'MotoFilmQCReport-1.0.0.schema.json', 'What is known about one cut film, by the hash of its file and of its plans: the technical result code measured, the content review of a person, what was not checked, and every finding with what became of it. Code puts it together; the two results are never folded into one pass.', $at),
    ],
    'phases' => [
        [
            'id' => '1ea30f37-a0ad-4f12-937d-2f5b053bea5b',
            'category' => 'moto',
            'workflow_code' => 'harley_custom_v1',
            'phase_code' => 'phase-11-qc',
            'kind' => 'phase',
            'name' => 'Phase 11 — QC policy (measured by code, reviewed by a person, no model)',
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
            'change_note' => 'moto-film-qc-policy-v1, read through action spec.film_qc with executor none: on the file of a cut film code finds black stretches of 100 ms and more, a picture that stands still and a silence of 1 s and more, and measures loudness and peak (EBU R128), which are shown and decide nothing; a stretch the film plan declared (a dip, a silent clip) is not reported, any other is a minor finding for a person to judge. A person reviews story and pacing, continuity, whether the sound fits the action, whether the text can be read, and whether a stretch found is meant; the first four must pass. A major or critical finding left open holds the approval back. No model reviews under this revision.',
            'created_at' => $at,
            'published_at' => $at,
        ],
    ],
    'links' => [
        [
            'id' => '4a978e33-da61-4494-ac59-563f750b2cc2',
            'phase_id' => '1ea30f37-a0ad-4f12-937d-2f5b053bea5b',
            'schema_id' => '36edc675-bcd3-4977-bc5d-9f7e39af2e78',
            'role' => 'input',
            'slot_key' => 'qc_policy',
            'data_pointer' => '',
            'validation_mode' => 'whole',
            'is_required' => 1,
            'created_at' => $at,
        ],
        [
            'id' => '8e602555-e4a6-4a12-ba79-a916c1b42d91',
            'phase_id' => '1ea30f37-a0ad-4f12-937d-2f5b053bea5b',
            'schema_id' => 'c6cbfad4-0919-48cc-b396-62e10a85f6dc',
            'role' => 'output',
            'slot_key' => 'qc_report',
            'data_pointer' => '',
            'validation_mode' => 'whole',
            'is_required' => 1,
            'created_at' => $at,
        ],
    ],
];
