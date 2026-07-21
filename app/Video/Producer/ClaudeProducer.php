<?php

namespace App\Video\Producer;

use App\Video\Article\RawArticle;
use App\Video\Llm\LlmClient;
use App\Video\Llm\LlmRequest;
use App\Video\World\VerifiedWorldGraph;

/**
 * Producer chay tren mot model that. Chi dung cho integration test / ghi lan
 * dau (co duyet) — giong ClaudeExtractor. CI dung FakeProducer.
 */
final class ClaudeProducer implements ProducerInterface
{
    public const INSTRUCTION_VERSION = 'producer-v1';

    public function __construct(
        private readonly LlmClient $llm,
        private readonly string $model = 'sonnet',
    ) {
    }

    public function produce(RawArticle $article, VerifiedWorldGraph $world): ProducerOutput
    {
        $request = new LlmRequest(
            $this->instruction(),
            $this->renderContext($article, $world),
            self::INSTRUCTION_VERSION,
            $this->model,
        );

        $response = $this->llm->complete($request);

        return $this->parse($response->text);
    }

    private function renderContext(RawArticle $article, VerifiedWorldGraph $world): string
    {
        $lines = ["TITLE: {$article->title}", '', $article->html, '', 'VERIFIED ENTITIES:'];

        foreach ($world->entities() as $entity) {
            $lines[] = sprintf('- %s (%s)', $entity->identity?->name ?? $entity->id, $entity->type->value);
        }

        return implode("\n", $lines);
    }

    private function parse(string $text): ProducerOutput
    {
        $s = trim($text);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $s, $m)) {
            $s = trim($m[1]);
        }

        $data = json_decode($s, true);
        if (! is_array($data)) {
            $data = [];
        }

        return new ProducerOutput(
            (string) ($data['target_audience'] ?? ''),
            (string) ($data['core_conflict'] ?? ''),
            (string) ($data['visual_promise'] ?? ''),
            is_array($data['emotional_curve'] ?? null)
                ? array_map('strval', $data['emotional_curve'])
                : [],
        );
    }

    private function instruction(): string
    {
        return <<<'TEXT'
        You are a film producer reading a news article to decide why it deserves to become
        a short documentary-style video. You do not decide scenes, cameras, or render language.

        Return ONLY raw JSON, no markdown fences, no commentary:

        {
          "target_audience": "who watches this and why",
          "core_conflict": "the tension or stakes that make this worth watching",
          "visual_promise": "one sentence describing what the viewer will get to see",
          "emotional_curve": ["ordered list of emotions the video should move through"]
        }

        Ground every claim in the article and the verified entities given to you. Do not
        invent facts not present in the article. This is a creative/editorial decision, not
        a factual extraction — you may use judgment, but stay consistent with what the
        article and verified entities actually establish.
        TEXT;
    }
}
