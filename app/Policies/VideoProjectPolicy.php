<?php

namespace App\Policies;

use App\Models\Admin;
use App\Models\VideoProject;

class VideoProjectPolicy
{
    public function view(Admin $admin, VideoProject $project): bool
    {
        if ($admin->isAdmin()) {
            return true;
        }

        return $admin->isMember()
            && $project->admin_id !== null
            && $admin->id === $project->admin_id;
    }

    public function update(Admin $admin, VideoProject $project): bool
    {
        return $this->view($admin, $project);
    }
}
