<?php

namespace App\Services\Video;

use App\Models\VideoProject;
use App\Models\VideoVisualIdentity;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

final class VisualIdentityStore
{
    public const SUBJECT = 'subject';

    public function latestForProject(string $projectId, string $type = self::SUBJECT): ?VideoVisualIdentity
    {
        return VideoVisualIdentity::query()
            ->where('project_id', $projectId)
            ->where('identity_type', $type)
            ->orderByDesc('version')
            ->first();
    }

    /** @param array<string, mixed> $conceptOutput */
    public function freezeFromConcept(
        string $projectId,
        array $conceptOutput,
        string $name = 'master_vessel',
        string $type = self::SUBJECT,
    ): ?VideoVisualIdentity {
        $identity = $conceptOutput['design_identity'] ?? null;

        if (! is_array($identity) || $identity === []) {
            return null;
        }

        $identity = $this->sortDeep($identity);
        $hash = $this->hash($identity);

        try {
            return DB::transaction(function () use ($projectId, $identity, $hash, $name, $type) {
                VideoProject::query()->whereKey($projectId)->lockForUpdate()->firstOrFail();

                $existing = VideoVisualIdentity::query()
                    ->where('project_id', $projectId)
                    ->where('identity_type', $type)
                    ->where('identity_hash', $hash)
                    ->orderByDesc('version')
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }

                return VideoVisualIdentity::create([
                    'project_id' => $projectId,
                    'identity_type' => $type,
                    'name' => $name,
                    'version' => 1 + (int) VideoVisualIdentity::query()
                        ->where('project_id', $projectId)
                        ->where('identity_type', $type)
                        ->max('version'),
                    'identity_json' => $identity,
                    'identity_hash' => $hash,
                    'locked_at' => null,
                ]);
            });
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /** @param array<string, mixed> $identity */
    public function hash(array $identity): string
    {
        return hash('sha256', json_encode(
            $this->sortDeep($identity),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private function sortDeep(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $inner) {
            if (is_array($inner)) {
                $value[$key] = $this->sortDeep($inner);
            }
        }

        return $value;
    }
}
