<?php

namespace App\Repositories\Eloquent;

use App\Models\Post;
use App\Repositories\Interfaces\PostRepositoryInterface;

class PostRepository extends BaseRepository implements PostRepositoryInterface
{
    public function getModel(): string
    {
        return Post::class;
    }
}
