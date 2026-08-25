<?php

namespace App\Video\Inspiration;

use RuntimeException;

final class InvalidInspirationBrief extends RuntimeException
{
    /**
     * `$rawResponse` la BANG CHUNG DA TRA TIEN.
     *
     * Mot cu goi that bai validate van ton dung so tien nhu mot cu goi thanh
     * cong. Vut cau tra loi di la lam mu moi lan debug sau: 2026-08-23 mot luot
     * concept fail voi cost_usd = 0.0269 nhung raw_response = NULL, nen khong
     * ai doc duoc Sonnet da tra ve con so nao.
     *
     * Mac dinh rong vi loi mang thi THAT SU khong co raw — gia vo co la noi doi.
     */
    /** @param list<string> $violations */
    public function __construct(
        public readonly array $violations,
        public readonly string $rawResponse = '',
    ) {
        parent::__construct('Inspiration brief failed validation: '.implode('; ', $violations));
    }
}
