<?php

namespace App\Repositories\Eloquent;

use App\Models\Article;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;

class VideoProjectRepository extends BaseRepository implements VideoProjectRepositoryInterface
{
    public function getModel(): string
    {
        return VideoProject::class;
    }

    // 1 project / bai viet: nhieu lan bam nut Tao Video tren cung bai viet
    // se gom chung ve 1 project (khong tao trung).
    public function findOrCreateByArticleId(Article $article): VideoProject
    {
        return VideoProject::firstOrCreate(
            ['article_id' => $article->id],
            ['title' => $article->title, 'admin_id' => auth()->id()],
        );
    }

    // Dung cho apiStore — Python tu dat ten project, khong can cat chuoi
    public function firstOrCreateByName(string $name, ?string $subjectId): VideoProject
    {
        return VideoProject::firstOrCreate(['title' => $name], ['subject_id' => $subjectId]);
    }

    // `latest_status` la subquery chu khong phai cot: suy tu luot moi nhat nen
    // luon khop thuc te, khong can code duy tri.
    public function listAllWithCounts(): iterable
    {
        return VideoProject::query()
            ->with(['article:id,title,category_id', 'article.category:id,name', 'admin:id,name'])
            ->withCount('sessions')
            ->withCount(['designCells as approved_assets_count' => fn ($q) => $q->where('status', 'approved')])
            ->withSum('costEntries as cost_actual_sum', 'cost_usd')
            ->addSelect(['latest_status' => VideoSession::select('status')
                ->whereColumn('project_id', 'video_projects.id')
                ->latest()
                ->limit(1)])
            ->latest()
            ->get();
    }
}
