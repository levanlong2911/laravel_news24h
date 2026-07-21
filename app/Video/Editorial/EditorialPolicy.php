<?php

namespace App\Video\Editorial;

/**
 * §12 Rule #1: Editorial knowledge chi ton tai duoi dang DU LIEU, khong bao
 * gio duoi dang nhanh code. Vi du: match builder=Feadship -> prohibit domes.
 *
 * match:    { attribute: value } — TAT CA phai khop voi Entity.attributes thi
 *           policy moi ap dung (AND). Rong = luon khop (policy vo dieu kien).
 * prohibit: attribute + value bi cam + ly do (bat buoc — khong prohibition
 *           nao duoc thieu ly do, dung theo schema.prohibition).
 */
final class EditorialPolicy
{
    /**
     * @param array<string, mixed> $match
     */
    public function __construct(
        public readonly array $match,
        public readonly string $prohibitAttribute,
        public readonly mixed $prohibitValue,
        public readonly string $reason,
    ) {
    }
}
