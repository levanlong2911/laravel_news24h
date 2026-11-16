<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoContract
{
    public const WORKFLOW = 'harley_custom_v1';

    public const ARTICLE_INPUT = 'phase1-article-v1';

    public const EVIDENCE_FORMAT = 'moto-evidence-v1';

    public const DNA_INPUT = 'moto-dna-input-v1';

    public const DNA_FORMAT = 'moto-dna-v1';

    public const DNA_INPUT_V2 = 'moto-dna-input-v2';

    public const DNA_FORMAT_V2 = 'moto-dna-v2';

    public const DESIGN_INPUT = 'moto-design-input-v1';

    public const DESIGN_FORMAT = 'moto-design-v1';

    public const DESIGN_INPUT_V2 = 'moto-design-input-v2';

    public const DESIGN_FORMAT_V2 = 'moto-design-v2';

    public const SCRIPT_INPUT = 'moto-script-input-v3';

    public const SCRIPT_FORMAT = 'moto-script-v1';

    public const SCRIPT_INPUT_V4 = 'moto-script-input-v4';

    public const SCRIPT_FORMAT_V2 = 'moto-script-v2';

    public const LOCATIONS_INPUT = 'moto-locations-input-v1';

    public const LOCATIONS_FORMAT = 'moto-locations-v1';

    public const LOCATIONS_INPUT_V2 = 'moto-locations-input-v2';

    public const LOCATIONS_FORMAT_V2 = 'moto-locations-v2';

    public const SCENES_INPUT = 'moto-scenes-input-v2';

    public const SCENES_FORMAT = 'moto-scenes-v1';

    public const SCENES_INPUT_V3 = 'moto-scenes-input-v3';

    public const SCENES_FORMAT_V2 = 'moto-scenes-v2';

    /** @var string the input of a scene plan whose work is followed under MotoWork::LAW_V2: the third input with work_tracking_version */
    public const SCENES_INPUT_V4 = 'moto-scenes-input-v4';

    /** @var string the answer of such a scene plan: no detached parts written by the model, work evidenced by event id, connection changes */
    public const SCENES_FORMAT_V3 = 'moto-scenes-v3';

    public const SHOTS_INPUT = 'moto-shots-input-v4';

    public const SHOTS_FORMAT = 'moto-shots-v1';

    public const SHOTS_INPUT_V5 = 'moto-shots-input-v5';

    public const SHOTS_FORMAT_V2 = 'moto-shots-v2';

    /** @var string the input of a shot plan made within a shot budget: the fifth input with film.shot_budget */
    public const SHOTS_INPUT_V6 = 'moto-shots-input-v6';

    /** @var string the input of a shot plan made from a scene plan followed under MotoWork::LAW_V2: the sixth input with work_tracking_version, the detached parts code derived and the connection changes of the work */
    public const SHOTS_INPUT_V7 = 'moto-shots-input-v7';

    /** @var string the input of a shot plan that also carries the signature moments of the design: the seventh input with motorcycle.signature_moments, each record as the design holds it, and no confirmation of procedures */
    public const SHOTS_INPUT_V8 = 'moto-shots-input-v8';

    /** @var string the way of making the clip of a shot from reference images, with no keyframe */
    public const REFERENCE_TO_VIDEO = 'reference_to_video';

    /** @var int the milliseconds every shot of an eventful plan renders and keeps */
    public const SHOT_MS = 3000;

    public const REFERENCE_INPUT = 'moto-reference-input-v2';

    public const REFERENCE_FORMAT = 'moto-reference-prompt-v3';

    public const REFERENCE_PLAN_EXECUTION = 'moto-reference-plan-v2';

    public const REFERENCE_EXECUTION = 'db-moto-phase-8-v3';

    public const KEYFRAME_PROMPT_INPUT = 'moto-keyframe-prompt-input-v4';

    public const KEYFRAME_PROMPT_FORMAT = 'moto-keyframe-prompt-v1';

    public const KEYFRAME_PROMPT_EXECUTION = 'db-moto-phase-9-v1';

    public const CLIP_PROMPT_INPUT = 'moto-clip-prompt-input-v3';

    public const CLIP_PROMPT_INPUT_V4 = 'moto-clip-prompt-input-v4';

    /** @var string the input of a clip prompt of the cinematic releases from phase-10 revision 16: as v4, with what the shot plan assumed for the shot, the ids the prompt must not hold, and for a person either the cell of a look or the whole identity master with the cells it holds */
    public const CLIP_PROMPT_INPUT_V5 = 'moto-clip-prompt-input-v5';

    public const CLIP_PROMPT_FORMAT = 'moto-clip-prompt-v1';

    public const CLIP_PROMPT_EXECUTION = 'db-moto-phase-10-v1';

    public const EVIDENCE_EXECUTION = 'db-moto-phase-1-v1';

    public const DNA_EXECUTION = 'db-moto-phase-2-v1';

    public const DESIGN_EXECUTION = 'db-moto-phase-3-v1';

    public const SCRIPT_EXECUTION = 'db-moto-phase-4-v1';

    public const LOCATIONS_EXECUTION = 'db-moto-phase-5-v1';

    public const SCENES_EXECUTION = 'db-moto-phase-6-v1';

    public const SHOTS_EXECUTION = 'db-moto-phase-7-v1';

    private const MAKE_PREFIX = '/^(?:Harley-Davidson|Harley Davidson|Harley|H-D)\s+/iu';

    public static function is(?string $workflow): bool
    {
        return $workflow === self::WORKFLOW;
    }

    /**
     * @param  array<string, mixed>  $manifest  the manifest a run pinned
     * @param  string  $schema  the key of one of its schemas
     * @return int the major version of that schema in the manifest, 1 when the manifest does not carry it
     */
    public static function generation(array $manifest, string $schema): int
    {
        foreach ((array) ($manifest['schemas'] ?? []) as $row) {
            if (is_array($row) && ($row['key'] ?? null) === $schema) {
                return max(1, (int) strtok((string) ($row['version'] ?? '1'), '.'));
            }
        }

        return 1;
    }

    /**
     * @param  string  $content  an output schema as the pinned bundle stores it
     * @return string the schema re-encoded without whitespace between tokens
     *
     * @throws \RuntimeException when the schema is not valid JSON
     */
    public static function compactSchema(string $content): string
    {
        try {
            $schema = json_decode($content, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Schema đầu ra không phải JSON hợp lệ: '.$e->getMessage());
        }

        return (string) json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    public const TECHNICAL_SCHEMA_VERSION = 'moto-technical-schema-v1';

    /** @var array<string, array<string, list<string>>> by schema name, the keywords of a released schema that count creative content, by the pointer of the node that carries them */
    private const CREATIVE_COUNTS = [
        'MotoDna' => [
            '/properties/dna/properties/visual_identity/properties/identity_cues' => ['minItems', 'maxItems'],
            '/properties/dna/properties/departures' => ['minItems', 'maxItems'],
            '/properties/dna/properties/features' => ['maxItems'],
            '/properties/dna/properties/must_avoid' => ['minItems'],
        ],
        'MotoDesign' => [
            '/$defs/data/properties/systems' => ['minItems'],
            '/$defs/data/properties/connections' => ['minItems'],
            '/$defs/data/properties/features' => ['maxItems'],
            '/$defs/motorcycle/properties/name' => ['minLength', 'maxLength'],
            '/$defs/motorcycle/properties/signature_moments' => ['minItems', 'maxItems'],
            '/$defs/motorcycle/properties/palette' => ['maxItems'],
            '/$defs/motorcycle/properties/materials' => ['minItems'],
            '/$defs/parts' => ['minItems'],
            '/$defs/rider_interface/properties/contact_points' => ['minItems'],
            '/$defs/identity_rule/properties/description' => ['maxLength'],
        ],
        'MotoScript' => [
            '/$defs/data/properties/beats' => ['minItems'],
            '/$defs/data/properties/text_cards' => ['minItems'],
            '/$defs/story/properties/loops' => ['maxItems'],
        ],
        'MotoLocations' => [
            '/$defs/location/properties/palette' => ['maxItems'],
            '/$defs/background/properties/must_show' => ['maxItems'],
        ],
    ];

    /**
     * @param  list<array{json_schema_id: string, content: string}>  $schemas  the output schemas a run pinned, as released
     * @return list<array{json_schema_id: string, content: string}> the same set for the check of an answer: every structural rule kept, the counts of creative content (CREATIVE_COUNTS) left out; the released schemas and their hashes are not touched
     */
    public static function technicalSchemas(array $schemas): array
    {
        return array_map(static function (array $schema): array {
            $rules = self::CREATIVE_COUNTS[basename((string) ($schema['json_schema_id'] ?? ''))] ?? [];
            $document = $rules === [] ? null : json_decode((string) $schema['content']);

            if (! is_object($document)) {
                return $schema;
            }

            foreach ($rules as $pointer => $keywords) {
                $node = $document;

                foreach (explode('/', ltrim($pointer, '/')) as $step) {
                    $node = is_object($node) ? ($node->{$step} ?? null) : null;
                }

                foreach (is_object($node) ? $keywords : [] as $keyword) {
                    unset($node->{$keyword});
                }
            }

            return ['content' => (string) json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)] + $schema;
        }, $schemas);
    }

    /** @return list<string> the name with its spaces collapsed and, when it starts with the make, the same name without it */
    public static function sourceNameForms(string $name): array
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $rest = trim((string) preg_replace(self::MAKE_PREFIX, '', $name));

        return array_values(array_unique(array_filter([$name, $rest], static fn (string $form): bool => mb_strlen($form) >= 3)));
    }

    public static function mentions(string $text, string $name): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu', $text) === 1;
    }

    /**
     * @param  list<string>  $names
     * @param  list<?string>  $allowed  the base model names a field may use; the make is always allowed
     * @return list<string> every form of the names, apart from a name that is the make or a base model, with or without the make before it
     */
    public static function blockedForms(array $names, array $allowed): array
    {
        $allowed = array_map(static fn (string $name): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name))), array_filter([...$allowed, 'Harley-Davidson', 'Harley Davidson'], 'is_string'));
        $forms = [];

        foreach ($names as $name) {
            $nameForms = self::sourceNameForms($name);

            if (array_intersect(array_map('mb_strtolower', $nameForms), $allowed) !== []) {
                continue;
            }

            foreach ($nameForms as $form) {
                $forms[$form] = true;
            }
        }

        return array_map('strval', array_keys($forms));
    }
}
