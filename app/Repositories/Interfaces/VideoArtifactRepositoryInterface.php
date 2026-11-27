<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoArtifact;
use Illuminate\Support\Collection;

interface VideoArtifactRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  ?string  $artifactId  null still runs the lookup (id is null)
     * @return ?VideoArtifact the artifact, of any project
     */
    public function findArtifact(?string $artifactId): ?VideoArtifact;

    /**
     * @param  ?string  $artifactId
     * @return VideoArtifact the artifact, of any project
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     */
    public function findArtifactOrFail(?string $artifactId): VideoArtifact;

    /**
     * @param  string  $projectId
     * @param  ?string  $artifactId
     * @return ?VideoArtifact the artifact of the project
     */
    public function findOwnedArtifact(string $projectId, ?string $artifactId): ?VideoArtifact;

    /**
     * @param  string  $projectId
     * @param  string  $artifactId
     * @param  string  $designImageId
     * @return ?VideoArtifact the artifact of the project rendered for that image
     */
    public function findKeyframeArtifact(string $projectId, string $artifactId, string $designImageId): ?VideoArtifact;

    /**
     * @param  ?string  $artifactId
     * @param  string  $designImageId
     * @return ?VideoArtifact the artifact rendered for that image, of any project
     */
    public function findArtifactOfImage(?string $artifactId, string $designImageId): ?VideoArtifact;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $artifactIds  an empty list still runs the query
     * @return Collection<int, VideoArtifact> the artifacts of the project with these ids
     */
    public function findOwnedArtifactsByIds(string $projectId, array|Collection $artifactIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $role
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return Collection<int, VideoArtifact> the artifacts of the project in that role with these ids
     */
    public function findOwnedArtifactsWithRoleByIds(string $projectId, string $role, array|Collection $artifactIds): Collection;

    /**
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return Collection<int, VideoArtifact> the artifacts with these ids, of any project
     */
    public function findArtifactsByIds(array|Collection $artifactIds): Collection;

    /**
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return Collection<int, VideoArtifact> id, width and height of the artifacts with these ids, of any project
     */
    public function findArtifactSizesByIds(array|Collection $artifactIds): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return array<string, string> sha256 by id of the artifacts of the project with these ids, as strings
     */
    public function pluckOwnedShaByIds(string $projectId, array|Collection $artifactIds): array;

    /**
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return array<string, string> sha256 by id of the artifacts with these ids, of any project, as strings
     */
    public function pluckShaByIds(array|Collection $artifactIds): array;

    /**
     * @param  string  $projectId
     * @param  string  $masterArtifactId  metadata_json.master_artifact_id
     * @param  string  $role
     * @return Collection<int, VideoArtifact> the crops of the project in that role cut from the master that belong to no image and are not superseded
     */
    public function findSheetCrops(string $projectId, string $masterArtifactId, string $role): Collection;
}
