<?php

namespace App\Services\PromptLibrary;

/**
 * Prompt da ghep xong cho MOT lan goi, voi day du nguon goc. Tao mot lan truoc
 * khi claim stage; sau do moi thu (hash, pin, noi dung gui model) deu doc tu
 * day, khong resolve lai active_revision_id.
 */
final class ResolvedPrompt
{
    /**
     * @param  list<array{binding_id: string, consumer_key: string, version: int, scope_type: string, scope_key: string}>  $bindings
     * @param  list<array{document_id: string, document_key: string, revision_id: string, version: string, source_sha256: string}>  $revisions
     * @param  list<array{binding_id: string, document_key: string, revision_id: string, section_key: string, body_sha256: string, slot: string, layer: string, merge_mode: string}>  $manifest
     * @param  array<string, string|null>  $sections  section_key => than; null khi hai section cung khoa cung gop vao
     */
    public function __construct(
        public readonly string $consumerKey,
        public readonly string $content,
        public readonly string $compositionHash,
        public readonly string $renderedHash,
        public readonly string $compilerVersion,
        public readonly array $bindings,
        public readonly array $revisions,
        public readonly array $manifest,
        public readonly array $sections = [],
    ) {}

    /**
     * Than mot section theo khoa, cho consumer can tung file rieng (vi du
     * 00_writer_contract.md) thay vi ca prompt da ghep.
     */
    public function section(string $key): string
    {
        if (! array_key_exists($key, $this->sections)) {
            throw new PromptLibraryException("Prompt {$this->consumerKey} khong co section {$key}.");
        }

        return $this->sections[$key]
            ?? throw new PromptLibraryException("Prompt {$this->consumerKey}: nhieu section cung khoa {$key}, khong biet lay cai nao.");
    }

    /**
     * Mot dong pin cho moi cap (binding, document) thuc su gop section vao prompt.
     *
     * @return list<array{binding_id: string, document_id: string, revision_id: string}>
     */
    public function pins(): array
    {
        $revisionOf = [];

        foreach ($this->revisions as $revision) {
            $revisionOf[$revision['revision_id']] = $revision['document_id'];
        }

        $pins = [];

        foreach ($this->manifest as $entry) {
            $pins[$entry['binding_id'].'|'.$entry['revision_id']] = [
                'binding_id' => $entry['binding_id'],
                'document_id' => $revisionOf[$entry['revision_id']],
                'revision_id' => $entry['revision_id'],
            ];
        }

        return array_values($pins);
    }

    /**
     * Ghi vao input_json._meta.prompt: _meta nam ngoai input_hash, nen them
     * nguon goc o day khong lam doi viec dung lai ket qua cu.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'consumer' => $this->consumerKey,
            'bindings' => array_map(fn (array $b): array => [
                'binding_id' => $b['binding_id'], 'version' => $b['version'], 'scope' => $b['scope_type'].':'.$b['scope_key'],
            ], $this->bindings),
            'revision_ids' => array_column($this->revisions, 'revision_id'),
            'composition_hash' => $this->compositionHash,
            'rendered_prompt_hash' => $this->renderedHash,
            'compiler_version' => $this->compilerVersion,
        ];
    }
}
