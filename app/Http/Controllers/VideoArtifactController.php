<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\VideoArtifact;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Artifact khong nam duoi docroot: `response.json` giu nguyen van phan hoi cua
 * provider, `constraints.json`/`projection.json` la noi dung noi bo. Phuc vu
 * qua day thay vi file tinh de moi lan tai deu di qua tang dang nhap.
 */
class VideoArtifactController extends Controller
{
    public function show(VideoArtifact $artifact): StreamedResponse
    {
        $this->authorizeArtifact($artifact);

        $disk = Storage::disk($artifact->storage_disk);

        abort_unless($disk->exists($artifact->storage_path), 404);

        // Noi dung khoa theo sha256 va bang nay chi ghi them, khong sua: mot id
        // luon tra ve dung mot chuoi byte, nen cache duoc vinh vien.
        return $disk->response($artifact->storage_path, null, [
            'Content-Type' => $artifact->mime_type ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    private function authorizeArtifact(VideoArtifact $artifact): void
    {
        $actor = auth()->user();

        if (! $actor instanceof Admin) {
            abort(403);
        }

        $project = $artifact->project;

        if ($project === null) {
            abort_unless($actor->isAdmin(), 403);

            return;
        }

        abort_if(Gate::forUser($actor)->denies('view', $project), 403);
    }
}
