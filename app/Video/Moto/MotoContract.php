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

    public const DESIGN_INPUT = 'moto-design-input-v1';

    public const DESIGN_FORMAT = 'moto-design-v1';

    public const SCRIPT_INPUT = 'moto-script-input-v3';

    public const SCRIPT_FORMAT = 'moto-script-v1';

    public const LOCATIONS_INPUT = 'moto-locations-input-v1';

    public const LOCATIONS_FORMAT = 'moto-locations-v1';

    public const SCENES_INPUT = 'moto-scenes-input-v2';

    public const SCENES_FORMAT = 'moto-scenes-v1';

    public const SHOTS_INPUT = 'moto-shots-input-v4';

    public const SHOTS_FORMAT = 'moto-shots-v1';

    public const REFERENCE_INPUT = 'moto-reference-input-v2';

    public const REFERENCE_FORMAT = 'moto-reference-prompt-v3';

    public const REFERENCE_PLAN_EXECUTION = 'moto-reference-plan-v2';

    public const REFERENCE_EXECUTION = 'db-moto-phase-8-v3';

    public const KEYFRAME_PROMPT_INPUT = 'moto-keyframe-prompt-input-v4';

    public const KEYFRAME_PROMPT_FORMAT = 'moto-keyframe-prompt-v1';

    public const KEYFRAME_PROMPT_EXECUTION = 'db-moto-phase-9-v1';

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
