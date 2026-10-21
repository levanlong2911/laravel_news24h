<?php

namespace App\Video\Superyacht\Validation\Invariants;

use App\Video\Superyacht\Support\BuildStates;
use App\Video\Superyacht\Validation\StateGeometryCompiler;
use App\Video\Superyacht\Validation\ValidationReport;
use App\Video\Superyacht\Validation\Violation;

/**
 * INV-CS-01  state equals the ShotSpec state; every sources revision exists and is locked
 * INV-CS-02  each constraint status matches its from_state / until_state and the shot state
 * INV-CS-03  parts mentioned positively in identity / state blocks ⊆ present_parts of the state
 */
final class CompiledShotInvariants implements InvariantSet
{
    public function __construct(
        private readonly BuildStates $states,
        private readonly StateGeometryCompiler $compiler,
    ) {
    }

    /** Fields left out of a CompiledShot's content hash (they change after compilation). */
    /** Source types stored once per shot (artifact key = shot id). */
    public const PER_SHOT_SOURCES = ['shot_spec', 'clay', 'frame'];

    public const NON_CONTENT_FIELDS = ['hash', 'status', 'validation'];

    public static function contentHash(array|object $doc): string
    {
        return \App\Video\Superyacht\Support\CanonicalJson::hash($doc, self::NON_CONTENT_FIELDS);
    }

    public function schema(): string
    {
        return 'CompiledShot';
    }

    public function check(array $doc, InvariantContext $ctx): ValidationReport
    {
        $v = [];
        $state = $doc['state'];

        // INV-CS-01
        $spec = $ctx->shotSpec($doc['shot_id']);
        if ($spec === null) {
            $v[] = new Violation('INV-CS-01', "no ShotSpec found for {$doc['shot_id']}", '/shot_id');
        } elseif (($spec['state'] ?? null) !== $state) {
            $v[] = new Violation('INV-CS-01', "state {$state} ≠ ShotSpec state {$spec['state']}", '/state');
        }
        foreach ((array) ($doc['sources'] ?? []) as $type => $rev) {
            $n = (int) substr((string) $rev, 3);
            $key = in_array($type, self::PER_SHOT_SOURCES, true) ? $doc['shot_id'] : null;
            if (! $ctx->isLockedRevision((string) $type, $n, $key)) {
                $v[] = new Violation('INV-CS-01', "source {$type}@{$rev} does not exist or is not locked", "/sources/{$type}");
            }
        }

        // INV-CS-02
        $registry = $ctx->constraints();
        foreach ($doc['constraints_to_check'] ?? [] as $i => $item) {
            $c = $registry[$item['id']] ?? null;
            if ($c === null) {
                $v[] = new Violation('INV-CS-02', "unknown constraint {$item['id']}", "/constraints_to_check/{$i}/id");
                continue;
            }
            $allowed = $this->allowedStatuses($state, $c['from_state'], $c['until_state'] ?? null);
            if (! in_array($item['status'], $allowed, true)) {
                $v[] = new Violation('INV-CS-02', sprintf('%s at %s must be %s, got %s', $item['id'], $state, implode(' or ', $allowed), $item['status']), "/constraints_to_check/{$i}/status");
            }
        }

        // INV-CS-03 (design states BS01-BS04 show drawings of the whole design: not checked)
        if (! $this->states->isDesignState($state)) {
            $present = null;
            foreach ($ctx->lockedCanonical()['build_states'] ?? [] as $s) {
                if ($s['id'] === $state) {
                    $present = $s['present_parts'] ?? [];
                }
            }
            if ($present === null) {
                $v[] = new Violation('INV-CS-03', "locked canonical has no build state {$state}", '/state');
            } else {
                foreach ($doc['image_prompt'] ?? [] as $i => $block) {
                    if (! in_array($block['block'], ['identity', 'state'], true)) {
                        continue;
                    }
                    $extra = array_diff($this->compiler->positiveMentions($block['text']), $present);
                    if ($extra !== []) {
                        $v[] = new Violation('INV-CS-03', "{$block['block']} block mentions parts not built at {$state}: " . implode(', ', $extra), "/image_prompt/{$i}/text");
                    }
                }
            }
        }

        return new ValidationReport($v);
    }

    /** @return array<int,string> */
    public function allowedStatuses(string $shotState, string $from, ?string $until): array
    {
        if ($this->states->compare($shotState, $from) < 0) {
            return ['not_yet_built'];
        }
        if ($until !== null && $this->states->compare($shotState, $until) > 0) {
            return ['not_applicable'];
        }

        return ['must_show', 'may_be_hidden'];
    }
}
