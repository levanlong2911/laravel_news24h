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

    public const EVIDENCE_EXECUTION = 'db-moto-phase-1-v1';

    public const DNA_EXECUTION = 'db-moto-phase-2-v1';

    private const MAKE_PREFIX = '/^(?:Harley-Davidson|Harley Davidson|Harley|H-D)\s+/iu';

    public static function is(?string $workflow): bool
    {
        return $workflow === self::WORKFLOW;
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
