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

    public function getListPost($user)
    {
        $query = Post::query();
        // dd($user);
        // Nếu là member → chỉ xem bài theo domain
        if ($user->role->name === 'member') {
            // dd(11);
            $query->where('domain', $user->domain)
                ->where('author_id', $user->id);
        }

        // Nếu là admin → xem tất cả (KHÔNG filter)
        return $query
            ->orderBy('created_at', 'desc')
            ->paginate(Paginate::PAGE->value);

        // return Post::query()
        //         ->where('author_id', $user->id)
        //         ->where('domain', $user->domain)
        //         ->orderBy('created_at', 'desc')
        //         ->paginate(Paginate::PAGE->value);
    }

    public function getPostById($id, $user)
    {
        return Post::with('tags')
        ->where('id', $id)
        ->when($user->role === 'member', function ($q) use ($user) {
            $q->where('domain', $user->domain)
              ->where('author_id', $user->id);
        })
        ->firstOrFail();
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
