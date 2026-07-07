<?php

namespace App\Services\AI\Video;

use App\Models\Article;
use App\Models\ArticleFact;
use App\Services\Admin\ClaudeWriterService;
use Illuminate\Support\Facades\Log;

/**
 * Asks Claude Sonnet to extract exactly 3 T2V scene specs from real article data.
 *
 * All scene fields are grounded in article facts — no fabrication.
 * Each scene is 5 seconds; 3 scenes = 15s total video.
 */
final class ArticleVideoSceneExtractor
{
    public function __construct(
        private readonly ClaudeWriterService $claude,
    ) {}

    /**
     * Extract 3 structured T2V scenes from the article's extracted facts.
     *
     * @return array<int, array{
     *   scene_number: int,
     *   duration_seconds: int,
     *   subject: string,
     *   action_type: string,
     *   setting: string,
     *   time_of_day: string,
     *   weather: string,
     *   mood: string,
     *   camera: string,
     *   key_fact: string
     * }>
     */
    public function extract(Article $article, ArticleFact $facts): array
    {
        $factsText    = json_encode($facts->facts_json,    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $entitiesText = json_encode($facts->entities_json ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $prompt = <<<PROMPT
You are a video director creating a 15-second Kling AI video from a news article.
Plan exactly 3 scenes of 5 seconds each based ONLY on the article's extracted facts.

Article title: {$article->title}

Extracted facts (use ONLY these — no fabrication):
{$factsText}

Named entities from article:
{$entitiesText}

SCENE STRUCTURE:
- Scene 1 (0–5s): Establishing — WHO is involved, WHERE is the location
- Scene 2 (5–10s): Key action — the MAIN EVENT from the article
- Scene 3 (10–15s): Resolution — the OUTCOME or reaction from the article

STRICT RULES:
1. "subject" must be the exact real name/entity from the article (e.g. "Patrick Mahomes", not "an athlete")
2. "setting" must be the exact real location from the article (e.g. "Arrowhead Stadium, Kansas City")
3. "key_fact" must directly quote or closely paraphrase one specific fact from the article
4. action_type must be EXACTLY one of: throw, run, celebrate, kick, dunk, sprint, tackle, score, block, pass, jump, dive, catch, shoot
5. time_of_day must be EXACTLY one of: day, night, indoor
6. weather must be EXACTLY one of: clear, rain, snow, cloudy
7. mood must be EXACTLY one of: POWER, JOY, EPIC, TENSE, AWE, DRAMA, CALM
8. camera must be EXACTLY one of: CLOSE, MEDIUM, WIDE, AERIAL, TRACKING

Return ONLY valid JSON (no markdown, no explanation):
{"scenes":[{"scene_number":1,"duration_seconds":5,"subject":"exact name from article","action_type":"throw","setting":"exact venue from article","time_of_day":"night","weather":"clear","mood":"POWER","camera":"WIDE","key_fact":"verbatim or close paraphrase from article"},{"scene_number":2,...},{"scene_number":3,...}]}
PROMPT;

        $response = $this->claude->generate($prompt, 'sonnet', '');
        $text     = $response->text;

        // Strip markdown code fences if Claude wraps in ```json
        $text = (string) preg_replace('/```(?:json)?\s*/i', '', $text);
        $text = (string) preg_replace('/```\s*$/', '', $text);
        $text = trim($text);

        $parsed = json_decode($text, true);

        if (!is_array($parsed) || empty($parsed['scenes'])) {
            // Try to extract JSON object from within a larger response
            if (preg_match('/\{.*\}/s', $text, $m)) {
                $parsed = json_decode($m[0], true);
            }
        }

        if (!is_array($parsed) || empty($parsed['scenes'])) {
            Log::error('[ArticleVideoSceneExtractor] Failed to parse scenes JSON', [
                'article_id'       => $article->id,
                'response_preview' => mb_substr($text, 0, 400),
            ]);
            throw new \RuntimeException(
                "ArticleVideoSceneExtractor: could not parse scene JSON for article {$article->id}"
            );
        }

        return array_values($parsed['scenes']);
    }
}
