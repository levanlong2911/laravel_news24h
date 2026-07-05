<?php

namespace App\Services\AI\ScenePlanner\Plans;

/**
 * Typed result from CuriosityPlanner::plan().
 *
 * Holds per-beat subject overrides that implement information-gap engineering:
 * the viewer sees motion and scale before they see identity, triggering the
 * brain's pattern-completion drive — the core mechanic behind viral retention.
 *
 * Null subject_override = keep CinematicBeatPlanner subject text as-is.
 * Non-null subject_override = replace it with information-state-aware text.
 */
final class CuriosityPlan
{
    /**
     * @param string $primaryQuestion  Hook question the viewer subconsciously asks at 0s
     * @param string $pattern          Information arc pattern (concealed_to_full)
     * @param string $category         Subject category this plan was built for
     * @param array  $beatStates       {beat_name: {state, subject_override}} per beat
     */
    public function __construct(
        public readonly string $primaryQuestion,
        public readonly string $pattern,
        public readonly string $category,
        public readonly array  $beatStates,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            primaryQuestion: $data['primary_question'] ?? '',
            pattern:         $data['pattern']          ?? 'concealed_to_full',
            category:        $data['category']         ?? 'generic',
            beatStates:      $data['beat_states']      ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'primary_question' => $this->primaryQuestion,
            'pattern'          => $this->pattern,
            'category'         => $this->category,
            'beat_states'      => $this->beatStates,
        ];
    }

    public function isEmpty(): bool
    {
        return $this->beatStates === [];
    }

    /** Returns subject override for a beat, or null if the original text should be kept. */
    public function subjectOverrideFor(string $beatName): ?string
    {
        return $this->beatStates[$beatName]['subject_override'] ?? null;
    }

    public function stateFor(string $beatName): string
    {
        return $this->beatStates[$beatName]['state'] ?? 'full';
    }
}
