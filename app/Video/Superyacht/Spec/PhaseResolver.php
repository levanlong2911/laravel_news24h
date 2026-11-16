<?php

namespace App\Video\Superyacht\Spec;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\Category;
use App\Models\VideoProject;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class PhaseResolver
{
    /** @param array<string, mixed> $contracts video.superyacht.contracts */
    public function __construct(private readonly array $contracts) {}

    /** @param array<string, mixed> $config video.superyacht */
    public static function fromConfig(array $config): self
    {
        return new self((array) ($config['contracts'] ?? []));
    }

    public function forProject(string $projectId, string $action, ?TaskContext $context = null): ResolvedPhase
    {
        $project = VideoProject::query()->find($projectId) ?? throw new RuntimeException("Project {$projectId} does not exist.");
        $article = $project->article ?? throw new RuntimeException("Project {$projectId} has no article.");
        $categoryId = (string) $article->category_id;

        if ($categoryId === '') {
            throw new RuntimeException("The article of project {$projectId} has no category.");
        }

        if ($context !== null && $context->projectId !== (string) $project->id) {
            throw new RuntimeException("The task context belongs to project {$context->projectId}, not {$project->id}.");
        }

        return $this->resolve($categoryId, $action, (string) $project->id, $context, $this->releaseOf($project, $categoryId));
    }

    /** @param ?string $release the release of a workflow that declares releases; without one such a workflow resolves its legacy release */
    public function forCategory(string $categoryId, string $action, ?TaskContext $context = null, ?string $release = null): ResolvedPhase
    {
        return $this->resolve($categoryId, $action, $context?->projectId, $context, $release);
    }

    /** @return ?string the release the project pinned for a workflow that declares releases, null when it pinned none or the workflow declares none */
    private function releaseOf(VideoProject $project, string $categoryId): ?string
    {
        $slug = Category::query()->find($categoryId)?->slug;
        $workflow = $slug === null ? null : ($this->contracts['categories'][$slug] ?? null);
        $key = $workflow === null ? null : ($this->contracts['workflows'][$workflow]['release_key'] ?? null);
        $pinned = is_string($key) ? ($project->metadata_json[$key] ?? null) : null;

        return is_string($pinned) && $pinned !== '' ? $pinned : null;
    }

    /** @param array<string, mixed> $manifest */
    public function fromManifest(array $manifest): ResolvedPhase
    {
        foreach ([
            'manifest_version' => ResolvedPhase::MANIFEST_VERSION,
            'compiler_version' => ResolvedPhase::COMPILER_VERSION,
            'envelope_version' => EnvelopeValidator::VERSION,
        ] as $key => $expected) {
            if (($manifest[$key] ?? null) !== $expected) {
                throw new RuntimeException("The manifest was built with {$key} ".json_encode($manifest[$key] ?? null)."; this code replays only {$expected}.");
            }
        }

        if (! is_string($manifest['bundle_hash'] ?? null) || ! hash_equals($manifest['bundle_hash'], CanonicalJson::hash($manifest, ['bundle_hash']))) {
            throw new RuntimeException('The manifest does not match its own bundle_hash.');
        }

        $category = Category::query()->find($manifest['category']['id'] ?? null)
            ?? throw new RuntimeException('The category of the manifest no longer exists.');
        $phase = AiPhase::query()->find($manifest['phase']['id'] ?? null)
            ?? throw new RuntimeException('The phase revision of the manifest no longer exists.');
        $instructions = array_map(
            static fn (array $item): AiPhase => AiPhase::query()->find($item['id'] ?? null)
                ?? throw new RuntimeException('An instruction revision of the manifest no longer exists.'),
            (array) $manifest['instructions'],
        );
        $action = (array) $manifest['action'];
        $key = (string) ($action['key'] ?? '');
        unset($action['key']);
        $context = isset($manifest['context']) ? TaskContext::fromArray((array) $manifest['context']) : null;

        $bundle = $this->build($category, (string) $manifest['workflow'], $key, ActionContract::normalise($key, $action), $phase, $instructions, $manifest['project_id'] ?? null, $context);

        if (! hash_equals($manifest['bundle_hash'], $bundle->bundleHash())) {
            throw new RuntimeException('Replaying the manifest gives another bundle: a stored row no longer matches it.');
        }

        return $bundle;
    }

    private function resolve(string $categoryId, string $actionKey, ?string $projectId, ?TaskContext $context, ?string $release = null): ResolvedPhase
    {
        $category = Category::query()->find($categoryId) ?? throw new RuntimeException("Category {$categoryId} does not exist.");
        $workflow = $this->contracts['categories'][$category->slug]
            ?? throw new RuntimeException("Category {$category->slug} has no configured workflow.");
        $contract = $this->release($workflow, $release);
        $action = $contract['actions'][$actionKey]
            ?? $this->contracts['workflows'][$workflow]['actions'][$actionKey]
            ?? throw new RuntimeException("Action {$actionKey} is not configured for workflow {$workflow}.");
        $action = ActionContract::normalise($actionKey, (array) $action);
        $pick = fn (string $code): AiPhase => isset($contract['phases'][$code])
            ? $this->within($category->id, $workflow, $code, (array) $contract['phases'][$code])
            : $this->current($category->id, $workflow, $code);
        $phase = $pick($action['phase']);
        $instructions = array_map($pick, $action['instructions']);

        return $this->build($category, $workflow, $actionKey, $action, $phase, $instructions, $projectId, $context);
    }

    /**
     * @return array{phases: array<string, array<string, mixed>>, actions: array<string, array<string, mixed>>} the revision ranges and the actions the release replaces; both empty for a workflow that declares no releases. A release that `extends` another takes that one's phases and actions and replaces, phase by phase and action by action, the ones it names itself
     *
     * @throws RuntimeException when the workflow declares releases and the named one is not among them, or a release extends itself
     */
    private function release(string $workflow, ?string $release): array
    {
        $declared = (array) ($this->contracts['workflows'][$workflow]['releases'] ?? []);

        if ($declared === []) {
            return ['phases' => [], 'actions' => []];
        }

        $code = $release ?? (string) ($this->contracts['workflows'][$workflow]['legacy_release'] ?? '');
        $contract = $declared[$code] ?? throw new RuntimeException("Release {$code} is not configured for workflow {$workflow}.");
        $base = ['phases' => [], 'actions' => []];

        if (is_string($contract['extends'] ?? null)) {
            if ($contract['extends'] === $code) {
                throw new RuntimeException("Release {$code} of workflow {$workflow} extends itself.");
            }

            $base = $this->release($workflow, $contract['extends']);
        }

        return [
            'phases' => array_replace($base['phases'], (array) ($contract['phases'] ?? [])),
            'actions' => array_replace($base['actions'], (array) ($contract['actions'] ?? [])),
        ];
    }

    /**
     * @param  array{from?: int, to?: ?int}  $range  the revisions of the phase the release was written against
     * @return AiPhase the current revision when it is inside the range, otherwise the newest published revision inside it
     *
     * @throws RuntimeException when no published revision is inside the range
     */
    private function within(string $categoryId, string $workflow, string $code, array $range): AiPhase
    {
        $rows = AiPhase::query()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflow)
            ->where('phase_code', $code)
            ->get()
            ->all();

        return self::choose($rows, $range) ?? throw new RuntimeException(isset($range['revisions'])
            ? "{$code} of {$workflow} has no published revision of its release catalogue (id, hash and input format ".json_encode($range['input_format'] ?? null).') for this category.'
            : "{$code} of {$workflow} has no published revision from ".(int) ($range['from'] ?? 1).(isset($range['to']) ? ' to '.(int) $range['to'] : '').' for this category.');
    }

    /**
     * @param  string  $workflow  the workflow of the project
     * @param  ?string  $release  the release the project pinned, null for none
     * @return ?array<string, mixed> the rule the release gives for the revisions of the phase: a catalogue of revisions or a range; null when the workflow declares no release or the release leaves the phase to the current revision
     *
     * @throws RuntimeException when the release is not configured
     */
    public function rule(string $workflow, ?string $release, string $code): ?array
    {
        $rule = $this->release($workflow, $release)['phases'][$code] ?? null;

        return is_array($rule) ? $rule : null;
    }

    /**
     * @param  list<AiPhase>  $rows  every revision of one phase of one category and workflow
     * @param  ?array<string, mixed>  $rule  rule(): `revisions` lists the revisions a release was written against, each by id and content hash, with the `input_format` their text must declare; otherwise `from` and `to` bound the revision numbers; null takes the current revision
     * @return ?AiPhase the revision the rule chooses, reading no row itself so a caller that locked the rows chooses among what it holds: under a catalogue the newest published revision the catalogue lists whose text still has its hash and declares the input format; under a range the current revision when it is inside, otherwise the newest published one inside; with no rule the one current revision; null when none fits
     */
    public static function choose(array $rows, ?array $rule): ?AiPhase
    {
        $current = array_values(array_filter($rows, static fn (AiPhase $row): bool => (bool) $row->is_current));
        $newest = static function (array $fitting): ?AiPhase {
            usort($fitting, static fn (AiPhase $a, AiPhase $b): int => $b->revision <=> $a->revision);

            return $fitting[0] ?? null;
        };

        if ($rule === null) {
            return count($current) === 1 ? $current[0] : null;
        }

        if (isset($rule['revisions'])) {
            $listed = array_column((array) $rule['revisions'], 'sha256', 'id');
            $marker = is_string($rule['input_format'] ?? null) ? 'Input format: `'.$rule['input_format'].'`' : null;

            return $newest(array_filter($rows, static fn (AiPhase $row): bool => $row->status === AiPhase::PUBLISHED
                && isset($listed[(string) $row->id])
                && hash_equals((string) $listed[(string) $row->id], (string) $row->content_sha256)
                && ($marker === null || str_contains((string) $row->prompt_content, $marker))));
        }

        $from = (int) ($rule['from'] ?? 1);
        $to = isset($rule['to']) ? (int) $rule['to'] : null;
        $inside = static fn (AiPhase $row): bool => $row->revision >= $from && ($to === null || $row->revision <= $to);

        if (count($current) === 1 && $inside($current[0])) {
            return $current[0];
        }

        return $newest(array_filter($rows, static fn (AiPhase $row): bool => $row->status === AiPhase::PUBLISHED && $inside($row)));
    }

    /** @param array{outputs: array<string, ?array<string, mixed>>} $action */
    private function assertContext(string $actionKey, array $action, ?TaskContext $context): void
    {
        $keys = array_values(array_unique(array_filter(array_map(
            static fn (?array $output): ?string => $output['items']['key'] ?? null,
            $action['outputs'],
        ))));

        if ($keys === []) {
            if ($context !== null) {
                throw new RuntimeException("Action {$actionKey} takes no task context.");
            }

            return;
        }

        if (count($keys) > 1) {
            throw new RuntimeException("Action {$actionKey} keys its outputs by ".implode(' and ', $keys).'; one task context cannot hold both.');
        }

        if ($context === null) {
            throw new RuntimeException("Action {$actionKey} processes one {$keys[0]} per task; resolve it with a task context.");
        }

        if ($context->inputKey !== $keys[0]) {
            throw new RuntimeException("The task context names {$context->inputKey}; action {$actionKey} is keyed by {$keys[0]}.");
        }
    }

    private function current(string $categoryId, string $workflow, string $code): AiPhase
    {
        $rows = AiPhase::query()
            ->where('category_id', $categoryId)
            ->where('workflow_code', $workflow)
            ->where('phase_code', $code)
            ->where('is_current', true)
            ->get();

        if ($rows->count() !== 1) {
            throw new RuntimeException("{$code} of {$workflow} has {$rows->count()} current revisions for this category; expected exactly one.");
        }

        return $rows->first();
    }

    /**
     * @param  array{phase: string, instructions: list<string>, executor: string, model_profile: ?string, contract_version: string, outputs: array<string, ?array<string, mixed>>}  $action
     * @param  list<AiPhase>  $instructions
     */
    private function build(Category $category, string $workflow, string $actionKey, array $action, AiPhase $phase, array $instructions, ?string $projectId, ?TaskContext $context): ResolvedPhase
    {
        $this->assertContext($actionKey, $action, $context);

        if ($context !== null && $context->projectId !== $projectId) {
            throw new RuntimeException("The task context belongs to project {$context->projectId}, not ".($projectId ?? 'no project').'.');
        }

        if ($phase->phase_code !== $action['phase'] || array_map(static fn (AiPhase $row): string => $row->phase_code, $instructions) !== $action['instructions']) {
            throw new RuntimeException("The revisions do not match action {$actionKey}.");
        }

        foreach ([$phase, ...$instructions] as $row) {
            if ($row->category_id !== $category->id || $row->workflow_code !== $workflow) {
                throw new RuntimeException("Revision {$row->id} belongs to another category or workflow.");
            }

            if ($row->status !== AiPhase::PUBLISHED) {
                throw new RuntimeException("Revision {$row->revision} of {$row->phase_code} is not published.");
            }

            if (! hash_equals($row->content_sha256, hash('sha256', $row->prompt_content))) {
                throw new RuntimeException("Revision {$row->revision} of {$row->phase_code} does not match its hash.");
            }
        }

        $links = $phase->schemaLinks()->with('schema')->get()
            ->sortBy(static fn ($link): string => $link->role.'|'.$link->slot_key)
            ->values();
        $schemas = [];
        $queue = $links->pluck('schema_id')->all();

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($schemas[$id])) {
                continue;
            }

            $schema = AiJsonSchema::query()->find($id) ?? throw new RuntimeException("Schema {$id} is missing.");

            if ($schema->status !== AiJsonSchema::PUBLISHED) {
                throw new RuntimeException("Schema {$schema->namespace}/{$schema->schema_key}@{$schema->version} is not published.");
            }

            if (! hash_equals($schema->schema_sha256, hash('sha256', $schema->schema_content))) {
                throw new RuntimeException("Schema {$schema->namespace}/{$schema->schema_key}@{$schema->version} does not match its hash.");
            }

            $schemas[$id] = $schema;
            array_push($queue, ...array_values((array) $schema->dependencies));
        }

        uasort($schemas, static fn (AiJsonSchema $a, AiJsonSchema $b): int => [$a->namespace, $a->schema_key, $a->version] <=> [$b->namespace, $b->schema_key, $b->version]);
        $mismatches = ActionContract::mismatches($action, $links->map(static fn ($link): array => PhaseRevisions::describe($link))->all());

        $manifest = [
            'manifest_version' => ResolvedPhase::MANIFEST_VERSION,
            'compiler_version' => ResolvedPhase::COMPILER_VERSION,
            'envelope_version' => EnvelopeValidator::VERSION,
            'project_id' => $projectId,
            'context' => $context?->toArray(),
            'category' => ['id' => $category->id, 'slug' => $category->slug],
            'workflow' => $workflow,
            'action' => ['key' => $actionKey] + $action,
            'phase' => ['id' => $phase->id, 'code' => $phase->phase_code, 'revision' => $phase->revision, 'sha256' => $phase->content_sha256],
            'instructions' => array_map(static fn (AiPhase $row, int $order): array => [
                'order' => $order,
                'id' => $row->id,
                'code' => $row->phase_code,
                'revision' => $row->revision,
                'sha256' => $row->content_sha256,
            ], $instructions, array_keys($instructions)),
            'links' => $links->map(static fn ($link): array => [
                'role' => $link->role,
                'slot' => $link->slot_key,
                'data_pointer' => $link->data_pointer,
                'validation_mode' => $link->validation_mode,
                'is_required' => $link->is_required,
                'schema_id' => $link->schema_id,
            ])->all(),
            'schemas' => array_values(array_map(static fn (AiJsonSchema $schema): array => [
                'id' => $schema->id,
                'namespace' => $schema->namespace,
                'key' => $schema->schema_key,
                'version' => $schema->version,
                'json_schema_id' => $schema->json_schema_id,
                'sha256' => $schema->schema_sha256,
                'dependencies' => (array) $schema->dependencies,
            ], $schemas)),
            'ready' => $mismatches === [],
            'contract_mismatches' => $mismatches,
        ];
        $manifest['bundle_hash'] = CanonicalJson::hash($manifest);

        $parts = array_map(static fn (AiPhase $row): array => ['code' => $row->phase_code, 'kind' => $row->kind, 'content' => $row->prompt_content], [...$instructions, $phase]);

        return new ResolvedPhase(
            $manifest,
            $parts,
            array_map(static fn (AiJsonSchema $schema): array => [
                'key' => $schema->schema_key,
                'json_schema_id' => $schema->json_schema_id,
                'content' => $schema->schema_content,
            ], $schemas),
        );
    }
}
