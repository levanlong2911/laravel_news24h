<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Render\Serializers;

use App\Services\AI\FilmOS\Render\ProviderCapability;
use App\Services\AI\FilmOS\Render\ProviderSerializer;
use App\Services\AI\FilmOS\Render\RenderIR;

final class KlingSerializer implements ProviderSerializer
{
    private const MODEL_NAME     = 'kling-v1';
    private const MODE           = 'std';
    private const CFG_SCALE      = 0.5;
    private const DEFAULT_ASPECT = '16:9';
    private const VALID_DURATIONS = [5, 10];

    public function provider(): string
    {
        return 'kling';
    }

    public function capability(): ProviderCapability
    {
        return new ProviderCapability(
            maxDurationSeconds: 10,
            supportsVideo:      true,
            supportsImage:      false,
            supportsAudio:      false,
        );
    }

    public function supports(RenderIR $ir): bool
    {
        $duration = (int) ($ir->constraints['duration'] ?? $ir->durationSeconds);
        return $duration <= $this->capability()->maxDurationSeconds;
    }

    /** @return array<string, mixed> */
    public function serialize(RenderIR $ir): array
    {
        $payload = [
            'model_name'   => self::MODEL_NAME,
            'prompt'       => $this->buildPrompt($ir->renderInstructions),
            'cfg_scale'    => self::CFG_SCALE,
            'mode'         => self::MODE,
            'duration'     => (string) $this->resolveDuration($ir),
            'aspect_ratio' => (string) ($ir->renderInstructions['aspectRatio']
                                        ?? $ir->metadata['aspectRatio']
                                        ?? self::DEFAULT_ASPECT),
        ];

        $negativePrompt = (string) ($ir->renderInstructions['negativePrompt']
                                    ?? $ir->metadata['negativePrompt']
                                    ?? '');
        if ($negativePrompt !== '') {
            $payload['negative_prompt'] = $negativePrompt;
        }

        return $payload;
    }

    private function buildPrompt(array $renderInstructions): string
    {
        $parts = [];

        if (!empty($renderInstructions['description'])) {
            $parts[] = (string) $renderInstructions['description'];
        }

        if (!empty($renderInstructions['camera'])) {
            $parts[] = $this->mapCamera((string) $renderInstructions['camera']);
        }

        if (!empty($renderInstructions['visualStrategy'])) {
            $parts[] = $this->mapVisualStrategy((string) $renderInstructions['visualStrategy']);
        }

        if (!empty($renderInstructions['style'])) {
            $parts[] = (string) $renderInstructions['style'] . ' style';
        }

        return implode(', ', array_filter($parts));
    }

    private function resolveDuration(RenderIR $ir): int
    {
        $requested = (int) ($ir->constraints['duration'] ?? $ir->durationSeconds);
        // Kling only supports 5 or 10 — pick nearest valid value
        return abs($requested - 5) <= abs($requested - 10) ? 5 : 10;
    }

    private function mapCamera(string $camera): string
    {
        return match ($camera) {
            'close_up'    => 'extreme close-up shot',
            'medium_shot' => 'medium shot',
            'wide'        => 'wide angle shot',
            'overhead'    => 'overhead shot',
            'tracking'    => 'tracking shot',
            default       => $camera,
        };
    }

    private function mapVisualStrategy(string $strategy): string
    {
        return match ($strategy) {
            'urgent'        => 'fast-paced dynamic cinematography',
            'documentary'   => 'documentary style filming',
            'contemplative' => 'slow deliberate cinematography',
            'observational' => 'observational documentary style',
            'dynamic'       => 'dynamic cinematic movement',
            default         => $strategy,
        };
    }
}
