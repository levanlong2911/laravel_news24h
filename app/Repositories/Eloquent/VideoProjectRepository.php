<?php

namespace App\Repositories\Eloquent;

use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoProject;
use App\Models\VideoSession;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

class VideoProjectRepository extends BaseRepository implements VideoProjectRepositoryInterface
{
    public function getModel(): string
    {
        return VideoProject::class;
    }

    // 1 project / bai viet: nhieu lan bam nut Tao Video tren cung bai viet
    // se gom chung ve 1 project (khong tao trung).
    public function findOrCreateByArticleId(Article $article, ?Admin $actor): VideoProject
    {
        // `article_id` la UNIQUE, nen mot hang ghi ra roi moi bi tu choi se khoa
        // bai viet do vinh vien. Chan quyen TAO truoc khi cham DB.
        if ($actor === null || (! $actor->isAdmin() && ! $actor->isMember())) {
            throw new AuthorizationException('This account may not open a video project.');
        }

        $existing = VideoProject::query()->where('article_id', $article->id)->first();

        if ($existing !== null) {
            if (Gate::forUser($actor)->denies('update', $existing)) {
                throw new AuthorizationException('This video project belongs to another account.');
            }

            return $existing;
        }

        $project = VideoProject::firstOrCreate(
            ['article_id' => $article->id],
            ['title' => $article->title, 'admin_id' => $actor->id],
        );

        // Hai request cung vao mot bai: ke thua cuoc nhan ve hang cua nguoi kia.
        if (Gate::forUser($actor)->denies('update', $project)) {
            throw new AuthorizationException('This video project belongs to another account.');
        }

        return $project;
    }

    // Dung cho apiStore — Python tu dat ten project, khong can cat chuoi
    public function firstOrCreateByName(string $name, ?string $subjectId): VideoProject
    {
        return VideoProject::firstOrCreate(['title' => $name], ['subject_id' => $subjectId]);
    }

    // `latest_status` la subquery chu khong phai cot: suy tu luot moi nhat nen
    // luon khop thuc te, khong can code duy tri.
    public function listAllWithCounts(?Admin $actor): iterable
    {
        if ($actor === null) {
            throw new AuthorizationException('Video projects cannot be listed without an actor.');
        }

        if (! $actor->isAdmin() && ! $actor->isMember()) {
            throw new AuthorizationException('This role may not list video projects.');
        }

        return VideoProject::query()
            ->when(! $actor->isAdmin(), fn ($query) => $query->where('admin_id', $actor->id))
            ->with(['article:id,title,category_id', 'article.category:id,name', 'admin:id,name'])
            ->withCount('sessions')
            ->withCount(['designImages as approved_assets_count' => fn ($q) => $q->where('status', 'approved')])
            ->withSum('costEntries as cost_actual_sum', 'cost_usd')
            ->withCount(['costEntries as unpriced_cost_count' => fn ($q) => $q->where('metadata_json->pricing', 'unpriced')])
            ->addSelect(['latest_status' => VideoSession::select('status')
                ->whereColumn('project_id', 'video_projects.id')
                ->latest()
                ->limit(1)])
            ->latest()
            ->get();
    }

    public function getById(string $id): ?VideoProject
    {
        return VideoProject::with([
            'article:id,title,content,source_url,source_name,created_at,category_id',
            'article.category:id,slug',
        ])->find($id);
    }
}
