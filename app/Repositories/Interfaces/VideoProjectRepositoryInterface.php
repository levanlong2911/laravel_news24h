<?php

namespace App\Repositories\Interfaces;

use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoProject;
use App\Models\VideoRenderScene;

interface VideoProjectRepositoryInterface extends RepositoryInterface
{
    public function findOrCreateByArticleId(Article $article, ?Admin $actor): VideoProject;

    public function listAllWithCounts(?Admin $actor): iterable;

    public function getById(string $id): ?VideoProject;

    /**
     * @param  string  $projectId
     * @return ?VideoProject the project with only id and admin_id selected, null when it does not exist
     */
    public function findOwnership(string $projectId): ?VideoProject;

    /**
     * @param  string  $projectId
     * @return ?VideoProject the full project row, null when it does not exist
     */
    public function findProject(string $projectId): ?VideoProject;

    /**
     * @param  string  $projectId
     * @return VideoProject the full project row
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when it does not exist
     */
    public function findProjectOrFail(string $projectId): VideoProject;

    /**
     * @param  string  $projectId
     * @return ?VideoProject the full project row with article and article.category eager loaded, null when it does not exist
     */
    public function findProjectWithCategory(string $projectId): ?VideoProject;

    /**
     * @param  string  $projectId
     * @param  list<string>  $relations  the relations to eager load, each with the columns it needs (such as 'article:id,category_id')
     * @return ?VideoProject the full project row with only those relations loaded, null when it does not exist
     */
    public function findProjectWith(string $projectId, array $relations): ?VideoProject;

    /**
     * @param  string  $projectId
     * @return VideoProject the full project row with article and article.category eager loaded
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when it does not exist
     */
    public function findProjectWithCategoryOrFail(string $projectId): VideoProject;

    /**
     * @param  string  $projectId
     * @return ?array<string, mixed> metadata_json as the model casts it, null when the project or the value does not exist
     */
    public function findMetadata(string $projectId): ?array;

    /**
     * @param  string  $projectId
     * @return VideoProject the project row locked FOR UPDATE; call inside the caller's transaction
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException when it does not exist
     */
    public function lockProject(string $projectId): VideoProject;

    /**
     * @param  string  $projectId
     * @return ?VideoProject the project row locked FOR UPDATE, null when it does not exist; call inside the caller's transaction
     */
    public function lockProjectOrNull(string $projectId): ?VideoProject;

    /**
     * @param  VideoProject  $project  the project the caller holds, usually locked
     * @param  array<string, mixed>  $metadata  the whole metadata_json to store
     * @return void
     */
    public function saveLockedMetadata(VideoProject $project, array $metadata): void;

    /**
     * @param  VideoProject  $project  the project a caller read, with or without its article loaded
     * @return ?Article the article of the project through its relation: the loaded one, otherwise read now
     */
    public function findArticleOf(VideoProject $project): ?Article;

    /**
     * @param  VideoRenderScene  $scene  a render scene, whose project_id may be null (a scene of a render plan)
     * @return ?VideoProject the full row of the project the scene names, read by its project_id as stored (a null id still runs its query and finds nothing)
     */
    public function findProjectOfScene(VideoRenderScene $scene): ?VideoProject;
}
