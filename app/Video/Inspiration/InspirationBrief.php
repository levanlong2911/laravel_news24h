<?php

namespace App\Video\Inspiration;

final class InspirationBrief
{
    /**
     * @param  list<string>  $articlePatterns
     * @param  list<SourceInsight>  $sourceInsights
     * @param  list<ExcludedContext>  $excludedContext
     */
    public function __construct(
        public readonly array $articlePatterns,
        public readonly string $articleFocus,
        public readonly array $sourceInsights,
        public readonly array $excludedContext,
    ) {}

    /**
     * Lượt hỏi lại sinh lại TOÀN BỘ brief, không vá đúng chỗ — đo được trên bài
     * Launchpad: sửa hai summary lẫn tên hãng thì mất luôn một aspect hợp lệ và
     * một article_pattern. Hợp nhất ở đây để lỗi identity không kéo theo dữ liệu
     * đã đúng.
     */
    public function mergedWith(self $newer): self
    {
        $insights = [];

        foreach ($this->sourceInsights as $insight) {
            $insights[$insight->aspect] = $insight;
        }

        foreach ($newer->sourceInsights as $insight) {
            $insights[$insight->aspect] = $insight;
        }

        $excluded = [];

        foreach ([...$this->excludedContext, ...$newer->excludedContext] as $item) {
            $excluded[$item->type.'|'.self::normalize($item->value)] ??= $item;
        }

        return new self(
            array_values(array_unique([...$this->articlePatterns, ...$newer->articlePatterns])),
            $newer->articleFocus,
            array_values($insights),
            array_values($excluded),
        );
    }

    /**
     * Nhóm quan sát mà brief NÀY chưa cung cấp insight — KHÔNG phải khẳng định
     * bài viết không có dữ liệu. Bài Synthesis nói về refit ở ba câu mà model
     * vẫn không trích; nó vẫn nằm ở đây.
     *
     * @return list<string>
     */
    public function uncoveredAspects(CategoryCreativeProfile $profile): array
    {
        $found = array_fill_keys(array_map(
            fn (SourceInsight $insight) => $insight->aspect,
            $this->sourceInsights,
        ), true);

        return array_values(array_filter(
            $profile->inspectionAspects,
            fn (string $aspect) => ! isset($found[$aspect]),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(CategoryCreativeProfile $profile): array
    {
        return [
            'article_patterns' => $this->articlePatterns,
            'article_focus' => $this->articleFocus,
            'source_insights' => array_map(fn (SourceInsight $item) => $item->toArray(), $this->sourceInsights),
            'excluded_context' => array_map(fn (ExcludedContext $item) => $item->toArray(), $this->excludedContext),
            'uncovered_aspects' => $this->uncoveredAspects($profile),
        ];
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }
}
