<?php

namespace App\Repositories\Interfaces;

use App\Models\VideoDesignImage;
use Illuminate\Support\Collection;

interface VideoDesignImageRepositoryInterface extends RepositoryInterface
{
    /**
     * @param  string  $projectId
     * @param  ?string  $imageId
     * @return ?VideoDesignImage the image of the project
     */
    public function findForProject(string $projectId, ?string $imageId): ?VideoDesignImage;

    /**
     * @param  string  $projectId
     * @param  string  $imageType
     * @param  ?string  $imageId
     * @return ?VideoDesignImage the image of the project and type
     */
    public function findForProjectOfType(string $projectId, string $imageType, ?string $imageId): ?VideoDesignImage;

    /**
     * @param  ?string  $imageId  null still runs the lookup (id is null)
     * @return ?VideoDesignImage the image, of any project
     */
    public function findImage(?string $imageId): ?VideoDesignImage;

    /**
     * @param  string  $imageId
     * @return ?VideoDesignImage the image, of any project, with its selected artifact eager loaded
     */
    public function findImageWithArtifact(string $imageId): ?VideoDesignImage;

    /**
     * @param  ?string  $imageId
     * @return ?VideoDesignImage id and render_scene_id of the image, of any project
     */
    public function findImageSceneLink(?string $imageId): ?VideoDesignImage;

    /**
     * @param  ?string  $imageId
     * @return ?string image_code of the image, of any project
     */
    public function findImageCode(?string $imageId): ?string;

    /**
     * @param  string  $imageId
     * @return mixed selected_artifact_id of the image as stored
     */
    public function findSelectedArtifactId(string $imageId): mixed;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $imageIds  an empty list still runs the query
     * @return Collection<int, VideoDesignImage> id, project_id, image_type, status, render_error, prompt_spec_json, claimed_at and render_queue of the images of the project with these ids
     */
    public function findTaskImages(string $projectId, array|Collection $imageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $renderSceneIds
     * @return Collection<int, VideoDesignImage> the scene keyframes of the project on these scenes with their candidate artifacts eager loaded, newest first then by image code descending
     */
    public function findKeyframesForRenderScenes(string $projectId, array|Collection $renderSceneIds): Collection;

    /**
     * @param  string  $projectId
     * @param  int  $revision
     * @return Collection<int, VideoDesignImage> the scene keyframes of the project on the scenes of that revision, oldest first, with their candidate artifacts eager loaded oldest first
     */
    public function findSceneKeyframesForRevision(string $projectId, int $revision): Collection;

    /**
     * @param  string  $sceneId
     * @return ?VideoDesignImage the approved scene keyframe of the scene, of any project
     */
    public function findApprovedSceneKeyframe(string $sceneId): ?VideoDesignImage;

    /**
     * @param  string  $renderSceneId
     * @param  string  $artifactId
     * @return bool whether the approved scene keyframe of the scene selects this artifact
     */
    public function isSelectedApprovedSceneKeyframe(string $renderSceneId, string $artifactId): bool;

    /**
     * @param  ?string  $imageId
     * @param  string  $sceneId
     * @return ?VideoDesignImage the image when it is a scene keyframe of that scene
     */
    public function findKeyframeOfScene(?string $imageId, string $sceneId): ?VideoDesignImage;

    /**
     * @param  string  $sceneId
     * @return ?VideoDesignImage the approved scene keyframe of the scene that selects a file, with that artifact eager loaded, of any project
     */
    public function findApprovedKeyframeWithArtifactForScene(string $sceneId): ?VideoDesignImage;

    /**
     * @param  string  $projectId
     * @param  string  $sceneId
     * @return ?VideoDesignImage the approved scene keyframe of the project on the scene that selects a file, latest approval first
     */
    public function findLatestApprovedKeyframeForScene(string $projectId, string $sceneId): ?VideoDesignImage;

    /**
     * @param  array<int, string>|Collection<int, string>  $sceneIds
     * @return Collection<int, VideoDesignImage> the approved scene keyframes on these scenes that select a file, with that artifact eager loaded, of any project
     */
    public function findApprovedKeyframesForScenes(array|Collection $sceneIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $sceneId
     * @return ?VideoDesignImage the first scene keyframe of the project ever made on the scene, by creation then id
     */
    public function findFirstKeyframeForScene(string $projectId, string $sceneId): ?VideoDesignImage;

    /**
     * @param  string  $projectId
     * @return Collection<int, VideoDesignImage> the approved environment plates of the project that select a file and name an environment key, with that artifact eager loaded
     */
    public function findApprovedEnvironmentPlates(string $projectId): Collection;

    /**
     * @param  string  $projectId
     * @return Collection<int, VideoDesignImage> environment_key and prompt_spec_json of the approved environment plates of the project that select a file and name an environment key
     */
    public function findApprovedEnvironmentKeys(string $projectId): Collection;

    /**
     * @param  string  $projectId
     * @return Collection<int, VideoDesignImage> the approved reference views of the project that select a file, with that artifact eager loaded
     */
    public function findApprovedReferenceViews(string $projectId): Collection;

    /**
     * @param  string  $projectId
     * @return Collection<int, VideoDesignImage> the approved reference views of the project that select a file, with that artifact eager loaded, by slot
     */
    public function findApprovedReferencesBySlot(string $projectId): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return Collection<int, VideoDesignImage> the images of the project that select one of these artifacts
     */
    public function findImagesBySelectedArtifacts(string $projectId, array|Collection $artifactIds): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $artifactIds
     * @return Collection<int, VideoDesignImage> the identity anchors of the project that select one of these artifacts
     */
    public function findAnchorsBySelectedArtifacts(string $projectId, array|Collection $artifactIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $planStageId  prompt_spec_json.reference_plan_stage_id
     * @param  mixed  $requestId  prompt_spec_json.request_id, compared as given
     * @param  mixed  $anchorArtifactId  prompt_spec_json.identity_anchor_artifact_id, compared as given
     * @param  mixed  $anchorSha256  prompt_spec_json.identity_anchor_sha256, compared as given
     * @return list<string> selected_artifact_id of the approved reference views of the project rendered for that request of the plan on that anchor
     */
    public function pluckPlannedReferenceArtifacts(string $projectId, string $planStageId, mixed $requestId, mixed $anchorArtifactId, mixed $anchorSha256): array;

    /**
     * @param  string  $projectId
     * @param  string  $subjectKey  compared with prompt_spec_json.subject_key, the default subject key when it has none
     * @return ?VideoDesignImage the approved identity anchor of the project for the subject that selects a file, with that artifact eager loaded, latest approval first
     */
    public function findApprovedAnchorForSubject(string $projectId, string $subjectKey): ?VideoDesignImage;

    /**
     * @param  string  $projectId
     * @param  string  $imageType
     * @return Collection<int, VideoDesignImage> the approved images of the project and type that select a file, with that artifact eager loaded, latest approval first
     */
    public function findApprovedWithSelectedArtifact(string $projectId, string $imageType): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $imageIds  an empty list still runs the query
     * @return Collection<string, mixed> image_code by id of the images of the project with these ids
     */
    public function findImageCodesByIds(string $projectId, array|Collection $imageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $imageIds
     * @return list<mixed> image_code of the images of the project with these ids
     */
    public function findImageCodeListByIds(string $projectId, array|Collection $imageIds): array;

    /**
     * @param  string  $projectId
     * @param  array<int, string>|Collection<int, string>  $imageIds
     * @return Collection<int, VideoDesignImage> id, project_id, image_type, status, environment_key and prompt_spec_json of the images of the project with these ids
     */
    public function findPlateImagesByIds(string $projectId, array|Collection $imageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $imageType
     * @param  list<string>  $statuses
     * @return Collection<int, VideoDesignImage> id, status and prompt_spec_json of the images of the project and type in these statuses, oldest first
     */
    public function findForCameraPins(string $projectId, string $imageType, array $statuses): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $imageType
     * @return ?object {total, changed}: how many images of the project and type there are and when one last changed, read without the model
     */
    public function countAndLastChangeOfType(string $projectId, string $imageType): ?object;

    /**
     * @param  string  $projectId
     * @param  string  $imageType
     * @param  list<string>  $statuses
     * @return Collection<int, VideoDesignImage> the images of the project and type in these statuses with their candidate artifacts eager loaded
     */
    public function findOfTypeByStatusesWithArtifacts(string $projectId, string $imageType, array $statuses): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $imageType
     * @param  array<int, string>|Collection<int, string>  $imageIds
     * @return Collection<int, VideoDesignImage> the images of the project and type with these ids
     */
    public function findOfTypeByIds(string $projectId, string $imageType, array|Collection $imageIds): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $designStageId  prompt_spec_json.design_stage_id
     * @return Collection<int, VideoDesignImage> the approved model sheet masters of the project made from that design, latest approval first
     */
    public function findApprovedModelSheetMasters(string $projectId, string $designStageId): Collection;

    /**
     * @param  string  $projectId
     * @param  string  $designStageId  prompt_spec_json.design_stage_id
     * @return Collection<int, VideoDesignImage> the approved model sheet masters of the project made from that design that select a file
     */
    public function findApprovedSelectedModelSheetMasters(string $projectId, string $designStageId): Collection;
}
