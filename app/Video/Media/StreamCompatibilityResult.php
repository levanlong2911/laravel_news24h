<?php

namespace App\Video\Media;

/**
 * Phan quyet cua `StreamCompatibility`.
 *
 * Kieu ro rang chu khong phai mang: cai duoc tra ve la mot QUYET DINH kem ly do,
 * va noi goi khong duoc phep doc nham mot khoa nao do roi di tiep.
 */
final class StreamCompatibilityResult
{
    /** @param list<string> $reasons */
    private function __construct(
        public readonly bool $accepted,
        public readonly ?string $code,
        public readonly array $reasons,
    ) {}

    public static function accepted(): self
    {
        return new self(true, null, []);
    }

    /** @param list<string> $reasons */
    public static function rejected(string $code, array $reasons): self
    {
        return new self(false, $code, $reasons);
    }
}
