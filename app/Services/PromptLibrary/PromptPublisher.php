<?php

namespace App\Services\PromptLibrary;

use App\Models\PromptLibrary\PromptBinding;
use App\Models\PromptLibrary\PromptBindingSection;
use App\Models\PromptLibrary\PromptDocument;
use App\Models\PromptLibrary\PromptRevision;
use App\Models\PromptLibrary\PromptScope;
use App\Models\PromptLibrary\PromptScopeBinding;
use App\Models\PromptLibrary\PromptSection;
use Illuminate\Support\Facades\DB;

/**
 * Ba thao tac doi trang thai cua thu vien, moi cai trong mot transaction:
 *   activateRevision  draft/retired -> published va tro active_revision_id vao no
 *                     (rollback = activate lai revision cu)
 *   publishBinding    draft -> published, sau khi kiem section, slot, merge mode
 *   attach            gan binding vao scope; binding khac cung consumer trong
 *                     scope do bi tat, de moi scope chi co mot binding active
 *
 * Binding_sections tro bang section_key (chuoi) vi binding dung qua nhieu
 * revision; toan ven duoc giu bang kiem tra o day va khi resolve.
 */
final class PromptPublisher
{
    public function activateRevision(PromptRevision $revision): PromptRevision
    {
        return DB::transaction(function () use ($revision): PromptRevision {
            $document = PromptDocument::query()->lockForUpdate()->findOrFail($revision->document_id);
            $revision = PromptRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $this->assertBindingsSatisfied($document, $revision);

            if ($revision->status !== PromptRevision::PUBLISHED) {
                $revision->status = PromptRevision::PUBLISHED;
                $revision->published_at ??= now();
                $revision->save();
            }

            $document->active_revision_id = $revision->id;
            $document->save();

            return $revision;
        });
    }

    public function publishBinding(PromptBinding $binding): PromptBinding
    {
        return DB::transaction(function () use ($binding): PromptBinding {
            $binding = PromptBinding::query()->lockForUpdate()->findOrFail($binding->id);

            if ($binding->status !== PromptBinding::DRAFT) {
                throw new PromptLibraryException("Binding {$binding->consumer_key} v{$binding->version} khong con o draft.");
            }

            $rows = $binding->sections()->get();

            if ($rows->isEmpty()) {
                throw new PromptLibraryException('Binding khong co section nao.');
            }

            $slots = (array) config('prompt_library.slots');

            foreach ($rows as $row) {
                /** @var PromptBindingSection $row */
                if (! in_array($row->slot_key, $slots, true)) {
                    throw new PromptLibraryException("Slot khong co trong prompt_library.slots: {$row->slot_key}");
                }

                $revision = PromptDocument::query()->find($row->document_id)?->activeRevision;

                if ($revision === null) {
                    throw new PromptLibraryException("Document cua section {$row->section_key} chua co revision active.");
                }

                if ($row->required && ! $this->hasSection($revision, $row->section_key)) {
                    throw new PromptLibraryException("Revision {$revision->version} thieu section bat buoc {$row->section_key}.");
                }
            }

            $binding->status = PromptBinding::PUBLISHED;
            $binding->compiler_version = (string) config('prompt_library.compiler_version');
            $binding->published_at = now();
            $binding->save();

            return $binding;
        });
    }

    /**
     * Tao version binding moi cho consumer; tru khi $draftOnly, publish va gan
     * vao scope luon. Tat ca trong MOT transaction: loi o buoc nao cung khong
     * de lai draft mo coi.
     *
     * @param  list<array{document_id: string, section_key: string, slot_key: string, merge_mode: string, required: bool, allow_override: bool}>  $rows
     */
    public function bind(
        string $consumer,
        array $rows,
        string $scopeType = 'global',
        string $scopeKey = 'default',
        int $priority = 0,
        ?string $description = null,
        bool $draftOnly = false,
    ): PromptBinding {
        if ($rows === []) {
            throw new PromptLibraryException('Binding can it nhat mot section.');
        }

        return DB::transaction(function () use ($consumer, $rows, $scopeType, $scopeKey, $priority, $description, $draftOnly): PromptBinding {
            $binding = PromptBinding::create([
                'consumer_key' => $consumer,
                'version' => ((int) PromptBinding::query()->where('consumer_key', $consumer)->lockForUpdate()->max('version')) + 1,
                'status' => PromptBinding::DRAFT,
                'compiler_version' => (string) config('prompt_library.compiler_version'),
                'description' => $description,
            ]);

            foreach (array_values($rows) as $order => $row) {
                PromptBindingSection::create($row + ['binding_id' => $binding->id, 'sort_order' => $order]);
            }

            if ($draftOnly) {
                return $binding;
            }

            $binding = $this->publishBinding($binding);
            $this->attach($binding, $scopeType, $scopeKey, $priority);

            return $binding;
        });
    }

    /**
     * Section cua binding dang active cho consumer trong mot scope, de so voi
     * cong thuc mong muon (null khi chua co).
     *
     * @return list<array{document_id: string, section_key: string, slot_key: string, merge_mode: string, required: bool, allow_override: bool}>|null
     */
    public function activeRows(string $consumer, string $scopeType = 'global', string $scopeKey = 'default'): ?array
    {
        $scope = PromptScope::query()->where('scope_type', $scopeType)->where('scope_key', $scopeKey)->first();
        $attached = $scope === null ? null : PromptScopeBinding::query()
            ->where('scope_id', $scope->id)
            ->where('is_active', true)
            ->whereHas('binding', fn ($q) => $q->where('consumer_key', $consumer)->where('status', PromptBinding::PUBLISHED))
            ->with('binding')
            ->first();

        return $attached?->binding->sections()->get()->map(fn (PromptBindingSection $s): array => [
            'document_id' => $s->document_id,
            'section_key' => $s->section_key,
            'slot_key' => $s->slot_key,
            'merge_mode' => $s->merge_mode,
            'required' => $s->required,
            'allow_override' => $s->allow_override,
        ])->values()->all();
    }

    public function attach(PromptBinding $binding, string $scopeType, string $scopeKey, int $priority = 0): PromptScopeBinding
    {
        if (! in_array($scopeType, (array) config('prompt_library.scope_order'), true)) {
            throw new PromptLibraryException("Loai scope khong hop le: {$scopeType}");
        }

        if ($binding->status !== PromptBinding::PUBLISHED) {
            throw new PromptLibraryException('Chi gan duoc binding da publish.');
        }

        return DB::transaction(function () use ($binding, $scopeType, $scopeKey, $priority): PromptScopeBinding {
            $scope = PromptScope::query()->firstOrCreate(['scope_type' => $scopeType, 'scope_key' => $scopeKey]);

            PromptScopeBinding::query()
                ->where('scope_id', $scope->id)
                ->where('binding_id', '!=', $binding->id)
                ->where('is_active', true)
                ->whereHas('binding', fn ($q) => $q->where('consumer_key', $binding->consumer_key))
                ->update(['is_active' => false]);

            return PromptScopeBinding::query()->updateOrCreate(
                ['scope_id' => $scope->id, 'binding_id' => $binding->id],
                ['priority' => $priority, 'is_active' => true],
            );
        });
    }

    private function assertBindingsSatisfied(PromptDocument $document, PromptRevision $revision): void
    {
        $required = PromptBindingSection::query()
            ->where('document_id', $document->id)
            ->where('required', true)
            ->whereHas('binding', fn ($q) => $q->where('status', PromptBinding::PUBLISHED))
            ->pluck('section_key')
            ->unique();

        $missing = $required->reject(fn (string $key): bool => $this->hasSection($revision, $key))->values()->all();

        if ($missing !== []) {
            throw new PromptLibraryException(
                "Revision {$revision->version} thieu section ma binding dang dung can: ".implode(', ', $missing)
            );
        }
    }

    private function hasSection(PromptRevision $revision, string $key): bool
    {
        return PromptSection::query()->where('revision_id', $revision->id)->where('section_key', $key)->exists();
    }
}
