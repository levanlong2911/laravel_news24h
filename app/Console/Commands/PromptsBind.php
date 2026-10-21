<?php

namespace App\Console\Commands;

use App\Models\PromptLibrary\PromptBindingSection;
use App\Models\PromptLibrary\PromptDocument;
use App\Services\PromptLibrary\PromptLibraryException;
use App\Services\PromptLibrary\PromptPublisher;
use Illuminate\Console\Command;

class PromptsBind extends Command
{
    protected $signature = 'prompts:bind
        {consumer : Consumer key, vi du video.storyboard}
        {--section=* : <document>:<section_key>@<slot>[:merge_mode][!optional][+override], lap lai theo thu tu}
        {--scope=global:default : <scope_type>:<scope_key> de gan binding}
        {--priority=0 : Priority trong scope}
        {--description= : Mo ta}
        {--draft : Chi tao draft, khong publish va khong gan scope}';

    protected $description = 'Tao version moi cho binding cua mot consumer, publish va gan vao scope';

    public function handle(PromptPublisher $publisher): int
    {
        $consumer = (string) $this->argument('consumer');
        $specs = (array) $this->option('section');

        if ($specs === []) {
            $this->error('Can it nhat mot --section.');

            return self::FAILURE;
        }

        try {
            $rows = array_map(fn (string $spec): array => $this->parse($spec), array_values($specs));
            $draftOnly = (bool) $this->option('draft');
            [$type, $key] = array_pad(explode(':', (string) $this->option('scope'), 2), 2, '');
            $binding = $publisher->bind(
                $consumer, $rows, $type, $key, (int) $this->option('priority'),
                $this->option('description') ?: null, $draftOnly,
            );

            if ($draftOnly) {
                $this->info("Da tao draft {$consumer} v{$binding->version} ({$binding->id}).");

                return self::SUCCESS;
            }
        } catch (PromptLibraryException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$consumer} v{$binding->version} da publish va gan vao {$this->option('scope')}.");

        return self::SUCCESS;
    }

    /** @return array{document_id: string, section_key: string, slot_key: string, merge_mode: string, required: bool, allow_override: bool} */
    private function parse(string $spec): array
    {
        if (! preg_match('/^([^:]+):([^@]+)@([a-z._]+)(?::(append|prepend|replace))?(!optional)?(\+override)?$/', $spec, $m)) {
            throw new PromptLibraryException("--section sai dang: {$spec}");
        }

        $document = PromptDocument::findByFullKey($m[1]) ?? throw new PromptLibraryException("Khong thay document {$m[1]}");

        return [
            'document_id' => $document->id,
            'section_key' => $m[2],
            'slot_key' => $m[3],
            'merge_mode' => ($m[4] ?? '') !== '' ? $m[4] : PromptBindingSection::APPEND,
            'required' => ($m[5] ?? '') === '',
            'allow_override' => ($m[6] ?? '') !== '',
        ];
    }
}
