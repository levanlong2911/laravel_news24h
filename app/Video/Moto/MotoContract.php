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

    public const SHOTS_INPUT = 'moto-shots-input-v4';

    public const SHOTS_FORMAT = 'moto-shots-v1';

    public const SHOTS_INPUT_V5 = 'moto-shots-input-v5';

    public const SHOTS_FORMAT_V2 = 'moto-shots-v2';

    /** @var string the input of a shot plan made within a shot budget: the fifth input with film.shot_budget */
    public const SHOTS_INPUT_V6 = 'moto-shots-input-v6';

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
