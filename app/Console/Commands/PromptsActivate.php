<?php

namespace App\Console\Commands;

use App\Models\PromptLibrary\PromptDocument;
use App\Services\PromptLibrary\PromptLibraryException;
use App\Services\PromptLibrary\PromptPublisher;
use Illuminate\Console\Command;

class PromptsActivate extends Command
{
    protected $signature = 'prompts:activate
        {document : <namespace>.<key>}
        {version : Version cua revision can dua len active}';

    protected $description = 'Publish revision (neu con draft) va tro active vao no; dung ca de rollback ve revision cu';

    public function handle(PromptPublisher $publisher): int
    {
        try {
            $document = PromptDocument::findByFullKey((string) $this->argument('document'))
                ?? throw new PromptLibraryException('Khong thay document '.$this->argument('document'));
            $revision = $document->revisions()->where('version', (string) $this->argument('version'))->first()
                ?? throw new PromptLibraryException('Khong thay revision '.$this->argument('version'));
            $previous = $document->active_revision_id;
            $revision = $publisher->activateRevision($revision);
        } catch (PromptLibraryException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$document->fullKey()} active -> {$revision->version} ({$revision->id})".($previous ? ", truoc do {$previous}" : ''));
        $this->line('Run da pin revision cu khong bi anh huong.');

        return self::SUCCESS;
    }
}
