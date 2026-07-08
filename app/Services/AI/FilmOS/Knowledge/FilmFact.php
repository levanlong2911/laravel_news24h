<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Knowledge;

/**
 * Layer 1 typed fact — a single independently-checkable claim from a source article.
 *
 * category values : EVIDENCE | RESULT | CONTEXT
 * visualRelevance : HIGH | MEDIUM | LOW
 * confidence      : 0.0–1.0 (extraction confidence, not editorial truth-value)
 * visualHint      : what the visual should show to illustrate this fact
 */
final class FilmFact
{
    public function __construct(
        public readonly string $id,
        public readonly string $text,
        public readonly string $category,
        public readonly string $visualRelevance,
        public readonly float  $confidence,
        public readonly string $visualHint,
    ) {}

    /**
     * Convert to the array format expected by ContextualMeaningResolver and all
     * existing FilmOS pipeline components.
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'text'             => $this->text,
            'category'         => $this->category,
            'visual_relevance' => $this->visualRelevance,
            'confidence'       => $this->confidence,
            'visual_hint'      => $this->visualHint,
        ];
    }

    /**
     * Build from the array format (either FilmOS-native or legacy pipeline format).
     * Handles both snake_case keys and legacy field names (statement, source_excerpt).
     */
    public static function fromArray(array $data): self
    {
        // Normalise confidence: string ("high"/"medium"/"low") → float
        $rawConf   = $data['confidence'] ?? 0.70;
        $confidence = is_string($rawConf) ? self::parseConfidenceString($rawConf) : (float) $rawConf;

        return new self(
            id:             strtoupper($data['id'] ?? 'F?'),
            text:           $data['text'] ?? $data['statement'] ?? '',
            category:       strtoupper($data['category']         ?? 'CONTEXT'),
            visualRelevance: strtoupper($data['visual_relevance'] ?? 'MEDIUM'),
            confidence:     $confidence,
            visualHint:     $data['visual_hint'] ?? $data['source_excerpt'] ?? '',
        );
    }

    /** Map legacy string confidence to float midpoint of each bucket. */
    private static function parseConfidenceString(string $level): float
    {
        return match (strtolower($level)) {
            'high'   => 0.90,
            'medium' => 0.72,
            'low'    => 0.55,
            default  => 0.70,
        };
    }
}
