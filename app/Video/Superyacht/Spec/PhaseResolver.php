<?php

namespace App\Video\Superyacht\Spec;

use App\Models\AiJsonSchema;
use App\Models\AiPhase;
use App\Models\Category;
use App\Models\VideoProject;
use App\Repositories\Interfaces\AiJsonSchemaRepositoryInterface;
use App\Repositories\Interfaces\AiPhaseRepositoryInterface;
use App\Repositories\Interfaces\CategoryRepositoryInterface;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use App\Video\Superyacht\Support\CanonicalJson;
use RuntimeException;

final class PhaseResolver
{
    /** @param array<string, mixed>|\Closure(): array<string, mixed> $contracts video.superyacht.contracts, or what reads them each time the resolver is asked */
    public function __construct(
        private readonly array|\Closure $contracts,
        private readonly AiPhaseRepositoryInterface $phases,
        private readonly AiJsonSchemaRepositoryInterface $schemas,
        private readonly CategoryRepositoryInterface $categories,
        private readonly VideoProjectRepositoryInterface $projects,
    ) {}

    /** @param array<string, mixed> $config video.superyacht */
    public static function fromConfig(
        array $config,
        AiPhaseRepositoryInterface $phases,
        AiJsonSchemaRepositoryInterface $schemas,
        CategoryRepositoryInterface $categories,
        VideoProjectRepositoryInterface $projects,
    ): self {
        return new self((array) ($config['contracts'] ?? []), $phases, $schemas, $categories, $projects);
    }

    /** @return array<string, mixed> video.superyacht.contracts the resolver works with now */
    private function contracts(): array
    {
        return $this->contracts instanceof \Closure ? ($this->contracts)() : $this->contracts;
    }

    /**
     * @param  string|VideoProject  $project  the id of the project, or the project a caller already read: its article and the category of that article are then read from it, loaded or not, and never asked for again
     */
    public function forProject(string|VideoProject $project, string $action, ?TaskContext $context = null): ResolvedPhase
    {
        $projectId = $project instanceof VideoProject ? (string) $project->id : $project;
        $project = $project instanceof VideoProject ? $project : ($this->projects->findProject($project) ?? throw new RuntimeException("Project {$projectId} does not exist."));
        $article = $this->projects->findArticleOf($project) ?? throw new RuntimeException("Project {$projectId} has no article.");
        $categoryId = (string) $article->category_id;

        if ($categoryId === '') {
            throw new RuntimeException("The article of project {$projectId} has no category.");
        }

        if ($context !== null && $context->projectId !== (string) $project->id) {
            throw new RuntimeException("The task context belongs to project {$context->projectId}, not {$project->id}.");
        }

        $category = $this->categories->findCategoryOfArticle($article) ?? throw new RuntimeException("Category {$categoryId} does not exist.");

        return $this->resolve($category, $action, (string) $project->id, $context, $this->releaseOf($project, $category));
    }

    /** @param ?string $release the release of a workflow that declares releases; without one such a workflow resolves its legacy release */
    public function forCategory(string $categoryId, string $action, ?TaskContext $context = null, ?string $release = null): ResolvedPhase
    {
        return $this->resolve($this->categories->findCategory($categoryId) ?? throw new RuntimeException("Category {$categoryId} does not exist."), $action, $context?->projectId, $context, $release);
    }

    /** @return ?string the release the project pinned for a workflow that declares releases, null when it pinned none or the workflow declares none */
    private function releaseOf(VideoProject $project, Category $category): ?string
    {
        $slug = $category->slug;
        $workflow = $slug === null ? null : ($this->contracts()['categories'][$slug] ?? null);
        $key = $workflow === null ? null : ($this->contracts()['workflows'][$workflow]['release_key'] ?? null);
        $pinned = is_string($key) ? ($project->metadata_json[$key] ?? null) : null;

        return is_string($pinned) && $pinned !== '' ? $pinned : null;
    }

    /**
     * @param  ?string  $release  the release a project pinned, null for none
     * @return ?string the phase an action reads, as the workflow declares the action under that release; null when neither the release nor the workflow declares it. No row is read, so it says nothing about whether the action is ready
     *
     * @throws RuntimeException when the release is not configured
     */
    public function phaseOf(string $workflow, ?string $release, string $action): ?string
    {
        $declared = $this->release($workflow, $release)['actions'][$action] ?? $this->contracts()['workflows'][$workflow]['actions'][$action] ?? null;

        return is_array($declared) && is_string($declared['phase'] ?? null) && $declared['phase'] !== '' ? $declared['phase'] : null;
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

        $category = $this->categories->findCategory($manifest['category']['id'] ?? null)
            ?? throw new RuntimeException('The category of the manifest no longer exists.');
        $phaseId = $manifest['phase']['id'] ?? null;
        $instructionIds = array_map(static fn (array $item): mixed => $item['id'] ?? null, (array) $manifest['instructions']);
        $rows = $this->phases->findPhasesByIds(array_values(array_unique(array_filter([$phaseId, ...$instructionIds], 'is_string'))))->keyBy('id');
        $phase = (is_string($phaseId) ? $rows->get($phaseId) : null)
            ?? throw new RuntimeException('The phase revision of the manifest no longer exists.');
        $instructions = array_map(
            static fn (mixed $id): AiPhase => (is_string($id) ? $rows->get($id) : null)
                ?? throw new RuntimeException('An instruction revision of the manifest no longer exists.'),
            $instructionIds,
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

    private function resolve(Category $category, string $actionKey, ?string $projectId, ?TaskContext $context, ?string $release = null): ResolvedPhase
    {
        $workflow = $this->contracts()['categories'][$category->slug]
            ?? throw new RuntimeException("Category {$category->slug} has no configured workflow.");
        $contract = $this->release($workflow, $release);
        $action = $contract['actions'][$actionKey]
            ?? $this->contracts()['workflows'][$workflow]['actions'][$actionKey]
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
        $declared = (array) ($this->contracts()['workflows'][$workflow]['releases'] ?? []);

        if ($declared === []) {
            return ['phases' => [], 'actions' => []];
        }

        $code = $release ?? (string) ($this->contracts()['workflows'][$workflow]['legacy_release'] ?? '');
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
        $rows = $this->phases->findRevisionsOfPhase($categoryId, $workflow, $code);

        return self::choose($rows, $range) ?? throw new RuntimeException(isset($range['revisions'])
            ? "{$code} of {$workflow} has no published revision of its release catalogue (id, hash and input format ".json_encode($range['input_format'] ?? null).') for this category: '.self::catalogueFault($rows, $range).'.'
            : "{$code} of {$workflow} has no published revision from ".(int) ($range['from'] ?? 1).(isset($range['to']) ? ' to '.(int) $range['to'] : '').' for this category.');
    }

    /**
     * @param  list<AiPhase>  $rows  every revision of the phase stored for the category
     * @param  array<string, mixed>  $rule  a catalogue of revisions that chose none of them
     * @return string why: no listed revision is stored at all, which is release data that was never seeded, or, for each one that is stored, what keeps it out — not published, a text whose hash is not the listed one, or a text that does not declare the input format
     */
    private static function catalogueFault(array $rows, array $rule): string
    {
        $listed = array_column((array) $rule['revisions'], 'sha256', 'id');
        $marker = is_string($rule['input_format'] ?? null) ? 'Input format: `'.$rule['input_format'].'`' : null;
        $stored = array_values(array_filter($rows, static fn (AiPhase $row): bool => isset($listed[(string) $row->id])));

        if ($stored === []) {
            return 'none of the '.count($listed).' listed revisions is stored, the release data was not seeded';
        }

        return implode('; ', array_map(static fn (AiPhase $row): string => "revision {$row->revision} is stored but ".match (true) {
            $row->status !== AiPhase::PUBLISHED => 'not published',
            ! hash_equals((string) $listed[(string) $row->id], (string) $row->content_sha256) => 'its text hash is not the one the catalogue lists',
            $marker !== null && ! str_contains((string) $row->prompt_content, $marker) => 'its text does not declare '.$marker,
            default => 'was not chosen',
        }, $stored));
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
        $rows = $this->phases->findCurrentRevisions($categoryId, $workflow, $code);

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

        $links = $this->phases->findSchemaLinksWithSchemas($phase)
            ->sortBy(static fn ($link): string => $link->role.'|'.$link->slot_key)
            ->values();
        $schemas = [];
        $read = [];

        foreach ($links as $link) {
            if ($link->schema !== null) {
                $read[$link->schema_id] = $link->schema;
            }
        }

        $queue = $links->pluck('schema_id')->all();

        while ($queue !== []) {
            $unread = array_values(array_unique(array_filter($queue, static fn (mixed $id): bool => ! isset($read[$id]))));

            foreach ($unread === [] ? [] : $this->schemas->findSchemasByIds($unread) as $row) {
                $read[$row->id] = $row;
            }

            $next = [];

            foreach ($queue as $id) {
                if (isset($schemas[$id])) {
                    continue;
                }

                $schema = $read[$id] ?? throw new RuntimeException("Schema {$id} is missing.");

                if ($schema->status !== AiJsonSchema::PUBLISHED) {
                    throw new RuntimeException("Schema {$schema->namespace}/{$schema->schema_key}@{$schema->version} is not published.");
                }

                if (! hash_equals($schema->schema_sha256, hash('sha256', $schema->schema_content))) {
                    throw new RuntimeException("Schema {$schema->namespace}/{$schema->schema_key}@{$schema->version} does not match its hash.");
                }

                $schemas[$id] = $schema;
                array_push($next, ...array_values((array) $schema->dependencies));
            }

            $queue = $next;
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
            $phase,
        );
    }
}
