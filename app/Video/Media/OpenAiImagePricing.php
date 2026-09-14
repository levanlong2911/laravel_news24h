<?php

namespace App\Video\Media;

/**
 * Gia anh OpenAI, doc tu snapshot co phien ban — giong het cach lam voi Gemini.
 *
 * Khac mot diem phai noi ro: trang gia cua OpenAI KHONG cong bo gia moi anh cho
 * gpt-image-2, nen snapshot dau tien chi ke thua nhung con so ung dung dang dung
 * va tu khai `verified: false`. Doi so that thi doi luon `pricing_version`, va o
 * da render giu nguyen gia da dong bang cua no.
 */
final class OpenAiImagePricing
{
    private const FILE = 'openai_image_pricing_2026_09_14.json';

    /** @var array<string, mixed>|null */
    private ?array $catalog = null;

    /** @return array{usd: float, version: string}|null null = chua dinh gia */
    public function unitFor(string $model, string $quality): ?array
    {
        $catalog = $this->catalog();
        $usd = $catalog['models'][$model]['qualities'][$quality]['usd_per_image'] ?? null;
        $version = $catalog['pricing_version'] ?? null;

        if ((! is_int($usd) && ! is_float($usd)) || ! is_string($version)) {
            return null;
        }

        $usd = (float) $usd;

        return $usd > 0 && is_finite($usd) && trim($version) !== ''
            ? ['usd' => $usd, 'version' => trim($version)]
            : null;
    }

    /** @return array<string, mixed> */
    private function catalog(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $path = rtrim((string) config('video.provider_evidence_dir'), '/\\').DIRECTORY_SEPARATOR.self::FILE;

        if (! is_file($path) || ! is_readable($path)) {
            return $this->catalog = [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return $this->catalog = is_array($data) ? $data : [];
    }
}
