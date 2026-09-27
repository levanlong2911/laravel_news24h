<?php

declare(strict_types=1);

namespace App\Services\Video;

use App\Models\VideoPlanningStage;
use App\Models\VideoVisualIdentity;
use App\Video\Screenplay\ScreenplayContentHash;

final class ScreenplaySubjectService
{
    public const MAIN_ROLE = 'protagonist';

    public function __construct(private readonly VisualIdentityStore $identities) {}

    /** @param array<string, mixed> $character */
    public static function isMain(array $character): bool
    {
        return ($character['role'] ?? null) === self::MAIN_ROLE;
    }

    /** @return list<array<string, mixed>> */
    public function mainCharacters(VideoPlanningStage $stage): array
    {
        return array_values(array_filter(
            (array) ($stage->output_json['characters'] ?? []),
            static fn (mixed $character): bool => is_array($character)
                && is_string($character['id'] ?? null)
                && self::isMain($character),
        ));
    }

    public function subjectKeyFor(VideoPlanningStage $stage, string $characterId): ?string
    {
        if (collect($this->mainCharacters($stage))->firstWhere('id', $characterId) === null) {
            return null;
        }

        return $this->generatedSubjectKey($stage, $characterId);
    }

    public function primarySubjectKey(VideoPlanningStage $stage): ?string
    {
        $object = collect($this->mainCharacters($stage))->firstWhere('kind', 'object');

        return $object === null ? null : $this->subjectKeyFor($stage, (string) $object['id']);
    }

    public function ensureIdentity(VideoPlanningStage $stage, string $characterId): ?VideoVisualIdentity
    {
        $subjectKey = $this->subjectKeyFor($stage, $characterId);

        if ($subjectKey === null) {
            return null;
        }

        $existing = $this->identities->latestForProject(
            (string) $stage->project_id, VisualIdentityStore::SUBJECT, $subjectKey,
        );

        if ($existing !== null) {
            return $existing;
        }

        $character = (array) collect($this->mainCharacters($stage))->firstWhere('id', $characterId);

        return $this->identities->freezeIdentity(
            (string) $stage->project_id,
            $this->characterIdentity($character, ScreenplayContentHash::of((array) $stage->output_json)),
            (string) ($character['name'] ?? $characterId),
            VisualIdentityStore::SUBJECT,
            $subjectKey,
        );
    }

    /**
     * @param  array<string, mixed>  $character
     * @return array<string, mixed>
     */
    private function characterIdentity(array $character, string $contentHash): array
    {
        return [
            'contract' => 'screenplay-subject-v1',
            'character_id' => (string) ($character['id'] ?? ''),
            'kind' => (string) ($character['kind'] ?? ''),
            'name' => (string) ($character['name'] ?? ''),
            'role' => (string) ($character['role'] ?? ''),
            'appearance' => (string) ($character['appearance'] ?? ''),
            'screenplay_content_hash' => $contentHash,
        ];
    }

    private function generatedSubjectKey(VideoPlanningStage $stage, string $characterId): string
    {
        return 'sp_'.substr(hash('sha256', $stage->id."\0".$characterId), 0, 40);
    }
}
