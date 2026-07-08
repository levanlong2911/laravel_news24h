<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Knowledge;

/**
 * Layer 1 extraction contract.
 *
 * Implementations accept clean article text and a domain hint, then return
 * an ordered array of FilmFact objects ready for ContextualMeaningResolver.
 *
 * Two implementations ship in Phase 1:
 *   - ClaudeFilmOSFactExtractor  : live extraction via Claude Haiku (real pipeline)
 *   - ArticleFactAdapter         : bridges existing ArticleFact DB rows (reuse if already extracted)
 */
interface FilmOSFactExtractor
{
    /**
     * @param  string $articleText   Plain-text content of the article (no HTML).
     * @param  string $domain        Domain hint: travel_warning, sports, documentary, etc.
     * @return FilmFact[]
     */
    public function extract(string $articleText, string $domain): array;
}
