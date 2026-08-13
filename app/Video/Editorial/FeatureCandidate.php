<?php

namespace App\Video\Editorial;

/**
 * Thuộc tính đã qua Gatekeeper của entity trong scene — Director CHỈ CHỌN.
 *
 * Tách khỏi ActionCandidate: một cái hồ bơi không phải hành động, nhét vào đó
 * sẽ phải bịa một động từ mà không Evidence nào chứng minh.
 *
 * `values` là list vì một tên thuộc tính mang được nhiều giá trị (Entity:14).
 * Không nhận null — class này tả thứ CÓ MẶT trong khung.
 */
final class FeatureCandidate
{
    /** @param  list<string|int|float|bool>  $values */
    public function __construct(
        public readonly string $entityId,
        public readonly string $attribute,
        public readonly array $values,
    ) {}

    /**
     * @return array{entity: string, attribute: string, values: list<string|int|float|bool>}
     */
    public function toArray(): array
    {
        return [
            'entity' => $this->entityId,
            'attribute' => $this->attribute,
            'values' => $this->values,
        ];
    }
}
