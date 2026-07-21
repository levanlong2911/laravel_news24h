<?php

namespace App\Repositories\Eloquent;

use App\Models\VideoProject;
use App\Repositories\Interfaces\VideoProjectRepositoryInterface;
use Illuminate\Support\Str;

class VideoProjectRepository extends BaseRepository implements VideoProjectRepositoryInterface
{
    public function getModel(): string
    {
        return VideoProject::class;
    }

    // 1 project / bai viet: nhieu lan bam nut Tao Video tren cung bai viet
    // se gom chung ve 1 project (khong tao trung).
    public function findOrCreateByArticle(string $title, string $articleId): VideoProject
    {
        return VideoProject::firstOrCreate(
            ['name' => Str::limit($title, 110, '')],
            ['subject_id' => $articleId]
        );
    }

    // Dung cho apiStore — Python tu dat ten project, khong can cat chuoi
    public function firstOrCreateByName(string $name, ?string $subjectId): VideoProject
    {
        return VideoProject::firstOrCreate(['name' => $name], ['subject_id' => $subjectId]);
    }
}
