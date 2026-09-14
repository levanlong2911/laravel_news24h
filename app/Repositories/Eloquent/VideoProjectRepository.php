<?php

namespace App\Repositories\Eloquent;

use App\Models\Admin;
use App\Models\Article;
use App\Models\VideoCostEntry;
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
            ->withCount(['costEntries as unpriced_cost_count' => fn ($q) => $q->where('metadata_json->pricing', 'unpriced')])
            // Hang design_image khong mang khoa `pricing` nao: khong ai biet con so do
            // la uoc tinh hay tien that. Goi no la "da doi soat" la noi qua.
            ->withCount(['costEntries as unclassified_cost_count' => fn ($q) => $q
                ->where('entity_type', 'design_image')
                ->whereNull('metadata_json->pricing')])
            /*
             * Hai cot, hai nghia, va CHI `design_image` duoc phan loai lai:
             *
             *   cost_actual_sum   — tien da xac nhan. Dong `shot`, QA, render attempt
             *                       cu khong co khoa `pricing` nao, nen chung giu
             *                       nguyen cach tinh cu; doi cach doc chung phai la
             *                       mot pha audit rieng.
             *   estimated_cost_sum — uoc tinh. Hang cu chua co `estimated_cost_usd`
             *                       thi lay chinh `cost_usd` cua no, nen khong phai
             *                       viet lai lich su bang migration nao.
             */
            ->addSelect(['cost_actual_sum' => VideoCostEntry::query()
                ->selectRaw("COALESCE(SUM(CASE
                    WHEN entity_type <> 'design_image' THEN cost_usd
                    WHEN JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) IN ('reported', 'reconciled', 'free') THEN cost_usd
                    ELSE 0 END), 0)")
                ->whereColumn('project_id', 'video_projects.id')])
            ->addSelect(['estimated_cost_sum' => VideoCostEntry::query()
                ->selectRaw("COALESCE(SUM(CASE
                    WHEN entity_type = 'design_image'
                     AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.pricing')) = 'estimated'
                    THEN COALESCE(JSON_EXTRACT(metadata_json, '$.estimated_cost_usd'), cost_usd)
                    ELSE 0 END), 0)")
                ->whereColumn('project_id', 'video_projects.id')])
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
