<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoArtifact;
use App\Repositories\Interfaces\VideoArtifactRepositoryInterface;
use Illuminate\Support\Collection;

class VideoArtifactRepository extends BaseRepository implements VideoArtifactRepositoryInterface
{
    public function getModel(): string
    {
        return VideoArtifact::class;
    }

    public function findArtifact(?string $artifactId): ?VideoArtifact
    {
        return $this->model->newQuery()->whereKey($artifactId)->first();
    }

    public function findArtifactOrFail(?string $artifactId): VideoArtifact
    {
        return $this->model->newQuery()->whereKey($artifactId)->firstOrFail();
    }

    public function findOwnedArtifact(string $projectId, ?string $artifactId): ?VideoArtifact
    {
        return $this->model->newQuery()->where('project_id', $projectId)->find($artifactId);
    }

    public function findKeyframeArtifact(string $projectId, string $artifactId, string $designImageId): ?VideoArtifact
    {
        return $this->model->newQuery()->whereKey($artifactId)->where('project_id', $projectId)->where('design_image_id', $designImageId)->first();
    }

    public function findArtifactOfImage(?string $artifactId, string $designImageId): ?VideoArtifact
    {
        return $this->model->newQuery()
            ->whereKey($artifactId)
            ->where('design_image_id', $designImageId)
            ->first();
    }

    public function findOwnedArtifactsByIds(string $projectId, array|Collection $artifactIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereIn('id', $artifactIds)->get();
    }

    public function findOwnedArtifactsWithRoleByIds(string $projectId, string $role, array|Collection $artifactIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('role', $role)->whereIn('id', $artifactIds)->get();
    }

    public function findArtifactsByIds(array|Collection $artifactIds): Collection
    {
        return $this->model->newQuery()->whereIn('id', $artifactIds)->get();
    }

    public function findArtifactSizesByIds(array|Collection $artifactIds): Collection
    {
        return $this->model->newQuery()->whereIn('id', $artifactIds)->get(['id', 'width', 'height']);
    }

    public function pluckOwnedShaByIds(string $projectId, array|Collection $artifactIds): array
    {
        return array_map('strval', $this->model->newQuery()->where('project_id', $projectId)->whereIn('id', $artifactIds)->pluck('sha256', 'id')->all());
    }

    public function pluckShaByIds(array|Collection $artifactIds): array
    {
        return array_map('strval', $this->model->newQuery()->whereIn('id', $artifactIds)->pluck('sha256', 'id')->all());
    }

    public function findSheetCrops(string $projectId, string $masterArtifactId, string $role): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('role', $role)
            ->whereNull('design_image_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.master_artifact_id')) = ?", [$masterArtifactId])
            ->whereRaw("JSON_EXTRACT(metadata_json, '$.superseded_at') IS NULL")
            ->get();
    }
}
