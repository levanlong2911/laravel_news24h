<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Knowledge;

use App\Models\Article;
use App\Models\ArticleFact;

/**
 * Bridges the existing ArticleFact DB model → FilmFact[] for FilmOS.
 *
 * Use this when an article already has an ArticleFact row (extracted by the
 * existing FactExtractorService in the Admin pipeline). Avoids a second Claude
 * call and respects the idempotency contract on ArticleFact.
 *
 * If no ArticleFact exists, delegate to ClaudeFilmOSFactExtractor instead.
 */
final class ArticleFactAdapter
{
    public function __construct(
        private readonly ClaudeFilmOSFactExtractor $fallbackExtractor,
    ) {}

    /**
     * Return FilmFact[] for the given Article.
     * Loads existing ArticleFact if present; otherwise runs live extraction.
     *
     * @return FilmFact[]
     */
    public function factsFor(Article $article, string $domain): array
    {
        /** @var ArticleFact|null $existing */
        $existing = $article->articleFact;

        if ($existing !== null) {
            return $this->adaptFromModel($existing);
        }

        // No DB row yet — extract via Claude and persist for reuse
        $plainText = $this->stripHtml($article->content ?? $article->summary ?? '');
        $facts     = $this->fallbackExtractor->extract($plainText, $domain);

        // Persist so the next call (ScriptGenerator, Story Planner, etc.) reuses it
        if (!empty($facts)) {
            ArticleFact::create([
                'article_id'         => $article->id,
                'facts_json'         => array_map(fn(FilmFact $f) => $f->toArray(), $facts),
                'confidence'         => 'medium',   // placeholder; per-fact confidence is in FilmFact
                'escalated_to_sonnet'=> false,
                'entities_json'      => null,
            ]);
        }

        return $facts;
    }

    /**
     * Adapt an existing ArticleFact DB row to FilmFact[].
     *
     * Existing format:
     *   {id, statement, category, visual_relevance, source_excerpt}
     *   + global confidence string on the parent row
     *
     * @return FilmFact[]
     */
    private function adaptFromModel(ArticleFact $model): array
    {
        $globalConf = match (strtolower((string) ($model->confidence ?? 'medium'))) {
            'high'   => 0.90,
            'medium' => 0.72,
            'low'    => 0.55,
            default  => 0.70,
        };

        $facts = [];
        foreach (($model->facts_json ?? []) as $i => $raw) {
            // Normalise text field (legacy uses 'statement', FilmOS uses 'text')
            $raw['text'] = $raw['text'] ?? $raw['statement'] ?? '';

            // Per-fact confidence falls back to the global row value
            if (!isset($raw['confidence'])) {
                $raw['confidence'] = $globalConf;
            }

            // Normalise ID to uppercase F1, F2, ...
            $raw['id'] = 'F' . ($i + 1);

            // Derive visual_hint from source_excerpt when not present
            if (empty($raw['visual_hint']) && !empty($raw['source_excerpt'])) {
                $raw['visual_hint'] = mb_substr((string) $raw['source_excerpt'], 0, 120);
            }

            $facts[] = FilmFact::fromArray($raw);
        }

        return $facts;
    }

    private function stripHtml(string $html): string
    {
        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
