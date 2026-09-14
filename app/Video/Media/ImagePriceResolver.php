<?php

namespace App\Video\Media;

use App\Enums\ImageQuality;

/**
 * Mot cho duy nhat tra gia cho mot spec, dung cho ca hai provider.
 *
 * Catalog la nguon gia duy nhat. Registry chi noi model duoc phep dung gi, va
 * noi ten file catalog cua no — no khong phai noi giu gia.
 */
final class ImagePriceResolver
{
    public function __construct(
        private readonly OpenAiImagePricing $openai = new OpenAiImagePricing,
        private readonly GeminiImagePricing $gemini = new GeminiImagePricing,
    ) {}

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>|null  $entry  entry registry cua dung model dang render
     * @return array{usd: float, version: string}|null
     */
    public function forSpec(array $spec, ?array $entry = null): ?array
    {
        $provider = (string) ($spec['provider'] ?? 'openai');

        if ($provider === 'openai') {
            // CUNG phep chuan hoa ma renderer dung luc gui request: o thieu quality
            // van render duoc o `high`, nen no cung phai tra gia duoc.
            return $this->openai->unitFor(
                (string) ($spec['model'] ?? ''),
                ImageQuality::fromSpecOrHigh($spec['quality'] ?? '')->value,
            );
        }

        if ($provider !== 'gemini' || ! is_array($entry)) {
            return null;
        }

        if (($entry['id'] ?? null) !== $provider.':'.($spec['model'] ?? '')
            || ! is_string($entry['evidence']['pricing'] ?? null)) {
            return null;
        }

        return $this->gemini->unitFor(
            $entry['evidence']['pricing'],
            (string) $entry['model'],
            (string) ($spec['image_size'] ?? ''),
        );
    }
}
