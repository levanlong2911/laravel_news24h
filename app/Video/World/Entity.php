<?php

namespace App\Video\World;

/**
 * Entity đã verify. Mọi thuộc tính ở đây đều truy được về bằng chứng trong bài.
 *
 * Kiểu này chỉ được EvidenceGatekeeper tạo ra. Không có đường nào dựng nó
 * trực tiếp từ output của LLM — đó là chủ ý.
 */
final class Entity
{
    /**
     * @param array<string, list<VerifiedAttribute>> $attributes Một tên có thể
     *        mang NHIỀU giá trị: một con tàu có beach club VÀ spa VÀ helipad.
     *        Đó không phải mâu thuẫn, đó là sự thật nhiều giá trị.
     */
    public function __construct(
        public readonly string $id,
        public readonly EntityType $type,
        public readonly array $attributes,
        public readonly ?Identity $identity = null,
    ) {
    }

    public function has(string $attribute): bool
    {
        return isset($this->attributes[$attribute]);
    }

    /** Giá trị đầu tiên. Tiện cho thuộc tính vốn chỉ có một giá trị. */
    public function value(string $attribute): mixed
    {
        return $this->attributes[$attribute][0]->value ?? null;
    }

    /** @return list<mixed> Mọi giá trị đã verify của thuộc tính này. */
    public function values(string $attribute): array
    {
        return array_map(
            fn (VerifiedAttribute $a) => $a->value,
            $this->attributes[$attribute] ?? [],
        );
    }

    /**
     * Entity chỉ có tên, không có thuộc tính nào — hoàn toàn hợp lệ.
     *
     * Feadship là một node thật của World Graph: bài báo nêu tên nó và nêu quan
     * hệ "Moonrise built_by Feadship". Bài không mô tả thuộc tính của chính
     * Feadship, và không cần. Nó tồn tại để neo quan hệ, không để render.
     */
    public function isAnchorOnly(): bool
    {
        return $this->attributes === [];
    }

    /**
     * Thuộc tính vật lý, render được — phần DUY NHẤT chảy xuống ProviderIR.
     *
     * Trả scalar khi thuộc tính có một giá trị, list khi có nhiều. Kiểu không
     * đồng nhất là CỐ Ý: nó phản ánh đúng thực tế và khớp `attributes` free-form
     * trong RenderPlan schema.
     *
     * @return array<string, mixed>
     */
    public function renderableAttributes(): array
    {
        $out = [];

        foreach ($this->attributes as $name => $verified) {
            $values     = array_map(fn (VerifiedAttribute $a) => $a->value, $verified);
            $out[$name] = count($values) === 1 ? $values[0] : $values;
        }

        return $out;
    }
}
