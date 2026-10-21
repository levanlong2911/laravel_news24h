<?php

namespace App\Services\PromptLibrary;

use App\Models\PromptLibrary\PromptBinding;
use App\Models\PromptLibrary\PromptBindingSection;
use App\Models\PromptLibrary\PromptDocument;
use App\Models\PromptLibrary\PromptRevision;
use App\Models\PromptLibrary\PromptScope;
use App\Models\PromptLibrary\PromptScopeBinding;
use App\Models\PromptLibrary\PromptSection;

/**
 * Tim binding cua mot consumer theo cac scope ap dung, chot revision dang
 * active cua tung document MOT lan, ghep section qua PromptCompiler va chen bien.
 *
 * Moi scope chi duoc co toi da mot binding active cho mot consumer; hai cai la
 * mo ho va bi tu choi. Scope global:default luon ap dung.
 */
final class PromptResolver
{
    public function __construct(
        private readonly PromptVariableResolver $variables,
    ) {}

    /**
     * @param  array<string, string|null>  $context  scope_type => scope_key, vi du ['workflow' => 'design_first_v1', 'project' => '...']
     * @param  array<string, scalar|null>  $variables
     */
    public function resolve(string $consumerKey, array $context = [], array $variables = []): ResolvedPrompt
    {
        $order = array_flip((array) config('prompt_library.scope_order'));
        $layers = $this->layers($consumerKey, ['global' => 'default'] + $context, $order);
        $revisions = [];
        $entries = [];

        foreach ($layers as $layer) {
            /** @var PromptBinding $binding */
            $binding = $layer['binding'];

            foreach ($binding->sections()->get() as $row) {
                /** @var PromptBindingSection $row */
                $revision = $revisions[$row->document_id] ??= $this->activeRevision($row->document_id);
                $section = PromptSection::query()
                    ->where('revision_id', $revision->id)
                    ->where('section_key', $row->section_key)
                    ->first();

                if ($section === null) {
                    if ($row->required) {
                        throw new PromptLibraryException(
                            "Binding {$consumerKey} v{$binding->version}: revision {$revision->version} thieu section bat buoc {$row->section_key}."
                        );
                    }

                    continue;
                }

                $entries[] = [
                    'layer' => $order[$layer['scope_type']],
                    'slot' => $row->slot_key,
                    'mode' => $row->merge_mode,
                    'allow_override' => $row->allow_override,
                    'body' => $section->body,
                    'ref' => [
                        'binding_id' => $binding->id,
                        'document_key' => $revision->document->fullKey(),
                        'revision_id' => $revision->id,
                        'section_key' => $section->section_key,
                        'body_sha256' => $section->body_sha256,
                        'slot' => $row->slot_key,
                        'layer' => $layer['scope_type'],
                        'merge_mode' => $row->merge_mode,
                    ],
                ];
            }
        }

        [$template, $manifest] = PromptCompiler::fromConfig()->compile($entries);
        $rendered = $this->variables->render($template, $variables);
        $sections = [];

        foreach ($manifest as $ref) {
            foreach ($entries as $entry) {
                if ($entry['ref'] === $ref) {
                    $sections[$ref['section_key']] = array_key_exists($ref['section_key'], $sections)
                        ? null
                        : $this->variables->render($entry['body'], $variables);
                    break;
                }
            }
        }

        return new ResolvedPrompt(
            consumerKey: $consumerKey,
            content: $rendered,
            compositionHash: hash('sha256', $template),
            renderedHash: hash('sha256', $rendered),
            compilerVersion: (string) config('prompt_library.compiler_version'),
            bindings: array_map(fn (array $l): array => [
                'binding_id' => $l['binding']->id,
                'consumer_key' => $consumerKey,
                'version' => $l['binding']->version,
                'scope_type' => $l['scope_type'],
                'scope_key' => $l['scope_key'],
            ], $layers),
            revisions: array_values(array_map(fn (PromptRevision $r): array => [
                'document_id' => $r->document_id,
                'document_key' => $r->document->fullKey(),
                'revision_id' => $r->id,
                'version' => $r->version,
                'source_sha256' => $r->source_sha256,
            ], $revisions)),
            manifest: $manifest,
            sections: $sections,
        );
    }

    /**
     * @param  array<string, string|null>  $context
     * @param  array<string, int>  $order
     * @return list<array{binding: PromptBinding, scope_type: string, scope_key: string, priority: int}>
     */
    private function layers(string $consumerKey, array $context, array $order): array
    {
        $layers = [];

        foreach ($context as $type => $key) {
            if ($key === null || $key === '') {
                continue;
            }

            if (! isset($order[$type])) {
                throw new PromptLibraryException("Loai scope khong co trong prompt_library.scope_order: {$type}");
            }

            $scope = PromptScope::query()->where('scope_type', $type)->where('scope_key', $key)->first();

            if ($scope === null) {
                continue;
            }

            $attached = PromptScopeBinding::query()
                ->where('scope_id', $scope->id)
                ->where('is_active', true)
                ->whereHas('binding', fn ($q) => $q->where('consumer_key', $consumerKey)->where('status', PromptBinding::PUBLISHED))
                ->with('binding')
                ->get();

            if ($attached->count() > 1) {
                throw new PromptLibraryException("Scope {$type}:{$key} co nhieu binding active cho {$consumerKey}.");
            }

            if ($attached->isNotEmpty()) {
                $layers[] = [
                    'binding' => $attached->first()->binding,
                    'scope_type' => $type,
                    'scope_key' => (string) $key,
                    'priority' => $attached->first()->priority,
                ];
            }
        }

        if ($layers === []) {
            throw new PromptLibraryException("Khong co binding published nao cho {$consumerKey}.");
        }

        usort($layers, fn (array $a, array $b): int => [$order[$a['scope_type']], $a['priority']] <=> [$order[$b['scope_type']], $b['priority']]);

        return $layers;
    }

    private function activeRevision(string $documentId): PromptRevision
    {
        $document = PromptDocument::query()->with('activeRevision.document')->find($documentId);
        $revision = $document?->activeRevision;

        if ($revision === null || $revision->status !== PromptRevision::PUBLISHED) {
            throw new PromptLibraryException('Document '.($document?->fullKey() ?? $documentId).' chua co revision published dang active.');
        }

        return $revision;
    }
}
