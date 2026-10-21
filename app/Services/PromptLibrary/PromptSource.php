<?php

namespace App\Services\PromptLibrary;

use App\Models\VideoProject;
use App\Models\VideoPlanningStage;

/**
 * Cua duy nhat de pipeline lay prompt tu thu vien (prompt_library.source).
 *
 * Che do file: resolve() tra null va moi consumer chay code doc file cu, khong
 * doi mot dong. Che do database: prompt duoc ghep qua PromptResolver.
 *
 * Dang ky scoped: trong MOT request/job, cung consumer + project chi ghep mot
 * lan, nen hash tinh luc claim va noi dung gui model luon cung mot revision.
 * Queue worker xoa instance scoped truoc moi job, nen job sau doc revision moi.
 */
class PromptSource
{
    public const FILE = 'file';

    public const DATABASE = 'database';

    /** execution_type cua ai_prompt_run_pins khi pin vao mot planning stage. */
    public const STAGE_EXECUTION = 'video_planning_stage';

    /** @var array<string, ResolvedPrompt> */
    private array $resolved = [];

    public function __construct(
        private readonly PromptResolver $resolver,
        private readonly PromptRunPinner $pinner,
    ) {}

    public function enabled(): bool
    {
        $mode = (string) config('prompt_library.source');

        if (! in_array($mode, [self::FILE, self::DATABASE], true)) {
            throw new PromptLibraryException("prompt_library.source khong hop le: {$mode}");
        }

        return $mode === self::DATABASE;
    }

    /** Null o che do file: noi goi giu nguyen duong doc file cu. */
    public function resolve(string $consumer, ?string $projectId = null): ?ResolvedPrompt
    {
        return $this->enabled() ? $this->fromDatabase($consumer, $projectId) : null;
    }

    /** Luon ghep tu DB, bat ke che do (dung cho prompts:verify). */
    public function fromDatabase(string $consumer, ?string $projectId = null): ResolvedPrompt
    {
        return $this->resolved[$consumer.'|'.$projectId] ??= $this->resolver->resolve($consumer, $this->context($projectId));
    }

    /**
     * Gan prompt da ghep vao mot author (ScenePlanAuthor, ScreenplayAuthor...).
     * Che do file tra nguyen author.
     *
     * @template T of object
     *
     * @param  T  $author
     * @return T
     */
    public function attach(object $author, string $consumer, ?string $projectId = null): object
    {
        $prompt = $this->resolve($consumer, $projectId);

        return $prompt === null ? $author : $author->withPrompt($prompt);
    }

    /**
     * Metadata va callback pin cho claimProjectStage(). Che do file: [[], null],
     * nen loi goi claim giong het truoc.
     *
     * @param  list<ResolvedPrompt|null>  $prompts
     * @return array{0: array<string, mixed>, 1: ?callable(VideoPlanningStage): void}
     */
    public function forClaim(array $prompts): array
    {
        $prompts = array_values(array_filter($prompts));

        if ($prompts === []) {
            return [[], null];
        }

        $meta = ['prompt' => count($prompts) === 1
            ? $prompts[0]->summary()
            : array_map(fn (ResolvedPrompt $p): array => $p->summary(), $prompts)];

        return [$meta, function (VideoPlanningStage $stage) use ($prompts): void {
            $this->pinner->pinAll(self::STAGE_EXECUTION, (string) $stage->id, $prompts);
        }];
    }

    /**
     * forClaim() cho consumer doc qua resolve() (prompt anh): dung ban da ghep
     * trong request nay.
     *
     * @param  list<string>  $consumers
     * @return array{0: array<string, mixed>, 1: ?callable(VideoPlanningStage): void}
     */
    public function claimFor(array $consumers, ?string $projectId = null): array
    {
        return $this->forClaim(array_map(fn (string $c): ?ResolvedPrompt => $this->resolve($c, $projectId), $consumers));
    }

    /**
     * Noi file nguon cua mot consumer theo prompt_library.consumers.
     *
     * @return array<string, string> section_key => duong dan file
     */
    public static function filesFor(string $consumer): array
    {
        $entry = self::entry($consumer);
        $files = [];

        if (isset($entry['dir'])) {
            $dir = rtrim((string) config($entry['dir']), '/\\');

            foreach ((array) $entry['files'] as $name) {
                $files[$name] = $dir.DIRECTORY_SEPARATOR.$name;
            }

            return $files;
        }

        foreach ((array) $entry['files'] as $key => $configKey) {
            $files[$key] = (string) config($configKey);
        }

        return $files;
    }

    public static function versionFor(string $consumer): string
    {
        return implode('+', array_map(fn (string $key): string => (string) config($key), (array) self::entry($consumer)['version']));
    }

    public static function documentFor(string $consumer): string
    {
        return (string) self::entry($consumer)['document'];
    }

    /**
     * Ten consumer co dau cham (video.storyboard) nen khong tra bang
     * config("prompt_library.consumers.{$consumer}"): dot notation se tach no ra.
     *
     * @return array<string, mixed>
     */
    private static function entry(string $consumer): array
    {
        $entry = ((array) config('prompt_library.consumers'))[$consumer] ?? null;

        if (! is_array($entry)) {
            throw new PromptLibraryException("Consumer chua dang ky trong prompt_library.consumers: {$consumer}");
        }

        return $entry;
    }

    /** @return array<string, string|null> */
    private function context(?string $projectId): array
    {
        $category = null;

        if ($projectId !== null && $projectId !== '') {
            $category = VideoProject::query()->with('article.category')->find($projectId)?->article?->category?->slug;
        }

        return [
            'workflow' => (string) config('video.screenplay.workflow'),
            'category' => $category,
            'project' => $projectId,
        ];
    }
}
