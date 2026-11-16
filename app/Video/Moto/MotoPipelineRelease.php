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

    /** @var list<string> the releases of three-second takes made from references: every one runs the same eventful pipeline, each with its own catalogue of revisions and its own check policy */
    public const CINEMATIC_RELEASES = [self::CINEMATIC, self::CINEMATIC_V2];

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
     * @param  string  $step  the step whose check asks: script or shots
     * @return array<string, mixed> what the release of the project adds to the check policy of that step, pinned in the context of a run when the run is made; empty for a release that adds nothing, so a run made under it is checked as before
     */
    public static function policy(VideoProject $project, string $step): array
    {
        return (array) config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW.'.releases.'.self::of($project).'.policy.'.$step, []);
    }

    /**
     * @param  bool  $hasRuns  whether the project already ran Phase 2 under no pinned release
     * @return string the release the project keeps from now on: the one it pinned, the legacy release when it already ran, otherwise the configured release of new projects
     *
     * @throws RuntimeException when the configured release of new projects is not declared
     */
    public static function pin(VideoProject $project, bool $hasRuns): string
    {
        $pinned = $project->metadata_json[self::KEY] ?? null;

        if (is_string($pinned) && $pinned !== '') {
            return $pinned;
        }

        $workflow = (array) config('video.superyacht.contracts.workflows.'.MotoContract::WORKFLOW);
        $release = $hasRuns ? self::LEGACY : (string) ($workflow['default_release'] ?? self::LEGACY);

        if (! isset($workflow['releases'][$release])) {
            throw new RuntimeException("Release {$release} chưa được khai trong cấu hình moto — không ghim, không gọi model.");
        }

        $project->forceFill(['metadata_json' => [...(array) $project->metadata_json, self::KEY => $release]])->save();

        return $release;
    }
}
