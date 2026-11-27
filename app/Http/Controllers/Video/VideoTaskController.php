<?php

namespace App\Http\Controllers\Video;

use App\Form\AdminCustomValidator;
use App\Services\Video\VideoTaskService;
use App\Services\VideoProjectService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class VideoTaskController extends VideoController
{
    public function __construct(
        VideoProjectService $videoProjectService,
        AdminCustomValidator $form,
        private readonly VideoTaskService $tasks
    ) {
        parent::__construct($videoProjectService, $form);
    }

    public function stage(string $id, string $stage)
    {
        $this->ownedProject($id);

        $state = Str::isUuid($stage)
            ? $this->tasks->stage($id, $stage)
            : null;

        abort_if($state === null, 404);

        return response()->json($state);
    }

    public function index(Request $request, string $id)
    {
        $this->ownedProject($id);
        $ids = static fn (string $kind): array => array_slice(array_values(array_filter(explode(',', (string) $request->query($kind, '')), static fn (string $value): bool => Str::isUuid($value))), 0, 50);

        return response()->json($this->tasks->tasks($id, ['stages' => $ids('stages'), 'images' => $ids('images'), 'clips' => $ids('clips')]));
    }
}
