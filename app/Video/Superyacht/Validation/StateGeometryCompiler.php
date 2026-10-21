<?php

namespace App\Video\Superyacht\Validation;

/**
 * Compiles a BuildState's structured fields into its English compiled_text
 * (STATE GEOMETRY + SURFACE STATE). Deterministic: the same fields always give
 * the same sentence, which is what INV-BS-04 checks.
 *
 * Example (BS08):
 * "The yacht under construction with a complete hull shell, an open main deck and
 *  the superstructure; no glazing, no mast. Surfaces: hull shell in red-oxide primer,
 *  superstructure in raw unpainted aluminium."
 */
final class StateGeometryCompiler
{
    /**
     * @param array<string,array{phrase:string,keywords:array<int,string>}> $parts
     * @param array<string,string> $surfaces
     */
    public function __construct(private readonly array $parts, private readonly array $surfaces)
    {
    }

    public static function fromConfig(array $config): self
    {
        return new self($config['parts'], $config['surfaces']);
    }

    /** @param array{kind:string,present_parts:array,absent_parts:array,surfaces_by_part:array|object} $state */
    public function compile(array $state): string
    {
        $prefix = match ($state['kind']) {
            'scale_model' => 'A white 1:25 tank model with',
            'finished' => 'The completed yacht with',
            default => 'The yacht under construction with',
        };

        $present = $this->ordered($state['present_parts']);
        $absent = $this->ordered($state['absent_parts']);

        $text = $prefix . ' ' . $this->joinList(array_map(fn ($p) => $this->parts[$p]['phrase'], $present));

        if ($absent !== []) {
            $text .= '; ' . implode(', ', array_map(fn ($p) => 'no ' . $this->parts[$p]['keywords'][0], $absent));
        }
        $text .= '.';

        $surfaces = (array) $state['surfaces_by_part'];
        if ($surfaces !== []) {
            $items = [];
            foreach ($this->ordered(array_keys($surfaces)) as $part) {
                $items[] = $this->parts[$part]['keywords'][0] . ' in ' . $this->surfaces[$surfaces[$part]];
            }
            $text .= ' Surfaces: ' . implode(', ', $items) . '.';
        }

        return $text;
    }

    /**
     * Parts mentioned positively (not after "no"/"without") in a free text block.
     *
     * @return array<int,string>
     */
    public function positiveMentions(string $text): array
    {
        $clauses = preg_split('/[,;.:]|\band\b|\bwith\b/i', mb_strtolower($text)) ?: [];
        $found = [];

        foreach ($clauses as $clause) {
            $clause = trim($clause);
            if ($clause === '' || preg_match('/^(no|without|not)\b/', $clause) === 1) {
                continue;
            }
            foreach ($this->parts as $part => $def) {
                foreach ($def['keywords'] as $keyword) {
                    // whole words, plural allowed: "mast" matches "masts" but not "master"
                    $pattern = '/\b' . preg_quote(mb_strtolower($keyword), '/') . '(e?s)?\b/u';
                    if (preg_match($pattern, $clause) === 1) {
                        $found[$part] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /** @param array<int,string> $parts @return array<int,string> parts in vocabulary order */
    private function ordered(array $parts): array
    {
        return array_values(array_filter(array_keys($this->parts), fn ($p) => in_array($p, $parts, true)));
    }

    /** @param array<int,string> $items */
    private function joinList(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? 'nothing yet';
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
