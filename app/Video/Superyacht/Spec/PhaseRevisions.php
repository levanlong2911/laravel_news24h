<?php

namespace App\Video\Superyacht\Spec;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\AiPhaseSchema;
use App\Models\Category;
use App\Video\Superyacht\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PhaseRevisions
{
    private const LOCK_TIMEOUT_SECONDS = 10;

    /** @param array<string, mixed> $contracts video.superyacht.contracts */
    public function __construct(private readonly array $contracts) {}

    /** @param array<string, mixed> $config video.superyacht */
    public static function fromConfig(array $config): self
    {
        return new self((array) ($config['contracts'] ?? []));
    }

    /**
     * @param  list<array{role: string, slot_key: string, schema_id: string, data_pointer: string, validation_mode: string, is_required: bool}>|null  $links  null keeps the links of $base
     */
    public function saveDraft(AiPhase $base, ?string $content, ?array $links, string $note, string $by): AiPhase
    {
        return $this->locked($base, function () use ($base, $content, $links, $note, $by): AiPhase {
            return DB::transaction(function () use ($base, $content, $links, $note, $by): AiPhase {
                $links ??= $base->schemaLinks()->get()->map(static fn (AiPhaseSchema $link): array => [
                    'role' => $link->role,
                    'slot_key' => $link->slot_key,
                    'schema_id' => $link->schema_id,
                    'data_pointer' => $link->data_pointer,
                    'validation_mode' => $link->validation_mode,
                    'is_required' => $link->is_required,
                ])->all();
                $body = $content ?? $base->prompt_content;
                $revision = 1 + (int) AiPhase::query()
                    ->where('category_id', $base->category_id)
                    ->where('workflow_code', $base->workflow_code)
                    ->where('phase_code', $base->phase_code)
                    ->max('revision');

                $draft = AiPhase::query()->create([
                    'category_id' => $base->category_id,
                    'workflow_code' => $base->workflow_code,
                    'phase_code' => $base->phase_code,
                    'revision' => $revision,
                    'kind' => $base->kind,
                    'name' => $base->name,
                    'sort_order' => $base->sort_order,
                    'prompt_content' => $body,
                    'content_sha256' => hash('sha256', $body),
                    'needs' => $base->needs,
                    'parallel_with' => $base->parallel_with,
                    'stop' => $base->stop,
                    'status' => AiPhase::DRAFT,
                    'is_current' => false,
                    'based_on_id' => $base->id,
                    'source_file' => $body === $base->prompt_content ? $base->source_file : null,
                    'source_section_key' => $body === $base->prompt_content ? $base->source_section_key : null,
                    'change_note' => $note,
                    'created_by' => $by,
                ]);

                foreach ($links as $link) {
                    $this->assertLinkShape($link);
                    AiPhaseSchema::query()->create(['phase_id' => $draft->id] + $link);
                }

                return $draft->refresh();
            });
        });
    }

    public function publish(AiPhase $draft, ?string $expectedCurrentId): AiPhase
    {
        return $this->locked($draft, function () use ($draft, $expectedCurrentId): AiPhase {
            return DB::transaction(function () use ($draft, $expectedCurrentId): AiPhase {
                $draft = AiPhase::query()->lockForUpdate()->findOrFail($draft->id);

                if ($draft->status !== AiPhase::DRAFT) {
                    throw new RuntimeException("Revision {$draft->revision} of {$draft->phase_code} is {$draft->status}; only a draft is published.");
                }

                $current = AiPhase::query()
                    ->where('category_id', $draft->category_id)
                    ->where('workflow_code', $draft->workflow_code)
                    ->where('phase_code', $draft->phase_code)
                    ->where('is_current', true)
                    ->lockForUpdate()
                    ->first();

                if ($current?->id !== $expectedCurrentId) {
                    throw new RuntimeException("Conflict: the current revision of {$draft->phase_code} is "
                        .($current === null ? 'none' : "{$current->revision} ({$current->id})")
                        .', not the one this draft was prepared against. Nothing was published.');
                }

                $this->assertPublishable($draft);

                if ($current !== null) {
                    $current->is_current = false;
                    $current->save();
                }

                $draft->status = AiPhase::PUBLISHED;
                $draft->is_current = true;
                $draft->published_at = now();
                $draft->save();

                return $draft->refresh();
            });
        });
    }

    public function restore(AiPhase $old, ?string $expectedCurrentId, string $note, string $by): AiPhase
    {
        return $this->publish($this->saveDraft($old, null, null, $note, $by), $expectedCurrentId);
    }

    /**
     * @return array{
     *     category: array{id: string, slug: string},
     *     workflow: string,
     *     changes: list<array{phase_code: string, current_id: string, current_revision: int, next_revision: int, added: list<string>, removed: list<string>, changed: list<string>, links: list<array<string, mixed>>}>,
     *     plan_hash: string
     * }
     */
    public function linkPlan(string $categorySlug): array
    {
        $categoryIds = Category::query()->where('slug', $categorySlug)->pluck('id');

        if ($categoryIds->count() !== 1) {
            throw new RuntimeException("Category slug {$categorySlug} matches {$categoryIds->count()} rows instead of exactly one.");
        }

        $categoryId = (string) $categoryIds->first();
        $workflow = $this->contracts['categories'][$categorySlug]
            ?? throw new RuntimeException("Category {$categorySlug} has no configured workflow.");
        $desired = [];
        $labels = [];

        foreach (ActionContract::desiredLinks((array) ($this->contracts['workflows'][$workflow] ?? [])) as $index => $row) {
            $absent = array_diff(['phase', 'role', 'slot', 'namespace', 'schema', 'version', 'pointer', 'mode', 'required', 'source'], array_keys((array) $row));

            if ($absent !== []) {
                throw new RuntimeException("links[{$index}] is missing ".implode(', ', $absent).'.');
            }

            $schema = AiJsonSchema::query()
                ->where('namespace', $row['namespace'])
                ->where('schema_key', $row['schema'])
                ->where('version', $row['version'])
                ->first();

            if ($schema === null || $schema->status !== AiJsonSchema::PUBLISHED) {
                throw new RuntimeException("links[{$index}] names {$row['namespace']}/{$row['schema']}@{$row['version']}, which is not a published schema.");
            }

            $link = [
                'role' => $row['role'],
                'slot_key' => $row['slot'],
                'schema_id' => $schema->id,
                'data_pointer' => $row['pointer'],
                'validation_mode' => $row['mode'],
                'is_required' => $row['required'],
            ];
            $this->assertLinkShape($link);
            $key = $row['role'].'|'.$row['slot'];

            if (isset($desired[$row['phase']][$key])) {
                throw new RuntimeException("links[{$index}] repeats {$row['phase']} {$key}.");
            }

            $desired[$row['phase']][$key] = $link;
            $labels[$schema->id] = "{$schema->namespace}/{$schema->schema_key}@{$schema->version}";
        }

        $currents = AiPhase::query()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflow)
            ->where('is_current', true)
            ->with('schemaLinks.schema')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('phase_code');
        $unknown = array_diff(array_keys($desired), $currents->keys()->all());

        if ($unknown !== []) {
            throw new RuntimeException('Links name phases without a current revision: '.implode(', ', $unknown).'.');
        }

        $describe = static function (array $link) use (&$labels): string {
            return "{$link['role']}/{$link['slot_key']} -> ".($labels[$link['schema_id']] ?? $link['schema_id'])
                .' '.($link['data_pointer'] === '' ? '(whole record)' : $link['data_pointer'])
                ." {$link['validation_mode']}".($link['is_required'] ? ' required' : ' optional');
        };
        $changes = [];

        foreach ($currents as $code => $current) {
            $have = [];

            foreach ($current->schemaLinks as $link) {
                $labels[$link->schema_id] ??= $link->schema === null ? $link->schema_id : "{$link->schema->namespace}/{$link->schema->schema_key}@{$link->schema->version}";
                $have[$link->role.'|'.$link->slot_key] = $link->only(['role', 'slot_key', 'schema_id', 'data_pointer', 'validation_mode', 'is_required']);
            }

            $want = $desired[$code] ?? [];
            ksort($want);
            $added = array_values(array_map($describe, array_diff_key($want, $have)));
            $removed = array_values(array_map($describe, array_diff_key($have, $want)));
            $changed = [];

            foreach (array_intersect_key($want, $have) as $key => $link) {
                if ($link != $have[$key]) {
                    $changed[] = $describe($have[$key]).' => '.$describe($link);
                }
            }

            if ($added === [] && $removed === [] && $changed === []) {
                continue;
            }

            $changes[] = [
                'phase_code' => $code,
                'current_id' => $current->id,
                'current_revision' => $current->revision,
                'next_revision' => 1 + (int) AiPhase::query()
                    ->where('category_id', $categoryId)
                    ->where('workflow_code', $workflow)
                    ->where('phase_code', $code)
                    ->max('revision'),
                'added' => $added,
                'removed' => $removed,
                'changed' => $changed,
                'links' => array_values($want),
            ];
        }

        $plan = ['category' => ['id' => $categoryId, 'slug' => $categorySlug], 'workflow' => $workflow, 'changes' => $changes];
        $plan['plan_hash'] = CanonicalJson::hash($plan);

        return $plan;
    }

    /** @return list<array{phase_code: string, revision: int, id: string}> */
    public function applyLinkPlan(string $categorySlug, string $expectedPlanHash, string $by): array
    {
        $plan = $this->linkPlan($categorySlug);

        if (! hash_equals($plan['plan_hash'], strtolower(trim($expectedPlanHash)))) {
            throw new RuntimeException("The link plan is now {$plan['plan_hash']}, not the reviewed {$expectedPlanHash}. Nothing was written.");
        }

        return DB::transaction(function () use ($plan, $by): array {
            $written = [];

            foreach ($plan['changes'] as $change) {
                $current = AiPhase::query()->findOrFail($change['current_id']);
                $draft = $this->saveDraft($current, null, $change['links'], "Liên kết schema theo cấu hình đã duyệt (plan {$plan['plan_hash']}); prompt giữ nguyên.", $by);
                $published = $this->publish($draft, $current->id);
                $written[] = ['phase_code' => $published->phase_code, 'revision' => $published->revision, 'id' => $published->id];
            }

            return $written;
        });
    }

    /**
     * @param  callable(): AiPhase  $work
     */
    private function locked(AiPhase $phase, callable $work): AiPhase
    {
        $lock = 'ai_phase:'.sha1($phase->category_id.'|'.$phase->workflow_code.'|'.$phase->phase_code);

        if ((int) DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$lock, self::LOCK_TIMEOUT_SECONDS])->acquired !== 1) {
            throw new RuntimeException("Another change of {$phase->phase_code} holds the lock.");
        }

        try {
            return $work();
        } finally {
            DB::select('SELECT RELEASE_LOCK(?) AS released', [$lock]);
        }
    }

    /** @param array<string, mixed> $link */
    private function assertLinkShape(array $link): void
    {
        if (! in_array($link['role'] ?? null, AiPhaseSchema::ROLES, true)
            || ! in_array($link['validation_mode'] ?? null, AiPhaseSchema::MODES, true)
            || ! is_string($link['slot_key'] ?? null) || $link['slot_key'] === '' || strlen($link['slot_key']) > 64
            || ! is_string($link['schema_id'] ?? null)
            || ! is_bool($link['is_required'] ?? null)
            || ! AiPhaseSchema::isPointer($link['data_pointer'] ?? null)) {
            throw new RuntimeException('A link needs a known role and mode, a slot, a schema id, a required flag and a confirmed JSON Pointer.');
        }
    }

    private function assertPublishable(AiPhase $draft): void
    {
        $links = $draft->schemaLinks()->with('schema')->get();

        foreach ($links as $link) {
            $this->assertLinkShape($link->only(['role', 'slot_key', 'schema_id', 'data_pointer', 'validation_mode', 'is_required']));

            if ($link->schema === null || $link->schema->status !== AiJsonSchema::PUBLISHED) {
                throw new RuntimeException("Link {$link->role}/{$link->slot_key} points to a schema that is not published.");
            }

            $this->assertDependenciesPublished($link->schema);
        }

        $described = $links->map(static fn (AiPhaseSchema $link): array => self::describe($link))->all();

        foreach ((array) ($this->contracts['workflows'][$draft->workflow_code]['actions'] ?? []) as $key => $config) {
            if (($config['phase'] ?? null) !== $draft->phase_code) {
                continue;
            }

            $mismatches = ActionContract::mismatches(ActionContract::normalise((string) $key, (array) $config), $described);

            if ($mismatches !== []) {
                throw new RuntimeException("Action {$key} does not accept {$draft->phase_code}: ".implode('; ', $mismatches).'. Nothing was published.');
            }
        }
    }

    /** @return array{role: string, slot: string, namespace: ?string, schema: ?string, version: ?string, data_pointer: string, validation_mode: string, is_required: bool} */
    public static function describe(AiPhaseSchema $link): array
    {
        return [
            'role' => $link->role,
            'slot' => $link->slot_key,
            'namespace' => $link->schema?->namespace,
            'schema' => $link->schema?->schema_key,
            'version' => $link->schema?->version,
            'data_pointer' => (string) $link->data_pointer,
            'validation_mode' => $link->validation_mode,
            'is_required' => $link->is_required,
        ];
    }

    private function assertDependenciesPublished(AiJsonSchema $schema): void
    {
        $seen = [$schema->id => true];
        $queue = array_values((array) $schema->dependencies);

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $dependency = AiJsonSchema::query()->find($id);

            if ($dependency === null || $dependency->status !== AiJsonSchema::PUBLISHED) {
                throw new RuntimeException("Schema {$schema->schema_key} depends on {$id}, which is missing or not published.");
            }

            array_push($queue, ...array_values((array) $dependency->dependencies));
        }
    }
}
