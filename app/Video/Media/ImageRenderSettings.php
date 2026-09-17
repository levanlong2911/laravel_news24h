<?php

namespace App\Video\Media;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use InvalidArgumentException;

/**
 * Mot bo thiet lap render DA duoc doi chieu voi registry.
 *
 * Thay bon enum `ImageSize`/`ImageModel`/`ImageQuality`/`ImageVariations` tren
 * duong anchor. Bon enum do mo ta MOT provider; tu luc co provider thu hai thi
 * chung khong con dien ta duoc dau vao nua.
 *
 * `provider` LUON lay tu entry registry, khong bao gio tu input: trinh duyet gui
 * len mot ma model, server tu quyet dinh ma do thuoc ve ai.
 *
 * Lop nay tu kiem lai moi thu, khong muon bao dam cua form: no la thu di thang vao
 * `prompt_spec_json`, va spec la ban ghi cuoi cung con doi chieu duoc.
 */
final class ImageRenderSettings
{
    private function __construct(
        public readonly string $task,
        public readonly string $provider,
        public readonly string $model,
        public readonly string $pricing,
        public readonly int $variations,
        public readonly ?string $apiVersion,
        public readonly ?string $shape,
        public readonly ?ImageSize $size,
        public readonly ?ImageQuality $quality,
        public readonly ?string $aspectRatio,
        public readonly ?string $imageSize,
        public readonly ?float $unitCostUsd,
        public readonly ?string $pricingVersion,
    ) {}

    /**
     * @param  array<string, mixed>  $entry  entry registry DA di qua `forTask()`
     * @param  array<string, mixed>  $data   input tu form
     *
     * @throws InvalidArgumentException
     */
    public static function of(string $task, array $entry, array $data): self
    {
        $controls = $entry['controls'];
        $variations = self::variations($data, (int) $entry['max_variations']);

        return $entry['provider'] === 'openai'
            ? self::openai($task, $entry, $controls, $data, $variations)
            : self::gemini($task, $entry, $controls, $data, $variations);
    }

    /**
     * Bon trong nam khoa dinh danh cua `DesignImageStore`; khoa thu nam la `prompt`,
     * do service them vao. `identityHash()` NEM neu thieu mot khoa, ke ca khi gia
     * tri la `null` — nen `quality` phai co mat du Gemini khong dung toi.
     *
     * Gemini gop hai control thanh MOT nhan theo dung khuon duong environment da
     * dung (`9:16@2K`). Khong bia width/height.
     *
     * @return array<string, mixed>
     */
    public function identity(): array
    {
        return [
            'model' => $this->model,
            'quality' => $this->quality?->value,
            'size' => $this->size?->value ?? $this->aspectRatio.'@'.$this->imageSize,
            'variations' => $this->variations,
        ];
    }

    /**
     * Khoi dinh tuyen di vao `prompt_spec_json`.
     *
     * Gia Gemini dong bang o day, luc tao o. `DesignImageStore::withPriceSnapshot()`
     * dong bang gia OpenAI — nen DTO de hai truong gia `null` cho OpenAI, khong
     * phai vi duong do khong co snapshot.
     *
     * @return array<string, mixed>
     */
    public function routing(): array
    {
        return array_filter([
            'task' => $this->task,
            'provider' => $this->provider,
            'pricing' => $this->pricing,
            'api_version' => $this->apiVersion,
            'shape' => $this->shape,
            'aspect_ratio' => $this->aspectRatio,
            'image_size' => $this->imageSize,
            'unit_cost_usd' => $this->unitCostUsd,
            'pricing_version' => $this->pricingVersion,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * Control THAT SU da yeu cau.
     *
     * Gemini khong khai so pixel nao, nen khong co width/height de ghi o day. Kich
     * thuoc that cua anh doc tu file tra ve.
     *
     * @return array<string, mixed>
     */
    public function nativeControls(): array
    {
        return $this->size !== null
            ? ['size' => $this->size->value, 'width' => $this->size->width(), 'height' => $this->size->height()]
            : ['aspect_ratio' => $this->aspectRatio, 'image_size' => $this->imageSize];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $controls
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private static function openai(string $task, array $entry, array $controls, array $data, int $variations): self
    {
        $size = ImageSize::tryFrom(self::choice($data, 'size', $controls['sizes']));
        $quality = ImageQuality::tryFrom(self::choice($data, 'quality', $controls['qualities']));

        if ($size === null || $quality === null) {
            throw new InvalidArgumentException('size hoac quality khong phai gia tri enum');
        }

        return new self(
            task: $task,
            provider: 'openai',
            model: (string) $entry['model'],
            pricing: (string) $entry['pricing'],
            variations: $variations,
            apiVersion: null,
            shape: null,
            size: $size,
            quality: $quality,
            aspectRatio: null,
            imageSize: null,
            unitCostUsd: null,
            pricingVersion: null,
        );
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<string, mixed>  $controls
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private static function gemini(string $task, array $entry, array $controls, array $data, int $variations): self
    {
        $aspect = self::choice($data, 'aspect_ratio', $controls['aspect_ratios']);
        $imageSize = self::choice($data, 'image_size', $controls['image_sizes']);
        $price = $controls['prices'][$imageSize] ?? null;
        $version = $entry['pricing_version'] ?? null;

        /*
         * Dung DUNG phep kiem cua `DesignImageStore::withPriceSnapshot()`. Nua voi —
         * co don gia ma thieu phien ban — thi Store coi nhu spec khong mang gia va
         * di tra `OpenAiImagePricing::unitFor($model, $quality)`. Bang gia do khong
         * co model Gemini nen no tra `null`, va o duoc tao ra voi gia rong.
         *
         * Luc do o KHONG con bao dam gia ngay tu luc tao: no phai trong cho backfill
         * va lai duoc luc claim, va neu backfill cung khong tra duoc gia thi bi chan
         * han. Chan o day de mot o moi khong bao gio phai song bang mot trong hai
         * ket cuc do.
         */
        if ((string) $entry['pricing'] === 'estimated' && ! self::priced($price, $version)) {
            throw new InvalidArgumentException('model Gemini khai estimated nhung bang gia khong day du');
        }

        return new self(
            task: $task,
            provider: 'gemini',
            model: (string) $entry['model'],
            pricing: (string) $entry['pricing'],
            variations: $variations,
            apiVersion: (string) $entry['api_version'],
            shape: (string) $entry['shape'],
            size: null,
            quality: null,
            aspectRatio: $aspect,
            imageSize: $imageSize,
            unitCostUsd: self::priced($price, $version) ? (float) $price : null,
            pricingVersion: is_string($version) ? trim($version) : null,
        );
    }

    private static function priced(mixed $price, mixed $version): bool
    {
        return (is_int($price) || is_float($price))
            && (float) $price > 0
            && is_finite((float) $price)
            && is_string($version)
            && trim($version) !== '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     *
     * @throws InvalidArgumentException
     */
    private static function choice(array $data, string $field, array $allowed): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || ! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException($field.' ngoai danh sach cua model');
        }

        return $value;
    }

    /**
     * Nhan so nguyen hoac chuoi TOAN chu so.
     *
     * Khong ep `(int)`: `1.9` va `'1abc'` deu thanh `1`. Va `\A...\z` chu khong
     * `^...$` — `$` con khop truoc mot `\n` cuoi chuoi, nen `"1\n"` se lot.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    private static function variations(array $data, int $max): int
    {
        $value = $data['variations'] ?? null;

        if (is_string($value) && preg_match('/\A[0-9]+\z/', $value) === 1) {
            $value = (int) $value;
        }

        if (! is_int($value) || $value < 1 || $value > $max) {
            throw new InvalidArgumentException('variations ngoai khoang cua model');
        }

        return $value;
    }
}
