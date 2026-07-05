<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from VisualContrastPlanner::plan().
 *
 * Per-beat brightness + color temperature contrast — the visual rhythm layer
 * that makes each beat feel distinct from the last. The brain notices change,
 * not continuity; alternating light/dark and warm/cool creates engagement even
 * when camera motion is slow.
 *
 * Tone strings are prepended to camera text so Kling receives tonal direction
 * alongside motion instructions.
 */
final class VisualContrastPlan
{
    public function __construct(
        /** [{beat, tone}] — tone is the prepend string for camera text */
        public readonly array $beats,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(beats: $data['beats'] ?? []);
    }

    public function toArray(): array
    {
        return ['beats' => $this->beats];
    }

    public function toneFor(string $beatName): string
    {
        foreach ($this->beats as $b) {
            if ($b['beat'] === $beatName) {
                return $b['tone'] ?? '';
            }
        }
        return '';
    }

    /** Compact phrase used by BeatFusionEngine as a natural-language lighting slot. */
    public function lightPhraseFor(string $beatName): string
    {
        foreach ($this->beats as $b) {
            if ($b['beat'] === $beatName) {
                return $b['light_phrase'] ?? '';
            }
        }
        return '';
    }

    public function isEmpty(): bool { return $this->beats === []; }
}
