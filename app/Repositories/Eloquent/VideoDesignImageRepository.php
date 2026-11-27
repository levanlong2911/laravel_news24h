<?php

namespace App\Repositories\Eloquent;

use App\Enums\DesignImageStatus;
use App\Models\VideoDesignImage;
use App\Models\VideoRenderScene;
use App\Repositories\Interfaces\VideoDesignImageRepositoryInterface;
use App\Services\Video\DesignImageStore;
use App\Services\Video\VisualIdentityStore;
use Illuminate\Support\Collection;

class VideoDesignImageRepository extends BaseRepository implements VideoDesignImageRepositoryInterface
{
    public function getModel(): string
    {
        return VideoDesignImage::class;
    }

    public function findForProject(string $projectId, ?string $imageId): ?VideoDesignImage
    {
        return $this->model->newQuery()->where('project_id', $projectId)->find($imageId);
    }

    public function findForProjectOfType(string $projectId, string $imageType, ?string $imageId): ?VideoDesignImage
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('image_type', $imageType)->find($imageId);
    }

    public function findImage(?string $imageId): ?VideoDesignImage
    {
        return $this->model->newQuery()->whereKey($imageId)->first();
    }

    public function findImageWithArtifact(string $imageId): ?VideoDesignImage
    {
        return $this->model->newQuery()->whereKey($imageId)->with('artifact')->first();
    }

    public function findImageSceneLink(?string $imageId): ?VideoDesignImage
    {
        return $this->model->newQuery()->whereKey($imageId)->first(['id', 'render_scene_id']);
    }

    public function findImageCode(?string $imageId): ?string
    {
        return $this->model->newQuery()->whereKey($imageId)->value('image_code');
    }

    public function findSelectedArtifactId(string $imageId): mixed
    {
        return $this->model->newQuery()->whereKey($imageId)->value('selected_artifact_id');
    }

    public function findTaskImages(string $projectId, array|Collection $imageIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereIn('id', $imageIds)
            ->get(['id', 'project_id', 'image_type', 'status', 'render_error', 'prompt_spec_json', 'claimed_at', 'render_queue']);
    }

    public function findKeyframesForRenderScenes(string $projectId, array|Collection $renderSceneIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->whereIn('render_scene_id', $renderSceneIds)->with('artifacts')->orderByDesc('created_at')->orderByDesc('image_code')->get();
    }

    public function findSceneKeyframesForRevision(string $projectId, int $revision): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->whereIn('render_scene_id', VideoRenderScene::query()
                ->where('project_id', $projectId)
                ->where('revision', $revision)
                ->select('id'))
            ->with(['artifacts' => fn ($query) => $query->orderBy('created_at')->orderBy('id')])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function findApprovedSceneKeyframe(string $sceneId): ?VideoDesignImage
    {
        return $this->model->newQuery()
            ->where('render_scene_id', $sceneId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->first();
    }

    public function isSelectedApprovedSceneKeyframe(string $renderSceneId, string $artifactId): bool
    {
        return $this->model->newQuery()
            ->where('render_scene_id', $renderSceneId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->where('selected_artifact_id', $artifactId)
            ->exists();
    }

    public function findKeyframeOfScene(?string $imageId, string $sceneId): ?VideoDesignImage
    {
        return $this->model->newQuery()->whereKey($imageId)->where('render_scene_id', $sceneId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)->first();
    }

    public function findApprovedKeyframeWithArtifactForScene(string $sceneId): ?VideoDesignImage
    {
        return $this->model->newQuery()
            ->where('render_scene_id', $sceneId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->first();
    }

    public function findLatestApprovedKeyframeForScene(string $projectId, string $sceneId): ?VideoDesignImage
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('render_scene_id', $sceneId)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->orderByDesc('approved_at')
            ->first();
    }

    public function findApprovedKeyframesForScenes(array|Collection $sceneIds): Collection
    {
        return $this->model->newQuery()
            ->whereIn('render_scene_id', $sceneIds)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->get();
    }

    public function findFirstKeyframeForScene(string $projectId, string $sceneId): ?VideoDesignImage
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::SCENE_KEYFRAME_TYPE)
            ->where('render_scene_id', $sceneId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    public function findApprovedEnvironmentPlates(string $projectId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereNotNull('environment_key')
            ->with('artifact')
            ->get();
    }

    public function findApprovedEnvironmentKeys(string $projectId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ENVIRONMENT_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereNotNull('environment_key')
            ->get(['environment_key', 'prompt_spec_json']);
    }

    public function findApprovedReferenceViews(string $projectId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::REFERENCE_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->get();
    }

    public function findApprovedReferencesBySlot(string $projectId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::REFERENCE_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->orderBy('slot_index')
            ->get();
    }

    public function findImagesBySelectedArtifacts(string $projectId, array|Collection $artifactIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereIn('selected_artifact_id', $artifactIds)->get();
    }

    public function findAnchorsBySelectedArtifacts(string $projectId, array|Collection $artifactIds): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->whereIn('selected_artifact_id', $artifactIds)
            ->get();
    }

    public function pluckPlannedReferenceArtifacts(string $projectId, string $planStageId, mixed $requestId, mixed $anchorArtifactId, mixed $anchorSha256): array
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::REFERENCE_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->where('prompt_spec_json->reference_plan_stage_id', $planStageId)
            ->where('prompt_spec_json->request_id', $requestId)
            ->where('prompt_spec_json->identity_anchor_artifact_id', $anchorArtifactId)
            ->where('prompt_spec_json->identity_anchor_sha256', $anchorSha256)
            ->pluck('selected_artifact_id')->map('strval')->all();
    }

    public function findApprovedAnchorForSubject(string $projectId, string $subjectKey): ?VideoDesignImage
    {
        return $this->model->newQuery()
            ->whereRaw(
                "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.subject_key')), ?) = ?",
                [VisualIdentityStore::DEFAULT_SUBJECT_KEY, $subjectKey],
            )
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::ANCHOR_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->with('artifact')
            ->orderByDesc('approved_at')
            ->first();
    }

    public function findApprovedWithSelectedArtifact(string $projectId, string $imageType): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('image_type', $imageType)
            ->where('status', DesignImageStatus::APPROVED->value)->whereNotNull('selected_artifact_id')->with('artifact')->orderByDesc('approved_at')->get();
    }

    public function findImageCodesByIds(string $projectId, array|Collection $imageIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereIn('id', $imageIds)->pluck('image_code', 'id');
    }

    public function findImageCodeListByIds(string $projectId, array|Collection $imageIds): array
    {
        return $this->model->newQuery()->where('project_id', $projectId)->whereIn('id', $imageIds)->pluck('image_code')->all();
    }

    public function findPlateImagesByIds(string $projectId, array|Collection $imageIds): Collection
    {
        return $this->model->newQuery()->whereIn('id', $imageIds)->where('project_id', $projectId)
            ->get(['id', 'project_id', 'image_type', 'status', 'environment_key', 'prompt_spec_json']);
    }

    public function findForCameraPins(string $projectId, string $imageType, array $statuses): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('image_type', $imageType)
            ->whereIn('status', $statuses)->orderBy('created_at')->get(['id', 'status', 'prompt_spec_json']);
    }

    public function changeSignatureOfType(string $projectId, string $imageType): string
    {
        $rows = $this->model->newQuery()->where('project_id', $projectId)->where('image_type', $imageType)->orderBy('id')
            ->selectRaw('id, status, selected_artifact_id, md5(prompt_spec_json) as spec, updated_at')->toBase()->get();

        return $rows->count().'@'.md5((string) json_encode($rows->map(static fn (object $row): array => (array) $row)->all()));
    }

    public function findOfTypeByStatusesWithArtifacts(string $projectId, string $imageType, array $statuses): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('image_type', $imageType)
            ->whereIn('status', $statuses)->with('artifacts')->get();
    }

    public function findOfTypeByIds(string $projectId, string $imageType, array|Collection $imageIds): Collection
    {
        return $this->model->newQuery()->where('project_id', $projectId)->where('image_type', $imageType)->whereIn('id', $imageIds)->get();
    }

    public function findApprovedModelSheetMasters(string $projectId, string $designStageId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.design_stage_id')) = ?", [$designStageId])
            ->orderByDesc('approved_at')
            ->get();
    }

    public function findApprovedSelectedModelSheetMasters(string $projectId, string $designStageId): Collection
    {
        return $this->model->newQuery()
            ->where('project_id', $projectId)
            ->where('image_type', DesignImageStore::MODEL_SHEET_MASTER_TYPE)
            ->where('status', DesignImageStatus::APPROVED->value)
            ->whereNotNull('selected_artifact_id')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(prompt_spec_json, '$.design_stage_id')) = ?", [$designStageId])
            ->get();
    }
}
