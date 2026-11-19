<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Models\VideoProject;
use RuntimeException;

final class MotoPipelineRelease
{
    public const KEY = 'moto_pipeline_release';

    public const LEGACY = 'harley-custom-legacy-v1';

    public const CINEMATIC = 'moto-cinematic-3s-v1';

    public const CINEMATIC_V2 = 'moto-cinematic-3s-v2';

    public const CINEMATIC_V3 = 'moto-cinematic-3s-v3';

    /** @var string the release whose design always has the five fixed roles and whose script one character for each, in their order */
    public const CINEMATIC_V4 = 'moto-cinematic-3s-v4';

    /** @var string the release whose design prompt agrees with the refs policy of MotoDesignRefs: refs name only ids the answer defines, and no text holds an id */
    public const CINEMATIC_V5 = 'moto-cinematic-3s-v5';

    /** @var string the release whose design tells seven stages, each physical one with the checkpoints it passes through (moto-design-v3); every later step reads the states through MotoDesignStates */
    public const CINEMATIC_V7 = 'moto-cinematic-3s-v7';

    /** @var string the key of the project metadata that keeps every change of release made to the project after it was pinned, oldest first */
    public const HISTORY_KEY = 'moto_pipeline_release_history';

    /** @var list<string> the releases of three-second takes made from references: every one runs the same eventful pipeline, each with its own catalogue of revisions and its own check policy */
    public const CINEMATIC_RELEASES = [self::CINEMATIC, self::CINEMATIC_V2, self::CINEMATIC_V3, self::CINEMATIC_V4, self::CINEMATIC_V5, self::CINEMATIC_V7];

    /** @return string the release the project pinned, the legacy release when it pinned none */
    public static function of(VideoProject $project): string
    {
        $pinned = $project->metadata_json[self::KEY] ?? null;

        return is_string($pinned) && $pinned !== '' ? $pinned : self::LEGACY;
    }

    /** @var list<string> the fields of brief.direction the Phase 2 prompt of the cinematic release names; every one is sent as null, so the model decides it */
    public const DIRECTION_FIELDS = ['theme', 'form', 'materials', 'graphics', 'change_level', 'presentation'];

    public static function cinematic(VideoProject $project): bool
    {
        return in_array(self::of($project), self::CINEMATIC_RELEASES, true);
    }

    /**
     * @return bool whether the release of the project designs seven stages with checkpoints: its design_format, read from the pinned release alone like its policy, is moto-design-v3
     */
    public static function checkpointed(VideoProject $project): bool
    {
        return config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW.'.releases.'.self::of($project).'.design_format') === MotoContract::DESIGN_FORMAT_V3;
    }

    /**
     * @param  string  $step  the step whose check asks: script or shots
     * @return array<string, mixed> what the release of the project adds to the check policy of that step, pinned in the context of a run when the run is made; empty for a release that adds nothing, so a run made under it is checked as before
     */
    public static function policy(VideoProject $project, string $step): array
    {
        return (array) config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW.'.releases.'.self::of($project).'.policy.'.$step, []);
    }

    /**
     * @param  bool|\Closure(): bool  $hasRuns  whether the project already ran Phase 2 under no pinned release; a closure is asked only when the project pinned none
     * @return string the release the project keeps: the one it pinned, the legacy release when it already ran, otherwise the configured release of new projects. Reads only, nothing is stored
     *
     * @throws RuntimeException when the configured release of new projects is not declared
     */
    public static function chosen(VideoProject $project, bool|\Closure $hasRuns): string
    {
        $pinned = $project->metadata_json[self::KEY] ?? null;

        if (is_string($pinned) && $pinned !== '') {
            return $pinned;
        }

        $workflow = (array) config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW);
        $release = ($hasRuns instanceof \Closure ? $hasRuns() : $hasRuns) ? self::LEGACY : (string) ($workflow['default_release'] ?? self::LEGACY);

        if (! isset($workflow['releases'][$release])) {
            throw new RuntimeException("Release {$release} chưa được khai trong cấu hình moto — không ghim, không gọi model.");
        }

        return $release;
    }

    /**
     * @param  bool  $hasRuns  whether the project already ran Phase 2 under no pinned release
     * @return string the release chosen() gives, stored with the project when it pinned none; the caller holds the project as it is stored now, since every other key of its metadata is written back from this copy
     *
     * @throws RuntimeException when the configured release of new projects is not declared
     */
    public static function pin(VideoProject $project, bool $hasRuns): string
    {
        $release = self::chosen($project, $hasRuns);
        self::keep($project, $release);

        return $release;
    }

    /**
     * @param  string  $release  what chosen() gave for this project
     * @return void the release is stored with a project that pinned none, and a project that pinned one is not written
     */
    public static function keep(VideoProject $project, string $release): void
    {
        $pinned = $project->metadata_json[self::KEY] ?? null;

        if (! is_string($pinned) || $pinned === '') {
            $project->forceFill(['metadata_json' => [...(array) $project->metadata_json, self::KEY => $release]])->save();
        }
    }

    /**
     * @param  bool  $hasRuns  whether the project already ran Phase 2
     * @return ?string the release a project opened before the current default keeps when it pinned none: the legacy release when it already ran, otherwise the release new projects took before; null for a project that pinned one
     */
    public static function inherited(VideoProject $project, bool $hasRuns): ?string
    {
        $pinned = $project->metadata_json[self::KEY] ?? null;

        if (is_string($pinned) && $pinned !== '') {
            return null;
        }

        $workflow = (array) config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW);

        return $hasRuns ? self::LEGACY : (string) ($workflow['previous_default_release'] ?? self::LEGACY);
    }

    /** @var array<string, array{stage: string, selection: ?string}> the steps a change of release may move: the phase whose revision the new release replaces, the planning stage of its runs and the project metadata key of its selection; the design has no selection of its own, its approval with the model sheet is what holds it */
    private const MOVABLE = [
        'phase-3' => ['stage' => 'yacht_design', 'selection' => null],
        'phase-6' => ['stage' => 'scenes', 'selection' => 'scenes_selection'],
        'phase-7' => ['stage' => 'shots', 'selection' => 'shots_selection'],
    ];

    /** @var array<string, list<string>> the phases a change of release moves only for a project that never ran them, with the planning stages their runs are kept in: a run of any status, waiting, running, failed or finished, holds the phase */
    private const UNSTARTED = [
        'phase-1' => ['inspiration'],
        'phase-2' => ['design_dna'],
        'phase-4' => ['script'],
        'phase-5' => ['locations'],
        'phase-8' => ['reference_review'],
        'phase-9' => ['keyframe_prompt'],
        'phase-10' => ['clip_plan', 'clip_prompt'],
        'phase-11' => ['film_plan'],
    ];

    /** @return list<string> the planning stages whose runs keep a project from moving while one waits or runs */
    public static function movableStages(): array
    {
        return array_values(array_unique(array_column(self::MOVABLE, 'stage')));
    }

    /**
     * @param  string  $to  the release the project moves to
     * @param  array<string, ?array<string, mixed>>  $rulesNow  for every phase of the workflow, the catalogue rule of the release the project has
     * @param  array<string, ?array<string, mixed>>  $rulesNext  the same for the release it moves to
     * @param  list<string>  $busy  the planning stages of the project with a run waiting or running
     * @param  bool  $designApproved  the design of the project was approved with its model sheet, read under the project lock
     * @param  ?list<string>  $ran  the planning stages the project has any run of, read under the project lock; null when the caller did not read them, and then a phase other than design, scenes and shots never moves
     * @return list<string> why the project cannot move: it pinned no release, the release is not declared or does not extend the one it has, it replaces a phase other than design, scenes and shots that the project already ran (or whose stages this code does not know), or a step it replaces is held — a design approved with its model sheet, a selection — or has a run in progress
     */
    public static function moveProblems(VideoProject $project, string $to, array $rulesNow, array $rulesNext, array $busy, bool $designApproved = false, ?array $ran = null): array
    {
        $workflow = (array) config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW);
        $pinned = $project->metadata_json[self::KEY] ?? null;

        if (! is_string($pinned) || $pinned === '') {
            return ['Dự án chưa ghim bản phát hành nào — ghim trước rồi mới chuyển.'];
        }

        if (! isset($workflow['releases'][$to])) {
            return ["Bản phát hành {$to} chưa được khai trong cấu hình moto."];
        }

        if (($workflow['releases'][$to]['extends'] ?? null) !== $pinned) {
            return ["{$to} không mở rộng trực tiếp {$pinned} — chỉ chuyển sang bản kế tiếp của bản dự án đang ghim."];
        }

        $problems = [];

        foreach (array_keys($rulesNow + $rulesNext) as $code) {
            if (($rulesNow[$code] ?? null) == ($rulesNext[$code] ?? null)) {
                continue;
            }

            $step = self::MOVABLE[$code] ?? null;

            if ($step === null) {
                $held = $ran === null || ! isset(self::UNSTARTED[$code]) ? null : array_values(array_intersect(self::UNSTARTED[$code], $ran));

                if ($held === null) {
                    $problems[] = "{$to} đổi revision của {$code} — nguồn đã có của dự án ở bước này sẽ không còn khớp; lệnh này chỉ chuyển Design, Scene và Shot.";
                } elseif ($held !== []) {
                    $problems[] = "{$to} đổi revision của {$code} và dự án đã có lượt ".implode(', ', $held).' ở bước này — nguồn đã có sẽ không còn khớp; bước này chỉ chuyển khi dự án chưa chạy nó.';
                }
            } elseif ($step['selection'] === null && $designApproved) {
                $problems[] = 'Bản thiết kế của dự án đã duyệt cùng model sheet — các bước sau đã dựng trên nó, không chuyển sang revision Design khác.';
            } elseif ($step['selection'] !== null && is_array($project->metadata_json[$step['selection']] ?? null)) {
                $problems[] = "Dự án đang chọn một bản {$step['stage']} — chuyển bản phát hành sẽ làm bản đó không dùng được.";
            } elseif (in_array($step['stage'], $busy, true)) {
                $problems[] = "Đang có lượt {$step['stage']} chờ hoặc đang chạy.";
            }
        }

        return $problems;
    }

    /**
     * @param  string  $to  the release the project keeps from now on
     * @param  string  $reason  why
     * @param  ?string  $actor  who decided
     * @return array{from: ?string, to: string, reason: string, actor: ?string, at: string} the change, stored with the project after the changes it had before; the caller checked moveProblems() or inherited() first
     */
    public static function record(VideoProject $project, string $to, string $reason, ?string $actor): array
    {
        $metadata = (array) $project->metadata_json;
        $pinned = $metadata[self::KEY] ?? null;
        $change = ['from' => is_string($pinned) && $pinned !== '' ? $pinned : null, 'to' => $to, 'reason' => $reason, 'actor' => $actor, 'at' => now()->toIso8601String()];
        $project->forceFill(['metadata_json' => [...$metadata, self::KEY => $to, self::HISTORY_KEY => [...array_values((array) ($metadata[self::HISTORY_KEY] ?? [])), $change]]])->save();

        return $change;
    }
}
