<?php

namespace App\Repositories\Eloquent;

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
        return VideoSession::with('project')->withCount('shots')->latest()->get();
    }

    public function findWithProjectAndShots(string $id): VideoSession
    {
        return VideoSession::with(['project', 'shots'])->findOrFail($id);
    }

    // GET /api/video-sessions/composing — runner poll de compose prompt
    public function findComposingWithProject(): iterable
    {
        return VideoSession::where('status', 'composing')
            ->with('project:id,name,subject_id')
            ->get(['id', 'project_id', 'code', 'renderplan_json']);
    }

    public function updateOrCreateByCode(string $code, array $attributes): VideoSession
    {
        return VideoSession::updateOrCreate(['code' => $code], $attributes);
    }
}
