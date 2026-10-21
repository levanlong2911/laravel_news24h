<?php

namespace App\Console\Commands;

use App\Models\PromptLibrary\PromptBindingSection;
use App\Models\PromptLibrary\PromptDocument;
use App\Services\PromptLibrary\Import\PromptImporter;
use App\Services\PromptLibrary\PromptLibraryException;
use App\Services\PromptLibrary\PromptPublisher;
use App\Services\PromptLibrary\PromptSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Dua prompt dang chay (file trong git, theo prompt_library.consumers) vao DB:
 * import revision, dua len active, va dam bao consumer co binding global gom
 * dung cac file do. Chay lai bao nhieu lan cung duoc: cung noi dung thi khong
 * tao gi moi. Doi file ma khong tang version trong config thi import tu choi.
 */
class PromptsSync extends Command
{
    protected $signature = 'prompts:sync
        {--only=* : Chi dong bo cac consumer nay}';

    protected $description = 'Nap prompt dang chay tu file vao thu vien prompt (import + active + binding), khong goi model';

    public function handle(PromptImporter $importer, PromptPublisher $publisher): int
    {
        $consumers = array_keys((array) config('prompt_library.consumers'));
        $only = (array) $this->option('only');

        if ($only !== []) {
            $consumers = array_values(array_intersect($consumers, $only));
        }

        $failed = 0;

        foreach ($consumers as $consumer) {
            try {
                $this->line("<fg=cyan>{$consumer}</>  ".$this->sync($consumer, $importer, $publisher));
            } catch (\Throwable $e) {
                $failed++;
                $this->line("<fg=red>{$consumer}</>  LOI: ".$e->getMessage());
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} consumer chua dong bo duoc.");

            return self::FAILURE;
        }

        $this->info('Xong. Chay prompts:verify truoc khi bat PROMPT_SOURCE=database.');

        return self::SUCCESS;
    }

    private function sync(string $consumer, PromptImporter $importer, PromptPublisher $publisher): string
    {
        $documentKey = PromptSource::documentFor($consumer);
        $files = [];

        foreach (PromptSource::filesFor($consumer) as $key => $path) {
            if (! is_file($path)) {
                throw new PromptLibraryException("Thieu file {$path}");
            }

            $files[$key] = (string) file_get_contents($path);
        }

        $single = array_keys($files) === ['body'];
        [$revision, $created] = $importer->import(
            $single ? $files['body'] : PromptImporter::filesContent($files),
            $documentKey,
            PromptSource::versionFor($consumer),
            $single ? PromptImporter::TEXT : PromptImporter::FILES,
            [
                'name' => $documentKey,
                'type' => 'prompt',
                'description' => "Prompt cua consumer {$consumer} (prompts:sync)",
                'filename' => implode(', ', array_map('basename', PromptSource::filesFor($consumer))),
                'commit' => $this->commitOf(array_values(PromptSource::filesFor($consumer))),
                'created_by' => 'prompts:sync',
            ],
        );

        $notes = [($created ? 'revision moi ' : 'revision co san ').$revision->version];
        $document = PromptDocument::query()->findOrFail($revision->document_id);

        if ($document->active_revision_id !== $revision->id) {
            $publisher->activateRevision($revision);
            $notes[] = 'da dua len active';
        }

        $wanted = array_map(fn (string $key): array => [
            'document_id' => $document->id,
            'section_key' => $key,
            'slot_key' => 'instructions.task',
            'merge_mode' => PromptBindingSection::APPEND,
            'required' => true,
            'allow_override' => false,
        ], array_keys($files));

        if ($publisher->activeRows($consumer) !== $wanted) {
            $binding = $publisher->bind($consumer, $wanted, description: "{$documentKey}: ".implode(', ', array_keys($files)));
            $notes[] = "binding v{$binding->version} @ global:default";
        } else {
            $notes[] = 'binding giu nguyen';
        }

        return implode(', ', $notes);
    }

    /** @param list<string> $paths commit cuoi chung cua cac file, chi khi tat ca nam trong git va sach. */
    private function commitOf(array $paths): ?string
    {
        $root = base_path();
        $relative = array_map(fn (string $p): string => ltrim(str_replace([realpath($root) ?: $root, '\\'], ['', '/'], (string) realpath($p)), '/'), $paths);
        $dirty = Process::path($root)->run(['git', 'status', '--porcelain', '--', ...$relative]);
        $log = Process::path($root)->run(['git', 'log', '-1', '--format=%H', '--', ...$relative]);

        if (! $dirty->successful() || trim($dirty->output()) !== '' || ! $log->successful()) {
            return null;
        }

        $commit = trim($log->output());

        return preg_match('/^[0-9a-f]{40}$/', $commit) ? $commit : null;
    }
}
