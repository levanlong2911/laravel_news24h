<?php

namespace App\Repositories\Eloquent;

use App\Models\Post;
use App\Enums\Paginate;
use App\Repositories\Interfaces\PostRepositoryInterface;

class PostRepository extends BaseRepository implements PostRepositoryInterface
{
    // protected Model $model;

    public function getModel(): string
    {
        return Post::class;
    }

    public function getListPost()
    {
        return Post::query()
                ->orderBy('created_at', 'desc')
                ->paginate(Paginate::PAGE->value);
    }

    public function getPostById($id)
    {
        return Post::with('tags')->find($id);
    }

    public function update($id, array $data): bool
    {
        $post = Post::find($id);
        if (!$post) {
            return false;
        }
        return $post->update($data);
    }

    public function getDataListIds($ids)
    {
        return Post::query()
                ->whereIn("id", $ids)
                ->get();
    }
}
