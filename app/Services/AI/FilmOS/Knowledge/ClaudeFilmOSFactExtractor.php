<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Knowledge;

use App\Services\Admin\ClaudeWriterService;
use Illuminate\Support\Facades\Log;

/**
 * Extracts FilmOS-typed facts directly from article text via Claude Haiku.
 * Auto-escalates to Sonnet when Haiku returns < MIN_FACTS or low confidence.
 *
 * Output is immediately compatible with ContextualMeaningResolver — no adapter needed.
 */
final class ClaudeFilmOSFactExtractor implements FilmOSFactExtractor
{
    private const MIN_FACTS              = 3;
    private const MAX_CONTENT_CHARS      = 4500;
    private const FALLBACK_CONFIDENCE    = 0.70;

    public function __construct(
        private readonly ClaudeWriterService $claude,
    ) {}

    /**
     * @return FilmFact[]
     */
    public function extract(string $articleText, string $domain): array
    {
        $text   = $this->truncate($articleText);
        $prompt = $this->buildPrompt($text, $domain);

        // Haiku first
        $response = $this->claude->generate($prompt, 'haiku');
        $parsed   = $this->parseJson($response->text);

        // Escalate to Sonnet when confidence is low or too few facts
        $confidence  = $parsed['confidence'] ?? 'low';
        $factCount   = count($parsed['facts'] ?? []);
        $needsEscalation = $parsed === null
            || in_array($confidence, ['low', 'medium'], true)
            || $factCount < self::MIN_FACTS;

        if ($needsEscalation) {
            Log::info('[FilmOS] FactExtractor escalating to Sonnet', [
                'domain'          => $domain,
                'haiku_confidence' => $confidence,
                'haiku_facts'      => $factCount,
            ]);
            $sonnetResponse = $this->claude->generate($prompt, 'sonnet');
            $sonnetParsed   = $this->parseJson($sonnetResponse->text);
            if ($sonnetParsed !== null) {
                $parsed = $sonnetParsed;
            }
        }

        if ($parsed === null || empty($parsed['facts'])) {
            Log::error('[FilmOS] FactExtractor: Claude returned unparseable JSON', [
                'domain' => $domain,
                'chars'  => strlen($articleText),
            ]);
            return [];
        }

        // Map global confidence string → per-fact float for facts missing individual scores
        $globalConfidence = match (strtolower($parsed['confidence'] ?? 'medium')) {
            'high'   => 0.90,
            'medium' => 0.72,
            'low'    => 0.55,
            default  => self::FALLBACK_CONFIDENCE,
        };

        $facts = [];
        foreach ($parsed['facts'] as $i => $raw) {
            // Assign per-fact confidence: use global if not present
            if (!isset($raw['confidence'])) {
                $raw['confidence'] = $globalConfidence;
            }
            // Normalise ID to F1, F2, ...
            $raw['id'] = 'F' . ($i + 1);

            $facts[] = FilmFact::fromArray($raw);
        }

        return $facts;
    }

    private function buildPrompt(string $articleText, string $domain): string
    {
        return <<<PROMPT
Domain: {$domain}

Article content:
{$articleText}

Extract every discrete, independently-checkable fact from this article. For each fact:
- Classify its category: EVIDENCE (physical proof, observations, specific incidents), RESULT (consequences, decisions, official responses), or CONTEXT (background, location, setting, general info)
- Rate visual_relevance: HIGH (can be shown directly on camera), MEDIUM (can be illustrated), or LOW (abstract or numbers only)
- Estimate confidence 0.0–1.0 in this fact's extraction accuracy (0.9+ for direct quotes, 0.7 for inferred, 0.5 for ambiguous)
- Write a visual_hint: one short phrase describing what video footage should show to illustrate this fact

Return ONLY a valid JSON object in this exact shape — no markdown fences, no commentary:
{
  "confidence": "high|medium|low",
  "facts": [
    {
      "text": "the fact as a complete sentence",
      "category": "EVIDENCE|RESULT|CONTEXT",
      "visual_relevance": "HIGH|MEDIUM|LOW",
      "confidence": 0.0,
      "visual_hint": "what the video should show"
    }
  ]
}
PROMPT;
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) > self::MAX_CONTENT_CHARS) {
            return mb_substr($text, 0, self::MAX_CONTENT_CHARS)
                . "\n\n[Content truncated — extract facts from the above section only.]";
        }
        return $text;
    }

    private function parseJson(string $text): ?array
    {
        // Strip markdown fences if Claude added them despite instructions
        $clean = preg_replace('/^```(?:json)?\s*/m', '', trim($text));
        $clean = preg_replace('/^```\s*$/m', '', $clean);

        $decoded = json_decode(trim($clean), true);
        return is_array($decoded) ? $decoded : null;
    }
}
