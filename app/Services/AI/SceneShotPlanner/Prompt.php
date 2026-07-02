<?php

namespace App\Services\AI\SceneShotPlanner;

use App\DTOs\BeatDTO;

final class Prompt
{
    public static function build(BeatDTO $beat, int $momentCount): string
    {
        $infoType = strtolower($beat->informationType);

        return <<<PROMPT
You are a visual storyteller. For the narrative beat below, generate exactly {$momentCount} distinct visual moments.

**Beat:**
- Goal: {$beat->goal}
- Viewer question: {$beat->viewerQuestion}
- Information type: {$beat->informationType}
- Emotion: {$beat->emotion}
- Duration: {$beat->duration}s
- Narrative intent: {$beat->narrativeIntent}

**Your task:**
Describe {$momentCount} visual moments that tell this beat's story. Think like a documentary cinematographer — what does the camera see? Focus on concrete visual ideas, not camera instructions.

**Each moment:**
- visual_intent: one sentence — exactly what the camera sees (no camera angles, no lens types)
- subject: who or what is the primary focus
- action: what is physically happening
- duration_hint: approximate seconds (all hints must sum to ~{$beat->duration}s)
- importance: HIGH | MEDIUM | LOW
  - HIGH = unmissable visual detail, the shot viewers remember
  - MEDIUM = supporting context, adds meaning
  - LOW = transition or establishing filler

**Rules:**
- Exactly {$momentCount} moments
- Information type is {$infoType} — let that shape which moments are visual priorities
- Do NOT mention camera type, lens, angle, movement, or shot size
- Do NOT include moment_index — the system assigns it
- All duration_hints must sum to approximately {$beat->duration}

Return ONLY a JSON object, no markdown:
{
  "moments": [
    {
      "visual_intent": "Rivets on worn leather seat catch the light",
      "subject": "motorcycle seat leather",
      "action": "camera dwells on surface texture",
      "duration_hint": 1.5,
      "importance": "HIGH"
    }
  ]
}
PROMPT;
    }
}
