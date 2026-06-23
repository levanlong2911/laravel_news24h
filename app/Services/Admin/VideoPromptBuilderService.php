<?php

namespace App\Services\Admin;

use App\Exceptions\VideoFrameworkNotConfiguredException;
use App\Models\CategoryContext;
use App\Models\PromptFramework;
use Illuminate\Support\Facades\Log;

/**
 * Video-pipeline counterpart to PromptBuilderService -- same {placeholder}
 * injection convention, but resolves purpose=video frameworks via
 * CategoryContext::forCategoryVideo() instead of the article-writing
 * forCategory()/framework(). Deliberately does NOT fall back to the
 * article-writing framework when a video framework isn't configured
 * (failure mode #7) -- it throws instead, so the caller can skip that
 * article with a clear log rather than silently using the wrong prompts.
 */
class VideoPromptBuilderService
{
    public function contextFor(string $categoryId): CategoryContext
    {
        $context = CategoryContext::forCategoryVideo($categoryId);

        if (!$context || !$context->videoFramework || !$context->videoFramework->is_active) {
            throw new VideoFrameworkNotConfiguredException($categoryId);
        }

        return $context;
    }

    public function frameworkFor(string $categoryId): PromptFramework
    {
        return $this->contextFor($categoryId)->videoFramework;
    }

    /**
     * Replace {placeholder} in template with real values. Logs AND strips
     * unresolved placeholders -- same behavior as PromptBuilderService::inject():
     * a typo'd placeholder in a seeded prompt fails loud in logs, but the
     * literal "{some_var}" text is never shipped to Claude (which would
     * otherwise read it as part of the instructions/narration).
     */
    public function inject(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }

        if (preg_match_all('/\{[a-z_]+\}/', $template, $matches) > 0) {
            Log::warning('[VideoPromptBuilder] Unresolved placeholders detected', [
                'placeholders' => array_values($matches[0]),
            ]);
            foreach ($matches[0] as $placeholder) {
                $template = str_replace($placeholder, '', $template);
            }
        }

        return $template;
    }
}
