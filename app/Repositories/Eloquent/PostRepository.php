<?php

namespace App\Repositories\Eloquent;

use App\Models\Post;
use App\Enums\Paginate;
use App\Repositories\Interfaces\PostRepositoryInterface;
use Illuminate\Support\Facades\Gate;

class PostRepository extends BaseRepository implements PostRepositoryInterface
{
    // protected Model $model;

    public function getModel(): string
    {
        return Post::class;
    }

    public function getListPost()
    {
        $user = auth()->user();

        return Post::with('tags')
            ->visibleTo($user)
            ->orderBy('created_at', 'desc')
            ->paginate(Paginate::PAGE->value);

        // Nếu là admin → xem tất cả (KHÔNG filter)
        // return $query
        //     ->orderBy('created_at', 'desc')
        //     ->paginate(Paginate::PAGE->value);

        // return Post::query()
        //         ->where('author_id', $user->id)
        //         ->where('domain', $user->domain)
        //         ->orderBy('created_at', 'desc')
        //         ->paginate(Paginate::PAGE->value);
    }

    public function getPostById($id): ?Post
    {
        $post = Post::with('tags')->find($id);
        if (!$post) {
            abort(404);
        }
        if (!Gate::allows('update', $post)) {
            return null;
        }
        return $post;
    }

    public function update($id, array $data): bool
    {
        $post = Post::findOrFail($id);

        Gate::authorize('update', $post);
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

    // public function delete(string $id): void
    // {
    //     $post = Post::findOrFail($id);

    //     Gate::authorize('delete', $post);

    //     $post->delete();
    // }

}
