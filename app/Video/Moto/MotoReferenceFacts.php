<?php

declare(strict_types=1);

namespace App\Video\Moto;

final class MotoReferenceFacts
{
    public const NOW = 'now';

    public const CONSTANT = 'constant';

    public const TARGET = 'target';

    /** @var list<string> the topics whose facts hold only at the cell's build state and checkpoint */
    private const SCOPED = ['surface', 'state', 'placement', 'shot', 'view', 'decision'];

    /** @var array<string, array{id: string, part_id: ?string, element: ?string, topic: string, time: string, scope: ?array{state: string, checkpoint: ?string}, value: string, source_path: string, basis: list<array<string, mixed>>}> */
    private array $facts = [];

    /** @param  array{state: string, checkpoint: ?string}  $scope  the build state and checkpoint of the cell */
    public function __construct(private readonly array $scope) {}

    /** @var array<string, string> the id of each fact by its path and value */
    private array $index = [];

    /** @var list<array{id: string, text: string, fact_refs: list<string>, section: string, must_rewrite: bool}> */
    private array $lines = [];

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $lines
     */
    public static function from(array $facts, array $lines, array $scope): self
    {
        $built = new self($scope);

        foreach ($facts as $fact) {
            $built->facts[(string) $fact['id']] = $fact;
            $built->index[$fact['source_path'].'|'.$fact['value']] = (string) $fact['id'];
        }

        $built->lines = array_values($lines);

        return $built;
    }

    /**
     * @param  string  $topic  identity, geometry, surface, state, placement, relation, view, shot, rule or decision
     * @param  list<array<string, mixed>>  $basis  what in the sources decided a resolved value
     * @param  string  $time  now holds at the cell's moment, constant at every moment, target only once the part is finished and fitted
     * @return string the id of the fact, the same id for the same path and value
     */
    public function add(?string $part, ?string $element, string $topic, string $value, string $path, array $basis = [], string $time = self::NOW): string
    {
        $key = $path.'|'.$value;

        if (isset($this->index[$key])) {
            return $this->index[$key];
        }

        $id = sprintf('F%02d', count($this->facts) + 1);
        $this->facts[$id] = ['id' => $id, 'part_id' => $part, 'element' => $element, 'topic' => $topic, 'time' => $time, 'scope' => in_array($topic, self::SCOPED, true) ? $this->scope : null, 'value' => $value, 'source_path' => $path, 'basis' => $basis];
        $this->index[$key] = $id;

        return $id;
    }

    /**
     * @param  list<string>  $refs  the facts the line is written from
     * @param  string  $section  the section of the cell the line belongs to
     * @param  bool  $mustRewrite  the source text cannot go to the image model as it stands
     */
    public function line(string $text, array $refs, string $section, bool $mustRewrite = false): void
    {
        foreach ($this->lines as $line) {
            if ($line['text'] === $text) {
                return;
            }
        }

        $this->lines[] = ['id' => sprintf('L%02d', count($this->lines) + 1), 'text' => $text, 'fact_refs' => array_values(array_unique($refs)), 'section' => $section, 'must_rewrite' => $mustRewrite];
    }

    /** @return list<array{id: string, text: string, fact_refs: list<string>, section: string, must_rewrite: bool}> */
    public function lines(): array
    {
        return $this->lines;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->facts);
    }
}
