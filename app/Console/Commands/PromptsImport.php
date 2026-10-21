<?php

namespace App\Console\Commands;

use App\Services\PromptLibrary\Import\PromptImporter;
use App\Services\PromptLibrary\PromptLibraryException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class PromptsImport extends Command
{
    protected $signature = 'prompts:import
        {path : File nguon (nen nam trong git)}
        {document : <namespace>.<key>, vi du video.storyboard_v4}
        {--spec= : Version cua revision, vi du storyboard-v4-r3 hoac 6.3.0}
        {--format=markdown : markdown | text}
        {--name= : Ten document (chi dung khi tao moi)}
        {--type=workflow : document_type (chi dung khi tao moi)}
        {--description= : Mo ta document (chi dung khi tao moi)}
        {--dry-run : Chi tach section va in ra, khong ghi DB}';

    protected $description = 'Nap mot file prompt thanh revision DRAFT bat bien (cung noi dung thi khong tao moi)';

    public function handle(PromptImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $version = (string) $this->option('spec');

        if (! is_file($path)) {
            $this->error("Khong thay file: {$path}");

            return self::FAILURE;
        }

        if ($version === '') {
            $this->error('Thieu --spec (version cua revision).');

            return self::FAILURE;
        }

        $content = (string) file_get_contents($path);
        $format = (string) $this->option('format');

        try {
            if ($this->option('dry-run')) {
                $sections = $importer->sections($content, $format);

                foreach ($sections as $s) {
                    $this->line(sprintf('%s%-60s L%d-%d  %d byte', str_repeat('  ', max(0, $s->level - 2)), $s->key, $s->startLine, $s->endLine, strlen($s->body)));
                }

                $this->info(count($sections).' section, sha256 '.hash('sha256', $content));

                return self::SUCCESS;
            }

            [$revision, $created] = $importer->import($content, (string) $this->argument('document'), $version, $format, [
                'name' => $this->option('name') ?: null,
                'type' => (string) $this->option('type'),
                'description' => $this->option('description') ?: null,
                'filename' => basename($path),
                'commit' => $this->commitOf($path),
                'created_by' => 'cli',
            ]);
        } catch (PromptLibraryException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(($created ? 'Da tao' : 'Da co san (cung noi dung)').": revision {$revision->version} [{$revision->status}] {$revision->id}");
        $this->line("  sha256 {$revision->source_sha256}, ".$revision->sections()->count().' section'.($revision->source_commit ? ", commit {$revision->source_commit}" : ', khong co commit (file ngoai git hoac dang sua)'));

        return self::SUCCESS;
    }

    /** Commit cuoi cua file, chi khi file nam trong git va khong co thay doi chua commit. */
    private function commitOf(string $path): ?string
    {
        $dir = dirname((string) realpath($path));
        $dirty = Process::path($dir)->run(['git', 'status', '--porcelain', '--', basename($path)]);
        $log = Process::path($dir)->run(['git', 'log', '-1', '--format=%H', '--', basename($path)]);

        if (! $dirty->successful() || trim($dirty->output()) !== '' || ! $log->successful()) {
            return null;
        }

        $commit = trim($log->output());

        return preg_match('/^[0-9a-f]{40}$/', $commit) ? $commit : null;
    }
}
