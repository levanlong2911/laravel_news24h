<?php

namespace App\Exceptions;

/**
 * Failure mode #7 (see Python-side AI VIDEO plan): if a category has no
 * purpose=video prompt framework configured, the video pipeline must fail
 * loud and skip that article -- never crash uncontrolled, and never
 * silently fall back to the article-writing framework (which would produce
 * video narration in the wrong voice/structure with no obvious error).
 */
class VideoFrameworkNotConfiguredException extends \RuntimeException
{
    public function __construct(string $categoryId)
    {
        $message = "No purpose=video prompt framework configured for category {$categoryId}. "
            . "Run VideoPromptFrameworkSeeder and link it via CategoryContext::video_framework_id before "
            . "this category's articles can enter the video pipeline.";
        parent::__construct($message);
    }
}
