<?php

namespace App\Video\Inspiration;

use InvalidArgumentException;

final class CategoryCreativeProfile
{
    private const SLOT_TYPES = ['text', 'integer', 'number', 'object', 'enum'];

    private const MAX_SLOT_GUIDANCE_LENGTH = 200;

    private const MAX_VIEWPOINT_GUIDANCE_LENGTH = 400;

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
        public readonly array $viewpointGuidance = [],
        public readonly array $arcStages = [],
        public readonly array $arcRequiredStages = [],
        public readonly array $conceptAntipatterns = [],
        public readonly array $conceptForbiddenTerms = [],
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

        // Rong la hop le: category khong khai hinh dang cam nao van chay duoc.
        // Nhung mot muc null hay mot mang long nhau se noi muon o luc dung
        // instruction, khi khong ai con nho no den tu config nao.
        foreach ($conceptAntipatterns as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "Creative profile {$key} concept_antipatterns must contain only non-empty strings.",
                );
            }
        }

        // Lap mot muc khong lam hong logic, nhung no in ra prompt hai lan va lam
        // model tuong day la hai rang buoc khac nhau.
        if (count($conceptAntipatterns) !== count(array_unique($conceptAntipatterns))) {
            throw new InvalidArgumentException(
                "Creative profile {$key} concept_antipatterns must be unique.",
            );
        }

        foreach ($conceptForbiddenTerms as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException(
                    "Creative profile {$key} concept_forbidden_terms must contain only non-empty strings.",
                );
            }
        }

        if (count($conceptForbiddenTerms) !== count(array_unique($conceptForbiddenTerms))) {
            throw new InvalidArgumentException(
                "Creative profile {$key} concept_forbidden_terms must be unique.",
            );
        }

        $this->assertIdentitySlotsAreSatisfiable($identitySlots);
    }

    /**
     * Tách khỏi constructor cùng lý do với assertConceptReady(): profile này còn
     * phục vụ Inspiration, nơi trình tự arc không liên quan. Nổ trước khi gọi
     * model — một stage bắt buộc nằm ngoài trình tự thì mọi output đều vi phạm
     * và tiền đã tiêu rồi.
     */
    public function assertArcReady(): void
    {
        foreach (['arc_stages' => $this->arcStages, 'arc_required_stages' => $this->arcRequiredStages] as $name => $values) {
            if ($values === [] || count($values) !== count(array_unique($values))) {
                throw new InvalidArgumentException("Creative profile {$this->key} {$name} must be non-empty and unique.");
            }

            foreach ($values as $value) {
                if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_]*\z/', $value) !== 1) {
                    throw new InvalidArgumentException("Creative profile {$this->key} {$name} contains an invalid key.");
                }
            }
        }

        if (array_diff($this->arcRequiredStages, $this->arcStages) !== []) {
            throw new InvalidArgumentException(
                "Creative profile {$this->key} arc_required_stages must all appear in arc_stages.",
            );
        }
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

        $expected = \App\Video\Concept\Viewpoint::values();
        $supplied = array_keys($this->viewpointGuidance);

        if (array_diff($expected, $supplied) !== [] || array_diff($supplied, $expected) !== []) {
            throw new InvalidArgumentException(
                "Creative profile {$this->key} viewpoint_guidance must cover exactly: ".implode(', ', $expected).'.',
            );
        }

        foreach ($this->viewpointGuidance as $name => $text) {
            if (! is_string($text) || trim($text) === '' || mb_strlen($text) > self::MAX_VIEWPOINT_GUIDANCE_LENGTH) {
                throw new InvalidArgumentException(
                    "Creative profile {$this->key} viewpoint_guidance.{$name} must be a non-empty string of at most "
                    .self::MAX_VIEWPOINT_GUIDANCE_LENGTH.' characters.',
                );
            }
        }
    }

    /** @param array<string, array<string, mixed>> $slots */
    private function assertIdentitySlotsAreSatisfiable(array $slots): void
    {
        foreach ($slots as $name => $spec) {
            if (! is_string($name) || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
                throw new InvalidArgumentException('Creative profile identity_slots contains an invalid slot name.');
            }

            $this->assertSlotSpecIsSatisfiable($name, $spec);
        }
    }

    private function assertSlotSpecIsSatisfiable(string $name, mixed $spec): void
    {
        if (! is_array($spec) || ! isset($spec['type']) || ! in_array($spec['type'], self::SLOT_TYPES, true)) {
            throw new InvalidArgumentException("Creative profile identity slot {$name} must declare type ".implode('|', self::SLOT_TYPES).'.');
        }

        // Tập key đóng: `maximum` gõ nhầm thay cho `max` phải nổ lúc deploy,
        // không được bỏ qua im lặng.
        $expected = match ($spec['type']) {
            'text' => ['type', 'max_length'],
            'object' => ['type', 'fields'],
            'enum' => ['type', 'values'],
            default => ['type', 'min', 'max'],
        };
        $allowed = [...$expected, 'guidance'];

        if (array_diff(array_keys($spec), $allowed) !== [] || array_diff($expected, array_keys($spec)) !== []) {
            throw new InvalidArgumentException(
                "Creative profile identity slot {$name} must declare ".implode(', ', $expected).' and may declare guidance.',
            );
        }

        if (array_key_exists('guidance', $spec)
            && (! is_string($spec['guidance'])
                || trim($spec['guidance']) === ''
                || mb_strlen($spec['guidance']) > self::MAX_SLOT_GUIDANCE_LENGTH)) {
            throw new InvalidArgumentException(
                "Creative profile identity slot {$name} guidance must be a non-empty string of at most ".self::MAX_SLOT_GUIDANCE_LENGTH.' characters.',
            );
        }

        if ($spec['type'] === 'text') {
            if (! is_int($spec['max_length']) || $spec['max_length'] < 1) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} must declare max_length > 0.");
            }

            return;
        }

        if ($spec['type'] === 'enum') {
            if (! is_array($spec['values']) || $spec['values'] === []) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} must declare at least one value.");
            }

            foreach ($spec['values'] as $value) {
                if (! is_string($value) || preg_match('/\A[a-z][a-z0-9_]*\z/', $value) !== 1) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} has an invalid value.");
                }
            }

            if (count($spec['values']) !== count(array_unique($spec['values']))) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} has duplicate values.");
            }

            return;
        }

        if ($spec['type'] === 'object') {
            if (! is_array($spec['fields']) || $spec['fields'] === []) {
                throw new InvalidArgumentException("Creative profile identity slot {$name} must declare at least one field.");
            }

            foreach ($spec['fields'] as $field => $fieldSpec) {
                if (! is_string($field) || preg_match('/\A[a-z][a-z0-9_]*\z/', $field) !== 1) {
                    throw new InvalidArgumentException("Creative profile identity slot {$name} contains an invalid field name.");
                }

                if (is_array($fieldSpec) && ($fieldSpec['type'] ?? null) === 'object') {
                    throw new InvalidArgumentException("Creative profile identity slot {$name}.{$field} must not nest another object.");
                }

                $this->assertSlotSpecIsSatisfiable("{$name}.{$field}", $fieldSpec);
            }

            return;
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
