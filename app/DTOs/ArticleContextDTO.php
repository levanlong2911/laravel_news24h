<?php

namespace App\DTOs;

use App\Models\Article;
use App\Models\ArticleFact;

final class ArticleContextDTO
{
    public function __construct(
        public readonly string $articleId,
        public readonly string $title,
        public readonly string $hook,
        public readonly string $category,
        public readonly string $domain,
        public readonly array  $factsJson,
        public readonly int    $viralScore,
    ) {}

    public static function fromModels(Article $article, ArticleFact $facts, string $domain): self
    {
        return new self(
            articleId:  $article->id,
            title:      $article->title,
            hook:       $article->fb_image_text ?: $article->title,
            category:   $article->category?->name ?? '',
            domain:     $domain,
            factsJson:  $facts->facts_json ?? [],
            viralScore: $article->viral_score ?? 0,
        );
    }

    public function toArray(): array
    {
        return [
            'article_id'  => $this->articleId,
            'title'       => $this->title,
            'hook'        => $this->hook,
            'category'    => $this->category,
            'domain'      => $this->domain,
            'facts_json'  => $this->factsJson,
            'viral_score' => $this->viralScore,
        ];
    }
}
