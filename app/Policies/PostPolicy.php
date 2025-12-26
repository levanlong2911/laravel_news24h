<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\Post;
use Illuminate\Auth\Access\Response;

class PostPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(Admin $admin): bool
    {
        //
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(Admin $admin, Post $post): bool
    {
        if ($admin->isAdmin()) return true;
        return $admin->role->name === 'member'
            && $admin->domain_id === $post->domain_id
            && $admin->id === $post->author_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(Admin $admin): bool
    {
        //
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(Admin $admin, Post $post): bool
    {
        if ($admin->isAdmin()) return true;

        return $admin->role->name === 'member'
            && $admin->domain_id === $post->domain_id
            && $admin->id === $post->author_id;
    }

    /**
     * Determine whether the admin can delete the model.
     */
    public function delete(Admin $admin, Post $post): bool
    {
        if ($admin->isAdmin()) return true;

        return $admin->role->name === 'member'
            && $admin->domain_id === $post->domain_id
            && $admin->id === $post->author_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(Admin $admin, Post $post): bool
    {
        //
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(Admin $admin, Post $post): bool
    {
        //
    }
}
