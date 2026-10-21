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

        return $this->resolve($categoryId, $action, (string) $project->id, $context);
    }

    public function forCategory(string $categoryId, string $action, ?TaskContext $context = null): ResolvedPhase
    {
        return $this->resolve($categoryId, $action, $context?->projectId, $context);
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

    private function resolve(string $categoryId, string $actionKey, ?string $projectId, ?TaskContext $context): ResolvedPhase
    {
        $category = Category::query()->find($categoryId) ?? throw new RuntimeException("Category {$categoryId} does not exist.");
        $workflow = $this->contracts['categories'][$category->slug]
            ?? throw new RuntimeException("Category {$category->slug} has no configured workflow.");
        $action = $this->contracts['workflows'][$workflow]['actions'][$actionKey]
            ?? throw new RuntimeException("Action {$actionKey} is not configured for workflow {$workflow}.");
        $action = ActionContract::normalise($actionKey, (array) $action);
        $phase = $this->current($category->id, $workflow, $action['phase']);
        $instructions = array_map(fn (string $code): AiPhase => $this->current($category->id, $workflow, $code), $action['instructions']);

        return $this->build($category, $workflow, $actionKey, $action, $phase, $instructions, $projectId, $context);
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
