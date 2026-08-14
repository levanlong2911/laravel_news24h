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
        public readonly string $conceptMission = '',
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

    /**
     * Một profile không khai khe nào thì `design_identity: {}` trở thành hợp lệ
     * và concept không đủ danh tính để dựng ảnh neo. Tách riêng khỏi constructor
     * vì profile còn dùng cho Inspiration, nơi identity slot không liên quan.
     */
    public function assertConceptReady(): void
    {
        if ($this->identitySlots === []) {
            throw new InvalidArgumentException("Creative profile {$this->key} declares no identity slots.");
        }

        // `mission` viết cho Inspiration — dùng lại nó ở đây sẽ bảo model soạn
        // brief thay vì thiết kế.
        if (trim($this->conceptMission) === '') {
            throw new InvalidArgumentException("Creative profile {$this->key} declares no concept mission.");
        }
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

            // Tập key đóng: `maximum` gõ nhầm thay cho `max` phải nổ lúc deploy,
            // không được bỏ qua im lặng.
            $expected = $spec['type'] === 'text' ? ['type', 'max_length'] : ['type', 'min', 'max'];

            if (array_diff(array_keys($spec), $expected) !== [] || array_diff($expected, array_keys($spec)) !== []) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} must declare exactly: ".implode(', ', $expected).'.');
            }

            if ($spec['type'] === 'text') {
                if (! is_int($spec['max_length']) || $spec['max_length'] < 1) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} must declare max_length > 0.");
                }

                continue;
            }

            foreach (['min', 'max'] as $bound) {
                if (! is_int($spec[$bound]) && ! is_float($spec[$bound])) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} must declare a numeric {$bound}.");
                }

                // NAN lọt qua thì phép so min > max bên dưới luôn false.
                if (is_float($spec[$bound]) && ! is_finite($spec[$bound])) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} must declare a finite {$bound}.");
                }
            }

            if ($spec['min'] > $spec['max']) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} has min greater than max.");
            }
        }
    }
}
