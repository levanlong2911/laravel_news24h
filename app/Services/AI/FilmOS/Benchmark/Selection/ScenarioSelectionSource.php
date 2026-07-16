<?php

declare(strict_types=1);

namespace App\Services\AI\FilmOS\Benchmark\Selection;

use App\Services\AI\FilmOS\Selection\ArticleFact;
use App\Services\AI\FilmOS\Selection\ArticleModel;
use App\Services\AI\FilmOS\Selection\BeatContext;
use App\Services\AI\FilmOS\Selection\Entity;
use App\Services\AI\FilmOS\Selection\FactRelevance;

/**
 * One authored scenario, split along the line ADR-019 draws.
 *
 * A scenario file is three different things wearing one hat:
 *   - the ARTICLE MODEL   facts, entities, topic_entity        -> policy input
 *   - the BEAT CONTEXTS   who is on screen per beat            -> policy input
 *   - the REFERENCE       what the author chose to look at     -> evaluator only
 *
 * This class hands out the first two and never the third; ReferenceSelection is a
 * separate class for a reason. It also never exposes `shots[].action` or
 * `ending_frame`: that prose IS the author's fact selection, written out longhand.
 * A policy that read it would be copying the answer in the guise of understanding.
 */
final class ScenarioSelectionSource
{
    private function __construct(private readonly array $doc) {}

    public static function fromFile(string $path): self
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("Scenario not found: {$path}");
        }
        return new self(json_decode($raw, true, 512, JSON_THROW_ON_ERROR));
    }

    public function id(): string
    {
        return (string) ($this->doc['id'] ?? 'unknown');
    }

    /**
     * Has this scenario been given its identity annotations yet?
     *
     * The v1 scenarios have not. They are skipped rather than guessed at: inferring
     * which entity a fact describes from its English is exactly the prose-parsing
     * this layer is forbidden to do.
     */
    public function hasIdentity(): bool
    {
        if (!isset($this->doc['topic_entity'])) {
            return false;
        }
        foreach ($this->doc['facts'] ?? [] as $f) {
            if (!isset($f['entity_refs'])) {
                return false;
            }
        }
        return true;
    }

    public function articleModel(): ArticleModel
    {
        $entities = [];
        foreach ($this->doc['world_objects'] ?? [] as $o) {
            $entities[$o['id']] = new Entity(
                id:         (string) $o['id'],
                type:       (string) ($o['type'] ?? 'object'),
                label:      (string) ($o['label'] ?? $o['id']),
                attributes: array_map('strval', $o['attributes'] ?? []),
            );
        }

        $facts = [];
        foreach ($this->doc['facts'] ?? [] as $f) {
            if (!isset($f['entity_refs'])) {
                throw new \RuntimeException("Fact {$f['id']} has no entity_refs — run the identity annotation first.");
            }
            $facts[] = new ArticleFact(
                id:         (string) $f['id'],
                entityRefs: array_map('strval', $f['entity_refs']),
                category:   (string) ($f['category'] ?? 'CONTEXT'),
                relevance:  FactRelevance::from((string) ($f['visual_relevance'] ?? 'LOW')),
                confidence: (float) ($f['confidence'] ?? 0.0),
                visualHint: isset($f['visual_hint']) ? (string) $f['visual_hint'] : null,
            );
        }

        $topic = (string) ($this->doc['topic_entity'] ?? '');
        if ($topic === '' || !isset($entities[$topic])) {
            throw new \RuntimeException("Scenario {$this->id()} has no valid topic_entity.");
        }

        return new ArticleModel(
            id:          $this->id(),
            entities:    $entities,
            topicEntity: $topic,
            facts:       $facts,
            goal:        (string) ($this->doc['goal'] ?? ''),
            visualStyle: (string) ($this->doc['visual_style'] ?? ''),
        );
    }

    /**
     * Staging per beat, translated from the scenario's node vocabulary into plain
     * entity ids — production has no nodes, and the policy must not learn the word.
     *
     * @return BeatContext[]
     */
    public function beatContexts(): array
    {
        $contexts = [];

        foreach ($this->doc['scene_nodes'] ?? [] as $beat => $nodes) {
            $entities = [];
            foreach ($nodes as $n) {
                $ref = $n['world_object_ref'] ?? $n['character_ref'] ?? null;
                if ($ref !== null && !in_array($ref, $entities, true)) {
                    $entities[] = (string) $ref;
                }
            }
            $contexts[] = new BeatContext(
                beat:            (string) $beat,
                visibleEntities: $entities,
            );
        }

        return $contexts;
    }

    /** Node id -> entity id, so the reference can be compared in the policy's own vocabulary. */
    public function nodeToEntity(): array
    {
        $map = [];
        foreach ($this->doc['scene_nodes'] ?? [] as $nodes) {
            foreach ($nodes as $n) {
                $ref = $n['world_object_ref'] ?? $n['character_ref'] ?? null;
                if ($ref !== null) {
                    $map[(string) $n['id']] = (string) $ref;
                }
            }
        }
        return $map;
    }

    /** @internal for ReferenceSelection only */
    public function shots(): array
    {
        return $this->doc['shots'] ?? [];
    }
}
