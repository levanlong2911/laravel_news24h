<?php

namespace App\Repositories\Eloquent;

use App\Enums\VideoSessionStatus;
use App\Models\VideoSession;
use App\Repositories\Interfaces\VideoSessionRepositoryInterface;

class VideoSessionRepository extends BaseRepository implements VideoSessionRepositoryInterface
{
    public function getModel(): string
    {
        return VideoSession::class;
    }

    public function listAllWithProjectAndShotCount(): iterable
    {
        return VideoSession::with(['project', 'admin:id,name', 'article:id,category_id', 'article.category:id,name'])
            ->withCount('shots')->latest()->get();
    }

    public function findWithProjectAndShots(string $id): VideoSession
    {
        return VideoSession::with(['project', 'shots.latestRender', 'latestRenderPlan'])
            ->withSum('costEntries as cost_actual', 'cost_usd')
            ->findOrFail($id);
    }

    // GET /api/video-sessions/composing — runner poll de compose prompt
    public function findComposingWithProject(): iterable
    {
        return VideoSession::where('status', VideoSessionStatus::COMPOSING->value)
            ->with(['project:id,title,subject_id', 'latestRenderPlan'])
            ->get(['id', 'project_id', 'code']);
    }

    public function updateOrCreateByCode(string $code, array $attributes): VideoSession
    {
        return VideoSession::updateOrCreate(['code' => $code], $attributes);
    }
}
