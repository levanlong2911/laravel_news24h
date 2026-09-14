<?php

namespace App\Video\Media;

/**
 * Gia niem yet cua Google, do nguoi doc va ky ten trong mot snapshot co phien ban.
 *
 * KHONG suy gia tu usageMetadata: Google khong cong bo quy tac token→tien cho tung
 * model va tung co, nen tinh lai tu token la bia. Token chi de quan sat va doi soat.
 *
 * File hong hoac thieu khong bao gio lam chet man hinh — no chi khien moi gia tri
 * tro thanh "chua dinh gia", va registry se chan neu config dam khai estimated.
 */
final class GeminiImagePricing
{
    /** @var array<string, array<string, mixed>> */
    private array $catalogs = [];

    /** @return array{usd: float, version: string}|null null = chua dinh gia */
    public function unitFor(string $file, string $model, string $imageSize): ?array
    {
        $catalog = $this->catalog($file);
        $usd = $catalog['models'][$model]['image_sizes'][$imageSize]['usd_per_image'] ?? null;
        $version = $catalog['pricing_version'] ?? null;

        if ((! is_int($usd) && ! is_float($usd)) || ! is_string($version)) {
            return null;
        }

        $usd = (float) $usd;

        return $usd > 0 && is_finite($usd) && trim($version) !== ''
            ? ['usd' => $usd, 'version' => trim($version)]
            : null;
    }

    /**
     * @param  list<string>  $imageSizes
     * @return list<string> nhung co CHUA co gia — registry dung de chan khai estimated
     */
    public function missingSizes(string $file, string $model, array $imageSizes): array
    {
        return array_values(array_filter(
            $imageSizes,
            fn (string $size): bool => $this->unitFor($file, $model, $size) === null,
        ));
    }

    /**
     * @param  list<string>  $imageSizes
     * @return array<string, float> chi nhung co co gia
     */
    public function pricesFor(string $file, string $model, array $imageSizes): array
    {
        $prices = [];

        foreach ($imageSizes as $size) {
            $unit = $this->unitFor($file, $model, $size);

            if ($unit !== null) {
                $prices[$size] = $unit['usd'];
            }
        }

        return $prices;
    }

    public function versionOf(string $file): ?string
    {
        $version = $this->catalog($file)['pricing_version'] ?? null;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }

    /** @return array<string, mixed> */
    private function catalog(string $file): array
    {
        if (isset($this->catalogs[$file])) {
            return $this->catalogs[$file];
        }

        if (preg_match('/^[a-z0-9_]+\.json$/', $file) !== 1) {
            return $this->catalogs[$file] = [];
        }

        $path = rtrim((string) config('video.gemini.evidence_dir'), '/\\').DIRECTORY_SEPARATOR.$file;

        if (! is_file($path) || ! is_readable($path)) {
            return $this->catalogs[$file] = [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return $this->catalogs[$file] = is_array($data) ? $data : [];
    }
}
