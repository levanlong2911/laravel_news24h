<?php

namespace App\Services\AI\StoryPlanner;

use App\DTOs\ArticleContextDTO;
use App\DTOs\TransformationDTO;

final class Prompt
{
    public static function build(ArticleContextDTO $ctx, TransformationDTO $transformation): string
    {
        $factsText  = collect($ctx->factsJson)
            ->map(fn (array $f) => "- [{$f['id']}] {$f['statement']}")
            ->implode("\n");

        $emotionArc = implode(' → ', $transformation->emotionArc);
        $totalSec   = $transformation->duration;

        return <<<PROMPT
You are a video story architect. Design a sequence of narrative beats for a {$totalSec}-second video.

**Video concept:**
Hook: {$ctx->hook}
Domain: {$ctx->domain}
Style: {$transformation->style}
Pacing: {$transformation->pacing}
Emotion arc: {$emotionArc}

**Facts to weave into the story:**
{$factsText}

**Beat field definitions:**
- goal: what this beat must achieve for the story
- viewer_question: the implicit question the viewer is asking at this moment
- information_type: one of FACT | PROCESS | COMPARISON | EMOTION | SPECULATION | DETAIL | SUMMARY
  - FACT: a declarative truth shown visually
  - PROCESS: showing how something is made or works (close-up, tool, hand shots)
  - COMPARISON: contrasting two states or objects
  - EMOTION: pure feeling, no explicit information
  - SPECULATION: forward-looking or aspirational
  - DETAIL: zoomed-in beauty shot, texture, macro
  - SUMMARY: tying threads together, resolution
- visual_priority: HIGH | MEDIUM | LOW
  - HIGH → richest visual treatment, most shot variety
  - MEDIUM → standard coverage
  - LOW → simple, let emotion carry it
- emotion: the dominant emotional register of this beat
- duration: seconds this beat occupies (all beats must sum to exactly {$totalSec}.0)
- transition: cut | dissolve | fade | match_cut | smash_cut
- narrative_intent: one sentence on why this beat exists in the arc

**Rules:**
- Design 3 to 6 beats — let the story dictate the number, not a fixed template
- Beat 1 emotion must be one of: anticipation, mystery, tension, curiosity
- No beat duration below 1.5 seconds
- Sum of all beat durations must equal exactly {$totalSec}.0 seconds
- Do NOT include beat_number — that is assigned by the system
- Each beat must have a distinct narrative purpose

Return ONLY a JSON object, no markdown:
{
  "beats": [
    {
      "goal": "Arrest attention in the first frame",
      "viewer_question": "What is this machine capable of?",
      "information_type": "EMOTION",
      "visual_priority": "HIGH",
      "emotion": "anticipation",
      "duration": 3.0,
      "transition": "smash_cut",
      "narrative_intent": "Open with visceral impact before any information lands"
    }
  ]
}
PROMPT;
    }
}
