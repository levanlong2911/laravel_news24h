<?php

namespace App\Video\Concept;

/**
 * Verbosity, khong phai loi. Chi mang code/path/so do — KHONG mang noi dung
 * field, vi warning di vao log.
 */
final class ConceptWarning
{
    public const PROSE_EXCEEDS_RECOMMENDED_LENGTH = 'PROSE_EXCEEDS_RECOMMENDED_LENGTH';

    public function __construct(
        public readonly string $code,
        public readonly string $field,
        public readonly int $actual,
        public readonly int $recommended,
    ) {}

    /**
     * @param  list<self>  $warnings
     * @return list<array{code: string, field: string, actual: int, recommended: int}>
     */
    public static function listToArray(array $warnings): array
    {
        return array_map(fn (self $warning) => $warning->toArray(), $warnings);
    }

    /** @return array{code: string, field: string, actual: int, recommended: int} */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'field' => $this->field,
            'actual' => $this->actual,
            'recommended' => $this->recommended,
        ];
    }
}
