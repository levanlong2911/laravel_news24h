<?php

namespace App\Console\Commands;

use App\Models\PromptLibrary\PromptDocument;
use Illuminate\Console\Command;

class PromptsSections extends Command
{
    protected $signature = 'prompts:sections
        {document : <namespace>.<key>}
        {version? : Mac dinh la revision active, khong co thi revision moi nhat}
        {--show= : In nguyen than mot section_key}';

    protected $description = 'Xem cay section, dong nguon va hash cua mot revision';

    public function handle(): int
    {
        $document = PromptDocument::findByFullKey((string) $this->argument('document'));

        if ($document === null) {
            $this->error('Khong thay document.');

            return self::FAILURE;
        }

        $version = $this->argument('version');
        $revision = $version !== null
            ? $document->revisions()->where('version', $version)->first()
            : ($document->activeRevision ?? $document->revisions()->latest('created_at')->first());

        if ($revision === null) {
            $this->error('Khong thay revision.');

            return self::FAILURE;
        }

        if (is_string($this->option('show'))) {
            $section = $revision->sections()->where('section_key', $this->option('show'))->first();

            if ($section === null) {
                $this->error('Khong thay section.');

                return self::FAILURE;
            }

            $this->output->write($section->body);

            return self::SUCCESS;
        }

        $this->info("{$document->fullKey()} {$revision->version} [{$revision->status}]".($document->active_revision_id === $revision->id ? ' ACTIVE' : ''));
        $this->line("  sha256 {$revision->source_sha256}, {$revision->byte_size} byte, format {$revision->format}");

        $this->table(['section_key', 'kind', 'dong', 'token~', 'sha256'], $revision->sections()->get()->map(fn ($s) => [
            str_repeat('  ', max(0, $s->heading_level - 2)).$s->section_key,
            $s->kind,
            $s->start_line.'-'.$s->end_line,
            $s->token_estimate,
            substr($s->body_sha256, 0, 12),
        ])->all());

        return self::SUCCESS;
    }
}
