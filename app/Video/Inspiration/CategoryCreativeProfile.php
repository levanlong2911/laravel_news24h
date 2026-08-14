<?php

namespace App\Video\Inspiration;

use InvalidArgumentException;

final class CategoryCreativeProfile
{
    private const SLOT_TYPES = ['text', 'integer', 'number'];

    /**
     * @param  list<string>  $articlePatterns
     * @param  list<string>  $inspectionAspects
     * @param  list<string>  $excludedContextTypes
     * @param  array<string, array<string, mixed>>  $identitySlots  Cấu hình vô nghiệm mà
     *                                                              vẫn gọi model thì tiền đã tiêu rồi mọi output mới bị từ chối —
     *                                                              nên nó phải nổ ở đây, lúc dựng profile.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $mission,
        public readonly array $articlePatterns,
        public readonly array $inspectionAspects,
        public readonly array $excludedContextTypes,
        public readonly array $identitySlots = [],
    ) {
        foreach ([$key, $mission] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Creative profile key and mission must not be empty.');
            }
        }

        foreach ([
            'article_patterns' => $articlePatterns,
            'inspection_aspects' => $inspectionAspects,
            'excluded_context_types' => $excludedContextTypes,
        ] as $name => $values) {
            if ($values === [] || count($values) !== count(array_unique($values))) {
                throw new InvalidArgumentException("Creative profile {$name} must be non-empty and unique.");
            }

            foreach ($values as $value) {
                if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_]*\z/', $value) !== 1) {
                    throw new InvalidArgumentException("Creative profile {$name} contains an invalid key.");
                }
            }
        }

        $this->assertIdentitySlotsAreSatisfiable($identitySlots);
    }

    /** @param array<string, array<string, mixed>> $slots */
    private function assertIdentitySlotsAreSatisfiable(array $slots): void
    {
        foreach ($slots as $name => $spec) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('Creative profile identity_slots contains an invalid slot name.');
            }

            if (! is_array($spec) || ! isset($spec['type']) || ! in_array($spec['type'], self::SLOT_TYPES, true)) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} must declare type text|integer|number.");
            }

            if ($spec['type'] === 'text') {
                if (! isset($spec['max_length']) || ! is_int($spec['max_length']) || $spec['max_length'] < 1) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} must declare max_length > 0.");
                }

                continue;
            }

            foreach (['min', 'max'] as $bound) {
                if (! isset($spec[$bound]) || ! is_int($spec[$bound]) && ! is_float($spec[$bound])) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} must declare a numeric {$bound}.");
                }
            }

            if ($spec['min'] > $spec['max']) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} has min greater than max.");
            }
        }
    }
}
