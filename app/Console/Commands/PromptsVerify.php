<?php

namespace App\Console\Commands;

use App\Services\PromptLibrary\PromptLibraryException;
use App\Services\PromptLibrary\PromptSource;
use App\Video\Prompt\AnchorDesignPrompt;
use App\Video\Prompt\GeometryPromptAuthor;
use App\Video\Scene\ScenePlanAuthor;
use Illuminate\Console\Command;
use ReflectionMethod;

/**
 * Kiem hoi quy truoc khi bat PROMPT_SOURCE=database. Khong goi model.
 *
 *  1. Moi file trong prompt_library.consumers trung tung byte voi section cung
 *     ten trong revision dang active.
 *  2. Hash prompt ma tung author/service thuc su dung (skill_hash, rules_hash,
 *     system message...) giong nhau giua che do file va database. Day chinh la
 *     cac gia tri vao input_hash khi claim, nen trung het thi bat database khong
 *     lam mat ket qua da co.
 *  3. (--baseline) so them voi ban chup truoc khi sua code.
 */
class PromptsVerify extends Command
{
    /** Gia tri trong input_hash ma service doc thang tu file (khong qua author). */
    private const RAW_FILES = [
        'file:image_prompt.anchor_geometry.prompt_path' => 'image.anchor_geometry',
        'file:image_prompt.reference.prompt_path' => 'image.reference_view',
        'file:image_prompt.reference_plan.prompt_path' => 'image.reference_plan',
    ];

    private const SCREENPLAY_AUTHORS = [
        'design_author' => 'video.screenplay.design',
        'design_repair_author' => null,
        'story_author' => 'video.screenplay.story',
        'location_author' => 'video.screenplay.locations',
        'scene_author' => 'video.screenplay.scenes',
    ];

    protected $signature = 'prompts:verify
        {--project= : Project id lam scope category/project}
        {--baseline= : File JSON hash chup tu code cu de so them}';

    protected $description = 'So prompt cua moi consumer doc tu file va tu DB tung byte (khong goi model)';

    public function handle(): int
    {
        $projectId = $this->option('project') ?: null;
        $failures = 0;

        $this->info('1. File so voi section trong DB');

        foreach (array_keys((array) config('prompt_library.consumers')) as $consumer) {
            try {
                $prompt = app(PromptSource::class)->fromDatabase($consumer, $projectId);

                foreach (PromptSource::filesFor($consumer) as $key => $path) {
                    $same = is_file($path) && $prompt->section($key) === file_get_contents($path);
                    $failures += $same ? 0 : 1;
                    $this->line(sprintf('  %s %-28s %s', $same ? '<fg=green>OK</>  ' : '<fg=red>KHAC</>', $consumer, $key));
                }
            } catch (PromptLibraryException $e) {
                $failures++;
                $this->line(sprintf('  <fg=red>LOI</>  %-28s %s', $consumer, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info('2. Hash prompt ma pipeline dung: che do file so voi database');

        try {
            $file = $this->snapshot(PromptSource::FILE, $projectId);
            $database = $this->snapshot(PromptSource::DATABASE, $projectId);
        } catch (\Throwable $e) {
            $this->error('Khong chup duoc hash: '.$e->getMessage());

            return self::FAILURE;
        }

        $baseline = $this->baseline();

        foreach ($file as $key => $hash) {
            $same = ($database[$key] ?? null) === $hash;
            $base = $baseline === null ? null : (($baseline[$key] ?? null) === $hash);
            $failures += ($same ? 0 : 1) + ($base === false ? 1 : 0);
            $this->line(sprintf(
                '  %s %-48s %s%s',
                $same ? '<fg=green>OK</>  ' : '<fg=red>KHAC</>',
                $key,
                substr($hash, 0, 16),
                $base === null ? '' : ($base ? '  = baseline' : '  <fg=red>!= baseline</>'),
            ));
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} kiem tra khong dat: CHUA duoc bat PROMPT_SOURCE=database.");

            return self::FAILURE;
        }

        $this->info('Moi consumer trung tung byte giua file va DB: bat PROMPT_SOURCE=database khong doi input_hash.');

        return self::SUCCESS;
    }

    /** @return array<string, string> */
    private function snapshot(string $mode, ?string $projectId): array
    {
        $previous = config('prompt_library.source');
        config(['prompt_library.source' => $mode]);
        app()->forgetScopedInstances();

        try {
            $source = app(PromptSource::class);
            $out = [];

            $storyboard = $source->attach(app(ScenePlanAuthor::class), 'video.storyboard', $projectId);
            $out['storyboard.skill_hash'] = $storyboard->skillHash();
            $out['storyboard.system'] = hash('sha256', $storyboard->preview(['verify' => true])['system']);

            foreach (self::SCREENPLAY_AUTHORS as $key => $consumer) {
                $author = app('video.screenplay.'.$key);
                $author = $consumer === null ? $author : $source->attach($author, $consumer, $projectId);
                $rules = new ReflectionMethod($author, 'rules');
                $rules->setAccessible(true);
                $out["screenplay.{$key}.rules"] = hash('sha256', (string) $rules->invoke($author));
                $out["screenplay.{$key}.lineage"] = hash('sha256', (string) json_encode($author->promptLineage()));
            }

            $out['geometry_author.skill_hash'] = app(GeometryPromptAuthor::class)->skillHash();
            $out['character_author.skill_hash'] = app('video.character_prompt_author')->skillHash();
            $anchor = app(AnchorDesignPrompt::class);
            $out['anchor_design.skill_hash'] = $anchor->skillHash();
            $out['anchor_design.geometry_template_hash'] = $anchor->geometryTemplateHash();

            foreach (self::RAW_FILES as $key => $consumer) {
                $prompt = $source->resolve($consumer, $projectId);
                $text = $prompt !== null
                    ? $prompt->content
                    : (string) @file_get_contents((string) config(substr($key, strlen('file:'))));
                $out[$key] = hash('sha256', $text);
            }

            ksort($out);

            return $out;
        } finally {
            config(['prompt_library.source' => $previous]);
            app()->forgetScopedInstances();
        }
    }

    /** @return array<string, string>|null */
    private function baseline(): ?array
    {
        $path = $this->option('baseline');

        if (! is_string($path) || $path === '') {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
