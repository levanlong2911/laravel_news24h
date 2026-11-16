<?php

declare(strict_types=1);

namespace App\Video\Moto;

use App\Models\VideoProject;

interface ReleaseBound
{
    /** @param  VideoProject  $project  the project whose pinned release the adapter works under from now on */
    public function bind(VideoProject $project): void;
}
